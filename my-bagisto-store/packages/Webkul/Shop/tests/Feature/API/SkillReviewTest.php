<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillReview;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * 加固计划 §四 A1 · 技能评价（用户侧，增长功能）。
 *
 * `skill_review` 建表后同样零写入方。本文件锁四件事：
 *   1. 评价真的落表，字段/归属/上限都对；
 *   2. **买了才能评**（与举报"谁都能报"刻意相反：公开内容需要门槛）；
 *   3. 一人一技能一条 —— 唯一键兜底，第二次明确告知"已经评过"；
 *   4. **可见性由机器判定显式写**，绝不由列默认值决定（迁移 000007 的立场）：
 *      命中违规 → hidden（留档，人工可改判）；扫描器没跑成 → pending（没查 ≠ 合规）。
 */

function a1rCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Review',
        'last_name'   => 'User',
        'email'       => 'a1r'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一个技能商品；给 $owner 时顺带授权（`skill_entitlements`）。返回 productId。 */
function a1rSkill(?Customer $owner = null): int
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'a1r-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($owner) {
        DB::table('skill_entitlements')->insert([
            'customer_id'      => $owner->id,
            'order_id'         => 0,
            'order_item_id'    => 0,
            'product_id'       => $productId,
            'skill_version_id' => 0,
            'status'           => 'active',
            'license_key'      => substr(md5(uniqid()), 0, 32),
            'delivery_channel' => 'manual',
            'granted_at'       => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    return $productId;
}

/** 临时词表挂到 local 驱动（与 SkillAudit 的 useWords 同源，改名避免套件内重名）。 */
function a1rWords(string $content): void
{
    $path = tempnam(sys_get_temp_dir(), 'a1rwords').'.txt';
    file_put_contents($path, $content);

    config([
        'content_safety.driver'                   => 'local',
        'content_safety.drivers.local.words_path' => $path,
    ]);
}

it('A1 · 买过的技能 → 评价落表，机审通过后显式可见', function () {
    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    $res = postJson('/api/skill-reviews', [
        'skill_id'     => $skillId,
        'rating'       => 5,
        'title'        => '抓取很稳',
        'content'      => '在自家仓库用了两周，纸箱识别比想象中准。',
        'is_anonymous' => true,
    ])->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.visible', true);

    $review = SkillReview::findOrFail($res->json('data.id'));

    expect($review->skill_id)->toBe($skillId)
        ->and($review->customer_id)->toBe($customer->id)
        ->and($review->rating)->toBe(5)
        ->and($review->title)->toBe('抓取很稳')
        ->and($review->is_anonymous)->toBeTrue()
        // 可见性必须显式写：迁移 000007 已把默认值改成 pending，
        // 靠默认值就等于"没人判断过就对外可见"
        ->and($review->status)->toBe(SkillReview::STATUS_VISIBLE);

    // 扫过的内容要留痕（人工复核 / 申诉都以它为依据）
    expect(ContentSecurityRecord::query()
        ->where('target_type', ContentSecurityRecord::TARGET_REVIEW)
        ->where('target_id', $review->id)
        ->value('result'))->toBe(ContentSecurityRecord::RESULT_PASS);
});

it('A1 · 没买过该技能 → 422 SKILL_NOT_OWNED，不落表', function () {
    $customer = a1rCustomer();
    $skillId = a1rSkill();          // 刻意不给授权

    actingAs($customer, 'customer');

    postJson('/api/skill-reviews', [
        'skill_id' => $skillId,
        'rating'   => 1,
        'title'    => '差',
        'content'  => '没用过，先给一星。',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'SKILL_NOT_OWNED');

    expect(SkillReview::count())->toBe(0);
});

it('A1 · 一人一技能一条：第二次 → 422 REVIEW_ALREADY_EXISTS，仍只有一条', function () {
    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    $payload = [
        'skill_id' => $skillId,
        'rating'   => 4,
        'title'    => '不错',
        'content'  => '整体好用，偶尔需要重试。',
    ];

    $first = postJson('/api/skill-reviews', $payload)->assertStatus(201);

    postJson('/api/skill-reviews', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'REVIEW_ALREADY_EXISTS')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(SkillReview::count())->toBe(1);
});

it('A1 · 正文（或标题）命中违禁词 → 落表但不可见，扫描留痕为 block', function () {
    a1rWords("赌博\n");

    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    // 标题命中：只扫正文的话，把违规文案挪到标题就绕过去了
    $res = postJson('/api/skill-reviews', [
        'skill_id' => $skillId,
        'rating'   => 5,
        'title'    => '赌博神器',
        'content'  => '描述里全是筹码兑换。',
    ])->assertStatus(201)
        ->assertJsonPath('data.visible', false)
        ->assertJsonPath('data.status', SkillReview::STATUS_HIDDEN);

    $review = SkillReview::findOrFail($res->json('data.id'));

    // 不发布，但**留档**：词表会误判，丢弃了人工就改判不回来
    expect($review->status)->toBe(SkillReview::STATUS_HIDDEN)
        ->and($review->isVisible())->toBeFalse();

    $scan = ContentSecurityRecord::query()
        ->where('target_type', ContentSecurityRecord::TARGET_REVIEW)
        ->where('target_id', $review->id)
        ->first();

    expect($scan->result)->toBe(ContentSecurityRecord::RESULT_BLOCK)
        ->and($scan->labels)->toContain('赌博');
});

it('A1 · 扫描器没跑成（词表缺失）→ 落 pending，不能当成"通过"', function () {
    config([
        'content_safety.driver'                   => 'local',
        'content_safety.drivers.local.words_path' => sys_get_temp_dir().'/a1r-not-exists-'.uniqid().'.txt',
    ]);

    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    $res = postJson('/api/skill-reviews', [
        'skill_id' => $skillId,
        'rating'   => 3,
        'title'    => '一般',
        'content'  => '还行。',
    ])->assertStatus(201)
        ->assertJsonPath('data.visible', false);

    expect(SkillReview::findOrFail($res->json('data.id'))->status)->toBe(SkillReview::STATUS_PENDING);
});

it('A1 · 评分/标题/正文越界 → 422，不落表', function () {
    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    $base = ['skill_id' => $skillId, 'rating' => 4, 'title' => 'ok', 'content' => 'ok'];

    postJson('/api/skill-reviews', array_merge($base, ['rating' => 6]))->assertStatus(422);
    postJson('/api/skill-reviews', array_merge($base, ['rating' => 0]))->assertStatus(422);
    postJson('/api/skill-reviews', array_merge($base, ['title' => str_repeat('长', SkillReview::TITLE_MAX + 1)]))->assertStatus(422);
    postJson('/api/skill-reviews', array_merge($base, ['content' => str_repeat('长', SkillReview::CONTENT_MAX + 1)]))->assertStatus(422);

    expect(SkillReview::count())->toBe(0);
});

it('A1 · 附件上限：最多 3 张、单张 ≤5MB、只收图片；带图转人工复核', function () {
    Storage::fake('public');

    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    actingAs($customer, 'customer');

    $base = ['skill_id' => $skillId, 'rating' => 4, 'title' => '附图', 'content' => '见图。'];

    // 4 张 → 超上限
    postJson('/api/skill-reviews', array_merge($base, [
        'media' => array_map(fn ($i) => UploadedFile::fake()->create("p{$i}.jpg", 10, 'image/jpeg'), range(1, 4)),
    ]))->assertStatus(422);

    // 6MB 单张 → 超上限
    postJson('/api/skill-reviews', array_merge($base, [
        'media' => [UploadedFile::fake()->create('big.jpg', SkillReview::MEDIA_MAX_KB + 100, 'image/jpeg')],
    ]))->assertStatus(422);

    // 非图片 → 不收（本轮内容安全只扫文本，收下就等于承诺了查不了的事）
    postJson('/api/skill-reviews', array_merge($base, [
        'media' => [UploadedFile::fake()->create('note.txt', 5, 'text/plain')],
    ]))->assertStatus(422);

    expect(SkillReview::count())->toBe(0);

    // 3 张图片 → 正常落盘 + 落 media；**但不过机器的手**：
    // 本层内容安全只扫文本，带图必须等人看一眼才见得了光
    $res = postJson('/api/skill-reviews', array_merge($base, [
        'media' => array_map(fn ($i) => UploadedFile::fake()->create("ok{$i}.jpg", 10, 'image/jpeg'), range(1, 3)),
    ]))->assertStatus(201)
        ->assertJsonPath('data.status', SkillReview::STATUS_PENDING)
        ->assertJsonPath('data.visible', false);

    $review = SkillReview::findOrFail($res->json('data.id'));

    expect($review->media)->toHaveCount(3)
        ->and($review->status)->toBe(SkillReview::STATUS_PENDING);

    foreach ($review->media as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('A1 · 未登录 → 401，不落表', function () {
    $customer = a1rCustomer();
    $skillId = a1rSkill($customer);

    postJson('/api/skill-reviews', [
        'skill_id' => $skillId,
        'rating'   => 5,
        'title'    => '好',
        'content'  => '好。',
    ])->assertStatus(401);

    expect(SkillReview::count())->toBe(0);
});
