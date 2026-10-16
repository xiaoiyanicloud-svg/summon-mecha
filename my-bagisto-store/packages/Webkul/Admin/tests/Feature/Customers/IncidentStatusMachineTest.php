<?php

use Webkul\Shop\Models\Incident;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * A6 · 事故受理台:非法状态流转被拒(与 Shop 侧的状态机测试配套)。
 *
 * 改前:`handle()` 只校验"目标状态在枚举里",不校验方向 —— 后台下拉里选一个
 * 已结案的案子 → "待受理",状态就被打回去了,`resolved_at` 还挂着。
 */

function a6AdminIncident(string $status = Incident::STATUS_REPORTED): Incident
{
    return Incident::create([
        'incident_no'    => testUniqueNo('I'),
        'severity'       => Incident::SEVERITY_MODERATE,
        'occurred_at'    => now()->subDay(),
        'description'    => 'x',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => $status,
        'resolved_at'    => $status === Incident::STATUS_CLOSED ? now() : null,
    ]);
}

it('A6 · 受理台:closed → reported 被拒,状态与 resolved_at 都不变', function () {
    $incident = a6AdminIncident(Incident::STATUS_CLOSED);

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_REPORTED,
        'note'   => '试图把已结案的案子打回待受理',
    ])->assertRedirect();

    $fresh = $incident->fresh();

    expect($fresh->status)->toBe(Incident::STATUS_CLOSED)
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and(session('error'))->toContain('不能从')
        // 没有落任何处置记录(非法流转不该留下痕迹)
        ->and($fresh->handlingEntries)->toHaveCount(0);
});

it('A6 · 受理台:closed → investigating(重开)允许,并清空 resolved_at', function () {
    $incident = a6AdminIncident(Incident::STATUS_CLOSED);

    $this->loginAsAdmin();

    post(route('admin.customers.incidents.handle', $incident->id), [
        'status' => Incident::STATUS_INVESTIGATING,
        'note'   => '当事人提供了新证据,重新调查',
    ])->assertRedirect();

    $fresh = $incident->fresh();

    expect($fresh->status)->toBe(Incident::STATUS_INVESTIGATING)
        ->and($fresh->resolved_at)->toBeNull()
        ->and($fresh->isOpen())->toBeTrue();
});

it('A6 · 受理台:下拉只给出当前状态与合法流转目标', function () {
    $incident = a6AdminIncident(Incident::STATUS_CLOSED);

    $this->loginAsAdmin();

    $html = get(route('admin.customers.incidents.show', $incident->id))->getContent();

    // 已结案的案子:应能看到"已结案"(当前)与"调查中"(唯一合法流转),看不到"待受理"
    expect($html)->toContain('value="'.Incident::STATUS_CLOSED.'"')
        ->and($html)->toContain('value="'.Incident::STATUS_INVESTIGATING.'"')
        ->and($html)->not->toContain('value="'.Incident::STATUS_REPORTED.'"');
});
