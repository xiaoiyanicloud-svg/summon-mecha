<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Models\CustomerInvoiceHeader;
use Webkul\Shop\Models\Invoice;
use Webkul\Shop\Services\Billing\InvoiceService;

/**
 * 发票（开票登记与常用抬头簿）—— 用户侧（B-15 升级支持 ToB 增值税普票/专票与收票邮箱）。
 *
 *   GET    /api/invoices               我名下已登记的开票记录
 *   POST   /api/invoices               针对某笔已支付订单申请开票（补/改抬头、税号、专票四要素、接收邮箱）
 *   GET    /api/invoices/headers       我的常用发票抬头簿
 *   POST   /api/invoices/headers       新增常用发票抬头
 *   PUT    /api/invoices/headers/{id}  修改常用发票抬头
 *   DELETE /api/invoices/headers/{id}  删除常用发票抬头
 *
 * ⚠ 诚实口径：当前只到"登记"为止；真实税控开具未接入前，绝不伪造已开具状态或假 PDF。
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

        $orderIds = $rows->flatMap(fn (Invoice $i) => $i->orderIds())->unique()->values()->all();

        $orders = $orderIds
            ? Order::query()->whereIn('id', $orderIds)->pluck('increment_id', 'id')
            : collect();

        return response()->json([
            'data' => $rows->map(fn (Invoice $i) => $this->format($i, $orders))->values(),
        ]);
    }

    /**
     * 申请开票 / 补全抬头与专票信息。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'order_id'        => ['required', 'integer'],
            'type'            => ['required', 'string', 'in:'.Invoice::TYPE_PERSONAL.','.Invoice::TYPE_COMPANY],
            'invoice_kind'    => ['nullable', 'string', 'in:'.Invoice::KIND_ORDINARY.','.Invoice::KIND_SPECIAL],
            'title'           => ['required', 'string', 'max:128'],
            'tax_no'          => ['nullable', 'string', 'max:32', 'required_if:type,'.Invoice::TYPE_COMPANY],
            'receive_email'   => ['nullable', 'email', 'max:191'],
            'receive_phone'   => ['nullable', 'string', 'max:32'],
            'bank_name'       => ['nullable', 'string', 'max:128'],
            'bank_account'    => ['nullable', 'string', 'max:64'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone'   => ['nullable', 'string', 'max:64'],
            'save_header'     => ['nullable', 'boolean'],
        ]);

        $kind = $payload['invoice_kind'] ?? Invoice::KIND_ORDINARY;
        if ($kind === Invoice::KIND_SPECIAL) {
            if ($payload['type'] !== Invoice::TYPE_COMPANY) {
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, '增值税专用发票仅支持企业抬头。', 422);
            }
            foreach ([
                'bank_name'       => '开户银行',
                'bank_account'    => '银行账号',
                'company_address' => '企业注册地址',
                'company_phone'   => '企业注册电话',
            ] as $field => $label) {
                if (empty(trim((string) ($payload[$field] ?? '')))) {
                    return $this->fail(ApiErrorCode::VALIDATION_ERROR, "申请增值税专用发票请完整填写{$label}。", 422);
                }
            }
        }

        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('id', $payload['order_id'])
            ->first();

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于你。', 404);
        }

        if (! in_array($order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '该订单尚未支付，暂不能开票。', 422);
        }

        $invoice = $this->invoices->forOrder((int) $order->id) ?: $this->invoices->issueForOrder($order);

        if (! $invoice) {
            return $this->fail(ApiErrorCode::SERVER_ERROR, '开票登记失败，请稍后重试。');
        }

        if ($invoice->status === Invoice::STATUS_ISSUED) {
            return $this->fail(ApiErrorCode::CONFLICT, '该发票已开具，如需换开或红冲请联系在线客服。', 409);
        }

        $updates = [
            'type'   => $payload['type'],
            'title'  => trim((string) $payload['title']),
            'tax_no' => $payload['type'] === Invoice::TYPE_COMPANY ? trim((string) ($payload['tax_no'] ?? '')) : null,
        ];

        if (Schema::hasColumn('invoice', 'invoice_kind')) {
            $updates['invoice_kind'] = $payload['type'] === Invoice::TYPE_COMPANY ? $kind : Invoice::KIND_ORDINARY;
            $updates['receive_email'] = ! empty($payload['receive_email']) ? trim((string) $payload['receive_email']) : null;
            $updates['receive_phone'] = ! empty($payload['receive_phone']) ? trim((string) $payload['receive_phone']) : null;
            $updates['bank_name'] = $payload['type'] === Invoice::TYPE_COMPANY && ! empty($payload['bank_name']) ? trim((string) $payload['bank_name']) : null;
            $updates['bank_account'] = $payload['type'] === Invoice::TYPE_COMPANY && ! empty($payload['bank_account']) ? trim((string) $payload['bank_account']) : null;
            $updates['company_address'] = $payload['type'] === Invoice::TYPE_COMPANY && ! empty($payload['company_address']) ? trim((string) $payload['company_address']) : null;
            $updates['company_phone'] = $payload['type'] === Invoice::TYPE_COMPANY && ! empty($payload['company_phone']) ? trim((string) $payload['company_phone']) : null;
        }

        $invoice->update($updates);

        // 勾选“同步保存为常用抬头”时自动沉淀到抬头簿，省去企业采购重复输入
        if (! empty($payload['save_header']) && Schema::hasTable('customer_invoice_headers')) {
            CustomerInvoiceHeader::query()->updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'type'        => $updates['type'],
                    'title'       => $updates['title'],
                ],
                [
                    'invoice_kind'    => $updates['invoice_kind'] ?? Invoice::KIND_ORDINARY,
                    'tax_no'          => $updates['tax_no'] ?? null,
                    'receive_email'   => $updates['receive_email'] ?? null,
                    'receive_phone'   => $updates['receive_phone'] ?? null,
                    'bank_name'       => $updates['bank_name'] ?? null,
                    'bank_account'    => $updates['bank_account'] ?? null,
                    'company_address' => $updates['company_address'] ?? null,
                    'company_phone'   => $updates['company_phone'] ?? null,
                ]
            );
        }

        $ordersMap = Order::query()->whereIn('id', $invoice->orderIds())->pluck('increment_id', 'id');

        return response()->json([
            'message' => '开票信息已登记，平台财务将按序处理。',
            'data'    => $this->format($invoice->fresh(), $ordersMap),
        ], 201);
    }

    /**
     * GET /api/invoices/headers —— 常用发票抬头列表。
     */
    public function headers(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        if (! Schema::hasTable('customer_invoice_headers')) {
            return response()->json(['data' => []]);
        }

        $rows = CustomerInvoiceHeader::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (CustomerInvoiceHeader $h) => $this->formatHeader($h))->values(),
        ]);
    }

    /**
     * POST /api/invoices/headers —— 新增常用发票抬头。
     */
    public function storeHeader(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $validated = $this->validateHeader($request);

        if (! empty($validated['is_default'])) {
            CustomerInvoiceHeader::query()->where('customer_id', $customer->id)->update(['is_default' => false]);
        }

        $header = CustomerInvoiceHeader::query()->create(array_merge($validated, [
            'customer_id' => $customer->id,
        ]));

        return response()->json([
            'message' => '发票抬头已保存。',
            'data'    => $this->formatHeader($header),
        ], 201);
    }

    /**
     * PUT /api/invoices/headers/{id} —— 更新常用发票抬头。
     */
    public function updateHeader(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $header = CustomerInvoiceHeader::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $header) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '发票抬头不存在。', 404);
        }

        $validated = $this->validateHeader($request);

        if (! empty($validated['is_default'])) {
            CustomerInvoiceHeader::query()
                ->where('customer_id', $customer->id)
                ->where('id', '!=', $header->id)
                ->update(['is_default' => false]);
        }

        $header->update($validated);

        return response()->json([
            'message' => '发票抬头已更新。',
            'data'    => $this->formatHeader($header->fresh()),
        ]);
    }

    /**
     * DELETE /api/invoices/headers/{id} —— 删除常用发票抬头。
     */
    public function destroyHeader(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $deleted = CustomerInvoiceHeader::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '发票抬头不存在。', 404);
        }

        return response()->json(['message' => '已删除。']);
    }

    protected function validateHeader(Request $request): array
    {
        $payload = $request->validate([
            'type'            => ['required', 'string', 'in:personal,company'],
            'invoice_kind'    => ['nullable', 'string', 'in:ordinary,special'],
            'title'           => ['required', 'string', 'max:128'],
            'tax_no'          => ['nullable', 'string', 'max:32', 'required_if:type,company'],
            'receive_email'   => ['nullable', 'email', 'max:191'],
            'receive_phone'   => ['nullable', 'string', 'max:32'],
            'bank_name'       => ['nullable', 'string', 'max:128'],
            'bank_account'    => ['nullable', 'string', 'max:64'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone'   => ['nullable', 'string', 'max:64'],
            'is_default'      => ['nullable', 'boolean'],
        ]);

        $type = $payload['type'];
        $kind = $type === 'company' ? ($payload['invoice_kind'] ?? 'ordinary') : 'ordinary';

        return [
            'type'            => $type,
            'invoice_kind'    => $kind,
            'title'           => trim((string) $payload['title']),
            'tax_no'          => $type === 'company' ? trim((string) ($payload['tax_no'] ?? '')) : null,
            'receive_email'   => ! empty($payload['receive_email']) ? trim((string) $payload['receive_email']) : null,
            'receive_phone'   => ! empty($payload['receive_phone']) ? trim((string) $payload['receive_phone']) : null,
            'bank_name'       => $type === 'company' && ! empty($payload['bank_name']) ? trim((string) $payload['bank_name']) : null,
            'bank_account'    => $type === 'company' && ! empty($payload['bank_account']) ? trim((string) $payload['bank_account']) : null,
            'company_address' => $type === 'company' && ! empty($payload['company_address']) ? trim((string) $payload['company_address']) : null,
            'company_phone'   => $type === 'company' && ! empty($payload['company_phone']) ? trim((string) $payload['company_phone']) : null,
            'is_default'      => (bool) ($payload['is_default'] ?? false),
        ];
    }

    protected function formatHeader(CustomerInvoiceHeader $h): array
    {
        return [
            'id'              => $h->id,
            'type'            => $h->type,
            'type_label'      => $h->type === 'company' ? '企业' : '个人',
            'invoice_kind'    => $h->invoice_kind ?: 'ordinary',
            'kind_label'      => $h->invoice_kind === 'special' ? '增值税专用发票' : '增值税普通发票',
            'title'           => $h->title,
            'tax_no'          => $h->tax_no,
            'receive_email'   => $h->receive_email,
            'receive_phone'   => $h->receive_phone,
            'bank_name'       => $h->bank_name,
            'bank_account'    => $h->bank_account,
            'company_address' => $h->company_address,
            'company_phone'   => $h->company_phone,
            'is_default'      => (bool) $h->is_default,
        ];
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

        $kind = $invoice->invoice_kind ?: Invoice::KIND_ORDINARY;

        return [
            'id'              => $invoice->id,
            'order_ids'       => $orderIds,
            'order_numbers'   => $numbers,
            'type'            => $invoice->type,
            'type_label'      => $invoice->type === Invoice::TYPE_COMPANY ? '企业' : '个人',
            'invoice_kind'    => $kind,
            'kind_label'      => $kind === Invoice::KIND_SPECIAL ? '增值税专用发票' : '增值税普通发票',
            'title'           => $invoice->title,
            'tax_no'          => $invoice->tax_no,
            'receive_email'   => $invoice->receive_email ?? null,
            'receive_phone'   => $invoice->receive_phone ?? null,
            'bank_name'       => $invoice->bank_name ?? null,
            'bank_account'    => $invoice->bank_account ?? null,
            'company_address' => $invoice->company_address ?? null,
            'company_phone'   => $invoice->company_phone ?? null,
            'amount'          => (float) $invoice->amount,
            'status'          => $invoice->status,
            'status_label'    => $invoice->status === Invoice::STATUS_ISSUED ? '已开具' : '已登记待开具',
            'file_path'       => $invoice->file_path,
            'issued_at'       => optional($invoice->issued_at)->toDateTimeString(),
            'created_at'      => optional($invoice->created_at)->toDateTimeString(),
        ];
    }
}
