<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * 开发者门户鉴权 —— 要求**登录 + 已入驻且状态正常**。
 *
 * 与 AuthenticateCustomer 的区别（别混用）：
 *   - 用 `developer` guard，不是 `customer`（两者会话互不影响）；
 *   - 未登录跳 `/dev/login`，**不是** `/admin`（前台停用那条路径对开发者不适用）；
 *   - 登录了但还没入驻 → 送去入驻页，而不是弹回登录（否则两边互踢成死循环）。
 *
 * 校验通过后把 profile 放进 request attributes，控制器不必再自查一遍。
 */
class AuthenticateDeveloper
{
    public function handle(Request $request, Closure $next)
    {
        $customer = auth('developer')->user();

        if (! $customer) {
            return $this->deny($request, '请先登录开发者门户。', 'shop.dev.login');
        }

        $profile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        // 还没有开发者主体 —— 这不算失败，是"还没入驻"
        if (! $profile) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => '尚未完成开发者入驻。',
                    'code'    => 'DEVELOPER_PROFILE_REQUIRED',
                ], 403);
            }

            return redirect()->route('shop.dev.onboarding');
        }

        if (! $profile->isActive()) {
            auth('developer')->logout();

            return $this->deny($request, '开发者账号已停用。', 'shop.dev.login');
        }

        $request->attributes->set('developer_profile', $profile);

        return $next($request);
    }

    protected function deny(Request $request, string $message, string $route)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'UNAUTHENTICATED'], 401);
        }

        return redirect()->route($route);
    }
}
