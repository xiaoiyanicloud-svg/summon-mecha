<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\AgentGatewayManager;
use Webkul\Shop\Services\Deploy\AgentSelfUpdateService;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\IllegalTaskTransitionException;

/**
 * Agent 接入接口 —— v3.4 步骤 8(计划 §5.2)。
 *
 * 供设备端 Agent(或本地模拟器)调用:注册换 DID → 心跳 → 上报任务回报 → 拉取指令。
 * 鉴权:per-device 凭据优先(证书签名 / 独立 secret),共享密钥为过渡形态(§4.22 F1 / B-12)。
 *
 * ── 契约(P9)· 机器消费者一律走 X1 信封 ──
 *   Agent 不是 UI,是**机器消费者**:它按结构解析、按码分支,没有"人看着办"的余地。
 *   故本控制器**全部**出口统一为 APIController 的 `ok()`/`fail()`:
 *     成功 {success:true, message?, data}
 *     失败 {success:false, code, message}
 *   此前这里是"成功返裸 data、失败只有中文 message 无 code"的混合体 ——
 *   端侧无法用统一逻辑判断成败,只能猜文案。
 */
class AgentController extends APIController
{
    /** 能力快照收哪些字段 —— 白名单，避免把任意请求体写进 `binding.meta`。 */
    protected const CAPABILITY_FIELDS = [
        'model_code',
        'firmware_version',
        'development_version',
        'sdk_version',
        'agent_version',
        'vendor_code',
    ];

    public function __construct(
        protected DeployTaskService $deploy,
        protected AgentGatewayManager $gateways,
    ) {}

