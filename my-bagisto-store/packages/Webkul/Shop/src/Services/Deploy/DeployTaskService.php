<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;

/**
 * 下发任务服务 —— 状态机 —— v3.4 步骤 8(计划 §4.6)。
 *
 *   pending → dispatching → downloading → installing → success
 *                   │            │
 *                   │            └→ download_failed → 重试 → failed
 *                   ├→ pending_online(设备离线,上线再取)
 *                   └→ cancelled
 *
 * 职责:建任务 + 签名 + 经网关下发 + 接收回报推进状态 + 落 install_record。
 * 传输细节在 AgentGateway(Mock 或 MQTT),本服务只管状态机与落库。
 */
class DeployTaskService
{
    /**
     * 熔断**不拦**的操作 —— 它们是熔断的补救手段。
     *
     * 熔断的语义是"阻断新下发"(见 KillSwitchService::isBlocked 的 forAction='new')。
     * 卸载/回滚/回收是事故发生后**往外撤**的动作:若把这三个也拦住,熔断一旦生效,
     * 连"批量卸载已装的问题技能"都做不了 —— 熔断自己制造了无法收拾的现场。
     */
    protected const REMEDIATION_OPS = [
        DeployTask::OP_UNINSTALL,
        DeployTask::OP_ROLLBACK,
        DeployTask::OP_RECLAIM,
    ];

    /** 合法的状态流转(防乱序/防回退)。 */
    protected const TRANSITIONS = [
        'pending'        => ['dispatching', 'pending_online', 'cancelled'],
        'dispatching'    => ['downloading', 'installing', 'success', 'failed', 'timeout', 'pending_online', 'cancelled'],
        'downloading'    => ['installing', 'success', 'failed', 'timeout', 'cancelled'],
        'installing'     => ['success', 'failed', 'timeout'],
        'pending_online' => ['dispatching', 'success', 'failed', 'cancelled'],
        'success'        => [],
        'failed'         => [],
        'timeout'        => [],
        'cancelled'      => [],
    ];

    public function __construct(
        protected SigningService $signing,
        protected AgentGatewayManager $gateways,
    ) {}

    /**
     * 创建一个下发任务并(尽力)下发。
     *
     * @param  int  $operation  DeployTask::OP_*
     */
    public function create(
        string $did,
        int $skillId,
        ?int $skillVersionId,
        ?int $entitlementId,
        int $operation,
        string $channel = 'agent',
        ?string $createdBy = 'system',
        ?string $packageUrl = null,
        ?string $packageHash = null,
    ): DeployTask {
        // 熔断闸:命中即拒建(不落库)。补救类动作(卸/回滚/回收)放行 —— 见 REMEDIATION_OPS。
        // 这是 D9「熔断可一键禁用」的硬缺口:此前 isBlocked() 全仓零调用,按钮按下去不下闸。
        $this->assertNotBlocked($skillId, $operation);

        $task = DeployTask::create([
            'task_no'          => $this->newTaskNo(),
            'did'              => $did,
            'channel'          => $channel,
            'skill_id'         => $skillId,
            'skill_version_id' => $skillVersionId,
            'entitlement_id'   => $entitlementId,
            'operation'        => $operation,
            'status'           => 'pending',
            'package_url'      => $packageUrl,
            'package_hash'     => $packageHash,
            'created_by'       => $createdBy,
        ]);

        $this->dispatch($task);

        return $task->fresh();
    }

    /**
     * 经网关下发(签名 + 在线判定)。
     */
    public function dispatch(DeployTask $task): void
    {
        $gateway = $this->gateways->gateway();

        // 设备离线 → 挂起(pending_online),上线(轮询)后再发
        if (! $gateway->isOnline($task->did)) {
            $this->transition($task, 'pending_online');
            return;
        }

        $result = $gateway->dispatch($task->did, $this->buildCommand($task));

        $this->transition($task, $result['ok'] ? 'dispatching' : 'failed');

        if (! $result['ok']) {
            $task->error_msg = $result['message'];
            $task->save();
        }
    }

    /**
     * 构造一条下发指令(含签名) —— 推/拉两种传输都复用它,避免端上各写各的。
     */
    public function buildCommand(DeployTask $task): array
    {
        $version = $task->skill_version_id ? SkillVersion::find($task->skill_version_id) : null;

        $ts = now()->timestamp;
        $canonical = $this->signing->canonical([
            'task_id'      => $task->task_no,
            'skill_id'     => $task->skill_id,
            'version'      => $version?->version,
            'package_hash' => $task->package_hash,
            'ts'           => $ts,
        ]);

        return [
            'task_id'      => $task->task_no,
            'operation'    => $task->operation,
            'skill_id'     => $task->skill_id,
            'version'      => $version?->version,
            'package_url'  => $task->package_url,
            'package_hash' => $task->package_hash,
            'signature'    => $this->signing->sign($canonical),
            'ts'           => $ts,
        ];
    }

    /**
     * 接收设备回报,推进状态机。
     *
     * @param  string  $phase  downloading|installing|done|failed
     */
    public function onEvent(string $taskNo, string $phase, ?string $errorCode = null, ?string $errorMsg = null): ?DeployTask
    {
        $task = DeployTask::query()->where('task_no', $taskNo)->first();
        if (! $task) {
            return null;
        }

        $map = [
            'downloading' => 'downloading',
            'installing'  => 'installing',
            'done'        => 'success',
            'failed'      => 'failed',
            'timeout'     => 'timeout',
        ];
        $target = $map[$phase] ?? null;
        if (! $target) {
            return $task;
        }

        if ($errorCode) {
            $task->error_code = $errorCode;
        }
        if ($errorMsg) {
            $task->error_msg = $errorMsg;
        }

        $this->transition($task, $target);

        // 成功 → 按 operation 落 install_record(装/升级/回滚/卸载语义不同)
        if ($target === 'success') {
            $this->recordInstall($task);
        }

        // 灰度自驱:本版本若有进行中的灰度,回报后重算指标并评估。
        // ⚠ 这是**刻意的设计**,不是"还没做定时任务"的权宜之计:
        //   灰度推进的信号是"设备回报",事件驱动比轮询更准也更快。
        //   项目现在已经有 scheduler（见 bootstrap/app.php 的 4 条运维任务）,
        //   仍然**不给灰度加定时任务** —— 那是拿错误的模型覆盖正确的设计。
        $this->evaluateGrayIfAny($task);

        return $task->fresh();
    }

