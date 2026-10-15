<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SupportTicket;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * A4 · skill_id 归属不校验（工单 + 事故）。
 *
 * 改前:`TicketController` 与 `IncidentController` 的 skill_id 只有 `nullable|integer`,
 * 无归属校验 —— 而同一控制器对 `device_id` **有** `DEVICE_NOT_OWNED` 校验。
 * 后果不止脏数据:事故证据链(`IncidentService::traceFor()`)会按该 product_id 固化
 * 版本与交付物 sha256 —— 可以把一次事故"挂"到与自己无关的技能版本上,污染定责依据。
 *
 * 改后:与 device_id 一致,校验 skill_id 在当前 customer 的 skill_entitlements 中存在。
 */

function a4Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Guard',
        'last_name'   => 'User',
        'email'       => 'a4'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一个技能商品(可选:给某客户授权)。返回 productId。
 */
function a4Skill(?Customer $owner = null): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'a4-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($owner) {
        DB::table('skill_entitlements')->insert([
            'customer_id'      => $owner->id,
            'order_id'         => 0,
            'order_item_id'    => 0,
            'product_id'       => $productId,
            'skill_version_id' => 0,
            'status'           => 'active',
            'license_key'      => substr(md5(uniqid()), 0, 32),
            'delivery_channel' => 'manual',
            'granted_at'       => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    return $productId;
}

it('A4 · 工单:skill_id 不在自己的授权里 → 422 SKILL_NOT_OWNED,且不落单', function () {
    $me = a4Customer();
    $other = a4Customer();

    $otherProduct = a4Skill($other);   // 他人购买

    actingAs($me, 'customer');

    postJson(route('shop.api.tickets.store'), [
        'content'  => '把别人的技能挂到我的工单上',
        'skill_id' => $otherProduct,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'SKILL_NOT_OWNED');

    expect(SupportTicket::where('customer_id', $me->id)->count())->toBe(0);
});

it('A4 · 工单:skill_id 是自己的 → 201,skill_id 落库', function () {
    $me = a4Customer();
    $mine = a4Skill($me);

    actingAs($me, 'customer');

    $res = postJson(route('shop.api.tickets.store'), [
        'content'  => '装不上',
        'skill_id' => $mine,
    ])->assertStatus(201);

    $ticket = SupportTicket::find($res->json('data.id'));

    expect($ticket->skill_id)->toBe($mine);
});

it('A4 · 报案:skill_id 不在自己的授权里 → 422 SKILL_NOT_OWNED,不固化证据链', function () {
    $me = a4Customer();
    $other = a4Customer();

    $otherProduct = a4Skill($other);

    actingAs($me, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_SEVERE,
        'description' => '把事故挂到别人买的技能上',
        'skill_id'    => $otherProduct,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'SKILL_NOT_OWNED');

    expect(Incident::where('customer_id', $me->id)->count())->toBe(0);
});

it('A4 · 报案:skill_id 是自己的 → 201', function () {
    $me = a4Customer();
    $mine = a4Skill($me);

    actingAs($me, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MODERATE,
        'description' => '手臂打到人',
        'skill_id'    => $mine,
    ])->assertStatus(201);

    expect(Incident::where('customer_id', $me->id)->where('skill_id', $mine)->count())->toBe(1);
});

it('A4 · 不传 skill_id 仍然照常开工单/报案(校验是可选的)', function () {
    $me = a4Customer();
    actingAs($me, 'customer');

    postJson(route('shop.api.tickets.store'), ['content' => '无技能工单'])->assertStatus(201);

    postJson(route('shop.api.incidents.store'), [
        'severity' => Incident::SEVERITY_MINOR, 'description' => '无技能报案',
    ])->assertStatus(201);
});
