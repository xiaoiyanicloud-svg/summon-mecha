<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\AgentGatewayManager;
use Webkul\Shop\Services\Deploy\DeployTaskService;

/**
 * Agent 接入接口 —— v3.4 步骤 8(计划 §5.2)。
 *
 * 供设备端 Agent(或本地模拟器)调用:注册换 DID → 心跳 → 上报任务回报 → 拉取指令。
 * 鉴权为共享密钥(agent.key);生产应升级 per-device 证书(§4.22 F1)。
 */
class AgentController extends APIController
{
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
            'sn'         => ['required', 'string', 'max:64'],
            'model_code' => ['nullable', 'string', 'max:64'],
            'vendor_code' => ['nullable', 'string', 'max:32'],
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

        $binding->save();

        return response()->json([
            'message' => '注册成功。',
            'data'    => [
                'did'          => $binding->did,
                'bind_status'  => $binding->bind_status,
                'online'       => true,
                // 生产应返回 per-device 证书;dev 返回共享说明
                'auth_hint'    => '请用 X-Agent-Key 头访问后续接口。',
            ],
        ], 201);
    }

    /**
     * 引导换身份:用一次性 enrollment token 换 DID + 共享密钥。
     *
     * ⚠ 这是"共享密钥 Agent 接口"与"per-device token 安装命令"之间的接缝:
     *   enroll 之前设备什么都没有,只能凭一次性 token;enroll 之后才拿到 X-Agent-Key。
     *   生产应把回传的 agent_key 换成 per-device 证书(计划 §4.22 F1),此处即替换点。
     */
    public function enroll(Request $request): JsonResponse
    {
        $agentKey = config('deploy_gateways.agent_key');

        if (blank($agentKey)) {
            return response()->json(['message' => 'Agent 通道未启用(未配置 AGENT_KEY)。'], 503);
        }

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
            return response()->json(['message' => '安装 token 无效。'], 401);
        }

        $enroll = $binding->meta['agent_enroll'] ?? [];

        if (empty($enroll['expires_at']) || now()->greaterThan(\Illuminate\Support\Carbon::parse($enroll['expires_at']))) {
            return response()->json(['message' => '安装 token 已过期,请重新获取。'], 401);
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

        return response()->json([
            'message' => '已配对。',
            'data'    => [
                'did'               => $binding->did,
                'sn'                => $binding->sn,
                'agent_key'         => $agentKey,
                'server_url'        => rtrim(config('app.url'), '/'),
                'server_public_key' => app(\Webkul\Shop\Services\Deploy\SigningService::class)->publicKeyPem(),
                'auth_hint'         => '后续请求带 X-Agent-Key 头。',
            ],
        ]);
    }

    /**
     * 心跳:刷新在线态。
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $request->validate(['did' => ['required', 'string', 'max:64']]);

        $binding = DeviceBinding::query()->where('did', $payload['did'])->first();
        if (! $binding) {
            return response()->json(['message' => '设备未注册。'], 404);
        }

        // 心跳就是这台设备刚连上来的证据（其余三处握手/配对/取指令同理）
        $binding->markSeen('agent');
        $binding->save();

        return response()->json(['message' => 'ok', 'data' => ['did' => $binding->did, 'online' => true]]);
    }

    /**
     * 上报任务回报,推进状态机。
     */
    public function event(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_no'    => ['required', 'string', 'max:40'],
            'phase'      => ['required', 'string', 'in:downloading,installing,done,failed,timeout'],
            'error_code' => ['nullable', 'string', 'max:32'],
            'error_msg'  => ['nullable', 'string', 'max:512'],
        ]);

        $task = $this->deploy->onEvent(
            $payload['task_no'],
            $payload['phase'],
            $payload['error_code'] ?? null,
            $payload['error_msg'] ?? null,
        );

        if (! $task) {
            return response()->json(['message' => '任务不存在。'], 404);
        }

        return response()->json(['message' => 'ok', 'data' => ['task_no' => $task->task_no, 'status' => $task->status]]);
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

        $binding = DeviceBinding::query()->where('did', $did)->first();
        if (! $binding) {
            return response()->json(['message' => '设备未注册。'], 404);
        }

        // 轮询即心跳
        $binding->markSeen('agent');
        $binding->save();

        // 待执行指令:dispatching(已下发待取)与 pending_online(曾因离线挂起)
        $tasks = DeployTask::query()
            ->where('did', $did)
            ->whereIn('status', ['dispatching', 'pending_online'])
            ->orderBy('id')
            ->get();

        $commands = [];
        foreach ($tasks as $t) {
            if ($t->status === 'pending_online') {
                $this->deploy->transition($t, 'dispatching');
            }
            $commands[] = $this->deploy->buildCommand($t);
        }

        return response()->json(['data' => $commands]);
    }
}
