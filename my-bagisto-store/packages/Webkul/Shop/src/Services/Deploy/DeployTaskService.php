<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\LifecycleNotifier;

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

    /**
     * 「未终态」—— 还可能被继续推进的状态集合。
     *
     * 建任务的幂等判定**只看这批**:同一台设备、同一个版本、同一个操作,只要上一条还没落地,
     * 就不该再建第二条。反过来,上一条已经 success/failed/timeout/cancelled 之后再建一条是
     * **合法**的(装失败了要能重来),所以终态一律不进判定。
     */
    protected const OPEN_STATUSES = [
        'pending',
        'dispatching',
        'downloading',
        'installing',
        'pending_online',
    ];

    /** 合法的状态流转(防乱序/防回退)。 */
    protected const TRANSITIONS = [
        // ⚠ `pending` 允许直接到 `failed`:任务刚建、还没交给网关时可能被**下发前的
        //   前置判定**否掉(设备未登记 NOT_BOUND 等)。这不是"乱序",是还没出门就发现
        //   发不出去 —— 不许它失败的话,任务会永远停在 pending(用户永远"下发中")。
        'pending'         => ['dispatching', 'pending_online', 'failed', 'cancelled'],
        'dispatching'     => ['downloading', 'installing', 'success', 'failed', 'timeout', 'pending_online', 'cancelled'],
        // download_failed = 下载失败,等待设备重试。P11:此前它只存在于类注释的状态图里,
        // TRANSITIONS 与 onEvent 的 map 都没有它 —— 设备报"下载失败"会落成泛化的 failed,
        // 于是"下载失败(可重试)"和"安装失败(不可重试)"在库里长得一模一样。
        'downloading'     => ['installing', 'download_failed', 'success', 'failed', 'timeout', 'cancelled'],
        'download_failed' => ['downloading', 'installing', 'failed', 'timeout'],
        'installing'      => ['success', 'failed', 'timeout'],
        'pending_online'  => ['dispatching', 'success', 'failed', 'timeout', 'cancelled'],
        'success'         => [],
        'failed'          => [],
        'timeout'         => [],
        'cancelled'       => [],
    ];

    public function __construct(
        protected SigningService $signing,
        protected AgentGatewayManager $gateways,
        protected LifecycleNotifier $notifier,
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

        // 幂等 + 事务边界,两个问题一次解决:
        //
        //   1. **幂等**:迁移 `create_deploy_and_audit_tables.php` 写着「幂等由应用层保证」,
        //      而应用层此前没有做 —— 双击一次下发就落两条任务,设备取指令时两条都拿到,
        //      同一个版本装两遍。现在对 (did, skill_version_id, operation) 的**未终态**记录短路。
        //      终态不进判定:失败之后重来一次是合法动作,不能挡。
        //
        //   2. **事务**:create → dispatch 此前不是事务,崩在中间会留下一条永远没人推进的
        //      `pending` —— 设备看不到它(只取 dispatching/pending_online),用户那边的
        //      "下发中"也永远不结束。
        //
        // ⚠ 并发下"查了再建"仍然是 check-then-act,故拿**设备那一行**做互斥:
        //   锁 device_binding 而不是 deploy_task —— 首次下发时 deploy_task 里**还没有行**,
        //   `SELECT ... FOR UPDATE` 对不存在的行不产生任何锁,两个并发请求会双双"查无重复"
        //   然后各建一条,等于没锁。
        return DB::transaction(function () use (
            $did, $skillId, $skillVersionId, $entitlementId, $operation,
            $channel, $createdBy, $packageUrl, $packageHash
        ) {
            $this->lockDevice($did);

            $existing = $this->findOpenTask($did, $skillVersionId, $operation);

            if ($existing) {
                return $existing;
            }

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
        });
    }

    /**
     * 以设备为粒度串行化建任务 —— create() 的幂等互斥点。
     *
     * 设备没登记过 binding 时 `first()` 返回 null(加锁落空),此时退化为"无锁短路":
     * 宁可少一层保护,也不给一条查不到设备的下发凭空造一条 binding。
     */
    protected function lockDevice(string $did): void
    {
        DeviceBinding::query()->where('did', $did)->lockForUpdate()->first();
    }

    /**
     * 找"同设备 + 同版本 + 同操作"的未终态任务(create() 的去重靶子)。
     *
     * skill_version_id 可空 —— 空值也参与判定,且必须用 whereNull,否则 `= null` 恒假、
     * 去重对"无版本下发"整批失效。
     */
    protected function findOpenTask(string $did, ?int $skillVersionId, int $operation): ?DeployTask
    {
        return DeployTask::query()
            ->where('did', $did)
            ->where('operation', $operation)
            ->whereIn('status', self::OPEN_STATUSES)
            ->when(
                $skillVersionId === null,
                fn ($q) => $q->whereNull('skill_version_id'),
                fn ($q) => $q->where('skill_version_id', $skillVersionId),
            )
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 记一次"这条指令已经投递给设备了"(投递标记)。
     *
     * 与 `DeviceBinding::markSeen()` 是**两件事**:那个记"这台设备来过"
     * (last_online_at,给在线判定用);这个记"**这一条任务**已经交到设备手上了"。
     * 只有后者能回答"设备重启后又来拉指令,该不该把这条再给它一次"。
     *
     * 用条件更新:并发下只有一方能写上,先到的不被后到的覆盖首次投递时间。
     */
    public function markDelivered(DeployTask $task): void
    {
        if ($task->delivered_at !== null) {
            return;
        }

        $at = now();

        DeployTask::query()
            ->whereKey($task->id)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $at]);

        $task->delivered_at = $at;
    }

    /**
     * 经网关下发(签名 + 在线判定)。
     *
     * 三条出口都带明确 `error_code`(P11)—— 此前离线 / 未登记一律无码,事后只能
     * 靠中文 error_msg 反推。
     */
    public function dispatch(DeployTask $task): void
    {
        $gateway = $this->gateways->gateway();

        // 设备**未绑定**(从未登记,或已被用户解绑):等它上线是等不到的 —— 这条路只可能是
        // 配错了 did、给了别人的设备,或设备已被解绑。直接判失败并标 NOT_BOUND,
        // 不挂起 24 小时让用户对着"下发中"干等。
        //
        // ⚠ D1:下发链路**只认 `device_binding.bind_status`**。此前这里只查"binding 是否存在",
        //   于是设备解绑（`DeviceBindingService::unbind()` 置 `unbound`）后仍能收到下发 ——
        //   界面已解绑、平台却还认它已确权。必须按 `bound` 判定。
        if (! DeviceBinding::query()
            ->where('did', $task->did)
            ->where('bind_status', DeviceBinding::BIND_STATUS_BOUND)
            ->exists()) {
            $this->failWith($task, DeployTask::ERR_NOT_BOUND, '设备未绑定或已解绑(找不到有效的设备绑定记录)。');

            return;
        }

        // 设备离线 → 挂起(pending_online),上线(轮询)后再发
        if (! $gateway->isOnline($task->did)) {
            $this->transition($task, 'pending_online');
            $this->stampError($task, DeployTask::ERR_OFFLINE, '设备离线,任务已挂起,待设备上线后取走。');

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
     * 置为失败并带码(经状态机,不硬写)。
     *
     * ⚠ 只在任务还处于合法前态时才生效:`transition()` 命中 0 行即视为竞态落败
     *   (设备刚好回报了结果),此时**不覆盖**它,并把内存态改回去,免得调用方
     *   拿着一个与库不一致的对象继续跑。
     */
    protected function failWith(DeployTask $task, string $code, string $message): void
    {
        $prevCode = $task->error_code;
        $prevMsg = $task->error_msg;

        // 先把字段设到模型上 —— transition() 会把 getDirty() 一并纳入条件更新
        $task->error_code = $code;
        $task->error_msg = $message;

        if (! $this->transition($task, 'failed')) {
            $task->error_code = $prevCode;
            $task->error_msg = $prevMsg;
        }
    }

    /**
     * 给**非终态**任务盖一个服务端判定的 error_code(如 pending_online 的 OFFLINE)。
     *
     * 用条件更新:状态在本次读取之后变了(设备上线取走了)就不写,避免把一个
     * 已经在装的任务标成"离线挂起"。
     */
    protected function stampError(DeployTask $task, string $code, string $message): void
    {
        $status = $task->status;

        DeployTask::query()
            ->whereKey($task->id)
            ->where('status', $status)
            ->update(['error_code' => $code, 'error_msg' => $message]);

        $task->forceFill(['error_code' => $code, 'error_msg' => $message])->syncOriginal();
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
     * @param  string  $phase  downloading|installing|done|failed|timeout
     *
     * @throws IllegalTaskTransitionException 回报与当前状态不构成合法流转(见 P9)
     */
    public function onEvent(string $taskNo, string $phase, ?string $errorCode = null, ?string $errorMsg = null): ?DeployTask
    {
        $task = DeployTask::query()->where('task_no', $taskNo)->first();
        if (! $task) {
            return null;
        }

        $map = [
            'downloading'     => 'downloading',
            'installing'      => 'installing',
            'download_failed' => 'download_failed',
            'done'            => 'success',
            'failed'          => 'failed',
            'timeout'         => 'timeout',
        ];
        $target = $map[$phase] ?? null;
        if (! $target) {
            return $task;
        }

        // P11:下载失败要有自己的码 —— 否则它和"安装失败"在库里长得一样,
        // 而前者是可重试的、后者不是。
        if (! $errorCode && $target === 'download_failed') {
            $errorCode = DeployTask::ERR_DOWNLOAD_FAILED;
        }

        if ($errorCode) {
            $task->error_code = $errorCode;
        }
        if ($errorMsg) {
            $task->error_msg = $errorMsg;
        }

        // 非法流转 → 明确拒绝(P9),不再"静默丢弃 + 返 200"。
        //
        // 此前这里忽略 transition() 的返回值:设备对一条已 success 的任务报 failed,
        // 服务端**什么都没做**却回 200 'ok'。端侧据此认为回报已被接受、云侧状态没动 ——
        // 端云永久分叉,而且服务端一行日志都不留(告警无从谈起)。
        if (! $this->transition($task, $target)) {
            Log::warning('下发回报被拒:非法状态流转', [
                'task_no' => $task->task_no,
                'did'     => $task->did,
                'from'    => $task->status,
                'to'      => $target,
            ]);

            throw new IllegalTaskTransitionException($task->task_no, $task->status, $target);
        }

        // 成功 → 按 operation 落 install_record(装/升级/回滚/卸载语义不同)
        if ($target === 'success') {
            // ⚠ 空跑不落 install_record(P11):带 PKG_MISSING 的 success 是"走通了状态机
            //   但什么都没装"(设备没拿到包)。照落一条 `installed` 等于**凭空造**一条安装事实,
            //   下游(灰度回滚靶子 / 事故影响面 / 设备已装列表)都会把这台设备算进去。
            if ($task->isDryRun()) {
                Log::warning('下发任务空跑(无包),不落安装事实', [
                    'task_no' => $task->task_no,
                    'did'     => $task->did,
                    'skill_id' => $task->skill_id,
                ]);
            } else {
                $this->recordInstall($task);
            }

            // B-2 · 用户侧站内通知(副作用;LifecycleNotifier 内已吞异常,不影响状态机):
            //   · 安装:仅"非空跑"才算真装上了(空跑什么都没装,不发"已下发");
            //   · 回收:回收语义不依赖包,空跑也算完成(试用确实被回收)。
            if ($task->operation === DeployTask::OP_INSTALL && ! $task->isDryRun()) {
                $this->notifier->deliveryCompleted($task);
            } elseif ($task->operation === DeployTask::OP_RECLAIM) {
                $this->notifier->trialExpired($task);
            }
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
     * 状态流转(带合法性校验 + 条件更新)。
     *
     * ⚠ 此前是 **check-then-act**:读出 `$task->status` → 校验 → 赋值 → `save()`。
     *   两个并发回报(设备重发/网络抖动)会双双读到同一个 `from`,双双通过校验,
     *   双双写库 —— 后写的覆盖先写的,一次流转凭空发生两次(例如 success 之后又被
     *   另一个请求按旧状态推成 failed)。
     *
     * 现在改为**条件更新**:`UPDATE ... WHERE id=? AND status=<期望的从前态>`。
     *   命中 0 行 = 有人抢先改了状态(或状态本就不合法)→ 本次流转视为未发生,返回 false。
     *   数据库的行级原子性是唯一裁决者,不依赖"读到的状态还没变"这个假设。
     *
     * @return bool  true = 本次流转真的发生了(或 from==to 的幂等短路);false = 被拒
     */
    public function transition(DeployTask $task, string $to): bool
    {
        $from = $task->status;

        // 同状态:幂等短路,不必碰库
        if ($from === $to) {
            return true;
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            return false; // 非法流转 —— 忽略(防乱序/回退)
        }

        $attributes = ['status' => $to];

        if (in_array($to, ['success', 'failed', 'timeout', 'cancelled'], true)) {
            $attributes['completed_at'] = now();
        }

        // 把调用方在 transition() 之前已经设到模型上的其它改动(如 onEvent() 先设的
        // error_code/error_msg)一并纳入本次条件更新 —— 旧实现是 $task->save(),
        // 会连同这些一起落库;拆成条件更新后若不带上,这些字段会被静默丢弃。
        $attributes = array_merge($task->getDirty(), $attributes);

        return DB::transaction(function () use ($task, $from, $to, $attributes) {
            // 条件更新:以"期望的从前态"为条件。Eloquent 会自动带 updated_at。
            $affected = DeployTask::query()
                ->whereKey($task->id)
                ->where('status', $from)
                ->update($attributes);

            if ($affected === 0) {
                return false; // 竞态落败 / 状态已被他人改动
            }

            // 同步内存态:让调用方手里的对象与库一致(否则后续 buildCommand/落库会用到旧值)
            $task->forceFill($attributes)->syncOriginal();

            return true;
        });
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
