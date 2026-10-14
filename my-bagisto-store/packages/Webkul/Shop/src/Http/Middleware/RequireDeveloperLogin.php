<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 开发者门户的**轻量登录门槛** —— 只要登录，不要求已入驻。
 *
 * 为什么要它：入驻表单、协议签署这类页面，用户"已登录但还没有 developer_profile"。
 * 若也用 AuthenticateDeveloper，就会被弹到入驻页 → 入驻页又被弹 → 死循环。
 */
class RequireDeveloperLogin
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('developer')->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '请先登录开发者门户。', 'code' => 'UNAUTHENTICATED'], 401);
            }

            return redirect()->route('shop.dev.login');
        }

        return $next($request);
    }
}
