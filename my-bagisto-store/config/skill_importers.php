<?php

/**
 * 技能导入器注册表 —— v3.4 步骤 6(计划 §4.4.3)。
 *
 * 加一种来源格式 = 实现 SkillImporter + 在这里加一行,业务代码零改动。
 * 顺序即优先级(先匹配者先用)。
 */
return [
    'importers' => [
        // 宇树 UniStore(metadata.yaml)
        [
            'class' => Webkul\Shop\Services\SkillImport\Importers\UnitreeImporter::class,
        ],

        // 通用动捕(BVH/SMPL-X/FBX)
        [
            'class' => Webkul\Shop\Services\SkillImport\Importers\RawMotionImporter::class,
        ],

        // 智元 AIMDK 资源包:P1
    ],
];