    /**
     * 首联注册:换 DID(设备身份),并把设备置为在线。
     */
    public function register(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'sn'                   => ['required', 'string', 'max:64'],
            'model_code'           => ['nullable', 'string', 'max:64'],
            'vendor_code'          => ['nullable', 'string', 'max:32'],
            'agent_version'        => ['nullable', 'string', 'max:32'],
            'firmware_version'     => ['nullable', 'string', 'max:64'],
            'development_version'  => ['nullable', 'string', 'max:64'],
            'sdk_version'          => ['nullable', 'string', 'max:64'],
            'capability_snapshot'   => ['nullable', 'array'],
            'capability_snapshot.model_code'          => ['nullable', 'string', 'max:64'],
            'capability_snapshot.firmware_version'    => ['nullable', 'string', 'max:64'],
            'capability_snapshot.development_version' => ['nullable', 'string', 'max:64'],
            'capability_snapshot.sdk_version'         => ['nullable', 'string', 'max:64'],
            'capability_snapshot.agent_version'       => ['nullable', 'string', 'max:32'],
            'capability_snapshot.vendor_code'         => ['nullable', 'string', 'max:32'],
        ]);

        $sn = $payload['sn'];

        // ⚠ 归并(关键):同一台物理机器只应有一条 binding。
        //   用户登记时 DeviceBindingService 建的是 `sn_{sn}` 且带 owner/devices_id;
        //   若这里按 did 另建一条,同一台机器就分裂成两条 —— 下发任务发给 `sn_` 那条,
        //   而 Agent 只拉自己 `did_` 那条,任务永远送不到(且 `sn_` 那条永远判离线)。
        //   故一律先按 sn 复用既有 binding,复用不到才新建。
        $binding = DeviceBinding::query()->where('sn', $sn)->first();

        if ($binding) {
            $binding->source = DeviceBinding::SOURCE_AGENT;

            if (! empty($payload['vendor_code'])) {
                $binding->vendor_code = $payload['vendor_code'];
            }

            if (! $binding->bound_at) {
                $binding->bound_at = now();
            }
        } else {
            $binding = new DeviceBinding([
                'did'         => 'did_'.substr(sha1($sn), 0, 16),
                'source'      => DeviceBinding::SOURCE_AGENT,
                'sn'          => $sn,
                'vendor_code' => $payload['vendor_code'] ?? null,
                'bind_status' => 'bound',
                'bound_at'    => now(),
            ]);
        }

        $binding->markSeen('agent');

        // 标记"这台机器已跑起 Agent" —— 用户侧据此显示"已配对"
        $meta = is_array($binding->meta) ? $binding->meta : [];
        $meta['agent_enrolled_at'] = now()->toDateTimeString();
        $binding->meta = $meta;

        // 能力快照：机型/固件/开发版本/SDK/Agent 版本/厂商 —— 适配证据链的输入。
        // 机型匹配不到时**只记快照，不拒绝注册**（机型库不可能覆盖市面全部设备）。
        $this->rememberCapabilitySnapshot($binding, $payload);
        $this->rememberAgentVersion($binding, $this->reportedAgentVersion($payload));

        $binding->save();

        return $this->ok([
            'did'          => $binding->did,
            'bind_status'  => $binding->bind_status,
            'online'       => true,
            // 生产应返回 per-device 证书;dev 返回共享说明
            'auth_hint'    => '请用 X-Agent-Key 头访问后续接口。',
        ], '注册成功。')->setStatusCode(201);
    }

    /**
     * 引导换身份:用一次性 enrollment token 换 DID + **per-device 凭据**(§4.22 F1)。
     *
     * ⚠ 这是"共享密钥 Agent 接口"与"per-device 凭据"之间的接缝:
     *   enroll 之前设备什么都没有,只能凭一次性 token;enroll 之后才拿到自己的凭据。
     *   生产形态是 per-device 证书 + mTLS(B-12):这里**签发**每设备一张证书 + 一份独立
     *   secret,服务端只存哈希/指纹(见 {@see DeviceCredentialService})。
     *   过渡期仍一并回 `agent_key`(共享密钥)—— 待 `agent_auth_mode=device` 后弃用。
     */
    public function enroll(Request $request): JsonResponse
    {
        $agentKey = config('deploy_gateways.agent_key');

        $payload = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $hash = hash('sha256', $payload['token']);

        // 按 token 哈希定位 binding。meta 是 JSON,这里逐行比对 ——
        // device_binding 规模小(单平台设备数),可接受;真要索引再加列。
        $binding = DeviceBinding::query()
            ->whereNotNull('meta')
            ->get()
            ->first(fn (DeviceBinding $b) => ($b->meta['agent_enroll']['token_hash'] ?? null) === $hash);

        if (! $binding) {
            return $this->fail(ApiErrorCode::ENROLL_TOKEN_INVALID, '安装 token 无效。', 401);
        }

        $enroll = $binding->meta['agent_enroll'] ?? [];

        if (empty($enroll['expires_at']) || now()->greaterThan(\Illuminate\Support\Carbon::parse($enroll['expires_at']))) {
            return $this->fail(ApiErrorCode::ENROLL_TOKEN_EXPIRED, '安装 token 已过期,请重新获取。', 401);
        }

        // 一次性:用掉即清
        $meta = is_array($binding->meta) ? $binding->meta : [];
        unset($meta['agent_enroll']);
        $meta['agent_enrolled_at'] = now()->toDateTimeString();

        $binding->meta = $meta;
        $binding->source = DeviceBinding::SOURCE_AGENT;
        $binding->markSeen('agent');

        if (! $binding->bound_at) {
            $binding->bound_at = now();
        }

        $binding->save();

        // 签发 per-device 凭据(每设备一份,可单独吊销)。签发失败即整个 enroll 失败 ——
        // 宁可不配对,也不回一份"没有凭据"的假成功。
        try {
            $credential = app(DeviceCredentialService::class)->issue($binding);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail(ApiErrorCode::AGENT_CREDENTIAL_INVALID, '设备凭据签发失败,请重试。', 500);
        }

        $authMode = (string) config('deploy_gateways.agent_auth_mode', 'auto');

        return $this->ok([
            'did'               => $binding->did,
            'sn'                => $binding->sn,
            // ── per-device 凭据(目标形态)──
            'device_secret'     => $credential['secret'],
            'certificate_pem'   => $credential['certificate_pem'],
            'private_key_pem'   => $credential['private_key_pem'],
            'ca_pem'            => $credential['ca_pem'],
            'credential_expires_at' => $credential['expires_at'],
            // ── 共享密钥(过渡期保留,待 auth_mode=device 弃用)──
            'agent_key'         => $agentKey,
            'auth_mode'         => $authMode,
            'server_url'        => rtrim(config('app.url'), '/'),
            'server_public_key' => app(\Webkul\Shop\Services\Deploy\SigningService::class)->publicKeyPem(),
            'auth_hint'         => '后续请求带 X-Agent-Did + X-Agent-Secret(或 X-Agent-Cert/Signature);X-Agent-Key 为过渡形态。',
        ], '已配对。');
    }

    /**
     * 心跳:刷新在线态。
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'did'                  => ['required', 'string', 'max:64'],
            'agent_version'        => ['nullable', 'string', 'max:32'],
            'model_code'           => ['nullable', 'string', 'max:64'],
            'vendor_code'          => ['nullable', 'string', 'max:32'],
            'firmware_version'     => ['nullable', 'string', 'max:64'],
            'development_version'  => ['nullable', 'string', 'max:64'],
            'sdk_version'          => ['nullable', 'string', 'max:64'],
            'capability_snapshot'   => ['nullable', 'array'],
            'capability_snapshot.model_code'          => ['nullable', 'string', 'max:64'],
            'capability_snapshot.firmware_version'    => ['nullable', 'string', 'max:64'],
            'capability_snapshot.development_version' => ['nullable', 'string', 'max:64'],
            'capability_snapshot.sdk_version'         => ['nullable', 'string', 'max:64'],
            'capability_snapshot.agent_version'       => ['nullable', 'string', 'max:32'],
            'capability_snapshot.vendor_code'         => ['nullable', 'string', 'max:32'],
        ]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();
        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_REGISTERED, '设备未注册。', 404);
        }

        // 心跳就是这台设备刚连上来的证据（其余三处握手/配对/取指令同理）
        $binding->markSeen('agent');

        // 顺带上报 Agent 自身版本(§4.22 F1 自更新 / B-13)—— 平台据此判断该不该推新。
        $this->rememberAgentVersion($binding, $this->reportedAgentVersion($payload));

        // 心跳同样刷新能力快照：固件/开发版本/SDK 会在设备维护时变化，
        // 只在注册时记一次会让适配判断一直停在首联那一刻。
        $this->rememberCapabilitySnapshot($binding, $payload);

        $binding->save();

        return $this->ok(['did' => $binding->did, 'online' => true], 'ok');
    }

    /**
     * 上报任务回报,推进状态机。
     *
     * ⚠ 必须带 `did` 并**先校验归属再推进**:全局共享密钥下,谁都能拿 task_no 报回报,
     *   若不校验,等于替别人的任务推进状态机(指令劫持)。归属校验放在 onEvent **之前** ——
     *   放到之后则状态已被他人任务改完,校验毫无意义。
     *   (per-device 证书是目标形态,计划 §4.22 F1;此处先把归属一致性补上。)
     */
    public function event(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_no'    => ['required', 'string', 'max:40'],
            'did'        => ['required', 'string', 'max:64'],
            'phase'      => ['required', 'string', 'in:downloading,installing,download_failed,done,failed,timeout'],
            'error_code' => ['nullable', 'string', 'max:32'],
            'error_msg'  => ['nullable', 'string', 'max:512'],
        ]);

        // per-device 身份下,上报者必须就是该设备本人(共享受限时 agent.did 为 null,跳过)。
        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        // 归属:任务必须属于上报者声明的 did。不匹配按"不存在"返回(不泄漏他人任务的存在)
        $owned = DeployTask::query()->where('task_no', $payload['task_no'])->first();

        if (! $owned || $owned->did !== $payload['did']) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        try {
            $task = $this->deploy->onEvent(
                $payload['task_no'],
                $payload['phase'],
                $payload['error_code'] ?? null,
                $payload['error_msg'] ?? null,
            );
        } catch (IllegalTaskTransitionException $e) {
            // 非法回报 —— 明确 409 + 码,而不是"200 ok 但其实没动"。
            // Agent 是机器消费者:它要靠码判断"这条回报被接受了没有";
            // 静默成功会让端云状态永久分叉(端侧继续等、云侧停在原地)。
            return $this->fail(ApiErrorCode::TASK_TRANSITION_INVALID, $e->getMessage(), 409, [
                'data' => ['task_no' => $e->taskNo, 'status' => $e->from],
            ]);
        }

        if (! $task) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        return $this->ok(['task_no' => $task->task_no, 'status' => $task->status], 'ok');
    }

    /**
     * 拉取待执行指令(HTTP 轮询传输;指令队列即 deploy_task 本身)。
     *
     * ⚠ 轮询即心跳:每次拉取都刷新在线态,故无需单独高频心跳。
     */
    public function commands(Request $request): JsonResponse
    {
        $payload = $request->validate(['did' => ['required', 'string', 'max:64']]);
        $did = $payload['did'];

        if ($denied = $this->rejectIdentityMismatch($request, $did)) {
            return $denied;
        }

        $binding = DeviceBinding::query()->where('did', $did)->first();
        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_REGISTERED, '设备未注册。', 404);
        }

        // 轮询即心跳
        $binding->markSeen('agent');
        $binding->save();

        // 待执行指令,两条来源:
        //   · pending_online —— 曾因离线挂起,**从未投递过**(delivered_at 恒为 NULL),上线就该给它;
        //   · dispatching 且**未投递** —— 已下发但还没被取走。
        //
        // ⚠ dispatching 且 delivered_at 非空 = 「已经交到这台设备手上、在等它回报」。
        //   此前这里不过滤投递标记、每轮把该 did 的全部任务重新返回一遍:
        //   设备在"取到指令"与"回报"之间重启,重启后的第一次轮询又会把同一条指令取走 —— 重装。
        //   取走后一直没有回报的,由 `ops:timeout-deploy-tasks` 兜底转 timeout(不是无限等)。
        //
        // ⚠ 每设备每轮**只发一条**(P7):此前一次返回该 did 的全部待执行任务,设备可能
        //   并行起多个安装 —— 而同一台物理机的技能装/卸本就应该串行。限 1 条后,设备
        //   一轮取走一条;取走的那条带上投递标记后即退出本查询,下一轮才轮到下一条。
        $tasks = DeployTask::query()
            ->where('did', $did)
            ->where(function ($q) {
                $q->where('status', 'pending_online')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'dispatching')->whereNull('delivered_at');
                    });
            })
            ->orderBy('id')
            ->limit(1)
            ->get();

        $commands = [];
        foreach ($tasks as $t) {
            $this->deploy->transition($t, 'dispatching');
            $this->deploy->markDelivered($t);
            $commands[] = $this->deploy->buildCommand($t);
        }

        return $this->ok($commands);
    }

    /**
     * per-device 身份一致性闸(§4.22 F1 / B-12)。
     *
     * 中间件在**证书/secret 形态**下已把真身 did 注进 `agent.did`;此时请求体/查询里的 did
     * 必须与之相等 —— 否则就是"拿着 A 的凭据冒充 B 的 did"(共享密钥时代无法防的缺口)。
     * 共享密钥形态下 `agent.did` 为 null(真身未知),不做此校验(旧行为保留,由各控制器
     * 自身的归属校验兜底)。
     */
    protected function rejectIdentityMismatch(Request $request, string $did): ?JsonResponse
    {
        $bound = $request->attributes->get('agent.did');

        if ($bound !== null && $bound !== '' && $bound !== $did) {
            return $this->fail(ApiErrorCode::AGENT_IDENTITY_MISMATCH, '设备身份与请求的 did 不一致。', 403);
        }

        return null;
    }

    /**
     * Agent 自更新工件(§4.22 F1 / B-13)。
     *
     * 设备带上自身 did + 当前版本,取一份**已签名**的最新工件;服务端**只提供更高版本**
     * (防降级,见 {@see AgentSelfUpdateService})。工件的下载/验签/替换/失败回滚是**设备侧**
     * 行为 —— 待真机验证;本接口只保证"平台告诉设备装什么、怎么验"。
     *
     * ⚠ 不提供"强制降级"入口:自更新的语义只能是"向上",向下的动作只有显式回滚(OP_ROLLBACK)。
     */
    public function selfUpdate(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'did'           => ['nullable', 'string', 'max:64'],
            'agent_version' => ['nullable', 'string', 'max:32'],
        ]);

        if (! empty($payload['did']) && ($denied = $this->rejectIdentityMismatch($request, $payload['did']))) {
            return $denied;
        }

        $binding = ! empty($payload['did'])
            ? DeviceBinding::query()->where('did', $payload['did'])->first()
            : $request->attributes->get('agent.binding');

        // 上报即记录(去重:只在版本变化时落库)
        if ($binding && ! empty($payload['agent_version'])) {
            $this->rememberAgentVersion($binding, $payload['agent_version']);
            $binding->save();
        }

        $current = $payload['agent_version']
            ?? ($binding && is_array($binding->meta) ? ($binding->meta['agent_version'] ?? null) : null);

        return $this->ok(app(AgentSelfUpdateService::class)->offerFor($current), 'ok');
    }

    /**
     * 把设备上报的 Agent 版本记进 binding.meta(仅当版本变化 —— 心跳频繁,不做无谓写)。
     */
    protected function rememberAgentVersion(DeviceBinding $binding, ?string $version): void
    {
        if (blank($version)) {
            return;
        }

        $meta = is_array($binding->meta) ? $binding->meta : [];

        if (($meta['agent_version'] ?? null) === $version) {
            return;
        }

        $meta['agent_version'] = $version;
        $meta['agent_version_seen_at'] = now()->toDateTimeString();
        $binding->meta = $meta;
    }

    /**
     * Agent 版本的两种上报形态：顶层 `agent_version`，或 `capability_snapshot.agent_version`。
     * 顶层优先；两者都空则视为未上报。
     *
     * @param  array<string, mixed>  $payload
     */
    protected function reportedAgentVersion(array $payload): ?string
    {
        $top = $payload['agent_version'] ?? null;

        if (is_string($top) && trim($top) !== '') {
            return $top;
        }

        $nested = is_array($payload['capability_snapshot'] ?? null)
            ? ($payload['capability_snapshot']['agent_version'] ?? null)
            : null;

        return is_string($nested) && trim($nested) !== '' ? $nested : null;
    }

    /**
     * 记录 Agent 自报的能力快照，并把能匹配上的机型同步到 `binding.model_id`。
     *
     * 取值优先级：顶层字段 > `capability_snapshot` 数组（兼容两种上报形态）。
     * 只收白名单字段；空串按"未上报"处理。
     *
     * ⚠ 按**增量合并**处理，不是整表替换：心跳经常只带部分字段（例如只带
     *   `agent_version`），整表替换会把上一次已上报的固件/SDK 抹掉，
     *   之后既无法判定也无法向用户解释。缺省字段 = 本次未上报，保留上一次已知值。
     *   完全没有能力字段的上报（纯在线心跳）连 `reported_at` 都不刷新。
     *
     * ⚠ 匹配不到机型**不拒绝注册/心跳**：机型库是平台已知范围的子集，
     *   把"平台不认识"当成"设备有问题"会挡掉真实设备。未知机型在适配判定里
     *   走 `unverified`，由证据补齐，而不是在这里失败。
     *
     * @param  array<string, mixed>  $payload
     */
    protected function rememberCapabilitySnapshot(DeviceBinding $binding, array $payload): void
    {
        $nested = is_array($payload['capability_snapshot'] ?? null) ? $payload['capability_snapshot'] : [];

        $reported = [];

        foreach (self::CAPABILITY_FIELDS as $field) {
            $value = $payload[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $value = $nested[$field] ?? null;
            }

            if (is_string($value) && trim($value) !== '') {
                $reported[$field] = trim($value);
            }
        }

        if ($reported === []) {
            return;
        }

        $meta = is_array($binding->meta) ? $binding->meta : [];
        $previous = is_array($meta['capability_snapshot'] ?? null) ? $meta['capability_snapshot'] : [];

        $snapshot = array_merge($previous, $reported);
        $snapshot['reported_at'] = now()->toDateTimeString();

        $meta['capability_snapshot'] = $snapshot;
        $binding->meta = $meta;

        if (isset($reported['vendor_code'])) {
            $binding->vendor_code = $reported['vendor_code'];
        }

        if (array_key_exists('model_code', $reported)) {
            $model = DeviceModel::query()->where('model_code', $reported['model_code'])->first();

            // 平台不认识的新机型也要把旧 model_id 清掉：否则换机后仍沿用旧机型的
            // 通道与证据，兼容性结论会张冠李戴（认不出 ≠ 保持原样）。
            $binding->model_id = $model?->id;
        }
    }
}
