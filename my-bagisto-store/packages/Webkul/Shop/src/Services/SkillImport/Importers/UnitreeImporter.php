<?php

namespace Webkul\Shop\Services\SkillImport\Importers;

use Webkul\Shop\Services\SkillImport\ImportResult;
use Webkul\Shop\Services\SkillImport\SkillImporter;
use Webkul\Shop\Services\SkillPackage\SkillManifest;

/**
 * 宇树 UniStore 包导入器 —— v3.4 步骤 6。
 *
 * 识别:含 `base_image` + `startup_commands`(官方 metadata.yaml 特征)。
 * 映射:metadata.yaml → 中立 manifest 的 runtime 段。
 *
 * ⚠ 诚实边界(计划 §4.4.3):宇树容器**依赖 G1 的 DDS 运控,物理上只跑宇树设备** →
 *   导入后**自动锁定机型为宇树、通道为 agent/manual**,不允许下发到别的机型。
 */
class UnitreeImporter implements SkillImporter
{
    public function supports(array $input): bool
    {
        return isset($input['base_image']) || isset($input['startup_commands']);
    }

    public function import(array $input): ImportResult
    {
        $warnings = [
            '宇树容器包依赖 G1 的 DDS 运控接口,仅能在宇树机型上运行;已自动锁定目标机型与通道。',
        ];

        $manifest = [
            'schema'            => SkillManifest::SCHEMA,
            'kind'              => 'app',
            'name'              => ['zh_CN' => (string) ($input['name'] ?? '导入的宇树应用'), 'en' => (string) ($input['name'] ?? 'Imported Unitree App')],
            'version'           => (string) ($input['version'] ?? '1.0.0'),
            'description'       => '由宇树 UniStore 包导入。',
            'pricing_mode'      => 'buyout',
            'delivery_channels' => ['agent', 'manual'],
            'permissions'       => $this->mapPermissions((array) ($input['permissions'] ?? [])),
            'resource_type'     => 'skill',
            'runtime'           => [
                'base_image'       => $input['base_image'] ?? null,
                'build_env'        => $input['build_env'] ?? [],
                'startup_commands' => $input['startup_commands'] ?? [],
                'entrypoint'       => $input['entrypoint'] ?? null,
                'port'             => 80,
            ],
            'compatibility'     => ['models' => [['vendor' => 'unitree', 'model' => 'G1']]],
            'safety'            => [
                'risk_level'        => 'medium',
                'requires_open_area' => true,
                'estop_required'    => true,
                'max_duration_s'    => 120,
            ],
        ];

        return ImportResult::ok(
            manifest: $manifest,
            warnings: $warnings,
            lockedModels: ['unitree_g1'],
            lockedChannels: ['agent', 'manual'],
        );
    }

    /**
     * 宇树 permissions(camera/motor...) → 平台权限白名单。
     */
    protected function mapPermissions(array $perms): array
    {
        $map = [
            'camera' => 'camera',
            'motor'  => 'motion_control',
            'audio'  => 'audio_play',
        ];

        $out = [];
        foreach ($perms as $p) {
            if (isset($map[$p])) {
                $out[] = $map[$p];
            }
        }

        return array_values(array_unique($out ?: ['motion_control']));
    }
}
