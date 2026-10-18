<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Jobs\RetrySkillDelivery;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\AdminAlertService;
use Webkul\Shop\Services\SkillDelivery\AppChannel;
use Webkul\Shop\Services\SkillDelivery\DeliveryResult;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAudit;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

// These are rollback-isolated core tests. They deliberately do NOT claim to
// prove real commit visibility or independent-worker concurrency; use the
// separate committed integration harness against the dedicated test database.
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new RuntimeException('Step10 tests require the dedicated summon_mecha_step10_test database.');
    }
    config(['mail.default'                       => 'array', 'queue.default' => 'database',
        'skill_delivery.retry.execution_enabled' => true,
        'skill_delivery.retry.safety_verified'   => true,
        'skill_delivery.channels.app.class'      => AppChannel::class]);
});

function step10CompensationFixture(bool $payment = true): SkillEntitlement
{
    $customer = Customer::create(['first_name' => 'Retry', 'last_name' => 'Test',
        'email'                                => 'retry-'.uniqid().'@example.com', 'password' => bcrypt('test-only'),
        'channel_id'                           => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1]);
    $productId = DB::table('products')->insertGetId(['sku' => 'RETRY-'.uniqid(), 'type' => 'simple',
        'attribute_family_id'                              => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'                                       => now(), 'updated_at' => now()]);
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId(['status' => 1, '_lft' => 1, '_rgt' => 2,
            'created_at'                                             => now(), 'updated_at' => now()]);
        DB::table('category_translations')->insert(['category_id' => $categoryId,
            'locale_id'                                           => DB::table('locales')->value('id') ?: 1, 'locale' => 'en', 'name' => 'Skill', 'slug' => 'skill']);
    }
    DB::table('product_categories')->insert(['product_id' => $productId, 'category_id' => $categoryId]);
    $orderId = DB::table('orders')->insertGetId(['increment_id' => testUniqueNo('RT'),
        'customer_id'                                           => $customer->id, 'status' => 'processing', 'grand_total' => 100,
        'created_at'                                            => now(), 'updated_at' => now()]);
    $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $productId,
        'type'                                                  => 'simple', 'name' => 'Retry skill', 'sku' => 'RT-'.uniqid(),
        'qty_ordered'                                           => 1, 'price' => 100, 'total' => 100, 'created_at' => now(), 'updated_at' => now()]);
    if ($payment) {
        PaymentTransaction::create(['order_id' => $orderId, 'out_trade_no' => testUniqueNo('PAY'),
            'transaction_id'                   => testUniqueNo('TX'), 'gateway' => 'mock', 'amount' => 100,
            'status'                           => 'paid', 'paid_at' => now()]);
    }
    $entitlement = SkillEntitlement::create(['customer_id' => $customer->id, 'order_id' => $orderId,
        'order_item_id'                                    => $itemId, 'product_id' => $productId, 'status' => 'pending_bind',
        'delivery_channel'                                 => 'app', 'license_key' => SkillEntitlement::newLicenseKey(),
        'expires_at'                                       => now()->addDays(7), 'max_activations' => 4, 'activations' => 0,
        'meta'                                             => ['download_count' => 7]]);
    app(SkillDeliveryCompensator::class)->recordFailure($entitlement->id, new RuntimeException('initial outage'));

    return $entitlement->fresh();
}

class Step10FailingAppChannel extends AppChannel
{
    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult
    {
        throw new RuntimeException('isolated injected delivery failure');
    }
}

class Step10RejectedAppChannel extends AppChannel
{
    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult
    {
        return DeliveryResult::fail('business rejection');
    }
}

it('keeps both execution gates fail closed without consuming a budget', function () {
    $entitlement = step10CompensationFixture();
    $before = $entitlement->meta;
    config(['skill_delivery.retry.safety_verified' => false]);
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['reason_code'])->toBe('EXECUTION_NOT_VERIFIED')
        ->and($entitlement->fresh()->meta)->toBe($before);
    config(['skill_delivery.retry.safety_verified' => true, 'skill_delivery.retry.execution_enabled' => false]);
    expect(app(SkillDeliveryCompensator::class)->executionAllowed())->toBeFalse();
});

