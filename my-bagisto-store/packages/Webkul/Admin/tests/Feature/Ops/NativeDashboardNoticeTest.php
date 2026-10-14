<?php

use function Pest\Laravel\get;

/**
 * 原生 Dashboard 的口径说明（本项目新增）。
 *
 * 为什么这条也值得测：本页 5 个数字全部来自 Bagisto 原生报表，而原生报表的
 * 「总销售额」算的是 `已开票金额 − 已退款`（见 `Admin/Helpers/Reporting/Sale.php:169`），
 * **不是收到的钱**。当前库里 0 张发票、25 张订单（20 张未付），于是页面上是
 * 「总销售额 ¥0.00」和「未付发票 ¥0.00」并排 —— 数字没错，含义全错：
 * 读的人会理解成"没有生意"，而事实是"从未开过发票"。
 *
 * 数字对、含义错，比没有数字更危险。所以必须有说明，且说明必须**在数字之前**。
 */

it('原生 Dashboard 可以渲染，并带口径说明', function () {
    $this->loginAsAdmin();

    get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertSeeText('本页数字按 Bagisto 原生报表口径计算');
});

it('口径说明写在数字之前（读数字之前先读到口径）', function () {
    $this->loginAsAdmin();

    $html = get(route('admin.dashboard.index'))->assertOk()->getContent();

    $noticePos = strpos($html, 'Bagisto 原生报表口径');
    $statsPos  = strpos($html, 'v-dashboard-overall-details');

    expect($noticePos)->not->toBeFalse()
        ->and($statsPos)->not->toBeFalse()
        // 说明在统计卡片之后出现等于没写：误解已经形成了
        ->and($noticePos)->toBeLessThan($statsPos);
});

it('口径说明讲清「¥0 不等于没有订单」——这是最容易误读的一点', function () {
    $this->loginAsAdmin();

    get(route('admin.dashboard.index'))
        ->assertOk()
        // 必须点明"已开票口径"与"未付款不计入"
        ->assertSeeText('已开票金额')
        ->assertSeeText('未付款、未开票的订单不计入')
        ->assertSeeText('不等于「没有订单」');
});

it('口径说明指向运营总览（平台真实口径在那边）', function () {
    $this->loginAsAdmin();

    $html = get(route('admin.dashboard.index'))->assertOk()->getContent();

    expect($html)->toContain(route('admin.dashboard.ops'))
        ->and($html)->toContain('运营总览');
});
