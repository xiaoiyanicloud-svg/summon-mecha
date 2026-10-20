<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * G4-06 · 详情访问记录（日聚合）。
 *
 * 隐私边界：
 *   · 只落库 `日期 + 对象 + 次数`，**不存 IP / UA / 用户 ID / 访客哈希**；
 *   · 去重键只在 cache 中短时存在（默认 1 小时），用 HMAC 派生，不写日志；
 *   · 登录用户用 `c:{id}` 去重，不落库；游客用 `HMAC(app.key, ip|ua|date)`。
 *
 * 失败只记 warning，不影响详情页返回：访问统计不能把内容页打成 500。
 */
class ContentViewRecorder
{
    public const TARGET_EVENT = 'event';

    public const TARGET_CONTENT = 'content';

    public const TARGETS = [self::TARGET_EVENT, self::TARGET_CONTENT];

    public function record(string $targetType, string $targetKey, ?int $customerId = null): void
    {
        if (! $this->enabled() || ! in_array($targetType, self::TARGETS, true)) {
            return;
        }

        $targetKey = trim($targetKey);

        if ($targetKey === '' || mb_strlen($targetKey) > 191) {
            return;
        }

        try {
            $dedupeKey = $this->dedupeKey($targetType, $targetKey, $customerId);
            $ttl = max(60, (int) config('ecosystem_g4.metrics.view_dedupe_seconds', 3600));

            if (! Cache::add($dedupeKey, 1, $ttl)) {
                return;
            }

            $this->increment($targetType, $targetKey);
        } catch (Throwable $exception) {
            Log::warning('ecosystem.content_view.record_failed', [
                'target_type' => $targetType,
                'reason'      => $exception->getMessage(),
            ]);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('ecosystem_g4.metrics.content_views_enabled', false);
    }

    private function increment(string $targetType, string $targetKey): void
    {
        $today = now('UTC')->toDateString();

        $updated = DB::table('ecosystem_content_view_daily')
            ->where('view_date', $today)
            ->where('target_type', $targetType)
            ->where('target_key', $targetKey)
            ->increment('views');

        if ($updated > 0) {
            return;
        }

        try {
            DB::table('ecosystem_content_view_daily')->insert([
                'view_date'   => $today,
                'target_type' => $targetType,
                'target_key'  => $targetKey,
                'views'       => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (Throwable $exception) {
            // 并发下另一个请求刚插入：改为自增；仍失败则交给外层 warning。
            $updated = DB::table('ecosystem_content_view_daily')
                ->where('view_date', $today)
                ->where('target_type', $targetType)
                ->where('target_key', $targetKey)
                ->increment('views');

            if ($updated === 0) {
                throw $exception;
            }
        }
    }

    private function dedupeKey(string $targetType, string $targetKey, ?int $customerId): string
    {
        if ($customerId !== null && $customerId > 0) {
            $visitor = 'c:'.$customerId;
        } else {
            $ip = (string) request()->ip();
            $ua = (string) request()->userAgent();
            $visitor = hash_hmac(
                'sha256',
                $ip.'|'.$ua.'|'.now('UTC')->toDateString(),
                (string) config('app.key'),
            );
        }

        return sprintf(
            'ecosystem:view:%s:%s:%s',
            $targetType,
            $targetKey,
            substr(hash('sha256', $visitor), 0, 24),
        );
    }
}
