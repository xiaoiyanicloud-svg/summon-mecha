<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * 包完整性 —— "**审核看到的那份，必须就是最终发布的那份**"（任务卡 D-7 · 平台化 ⑨）。
 *
 * ── 病 ──
 *   交付物按 `(skill_version_id, carrier_type, sort_order)` **幂等 upsert**
 *   （`AssetUploadService::store`）：重复上传会覆盖记录并删掉旧文件。于是
 *   "提审之后、发布之前"再传一次包，落盘的就换成了新文件，而那条"审核通过"的记录
 *   看起来**依然有效** —— 审的那个包与发出去的那个包可以是两个不同的字节流，
 *   此前**没有任何记录能发现**。
 *
 * ── 本文件锁什么 ──
 *   1. 核心端到端：换包 → 发布被拒（PACKAGE_CHANGED_AFTER_REVIEW）+ 商品不可售 + 市场搜不到；
 *   2. 换包 + **重新提审** → 以新指纹通过 → 发布成功；
 *   3. 已发布版本不许换包（且落盘文件一字未改）；
 *   4. 非文件类交付物（cloud_config）不参与指纹，改 payload 不误拦发布；
 *   5. 判据本身：排序稳定、任一文件类 hash 变则指纹变、没有文件类交付物时指纹为 null。
 *
 * ⚠ 每条"发布成功"的用例都走**真实的供给侧链路**（开发者门户建技能/传包/提审 → 运营审核台
 *   判 L2 → 发布），因为指纹是在这几个环节里被写下来又读出来的 —— 用假记录造夹具就绕过了
 *   本卡真正要证明的那条链。
 */

// ==================== 造数据 ====================

function pkgCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Pkg',
        'email'       => 'pkg'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function pkgAdmin(): Admin
{
    return Admin::factory()->create(['role_id' => 1]);
}

/**
 * 造一个合法的 `.skill` 包（zip + manifest.json）。
 *
 * `$salt` 决定包体字节 —— "换包"就是换它：同一个交付物位置、另一份字节流。
 */
function pkgPackage(string $name = '包完整性技能', string $salt = 'v1'): string
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

    $path = tempnam(sys_get_temp_dir(), 'pkgskill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('README.txt', 'package payload '.$salt);
    $zip->close();

    return $path;
}

/**
 * 走真实开发者链路建主体 + 建技能商品（status=0，挂「技能」分类）。
 *
 * @return array{0: DeveloperProfile, 1: int}  [profile, productId]
 */
function pkgSkill(string $name): array
{
    $customer = pkgCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    // D-5 主体资格是**发布链上更靠前**的另一道前置（实名 + 协议）。
    // 本文件测的是包完整性判据，前置要成立，否则发布会先被主体资格挡下、根本走不到这里。
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199]);

    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    return [$profile, $productId];
}

/** 建一个版本（草稿），返回它。 */
function pkgVersion(int $productId, ?int $sellerId, string $no = '1.0.0'): SkillVersion
{
    post(route('shop.dev.skills.versions.store', $productId), ['version' => $no]);

    return SkillVersion::where('product_id', $productId)->where('version', $no)->firstOrFail();
}

/** 从开发者门户上传一个文件类交付物（走真实上传核）。 */
function pkgUploadFile(SkillVersion $version, string $packagePath, string $fileName = 'demo.skill')
{
    return post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile($packagePath, $fileName, 'application/zip', null, true),
    ])->assertSessionHasNoErrors();
}

/** 从开发者门户提审（内部会跑 L0 机审并落生命周期状态）。 */
function pkgSubmit(SkillVersion $version): void
{
    post(route('shop.dev.versions.submit', $version->id))->assertSessionHasNoErrors();
}

/** 运营在审核台判 L2（登录身份写入 auditor_id）。 */
function pkgApproveL2(SkillVersion $version): void
{
    actingAs(pkgAdmin(), 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), ['result' => AuditRecord::RESULT_PASS]);
}

/**
 * 切回开发者身份。
 *
 * `actingAs($admin, 'admin')` 会把**默认 guard** 换成 admin，而本文件要在
 * "运营判完 L2 之后"再以开发者身份传包（这正是本卡的核心场景）。显式切回来，
 * 免得用例因为身份串了而以 404/未登录的方式假失败。
 */
function pkgActAsDeveloper(DeveloperProfile $profile): void
{
    actingAs(Customer::findOrFail($profile->customer_id), 'developer');
}

function pkgHeaders(): array
{
    return ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json'];
}

