<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\SkillReport;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 加固计划 §四 A1 · 举报处置台（人工端）。
 *
 * 与 `ContentSecurityTest` 同源：机器扫 + 人工判，人是那后半截。
 * 本文件锁四件事：
 *   1. 用户举报在后台**看得见**（列表 + 详情，含举报正文与理由）；
 *   2. 处置落库并留 `operation_log`（谁、什么时候、判成什么）；
 *   3. **驳回必须写理由**（认定"没问题"= 把风险收回来自己扛）；
 *   4. 结案**释放去重槽位** —— 不释放的话，被驳回一次之后这个对象再没人能举报。
 */

function a1Report(array $overrides = []): SkillReport
{
    return SkillReport::create(array_merge([
        'reporter_customer_id' => a1Reporter()->id,
        'target_type'          => SkillReport::TARGET_SKILL,
        'target_id'            => 555001,
        'reason_category'      => SkillReport::REASON_SAFETY,
        'detail'               => '机械臂在有人靠近时不减速。',
        'status'               => SkillReport::STATUS_PENDING,
        'open_slot'            => SkillReport::OPEN_SLOT,
    ], $overrides));
}

function a1Reporter(): Customer
{
    return Customer::create([
        'first_name'  => 'Rep',
        'last_name'   => 'Orter',
        'email'       => 'a1admin'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('A1 · 举报列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skill-reports.index'))
        ->assertOk()
        ->assertSeeText('举报处置台');
});

it('A1 · 用户举报在后台可见：列表带理由，详情带正文', function () {
    $report = a1Report();

    $this->loginAsAdmin();

    // 列表走 datagrid（ajax）—— 断言那一行真的在数据里，不是只断 200
    $grid = get(route('admin.catalog.skill-reports.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk();

    expect($grid->json('records.0.id'))->toBe($report->id)
        // 列渲染的 closure 真的跑了（datagrid 回的是渲染后的 HTML）
        ->and($grid->json('records.0.status'))->toContain('待处置')
        ->and($grid->json('records.0.reason_category'))->toContain('安全风险');

    get(route('admin.catalog.skill-reports.show', $report->id))
        ->assertOk()
        ->assertSeeText('机械臂在有人靠近时不减速')
        ->assertSeeText('安全风险')
        ->assertSeeText('待处置');
});

it('A1 · 详情页对"对象已不存在"和"未填正文"给出明确说明，不静默留白', function () {
    $report = a1Report(['target_id' => 999999, 'detail' => null]);

    $this->loginAsAdmin();

    get(route('admin.catalog.skill-reports.show', $report->id))
        ->assertOk()
        ->assertSeeText('已不存在')
        ->assertSeeText('用户未填写正文');
});

it('A1 · 处置成立 → 落库 + 留 operation_log + 释放去重槽位', function () {
    $report = a1Report();

    $admin = $this->loginAsAdmin();

    post(route('admin.catalog.skill-reports.dispose', $report->id), [
        'status' => 'accepted',
        'note'   => '已通知开发者整改，并暂停新下发。',
    ])->assertRedirect();

    $fresh = $report->fresh();

    expect($fresh->status)->toBe(SkillReport::STATUS_ACCEPTED)
        ->and($fresh->handler_id)->toBe($admin->id)
        ->and($fresh->handled_at)->not->toBeNull()
        ->and($fresh->handling_note)->toContain('暂停新下发')
        // 结案 → 槽位释放。不释放的话被驳回/受理过一次之后，这个对象再没人能举报
        ->and($fresh->open_slot)->toBeNull();

    expect(DB::table('operation_log')
        ->where('action', 'skill_report.dispose')
        ->where('target_id', $report->id)
        ->exists())->toBeTrue();
});

it('A1 · 驳回举报必须写理由，否则不落库', function () {
    $report = a1Report();

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-reports.dispose', $report->id), [
        'status' => 'rejected',
        // 故意不写 note
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(SkillReport::STATUS_PENDING)
        ->and($report->fresh()->open_slot)->toBe(SkillReport::OPEN_SLOT)
        ->and(session('error'))->toContain('必须写明理由');
});

it('A1 · 写了理由的驳回可以落库', function () {
    $report = a1Report();

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-reports.dispose', $report->id), [
        'status' => 'rejected',
        'note'   => '现场复核：该版本默认限速已生效，举报描述与实物不符。',
    ])->assertRedirect();

    $fresh = $report->fresh();

    expect($fresh->status)->toBe(SkillReport::STATUS_REJECTED)
        ->and($fresh->handling_note)->toContain('默认限速')
        ->and($fresh->open_slot)->toBeNull();
});

it('A1 · 处置值域受限：不能退回待处置，也不能自造状态', function () {
    $report = a1Report();

    $this->loginAsAdmin();

    // 退回 pending 会让已结案的记录重新占用去重槽位，和历史结论打架
    post(route('admin.catalog.skill-reports.dispose', $report->id), ['status' => 'pending'])
        ->assertSessionHasErrors('status');

    post(route('admin.catalog.skill-reports.dispose', $report->id), ['status' => 'ignored'])
        ->assertSessionHasErrors('status');

    expect($report->fresh()->status)->toBe(SkillReport::STATUS_PENDING)
        ->and($report->fresh()->open_slot)->toBe(SkillReport::OPEN_SLOT);
});

it('A1 · 结案后同一用户可再次举报同一对象（槽位已释放）', function () {
    $report = a1Report();

    $this->loginAsAdmin();

    post(route('admin.catalog.skill-reports.dispose', $report->id), [
        'status' => 'rejected',
        'note'   => '证据不足，先不受理。',
    ])->assertRedirect();

    // 对象没改好就该能再报 —— 库级唯一键只约束"未处置"那一条
    $second = SkillReport::create([
        'reporter_customer_id' => $report->reporter_customer_id,
        'target_type'          => $report->target_type,
        'target_id'            => $report->target_id,
        'reason_category'      => SkillReport::REASON_ILLEGAL,
        'status'               => SkillReport::STATUS_PENDING,
        'open_slot'            => SkillReport::OPEN_SLOT,
    ]);

    expect($second->id)->toBeGreaterThan($report->id)
        ->and(SkillReport::where('target_id', $report->target_id)->count())->toBe(2);
});
