<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationPolicy;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;

/**
 * G2-A · 报名范围配置、激活与容量不变式。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g2ScopePayload(array $overrides = []): array
{
    return array_merge([
        'capacity'               => 10,
        'confirm_capacity'       => true,
        'review_mode'            => 'auto',
        'registration_opens_at'  => now()->subHour()->format('Y-m-d H:i:s'),
        'registration_closes_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'review_closes_at'       => null,
        'cancel_policy'          => 'before_close',
        'reapply_policy'         => 'allowed',
        'max_attempts'           => 3,
        'change_ack_required'    => false,
        'decline_releases_seat'  => false,
        'no_response_action'     => 'none',
        'privacy_version'        => '2026-10-19',
        'privacy_summary'        => '同意摘要',
        'rule_version_id'        => null,
    ], $overrides);
}

function g2AdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G2 管理员',
        'email'      => 'g2-'.bin2hex(random_bytes(4)).'@example.test',
        'password'   => bcrypt('secret123'),
        'role_id'    => DB::table('roles')->insertGetId([
            'name'            => 'G2 Super '.bin2hex(random_bytes(3)),
            'permission_type' => 'all',
            'permissions'     => json_encode([]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]),
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('G2-A · scope 计量单位由活动类型决定，类型未核定拒绝创建', function () {
    $service = app(RegistrationScopeService::class);
    $adminId = g2AdminId();

    $salon = Fixtures::event(['kind' => 'salon']);
    $scope = $service->createDefault(Event::find($salon['id']), g2ScopePayload(), $adminId);
    expect($scope->unit)->toBe('person');
    expect($scope->status)->toBe('draft');

    $competition = Fixtures::event(['kind' => 'competition']);
    $scope2 = $service->createDefault(Event::find($competition['id']), g2ScopePayload(), $adminId);
    expect($scope2->unit)->toBe('team');

    $unknown = Fixtures::event(['kind' => null]);
    expect(fn () => $service->createDefault(Event::find($unknown['id']), g2ScopePayload(), $adminId))
        ->toThrow(RegistrationException::class);
});

it('G2-A · 容量未确认 / 手动审核缺截止 / 规则未绑定都不能激活', function () {
    $service = app(RegistrationScopeService::class);
    $adminId = g2AdminId();

    $event = Fixtures::event(['kind' => 'salon']);

    $noCapacity = $service->createDefault(
        Event::find($event['id']),
        g2ScopePayload(['confirm_capacity' => false]),
        $adminId,
    );

    expect(fn () => $service->activate($noCapacity, (int) $noCapacity->row_version, $adminId))
        ->toThrow(RegistrationException::class);

    $event2 = Fixtures::event(['kind' => 'salon']);
    $manual = $service->createDefault(
        Event::find($event2['id']),
        g2ScopePayload(['review_mode' => 'manual', 'review_closes_at' => null]),
        $adminId,
    );

    expect(fn () => $service->activate($manual, (int) $manual->row_version, $adminId))
        ->toThrow(RegistrationException::class);

    // 直接写一个“审核截止早于报名截止”的 scope，激活前置仍必须拒绝。
    $event3 = Fixtures::event(['kind' => 'salon']);
    $badId = Fixtures::scope($event3['id'], [
        'review_mode'      => 'manual',
        'review_closes_at'=> now('UTC')->addHours(2)->format('Y-m-d H:i:s'),
        'registration_closes_at' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
    ]);

    expect(fn () => $service->activate(EventRegistrationScope::find($badId), 0, $adminId))
        ->toThrow(RegistrationException::class);
});

it('G2-A · 激活成功后 policy_confirmed_at 写入，capacity=0 不是不限', function () {
    $service = app(RegistrationScopeService::class);
    $policy = app(RegistrationPolicy::class);
    $adminId = g2AdminId();

    $event = Fixtures::event(['kind' => 'salon']);
    $scope = $service->createDefault(Event::find($event['id']), g2ScopePayload(['capacity' => 0]), $adminId);

    $activated = $service->activate($scope, (int) $scope->row_version, $adminId);

    expect($activated->status)->toBe('open');
    expect($activated->policy_confirmed_at)->not->toBeNull();
    expect($policy->capacityAllowsSubmission($activated))->toBeFalse();
});

it('G2-A · 容量不得低于已确认席位；表单/隐私变化递增版本', function () {
    $service = app(RegistrationScopeService::class);
    $adminId = g2AdminId();

    $event = Fixtures::event(['kind' => 'salon']);
    $scope = $service->createDefault(Event::find($event['id']), g2ScopePayload(['capacity' => 10]), $adminId);

    DB::table('event_registration_scopes')->where('id', $scope->id)->update(['confirmed_count' => 4]);
    $scope->refresh();

    expect(fn () => $service->adjustCapacity($scope, (int) $scope->row_version, 3, true, $adminId, '降容'))
        ->toThrow(RegistrationException::class);

    $updated = $service->adjustCapacity($scope, (int) $scope->row_version, 4, true, $adminId, '降到已占数');

    expect((int) $updated->capacity)->toBe(4);

    $beforeVersion = (int) $updated->form_version;
    $changed = $service->update($updated, (int) $updated->row_version, [
        'privacy_version' => '2026-10-20',
    ], $adminId);

    expect((int) $changed->form_version)->toBe($beforeVersion + 1);
});

it('G2-A · 对账从 attempts 重算 confirmed_count', function () {
    $service = app(RegistrationScopeService::class);
    $adminId = g2AdminId();

    $event = Fixtures::event(['kind' => 'salon']);
    $scope = $service->createDefault(Event::find($event['id']), g2ScopePayload(['capacity' => 10]), $adminId);

    DB::table('event_registration_scopes')->where('id', $scope->id)->update(['confirmed_count' => 5]);
    $scope->refresh();

    $dry = $service->reconcileConfirmedCount($scope, false);
    expect($dry['stored'])->toBe(5);
    expect($dry['computed'])->toBe(0);
    expect($dry['changed'])->toBeTrue();

    $applied = $service->reconcileConfirmedCount($scope, true);
    expect($applied['computed'])->toBe(0);
    expect((int) EventRegistrationScope::find($scope->id)->confirmed_count)->toBe(0);
});
