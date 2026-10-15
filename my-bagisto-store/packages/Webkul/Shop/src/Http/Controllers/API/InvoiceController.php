<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\Invoice;
use Webkul\Shop\Services\Billing\InvoiceService;

/**
 * 发票（开票登记）—— 用户侧（B-15 · 计划 §4.18）。
 *
 *   GET  /api/invoices   我名下已登记的开票记录
 *   POST /api/invoices   针对某笔已支付订单申请开票（补/改抬头与税号）
 *
 * ⚠ 表名是单数 `invoice`（中国的税务发票/开票），**不是** Bagisto 原生的 `invoices`
 *   （订单商业发票/发货单）—— 两者别混，见 `Models\Invoice` 类注释。
 *
 * ⚠ 当前只到"登记"为止：支付成功时 `InvoiceService` 已自动落一条 `applied`（抬头=订单姓名、
 *   税号空）。本控制器做的是**用户侧补全**：填抬头类型 / 抬头 / 税号。
 *   真正的"开具"（税控 / PDF / 回执）未接入，故 `issued` 记录一律**不允许改** ——
 *   改了会让台账与税务依据对不上。Admin 侧同样刻意没有"标记已开具"的按钮。
 */
class InvoiceController extends APIController
{
    public function __construct(protected InvoiceService $invoices) {}

    /**
     * 我名下的开票登记。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $rows = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        // 订单号：一次性取回涉及到的订单（increment_id），避免逐条查库
        $orderIds = $rows->flatMap(fn (Invoice $i) => $i->orderIds())->unique()->values()->all();

        $orders = $orderIds
            ? Order::query()->whereIn('id', $orderIds)->pluck('increment_id', 'id')
            : collect();

        return response()->json([
            'data' => $rows->map(fn (Invoice $i) => $this->format($i, $orders))->values(),
        ]);
    }

    /**
     * 申请开票 / 补全抬头。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'order_id' => ['required', 'integer'],
            'type'     => ['required', 'string', 'in:'.Invoice::TYPE_PERSONAL.','.Invoice::TYPE_COMPANY],
            'title'    => ['required', 'string', 'max:128'],
            // 企业抬头必须给税号 —— 没有税号的公司票开不出来，别让它静默落空
            'tax_no'   => ['nullable', 'string', 'max:32', 'required_if:type,'.Invoice::TYPE_COMPANY],
        ]);

        // 订单必须是**本人的**
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('id', $payload['order_id'])
            ->first();

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于你。', 404);
        }

        // 只有已支付订单能开票（未付款、已取消的都开不出）
        if (! in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '该订单尚未支付，暂不能开票。', 422);
        }

        // 已登记的直接改；没有的先登记（复用支付回调那条链，保证同一张订单只会有一条登记）
        $invoice = $this->invoices->forOrder((int) $order->id) ?: $this->invoices->issueForOrder($order);

        if (! $invoice) {
            return $this->fail(ApiErrorCode::SERVER_ERROR, '开票登记失败，请稍后重试。');
        }

        // 已开具（税务侧已出票）不允许改 —— 改了台账与票面就不一致了
        if ($invoice->status === Invoice::STATUS_ISSUED) {
            return $this->fail(ApiErrorCode::CONFLICT, '该发票已开具，如需修改请联系客服。', 409);
        }

        $invoice->update([
            'type'   => $payload['type'],
            'title'  => $payload['title'],
            'tax_no' => $payload['type'] === Invoice::TYPE_COMPANY ? ($payload['tax_no'] ?? null) : null,
        ]);

        return response()->json([
            'message' => '开票信息已提交，平台将尽快处理。',
            'data'    => $this->format($invoice->fresh()),
        ], 201);
    }

    /**
     * @param  \Illuminate\Support\Collection|null  $orders  id => increment_id
     */
    protected function format(Invoice $invoice, $orders = null): array
    {
        $orderIds = $invoice->orderIds();

        $numbers = $orderIds;
        if ($orders) {
            $numbers = array_values(array_filter(array_map(fn ($id) => $orders[$id] ?? null, $orderIds)));
        }

        return [
            'id'          => $invoice->id,
            'order_ids'   => $orderIds,
            'order_numbers' => $numbers,
            'type'        => $invoice->type,
            'type_label'  => $invoice->type === Invoice::TYPE_COMPANY ? '企业' : '个人',
            'title'       => $invoice->title,
            // 空税号 = "还没采集过"，不是"用户是个人" —— 如实透出 null，前端显示"未填写"
            'tax_no'      => $invoice->tax_no,
            'amount'      => (float) $invoice->amount,
            'status'      => $invoice->status,
            'status_label' => $invoice->status === Invoice::STATUS_ISSUED ? '已开具' : '已登记待开具',
            'file_path'   => $invoice->file_path,
            'issued_at'   => optional($invoice->issued_at)->toDateTimeString(),
            'created_at'  => optional($invoice->created_at)->toDateTimeString(),
        ];
    }
}