it('records one durable cycle and preserves unrelated meta across repeated exceptions', function () {
    $entitlement = step10CompensationFixture();
    $cycle = $entitlement->meta['delivery_retry']['cycle_id'];
    $meta = $entitlement->meta;
    $meta['delivery_retry']['attempts'] = 2;
    $entitlement->update(['meta' => $meta]);
    app(SkillDeliveryCompensator::class)->recordFailure($entitlement->id, new RuntimeException('another event'));
    $meta = $entitlement->fresh()->meta;
    expect($meta['delivery_retry']['cycle_id'])->toBe($cycle)
        ->and($meta['delivery_retry']['attempts'])->toBe(2)
        ->and($meta['download_count'])->toBe(7)
        ->and($meta['delivery_failed']['message'])->not->toContain('another event');
    expect($meta['delivery_failed']['channel'])->toBe('app');
});

it('records a safe channel key for unknown channel diagnostics', function () {
    $entitlement = step10CompensationFixture();
    $entitlement->update(['delivery_channel' => 'no-such-channel']);
    app(SkillDeliveryCompensator::class)->recordFailure($entitlement->id, new InvalidArgumentException('sensitive config value'));
    $failure = $entitlement->fresh()->meta['delivery_failed'];
    expect($failure['channel'])->toBe('no-such-channel')->and($failure['message'])->not->toContain('sensitive config value');
});

it('re-inspects terminal paid orders and shares the callback alert dedup key', function () {
    $entitlement = step10CompensationFixture();
    DB::table('orders')->where('id', $entitlement->order_id)->update(['status' => 'canceled']);
    $paymentId = PaymentTransaction::where('order_id', $entitlement->order_id)->value('id');
    $this->mock(AdminAlertService::class)->shouldReceive('alert')->once()
        ->withArgs(fn ($type, $title, $content, $key, $payload, $level) => $type === 'payment.review_required' && $key === 'payment-order-state:'.$paymentId
            && (int) $payload['payment_transaction_id'] === (int) $paymentId
            && $level === AdminAlertService::LEVEL_URGENT)->andReturn(1);
    $audit = app(SkillDeliveryAudit::class);
    $issues = array_values(array_filter($audit->inspect(), fn ($issue) => (int) ($issue['payment_transaction_id'] ?? 0) === (int) $paymentId));
    expect($issues)->toHaveCount(1)->and($audit->notify($issues))->toBe(1)
        ->and(DB::table('orders')->where('id', $entitlement->order_id)->value('status'))->toBe('canceled');
});

it('inspects without writing or calling the delivery channel', function () {
    $entitlement = step10CompensationFixture();
    $before = $entitlement->meta;
    $result = app(SkillDeliveryCompensator::class)->inspect($entitlement->id);
    expect($result['reason_code'])->toBe('ELIGIBLE_APP_EXCEPTION')
        ->and($entitlement->fresh()->meta)->toBe($before);
    $this->artisan('skills:redeliver', ['orderId' => $entitlement->order_id])->assertSuccessful();
    expect($entitlement->fresh()->meta)->toBe($before);
});

it('requires a matching paid transaction rather than only processing status', function () {
    $entitlement = step10CompensationFixture(false);
    $this->travel(61)->seconds();
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['reason_code'])->toBe('PAID_TRANSACTION_MISSING')
        ->and($result['attempts'])->toBe(0)
        ->and($entitlement->fresh()->granted_at)->toBeNull();
});

it('does not accept a payment with the wrong amount', function () {
    $entitlement = step10CompensationFixture();
    PaymentTransaction::where('order_id', $entitlement->order_id)->update(['amount' => 1]);
    expect(app(SkillDeliveryCompensator::class)->inspect($entitlement->id)['reason_code'])->toBe('PAID_TRANSACTION_MISSING');
});

it('waits for payment and refuses terminal orders without charging an attempt', function ($status, $reason) {
    $entitlement = step10CompensationFixture();
    DB::table('orders')->where('id', $entitlement->order_id)->update(['status' => $status]);
    $this->travel(61)->seconds();
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['reason_code'])->toBe($reason)->and($result['attempts'])->toBe(0)
        ->and($entitlement->fresh()->granted_at)->toBeNull();
})->with([['pending', 'WAITING_PAYMENT'], ['pending_payment', 'WAITING_PAYMENT'],
    ['canceled', 'ORDER_TERMINAL'], ['closed', 'ORDER_TERMINAL'], ['fraud', 'ORDER_TERMINAL']]);

