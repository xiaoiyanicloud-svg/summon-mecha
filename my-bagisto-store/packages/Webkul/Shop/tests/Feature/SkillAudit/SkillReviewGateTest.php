<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\ReviewGate;
use Webkul\User\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 审核台 + 发布闸门（v3.4 步骤 6/10 收口）。
 *
 * 这个文件锁住的是**勘察抓出来的三个真漏洞**，不是新增功能：
 *
 *   B1 发布闸门缺失 —— `publish()` 原先只看"有没有交付物"，未过审也能上架
 *   B2 上传实现两份 —— Admin 的 `storeAsset` 是内联副本，没有 sha256 复核
 *   B3 `isFullyApproved` 零调用方 —— 而且它写死"三层全过"，接上去会让谁都发布不了
 *
 * 另加 E 组（同一类病的第四例）：机器通道可以随便填 `auditor_id`，
 * 而 Admin 把它渲染成 `#<id>` —— 一条可以随便填的"审核人签字"。
 * 详见文件末尾 E 段的说明。
 *
 * 测试走的是**真实的供给侧链路**：开发者提审 → 平台审核 → 发布。
 * 这才是这条链实际的样子，比拆成孤立单元更能证明它通了。
 */

// ==================== 造数据 ====================

function rgCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'User',
        'email'       => 'rg'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function rgAdmin(): Admin
{
    return Admin::factory()->create(['role_id' => 1]);
}

function rgPackage(string $name = '测试技能'): string
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

    $path = tempnam(sys_get_temp_dir(), 'rgskill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('README.txt', 'demo package');
    $zip->close();

    return $path;
}

/**
 * 走完开发者侧链路，得到一个"已提审且 L0 已过"的版本。
 *
 * @return array{0:int,1:SkillVersion}  [productId, version]
 */
function rgSubmittedVersion(): array
{
    $customer = rgCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();

    // D-5 实名发布前置：本文件锁的是**发布闸门**（审核过没过），而主体资格是闸门**之前**的另一道前置。
    // 主体若不合格，发布会先被主体资格挡下、根本走不到闸门 —— 那样这些用例就测不到它们要测的东西。
    // 所以 fixture 里把主体补成合格（实名通过 + 协议已签），**不**弱化前置本身。
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '抓取技能', 'price' => 199]);
    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile(rgPackage(), 'demo.skill', 'application/zip', null, true),
    ]);

    post(route('shop.dev.versions.submit', $version->id));

    return [$productId, $version->fresh()];
}

/**
 * 直接写一条审核记录（给闸门策略测试用，不经过 HTTP）。
 */
