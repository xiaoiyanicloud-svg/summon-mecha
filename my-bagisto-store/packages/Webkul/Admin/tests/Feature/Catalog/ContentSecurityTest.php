<?php

use Webkul\Shop\Models\ContentSecurityRecord;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 内容安全处置台（计划 §4.16 的人工那半截）。
 *
 * 在此之前两个断点都在：
 *   ① 扫描结果**根本不落库**（`content_security_record` 全仓零写入）；
 *   ② 就算落了库，也没有任何处置入口。
 * （① 的修复与留痕断言在 `Shop/tests/Feature/SkillAudit/SkillReviewGateTest.php`，
 *   它走的是真实的提审链路。本文件只管处置台。）
 */

function csRecord(array $overrides = []): ContentSecurityRecord
{
    return ContentSecurityRecord::create(array_merge([
        'target_type' => ContentSecurityRecord::TARGET_SKILL_META,
        'target_id'   => 555001,
        'provider'    => 'local',
        'scan_type'   => 'text',
        'result'      => ContentSecurityRecord::RESULT_BLOCK,
        'labels'      => ['赌博'],
        'raw'         => ['driver' => 'local'],
        'disposition' => ContentSecurityRecord::DISPOSITION_PENDING,
        'scanned_at'  => now(),
    ], $overrides));
}

it('内容安全列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.content-security.index'))
        ->assertOk()
        ->assertSeeText('内容安全');
});

it('详情页可以渲染，并显示命中的词', function () {
    $record = csRecord();

    $this->loginAsAdmin();

    get(route('admin.catalog.content-security.show', $record->id))
        ->assertOk()
        ->assertSeeText('赌博')
        ->assertSeeText('机器判定')
        ->assertSeeText('人工处置');
});

it('处置：按违规处置不需要理由也放行', function () {
    $record = csRecord();

    $admin = $this->loginAsAdmin();

    post(route('admin.catalog.content-security.dispose', $record->id), [
        'disposition' => 'actioned',
    ])->assertRedirect();

    $fresh = $record->fresh();

    expect($fresh->disposition)->toBe(ContentSecurityRecord::DISPOSITION_ACTIONED)
        ->and($fresh->disposition_by)->toBe($admin->id)
        ->and($fresh->disposition_at)->not->toBeNull();

    expect(DB::table('operation_log')
        ->where('action', 'content_security.dispose')
        ->where('target_id', $record->id)
        ->exists())->toBeTrue();
});

it('处置：放行（推翻机器拦截）必须写理由，否则不落库', function () {
    $record = csRecord();

    $this->loginAsAdmin();

    post(route('admin.catalog.content-security.dispose', $record->id), [
        'disposition' => 'cleared',
        // 故意不写 note
    ])->assertRedirect();

    expect($record->fresh()->disposition)->toBe(ContentSecurityRecord::DISPOSITION_PENDING)
        ->and(session('error'))->toContain('必须写明理由');
});

it('处置：写了理由的放行可以落库', function () {
    $record = csRecord();

    $this->loginAsAdmin();

    post(route('admin.catalog.content-security.dispose', $record->id), [
        'disposition' => 'cleared',
        'note'        => '复核过是产品名里的字，非违规',
    ])->assertRedirect();

    $fresh = $record->fresh();

    expect($fresh->disposition)->toBe(ContentSecurityRecord::DISPOSITION_CLEARED)
        ->and($fresh->disposition_note)->toContain('非违规');
});

it('处置：值域受限，不能自造处置状态', function () {
    $record = csRecord();

    $this->loginAsAdmin();

    post(route('admin.catalog.content-security.dispose', $record->id), [
        'disposition' => 'ignored',
    ])->assertSessionHasErrors('disposition');

    expect($record->fresh()->disposition)->toBe(ContentSecurityRecord::DISPOSITION_PENDING);
});

it('机器判 pass 的记录：界面明说无需人工处置，不给处置表单', function () {
    $record = csRecord([
        'result' => ContentSecurityRecord::RESULT_PASS,
        'labels' => null,
    ]);

    $this->loginAsAdmin();

    get(route('admin.catalog.content-security.show', $record->id))
        ->assertOk()
        ->assertSeeText('无需人工处置')
        ->assertDontSee('name="disposition"', false);
});

it('模型口径：pass 不算"待人工处理"（否则队列永远清不完）', function () {
    $pass = csRecord(['result' => ContentSecurityRecord::RESULT_PASS, 'labels' => null]);
    $block = csRecord(['result' => ContentSecurityRecord::RESULT_BLOCK]);

    expect($pass->needsHumanAttention())->toBeFalse()
        ->and($block->needsHumanAttention())->toBeTrue();

    // 已处置的也不再是待办
    $block->update(['disposition' => ContentSecurityRecord::DISPOSITION_ACTIONED]);

    expect($block->fresh()->needsHumanAttention())->toBeFalse();
});