/** 经 internal.key 通道发布（与运营后台共用同一条 SkillPublisher 闸门）。 */
function pkgPublishApi(SkillVersion $version)
{
    return post(route('shop.api.skill-admin.versions.publish', $version->id), [], pkgHeaders());
}

/** 某层最近一条审核记录上的包指纹。 */
function pkgRecordFingerprint(SkillVersion $version, int $layer): ?string
{
    return AuditRecord::query()
        ->forVersion((int) $version->product_id, (int) $version->id)
        ->where('layer', $layer)
        ->orderByDesc('id')
        ->first()?->package_fingerprint;
}

/** 市场搜索命中该名字的商品 id 集合（字段断言证明不了"看得见/看不见"，所以打接口）。 */
function pkgSearchIds(string $q): array
{
    $res = getJson(route('shop.api.skill-catalog.search', ['q' => $q]))->assertOk();

    return collect($res->json('data'))->pluck('id')->map('intval')->all();
}

/** 直接建一条交付物记录（给"判据本身"那组用例用，不造字节）。 */
function pkgAsset(int $versionId, string $carrier, int $sort, ?string $hash): SkillAsset
{
    $isFile = $carrier === SkillAsset::CARRIER_FILE;

    return SkillAsset::create([
        'skill_version_id' => $versionId,
        'carrier_type'     => $carrier,
        'sort_order'       => $sort,
        'file_path'        => $isFile ? "skills/pkg/{$versionId}_{$sort}.skill" : null,
        'file_name'        => $isFile ? "s{$sort}.skill" : null,
        'file_hash'        => $hash,
    ]);
}

beforeEach(function () {
    // 交付物走假盘：上传/发布都会真实落盘与验在盘性（见 SkillPublisher 的可交付性前置）
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ==================== 1 · 核心端到端 ====================

it('核心端到端：提审 → L2 通过 → 换包 → 发布被拒，商品仍不可售、市场仍搜不到', function () {
    $name = '包完整性'.substr(uniqid(), -6);

    [$profile, $productId] = pkgSkill($name);
    $version = pkgVersion($productId, $profile->seller_id);

    // 提审：上传合法包 → L0 机审通过 → 版本进 in_review
    pkgUploadFile($version, pkgPackage($name, 'first'));
    pkgSubmit($version);

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    $fingerprint = app(PackageFingerprint::class)->forVersion($version->id);

    expect($fingerprint)->not->toBeNull();

    // L0 记录上写着**判决当时**的包指纹（否则发布时无从比对）
    expect(pkgRecordFingerprint($version, AuditRecord::LAYER_MACHINE))->toBe($fingerprint);

    // L2 人审通过 —— 审核员看的必须是这份字节流
    pkgApproveL2($version);
    expect(pkgRecordFingerprint($version, AuditRecord::LAYER_HUMAN))->toBe($fingerprint);

    // 发布前：市场搜不到（商品建出来就是 status=0）
    expect(pkgSearchIds($name))->not->toContain($productId);

    // ── 换包：同一版本、同一 (carrier_type, sort_order)，覆盖成另一份字节流 ──
    pkgActAsDeveloper($profile);
    pkgUploadFile($version, pkgPackage($name, 'second'), 'demo-v2.skill');

    // 状态回退**不静默**：开发者当场被告知提审已作废、要重新提交
    expect(session('success'))->toContain('退回草稿');

    $after = app(PackageFingerprint::class)->forVersion($version->id);

    expect($after)->not->toBe($fingerprint);

    // 发布：闸门（L0/L2 记录）**仍然是过的**，但"审的那份"≠"要发的那份" → 拒绝
    $res = pkgPublishApi($version);

    $res->assertStatus(422)->assertJsonPath('code', 'PACKAGE_CHANGED_AFTER_REVIEW');
    expect($res->json('message'))->toContain('交付物发生了变化')
        ->and($res->json('message'))->toContain('重新提交审核');

    // 版本没有变成 published（拒绝发生在状态迁移**之前**，不留"看起来发布过"的痕迹）
    expect($version->fresh()->status)->not->toBe(SkillVersion::STATUS_PUBLISHED);

    // 且已从「在审」退回草稿 —— 那条"已通过"的审核记录对应的是旧字节，事实作废，
    // 不能让它在审核台队列里继续显示成"已通过（待发布）"。理由见文件末尾「状态口径」。
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);

    // 商品仍不可售 + 市场仍搜不到（**接口断言**，不是字段断言）
    expect(pkgSearchIds($name))->not->toContain($productId);

    $flat = DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('locale', app()->getLocale())
        ->first();

    expect($flat)->not->toBeNull()
        ->and((int) $flat->status)->not->toBe(1);

    // 运营后台同一条动作：flash 必须说清"是包变了，不是审核没过"，且留在**商品编辑页**
    // （跳审核台会让运营面对一个显示"已通过"的页面空转）
    actingAs(pkgAdmin(), 'admin');

    post(route('admin.catalog.skills.versions.publish', $version->id))
        ->assertRedirect(route('admin.catalog.skills.edit', $productId));

    expect(session('error'))->toContain('不是审核未过');
});

// ==================== 2 · 重新提审后放行 ====================

it('换包后重新提审 → 新指纹落进 L0/L2 → 发布成功', function () {
    $name = '包重审'.substr(uniqid(), -6);

    [$profile, $productId] = pkgSkill($name);
    $version = pkgVersion($productId, $profile->seller_id);

    pkgUploadFile($version, pkgPackage($name, 'first'));
    pkgSubmit($version);
    pkgApproveL2($version);

    $oldFingerprint = pkgRecordFingerprint($version, AuditRecord::LAYER_HUMAN);

    // 换包 —— 版本被退回 draft（那份"已通过"作废）
    pkgActAsDeveloper($profile);
    pkgUploadFile($version, pkgPackage($name, 'second'), 'demo-v2.skill');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);

    // 重新提审（草稿 → 在审是白名单边）：L0 记录带上**新**指纹
    pkgSubmit($version);

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    $newFingerprint = pkgRecordFingerprint($version, AuditRecord::LAYER_MACHINE);

    expect($newFingerprint)->not->toBeNull()
        ->and($newFingerprint)->not->toBe($oldFingerprint);

    // 运营对新包再判一次 L2
    pkgApproveL2($version);

    expect(pkgRecordFingerprint($version, AuditRecord::LAYER_HUMAN))->toBe($newFingerprint);

    // 这时"审的"与"要发的"是同一份 → 放行
    $res = pkgPublishApi($version);

    $res->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and($res->json('data.status'))->toBe(SkillVersion::STATUS_PUBLISHED);

    // 发布 = 真上架：市场搜得到
    expect(pkgSearchIds($name))->toContain($productId);
});

