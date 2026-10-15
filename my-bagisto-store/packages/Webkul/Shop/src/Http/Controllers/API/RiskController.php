<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\RiskDisclosureService;

/**
 * 风险告知与留痕（B-5 · 计划 §4.14 L2 人审 / D6 用户分级告知留痕）。
 *
 *   GET  /api/risk/{productId}   取该技能的分档告知（弹窗数据源；游客也可读）
 *   POST /api/risk/acknowledge   落一条勾选留痕（需登录）
 *
 * 为什么 GET 允许游客：风险告知本身就是"买之前先看清"，把它锁在登录后等于
 * 让没登录的人直接冲着"立即开通"去 —— 告知的时机就错了。
 */
class RiskController extends APIController
{
    public function __construct(protected RiskDisclosureService $risk) {}

    /**
     * 分档告知档案。
     */
    public function profile(int $productId): JsonResponse
    {
        $profile = $this->risk->profile($productId);

        if (! $profile) {
            return $this->fail(ApiErrorCode::RISK_PROFILE_NOT_FOUND, '该技能暂无可展示的风险告知信息。', 404);
        }

        return response()->json(['data' => $profile]);
    }

    /**
     * 勾选留痕。
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'product_id'       => ['required', 'integer'],
            'skill_version_id' => ['nullable', 'integer'],
            'entitlement_id'   => ['nullable', 'integer'],
            'channel'          => ['nullable', 'string', 'max:24'],
            'checked'          => ['nullable', 'array'],
            'checked.*'        => ['string', 'max:64'],
        ]);

        // 带了授权就必须是本人的（防给别人的授权留痕 / 防越权探测）
        if (! empty($payload['entitlement_id'])) {
            $owned = SkillEntitlement::query()
                ->where('customer_id', $customer->id)
                ->where('id', $payload['entitlement_id'])
                ->exists();

            if (! $owned) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在或不属于你。', 404);
            }
        }

        $result = $this->risk->acknowledge(
            (int) $customer->id,
            (int) $payload['product_id'],
            $payload['skill_version_id'] ?? null,
            $payload['entitlement_id'] ?? null,
            $payload['channel'] ?? null,
            $payload['checked'] ?? [],
            $request->ip(),
            (string) $request->userAgent(),
        );

        if (! $result['ok']) {
            return $this->fail($result['code'], $result['message'], $result['status'] ?? 200);
        }

        $record = $result['record'];

        return response()->json([
            'message' => $result['message'],
            'data'    => [
                'id'         => $record->id,
                'risk_level' => $record->ack_payload['risk_level'] ?? null,
                'created_at' => optional($record->created_at)->toDateTimeString(),
            ],
        ], 201);
    }
}
