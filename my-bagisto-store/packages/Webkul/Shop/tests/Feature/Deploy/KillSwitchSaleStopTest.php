<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Deploy\KillSwitchService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 任务卡 S-8 · 熔断必须**真的止得住**:停销售 + 停下载(紧急止损闭环)。
 *
 * 缺口(S-8 标题即靶心):`KillSwitchService::isBlocked()` 全仓只有一处读点
 * (`DeployTaskService::assertNotBlocked`,拦"建新下发任务")。于是熔断只挡住了
 * "自动下发"一条路 —— 被熔断的技能**仍出现在市场**、**仍能下单发授权**、
 * **人工通道的包仍能下给新用户**。对当前唯一真能跑通的 `manual` 通道,
 * 熔断既不退钱也不拦下载。
 *
 * 熔断强度是**递增阶梯**(见 SkillBlocklist 的 ACTION_* 注释):
 *   block_new  ⊂  block_all  ⊂  force_uninstall / force_rollback
 * 本文件按阶梯逐档钉死各读取点的行为:
 *   'new'/'sale'(新增流通/销售)  —— 任何生效熔断都拦(市场不展示 + 不可下单);
 *   'download'(既有授权下载)     —— 仅 block_all 及以上才拦(block_new 不停)。
 *
 * 判定收敛在 `KillSwitchService::isBlockedFor()`(唯一读入口),四个调用点各问各的"门"。
 */
// ==================== 造数据 ====================

function s8Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'S8',
        'last_name'   => 'SaleStop',
        'email'       => 's8'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** skill 分类 id(市场搜索只在该分类下搜)。缺失直接失败,不静默跳过。 */
function s8SkillCategoryId(): int
{
    $id = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    expect($id)->not->toBeNull();

    return (int) $id;
}

