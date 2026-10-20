<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamRegistrationService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-03 / G3-04 · 队伍、邀请、权限、队伍报名归属与快照。
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

function g3TeamContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version);

    return [
        'admin' => $admin,
        'event_id' => $event['id'],
        'edition_id' => $edition,
        'scope_id' => $scope,
        'track_id' => $trackId,
        'track' => CompetitionTrack::query()->with('edition')->findOrFail($trackId),
        'event' => Event::query()->findOrFail($event['id']),
    ];
}

it('G3-03 · 队长创建队伍、邀请码一次性、成员接受后才计入有效成员', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $member = Customer::query()->findOrFail(Fixtures::customer('队员'));
    $service = app(CompetitionTeamService::class);

    $team = $service->createTeam($captain, $ctx['track'], '机甲一队');
    expect($team->status)->toBe('active');

    $issued = $service->issueInvite($team, $captain, (int) $team->row_version);

    // 邀请中不是有效成员。
    expect((int) $team->fresh()->activeMembers()->count())->toBe(1);

    $accepted = $service->acceptInvite($member, $issued['token']);
    expect($accepted->status)->toBe('active');
    expect((int) $team->fresh()->activeMembers()->count())->toBe(2);

    // 同一个 token 不能被二次使用。
    expect(fn () => $service->acceptInvite($member, $issued['token']))
        ->toThrow(CompetitionException::class);
});

it('G3-03 · 非队长不能签发邀请/移交，队员不能越权', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $member = Customer::query()->findOrFail(Fixtures::customer('队员'));
    $outsider = Customer::query()->findOrFail(Fixtures::customer('路人'));
    $service = app(CompetitionTeamService::class);

    $team = $service->createTeam($captain, $ctx['track'], '权限队');
    $token = $service->issueInvite($team, $captain, (int) $team->row_version)['token'];
    $service->acceptInvite($member, $token);

    expect(fn () => $service->issueInvite($team, $member, (int) $team->fresh()->row_version))
        ->toThrow(CompetitionException::class);
    expect(fn () => $service->transferCaptain($team, $member, (int) $captain->id, (int) $team->fresh()->row_version))
        ->toThrow(CompetitionException::class);

    // 非成员访问不到队伍。
    expect($service->accessibleTeam((int) $team->id, $outsider))->toBeNull();
});

it('G3-03 · 一人多队默认禁止，队长移交后权限随之变化', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $member = Customer::query()->findOrFail(Fixtures::customer('队员'));
    $service = app(CompetitionTeamService::class);

    $teamA = $service->createTeam($captain, $ctx['track'], '甲队');
    $tokenA = $service->issueInvite($teamA, $captain, (int) $teamA->row_version)['token'];
    $service->acceptInvite($member, $tokenA);

    $other = Customer::query()->findOrFail(Fixtures::customer('别队队长'));
    $teamB = $service->createTeam($other, $ctx['track'], '乙队');
    $tokenB = $service->issueInvite($teamB, $other, (int) $teamB->row_version)['token'];

    // 同一赛项已有一支队伍，默认不允许再加入第二支。
    expect(fn () => $service->acceptInvite($member, $tokenB))
        ->toThrow(CompetitionException::class);

    // 移交后旧队长降为成员，新队长可以签发邀请。
    $fresh = $teamA->fresh();
    $service->transferCaptain($fresh, $captain, (int) $member->id, (int) $fresh->row_version);

    expect((int) $teamA->fresh()->captain_customer_id)->toBe((int) $member->id);
    expect((string) CompetitionTeamMember::query()
        ->where('team_id', $teamA->id)
        ->where('customer_id', $captain->id)
        ->value('role'))->toBe('member');
    expect(fn () => $service->issueInvite($teamA->fresh(), $captain, (int) $teamA->fresh()->row_version))
        ->toThrow(CompetitionException::class);
});

