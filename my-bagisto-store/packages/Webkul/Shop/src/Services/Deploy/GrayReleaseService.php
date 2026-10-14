<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\RollbackRecord;
use Webkul\Shop\Models\SkillVersion;

/**
 * 灰度发布 / 自动回滚 —— v3.4 步骤 8(计划 §4.10)。
 *
 * 策略:白名单 DID / 比例分批(5%→20%→50%→100%)。
 * 自动回滚阈值:安装成功率 < 90% 暂停;< 80% 回滚(向已更新设备推回滚任务)。
 */
class GrayReleaseService
{
    protected const PAUSE_THRESHOLD = 90.0;   // 成功率 < 90% 暂停

    protected const ROLLBACK_THRESHOLD = 80.0; // 成功率 < 80% 回滚

    public function __construct(protected DeployTaskService $deploy) {}

    /**
     * 开启一次灰度。
     */
    public function start(int $skillVersionId, array $strategy = ['percent' => 5], ?string $batchNo = null): GrayRelease
    {
        return GrayRelease::create([
            'skill_version_id' => $skillVersionId,
            'strategy'         => $strategy,
            'batch_no'         => $batchNo ?: 'G'.now()->format('ymdHis'),
            'status'           => 'releasing',
            'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0],
        ]);
    }

    /**
     * 记录一次批次指标。
     */
    public function recordMetric(int $releaseId, int $installs, int $successes, int $failures): ?array
    {
        $release = GrayRelease::find($releaseId);
        if (! $release) {
            return null;
        }

        $release->metrics = [
            'installs'  => $installs,
            'successes' => $successes,
            'failures'  => $failures,
            'success_rate' => $installs > 0 ? round($successes / $installs * 100, 2) : null,
        ];
        $release->save();

        return $this->evaluate($release);
    }

    /**
     * 依据指标决定:继续 / 暂停 / 回滚。
     */
    public function evaluate(GrayRelease $release): array
    {
        $rate = $release->metrics['success_rate'] ?? null;

        if ($rate === null) {
            return ['action' => 'continue', 'reason' => '暂无数据'];
        }

        if ($rate < self::ROLLBACK_THRESHOLD) {
            $this->abort($release, "成功率 {$rate}% < ".self::ROLLBACK_THRESHOLD.'%,自动回滚');

            return ['action' => 'rollback', 'reason' => "成功率 {$rate}% 低于回滚阈值"];
        }

        if ($rate < self::PAUSE_THRESHOLD) {
            $release->status = 'paused';
            $release->save();

            return ['action' => 'pause', 'reason' => "成功率 {$rate}% 低于暂停阈值"];
        }

        return ['action' => 'continue', 'reason' => "成功率 {$rate}% 正常"];
    }

    /**
     * 从库中聚合指标并评估 —— 自驱路径(设备回报后由 DeployTaskService 调用)。
     *
     * 口径:该版本下所有下发任务的 总数 / success / failed+timeout。
     */
    public function evaluateFromRecords(GrayRelease $release): array
    {
        $tasks = DeployTask::query()->where('skill_version_id', $release->skill_version_id);

        $installs = (clone $tasks)->count();
        $successes = (clone $tasks)->where('status', 'success')->count();
        $failures = (clone $tasks)->whereIn('status', ['failed', 'timeout'])->count();

        return $this->recordMetric($release->id, $installs, $successes, $failures) ?? [];
    }

    /**
     * 中止并回滚:向"已更新该版本"的设备推回滚任务。
     *
     * @param  string  $trigger  auto(阈值命中)/ manual(人工)
     */
    public function abort(GrayRelease $release, string $reason = '', string $trigger = 'auto'): int
    {
        $release->status = 'aborted';
        $release->save();

        // ⚠ 原实现写死 skillId: 0 —— 会让 deploy_task.skill_id 全错,
        //   连带 KillSwitchService 按 product 匹配失效。必须从版本反查 product_id。
        $productId = (int) (SkillVersion::find($release->skill_version_id)?->product_id ?? 0);

        $updated = InstallRecord::query()
            ->where('skill_version_id', $release->skill_version_id)
            ->where('status', 'updated')
            ->get();

        $count = 0;
        foreach ($updated as $rec) {
            $this->deploy->create(
                did: $rec->did,
                skillId: $productId,
                skillVersionId: $release->skill_version_id,
                entitlementId: $rec->entitlement_id,
                operation: DeployTask::OP_ROLLBACK,
                channel: 'agent',
                createdBy: 'gray_rollback',
            );
            $count++;
        }

        RollbackRecord::create([
            'gray_release_id'  => $release->id,
            'trigger'          => $trigger,
            'reason'           => $reason,
            'affected_devices' => $count,
            'created_at'       => now(),
        ]);

        return $count;
    }
}