function rgAudit(int $productId, int $versionId, int $layer, int $result): AuditRecord
{
    return AuditRecord::create([
        'skill_id'     => $productId,
        'version'      => $versionId,
        'audit_type'   => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
        'layer'        => $layer,
        'result'       => $result,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
});

// ==================== A · 闸门策略 ====================

it('闸门：没有记录的层是「尚未审核」，不是通过 —— 一律挡', function () {
    $evaluation = app(ReviewGate::class)->evaluate(900001, 900001);

    expect($evaluation['ok'])->toBeFalse()
        ->and(collect($evaluation['unmet'])->pluck('reason')->all())
        ->toBe(['尚未审核', '尚未审核']);
});

it('闸门：L0 过了但 L2 没判 → 仍不许发布', function () {
    rgAudit(900002, 900002, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);

    $evaluation = app(ReviewGate::class)->evaluate(900002, 900002);

    expect($evaluation['ok'])->toBeFalse()
        ->and($evaluation['unmet'])->toHaveCount(1)
        ->and($evaluation['unmet'][0]['label'])->toBe('L2 人审');
});

it('闸门：L0 + L2 都过 → 放行', function () {
    rgAudit(900003, 900003, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    rgAudit(900003, 900003, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    expect(app(ReviewGate::class)->evaluate(900003, 900003)['ok'])->toBeTrue();
});

it('闸门：以每层最新一条记录为准（先驳回、整改后通过 → 放行）', function () {
    rgAudit(900004, 900004, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    rgAudit(900004, 900004, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_REJECT);

    expect(app(ReviewGate::class)->evaluate(900004, 900004)['ok'])->toBeFalse();

    // 开发者整改后重审通过 —— 最新一条说了算
    rgAudit(900004, 900004, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    expect(app(ReviewGate::class)->evaluate(900004, 900004)['ok'])->toBeTrue();
});

it('闸门：L1 仿真器未接入 → 不参与闸门，但如实报「未接入」而不是「通过」', function () {
    $gate = app(ReviewGate::class);

    expect($gate->isRequired(AuditRecord::LAYER_SIMULATION))->toBeFalse()
        ->and($gate->isAvailable(AuditRecord::LAYER_SIMULATION))->toBeFalse();

    $state = $gate->layerStates(900005, 900005)[AuditRecord::LAYER_SIMULATION];

    // 界面靠 available 打出"未接入"，绝不能落到"通过"
    expect($state['available'])->toBeFalse()
        ->and($state['result_label'])->not->toBe('通过');
});

it('闸门：配了未接入的层 → fail-closed（不是静默放行）', function () {
    config(['skill_audit.required_layers' => [1, 2, 3]]);

    rgAudit(900006, 900006, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    rgAudit(900006, 900006, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    $evaluation = app(ReviewGate::class)->evaluate(900006, 900006);

    expect($evaluation['ok'])->toBeFalse()
        ->and($evaluation['unmet'][0]['reason'])->toBe('该层未接入，无法审核');
});

// ==================== B · 上传安全回归（B2）====================

it('B2 回归：后台传包走共享安全核 —— sha256 声明不符直接拒绝，不落盘', function () {
    [$productId, $version] = rgSubmittedVersion();

    $before = SkillAsset::where('skill_version_id', $version->id)->orderByDesc('id')->first();
    $beforeHash = $before->file_hash;

    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skills.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile(rgPackage('换个包'), 'other.skill', 'application/zip', null, true),
        'sha256'       => str_repeat('a', 64),   // 假的声明哈希
    ])->assertRedirect();

    expect(session('error'))->toContain('sha256');

    // 原交付物原封不动 —— 拒绝必须发生在落盘之前
    $after = SkillAsset::where('skill_version_id', $version->id)->orderByDesc('id')->first();

    expect($after->id)->toBe($before->id)
        ->and($after->file_hash)->toBe($beforeHash);
});

it('B2 回归：声明正确的 sha256 → 放行', function () {
    [$productId, $version] = rgSubmittedVersion();

    actingAs(rgAdmin(), 'admin');

    $path = rgPackage('换对了');
    $realHash = hash_file('sha256', $path);

    post(route('admin.catalog.skills.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile($path, 'good.skill', 'application/zip', null, true),
        'sha256'       => $realHash,
    ])->assertRedirect();

    expect(session('success'))->not->toBeNull();

    $asset = SkillAsset::where('skill_version_id', $version->id)->orderByDesc('id')->first();

    expect($asset->file_hash)->toBe($realHash);
});

// ==================== C · 发布闸门（B1）====================

it('B1 回归：没过 L2 人审，后台点发布被拒（原先能直接上架）', function () {
    [$productId, $version] = rgSubmittedVersion();

    // L0 已过，但 L2 还没判
    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skills.versions.publish', $version->id))->assertRedirect();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and(session('error'))->toContain('发布闸门');
});

it('B1 回归：L0 + L2 都过 → 发布成功', function () {
    [$productId, $version] = rgSubmittedVersion();

    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), ['result' => 1])->assertRedirect();

    post(route('admin.catalog.skills.versions.publish', $version->id))->assertRedirect();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and($version->fresh()->released_at)->not->toBeNull();
});

it('B1 回归：L2 判为驳回 → 依然不许发布', function () {
    [$productId, $version] = rgSubmittedVersion();

    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), [
        'result'          => AuditRecord::RESULT_REJECT,
        'reason_category' => 'SAFETY_MISSING',
        'reason_detail'   => '高危动作缺安全声明',
    ])->assertRedirect();

    post(route('admin.catalog.skills.versions.publish', $version->id))->assertRedirect();

    // 提审后是 in_review；L2 判驳回 → AuditService 把生命周期落到 rejected（不是 draft）
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_REJECTED);
});

// ==================== D · L2 判定 ====================

