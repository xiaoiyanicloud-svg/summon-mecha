<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\SkillDelivery\AppChannel;
use Webkul\Shop\Services\SkillDelivery\DeliveryChannel;
use Webkul\Shop\Services\SkillDelivery\DeliveryResult;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * Step 10 B2: exercise the real order listener and database with isolated,
 * deterministic channel failures. These are sequential regression tests;
 * they do not claim to prove multi-process locking or afterCommit behavior.
 *
 * Run only through work/Invoke-Step10Tests.ps1, against the dedicated test DB.
 * Fixtures in this file do not depend on another test file's helper functions.
 */
class Step10DeliveryProbeChannel implements DeliveryChannel
{
    /** @var array<int, int> Order item IDs, in invocation order. */
    public array $calls = [];

    public function __construct(private Closure $behavior) {}

    public function key(): string
    {
        return 'app';
    }

    public function label(): string
    {
        return 'Step 10 isolated app probe';
    }

    public function canDeliver(SkillEntitlement $entitlement): bool
    {
        return $entitlement->isUsable();
    }

    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult
    {
        $this->calls[] = (int) $entitlement->order_item_id;

        return ($this->behavior)($entitlement, $context);
    }

    public function revoke(SkillEntitlement $entitlement): void
    {
        (new AppChannel)->revoke($entitlement);
    }
}

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('Step 10 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['skill_delivery.default' => 'app']);
});

function step10DeliveryToken(): string
{
    return bin2hex(random_bytes(8));
}

