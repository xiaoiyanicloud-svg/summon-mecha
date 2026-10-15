<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillPackage\ManifestValidator;
use Webkul\Shop\Services\SkillPackage\SkillManifest;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 任务卡 D-6 · schema 版本策略（⑧）。
 *
 * 锁住两件此前缺失的事：
 *   1. **分清来的是什么 schema 版本** —— 此前 ManifestValidator 把 v1/v3/别的命名空间
 *      一律用同一句"必须为 zhaohuan.skill/v2"拒绝，开发者看不出是"太新""太旧"还是"写错了"。
 *      现在按缺失 / 支持 / 太新 / 太旧或停用 / 不认识 分开给提示（四种措辞必须互相可区分）。
 *   2. **schema 落库补齐到所有通道** —— 此前只有 Admin 导入路径写 skill_versions.manifest_schema，
 *      开发者门户通道不写；而 IncidentService 把它当"事故证据快照"读，于是开发者技能出事故时
 *      这一项恒为 NULL。写入点收敛到 submitLayer0（三条通道的唯一漏斗）。
 *
 * 断言尽量用**关键字**而非整句，避免文案微调就红。
 */

// ==================== 造数据 ====================

function msvManifest(array $overrides = []): array
{
    return array_merge([
        'schema'            => SkillManifest::SCHEMA,
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '示例技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
    ], $overrides);
}

/**
 * 用指定的 schema 跑一遍校验，取回 errors（只需 schema 那一类的报错时用）。
 *
 * @return string[]
 */
function msvSchemaErrors(string $schema): array
{
    return app(ManifestValidator::class)->validate(msvManifest(['schema' => $schema]))['errors'];
}

function msvCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'User',
        'email'       => 'msv'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一个合法的 .skill 包（ZIP + manifest.json，schema = zhaohuan.skill/v2）。
 */
function msvPackage(string $name = '版本策略技能'): string
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => $name],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $path = tempnam(sys_get_temp_dir(), 'msv').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('README.txt', 'msv package');
    $zip->close();

    return $path;
}

/**
 * 造一个挂在「技能」分类下的商品（Admin 导入台要求商品在技能分类下才准挂版本）。
 */
