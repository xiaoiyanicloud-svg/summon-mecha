<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * B-19 · 一机一码 `national_code` 补录入/校验。
 *
 * 缺口:`device_binding.national_code`(工信部一机一码)自建表就有列、也在 fillable 里,
 *   但**无接口、无录入、无校验** —— 它只是注释里的一句话,永远为 NULL。
 *
 * 本文件锁三件事:
 *   1. 登记时可录入,并**规范化**(统一大写);
 *   2. 形状校验(字母数字/连字符,8–40 位);
 *   3. 全局唯一 —— "一机一码"的本义:同一个码不能被两台设备占。
 *
 * ⚠ 诚实边界:平台**无法**向工信部核验码的真伪(没有那个外部通道)。校验通过只代表
 *   "形状对 + 没与别的设备重复",**不等于**"已核验"。界面文案别读成后者。
 */

function b19Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Nation',
        'last_name'   => 'B19',
        'email'       => 'b19'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('B-19 · 登记带一机一码 → 落真相源 binding,并统一大写', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), [
        'sn'            => 'B19-'.uniqid(),
        'national_code' => 'ab12cd34ef56',
    ])->assertStatus(201);

    $deviceId = $res->json('data.id');
    $binding = DeviceBinding::where('devices_id', $deviceId)->firstOrFail();

    expect($binding->national_code)->toBe('AB12CD34EF56')
        // 接口也要把它透出(否则录了也没人看得到)
        ->and($res->json('data.national_code'))->toBe('AB12CD34EF56');
});

it('B-19 · 不填一机一码仍可登记(可选,不拦主链路)', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), ['sn' => 'B19-'.uniqid()])->assertStatus(201);

    $binding = DeviceBinding::where('devices_id', $res->json('data.id'))->firstOrFail();

    expect($binding->national_code)->toBeNull();
});

it('B-19 · 格式非法的一机一码 → 422,且不建设备', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $sn = 'B19-BAD-'.uniqid();

    postJson(route('shop.api.devices.store'), [
        'sn'            => $sn,
        'national_code' => '有中文的码!!',
    ])->assertStatus(422);

    expect(Device::query()->where('sn', $sn)->exists())->toBeFalse();
});

it('B-19 · 同一个码不能被两台设备占用(全局唯一)', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $code = 'UNIQ'.strtoupper(substr(md5(uniqid()), 0, 8));

    postJson(route('shop.api.devices.store'), ['sn' => 'B19-A-'.uniqid(), 'national_code' => $code])
        ->assertStatus(201);

    $snB = 'B19-B-'.uniqid();

    postJson(route('shop.api.devices.store'), ['sn' => $snB, 'national_code' => $code])
        ->assertStatus(422);

    expect(Device::query()->where('sn', $snB)->exists())->toBeFalse();
});

it('B-19 · 大小写不同视为同一个码(标识符不该因大小写被当成两台机器)', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $code = 'MIXCASE'.strtoupper(substr(md5(uniqid()), 0, 8));

    postJson(route('shop.api.devices.store'), ['sn' => 'B19-C-'.uniqid(), 'national_code' => $code])
        ->assertStatus(201);

    postJson(route('shop.api.devices.store'), ['sn' => 'B19-D-'.uniqid(), 'national_code' => strtolower($code)])
        ->assertStatus(422);
});

it('B-19 · 已登记的设备可补录一机一码(update)', function () {
    $customer = b19Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.devices.store'), ['sn' => 'B19-FILL-'.uniqid()])->assertStatus(201);
    $deviceId = (int) $res->json('data.id');

    expect(DeviceBinding::where('devices_id', $deviceId)->value('national_code'))->toBeNull();

    $code = 'LATER'.strtoupper(substr(md5(uniqid()), 0, 8));

    putJson(route('shop.api.devices.update', $deviceId), ['national_code' => $code])->assertOk();

    expect(DeviceBinding::where('devices_id', $deviceId)->value('national_code'))->toBe($code);
});
