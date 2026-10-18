<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

function s18CatalogProduct(bool $inSkillCategory = true): int
{
    $sku = 'S18-'.Str::uuid();
    $family = DB::table('attribute_families')->value('id');
    $product = DB::table('products')->insertGetId([
        'sku' => $sku, 'type' => 'simple', 'attribute_family_id' => $family,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('product_flat')->insert([
        'product_id' => $product, 'sku' => $sku, 'name' => $sku,
        'status' => 1, 'visible_individually' => 1, 'locale' => app()->getLocale(),
        'price' => 100, 'url_key' => $sku, 'channel' => DB::table('channels')->value('code'),
        'attribute_family_id' => $family, 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($inSkillCategory) {
        $category = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
        expect($category)->not->toBeNull();
        DB::table('product_categories')->insert(['product_id' => $product, 'category_id' => $category]);
    }

    return $product;
}

function s18CatalogVersion(int $product, string $version, array $channels = ['manual'], string $risk = 'low', string $status = 'published', ?string $releasedAt = '2026-10-18 01:00:00'): int
{
    return DB::table('skill_versions')->insertGetId([
        'product_id' => $product, 'version' => $version, 'channel' => 'stable',
        'status' => $status, 'delivery_channels' => json_encode($channels),
        'risk_level' => $risk, 'released_at' => $releasedAt,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function s18SearchIds(array $filters = []): array
{
    return collect(getJson(route('shop.api.skill-catalog.search', ['q' => 'S18-'] + $filters))
        ->assertOk()->json('data'))->pluck('id')->map('intval')->all();
}

beforeEach(function () {
    // 本批会临时改变分类配置，必须使用独立测试库，禁止落到下载副本/业务库。
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDatabaseName())->toMatch('/(?:_test|_testing)$/');
});

it('分类缺失时技能市场返回空货架，不能把全商城当作技能供给', function () {
    $skill = s18CatalogProduct();
    $hardware = s18CatalogProduct(false);
    s18CatalogVersion($skill, '1.0.0');
    s18CatalogVersion($hardware, '1.0.0');
    DB::table('category_translations')->where('slug', 'skill')->update(['slug' => 's18-hidden-skill']);

    expect(s18SearchIds())->toBe([]);
});

it('技能分类存在时仍隔离其它分类的已发布商品', function () {
    $skill = s18CatalogProduct();
    $hardware = s18CatalogProduct(false);
    s18CatalogVersion($skill, '1.0.0');
    s18CatalogVersion($hardware, '1.0.0');

    expect(s18SearchIds())->toContain($skill)->not->toContain($hardware);
});

it('无筛选的市场也不展示没有发布版本或只有草稿的技能', function () {
    $noVersion = s18CatalogProduct();
    $draft = s18CatalogProduct();
    $published = s18CatalogProduct();
    s18CatalogVersion($draft, '1.0.0', status: 'draft');
    s18CatalogVersion($published, '1.0.0');

    expect(s18SearchIds())->toContain($published)->not->toContain($noVersion)->not->toContain($draft);
});

it('旧发布版本的通道和风险不能使不满足条件的当前版本继续命中', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '1.0.0', ['manual'], 'low');
    s18CatalogVersion($product, '2.0.0', ['agent'], 'high', releasedAt: '2026-10-18 02:00:00');

    expect(s18SearchIds(['channel' => 'manual']))->not->toContain($product)
        ->and(s18SearchIds(['risk' => 'low']))->not->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent', 'risk' => 'high']))->toContain($product);
});

it('发布时间比创建顺序优先，回填的旧版本不能替代当前发布', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '2.0.0', ['agent'], releasedAt: '2026-10-18 02:00:00');
    s18CatalogVersion($product, '1.0.0', ['manual']);

    expect(s18SearchIds(['channel' => 'manual']))->not->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->toContain($product);
});

it('发布时间相同以较大的版本记录 id 确定当前版本', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '1.0.0');
    s18CatalogVersion($product, '2.0.0', ['agent']);

    expect(s18SearchIds(['channel' => 'manual']))->not->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->toContain($product);
});

it('两个发布时间都为空的历史版本仍按 id 确定当前版本', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '1.0.0', releasedAt: null);
    s18CatalogVersion($product, '2.0.0', ['agent'], releasedAt: null);

    expect(s18SearchIds(['channel' => 'manual']))->not->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->toContain($product);
});

it('有发布时间的版本优先于后插入的无日期历史版本', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '2.0.0', ['agent']);
    s18CatalogVersion($product, '1.0.0', releasedAt: null);

    expect(s18SearchIds(['channel' => 'manual']))->not->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->toContain($product);
});

it('较新的草稿与归档不遮蔽仍在发布的版本', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '1.0.0');
    s18CatalogVersion($product, '2.0.0', ['agent'], status: 'draft', releasedAt: '2026-10-18 03:00:00');
    s18CatalogVersion($product, '3.0.0', ['agent'], status: 'archived', releasedAt: '2026-10-18 04:00:00');

    expect(s18SearchIds(['channel' => 'manual']))->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->not->toContain($product);
});

it('空通道数组与兼容性检查保持缺省人工通道的同一口径', function () {
    $product = s18CatalogProduct();
    s18CatalogVersion($product, '1.0.0', []);

    expect(s18SearchIds(['channel' => 'manual']))->toContain($product)
        ->and(s18SearchIds(['channel' => 'agent']))->not->toContain($product);
});

it('筛选字段传入数组应返回校验错误而非服务异常', function (string $field) {
    getJson(route('shop.api.skill-catalog.search', [$field => ['invalid']]))->assertUnprocessable();
})->with(['q', 'model', 'channel', 'motion', 'risk']);
