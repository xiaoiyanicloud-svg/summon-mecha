<?php

namespace Webkul\Shop\Services\SkillAudit;

use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\ContentScanRecorder;
use Webkul\Shop\Services\SkillPackage\ManifestValidator;

/**
 * 审核服务 —— 三层准入 —— v3.4 步骤 6(计划 §4.8/§4.21)。
 *
 *   L0 机审(recordLayer)   : 清单格式 + 内容关键字 + 权限比对
 *   L1 仿真(L0 通过后)      : 接仿真跑分结果,跑不起来 → 拒
 *   L2 人审                 : 功能完整性 / 描述一致 / 侵权
 *
 * 每层写一条 audit_record,不过 → 记 RejectionReason。
 * 这是"垃圾不过审"的骨架;仿真跑分本体(沙盒/仿真器)在后续接入。
 */
class AuditService
{
    public function __construct(
        protected ManifestValidator $manifestValidator,
        protected ContentSafetyManager $contentSafety,
        protected ContentScanRecorder $scanRecorder,
    ) {}

    /**
     * 提交版本审核 → 跑 L0 机审,返回是否进入下一层。
     *
     * @return array{layer: int, result: int, reasons: array}
     */
    public function submitLayer0(int $skillId, int $version, array $manifest): array
    {
        $reasons = [];
        $notes = [];

        // 1) 清单格式
        $m = $this->manifestValidator->validate($manifest);
        if (! $m['valid']) {
            $reasons[] = RejectionReason::TECH_FORMAT.':'.implode(' / ', $m['errors']);
        }

        // 2) 内容安全 —— 走 ContentSafety 层（v3.4 步骤 6）。
        //    此前这里是空数组占位；现在换成真实的扫描器（本地词表可换 msgSecCheck）。
        $screen = $this->contentSafety->scanner()->scan($this->flattenText($manifest));

        // 留痕：扫描明细进 content_security_record，供事后追溯与人工处置
        // （此前只判不留 —— 事后查不到"当时扫出过什么"）
        $this->scanRecorder->record(
            ContentSecurityRecord::TARGET_SKILL_META,
            $version,
            $screen,
            'text',
            ['skill_id' => $skillId, 'driver' => $this->contentSafety->driver()]
        );

        if ($screen->degraded) {
            // ⚠「没查」≠「合规」：扫描器没跑起来时按配置处理（默认拦截，理由码 CONTENT_UNSCREENED）
            $notes[] = '内容扫描未运行：'.$screen->message;

            if ($this->contentSafety->failsClosed()) {
                $reasons[] = RejectionReason::CONTENT_UNSCREENED.':'.$screen->message;
            }
        } elseif ($screen->isViolation()) {
            $reasons[] = RejectionReason::CONTENT_VIOLATION
                .':命中 ['.implode('、', $screen->hits).']（驱动 '.$screen->provider.'）';
        }

        // 3) 权限越界(声明与实际调用的比对需要扫描 app/ 内容;此处先做"声明合法性",
        //    真正的调用比对在包解析后由 PackageInspector 提供,见 TODO)
        //    —— 保持职责单一:本层只做能做的,不虚构未实现的检查。

        $result = empty($reasons) ? AuditRecord::RESULT_PASS : AuditRecord::RESULT_REJECT;

        $this->record($skillId, $version, AuditRecord::LAYER_MACHINE, $result, $reasons);

        return ['layer' => AuditRecord::LAYER_MACHINE, 'result' => $result, 'reasons' => $reasons, 'notes' => $notes];
    }

    /**
     * L1 仿真预跑 —— 接收仿真器结果(由调用方/任务产出)。
     *
     * @param  array  $sim  {score: float|null, ran: bool, message: string}
     */
    public function recordLayer1(int $skillId, int $version, array $sim): array
    {
        $reasons = [];

        if (($sim['ran'] ?? false) !== true) {
            $reasons[] = RejectionReason::TECH_UNRUNNABLE.':'.($sim['message'] ?? '仿真未跑通');
        }

        $result = empty($reasons) ? AuditRecord::RESULT_PASS : AuditRecord::RESULT_REJECT;
        $this->record($skillId, $version, AuditRecord::LAYER_SIMULATION, $result, $reasons, $sim);

        return ['layer' => AuditRecord::LAYER_SIMULATION, 'result' => $result, 'reasons' => $reasons];
    }

    /**
     * L2 人审 —— 审核员定论。
     */
    public function recordLayer2(
        int $skillId,
        int $version,
        int $result,
        ?int $auditorId = null,
        ?string $reasonCategory = null,
        ?string $detail = null,
        ?string $suggestion = null,
    ): array {
        $this->record($skillId, $version, AuditRecord::LAYER_HUMAN, $result, [], null, [
            'auditor_id'      => $auditorId,
            'reason_category' => $reasonCategory,
            'reason_detail'   => $detail,
            'suggestion'      => $suggestion,
        ]);

        return ['layer' => AuditRecord::LAYER_HUMAN, 'result' => $result];
    }

    /**
     * 版本整体是否通过（**按策略闸门**，见 ReviewGate）。
     *
     * ⚠ 原先这里写死"三层全过"。现在委托给 `ReviewGate`，因为闸门必须是**策略**
     *   （`config/skill_audit.php`）—— L1 仿真层依赖尚未接入的外部仿真器，
     *   写死会让任何版本都过不了。"过审"从此只有一处定义。
     */
    public function isFullyApproved(int $skillId, int $version): bool
    {
        return app(ReviewGate::class)->evaluate($skillId, $version)['ok'];
    }

    /**
     * 写一条审核记录。
     */
    protected function record(
        int $skillId,
        int $version,
        int $layer,
        int $result,
        array $reasons = [],
        ?array $extraPayload = null,
        array $overrides = [],
    ): AuditRecord {
        return AuditRecord::create(array_merge([
            'skill_id'      => $skillId,
            'version'       => $version,
            'audit_type'    => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
            'layer'         => $layer,
            'result'        => $result,
            'reason_detail' => $reasons ? implode("\n", $reasons) : null,
            'submitted_at'  => now(),
            'audited_at'    => $result === AuditRecord::RESULT_PENDING ? null : now(),
        ], $overrides));
    }

    /**
     * 把 manifest 里的可读文本拍平，做内容扫描。
     *
     * ⚠ 递归收集**全部字符串值**，而不是只挑 name/description ——
     *   否则开发者把违规文案塞进别的字段就绕过了 L0（那是个真实的绕过口子）。
     *   代价是也会扫到 version / schema 这类无害字段，对词表匹配没有影响。
     */
    protected function flattenText(array $manifest): string
    {
        return implode("\n", $this->collectStrings($manifest));
    }

    /**
     * @return string[]
     */
    protected function collectStrings(mixed $value, int $depth = 0): array
    {
        // 防御：manifest 理论上不会有深层嵌套，但别让畸形输入把栈打爆
        if ($depth > 12) {
            return [];
        }

        if (is_string($value)) {
            return [$value];
        }

        if (is_scalar($value)) {
            return [(string) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $out = array_merge($out, $this->collectStrings($item, $depth + 1));
        }

        return $out;
    }
}