it('L2 判定：审核人取自登录的管理员身份，请求体伪造无效', function () {
    [$productId, $version] = rgSubmittedVersion();

    $admin = rgAdmin();
    actingAs($admin, 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), [
        'result'     => 1,
        'auditor_id' => 999999,   // 伪造 —— 必须被忽略
    ])->assertRedirect();

    $record = AuditRecord::query()
        ->forVersion($productId, $version->id)
        ->where('layer', AuditRecord::LAYER_HUMAN)
        ->orderByDesc('id')
        ->first();

    expect($record)->not->toBeNull()
        ->and((int) $record->auditor_id)->toBe((int) $admin->id);
});

it('L2 判定：驳回/待补充必须给理由码，否则不落记录', function () {
    [$productId, $version] = rgSubmittedVersion();

    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), [
        'result' => AuditRecord::RESULT_REJECT,
        // 故意不填 reason_category
    ])->assertRedirect();

    expect(AuditRecord::query()->forVersion($productId, $version->id)
        ->where('layer', AuditRecord::LAYER_HUMAN)->exists())->toBeFalse()
        ->and(session('error'))->toContain('理由类别');
});

it('L2 判定：理由码必须在拒绝清单里，不能自造', function () {
    [$productId, $version] = rgSubmittedVersion();

    actingAs(rgAdmin(), 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), [
        'result'          => AuditRecord::RESULT_REJECT,
        'reason_category' => 'NOT_A_REAL_CODE',
    ])->assertRedirect();

    expect(AuditRecord::query()->forVersion($productId, $version->id)
        ->where('layer', AuditRecord::LAYER_HUMAN)->exists())->toBeFalse();
});

// ==================== E · 审计留痕 ====================

it('审核与发布都留痕到 operation_log', function () {
    [$productId, $version] = rgSubmittedVersion();

    $admin = rgAdmin();
    actingAs($admin, 'admin');

    post(route('admin.catalog.skill-reviews.judge', $version->id), ['result' => 1]);
    post(route('admin.catalog.skills.versions.publish', $version->id));

    expect(DB::table('operation_log')
        ->where('actor_type', 'admin')
        ->where('actor_id', $admin->id)
        ->where('action', 'skill.review.layer2')
        ->where('target_id', $version->id)
        ->exists())->toBeTrue();

    expect(DB::table('operation_log')
        ->where('action', 'skill.version.publish')
        ->where('target_id', $version->id)
        ->exists())->toBeTrue();
});

/**
 * 说明：审核台的**渲染冒烟**不在本文件 —— 它放在 `Webkul/Admin/tests/Feature/Catalog/
 * SkillReviewConsoleTest.php`。原因是本套件注册了店铺主题，Admin 布局解析主题资产时
 * 会命中店铺的 Vite 清单而渲染失败（环境问题，与代码无关）。
 * 逻辑断言（闸门策略、发布拦截、上传安全、审核人身份）全在本文件，且不需要渲染页面。
 */

// ==================== G · 内容安全留痕 ====================

it('提审会把内容扫描明细写进 content_security_record（原先只判不留）', function () {
    [$productId, $version] = rgSubmittedVersion();

    $record = DB::table('content_security_record')
        ->where('target_type', 'skill_meta')
        ->where('target_id', $version->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->result)->toBe('pass')
        ->and($record->disposition)->toBe('pending')
        ->and($record->scan_type)->toBe('text');

    // raw 里要能看出"当时用的是哪个驱动" —— 否则词表事故与驱动切换长得一样
    $raw = json_decode($record->raw ?? '{}', true);

    expect($raw['driver'] ?? null)->toBe('local')
        ->and((int) ($raw['skill_id'] ?? 0))->toBe((int) $productId);
});

it('命中违禁词：扫描记录落 block，且处置状态待人处理', function () {
    $customer = rgCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    post(route('shop.dev.skills.store'), ['name' => '抓取技能', 'price' => 199]);
    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    // 把违禁文案塞进技能名 —— 走的是真实的 .skill 包解析路径
    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => new UploadedFile(
            rgPackage('本技能用于赌博机操作'),
            'bad.skill',
            'application/zip',
            null,
            true
        ),
    ]);

    post(route('shop.dev.versions.submit', $version->id));

    $record = DB::table('content_security_record')
        ->where('target_type', 'skill_meta')
        ->where('target_id', $version->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->result)->toBe('block')
        ->and($record->disposition)->toBe('pending')
        ->and(json_decode($record->labels ?? '[]', true))->toContain('赌博');
});

