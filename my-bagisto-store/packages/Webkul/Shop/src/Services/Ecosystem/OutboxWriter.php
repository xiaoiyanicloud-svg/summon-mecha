<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Webkul\Shop\Models\EcosystemOutbox;

/**
 * outbox 写入入口(ADR-0001 G1-D07)。
 *
 * ── 事务边界 ──
 *   本类**不自开事务**：outbox 必须与业务写落在调用方同一个事务里。
 *   如果这里自己 `DB::transaction()`，业务回滚时 outbox 已经先提交，
 *   消费方会看到一条“业务从未发生”的事实 —— 这正是 outbox 模式要避免的分叉。
 *   调用约定：`DB::transaction(function () { $business->write(); $outbox->write(...); })`。
 *
 * ── 白名单 ──
 *   `config/ecosystem_outbox.php` 是 aggregate_type / event_type 的单一事实源。
 *   未知类型直接拒绝，避免 G1 还没立项的聚合（报名通知等）悄悄混进消费流。
 *
 * ── 幂等 ──
 *   `dedupe_key` 非空时：
 *     · 同 key + 同事实 → 返回既有行（重放是幂等的，不造第二条）；
 *     · 同 key + 不同事实 → 抛异常（同 key 被复用去承载另一件事，是调用方 bug，
 *       不能静默覆盖，否则第一次的事实就丢了）。
 *
 * ── PII ──
 *   payload 只允许 ID / 版本 / 原因码。见 `assertNoPii()`：按键名递归拒绝
 *   phone / contact / email / 证件 / token 等明显承载个人数据的字段。
 */
class OutboxWriter
{
    /**
     * payload 键名中出现的 PII 片段（归一化为小写字母数字后做子串匹配）。
     *
     * 这是**入口防线**，不是完整的数据分类器：它拦的是“明显不该进 outbox”的字段。
     * 真正的边界仍由调用方保证 —— payload 设计上只放 ID、版本和原因码。
     *
     * @var array<int, string>
     */
    protected const PII_KEY_FRAGMENTS = [
        'phone',
        'telephone',
        'mobile',
        'contact',
        'email',
        'wechat',
        'weixin',
        'idcard',
        'idnumber',
        'identitycard',
        'nationalcode',
        'passport',
        'token',
        'secret',
        'password',
        'credential',
        'address',
        'realname',
        'fullname',
    ];

    /**
     * @param  array<string, mixed>  $payload  只放 ID / 版本 / 原因码
     * @param  string|null  $dedupeKey  业务幂等键；NULL 表示不做去重
     * @param  DateTimeInterface|null  $availableAt  默认当前 UTC 时间
     *
     * @throws InvalidArgumentException 未知 aggregate/event 类型，或 payload 含 PII 键
     * @throws \RuntimeException 同一 dedupe_key 被用于不同业务事实
     */
    public function write(
        string $aggregateType,
        int $aggregateId,
        string $eventType,
        array $payload,
        ?string $dedupeKey = null,
        ?DateTimeInterface $availableAt = null,
    ): EcosystemOutbox {
        $this->assertRegistered($aggregateType, $eventType);
        $this->assertNoPii($payload);

        // 空串不是有效的业务幂等键：统一按“不去重”处理，
        // 避免两件本来无关的事因为都传了 '' 而撞唯一索引。
        $dedupeKey = $dedupeKey === '' ? null : $dedupeKey;

        $attributes = [
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'event_type'     => $eventType,
            'payload'        => $payload,
            'dedupe_key'     => $dedupeKey,
            // 字段以 UTC 存储（ADR G1-D04 / §4.11）。
            'available_at'   => $availableAt ?? now()->utc(),
        ];

        if ($dedupeKey !== null) {
            $existing = EcosystemOutbox::query()->where('dedupe_key', $dedupeKey)->first();

            if ($existing) {
                return $this->replayOrThrow($existing, $attributes);
            }
        }

        try {
            $row = EcosystemOutbox::create($attributes);

            // create() 的返回模型只带写入的属性：status / attempts 来自 DB 默认值，
            // created_at / updated_at 由数据库/框架回填，调用方此时看不到。
            // 统一 refresh 一次，保证写入入口的返回值 = 库内真实事实；
            // dedupe 重放分支返回的是查询出来的模型，本来就是完整行，不需要 refresh。
            return $row->refresh();
        } catch (QueryException $exception) {
            if ($dedupeKey === null || ! $this->isDedupeKeyViolation($exception)) {
                throw $exception;
            }

            // 并发下另一个事务先插入了同 key。这里**不能**用普通 SELECT 回查：
            // 调用方事务通常是 REPEATABLE READ，上面的 pre-check 已经建立了
            // 一致性快照；holder 提交后，普通 SELECT 仍然读旧快照（看不到新行），
            // 于是这里会误判“行不存在”并把 1062 抛回去，幂等重放就失效了。
            //
            // `lockForUpdate()` 是 current read：它会读最新已提交版本（并加行锁），
            // 能看到 holder 提交的行。找不到才说明不是 dedupe_key 冲突，原样抛出。
            $existing = EcosystemOutbox::query()
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->replayOrThrow($existing, $attributes);
        }
    }

