<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 技能上传通道的鉴权。
 *
 * 只挂在 skill-admin 路由组上 —— 这是本项目第一次出现"商品写接口",必须挡住。
 * 之前 Shop API 的商品接口全是 GET,所以没有任何写入口需要保护。
 *
 * 刻意**不**复用 api.customer:那是"买家身份",而上传方是平台/合作方系统,
 * 两者身份模型不同,混用会让将来接多商户时无从区分。
 *
 * 密钥未配置时返回 503 而不是放行 —— 宁可整组不可用,也不要无鉴权的写接口裸奔。
 */
class VerifyInternalKey
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $expected = config('skill_delivery.upload_key');

        if (blank($expected)) {
            return response()->json([
                'message' => '技能上传通道未启用：请在 .env 配置 SKILL_UPLOAD_KEY。',
            ], 503);
        }

        $given = (string) $request->header('X-Internal-Key', '');

        // hash_equals 做定长时间比较,避免用 === 泄露前缀匹配长度
        if ($given === '' || ! hash_equals((string) $expected, $given)) {
            return response()->json([
                'message' => '未授权的上传请求。',
            ], 401);
        }

        return $next($request);
    }
}
