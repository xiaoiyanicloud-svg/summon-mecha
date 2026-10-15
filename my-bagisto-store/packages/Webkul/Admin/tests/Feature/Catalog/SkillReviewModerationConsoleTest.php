<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillReview;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 加固计划 §四 A1 · 评价处置台（人工端）。
 *
 * 与 `ContentSecurityTest` 同源：机器先扫、人再判。本文件锁四件事：
 *   1. 用户评价在后台**看得见**（列表 + 详情 + 机器判定）；
 *   2. 处置落库并留 `operation_log`；
 *   3. **恢复显示（推翻机器拦截）必须写理由**；隐藏不强制；
 *   4. 匿名评价在后台**同样不显示真名**（否则那个选项就是骗人的）。
 */

function a1revAuthor(): Customer
{
    return Customer::create([
        'first_name'  => 'Revi',
        'last_name'   => 'Ewer',
        'email'       => 'a1rev'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function a1revReview(array $overrides = []): SkillReview
{
    return SkillReview::create(array_merge([
        'skill_id'    => 555002,
        'customer_id' => a1revAuthor()->id,
        'rating'      => 2,
        'title'       => '噪音有点大',
        'content'     => '夜里跑起来声音很明显，白天还行。',
        'status'      => SkillReview::STATUS_HIDDEN,
    ], $overrides));
}

it('A1 · 评价列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skill-review-moderation.index'))
        ->assertOk()
        ->assertSeeText('评价处置台');
});

it('A1 · 用户评价在后台可见：列表那一行 + 详情正文 + 机器判定', function () {
    $review = a1revReview();

    ContentSecurityRecord::create([
        'target_type' => ContentSecurityRecord::TARGET_REVIEW,
        'target_id'   => $review->id,
        'provider'    => 'local',
        'scan_type'   => 'text',
        'result'      => ContentSecurityRecord::RESULT_BLOCK,
        'labels'      => ['赌博'],
        'raw'         => ['driver' => 'local'],
        'disposition' => ContentSecurityRecord::DISPOSITION_PENDING,
        'scanned_at'  => now(),
    ]);

    $this->loginAsAdmin();

    $grid = get(route('admin.catalog.skill-review-moderation.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();

    expect($grid->json('records.0.id'))->toBe($review->id)
        ->and($grid->json('records.0.status'))->toContain('不可见');

    get(route('admin.catalog.skill-review-moderation.show', $review->id))
        ->assertOk()
        ->assertSeeText('夜里跑起来声音很明显')
        ->assertSeeText('噪音有点大')
        ->assertSeeText('命中违禁词')
        ->assertSeeText('赌博');
});

it('A1 · 匿名评价在后台也不显示真名', function () {
    $author = a1revAuthor();
    $review = a1revReview(['customer_id' => $author->id, 'is_anonymous' => true]);

    $this->loginAsAdmin();

    get(route('admin.catalog.skill-review-moderation.show', $review->id))
        ->assertOk()
        ->assertSeeText('匿名')
        ->assertDontSeeText($author->first_name.' '.$author->last_name);
});

it('A1 · 隐藏一条评价 → 落库 + 留 operation_log', function () {
    $review = a1revReview(['status' => SkillReview::STATUS_VISIBLE]);

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-review-moderation.moderate', $review->id), [
        'status' => 'hidden',
        'note'   => '含未公开的产品缺陷细节，先隐藏。',
    ])->assertRedirect();

    $fresh = $review->fresh();

    expect($fresh->status)->toBe(SkillReview::STATUS_HIDDEN)
        ->and($fresh->moderation_note)->toContain('先隐藏');

    expect(DB::table('operation_log')
        ->where('action', 'skill_review.moderate')
        ->where('target_id', $review->id)
        ->exists())->toBeTrue();
});

it('A1 · 恢复显示必须写理由，否则不落库', function () {
    $review = a1revReview();

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-review-moderation.moderate', $review->id), [
        'status' => 'visible',
        // 故意不写 note
    ])->assertRedirect();

    expect($review->fresh()->status)->toBe(SkillReview::STATUS_HIDDEN)
        ->and(session('error'))->toContain('必须写明理由');
});

it('A1 · 写了理由的恢复显示可以落库（词表误判的出口）', function () {
    $review = a1revReview();

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-review-moderation.moderate', $review->id), [
        'status' => 'visible',
        'note'   => '复核：命中的是商品名里的字，非违规，恢复显示。',
    ])->assertRedirect();

    $fresh = $review->fresh();

    expect($fresh->status)->toBe(SkillReview::STATUS_VISIBLE)
        ->and($fresh->isVisible())->toBeTrue()
        ->and($fresh->moderation_note)->toContain('商品名');
});

it('A1 · 处置值域受限：不能退回"待人工看"，也不能自造状态', function () {
    $review = a1revReview(['status' => SkillReview::STATUS_VISIBLE]);

    $this->loginAsAdmin();

    // 退回 pending 会让"机审没跑成"与"人已经判过"混在一起，事后分不清谁判的
    post(route('admin.catalog.skill-review-moderation.moderate', $review->id), ['status' => 'pending'])
        ->assertSessionHasErrors('status');

    post(route('admin.catalog.skill-review-moderation.moderate', $review->id), ['status' => 'ignored'])
        ->assertSessionHasErrors('status');

    expect($review->fresh()->status)->toBe(SkillReview::STATUS_VISIBLE);
});
