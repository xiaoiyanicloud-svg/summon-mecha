<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillReport;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * 加固计划 §四 A1 · 举报（用户侧）。
 *
 * `skill_report` 建表后一直**零写入方**：技能上线之后，用户没有任何违规举报入口。
 * 本文件锁三件事：
 *   1. 举报真的落表，且去重槽位（`open_slot`）语义正确 —— 未处置期间同一对象只受理一条；
 *   2. 防滥用两件套在位：去重 + 限流（`throttle:report`，比 write 更紧）；
 *   3. **举报命中违禁词不拦、但要留痕** —— 举报人描述违规往往必须引述违规内容，
 *      拦了等于把合规通道关掉（与评价那边的口径刻意不同）。
 */

function a1Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Report',
        'last_name'   => 'User',
        'email'       => 'a1'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 造一个技能商品（本项目里"技能"就是商品）。 */
function a1Skill(): int
{
    return DB::table('products')->insertGetId([
        'sku'        => 'a1-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** 写一个临时违禁词表并挂到 local 驱动上（与 SkillAudit 的 useWords 同源，改名避免套件内重名）。 */
function a1Words(string $content): void
{
    $path = tempnam(sys_get_temp_dir(), 'a1words').'.txt';
    file_put_contents($path, $content);

    config([
        'content_safety.driver'                   => 'local',
        'content_safety.drivers.local.words_path' => $path,
    ]);
}

it('A1 · 举报技能 → 201 落表，状态 pending 并占用去重槽位', function () {
    $customer = a1Customer();
    $skillId = a1Skill();

    actingAs($customer, 'customer');

    $res = postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_SAFETY,
        'detail'          => '这个技能会让机械臂快速甩动，离人很近也不减速。',
    ])->assertStatus(201)
        ->assertJsonPath('success', true);

    $report = SkillReport::findOrFail($res->json('data.id'));

    expect($report->reporter_customer_id)->toBe($customer->id)
        ->and($report->target_id)->toBe($skillId)
        ->and($report->reason_category)->toBe(SkillReport::REASON_SAFETY)
        ->and($report->status)->toBe(SkillReport::STATUS_PENDING)
        ->and($report->open_slot)->toBe(SkillReport::OPEN_SLOT)
        ->and($report->handler_id)->toBeNull();
});

it('A1 · 举报不要求持有该技能 —— 别人没买的技能也能举报', function () {
    $customer = a1Customer();
    $skillId = a1Skill();     // 刻意不给这位客户任何授权

    actingAs($customer, 'customer');

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_ILLEGAL,
    ])->assertStatus(201);

    // 要求持有才能举报 = 把最该被举报的技能保护起来，通道等于不存在
    expect(DB::table('skill_entitlements')->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(SkillReport::where('reporter_customer_id', $customer->id)->count())->toBe(1);
});

it('A1 · 重复举报同一对象 → 200 且 duplicate:true，不新增记录', function () {
    $customer = a1Customer();
    $skillId = a1Skill();

    actingAs($customer, 'customer');

    $payload = [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_FRAUD,
        'detail'          => '宣传里说能做的动作，实际做不到。',
    ];

    $first = postJson('/api/skill-reports', $payload)->assertStatus(201);
    $second = postJson('/api/skill-reports', $payload)
        ->assertStatus(200)
        ->assertJsonPath('duplicate', true);

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SkillReport::query()->where('target_type', SkillReport::TARGET_SKILL)
            ->where('target_id', $skillId)->count())->toBe(1);
});

it('A1 · 举报对象不存在 → 422 REPORT_TARGET_NOT_FOUND，不落表', function () {
    $customer = a1Customer();

    actingAs($customer, 'customer');

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => 99999999,
        'reason_category' => SkillReport::REASON_OTHER,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'REPORT_TARGET_NOT_FOUND');

    expect(SkillReport::count())->toBe(0);
});

it('A1 · 对象类型/理由类别值域受限；developer 是留位，不接受', function () {
    $customer = a1Customer();
    $skillId = a1Skill();

    actingAs($customer, 'customer');

    // developer：表注释里留了位，但第三方开发者目前为 0（项目简报），不实现也不接受
    postJson('/api/skill-reports', [
        'target_type'     => 'developer',
        'target_id'       => 1,
        'reason_category' => SkillReport::REASON_OTHER,
    ])->assertStatus(422);

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => 'whatever',
    ])->assertStatus(422);

    expect(SkillReport::count())->toBe(0);
});

it('A1 · 正文超长（>2000）→ 422，不落表', function () {
    $customer = a1Customer();
    $skillId = a1Skill();

    actingAs($customer, 'customer');

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_OTHER,
        'detail'          => str_repeat('长', 2001),
    ])->assertStatus(422);

    expect(SkillReport::count())->toBe(0);
});

it('A1 · 正文命中违禁词 → 照收（不拦），但扫描留痕落 content_security_record', function () {
    a1Words("赌博\n");

    $customer = a1Customer();
    $skillId = a1Skill();

    actingAs($customer, 'customer');

    $res = postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_ILLEGAL,
        // 举报人要说明"这个技能在搞赌博"，必然要引述违规词
        'detail'          => '这个技能实际是在做赌博筹码兑换。',
    ])->assertStatus(201);

    // 照收：拦掉它等于"最该被举报的内容最报不上来"
    expect(SkillReport::count())->toBe(1);

    // 但机器看到的东西必须留痕，由内容安全处置台的人工判断
    $scan = ContentSecurityRecord::query()
        ->where('target_type', ContentSecurityRecord::TARGET_SKILL_REPORT)
        ->where('target_id', $res->json('data.id'))
        ->first();

    expect($scan)->not->toBeNull()
        ->and($scan->result)->toBe(ContentSecurityRecord::RESULT_BLOCK)
        ->and($scan->labels)->toContain('赌博')
        ->and($scan->disposition)->toBe(ContentSecurityRecord::DISPOSITION_PENDING);
});

it('A1 · 限流：同一用户一分钟内第 6 次举报 → 429 RATE_LIMITED', function () {
    $customer = a1Customer();

    actingAs($customer, 'customer');

    // 换对象刷 —— 去重挡的是"同一对象重复"，挡不住"换个对象接着刷"，这一档就是挡它的
    $ids = array_map(fn () => a1Skill(), range(1, 6));

    foreach (array_slice($ids, 0, 5) as $id) {
        postJson('/api/skill-reports', [
            'target_type'     => SkillReport::TARGET_SKILL,
            'target_id'       => $id,
            'reason_category' => SkillReport::REASON_OTHER,
        ])->assertStatus(201);
    }

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $ids[5],
        'reason_category' => SkillReport::REASON_OTHER,
    ])->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');

    expect(SkillReport::count())->toBe(5);
});

it('A1 · 未登录 → 401，不落表', function () {
    $skillId = a1Skill();

    postJson('/api/skill-reports', [
        'target_type'     => SkillReport::TARGET_SKILL,
        'target_id'       => $skillId,
        'reason_category' => SkillReport::REASON_OTHER,
    ])->assertStatus(401);

    expect(SkillReport::count())->toBe(0);
});
