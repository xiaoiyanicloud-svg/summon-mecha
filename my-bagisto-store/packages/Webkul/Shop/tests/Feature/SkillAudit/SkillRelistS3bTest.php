<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\SkillAudit\SkillPublisher;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 任务卡 S-3b · 复上架 500 修复：商品级 relist + 必过闸门。
 *
 * ── 病灶（已实测） ──
 *   下架 = **商品级停售**（product.status=0 / visible_individually=0），
 *   而 `skill_versions.status` 仍是 published。再点「发布」会撞状态机
 *   `published → published`，抛 RuntimeException → 两条 HTTP 通道都 **500**。
 *
 * ── 修法（拍板） ──
 *   复上架做成**商品级 relist**，复用 ReviewGate + 包指纹自动判定要不要重新过审；
 *   绝不给状态机加 `published → published`。熔断生效中禁止复上架。
 *   下架只停新增销售，**不回收已售授权**。
 *
 * 本文件的断言全部走真实服务/路由（不 mock），包括 `product_flat` 字段与市场可售性。
 */

function s3bHeaders(): array
{
    return ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json'];
}

function s3bLocale(): string
{
    return (string) (optional(core()->getDefaultChannel()->default_locale)->code
        ?: core()->getDefaultChannelCode());
}

/** 建一个真实商品（EAV + flat），初始 status=0（与开发者建技能一致）。 */
function s3bProduct(string $name = 'S3B 复上架技能'): int
{
    Event::dispatch('catalog.product.create.before');

    $product = app(ProductRepository::class)->create([
        'type'                => 'simple',
        'attribute_family_id' => (int) (DB::table('attribute_families')->where('code', 'default')->value('id')
            ?: DB::table('attribute_families')->value('id') ?: 1),
        'sku'                 => 'S3B'.strtoupper(Str::random(10)),
    ]);

    Event::dispatch('catalog.product.create.after', $product);

    s3bUpdateProduct((int) $product->id, $name, 0, 1);

    return (int) $product->id;
}

/** 走商品更新通道（带事件三步），让 EAV 与 product_flat 同步。 */
function s3bUpdateProduct(int $productId, string $name, int $status, int $visible): void
{
    $repository = app(ProductRepository::class);
    $product = $repository->find($productId);

    $categories = $product->categories->pluck('id')->all();

    if ($categories === []) {
        $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

        if ($categoryId) {
            $categories = [(int) $categoryId];
        }
    }

    Event::dispatch('catalog.product.update.before', $productId);

    $repository->update([
        'name'                 => $name,
        'url_key'              => 's3b-'.$productId,
        'price'                => 199,
        'status'               => $status,
        'visible_individually' => $visible,
        'channel'              => core()->getDefaultChannelCode(),
        'locale'               => s3bLocale(),
        'categories'           => $categories,
    ], $productId);

    Event::dispatch('catalog.product.update.after', $product->fresh());
}

/** 造一个"已过 L0+L2、待发布"的版本，交付物真实落盘，审核记录带包指纹。 */
function s3bVersion(int $productId, string $bytes = 'S3B-PACKAGE-BYTES'): SkillVersion
{
    $version = SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_IN_REVIEW,
        'license_mode' => 'per_device',
    ]);

    $path = 'skills/s3b/'.uniqid().'.skill';
    Storage::disk(SkillAsset::disk())->put($path, $bytes);

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => $path,
        'file_name'        => 's3b.skill',
        'file_size'        => strlen($bytes),
        'file_hash'        => hash('sha256', $bytes),
    ]);

    $fingerprint = app(PackageFingerprint::class)->forVersion((int) $version->id);

    foreach ([AuditRecord::LAYER_MACHINE, AuditRecord::LAYER_HUMAN] as $layer) {
        AuditRecord::create([
            'skill_id'            => $productId,
            'version'             => $version->id,
            'audit_type'          => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
            'layer'               => $layer,
            'result'              => AuditRecord::RESULT_PASS,
            'submitted_at'        => now(),
            'audited_at'          => now(),
            'package_fingerprint' => $fingerprint,
        ]);
    }

    return $version->fresh('assets');
}

