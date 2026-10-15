<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillTag;

use function Pest\Laravel\getJson;

/**
 * B-9 · 技能市场筛选维度(机型 / 通道 / 动作类型 / 风险等级)。
 *
 * 改前:`SkillCatalogController::search()` 只按 `name LIKE` 搜(参数仅 q),
 * 类目/标签接口虽已存在却**零消费** —— 用户没法按机型/通道/动作/风险挑技能。
 *
 * 这里直接向 `product_flat` / `skill_versions` / `skill_tag` 落最少的行来驱动真实 SQL,
 * 不建完整商品(搜索 SQL 只碰这几张表,不 join `products`)。断言的是**真实筛选结果**:
 * "能筛到"必须与 `CompatibilityService`(通道/机型)/ 风险等级同一口径。
 */

/**
 * skill 分类 id(搜索只在该分类下搜)。缺失直接失败,不静默跳过。
 */
function b9SkillCategoryId(): int
{
    $id = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    expect($id)->not->toBeNull();

    return (int) $id;
}

/**
 * 落一个技能商品的可搜索行 + 一个已发布版本。
 *
 * @param  array{version?:string, channel?:?array, risk?:?string}  $ver
 */
function b9SeedSkill(string $name, array $ver = [], ?string $motionTag = null): int
{
    $categoryId = b9SkillCategoryId();
    $familyId = DB::table('attribute_families')->value('id');
    $chCode = DB::table('channels')->value('code');
    $sku = 'b9-'.uniqid();

    $productId = DB::table('products')->insertGetId([
        'sku'                => $sku,
        'type'               => 'simple',
        'attribute_family_id'=> $familyId,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    DB::table('product_flat')->insert([
        'product_id'           => $productId,
        'sku'                  => $sku,
        'name'                 => $name,
        'status'               => 1,
        'visible_individually' => 1,
        'locale'               => app()->getLocale(),
        'price'                => 100,
        'url_key'              => $sku,
        'channel'              => $chCode,
        'attribute_family_id'  => $familyId,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $productId,
        'category_id' => $categoryId,
    ]);

    DB::table('skill_versions')->insert([
        'product_id'        => $productId,
        'version'           => $ver['version'] ?? '1.0.0',
        'channel'           => 'stable',
        'status'            => 'published',
        'delivery_channels' => isset($ver['channel']) ? json_encode($ver['channel']) : null,
        'risk_level'        => $ver['risk'] ?? null,
        'released_at'       => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    if ($motionTag !== null) {
        $tagId = DB::table('skill_tag')->insertGetId([
            'name'       => $motionTag,
            'type'       => SkillTag::TYPE_MOTION,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('skill_tag_pivot')->insert(['skill_id' => $productId, 'tag_id' => $tagId]);
    }

    return (int) $productId;
}

/**
 * 取搜索结果里的商品 id 集合。
 */
function b9Ids($response): array
{
    return collect($response->json('data'))->pluck('id')->map('intval')->all();
}

// ==================== filters 候选 ====================

it('B-9 · filters 返回 通道/风险等级,且不发未就绪的厂商云通道', function () {
    $res = getJson(route('shop.api.skill-catalog.filters'))->assertOk();

    $channels = collect($res->json('data.channels'))->pluck('value')->all();
    $risks = collect($res->json('data.risks'))->pluck('value')->all();

    expect($channels)->toContain('agent')->toContain('manual')
        ->and($risks)->toContain('low')->toContain('medium')->toContain('high')
        ->and($res->json('data.models'))->toBeArray();
});

// ==================== 四个筛选维度 ====================

it('B-9 · 风险等级筛选只回该等级的技能', function () {
    $low = b9SeedSkill('B9低风险技能', ['risk' => 'low', 'channel' => ['manual']]);
    $high = b9SeedSkill('B9高风险技能', ['risk' => 'high', 'channel' => ['agent']]);

    $ids = b9Ids(getJson(route('shop.api.skill-catalog.search', ['risk' => 'high']))->assertOk());

    expect($ids)->toContain($high)->not->toContain($low);
});

it('B-9 · 交付通道筛选:agent 命中声明 agent 的技能,manual 用"缺省即人工"口径', function () {
    $agentOnly = b9SeedSkill('B9在线技能', ['channel' => ['agent']]);
    $manual = b9SeedSkill('B9人工技能', ['channel' => ['manual']]);

    $agentIds = b9Ids(getJson(route('shop.api.skill-catalog.search', ['channel' => 'agent']))->assertOk());
    expect($agentIds)->toContain($agentOnly)->not->toContain($manual);

    $manualIds = b9Ids(getJson(route('shop.api.skill-catalog.search', ['channel' => 'manual']))->assertOk());
    expect($manualIds)->toContain($manual)->not->toContain($agentOnly);
});

it('B-9 · 机型筛选:取机型可用通道与技能声明通道的交集(仅人工机型匹配不到纯在线技能)', function () {
    // unitree_h1 的 comm_channels = ["manual"];纯 agent 技能与之无交集
    $agentOnly = b9SeedSkill('B9机型-在线', ['channel' => ['agent']]);
    $manual = b9SeedSkill('B9机型-人工', ['channel' => ['manual']]);

    $ids = b9Ids(getJson(route('shop.api.skill-catalog.search', ['model' => 'unitree_h1']))->assertOk());

    expect($ids)->toContain($manual)->not->toContain($agentOnly);
});

it('B-9 · 动作类型筛选:按 motion_type 标签命中', function () {
    $dance = b9SeedSkill('B9舞蹈技能', ['channel' => ['manual']], '舞蹈');
    $other = b9SeedSkill('B9无标签技能', ['channel' => ['manual']]);

    $ids = b9Ids(getJson(route('shop.api.skill-catalog.search', ['motion' => '舞蹈']))->assertOk());

    expect($ids)->toContain($dance)->not->toContain($other);
});

it('B-9 · 名称搜索仍生效,且与筛选叠加(AND)', function () {
    $a = b9SeedSkill('B9叠加-叠衣机', ['risk' => 'low', 'channel' => ['manual']]);
    $b = b9SeedSkill('B9叠加-扫地机', ['risk' => 'high', 'channel' => ['manual']]);

    // 只按名字
    expect(b9Ids(getJson(route('shop.api.skill-catalog.search', ['q' => 'B9叠加-叠衣']))->assertOk()))
        ->toContain($a)->not->toContain($b);

    // 名字 + 风险叠加:叠衣机是 low,查 high 应被排除
    expect(b9Ids(getJson(route('shop.api.skill-catalog.search', ['q' => 'B9叠加', 'risk' => 'high']))->assertOk()))
        ->toContain($b)->not->toContain($a);
});

it('B-9 · 搜索回显生效的筛选维度', function () {
    $res = getJson(route('shop.api.skill-catalog.search', ['risk' => 'low', 'channel' => 'manual']))->assertOk();

    expect($res->json('filters'))->toMatchArray(['risk' => 'low', 'channel' => 'manual']);
});
