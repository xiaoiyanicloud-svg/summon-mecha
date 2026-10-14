<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Services\NotificationService;

/**
 * 平台侧告警 —— 给运营发"系统自己发现的事"。
 *
 * 为什么需要它：定时任务扫出来的问题（备案快到期、工单超时、事故没结案）
 * 如果没有落点，就只是日志里的一行字 —— 没人看。这个服务把它们变成
 * **管理员能看到的待办**。
 *
 * **去重是核心设计**，不是优化：
 *   一个每天跑的任务，如果每次都新建一条告警，三天之内运营就会开始无视它们 ——
 *   而"被无视的告警"比没有告警更糟，因为它给了你一种"有人在看"的错觉。
 *   规则：同一 `dedupKey` 的通知，**只要还有未读的、或最近 7 天内创建过，就不再发**。
 */
class AdminAlertService
{
    /**
     * 静默期（天）。同一件事在这个窗口内只提醒一次。
     */
    public const QUIET_DAYS = 7;

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARN = 'warn';

    public const LEVEL_URGENT = 'urgent';

    public function __construct(protected NotificationService $notifications) {}

    /**
     * 发一条平台告警（对所有在职管理员各发一条）。
     *
     * @return int 实际新建的通知条数（0 = 全部被去重跳过）
     */
    public function alert(
        string $type,
        string $title,
        string $content,
        string $dedupKey,
        array $payload = [],
        string $level = self::LEVEL_INFO,
    ): int {
        $admins = DB::table('admins')->where('status', 1)->pluck('id');

        if ($admins->isEmpty()) {
            // 没有在职管理员 —— 写日志，但别把任务搞挂
            Log::warning('平台告警无处投递（没有在职管理员）：'.$title, ['type' => $type, 'dedup' => $dedupKey]);

            return 0;
        }

        $created = 0;

        foreach ($admins as $adminId) {
            if ($this->recentlyAlerted((int) $adminId, $dedupKey)) {
                continue;
            }

            try {
                $this->notifications->send(
                    'admin',
                    (int) $adminId,
                    $type,
                    $title,
                    $content,
                    array_merge($payload, ['dedup_key' => $dedupKey, 'level' => $level]),
                );

                $created++;
            } catch (Throwable $e) {
                Log::error('平台告警写入失败：'.$e->getMessage(), ['type' => $type, 'dedup' => $dedupKey]);
            }
        }

        return $created;
    }

    /**
     * 同一件事近期是否已经提醒过。
     *
     * ⚠ 用 `whereJsonContains` 查 payload 里的 dedup_key。
     *   MySQL 的 JSON 列不能直接建普通索引，但 `notification` 是**低写入、低读取量**的表
     *   （一天几条），全表扫也可以接受。若哪天量级上来了，再加生成列 + 索引。
     */
    protected function recentlyAlerted(int $adminId, string $dedupKey): bool
    {
        return Notification::query()
            ->where('recipient_type', 'admin')
            ->where('recipient_id', $adminId)
            ->where('payload->dedup_key', $dedupKey)
            ->where(function ($q) {
                // 未读的：还没人处理，别重复提醒
                $q->whereNull('read_at')
                    // 或者刚发过不久：读过也不代表处理完了，但也别天天刷屏
                    ->orWhere('created_at', '>=', Carbon::now()->subDays(self::QUIET_DAYS));
            })
            ->exists();
    }
}
