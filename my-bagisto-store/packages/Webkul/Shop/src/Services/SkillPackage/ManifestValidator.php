<?php

namespace Webkul\Shop\Services\SkillPackage;

use Webkul\Shop\Models\SkillAsset;

/**
 * 中立技能清单校验器 —— v3.4 步骤 6,L0 机审的一部分。
 *
 * 只做"契约校验":形状对不对、必填在不在、枚举合法否。
 * 不做内容安全/权限越权(那些在 AuditService 的 L0 里)。
 *
 * 返回 {valid, errors[], warnings[]} —— errors 非空即拒绝,warnings 只提示不拦。
 */
class ManifestValidator
{
    /**
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validate(array $data): array
    {
        $manifest = SkillManifest::fromArray($data);
        $errors = [];
        $warnings = [];

        // schema —— 不再"一句话打死"，按四种情况给能指引下一步的提示（绝不静默接受）。
        //   为什么要分：此前无论来的是 v1、v3 还是别的命名空间，返回的都是同一句
        //   "schema 必须为 zhaohuan.skill/v2"，开发者看不出到底是"太新""太旧"还是"写错了"，
        //   只能瞎试。分开之后每种原因对应一种明确的改法。
        if ($manifest->schema === '') {
            $errors[] = 'schema 缺失，应为 '.SkillManifest::SCHEMA.'。';
        } elseif (! in_array($manifest->schema, SkillManifest::SCHEMA_SUPPORTED, true)) {
            $errors[] = $this->describeSchemaMismatch($manifest->schema);
        }

        // kind
        $kinds = [SkillManifest::KIND_MOTION, SkillManifest::KIND_APP, SkillManifest::KIND_COMPOSITE];
        if (! in_array($manifest->kind, $kinds, true)) {
            $errors[] = "kind 必须是 ".(implode('/', $kinds))." 之一。";
        }

        // 名称(至少一种语言)
        if (empty($manifest->name)) {
            $errors[] = 'name 至少需要一种语言(zh_CN 或 en)。';
        }

        // 版本
        if (! preg_match('/^\d+\.\d+\.\d+/', $manifest->version)) {
            $errors[] = 'version 需符合 x.y.z 形式。';
        }

        // 交付通道
        if (empty($manifest->deliveryChannels)) {
            $errors[] = 'delivery_channels 不能为空。';
        } else {
            foreach ($manifest->deliveryChannels as $ch) {
                if (! $this->isValidChannel($ch)) {
                    $errors[] = "delivery_channels 含非法值 [{$ch}](合法:agent / manual / vendor:{code})。";
                }
            }
        }

        // 权限白名单
        foreach ($manifest->permissions as $p) {
            if (! in_array($p, SkillManifest::PERMISSION_WHITELIST, true)) {
                $errors[] = "permissions 含未知权限 [{$p}]。";
            }
        }

        // 资源类型
        if (! in_array($manifest->resourceType, SkillManifest::RESOURCE_TYPES, true)) {
            $errors[] = "resource_type 非法 [{$manifest->resourceType}]。";
        }

        // 适配声明（A3）—— 必须校验**原始数组**，不能只看 SkillManifest::fromArray()。
        //   后者把非法值静默降级为 []，只看它会把"写错了"当成"没声明"，
        //   于是错误声明可以过审、详情页再把它悄悄丢掉 —— 声明与审核事实分叉。
        //   缺省（完全没写）是合法的：那表示"未声明适配"，不是格式错误。
        if (array_key_exists('compatibility', $data)) {
            $this->validateCompatibility($data['compatibility'], $errors);
        }

        // safety 必填(计划 §4.14 L1:高危动作必须声明)
        if (empty($manifest->safety)) {
            $errors[] = 'safety 必填(risk_level/requires_open_area/estop_required/max_duration_s)。';
        } else {
            $risk = $manifest->riskLevel();
            if (! in_array($risk, ['low', 'medium', 'high'], true)) {
                $errors[] = "safety.risk_level 必须是 low/medium/high，当前 [{$risk}]。";
            }
        }

        // kind=app 时须有 runtime 入口
        if ($manifest->kind === SkillManifest::KIND_APP && empty($manifest->runtime['entrypoint'] ?? null)) {
            $warnings[] = 'kind=app 建议提供 runtime.entrypoint。';
        }

        // kind=motion 时建议有 motion 段
        if ($manifest->kind === SkillManifest::KIND_MOTION && empty($manifest->motion)) {
            $warnings[] = 'kind=motion 建议提供 motion 段(format/fps)。';
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    protected function isValidChannel(string $ch): bool
    {
        return SkillAsset::isValidDeliveryChannel($ch);
    }

    /**
     * 清单 `compatibility` 段的契约校验。
     *
     * 形状：{ models: [ { vendor, model, min_firmware?, min_app?, dof? } ] }
     * 只有 vendor/model 是必填；其余为可选下限/能力提示。
     *
     * ⚠ 这里只校验"声明写得对不对"，不判断真机能否安装 —— 后者属于证据链
     *   （skill_compatibility_evidence），不得由一份自报声明直接升级为"已验证"。
     *
     * @param  mixed  $compatibility
     * @param  array<int, string>  $errors
     */
    protected function validateCompatibility($compatibility, array &$errors): void
    {
        if (! is_array($compatibility)) {
            $errors[] = 'compatibility 必须是对象（含 models 数组）。';

            return;
        }

        if ($compatibility === []) {
            $errors[] = 'compatibility 不能为空对象；未声明适配条件请整段省略。';

            return;
        }

        $models = $compatibility['models'] ?? null;

        if (! is_array($models) || $models === []) {
            $errors[] = 'compatibility.models 必须是非空数组。';

            return;
        }

        foreach ($models as $index => $entry) {
            $position = 'compatibility.models['.$index.']';

            if (! is_array($entry)) {
                $errors[] = "{$position} 必须是对象。";

                continue;
            }

            foreach (['vendor', 'model'] as $required) {
                $value = $entry[$required] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    $errors[] = "{$position}.{$required} 必填且必须是非空字符串。";
                }
            }

            foreach (['min_firmware', 'min_app'] as $optional) {
                if (isset($entry[$optional]) && ! is_string($entry[$optional])) {
                    $errors[] = "{$position}.{$optional} 必须是字符串。";
                }
            }

            if (isset($entry['dof']) && (! is_int($entry['dof']) || $entry['dof'] < 0)) {
                $errors[] = "{$position}.dof 必须是非负整数。";
            }
        }
    }

    /**
     * schema 不在接受清单里时，给一句能让开发者**自助修复**的话。
     *
     * 三类原因对应三种改法，措辞刻意区分：
     *   太新   → 平台还没支持，别改包，等平台 / 或降版本导出；
     *   太旧   → 该版本已停用，按当前版本重新导出；
     *   不认识 → 命名空间根本不对（多半是把别的格式当成本平台清单了）。
     * 提示里带上"当前支持到 vN"是关键 —— 让"太新"一眼可见，不再被误当成写错字段。
     */
    protected function describeSchemaMismatch(string $schema): string
    {
        $current = SkillManifest::SCHEMA;
        $currentVersion = SkillManifest::parseSchemaVersion($current);

        // "推荐给开发者的版本"标签：正常是 `v2（zhaohuan.skill/v2）`；
        // 万一 SCHEMA 常量被改坏取不出号，退化成只报串名，不拼出 "v" 这种半截话。
        $target = $currentVersion !== null ? "v{$currentVersion}（{$current}）" : $current;

        $incoming = SkillManifest::parseSchemaVersion($schema);

        // 非本族或格式不对 —— 连版本号都取不出来，谈不上新旧
        if ($incoming === null) {
            return "无法识别的 schema 命名空间 [{$schema}]（本平台清单应以 "
                .SkillManifest::SCHEMA_FAMILY."/ 开头，如 {$current}）。";
        }

        if ($currentVersion !== null && $incoming > $currentVersion) {
            return "schema [{$schema}] 版本过新，平台当前支持到 {$target}，"
                ."请用 {$current} 重新导出后再提交。";
        }

        // 能走到这里 = 同族、版本号 ≤ 当前、且不等于当前（等于当前不会进本方法）→ 已停用/过旧
        return "schema [{$schema}] 该版本已停用，请用 {$target} 重新导出后再提交。";
    }
}
