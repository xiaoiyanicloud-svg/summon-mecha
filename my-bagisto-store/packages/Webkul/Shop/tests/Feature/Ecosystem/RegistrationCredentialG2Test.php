<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\RegistrationCancellationService;
use Webkul\Shop\Services\Ecosystem\RegistrationCredentialService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationStaffScope;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-C · 入场凭证与签到。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
        'ecosystem_registration.enabled'               => true,
    ]);
});

function g2CredCustomer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-cred-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2CredScope(int $eventId, array $overrides = []): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, array_merge([
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity'            => 5,
    ], $overrides));

    return EventRegistrationScope::findOrFail($id);
}

function g2CredInput(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'     => 'cred-'.bin2hex(random_bytes(8)),
        'form_version'    => (int) $scope->form_version,
        'privacy_version' => (string) $scope->privacy_version,
        'consent'         => true,
        'name'            => '张三',
        'phone'           => '13800000001',
        'city'            => '上海',
    ], $overrides);
}

function g2CredAdmin(string $type = 'all', array $permissions = []): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-C · 凭证只返回一次原始 token，库里只存 SHA-256', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2CredScope($event['id']);
    $customer = g2CredCustomer();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2CredInput($scope));

    $credential = app(RegistrationCredentialService::class)->issueForCustomer(
        (int) $result['registration']->id,
        (int) $customer->id,
    );

    expect($credential['token'])->not->toBe('');
    expect($credential['qr_payload'])->toContain($credential['token']);
    expect($credential['qr_payload'])->not->toContain('13800000001');
    expect($credential['qr_payload'])->not->toContain('张三');

    $row = DB::table('event_checkin_tokens')->where('attempt_id', $result['attempt']->id)->first();

    expect($row->token_hash)->toBe(hash('sha256', $credential['token']));
    expect($row->token_hash)->not->toContain($credential['token']);
});

it('G2-C · 重发吊销旧凭证；旧 token 不能签到', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2CredScope($event['id']);
    $customer = g2CredCustomer();
    $admin = g2CredAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2CredInput($scope));

    $service = app(RegistrationCredentialService::class);
    $first = $service->issueForCustomer((int) $result['registration']->id, (int) $customer->id);
    $second = $service->issueForCustomer((int) $result['registration']->id, (int) $customer->id);

    expect($first['token'])->not->toBe($second['token']);

    expect(fn () => $service->checkIn($first['token'], $admin))
        ->toThrow(RegistrationException::class);

    $attempt = $service->checkIn($second['qr_payload'], $admin);

    expect($attempt->status)->toBe(EventRegistrationAttempt::STATUS_CHECKED_IN);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);

    // 重复扫描幂等。
    $again = $service->checkIn($second['qr_payload'], $admin);
    expect((int) $again->id)->toBe((int) $attempt->id);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);
});

it('G2-C · 取消/重报后旧轮次 token 失效', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2CredScope($event['id']);
    $customer = g2CredCustomer();
    $admin = g2CredAdmin();

    $first = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2CredInput($scope));
    $credential = app(RegistrationCredentialService::class)->issueForCustomer((int) $first['registration']->id, (int) $customer->id);

    app(RegistrationCancellationService::class)->cancelByCustomer(
        (int) $first['registration']->id,
        (int) $customer->id,
        ['request_key' => 'c-'.bin2hex(random_bytes(4)), 'expected_attempt_id' => (int) $first['attempt']->id],
    );

    app(RegistrationSubmissionService::class)->submit(
        Event::find($event['id']),
        $customer,
        g2CredInput($scope, ['expected_attempt_id' => (int) $first['attempt']->id]),
        'reapply',
    );

    expect(fn () => app(RegistrationCredentialService::class)->checkIn($credential['token'], $admin))
        ->toThrow(RegistrationException::class);
});

it('G2-C · 待审/已拒绝/活动取消都不能签发或签到', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2CredScope($event['id'], [
        'review_mode'      => 'manual',
        'review_closes_at' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
    ]);
    $customer = g2CredCustomer();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2CredInput($scope));

    expect($result['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_SUBMITTED);
    expect(fn () => app(RegistrationCredentialService::class)->issueForCustomer(
        (int) $result['registration']->id,
        (int) $customer->id,
    ))->toThrow(RegistrationException::class);
});

it('G2-C · 无 checkin 活动范围时员工不能签到', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2CredScope($event['id']);
    $customer = g2CredCustomer();
    $super = g2CredAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2CredInput($scope));
    $credential = app(RegistrationCredentialService::class)->issueForCustomer((int) $result['registration']->id, (int) $customer->id);

    $operator = g2CredAdmin('custom', ['ecosystem.registrations.checkin']);

    // 有全局权限但无活动范围。
    expect(fn () => app(RegistrationCredentialService::class)->checkIn($credential['token'], $operator))
        ->toThrow(RegistrationException::class);

    app(RegistrationStaffScope::class)->grant(
        $event['id'],
        (int) $operator->id,
        ['review'],
        null,
        null,
        (int) $super->id,
    );

    // 有活动范围但能力不是 checkin。
    expect(fn () => app(RegistrationCredentialService::class)->checkIn($credential['token'], $operator))
        ->toThrow(RegistrationException::class);
});
