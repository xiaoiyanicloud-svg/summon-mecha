<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ecosystem\RegistrationReviewService;

/**
 * G2-06 · 审核到期扫描。
 *
 * 只处理 `submitted` 且 scope 为 manual、review_closes_at 已过的轮次：
 * 置 `rejected`（review_expired），不释放不存在的席位，通知走 outbox。
 *
 * 手工演练：php artisan ecosystem:expire-registration-reviews --dry-run
 * 调度器仅在 `ecosystem_registration.review_expiry.enabled=true` 时触发；
 * 手工执行不受开关限制（运维显式动作）。
 */
class ExpireRegistrationReviews extends Command
{
    protected $signature = 'ecosystem:expire-registration-reviews
        {--limit= : 本轮最多处理条数（默认 config，上限 1000）}
        {--dry-run : 只统计到期条数，不写库}';

    protected $description = '把审核截止后仍未处理的报名置为 rejected（review_expired）';

    public function handle(RegistrationReviewService $reviews): int
    {
        $limit = (int) ($this->option('limit') ?: config('ecosystem_registration.review_expiry.batch_size', 200));
        $limit = max(1, min(1000, $limit));

        if ((bool) $this->option('dry-run')) {
            $now = now('UTC')->format('Y-m-d H:i:s');

            $count = (int) DB::table('event_registration_attempts as a')
                ->join('event_registration_scopes as s', 's.id', '=', 'a.scope_id')
                ->where('a.status', EventRegistrationAttempt::STATUS_SUBMITTED)
                ->where('s.review_mode', 'manual')
                ->whereNotNull('s.review_closes_at')
                ->where('s.review_closes_at', '<', $now)
                ->count();

            $this->line(sprintf('[DRY-RUN] 到期未审 %d 条；本轮上限 %d。未写库。', $count, $limit));

            return self::SUCCESS;
        }

        try {
            $processed = $reviews->expireOverdue($limit);
        } catch (Throwable $exception) {
            $this->error('审核到期扫描失败：'.$exception::class.' '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('已置为 review_expired：%d 条。', $processed));

        return self::SUCCESS;
    }
}
