<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\PublicRegistrationState;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationPolicy;
use Webkul\Shop\Services\Ecosystem\RegistrationReviewService;
use Webkul\Shop\Services\Ecosystem\RegistrationCancellationService;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-B · 提交 / 幂等 / 审核 / 取消 / 重报 / 公开状态。
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

function g2Customer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-customer-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2OpenScope(int $eventId, array $overrides = []): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, array_merge([
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
    ], $overrides));

    return EventRegistrationScope::findOrFail($id);
}

function g2Input(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'    => 'req-'.bin2hex(random_bytes(8)),
        'form_version'   => (int) $scope->form_version,
        'privacy_version'=> (string) $scope->privacy_version,
        'consent'        => true,
        'name'           => '张三',
        'phone'          => '13800000001',
        'city'           => '上海',
        'note'           => '报名备注',
    ], $overrides);
}

function g2SuperAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'all', 'permissions' => []]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-B · 自动确认：提交即占位，联系人密文入库，outbox 同事务', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => 2, 'review_mode' => 'auto']);
    $customer = g2Customer();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));

    expect($result['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_CONFIRMED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);
    expect($result['registration']->public_no)->not->toBe('');
    expect($result['registration']->identity_kind)->toBe('customer');

    $raw = DB::table('event_registration_attempts')->where('id', $result['attempt']->id)->first();

    expect($raw->contact_name_enc)->not->toContain('张三');
    expect($raw->contact_phone_enc)->not->toContain('13800000001');
    expect(DB::table('event_registrations')->where('id', $result['registration']->id)->value('name'))->toBe('');

    $vault = app(RegistrationContactVault::class);
    expect($vault->decryptName($raw->contact_name_enc))->toBe('张三');
    expect($vault->decryptPhone($raw->contact_phone_enc))->toBe('13800000001');

    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'registration')
        ->where('event_type', 'registration.confirmed')
        ->where('aggregate_id', $result['registration']->id)
        ->exists())->toBeTrue();
});

it('G2-B · 同 key 同载荷重放不新增；同 key 异载荷 409', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id']);
    $customer = g2Customer();
    $input = g2Input($scope);
    $service = app(RegistrationSubmissionService::class);

    $first = $service->submit(Event::find($event['id']), $customer, $input);
    $second = $service->submit(Event::find($event['id']), $customer, $input);

    expect($second['replayed'])->toBeTrue();
    expect((int) $second['attempt']->id)->toBe((int) $first['attempt']->id);
    expect(DB::table('event_registration_attempts')->where('registration_id', $first['registration']->id)->count())->toBe(1);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);

    $conflict = g2Input($scope, ['request_key' => $input['request_key'], 'name' => '李四']);

    expect(fn () => $service->submit(Event::find($event['id']), $customer, $conflict))
        ->toThrow(RegistrationException::class);
});

it('G2-B · 人工审核：待审不占位，批准取额，拒绝不释放席位', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], [
        'capacity'         => 1,
        'review_mode'      => 'manual',
        'review_closes_at' => now('UTC')->addDays(2)->format('Y-m-d H:i:s'),
    ]);
    $customer = g2Customer();
    $admin = g2SuperAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));

    expect($result['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_SUBMITTED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    $approved = app(RegistrationReviewService::class)->approve((int) $result['attempt']->id, $admin);

    expect($approved->status)->toBe(EventRegistrationAttempt::STATUS_CONFIRMED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);

    // 第二个申请在容量已满时被拒。
    $other = g2Customer('other');
    expect(fn () => app(RegistrationSubmissionService::class)
        ->submit(Event::find($event['id']), $other, g2Input($scope)))
        ->toThrow(RegistrationException::class);
});

it('G2-B · 容量 1 时第二个客户稳定拒绝，计数不超额', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => 1]);

    $first = g2Customer('one');
    $second = g2Customer('two');
    $service = app(RegistrationSubmissionService::class);

    $service->submit(Event::find($event['id']), $first, g2Input($scope));

    try {
        $service->submit(Event::find($event['id']), $second, g2Input($scope));
        $this->fail('第二个报名不应成功');
    } catch (RegistrationException $exception) {
        expect($exception->errorCode())->toBe('REGISTRATION_FULL');
    }

    expect((int) $scope->refresh()->confirmed_count)->toBe(1);
    expect(DB::table('event_registration_attempts')->where('scope_id', $scope->id)->where('status', 'confirmed')->count())->toBe(1);
});

