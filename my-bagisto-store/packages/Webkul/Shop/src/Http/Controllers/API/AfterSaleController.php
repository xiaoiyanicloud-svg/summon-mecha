<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * 售后/退款 —— 用户侧(v3.4 步骤 4)。
 */
class AfterSaleController extends APIController
{
    public function __construct(protected AfterSaleService $service) {}

    /**
     * 我对某条授权发起的售后列表。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $rows = AfterSale::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AfterSale $a) => [
                'id'             => $a->id,
                'entitlement_id' => $a->entitlement_id,
                'type'           => $a->type,
                'policy_basis'   => $a->policy_basis,
                'status'         => $a->status,
                'reason_category' => $a->reason_category,
                'created_at'     => optional($a->created_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * 发起售后。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'entitlement_id' => ['required', 'integer'],
            'reason_category' => ['nullable', 'string', 'max:64'],
            'detail'          => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->service->apply(
            $customer->id,
            (int) $payload['entitlement_id'],
            $payload['reason_category'] ?? null,
            $payload['detail'] ?? null,
        );

        return response()->json(
            ['message' => $result['message'], 'data' => $result['data'] ?? null],
            $result['ok'] ? 201 : 422
        );
    }
}