it('refuses every after-sale history including a rejected request without restoring frozen rights', function ($status) {
    $entitlement = step10CompensationFixture();
    AfterSale::create(['order_id' => $entitlement->order_id, 'order_item_id' => $entitlement->order_item_id,
        'entitlement_id'          => $entitlement->id, 'customer_id' => $entitlement->customer_id, 'status' => $status]);
    $this->travel(61)->seconds();
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['reason_code'])->toBe('AFTER_SALE_OR_REFUND')
        ->and($result['attempts'])->toBe(0)->and($entitlement->fresh()->granted_at)->toBeNull();
})->with(['applied', 'reviewing', 'approved', 'refunded', 'rejected']);

it('blocks native item refunds and cancellations', function ($column) {
    $entitlement = step10CompensationFixture();
    DB::table('order_items')->where('id', $entitlement->order_item_id)->update([$column => 1]);
    expect(app(SkillDeliveryCompensator::class)->inspect($entitlement->id)['reason_code'])->toBe('AFTER_SALE_OR_REFUND');
})->with(['qty_refunded', 'qty_canceled']);

it('never reopens revoked expired or expiring entitlements', function ($status) {
    $entitlement = step10CompensationFixture();
    $entitlement->update(['status' => $status]);
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['reason_code'])->toBe('ENTITLEMENT_UNUSABLE')
        ->and($result['attempts'])->toBe(0)->and($entitlement->fresh()->status)->toBe($status);
})->with(['revoked', 'expired', 'expiring']);

it('honors the kill switch rather than recovering blocked app rights', function () {
    $entitlement = step10CompensationFixture();
    SkillBlocklist::create(['skill_id' => $entitlement->product_id, 'status' => 'active',
        'action'                       => 'block_all', 'reason' => 'isolated safety test']);
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['reason_code'])->toBe('SKILL_BLOCKED');
});

it('leaves agent trial and non-exception business failures out of the automatic scope', function () {
    $entitlement = step10CompensationFixture();
    $entitlement->update(['delivery_channel' => 'agent']);
    expect(app(SkillDeliveryCompensator::class)->inspect($entitlement->id)['reason_code'])->toBe('CHANNEL_OUT_OF_SCOPE');
    $meta = $entitlement->meta;
    unset($meta['delivery_failed'], $meta['delivery_retry']);
    $meta['delivery'] = ['ok' => false, 'message' => 'waiting'];
    $entitlement->update(['delivery_channel' => 'app', 'meta' => $meta]);
    expect(app(SkillDeliveryCompensator::class)->hasFailureState($entitlement->fresh()))->toBeTrue()
        ->and(app(SkillDeliveryCompensator::class)->inspect($entitlement->id)['reason_code'])->toBe('NO_TECHNICAL_FAILURE');
});

it('recovers app pending_bind once while preserving license expiry and counters', function () {
    $entitlement = step10CompensationFixture();
    $license = $entitlement->license_key;
    $expires = $entitlement->expires_at->toDateTimeString();
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['reason_code'])->toBe('BACKOFF');
    $this->travel(61)->seconds();
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    $fresh = $entitlement->fresh();
    expect($result['state'])->toBe('succeeded')->and($result['attempts'])->toBe(1)
        ->and($fresh->status)->toBe('pending_bind')->and($fresh->granted_at)->not->toBeNull()
        ->and($fresh->license_key)->toBe($license)->and($fresh->expires_at->toDateTimeString())->toBe($expires)
        ->and($fresh->activations)->toBe(0)->and($fresh->max_activations)->toBe(4)
        ->and($fresh->meta['download_count'])->toBe(7)->and($fresh->meta['delivery_failed'])->toBeNull();
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['attempts'])->toBe(1);
    expect(app(SkillDeliveryCompensator::class)->hasFailureState($entitlement->fresh()))->toBeFalse()
        ->and($entitlement->fresh()->meta['delivery_retry']['attempts'])->toBe(1);
});

it('shares a maximum of three attempts across repeated entry points and persistent failures', function () {
    $entitlement = step10CompensationFixture();
    config(['skill_delivery.channels.app.class' => Step10FailingAppChannel::class]);
    foreach ([61, 301, 901] as $index => $wait) {
        $this->travel($wait)->seconds();
        $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
        expect($result['attempts'])->toBe($index + 1);
    }
    expect($result['state'])->toBe('exhausted')->and($entitlement->fresh()->granted_at)->toBeNull();
    app(SkillDeliveryCompensator::class)->recordFailure($entitlement->id, new RuntimeException('repeat callback'));
    $this->travel(1000)->seconds();
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['attempts'])->toBe(3)
        ->and(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['state'])->toBe('exhausted');
});

