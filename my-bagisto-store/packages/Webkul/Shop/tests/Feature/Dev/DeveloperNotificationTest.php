<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\NotificationService;
use Webkul\Shop\Services\SkillAudit\AuditService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 开发者通知 · 生产端 + 门户收件箱（任务卡 D-4）。
 *
 * 本卡存在的理由：通知中心读写端早就通了，但**没有开发者侧的生产者** ——
 * 开发者中心里看不到"我被驳回了"。只做生产端（写进 notification 却没人能读）
 * 或只做收件箱（页面在、永远空）都不算完成，所以这里必须**一条链**验证：
 * 提审 → 判定 → 开发者门户看得到。
 *
 * 锁六件事：
 *   ① 端到端主链：提审 → L2 驳回 → 门户能看到，且**带驳回理由**；
 *   ② L2 通过 → approved 通知；③ 发布成功 → published 通知；
 *   ④ 他人不可见（列表不混、read 他条 → 404）；
 *   ⑤ 买家类通知不混进开发者中心（同账号、另一个身份的事务）；
 *   ⑥ 平台自营（seller_id 为 NULL）判定后不发（找不到归属就不发）。
 */

function dnPackage(): UploadedFile
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '通知测试技能'],
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

    return new UploadedFile($path, 'dn-demo.skill', 'application/zip', null, true);
}

/** 走真实门户链路完成「入驻 → 签协议」，返回 [customer, profile]。 */
function dnOnboarded(string $prefix = 'dn'): array
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Notify',
        'email'       => $prefix.uniqid().'@example.com',
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

    return [$customer, DeveloperProfile::where('customer_id', $customer->id)->firstOrFail()];
}

/** 通过门户建一个技能，返回商品 id。 */
function dnSkill(string $name = '通知测试技能'): int
{
    post(route('shop.dev.skills.store'), ['name' => $name, 'price' => 199])->assertRedirect();

    return (int) DB::table('products')->orderByDesc('id')->value('id');
}

/** 直接落一个已提审（in_review）的版本 —— 供"不涉及真包"的用例做夹具。 */
function dnVersion(int $productId, ?int $sellerId, string $status = SkillVersion::STATUS_IN_REVIEW): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'seller_id'    => $sellerId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => $status,
        'license_mode' => 'per_device',
    ]);
}

function dnAudit(int $productId, int $versionId, int $layer, int $result): AuditRecord
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
    Storage::fake(SkillAsset::DISK);
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

// ==================== ① 端到端主链 ====================

it('端到端：开发者提审 → 运营 L2 驳回 → 门户通知页看得到，且带驳回理由', function () {
    [$customer] = dnOnboarded('dn_e2e_');

    $productId = dnSkill('闭环通知技能');

    // 开发者侧真实动作：建版本 → 传包 → 提审（提审会跑 L0 机审）
    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0'])->assertRedirect();
    $version = SkillVersion::where('product_id', $productId)->orderByDesc('id')->firstOrFail();

    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => dnPackage(),
    ])->assertRedirect();

    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);

    // 运营侧 L2 判驳回（审核台 judge 调的就是这个方法 —— 本卡只把"判定处"当作挂载点）
    app(AuditService::class)->recordLayer2(
        $productId,
        $version->id,
        AuditRecord::RESULT_REJECT,
        null,
        'INCOMPLETE',
        '技能包缺少安装说明文件'
    );

    // 判决落库了
    $this->assertDatabaseHas('notification', [
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.review.rejected',
    ]);

    // **核心断言**：开发者访问门户通知页，看得到这条，且含驳回理由（人话 + 明细）
    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('技能审核未通过')
        ->assertSeeText('功能不完整')          // 理由码 INCOMPLETE 的人话标签
        ->assertSeeText('技能包缺少安装说明文件'); // 审核员填的明细
});

// ==================== ② L2 通过 ====================

it('L2 通过 → 开发者收到 skill.review.approved 通知', function () {
    [$customer, $profile] = dnOnboarded('dn_pass_');

    $productId = dnSkill();
    $version = dnVersion($productId, $profile->seller_id);

    app(AuditService::class)->recordLayer2($productId, $version->id, AuditRecord::RESULT_PASS, null);

    $this->assertDatabaseHas('notification', [
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.review.approved',
    ]);
});

// ==================== ③ 发布成功 ====================

it('发布成功 → 开发者收到 skill.published 通知', function () {
    [$customer, $profile] = dnOnboarded('dn_pub_');

    $productId = dnSkill();

    // 过闸门（L0 + L2 都过）且带交付物的版本
    $version = dnVersion($productId, $profile->seller_id);
    SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => $profile->seller_id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/dn-pub.skill',
        'file_name'        => 'dn-pub.skill',
        'file_size'        => 1024,
    ]);
    dnAudit($productId, $version->id, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    dnAudit($productId, $version->id, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    post(route('shop.api.skill-admin.versions.publish', $version->id), [], [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ])->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    $this->assertDatabaseHas('notification', [
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.published',
    ]);

    get(route('shop.dev.notifications.index'))->assertOk()->assertSeeText('技能已上架');
});

// ==================== ④ 他人不可见 ====================

