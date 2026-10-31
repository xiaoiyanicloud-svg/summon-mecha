<?php

use Illuminate\Support\Facades\DB;
use Webkul\Core\Models\Channel;

use function Pest\Laravel\getJson;

/**
 * 配件目录服务端排序：好评与销量必须来自真实业务表，
 * 不能只在当前页内按 DTO 字段重排。
 */
function accessorySortCatalogContext(): array
{
    $localeCode = app()->getLocale() ?: 'en';
    $localeId = DB::table('locales')->where('code', $localeCode)->value('id');

    if (! $localeId) {
        $localeId = DB::table('locales')->insertGetId([
            'code'       => $localeCode,
            'name'       => 'English',
            'direction'  => 'ltr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $currencyId = DB::table('currencies')->where('code', 'USD')->value('id');

    if (! $currencyId) {
        $currencyId = DB::table('currencies')->insertGetId([
            'code'       => 'USD',
            'name'       => 'US Dollar',
            'symbol'     => 'USD',
            'decimal'    => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $familyId = DB::table('attribute_families')->value('id');

    if (! $familyId) {
        $familyId = DB::table('attribute_families')->insertGetId([
            'code'            => 'default',
            'name'            => 'Default',
            'status'          => 1,
            'is_user_defined' => 0,
        ]);
    }

    $categoryId = DB::table('category_translations')->where('slug', 'accessory')->value('category_id');

    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId([
            'position'   => 0,
            'status'     => 1,
            '_lft'       => 1,
            '_rgt'       => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'name'        => '配件',
            'slug'        => 'accessory',
            'url_path'    => 'accessory',
            'locale_id'   => $localeId,
            'locale'      => $localeCode,
        ]);
    }

    $channel = DB::table('channels')->first();

    if (! $channel) {
        $channelId = DB::table('channels')->insertGetId([
            'code'              => 'default',
            'timezone'          => 'UTC',
            'hostname'          => 'localhost',
            'root_category_id'  => $categoryId,
            'default_locale_id' => $localeId,
            'base_currency_id'  => $currencyId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::table('channel_translations')->insert([
            'channel_id' => $channelId,
            'locale'     => $localeCode,
            'name'       => 'Default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('channel_locales')->insert(['channel_id' => $channelId, 'locale_id' => $localeId]);
        DB::table('channel_currencies')->insert(['channel_id' => $channelId, 'currency_id' => $currencyId]);
        $channel = DB::table('channels')->find($channelId);
    }

    app()->setLocale($localeCode);
    core()->setCurrentChannel(Channel::find($channel->id));

    return [
        'category_id' => (int) $categoryId,
        'family_id'   => (int) $familyId,
        'channel'     => (string) $channel->code,
        'locale'      => $localeCode,
    ];
}

function accessorySortSeedProduct(string $name, float $price): int
{
    $context = accessorySortCatalogContext();
    $sku = 'sort-'.uniqid();

    $productId = DB::table('products')->insertGetId([
        'sku'                 => $sku,
        'type'                => 'simple',
        'attribute_family_id' => $context['family_id'],
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_flat')->insert([
        'product_id'           => $productId,
        'sku'                  => $sku,
        'name'                 => $name,
        'status'               => 1,
        'visible_individually' => 1,
        'locale'               => $context['locale'],
        'price'                => $price,
        'url_key'              => $sku,
        'channel'              => $context['channel'],
        'attribute_family_id'  => $context['family_id'],
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $productId,
        'category_id' => $context['category_id'],
    ]);

    return (int) $productId;
}

function accessorySortSeedReview(int $productId, int $rating): void
{
    DB::table('product_reviews')->insert([
        'name'        => '排序测试',
        'title'       => '排序测试评价',
        'rating'      => $rating,
        'comment'     => '仅用于自动化测试',
        'status'      => 'approved',
        'product_id'  => $productId,
        'customer_id' => null,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function accessorySortSeedSales(int $productId, int $qty): void
{
    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'SORT'.uniqid(),
        'status'       => 'completed',
        'grand_total'  => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('order_items')->insert([
        'order_id'     => $orderId,
        'product_id'   => $productId,
        'type'         => 'simple',
        'name'         => '排序测试配件',
        'sku'          => 'SORTITEM'.uniqid(),
        'qty_ordered'  => $qty,
        'qty_invoiced' => $qty,
        'qty_refunded' => 0,
        'price'        => 100,
        'base_price'   => 100,
        'total'        => 100 * $qty,
        'base_total'   => 100 * $qty,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

it('配件排序 · 好评优先按已审核评价均分与评价数排序', function () {
    $token = 'RATE'.uniqid();
    $lower = accessorySortSeedProduct($token.'-低评分', 100);
    $higher = accessorySortSeedProduct($token.'-高评分', 200);

    accessorySortSeedReview($lower, 2);
    accessorySortSeedReview($higher, 5);
    accessorySortSeedReview($higher, 4);

    $response = getJson(route('shop.api.accessory-catalog.search', [
        'q'     => $token,
        'sort'  => 'rating_desc',
        'limit' => 10,
    ]))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$higher, $lower]);
});

it('配件排序 · 销量优先按已开票数量减退款数量排序', function () {
    $token = 'SALE'.uniqid();
    $lower = accessorySortSeedProduct($token.'-低销量', 100);
    $higher = accessorySortSeedProduct($token.'-高销量', 200);

    accessorySortSeedSales($lower, 1);
    accessorySortSeedSales($higher, 5);

    $response = getJson(route('shop.api.accessory-catalog.search', [
        'q'     => $token,
        'sort'  => 'sales_desc',
        'limit' => 10,
    ]))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$higher, $lower]);
});
