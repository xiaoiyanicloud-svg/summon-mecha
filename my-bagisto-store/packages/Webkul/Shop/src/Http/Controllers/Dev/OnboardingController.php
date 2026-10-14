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
            'dev_type'     => ['required', 'string', 'in:'.$allowed],
            'seller_name'  => ['nullable', 'string', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        // 资质信息先原样留档（realname 审核属平台端，本轮不做，状态停在 pending）
        $certPayload = array_filter([
            'contact_name'  => $payload['contact_name'] ?? null,
            'contact_phone' => $payload['contact_phone'] ?? null,
        ]);

        $this->onboarding->register(auth('developer')->user(), [
            'dev_type'     => $payload['dev_type'],
            'seller_name'  => $payload['seller_name'] ?? null,
            'cert_payload' => $certPayload ?: null,
        ]);

        return redirect()
            ->route('shop.dev.agreements')
            ->with('success', '入驻信息已提交。请签署以下协议后开始发布技能。');
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
            ->with('success', '协议已签署，现在可以创建技能了。');
    }
}
