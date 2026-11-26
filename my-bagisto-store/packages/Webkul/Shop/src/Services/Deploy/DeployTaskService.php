<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\Contracts\AgentTaskEnvelopeV2;
use Webkul\Shop\Services\Deploy\Contracts\ExecutionFact;
use Webkul\Shop\Services\Deploy\Contracts\OutboxLease;
use Webkul\Shop\Services\LifecycleNotifier;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAssetResolver;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;

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
        DeployTask::OP_CANCEL_EXECUTION,
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

    /**
     * 建任务时,「task_no 撞 `uk_deploy_task_no`」的换号重试上限(Task S-10)。
     *
     * 与 `TicketController::createTicket()` 的既有上限**保持一致(5)** —— 全仓唯一一处
     * 已成型的"造号撞唯一键 → 换号重试"写法就在那里,这里照它的口径,不另立标准。
     * 5 次仍撞(既是概率上可忽略,也说明号池配置或时钟出了问题),就照常抛出,不吞。
     */
    protected const TASK_NO_RETRY_LIMIT = 5;

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
        'installing'      => ['success', 'failed', 'timeout', 'cancelled'],
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
        /**
         * 包指纹的唯一实现（任务卡 D-7）—— 下发指令里的 `package_hash` 必须复用它。
         * 另算一套 = 把"审的就是发的"交给两份实现去兑现，它们迟早漂移。
         */
        protected PackageFingerprint $fingerprint,
        /**
         * 一次下发到底交付哪一份字节 —— 多交付物版本的唯一解析点（C2）。
         */
        protected ?SkillDeliveryAssetResolver $assets = null,
    ) {
        $this->assets ??= app(SkillDeliveryAssetResolver::class);
    }

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
        bool $allowDowngrade = false,
        ?string $executionId = null,
        string $protocolVersion = DeployTask::PROTOCOL_V1,
        ?array $signedPayload = null,
        ?int $taskExpiresAt = null,
    ): DeployTask {
        // 熔断闸:命中即拒建(不落库)。补救类动作(卸/回滚/回收/取消)放行 —— 见 REMEDIATION_OPS。
        $this->assertNotBlocked($skillId, $operation);

        // 版本单调闸(§4.22 F1 / B-13):**安装**时拒绝低于本机已装版本的包。
        // 回滚(OP_ROLLBACK)是显式的向下动作,不过这闸;allowDowngrade 供平台受控场景显式放行。
        if ($operation === DeployTask::OP_INSTALL && ! $allowDowngrade) {
            $this->assertNotDowngrade($did, $skillVersionId);
        }

        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use (
                    $did, $skillId, $skillVersionId, $entitlementId, $operation,
                    $channel, $createdBy, $packageUrl, $packageHash,
                    $executionId, $protocolVersion, $signedPayload, $taskExpiresAt
                ) {
                    $this->lockDevice($did);

                    // 执行/取消类任务按 execution_id 独立去重；生命周期任务按 (did, skill_id, version, op) 去重
                    $existing = in_array($operation, [DeployTask::OP_EXECUTE, DeployTask::OP_CANCEL_EXECUTION], true)
                        ? ($executionId ? $this->findOpenExecutionTask($did, $operation, $executionId) : null)
                        : $this->findOpenTask($did, $skillId, $skillVersionId, $operation);

                    if ($existing) {
                        return $existing;
                    }

                    $maxSeq = (int) (DeployTask::where('did', $did)->max('command_seq') ?? 0);
                    $commandSeq = $maxSeq + 1;

                    // 包地址/哈希在**这唯一漏斗**里推导 —— 三个调用点(用户下发 / 试用 / 回收)
                    // 谁都不用改,也不会各写一套(见 resolvePackage)。
                    [$resolvedUrl, $resolvedHash, $resolvedAssetId] = $this->resolvePackage(
                        $did,
                        $skillVersionId,
                        $operation,
                        $packageUrl,
                        $packageHash,
                    );

                    $task = DeployTask::create([
                        'task_no'          => $this->newTaskNo(),
                        'command_seq'      => $commandSeq,
                        'did'              => $did,
                        'channel'          => $channel,
                        'protocol_version' => $protocolVersion,
                        'skill_id'         => $skillId,
                        'skill_version_id' => $skillVersionId,
                        'entitlement_id'   => $entitlementId,
                        'execution_id'     => $executionId,
                        'operation'        => $operation,
                        'status'           => 'pending',
                        'package_url'      => $resolvedUrl,
                        'package_hash'     => $resolvedHash,
                        // C2：把"当时决定发的那一个文件"钉住，重签 URL / 端侧复算都以它为准。
                        'package_asset_id' => $resolvedAssetId,
                        'signed_payload'   => $signedPayload,
                        'task_expires_at'  => $taskExpiresAt ? now()->setTimestamp($taskExpiresAt) : null,
                        'created_by'       => $createdBy,
                    ]);

                    // 「无包可下发」如实打标(任务卡 S-1):该版本没有文件类交付物时,resolvePackage
                    //   不发出可下载的假地址,但**必须留下原因** —— 否则事后从 `package_url IS NULL`
                    //   根本分不清"忘了填"还是"本来就没有包"。
                    //   用 ERR_PKG_MISSING:模型里它本就同时覆盖"平台侧没分发交付物"这一情形;
                    //   且设备空跑回报成功时,本任务会被判成空跑(不落 install_record、不进灰度成功率)。
                    if (DeployTask::needsPackage($operation) && $skillVersionId && $resolvedHash === null) {
                        $task->error_code = DeployTask::ERR_PKG_MISSING;
                        $task->error_msg = '该版本没有文件类交付物,无包可下发(设备将只走状态机,不落安装事实)。';
                        $task->save();
                    }

                    $this->dispatch($task);

                    return $task->fresh();
                });
            } catch (QueryException $e) {
                // 不是"本表 task_no 撞号" → 照常抛:别的原因(别的唯一约束、连接错误)不该被
                // "换号重试"掩盖;试满上限仍撞 → 也照常抛(不留半条记录,事务已回滚)。
                if (! $this->isTaskNoDuplicate($e) || ++$attempts >= self::TASK_NO_RETRY_LIMIT) {
                    throw $e;
                }

                // 撞号 → 下一轮 newTaskNo() 会造一个新号再试
            }
        }
    }

    /**
     * 推导一条任务该带什么样的包地址/哈希（任务卡 S-1）。
     *
     * ⚠ 位置：放在 create() 这**唯一漏斗**里，而不是三个调用点
     *   （用户下发 DeployController / 试用 TrialService::start / 回收 TrialService::reclaimDue）。
     *   "带哪个包"是这条任务基于 (operation, skill_version_id) 的**固有属性**，
     *   不是调用方各自的选择 —— 放在调用点，任何**新增**的调用点都会漏填，
     *   而漏填的表现恰恰是"设备什么都没装"（还看不出是忘了带包）。
     *
     * @param  string|null  $overrideUrl   显式覆盖（测试 / 平台特例）
     * @param  string|null  $overrideHash  显式覆盖（测试 / 平台特例）
     * @return array{0: ?string, 1: ?string, 2: ?int}  [package_url, package_hash, package_asset_id]
     */
    protected function resolvePackage(
        string $did,
        ?int $versionId,
        int $operation,
        ?string $overrideUrl,
        ?string $overrideHash,
    ): array
    {
        // 显式覆盖优先：给测试与平台特例留的口子（默认路径一律自动推导）。
        if ($overrideUrl !== null || $overrideHash !== null) {
            return [$overrideUrl, $overrideHash, null];
        }

        // 非安装类操作（卸 / 回收 / 启停）不需要包 —— 不挂地址（见 DeployTask::PACKAGE_OPERATIONS）。
        if (! DeployTask::needsPackage($operation)) {
            return [null, null, null];
        }

        // 没有版本就无法定位"发哪个包"。
        if (! $versionId) {
            return [null, null, null];
        }

        // 指纹 = 该版本文件类交付物集合的唯一判据（复用 D-7 那**一处**实现，不另算一套）。
        $hash = $this->fingerprint->forVersion($versionId);

        // null = 该版本没有文件类交付物（没有字节可发）。
        //   **绝不**发一个"可下载"的假地址：那会让设备去拉一个不存在的包，
        //   或更糟 —— 平台自己都说不清在发什么。调用方据此把任务如实标成"无包"。
        if ($hash === null) {
            return [null, null, null];
        }

        // C2：按设备机型挑"成功 build 的机型产物"；没有机型产物时只接受**唯一**文件。
        //   多份文件且无法唯一确定 → SkillAssetAmbiguousException（API 边界转 409）。
        //   绝不在这里 `defaultFileForVersion()` 悄悄取排序第一个：那等于让排序实现
        //   决定这次下发的是哪份字节，端侧复算迟早对不上。
        $asset = $this->assets->resolveForVersion(
            (int) $versionId,
            $this->modelCodeForDid($did),
        );

        // ⚠ 还必须能拿到"被下载的那个文件"的字节 sha256 —— 端侧靠它复算，且它会被签进指令。
        //   历史行可能 `file_hash` 为空（上传核早已一律写入，但存量 NULL 是可能的）：此时
        //   **不发地址** —— 发出去端侧也无从验证，只会把一份无法证明来源的字节装上。
        //   这与下载端点"记录为空即拒绝输出"是同一取舍，只是把它提前到了下发之前。
        //   于是形成一条硬不变式：`package_url` 非空 ⟺ `package_sha256` 非空
        //   （有包任务必须两者都有，见签名的取舍）。
        $sha256 = $asset?->file_hash;

        if (! is_string($sha256) || $sha256 === '') {
            return [null, null, null];
        }

        return [$this->packageUrlFor((int) $versionId, (int) $asset->id), $hash, (int) $asset->id];
    }

    /**
     * 签发一个**限时**的技能包下载地址（任务卡 S-1）。
     *
     * 用 Laravel 自带签名 URL，不引入新依赖：`expires` 与 `signature` 是查询串的一部分，
     * 改 `versionId` 或改 `expires` 都会让签名失配（见路由挂的 `signed` 中间件）。
     * 这只是**第一重**闸 —— 端点同时还要求 agent 身份（`agent.key`），
     * 故 URL 即便泄露，没有设备凭据也拿不到包。
     */
    protected function packageUrlFor(int $versionId, ?int $assetId = null): string
    {
        $ttl = (int) config('deploy_gateways.package_url_ttl_minutes', 10);

        $parameters = ['versionId' => $versionId];

        // 钉住资产：下载端点据此只发这一份，而不是按当时的排序临时挑。
        if ($assetId !== null) {
            $parameters['asset_id'] = $assetId;
        }

        return URL::temporarySignedRoute(
            'shop.api.agent.package.download',
            now()->addMinutes($ttl),
            $parameters,
        );
    }

    /**
     * 指令里 `package_sha256` 的值 —— 默认下发的那**一个**文件类交付物的 sha256。
     *
     * 与下载端点的默认选片（{@see SkillAsset::defaultFileForVersion()}）是**同一处**判据：
     * 端侧拿到的字节就是这个文件，它复算出的 sha256 就该等于这里给的值。
     */
    protected function packageSha256For(DeployTask $task): ?string
    {
        if ($task->package_hash === null || ! $task->skill_version_id) {
            return null;
        }

        $versionId = (int) $task->skill_version_id;
        $asset = $task->package_asset_id
            ? SkillAsset::query()
                ->where('skill_version_id', $versionId)
                ->whereKey((int) $task->package_asset_id)
                ->first()
            : SkillAsset::defaultFileForVersion($versionId);

        return $asset && $asset->isFile() ? $asset->file_hash : null;
    }

    /**
     * 设备绑定上的机型 code —— 机型产物选择的输入。
     *
     * 先取 binding.model_id 指向的机型库，再回退到 Agent 上报的能力快照；
     * 两者都未知时返回 null（解析器再走"唯一文件"这条保守路径）。
     */
    protected function modelCodeForDid(string $did): ?string
    {
        $binding = DeviceBinding::query()->with('model')->where('did', $did)->first();

        if (! $binding) {
            return null;
        }

        $modelCode = $binding->model?->model_code;

        if (is_string($modelCode) && $modelCode !== '') {
            return $modelCode;
        }

        $snapshot = is_array($binding->meta) ? ($binding->meta['capability_snapshot'] ?? null) : null;
        $reported = is_array($snapshot) ? ($snapshot['model_code'] ?? null) : null;

        return is_string($reported) && $reported !== '' ? $reported : null;
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
    protected function findOpenTask(string $did, int $skillId, ?int $skillVersionId, int $operation): ?DeployTask
    {
        return DeployTask::query()
            ->where('did', $did)
            ->where('skill_id', $skillId)
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
     * 执行/取消类任务按 execution_id 独立去重（严防合并不同合法执行）。
     */
    protected function findOpenExecutionTask(string $did, int $operation, string $executionId): ?DeployTask
    {
        return DeployTask::query()
            ->where('did', $did)
            ->where('operation', $operation)
            ->where('execution_id', $executionId)
            ->whereIn('status', self::OPEN_STATUSES)
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
     * 原子领取下一条 Outbox 命令。
     *
     * deploy_task 本身即持久 Outbox；claim 只做数据库事务，不调用 HTTP/SDK/DDS。
     * ACK 丢失时租约过期后可重投，设备端以 task_id + durable journal 幂等。
     */
    public function claimNextCommand(string $did, string $protocolVersion): ?DeployTask
    {
        // MySQL InnoDB 在多个 worker 同时对同一任务做 SELECT ... FOR UPDATE 时
        // 可能选择其中一个事务作为死锁牺牲者（40001）。这是预期竞态，不是任务失败。
        // 有界重试后，失败者会看到任务已被另一事务领取并返回 null；绝不无限重试。
        return DB::transaction(function () use ($did, $protocolVersion) {
            $now = now();
            $query = DeployTask::query()
                ->where('did', $did)
                ->whereNull('delivered_at')
                ->where(function ($q) use ($now) {
                    $q->where('status', 'pending_online')
                        ->orWhere('status', 'dispatching');
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('outbox_lease_expires_at')
                        ->orWhere('outbox_lease_expires_at', '<=', $now);
                });

            if ($protocolVersion !== DeployTask::PROTOCOL_V2) {
                $query->whereNotIn('operation', DeployTask::EXECUTION_OPERATIONS);
            }

            $task = $query->orderBy('id')->lockForUpdate()->first();
            if (! $task) {
                return null;
            }

            if (! $this->transition($task, 'dispatching')) {
                return null;
            }

            $acquiredAt = now();
            $attempt = (int) $task->delivery_attempt + 1;
            $lease = new OutboxLease(
                taskId: (int) $task->id,
                token: Str::random(64),
                attempt: $attempt,
                acquiredAt: $acquiredAt->timestamp,
                expiresAt: $acquiredAt->copy()->addSeconds((int) config('deploy_gateways.command_lease_seconds', 30))->timestamp,
            );
            $task->outbox_lease_token = $lease->token;
            $task->outbox_lease_expires_at = $acquiredAt->copy()->addSeconds((int) config('deploy_gateways.command_lease_seconds', 30));
            $task->delivery_attempt = $lease->attempt;
            $task->saveQuietly();

            if ($protocolVersion !== DeployTask::PROTOCOL_V2) {
                $this->markDelivered($task);
            }

            $task = $task->fresh();
            Log::info('deploy.outbox.claimed', [
                'service' => 'skill-deploy',
                'env' => app()->environment(),
                'task_id' => $task->task_no,
                'did' => $task->did,
                'operation' => (int) $task->operation,
                'status' => $task->status,
                'attempt' => (int) $task->delivery_attempt,
                'protocol' => $protocolVersion,
            ]);

            return $task;
        }, 3);
    }
    /**
     * 构造一条下发指令(含签名) —— 推/拉两种传输都复用它,避免端上各写各的。
     */
    public function buildCommand(DeployTask $task): array
    {
        $version = $task->skill_version_id ? SkillVersion::find($task->skill_version_id) : null;

        $ts = now()->timestamp;

        // 端侧复算的靶子 —— 被下载的**那个文件**的 sha256（= skill_assets.file_hash）。
        // ⚠ **必须先算出来、再进签名串**：它被签住，端侧"复算比对"这道防线才有意义
        //   （否则能改指令的攻击者可同时换掉 URL 与这个哈希，让复算白白通过）。
        $packageSha256 = $this->packageSha256For($task);

        $canonical = $this->signing->canonical([
            'task_id'        => $task->task_no,
            'skill_id'       => $task->skill_id,
            'version'        => $version?->version,
            'package_hash'   => $task->package_hash,
            'package_sha256' => $packageSha256,
            'ts'             => $ts,
        ]);

        // ⚠ 包地址在**取指令的这一刻**重新签发，不用建任务时存下的那一条。
        //   URL 是**限时**的，而"设备什么时候来取"不确定（离线挂起后可能几小时后才上线）。
        //   沿用旧 URL 会让一条本来有效的下发因为"等太久"而被拒 —— 那是把限时设计
        //   用错了地方。`deploy_task.package_url` 里存的那条是**记录**（排查/审计用），
        //   这里给出的是**当下可用**的那条。
        //
        //   URL 本身**不入签名串**（见 SigningService::canonical）：地址可以变，
        //   但字节必须匹配签住的 package_sha256 —— 签住哈希就锁死了内容。
        // 硬不变式：package_url 非空 ⟺ package_sha256 非空。
        //   任务钉住的资产若已被删除/哈希被清空，package_sha256 会取到 null；
        //   此时仍发 URL 等于让设备去拉一份"无法复算"的字节 —— 宁可不发地址。
        $packageUrl = ($packageSha256 !== null && $task->package_hash !== null && $task->skill_version_id)
            ? $this->packageUrlFor(
                (int) $task->skill_version_id,
                $task->package_asset_id ? (int) $task->package_asset_id : null,
            )
            : null;

        $keyId = $this->signing->keyId();
        $payload = is_array($task->signed_payload) ? $task->signed_payload : [];
        $binding = DeviceBinding::query()->with('model')->where('did', $task->did)->first();
        $artifact = $task->package_asset_id ? SkillAsset::query()->whereKey($task->package_asset_id)->first() : null;
        $buildRun = $artifact
            ? SkillBuildRun::query()->where('artifact_asset_id', $artifact->id)->orderByDesc('id')->first()
            : null;
        $modelCode = $payload['target_model_code'] ?? $artifact?->target_model_code ?? $binding?->model?->model_code;
        $targetProfileDigest = (string) ($payload['target_profile_digest'] ?? $buildRun?->target_profile_digest ?? '');
        $buildKey = $payload['build_key'] ?? $artifact?->build_key ?? $buildRun?->build_key;
        $nonce = $payload['nonce'] ?? bin2hex(random_bytes(16));
        $expiresAtTimestamp = $task->task_expires_at ? $task->task_expires_at->timestamp : ($ts + 3600);

        $signed = [
            'protocol'                      => DeployTask::PROTOCOL_V2,
            'protocol_version'              => DeployTask::PROTOCOL_V2,
            'signature_algorithm'           => 'RSA-SHA256',
            'key_id'                        => $keyId,
            'task_id'                       => (string) $task->task_no,
            'command_seq'                   => (int) ($task->command_seq ?: 1),
            'did'                           => (string) $task->did,
            'operation'                     => (int) $task->operation,
            'model_code'                    => $modelCode ? (string) $modelCode : null,
            'skill_id'                      => (string) $task->skill_id,
            'skill_version_id'              => $task->skill_version_id ? (string) $task->skill_version_id : null,
            'version'                       => $version?->version ? (string) $version->version : null,
            'package_asset_id'              => $task->package_asset_id ? (string) $task->package_asset_id : null,
            'package_hash'                  => $task->package_hash ?: null,
            'package_sha256'                => $packageSha256 ?: null,
            'build_key'                     => $payload['build_key'] ?? null,
            'target_model_code'             => $modelCode ? (string) $modelCode : ($payload['target_model_code'] ?? null),
            'target_profile_digest'         => $targetProfileDigest !== '' ? $targetProfileDigest : null,
            'verification_digest'           => $payload['verification_digest'] ?? null,
            'revoked_version_id'            => $payload['revoked_version_id'] ?? null,
            'restore_asset_id'              => $payload['restore_asset_id'] ?? null,
            'restore_sha256'                => $payload['restore_sha256'] ?? null,
            'execution_id'                  => $task->execution_id ?: null,
            'target_release_id'             => $payload['target_release_id'] ?? null,
            'installed_asset_id'            => $payload['installed_asset_id'] ?? null,
            'installed_sha256'              => $payload['installed_sha256'] ?? null,
            'plan_digest'                   => $payload['plan_digest'] ?? null,
            'runtime_digest'                => $payload['runtime_digest'] ?? null,
            'execution_params'              => $payload['execution_params'] ?? null,
            'execution_deadline'            => isset($payload['execution_deadline']) ? (int) $payload['execution_deadline'] : null,
            'entitlement_id'                => $task->entitlement_id ? (string) $task->entitlement_id : null,
            'authorization_policy_version'  => 'v1',
            'authorization_not_before'      => isset($payload['authorization_not_before']) ? (int) $payload['authorization_not_before'] : null,
            'authorization_expires_at'      => isset($payload['authorization_expires_at']) ? (int) $payload['authorization_expires_at'] : null,
            'revocation_epoch'              => (int) ($payload['revocation_epoch'] ?? 0),
            'nonce'                         => $nonce,
            'issued_at'                     => (int) $ts,
            'expires_at'                    => (int) $expiresAtTimestamp,
            'task_expires_at'               => (int) $expiresAtTimestamp,
        ];

        $signatureV1 = $this->signing->sign($canonical);
        $signatureV2 = $this->signing->signV2($signed);

        if ($targetProfileDigest !== '') {
            // 强类型契约校验：实际生成的 v2 信封必须满足与 Agent 相同的身份/摘要约束。
            new AgentTaskEnvelopeV2(
                taskId: (string) $task->task_no,
                commandSeq: (int) ($task->command_seq ?: 1),
                did: (string) $task->did,
                operation: (int) $task->operation,
                modelCode: (string) $modelCode,
                packageSha256: (string) ($packageSha256 ?: ''),
                targetProfileDigest: $targetProfileDigest,
                nonce: $nonce,
                issuedAt: (int) $ts,
                expiresAt: (int) $expiresAtTimestamp,
                payload: $signed,
                signature: $signatureV2,
                keyId: $keyId,
            );
        }

        $payload['signed'] = $signed;
        $payload['signature_v1'] = $signatureV1;
        $payload['signature_v2'] = $signatureV2;
        $payload['nonce'] = $nonce;
        $task->signed_payload = $payload;
        if (! $task->task_expires_at) {
            $task->task_expires_at = \Illuminate\Support\Carbon::createFromTimestamp($expiresAtTimestamp);
        }
        if ($task->exists) {
            $task->saveQuietly();
        }
        $cmd = [
            'protocol'              => $task->protocol_version === DeployTask::PROTOCOL_V2 ? DeployTask::PROTOCOL_V2 : DeployTask::PROTOCOL_V1,
            'protocol_version'      => $task->protocol_version ?: DeployTask::PROTOCOL_V1,
            'task_id'               => $task->task_no,
            'command_seq'           => (int) ($task->command_seq ?: 1),
            'did'                   => (string) $task->did,
            'operation'             => $task->operation,
            'model_code'            => $signed['model_code'] ?? null,
            'skill_id'              => $task->skill_id,
            'version'               => $version?->version,
            'execution_id'          => $task->execution_id,
            'package_url'           => $packageUrl,
            'package_hash'          => $task->package_hash,
            'package_sha256'        => $packageSha256,
            'target_profile_digest' => $signed['target_profile_digest'] ?? null,
            'nonce'                 => $nonce,
            'issued_at'             => (int) $ts,
            'expires_at'            => (int) $expiresAtTimestamp,
            'key_id'                => $keyId,
            'signature'             => $task->protocol_version === DeployTask::PROTOCOL_V2 ? $signatureV2 : $signatureV1,
            'signature_v1'          => $signatureV1,
            'signature_v2'          => $signatureV2,
            'signed'                => $signed,
            'ts'                    => $ts,
        ];

        return $cmd;
    }

    /**
     * 接收设备回报,推进状态机。
     *
     * @param  string  $phase  downloading|installing|done|failed|timeout
     *
     * @throws IllegalTaskTransitionException 回报与当前状态不构成合法流转(见 P9)
     */
    public function onEvent(
        string $taskNo,
        string $phase,
        ?string $errorCode = null,
        ?string $errorMsg = null,
        ?ExecutionFact $executionFact = null,
        ?int $eventSeq = null,
    ): ?DeployTask
    {
        $task = DeployTask::query()->where('task_no', $taskNo)->lockForUpdate()->first();
        if (! $task) {
            return null;
        }

        $isExecutionTask = in_array((int) $task->operation, DeployTask::EXECUTION_OPERATIONS, true);
        if ($isExecutionTask) {
            if (! $executionFact) {
                throw new \InvalidArgumentException('EXECUTION_FACT_REQUIRED: 执行任务不得只凭 phase 宣告成功或停止');
            }
            if ($executionFact->executionId !== (string) $task->execution_id) {
                throw new \InvalidArgumentException('EXECUTION_FACT_ID_MISMATCH: 执行事实不属于该任务');
            }
            $this->assertFactMatchesPhase($phase, $executionFact);
        } elseif ($executionFact) {
            throw new \InvalidArgumentException('EXECUTION_FACT_UNEXPECTED: 非执行任务不得携带执行事实');
        }

        // phase → DeployTask 状态。accepted/running 在运输任务上只表示已进入
        // “安装/执行中”这个既有状态；真正的执行结论由 ExecutionFact 提供。
        $map = [
            'accepted'        => 'installing',
            'running'         => 'installing',
            'downloading'     => 'downloading',
            'installing'      => 'installing',
            'cancel_requested'=> null,
            'download_failed' => 'download_failed',
            'done'            => 'success',
            'failed'          => 'failed',
            'canceled'        => 'cancelled',
            'timeout'         => 'timeout',
            'unknown'         => 'timeout',
        ];

        if (! array_key_exists($phase, $map)) {
            throw new \InvalidArgumentException("DEVICE_FACT_PHASE_UNKNOWN: {$phase}");
        }

        $target = $map[$phase];

        // 事实为 UNKNOWN 时，任务只能安全收敛到 timeout；原始 phase 的 target
        // 仍用于“是否迟到”的合法流转判断（见下方 TRANSITIONS 检查）。
        $effectiveTarget = ($executionFact && $executionFact->observedStatus === 'UNKNOWN')
            ? 'timeout'
            : $target;

        if (! $errorCode && $target === 'download_failed') {
            $errorCode = DeployTask::ERR_DOWNLOAD_FAILED;
        }

        if ($errorCode) {
            $task->error_code = $errorCode;
        }
        if ($errorMsg) {
            $task->error_msg = $errorMsg;
        }

        // CANCEL_REQUESTED 只是控制面事实，不是物理停止。任务状态不变，
        // SkillExecution 进入 CANCEL_REQUESTED；资源租约必须继续占用。
        if ($phase === 'cancel_requested') {
            if ($executionFact) {
                $this->persistExecutionFact($task, $executionFact, $eventSeq);
            }
            $task->saveQuietly();

            return $task->fresh();
        }

        if ($task->status !== $target) {
            // 先按端侧“原始 phase”判断这次流转是否合法；不合法 = 迟到报告。
            if (! in_array($target, self::TRANSITIONS[$task->status] ?? [], true)) {
                Log::warning('下发回报被拒:非法状态流转', [
                    'task_no' => $task->task_no,
                    'did'     => $task->did,
                    'from'    => $task->status,
                    'to'      => $target,
                    'phase'   => $phase,
                ]);

                throw new IllegalTaskTransitionException($task->task_no, $task->status, $target);
            }

            // 合法但事实为 UNKNOWN：按安全目标收敛，绝不把任务推进到 success。
            if (! $this->transition($task, $effectiveTarget)) {
                Log::warning('下发回报被拒:安全收敛失败', [
                    'task_no' => $task->task_no,
                    'did'     => $task->did,
                    'from'    => $task->status,
                    'to'      => $effectiveTarget,
                    'phase'   => $phase,
                ]);

                throw new IllegalTaskTransitionException($task->task_no, $task->status, $effectiveTarget);
            }
        } elseif ($task->isDirty()) {
            $task->saveQuietly();
        }

        if ($target === 'success') {
            if ($task->isDryRun()) {
                Log::warning('下发任务空跑(无包),不落安装事实', [
                    'task_no' => $task->task_no,
                    'did'     => $task->did,
                    'skill_id' => $task->skill_id,
                ]);
            } else {
                $this->recordInstall($task);
            }

            if ($task->operation === DeployTask::OP_INSTALL && ! $task->isDryRun()) {
                $this->markEntitlementActivated($task);
                $this->notifier->deliveryCompleted($task);
            } elseif ($task->operation === DeployTask::OP_RECLAIM) {
                $this->markEntitlementExpired($task);
                $this->notifier->trialExpired($task);
            }
        }

        if ($executionFact) {
            $this->persistExecutionFact($task, $executionFact, $eventSeq);
        }

        // 灰度自驱仅用于安装/交付任务；执行事实不参与安装成功率。
        if (! $isExecutionTask) {
            $this->evaluateGrayIfAny($task);
        }

        return $task->fresh();
    }

    protected function assertFactMatchesPhase(string $phase, ExecutionFact $fact): void
    {
        // 终态 phase 允许携带 UNKNOWN：端侧声称 done/failed/canceled 但拿不出
        // 可验证的 StopObservation 时，平台必须降级为 UNKNOWN，不能凭 phase 进终态。
        $expected = match ($phase) {
            'accepted' => ['ACCEPTED'],
            'running', 'downloading', 'installing' => ['RUNNING'],
            'cancel_requested' => ['CANCEL_REQUESTED'],
            'done' => ['SUCCEEDED', 'UNKNOWN'],
            'failed' => ['FAILED', 'UNKNOWN'],
            'canceled' => ['CANCELED', 'UNKNOWN'],
            'timeout', 'unknown' => ['UNKNOWN'],
            default => null,
        };

        if ($expected === null || ! in_array($fact->observedStatus, $expected, true)) {
            throw new \InvalidArgumentException(
                "EXECUTION_FACT_PHASE_MISMATCH: phase={$phase}, observed_status={$fact->observedStatus}"
            );
        }
    }

    protected function persistExecutionFact(DeployTask $task, ExecutionFact $fact, ?int $eventSeq): void
    {
        $execution = SkillExecution::query()
            ->where('execution_id', $task->execution_id)
            ->lockForUpdate()
            ->first();

        if (! $execution) {
            throw new \DomainException('EXECUTION_NOT_FOUND: 执行事实没有对应的执行记录');
        }

        $previousSeq = $execution->last_event_seq !== null ? (int) $execution->last_event_seq : 0;
        if ($eventSeq !== null && $eventSeq <= $previousSeq) {
            throw new \DomainException(
                "EVENT_SEQ_STALE: incoming={$eventSeq}, last={$previousSeq}"
            );
        }

        if ($execution->status !== $fact->observedStatus) {
            if ($execution->isTerminal() || ! $execution->canTransitionTo($fact->observedStatus)) {
                throw new \DomainException(
                    "EXECUTION_TRANSITION_INVALID: {$execution->status} -> {$fact->observedStatus}"
                );
            }
        }

        $execution->fill([
            'deploy_task_id' => $task->id,
            'did' => $task->did,
            'skill_id' => $task->skill_id,
            'skill_version_id' => $task->skill_version_id,
            'entitlement_id' => $task->entitlement_id,
            'status' => $fact->observedStatus,
            'actual_started' => $fact->actualStarted,
            'actual_stopped' => $fact->actualStopped,
            'execution_evidence' => $fact->stopObservation
                ? array_merge($fact->evidence, ['stop_observation' => $fact->stopObservation->toArray()])
                : $fact->evidence,
            'error_code' => $fact->errorCode ?? $task->error_code,
            'error_msg' => $task->error_msg,
            'started_at' => $fact->startedAt
                ? \Illuminate\Support\Carbon::createFromTimestamp($fact->startedAt)
                : $execution->started_at,
            'completed_at' => $fact->isTerminal()
                ? \Illuminate\Support\Carbon::createFromTimestamp($fact->stoppedAt ?? $fact->reportedAt)
                : null,
            'observed_at' => now(),
            'last_event_seq' => $eventSeq ?? $execution->last_event_seq,
        ]);
        $execution->save();
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
    /**
     * 试用回收成功 → 把授权从 `expiring` 落成 `expired`（B-3）。
     *
     * 条件更新（`where status = expiring`）：回收任务可能对一条早已被撤销/已终态的
     * 授权迟到回报，此时不该把它**从别的状态**改写成 expired。命中 0 行即视为"不由本次回收收尾"。
     */
    protected function markEntitlementActivated(DeployTask $task): void
    {
        if (! $task->entitlement_id) {
            return;
        }

        $entitlement = SkillEntitlement::find($task->entitlement_id);
        if (! $entitlement || ! in_array($entitlement->status, [SkillEntitlement::STATUS_GRANTED, SkillEntitlement::STATUS_PENDING_BIND], true)) {
            return;
        }

        $meta = is_array($entitlement->meta) ? $entitlement->meta : [];
        $meta['installed_at'] = now()->toDateTimeString();
        $meta['installed_by'] = 'agent';
        $meta['installed_did'] = $task->did;
        $meta['installed_device_confirmed'] = true;

        $entitlement->status = SkillEntitlement::STATUS_ACTIVE;
        $entitlement->activations = (int) $entitlement->activations + 1;
        $entitlement->meta = $meta;
        $entitlement->save();
    }

    protected function markEntitlementExpired(DeployTask $task): void
    {
        if (! $task->entitlement_id) {
            return;
        }

        SkillEntitlement::query()
            ->whereKey($task->entitlement_id)
            ->where('status', SkillEntitlement::STATUS_EXPIRING)
            ->update(['status' => SkillEntitlement::STATUS_EXPIRED, 'updated_at' => now()]);
    }

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
            case DeployTask::OP_INSTALL:
                // 安装:此前已装同 skill 的其它版本 → updated,否则 installed
                $status = $this->isUpgrade($task, $version) ? 'updated' : 'installed';
                InstallRecord::updateOrCreate($key, $attrs + [
                    'status'       => $status,
                    'installed_at' => now(),
                    // S-5 · 落**真实交付通道**,让熔断能分清"能自动卸载"与"不能"(见 installChannelFor)。
                    'channel'      => $this->installChannelFor($task),
                ]);
                break;

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
            case DeployTask::OP_EXECUTE:
            case DeployTask::OP_CANCEL_EXECUTION:
            default:
                // 启停不改安装事实，技能执行与取消绝不落安装事实！
                break;
        }
    }

    /**
     * 这条安装记录该记的**交付通道**(任务卡 S-5)。
     *
     * 以**本次下发任务的通道**为准 —— 它才是"这条技能实际是怎么落到设备上的"
     * (agent 推送 → `agent`;用户在 DeployController 选 `manual` → `manual`)。
     *
     * ⚠ 为什么**不优先** `skill_entitlement.delivery_channel`:那一列在建单时由
     *   `AppChannel` 落成 `app`(试用才是 `agent`),之后**不随下发更新** ——
     *   一次经 agent 推送的**买断**安装,其授权通道仍然是 `app`。若以授权通道为准,
     *   这类安装会被误记成 `app`,熔断据此判定"无法自动卸载",而它明明有 agent 回程。
     *   故授权通道只在**任务通道缺失**时兜底(防御式:任务通道正常不为空)。
     */
    protected function installChannelFor(DeployTask $task): ?string
    {
        if (is_string($task->channel) && $task->channel !== '') {
            return $task->channel;
        }

        return $task->entitlement_id
            ? optional(SkillEntitlement::find($task->entitlement_id))->delivery_channel
            : null;
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

    /**
     * 生成下发任务号:`DT` + 秒级时间戳 + 随机后缀(Task S-10)。
     *
     * ⚠ 后缀是**6 位大写 base36**(36^6 ≈ 2.18e9 档),不是唯一性保证 —— 唯一性由
     *   `uk_deploy_task_no` + {@see self::create()} 的换号重试兜底。它只是把同一秒内
     *   跨设备碰撞的概率压到可忽略,让重试那条路径几乎不会走到。
     *
     *   此前后缀是 `random_int(1000, 9999)` —— 只有 **9000 档**。秒级时间戳在同一秒内
     *   是常量,唯一性只剩这点随机数,并发下发撞号是生日问题级别:实测(见
     *   TestUniqueNoGuardS9Test)同秒两条就可能撞 `uk_deploy_task_no`,而当时**没有**
     *   任何重试兜底,一次撞号就是下发接口 500。这里把熵放宽到 base36×6,再叠加兜底重试。
     *
     *   写法与 `TicketController::generateTicketNo()` 对齐(同为"前缀+时间戳+随机后缀"),
     *   只是本表沿用既有 `DT` 直连(列宽 40,`DT`+12+1+6 = 21,够放)。前缀与时间戳均在,
     *   运维仍可按 `DT` + 日期在日志里检索;`task_no` 全仓只作**不透明字符串**用,无人按位解析。
     */
    protected function newTaskNo(): string
    {
        return 'DT'.now()->format('ymdHis').'-'.Str::upper(Str::random(6));
    }

    /**
     * 这次 QueryException 是不是「task_no 撞了 `uk_deploy_task_no`」—— 决定要不要换号重试。
     *
     * 与 `TicketController::isDuplicateKey()` **同思路**(MySQL 1062 / `Duplicate entry` /
     * SQLite `UNIQUE constraint failed`),但**多收一道**,理由:
     *
     * ⚠ 换号重试的前提是"冲突由 task_no 引起,换一个号就能成"。`deploy_task` 表**当前**
     *   唯一的唯一键确实是 `uk_deploy_task_no`,但迁移日后完全可能再加别家的唯一约束。
     *   届时若只按"是不是唯一键冲突"判,那条**与造号无关**的冲突会被误当成撞号 ——
     *   无谓地换号重试 5 次,最后把真实错误吞进"重试耗尽"的异常里,根因被掩盖。
     *   故这里连**约束名/列名**一起认,只认本表这一条:
     *     · MySQL  —— 报文含 `uk_deploy_task_no`(8.0 带上表名 `deploy_task.uk_deploy_task_no`,
     *                 子串命中;5.7 即索引名本身);
     *     · SQLite —— 报文含 `deploy_task.task_no`。
     *   两者都不含 → 不是本表撞号,交回调用方照常抛(宁可少重试,也不误吞)。
     */
    protected function isTaskNoDuplicate(QueryException $e): bool
    {
        $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');

        if (! $isDuplicate) {
            return false;
        }

        return str_contains($e->getMessage(), 'uk_deploy_task_no')
            || str_contains($e->getMessage(), 'deploy_task.task_no');
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

    /**
     * 版本单调闸(§4.22 F1 / B-13):拒绝**低于**本机已装版本的安装。
     *
     * ⚠ 这是服务端能兑现的那一半(平台自己不制造降级指令)。端侧应凭**签名元数据**独立
     *   再判一次(Uptane/TUF)—— 那部分待真机 Agent 实现。
     *
     * @throws DowngradeRejectedException
     */
    protected function assertNotDowngrade(string $did, ?int $skillVersionId): void
    {
        if (! $skillVersionId) {
            return;
        }

        $target = SkillVersion::find($skillVersionId);

        if (! $target || blank($target->version)) {
            return;
        }

        $installed = $this->installedVersionForProduct($did, (int) $target->product_id);

        if ($installed !== null && AgentVersion::isDowngrade($installed, (string) $target->version)) {
            throw new DowngradeRejectedException($installed, (string) $target->version);
        }
    }

    /**
     * 该 did 在**同一 product** 下已装(installed/updated,不含 rolled_back)的最高版本。
     *
     * install_record 只有 skill_version_id,版本号取自其 `current_version`(回退到关联版本)。
     */
    protected function installedVersionForProduct(string $did, int $productId): ?string
    {
        $versionIds = SkillVersion::query()->where('product_id', $productId)->pluck('id');

        if ($versionIds->isEmpty()) {
            return null;
        }

        $records = InstallRecord::query()
            ->where('did', $did)
            ->whereIn('skill_version_id', $versionIds)
            ->whereIn('status', ['installed', 'updated'])
            ->get();

        $highest = null;

        foreach ($records as $record) {
            $version = $record->current_version
                ?: optional(SkillVersion::find($record->skill_version_id))->version;

            if (blank($version)) {
                continue;
            }

            if ($highest === null || version_compare((string) $version, (string) $highest, '>')) {
                $highest = (string) $version;
            }
        }

        return $highest;
    }
}