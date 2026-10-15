<?php

namespace Webkul\Shop\Services\SkillImport\Importers;

use Webkul\Shop\Services\SkillImport\ImportResult;
use Webkul\Shop\Services\SkillImport\SkillImporter;
use Webkul\Shop\Services\SkillPackage\SkillManifest;

/**
 * 通用动捕导入器(BVH/SMPL-X)—— v3.4 步骤 6。
 *
 * 识别:带 `motion_format`(bvh/smplx/fbx)的输入。
 * 映射:motion 段 → 中立 manifest;机型与通道由用户选定(通用动作可重定向)。
 */
class RawMotionImporter implements SkillImporter
{
    protected const SUPPORTED = ['bvh', 'smplx', 'fbx', 'macp'];

    public function supports(array $input): bool
    {
        return isset($input['motion_format'])
            && in_array(strtolower((string) $input['motion_format']), self::SUPPORTED, true);
    }

    public function import(array $input): ImportResult
    {
        $format = strtolower((string) $input['motion_format']);

        $manifest = [
            'schema'            => SkillManifest::SCHEMA,
            'kind'              => 'motion',
            'name'              => ['zh_CN' => (string) ($input['name'] ?? '导入的动作'), 'en' => (string) ($input['name'] ?? 'Imported Motion')],
            'version'           => (string) ($input['version'] ?? '1.0.0'),
            'description'       => "由 {$format} 动捕数据导入。",
            'pricing_mode'      => (string) ($input['pricing_mode'] ?? 'buyout'),
            'delivery_channels' => ['agent', 'manual'],
            'permissions'       => ['motion_control'],
            'resource_type'     => 'motion',
            'motion'            => [
                'format'   => $format,
                'fps'      => (int) ($input['fps'] ?? 60),
                'retarget' => (array) ($input['retarget'] ?? []),
            ],
            'safety'            => [
                'risk_level'        => (string) ($input['risk_level'] ?? 'low'),
                'requires_open_area' => true,
                'estop_required'    => false,
                'max_duration_s'    => (int) ($input['max_duration_s'] ?? 120),
            ],
        ];

        $warnings = ['通用动作需经重定向工具适配到目标机型后,才能下发(见转换层)。'];

        return ImportResult::ok($manifest, $warnings);
    }
}