// ==================== 3 · 已发布版本不许换包 ====================

it('已发布版本不许换包：上传被拒，且落盘文件与 file_hash 一字未改', function () {
    $name = '包冻结'.substr(uniqid(), -6);

    [$profile, $productId] = pkgSkill($name);
    $version = pkgVersion($productId, $profile->seller_id);

    pkgUploadFile($version, pkgPackage($name, 'first'));
    pkgSubmit($version);
    pkgApproveL2($version);

    pkgPublishApi($version)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    $before = SkillAsset::where('skill_version_id', $version->id)->firstOrFail();
    $bytesBefore = Storage::disk(SkillAsset::disk())->get($before->file_path);

    // 已发布的版本：买家可能已经下载/安装了这一份，覆盖它就是让"线上那一份"静默换字节
    $res = post(route('shop.api.skill-admin.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile(pkgPackage($name, 'evil'), 'evil.skill', 'application/zip', null, true),
    ], pkgHeaders());

    $res->assertStatus(409)->assertJsonPath('code', 'VERSION_PUBLISHED_IMMUTABLE');
    expect($res->json('message'))->toContain('新建一个版本');

    // 落盘那一份**一字未改**（记录、路径、字节流三者都要一样）
    $after = SkillAsset::where('skill_version_id', $version->id)->firstOrFail();

    expect($after->id)->toBe($before->id)
        ->and($after->file_path)->toBe($before->file_path)
        ->and($after->file_hash)->toBe($before->file_hash);

    Storage::disk(SkillAsset::disk())->assertExists($before->file_path);
    expect(Storage::disk(SkillAsset::disk())->get($before->file_path))->toBe($bytesBefore);
});

// ==================== 4 · 非文件类交付物不影响指纹 ====================

