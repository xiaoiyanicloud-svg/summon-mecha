<?php

/*
| 五机型 GA 分级开关。所有入口默认关闭，禁止通过单一总开关绕过目标审核和 HIL。
*/
return [
    'build_enabled' => (bool) env('SKILL_GA_BUILD_ENABLED', false),
    'publish_enabled' => (bool) env('SKILL_GA_PUBLISH_ENABLED', false),
    'install_enabled' => (bool) env('SKILL_GA_INSTALL_ENABLED', false),
    'execute_enabled' => (bool) env('SKILL_GA_EXECUTE_ENABLED', false),
    'reconcile_enabled' => (bool) env('SKILL_GA_RECONCILE_ENABLED', false),
    'targets' => [
        'unitree_g1' => (bool) env('SKILL_GA_UNITREE_G1_ENABLED', false),
        'unitree_r1' => (bool) env('SKILL_GA_UNITREE_R1_ENABLED', false),
        'agibot_x2' => (bool) env('SKILL_GA_AGIBOT_X2_ENABLED', false),
        'agibot_a3' => (bool) env('SKILL_GA_AGIBOT_A3_ENABLED', false),
        'engineai_t800' => (bool) env('SKILL_GA_ENGINEAI_T800_ENABLED', false),
    ],
];