    /**
     * 取消(仅未终结的状态可取消)。
     */
    public function cancel(string $taskNo): ?DeployTask
    {
        $task = DeployTask::query()->where('task_no', $taskNo)->first();
        if (! $task) {
            return null;
        }

        $this->transition($task, 'cancelled');

        return $task->fresh();
    }

    /**
     * 状态流转(带合法性校验,非法流转忽略)。
     */
    public function transition(DeployTask $task, string $to): bool
    {
        $from = $task->status;

        if ($from === $to) {
            return true;
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            return false; // 非法流转 —— 忽略(防乱序/回退)
        }

        $task->status = $to;

        if (in_array($to, ['success', 'failed', 'timeout', 'cancelled'], true)) {
            $task->completed_at = now();
        }

        $task->save();

        return true;
    }

    /**
     * 按 operation 落 install_record —— 装/升级/回滚/卸载语义不同,不能一律写 installed。
     *
     * "升级"(updated)是灰度回滚的靶子:GrayReleaseService::abort() 找的正是
     * `status='updated'` 的设备,所以这个值必须真的被写出来,否则自动回滚空转。
     */
    protected function recordInstall(DeployTask $task): void
    {
        // 无技能版本就无法标识"装了什么" —— 跳过(install_record 的唯一键是 did+skill_version_id)
        if (! $task->skill_version_id) {
            return;
        }

        $version = SkillVersion::find($task->skill_version_id);

        $attrs = [
            'entitlement_id'  => $task->entitlement_id,
            'deploy_task_id'  => $task->id,
            'current_version' => $version?->version,
        ];

        $key = ['did' => $task->did, 'skill_version_id' => $task->skill_version_id];

        switch ($task->operation) {
            case DeployTask::OP_UNINSTALL:
            case DeployTask::OP_RECLAIM:
                InstallRecord::updateOrCreate($key, $attrs + ['status' => 'uninstalled', 'removed_at' => now()]);
                break;

            case DeployTask::OP_ROLLBACK:
                // 回滚任务携带的是"坏版本";设备回滚成功后该版本记录落 rolled_back
                InstallRecord::updateOrCreate($key, $attrs + ['status' => 'rolled_back', 'removed_at' => now()]);
                break;

            case DeployTask::OP_ENABLE:
            case DeployTask::OP_DISABLE:
                // 启停不改安装事实
                break;

            default:
                // 安装:此前已装同 skill 的其它版本 → updated,否则 installed
                $status = $this->isUpgrade($task, $version) ? 'updated' : 'installed';
                InstallRecord::updateOrCreate($key, $attrs + ['status' => $status, 'installed_at' => now()]);
        }
    }

    /**
     * 是否属于"同 skill 的版本升级"。
     *
     * install_record 只有 skill_version_id,须经 skill_versions.product_id 反查同 product 的其它版本记录。
     */
    protected function isUpgrade(DeployTask $task, ?SkillVersion $version): bool
    {
        if (! $version) {
            return false;
        }

        $otherVersionIds = SkillVersion::query()
            ->where('product_id', $version->product_id)
            ->where('id', '!=', $version->id)
            ->pluck('id');

        if ($otherVersionIds->isEmpty()) {
            return false;
        }

        return InstallRecord::query()
            ->where('did', $task->did)
            ->whereIn('skill_version_id', $otherVersionIds)
            ->whereIn('status', ['installed', 'updated'])
            ->exists();
    }

    /**
     * 灰度自驱:本版本若有进行中的灰度,按库中最新回报重算指标并评估。
     *
     * ⚠ 惰性解析:GrayReleaseService 的构造器注入了本服务,直接注入会构成循环依赖。
     */
    protected function evaluateGrayIfAny(DeployTask $task): void
    {
        if (! $task->skill_version_id) {
            return;
        }

        $release = \Webkul\Shop\Models\GrayRelease::query()
            ->where('skill_version_id', $task->skill_version_id)
            ->where('status', 'releasing')
            ->latest('id')
            ->first();

        if (! $release) {
            return;
        }

        app(\Webkul\Shop\Services\Deploy\GrayReleaseService::class)->evaluateFromRecords($release);
    }

    protected function newTaskNo(): string
    {
        return 'DT'.now()->format('ymdHis').random_int(1000, 9999);
    }

    /**
     * 拒建已被熔断的技能的新下发任务。
     *
     * ⚠ 用 app() 惰性解析 KillSwitchService:KillSwitchService 的构造器注入了本服务,
     *   构造器里互相注入会构成循环依赖。这与 evaluateGrayIfAny() 的取舍一致。
     *
     * @throws SkillBlockedException
     */
    protected function assertNotBlocked(int $skillId, int $operation): void
    {
        // 卸/回滚/回收是熔断的补救动作,放行
        if (in_array($operation, self::REMEDIATION_OPS, true)) {
            return;
        }

        if (app(KillSwitchService::class)->isBlocked($skillId, 'new')) {
            throw new SkillBlockedException('该技能已被熔断,暂不可下发。');
        }
    }
}
