<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * 开发者门户 · 登录 / 登出 / 总览 —— v3.4 步骤 9。
 *
 * ⚠ 三件事与前台刻意不同（别照抄前台写法）：
 *   1. 用 `developer` guard，不是 `customer`（两套会话互不影响）；
 *   2. 未登录跳 `/dev/login`，不是 `/admin`（前台停用那条路对开发者不适用）；
 *   3. 不复用 AuthenticateCustomer 中间件（它会 redirect 到 /admin）。
 */
class PortalController extends Controller
{
    public function showLogin()
    {
        if (auth('developer')->check()) {
            return redirect()->route('shop.dev.dashboard');
        }

        return view('shop::dev.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 账号就是 customers 表 —— 与 C 端同账号体系，只是走 developer 会话
        if (! auth('developer')->attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => '邮箱或密码不正确。']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('shop.dev.dashboard'));
    }

    public function logout(Request $request)
    {
        auth('developer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('shop.dev.login');
    }

    /**
     * 总览：入驻状态 + 我的技能入口。
     */
    public function dashboard(Request $request)
    {
        $customer = auth('developer')->user();

        $profile = DeveloperProfile::query()
            ->where('customer_id', $customer->id)
            ->first();

        return view('shop::dev.dashboard', [
            'profile'       => $profile,
            'agreementsOk'  => $profile ? $profile->hasSignedAllAgreements() : false,
        ]);
    }
}
