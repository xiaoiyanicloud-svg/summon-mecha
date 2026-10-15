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
 * 安全事件时:① 阻断新下发 ② (可选)对已装设备建 agent 卸载/回滚任务(生效范围见下)。
 * ⚠ 复用 §4.10 的思路做分批:这里只建任务 + 交网关,真正的分批/限流由网关/队列控。
 *   (Mock 网关为同步,生产 MQTT 需分批,已在计划中列为注意项)
 *
 * ── S-5 · 熔断的**真实生效范围**(D9 原话"一键禁用已安装设备",事实有三处约束) ──
 *   ① 对新下发:**即时生效** —— 命中即拒建新任务(`DeployTaskService::assertNotBlocked()`)。
 *   ② 对已装设备:建 `channel='agent'` 的卸载/回滚任务,但传输是 HTTP **拉**
 *      (`HttpPollAgentGateway`)。设备离线时任务落 `pending_online`,要等它**下次上线**
 *      才取得走 —— 不是"按下去就当场停了"。在线设备则立刻进入可拉取状态。
 *   ③ 人工通道(app/manual)安装的设备:**当前无法自动卸载** —— 平台没有回程通道。
 *      (此前这里对每一条 install 记录都建 agent 任务,连人工通道装上的也照建,
 *       把"永远没人来拉的任务"报成"已建卸载任务"。S-5 改为不再假装,如实分列。)
 *   故 `engage()` 不再只回一个笼统数字,而是分列三类(见返回值):
 *     即时在线 / 离线待上线 / 无法自动卸载。
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
     * @return array{blocklist_id: int, affected: int, forced_tasks: int, forced_online: int, forced_offline: int, unable_auto_uninstall: int}
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
            return [
                'blocklist_id'          => $existing->id,
                'affected'              => $existing->affected_devices,
                'forced_tasks'          => 0,
                'forced_online'         => 0,
                'forced_offline'        => 0,
                'unable_auto_uninstall' => 0,
            ];
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

        // 需强制处置的动作 → 建卸载/回滚任务。
        //
        // ⚠ 三类数字必须**从库里的真实状态算**,不许写一个好看的数字:
        //   · 在线 → 任务立刻可被拉取(dispatching);离线 → 落 pending_online(等下次上线);
        //   · 人工通道/通道未知/设备不可达 → **建不出**能生效的任务,如实计入"无法自动卸载"。
        $forcedOnline = 0;
        $forcedOffline = 0;
        $unable = 0;

        if (in_array($action, [SkillBlocklist::ACTION_FORCE_UNINSTALL, SkillBlocklist::ACTION_FORCE_ROLLBACK], true)) {
            $op = $action === SkillBlocklist::ACTION_FORCE_UNINSTALL ? DeployTask::OP_UNINSTALL : DeployTask::OP_ROLLBACK;

            foreach ($installs as $rec) {
                // 只有"经 agent 推下去的"才代表平台有 agent 回程能撤回来 —— 见 canAutoUninstall()。
                // 人工通道/通道未知一律不建任务:建了也拉不动,那正是 S-5 要修掉的谎。
                if (! $this->canAutoUninstall($rec)) {
                    $unable++;

                    continue;
                }

                $task = $this->deploy->create(
                    did: $rec->did,
                    skillId: $skillId,
                    skillVersionId: $rec->skill_version_id,
                    entitlementId: $rec->entitlement_id,
                    operation: $op,
                    channel: 'agent',
                    createdBy: 'killswitch',
                );

                // 分类只**如实读** dispatch 落在库里的结果,不在这里另做一次在线推断
                // —— 在线判定/挂起逻辑的唯一实现是 DeployTaskService::dispatch()。
                if ($task->status === 'pending_online') {
                    $forcedOffline++;
                } elseif (in_array($task->status, ['failed', 'timeout', 'cancelled'], true)) {
                    // 建了任务却投不出去(设备已解绑/不存在)→ 不能算"生效",同样归入无法自动卸载。
                    $unable++;
                } else {
                    $forcedOnline++;
                }
            }
        }

        $forced = $forcedOnline + $forcedOffline;

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
                'operator'              => $actor,
                'blocklist_id'          => $block->id,
                'action'                => $action,
                'severity'              => $severity,
                'reason'                => $reason,
                'affected_devices'      => $block->affected_devices,
                'forced_tasks'          => $forced,
                'forced_online'         => $forcedOnline,
                'forced_offline'        => $forcedOffline,
                'unable_auto_uninstall' => $unable,
            ],
            $ip
        );

        // 熔断是 D9 的安全动作,除了落库留痕也要在**日志里**可见 ——
        // `Services/Deploy` 此前一行日志都没有,运维翻日志时看不到任何下发侧安全事件。
        Log::warning('技能熔断已生效', [
            'skill_id'              => $skillId,
            'action'                => $action,
            'severity'              => $severity,
            'actor'                 => $actor,
            'affected_devices'      => $block->affected_devices,
            'forced_tasks'          => $forced,
            'forced_online'         => $forcedOnline,
            'forced_offline'        => $forcedOffline,
            'unable_auto_uninstall' => $unable,
        ]);

        return [
            'blocklist_id'          => $block->id,
            'affected'              => $block->affected_devices,
            'forced_tasks'          => $forced,
            'forced_online'         => $forcedOnline,
            'forced_offline'        => $forcedOffline,
            'unable_auto_uninstall' => $unable,
        ];
    }

    /**
     * 该安装记录能否**经 agent 通道**自动卸载。
     *
     * 唯一判据是 `channel === 'agent'`：只有"经 agent 推下去的"才代表平台对那台设备
     * 有 agent 回程、能经 agent 撤回来。
     *   · 人工通道(app/manual)、厂商通道 → 平台从未控制那条链路，无回程；
     *   · NULL（历史行，通道未知）→ 无从判断，同样按"无法自动卸载"处理。
     * 两类都**不**凭空建一条 agent 卸载任务假装覆盖到了 —— 宁可如实报"无法自动卸载"，
     * 也不拿一个永远拉不动的任务冒充"覆盖到了"（这正是 S-5 要修的谎）。
     */
    protected function canAutoUninstall(InstallRecord $rec): bool
    {
        return $rec->channel === 'agent';
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
