<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/**
 * B-16 · 技能详情页「版本历史 + 通道标识」的数据源。
 *
 * `pages/home/jineng.vue` 此前只有真实商品+评价+兼容性校验,没有版本历史、没有通道标识 ——
 * 用户看不到"这个技能发过几版、当前是哪一版、是正式版还是测试版、怎么交付"。
 *
 * 这里只测**公开只读**的 `GET /api/skill-catalog/versions/{productId}`:
 *   - 只回已发布 / 已归档(草稿从不发布,不对外);
 *   - 新版在前;带发布通道标签(stable→正式版 / beta→测试版)与交付通道;
 *   - 没有发布过就返回空数组(不编)。
 *
 * ⚠ 发布通道(`channel` = stable/beta)与交付通道(`delivery_channels` = agent/manual/vendor:*)
 *   是两条不同的轴 —— 术语纪律里明写不可混淆,这里一并锁住。
 */

function b16SeedProduct(): int
{
    $familyId = DB::table('attribute_families')->value('id');
    $sku = 'b16-'.uniqid();

    return (int) DB::table('products')->insertGetId([
        'sku'                 => $sku,
        'type'                => 'simple',
        'attribute_family_id' => $familyId,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

function b16SeedVersion(int $productId, string $version, string $status, array $extra = []): int
{
    return (int) DB::table('skill_versions')->insertGetId(array_merge([
        'product_id'  => $productId,
        'version'     => $version,
        'channel'     => 'stable',
        'status'      => $status,
        'released_at' => now(),
        'created_at'  => now(),
        'updated_at'  => now(),
    ], $extra));
}

it('B-16 · versions 只回已发布/已归档,草稿不对外', function () {
    $productId = b16SeedProduct();
    b16SeedVersion($productId, '2.0.0', 'published', ['released_at' => now()]);
    b16SeedVersion($productId, '1.0.0', 'archived', ['released_at' => now()->subDay()]);
    $draft = b16SeedVersion($productId, '3.0.0-draft', 'draft');

    $res = getJson(route('shop.api.skill-catalog.versions', $productId))->assertOk();

    $versions = collect($res->json('data'))->pluck('version')->all();

    expect($versions)->toBe(['2.0.0', '1.0.0'])
        ->and(collect($res->json('data'))->pluck('id')->all())->not->toContain($draft);
});

it('B-16 · 发布通道标签:stable→正式版 / beta→测试版;交付通道原样回传', function () {
    $productId = b16SeedProduct();
    b16SeedVersion($productId, '1.1.0', 'published', [
        'channel'           => 'beta',
        'delivery_channels' => json_encode(['agent', 'manual']),
        'risk_level'        => 'medium',
    ]);

    $row = collect(getJson(route('shop.api.skill-catalog.versions', $productId))->json('data'))->first();

    expect($row['channel'])->toBe('beta')
        ->and($row['channel_label'])->toBe('测试版')
        ->and($row['delivery_channels'])->toBe(['agent', 'manual'])
        ->and($row['risk_level'])->toBe('medium');
});

it('B-16 · 未发布过任何版本 → 空数组(不编造历史)', function () {
    $productId = b16SeedProduct();
    b16SeedVersion($productId, '0.1.0', 'draft');

    $res = getJson(route('shop.api.skill-catalog.versions', $productId))->assertOk();

    expect($res->json('data'))->toBe([]);
});
