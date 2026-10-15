<?php

namespace Webkul\Admin\Http\Controllers\Ops;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Ops\AfterSaleDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * 售后/退款审批台（B-1）。
 *
 * 补的是整条链缺的那一头：申请端早已通（`POST /api/after-sale`），
 * 而 `AfterSaleService::approve()` 此前**全仓零调用方** ——
 * 用户点完退款就永远停在"已受理"，没人能批。本台是平台端的审批入口。
 *
 * 本台**只暴露** `approve()` / `reject()` 两个既有动作，不新造规则：
 *   · 批准 = 冻结授权 + 走退款（金额与政策判定全在 `AfterSaleService::approve()` 内，
 *     含 D10 政策、行锁防重、实付金额、M8 资金留痕）；
 *   · 驳回 = 置 rejected + 可选理由。
 *
 * ⚠ 部分/整单退款边界（A-12）**尚未裁决** —— 本台**不自创边界**，按 `approve()` 现有
 *   实现暴露（金额取该订单条目实付，整条）。边界定下来前不要在这里加"部分退款"输入。
 */
class AfterSaleController extends Controller
{
    public function __construct(protected AfterSaleService $afterSales) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(AfterSaleDataGrid::class)->process();
        }

        $pending = AfterSale::query()
            ->whereIn('status', [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING])
            ->count();

        $total = AfterSale::query()->count();

        return view('admin::ops.after-sales.index', compact('pending', 'total'));
    }

    public function show(int $id)
    {
        $afterSale = AfterSale::find($id);

        if (! $afterSale) {
            session()->flash('error', '售后单不存在。');

            return redirect()->route('admin.after-sales.index');
        }

        $entitlement = $afterSale->entitlement_id
            ? SkillEntitlement::find($afterSale->entitlement_id)
            : null;

        // 已发起的退款单（若有）：批准后台账里就有这条，退款金额以它为准
        $refund = RefundRequest::query()
            ->where('after_sale_id', $afterSale->id)
            ->orderByDesc('id')
            ->first();

        return view('admin::ops.after-sales.show', compact('afterSale', 'entitlement', 'refund'));
    }

    /**
     * 批准 —— 冻结授权 + 走退款。
     *
     * ⚠ 这是**资金动作**：`approve()` 会调 PaymentGateway::refund（资质就绪前是 mock）。
     *   前端表单带二次确认；服务端的状态守卫/行锁/金额校验全在 Service 内。
     */
    public function approve(int $id)
    {
        $result = $this->afterSales->approve($id, auth()->guard('admin')->user()->id);

        session()->flash(
            ($result['ok'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );

        return redirect()->route('admin.after-sales.show', $id);
    }

    /**
     * 驳回。
     */
    public function reject(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->afterSales->reject(
            $id,
            auth()->guard('admin')->user()->id,
            $data['reason'] ?? null,
        );

        session()->flash(
            ($result['ok'] ?? false) ? 'success' : 'error',
            (string) ($result['message'] ?? '')
        );

        return redirect()->route('admin.after-sales.show', $id);
    }
}
