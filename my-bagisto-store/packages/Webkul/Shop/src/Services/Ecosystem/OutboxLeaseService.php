<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webkul\Shop\Models\EcosystemOutbox;

/**
 * outbox 领取 / 结算 / 失败 / 过期恢复（ADR-0001 G1-D07 / 规划 §5.3 / §8.6 / G1-09）。
 *
 * ── 为什么是短事务 + FOR UPDATE ──
 *   MySQL 5.7 没有 `SKIP LOCKED`，不能照抄 MySQL 8 的 `FOR UPDATE SKIP LOCKED`。
 *   这里用小 batch（配置上限 20）在**一个短事务**里锁住待领取行并立即提交；
 *   handler 在事务外执行，避免持 outbox 行锁反向等待父对象锁。
 *
 * ── 租约 token ──
 *   `lease_owner = host:pid:uuid`，**每次领取唯一**。结算/失败 SQL 必须同时匹配：
 *     id + status='processing' + lease_owner + lease_until >= now
 *   影响 0 行即 `OutboxLeaseLostException` —— 旧 worker 不写任何状态。
 *
 * ── attempts ──
 *   attempts 在**领取时** +1，不是结算时。这样进程崩溃也能留下“已尝试过”的事实；
 *   过期恢复不重复 +1。
 *
 * ── 时间 ──
 *   `available_at` / `lease_until` 以 UTC DATETIME 落库（ADR G1-D04）。
 *   本类所有比较都用 `now('UTC')`，禁止用应用默认时区（Asia/Shanghai）。
 */
class OutboxLeaseService
{
    /**
     * 领取一批 pending 且到期的行，标记为 processing 并返回。
     *
     * @return array<int, EcosystemOutbox>
     */
    public function claim(int $limit = 0): array
    {
        $batchSize = $this->batchSize();
        $limit = $limit > 0 ? min($limit, $batchSize) : $batchSize;

        if ($limit <= 0) {
            return [];
        }

        $now = now('UTC');
        $nowString = $now->format('Y-m-d H:i:s');
        $leaseOwner = $this->newLeaseOwner();
        $leaseUntil = $now->copy()->addSeconds($this->leaseSeconds())->format('Y-m-d H:i:s');

        $claimedIds = DB::transaction(function () use ($limit, $nowString, $leaseOwner, $leaseUntil): array {
            // 短事务：只做“选中 + 标记”，不执行 handler。
            $rows = DB::table('ecosystem_outbox')
                ->where('status', 'pending')
                ->where('available_at', '<=', $nowString)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get(['id']);

            $ids = [];

            foreach ($rows as $row) {
                $affected = DB::table('ecosystem_outbox')
                    ->where('id', (int) $row->id)
                    ->where('status', 'pending')
                    ->where('available_at', '<=', $nowString)
                    ->update([
                        'status'      => 'processing',
                        'lease_owner' => $leaseOwner,
                        'lease_until' => $leaseUntil,
                        'attempts'    => DB::raw('attempts + 1'),
                        'updated_at'  => now(),
                    ]);

                if ($affected === 1) {
                    $ids[] = (int) $row->id;
                }
            }

            return $ids;
        });

        if ($claimedIds === []) {
            return [];
        }

        $models = EcosystemOutbox::query()
            ->whereIn('id', $claimedIds)
            ->orderBy('id')
            ->get()
            ->all();

        // 只返回本次 token 领取的行；理论上全部匹配，防御性过滤避免返回旧租约行。
        return array_values(array_filter(
            $models,
            fn (EcosystemOutbox $row): bool => (string) $row->status === 'processing'
                && (string) $row->lease_owner === $leaseOwner,
        ));
    }

    /**
     * 结算成功：processing -> sent。
     *
     * @throws OutboxLeaseLostException 租约已失效 / 已过期
     */
    public function settle(int $outboxId, string $leaseOwner): void
    {
        $affected = DB::table('ecosystem_outbox')
            ->where('id', $outboxId)
            ->where('status', 'processing')
            ->where('lease_owner', $leaseOwner)
            ->where('lease_until', '>=', $this->nowString())
            ->update([
                'status'       => 'sent',
                'lease_owner'  => null,
                'lease_until'  => null,
                'last_error'   => null,
                'updated_at'   => now(),
            ]);

        if ($affected !== 1) {
            throw OutboxLeaseLostException::forRow($outboxId, $leaseOwner);
        }
    }

