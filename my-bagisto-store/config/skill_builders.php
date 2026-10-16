<?php

/**
 * 技能构建器注册表 —— v3.4 步骤 6(计划 §4.20 转换层)。
 *
 * 写法对齐项目既有套路(config 注册表 + 容器解析,见 skill_delivery.php / payment_gateways.php)。
 * 加一种技能形态 = 实现 CarrierBuilder + 在这里加一行,业务代码零改动。
 *
 * ⚠ 口径更正(2026-10-16 · D-9):这里的构建器负责的是**编排与登记**,不是真正的转换 ——
 *   中立动作到各机型关节的重定向算法是**外部工具**,平台侧**当前没有生产者**。
 *   故受支持 kind 的构建结果恒为 `pending`(产物需外部工具或人工回填 `artifact_asset_id`),
 *   且 `skill_build` 表**不在交付链路上**。别把"登记了一条 build"读成"已经能下发"。
 */
return [
    'builders' => [
        // 动作类(可跨本体重定向):P0 唯一实现
        [
            'class' => Webkul\Shop\Services\SkillCompile\Builders\MotionCarrierBuilder::class,
        ],

        // 容器应用类(宇树 Docker 载体):P1 实现
        // [
        //     'class' => Webkul\Shop\Services\SkillCompile\Builders\AppCarrierBuilder::class,
        // ],
    ],
];
