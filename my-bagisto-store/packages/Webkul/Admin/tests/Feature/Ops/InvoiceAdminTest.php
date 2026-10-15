<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Services\Ops\OpsMetricsService;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * M9 · 发票在后台可见；其余三项（结算/提现/对账）在平台上标「未接入」而不是 0。
 *
 * 计划 §一 M9 的验收原话：「发票：下单支付后能生成一条 `invoice` 记录并在后台可见；
 * 其余三项：断言平台看板对它们显示「未接入」」。
 */

it('开票台账页可以渲染（菜单 / ACL / 路由都接上了）', function () {
    $this->loginAsAdmin();

    get(route('admin.invoices.index'))
        ->assertOk()
        ->assertSeeText('开票登记');
});

it('开票台账能列出支付回调生成的那条记录', function () {
    $title = '开票测试抬头'.uniqid();

    DB::table('invoice')->insert([
        'customer_id' => 1,
        'order_ids'   => json_encode([424242]),
        'type'        => 'personal',
        'title'       => $title,
        'tax_no'      => null,
        'amount'      => 199,
        'status'      => 'applied',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $this->loginAsAdmin();

    getJson(route('admin.invoices.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonPath('records.0.title', $title)
        ->assertJsonPath('records.0.id', DB::table('invoice')->where('title', $title)->value('id'));
});

it('发票是真实指标（M9 起真的有人写），不能再被说成"未接入"', function () {
    $trade = app(OpsMetricsService::class)->trade();

    expect($trade)->toHaveKey('invoices')
        ->and($trade['invoices']['ready'])->toBeTrue()
        ->and($trade['invoices']['value'])->toBeInt()
        // 口径必须点明"真实开具未接入"，否则这个数字会被读成"票都开好了"
        ->and($trade['invoices']['basis'])->toContain('真实开具');
});

it('结算 / 提现 / 对账：平台看板标「未接入」，而不是渲染成 0', function () {
    $service = app(OpsMetricsService::class);

    $names = collect($service->notConnected())->pluck('name')->implode(' | ');

    expect($names)->toContain('结算')
        ->and($names)->toContain('提现')
        ->and($names)->toContain('对账');

    // 关键：这三项**不许以指标的形式出现** —— 一旦是"指标"，页面就会把它渲染成一个 0，
    // 而"结算金额 0"与"结算根本没做"对决策的含义完全相反（见 OpsMetricsService 类注释）
    $keys = collect([
        $service->trade(), $service->supply(), $service->review(), $service->devices(),
        $service->entitlements(), $service->service(), $service->developers(), $service->compliance(),
    ])->flatMap(fn ($group) => array_keys($group));

    expect($keys->all())->not->toContain('settlement')
        ->and($keys->all())->not->toContain('withdrawal')
        ->and($keys->all())->not->toContain('reconciliation')
        // 而发票**是**指标（做完了的东西要显示出来，不是藏进"未接入"清单里）
        ->and($keys->all())->toContain('invoices');

    // 列出来而不是藏起来：页面上真的能看到这三行
    $this->loginAsAdmin();

    get(route('admin.dashboard.ops'))
        ->assertOk()
        ->assertSeeText('结算')
        ->assertSeeText('提现')
        ->assertSeeText('对账');
});

it('开票台账只读 —— 没有「标记为已开具」这类写入动作', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.invoices.'));

    // 手工点一下就能造出"已开票"记录，是没有税务依据的假账（见 InvoiceController 注释）。
    // 断言"没有任何写方法"，而不是逐个列方法名 —— 后者会把 HEAD（GET 的伴随方法）当成差异。
    $writeMethods = $routes->flatMap(fn ($route) => $route->methods())
        ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
        ->unique()
        ->values();

    expect($routes)->toHaveCount(1)
        ->and($writeMethods->all())->toBe([]);
});
