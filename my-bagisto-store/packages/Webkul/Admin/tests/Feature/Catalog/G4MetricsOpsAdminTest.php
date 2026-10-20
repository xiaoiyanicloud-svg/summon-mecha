<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * G4-06 / G4-07 · Admin 指标页与运营工作台。
 */
beforeEach(function () {
    expect(app()->environment())->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toMatch('/(?:_test|_testing)$/')
        ->not->toBe('root');

    Storage::fake('local');

    config([
        'ecosystem_g4.metrics.enabled'               => true,
        'ecosystem_g4.metrics.content_views_enabled' => true,
        'ecosystem_g4.ops.enabled'                   => true,
    ]);
});

function g4Admin(array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function g4SuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

/**
 * @return array{event: array{id: int, revision_id: int}, attempt_id: int}
 */
function g4OpsFixtures(): array
{
    $event = Fixtures::event();
    $vault = app(RegistrationContactVault::class);
    $contact = $vault->buildContact('张三', '13800138000', '上海');

    $scopeId = (int) DB::table('event_registration_scopes')->insertGetId([
        'event_id'              => $event['id'],
        'scope_key'             => 'default',
        'unit'                  => 'person',
        'capacity'              => 10,
        'capacity_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'confirmed_count'       => 0,
        'review_mode'           => 'manual',
        'registration_closes_at'=> now('UTC')->addHours(12)->format('Y-m-d H:i:s'),
        'status'                => 'open',
        'policy_confirmed_at'   => now('UTC')->format('Y-m-d H:i:s'),
        'row_version'           => 0,
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);

    $registrationId = (int) DB::table('event_registrations')->insertGetId([
        'event_id'    => $event['id'],
        'scope_id'    => $scopeId,
        'subject_key' => 'c:'.random_int(1000, 999999),
        'public_no'   => 'G4-OPS-'.bin2hex(random_bytes(5)),
        'identity_kind' => 'customer',
        'row_version' => 0,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $attemptId = (int) DB::table('event_registration_attempts')->insertGetId([
        'registration_id'     => $registrationId,
        'event_id'            => $event['id'],
        'scope_id'            => $scopeId,
        'owner_customer_id'   => 1,
        'attempt_no'          => 1,
        'status'              => 'submitted',
        'review_mode'         => 'manual',
        'form_version'        => 1,
        'privacy_version'     => 'p1',
        'privacy_fingerprint' => str_repeat('a', 64),
        'consent_accepted_at' => now('UTC')->format('Y-m-d H:i:s'),
        'contact_name_enc'    => $contact['contact_name_enc'],
        'contact_phone_enc'   => $contact['contact_phone_enc'],
        'contact_phone_hash'  => $contact['contact_phone_hash'],
        'contact_phone_last4' => $contact['contact_phone_last4'],
        'contact_city'        => '上海',
        'request_key'         => 'g4-'.bin2hex(random_bytes(6)),
        'submitted_at'        => now('UTC')->format('Y-m-d H:i:s'),
        'row_version'         => 0,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('event_registrations')->where('id', $registrationId)->update([
        'current_attempt_id' => $attemptId,
    ]);

    return ['event' => $event, 'attempt_id' => $attemptId];
}

it('G4-06 · 指标页按权限开放，关闭时明确提示未启用', function () {
    $this->actingAs(g4Admin(['ecosystem.metrics.view']), 'admin');
    get('/admin/catalog/ecosystem-metrics')
        ->assertOk()
        ->assertSee('运营指标')
        ->assertSee('报名提交数');

    config(['ecosystem_g4.metrics.enabled' => false]);
    get('/admin/catalog/ecosystem-metrics')
        ->assertOk()
        ->assertSee('指标能力未启用');

    $this->actingAs(g4Admin(['catalog']), 'admin');
    get('/admin/catalog/ecosystem-metrics')->assertForbidden();
});

it('G4-07 · 运营工作台只对 ops.view 开放，超管可见全部事实', function () {
    g4OpsFixtures();

    $this->actingAs(g4SuperAdmin(), 'admin');
    get('/admin/catalog/ecosystem-ops')
        ->assertOk()
        ->assertSee('运营工作台')
        ->assertSee('待审报名')
        ->assertSee('即将截止');

    $this->actingAs(g4Admin(['catalog']), 'admin');
    get('/admin/catalog/ecosystem-ops')->assertForbidden();
});

it('G4-07 · 无 pii 能力显示遮罩，有 pii 能力且具备活动范围才显示明文', function () {
    $ctx = g4OpsFixtures();

    $maskedAdmin = g4Admin(['ecosystem.ops.view']);
    DB::table('event_staff_assignments')->insert([
        'event_id'     => $ctx['event']['id'],
        'admin_id'     => $maskedAdmin->id,
        'capabilities' => json_encode(['review']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $this->actingAs($maskedAdmin, 'admin');
    $masked = get('/admin/catalog/ecosystem-ops')->assertOk();
    expect((string) $masked->getContent())
        ->toContain('张*')
        ->toContain('138****8000')
        ->not->toContain('张三');

    $piiAdmin = g4Admin(['ecosystem.ops.view', 'ecosystem.registrations.pii']);
    DB::table('event_staff_assignments')->insert([
        'event_id'     => $ctx['event']['id'],
        'admin_id'     => $piiAdmin->id,
        'capabilities' => json_encode(['review', 'pii']),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $this->actingAs($piiAdmin, 'admin');
    $plain = get('/admin/catalog/ecosystem-ops')->assertOk();
    expect((string) $plain->getContent())
        ->toContain('张三')
        ->toContain('13800138000')
        ->toContain('含 PII（已授权）');
});

it('G4-07 · 无活动范围的运营角色看不到该活动报名', function () {
    g4OpsFixtures();

    $this->actingAs(g4Admin(['ecosystem.ops.view']), 'admin');
    $response = get('/admin/catalog/ecosystem-ops')->assertOk();

    expect((string) $response->getContent())
        ->toContain('没有待审报名')
        ->not->toContain('张**');
});

it('G4-03 · Admin 关联只接受真实目标，可添加与删除', function () {
    $source = Fixtures::event([], ['name' => '关联来源']);
    $target = Fixtures::event([], ['name' => '关联目标']);

    $admin = g4Admin(['ecosystem.events.view', 'ecosystem.events.edit']);
    $this->actingAs($admin, 'admin');

    post("/admin/catalog/ecosystem-relations/events/{$source['id']}", [
        'target_type' => 'event',
        'target_key'  => (string) $target['id'],
        'sort_order'  => 0,
    ])->assertRedirect();

    $relation = DB::table('ecosystem_relations')
        ->where('source_type', 'event')
        ->where('source_key', (string) $source['id'])
        ->first();

    expect($relation)->not->toBeNull();
    expect((string) $relation->target_key)->toBe((string) $target['id']);

    post("/admin/catalog/ecosystem-relations/events/{$source['id']}", [
        'target_type' => 'event',
        'target_key'  => '999999999',
    ])->assertSessionHasErrors('action');

    expect(DB::table('ecosystem_relations')->count())->toBe(1);

    post("/admin/catalog/ecosystem-relations/events/{$source['id']}/{$relation->id}/delete")
        ->assertRedirect();

    expect(DB::table('ecosystem_relations')->count())->toBe(0);

    $this->actingAs(g4Admin(['catalog']), 'admin');
    get("/admin/catalog/ecosystem-relations/events/{$source['id']}")->assertForbidden();
});

it('G4-07 · 通知失败可重排，且只接受未租用的 failed', function () {    $outboxId = (int) DB::table('ecosystem_outbox')->insertGetId([
        'aggregate_type' => 'registration',
        'aggregate_id'   => 1,
        'event_type'     => 'registration.confirmed',
        'payload'        => json_encode(['x' => 1]),
        'dedupe_key'     => 'g4-'.bin2hex(random_bytes(6)),
        'status'         => 'failed',
        'attempts'       => 5,
        'available_at'   => now('UTC')->format('Y-m-d H:i:s'),
        'lease_owner'    => null,
        'last_error'     => 'smtp down',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $admin = g4Admin(['ecosystem.ops.view', 'ecosystem.ops.retry']);
    $this->actingAs($admin, 'admin');

    post("/admin/catalog/ecosystem-ops/outbox/{$outboxId}/retry")
        ->assertRedirect();

    $row = DB::table('ecosystem_outbox')->where('id', $outboxId)->first();
    expect((string) $row->status)->toBe('pending');
    expect((int) $row->attempts)->toBe(0);
    expect($row->last_error)->toBeNull();

    // 已租用的记录不能被抢走。
    DB::table('ecosystem_outbox')->where('id', $outboxId)->update([
        'status'      => 'failed',
        'lease_owner' => 'worker-1',
        'lease_until' => now('UTC')->addMinutes(5)->format('Y-m-d H:i:s'),
    ]);

    post("/admin/catalog/ecosystem-ops/outbox/{$outboxId}/retry")
        ->assertSessionHasErrors('action');

    expect((string) DB::table('ecosystem_outbox')->where('id', $outboxId)->value('status'))->toBe('failed');

    $this->actingAs(g4Admin(['ecosystem.ops.view']), 'admin');
    post("/admin/catalog/ecosystem-ops/outbox/{$outboxId}/retry")->assertForbidden();
});
