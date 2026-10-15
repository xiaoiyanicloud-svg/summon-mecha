<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\SkillDelivery\TrialService;

/**
 * 试用到期回收（B-3 · 计划 §4.7「20 分钟自动回收」）。
 *
 *   php artisan ops:reclaim-expired-trials [--dry-run]
 *
 * 为什么必须有这条（而不是"到期了用户自己会卸载"）：
 *   试用 = 一条 20 分钟的临时授权。时间一到，**平台有责任把技能从设备上收回来** ——
 *   否则"20 分钟"只是页面上的一个倒计时，机器上跑着的还是完整能力，试用等于白送。
 *
 * 每一步都走既有底座（本命令只做"发现+触发"，不自己写状态机/不自己发指令）：
 *   · 扫描     `(status, expires_at)` 索引（计划 §3.4 就是为这条建的）
 *   · 认领     条件更新 `status → expiring`（`TrialService::reclaimDue()`）
 *   · 回收     复用 `DeployTaskService::create(operation=OP_RECLAIM)`（OP_RECLAIM=6 已在白名单）
 *   · 收尾     设备回报成功后 `expiring → expired` + 站内通知（`DeployTaskService::onEvent()`）
 *
 * ⚠ 跑到 `expiring` 却还没回收完的授权**不会**被本命令重复处理
 *   （`whereNotIn(status, [expiring, ...])`）—— 回收任务自己会推进终态。
 *   设备长期离线时它会一直挂在 `expiring`：这是**如实**的"还没收回"，不是 bug。
 */
class ReclaimExpiredTrials extends Command
{
    protected $signature = 'ops:reclaim-expired-trials
        {--dry-run : 只统计到期的试用授权，不做任何写操作}';

    protected $description = '扫描到期的试用授权，发回收任务（operation=6）并推进状态机';

    public function handle(TrialService $trials): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $r = $trials->reclaimDue($dryRun);

        $this->line(sprintf(
            '%s到期试用候选 %d 条。',
            $dryRun ? '[DRY-RUN] ' : '',
            $r['candidates'],
        ));

        if ($dryRun) {
            $this->info('（dry-run，未写入）');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '认领 %d 条 → 发回收任务 %d 条 / 无需回收直接过期 %d 条 / 建任务失败退回 %d 条。',
            $r['reclaimed'],
            $r['tasks'],
            $r['expired'],
            $r['failed'],
        ));

        return self::SUCCESS;
    }
}
