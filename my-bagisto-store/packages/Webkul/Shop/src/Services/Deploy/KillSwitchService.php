<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillBlocklist;

/**
 * 熔断服务 —— v3.4 步骤 8(计划 §4.19)。
 *
 * 安全事件时:① 阻断新下发 ② (可选)对已装设备批量卸载/回滚。
 * ⚠ 复用 §4.10 的思路做分批:这里只建任务 + 交网关,真正的分批/限流由网关/队列控。
 *   (Mock 网关为同步,生产 MQTT 需分批,已在计划中列为注意项)
 */
class KillSwitchService
{
    public function __construct(
        protected DeployTaskService $deploy,
    ) {}

    /**
     * 触发熔断。
     *
     * @return array{blocklist_id: int, affected: int, forced_tasks: int}
     */
    public function engage(
        int $skillId,
        string $action = SkillBlocklist::ACTION_BLOCK_ALL,
        string $reason = '',
        string $severity = 'high',
        ?string $createdBy = 'admin',
    ): array {
        // 已有生效熔断则不重复
        $existing = SkillBlocklist::activeFor($skillId);
        if ($existing) {
            return ['blocklist_id' => $existing->id, 'affected' => $existing->affected_devices, 'forced_tasks' => 0];
        }

        // 找出已装该技能的设备
        $installs = InstallRecord::query()
            ->whereIn('skill_version_id', function ($sub) use ($skillId) {
                // 该技能下所有版本的 install 记录(简化:用 skill_version 关联,这里按 skill_version_id 里含 skill 的近似处理)
                $sub->select('id')->from('skill_versions')->where('product_id', $skillId);
            })
            ->where('status', 'installed')
            ->get();

        $block = SkillBlocklist::create([
            'skill_id'         => $skillId,
            'reason'           => $reason,
            'severity'         => $severity,
            'action'           => $action,
            'status'           => SkillBlocklist::STATUS_ACTIVE,
            'affected_devices' => $installs->count(),
            'created_by'       => $createdBy,
        ]);

        // 需强制处置的动作 → 建卸载/回滚任务
        $forced = 0;
        if (in_array($action, [SkillBlocklist::ACTION_FORCE_UNINSTALL, SkillBlocklist::ACTION_FORCE_ROLLBACK], true)) {
            $op = $action === SkillBlocklist::ACTION_FORCE_UNINSTALL ? DeployTask::OP_UNINSTALL : DeployTask::OP_ROLLBACK;
            foreach ($installs as $rec) {
                $this->deploy->create(
                    did: $rec->did,
                    skillId: $skillId,
                    skillVersionId: $rec->skill_version_id,
                    entitlementId: $rec->entitlement_id,
                    operation: $op,
                    channel: 'agent',
                    createdBy: 'killswitch',
                );
                $forced++;
            }
        }

        return ['blocklist_id' => $block->id, 'affected' => $block->affected_devices, 'forced_tasks' => $forced];
    }

    /**
     * 解除熔断。
     */
    public function lift(int $skillId): bool
    {
        $block = SkillBlocklist::activeFor($skillId);
        if (! $block) {
            return false;
        }

        $block->status = SkillBlocklist::STATUS_LIFTED;
        $block->lifted_at = now();
        $block->save();

        return true;
    }

    /**
     * 某技能是否已被熔断(下发前校验)。
     */
    public function isBlocked(int $skillId, string $forAction = 'new'): bool
    {
        $block = SkillBlocklist::activeFor($skillId);
        if (! $block) {
            return false;
        }

        // block_new 只拦新下发;其余动作也拦新下发
        return $forAction === 'new';
    }
}
