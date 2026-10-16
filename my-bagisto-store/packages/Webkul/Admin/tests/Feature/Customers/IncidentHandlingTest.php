<?php

use Webkul\Shop\Models\Incident;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 事故受理台（平台侧，计划 §4.19）。
 *
 * 锁两条硬规则：
 *   ① 定责必须写说明 —— 责任划分影响赔偿，没有书面依据的"已定责"等于没定
 *   ② 结案必须写结论
 * 以及一条结构性约束：`trace` 只读展示，**没有编辑入口**（能改的证据链不是证据链）。
 */

function ihIncident(array $overrides = []): Incident
{
    return Incident::create(array_merge([
        'incident_no'    => testUniqueNo('I'),
        'severity'       => Incident::SEVERITY_MODERATE,
        'occurred_at'    => now()->subDay(),
        'description'    => '机器人在展厅突然加速，撞坏了展台',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
        'trace'          => [
            'captured_at' => now()->toDateTimeString(),
            'resolved'    => [],
            'skill_version' => ['id' => 1, 'version' => '1.0.0', 'status' => 'published'],
            'deliverable' => ['file_name' => 'demo.skill', 'file_hash' => 'abc123'],
            'device'      => ['model_name' => '测试机型', 'firmware' => '1.2.3'],
            'entitlement' => null,
            'install'     => null,
            'missing'     => ['没有安装记录 —— 可能是人工通道自行安装（平台不掌握安装事实）'],
        ],
    ], $overrides));
}

it('事故列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.customers.incidents.index'))
        ->assertOk()
        ->assertSeeText('事故受理');
});

it('事故详情页可以渲染，并展示证据链快照', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    get(route('admin.customers.incidents.show', $incident->id))
        ->assertOk()
        ->assertSeeText($incident->incident_no)
        ->assertSeeText('证据链快照')
        // sha256 是定责时最硬的一条证据，必须显示出来
        ->assertSeeText('abc123')
        // 查不到的环节要显式说明，不能让人误以为"没问题"
        ->assertSeeText('没有查到');
});

it('详情页不提供 trace 的编辑入口（证据链只读）', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    $html = get(route('admin.customers.incidents.show', $incident->id))->getContent();

    // 页面里只应有一个表单（处置表单），不应存在修改 trace 的表单
    expect($html)->toContain('保存处理进展')
        ->and(substr_count($html, '<form'))->toBe(1);
});

it('定责：改了责任方却不写说明 → 被拒，状态不变', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status'         => Incident::STATUS_ATTRIBUTED,
        'responsibility' => Incident::RESPONSIBILITY_DEVELOPER,
        // 故意不写 note
    ])->assertRedirect();

    expect($incident->fresh()->responsibility)->toBe(Incident::RESPONSIBILITY_UNKNOWN)
        ->and($incident->fresh()->status)->toBe(Incident::STATUS_REPORTED)
        ->and(session('error'))->toContain('必须写明依据');
});

it('定责：写了说明 → 落库，并追加处理日志', function () {
    $incident = ihIncident();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status'         => Incident::STATUS_ATTRIBUTED,
        'responsibility' => Incident::RESPONSIBILITY_DEVELOPER,
        'note'           => '复核技能包与安装记录：技能未声明急停保护，判定开发者责任',
    ])->assertRedirect();

    $fresh = $incident->fresh();
    $entries = $fresh->handlingEntries;

    expect($fresh->responsibility)->toBe(Incident::RESPONSIBILITY_DEVELOPER)
        ->and($fresh->status)->toBe(Incident::STATUS_ATTRIBUTED)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->admin_id)->toBe($admin->id)
        ->and($entries[0]->note)->toContain('急停保护');
});

it('结案：不写结论 → 被拒', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_CLOSED,
    ])->assertRedirect();

    expect($incident->fresh()->status)->toBe(Incident::STATUS_REPORTED)
        ->and($incident->fresh()->resolved_at)->toBeNull();
});

it('结案：写结论 → 状态与 resolved_at 一并落', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_RESOLVED,
        'note'   => '已与当事人达成一致，全额赔付展台损失',
    ])->assertRedirect();

    expect($incident->fresh()->status)->toBe(Incident::STATUS_RESOLVED)
        ->and($incident->fresh()->resolved_at)->not->toBeNull()
        ->and($incident->fresh()->isOpen())->toBeFalse();
});

it('处理日志是追加而不是覆盖（历史不能被抹掉）', function () {
    $incident = ihIncident();

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_INVESTIGATING,
        'note'   => '第一步：调取设备日志',
    ]);

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_INVESTIGATING,
        'note'   => '第二步：联系厂商确认固件',
    ]);

    $entries = $incident->fresh()->handlingEntries;

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->note)->toContain('第一步')
        ->and($entries[1]->note)->toContain('第二步');
});

it('处理动作留痕到 operation_log', function () {
    $incident = ihIncident();

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_INVESTIGATING,
        'note'   => '开始调查',
    ]);

    expect(DB::table('operation_log')
        ->where('action', 'incident.handle')
        ->where('target_id', $incident->id)
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();
});

it('严重程度：只有严重/重大才触发应急标记', function () {
    expect(ihIncident(['severity' => Incident::SEVERITY_MINOR])->requiresEscalation())->toBeFalse()
        ->and(ihIncident(['severity' => Incident::SEVERITY_MODERATE])->requiresEscalation())->toBeFalse()
        ->and(ihIncident(['severity' => Incident::SEVERITY_SEVERE])->requiresEscalation())->toBeTrue()
        ->and(ihIncident(['severity' => Incident::SEVERITY_CRITICAL])->requiresEscalation())->toBeTrue();
});

it('严重事故在详情页显示应急标记', function () {
    $incident = ihIncident(['severity' => Incident::SEVERITY_CRITICAL]);

    $this->loginAsAdmin();

    get(route('admin.customers.incidents.show', $incident->id))
        ->assertOk()
        ->assertSeeText('需应急响应');
});
