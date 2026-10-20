<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventChange;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\PublicRegistrationState;
use Webkul\Shop\Services\Ecosystem\RegistrationCancellationService;
use Webkul\Shop\Services\Ecosystem\RegistrationChangeService;
use Webkul\Shop\Services\Ecosystem\RegistrationCredentialService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-C · 活动变更 / 确认 / 取消影响。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
        'ecosystem_registration.enabled'               => true,
        'ecosystem_registration.lifecycle.enabled'     => true,
    ]);
});

function g2ChangeCustomer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-change-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2ChangeScope(int $eventId, array $overrides = []): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, array_merge([
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity'            => 5,
    ], $overrides));

    return EventRegistrationScope::findOrFail($id);
}

function g2ChangeInput(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'     => 'chg-'.bin2hex(random_bytes(8)),
        'form_version'    => (int) $scope->form_version,
        'privacy_version' => (string) $scope->privacy_version,
        'consent'         => true,
        'name'            => '张三',
        'phone'           => '13800000001',
        'city'            => '上海',
    ], $overrides);
}

function g2ChangeAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'all', 'permissions' => []]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-C · 生命周期写能力关闭时取消活动被拒绝', function () {
    config(['ecosystem_registration.lifecycle.enabled' => false]);

    $event = Fixtures::event(['kind' => 'salon']);
    $admin = g2ChangeAdmin();

    expect(fn () => app(RegistrationChangeService::class)
        ->cancelEvent(Event::find($event['id']), $admin, '测试取消'))
        ->toThrow(RegistrationException::class);

    expect((string) Event::find($event['id'])->hosting_status)->toBe('scheduled');
});

it('G2-C · 取消活动立即停写、取消有效轮次、释放席位并吊销凭证', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ChangeScope($event['id']);
    $customer = g2ChangeCustomer();
    $admin = g2ChangeAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2ChangeInput($scope));
    $credential = app(RegistrationCredentialService::class)->issueForCustomer((int) $result['registration']->id, (int) $customer->id);

    $change = app(RegistrationChangeService::class)->cancelEvent(Event::find($event['id']), $admin, '场地原因取消');

    $eventModel = Event::find($event['id']);
    expect($eventModel->hosting_status)->toBe('canceled');
    expect($scope->refresh()->status)->toBe('closed');
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);
    expect($result['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
    expect($result['attempt']->refresh()->cancel_reason_code)->toBe('event_canceled');
    expect($change->type)->toBe(EventChange::TYPE_CANCEL);
    expect((int) $change->details['released_seats'])->toBe(1);

    // 旧凭证不可用。
    expect(fn () => app(RegistrationCredentialService::class)->checkIn($credential['token'], $admin))
        ->toThrow(RegistrationException::class);

    // 公开状态变为 canceled，写入口停用。
    $revision = \Webkul\Shop\Models\EventRevision::find($event['revision_id']);
    expect(app(PublicRegistrationState::class)->forEvent($eventModel, $revision, (int) $customer->id)['registration_reason'])
        ->toBe('canceled');

    // 重复取消幂等。
    $again = app(RegistrationChangeService::class)->cancelEvent($eventModel, $admin, '再次取消');
    expect((int) $again->id)->toBe((int) $change->id);
});

it('G2-C · 需确认变更：未响应阻止签到；接受后放行', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ChangeScope($event['id'], ['change_ack_required' => true]);
    $customer = g2ChangeCustomer();
    $admin = g2ChangeAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2ChangeInput($scope));
    $credential = app(RegistrationCredentialService::class)->issueForCustomer((int) $result['registration']->id, (int) $customer->id);

    $change = app(RegistrationChangeService::class)->recordReschedule(
        Event::find($event['id']),
        $admin,
        '改到新场馆',
        ['revision_id' => $event['revision_id']],
        true,
        now('UTC')->addDay(),
        true,
        'none',
    );

    expect(app(RegistrationChangeService::class)->pendingForRegistration($result['registration']))->toHaveCount(1);

    expect(fn () => app(RegistrationCredentialService::class)->checkIn($credential['token'], $admin))
        ->toThrow(RegistrationException::class);

    $ack = app(RegistrationChangeService::class)->acknowledge(
        (int) $result['registration']->id,
        (int) $customer->id,
        (int) $change->id,
        'accepted',
        (int) $result['attempt']->id,
    );

    expect($ack->response)->toBe('accepted');
    expect(app(RegistrationChangeService::class)->pendingForRegistration($result['registration']))->toHaveCount(0);

    $checkedIn = app(RegistrationCredentialService::class)->checkIn($credential['token'], $admin);
    expect($checkedIn->status)->toBe(EventRegistrationAttempt::STATUS_CHECKED_IN);
});

