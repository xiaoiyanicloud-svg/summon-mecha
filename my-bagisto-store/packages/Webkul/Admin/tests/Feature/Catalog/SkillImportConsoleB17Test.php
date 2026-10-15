<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * B-17 · 平台端「技能导入」入口。
 *
 * 改前:导入器(宇树 UniStore / 通用动捕)与注册表都实现了,但**唯一入口是
 * internal.key 的 `POST /api/skill-supply/import`** —— Admin 与开发者门户都没有导入
 * UI/路由,运营只能敲 curl。
 *
 * 这里覆盖后台导入台:页面可渲染、导入留痕、可选挂商品落草稿版本、输入非法时的挡拒。
 * 走的是与 API 通道**同一份** `SkillImportService`(不存在两套实现)。
 */

function b17SeedSkillProduct(): int
{
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
    $familyId = DB::table('attribute_families')->value('id');
    $sku = 'b17-'.uniqid();

    $productId = (int) DB::table('products')->insertGetId([
        'sku'                 => $sku,
        'type'                => 'simple',
        'attribute_family_id' => $familyId,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $productId,
        'category_id' => $categoryId,
    ]);

    return $productId;
}

function b17UnitreeInput(): string
{
    return json_encode([
        'name'             => 'B17 宇树迎宾',
        'version'          => '1.2.3',
        'base_image'       => 'registry.example.com/g1:1.2.3',
        'startup_commands' => ['./run.sh'],
        'permissions'      => ['camera'],
    ], JSON_UNESCAPED_UNICODE);
}

it('B-17 · 导入台页面可渲染(Admin 有入口)', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skills.import'))
        ->assertOk()
        ->assertSeeText('技能导入');
});

it('B-17 · 导入宇树包(不挂商品):落 import 留痕,映射为中立清单', function () {
    $this->loginAsAdmin();

    post(route('admin.catalog.skills.import.store'), [
        'metadata'   => b17UnitreeInput(),
        'source_ref' => 'https://unistore.example.com/app/1',
    ])->assertRedirect(route('admin.catalog.skills.import'));

    $row = DB::table('skill_import')->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->source_format)->toBe('unitree_unistore')
        ->and($row->source_ref)->toBe('https://unistore.example.com/app/1')
        ->and($row->status)->toBe('mapped');

    // mapped_manifest 落了中立清单(宇树 → kind=app)
    expect(json_decode($row->mapped_manifest, true)['kind'])->toBe('app');
});

it('B-17 · 导入并挂商品:落一条草稿版本,机型/通道按导入器的自动收紧', function () {
    $this->loginAsAdmin();
    $productId = b17SeedSkillProduct();

    post(route('admin.catalog.skills.import.store'), [
        'metadata'   => b17UnitreeInput(),
        'product_id' => $productId,
    ])->assertRedirect(route('admin.catalog.skills.import'));

    $version = SkillVersion::where('product_id', $productId)->where('version', '1.2.3')->first();

    expect($version)->not->toBeNull()
        ->and($version->status)->toBe(SkillVersion::STATUS_DRAFT)
        // 宇树容器只跑宇树 → 导入器锁定通道 agent/manual
        ->and($version->delivery_channels)->toBe(['agent', 'manual'])
        ->and($version->risk_level)->toBe('medium')
        // D3:买断制用 pricing_mode 表达
        ->and($version->pricing_mode)->toBe('buyout');
});

it('B-17 · 非法 JSON → 被挡下、回退,且不留痕', function () {
    $this->loginAsAdmin();

    post(route('admin.catalog.skills.import.store'), ['metadata' => '这不是 JSON'])
        ->assertRedirect(route('admin.catalog.skills.import'))
        ->assertSessionHas('error');

    expect(DB::table('skill_import')->count())->toBe(0);
});

it('B-17 · 无法识别的格式 → 明确报错,不留痕', function () {
    $this->loginAsAdmin();

    post(route('admin.catalog.skills.import.store'), [
        'metadata' => json_encode(['name' => '来路不明的包', 'foo' => 'bar']),
    ])->assertRedirect(route('admin.catalog.skills.import'))
        ->assertSessionHas('error');

    expect(DB::table('skill_import')->count())->toBe(0);
});