    /**
     * 失败回报：可重试且未达上限 -> pending + backoff；
     * 可重试但已达上限 -> failed（人工补偿：Ops 工作台可重排）；
     * 不可重试 -> dead（终态：superseded / 事实不符 / 未知类型，绝不复活）。
     *
     * `failed` 与 `dead` 必须分开：前者是“通道或数据库暂时不行，值得人看一眼再重排”，
     * 后者是“这条事实永远不能再投递”。把两者混成一个状态，
     * 运营要么看不到可补偿的失败，要么能重排出已被撤稿覆盖的旧事实。
     *
     * @return 'pending'|'failed'|'dead' 写入后的状态
     *
     * @throws OutboxLeaseLostException 租约已失效 / 已过期
     */
    public function fail(
        int $outboxId,
        string $leaseOwner,
        int $attempts,
        string $lastError,
        bool $retryable,
    ): string {
        $lastError = $this->normalizeError($lastError);
        $maxAttempts = $this->maxAttempts();
        $target = match (true) {
            $retryable && $attempts < $maxAttempts => 'pending',
            $retryable => 'failed',
            default    => 'dead',
        };

        $attributes = [
            'status'      => $target,
            'lease_owner' => null,
            'lease_until' => null,
            'last_error'  => $target === 'pending'
                ? $lastError
                : $target.':'.$lastError,
            'updated_at'  => now(),
        ];

        if ($target === 'pending') {
            $attributes['available_at'] = now('UTC')
                ->addSeconds($this->backoffSeconds($attempts))
                ->format('Y-m-d H:i:s');
        }

        $affected = DB::table('ecosystem_outbox')
            ->where('id', $outboxId)
            ->where('status', 'processing')
            ->where('lease_owner', $leaseOwner)
            ->where('lease_until', '>=', $this->nowString())
            ->update($attributes);

        if ($affected !== 1) {
            throw OutboxLeaseLostException::forRow($outboxId, $leaseOwner);
        }

        return $target;
    }

    /**
     * 恢复过期租约：processing 且 lease_until < now（或为空）→ pending。
     *
     * 不增加 attempts（领取时已加过），不删除记录，不改变 payload。
     *
     * @return int 恢复行数
     */
    public function recoverExpired(): int
    {
        $nowString = $this->nowString();

        return DB::table('ecosystem_outbox')
            ->where('status', 'processing')
            ->where(function ($query) use ($nowString): void {
                $query->whereNull('lease_until')
                    ->orWhere('lease_until', '<', $nowString);
            })
            ->update([
                'status'       => 'pending',
                'lease_owner'  => null,
                'lease_until'  => null,
                // 稳定原因码，便于运维区分“失败重试”与“worker 崩溃后回收”。
                'last_error'   => 'lease_expired',
                'available_at' => $nowString,
                'updated_at'   => now(),
            ]);
    }

    /**
     * 只读统计：到期可领取 / 过期租约。dry-run 用，不写库。
     *
     * @return array{pending_due: int, expired_leases: int}
     */
    public function inspect(): array
    {
        $nowString = $this->nowString();

        return [
            'pending_due' => (int) DB::table('ecosystem_outbox')
                ->where('status', 'pending')
                ->where('available_at', '<=', $nowString)
                ->count(),
            'expired_leases' => (int) DB::table('ecosystem_outbox')
                ->where('status', 'processing')
                ->where(function ($query) use ($nowString): void {
                    $query->whereNull('lease_until')
                        ->orWhere('lease_until', '<', $nowString);
                })
                ->count(),
        ];
    }

    /**
     * 每次领取唯一的 token：host:pid:uuid。
     */
    public function newLeaseOwner(): string
    {
        $host = substr((string) (gethostname() ?: 'host'), 0, 40);

        return sprintf('%s:%d:%s', $host, (int) getmypid(), (string) Str::uuid());
    }

    /**
     * `batch_size` 上限 20（MySQL 5.7 无 SKIP LOCKED，缩短锁持有时间）。
     */
    public function batchSize(): int
    {
        return max(1, min(20, (int) config('ecosystem_outbox.batch_size', 20)));
    }

    public function leaseSeconds(): int
    {
        $seconds = (int) config('ecosystem_outbox.lease_seconds', 300);

        if ($seconds <= 0) {
            throw new InvalidArgumentException('ecosystem_outbox.lease_seconds 必须大于 0。');
        }

        return $seconds;
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('ecosystem_outbox.max_attempts', 5));
    }

    /**
     * 第 N 次尝试失败后的等待秒数；超出列表沿用最后一项。
     */
    public function backoffSeconds(int $attempts): int
    {
        $backoffs = array_values(array_map(
            static fn ($value): int => max(1, (int) $value),
            (array) config('ecosystem_outbox.retry_backoff_seconds', [30, 120, 600, 1800, 3600]),
        ));

        if ($backoffs === []) {
            return 60;
        }

        $index = max(0, min(count($backoffs) - 1, $attempts - 1));

        return $backoffs[$index];
    }

    private function nowString(): string
    {
        return now('UTC')->format('Y-m-d H:i:s');
    }

    private function normalizeError(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        if ($error === '') {
            $error = 'unknown_error';
        }

        return mb_substr($error, 0, 1000);
    }
}