/** 下架 = 商品级停售（status=0 + visible_individually=0），**版本状态不动**。 */
function s3bDownShelve(int $productId, string $name): void
{
    s3bUpdateProduct($productId, $name, 0, 0);
}

function s3bFlat(int $productId): ?object
{
    return DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('channel', core()->getDefaultChannelCode())
        ->where('locale', s3bLocale())
        ->first(['status', 'visible_individually']);
}

/** 跑第一次发布（internal.key 通道），返回发布后的版本。 */
function s3bPublish(SkillVersion $version): SkillVersion
{
    post(route('shop.api.skill-admin.versions.publish', $version->id), [], s3bHeaders())->assertOk();

    return $version->fresh('assets');
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
    config([
        'skill_delivery.upload_key'  => 'test-internal-key',
        'skill_audit.required_layers' => [1, 3],
    ]);
});

it('a) 发布 → 下架 → relist：成功复售，版本仍 published，商品 status/visible 回到 1', function () {
    $name = 'S3B 复售'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bVersion($productId);

    $version = s3bPublish($version);

    expect($version->status)->toBe(SkillVersion::STATUS_PUBLISHED);
    expect((int) s3bFlat($productId)->status)->toBe(1)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(1);

    s3bDownShelve($productId, $name);

    expect((int) s3bFlat($productId)->status)->toBe(0)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(0)
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    actingAs(Admin::factory()->create(['role_id' => 1]), 'admin');

    post(route('admin.catalog.skills.versions.relist', $version->id))->assertRedirect();

    expect(session('error'))->toBeNull()
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and((int) s3bFlat($productId)->status)->toBe(1)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(1);
});