function step10DeliveryCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Step10',
        'last_name'   => 'Delivery',
        'email'       => 'step10-delivery-'.step10DeliveryToken().'@example.com',
        'password'    => bcrypt('step10-isolated-test'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function step10DeliveryDevice(Customer $customer): Device
{
    return Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'S10-'.step10DeliveryToken(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
}

function step10DeliveryProduct(): int
{
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId([
            'status'     => 1,
            '_lft'       => 1,
            '_rgt'       => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'locale_id'   => DB::table('locales')->value('id') ?: 1,
            'locale'      => 'en',
            'name'        => 'Step 10 skill',
            'slug'        => 'skill',
        ]);
    }

    $productId = DB::table('products')->insertGetId([
        'sku'                 => 'S10-'.step10DeliveryToken(),
        'type'                => 'simple',
        'attribute_family_id' => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $productId,
        'category_id' => $categoryId,
    ]);

    return $productId;
}

function step10DeliveryOrder(Customer $customer, string $status = 'pending'): object
{
    $id = DB::table('orders')->insertGetId([
        'increment_id' => 'S10'.step10DeliveryToken(),
        'customer_id'  => $customer->id,
        'status'       => $status,
        'grand_total'  => 200,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return OrderProxy::modelClass()::findOrFail($id);
}

function step10DeliveryItem(object $order, int $productId, ?Device $device = null): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'    => $order->id,
        'product_id'  => $productId,
        'type'        => 'simple',
        'name'        => 'Step 10 skill package',
        'sku'         => 'S10-I-'.step10DeliveryToken(),
        'qty_ordered' => 1,
        'price'       => 100,
        'total'       => 100,
        'additional'  => json_encode(['skill' => ['device_id' => $device?->id]], JSON_THROW_ON_ERROR),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function step10DeliveryEntitlement(object $order, int $itemId, ?Device $device = null, array $overrides = []): SkillEntitlement
{
    $item = DB::table('order_items')->find($itemId);

    return SkillEntitlement::create(array_merge([
        'customer_id'      => $order->customer_id,
        'order_id'         => $order->id,
        'order_item_id'    => $itemId,
        'product_id'       => $item->product_id,
        'device_id'        => $device?->id,
        'delivery_channel' => 'app',
        'status'           => SkillEntitlement::STATUS_PENDING_BIND,
        'license_key'      => SkillEntitlement::newLicenseKey(),
    ], $overrides));
}

function step10DeliveryProbe(Closure $behavior): Step10DeliveryProbeChannel
{
    $probe = new Step10DeliveryProbeChannel($behavior);
    app()->instance(Step10DeliveryProbeChannel::class, $probe);
    config(['skill_delivery.channels.app.class' => Step10DeliveryProbeChannel::class]);
    app()->forgetInstance(SkillDeliveryManager::class);
    app()->forgetInstance(SkillDelivery::class);

    return $probe;
}

it('Step 10 keeps authorization creation on an unpaid order and treats waiting for a device as normal', function () {
    $order = step10DeliveryOrder(step10DeliveryCustomer());
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());

    app(SkillDelivery::class)->afterOrderCreated($order);

    $entitlement = SkillEntitlement::where('order_item_id', $itemId)->sole();

    expect($order->status)->toBe('pending')
        ->and($entitlement->status)->toBe(SkillEntitlement::STATUS_PENDING_BIND)
        ->and($entitlement->granted_at)->not->toBeNull()
        ->and($entitlement->license_key)->not->toBeEmpty()
        ->and($entitlement->device_id)->toBeNull()
        ->and($entitlement->meta['delivery']['ok'] ?? null)->toBeTrue()
        ->and($entitlement->meta['delivery_failed'] ?? null)->toBeNull();
});

it('Step 10 isolates a first item channel exception and still delivers the second skill', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $firstId = step10DeliveryItem($order, step10DeliveryProduct(), $device);
    $secondId = step10DeliveryItem($order, step10DeliveryProduct(), $device);
    $probe = step10DeliveryProbe(function ($entitlement, $context) use ($firstId) {
        if ((int) $entitlement->order_item_id === $firstId) {
            throw new RuntimeException('Step 10 deterministic first-item transport failure');
        }

        return (new AppChannel)->deliver($entitlement, $context);
    });

    app(SkillDelivery::class)->afterOrderCreated($order);

    $first = SkillEntitlement::where('order_item_id', $firstId)->sole();
    $second = SkillEntitlement::where('order_item_id', $secondId)->sole();

    expect($probe->calls)->toBe([$firstId, $secondId])
        ->and($first->meta['delivery_failed']['class'] ?? null)->toBe(RuntimeException::class)
        ->and($first->granted_at)->toBeNull()
        ->and($second->status)->toBe(SkillEntitlement::STATUS_GRANTED)
        ->and($second->granted_at)->not->toBeNull()
        ->and($second->meta['delivery']['ok'] ?? null)->toBeTrue()
        ->and(SkillEntitlement::where('order_id', $order->id)->count())->toBe(2);
});

it('Step 10 isolates a failure before authorization creation instead of abandoning later items', function () {
    $customer = step10DeliveryCustomer();
    $order = step10DeliveryOrder($customer);
    $firstProductId = step10DeliveryProduct();
    $secondProductId = step10DeliveryProduct();
    $firstId = step10DeliveryItem($order, $firstProductId);
    $secondId = step10DeliveryItem($order, $secondProductId);
    $gate = Mockery::mock(KillSwitchService::class);
    $gate->shouldReceive('isBlockedFor')->with($firstProductId, 'sale')
        ->andThrow(new RuntimeException('Step 10 pre-grant lookup failure'));
    $gate->shouldReceive('isBlockedFor')->with($secondProductId, 'sale')->andReturnFalse();
    app()->instance(KillSwitchService::class, $gate);

    app(SkillDelivery::class)->afterOrderCreated($order);

    expect(SkillEntitlement::where('order_item_id', $firstId)->exists())->toBeFalse()
        ->and(SkillEntitlement::where('order_item_id', $secondId)->count())->toBe(1)
        ->and(SkillEntitlement::where('order_item_id', $secondId)->sole()->meta['delivery']['ok'] ?? null)->toBeTrue();
});

it('Step 10 records an explicit false delivery result as failure without marking delivery complete', function () {
    $order = step10DeliveryOrder(step10DeliveryCustomer());
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $probe = step10DeliveryProbe(fn () => DeliveryResult::fail('Step 10 deliberate business rejection'));

    app(SkillDelivery::class)->afterOrderCreated($order);

    $entitlement = SkillEntitlement::where('order_item_id', $itemId)->sole();

    expect($probe->calls)->toBe([$itemId])
        ->and($entitlement->meta['delivery']['ok'] ?? null)->toBeFalse()
        ->and($entitlement->meta['delivery_failed'] ?? null)->toBeArray()->not->toBeEmpty()
        ->and($entitlement->status)->toBe(SkillEntitlement::STATUS_PENDING_BIND)
        ->and($entitlement->granted_at)->toBeNull();
});

it('Step 10 does not turn a duplicate order event into another initial attempt or erase its prior failure', function () {
    $order = step10DeliveryOrder(step10DeliveryCustomer());
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $probe = step10DeliveryProbe(function ($entitlement, $context) {
        static $attempt = 0;

        if (++$attempt === 1) {
            throw new RuntimeException('Step 10 persisted technical failure');
        }

        return (new AppChannel)->deliver($entitlement, $context);
    });
    $listener = app(SkillDelivery::class);
    $listener->afterOrderCreated($order);
    $failed = SkillEntitlement::where('order_item_id', $itemId)->sole();
    $failure = $failed->meta['delivery_failed'] ?? null;
    $license = $failed->license_key;

    expect($failure)->toBeArray()->not->toBeEmpty();

    $listener->afterOrderCreated($order->fresh());
    $again = $failed->fresh();

    expect($probe->calls)->toBe([$itemId])
        ->and($again->meta['delivery_failed'] ?? null)->toBe($failure)
        ->and($again->granted_at)->toBeNull()
        ->and($again->license_key)->toBe($license)
        ->and(SkillEntitlement::where('order_item_id', $itemId)->count())->toBe(1);
});

it('Step 10 skips already delivered items and preserves current purchased rights on duplicate order events', function (string $status) {
    $customer = step10DeliveryCustomer();
    $oldDevice = step10DeliveryDevice($customer);
    $currentDevice = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $productId = step10DeliveryProduct();
    $itemId = step10DeliveryItem($order, $productId, $oldDevice);
    $version = SkillVersion::create([
        'product_id'  => $productId,
        'version'     => '1.0.0',
        'status'      => SkillVersion::STATUS_PUBLISHED,
        'released_at' => now()->subDays(2),
    ]);
    $entitlement = step10DeliveryEntitlement($order, $itemId, $currentDevice, [
        'status'           => $status,
        'skill_version_id' => $version->id,
        'granted_at'       => now()->subDay(),
        'expires_at'       => now()->addDays(20),
        'activations'      => 2,
        'max_activations'  => 5,
        'meta'             => ['download_count' => 4, 'delivery' => ['ok' => true]],
    ]);
    $before = $entitlement->fresh()->only([
        'status', 'device_id', 'skill_version_id', 'license_key',
        'activations', 'max_activations', 'meta',
    ]);
    $grantedAt = $entitlement->granted_at->toDateTimeString();
    $expiresAt = $entitlement->expires_at->toDateTimeString();
    $probe = step10DeliveryProbe(function () {
        throw new LogicException('A delivered item must not be delivered again.');
    });

    app(SkillDelivery::class)->afterOrderCreated($order);
    $after = $entitlement->fresh();

    expect($probe->calls)->toBe([])
        ->and($after->only(array_keys($before)))->toBe($before)
        ->and($after->granted_at->toDateTimeString())->toBe($grantedAt)
        ->and($after->expires_at->toDateTimeString())->toBe($expiresAt)
        ->and(SkillEntitlement::where('order_item_id', $itemId)->count())->toBe(1);
})->with([SkillEntitlement::STATUS_GRANTED, SkillEntitlement::STATUS_ACTIVE]);

it('Step 10 app channel itself does not overwrite a delivered active entitlement with an old context device', function () {
    $customer = step10DeliveryCustomer();
    $oldDevice = step10DeliveryDevice($customer);
    $currentDevice = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct(), $oldDevice);
    $entitlement = step10DeliveryEntitlement($order, $itemId, $currentDevice, [
        'status'          => SkillEntitlement::STATUS_ACTIVE,
        'granted_at'      => now()->subDay(),
        'activations'     => 2,
        'max_activations' => 4,
        'meta'            => ['download_count' => 8, 'installed_by' => 'user_reported'],
    ]);
    $before = $entitlement->fresh()->only(['status', 'device_id', 'license_key', 'activations', 'max_activations', 'meta']);
    $grantedAt = $entitlement->granted_at->toDateTimeString();

    $result = (new AppChannel)->deliver($entitlement, ['device_id' => $oldDevice->id]);
    $after = $entitlement->fresh();

    expect($result->ok)->toBeTrue()
        ->and($after->only(array_keys($before)))->toBe($before)
        ->and($after->granted_at->toDateTimeString())->toBe($grantedAt);
});

