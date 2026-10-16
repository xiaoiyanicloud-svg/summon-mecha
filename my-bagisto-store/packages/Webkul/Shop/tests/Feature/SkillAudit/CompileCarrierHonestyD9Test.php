<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\AuditService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 编译层口径降级（任务卡 D-9）。
 *
 * 两件事，同一主题：**"登记"不等于"产出"，"声明"不等于"拿得出"。**
 *
 *   ① `SkillSupplyController::compile()` 此前无论 per-model 结果如何都返 200 + "编译已登记。"
 *      —— kind 不支持 / 清单缺段这类可修正的输入问题，HTTP 层看不见；且平台侧当前**没有
 *      生产者**（转换产物需外部重定向工具或人工回填），故"已登记"从来不是"已编译"。
 *      本卡把三种结果分开如实说：全失败 → 422 + 码；部分失败 → 200 点名；pending → 如实说"待产出"。
 *   ② 发布闸门的"载体可用性"缺口：原先只查 `carrier_type === 'file'` 的交付物在不在盘上，
 *      声明为 `app_package` / `vendor_package` / `motion_data` 的交付物**完全不查** ——
 *      一个"只声明 app_package 且盘上没文件"的版本能上架，买家付钱后拿到"文件缺失"。
 *      本卡把三类新载体纳入"必须落盘"判定，同时**保住** cloud_config 等不落盘载体的豁免。
 *
 * 断言一律走**真实链路**（门户提审 / internal.key 编译与发布），不靠字段断言自说自话。
 */

// ==================== 夹具 ====================

/** 造一个合法 .skill 包（zip + manifest.json）。 */
function d9Package(): UploadedFile
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '编译口径技能'],
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

    $path = tempnam(sys_get_temp_dir(), 'd9skill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('README.txt', 'd9 payload');
    $zip->close();

    return new UploadedFile($path, 'd9-demo.skill', 'application/zip', null, true);
}

/** 走真实门户链路完成「入驻 → 签协议 → 实名通过」，返回 profile。 */
function d9Developer(): DeveloperProfile
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Compile',
        'email'       => 'd9'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    return $profile->fresh();
}

/** 门户建技能商品，返回真实商品 id（发布要把商品置为可售，商品必须真实存在）。 */
function d9Skill(string $name): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

/** 门户建一个版本（草稿）。 */
function d9Version(int $productId): SkillVersion
{
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0'])->assertRedirect();

    return SkillVersion::where('product_id', $productId)->orderByDesc('id')->firstOrFail();
}

/** 门户上传一个真包并提审（跑 L0 机审 → 版本进 in_review）。 */
function d9UploadAndSubmit(SkillVersion $version): void
{
    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => d9Package(),
    ])->assertRedirect();

    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);
}

/** L2 人审判通过（发布闸门要求的第二条轴）。 */
function d9ApproveL2(int $productId, int $versionId): void
{
    app(AuditService::class)->recordLayer2($productId, $versionId, AuditRecord::RESULT_PASS, null);
}

/** 走 internal.key 通道发布（与运营后台共用同一条 SkillPublisher 闸门）。 */
function d9Publish(int $versionId)
{
    return post(route('shop.api.skill-admin.versions.publish', $versionId), [], [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ]);
}

/** 走 internal.key 通道编译。 */
function d9Compile(int $versionId, array $body)
{
    return post(route('shop.api.skill-supply.compile', $versionId), $body, [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ]);
}

