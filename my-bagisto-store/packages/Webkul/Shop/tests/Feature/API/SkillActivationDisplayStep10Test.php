<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('Step 10 tests require the dedicated summon_mecha_step10_test database.');
    }
});

/** Real purchase records; the version limit intentionally differs from the entitlement. */
function step10ActivationFixture(int $used = 0, ?int $limit = 3): array
{
    $token = bin2hex(random_bytes(8));
    $customer = Customer::create([
        'first_name' => 'Step10', 'last_name' => 'Activation',
        'email'      => 'step10-activation-'.$token.'@example.com',
        'password'   => bcrypt('step10-isolated-test'),
        'channel_id' => core()->getDefaultChannel()->id,
        'status'     => 1, 'is_verified' => 1,
    ]);
    $familyId = DB::table('attribute_families')->value('id');
    if (! $familyId) {
        throw new LogicException('The isolated test database must contain an attribute family.');
    }
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'S10-A-'.$token, 'type' => 'simple', 'attribute_family_id' => $familyId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $version = SkillVersion::create([
        'product_id'        => $productId, 'version' => '1.0.0',
        'status'            => SkillVersion::STATUS_PUBLISHED,
        'delivery_channels' => ['app'], 'max_activations' => 99,
        'released_at'       => now(),
    ]);
    $device = Device::create([
        'customer_id' => $customer->id, 'sn' => 'S10-A-'.$token,
        'bind_status' => Device::STATUS_BOUND, 'bound_at' => now(),
    ]);
    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'S10A'.$token, 'customer_id' => $customer->id,
        'status'       => 'processing', 'grand_total' => 100,
        'created_at'   => now(), 'updated_at' => now(),
    ]);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id'    => $orderId, 'product_id' => $productId, 'type' => 'simple',
        'name'        => 'Step 10 activation package', 'sku' => 'S10-AI-'.$token,
        'qty_ordered' => 1, 'price' => 100, 'total' => 100,
        'created_at'  => now(), 'updated_at' => now(),
    ]);
    $entitlement = SkillEntitlement::create([
        'customer_id' => $customer->id, 'order_id' => $orderId, 'order_item_id' => $itemId,
        'product_id'  => $productId, 'skill_version_id' => $version->id,
        'device_id'   => $device->id, 'delivery_channel' => 'app',
        'status'      => SkillEntitlement::STATUS_GRANTED, 'license_key' => SkillEntitlement::newLicenseKey(),
        'activations' => $used, 'max_activations' => $limit, 'granted_at' => now(),
        'meta'        => ['delivery' => ['ok' => true], 'download_count' => 7],
    ]);

    return compact('customer', 'version', 'device', 'entitlement');
}

it('Step 10 lists activation usage from the entitlement and clamps finite remaining usage', function ($used, $limit, $remaining) {
    $fixture = step10ActivationFixture($used, $limit);
    actingAs($fixture['customer'], 'customer');

    getJson(route('shop.api.skills.mine'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $fixture['entitlement']->id)
        ->assertJsonPath('data.0.activations', $used)
        ->assertJsonPath('data.0.max_activations', $limit)
        ->assertJsonPath('data.0.remaining_activations', $remaining);
})->with([
    'finite'             => [2, 5, 3],
    'unlimited'          => [4, null, null],
    'zero allowance'     => [0, 0, 0],
    'exhausted'          => [5, 5, 0],
    'historical overuse' => [6, 5, 0],
]);

it('Step 10 keeps other customers activation records out of the mine response', function () {
    $owned = step10ActivationFixture(1, 4);
    step10ActivationFixture(8, 10);
    actingAs($owned['customer'], 'customer');

    getJson(route('shop.api.skills.mine'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $owned['entitlement']->id)
        ->assertJsonPath('data.0.activations', 1)
        ->assertJsonPath('data.0.max_activations', 4)
        ->assertJsonPath('data.0.remaining_activations', 3);
});

it('Step 10 refreshes activation usage after install and preserves unlimited semantics', function ($limit, $remaining) {
    $fixture = step10ActivationFixture(0, $limit);
    actingAs($fixture['customer'], 'customer');

    postJson(route('shop.api.skills.confirm-install', $fixture['entitlement']->id))
        ->assertOk()->assertJsonPath('data.activations', 1);
    getJson(route('shop.api.skills.mine'))->assertOk()
        ->assertJsonPath('data.0.activations', 1)
        ->assertJsonPath('data.0.max_activations', $limit)
        ->assertJsonPath('data.0.remaining_activations', $remaining);
    expect($fixture['entitlement']->fresh()->meta['download_count'])->toBe(7);
})->with(['finite' => [2, 1], 'unlimited' => [null, null]]);

it('Step 10 enforces the displayed limit and leaves active confirmation idempotent', function () {
    $fixture = step10ActivationFixture(1, 1);
    $entitlement = $fixture['entitlement'];
    actingAs($fixture['customer'], 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertStatus(409)->assertJsonPath('code', ApiErrorCode::MAX_ACTIVATIONS_REACHED);
    expect($entitlement->fresh()->activations)->toBe(1)
        ->and($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_GRANTED);

    $entitlement->update(['status' => SkillEntitlement::STATUS_ACTIVE]);
    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertOk()->assertJsonPath('data.idempotent', true)->assertJsonPath('data.activations', 1);
    getJson(route('shop.api.skills.mine'))->assertOk()
        ->assertJsonPath('data.0.activations', 1)->assertJsonPath('data.0.remaining_activations', 0);
});
