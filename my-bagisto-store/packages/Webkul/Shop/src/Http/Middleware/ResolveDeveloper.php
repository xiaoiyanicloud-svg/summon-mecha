<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * `/api/dev/*` 的鉴权 —— **只认 Bearer token**。
 *
 * ⚠ 为什么只认 token、不接受 cookie-session：
 *   `bootstrap/app.php` 对整条 `api/*` 前缀豁免了 CSRF。若这里接受 session，
 *   就出现了"已登录门户的用户被第三方页面诱导发跨站写请求"的洞。
 *   Bearer 头不受 CSRF 影响，所以对外 API 走 token 是安全的；表单交互走 `/dev/*`。
 *
 * ⚠ 与 internal.key 的边界：这里**绝不读** `config('skill_delivery.upload_key')`。
 *   那是"平台/合作方系统"的单一共享密钥，与"某个开发者"是两回事。
 */
class ResolveDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        $customer = $request->user('sanctum');

        if (! $customer) {
            return response()->json(['message' => '未认证。', 'code' => 'UNAUTHENTICATED'], 401);
        }

        $profile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        // 有买家身份 ≠ 是开发者：必须以 developer_profile 为准
        if (! $profile || ! $profile->isActive()) {
            return response()->json([
                'message' => '尚未完成开发者入驻。',
                'code'    => 'DEVELOPER_PROFILE_REQUIRED',
            ], 403);
        }

        // 让下游能统一用 auth('developer')->user()，与门户侧写法一致
        auth('developer')->setUser($customer);

        $request->attributes->set('developer_profile', $profile);

        return $next($request);
    }
}
