<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;

/**
 * Agent 接入鉴权 —— v3.4 步骤 8,per-device 凭据升级见 §4.22 F1(B-12)。
 *
 * 只挂在 agent 路由组上(注册/心跳/回报/取指令)。这些接口能改任务状态,必须挡住。
 * 身份模型与 buyer(customer)/internal(平台) 都不同,故单独一套鉴权。
 *
 * ── 鉴权形态与渐进切换(agent_auth_mode)──
 *   1. **per-device 证书 + 请求签名**(目标形态,证明持有私钥 —— 见下)
 *      X-Agent-Did + X-Agent-Signature + X-Agent-Ts
 *   2. **per-device bearer secret**(过渡:每设备一份独立密钥)
 *      X-Agent-Did + X-Agent-Secret
 *   3. **共享密钥**(旧形态,过渡期保留 / `device` 模式下弃用)
 *      X-Agent-Key
 *
 *   三种形态下,**首次命中即身份确定**:1/2 能定位到唯一 did(注入 `agent.did`,供控制器
 *   做归属一致性校验);3 定位不到 —— 共享密钥下真身未知,这正是 B-12 要消灭的缺口。
 *
 * ⚠ 证书形态的**服务端校验逻辑**已做实并测试(链到平台 CA + 指纹一致 + 签名验签 + ts 窗口);
 *   **设备侧 mTLS 握手 / 私钥签名** 待真机验证 —— 本机无真机/EMQX。
 *
 * 密钥/凭据都不可用时:共享密钥缺配置且无凭据 → 503(整组不可用,不放行);否则 401。
 */
class VerifyAgentKey
{
    public function __construct(protected DeviceCredentialService $credentials) {}

    public function handle(Request $request, Closure $next)
    {
        $mode = (string) config('deploy_gateways.agent_auth_mode', 'auto');

        // ── 形态 1 / 2:per-device 凭据(带 did,能定位真身)──
        $did = (string) $request->header('X-Agent-Did', '');

        if ($did !== '') {
            $binding = DeviceBinding::query()->where('did', $did)->first();

            // 不区分"设备不存在"与"凭据错" —— 两者都 401,不给枚举信号。
            if (! $binding) {
                return $this->deny('设备凭据无效。', 401);
            }

            if ($this->authenticateDevice($request, $binding)) {
                // 真身已知 → 注入,供控制器做 did 一致性校验(消灭"同 key 冒充他人 did")。
                $request->attributes->set('agent.did', $binding->did);
                $request->attributes->set('agent.binding', $binding);

                return $next($request);
            }

            // 带了 did 却没通过 → 直接拒,绝不**回退**到共享密钥
            // (否则一个坏凭据会被共享密钥"救活",吊销/过期形同虚设)。
            return $this->deny('设备凭据无效。', 401);
        }

        // ── 形态 3:共享密钥(过渡;device 模式下弃用)──
        if ($mode !== 'device') {
            $sharedKey = config('deploy_gateways.agent_key');
            $given = (string) $request->header('X-Agent-Key', '');

            if (blank($sharedKey)) {
                // 没配共享密钥、也没带任何 per-device 凭据 → 整组不可用(对齐旧行为,不放行)。
                return $this->deny('Agent 接入通道未启用：请在 .env 配置 AGENT_KEY。', 503);
            }

            if ($given !== '' && hash_equals((string) $sharedKey, $given)) {
                // 共享密钥下真身未知 → agent.did 置 null。
                $request->attributes->set('agent.did', null);

                return $next($request);
            }
        }

        return $this->deny('未授权的 Agent 请求。', 401);
    }

    /**
     * 校验一条 per-device 凭据(证书签名优先,其次 bearer secret)。
     */
    protected function authenticateDevice(Request $request, DeviceBinding $binding): bool
    {
        $signature = (string) $request->header('X-Agent-Signature', '');
        $ts = (string) $request->header('X-Agent-Ts', '');

        if ($signature !== '' && $ts !== '') {
            if ($this->credentials->verifyRequestSignature(
                $binding,
                $signature,
                $ts,
                $request->method(),
                $request->path(),
            )) {
                return true;
            }
        }

        $secret = (string) $request->header('X-Agent-Secret', '');

        if ($secret !== '' && $this->credentials->verifySecret($binding, $secret)) {
            return true;
        }

        return false;
    }

    protected function deny(string $message, int $status)
    {
        return response()->json(['message' => $message], $status);
    }
}