it('reconciles a saved app grant after diagnostic failure without redelivery or consuming another attempt and permits confirmation', function () {
    $entitlement = step10CompensationFixture();
    $device = Device::create(['customer_id' => $entitlement->customer_id, 'sn' => uniqid('RECON-'),
        'bind_status'                       => Device::STATUS_BOUND, 'bound_at' => now()]);
    $meta = $entitlement->meta;
    $meta['delivery_retry']['attempts'] = 2;
    $ledger = $meta['delivery_retry'];
    unset($meta['delivery']);
    $entitlement->update(['device_id' => $device->id, 'status' => SkillEntitlement::STATUS_GRANTED,
        'granted_at'                  => now(), 'meta' => $meta]);
    $license = $entitlement->license_key;
    $expires = $entitlement->expires_at->toDateTimeString();
    $this->mock(SkillDeliveryManager::class)->shouldNotReceive('channel');
    $compensator = app(SkillDeliveryCompensator::class);
    $result = $compensator->execute($entitlement->id);
    $fresh = $entitlement->fresh();
    expect($result['state'])->toBe('succeeded')->and($result['reason_code'])->toBe('ALREADY_DELIVERED')
        ->and($result['attempts'])->toBe(2)->and($fresh->meta['delivery_retry']['cycle_id'])->toBe($ledger['cycle_id'])
        ->and($fresh->meta['delivery_failed'])->toBeNull()->and($fresh->meta['delivery']['ok'])->toBeTrue()
        ->and($fresh->meta['delivery']['code'])->toBe('APP_DELIVERY_CONFIRMED')
        ->and($fresh->meta['delivery']['data']['source'])->toBe('local_entitlement_state')
        ->and($fresh->license_key)->toBe($license)->and($fresh->device_id)->toBe($device->id)
        ->and($fresh->expires_at->toDateTimeString())->toBe($expires)
        ->and($fresh->activations)->toBe(0)->and($fresh->meta['download_count'])->toBe(7)
        ->and($compensator->hasFailureState($fresh))->toBeFalse();
    $this->actingAs(Customer::find($entitlement->customer_id), 'customer');
    $this->postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();
    expect($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($entitlement->fresh()->meta['delivery_retry']['attempts'])->toBe(2);
});

it('records ok=false as manual rather than silently clearing the technical failure', function () {
    $entitlement = step10CompensationFixture();
    config(['skill_delivery.channels.app.class' => Step10RejectedAppChannel::class]);
    $this->travel(61)->seconds();
    $result = app(SkillDeliveryCompensator::class)->execute($entitlement->id);
    expect($result['state'])->toBe('manual')->and($entitlement->fresh()->meta['delivery']['ok'])->toBeFalse()
        ->and($entitlement->fresh()->meta['delivery_failed'])->not->toBeNull();
});

it('rejects duplicate execution during a live lease and keeps consumed crash attempts', function () {
    $entitlement = step10CompensationFixture();
    $meta = $entitlement->meta;
    $meta['delivery_retry'] = array_merge($meta['delivery_retry'], ['state' => 'running',
        'attempts'                                                          => 1, 'lease_token' => 'crashed-worker', 'lease_until' => now()->timestamp + 120,
        'next_retry_at'                                                     => now()->timestamp - 1]);
    $entitlement->update(['meta' => $meta]);
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['reason_code'])->toBe('LEASE_BUSY');
    $this->travel(121)->seconds();
    expect(app(SkillDeliveryCompensator::class)->execute($entitlement->id)['attempts'])->toBe(2);
});

it('marks one dispatch until expiry even when afterCommit is held by the test transaction', function () {
    Bus::fake([RetrySkillDelivery::class]);
    $entitlement = step10CompensationFixture();
    $this->travel(61)->seconds();
    expect(app(SkillDeliveryCompensator::class)->dispatchDue()['dispatched'])->toBe(1)
        ->and(app(SkillDeliveryCompensator::class)->dispatchDue()['dispatched'])->toBe(0)
        ->and($entitlement->fresh()->meta['delivery_retry']['attempts'])->toBe(0);
    // Intentionally no assertion of real commit/worker visibility in this suite.
});

it('never manufactures an entitlement during inspection of a missing ID', function () {
    $count = SkillEntitlement::count();
    expect(app(SkillDeliveryCompensator::class)->inspect(2147483000)['reason_code'])->toBe('ENTITLEMENT_MISSING')
        ->and(SkillEntitlement::count())->toBe($count);
    expect(app(SkillDeliveryAudit::class)->inspect(10))->toBeArray();
});