it('他人不可见：A 的通知不出现在 B 的门户，B 用 A 的 id 调 read → 404', function () {
    [$custA] = dnOnboarded('dn_a_');

    $aNote = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $custA->id,
        'type'           => 'skill.review.rejected',
        'title'          => 'A 的驳回通知',
        'content'        => '这是 A 才会看到的内容',
    ]);

    [$custB] = dnOnboarded('dn_b_');

    // B 的门户列表里看不到 A 的通知
    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertDontSeeText('A 的驳回通知')
        ->assertDontSeeText('这是 A 才会看到的内容');

    // B 拿着 A 的通知 id 去标记已读 → 404（不是 403：403 会暴露"这条 id 存在"）
    post(route('shop.dev.notifications.read', $aNote->id))->assertNotFound();

    // 且 A 的通知**没有被标记已读**（B 的越权请求没有副作用）
    expect(Notification::find($aNote->id)->read_at)->toBeNull();
});

// ==================== ⑤ 买家类通知不混入 ====================

it('买家事务通知不混进开发者中心：同一 customer 的 refund.settled 不出现', function () {
    [$customer] = dnOnboarded('dn_buyer_');

    // 同一个账号（既是开发者也是买家），但不是 skill. 前缀 —— 属"另一个身份"下的事务
    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'refund.settled',
        'title'          => '退款已到账',
        'content'        => '你的退款已发起',
    ]);

    // 再放一条技能类通知，证明页面确实渲染了（不是空页导致的假阴性）
    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.published',
        'title'          => '技能已上架',
        'content'        => '版本 1.0.0 已上架',
    ]);

    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('技能已上架')      // skill. 的看得到
        ->assertDontSeeText('退款已到账');  // 买家事务的看不到
});

// ==================== ⑥ 平台自营不发 ====================

it('平台自营（seller_id 为 NULL）判定后不发通知：找不到归属就不发', function () {
    dnOnboarded('dn_plat_');  // 只为拿到门户身份去建一个真实商品；收件人解析看的是版本的 seller_id

    $productId = dnSkill('自营占位技能');
    $platformVersion = SkillVersion::create([
        'product_id'   => $productId,
        'seller_id'    => null,   // 平台自营
        'version'      => '9.9.9',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_IN_REVIEW,
        'license_mode' => 'per_device',
    ]);

    $before = DB::table('notification')->count();

    app(AuditService::class)->recordLayer2(
        $productId,
        $platformVersion->id,
        AuditRecord::RESULT_REJECT,
        null,
        'INCOMPLETE',
        '自营版本没有归属，不该产生收件人'
    );

    // 判定确实落库了（不是为了"没发通知"而整体没跑）
    $this->assertDatabaseHas('audit_record', [
        'skill_id' => $productId,
        'version'  => $platformVersion->id,
        'layer'    => AuditRecord::LAYER_HUMAN,
        'result'   => AuditRecord::RESULT_REJECT,
    ]);

    // notification 表**一行都没多** —— 没有可归属的开发者，就绝不臆造收件人
    expect(DB::table('notification')->count())->toBe($before);
});

// ==================== 附带：门户自己标记已读 ====================

it('门户标记已读：只对本人 + skill. 通知生效，读后 read_at 落库', function () {
    [$customer] = dnOnboarded('dn_read_');

    $note = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.review.approved',
        'title'          => '技能审核已通过',
        'content'        => '等待上架',
    ]);

    post(route('shop.dev.notifications.read', $note->id))->assertRedirect();

    expect(Notification::find($note->id)->read_at)->not->toBeNull();

    // 买家类通知即便属于本人，也不在门户可见范围 → read 404
    $buyerNote = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'refund.settled',
        'title'          => '退款已到账',
    ]);

    post(route('shop.dev.notifications.read', $buyerNote->id))->assertNotFound();
    expect(Notification::find($buyerNote->id)->read_at)->toBeNull();
});

// ==================== ⑦ 通知失败不影响主业务 ====================

it('通知写失败不影响主业务：审核判定照常落库、状态照常迁移（副作用吞异常）', function () {
    [, $profile] = dnOnboarded('dn_fail_');

    $productId = dnSkill();
    $version = dnVersion($productId, $profile->seller_id);

    // 让"发通知"这一步必然抛异常（模拟通知服务不可用）
    $this->mock(NotificationService::class, function ($mock) {
        $mock->shouldReceive('toCustomer')->andThrow(new RuntimeException('通知服务不可用'));
    });

    // 判定必须**照常返回**，不被通知异常打断
    $result = app(AuditService::class)->recordLayer2(
        $productId,
        $version->id,
        AuditRecord::RESULT_REJECT,
        null,
        'INCOMPLETE',
        '理由不能丢'
    );

    expect($result['result'])->toBe(AuditRecord::RESULT_REJECT)
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_REJECTED);

    // 判定确实落库了（通知炸了也没影响"判决"这个权威事实）
    $this->assertDatabaseHas('audit_record', [
        'skill_id' => $productId,
        'version'  => $version->id,
        'layer'    => AuditRecord::LAYER_HUMAN,
        'result'   => AuditRecord::RESULT_REJECT,
    ]);

    // 通知一条都没写进去（服务一直在抛）—— 但这不是失败，是"副作用没成"
    $this->assertDatabaseMissing('notification', [
        'recipient_id' => $profile->customer_id,
        'type'         => 'skill.review.rejected',
    ]);
});
