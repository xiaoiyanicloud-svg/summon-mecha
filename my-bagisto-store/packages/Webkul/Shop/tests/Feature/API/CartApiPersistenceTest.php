<?php

use Webkul\Faker\Helpers\Product as ProductFaker;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

it('keeps a guest cart across API requests by guest key', function () {
    $product = (new ProductFaker([
        'attributes' => [
            5  => 'new',
            11 => 'price',
            26 => 'guest_checkout',
        ],
        'attribute_value' => [
            'new'            => ['boolean_value' => true],
            'price'          => ['float_value' => 499],
            'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();

    $guestKey = 'cart-persist-0123456789abcdef';

    $created = postJson(route('shop.api.checkout.cart.store'), [
        'product_id' => $product->id,
        'quantity'   => 2,
        'order_type' => 'buy',
    ], ['X-Guest-Key' => $guestKey])
        ->assertOk()
        ->assertJsonPath('data.is_guest', 1)
        ->assertJsonPath('data.items.0.product_id', $product->id)
        ->assertJsonPath('data.items.0.order_type', 'buy');

    $itemId = $created->json('data.items.0.id');
    expect($itemId)->not->toBeNull();

    getJson(route('shop.api.checkout.cart.index'), ['X-Guest-Key' => $guestKey])
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $itemId)
        ->assertJsonPath('data.items.0.order_type', 'buy');

    putJson(route('shop.api.checkout.cart.update'), [
        'qty' => [(string) $itemId => 3],
    ], ['X-Guest-Key' => $guestKey])
        ->assertOk();

    getJson(route('shop.api.checkout.cart.index'), ['X-Guest-Key' => $guestKey])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 3);
});

it('does not expose one guest cart to another guest key', function () {
    $product = (new ProductFaker([
        'attributes' => [
            5  => 'new',
            11 => 'price',
            26 => 'guest_checkout',
        ],
        'attribute_value' => [
            'new'            => ['boolean_value' => true],
            'price'          => ['float_value' => 299],
            'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();

    postJson(route('shop.api.checkout.cart.store'), [
        'product_id' => $product->id,
        'quantity'   => 1,
        'order_type' => 'buy',
    ], ['X-Guest-Key' => 'guest-a-0123456789abcdef'])->assertOk();

    getJson(route('shop.api.checkout.cart.index'), ['X-Guest-Key' => 'guest-b-0123456789abcdef'])
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('keeps rental period metadata in the guest cart', function () {
    $product = (new ProductFaker([
        'attributes' => [
            5  => 'new',
            11 => 'price',
            26 => 'guest_checkout',
        ],
        'attribute_value' => [
            'new'            => ['boolean_value' => true],
            'price'          => ['float_value' => 399],
            'guest_checkout' => ['boolean_value' => true],
        ],
    ]))->getSimpleProductFactory()->create();

    $guestKey = 'cart-rent-0123456789abcdef';

    postJson(route('shop.api.checkout.cart.store'), [
        'product_id' => $product->id,
        'quantity'   => 1,
        'order_type' => 'rent',
        'rent_days'  => 7,
        'rent_period'=> '7天',
        'start_date' => '2026-11-01',
        'product_type' => 'quadruped',
    ], ['X-Guest-Key' => $guestKey])->assertOk();

    getJson(route('shop.api.checkout.cart.index'), ['X-Guest-Key' => $guestKey])
        ->assertOk()
        ->assertJsonPath('data.items.0.order_type', 'rent')
        ->assertJsonPath('data.items.0.rent_days', 7)
        ->assertJsonPath('data.items.0.rent_period', '7天')
        ->assertJsonPath('data.items.0.start_date', '2026-11-01')
        ->assertJsonPath('data.items.0.product_type', 'quadruped');
});
