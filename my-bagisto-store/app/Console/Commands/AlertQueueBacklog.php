<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 队列健康告警（v3.4 第 10 步）。
 *
 *   php artisan ops:alert-queue-backlog [--dry-run]
 *
 * **判据是"最老的 job 躺了多久"，不是"积压了多少条"**：
 *   积压 200 条但 worker 正在飞速消费，是正常高峰；
 *   积压 3 条但最老那条已经躺了 6 小时，说明 **worker 根本没在跑** ——
 *   后者才是要报警的事，而单看条数这两者长得一模一样。
 *
 * 本项目当前的实情正是后者：`jobs` 表有积压且从未被消费过
 * （没有常驻 queue:work）。这个命令会让它**可见**，而不是替谁决定要不要消费 ——
 * 消费会补发历史订单邮件，那是业务决策，不是技术默认。
 */
class AlertQueueBacklog extends Command
{
    protected $signature = 'ops:alert-queue-backlog
        {--minutes=30 : 超过这个时长还没被消费就告警}
        {--dry-run : 只报告将要做什么}';

    protected $description = '检测队列是否无人消费（按最老 job 的滞留时长）并发平台告警';

    public function handle(AdminAlertService $alerts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $threshold = max(1, (int) $this->option('minutes'));

        if (! Schema::hasTable('jobs')) {
            $this->warn('没有 jobs 表（队列不是 database 驱动）—— 跳过。');

            return self::SUCCESS;
        }

        // ---- 1) 最老的待消费 job 躺了多久
        $oldest = DB::table('jobs')->orderBy('available_at')->first();
        $pending = DB::table('jobs')->count();

        $stuckMinutes = null;

        if ($oldest) {
            // 用 available_at（"这条 job 什么时候可以被消费"）而不是 created_at：
            // 带延迟的 job 本来就不该被立刻消费，用它判会误报。
            $stuckMinutes = (int) now()->diffInMinutes(
                \Illuminate\Support\Carbon::createFromTimestamp($oldest->available_at)
            );
        }

        // ---- 2) 失败队列（有就是有问题，不需要阈值）
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $this->line(sprintf(
            '%s待消费 %d 条；最老一条滞留 %s；失败队列 %d 条。',
            $dryRun ? '[DRY-RUN] ' : '',
            $pending,
            $stuckMinutes === null ? '—' : $stuckMinutes.' 分钟',
            $failed
        ));

        $sent = 0;

        if ($stuckMinutes !== null && $stuckMinutes >= $threshold) {
            $title = sprintf('队列无人消费：最老任务已滞留 %d 分钟', $stuckMinutes);

            $content = sprintf(
                "待消费任务：%d 条\n最老一条可执行于：%s（滞留 %d 分钟）\n\n"
                ."这通常意味着 queue:work 没有在跑。请检查常驻进程，"
                ."或确认部署清单里的调度/队列步骤是否执行过。\n\n"
                ."⚠ 消费积压会补发历史通知/邮件 —— 这是业务决策，请先确认再消费，不要直接清表。",
                $pending,
                \Illuminate\Support\Carbon::createFromTimestamp($oldest->available_at)->toDateTimeString(),
                $stuckMinutes
            );

            if ($dryRun) {
                $this->line('   · '.$title);
            } else {
                $sent += $alerts->alert(
                    'queue_stuck',
                    $title,
                    $content,
                    'queue:stuck',
                    ['pending' => $pending, 'stuck_minutes' => $stuckMinutes],
                    $stuckMinutes >= $threshold * 4
                        ? AdminAlertService::LEVEL_URGENT
                        : AdminAlertService::LEVEL_WARN,
                );
            }
        }

        if ($failed > 0) {
            $title = sprintf('队列有 %d 条失败任务', $failed);

            if ($dryRun) {
                $this->line('   · '.$title);
            } else {
                $sent += $alerts->alert(
                    'queue_failed',
                    $title,
                    sprintf("failed_jobs 表有 %d 条记录。\n\n失败任务不会自愈 —— 需要人工看 `php artisan queue:failed` 并决定重试或丢弃。", $failed),
                    // ⚠ 去重键带上条数：失败数变了要能再报一次（同一个键会一直被去重吞掉）
                    'queue:failed:'.$failed,
                    ['failed' => $failed],
                    AdminAlertService::LEVEL_WARN,
                );
            }
        }

        $this->info($dryRun ? '（dry-run，未写入）' : sprintf('已发出 %d 条告警。', $sent));

        return self::SUCCESS;
    }
}