it('Step 10 preserves unrelated metadata written while the channel runs instead of replacing it from an old snapshot', function () {
    $order = step10DeliveryOrder(step10DeliveryCustomer());
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    step10DeliveryProbe(function ($entitlement, $context) {
        $current = $entitlement->fresh();
        $meta = is_array($current->meta) ? $current->meta : [];
        $meta['download_count'] = 7;
        $meta['unrelated_diagnostic'] = 'preserve-me';
        $current->meta = $meta;
        $current->save();

        return (new AppChannel)->deliver($entitlement, $context);
    });

    app(SkillDelivery::class)->afterOrderCreated($order);

    $entitlement = SkillEntitlement::where('order_item_id', $itemId)->sole();

    expect($entitlement->meta['download_count'] ?? null)->toBe(7)
        ->and($entitlement->meta['unrelated_diagnostic'] ?? null)->toBe('preserve-me')
        ->and($entitlement->meta['delivery']['ok'] ?? null)->toBeTrue();
});

it('Step 10 leaves an explicit failure for an unregistered channel without claiming successful delivery', function () {
    $order = step10DeliveryOrder(step10DeliveryCustomer());
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    config(['skill_delivery.default' => 'step10-unregistered']);

    app(SkillDelivery::class)->afterOrderCreated($order);

    $entitlement = SkillEntitlement::where('order_item_id', $itemId)->sole();
    $failure = $entitlement->meta['delivery_failed'] ?? null;

    expect($entitlement->delivery_channel)->toBe('step10-unregistered')
        ->and($entitlement->status)->toBe(SkillEntitlement::STATUS_PENDING_BIND)
        ->and($entitlement->granted_at)->toBeNull()
        ->and($failure)->toBeArray()->not->toBeEmpty()
        ->and($failure['class'] ?? null)->toBe(InvalidArgumentException::class)
        ->and($failure['channel'] ?? null)->toBe('step10-unregistered')
        ->and($failure['message'] ?? '')->not->toBeEmpty();
});

