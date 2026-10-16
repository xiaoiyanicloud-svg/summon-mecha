<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ops\OpsMetricsService;

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
    public function __construct(
        protected OpsMetricsService $metrics,
        protected DeveloperSkillService $skills,
    ) {}
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
     * 总览：入驻状态 + 我的数据 + 我的技能入口。
     *
     * ⚠ 这里有一道**必须显式写出来**的守卫，别删：
     *   `developer_profile.seller_id` 是可空列，而 `OpsMetricsService::forSeller()`
     *   的参数是非空 `int`。所以不能写 `->forSeller($profile->seller_id)` ——
     *   主体没有归属时那会抛 TypeError。更重要的是**为什么不能改成"传 null 就是平台口径"**：
     *   那样"这个开发者没有归属主体"会被静默解释成"给他看平台全站数字"，
     *   而本仓库在 `DeveloperSkillService` 和 `Admin\Customers\DeveloperController`
     *   两处都踩过同一形态的坑（`where('seller_id', null)` 退化成 `is null`，
     *   恰好匹配平台自营）。所以：没归属就**一条数据都不查**，如实说主体未就绪。
     */
    public function dashboard(Request $request)
    {
        $customer = auth('developer')->user();

        $profile = DeveloperProfile::query()
            ->where('customer_id', $customer->id)
            ->first();

        // 只有主体真的有归属时才算数字。没有归属时 $metrics 保持 null，
        // 视图侧走「主体未就绪」而不是渲染一片 0。
        $metrics = null;
        $gaps = null;

        if ($profile && $profile->seller_id) {
            $metrics = $this->metrics->forSeller($profile->seller_id);
            $gaps = $this->metrics->notAvailableForSeller();
        }

        return view('shop::dev.dashboard', [
            'profile'           => $profile,
            'agreementsOk'      => $profile ? $profile->hasSignedAllAgreements() : false,
            'metrics'           => $metrics,
            'gaps'              => $gaps,
            'subjectIncomplete' => $profile && ! $profile->seller_id,
            // 未实名时 dashboard 的「创建技能」按钮改为打开实名引导弹窗（S-12d）、
            // 并显示一条非阻断提示条（R3）。判定统一走 service（唯一实现）。
            'createGate'        => $profile ? $this->skills->canCreateSkill($profile) : null,
        ]);
    }
}
