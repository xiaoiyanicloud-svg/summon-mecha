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
        if (! $request->filled('backend_code') && $request->filled('key')) {
            $request->merge(['backend_code' => $request->input('key')]);
        }

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

    /**
     * POST /api/portal/handoff-ticket —— 签发 45 秒单次免登票据（App/H5 已登录客户免二次输密码直达 /merchant 或 /dev）。
     */
    public function handoffTicket(Request $request): JsonResponse
    {
        if (! $request->filled('backend_code') && $request->filled('key')) {
            $request->merge(['backend_code' => $request->input('key')]);
        }

        $validated = $request->validate([
            'backend_code'  => ['required', 'string', 'max:64'],
            'redirect_path' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = auth('sanctum')->user() ?? auth('customer')->user();
        if (! $customer) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录账号。', 401);
        }

        $res = $this->registryService->issueHandoffTicket(
            $customer,
            (string) $validated['backend_code'],
            $validated['redirect_path'] ?? null,
            $request
        );

        if (! ($res['ok'] ?? false)) {
            $status = ($res['code'] ?? '') === 'NOT_FOUND' ? 404 : (($res['code'] ?? '') === 'SERVICE_UNAVAILABLE' ? 503 : 403);
            $errCode = match ($res['code'] ?? '') {
                'NOT_FOUND'           => ApiErrorCode::NOT_FOUND,
                'SERVICE_UNAVAILABLE' => ApiErrorCode::SERVICE_UNAVAILABLE,
                default               => ApiErrorCode::FORBIDDEN,
            };

            return $this->fail($errCode, (string) ($res['message'] ?? '无权访问目标工作台。'), $status);
        }

        return $this->ok([
            'ticket'     => $res['ticket'],
            'expires_in' => $res['expires_in'],
            'sso_url'    => $res['sso_url'],
        ], '免登票据已签发。');
    }

    /**
     * GET /api/portal/summary —— 小程序 / 移动端原生轻量工作台看板聚合。
     */
    public function summary(): JsonResponse
    {
        $customer = auth('sanctum')->user() ?? auth('customer')->user();
        if (! $customer) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录账号。', 401);
        }

        return $this->ok($this->registryService->mobileWorkbenchSummary($customer));
    }
}