function step10DeliveryFailureMeta(): array
{
    return [
        'download_count'       => 6,
        'unrelated_diagnostic' => 'keep-existing-meta',
        'delivery'             => ['ok' => false, 'code' => 'TECHNICAL_EXCEPTION'],
        'delivery_failed'      => ['class' => RuntimeException::class, 'message' => 'previous diagnostic', 'at' => '2026-10-17 12:00:00'],
        'delivery_retry'       => ['cycle_id' => 'step10-existing-cycle', 'state' => 'exhausted', 'attempts' => 3, 'reason_code' => 'RETRY_EXHAUSTED'],
    ];
}

it('Step 10 binds an entitlement with an exhausted failure cycle without attempting delivery or replacing its budget', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $meta = step10DeliveryFailureMeta();
    $entitlement = step10DeliveryEntitlement($order, $itemId, null, ['meta' => $meta]);
    $probe = step10DeliveryProbe(function () {
        throw new LogicException('Binding must not restart an exhausted failure cycle.');
    });
    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()->assertJsonPath('success', false)->assertJsonPath('code', ApiErrorCode::SKILL_DELIVERY_FAILED)
        ->assertJsonPath('data.delivery.ok', false);

    $after = $entitlement->fresh();
    expect($probe->calls)->toBe([])
        ->and((int) $after->device_id)->toBe($device->id)
        ->and($after->status)->toBe(SkillEntitlement::STATUS_PENDING_BIND)
        ->and($after->granted_at)->toBeNull()
        ->and($after->meta['delivery_retry'])->toEqual($meta['delivery_retry'])
        ->and($after->meta['delivery_failed'])->toEqual($meta['delivery_failed'])
        ->and($after->meta['download_count'])->toBe(6)
        ->and($after->meta['unrelated_diagnostic'])->toBe('keep-existing-meta');
});

