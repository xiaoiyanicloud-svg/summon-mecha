<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\DeployTaskService;

/**
 * 下发任务的服务端超时兜底（P6）。
 *
 *   php artisan ops:timeout-deploy-tasks [--dry-run]
 *
 * 为什么必须有这条:
 *   一个下发任务只有两种方式离开"进行中":**设备回报**,或**被取消**。
 *   而设备是随时会掉线的外部实体 —— 它可能带着一条 `dispatching` 永远不再上报。
 *   在此之前服务端**没有任何兜底**:给一台长期离线的设备下发,任务会永久停在
 *   `pending_online`,用户界面永远显示"下发中"(既不成功也不失败,且没人知道)。
 *
 * 两类靶子,阈值分开 —— 它们的"正常等待时间"根本不是一个量级:
 *   · `pending_online`  —— 设备离线,等它上线。离线可以持续很久(用户关机、断网),
 *                          故阈值按**小时**(默认 24h,从 created_at 起算)。
 *   · `dispatching/downloading/installing` —— 设备**已经拿到了**这条指令在装,
 *                          正常是秒级到分钟级;迟迟不回报基本就是装到一半断了,
 *                          故阈值按**分钟**(默认 30min,从 delivered_at 起算)。
 *
 * ⚠ 只置 `timeout`,**不重试、不删记录**:超时是"这台设备这次没成",不是"这条应当消失"。
 *   要不要重发是人的判断(或后续单独的重试策略),自动重发会在"设备真的坏了"时无限刷。
 *   本命令与 `AlertQueueBacklog` 同一立场:让问题**可见**,而不是替谁做业务决定。
 */
class TimeoutDeployTasks extends Command
{
    protected $signature = 'ops:timeout-deploy-tasks
        {--pending-hours=24 : pending_online 超过这个时长仍无设备上线就判超时}
        {--inflight-minutes=30 : dispatching/downloading/installing 超过这个时长未回报就判超时}
        {--dry-run : 只报告将要做什么}';

    protected $description = '超时未回报的下发任务置 timeout（pending_online 与在途任务的服务端兜底）';

    public function handle(DeployTaskService $deploy): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pendingHours = max(1, (int) $this->option('pending-hours'));
        $inflightMinutes = max(1, (int) $this->option('inflight-minutes'));

        $pendingCutoff = now()->subHours($pendingHours);
        $inflightCutoff = now()->subMinutes($inflightMinutes);

        // ---- 1) pending_online:设备始终没上线取走（从建任务起算）
        $pending = DeployTask::query()
            ->where('status', 'pending_online')
            ->where('created_at', '<', $pendingCutoff)
            ->get();

        // ---- 2) 在途:已下发(或已取走)但迟迟不回报
        //   · 已取走的看 delivered_at（取走那一刻,最准）;
        //   · 未取走的看 updated_at（进入该状态那一刻）。
        $inflight = DeployTask::query()
            ->whereIn('status', ['dispatching', 'downloading', 'installing'])
            ->where(function ($q) use ($inflightCutoff) {
                $q->where('delivered_at', '<', $inflightCutoff)
                    ->orWhere(function ($q2) use ($inflightCutoff) {
                        $q2->whereNull('delivered_at')->where('updated_at', '<', $inflightCutoff);
                    });
            })
            ->get();

        $this->line(sprintf(
            '%s待判超时:pending_online %d 条(>%dh);在途 %d 条(>%dm)。',
            $dryRun ? '[DRY-RUN] ' : '',
            $pending->count(),
            $pendingHours,
            $inflight->count(),
            $inflightMinutes,
        ));

        $timedOut = 0;

        foreach ($pending->concat($inflight) as $task) {
            if ($dryRun) {
                $this->line(sprintf('   · %s (%s) 建于 %s', $task->task_no, $task->status, $task->created_at));

                continue;
            }

            // 走状态机(唯一的流转入口):非法流转会被 transition 拒绝,不会硬写。
            if ($deploy->transition($task, 'timeout')) {
                $timedOut++;
            } else {
                // 期间设备已经回报、状态已变 —— 说明它没超时,跳过即可(不是错误)。
                $this->line(sprintf('   · %s 未超时(当前 %s),跳过。', $task->task_no, $task->fresh()->status));
            }
        }

        $this->info($dryRun
            ? '（dry-run，未写入）'
            : sprintf('已将 %d 条超时任务置为 timeout。', $timedOut));

        return self::SUCCESS;
    }
}
