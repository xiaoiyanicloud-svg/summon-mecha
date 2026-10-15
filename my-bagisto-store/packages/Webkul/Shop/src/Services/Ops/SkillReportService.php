<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillReport;
use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\ContentScanRecorder;
use Webkul\Shop\Services\ContentSafety\ScanResult;

/**
 * 举报受理 —— 加固计划 §四 A1。
 *
 * 三件事在这个类里合起来才有意义，缺一个功能就是残的：
 *   1. **去重**：同一举报人对同一对象，在上一单没处置完之前只受理一条
 *      （库级唯一键 `uk_skill_report_open` 兜底，不是 check-then-act）；
 *   2. **内容安全扫描 + 留痕**：走项目既有的 ContentSafety 链路（`ScanResult` +
 *      `content_security_record`），与技能提审同一套，不另起炉灶；
 *   3. **处置释放槽位**：处置后 `open_slot` 置 NULL —— 对象没改好就该能再被举报。
 *
 * ── 一个刻意的**不对称**：举报命中违禁词**不拦**（评价那边是拦的）──
 *   举报人要描述违规，往往必须引述违规内容（"这个技能在宣传赌博"本身命中"赌博"）。
 *   若照评价的规矩命中即拒，最该被举报的内容反而最报不上来 —— 那等于把合规通道
 *   关掉了。正确处理是**照收 + 留痕给人看**：扫描结果落 `content_security_record`
 *   （result=block、disposition=pending），在「内容安全处置台」里等着人判断。
 *   代价是举报正文本身可以夹带违规文本进后台，故它有长度上限 + 限流 + 去重三件套。
 */
class SkillReportService
{
    public function __construct(
        protected ContentSafetyManager $contentSafety,
        protected ContentScanRecorder $scanRecorder,
    ) {}

    /**
     * 受理一条举报。
     *
     * @param  string  $reasonCategory  取值见 {@see SkillReport::REASON_LABELS}
     * @return array{report: SkillReport, duplicate: bool}
     *                                               duplicate=true 表示"同一对象已有一条未处置的举报，
     *                                               本次未新建，返回的是那条"
     */
    public function submit(
        int $customerId,
        string $targetType,
        int $targetId,
        string $reasonCategory,
        ?string $detail,
    ): array {
        // 快路径：已有未处置的同一对象举报 → 直接返回它（不新建、不重复进后台队列）
        $existing = $this->openReportFor($customerId, $targetType, $targetId);

        if ($existing) {
            return ['report' => $existing, 'duplicate' => true];
        }

        // 扫描在**落库前**跑：这样"扫了什么"能跟着举报单一起落痕（见 recordScan）
        $scan = $this->contentSafety->scanner()->scan($this->scanText($reasonCategory, $detail));

        $attempts = 0;

        while (true) {
            try {
                $report = SkillReport::create([
                    'reporter_customer_id' => $customerId,
                    'target_type'          => $targetType,
                    'target_id'            => $targetId,
                    'reason_category'      => $reasonCategory,
                    'detail'               => $detail,
                    'status'               => SkillReport::STATUS_PENDING,
                    'open_slot'            => SkillReport::OPEN_SLOT,
                ]);

                $this->recordScan($report, $scan);

                return ['report' => $report, 'duplicate' => false];
            } catch (QueryException $e) {
                // 唯一键冲突 = 并发的同一(举报人,对象)请求刚落了单 → 取回它按"已受理"返回。
                // 与 A2 工单幂等同一套路：唯一键是兜底，不是期望路径。
                if (! $this->isDuplicateKey($e) || ++$attempts >= 3) {
                    throw $e;
                }

                $existing = $this->openReportFor($customerId, $targetType, $targetId);

                if ($existing) {
                    return ['report' => $existing, 'duplicate' => true];
                }

                // 不是去重键撞的（理论上只剩别的唯一约束）→ 再试一轮
            }
        }
    }

    /**
     * 管理端处置。
     *
     * ⚠ 处置**不自动改技能的上架状态** —— 与「内容安全处置台」同一立场：
     *   "这条举报成立"和"这个技能该不该卖"是两个判断，自动联动会让"为什么下架"
     *   事后查不清。要下架请去审核台 / 熔断台（那里有独立的理由与留痕）。
     *
     * ⚠ `open_slot` 必须随状态释放（见模型注释）：不释放的话，被驳回一次之后
     *   这个对象就再没人能举报了。
     *
     * @param  string  $status  取值 {@see SkillReport::STATUS_LABELS}
     */
    public function dispose(int $reportId, int $adminId, string $status, ?string $note): SkillReport
    {
        $report = SkillReport::findOrFail($reportId);

        $report->update([
            'status'        => $status,
            'open_slot'     => SkillReport::openSlotFor($status),
            'handler_id'    => $adminId,
            'handled_at'    => now(),
            'handling_note' => $note,
        ]);

        return $report;
    }

    /**
     * 举报对象是否存在 —— 举报必须落在真实对象上，否则后台全是查无此物的噪音。
     *
     * 注意：**不校验归属**（见模型注释：要求持有才能举报 = 把最该被举报的技能保护起来）。
     */
    public function targetExists(string $targetType, int $targetId): bool
    {
        return match ($targetType) {
            SkillReport::TARGET_SKILL  => Product::query()->whereKey($targetId)->exists(),
            SkillReport::TARGET_REVIEW => DB::table('skill_review')->where('id', $targetId)->exists(),
            default                    => false,
        };
    }

    protected function openReportFor(int $customerId, string $targetType, int $targetId): ?SkillReport
    {
        return SkillReport::query()
            ->where('reporter_customer_id', $customerId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->open()
            ->first();
    }

    /**
     * 留痕到 `content_security_record`（驱动名一并记下，便于追"当时是谁扫的"）。
     *
     * 留痕失败不影响举报落库（见 ContentScanRecorder 注释），但绝不静默。
     */
    protected function recordScan(SkillReport $report, ScanResult $scan): void
    {
        $this->scanRecorder->record(
            ContentSecurityRecord::TARGET_SKILL_REPORT,
            $report->id,
            $scan,
            'text',
            [
                'reporter_id'  => $report->reporter_customer_id,
                'target_type'  => $report->target_type,
                'target_id'    => $report->target_id,
                'driver'       => $this->contentSafety->driver(),
            ]
        );
    }

    /**
     * 举报正文的扫描文本 —— 理由类别也要扫（它是用户选的枚举，但拼进文本无副作用）。
     */
    protected function scanText(string $reasonCategory, ?string $detail): string
    {
        return trim($reasonCategory."\n".(string) $detail);
    }

    /**
     * 唯一键冲突（MySQL 1062 / SQLite 唯一约束）—— 与 `TicketController::isDuplicateKey` 同源。
     */
    protected function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
