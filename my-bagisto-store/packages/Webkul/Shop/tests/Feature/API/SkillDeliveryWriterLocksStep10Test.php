<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Models\ChannelProxy;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\ProductProxy;
use Webkul\Sales\Repositories\RefundRepository;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\Payment\PaymentGateway;
use Webkul\Shop\Services\Payment\PaymentManager;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new RuntimeException('Dedicated Step10 database required.');
    }
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
});

function step10WriterEntitlement(): SkillEntitlement
{
    $customer = Customer::create(['first_name' => 'Writer', 'last_name' => 'Locks',
        'email'                                => uniqid('writer-').'@example.com', 'password' => bcrypt('test-only'),
        'channel_id'                           => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1]);
    $productId = DB::table('products')->insertGetId(['sku' => uniqid('WL-'), 'type' => 'simple',
        'attribute_family_id'                              => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'                                       => now(), 'updated_at' => now()]);
    $orderId = DB::table('orders')->insertGetId(['increment_id' => testUniqueNo('WL'),
        'customer_type'                                         => Customer::class, 'channel_type' => ChannelProxy::modelClass(),
        'total_qty_ordered'                                     => 1, 'sub_total' => 100, 'base_sub_total' => 100,
        'grand_total_invoiced'                                  => 100, 'base_grand_total_invoiced' => 100,
        'customer_id'                                           => $customer->id, 'channel_id' => core()->getDefaultChannel()->id,
        'channel_name'                                          => 'Test', 'status' => 'processing', 'grand_total' => 100,
        'base_grand_total'                                      => 100, 'base_currency_code' => 'USD', 'channel_currency_code' => 'USD',
        'order_currency_code'                                   => 'USD', 'created_at' => now(), 'updated_at' => now()]);
    $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $productId,
        'product_type'                                          => ProductProxy::modelClass(), 'qty_refunded' => 0, 'qty_canceled' => 0,
        'qty_shipped'                                           => 0, 'tax_amount' => 0, 'base_tax_amount' => 0,
        'type'                                                  => 'simple', 'name' => 'Writer skill', 'sku' => uniqid('WLI-'), 'qty_ordered' => 1,
        'qty_invoiced'                                          => 1, 'price' => 100, 'base_price' => 100, 'price_incl_tax' => 100,
        'base_price_incl_tax'                                   => 100, 'total' => 100, 'base_total' => 100,
        'created_at'                                            => now(), 'updated_at' => now()]);

    return SkillEntitlement::create(['customer_id' => $customer->id, 'order_id' => $orderId,
        'order_item_id'                            => $itemId, 'product_id' => $productId,
        'delivery_channel'                         => 'app', 'status' => 'pending_bind', 'license_key' => SkillEntitlement::newLicenseKey()]);
}

function step10LockIndex(array $sql, string $table): int
{
    foreach ($sql as $index => $query) {
        if (str_contains($query, 'from `'.$table.'`') && str_contains($query, 'for update')) {
            return $index;
        }
    }
    throw new RuntimeException('Expected current row lock not found: '.$table);
}

it('approves and settles in the shared row lock order while keeping the gateway outside its preparation transaction', function () {
    $entitlement = step10WriterEntitlement();
    $sale = AfterSale::create(['order_id' => $entitlement->order_id, 'order_item_id' => $entitlement->order_item_id,
        'entitlement_id'                  => $entitlement->id, 'customer_id' => $entitlement->customer_id, 'status' => 'applied']);
    $outerLevel = DB::transactionLevel();
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('refund')->once()->andReturnUsing(function () use ($outerLevel) {
        expect(DB::transactionLevel())->toBe($outerLevel);

        return ['ok' => true, 'message' => 'isolated mock'];
    });
    $this->mock(PaymentManager::class)->shouldReceive('gateway')->once()->andReturn($gateway);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    $result = app(AfterSaleService::class)->approve($sale->id);
    expect($result['ok'])->toBeTrue()->and($entitlement->fresh()->status)->toBe('revoked')
        ->and(step10LockIndex($sql, 'orders'))->toBeLessThan(step10LockIndex($sql, 'order_items'))
        ->and(step10LockIndex($sql, 'order_items'))->toBeLessThan(step10LockIndex($sql, 'skill_entitlements'))
        ->and(step10LockIndex($sql, 'skill_entitlements'))->toBeLessThan(step10LockIndex($sql, 'after_sale'));
});

