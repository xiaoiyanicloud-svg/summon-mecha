<?php

namespace Webkul\Shop\Services\SkillPackage;

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
        if (in_array($ch, ['agent', 'manual'], true)) {
            return true;
        }

        return (bool) preg_match('/^vendor:[a-z0-9_]+$/', $ch);
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