it('非文件类（cloud_config）不参与指纹：改 payload 指纹不变，也不误拦发布', function () {
    $name = '包非文件'.substr(uniqid(), -6);

    [$profile, $productId] = pkgSkill($name);
    $version = pkgVersion($productId, $profile->seller_id);

    pkgUploadFile($version, pkgPackage($name, 'first'));
    pkgSubmit($version);

    // 一条只存 payload、不落盘的交付物（云端配置）
    post(route('shop.api.skill-admin.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG,
        'payload'      => ['endpoint' => 'https://a.example.com'],
    ], pkgHeaders())->assertStatus(201);

    $fingerprint = app(PackageFingerprint::class)->forVersion($version->id);

    expect($fingerprint)->not->toBeNull();

    pkgApproveL2($version);

    // 改这条云端配置的 payload —— **没有字节变化**，指纹必须纹丝不动
    post(route('shop.api.skill-admin.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG,
        'payload'      => ['endpoint' => 'https://b.example.com'],
    ], pkgHeaders())->assertStatus(201);

    expect(app(PackageFingerprint::class)->forVersion($version->id))->toBe($fingerprint);

    // 版本仍在审：非文件类上传不该把提审打回（它对审核结论没有影响）
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    // 发布不被误拦：指纹与 L2 记录一致 → 放行
    pkgPublishApi($version)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);
});

// ==================== 5 · 判据本身 ====================

it('判据只此一处：同一交付物集合、写入顺序不同 → 同一指纹；任一文件类 hash 变 → 指纹变', function () {
    $fingerprint = app(PackageFingerprint::class);

    $a = SkillVersion::create(['product_id' => 880001, 'version' => '1.0.0', 'channel' => 'stable', 'status' => SkillVersion::STATUS_DRAFT]);
    $b = SkillVersion::create(['product_id' => 880002, 'version' => '1.0.0', 'channel' => 'stable', 'status' => SkillVersion::STATUS_DRAFT]);

    $ha = str_repeat('a', 64);
    $hb = str_repeat('b', 64);
    $hc = str_repeat('c', 64);

    // 同一组交付物，**插入顺序相反**
    foreach ([[0, $ha], [1, $hb], [2, $hc]] as [$sort, $hash]) {
        pkgAsset($a->id, SkillAsset::CARRIER_FILE, $sort, $hash);
    }

    foreach ([[2, $hc], [1, $hb], [0, $ha]] as [$sort, $hash]) {
        pkgAsset($b->id, SkillAsset::CARRIER_FILE, $sort, $hash);
    }

    // 入库先后不是语义 —— 判据先按 (carrier_type, sort_order) 排序，所以两者必须一致
    expect($fingerprint->forVersion($a->id))->toBe($fingerprint->forVersion($b->id))
        ->and($fingerprint->forVersion($a->id))->not->toBeNull();

    // 非文件类交付物不参与：加一条 api_bundle 到 A，指纹不变
    pkgAsset($a->id, SkillAsset::CARRIER_API_BUNDLE, 0, null);
    pkgAsset($a->id, SkillAsset::CARRIER_CLOUD_CONFIG, 0, null);

    expect($fingerprint->forVersion($a->id))->toBe($fingerprint->forVersion($b->id));

    // 只要**一个**文件类交付物的 hash 变了，指纹就变
    SkillAsset::where('skill_version_id', $a->id)
        ->where('carrier_type', SkillAsset::CARRIER_FILE)
        ->where('sort_order', 1)
        ->update(['file_hash' => str_repeat('d', 64)]);

    expect($fingerprint->forVersion($a->id))->not->toBe($fingerprint->forVersion($b->id));
});

it('没有文件类交付物 → 指纹是 null（不是空串冒充的有效指纹）', function () {
    $version = SkillVersion::create([
        'product_id' => 880003, 'version' => '1.0.0', 'channel' => 'stable', 'status' => SkillVersion::STATUS_DRAFT,
    ]);

    $fingerprint = app(PackageFingerprint::class);

    expect($fingerprint->forVersion($version->id))->toBeNull();

    // 只有非文件类交付物，同样没有"字节"可言
    pkgAsset($version->id, SkillAsset::CARRIER_CLOUD_CONFIG, 0, null);
    pkgAsset($version->id, SkillAsset::CARRIER_REMOTE_SERVICE, 1, null);

    expect($fingerprint->forVersion($version->id))->toBeNull();
});

it('判据只此一处：仓库里没有第二处拼包指纹串的实现', function () {
    // 结构性守卫（与 ErrorEnvelopeX1Test 的源码扫描同思路）：
    // 审核侧写指纹与发布侧比指纹必须调同一个类。谁再内联一份，
    // 两份迟早漂移 —— 而漂移的那一次就是一次换包事故。
    $offenders = [];

    foreach ([base_path('packages/Webkul/Shop/src'), base_path('packages/Webkul/Admin/src')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_ends_with($file->getFilename(), 'PackageFingerprint.php')) {
                continue;
            }

            if (preg_match('/carrier_type\s*\.\s*\'\\|\'/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
