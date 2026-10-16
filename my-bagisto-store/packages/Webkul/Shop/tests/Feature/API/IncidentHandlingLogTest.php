<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Services\Incident\IncidentService;

/**
 * A5 · 事故处理记录读-改-写(处置留痕会丢)。
 *
 * 改前:`advance()` 读出 `incident.handling_log` 整列 → append → 整列写回。
 * 两名管理员并发处置 → 后写覆盖先写,一条处置记录凭空消失(lost update)。
 *
 * 改后:处置记录落 `incident_handling_log`(append-only,一行一条),状态推进走行锁事务。
 */

function a5Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Inc',
        'last_name'   => 'Log',
        'email'       => 'a5'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function a5Incident(Customer $customer): Incident
{
    return Incident::create([
        'incident_no'    => testUniqueNo('I'),
        'customer_id'    => $customer->id,
        'severity'       => Incident::SEVERITY_SEVERE,
        'occurred_at'    => now(),
        'description'    => '手臂打到人',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
    ]);
}

it('A5 · 每次处置各落一行,不整列重写(后一次处置不覆盖前一次)', function () {
    $admin = Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);
    $this->actingAs($admin, 'admin');

    $incident = a5Incident(a5Customer());
    $service = app(IncidentService::class);

    $service->advance($incident, Incident::STATUS_INVESTIGATING, null, '已受理,联系报案人');
    $service->advance($incident, Incident::STATUS_ATTRIBUTED, Incident::RESPONSIBILITY_DEVELOPER, '急停未生效,判开发者');

    $rows = DB::table('incident_handling_log')
        ->where('incident_id', $incident->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        // 两条都在 —— 并发下也不会丢,因为物理上是两行
        ->and($rows[0]->note)->toBe('已受理,联系报案人')
        ->and($rows[1]->note)->toBe('急停未生效,判开发者')
        ->and($rows[0]->admin_id)->toBe($admin->id)
        ->and($rows[1]->responsibility)->toBe(Incident::RESPONSIBILITY_DEVELOPER);

    // 状态推进生效
    expect(Incident::find($incident->id)->status)->toBe(Incident::STATUS_ATTRIBUTED)
        ->and(Incident::find($incident->id)->responsibility)->toBe(Incident::RESPONSIBILITY_DEVELOPER);
});

it('A5 · 处置记录不再写 incident.handling_log 那一列(单一事实源)', function () {
    $admin = Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);
    $this->actingAs($admin, 'admin');

    $incident = a5Incident(a5Customer());

    app(IncidentService::class)->advance($incident, Incident::STATUS_INVESTIGATING, null, '受理');

    expect(Incident::find($incident->id)->handling_log)->toBeNull()
        ->and(DB::table('incident_handling_log')->where('incident_id', $incident->id)->count())->toBe(1);
});

it('A5 · 关系按时间顺序返回处置记录(后台展示用)', function () {
    $admin = Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);
    $this->actingAs($admin, 'admin');

    $incident = a5Incident(a5Customer());
    $service = app(IncidentService::class);

    $service->advance($incident, Incident::STATUS_INVESTIGATING, null, '第一条');
    $service->advance($incident, Incident::STATUS_RESOLVED, Incident::RESPONSIBILITY_USER, '第二条');

    $entries = Incident::find($incident->id)->handlingEntries;

    expect($entries)->toHaveCount(2)
        ->and($entries->first()->note)->toBe('第一条')
        ->and($entries->last()->note)->toBe('第二条');

    // 解决时间也一并写入(resolved)
    expect(Incident::find($incident->id)->resolved_at)->not->toBeNull();
});