    protected function assertRegistered(string $aggregateType, string $eventType): void
    {
        $aggregateTypes = (array) config('ecosystem_outbox.aggregate_types', []);
        $eventTypes = (array) config('ecosystem_outbox.event_types', []);

        if (! in_array($aggregateType, $aggregateTypes, true)) {
            throw new InvalidArgumentException(sprintf(
                '未登记的 outbox aggregate_type「%s」；G1 只允许：%s。',
                $aggregateType,
                implode(', ', $aggregateTypes),
            ));
        }

        if (! in_array($eventType, $eventTypes, true)) {
            throw new InvalidArgumentException(sprintf(
                '未登记的 outbox event_type「%s」；G1 只允许：%s。',
                $eventType,
                implode(', ', $eventTypes),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertNoPii(array $payload, string $path = 'payload'): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isPiiKey($key)) {
                throw new InvalidArgumentException(sprintf(
                    'outbox payload 不得承载 PII：%s.%s（只允许 ID / 版本 / 原因码）。',
                    $path,
                    $key,
                ));
            }

            if (is_array($value)) {
                $this->assertNoPii($value, $path.'.'.$key);
            }
        }
    }

    protected function isPiiKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';

        foreach (self::PII_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function replayOrThrow(EcosystemOutbox $existing, array $attributes): EcosystemOutbox
    {
        $incomingFingerprint = $this->fingerprint(
            $attributes['aggregate_type'],
            (int) $attributes['aggregate_id'],
            $attributes['event_type'],
            (array) $attributes['payload'],
        );

        $existingFingerprint = $this->fingerprint(
            (string) $existing->aggregate_type,
            (int) $existing->aggregate_id,
            (string) $existing->event_type,
            (array) ($existing->payload ?? []),
        );

        if ($incomingFingerprint !== $existingFingerprint) {
            throw new \RuntimeException(sprintf(
                'outbox dedupe_key「%s」已被另一条业务事实占用：既有 %s#%d %s，本次 %s#%d %s。',
                (string) $attributes['dedupe_key'],
                $existing->aggregate_type,
                (int) $existing->aggregate_id,
                $existing->event_type,
                $attributes['aggregate_type'],
                (int) $attributes['aggregate_id'],
                $attributes['event_type'],
            ));
        }

        // 同一业务事实的重放：返回既有行，不新增、不改写状态。
        return $existing;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function fingerprint(string $aggregateType, int $aggregateId, string $eventType, array $payload): string
    {
        return hash('sha256', (string) json_encode([
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'event_type'     => $eventType,
            'payload'        => $this->normalize($payload),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 键顺序不影响“是否同一事实”；MySQL JSON 的回读顺序也不保证与写入一致。
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function normalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }

    /**
     * 只把 `ecosystem_outbox` 的 dedupe_key 唯一键冲突当作可重放的并发事件。
     *
     * ⚠ 不能只看 1062：表上其他唯一约束（将来新增）也可能报同一个错误号。
     * 错误消息里必须出现本表唯一索引名或该列名，才认为“是同一业务事实的并发插入”。
     */
    protected function isDedupeKeyViolation(QueryException $exception): bool
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'uk_ecosystem_outbox_dedupe_key')
            || str_contains($message, 'dedupe_key');
    }
}
