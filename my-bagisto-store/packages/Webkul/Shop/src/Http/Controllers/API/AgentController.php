<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\AgentGatewayManager;
use Webkul\Shop\Services\Deploy\AgentFactSignatureVerifier;
use Webkul\Shop\Services\Deploy\AgentEntitlementService;
use Webkul\Shop\Services\Deploy\AgentSelfUpdateService;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DeployInboxService;
use Webkul\Shop\Services\Deploy\DeployMetricCounter;
use Webkul\Shop\Services\Deploy\DeployReconcileService;
use Webkul\Shop\Services\Deploy\DesiredStateService;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\DeviceHealthService;
use Webkul\Shop\Services\Deploy\IllegalTaskTransitionException;
use Webkul\Shop\Services\Deploy\Contracts\StopObservation;
use Webkul\Shop\Services\Deploy\Contracts\StopObservationSourceForbiddenException;

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
        protected DeployInboxService $inbox,
        protected DeployReconcileService $reconciler,
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
            // A3：设备端增量计数。键集由服务层白名单裁决（控制器不再维护第二份清单）。
            'metrics'              => ['nullable', 'array'],
            'metrics.batch_id'     => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:-]{8,64}$/'],
            'metrics.counters'     => ['nullable', 'array', 'max:16'],
            'metrics.counters.*'   => ['integer', 'min:0', 'max:1000000'],
            'metrics.gauges'       => ['nullable', 'array', 'max:8'],
            'metrics.gauges.*'     => ['integer', 'min:0', 'max:100000000'],
            // A2：健康快照（瞬时值，覆盖）。采不到就不带 —— null 表示"本次未上报"。
            'health'                      => ['nullable', 'array'],
            'health.battery_pct'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'health.temperature_c'        => ['nullable', 'numeric', 'between:-40,150'],
            'health.cpu_pct'              => ['nullable', 'numeric', 'between:0,100'],
            'health.memory_used_mb'       => ['nullable', 'integer', 'min:0', 'max:16777216'],
            'health.memory_total_mb'      => ['nullable', 'integer', 'min:0', 'max:16777216'],
            'health.disk_free_mb'         => ['nullable', 'integer', 'min:0', 'max:16777216'],
            'health.disk_total_mb'        => ['nullable', 'integer', 'min:0', 'max:16777216'],
            'health.uptime_seconds'       => ['nullable', 'integer', 'min:0', 'max:3153600000'],
            'health.error_counters'       => ['nullable', 'array', 'max:32'],
            'health.error_counters.*'     => ['integer', 'min:0', 'max:1000000000'],
            'health.sampled_at'           => ['nullable', 'integer', 'min:1'],
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

        // A2：健康快照（电量/温度/CPU/内存/磁盘/错误码趋势）。瞬时值，随心跳覆盖。
        if (! empty($payload['health'])) {
            try {
                app(DeviceHealthService::class)->apply($binding, $payload['health']);
            } catch (\InvalidArgumentException $exception) {
                // 与 A3 未知指标键同口径：4xx 让 Agent 停止重试并丢弃该批，
                // 而不是 5xx 制造一个永远重试不完的坏批次。
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
            }
        }

        $binding->save();

        // A3：应用设备端增量计数。`(did, batch_id)` 幂等 —— Agent 重发同一批不会重复累加；
        // 重复批次同样回 200，因为对 Agent 来说"我报过了"就够了，它要看的是能不能安全清空本地增量。
        if (! empty($payload['metrics'])) {
            $counters = (array) ($payload['metrics']['counters'] ?? []);
            $gauges = (array) ($payload['metrics']['gauges'] ?? []);
            $batchId = (string) ($payload['metrics']['batch_id'] ?? '');

            // 有增量就必须带批次号：否则重发无法按 (did,batch_id) 去重，只会重复累加。
            if ($counters !== [] && $batchId === '') {
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, 'metrics.counters 必须同时携带 metrics.batch_id。', 422);
            }

            try {
                if ($counters !== []) {
                    app(DeployMetricCounter::class)->applyAgentBatch(
                        (string) $payload['did'],
                        $batchId,
                        $counters,
                    );
                }
                if ($gauges !== []) {
                    app(DeployMetricCounter::class)->applyAgentGauges(
                        (string) $payload['did'],
                        array_map('intval', $gauges),
                    );
                }
            } catch (\InvalidArgumentException $exception) {
                // 键不在白名单 = Agent 侧写错了指标名。回 4xx 让它**停止重试并丢弃该批**，
                // 而不是 5xx 造成一个永远重试不完的坏批次。
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
            }
        }

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
            'task_no' => ['required', 'string', 'max:40'],
            'did' => ['required', 'string', 'max:64'],
            'event_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{8,64}$/'],
            'event_seq' => ['nullable', 'integer', 'min:1'],
            'phase' => ['required', 'string', 'in:accepted,running,downloading,installing,cancel_requested,download_failed,done,failed,canceled,timeout,unknown'],
            'error_code' => ['nullable', 'string', 'max:32'],
            'error_msg' => ['nullable', 'string', 'max:512'],
            'execution_id' => ['nullable', 'string', 'max:64'],
            'observed_status' => ['nullable', 'string', 'in:ACCEPTED,RUNNING,CANCEL_REQUESTED,SUCCEEDED,FAILED,CANCELED,UNKNOWN'],
            'actual_started' => ['nullable', 'boolean'],
            'actual_stopped' => ['nullable', 'boolean'],
            'started_at' => ['nullable', 'integer', 'min:1'],
            'stopped_at' => ['nullable', 'integer', 'min:1'],
            'evidence' => ['nullable', 'array'],
            'stop_observation' => ['nullable', 'array'],
            'fact_envelope' => ['nullable', 'array'],
            'reported_at' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        // A1：事件正文验签必须在 Inbox / 任务状态 / SkillExecution 修改之前完成。
        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();

        if ($binding) {
            $factAuth = app(AgentFactSignatureVerifier::class)->verify($binding, $payload);

            if (! $factAuth['ok']) {
                $status = in_array($factAuth['code'], [
                    AgentFactSignatureVerifier::CODE_INVALID,
                    AgentFactSignatureVerifier::CODE_KEY_UNKNOWN,
                    AgentFactSignatureVerifier::CODE_KEY_REVOKED,
                    AgentFactSignatureVerifier::CODE_KEY_STALE,
                    AgentFactSignatureVerifier::CODE_KEY_INACTIVE,
                ], true) ? 401 : 422;

                // A3：验签被拒的次数就是"有人在伪造/配错了密钥"的可观测信号，
                // 必须在**拒绝发生的那一处**计数，否则事后只能翻日志、算不出趋势。
                app(DeployMetricCounter::class)->increment('fact_auth_rejected_total');

                return $this->fail($factAuth['code'], $factAuth['message'] ?? '事件正文认证失败。', $status);
            }

            // 只记确定性元数据（level/key_id），不写时间戳 —— 否则同载荷重投的哈希会漂移。
            $payload['_platform_fact_auth'] = [
                'level'  => $factAuth['level'],
                'key_id' => $factAuth['key_id'],
            ];
        }

        $task = DeployTask::query()->where('task_no', $payload['task_no'])->first();
        if (! $task || $task->did !== $payload['did']) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        $fact = null;
        if (in_array((int) $task->operation, DeployTask::EXECUTION_OPERATIONS, true)) {
            $executionId = (string) ($payload['execution_id'] ?? $task->execution_id ?? '');
            if ($task->execution_id && $executionId !== (string) $task->execution_id) {
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, 'execution_id 与任务不一致。', 422);
            }

            $observedStatus = (string) ($payload['observed_status'] ?? $this->executionStatusForPhase($payload['phase']));
            $isTerminal = in_array($observedStatus, ['SUCCEEDED', 'FAILED', 'CANCELED'], true);
            $reportedAt = (int) ($payload['reported_at'] ?? now()->timestamp);

            $observation = null;
            $downgradeReason = null;

            if (! empty($payload['stop_observation']) && is_array($payload['stop_observation'])) {
                $observationData = $payload['stop_observation'];
                $observationData['execution_id'] = $observationData['execution_id'] ?? $executionId;

                if ((string) $observationData['execution_id'] !== $executionId) {
                    return $this->fail(ApiErrorCode::VALIDATION_ERROR, 'stop_observation.execution_id 与任务不一致。', 422);
                }

                try {
                    $observation = StopObservation::fromArray($observationData);
                } catch (StopObservationSourceForbiddenException $exception) {
                    // A2.2：SDK 返回 / BT halt / 云端 ACK / mock / SIL 冒充物理停止 → 明确拒绝。
                    return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
                } catch (\InvalidArgumentException $exception) {
                    // A2.3：形状非法 → 不进入终态，降级 UNKNOWN。
                    $downgradeReason = 'INVALID_STOP_OBSERVATION';
                    $observation = null;
                }

                if ($observation !== null) {
                    // 观察与事实必须描述同一件事，禁止“事实说已停、观察说未停”的拼接。
                    if ($observation->actualStarted !== (bool) ($payload['actual_started'] ?? false)
                        || $observation->actualStopped !== (bool) ($payload['actual_stopped'] ?? false)) {
                        return $this->fail(
                            ApiErrorCode::VALIDATION_ERROR,
                            'stop_observation 与事实的 actual_started/actual_stopped 不一致。',
                            422,
                        );
                    }

                    if (! $observation->isAcceptable()) {
                        $downgradeReason = 'UNACCEPTABLE_STOP_OBSERVATION';
                        $observation = null;
                    } elseif (! $observation->isFresh($reportedAt)) {
                        $downgradeReason = 'STALE_STOP_OBSERVATION';
                        $observation = null;
                    }
                }
            } elseif ($isTerminal) {
                $downgradeReason = 'STOP_OBSERVATION_MISSING';
            }

            // 只有“声称已停但观察不可信”才降级为 UNKNOWN；声称未停的终态仍按原契约 422。
            // 降级依据只使用 payload 内的 reported_at/observed_at，重试同载荷得到同一哈希。
            if ($isTerminal
                && (bool) ($payload['actual_stopped'] ?? false)
                && $observation === null) {
                $observedStatus = 'UNKNOWN';
                $payload['actual_stopped'] = false;
                $payload['stopped_at'] = null;
                // 保留端侧原始 phase（如 done）用于迟到判定与审计；
                // onEvent 会在 fact=UNKNOWN 时把任务安全收敛到 timeout，绝不推进到 success。
                $payload['evidence'] = array_merge(
                    is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    ['stop_observation_downgrade' => $downgradeReason],
                );
            } elseif (! $isTerminal && $downgradeReason !== null) {
                $payload['evidence'] = array_merge(
                    is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    ['stop_observation_ignored' => $downgradeReason],
                );
            }

            try {
                $fact = new \Webkul\Shop\Services\Deploy\Contracts\ExecutionFact(
                    executionId: $executionId,
                    observedStatus: $observedStatus,
                    actualStarted: (bool) ($payload['actual_started'] ?? false),
                    actualStopped: (bool) ($payload['actual_stopped'] ?? false),
                    startedAt: isset($payload['started_at']) ? (int) $payload['started_at'] : null,
                    stoppedAt: isset($payload['stopped_at']) ? (int) $payload['stopped_at'] : null,
                    errorCode: $payload['error_code'] ?? null,
                    evidence: is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [],
                    reportedAt: $reportedAt,
                    stopObservation: $observation,
                );
            } catch (\InvalidArgumentException $exception) {
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
            }
        }

        try {
            $result = $this->inbox->processFact($payload, $fact);
        } catch (IllegalTaskTransitionException $exception) {
            return $this->fail(ApiErrorCode::TASK_TRANSITION_INVALID, $exception->getMessage(), 409, [
                'data' => ['task_no' => $exception->taskNo, 'status' => $exception->from],
            ]);
        } catch (\DomainException $exception) {
            return $this->fail(ApiErrorCode::TASK_TRANSITION_INVALID, $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
        }

        if (! empty($result['not_found'])) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        if (! empty($result['conflict'])) {
            return $this->fail(ApiErrorCode::CONFLICT, '同一 event_id 的事实载荷不一致。', 409);
        }

        $event = $result['event'];
        if (! empty($result['terminal_conflict'])) {
            return $this->fail(
                ApiErrorCode::TASK_TRANSITION_INVALID,
                '终态冲突事实已入账，但服务端终态未回退。',
                409,
                ['data' => [
                    'task_no' => $result['task']->task_no,
                    'status' => $result['task']->status,
                    'event_id' => $event->event_id,
                    'ack_id' => $event->ack_id,
                    'late_report' => true,
                ]],
            );
        }
        $updatedTask = $result['task'];

        return $this->ok([
            'task_no' => $updatedTask->task_no,
            'status' => $updatedTask->status,
            'event_id' => $event->event_id,
            'ack_id' => $event->ack_id,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
            'applied' => (bool) $event->applied,
            'late_report' => (bool) ($result['late_report'] ?? $event->late_report),
            'server_time' => now()->timestamp,
        ], ! empty($result['late_report']) ? '迟到事实已入账，服务端终态未回退。' : 'ok');
    }

    protected function executionStatusForPhase(string $phase): string
    {
        return match ($phase) {
            'accepted' => 'ACCEPTED',
            'running', 'downloading', 'installing' => 'RUNNING',
            'cancel_requested' => 'CANCEL_REQUESTED',
            'done' => 'SUCCEEDED',
            'failed' => 'FAILED',
            'canceled' => 'CANCELED',
            'timeout', 'unknown' => 'UNKNOWN',
            default => throw new \InvalidArgumentException("无法从 phase 推导执行状态: {$phase}"),
        };
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
        $protocolVersion = (string) ($request->query('protocol_version')
            ?? $request->header('X-Agent-Protocol')
            ?? DeployTask::PROTOCOL_V1);

        $task = $this->deploy->claimNextCommand($did, $protocolVersion);
        $commands = $task ? [$this->deploy->buildCommand($task)] : [];

        return $this->ok($commands);
    }

    /**
     * A5：设备执行前查询本机是否仍持有该技能的有效授权。
     *
     * 为什么需要独立接口：指令里虽有 `authorization_expires_at`，但那是**下发那一刻**
     * 的快照；设备离线数天后仍可能按旧指令执行。回收/退款/过期必须能在执行前
     * 重新问一次平台，且不能依赖"用户主动连网"以外的任何前提。
     *
     * 语义：**200 + allowed=false** 是正常业务结果（Agent 按 reason 分支并缓存），
     * 不是 HTTP 错误。只有身份不匹配/设备不存在才走 4xx。
     */
    public function entitlementCheck(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'did'              => ['required', 'string', 'max:64'],
            'skill_id'         => ['required', 'integer', 'min:1'],
            'skill_version_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();

        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_REGISTERED, '设备未注册。', 404);
        }

        $binding->markSeen('agent');
        $binding->save();

        $result = app(AgentEntitlementService::class)->check(
            $binding,
            (int) $payload['skill_id'],
            isset($payload['skill_version_id']) ? (int) $payload['skill_version_id'] : null,
        );

        return $this->ok($result, $result['allowed'] ? '授权有效。' : '授权不可用。');
    }

    /**
     * A4：声明式目标状态 —— 这台设备"应该"装什么，与平台已知的实际状态差在哪。
     *
     * 只读：不在这里改任务、不改授权。自动收敛由服务层开关控制（默认关闭）。
     */
    public function desiredState(Request $request): JsonResponse
    {
        $payload = $request->validate(['did' => ['required', 'string', 'max:64']]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();

        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_REGISTERED, '设备未注册。', 404);
        }

        $binding->markSeen('agent');
        $binding->save();

        return $this->ok(app(DesiredStateService::class)->snapshot($binding), 'ok');
    }

    /**
     * Agent 接收任务 / 事件持久化 ACK 确认（端云双向对账通道）。
     */
    public function ack(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_no'  => ['required', 'string', 'max:40'],
            'did'      => ['required', 'string', 'max:64'],
            'event_id' => ['required', 'string', 'max:64'],
            'stage'    => ['required', 'string', 'max:32'],
        ]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        $task = DeployTask::query()->where('task_no', $payload['task_no'])->first();
        if (! $task || $task->did !== $payload['did']) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        $result = $this->inbox->acknowledge($payload);

        if (! empty($result['not_found'])) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        if (! empty($result['conflict'])) {
            return $this->fail(ApiErrorCode::CONFLICT, '同一 event_id 的 ACK 载荷不一致。', 409);
        }

        $event = $result['event'];

        return $this->ok([
            'task_no'     => $payload['task_no'],
            'did'         => $payload['did'],
            'event_id'    => $payload['event_id'],
            'stage'       => $payload['stage'],
            'ack_id'      => $event->ack_id,
            'status'      => 'acknowledged',
            'duplicate'   => (bool) ($result['duplicate'] ?? true),
            'server_time' => now()->timestamp,
        ], 'ACK 成功。');
    }

    /**
     * Agent 事实对账接口（支持断电、迟到回报与脱机恢复）。
     */
    public function reconcile(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'did' => ['required', 'string', 'max:64'],
            'model_code' => ['required', 'string', 'max:64'],
            'observed_generation' => ['required', 'integer', 'min:0'],
            'skills' => ['nullable', 'array'],
            'skills.*.skill_version_id' => ['required', 'integer', 'min:1'],
            'skills.*.status' => ['required', 'string', 'max:32'],
            'skills.*.version' => ['nullable', 'string', 'max:64'],
            'skills.*.package_sha256' => ['nullable', 'string', 'size:64'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.task_no' => ['required', 'string', 'max:40'],
            'tasks.*.status' => ['required', 'string', 'max:32'],
            'active_executions' => ['nullable', 'array'],
            'active_executions.*.execution_id' => ['required', 'string', 'max:64'],
            'active_executions.*.status' => ['required', 'string', 'max:32'],
            'outbox_cursor' => ['nullable', 'string', 'max:128'],
            'reported_at' => ['required', 'integer', 'min:1'],
        ]);

        if ($denied = $this->rejectIdentityMismatch($request, $payload['did'])) {
            return $denied;
        }

        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();
        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_REGISTERED, '设备未注册。', 404);
        }

        $binding->markSeen('agent');
        $binding->save();

        try {
            $reconciliation = $this->reconciler->reconcile($binding, $payload);
        } catch (\DomainException $exception) {
            return $this->fail(ApiErrorCode::CONFLICT, $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $exception->getMessage(), 422);
        }

        return $this->ok($reconciliation, '对账完成。');
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

        // 强凭据与吊销防线：已吊销独立凭据的设备绝不允许回退到共享密钥访问
        $binding = DeviceBinding::query()->where('did', $did)->first();
        if ($binding) {
            $credentialService = app(DeviceCredentialService::class);
            if ($credentialService->isRevoked($binding)) {
                return $this->fail(ApiErrorCode::AGENT_CREDENTIAL_INVALID, '设备凭据已被吊销，禁止访问。', 401);
            }
            $mode = (string) config('deploy_gateways.agent_auth_mode', 'auto');
            if ($mode === 'device' && empty($bound)) {
                return $this->fail(ApiErrorCode::UNAUTHENTICATED, '生产环境要求独立设备认证，禁止共享密钥访问。', 401);
            }
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