it('Step 10 reports a false channel result after binding as delivery failure while preserving the binding', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $entitlement = step10DeliveryEntitlement($order, $itemId);
    $probe = step10DeliveryProbe(fn () => DeliveryResult::fail('Step 10 binding business rejection'));
    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()->assertJsonPath('success', false)->assertJsonPath('code', ApiErrorCode::SKILL_DELIVERY_FAILED)
        ->assertJsonPath('data.delivery.ok', false);

    $after = $entitlement->fresh();
    expect($probe->calls)->toBe([$itemId])
        ->and((int) $after->device_id)->toBe($device->id)
        ->and($after->granted_at)->toBeNull()
        ->and($after->meta['delivery']['ok'] ?? null)->toBeFalse();
});

it('Step 10 records unknown-channel binding failures without dropping unrelated metadata', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $entitlement = step10DeliveryEntitlement($order, $itemId, null, [
        'delivery_channel' => 'step10-unknown-bind',
        'meta'             => ['download_count' => 5, 'unrelated_diagnostic' => 'retain-this'],
    ]);
    actingAs($customer, 'customer');

    putJson(route('shop.api.skills.bind', $entitlement->id), ['device_id' => $device->id])
        ->assertOk()->assertJsonPath('success', false)->assertJsonPath('code', ApiErrorCode::SKILL_DELIVERY_FAILED);

    $after = $entitlement->fresh();
    expect((int) $after->device_id)->toBe($device->id)
        ->and($after->meta['download_count'])->toBe(5)
        ->and($after->meta['unrelated_diagnostic'])->toBe('retain-this')
        ->and($after->meta['delivery_failed']['class'] ?? null)->toBe(InvalidArgumentException::class)
        ->and($after->meta['delivery_failed']['channel'] ?? null)->toBe('step10-unknown-bind');
});

it('Step 10 device registration may claim a failed entitlement but cannot restart its failure cycle', function () {
    $customer = step10DeliveryCustomer();
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct());
    $meta = step10DeliveryFailureMeta();
    $entitlement = step10DeliveryEntitlement($order, $itemId, null, ['meta' => $meta]);
    $probe = step10DeliveryProbe(function () {
        throw new LogicException('Device registration must not reset retry exhaustion.');
    });
    actingAs($customer, 'customer');

    $response = postJson(route('shop.api.devices.store'), ['sn' => 'S10-REGISTER-'.step10DeliveryToken()])
        ->assertCreated()->assertJsonPath('claim.claimed', 0)->assertJsonPath('claim.failed', 1);

    $after = $entitlement->fresh();
    expect($probe->calls)->toBe([])
        ->and((int) $after->device_id)->toBe((int) $response->json('data.id'))
        ->and($after->meta['delivery_retry'])->toEqual($meta['delivery_retry'])
        ->and($after->meta['delivery_failed'])->toEqual($meta['delivery_failed'])
        ->and($after->meta['download_count'])->toBe(6)
        ->and($after->granted_at)->toBeNull();
});

it('Step 10 device registration skips an expired pending entitlement while claiming another usable item', function () {
    $customer = step10DeliveryCustomer();
    $order = step10DeliveryOrder($customer);
    $expiredItemId = step10DeliveryItem($order, step10DeliveryProduct());
    $usableItemId = step10DeliveryItem($order, step10DeliveryProduct());
    $expired = step10DeliveryEntitlement($order, $expiredItemId, null, ['expires_at' => now()->subMinute()]);
    $usable = step10DeliveryEntitlement($order, $usableItemId);
    actingAs($customer, 'customer');

    $response = postJson(route('shop.api.devices.store'), ['sn' => 'S10-REGISTER-'.step10DeliveryToken()])
        ->assertCreated()->assertJsonPath('claim.claimed', 1)->assertJsonPath('claim.failed', 0);

    expect($expired->fresh()->device_id)->toBeNull()
        ->and($expired->fresh()->granted_at)->toBeNull()
        ->and((int) $usable->fresh()->device_id)->toBe((int) $response->json('data.id'))
        ->and($usable->fresh()->status)->toBe(SkillEntitlement::STATUS_GRANTED);
});

it('Step 10 installation confirmation cannot turn an unresolved delivery failure into an active entitlement', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct(), $device);
    $meta = step10DeliveryFailureMeta();
    $entitlement = step10DeliveryEntitlement($order, $itemId, $device, ['meta' => $meta]);
    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertStatus(409)->assertJsonPath('code', ApiErrorCode::SKILL_DELIVERY_FAILED);

    $after = $entitlement->fresh();
    expect($after->status)->toBe(SkillEntitlement::STATUS_PENDING_BIND)
        ->and($after->activations)->toBe(0)
        ->and($after->meta)->toEqual($meta)
        ->and(InstallRecord::where('entitlement_id', $entitlement->id)->exists())->toBeFalse();
});

