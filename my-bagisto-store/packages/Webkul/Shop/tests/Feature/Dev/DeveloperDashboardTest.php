<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Ops\OpsMetricsService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 开发者「我的数据」—— 并入门户总览页（不新开页面）。
 *
 * 这个页面的测试重点**不是数字对不对**，而是**它会不会骗人**：
 *   ① 开发者打开它，真正的问题是"有多少人在用"——而平台恰恰没有曝光/安装统计。
 *      第一眼看到一串 0 会读成"我的技能没人用"。所以「说清边界」必须排在
 *      **任何一个数字之前**（下面有 assertSeeTextInOrder 锁着）。
 *   ② 数字只能来自他自己的主体。主体缺归属时**一条都不展示**，
 *      绝不回落到平台全站口径（本仓库在 seller_id 可空这件事上踩过两次同型坑）。
 *   ③ 口径用开发者语域，不是平台运维行话（`skill_search_index`/`out_trade_no`/`E1` 读不懂）。
 */

function devDashCustomer(string $prefix = 'dash'): Customer
{
    return Customer::create([
        'first_name'  => 'Dash',
        'last_name'   => 'Dev',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 走真实 HTTP 完成入驻 + 签协议，返回 [customer, profile]。
 *
 * ⚠ 与 DeveloperAuthzTest 的 makeDev() 走同一条路（不手搓 profile）——
 *   手搓出来的 profile 会漏掉 seller 归属，那样测的就不是真实形态了。
 */
function devDashOnboarded(string $prefix = 'dash'): array
{
    $customer = devDashCustomer($prefix);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/** 直接给某个 seller 造 N 个技能商品（服务层测试用，不走 HTTP）。 */
function devDashSkills(int $sellerId, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        DB::table('products')->insert([
            'sku'        => 'dash-'.uniqid(),
            'type'       => 'simple',
            'seller_id'  => $sellerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

// ==================== 服务层：只算自己的 ====================

it('forSeller：只统计该主体自己的技能，别人的一个都不算', function () {
    [, $a] = devDashOnboarded('dash_a_');
    [, $b] = devDashOnboarded('dash_b_');

    devDashSkills($a->seller_id, 2);
    devDashSkills($b->seller_id, 5);

    $metrics = app(OpsMetricsService::class);

    expect($metrics->forSeller($a->seller_id)['products']['value'])->toBe(2)
        ->and($metrics->forSeller($b->seller_id)['products']['value'])->toBe(5);
});

it('forSeller：签名是非空 int —— "没有归属"在类型上不可表达', function () {
    // 这是防"顺手改成 ?int $sellerId = null 然后 null 走平台口径"的回归锁。
    // 参数一旦变成可空，"这个开发者没有归属主体"就会被静默解释成"给他看平台全站数字"。
    $method = new ReflectionMethod(OpsMetricsService::class, 'forSeller');
    $param = $method->getParameters()[0];

    expect($param->getType()->getName())->toBe('int')
        ->and($param->getType()->allowsNull())->toBeFalse()
        ->and($param->isDefaultValueAvailable())->toBeFalse();
});

it('forSeller：下载量取自真实的下载计数，不是空的', function () {
    [, $dev] = devDashOnboarded('dash_dl_');

    DB::table('skill_entitlements')->insert([
        'customer_id'   => 1,
        'order_id'      => 1,
        'order_item_id' => 990001,
        'product_id'    => 1,
        'seller_id'     => $dev->seller_id,
        'status'        => 'active',
        'meta'          => json_encode(['download_count' => 5]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('skill_entitlements')->insert([
        'customer_id'   => 1,
        'order_id'      => 1,
        'order_item_id' => 990002,
        'product_id'    => 1,
        'seller_id'     => $dev->seller_id,
        'status'        => 'active',
        'meta'          => json_encode(['download_count' => 4]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $m = app(OpsMetricsService::class)->forSeller($dev->seller_id);

    expect($m['downloads']['value'])->toBe(9)
        ->and($m['entitlements']['value'])->toBe(2);

    // 口径必须写明真实边界，否则会被读成"全部下载/全部安装"
    expect($m['downloads']['basis'])->toContain('鉴权下载')
        ->and($m['downloads']['basis'])->toContain('人工送达');
});

// ==================== 页面：顺序、语域、空态 ====================

it('未入驻时仍然看到入驻引导（新增数据块不能把这条挤掉）', function () {
    actingAs(devDashCustomer(), 'developer');

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeText('还没有开发者主体')
        ->assertSeeText('开始入驻');
});

it('顺序锁：边界说明与「暂不提供的数据」必须排在任何一个数字之前', function () {
    // 这条是整块的重点。一个开发者打开看板，真正的问题是「有多少人在用」；
    // 而平台恰恰没有曝光/安装统计。第一眼看到 0 会读成"我的技能没人用"。
    // 所以「先说清楚边界」→「暂不提供的数据」→「已统计的部分」这个顺序不能动。
    [, $dev] = devDashOnboarded();

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder([
            '先说清楚这份数据的边界',
            '暂不提供的数据',
            '已统计的部分',
            // 第一个真实数字
            '你名下的技能商品数',
        ]);
});

it('口径用开发者语域 —— 不得出现平台运维行话', function () {
    [, $dev] = devDashOnboarded();

    $html = get(route('shop.dev.dashboard'))->assertOk()->getContent();

    foreach (['skill_search_index', 'out_trade_no', 'invoices', 'materialized', 'E1'] as $jargon) {
        expect($html)->not->toContain($jargon);
    }

    // ⚠ blade 的 `{{ }}` **不解析 markdown** —— 正文里写 `**加粗**` 会原样显示成带星号的文字。
    //   实测在本页面上踩过（模板层，不是服务层）。这类缺陷不报错、不崩页，只是难看且像 bug。
    expect($html)->not->toContain('**');

    // 正面：要讲清"这一项我们给不了、为什么"
    expect($html)->toContain('安装')
        ->and($html)->toContain('不是');
});

it('「暂不提供的数据」每条都有名字与理由，理由里不许出现 markdown 星号', function () {
    $gaps = app(OpsMetricsService::class)->notAvailableForSeller();

    expect($gaps)->not->toBe([]);

    foreach ($gaps as $gap) {
        expect($gap)->toHaveKeys(['name', 'reason'])
            ->and($gap['name'])->not->toBe('')
            ->and($gap['reason'])->not->toBe('')
            // `{{ }}` 不解析 markdown，星号会原样显示在页面上（实测踩过）
            ->and($gap['reason'])->not->toContain('**');
    }

    // 开发者最想知道、而平台偏偏给不了的那几项，必须在里面 ——
    // 少写一项，读者就会以为"平台有这一项、只是这里是 0"。
    $names = collect($gaps)->pluck('name')->implode(' | ');

    expect($names)->toContain('安装')
        ->and($names)->toContain('收入')
        ->and($names)->toContain('曝光');
});

it('主体缺归属：一条数据都不展示，更不许回落到平台数字', function () {    [, $dev] = devDashOnboarded();

    // 模拟一个 seller_id 为空的主体（列可空，人工造数/数据修复都可能产生）
    $dev->update(['seller_id' => null]);

    get(route('shop.dev.dashboard'))
        ->assertOk()
        ->assertSeeText('主体未就绪')
        // 任何一个指标口径都不许出现
        ->assertDontSeeText('你名下的技能商品数')
        ->assertDontSeeText('你的技能被下载的次数');
});

it('平台口径的维度不出现在开发者页上（它们不是"不适用"，是压根不在这个列表里）', function () {
    [, $dev] = devDashOnboarded();

    $metrics = app(OpsMetricsService::class)->forSeller($dev->seller_id);

    // 设备属于客户、内容安全/工单/事故属于平台 —— 没有直挂 seller 列，
    // 所以不该出现在开发者的指标表里（不是标成"不适用"，是根本不出现）
    foreach (['devices', 'agent_online', 'content_block', 'tickets_open', 'incidents_open', 'filings'] as $platformOnly) {
        expect($metrics)->not->toHaveKey($platformOnly);
    }
});
