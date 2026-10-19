<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\Event;

/**
 * G1-02 的**唯一新写入口**（ADR-0001 §4.2 / G1-D03 / G1-D08）。
 *
 * 只负责一件事：在行锁内按 `expected_row_version` 做乐观并发校验，并维护
 * `events.row_version`。发布/审核/撤稿/排期的状态机属于 G1-08，不在本类里实现。
 *
 * ── 为什么必须 lockForUpdate + 行版本 ──
 *   两个后台编辑同时保存时，“先读后写”会让后写者静默覆盖先写者。
 *   行锁保证“读到的版本”与“写入的版本”之间没有第三方插入；
 *   `expected_row_version` 则把“我基于哪个版本编辑”这一事实交给调用方显式声明。
 *   版本不符 → `EventRevisionConflictException`，由 HTTP 层映射成
 *   `EVENT_REVISION_CONFLICT`(409)，而不是假装保存成功。
 *
 * ── no-op ──
 *   传入值与原值相同（真实的 Eloquent dirty 判定）时**不递增** row_version，
 *   也不刷新 updated_at。否则前端重复提交同一个表单会把版本号推高，
 *   让另一个正在编辑的人无故收到冲突。
 *
 * ── 写入范围 ──
 *   仅 G1-02 发布字段与 G1-03 来源字段。旧列（name/status/date/...）继续由旧管理页维护，
 *   本服务拒绝通过“新的并发写入口”改它们，避免两条写路径互相绕过版本校验。
 *   需要扩大范围时应由 G1-08 明确扩展白名单并补测试，而不是在这里静默透传。
 *
 * ── 时间 ──
 *   `starts_at` / `ends_at` / `first_published_at` / `published_at` 按 ADR G1-D04
 *   以 UTC 存储。模型使用 `Casts\UtcDateTime`：
 *     · 写库时 DateTimeInterface 按其自身时区、字符串按应用默认时区解释，
 *       统一显式换算为 UTC 再落 DATETIME；
 *     · 读库时明确按 UTC 解析，不受应用默认时区（Asia/Shanghai）影响。
 *   时区转换由 cast 显式完成，不依赖 MySQL / 驱动的隐式时区。
 */
class EventWriteService
{
    /**
     * G1 可写字段（G1-02 发布字段 + G1-03 来源字段）。
     *
     * 刻意**不含** `row_version`：版本号只能由本服务按“期望值 + 1”维护，
     * 不接受调用方直接设置（否则乐观并发形同虚设）。
     */
    protected const WRITABLE_FIELDS = [
        'kind',
        'participation_mode',
        'publish_status',
        'disposition',
        'hosting_status',
        'timezone',
        'starts_at',
        'ends_at',
        'published_revision_id',
        'working_revision_id',
        'first_published_at',
        'published_at',

        // G1-03 来源与主办方；组合/枚举/核验边界见 EventSourceValidator。
        'source_type',
        'platform_role',
        'organizer_id',
    ];

    public function __construct(
        private readonly EventSourceValidator $sourceValidator,
        private readonly RevisionPointerValidator $pointerValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  只允许 WRITABLE_FIELDS 中的键
     *
     * @throws EventRevisionConflictException 库内 row_version 与期望值不一致
     * @throws InvalidArgumentException 传入了本入口不负责的字段
     */
    public function update(Event $event, int $expectedRowVersion, array $attributes): Event
    {
        $unsupported = array_diff(array_keys($attributes), self::WRITABLE_FIELDS);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(sprintf(
                'EventWriteService 不接受字段：%s；本入口只允许：%s。',
                implode(', ', $unsupported),
                implode(', ', self::WRITABLE_FIELDS),
            ));
        }

        return DB::transaction(function () use ($event, $expectedRowVersion, $attributes): Event {
            $locked = Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $actualRowVersion = (int) $locked->row_version;

            if ($actualRowVersion !== $expectedRowVersion) {
                throw new EventRevisionConflictException(
                    (int) $locked->getKey(),
                    $expectedRowVersion,
                    $actualRowVersion,
                );
            }

            $locked->fill($attributes);

            // 来源三字段任一变更都必须复核“同时为空或同时齐全 + 组合 + 主体状态”，
            // 防止通过本入口绕过 EventSourceService 直接写入半个来源。
            if ($locked->isDirty(['source_type', 'platform_role', 'organizer_id'])) {
                $this->sourceValidator->assertDraftAssignment($locked);
            }

            // G1-04：revision 指针不是普通可写字段。
            // published 指针本批一律拒绝；working 指针必须是同活动 draft revision。
            if ($locked->isDirty(['published_revision_id', 'working_revision_id'])) {
                $this->pointerValidator->assertAssignable($locked);
            }

            // 真实变更才递增；重复提交同一份表单是 no-op。
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->row_version = $expectedRowVersion + 1;
            $locked->save();

            return $locked;
        });
    }
}
