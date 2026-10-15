<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\SkillReport;
use Webkul\Shop\Services\Ops\SkillReportService;

/**
 * 举报 —— 用户侧入口（加固计划 §四 A1）。
 *
 * 为什么这条通道的优先级高于评价：平台对外讲的是"先审后发 + 内容安全"，
 * 那是**上线前**的闸门；技能一旦下发、在真实机器上跑起来，
 * 用户能按的最后一个刹车就是这个举报入口。它此前不存在（表建了、零代码）。
 *
 * 三个刻意的口径，与相邻接口**不同**，不要按惯性"统一"掉：
 *   · **不要求持有该技能**：举报对象恰恰不该是我买的东西（详见模型注释）。
 *   · **不要求写 detail**：能指出"哪里不对"已经够立案，强制写正文会劝退真实举报。
 *   · **命中违禁词不拦**：举报人描述违规往往必须引述违规内容，拦了等于关掉通道 ——
 *     改为照收 + 扫描留痕，由「内容安全处置台」人工判断（见 SkillReportService 注释）。
 *
 * 滥用防护（三件套）：去重（同一对象未处置期间只受理一条，库级唯一键兜底）
 * + 限流（`throttle:report`，比其他写入口更紧，见 ShopServiceProvider）
 * + 正文长度上限（2000 字）。
 */
class SkillReportController extends APIController
{
    public function __construct(protected SkillReportService $reports) {}

    /**
     * 提交举报。
     *
     * 201 = 新建受理；200 + `duplicate:true` = 该对象已有一条未处置的举报，本次未新建。
     * （沿用 A2 工单幂等的表达：回放不是创建，所以不是 201。）
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $data = $request->validate([
            'target_type'     => ['required', 'string', Rule::in(SkillReport::TARGET_TYPES)],
            'target_id'       => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', 'string', Rule::in(array_keys(SkillReport::REASON_LABELS))],
            'detail'          => ['nullable', 'string', 'max:2000'],
        ]);

        $targetType = $data['target_type'];
        $targetId = (int) $data['target_id'];

        // 对象必须真实存在 —— 否则后台队列会被"查无此物"的举报灌满
        if (! $this->reports->targetExists($targetType, $targetId)) {
            return $this->reject(
                ApiErrorCode::REPORT_TARGET_NOT_FOUND,
                '举报对象不存在，请刷新后重试。'
            );
        }

        ['report' => $report, 'duplicate' => $duplicate] = $this->reports->submit(
            $customer->id,
            $targetType,
            $targetId,
            $data['reason_category'],
            $data['detail'] ?? null,
        );

        return $this->ok(
            [
                'id'          => $report->id,
                'target_type' => $report->target_type,
                'target_id'   => $report->target_id,
                'status'      => $report->status,
                'created_at'  => optional($report->created_at)->toDateTimeString(),
            ],
            $duplicate
                ? '你已举报过该内容，我们正在核实，无需重复提交。'
                : '举报已受理，我们会尽快核实。',
            $duplicate ? ['duplicate' => true] : [],
        )->setStatusCode($duplicate ? 200 : 201);
    }
}