it('Step 10 installation confirmation preserves other metadata and stays idempotent after recording installation', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $productId = step10DeliveryProduct();
    $itemId = step10DeliveryItem($order, $productId, $device);
    $version = SkillVersion::create(['product_id' => $productId, 'version' => '1.0.0', 'status' => SkillVersion::STATUS_PUBLISHED]);
    $entitlement = step10DeliveryEntitlement($order, $itemId, $device, [
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'skill_version_id' => $version->id,
        'granted_at'       => now()->subMinute(),
        'activations'      => 0,
        'max_activations'  => 1,
        'meta'             => ['download_count' => 9, 'unrelated_diagnostic' => 'keep-install-meta'],
    ]);
    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk()->assertJsonPath('data.activations', 1);
    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk()->assertJsonPath('data.idempotent', true);

    $after = $entitlement->fresh();
    expect($after->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($after->activations)->toBe(1)
        ->and($after->meta['download_count'])->toBe(9)
        ->and($after->meta['unrelated_diagnostic'])->toBe('keep-install-meta')
        ->and($after->meta['installed_by'])->toBe('user_reported')
        ->and(InstallRecord::where('entitlement_id', $entitlement->id)->count())->toBe(1);
});

it('Step 10 rolls back activation and installation metadata when writing the installation fact fails', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $productId = step10DeliveryProduct();
    $itemId = step10DeliveryItem($order, $productId, $device);
    $version = SkillVersion::create(['product_id' => $productId, 'version' => '1.0.0', 'status' => SkillVersion::STATUS_PUBLISHED]);
    $meta = ['download_count' => 3, 'unrelated_diagnostic' => 'before-install-failure'];
    $entitlement = step10DeliveryEntitlement($order, $itemId, $device, [
        'status'           => SkillEntitlement::STATUS_GRANTED,
        'granted_at'       => now()->subMinute(),
        'skill_version_id' => $version->id,
        'activations'      => 0,
        'max_activations'  => 1,
        'meta'             => $meta,
    ]);
    actingAs($customer, 'customer');
    $this->withoutExceptionHandling();
    $event = 'eloquent.creating: '.InstallRecord::class;
    app('events')->listen($event, function () {
        throw new RuntimeException('Step 10 deterministic install-record persistence failure');
    });

    try {
        expect(fn () => postJson(route('shop.api.skills.confirm-install', $entitlement->id)))
            ->toThrow(RuntimeException::class, 'Step 10 deterministic install-record persistence failure');
    } finally {
        app('events')->forget($event);
    }

    $after = $entitlement->fresh();
    expect($after->status)->toBe(SkillEntitlement::STATUS_GRANTED)
        ->and($after->activations)->toBe(0)
        ->and($after->meta)->toEqual($meta)
        ->and(InstallRecord::where('entitlement_id', $entitlement->id)->exists())->toBeFalse();
});

it('Step 10 allows confirmation after successful compensation while retaining the completed retry history', function () {
    $customer = step10DeliveryCustomer();
    $device = step10DeliveryDevice($customer);
    $order = step10DeliveryOrder($customer);
    $itemId = step10DeliveryItem($order, step10DeliveryProduct(), $device);
    $ledger = ['cycle_id' => 'step10-completed-cycle', 'state' => 'succeeded', 'attempts' => 2, 'reason_code' => 'APP_DELIVERED'];
    $entitlement = step10DeliveryEntitlement($order, $itemId, $device, [
        'status'          => SkillEntitlement::STATUS_GRANTED,
        'granted_at'      => now()->subMinute(),
        'max_activations' => 1,
        'meta'            => ['download_count' => 4, 'delivery' => ['ok' => true], 'delivery_failed' => null, 'delivery_retry' => $ledger],
    ]);
    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))
        ->assertOk()->assertJsonPath('data.activations', 1);

    $after = $entitlement->fresh();
    expect($after->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($after->meta['delivery_retry'])->toEqual($ledger)
        ->and($after->meta['download_count'])->toBe(4);
});