/** 某商品在 flat 表里的 status（证明"商品可不可售"）。 */
function d9FlatStatus(int $productId): ?int
{
    $status = DB::table('product_flat')
        ->where('product_id', $productId)
        ->where('locale', app()->getLocale())
        ->value('status');

    return $status === null ? null : (int) $status;
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ==================== ① 发布闸门：需落盘载体必须真的在盘上 ====================

it('只声明 app_package、盘上无文件 → 发布被拒（ASSET_FILE_MISSING），版本仍 in_review、商品不可售', function () {
    $profile = d9Developer();
    $name = '应用包缺件'.substr(uniqid(), -6);
    $productId = d9Skill($name);
    $version = d9Version($productId);

    // 先用真包过 L0 → in_review
    d9UploadAndSubmit($version);

    // 换成"只声明 app_package、却没有任何文件路径"的交付物 —— 正是本卡要拦的那种。
    // （app_package 属需落盘载体：容器化应用包必须真有一份可交付的包。）
    SkillAsset::query()->where('skill_version_id', $version->id)->delete();

    $ghost = SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $profile->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_APP_PACKAGE,
        'file_path'        => null,
        'file_name'        => null,
    ]);

    // 闸门（审核层）本身是过的 —— 这样能证明拦住它的是**载体可用性**，不是审核没过
    d9ApproveL2($productId, $version->id);

    $res = d9Publish($version->id)->assertStatus(422)->assertJsonPath('code', 'ASSET_FILE_MISSING');

    // 报错说清是**哪种载体**、缺的是什么
    $message = (string) $res->json('message');
    expect($message)->toContain('app_package')
        ->and($message)->toContain((string) $ghost->id);

    // 版本停在原地 + 商品仍不可售（不留 published 半状态）
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and(d9FlatStatus($productId))->not->toBe(1);
});

it('对照：只有 non-file 载体（cloud_config）的版本仍可发布 —— 不误伤不落盘载体', function () {
    $profile = d9Developer();
    $name = '云配置可发'.substr(uniqid(), -6);
    $productId = d9Skill($name);
    $version = d9Version($productId);

    d9UploadAndSubmit($version);

    // 换成"只有 payload、不落盘"的 cloud_config —— 按设计就没有文件，不该被拦
    SkillAsset::query()->where('skill_version_id', $version->id)->delete();

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $profile->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_CLOUD_CONFIG,
        'payload'          => ['endpoint' => 'https://example.com/config'],
    ]);

    d9ApproveL2($productId, $version->id);

    d9Publish($version->id)->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and(d9FlatStatus($productId))->toBe(1);
});

it('判据本身：isFile 只认 file，isFileBacked 覆盖 4 个需落盘载体（不污染指纹口径）', function () {
    $mk = fn (string $carrier) => new SkillAsset(['carrier_type' => $carrier]);

    // isFile() 语义**一字未动** —— 指纹与 C 端下载仍只认它，不能把新载体并进去
    expect($mk(SkillAsset::CARRIER_FILE)->isFile())->toBeTrue()
        ->and($mk(SkillAsset::CARRIER_APP_PACKAGE)->isFile())->toBeFalse()
        ->and($mk(SkillAsset::CARRIER_MOTION_DATA)->isFile())->toBeFalse()
        ->and($mk(SkillAsset::CARRIER_VENDOR_PACKAGE)->isFile())->toBeFalse();

    // isFileBacked() 把三个 v3.4 新载体一并纳入"必须在盘上"
    foreach ([
        SkillAsset::CARRIER_FILE,
        SkillAsset::CARRIER_MOTION_DATA,
        SkillAsset::CARRIER_APP_PACKAGE,
        SkillAsset::CARRIER_VENDOR_PACKAGE,
    ] as $carrier) {
        expect($mk($carrier)->isFileBacked())->toBeTrue("{$carrier} 应属需落盘载体");
    }

    // 不落盘载体豁免
    foreach ([
        SkillAsset::CARRIER_CLOUD_CONFIG,
        SkillAsset::CARRIER_REMOTE_SERVICE,
        SkillAsset::CARRIER_API_BUNDLE,
    ] as $carrier) {
        expect($mk($carrier)->isFileBacked())->toBeFalse("{$carrier} 不该被当作需落盘载体");
    }
});

// ==================== ② compile 端点：如实报"登记"，不谎报"编译" ====================

it('全部机型 kind 不支持 → 422 + SKILL_COMPILE_NOTHING_REGISTERED，data 带 per-model 明细', function () {
    $vid = 960000 + random_int(1, 9000);

    $res = d9Compile($vid, [
        'manifest'      => ['kind' => 'app'],   // app 当前无构建器（AppCarrierBuilder 是 P1 未实现）
        'target_models' => ['unitree_g1', 'agibot_a2'],
    ])->assertStatus(422)->assertJsonPath('code', 'SKILL_COMPILE_NOTHING_REGISTERED');

    expect($res->json('message'))->toContain('没有任何机型登记成功');

    $data = $res->json('data');
    expect($data)->toBeArray()->toHaveCount(2)
        ->and(array_column($data, 'status'))->each->toBe('failed');
});