it('native refunds lock the real order before item writes without changing entitlement policy', function () {
    $entitlement = step10WriterEntitlement();
    Event::fake(['sales.refund.save.before', 'sales.refund.save.after']);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    $refund = app(RefundRepository::class)->create(['order_id' => $entitlement->order_id,
        'refund'                                               => ['items' => [$entitlement->order_item_id => 1], 'adjustment_refund' => 0,
            'adjustment_fee'                                               => 0, 'shipping' => 0]]);
    expect($refund->id)->not->toBeNull()
        ->and(step10LockIndex($sql, 'orders'))->toBeLessThan(step10LockIndex($sql, 'order_items'))
        ->and(DB::table('refund_items')->where('order_item_id', $entitlement->order_item_id)->exists())->toBeTrue()
        ->and($entitlement->fresh()->status)->toBe('pending_bind');
});

it('settles a refund after locking order item entitlement and after-sale before the refund row', function () {
    $entitlement = step10WriterEntitlement();
    $entitlement->update(['status' => 'revoked']);
    $sale = AfterSale::create(['order_id' => $entitlement->order_id, 'order_item_id' => $entitlement->order_item_id,
        'entitlement_id'                  => $entitlement->id, 'customer_id' => $entitlement->customer_id, 'status' => 'approved']);
    $refund = RefundRequest::create(['after_sale_id' => $sale->id, 'amount' => 100, 'status' => 'pending',
        'wechat_refund_no'                           => testUniqueNo('WLR')]);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    expect(app(AfterSaleService::class)->settleRefund($refund->id, true)['ok'])->toBeTrue()
        ->and(step10LockIndex($sql, 'orders'))->toBeLessThan(step10LockIndex($sql, 'order_items'))
        ->and(step10LockIndex($sql, 'order_items'))->toBeLessThan(step10LockIndex($sql, 'skill_entitlements'))
        ->and(step10LockIndex($sql, 'skill_entitlements'))->toBeLessThan(step10LockIndex($sql, 'after_sale'))
        ->and(step10LockIndex($sql, 'after_sale'))->toBeLessThan(step10LockIndex($sql, 'refund_request'));
});

it('rejects an item outside the locked native order and rolls back the refund header', function () {
    $orderA = step10WriterEntitlement();
    $orderB = step10WriterEntitlement();
    Event::fake(['sales.refund.save.before', 'sales.refund.save.after']);
    $count = DB::table('refunds')->count();
    expect(fn () => app(RefundRepository::class)->create(['order_id' => $orderA->order_id,
        'refund'                                                     => ['items' => [$orderB->order_item_id => 1], 'adjustment_refund' => 0,
            'adjustment_fee'                                                     => 0, 'shipping' => 0]]))->toThrow(ModelNotFoundException::class);
    expect(DB::table('refunds')->count())->toBe($count)
        ->and(DB::table('refund_items')->where('order_item_id', $orderB->order_item_id)->exists())->toBeFalse();
});

it('locks the real product for engage and lift and dispatches force work after its short transaction', function () {
    $entitlement = step10WriterEntitlement();
    $version = SkillVersion::create(['product_id' => $entitlement->product_id, 'version' => '1.0.0',
        'channel'                                 => 'stable', 'status' => 'published', 'license_mode' => 'per_device']);
    InstallRecord::create(['did' => uniqid('writer-did-'), 'skill_version_id' => $version->id,
        'entitlement_id'         => $entitlement->id, 'channel' => 'agent', 'status' => 'installed', 'installed_at' => now()]);
    $outerLevel = DB::transactionLevel();
    $deploy = $this->mock(DeployTaskService::class);
    $deploy->shouldReceive('create')->once()->andReturnUsing(function () use ($outerLevel, $entitlement) {
        expect(DB::transactionLevel())->toBe($outerLevel)
            ->and(SkillBlocklist::activeFor($entitlement->product_id))->not->toBeNull();

        return new DeployTask(['status' => 'dispatching']);
    });
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    $service = app(KillSwitchService::class);
    $first = $service->engage($entitlement->product_id, 'force_uninstall');
    $again = $service->engage($entitlement->product_id, 'force_uninstall');
    expect($first['forced_tasks'])->toBe(1)->and($again['blocklist_id'])->toBe($first['blocklist_id'])
        ->and(step10LockIndex($sql, 'products'))->toBeLessThan(step10LockIndex($sql, 'skill_blocklist'));
    $sql = [];
    expect($service->lift($entitlement->product_id))->toBeTrue()
        ->and(step10LockIndex($sql, 'products'))->toBeLessThan(step10LockIndex($sql, 'skill_blocklist'))
        ->and(SkillBlocklist::activeFor($entitlement->product_id))->toBeNull();
});
