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

        // schema
        if ($manifest->schema !== SkillManifest::SCHEMA) {
            $errors[] = "schema 必须为 ".SkillManifest::SCHEMA."，当前为 [{$manifest->schema}]。";
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
}
