<?php

use Illuminate\Support\Facades\DB;
use Webkul\Faker\Helpers\Product as ProductFaker;

use function Pest\Laravel\getJson;

it('商品目录透出好评与真实销量元数据', function () {
    $product = (new ProductFaker)
        ->getSimpleProductFactory()
        ->create();

    DB::table('product_reviews')->insert([
        'name'       => '排序测试',
        'title'      => '排序测试评价',
        'rating'     => 5,
        'comment'    => '仅用于自动化测试',
        'status'     => 'approved',
        'product_id' => $product->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'PSORT'.uniqid(),
        'status'       => 'completed',
        'grand_total'  => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('order_items')->insert([
        'order_id'      => $orderId,
        'product_id'    => $product->id,
        'type'          => 'simple',
        'name'          => $product->name,
        'sku'           => $product->sku,
        'qty_ordered'   => 4,
        'qty_invoiced'  => 4,
        'qty_refunded'  => 1,
        'price'         => 100,
        'base_price'    => 100,
        'total'         => 400,
        'base_total'    => 400,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $response = getJson(route('shop.api.products.index', ['limit' => 200]))->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $product->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['ratings']['average'])->toBe(5.0)
        ->and((int) $row['reviews']['total'])->toBe(1)
        ->and((int) $row['sales']['total'])->toBe(3);
});
