<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\AdminAlertService;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAudit;

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new RuntimeException('Dedicated Step10 database required.');
    }
    config(['cache.default' => 'array']);
    Cache::flush();
});

function step10RotationFacts(string $kind, int $count): array
{
    $customer = Customer::create(['first_name' => 'Audit', 'last_name' => 'Rotation',
        'email'                                => uniqid('rotation-').'@example.com', 'password' => bcrypt('test-only'),
        'channel_id'                           => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1]);
    $productId = DB::table('products')->insertGetId(['sku' => uniqid('ROT-'), 'type' => 'simple',
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
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $orderId = DB::table('orders')->insertGetId(['increment_id' => testUniqueNo('AR'),
            'customer_id'                                           => $customer->id, 'status' => $kind === 'payments' ? 'canceled' : 'processing',
            'grand_total'                                           => 100, 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'payments') {
            $ids[] = PaymentTransaction::create(['order_id' => $orderId, 'out_trade_no' => testUniqueNo('RP'),
                'transaction_id'                            => testUniqueNo('RX'), 'gateway' => 'mock', 'amount' => 100,
                'status'                                    => 'paid', 'paid_at' => now()])->id;

            continue;
        }
        $itemId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'product_id' => $productId,
            'type'                                                  => 'simple', 'name' => 'Audit skill', 'sku' => uniqid('ARI-'), 'qty_ordered' => 1,
            'price'                                                 => 100, 'total' => 100, 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'items') {
            $ids[] = $itemId;

            continue;
        }
        $ids[] = SkillEntitlement::create(['customer_id' => $customer->id, 'order_id' => $orderId,
            'order_item_id'                              => $itemId, 'product_id' => $productId, 'delivery_channel' => 'app',
            'status'                                     => 'pending_bind', 'meta' => ['delivery_failed' => ['class' => RuntimeException::class],
                'delivery_retry'                                                                         => ['cycle_id' => uniqid('cycle-'), 'state' => 'exhausted',
                    'attempts'                                                                                          => 3, 'reason_code' => 'RETRY_EXHAUSTED']]])->id;
    }

    return $ids;
}

it('rotates every issue category past the first 100 and revisits failures without treating cache as sent state', function ($kind, $reason, $field) {
    $expected = step10RotationFacts($kind, 101);
    $audit = app(SkillDeliveryAudit::class);
    $extract = fn ($issues) => array_map(fn ($issue) => (int) $issue[$field],
        array_values(array_filter($issues, fn ($issue) => $issue['reason_code'] === $reason)));
    $key = 'skill-delivery-audit:cursor:v1:'.sha1(DB::connection()->getDatabaseName());
    $before = $extract($audit->inspect(100));
    expect($before)->toHaveCount(100)->and($extract($audit->inspect(100)))->toBe($before)
        ->and(Cache::get($key))->toBeNull();

    $batch1 = $audit->inspect(100, true);
    $this->mock(AdminAlertService::class)->shouldReceive('alert')->andReturn(0);
    // Simulate an unsent notification. Progress must eventually revisit it.
    app(SkillDeliveryAudit::class)->notify($batch1);
    $batch2 = $audit->inspect(100, true);
    expect($extract($batch1))->toHaveCount(100)->and($extract($batch2))->toBe([(int) end($expected)])
        ->and(array_merge($extract($batch1), $extract($batch2)))->toBe(array_map('intval', $expected));
    expect($extract($audit->inspect(100, true)))->toBe($before);
    Cache::forget($key);
    expect($extract($audit->inspect(100, true)))->toBe($before)
        ->and($extract($audit->inspect(100, true)))->toBe([(int) end($expected)]);
    if ($kind === 'entitlements') {
        expect(SkillEntitlement::whereIn('id', $expected)->get()->every(fn ($entitlement) => $entitlement->meta['delivery_retry']['attempts'] === 3))->toBeTrue();
    }
})->with([
    ['payments', 'TERMINAL_ORDER_WITH_PAID_TRANSACTION', 'payment_transaction_id'],
    ['items', 'ENTITLEMENT_MISSING', 'order_item_id'],
    ['entitlements', 'RETRY_EXHAUSTED', 'entitlement_id'],
]);
