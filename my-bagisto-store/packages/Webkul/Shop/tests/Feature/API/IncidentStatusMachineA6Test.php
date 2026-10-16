<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Services\Incident\IllegalIncidentTransitionException;
use Webkul\Shop\Services\Incident\IncidentService;

/**
 * A6 · 事故状态机与 resolved_at 清空。
 *
 * 改前:`advance()` 只校验"目标状态在枚举里",不校验方向 → `closed → reported` 也能落库,
 * 且状态回退时 `resolved_at` 不清 → SLA 口径失真。
 *
 * 改后:按 `Incident::STATUS_TRANSITIONS` 校验合法流转;回退到未结状态时清 `resolved_at`。
 */

function a6Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'State',
        'last_name'   => 'Machine',
        'email'       => 'a6'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function a6Incident(string $status = Incident::STATUS_REPORTED): Incident
{
    return Incident::create([
        'incident_no'    => testUniqueNo('I'),
        'customer_id'    => a6Customer()->id,
        'severity'       => Incident::SEVERITY_MODERATE,
        'occurred_at'    => now()->subDay(),
        'description'    => 'x',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => $status,
    ]);
}

beforeEach(function () {
    $this->admin = Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);
    $this->actingAs($this->admin, 'admin');
});

it('A6 · 判定:主线向前合法,终态→reported 非法', function () {
    $reported = a6Incident(Incident::STATUS_REPORTED);

    expect($reported->canTransitionTo(Incident::STATUS_INVESTIGATING))->toBeTrue()
        ->and($reported->canTransitionTo(Incident::STATUS_RESOLVED))->toBeTrue()
        // 同状态幂等
        ->and($reported->canTransitionTo(Incident::STATUS_REPORTED))->toBeTrue();

    $closed = a6Incident(Incident::STATUS_CLOSED);

    // 已结案不能被打回"待受理"(那等于把已投入的处理记录归零)
    expect($closed->canTransitionTo(Incident::STATUS_REPORTED))->toBeFalse()
        // 但允许重开为"调查中"
        ->and($closed->canTransitionTo(Incident::STATUS_INVESTIGATING))->toBeTrue();

    $resolved = a6Incident(Incident::STATUS_RESOLVED);

    expect($resolved->canTransitionTo(Incident::STATUS_CLOSED))->toBeTrue()
        ->and($resolved->canTransitionTo(Incident::STATUS_INVESTIGATING))->toBeTrue()
        ->and($resolved->canTransitionTo(Incident::STATUS_ATTRIBUTED))->toBeFalse();
});

it('A6 · 服务层:非法流转抛异常且不落任何处置记录', function () {
    $incident = a6Incident(Incident::STATUS_CLOSED);

    expect(fn () => app(IncidentService::class)
        ->advance($incident, Incident::STATUS_REPORTED, null, 'x'))
        ->toThrow(IllegalIncidentTransitionException::class);

    // 状态没动、也没多出一条处置记录(事务回滚干净)
    expect(Incident::find($incident->id)->status)->toBe(Incident::STATUS_CLOSED)
        ->and(DB::table('incident_handling_log')->where('incident_id', $incident->id)->count())->toBe(0);
});

it('A6 · 状态回退(resolved → investigating)必须清空 resolved_at', function () {
    $incident = a6Incident(Incident::STATUS_INVESTIGATING);
    $service = app(IncidentService::class);

    // 先结案,落 resolved_at
    $service->advance($incident, Incident::STATUS_RESOLVED, null, '一度已解决');
    expect(Incident::find($incident->id)->resolved_at)->not->toBeNull();

    // 再重新打开为"调查中" —— resolved_at 必须清掉,否则 SLA 口径里它还挂着结案时间
    $service->advance($incident, Incident::STATUS_INVESTIGATING, null, '当事人要求重开');

    $fresh = Incident::find($incident->id);

    expect($fresh->status)->toBe(Incident::STATUS_INVESTIGATING)
        ->and($fresh->resolved_at)->toBeNull()
        ->and($fresh->isOpen())->toBeTrue();
});

it('A6 · resolved → closed 不刷新原结案时间(只保留首次进入终态的时刻)', function () {
    $incident = a6Incident(Incident::STATUS_INVESTIGATING);
    $service = app(IncidentService::class);

    $service->advance($incident, Incident::STATUS_RESOLVED, null, '已解决');
    $firstResolvedAt = Incident::find($incident->id)->resolved_at;

    // 时间往前走一分钟,再结案
    test()->travel(60);

    $service->advance($incident, Incident::STATUS_CLOSED, null, '结案归档');

    $fresh = Incident::find($incident->id);

    expect($fresh->status)->toBe(Incident::STATUS_CLOSED)
        // 结案时刻没有被"再点一次"刷成新时间 —— SLA 口径稳定
        ->and($fresh->resolved_at->equalTo($firstResolvedAt))->toBeTrue();
});
