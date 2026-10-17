<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // S-11e：入驻五步进度。**只读既有事实**（invite_id / 登录态 / 实名两层 /
            // 协议签署 / 该 seller 下是否有商品），不新增任何真相源或状态列。
            'onboardingSteps'   => $this->onboardingSteps($profile),
        ]);
    }

    /**
     * 入驻进度五步（S-11e）—— 顺序即指引：第一个未完成的就是「进行中」。
     *
     * 口径硬约束：实名一律写「平台人工审核（人工确认，非身份核验）」，
     * **严禁**写成"实名认证通过 / 已核验身份"（真 OCR / 工商核验未接入）。
     * 邀请步骤即使没有 invite_id 也算完成 —— 邀请码不是准入闸门。
     *
     * @return array<int, array{key:string,label:string,done:bool,current:bool,detail:string,url:?string}>
     */
    private function onboardingSteps(?DeveloperProfile $profile): array
    {
        $layerText = static fn (?string $status): string => match ($status) {
            DeveloperProfile::REALNAME_APPROVED => '已通过',
            DeveloperProfile::REALNAME_REJECTED => '已驳回',
            DeveloperProfile::REALNAME_PENDING  => '待审核',
            default                             => '未提交',
        };

        $realnameDone   = $profile?->isRealnameApproved() ?? false;
        $agreementsDone = $profile?->hasSignedAllAgreements() ?? false;

        // 技能 = 商品，归属锚点是 seller_id。**没有归属就不查** ——
        // `where('seller_id', null)` 会退化成 `is null`，恰好匹配平台自营（本仓库踩过两次）。
        $hasSkill = $profile && $profile->seller_id
            ? DB::table('products')->where('seller_id', $profile->seller_id)->exists()
            : false;

        if (! $profile) {
            $realnameDetail = '平台人工审核（人工确认，非身份核验）';
        } elseif ($realnameDone
            && $profile->company_realname_status === null
            && $profile->operator_realname_status === null) {
            // 存量不追溯：历史主体只有总状态 approved、两层列为 NULL。
            $realnameDetail = '平台人工审核已确认（历史主体未分两层记录）';
        } else {
            $realnameDetail = '公司认证：'.$layerText($profile->company_realname_status)
                .'；操作人认证：'.$layerText($profile->operator_realname_status)
                .'（平台人工审核，两层都通过才算完成）';
        }

        $steps = [
            [
                'key'    => 'invite',
                'label'  => '邀请来源',
                // 邀请码不是准入闸门：有没有码，这一步都算完成，只在 detail 里如实说明。
                'done'   => true,
                'detail' => $profile && $profile->invite_id
                    ? '已记录来源'
                    : '未使用邀请链接（不影响入驻）',
                'url'    => null,
            ],
            [
                'key'    => 'register',
                'label'  => '注册账号',
                // 能进 dashboard 就说明已登录，注册这一步天然完成。
                'done'   => true,
                'detail' => '已登录开发者账号',
                'url'    => null,
            ],
            [
                'key'    => 'realname',
                'label'  => '实名',
                'done'   => $realnameDone,
                'detail' => $realnameDetail,
                'url'    => route('shop.dev.realname'),
            ],
            [
                'key'    => 'agreement',
                'label'  => '签署协议',
                'done'   => $agreementsDone,
                'detail' => $agreementsDone ? '已签署全部必需协议' : '尚未签署全部必需协议',
                'url'    => route('shop.dev.agreements'),
            ],
            [
                'key'    => 'skill',
                'label'  => '创建技能',
                'done'   => $hasSkill,
                'detail' => $hasSkill ? '已创建技能商品' : '还没有创建技能商品',
                'url'    => route('shop.dev.skills.create'),
            ],
        ];

        // 第一个未完成的标 current；全部完成时没有 current。
        $currentAssigned = false;

        foreach ($steps as &$step) {
            $step['current'] = false;

            if (! $currentAssigned && ! $step['done']) {
                $step['current']   = true;
                $currentAssigned   = true;
            }
        }

        unset($step);

        return $steps;
    }
}