it('G2-B · 取消只释放一次；重报创建新轮次，旧指令不影响新轮次', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => 2]);
    $customer = g2Customer();

    $first = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));
    $cancelInput = [
        'request_key'         => 'cancel-'.bin2hex(random_bytes(6)),
        'expected_attempt_id' => (int) $first['attempt']->id,
    ];

    $cancel = app(RegistrationCancellationService::class)->cancelByCustomer(
        (int) $first['registration']->id,
        (int) $customer->id,
        $cancelInput,
    );

    expect($cancel['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    // 重放取消不二次释放。
    $replay = app(RegistrationCancellationService::class)->cancelByCustomer(
        (int) $first['registration']->id,
        (int) $customer->id,
        $cancelInput,
    );
    expect($replay['replayed'])->toBeTrue();
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    // 重报必须钉住旧轮次；新轮次创建后旧取消键不会作用于它。
    $reapply = app(RegistrationSubmissionService::class)->submit(
        Event::find($event['id']),
        $customer,
        g2Input($scope, ['expected_attempt_id' => (int) $first['attempt']->id]),
        'reapply',
    );

    expect($reapply['attempt']->attempt_no)->toBe(2);
    expect($reapply['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_CONFIRMED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(1);

    $stale = [
        'request_key'         => 'cancel-stale-'.bin2hex(random_bytes(6)),
        'expected_attempt_id' => (int) $first['attempt']->id,
    ];

    expect(fn () => app(RegistrationCancellationService::class)->cancelByCustomer(
        (int) $first['registration']->id,
        (int) $customer->id,
        $stale,
    ))->toThrow(RegistrationException::class);

    expect((int) $scope->refresh()->confirmed_count)->toBe(1);
});

it('G2-B · 已签到不能自助取消；运营纠正可取消并释放一次', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => 2]);
    $customer = g2Customer();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));

    DB::table('event_registration_attempts')->where('id', $result['attempt']->id)->update([
        'status' => EventRegistrationAttempt::STATUS_CHECKED_IN,
    ]);
    DB::table('event_registration_scopes')->where('id', $scope->id)->update(['confirmed_count' => 1]);

    expect(fn () => app(RegistrationCancellationService::class)->cancelByCustomer(
        (int) $result['registration']->id,
        (int) $customer->id,
        ['request_key' => 'c-'.bin2hex(random_bytes(4)), 'expected_attempt_id' => (int) $result['attempt']->id],
    ))->toThrow(RegistrationException::class);

    $admin = g2SuperAdmin();
    $canceled = app(RegistrationCancellationService::class)->cancelByAdmin(
        (int) $result['attempt']->id,
        $admin,
        'admin_correction',
        '现场纠正',
    );

    expect($canceled->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);
});

it('G2-B · 公开状态 reason 优先级：requires_login / ok / already_registered / full', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => 1]);
    $state = app(PublicRegistrationState::class);
    $eventModel = Event::find($event['id']);
    $revision = EventRevision::find($event['revision_id']);

    expect($state->forEvent($eventModel, $revision, null)['registration_reason'])->toBe('requires_login');

    $customer = g2Customer();
    expect($state->forEvent($eventModel, $revision, (int) $customer->id)['registration_reason'])->toBe('ok');

    app(RegistrationSubmissionService::class)->submit($eventModel, $customer, g2Input($scope));

    expect($state->forEvent($eventModel, $revision, (int) $customer->id)['registration_reason'])->toBe('already_registered');

    $other = g2Customer('other');
    expect($state->forEvent($eventModel, $revision, (int) $other->id)['registration_reason'])->toBe('full');
});

it('G2-B · G0 双闸关闭时写入口仍稳定拒绝', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id']);
    $customer = g2Customer();

    config(['ecosystem.events.registration_write_verified' => false]);

    expect(fn () => app(RegistrationSubmissionService::class)
        ->submit(Event::find($event['id']), $customer, g2Input($scope)))
        ->toThrow(RegistrationException::class);

    expect(DB::table('event_registration_attempts')->count())->toBe(0);
});

it('G2-B · 审核截止后扫描置 rejected，不释放不存在的席位', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], [
        'capacity'         => 2,
        'review_mode'      => 'manual',
        'review_closes_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
        'registration_closes_at' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
    ]);
    $customer = g2Customer();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));

    expect($result['attempt']->status)->toBe(EventRegistrationAttempt::STATUS_SUBMITTED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);

    $processed = app(RegistrationReviewService::class)->expireOverdue(10);

    expect($processed)->toBe(1);
    expect($result['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_REJECTED);
    expect($result['attempt']->refresh()->decision_reason_code)->toBe('review_expired');
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);
});

it('G2-B · capacity=null 不限；已有轮次优先于“名额已满”', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], ['capacity' => null]);
    $service = app(RegistrationSubmissionService::class);

    $one = g2Customer('one');
    $two = g2Customer('two');

    $service->submit(Event::find($event['id']), $one, g2Input($scope));
    $service->submit(Event::find($event['id']), $two, g2Input($scope));

    expect((int) $scope->refresh()->confirmed_count)->toBe(2);

    // 容量收紧到 1 后，已报名本人再次提交应得到 already_active，而不是 full。
    DB::table('event_registration_scopes')->where('id', $scope->id)->update(['capacity' => 1]);
    $scope->refresh();

    try {
        $service->submit(Event::find($event['id']), $one, g2Input($scope));
        $this->fail('已有有效轮次不应再次提交');
    } catch (RegistrationException $exception) {
        expect($exception->errorCode())->toBe('REGISTRATION_ALREADY_ACTIVE');
    }

    expect((int) $scope->refresh()->confirmed_count)->toBe(2);
});

it('G2-B · 审核截止后不再批准', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id'], [
        'review_mode'      => 'manual',
        'review_closes_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
        'registration_closes_at' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
    ]);
    $customer = g2Customer();
    $admin = g2SuperAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2Input($scope));

    expect(fn () => app(RegistrationReviewService::class)->approve((int) $result['attempt']->id, $admin))
        ->toThrow(RegistrationException::class);

    expect($result['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_SUBMITTED);
    expect((int) $scope->refresh()->confirmed_count)->toBe(0);
});

it('G2-B · 旧表单/隐私版本在登录后必须重取，不能提交', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2OpenScope($event['id']);
    $customer = g2Customer();
    $service = app(RegistrationSubmissionService::class);

    try {
        $service->submit(Event::find($event['id']), $customer, g2Input($scope, ['form_version' => 99]));
        $this->fail('旧表单版本不应提交');
    } catch (RegistrationException $exception) {
        expect($exception->reason())->toBe('form_version_stale');
    }

    try {
        $service->submit(Event::find($event['id']), $customer, g2Input($scope, ['privacy_version' => 'old-version']));
        $this->fail('旧隐私版本不应提交');
    } catch (RegistrationException $exception) {
        expect($exception->reason())->toBe('privacy_version_stale');
    }

    expect(DB::table('event_registration_attempts')->where('event_id', $event['id'])->count())->toBe(0);
});