it('b) relist 后已售授权一条不少（数量与内容不变）', function () {
    $name = 'S3B 授权'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bVersion($productId);
    $version = s3bPublish($version);

    $customer = Customer::create([
        'first_name'  => 'S3B',
        'last_name'   => 'Buyer',
        'email'       => 's3b'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'S3B'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => 199,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => random_int(1, 2000000000),
        'product_id'       => $productId,
        'skill_version_id' => $version->id,
        'status'           => SkillEntitlement::STATUS_ACTIVE,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);

    $before = SkillEntitlement::query()
        ->where('product_id', $productId)
        ->orderBy('id')
        ->get(['id', 'customer_id', 'product_id', 'skill_version_id', 'status', 'license_key', 'activations'])
        ->toArray();

    s3bDownShelve($productId, $name);

    $result = app(SkillPublisher::class)->relist($version->fresh());

    expect($result['ok'])->toBeTrue()
        ->and($result['code'])->toBe(SkillPublisher::OK_RELISTED);

    $after = SkillEntitlement::query()
        ->where('product_id', $productId)
        ->orderBy('id')
        ->get(['id', 'customer_id', 'product_id', 'skill_version_id', 'status', 'license_key', 'activations'])
        ->toArray();

    // 数量 + 逐条内容都不变（下架只停新增销售，不回收已售授权）。
    expect($after)->toBe($before)
        ->and($after)->toHaveCount(1);
});

it('c) 包被换过（指纹不一致）→ relist 被拒 PACKAGE_CHANGED_AFTER_REVIEW，商品仍不可售', function () {
    $name = 'S3B 换包'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bVersion($productId);
    $version = s3bPublish($version);

    s3bDownShelve($productId, $name);

    // 模拟"提审之后又换了一次包"：交付物记录的 file_hash 变了，审核记录上的指纹没变。
    SkillAsset::where('skill_version_id', $version->id)->update([
        'file_hash' => hash('sha256', 'SWAPPED-PACKAGE'),
    ]);

    $result = app(SkillPublisher::class)->relist($version->fresh('assets'));

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe(SkillPublisher::ERR_PACKAGE_CHANGED_AFTER_REVIEW)
        ->and((int) s3bFlat($productId)->status)->toBe(0)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(0);
});

it('d) 熔断生效中 → relist 被拒 SKILL_BLOCKED，商品仍不可售', function () {
    $name = 'S3B 熔断'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bVersion($productId);
    $version = s3bPublish($version);

    s3bDownShelve($productId, $name);

    app(KillSwitchService::class)->engage($productId, SkillBlocklist::ACTION_BLOCK_ALL, 'pest:s3b', 'high');

    $result = app(SkillPublisher::class)->relist($version->fresh('assets'));

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe(SkillPublisher::ERR_SKILL_BLOCKED)
        ->and($result['message'])->toContain('熔断')
        ->and((int) s3bFlat($productId)->status)->toBe(0)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(0);
});

it('e) 已在售 → relist 幂等提示 ALREADY_SELLABLE，不 500、状态不变', function () {
    $name = 'S3B 幂等'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bPublish(s3bVersion($productId));

    $beforeStatus = $version->fresh()->status;
    $beforeFlat = s3bFlat($productId);

    $result = app(SkillPublisher::class)->relist($version->fresh('assets'));

    expect($result['ok'])->toBeTrue()
        ->and($result['code'])->toBe(SkillPublisher::ALREADY_SELLABLE)
        ->and($version->fresh()->status)->toBe($beforeStatus)
        ->and((int) s3bFlat($productId)->status)->toBe((int) $beforeFlat->status)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe((int) $beforeFlat->visible_individually);

    // HTTP 通道同样不 500（幂等提示走成功分支）。
    actingAs(Admin::factory()->create(['role_id' => 1]), 'admin');

    post(route('admin.catalog.skills.versions.relist', $version->id))->assertRedirect();

    expect((int) s3bFlat($productId)->status)->toBe(1);
});

it('f) 闸门未过（审核记录被删）→ relist 被拒 GATE_NOT_PASSED，商品仍不可售', function () {
    $name = 'S3B 闸门'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bVersion($productId);
    $version = s3bPublish($version);

    s3bDownShelve($productId, $name);

    AuditRecord::query()->forVersion($productId, (int) $version->id)->delete();

    $result = app(SkillPublisher::class)->relist($version->fresh('assets'));

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe(SkillPublisher::ERR_GATE_NOT_PASSED)
        ->and((int) s3bFlat($productId)->status)->toBe(0)
        ->and((int) s3bFlat($productId)->visible_individually)->toBe(0);
});

it('g) 旧路径：对已 published 版本再 publish → VERSION_ALREADY_PUBLISHED，两条 HTTP 通道都不再 500', function () {
    $name = 'S3B 旧路径'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bPublish(s3bVersion($productId));

    // 服务层：明确结果码（不是异常）。
    $result = app(SkillPublisher::class)->publish($version->fresh('assets'));

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe(SkillPublisher::ERR_VERSION_ALREADY_PUBLISHED)
        ->and($result['message'])->toContain('重新上架');

    // 后台通道：302 回编辑页 + 可读的错误文案（原先这里 500）。
    actingAs(Admin::factory()->create(['role_id' => 1]), 'admin');

    post(route('admin.catalog.skills.versions.publish', $version->id))->assertRedirect();

    expect(session('error'))->toContain('重新上架');

    // internal.key 通道：422 + 可识别的错误码（原先这里 500）。
    post(route('shop.api.skill-admin.versions.publish', $version->id), [], s3bHeaders())
        ->assertStatus(422)
        ->assertJson(['success' => false, 'code' => 'VERSION_ALREADY_PUBLISHED']);

    // 两条通道都没改版本状态。
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);
});

it('R3 界面：已发布且商品不可售时显示「重新上架」，可售时不显示', function () {
    $name = 'S3B 界面'.substr(uniqid(), -6);
    $productId = s3bProduct($name);
    $version = s3bPublish(s3bVersion($productId));

    actingAs(Admin::factory()->create(['role_id' => 1]), 'admin');

    // 已可售 → 不显示复上架入口。
    get(route('admin.catalog.skills.edit', $productId))
        ->assertOk()
        ->assertDontSee('重新上架');

    s3bDownShelve($productId, $name);

    // 已发布 + 商品不可售 → 显示入口，并说明仍需闸门与包指纹校验。
    $response = get(route('admin.catalog.skills.edit', $productId))->assertOk();

    expect($response->getContent())
        ->toContain('重新上架')
        ->toContain(route('admin.catalog.skills.versions.relist', $version->id))
        ->toContain('复上架仍需过闸门与包指纹校验');
});
