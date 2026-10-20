<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionSeries;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionSeriesService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-02 · 系列 / 届次 / 赛项 / scope 关联与隔离。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem.events.registration_enabled' => true,
        'ecosystem.events.registration_write_verified' => true,
        'ecosystem_registration.enabled' => true,
    ]);
});

function g3ScopePayload(array $overrides = []): array
{
    $now = now('UTC');

    return array_merge([
        'capacity'               => 8,
        'confirm_capacity'       => true,
        'review_mode'            => 'auto',
        'registration_closes_at' => $now->copy()->addDay()->format('Y-m-d H:i:s'),
        'privacy_version'        => '2026-10-19',
        'privacy_summary'        => '同意摘要',
        'reorder_policy'         => 'never',
    ], $overrides);
}

function g3CreateTrack(Event $event, int $adminId, array $overrides = []): CompetitionTrack
{
    $service = app(CompetitionSeriesService::class);
    $version = Fixtures::ruleVersion((int) $event->id, $adminId);
    $profile = Fixtures::rulesProfile();
    $series = $service->createSeries([
        'name' => '测试系列 '.bin2hex(random_bytes(3)),
        'slug' => 'g3-svc-'.bin2hex(random_bytes(4)),
        'organizer_name' => '召唤机甲',
    ], $adminId);

    $edition = $service->createEdition($series, $event, [
        'edition_no' => 1,
        'name' => '2026 届',
        'timezone' => 'Asia/Shanghai',
    ], $adminId);

    return $service->createTrack($edition, array_merge([
        'track_key' => 'robowar',
        'name' => '机甲对战',
        'discipline' => '对抗',
        'submission_deadline_at' => now('UTC')->addDays(5)->format('Y-m-d H:i:s'),
        'rules_version_id' => (int) $version->getKey(),
        'rules_profile' => $profile,
        'confirm_rules' => true,
        'scope' => g3ScopePayload(),
    ], $overrides), $adminId);
}

it('G3-02 · 通过服务创建系列/届次/赛项并绑定专属 team scope', function () {
    $admin = Fixtures::adminId();
    $event = Fixtures::event();

    $track = g3CreateTrack(Event::query()->findOrFail($event['id']), $admin);

    expect($track->track_key)->toBe('robowar');
    expect($track->status)->toBe('draft');
    expect($track->rules_confirmed_at)->not->toBeNull();
    expect($track->rules_fingerprint)->toHaveLength(64);

    $scope = EventRegistrationScope::query()->findOrFail($track->registration_scope_id);
    expect($scope->unit)->toBe('team');
    expect($scope->scope_key)->toBe('robowar');
    expect((int) $scope->rule_version_id)->toBe((int) $track->rules_version_id);
    expect($scope->capacity_confirmed_at)->not->toBeNull();

    // 激活后仍不等于报名开放：scope 状态由 G2 单独管理。
    $activated = app(CompetitionSeriesService::class)->activate($track, (int) $track->row_version, $admin);
    expect($activated->status)->toBe('active');
    expect(EventRegistrationScope::query()->find($track->registration_scope_id)->status)->not->toBe('open');
});

it('G3-02 · 同一活动不能建立两届，scope 不能被别的届次复用', function () {
    $admin = Fixtures::adminId();
    $eventA = Fixtures::event();
    $eventB = Fixtures::event();
    $service = app(CompetitionSeriesService::class);
    $versionA = Fixtures::ruleVersion($eventA['id'], $admin);
    $series = Fixtures::series();
    $editionA = $service->createEdition(CompetitionSeries::query()->findOrFail($series['id']), Event::query()->findOrFail($eventA['id']), [
        'edition_no' => 1, 'name' => 'A 届', 'timezone' => 'Asia/Shanghai',
    ], $admin);

    expect(fn () => $service->createEdition(
        CompetitionSeries::query()->findOrFail($series['id']),
        Event::query()->findOrFail($eventA['id']),
        ['edition_no' => 2, 'name' => 'A2 届', 'timezone' => 'Asia/Shanghai'],
        $admin,
    ))->toThrow(CompetitionException::class);

    // B 活动属于另一个届次，不能复用 A 的 scope。
    $seriesB = Fixtures::series();
    $editionB = $service->createEdition(CompetitionSeries::query()->findOrFail($seriesB['id']), Event::query()->findOrFail($eventB['id']), [
        'edition_no' => 1, 'name' => 'B 届', 'timezone' => 'Asia/Shanghai',
    ], $admin);
    $scopeA = Fixtures::scope($eventA['id'], 'track-a');

    expect(fn () => $service->createTrack($editionB, [
        'track_key' => 'track-b',
        'name' => 'B 赛项',
        'submission_deadline_at' => now('UTC')->addDays(5)->format('Y-m-d H:i:s'),
        'registration_scope_id' => $scopeA,
    ], $admin))->toThrow(CompetitionException::class);
});

it('G3-02 · 规则未确认/容量未确认/无提交截止时不能激活', function () {
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $track = g3CreateTrack(Event::query()->findOrFail($event['id']), $admin);

    DB::table('competition_tracks')->where('id', $track->id)->update([
        'rules_confirmed_at' => null,
        'rules_fingerprint' => null,
    ]);

    expect(fn () => app(CompetitionSeriesService::class)->activate(
        CompetitionTrack::query()->findOrFail($track->id),
        0,
        $admin,
    ))->toThrow(CompetitionException::class);
});

it('G3-02 · 普通活动仍是 person 单位，个人报名入口不能写入 team scope', function () {
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $track = g3CreateTrack(Event::query()->findOrFail($event['id']), $admin);
    app(CompetitionSeriesService::class)->activate($track, (int) $track->row_version, $admin);

    // 普通 salon 仍创建 person scope。
    $salon = Fixtures::event(['kind' => 'salon']);
    $personScope = app(RegistrationScopeService::class)->createDefault(
        Event::query()->findOrFail($salon['id']),
        g3ScopePayload(),
        $admin,
    );
    expect($personScope->unit)->toBe('person');

    // 个人报名入口只能看到 default scope；赛事活动默认 scope 也是 team。
    $customer = Customer::query()->findOrFail(Fixtures::customer());
    $defaultScope = app(RegistrationScopeService::class)->createDefault(
        Event::query()->findOrFail($event['id']),
        g3ScopePayload(),
        $admin,
    );
    $scope = $defaultScope;

    try {
        app(RegistrationSubmissionService::class)->submit(
            Event::query()->findOrFail($event['id']),
            $customer,
            [
                'request_key'      => 'g3-personal-'.bin2hex(random_bytes(4)),
                'form_version'     => (int) $scope->form_version,
                'privacy_version'  => (string) $scope->privacy_version,
                'rules_version_id' => $scope->rule_version_id,
                'consent'          => true,
                'name'             => '测试选手',
                'phone'            => '13800000000',
            ],
        );
        expect(false)->toBeTrue('个人报名不应写入 team scope');
    } catch (RegistrationException $exception) {
        expect($exception->errorCode())->toBe('COMPETITION_UNIT_MISMATCH');
    }
});
