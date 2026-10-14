<?php

/**
 * 技能构建器注册表 —— v3.4 步骤 6(计划 §4.20 转换层)。
 *
 * 写法对齐项目既有套路(config 注册表 + 容器解析,见 skill_delivery.php / payment_gateways.php)。
 * 加一种技能形态 = 实现 CarrierBuilder + 在这里加一行,业务代码零改动。
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
