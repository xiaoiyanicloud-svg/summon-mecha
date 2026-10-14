<?php

namespace Webkul\Shop\Services\SkillImport;

use Webkul\Shop\Models\SkillVersion;

/**
 * 技能导入服务 —— 编排 + 落痕 —— v3.4 步骤 6(计划 §4.4.3)。
 *
 * 输入(他平台包/动捕)→ 选导入器 → 中立 manifest → 写 skill_import 留痕 → 收紧约束。
 * 导入器可插拔(见 config/skill_importers.php)。
 */
class SkillImportService
{
    /**
     * 识别 + 导入。返回 ImportResult(含自动收紧的机型/通道)。
     */
    public function import(array $input, ?int $sellerId = null, ?string $sourceRef = null): array
    {
        $importer = $this->resolveImporter($input);

        if (! $importer) {
            return [
                'ok'       => false,
                'message'  => '无法识别该技能包格式(支持:宇树 UniStore / 通用动捕 BVH·SMPLX)。',
                'result'   => null,
            ];
        }

        $result = $importer->import($input);

        if (! $result->ok) {
            return ['ok' => false, 'message' => $result->error ?? '导入失败。', 'result' => null];
        }

        // 留痕(与 skill_import 表对应;此处直接写,表已存在)
        $importId = \DB::table('skill_import')->insertGetId([
            'seller_id'       => $sellerId,
            'source_format'   => $this->sourceFormatFor($input),
            'source_ref'      => $sourceRef,
            'status'          => 'mapped',
            'raw_meta'        => json_encode($input, JSON_UNESCAPED_UNICODE),
            'mapped_manifest' => json_encode($result->manifest, JSON_UNESCAPED_UNICODE),
            'warnings'        => json_encode($result->warnings, JSON_UNESCAPED_UNICODE),
            'created_at'      => now(),
        ]);

        return [
            'ok'          => true,
            'message'     => '导入成功,已映射为中立清单。',
            'import_id'   => $importId,
            'manifest'    => $result->manifest,
            'warnings'    => $result->warnings,
            'locked_models'   => $result->lockedModels,
            'locked_channels' => $result->lockedChannels,
        ];
    }

    /**
     * 选一个能识别的导入器(配置注册表 + 容器)。
     */
    protected function resolveImporter(array $input): ?SkillImporter
    {
        foreach ((array) config('skill_importers.importers', []) as $entry) {
            $class = $entry['class'] ?? null;
            if (! $class || ! class_exists($class)) {
                continue;
            }
            $importer = app($class);
            if ($importer instanceof SkillImporter && $importer->supports($input)) {
                return $importer;
            }
        }

        return null;
    }

    protected function sourceFormatFor(array $input): string
    {
        if (isset($input['base_image']) || isset($input['startup_commands'])) {
            return 'unitree_unistore';
        }
        if (isset($input['motion_format'])) {
            return strtolower((string) $input['motion_format']);
        }

        return 'native';
    }
}
