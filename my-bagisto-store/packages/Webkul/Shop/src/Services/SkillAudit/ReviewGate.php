<?php

namespace Webkul\Shop\Services\SkillAudit;

use Webkul\Shop\Models\AuditRecord;

/**
 * 发布闸门 —— "一个版本够不够格上架"的**唯一定义处**。
 *
 * 为什么单独一个类，而不是继续用 `AuditService::isFullyApproved()`：
 * 后者把"必须三层全过"写死在代码里，而 L1 仿真层依赖一个**外部仿真器**
 * （尚未接入）。写死的结果是：**所有技能都发布不了**。
 * 闸门必须能表达"哪几层参与"，所以它是一条**策略**，进配置（`config/skill_audit.php`）。
 *
 * 三条原则，改动前先读：
 *
 *   1. **没审 ≠ 通过**。没有记录的层不是"通过"，是"尚未审核"，一律挡。
 *      （与内容安全的「没查 ≠ 合规」同源）
 *   2. **未接入的层如实说不参与**，而不是伪装成已通过。界面读 `available`。
 *   3. **不提供绕过**。运营想发布一个没过闸门的版本，只能去把审核做掉 ——
 *      这是这条闸门存在的全部意义。
 */
class ReviewGate
{
    /**
     * 参与闸门的层。
     *
     * @return int[]
     */
    public function requiredLayers(): array
    {
        $layers = config('skill_audit.required_layers', [1, 3]);

        return array_values(array_unique(array_map('intval', (array) $layers)));
    }

    /**
     * 全部层（不只是参与闸门的层）—— 界面上要能看见"L1 未接入"。
     *
     * @return int[]
     */
    public function allLayers(): array
    {
        return array_keys((array) config('skill_audit.layer_labels', [1 => 'L0 机审', 3 => 'L2 人审']));
    }

    public function label(int $layer): string
    {
        return (string) (config('skill_audit.layer_labels.'.$layer) ?: '第 '.$layer.' 层');
    }

    /**
     * 该层是否已接入。
     *
     * ⚠ 这是**声明**，不是探测。仿真器接入后要改 `config/skill_audit.php`
     *   的 `layers_available`，并把该层加进 `required_layers`。
     */
    public function isAvailable(int $layer): bool
    {
        return (bool) config('skill_audit.layers_available.'.$layer, false);
    }

    public function isRequired(int $layer): bool
    {
        return in_array($layer, $this->requiredLayers(), true);
    }

    /**
     * 该版本是否已提审过（至少有一条审核记录）。
     *
     * 用来把"还没提审"和"提审了没过"分开 —— 前者不该出现在审核队列里。
     */
    public function hasSubmitted(int $productId, int $versionId): bool
    {
        return AuditRecord::query()->forVersion($productId, $versionId)->exists();
    }

    /**
     * 各层的最新判定。
     *
     * @return array<int, array{
     *     layer: int, label: string, available: bool, required: bool,
     *     result: int|null, result_label: string, audited_at: mixed,
     *     reason_category: string|null, reason_detail: string|null, suggestion: string|null
     * }>
     */
    public function layerStates(int $productId, int $versionId): array
    {
        // 一次查全部层的最新记录，避免逐层查库
        $latest = AuditRecord::query()
            ->forVersion($productId, $versionId)
            ->orderBy('id')
            ->get()
            ->keyBy('layer');   // 后写覆盖先写 → 留在手里的是最新一条

        $states = [];

        foreach ($this->allLayers() as $layer) {
            /** @var AuditRecord|null $record */
            $record = $latest->get($layer);

            $states[$layer] = [
                'layer'           => $layer,
                'label'           => $this->label($layer),
                'available'       => $this->isAvailable($layer),
                'required'        => $this->isRequired($layer),
                'result'          => $record?->result,
                'result_label'    => $this->resultLabel($record?->result),
                'audited_at'      => $record?->audited_at,
                'reason_category' => $record?->reason_category,
                'reason_detail'   => $record?->reason_detail,
                'suggestion'      => $record?->suggestion,
            ];
        }

        return $states;
    }

    /**
     * 闸门判定。
     *
     * @return array{
     *     ok: bool,
     *     unmet: array<int, array{layer: int, label: string, reason: string}>,
     *     layers: array<int, array>,
     *     message: string
     * }
     */
    public function evaluate(int $productId, int $versionId): array
    {
        $layers = $this->layerStates($productId, $versionId);
        $unmet = [];

        foreach ($this->requiredLayers() as $layer) {
            $state = $layers[$layer] ?? null;

            // 配了一个不存在的层 —— 那是配置写错了，fail-closed 并说清楚
            if ($state === null) {
                $unmet[] = ['layer' => $layer, 'label' => $this->label($layer), 'reason' => '配置要求该层，但系统不认识这一层'];

                continue;
            }

            if ($state['result'] === AuditRecord::RESULT_PASS) {
                continue;
            }

            $unmet[] = [
                'layer'  => $layer,
                'label'  => $state['label'],
                'reason' => $this->unmetReason($state),
            ];
        }

        $ok = $unmet === [];

        return [
            'ok'      => $ok,
            'unmet'   => $unmet,
            'layers'  => $layers,
            'message' => $ok
                ? '三层闸门已满足，可以发布。'
                : '尚有审核未通过：'.implode('；', array_map(
                    fn ($u) => $u['label'].'（'.$u['reason'].'）',
                    $unmet
                )),
        ];
    }

    /**
     * 一层没过的原因 —— 措辞要能直接给运营看。
     *
     * @param  array{available: bool, result: int|null}  $state
     */
    protected function unmetReason(array $state): string
    {
        if ($state['result'] === null) {
            // 层没接入时，原因不是"忘了审"，而是"审不了" —— 这两种要说清楚
            return $state['available'] ? '尚未审核' : '该层未接入，无法审核';
        }

        return match ($state['result']) {
            AuditRecord::RESULT_PENDING    => '待审',
            AuditRecord::RESULT_REJECT     => '已驳回',
            AuditRecord::RESULT_SUPPLEMENT => '待补充材料',
            default                        => '状态未知',
        };
    }

    /**
     * 判定结果的人话。
     *
     * ⚠ `null`（没有记录）必须与"待审"分开：一个是"还没人看"，一个是"看了，排队中"。
     */
    public function resultLabel(?int $result): string
    {
        return match ($result) {
            null                           => '尚未审核',
            AuditRecord::RESULT_PENDING    => '待审',
            AuditRecord::RESULT_PASS       => '通过',
            AuditRecord::RESULT_REJECT     => '已驳回',
            AuditRecord::RESULT_SUPPLEMENT => '待补充',
            default                        => '未知',
        };
    }
}
