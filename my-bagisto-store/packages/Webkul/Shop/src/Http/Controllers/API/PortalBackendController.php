<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Services\Merchant\PortalBackendRegistryService;

class PortalBackendController extends APIController
{
    public function __construct(
        protected PortalBackendRegistryService $registryService,
    ) {}

    /**
     * GET /api/portal/backends
     *
     * 返回统一后台选择入口列表（配置 + 数据库注册表合并，按当前用户动态计算权限与禁用原因）。
     */
    public function index(Request $request): JsonResponse
    {
        $customer = auth('sanctum')->user()
            ?? auth('customer')->user()
            ?? auth('merchant')->user()
            ?? auth('developer')->user();

        $backends = $this->registryService->listForCustomer($customer);

        return $this->ok($backends, '', [
            'meta' => [
                'authenticated' => (bool) $customer,
                'user_id'       => $customer ? (int) $customer->id : null,
            ],
        ]);
    }

    /**
     * POST /api/portal/backends/track
     *
     * 记录后台选择入口点击埋点（落 operation_log 不可篡改审计表）。
     */
    public function track(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'backend_code' => ['required', 'string', 'max:64'],
            'source'       => ['nullable', 'string', 'max:64'],
            'timestamp'    => ['nullable'],
        ]);

        $customer = auth('sanctum')->user()
            ?? auth('customer')->user()
            ?? auth('merchant')->user()
            ?? auth('developer')->user();

        $result = $this->registryService->trackClick(
            $customer,
            (string) $validated['backend_code'],
            (string) ($validated['source'] ?? 'portal_selector'),
            isset($validated['timestamp']) ? (string) $validated['timestamp'] : null,
            $request
        );

        if (! ($result['recorded'] ?? false)) {
            return $this->fail(
                ApiErrorCode::NOT_FOUND,
                '未找到对应的后台入口配置。',
                404
            );
        }

        return $this->ok($result, '已记录后台入口访问埋点。');
    }
}
