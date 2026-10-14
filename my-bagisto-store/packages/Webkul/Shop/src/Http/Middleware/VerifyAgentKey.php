<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Agent 接入鉴权 —— v3.4 步骤 8。
 *
 * 只挂在 agent 路由组上(注册/心跳/回报)。这些接口能改任务状态,必须挡住。
 * 身份模型与 buyer(customer)/internal(平台) 都不同,故单独一把密钥。
 *
 * 密钥未配置时返回 503 而不是放行 —— 宁可整组不可用,也不要无鉴权接口裸奔(对齐 VerifyInternalKey)。
 *
 * ⚠ 这是"共享密钥"级鉴权,够开发期用;生产应升级为 per-device 证书(计划 §4.22 F1)。
 */
class VerifyAgentKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('deploy_gateways.agent_key');

        if (blank($expected)) {
            return response()->json([
                'message' => 'Agent 接入通道未启用：请在 .env 配置 AGENT_KEY。',
            ], 503);
        }

        $given = (string) $request->header('X-Agent-Key', '');

        if ($given === '' || ! hash_equals((string) $expected, $given)) {
            return response()->json([
                'message' => '未授权的 Agent 请求。',
            ], 401);
        }

        return $next($request);
    }
}
