<?php

namespace Webkul\Shop\Services\SkillAudit;

use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\ContentScanRecorder;
use Webkul\Shop\Services\LifecycleNotifier;
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
        protected SkillVersionState $states,
        protected LifecycleNotifier $notifier,
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

        // 1.5) schema 落库 —— 补齐**所有通道**（含开发者门户）。
        //   submitLayer0 是门户 / internal.key / 运营后台三条通道的**唯一漏斗**，
        //   写在这一处即全覆盖；写进控制器必然漏掉某条 —— 此前只有 Admin 导入路径写
        //   `manifest_schema`，于是开发者技能出事故时，IncidentService 读到的"证据快照"
        //   这一项恒为 NULL（正好是最该有、却又最缺的一条）。
        //   ⚠ 只写这一列：source_format / imported_meta 归导入器所有，这里不碰；
        //   ⚠ manifest 缺 schema 时**不写**（保持原值 NULL），别把空串写进去冒充有值。
        $schema = $manifest['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            SkillVersion::where('id', $version)->update(['manifest_schema' => $schema]);
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

        // 提交审核 = 版本进入终态判定（**一次迁移**，不再两跳）：
        //   - L0 通过 → in_review（进入后续 L1/L2 与发布流程）；
        //   - L0 不过 → rejected（刚进审就被机审挡下 = "进了审、又被立即驳回"）。
        //   `draft → rejected` 是白名单里的直接边（见 SkillVersionState::TRANSITIONS）。
        //   ⚠ 此前走的是 draft→in_review→rejected 两次写：两步不在一个事务里，第二步失败会
        //     留下 "status=in_review 但 audit_record 已判驳回" 的分裂状态。收敛成一次写即消除。
        $statusOk = $this->syncLifecycle(
            $version,
            $result === AuditRecord::RESULT_PASS
                ? SkillVersion::STATUS_IN_REVIEW
                : SkillVersion::STATUS_REJECTED
        );

        // 通知开发者（任务卡 D-4）——**纯副作用**：判定已经落库，通知发不出去也不能
        // 影响返回值（LifecycleNotifier 内部 safe() 吞异常，找不到归属就不发）。
        // L0 的驳回理由是一组 "CODE:明细"，拍平成 reason_detail 交给通知层。
        $this->notifier->skillReviewDecided($skillId, $version, $result, [
            'layer'         => AuditRecord::LAYER_MACHINE,
            'reason_detail' => $reasons ? implode("\n", $reasons) : null,
        ]);

        return [
            'layer' => AuditRecord::LAYER_MACHINE,
            'result' => $result,
            'reasons' => $reasons,
            'notes' => $notes,
            // 新增键（既有键不动）：状态迁移是否成功。失败已 report，调用方可据此告警。
            'status_transition_ok' => $statusOk,
        ];
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

        // L2 驳回 / 待补充 → 版本落 rejected；L2 通过**不写状态** —— 保持 in_review，
        // 等"过闸门后发布"那一步才写 published（发布是生命周期上的另一个动作）。
        $statusOk = true;

        if (in_array($result, [AuditRecord::RESULT_REJECT, AuditRecord::RESULT_SUPPLEMENT], true)) {
            $statusOk = $this->syncLifecycle($version, SkillVersion::STATUS_REJECTED);
        }

        // 通知开发者（任务卡 D-4）——纯副作用，同 submitLayer0。
        // 驳回/待补充带上理由码与明细，让开发者知道"改什么才能过"。
        $this->notifier->skillReviewDecided($skillId, $version, $result, [
            'layer'           => AuditRecord::LAYER_HUMAN,
            'reason_category' => $reasonCategory,
            'reason_detail'   => $detail,
        ]);

        return [
            'layer' => AuditRecord::LAYER_HUMAN,
            'result' => $result,
            // 新增键（既有键不动）：见 submitLayer0 同名字段说明。L2 通过时不涉及迁移，恒 true。
            'status_transition_ok' => $statusOk,
        ];
    }

    /**
     * 审核判定落库后，把版本生命周期同步到 `$to`（唯一入口 = SkillVersionState）。
     *
     * ⚠ 状态写失败**不静默吞**：`report()` 留痕，返回值交给调用方放进 `status_transition_ok`。
     *   为什么不直接抛：审核记录已经落库（判决是权威事实），此时把整个请求炸掉反而
     *   会让调用方以为"审核没成功"，而其实只剩生命周期这一根轴没跟上 —— 记为异常并如实回报更准。
     *
     * 版本不存在时（如单测直接用合成 id 调 submitLayer0）没有可迁移对象，跳过并视为 ok。
     */
    protected function syncLifecycle(int $versionId, string $to): bool
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            return true;
        }

        // 已经在目标状态 = 无需迁移（例如对已 in_review 的版本重跑 L0 机审）→ 视为 ok，
        // 不当作失败去 report —— 那不是状态机出错，只是没有要改的东西。
        if ((string) $version->status === $to) {
            return true;
        }

        $result = $this->states->transition($version, $to);

        if (! $result['ok']) {
            report(new \RuntimeException(
                "审核后版本状态迁移失败：#{$versionId} → {$to}（{$result['code']} {$result['message']}）"
            ));
        }

        return $result['ok'];
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
