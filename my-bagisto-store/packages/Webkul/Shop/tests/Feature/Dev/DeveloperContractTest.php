<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * `/api/dev/*` 合同测试（v3.4 步骤 9）。
 *
 * 目的：证明**一个纯 API 客户端（无 Blade、无 session）能独立跑完
 * 「登录 → 入驻 → 签协议 → 建技能 → 建版本 → 传包 → 提审」整条闭环**。
 *
 * 这组测试存在的直接原因：一次审计发现该闭环此前**跑不通**——
 *   · 没有任何端点发 token（纯前端登不进来）
 *   · `POST /api/dev/profile` 挂在 api.developer 下 → 未入驻即 403 → **新开发者永远入驻不了**（僵尸端点）
 *   · `GET /api/dev/agreements` 不回协议正文 → 签署页渲染不出来
 * 这三条现在是回归测试锁死的对象。
 *
 * ⚠ 用**真实 Bearer token**（不是 Sanctum::actingAs 的替身），因为要验的正是这条链路。
 */

function apiCustomer(string $password = 'secret123'): Customer
{
    return Customer::create([
        'first_name'  => 'Api',
        'last_name'   => 'Dev',
        'email'       => 'apidev'.uniqid().'@example.com',
        'password'    => bcrypt($password),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function apiPackage(): UploadedFile
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '合同测试技能'],
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

    $path = tempnam(sys_get_temp_dir(), 'skill').'.skill';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->close();

    return new UploadedFile($path, 'demo.skill', 'application/zip', null, true);
}

beforeEach(function () {
    Storage::fake(SkillAsset::DISK);
});

it('发 token：错误凭据 401（且不区分邮箱不存在/密码错，防账号枚举）', function () {
    $customer = apiCustomer();

    postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'wrong-password',
    ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    postJson(route('shop.api.dev.auth.token'), [
        'email' => 'nobody'.uniqid().'@example.com', 'password' => 'whatever',
    ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('未入驻也能拿到 token，且 GET/POST profile 不再被 403 挡死（僵尸端点回归）', function () {
    $customer = apiCustomer();

    $res = postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'secret123',
    ])->assertOk();

    $token = $res->json('data.token');
    $headers = ['Authorization' => 'Bearer '.$token];

    expect($token)->toBeString()->not->toBeEmpty();
    // token 带真实过期（全局 sanctum.expiration 是 null，这里走按 token 过期）
    expect($res->json('data.expires_at'))->not->toBeNull();
    expect($res->json('data.developer.has_profile'))->toBeFalse();

    // ⚠ 这条以前是 403 —— 中间件因"没有 profile"拒绝，而创建 profile 的正是这个请求
    getJson(route('shop.api.dev.profile.show'), $headers)
        ->assertOk()
        ->assertJsonPath('data', null);

    postJson(route('shop.api.dev.profile.store'), ['dev_type' => 'personal'], $headers)
        ->assertStatus(201);

    getJson(route('shop.api.dev.profile.show'), $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('纯 API 跑完整闭环：登录→入驻→签协议→建技能→建版本→传包→提审', function () {
    $customer = apiCustomer();

    $token = postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'secret123',
    ])->assertOk()->json('data.token');

    $h = ['Authorization' => 'Bearer '.$token];

    // 入驻
    postJson(route('shop.api.dev.profile.store'), [
        'dev_type' => 'personal', 'seller_name' => '合同测试',
    ], $h)->assertStatus(201);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->firstOrFail();

    // 协议正文必须能取到（否则纯前端渲染不出签署页）
    $agreements = getJson(route('shop.api.dev.agreements.index'), $h)->assertOk()->json('data');

    expect($agreements)->toHaveCount(2);
    expect($agreements[0]['text'])->not->toBeEmpty();
    expect($agreements[0]['text_hash'])->toBe(hash('sha256', $agreements[0]['text']));

    postJson(route('shop.api.dev.agreements.store'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ], $h)->assertOk();

    expect($profile->fresh()->hasSignedAllAgreements())->toBeTrue();

    // S-12a：建技能前置实名（平台审核动作，测试直接置 approved）
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    // 建技能
    $skillId = null;
    postJson(route('shop.api.dev.skills.store'), [
        'name' => '合同测试技能', 'price' => 88,
    ], $h)->assertStatus(201);

    $skillId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');
    expect($skillId)->not->toBeNull();

    getJson(route('shop.api.dev.skills.index'), $h)
        ->assertOk()
        ->assertJsonPath('data.0.name', '合同测试技能');

    // 建版本
    postJson(route('shop.api.dev.skills.versions.store', $skillId), ['version' => '1.0.0'], $h)
        ->assertStatus(201);

    $version = SkillVersion::where('product_id', $skillId)->firstOrFail();

    // 传包（multipart + Bearer）—— 注意用 post() 而不是 postJson()，文件要走 multipart
    $res = $this->post(route('shop.api.dev.versions.assets.store', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => apiPackage(),
    ], $h + ['Accept' => 'application/json']);

    $res->assertStatus(201)->assertJsonPath('data.file_ready', true);

    // 提审
    $submit = $this->post(route('shop.api.dev.versions.submit', $version->id), [], $h + ['Accept' => 'application/json']);
    $submit->assertOk()->assertJsonPath('code', 'SUBMITTED');

    $this->assertDatabaseHas('audit_record', [
        'skill_id' => $skillId,
        'version'  => $version->id,
        'layer'    => AuditRecord::LAYER_MACHINE,
        'result'   => AuditRecord::RESULT_PASS,
    ]);

    // 审核状态可查
    getJson(route('shop.api.dev.audits.index'), $h)->assertOk()->assertJsonCount(1, 'data');
});

it('登出会真正吊销 token 行', function () {
    $customer = apiCustomer();

    $token = postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'secret123',
    ])->json('data.token');

    $h = ['Authorization' => 'Bearer '.$token];

    getJson(route('shop.api.dev.profile.show'), $h)->assertOk();

    $before = DB::table('personal_access_tokens')
        ->where('tokenable_id', $customer->id)->count();
    expect($before)->toBe(1);

    postJson(route('shop.api.dev.auth.logout'), [], $h)->assertOk();

    // 契约是"token 行被删除"——删掉了就再也认证不上（Sanctum 语义）
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $customer->id)->count())->toBe(0);

    // ⚠ 这里不直接断言"下一次请求 401"：同一个测试进程里多次请求共享应用容器，
    //   sanctum guard 会缓存已解析的用户，断言会假阳性。要验真实失效，
    //   需另起进程（见 HTTP 冒烟），不在此处硬凑。
});

