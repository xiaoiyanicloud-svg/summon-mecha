<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationStaffScope;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-A / G2-14 · 全局能力 + 活动范围双层授权。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g2StaffAdmin(string $type, array $permissions = []): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-14 · 有全局权限但无活动范围仍拒绝；授予后放行', function () {
    $service = app(RegistrationStaffScope::class);
    $event = Fixtures::event();

    $super = g2StaffAdmin('all');
    $operator = g2StaffAdmin('custom', ['ecosystem.registrations.review']);

    expect(fn () => $service->assertCapability($event['id'], 'review', $operator))
        ->toThrow(RegistrationException::class);

    $assignment = $service->grant(
        $event['id'],
        (int) $operator->id,
        ['review'],
        null,
        null,
        (int) $super->id,
        '审核志愿者',
    );

    expect($assignment->hasCapability('review'))->toBeTrue();
    expect($service->assertCapability($event['id'], 'review', $operator))->not->toBeNull();

    // 能力分离：有 review 不等于有 pii/export。
    expect(fn () => $service->assertCapability($event['id'], 'pii', $operator))
        ->toThrow(RegistrationException::class);
});

it('G2-14 · 只有超管能授予/撤销；非超管不能自增范围', function () {
    $service = app(RegistrationStaffScope::class);
    $event = Fixtures::event();

    $operator = g2StaffAdmin('custom', ['ecosystem.registrations.settings', 'ecosystem.registrations.staff']);

    expect(fn () => $service->grant($event['id'], (int) $operator->id, ['settings'], null, null, (int) $operator->id))
        ->toThrow(RegistrationException::class);

    expect(fn () => $service->revoke($event['id'], (int) $operator->id, (int) $operator->id))
        ->toThrow(RegistrationException::class);

    expect(DB::table('event_staff_assignments')->where('event_id', $event['id'])->count())->toBe(0);
});

it('G2-14 · 过期范围不再生效；超管可越过范围', function () {
    $service = app(RegistrationStaffScope::class);
    $event = Fixtures::event();

    $super = g2StaffAdmin('all');
    $operator = g2StaffAdmin('custom', ['ecosystem.registrations.review']);

    $service->grant(
        $event['id'],
        (int) $operator->id,
        ['review'],
        now()->subDays(2),
        now()->subDay(),
        (int) $super->id,
    );

    expect(fn () => $service->assertCapability($event['id'], 'review', $operator))
        ->toThrow(RegistrationException::class);

    // 超管返回 null，表示越过范围；调用方仍必须写审计。
    expect($service->assertCapability($event['id'], 'pii', $super))->toBeNull();
});

it('G2-14 · 未知能力被拒绝，且不会写入范围', function () {
    $service = app(RegistrationStaffScope::class);
    $event = Fixtures::event();
    $super = g2StaffAdmin('all');
    $operator = g2StaffAdmin('custom', []);

    expect(fn () => $service->grant(
        $event['id'],
        (int) $operator->id,
        ['superuser'],
        null,
        null,
        (int) $super->id,
    ))->toThrow(RegistrationException::class);

    expect(DB::table('event_staff_assignments')->where('event_id', $event['id'])->count())->toBe(0);
});
