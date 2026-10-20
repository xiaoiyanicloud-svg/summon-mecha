<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * G2-A · Admin 报名范围/人员范围的路由授权与活动范围。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g2AdminAclAdmin(string $type, array $permissions = []): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function g2AdminAclEvent(): int
{
    $now = '2026-10-19 04:00:00';

    $eventId = (int) DB::table('events')->insertGetId([
        'name'               => 'G2 后台测试活动',
        'type'               => '旧类型',
        'status'             => 'registering',
        'kind'               => 'salon',
        'participation_mode' => 'offline',
        'publish_status'     => 'published',
        'disposition'        => 'normal',
        'hosting_status'     => 'scheduled',
        'timezone'           => 'Asia/Shanghai',
        'source_type'        => 'platform',
        'platform_role'      => 'organizer',
        'row_version'        => 1,
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);

    DB::table('event_registration_scopes')->insert([
        'event_id'               => $eventId,
        'scope_key'              => 'default',
        'unit'                   => 'person',
        'capacity'               => 10,
        'capacity_confirmed_at'  => $now,
        'confirmed_count'        => 0,
        'review_mode'            => 'auto',
        'registration_opens_at'  => '2026-10-18 04:00:00',
        'registration_closes_at' => '2026-10-25 04:00:00',
        'review_closes_at'       => null,
        'cancel_policy'          => 'before_close',
        'reapply_policy'         => 'allowed',
        'max_attempts'           => 3,
        'change_ack_required'    => 0,
        'decline_releases_seat'  => 0,
        'no_response_action'     => 'none',
        'form_version'           => 1,
        'form_schema'            => json_encode(['fields' => ['name', 'phone'], 'required' => ['name', 'phone']]),
        'privacy_version'        => '2026-10-19',
        'privacy_summary'        => '摘要',
        'rule_version_id'        => null,
        'status'                 => 'draft',
        'policy_confirmed_at'    => null,
        'policy_confirmed_by'    => null,
        'row_version'            => 0,
        'created_at'             => $now,
        'updated_at'             => $now,
    ]);

    return $eventId;
}

it('G2-A · 有 settings 全局权限但无活动范围时 403；授予后可打开', function () {
    $eventId = g2AdminAclEvent();
    $super = g2AdminAclAdmin('all');
    $operator = g2AdminAclAdmin('custom', ['ecosystem.registrations.settings']);

    $this->actingAs($operator, 'admin');
    get(route('admin.catalog.ecosystem.registrations.scope', $eventId))->assertForbidden();

    DB::table('event_staff_assignments')->insert([
        'event_id'     => $eventId,
        'admin_id'     => $operator->id,
        'capabilities' => json_encode(['settings']),
        'valid_from'   => null,
        'valid_until'  => null,
        'granted_by'   => $super->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    get(route('admin.catalog.ecosystem.registrations.scope', $eventId))->assertOk();
});

it('G2-A · 非超管不能授予人员范围；超管可以', function () {
    $eventId = g2AdminAclEvent();
    $super = g2AdminAclAdmin('all');
    $operator = g2AdminAclAdmin('custom', [
        'ecosystem.registrations.settings',
        'ecosystem.registrations.staff',
    ]);

    // 给 operator 一个 settings 范围，让它能打开 staff 页面，但仍不能授予。
    DB::table('event_staff_assignments')->insert([
        'event_id'     => $eventId,
        'admin_id'     => $operator->id,
        'capabilities' => json_encode(['settings']),
        'granted_by'   => $super->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $this->actingAs($operator, 'admin');

    get(route('admin.catalog.ecosystem.registrations.staff', $eventId))->assertOk();

    post(route('admin.catalog.ecosystem.registrations.staff.grant', $eventId), [
        'admin_id'     => $operator->id,
        'capabilities' => ['pii'],
    ])->assertForbidden();

    $this->actingAs($super, 'admin');

    post(route('admin.catalog.ecosystem.registrations.staff.grant', $eventId), [
        'admin_id'     => $operator->id,
        'capabilities' => ['review', 'pii'],
    ])->assertRedirect(route('admin.catalog.ecosystem.registrations.staff', $eventId));

    $assignment = DB::table('event_staff_assignments')
        ->where('event_id', $eventId)
        ->where('admin_id', $operator->id)
        ->first();

    expect(json_decode($assignment->capabilities, true))->toBe(['review', 'pii']);
});
