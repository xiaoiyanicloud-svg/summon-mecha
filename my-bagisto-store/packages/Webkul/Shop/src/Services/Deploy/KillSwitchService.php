<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\Log;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 熔断服务 —— v3.4 步骤 8(计划 §4.19)。
 *
 * 安全事件时:① 阻断新下发 ② (可选)对已装设备批量卸载/回滚。
 * ⚠ 复用 §4.10 的思路做分批:这里只建任务 + 交网关,真正的分批/限流由网关/队列控。
 *   (Mock 网关为同步,生产 MQTT 需分批,已在计划中列为注意项)
 *
 * ── P10 · 熔断是 D9 的安全动作,必须留痕到"人" ──
 *   此前 `engage()` 的 `$createdBy` 默认写死 `'admin'`,而唯一调用方
 *   (`SkillSupplyController::block`)不传 → **每个熔断都记成 admin**,真实调用方丢失。
 *   编造的身份比留空更坏:它看起来像有留痕。现在默认是 `internal`(内部通道),
 *   调用方若能自报身份则记 `internal:<operator>`;动作同时进 `operation_log`(带 IP)。
 */
class KillSwitchService
{
    /**
     * 调用方没有自报身份时的兜底 —— **不要退回 'admin'**。
     *
     * 熔断走的是 internal.key 共享密钥通道,服务端没有任何已认证的管理员身份;
     * 写 'admin' 等于替调用方编一个身份。
     */
    public const ACTOR_INTERNAL = 'internal';

    public function __construct(
        protected DeployTaskService $deploy,
        protected OperationLogger $oplog,
    ) {}

    /**
     * 触发熔断。
     *
     * @param  string|null  $createdBy  调用方自报的身份(internal[:operator]);null → 记 internal
     * @param  string|null  $ip         来源 IP(HTTP 调用方传 request()->ip())
     * @return array{blocklist_id: int, affected: int, forced_tasks: int}
     */
    public function engage(
        int $skillId,
        string $action = SkillBlocklist::ACTION_BLOCK_ALL,
        string $reason = '',
        string $severity = 'high',
        ?string $createdBy = null,
        ?string $ip = null,
    ): array {
        $actor = $createdBy ?: self::ACTOR_INTERNAL;

        // 已有生效熔断则不重复(也不留痕 —— 重复点一次不是一次新的安全动作)
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
            'created_by'       => $actor,
            'ip'               => $ip,
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

        // 审计:熔断是安全动作,`operation_log` 里要能查到"谁、从哪、为什么按的"。
        // 用 log() 而非 logOrFail():留痕是旁路,不该因为审计表写不进去就让熔断失败
        // —— 熔断失效的代价远大于少一条审计行(写失败会以 error 级进日志)。
        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            null,
            'killswitch.engage',
            'skill',
            $skillId,
            [
                'operator'         => $actor,
                'blocklist_id'     => $block->id,
                'action'           => $action,
                'severity'         => $severity,
                'reason'           => $reason,
                'affected_devices' => $block->affected_devices,
                'forced_tasks'     => $forced,
            ],
            $ip
        );

        // 熔断是 D9 的安全动作,除了落库留痕也要在**日志里**可见 ——
        // `Services/Deploy` 此前一行日志都没有,运维翻日志时看不到任何下发侧安全事件。
        Log::warning('技能熔断已生效', [
            'skill_id'         => $skillId,
            'action'           => $action,
            'severity'         => $severity,
            'actor'            => $actor,
            'affected_devices' => $block->affected_devices,
            'forced_tasks'     => $forced,
        ]);

        return ['blocklist_id' => $block->id, 'affected' => $block->affected_devices, 'forced_tasks' => $forced];
    }

    /**
     * 解除熔断。
     *
     * 解除是熔断的对称动作(把闸重新抬起来),同样要留痕 —— 只记"按下去"不记"抬起来",
     * 复盘时会看到一条永远生效的熔断。
     */
    public function lift(int $skillId, ?string $createdBy = null, ?string $ip = null): bool
    {
        $block = SkillBlocklist::activeFor($skillId);
        if (! $block) {
            return false;
        }

        $block->status = SkillBlocklist::STATUS_LIFTED;
        $block->lifted_at = now();
        $block->save();

        Log::info('技能熔断已解除', [
            'skill_id'     => $skillId,
            'blocklist_id' => $block->id,
            'actor'        => $createdBy ?: self::ACTOR_INTERNAL,
        ]);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            null,
            'killswitch.lift',
            'skill',
            $skillId,
            [
                'operator'     => $createdBy ?: self::ACTOR_INTERNAL,
                'blocklist_id' => $block->id,
            ],
            $ip
        );

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
