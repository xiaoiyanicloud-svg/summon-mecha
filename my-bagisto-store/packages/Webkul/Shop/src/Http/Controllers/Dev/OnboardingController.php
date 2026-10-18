<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Services\Developer\DeveloperOnboardingService;

/**
 * 开发者门户 · 入驻与协议签署 —— v3.4 步骤 9。
 *
 * 两条路径分工：
 *   - 入驻表单：`developer.session`（只要登录）—— 用户此时还没有 profile，
 *     若用 `developer` 中间件会与入驻页互相弹跳成死循环；
 *   - 协议签署：`developer`（要求已有 profile）。
 *
 * S-11d：`/dev/join` 把邀请码写进 session（S-11c），这里在**建 profile 的同一刻**
 *   读取并领取。邀请码不是准入闸门 —— 码失效只给中性提示，**仍完成入驻**。
 */
class OnboardingController extends Controller
{
    public function __construct(protected DeveloperOnboardingService $onboarding) {}

    public function form(Request $request)
    {
        // 已入驻的直接放行到协议页/技能页，别让他重复入驻
        $profile = $request->attributes->get('developer_profile');

        if ($profile) {
            return redirect()->route('shop.dev.skills.index');
        }

        return view('shop::dev.onboarding.form', [
            'devTypes' => (array) config('developer_portal.dev_types', []),
        ]);
    }

    public function store(Request $request)
    {
        $allowed = implode(',', (array) config('developer_portal.dev_types', ['personal']));

        $payload = $request->validate([
            'dev_type'      => ['required', 'string', 'in:'.$allowed],
            'seller_name'   => ['nullable', 'string', 'max:191'],
            'contact_name'  => ['nullable', 'string', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        // 资质信息先原样留档（realname 审核属平台端，本轮不做，状态停在 pending）
        $certPayload = array_filter([
            'contact_name'  => $payload['contact_name'] ?? null,
            'contact_phone' => $payload['contact_phone'] ?? null,
        ]);

        // S-11d：/dev/join 写进 session 的码，在**建 profile 的这一刻**才领取
        $code = $request->session()->get(InviteController::SESSION_KEY);
        $code = is_string($code) ? trim($code) : null;
        $code = $code === '' ? null : $code;

        $result = $this->onboarding->registerWithInvite(auth('developer')->user(), [
            'dev_type'     => $payload['dev_type'],
            'seller_name'  => $payload['seller_name'] ?? null,
            'cert_payload' => $certPayload ?: null,
        ], $code);

        $state = $result['invite']['state'];

        if ($state === 'claimed') {
            $request->session()->forget(InviteController::SESSION_KEY);
            $message = '入驻信息已提交，已记录邀请来源。请签署以下协议后开始发布技能。';
        } elseif ($code !== null) {
            // 码失效 / 被抢 / 本人已领 —— **不阻断入驻**，中性提示，不报成失败
            $message = in_array($state, ['already_claimed', 'already_registered'], true)
                ? '入驻信息已提交，邀请来源未重复记录。请签署以下协议后开始发布技能。'
                : '邀请码已失效，已按自助通道完成入驻；你仍可正常完成实名与协议。';
        } else {
            $message = '入驻信息已提交。请签署以下协议后开始发布技能。';
        }

        return redirect()->route('shop.dev.agreements')->with('success', $message);
    }

    public function agreements(Request $request)
    {
        return view('shop::dev.onboarding.agreements', [
            'profile'    => $request->attributes->get('developer_profile'),
            'docs'       => (array) config('developer_portal.agreements', []),
            'signedDocs' => $request->attributes->get('developer_profile')
                ?->agreements()->pluck('doc_type')->all() ?? [],
        ]);
    }

    public function signAgreements(Request $request)
    {
        $profile = $request->attributes->get('developer_profile');

        $docs = (array) config('developer_portal.agreements', []);

        $accepted = [];

        foreach (array_keys($docs) as $docType) {
            $accepted[$docType] = $request->boolean('accept_'.$docType);
        }

        $result = $this->onboarding->signAgreements($profile, $accepted, $request);

        if (! $result['ok']) {
            return back()->withErrors(['agreements' => $result['message']]);
        }

        return redirect()
            ->route('shop.dev.skills.index')
            ->with('success', '协议已签署。请在总览查看实名进度，满足条件后即可创建技能。');
    }
}
