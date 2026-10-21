<?php

return [
    // Expand 后逐项开放；停止写入不关闭客户历史查询。
    'publication_v2' => env('SCENE_PUBLICATION_V2', false),
    'requests_enabled' => env('SCENE_REQUESTS_ENABLED', false),
    'quotes_enabled' => env('SCENE_QUOTES_ENABLED', false),
    'reservations_enabled' => env('SCENE_RESERVATIONS_ENABLED', false),
    'project_actions_enabled' => env('SCENE_PROJECT_ACTIONS_ENABLED', false),
    'operations_enabled' => env('SCENE_OPERATIONS_ENABLED', false),
    // 部署环境先执行 SC3 MySQL 事务/并发验收，再显式开放；历史查询不受此开关影响。
    'order_conversion_enabled' => env('SCENE_ORDER_CONVERSION_ENABLED', false),
    'guest_days' => 30,
    'hold_minutes' => 20,
    'currency' => 'CNY',
    'terms' => ['version' => null, 'text' => null],
    // 未冻结取消、损坏、退款、税率规则时不生成默认承诺。
    'commercial_policy' => null,
    'attachment_scan_command' => null,
    'workflows' => [
        'manual_site_v1' => ['version' => 1, 'tasks' => [
            ['key' => 'dispatch', 'name' => '出库检查', 'dependencies' => [], 'checklist' => ['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'project_state_after' => 'delivering'],
            ['key' => 'transport', 'name' => '运输到场', 'dependencies' => ['dispatch'], 'checklist' => ['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'project_state_after' => 'delivering'],
            ['key' => 'setup', 'name' => '现场布置', 'dependencies' => ['transport'], 'checklist' => ['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'project_state_after' => 'delivering'],
            ['key' => 'rehearsal', 'name' => '安全检查与彩排', 'dependencies' => ['setup'], 'checklist' => ['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'project_state_after' => 'servicing'],
            ['key' => 'service', 'name' => '现场服务', 'dependencies' => ['rehearsal'], 'checklist' => ['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'project_state_after' => 'awaiting_acceptance'],
        ]],
    ],
];
