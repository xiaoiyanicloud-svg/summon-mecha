<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Admin\DataGrids\Catalog\SkillReportDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\Product;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillReport;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\Ops\SkillReportService;

/**
 * 举报处置台（加固计划 §四 A1 的人工端）。
 *
 * 在此之前 `skill_report` 是**只建了表**的：没有用户侧举报入口，也就没有处置入口。
 * 现在两半都通了：`/api/skill-reports`（用户）→ 本台（平台）。
 *
 * 两条与相邻台面一致的原则：
 *   ① **处置不自动改技能状态** —— "这条举报成立"与"这个技能该不该卖"是两个判断，
 *      想下架请去审核台 / 熔断台（那里各有独立的理由与留痕）。自动联动会让
 *      "为什么下架"事后查不清。
 *   ② **驳回举报（认定不成立）必须写理由** —— 那是把风险收回来自己扛，
 *      与「内容安全处置台」要求"放行必须写理由"是同一条原则。
 *
 * ⚠ 与「审核台」的区别别搞混：`SkillReviewController`（admin.catalog.skill-reviews.*）
 *   审的是**技能版本准入**（skill_versions），本台处置的是**用户举报**（skill_report）。
 *   两者都叫 review/report 但对象完全不同。
 */
class SkillReportController extends Controller
{
    public function __construct(
        protected SkillReportService $reports,
        protected OperationLogger $oplog,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SkillReportDataGrid::class)->process();
        }

        return view('admin::catalog.skill-reports.index');
    }

    public function show(int $id)
    {
        $report = SkillReport::find($id);

        if (! $report) {
            session()->flash('error', '举报记录不存在。');

            return redirect()->route('admin.catalog.skill-reports.index');
        }

        // 对象还原：举报技能 → 商品（"技能"就是商品）；举报评价 → 评价行 + 它所属的技能
        $product = null;
        $review = null;

        if ($report->target_type === SkillReport::TARGET_SKILL) {
            $product = Product::find($report->target_id);
        } elseif ($report->target_type === SkillReport::TARGET_REVIEW) {
            $review = DB::table('skill_review')->where('id', $report->target_id)->first();

            if ($review) {
                $product = Product::find($review->skill_id);
            }
        }

        $reporter = $report->reporter_customer_id
            ? Customer::find($report->reporter_customer_id)
            : null;

        // 举报正文的内容安全扫描留痕（由 SkillReportService 写入）—— 给人看出来"机器看到了什么"
        $scan = ContentSecurityRecord::query()
            ->where('target_type', ContentSecurityRecord::TARGET_SKILL_REPORT)
            ->where('target_id', $report->id)
            ->orderByDesc('id')
            ->first();

        return view('admin::catalog.skill-reports.show', compact('report', 'product', 'review', 'reporter', 'scan'));
    }

    /**
     * 处置：受理（举报成立）/ 驳回（举报不成立）。
     *
     * ⚠ 值域里**刻意不含 `pending`**：处置只能向前，不能把已结案的举报再退回待处置。
     *   退回去意味着"未处置"的去重槽位复活，历史结论与去重语义会打架
     *   （见 SkillReport 模型的 open_slot 注释）。
     */
    public function dispose(Request $request, int $id)
    {
        $report = SkillReport::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in([SkillReport::STATUS_ACCEPTED, SkillReport::STATUS_REJECTED])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        // 驳回 = 认定举报不成立 = 把风险收回来自己扛 —— 必须留书面理由
        if ($data['status'] === SkillReport::STATUS_REJECTED && empty($data['note'])) {
            session()->flash('error', '驳回举报必须写明理由 —— 这是事后唯一能解释"为什么没处理"的依据。');

            return redirect()->route('admin.catalog.skill-reports.show', $id);
        }

        $adminId = auth()->guard('admin')->user()->id;

        $this->reports->dispose($id, $adminId, $data['status'], $data['note'] ?? null);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $adminId,
            'skill_report.dispose',
            'skill_report',
            $id,
            [
                'status'      => $data['status'],
                'target_type' => $report->target_type,
                'target_id'   => $report->target_id,
                'note'        => $data['note'] ?? null,
            ],
            $request->ip()
        );

        session()->flash('success', '已记录处置结果。');

        return redirect()->route('admin.catalog.skill-reports.show', $id);
    }
}