function msvSkillProduct(): int
{
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
    $familyId = DB::table('attribute_families')->value('id');

    $productId = (int) DB::table('products')->insertGetId([
        'sku'                 => 'msv-'.uniqid(),
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

// ==================== 1. parseSchemaVersion ====================

it('parseSchemaVersion：本族取出版本号，族外与畸形一律 null', function () {
    expect(SkillManifest::parseSchemaVersion('zhaohuan.skill/v2'))->toBe(2)
        ->and(SkillManifest::parseSchemaVersion('zhaohuan.skill/v10'))->toBe(10);
});

it('parseSchemaVersion：族外命名空间 / 无版本 / 畸形格式 → null', function () {
    expect(SkillManifest::parseSchemaVersion('other/thing'))->toBeNull()
        ->and(SkillManifest::parseSchemaVersion('zhaohuan.skill'))->toBeNull()
        ->and(SkillManifest::parseSchemaVersion('zhaohuan.skill/'))->toBeNull()
        ->and(SkillManifest::parseSchemaVersion('zhaohuan.skill/v'))->toBeNull()
        ->and(SkillManifest::parseSchemaVersion('zhaohuan.skill/v2.1'))->toBeNull()
        ->and(SkillManifest::parseSchemaVersion(''))->toBeNull();
});

// ==================== 2. ManifestValidator 四类判定 ====================

it('schema = 当前版本 → 通过，errors 为空', function () {
    $v = app(ManifestValidator::class)->validate(msvManifest());

    expect($v['valid'])->toBeTrue()
        ->and($v['errors'])->toBe([]);
});

it('schema 缺失 → 提示"缺失"', function () {
    $errors = msvSchemaErrors('');

    $schemaErr = collect($errors)->first(fn ($e) => str_contains($e, 'schema'));

    expect($schemaErr)->not->toBeNull()
        ->and($schemaErr)->toContain('缺失');
});

it('schema 太新（v3）：明确说"平台当前支持到 v2"，而不是笼统拒绝', function () {
    $schemaErr = collect(msvSchemaErrors('zhaohuan.skill/v3'))->first(fn ($e) => str_contains($e, 'schema'));

    expect($schemaErr)->toContain('过新')
        ->and($schemaErr)->toContain('支持到 v2');
});

it('schema 太旧 / 已停用（v1）：提示"已停用"', function () {
    $schemaErr = collect(msvSchemaErrors('zhaohuan.skill/v1'))->first(fn ($e) => str_contains($e, 'schema'));

    expect($schemaErr)->toContain('已停用')
        ->and($schemaErr)->toContain('v2');
});

it('schema 命名空间不认识：提示"无法识别"', function () {
    $schemaErr = collect(msvSchemaErrors('other/thing'))->first(fn ($e) => str_contains($e, 'schema'));

    expect($schemaErr)->toContain('无法识别');
});

it('三类"不对"的提示互相可区分（关键字不重叠、整句不相等）', function () {
    $tooNew = collect(msvSchemaErrors('zhaohuan.skill/v3'))->first(fn ($e) => str_contains($e, 'schema'));
    $tooOld = collect(msvSchemaErrors('zhaohuan.skill/v1'))->first(fn ($e) => str_contains($e, 'schema'));
    $unknown = collect(msvSchemaErrors('other/thing'))->first(fn ($e) => str_contains($e, 'schema'));

    // 三句话各不相同
    expect($tooNew)->not->toBe($tooOld)
        ->and($tooNew)->not->toBe($unknown)
        ->and($tooOld)->not->toBe($unknown);

    // 关键字互斥 —— "太新"不会带"已停用"，"太旧"不会带"过新"
    expect($tooNew)->not->toContain('已停用')
        ->and($tooOld)->not->toContain('过新')
        ->and($unknown)->not->toContain('过新')
        ->and($unknown)->not->toContain('已停用');
});

// ==================== 3. 端到端：开发者通道把 schema 落库 ====================

it('端到端：开发者提审 v2 包 → skill_versions.manifest_schema 由 NULL 变为 zhaohuan.skill/v2', function () {
    Storage::fake(SkillAsset::DISK);

    $customer = msvCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    post(route('shop.dev.skills.store'), ['name' => '版本策略技能', 'price' => 199]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    // 上传合法技能包
    $package = new UploadedFile(msvPackage(), 'msv.skill', 'application/zip', null, true);
    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $package,
    ])->assertRedirect();

    // 提审前：这一列是 NULL —— 正是本次要修掉的缺口（开发者通道此前从不写）
    expect($version->fresh()->manifest_schema)->toBeNull();

    // 提审
    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();

    // 提审后：schema 落库（NULL → zhaohuan.skill/v2）
    expect($version->fresh()->manifest_schema)->toBe('zhaohuan.skill/v2');

    // 同时 L0 审核记录正常落地
    $this->assertDatabaseHas('audit_record', [
        'skill_id' => $productId,
        'version'  => $version->id,
        'layer'    => AuditRecord::LAYER_MACHINE,
        'result'   => AuditRecord::RESULT_PASS,
    ]);
});

// ==================== 4. 回归：Admin 导入路径照常写 manifest_schema ====================

it('回归：Admin 导入路径仍然照常写 manifest_schema', function () {
    $admin = Admin::factory()->create(['role_id' => 1]);
    actingAs($admin, 'admin');

    $productId = msvSkillProduct();

    post(route('admin.catalog.skills.import.store'), [
        'metadata' => json_encode([
            'name'             => 'MSV 宇树包',
            'version'          => '1.2.3',
            'base_image'       => 'registry.example.com/g1:1.2.3',
            'startup_commands' => ['./run.sh'],
            'permissions'      => ['camera'],
        ], JSON_UNESCAPED_UNICODE),
        'product_id' => $productId,
    ])->assertRedirect(route('admin.catalog.skills.import'));

    $version = SkillVersion::where('product_id', $productId)->where('version', '1.2.3')->first();

    expect($version)->not->toBeNull()
        ->and($version->manifest_schema)->toBe(SkillManifest::SCHEMA);
});