it('kind=motion（motion 段齐备）→ 200 且 message 如实说「待人工/外部产出」，artifact 仍为 null', function () {
    $vid = 960000 + random_int(1, 9000);

    $res = d9Compile($vid, [
        'manifest'      => ['kind' => 'motion', 'motion' => ['format' => 'bvh', 'fps' => 30]],
        'target_models' => ['unitree_g1', 'agibot_a2'],
    ])->assertOk();

    $message = (string) $res->json('message');

    // 不许说"编译完成"，必须如实说"待产出、就绪前不可交付"
    expect($message)->toContain('待人工/外部产出')
        ->and($message)->toContain('不可交付')
        ->and($message)->not->toContain('编译完成');

    $rows = SkillBuild::where('skill_version_id', $vid)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['pending']);

    // 平台侧无生产者 —— 产物没有被回填，artifact_asset_id 恒 NULL
    expect($rows->whereNotNull('artifact_asset_id'))->toHaveCount(0);
});

// ==================== ③ skill_build 不在交付链路上（事实断言）====================

it('skill_build 不在交付链路上：交付/下发代码里没有任何一处引用它', function () {
    // 结构性守卫（与 PackageIntegrityTest / ErrorEnvelopeX1Test 的源码扫描同思路）：
    // 交付只认 skill_assets（见 D1/D2）。谁把 skill_build 接进交付/下发路径，
    // 就等于把"没产出的登记"当成"可下发的产物" —— 这条守卫拦的就是那一步。
    $roots = [
        base_path('packages/Webkul/Shop/src/Services/Deploy'),
        base_path('packages/Webkul/Shop/src/Services/SkillDelivery'),
        base_path('packages/Webkul/Shop/src/Http/Controllers/API/SkillController.php'),
        base_path('packages/Webkul/Shop/src/Http/Controllers/API/AgentPackageController.php'),
    ];

    $files = [];

    foreach ($roots as $root) {
        if (is_file($root)) {
            $files[] = $root;

            continue;
        }

        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    expect($files)->not->toBeEmpty('扫描根没找到任何 PHP 文件，断言失去意义');

    $offenders = array_values(array_filter(
        $files,
        fn (string $path) => preg_match('/\bSkillBuild\b|skill_build/', (string) file_get_contents($path)) === 1
    ));

    expect($offenders)->toBe([]);
});

it('compile 只登记 skill_build，不产出任何交付物（skill_assets 恒为 0）', function () {
    $vid = 960000 + random_int(1, 9000);

    d9Compile($vid, [
        'manifest'      => ['kind' => 'motion', 'motion' => ['format' => 'bvh', 'fps' => 30]],
        'target_models' => ['unitree_g1'],
    ])->assertOk();

    expect(SkillBuild::where('skill_version_id', $vid)->count())->toBeGreaterThan(0)
        // 产物没有被回填 —— 交付链路读的 skill_assets 一条都没多出来
        ->and(SkillAsset::where('skill_version_id', $vid)->count())->toBe(0);
});

// ==================== ④ 开发者门户：载体中文标签 + 诚实说明 ====================

it('开发者门户：交付物列显示载体中文标签，且明说「平台不做自动编译」', function () {
    $profile = d9Developer();
    $productId = d9Skill('门户标签技能');
    $version = d9Version($productId);

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $profile->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_APP_PACKAGE,
        'file_name'        => 'demo-app.tar',
        'file_path'        => 'skills/demo-app.tar',
    ]);

    get(route('shop.dev.skills.show', $productId))
        ->assertOk()
        // 载体列的中文标签（映射取自 SkillAsset::CARRIER_LABELS）
        ->assertSeeText('应用包')
        // 上传区旁的诚实说明
        ->assertSeeText('平台不做自动编译')
        ->assertSeeText('多机型适配需自行产出后登记');
});
