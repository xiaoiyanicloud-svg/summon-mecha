<?php

namespace Webkul\Shop\Services\SkillAudit;

use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\ContentScanRecorder;
use Webkul\Shop\Services\LifecycleNotifier;
use Webkul\Shop\Services\SkillPackage\ManifestValidator;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;

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
        protected PackageFingerprint $fingerprint,
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

        // 1.5) 开发者**声明的字段**落库 —— 补齐**所有通道**（含开发者门户）。
        //   submitLayer0 是门户 / internal.key / 运营后台三条通道的**唯一漏斗**，
        //   写在这一处即全覆盖；写进控制器必然漏掉某条。此前只有 Admin **导入路径**写这几列，
        //   于是开发者提交的技能恒为 NULL，代价不是"少几个字段"，而是**功能直接失效**：
        //     · `delivery_channels` 恒 NULL → TrialService 的"必须声明 agent 通道"硬闸永不过
        //       → 开发者技能**永远不能试用**（D4 变成了自营专属）；
        //     · `risk_level` 恒 NULL → RiskDisclosureService 读不到等级，风险告知退化成"未分级"；
        //     · `manifest_schema`（本次之前已在写）—— 出事故时 IncidentService 读的"证据快照"。
        //   ⚠ 写入规则：**有值才写**（覆盖为最新声明）；无值时**不写** ——
        //     别用空值把已有声明抹掉（整改后重提审的包若少写了某项，不该清掉上一次的合法声明）。
        //   ⚠ 只写这几列：source_format / imported_meta 归导入器所有，这里不碰。
        $declared = [];

        $schema = $manifest['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $declared['manifest_schema'] = $schema;
        }

        // delivery_channels 是 json 列（模型 cast array）；空数组与 NULL 一样"没声明"，不写。
        $channels = $manifest['delivery_channels'] ?? null;
        if (is_array($channels) && $channels !== []) {
            $declared['delivery_channels'] = array_values($channels);
        }

        // safety 可能是畸形值（非数组）—— 取不到字符串就当作"没声明"，绝不写 null 进去。
        $safety = $manifest['safety'] ?? null;
        $riskLevel = is_array($safety) ? ($safety['risk_level'] ?? null) : null;
        if (is_string($riskLevel) && $riskLevel !== '') {
            $declared['risk_level'] = $riskLevel;
        }

        // ⚠ D3 红线：定价一律走 `pricing_mode`（buyout），**绝不**写 `license_mode='perpetual'`；
        //   两列正交 —— license_mode 是"授权范围"（per_device…），pricing_mode 是"怎么卖"。
        $pricingMode = $manifest['pricing_mode'] ?? null;
        if (is_string($pricingMode) && $pricingMode !== '') {
            $declared['pricing_mode'] = $pricingMode;
        }

        // 权限声明落库（D-8 改判版：**只做可见化**，让用户在下单前看得见"这技能会用到什么"）。
        //   ⚠ 明确**不做**任何"声明 vs 实际调用"的比对/强制，理由（不要再有人回来补这个幻想）：
        //     ① device_model 表没有任何能力/权限字段 —— 没有可比对的真实数据源，
        //        做下去只能是"自造一套能力词表去校验另一套自造词表"；
        //     ② `kind=motion` 的纯动作技能没有代码可分析，"实际调用"无从谈起；
        //     ③ 端侧受控执行不存在（E7），没有运行时能观测到"实际用了什么"。
        //   写入规则与邻列一致：**非空数组才写**；无值不写 —— 空数组不覆盖已有声明。
        $permissions = $manifest['permissions'] ?? null;
        if (is_array($permissions) && $permissions !== []) {
            $declared['permissions'] = array_values($permissions);
        }

        // A3 · 适配声明落库：清单里的 `compatibility` 此前校验完即丢，买家/设备侧只能看到
        //   交付通道，看不到"这个版本声明支持哪些机型/固件/SDK/Agent"。
        //   ⚠ 这是**声明**，不是证据；实际验证结果在 skill_compatibility_evidence。
        //   写入规则与 permissions 一致：非空数组才写，不拿空值抹掉已有声明。
        $compatibility = $manifest['compatibility'] ?? null;
        if (is_array($compatibility) && $compatibility !== []) {
            $declared['compatibility'] = $compatibility;
        }

        if ($declared !== []) {
            // 用模型写入：`compatibility` 等 json 列需要走 cast 序列化，
            // 查询构造器 update 会绕开 cast 把数组直接塞进 PDO。
            $target = SkillVersion::find($version);

            if ($target) {
                $target->fill($declared);
                $target->save();
            }
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

        // 包指纹 —— "**判决当时看到的是哪一份包**"（任务卡 D-7 · ⑨）。
        // 落在这里而不是版本表上：版本表会被后续上传覆盖，等于没记。
        // ⚠ 取的是**此刻**的指纹（= 审核员面前那份包）；发布时拿它跟当时的指纹比。
        $this->record($skillId, $version, AuditRecord::LAYER_MACHINE, $result, $reasons, null, [
            'package_fingerprint' => $this->fingerprint->forVersion($version),
        ]);

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
            // 包指纹 —— 人审看的必须是**这份字节流**（任务卡 D-7 · ⑨）。
            // 与 L0 同理：记录"判决当时的那一份"，发布时据此比对。只加不改既有键。
            'package_fingerprint' => $this->fingerprint->forVersion($version),
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
