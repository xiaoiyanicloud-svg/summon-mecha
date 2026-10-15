<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\RiskAcknowledgment;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * B-5 · L3 风险告知留痕（计划 §4.14「用户分级告知留痕」/ D6 三层责任模型）。
 *
 * 勘察结论（2026-10-14）：`risk_acknowledgment` 表**已建但全仓零引用** ——
 * 有表、没模型、没接口、没前端弹窗，`/api/risk/acknowledge` 不存在。
 * 本文件取证的正是"告知 + 留痕"这半条，以及它最容易做假的三处：
 *   ① 分档判据是 `manifest.safety.risk_level`（low/medium/high），不是机型兼容分级；
 *   ② high 风险**必须逐条勾选**才放行 —— 不是"点个确定"；
 *   ③ 落库留的是**当时那份文案的指纹**（disclosure_hash）+ IP/UA，不是"我们现在还这么说"。
 */

function b5Product(?string $riskLevel): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b5-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('skill_versions')->insert([
        'product_id'        => $productId,
        'version'           => '1.0.0',
        'status'            => 'published',
        'risk_level'        => $riskLevel,
        'delivery_channels' => json_encode(['agent']),
        'released_at'       => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    return $productId;
}

function b5Customer(): Customer
{
    return Customer::create([
        'first_name'  => '告知',
        'last_name'   => '客户',
        'email'       => 'b5'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

// ==================== ① 分档告知 ====================

it('B-5 · high 风险 → 逐条勾选(require_checklist=true) + 3 条告知项', function () {
    $productId = b5Product('high');

    getJson(route('shop.api.risk.profile', $productId))
        ->assertOk()
        ->assertJsonPath('data.risk_level', 'high')
        ->assertJsonPath('data.label', '高风险')
        ->assertJsonPath('data.require_checklist', true)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.disclosure_hash', fn ($h) => is_string($h) && strlen($h) === 64);
});

it('B-5 · 告知对游客可见（买之前就要能看到）', function () {
    $productId = b5Product('high');

    // 没有 actingAs —— 仍应 200
    getJson(route('shop.api.risk.profile', $productId))->assertOk();
});

it('B-5 · low 风险 → 不强制勾选', function () {
    $productId = b5Product('low');

    getJson(route('shop.api.risk.profile', $productId))
        ->assertOk()
        ->assertJsonPath('data.risk_level', 'low')
        ->assertJsonPath('data.require_checklist', false);
});

it('B-5 · 未声明 risk_level → 如实标为「未分级」，不臆造成 low', function () {
    $productId = b5Product(null);

    getJson(route('shop.api.risk.profile', $productId))
        ->assertOk()
        ->assertJsonPath('data.risk_level', null)
        ->assertJsonPath('data.label', '未分级')
        ->assertJsonPath('data.require_checklist', false);
});

it('B-5 · 没有已发布版本 → 404 RISK_PROFILE_NOT_FOUND', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'b5-empty-'.uniqid(), 'type' => 'simple', 'created_at' => now(), 'updated_at' => now(),
    ]);

    getJson(route('shop.api.risk.profile', $productId))
        ->assertStatus(404)
        ->assertJsonPath('code', 'RISK_PROFILE_NOT_FOUND');
});

// ==================== ② 留痕 ====================

it('B-5 · high 风险缺勾选项 → 422 RISK_ACK_REQUIRED，不落库', function () {
    $customer = b5Customer();
    $productId = b5Product('high');

    actingAs($customer, 'customer');

    postJson(route('shop.api.risk.acknowledge'), [
        'product_id' => $productId,
        'checked'    => ['confirm_open_area'], // 只勾了一条
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'RISK_ACK_REQUIRED');

    expect(RiskAcknowledgment::query()->count())->toBe(0);
});

it('B-5 · high 风险逐条勾选 → 落一条留痕（含文案指纹 + IP）', function () {
    $customer = b5Customer();
    $productId = b5Product('high');

    actingAs($customer, 'customer');

    postJson(route('shop.api.risk.acknowledge'), [
        'product_id' => $productId,
        'channel'    => 'agent',
        'checked'    => ['confirm_open_area', 'confirm_estop', 'confirm_supervision'],
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.risk_level', 'high');

    $row = RiskAcknowledgment::query()->first();

    expect($row)->not->toBeNull('风险告知没有留痕')
        ->and($row->customer_id)->toBe($customer->id)
        ->and($row->skill_version_id)->not->toBeNull()
        ->and($row->channel)->toBe('agent')
        ->and($row->ack_payload['risk_level'])->toBe('high')
        ->and($row->ack_payload['checked'])->toHaveCount(3)
        ->and($row->ack_payload['disclosure_hash'])->toBeString()
        ->and($row->ip)->not->toBeNull();
});

it('B-5 · low 风险无需勾选也能留痕（告知照留，只是不阻塞）', function () {
    $customer = b5Customer();
    $productId = b5Product('low');

    actingAs($customer, 'customer');

    postJson(route('shop.api.risk.acknowledge'), ['product_id' => $productId])
        ->assertStatus(201);

    expect(RiskAcknowledgment::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('B-5 · 带了别人家的授权 → 404 ENTITLEMENT_NOT_FOUND（越权探测）', function () {
    $customer = b5Customer();
    $other = b5Customer();
    $productId = b5Product('low');

    $foreign = \Webkul\Shop\Models\SkillEntitlement::create([
        'customer_id'      => $other->id,
        'order_id'         => 992000 + random_int(1, 9999),
        'order_item_id'    => 992000 + random_int(1, 9999),
        'product_id'       => $productId,
        'status'           => 'active',
        'delivery_channel' => 'agent',
        'granted_at'       => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.risk.acknowledge'), [
        'product_id'     => $productId,
        'entitlement_id' => $foreign->id,
    ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'ENTITLEMENT_NOT_FOUND');
});
