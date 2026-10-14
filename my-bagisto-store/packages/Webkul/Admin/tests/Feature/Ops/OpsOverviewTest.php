<?php

use Webkul\Shop\Services\Ops\OpsMetricsService;

use function Pest\Laravel\get;

/**
 * 运营总览（v3.4 第 10 步）。
 *
 * 这个页面的测试重点不是"数字对不对"，而是**它会不会骗人**：
 *   ① 每个指标必须带口径（不知道口径的数字比没有数字更危险）
 *   ② 数据源未接入的维度必须显示「未接入」，**不能显示 0**
 *      ——"下载量 0"与"下载量根本没统计"对决策的含义完全相反
 *   ③ 已被核验否定、故意不做的维度要**显式列出来**，而不是从页面上消失
 */

it('运营总览页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.dashboard.ops'))
        ->assertOk()
        ->assertSeeText('运营总览');
});

it('每个指标都带口径说明', function () {
    $service = app(OpsMetricsService::class);

    foreach ([
        $service->trade(), $service->supply(), $service->review(), $service->devices(),
        $service->entitlements(), $service->service(), $service->developers(), $service->compliance(),
    ] as $group) {
        foreach ($group as $key => $metric) {
            expect($metric)->toHaveKeys(['value', 'basis', 'ready'])
                ->and($metric['basis'])->not->toBe('', "指标 {$key} 缺口径说明");
        }
    }
});

it('交易口径：金额明确写「下单口径」而不是收入', function () {
    $trade = app(OpsMetricsService::class)->trade();

    // ⚠ 本项目没有真实支付（WechatGateway 是占位）。把下单金额说成收入是虚假陈述。
    expect($trade['skill_amount']['basis'])->toContain('下单口径');

    // 已收款维度必须是"未接入"，不能是一个数字
    expect($trade['paid_amount']['ready'])->toBeFalse()
        ->and($trade['paid_amount']['value'])->toBeNull();
});

it('未接入的维度：显示「未接入」而不是 0', function () {
    $this->loginAsAdmin();

    get(route('admin.dashboard.ops'))
        ->assertOk()
        // 页面上必须真的出现这四个字
        ->assertSeeText('未接入');
});

it('故意不做的维度被显式列出（而不是从页面上消失）', function () {
    $notConnected = app(OpsMetricsService::class)->notConnected();

    $names = collect($notConnected)->pluck('name')->implode(' | ');

    expect($names)->toContain('下载量')
        ->and($names)->toContain('浏览量')
        ->and($names)->toContain('净收入')
        ->and($names)->toContain('对账');

    // 每一条都要说明"为什么没有"，否则读者会以为是遗漏
    foreach ($notConnected as $row) {
        expect($row['reason'])->not->toBe('');
    }
});

it('看板页把未统计维度的理由也渲染出来', function () {
    $this->loginAsAdmin();

    get(route('admin.dashboard.ops'))
        ->assertOk()
        ->assertSeeText('本页未统计的维度')
        ->assertSeeText('下载量');
});

it('审核队列深度：用正确的关联列（audit_record 的列名与内容不一致）', function () {
    // 造一个已提审（有 L0 记录）、尚未做 L2 的草稿版本
    $productId = DB::table('products')->insertGetId([
        'sku' => 'ops-sku-'.uniqid(), 'type' => 'simple',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $versionId = DB::table('skill_versions')->insertGetId([
        'product_id' => $productId, 'version' => '1.0.0', 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // ⚠ skill_id 存的是 products.id，version 存的是 skill_versions.id
    DB::table('audit_record')->insert([
        'skill_id' => $productId, 'version' => $versionId, 'audit_type' => 1,
        'layer' => 1, 'result' => 1, 'submitted_at' => now(), 'audited_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $before = app(OpsMetricsService::class)->review()['pending_l2']['value'];

    expect($before)->toBeGreaterThan(0);

    // L2 通过之后，队列深度应减一
    DB::table('audit_record')->insert([
        'skill_id' => $productId, 'version' => $versionId, 'audit_type' => 2,
        'layer' => 3, 'result' => 1, 'submitted_at' => now(), 'audited_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(OpsMetricsService::class)->review()['pending_l2']['value'])->toBe($before - 1);
});

it('未结案事故：严重度区分出应急响应线', function () {
    DB::table('incident')->insert([
        [
            'incident_no' => 'I-OPS-1', 'severity' => 'critical', 'status' => 'reported',
            'description' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'incident_no' => 'I-OPS-2', 'severity' => 'minor', 'status' => 'reported',
            'description' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'incident_no' => 'I-OPS-3', 'severity' => 'critical', 'status' => 'resolved',
            'description' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $service = app(OpsMetricsService::class)->service();

    // 2 起未结（第 3 起已结不算），其中 1 起严重
    expect($service['incidents_open']['value'])->toBe(2)
        ->and($service['incidents_severe']['value'])->toBe(1);
});
