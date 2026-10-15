<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Webkul\Admin\DataGrids\Catalog\SkillReviewModerationDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\Product;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillReview;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\Ops\SkillReviewService;

/**
 * 评价处置台（加固计划 §四 A1 的人工端）。
 *
 * ⚠ **别和「审核台」搞混**：`SkillReviewController`（admin.catalog.skill-reviews.*）
 *   审的是**技能版本准入**（skill_versions）；本台管的是**用户写的技能评价**
 *   （skill_review）—— 英文都带 review，对象完全不同。
 *
 * 一条与相邻台面一致的原则：
 *   **恢复显示（推翻机器拦截）必须写理由**，而隐藏不强制。
 *   放行是把风险收回来自己扛，留痕是唯一能事后解释的凭据
 *   —— 与「内容安全处置台」的"放行必须写理由"、
 *   「举报处置台」的"驳回必须写理由"同一条原则。
 *
 * 处置**不自动联动**技能上架状态或评分聚合：一轮只做"这条内容能不能被看见"这一个判断。
 */
class SkillReviewModerationController extends Controller
{
    public function __construct(
        protected SkillReviewService $reviews,
        protected OperationLogger $oplog,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SkillReviewModerationDataGrid::class)->process();
        }

        return view('admin::catalog.skill-review-moderation.index');
    }

    public function show(int $id)
    {
        $review = SkillReview::find($id);

        if (! $review) {
            session()->flash('error', '评价不存在。');

            return redirect()->route('admin.catalog.skill-review-moderation.index');
        }

        $product = Product::find($review->skill_id);

        // 匿名评价：后台也不把真名端出来（用户选了匿名，后台顺手透出去就等于这个选项是骗人的）。
        // 需要追溯时走 operation_log / 数据层，不在这个界面里做。
        $author = (! $review->is_anonymous && $review->customer_id)
            ? Customer::find($review->customer_id)
            : null;

        // 机器扫描明细（SkillReviewService 写入，target_type=review）
        $scan = ContentSecurityRecord::query()
            ->where('target_type', ContentSecurityRecord::TARGET_REVIEW)
            ->where('target_id', $review->id)
            ->orderByDesc('id')
            ->first();

        return view('admin::catalog.skill-review-moderation.show', compact('review', 'product', 'author', 'scan'));
    }

    /**
     * 处置：可见 / 不可见。
     *
     * ⚠ 值域不含 `pending`：处置只能向前 —— 退回"待人工看"会让"机审没跑成"
     *   与"人已经判过"混在一起，事后分不清谁判的（见 SkillReviewService::moderate）。
     */
    public function moderate(Request $request, int $id)
    {
        $review = SkillReview::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in([SkillReview::STATUS_VISIBLE, SkillReview::STATUS_HIDDEN])],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        // 恢复显示 = 推翻机器拦截 = 把风险收回来自己扛 —— 必须留书面理由
        if ($data['status'] === SkillReview::STATUS_VISIBLE && empty($data['note'])) {
            session()->flash('error', '恢复显示（推翻机器拦截）必须写明理由 —— 这是事后唯一能解释的依据。');

            return redirect()->route('admin.catalog.skill-review-moderation.show', $id);
        }

        $this->reviews->moderate($id, $data['status'], $data['note'] ?? null);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'skill_review.moderate',
            'skill_review',
            $id,
            [
                'status'   => $data['status'],
                'skill_id' => $review->skill_id,
                'note'     => $data['note'] ?? null,
            ],
            $request->ip()
        );

        session()->flash('success', '已记录处置结果。');

        return redirect()->route('admin.catalog.skill-review-moderation.show', $id);
    }
}
