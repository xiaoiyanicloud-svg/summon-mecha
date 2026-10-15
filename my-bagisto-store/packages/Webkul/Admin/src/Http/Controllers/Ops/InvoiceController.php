<?php

namespace Webkul\Admin\Http\Controllers\Ops;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Ops\InvoiceDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Invoice;

/**
 * 开票登记台账（M9）。
 *
 * 支付成功时由 `InvoiceService::issueForOrder()` 自动落一条 `applied` 记录，
 * 本页是运营看这些记录的地方。
 *
 * ⚠ 本页**只读**：不提供"标记为已开具"。真实开具（税控/开票服务商/PDF）未接入，
 *   手工把状态点成"已开具"只会造出没有税务依据的记录（见 `Invoice` / `InvoiceDataGrid` 注释）。
 */
class InvoiceController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(InvoiceDataGrid::class)->process();
        }

        $registered = Invoice::query()->count();

        $applied = Invoice::query()->where('status', Invoice::STATUS_APPLIED)->count();

        $amount = (float) Invoice::query()->sum('amount');

        return view('admin::ops.invoices.index', [
            'registered' => $registered,
            'applied'    => $applied,
            'amount'     => $amount,
            'gap'        => $this->paidOrdersWithoutInvoice(),
        ]);
    }

    /**
     * 已支付、但台账里没有开票登记的订单数 —— **这是本页存在的真正理由**。
     *
     * 只显示"登记了 N 条"是不够的：那个数字涨了也不说明有没有漏。
     * 有对照的另一侧才知道漏没漏：已支付订单（processing/completed 且非游客单）
     * 里有几笔至今没有登记。
     *
     * ⚠ 这个差值**当前不会是 0**：M9 之前的已支付订单本来就没有登记，
     *   需要人工补开。页面照实显示，**不粉饰成 0**（本项目对"没有数据 vs 数据是零"的立场
     *   见 `OpsMetricsService::notConnected()`）。
     *
     * 用 PHP 做差集而不是 `JSON_CONTAINS` 关联子查询：两侧数据量同阶
     * （都 ≈ 已支付订单数），而且不必踩 MySQL/MariaDB 对 JSON 候选值解析的差异。
     *
     * 游客单（`customer_id` 为空）不在比对范围内 —— 它**开不出票**，不是"漏登记"
     * （见 `InvoiceService::issueForOrder()` 的返回 null 分支）。
     */
    protected function paidOrdersWithoutInvoice(): int
    {
        $paidOrderIds = DB::table('orders')
            ->whereIn('status', ['processing', 'completed'])
            ->whereNotNull('customer_id')
            ->pluck('id')
            ->all();

        if ($paidOrderIds === []) {
            return 0;
        }

        // 刻意走 DB::table 而不是 Invoice 模型:这里要的是**列里的原始 json 文本**,
        // 用模型 pluck 会经 `order_ids => 'array'` 转换,拿到的类型取决于 Eloquent 版本行为
        // (有转换是数组、没有是字符串) —— 这类"取决于版本"的隐式行为不值得赌。
        $registeredIds = DB::table('invoice')
            ->pluck('order_ids')
            ->flatMap(fn ($ids) => array_map('intval', json_decode((string) $ids, true) ?: []))
            ->unique()
            ->all();

        return count(array_diff($paidOrderIds, $registeredIds));
    }
}