/** 落一个技能商品(可搜索行)+ 一个已发布版本。返回 products.id。 */
function s8SeedSkill(string $name, string $channel = 'manual'): int
{
    $categoryId = s8SkillCategoryId();
    $familyId = DB::table('attribute_families')->value('id');
    $chCode = DB::table('channels')->value('code');
    $sku = 's8-'.uniqid();

    $productId = DB::table('products')->insertGetId([
        'sku'                 => $sku,
        'type'                => 'simple',
        'attribute_family_id' => $familyId,
        'created_at'          => now(),
        'updated_at'          => now(),
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
        'version'           => '1.0.0',
        'channel'           => 'stable',
        'status'            => 'published',
        'delivery_channels' => json_encode([$channel]),
        'released_at'       => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    return (int) $productId;
}

function s8VersionId(int $productId): int
{
    return (int) DB::table('skill_versions')->where('product_id', $productId)->value('id');
}

/** 给某个版本挂一个真实文件交付物(前提:Storage 已 fake)。返回 skill_assets.id。 */
function s8AttachAsset(int $versionId): int
{
    $path = 'skills/s8/'.uniqid().'.skill';
    Storage::disk(SkillAsset::disk())->put($path, 'SKILL-BYTES');

    return (int) DB::table('skill_assets')->insertGetId([
        'skill_version_id' => $versionId,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => $path,
        'file_name'        => 'demo.skill',
        'file_size'        => 11,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
}

/** 造一条"已支付 + 可用"的授权(付费墙与 isUsable 都越过)。 */
function s8PaidEntitlement(Customer $customer, int $productId, int $versionId): SkillEntitlement
{
    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'S8'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing', // 已支付,越过付费墙
        'grand_total'  => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => random_int(1, 2000000000),
        'product_id'       => $productId,
        'skill_version_id' => $versionId,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);
}

/** 一站式:fake 盘 + 挂交付物 + 造授权。返回 [entitlement, assetId]。 */
function s8Downloadable(Customer $customer, int $productId): array
{
    Storage::fake(SkillAsset::disk());

    $versionId = s8VersionId($productId);
    $assetId = s8AttachAsset($versionId);
    $entitlement = s8PaidEntitlement($customer, $productId, $versionId);

    return [$entitlement, $assetId];
}

function s8Engage(int $productId, string $action = SkillBlocklist::ACTION_BLOCK_ALL): array
{
    return app(KillSwitchService::class)->engage($productId, $action, 'pest:s8-sale-stop', 'high');
}

/**
 * 建一个**真实**商品（走工厂，带 EAV 属性值）并挂到指定 slug 的分类下，返回 products.id。
 *
 * ⚠ 为什么不能用 `s8SeedSkill`（只写 product_flat）：首页聚合 `/api/tob-catalog` 走
 *   `ProductRepository::getAll`（native 数据层，读 `product_attribute_values` 的 EAV），
 *   **不读 product_flat** —— 只写真 flat 的商品在这个接口里根本不出现。
 */
function s8FactoryProductInCategory(string $slug): int
{
    $category = \Webkul\Category\Models\Category::query()
        ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
        ->firstOrFail();

    $product = (new \Webkul\Faker\Helpers\Product)
        ->getSimpleProductFactory()
        ->hasAttached($category)
        ->create();

    return (int) $product->id;
}


// ==================== 1 · 市场与详情(停售) ====================

it('S-8 · 熔断后:市场搜索搜不到该技能(查询层排除)', function () {
    $name = 'S8市场技能'.uniqid();
    $productId = s8SeedSkill($name);

    // 熔断前:搜得到
    expect(collect(getJson(route('shop.api.skill-catalog.search', ['q' => $name]))->assertOk()->json('data'))
        ->pluck('id')->map('intval')->all())
        ->toContain($productId);

    s8Engage($productId);

    // 熔断后:搜不到(缓存/过滤都不能漏——这里直接就没了)
    expect(collect(getJson(route('shop.api.skill-catalog.search', ['q' => $name]))->assertOk()->json('data'))
        ->pluck('id')->map('intval')->all())
        ->not->toContain($productId);
});

it('S-8 · 熔断后:详情**明确报错**(SKILL_BLOCKED),不是静默 404', function () {
    $productId = s8SeedSkill('S8详情技能'.uniqid());

    // 熔断前:详情正常
    getJson(route('shop.api.skill-catalog.versions', ['productId' => $productId]))->assertOk();

    s8Engage($productId);

    // 熔断后:明确报错,带码 + 人话;且**不是** 404(静默消失会让用户以为是网络/平台坏了)
    $res = getJson(route('shop.api.skill-catalog.versions', ['productId' => $productId]));

    $res->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SKILL_BLOCKED');

    expect($res->json('message'))->toContain('下架')
        ->and($res->status())->not->toBe(404);
});

// ==================== 2 · 下单与授权(停售的最后一道闸) ====================

it('S-8 · 熔断后:下单被拦(最早入口),且不产生新的 skill_entitlement', function () {
    $customer = s8Customer();
    $productId = s8SeedSkill('S8下单技能'.uniqid());

    s8Engage($productId);

    actingAs($customer, 'customer');

    postJson('/api/tob-order', [
        'type' => 'skill', 'product_id' => $productId, 'qty' => 1,
        'name' => '张三', 'phone' => '13800000000',
        'city' => '上海', 'address' => '某路1号',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'SKILL_BLOCKED');

    // fail-closed:一张授权都不该产生;这单也不该建(该客户名下无任何订单)
    expect(SkillEntitlement::where('product_id', $productId)->count())->toBe(0)
        ->and(DB::table('orders')->where('customer_id', $customer->id)->count())->toBe(0);
});

it('S-8 · 兜底:SkillDelivery 在创建授权处 fail-closed(下单源不止一条)', function () {
    $customer = s8Customer();
    $productId = s8SeedSkill('S8兜底技能'.uniqid());

    // 直接造订单 + 技能条目,模拟"绕过 tob-order 的另一个下单源"
    $buildItem = function () use ($customer, $productId): int {
        $orderId = DB::table('orders')->insertGetId([
            'increment_id' => 'S8L'.uniqid(),
            'customer_id'  => $customer->id,
            'status'       => 'pending',
            'grand_total'  => 100,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return (int) DB::table('order_items')->insertGetId([
            'order_id'    => $orderId,
            'product_id'  => $productId,
            'type'        => 'simple',
            'name'        => '技能包',
            'sku'         => 'S8I'.uniqid(),
            'qty_ordered' => 1,
            'price'       => 100,
            'total'       => 100,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    };

    $listener = app(SkillDelivery::class);

    // 未熔断:这条路径会正常发授权
    $itemUnblocked = $buildItem();
    $orderA = OrderProxy::modelClass()::find(DB::table('order_items')->where('id', $itemUnblocked)->value('order_id'));
    $listener->afterOrderCreated($orderA);

    expect(SkillEntitlement::where('order_item_id', $itemUnblocked)->exists())->toBeTrue();

    // 熔断后:同一路径**不再**产生新授权(fail-closed)
    s8Engage($productId);

    $itemBlocked = $buildItem();
    $orderB = OrderProxy::modelClass()::find(DB::table('order_items')->where('id', $itemBlocked)->value('order_id'));
    $listener->afterOrderCreated($orderB);

    expect(SkillEntitlement::where('order_item_id', $itemBlocked)->exists())->toBeFalse();
});

// ==================== 3 · 阶梯:block_new 不停既有下载 ====================

it('S-8 · block_new 下:已授权用户的 download **仍可**(只停新增)', function () {
    $customer = s8Customer();
    $productId = s8SeedSkill('S8阶梯-new'.uniqid());
    [$entitlement, $assetId] = s8Downloadable($customer, $productId);

    s8Engage($productId, SkillBlocklist::ACTION_BLOCK_NEW);

    actingAs($customer, 'customer');

    get(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => $assetId]))
        ->assertOk();

    // assets 列表也不该报"暂停分发"
    getJson(route('shop.api.skills.assets', ['entitlementId' => $entitlement->id]))
        ->assertOk()
        ->assertJsonPath('blocked', false);
});

// ==================== 4 · 阶梯:block_all 停既有下载 ====================

it('S-8 · block_all 下:已授权用户的 download 被拒,assets 给出明确提示', function () {
    $customer = s8Customer();
    $productId = s8SeedSkill('S8阶梯-all'.uniqid());
    [$entitlement, $assetId] = s8Downloadable($customer, $productId);

    s8Engage($productId, SkillBlocklist::ACTION_BLOCK_ALL);

    actingAs($customer, 'customer');

    // 下载被拒:明确码 + 人话(提示联系客服处理售后)
    $res = get(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => $assetId]));

    $res->assertStatus(409);
    expect($res->json('code'))->toBe('SKILL_BLOCKED')
        ->and($res->json('message'))->toContain('客服');

    // assets:列表仍展示(权益还在),但明确告知已暂停分发
    getJson(route('shop.api.skills.assets', ['entitlementId' => $entitlement->id]))
        ->assertOk()
        ->assertJsonPath('blocked', true)
        ->assertJsonPath('block_notice', fn ($v) => is_string($v) && str_contains($v, '客服'));
});

// ==================== 5 · 解除熔断后恢复 ====================

it('S-8 · lift 后:市场恢复可见、下载恢复', function () {
    $customer = s8Customer();
    $name = 'S8恢复技能'.uniqid();
    $productId = s8SeedSkill($name);
    [$entitlement, $assetId] = s8Downloadable($customer, $productId);

    s8Engage($productId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 熔断中:市场搜不到、下载被拒
    expect(collect(getJson(route('shop.api.skill-catalog.search', ['q' => $name]))->json('data'))
        ->pluck('id')->map('intval')->all())->not->toContain($productId);

    actingAs($customer, 'customer');
    get(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => $assetId]))
        ->assertStatus(409);

    // 解除
    expect(app(KillSwitchService::class)->lift($productId))->toBeTrue();

    // 市场恢复可见
    expect(collect(getJson(route('shop.api.skill-catalog.search', ['q' => $name]))->assertOk()->json('data'))
        ->pluck('id')->map('intval')->all())->toContain($productId);

    // 详情恢复可用
    getJson(route('shop.api.skill-catalog.versions', ['productId' => $productId]))->assertOk();

    // 下载恢复
    get(route('shop.api.skills.download', ['entitlementId' => $entitlement->id, 'assetId' => $assetId]))
        ->assertOk();
});

// ==================== 6 · 只影响目标技能 ====================

it('S-8 · 只影响目标技能:另一个未熔断技能的市场可见性与下载不受影响', function () {
    $customer = s8Customer();
    // 两个技能共用一个**唯一前缀**,一次搜索即可同时覆盖到,便于断言"一个消失一个还在"
    $prefix = 'S8共存'.uniqid();
    $blockedId = s8SeedSkill($prefix.'-A');
    $okId = s8SeedSkill($prefix.'-B');

    Storage::fake(SkillAsset::disk());
    $blockedAsset = s8AttachAsset(s8VersionId($blockedId));
    $okAsset = s8AttachAsset(s8VersionId($okId));
    $blockedEnt = s8PaidEntitlement($customer, $blockedId, s8VersionId($blockedId));
    $okEnt = s8PaidEntitlement($customer, $okId, s8VersionId($okId));

    s8Engage($blockedId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 市场:目标技能没了,另一个照常在
    $ids = collect(getJson(route('shop.api.skill-catalog.search', ['q' => $prefix]))->assertOk()->json('data'))
        ->pluck('id')->map('intval')->all();

    expect($ids)->not->toContain($blockedId)   // 被熔断 → 消失
        ->and($ids)->toContain($okId);          // 未熔断 → 仍在

    // 下载:目标被拒,另一个照常
    actingAs($customer, 'customer');

    get(route('shop.api.skills.download', ['entitlementId' => $blockedEnt->id, 'assetId' => $blockedAsset]))
        ->assertStatus(409);

    get(route('shop.api.skills.download', ['entitlementId' => $okEnt->id, 'assetId' => $okAsset]))
        ->assertOk();
});

// ==================== 7 · 影响面留痕(人的账) ====================

it('S-8 · engage 记下该技能真实的已售/已装/已下载数,并落入 operation_log', function () {
    $customer = s8Customer();
    $productId = s8SeedSkill('S8影响面技能'.uniqid());
    $versionId = s8VersionId($productId);

    // 3 条授权:1 条已安装(activations>0)、1 条已下载(meta.download_count)、1 条未动
    $installed = s8PaidEntitlement($customer, $productId, $versionId);
    $installed->activations = 1;
    $installed->status = SkillEntitlement::STATUS_ACTIVE;
    $installed->save();

    $downloaded = s8PaidEntitlement($customer, $productId, $versionId);
    $downloaded->meta = ['download_count' => 2];
    $downloaded->save();

    s8PaidEntitlement($customer, $productId, $versionId); // 未动

    $res = s8Engage($productId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 从库真算,不是占位值
    expect($res['sold_entitlements'])->toBe(3)
        ->and($res['installed_entitlements'])->toBe(1)
        ->and($res['downloaded_entitlements'])->toBe(1);

    // 与库里真实授权数一致
    expect($res['sold_entitlements'])->toBe(SkillEntitlement::where('product_id', $productId)->count());

    // 同时落入 operation_log
    $log = OperationLog::query()
        ->where('action', 'killswitch.engage')
        ->where('target_id', $productId)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->payload['sold_entitlements'])->toBe(3)
        ->and((int) $log->payload['installed_entitlements'])->toBe(1)
        ->and((int) $log->payload['downloaded_entitlements'])->toBe(1);
});

// ==================== 8 · 首页聚合(停售面最后一处漏口) ====================
// 首页数据源 `/api/tob-catalog` 的 skill 段此前**没过滤熔断技能**：用户第一眼就能看到、点进去。
// 停售只在搜索接口那一条路生效，首页这条漏了。下面钉死"该段过滤、其它段不受影响"。

/** 取聚合里某个段的商品 id 集合。 */
function s8CatalogSegIds(string $seg): array
{
    $data = getJson(route('shop.api.tob-catalog.index'))->assertOk()->json('data');

    return collect($data[$seg])->pluck('id')->map('intval')->all();
}

it('S-8 · 熔断后:首页聚合 /api/tob-catalog 的技能段不含该技能;买/租段不受影响', function () {
    $skillId = s8FactoryProductInCategory('skill');
    $buyId = s8FactoryProductInCategory('buy');

    // 熔断前：技能段含它
    expect(s8CatalogSegIds('skill'))->toContain($skillId);

    s8Engage($skillId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 熔断后：技能段不含它；买段照常在（熔断只针对技能，不牵连其它段）
    expect(s8CatalogSegIds('skill'))->not->toContain($skillId)
        ->and(s8CatalogSegIds('buy'))->toContain($buyId);
});

it('S-8 · 未熔断时:首页聚合技能段仍含该技能（反向对照，防过滤写宽把技能段清空）', function () {
    $skillId = s8FactoryProductInCategory('skill');

    expect(s8CatalogSegIds('skill'))->toContain($skillId);
});

// ==================== 9 · 买家侧商品面(/api/products) ====================
// 生态中心(EcoCenter.vue)与机器页回退都经 `GET /api/products?category_id=…` 拉技能,
// 详情/关联也走这条面。本段钉死:该面对买家**排除**被熔断技能、详情**明确报错**,
// 且非技能分类与后台查询路径**不受影响**。

function s8CategoryIdBySlug(string $slug): int
{
    return (int) DB::table('category_translations')->where('slug', $slug)->value('category_id');
}

/** `GET /api/products?…` 命中的商品 id 集合。 */
function s8ProductIds(array $query): array
{
    $res = getJson(route('shop.api.products.index', $query))->assertOk();

    return collect($res->json('data'))->pluck('id')->map('intval')->all();
}

it('S-8 · 熔断后:GET /api/products?category_id=技能分类 不含该技能;非技能分类不受影响', function () {
    $skillId = s8FactoryProductInCategory('skill');
    $buyId = s8FactoryProductInCategory('buy');

    $skillCat = s8SkillCategoryId();
    $buyCat = s8CategoryIdBySlug('buy');

    // 熔断前:技能分类列表含它
    expect(s8ProductIds(['category_id' => $skillCat]))->toContain($skillId);

    s8Engage($skillId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 熔断后:技能分类列表不含它;买分类列表照常(按 id 排除天然只影响技能)
    expect(s8ProductIds(['category_id' => $skillCat]))->not->toContain($skillId)
        ->and(s8ProductIds(['category_id' => $buyCat]))->toContain($buyId);
});

it('S-8 · 熔断后:GET /api/products/{id} 打开被熔断技能 → 409 SKILL_BLOCKED（不静默 404）', function () {
    $skillId = s8FactoryProductInCategory('skill');

    // 熔断前:详情正常
    getJson(route('shop.api.products.show', ['id' => $skillId]))->assertOk();

    s8Engage($skillId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 熔断后:明确报错,码 + 人话;且**不是** 404(与 skill-catalog/versions 同一语义)
    $res = getJson(route('shop.api.products.show', ['id' => $skillId]));

    $res->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SKILL_BLOCKED');

    expect($res->status())->not->toBe(404);
});

it('S-8 · 未熔断时:GET /api/products 技能分类列表与详情都正常可见（反向对照）', function () {
    $skillId = s8FactoryProductInCategory('skill');
    $skillCat = s8SkillCategoryId();

    expect(s8ProductIds(['category_id' => $skillCat]))->toContain($skillId);

    getJson(route('shop.api.products.show', ['id' => $skillId]))->assertOk();
});

it('S-8 · 后台查询路径不受影响:不带 exclude_ids 的 getAll 仍返回被熔断技能', function () {
    $skillId = s8FactoryProductInCategory('skill');
    $skillCat = s8SkillCategoryId();

    s8Engage($skillId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 过滤只加在**买家侧**控制器;Admin 的商品列表走 ProductRepository::getAll 且**不传 exclude_ids**
    // —— 故后台仍能看到被熔断的技能(运营要拿它来处置/复盘),这里把这条边界钉死。
    $rows = app(ProductRepository::class)->setSearchEngine('database')->getAll([
        'category_id'          => $skillCat,
        'status'               => 1,
        'visible_individually' => 1,
        'limit'                => 200,
        'channel_id'           => core()->getCurrentChannel()->id,
    ]);

    $ids = collect(method_exists($rows, 'items') ? $rows->items() : $rows)
        ->pluck('id')->map('intval')->all();

    expect($ids)->toContain($skillId);
});

// ==================== 10 · 小召 AI 的取数面 ====================
// fetchCatalog() 把技能库喂给 Coze。熔断是紧急止损,AI 侧也必须排除被熔断技能,
// 否则"一边下架一边导流"(用户点进去撞 409)。

it('S-8 · 熔断后:小召 AI 的技能库(fetchCatalog)不含被熔断技能;机型库不受影响', function () {
    $skillId = s8FactoryProductInCategory('skill');
    $machineId = s8FactoryProductInCategory('rent');

    // fetchCatalog 是 private 且输出只喂给 Coze(外部),无法经公开路由直接观察其取数结果;
    // 用反射直接取它的**真实输出**做行为断言(不是断言"查询带了参数",是断言结果里真的没了)。
    $controller = app(\Webkul\Shop\Http\Controllers\API\SummonAiController::class);

    $fetch = function () use ($controller): array {
        $method = new \ReflectionMethod($controller, 'fetchCatalog');
        $method->setAccessible(true);

        return $method->invoke($controller);
    };

    // 熔断前:技能库含它、机型库含机型
    $before = $fetch();
    expect(collect($before['skills'])->pluck('id')->map('intval')->all())->toContain($skillId)
        ->and(collect($before['machines'])->pluck('id')->map('intval')->all())->toContain($machineId);

    s8Engage($skillId, SkillBlocklist::ACTION_BLOCK_ALL);

    // 熔断后:技能库不含它;机型库照常(熔断只针对技能商品,只过滤 skills 段)
    $after = $fetch();
    expect(collect($after['skills'])->pluck('id')->map('intval')->all())->not->toContain($skillId)
        ->and(collect($after['machines'])->pluck('id')->map('intval')->all())->toContain($machineId);
});
