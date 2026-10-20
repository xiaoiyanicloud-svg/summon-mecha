<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Services\Ecosystem\RegistrationChangeService;

/**
 * G2-08 · 变更确认到期扫描。
 *
 * 只对已发布、requires_ack=true、ack_deadline 已过的变更补写 no_response；
 * 是否释放席位由变更自身政策决定。未响应永不视为同意。
 */
class ExpireRegistrationChangeAcks extends Command
{
    protected $signature = 'ecosystem:expire-registration-change-acks
        {--limit= : 本轮最多处理变更数（默认 200）}
        {--dry-run : 只统计到期变更，不写库}';

    protected $description = '处理逾期未确认的活动变更（no_response / 按政策释放席位）';

    public function handle(RegistrationChangeService $changes): int
    {
        $limit = max(1, min(1000, (int) ($this->option('limit') ?: 200)));
        $now = now('UTC')->format('Y-m-d H:i:s');

        $due = (int) DB::table('event_changes')
            ->where('status', 'published')
            ->where('requires_ack', 1)
            ->whereNotNull('ack_deadline')
            ->where('ack_deadline', '<', $now)
            ->count();

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf('[DRY-RUN] 到期未处理变更 %d 条；本轮上限 %d。未写库。', $due, $limit));

            return self::SUCCESS;
        }

        try {
            $processed = $changes->expireAcks($limit);
        } catch (Throwable $exception) {
            $this->error('变更确认到期扫描失败：'.$exception::class.' '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('写入 no_response / 按政策处理：%d 条。', $processed));

        return self::SUCCESS;
    }
}