it('未入驻用户访问需要入驻的端点 → 403 DEVELOPER_PROFILE_REQUIRED（该拦的仍要拦）', function () {
    $customer = apiCustomer();

    $token = postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'secret123',
    ])->json('data.token');

    $h = ['Authorization' => 'Bearer '.$token];

    getJson(route('shop.api.dev.skills.index'), $h)
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEVELOPER_PROFILE_REQUIRED');

    getJson(route('shop.api.dev.agreements.index'), $h)
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEVELOPER_PROFILE_REQUIRED');
});

it('信封冻结：成功 {message,data}、错误 {message,code}；404 也带 code', function () {
    $customer = apiCustomer();

    $token = postJson(route('shop.api.dev.auth.token'), [
        'email' => $customer->email, 'password' => 'secret123',
    ])->json('data.token');

    $h = ['Authorization' => 'Bearer '.$token];

    postJson(route('shop.api.dev.profile.store'), ['dev_type' => 'personal'], $h);

    // 成功：两个键都在
    getJson(route('shop.api.dev.skills.index'), $h)
        ->assertOk()
        ->assertJsonStructure(['message', 'data']);

    // 错误：{message, code}
    getJson(route('shop.api.dev.skills.show', 999999), $h)
        ->assertStatus(404)
        ->assertJsonPath('code', 'SKILL_NOT_FOUND')
        ->assertJsonStructure(['message', 'code']);
});
