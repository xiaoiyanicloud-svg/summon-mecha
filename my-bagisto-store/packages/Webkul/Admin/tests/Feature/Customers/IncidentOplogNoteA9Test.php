<?php

use Webkul\Shop\Models\Incident;

use function Pest\Laravel\post;

/**
 * A9 · 事故受理台:oplog payload 并入 note 摘要。
 *
 * 改前 operation_log 只记 status/responsibility/severity —— 唯独没有 note,
 * 而 note 正是这次处置"为什么这么判"的唯一依据。缺了它,审计只能回答
 * "谁把状态改成了什么",回答不了"凭什么"。
 */

it('A9 · 处置留痕把说明摘要并进 payload', function () {
    $incident = Incident::create([
        'incident_no'    => 'I'.now()->format('ymdHis').'-'.uniqid(),
        'severity'       => Incident::SEVERITY_MODERATE,
        'occurred_at'    => now()->subDay(),
        'description'    => 'x',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
    ]);

    $admin = $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status'         => Incident::STATUS_ATTRIBUTED,
        'responsibility' => Incident::RESPONSIBILITY_DEVELOPER,
        'note'           => '复核技能包未声明急停保护,判定开发者责任',
    ])->assertRedirect();

    $payload = json_decode(DB::table('operation_log')
        ->where('action', 'incident.handle')
        ->where('target_id', $incident->id)
        ->where('actor_id', $admin->id)
        ->value('payload'), true);

    expect($payload['status'])->toBe(Incident::STATUS_ATTRIBUTED)
        ->and($payload['responsibility'])->toBe(Incident::RESPONSIBILITY_DEVELOPER)
        ->and($payload['note'])->toContain('急停保护');
});

it('A9 · 无说明时 payload.note 为 null(不伪造一条摘要)', function () {
    $incident = Incident::create([
        'incident_no'    => 'I'.now()->format('ymdHis').'-'.uniqid(),
        'severity'       => Incident::SEVERITY_MODERATE,
        'occurred_at'    => now()->subDay(),
        'description'    => 'x',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
    ]);

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_INVESTIGATING,
    ])->assertRedirect();

    $payload = json_decode(DB::table('operation_log')
        ->where('action', 'incident.handle')
        ->where('target_id', $incident->id)
        ->value('payload'), true);

    expect($payload['note'])->toBeNull();
});