it('G2-C · 拒绝变更按政策释放席位；逾期 no_response 按政策处理', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ChangeScope($event['id'], ['change_ack_required' => true]);
    $customer = g2ChangeCustomer();
    $admin = g2ChangeAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2ChangeInput($scope));

    $change = app(RegistrationChangeService::class)->recordReschedule(
        Event::find($event['id']),
        $admin,
        '改期',
        ['revision_id' => $event['revision_id']],
        true,
        now('UTC')->addDay(),
        true,
        'none',
    );

    app(RegistrationChangeService::class)->acknowledge(
        (int) $result['registration']->id,
        (int) $customer->id,
        (int) $change->id,
        'declined',
        (int) $result['attempt']->id,
    );

    expect($result['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
    expect($result['attempt']->refresh()->cancel_reason_code)->toBe('change_declined');
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    // 第二个客户：逾期未响应，政策 release。
    $other = g2ChangeCustomer('other');
    $second = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $other, g2ChangeInput($scope));
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);

    $expired = app(RegistrationChangeService::class)->recordReschedule(
        Event::find($event['id']),
        $admin,
        '再次改期',
        ['revision_id' => $event['revision_id']],
        true,
        now('UTC')->addMinute(),
        false,
        'release',
    );

    // 模拟时间走过确认截止：服务只在创建时要求截止在未来。
    DB::table('event_changes')->where('id', $expired->id)->update([
        'ack_deadline' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    ]);

    $processed = app(RegistrationChangeService::class)->expireAcks(10);
    expect($processed)->toBeGreaterThanOrEqual(1);
    expect($second['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
    expect($second['attempt']->refresh()->cancel_reason_code)->toBe('change_no_response');
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);
});

it('G2-C · 容量/关闭变更在同一事务写入 event_changes', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ChangeScope($event['id']);
    $admin = g2ChangeAdmin();
    $adminId = (int) $admin->id;

    app(RegistrationScopeService::class)->adjustCapacity($scope, (int) $scope->row_version, 3, true, $adminId, '缩减');
    $scope->refresh();

    $capacityChange = EventChange::query()->where('event_id', $event['id'])->where('type', 'capacity')->first();
    expect($capacityChange)->not->toBeNull();
    expect((int) $capacityChange->details['after'])->toBe(3);

    app(RegistrationScopeService::class)->close($scope, (int) $scope->row_version, $adminId, '临时关闭');

    $closeChange = EventChange::query()->where('event_id', $event['id'])->where('type', 'close')->first();
    expect($closeChange)->not->toBeNull();
});

it('G2-C · 取消活动跨 500 行扇出不漏人（T53）', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ChangeScope($event['id'], ['capacity' => 1000]);
    $admin = g2ChangeAdmin();
    $now = now('UTC')->format('Y-m-d H:i:s');
    $total = 520;

    $registrations = [];

    for ($i = 1; $i <= $total; $i++) {
        $registrations[] = [
            'event_id'      => $event['id'],
            'scope_id'      => $scope->id,
            'subject_key'   => 'load:'.$i,
            'public_no'     => 'EVLOAD'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'customer_id'   => $i,
            'identity_kind' => 'customer',
            'name'          => '',
            'phone'         => '',
            'row_version'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
    }

    DB::table('event_registrations')->insert($registrations);

    $ids = DB::table('event_registrations')
        ->where('event_id', $event['id'])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $attempts = [];

    foreach ($ids as $index => $registrationId) {
        $attempts[] = [
            'registration_id'     => $registrationId,
            'event_id'            => $event['id'],
            'scope_id'            => $scope->id,
            'owner_customer_id'   => $index + 1,
            'attempt_no'          => 1,
            'status'              => EventRegistrationAttempt::STATUS_CONFIRMED,
            'review_mode'         => 'auto',
            'form_version'        => 1,
            'form_snapshot'       => '{}',
            'rules_version_id'    => null,
            'rules_fingerprint'   => null,
            'privacy_version'     => '2026-10-19',
            'privacy_fingerprint' => str_repeat('a', 64),
            'consent_accepted_at' => $now,
            'contact_name_enc'    => 'x',
            'contact_phone_enc'   => 'x',
            'contact_phone_hash'  => str_repeat('b', 64),
            'contact_phone_last4' => '0000',
            'contact_city'        => '上海',
            'note_enc'            => null,
            'request_key'         => 'load-'.$index,
            'submitted_at'        => $now,
            'row_version'         => 0,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
    }

    DB::table('event_registration_attempts')->insert($attempts);

    DB::table('event_registration_scopes')->where('id', $scope->id)->update(['confirmed_count' => $total]);
    $scope->refresh();

    app(RegistrationChangeService::class)->cancelEvent(Event::find($event['id']), $admin, '批量取消');

    expect(DB::table('event_registration_attempts')
        ->where('event_id', $event['id'])
        ->where('status', EventRegistrationAttempt::STATUS_CANCELED)
        ->count())->toBe($total);

    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'registration')
        ->where('event_type', 'registration.event_canceled')
        ->count())->toBe($total);
});