it('G3-04 · 队伍报名归属队伍、冻结成员/资格/机器人声明快照', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $member = Customer::query()->findOrFail(Fixtures::customer('队员'));
    $teamService = app(CompetitionTeamService::class);

    $team = $teamService->createTeam($captain, $ctx['track'], '快照队');
    $token = $teamService->issueInvite($team, $captain, (int) $team->row_version)['token'];
    $teamService->acceptInvite($member, $token);

    $scope = DB::table('event_registration_scopes')->where('id', $ctx['scope_id'])->first();

    $result = app(CompetitionTeamRegistrationService::class)->submit(
        $ctx['event'],
        $ctx['track'],
        $team->fresh(),
        $captain,
        [
            'request_key'     => 'g3-team-'.bin2hex(random_bytes(4)),
            'form_version'    => (int) $scope->form_version,
            'privacy_version' => (string) $scope->privacy_version,
            'rules_version_id'=> (int) $ctx['track']->rules_version_id,
            'consent'         => true,
            'name'            => '队长联系人',
            'phone'           => '13800000001',
            'robot'           => ['model' => 'M1', 'asset_no' => 'A1'],
        ],
    );

    $registration = $result['registration'];
    $entry = $result['entry'];

    expect($registration->identity_kind)->toBe(EventRegistration::IDENTITY_TEAM);
    expect($registration->subject_key)->toBe('t:'.(int) $team->id);
    expect($registration->team_id)->toBe((int) $team->id);
    expect($entry->status)->toBe(CompetitionEntry::STATUS_CONFIRMED);
    expect($entry->frozen_at)->not->toBeNull();
    expect(count((array) $entry->roster_snapshot))->toBe(2);
    expect($entry->eligibility_snapshot['team_name'])->toBe('快照队');

    // 冻结后队伍改名不回溯覆盖快照。
    DB::table('competition_teams')->where('id', $team->id)->update(['name' => '改名后的队']);
    expect($entry->fresh()->eligibility_snapshot['team_name'])->toBe('快照队');

    // 冻结后成员/队长变更被拒绝。
    expect(fn () => $teamService->leave($team->fresh(), $member, (int) $team->fresh()->row_version))
        ->toThrow(CompetitionException::class);
});

it('G3-04 · 未达资格（人数/机器人声明）不能确认参赛', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $teamService = app(CompetitionTeamService::class);
    $team = $teamService->createTeam($captain, $ctx['track'], '独狼队');

    $scope = DB::table('event_registration_scopes')->where('id', $ctx['scope_id'])->first();

    // 规则要求至少 2 人；当前只有队长。
    try {
        app(CompetitionTeamRegistrationService::class)->submit(
            $ctx['event'],
            $ctx['track'],
            $team,
            $captain,
            [
                'request_key'     => 'g3-under-'.bin2hex(random_bytes(4)),
                'form_version'    => (int) $scope->form_version,
                'privacy_version' => (string) $scope->privacy_version,
                'rules_version_id'=> (int) $ctx['track']->rules_version_id,
                'consent'         => true,
                'name'            => '队长联系人',
                'phone'           => '13800000002',
                'robot'           => ['model' => 'M1', 'asset_no' => 'A1'],
            ],
        );
        expect(false)->toBeTrue('人数不足不应提交成功');
    } catch (CompetitionException $exception) {
        expect($exception->errorCode())->toBe('COMPETITION_ELIGIBILITY_FAILED');
    }

    expect(CompetitionEntry::query()->where('team_id', $team->id)->exists())->toBeFalse();
    expect(EventRegistration::query()->where('team_id', $team->id)->exists())->toBeFalse();
});

it('G3-03 · 非队长提交队伍报名被拒绝', function () {
    $ctx = g3TeamContext();
    $captain = Customer::query()->findOrFail(Fixtures::customer('队长'));
    $member = Customer::query()->findOrFail(Fixtures::customer('队员'));
    $teamService = app(CompetitionTeamService::class);
    $team = $teamService->createTeam($captain, $ctx['track'], '越权队');
    $token = $teamService->issueInvite($team, $captain, (int) $team->row_version)['token'];
    $teamService->acceptInvite($member, $token);

    $scope = DB::table('event_registration_scopes')->where('id', $ctx['scope_id'])->first();

    expect(fn () => app(CompetitionTeamRegistrationService::class)->submit(
        $ctx['event'],
        $ctx['track'],
        $team->fresh(),
        $member,
        [
            'request_key'     => 'g3-forbidden-'.bin2hex(random_bytes(4)),
            'form_version'    => (int) $scope->form_version,
            'privacy_version' => (string) $scope->privacy_version,
            'rules_version_id'=> (int) $ctx['track']->rules_version_id,
            'consent'         => true,
            'name'            => '队员联系人',
            'phone'           => '13800000003',
            'robot'           => ['model' => 'M1', 'asset_no' => 'A1'],
        ],
    ))->toThrow(CompetitionException::class);
});
