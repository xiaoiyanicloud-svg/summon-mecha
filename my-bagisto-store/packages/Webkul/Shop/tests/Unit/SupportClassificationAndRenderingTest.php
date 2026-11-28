<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Webkul\Admin\DataGrids\Customers\SupportTicketDataGrid;
use Webkul\Shop\Models\SupportTicket;

if (! Facade::getFacadeApplication()) {
    $container = new Container();
    $container->instance('events', new Dispatcher($container));
    Facade::setFacadeApplication($container);
}

if (! function_exists('e')) {
    function e($value, $doubleEncode = true): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $doubleEncode);
    }
}

it('工单分类标签与色调映射覆盖所有枚举值且兜底安全', function () {
    $expectedKeys = [
        SupportTicket::CATEGORY_DELIVERY,
        SupportTicket::CATEGORY_SKILL,
        SupportTicket::CATEGORY_DEVICE,
        SupportTicket::CATEGORY_PAYMENT,
        SupportTicket::CATEGORY_OTHER,
    ];

    expect(array_keys(SupportTicket::CATEGORY_LABELS))->toBe($expectedKeys)
        ->and(array_keys(SupportTicket::CATEGORY_TONES))->toBe($expectedKeys);

    $ticket = new SupportTicket(['category' => SupportTicket::CATEGORY_DEVICE]);
    expect($ticket->categoryLabel())->toBe('设备问题')
        ->and($ticket->categoryTone())->toBe('urgent');

    $legacy = new SupportTicket(['category' => 'legacy_unknown']);
    expect($legacy->categoryLabel())->toBe('legacy_unknown')
        ->and($legacy->categoryTone())->toBe('closed');
});

it('快捷回复注册表覆盖每个工单分类及通用组', function () {
    $config = require dirname(__DIR__, 5).'/config/support_quick_replies.php';
    $groups = $config['groups'] ?? [];

    expect($groups)->toHaveKey('common');

    foreach (array_keys(SupportTicket::CATEGORY_LABELS) as $categoryKey) {
        expect($groups)->toHaveKey($categoryKey);
        expect($groups[$categoryKey]['items'] ?? [])->not->toBeEmpty();
    }
});

it('后台工单列表 DataGrid 支持问题分类筛选、中文标签渲染与受理人列', function () {
    $grid = new SupportTicketDataGrid();
    $grid->prepareColumns();
    $columns = collect($grid->getColumns())->keyBy('index');

    $categoryCol = $columns->get('category');
    expect($categoryCol)->not->toBeNull()
        ->and($categoryCol->getFilterable())->toBeTrue()
        ->and(array_column($categoryCol->getFilterableOptions(), 'value'))
        ->toBe(array_keys(SupportTicket::CATEGORY_LABELS));

    $closure = $categoryCol->getClosure();
    $renderedDevice = $closure((object) ['category' => SupportTicket::CATEGORY_DEVICE]);
    expect($renderedDevice)->toContain('设备问题')->not->toContain('>device<');

    expect($columns->has('assignee_name'))->toBeTrue()
        ->and($columns->has('updated_at'))->toBeTrue();
});

it('后台工作台视图安全渲染商品/订单/设备三类结构化附件并包含归类筛选钩子', function () {
    $base = dirname(__DIR__, 5).'/packages/Webkul/Admin/src/Resources/views/customers/tickets';
    $workspace = file_get_contents($base.'/partials/workspace.blade.php');
    $inboxList = file_get_contents($base.'/partials/conversation-list.blade.php');
    $index = file_get_contents($base.'/index.blade.php');

    expect($workspace)
        ->toContain('id="inbox-category-filter"')
        ->toContain('id="chat-category-badge"')
        ->toContain('id="quick-reply-search"')
        ->toContain('id="quick-replies-container"')
        ->toContain('id="ticket-category-select"')
        ->toContain('$isOrderAttachment')
        ->toContain('$isDeviceAttachment')
        ->not->toContain('{{ $attachment }}');

    expect($inboxList)
        ->toContain('data-category=')
        ->toContain("\$row['category_label']");

    expect($index)
        ->toContain('待处理归类分布')
        ->toContain('客服工单');
});
