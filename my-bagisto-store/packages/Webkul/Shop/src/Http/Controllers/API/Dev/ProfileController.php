<?php

namespace Webkul\Shop\Http\Controllers\API\Dev;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperOnboardingService;

/**
 * `/api/dev/profile` —— 开发者主体的 JSON 合同（v3.4 步骤 9）。
 *
 * 这一层是**对外合同**：将来门户换 Vue3 SPA、或小程序 web-view 打开时，
 * 直接消费这些端点，数据层不用动。所以：
 *   - 走 `shop.api.dev.*` 稳定路由名；
 *   - 统一 `{message, data}` 信封；
 *   - 不回泄内部字段。
 *
 * ⚠ 鉴权由 `api.developer` 中间件负责（只认 Bearer token，理由见该中间件注释）。
 */
class ProfileController extends APIController
{
    public function __construct(protected DeveloperOnboardingService $onboarding) {}

    /**
     * 查我的开发者主体。
     *
     * 挂的是轻量中间件（只要求 token）——所以**没有 profile 不是错误**，
     * 而是"还没入驻"这一态：`data` 返回 null，纯前端据此决定跳入驻页还是进门户。
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->attributes->get('developer_profile');

        return response()->json([
            'message' => $profile ? '已入驻。' : '尚未完成开发者入驻。',
            'data'    => $profile ? $this->format($profile) : null,
        ]);
    }

    /**
     * 入驻（幂等：已入驻则返回既有主体）。
     */
    public function store(Request $request): JsonResponse
    {
        $allowed = implode(',', (array) config('developer_portal.dev_types', ['personal']));

        $payload = $request->validate([
            'dev_type'     => ['required', 'string', 'in:'.$allowed],
            'seller_name'  => ['nullable', 'string', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $profile = $this->onboarding->register(auth('developer')->user(), [
            'dev_type'    => $payload['dev_type'],
            'seller_name' => $payload['seller_name'] ?? null,
            'cert_payload' => array_filter([
                'contact_name'  => $payload['contact_name'] ?? null,
                'contact_phone' => $payload['contact_phone'] ?? null,
            ]) ?: null,
        ]);

        return response()->json([
            'message' => '入驻信息已提交。',
            'data'    => $this->format($profile),
        ], 201);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        return $request->attributes->get('developer_profile');
    }

    protected function format(DeveloperProfile $profile): array
    {
        return [
            'id'                   => $profile->id,
            'dev_type'             => $profile->dev_type,
            'realname_status'      => $profile->realname_status,
            'status'               => $profile->status,
            'agreement_signed_at'  => optional($profile->agreement_signed_at)->toDateTimeString(),
            'agreements_ok'        => $profile->hasSignedAllAgreements(),
        ];
    }
}
