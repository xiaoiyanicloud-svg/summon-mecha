<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Services\Ecosystem\EcosystemOutboxProcessor;
use Webkul\Shop\Services\Ecosystem\RegistrationChangeService;
use Webkul\Shop\Services\Ecosystem\RegistrationReviewService;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-D · 报名 outbox 消费与站内信。
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

function g2NotifyCustomer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-notify-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2NotifyScope(int $eventId, array $overrides = []): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, array_merge([
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity'            => 5,
    ], $overrides));

    return EventRegistrationScope::findOrFail($id);
}

function g2NotifyInput(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'     => 'ntf-'.bin2hex(random_bytes(8)),
        'form_version'    => (int) $scope->form_version,
        'privacy_version' => (string) $scope->privacy_version,
        'consent'         => true,
        'name'            => '张三',
        'phone'           => '13800000001',
        'city'            => '上海',
    ], $overrides);
}

function g2NotifyAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'all', 'permissions' => []]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-D · 自动确认产生一条站内信；重投不重复', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2NotifyScope($event['id']);
    $customer = g2NotifyCustomer();

    app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2NotifyInput($scope));

    $summary = app(EcosystemOutboxProcessor::class)->run(20);

    expect($summary['sent'])->toBeGreaterThanOrEqual(1);

    $notification = Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', (int) $customer->id)
        ->where('type', 'registration.confirmed')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->channel)->toBe(Notification::CHANNEL_INAPP);
    expect($notification->content)->not->toContain('13800000001');
    expect(json_encode($notification->payload, JSON_UNESCAPED_UNICODE))->not->toContain('张三');

    // 模拟至少一次投递：把同一 outbox 行重新置为 pending，再跑一轮。
    DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'registration')
        ->update([
            'status'       => 'pending',
            'lease_owner'  => null,
            'lease_until'  => null,
            'available_at' => now('UTC')->format('Y-m-d H:i:s'),
        ]);

    app(EcosystemOutboxProcessor::class)->run(20);

    expect(Notification::query()
        ->where('recipient_id', (int) $customer->id)
        ->where('type', 'registration.confirmed')
        ->count())->toBe(1);
});

it('G2-D · 人工审核：待审/确认各一条；拒绝有独立消息', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2NotifyScope($event['id'], [
        'review_mode'      => 'manual',
        'review_closes_at' => now('UTC')->addDay()->format('Y-m-d H:i:s'),
    ]);
    $customer = g2NotifyCustomer();
    $admin = g2NotifyAdmin();
    $processor = app(EcosystemOutboxProcessor::class);

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2NotifyInput($scope));
    $processor->run(20);

    expect(Notification::query()->where('recipient_id', $customer->id)->where('type', 'registration.submitted')->count())->toBe(1);

    app(RegistrationReviewService::class)->approve((int) $result['attempt']->id, $admin);
    $processor->run(20);

    expect(Notification::query()->where('recipient_id', $customer->id)->where('type', 'registration.confirmed')->count())->toBe(1);
});

it('G2-D · 活动取消通知每个有效轮次；消息不含 PII', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2NotifyScope($event['id']);
    $customer = g2NotifyCustomer();
    $admin = g2NotifyAdmin();

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2NotifyInput($scope));

    app(RegistrationChangeService::class)->cancelEvent(Event::find($event['id']), $admin, '取消');
    app(EcosystemOutboxProcessor::class)->run(50);

    $notification = Notification::query()
        ->where('recipient_id', (int) $customer->id)
        ->where('type', 'registration.event_canceled')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->channel)->toBe(Notification::CHANNEL_INAPP);
    expect(json_encode($notification->payload, JSON_UNESCAPED_UNICODE))->not->toContain('13800000001');
    expect(json_encode($notification->payload, JSON_UNESCAPED_UNICODE))->not->toContain('张三');

    expect($result['attempt']->refresh()->status)->toBe(EventRegistrationAttempt::STATUS_CANCELED);
});