// ==================== E · 机器通道不得伪造审核人 ====================
//
// 这一类与 mock 支付网关是**完全同类**的问题：显示一条假的追溯。
// `POST /api/skill-supply/versions/{id}/review` 挂的是 internal.key ——
// 一把**单一的平台共享密钥**，它不代表任何具体的人。所以请求体里的 `auditor_id`
// 写谁就是谁，服务端一个字都核验不了。而 Admin 审核台把该字段渲染成 `#<id>`，
// 看上去就成"某位真人审过这一版"了 —— 出事要复盘"是谁放行的"，拿到的是一个
// 可以随便填的数字。
//
// 现在的不变量：**只有平台控制台（登录身份写入）才能产生非空 auditor_id**。
// 下面三条分别锁：拒收、拒收后不留痕、以及不传时写进去的确实是空。

it('机器通道：请求体里带 auditor_id → 明确拒收，且一条审核记录都不落', function () {
    [$productId, $version] = rgSubmittedVersion();

    config(['skill_delivery.upload_key' => 'test-internal-key']);

    $response = post(
        route('shop.api.skill-supply.review', $version->id),
        [
            'skill_id'   => $productId,
            'layer'      => AuditRecord::LAYER_HUMAN,
            'result'     => AuditRecord::RESULT_PASS,
            'auditor_id' => 999999,   // 伪造的"审核人"
        ],
        ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json']
    );

    $response->assertStatus(422)
        ->assertJsonPath('code', 'AUDITOR_ID_NOT_ACCEPTED_HERE');

    // 错误信息要说出"该改去哪里"（平台控制台），否则调用方只会把字段删掉再试一次
    expect($response->json('message'))->toContain('控制台');

    // 关键：拒收之后**不能**悄悄落一条"某人审过了"的记录
    expect(AuditRecord::query()
        ->forVersion($productId, $version->id)
        ->where('layer', AuditRecord::LAYER_HUMAN)
        ->count())->toBe(0);
});

it('机器通道：不带 auditor_id 可正常记录，但审核人写进去就是空', function () {
    [$productId, $version] = rgSubmittedVersion();

    config(['skill_delivery.upload_key' => 'test-internal-key']);

    post(
        route('shop.api.skill-supply.review', $version->id),
        [
            'skill_id'        => $productId,
            'layer'           => AuditRecord::LAYER_HUMAN,
            'result'          => AuditRecord::RESULT_PASS,
            'reason_category' => 'other',
            'reason_detail'   => '外部工具批量过审',
        ],
        ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json']
    )->assertOk();

    $record = AuditRecord::query()
        ->forVersion($productId, $version->id)
        ->where('layer', AuditRecord::LAYER_HUMAN)
        ->orderByDesc('id')
        ->first();

    // 不是"没记上"，而是"记上了、但如实写着没有可核验的审核人"
    expect($record)->not->toBeNull()
        ->and($record->result)->toBe(AuditRecord::RESULT_PASS)
        ->and($record->auditor_id)->toBeNull();
});

it('不变量：内部通道写入的记录，auditor_id 恒为空（非空只可能来自控制台）', function () {
    [$productId, $version] = rgSubmittedVersion();

    config(['skill_delivery.upload_key' => 'test-internal-key']);

    $headers = ['X-Internal-Key' => 'test-internal-key', 'Accept' => 'application/json'];

    // L1 仿真 + L2 人审各来一次，都走机器通道
    post(route('shop.api.skill-supply.review', $version->id), [
        'skill_id' => $productId,
        'layer'    => AuditRecord::LAYER_SIMULATION,
        'result'   => AuditRecord::RESULT_PASS,
        'sim'      => ['ran' => true, 'score' => 0.9],
    ], $headers)->assertOk();

    post(route('shop.api.skill-supply.review', $version->id), [
        'skill_id'        => $productId,
        'layer'           => AuditRecord::LAYER_HUMAN,
        'result'          => AuditRecord::RESULT_SUPPLEMENT,
        'reason_category' => 'other',
    ], $headers)->assertOk();

    expect(AuditRecord::query()
        ->forVersion($productId, $version->id)
        ->whereNotNull('auditor_id')
        ->count())->toBe(0);
});
