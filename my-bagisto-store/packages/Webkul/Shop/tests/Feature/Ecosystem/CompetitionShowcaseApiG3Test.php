<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;
use Webkul\Shop\Services\Ecosystem\CompetitionSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G3-11 API · 队长授权、公开展示开关与最小字段。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.public_showcase_enabled' => true,
        'ecosystem.events.public_enabled' => true,
    ]);
});

function g3sapiProfile(): array
{
    return Fixtures::rulesProfile(array_replace_recursive([
        'scoring' => [
            'policy'  => 'total_desc',
            'scale'   => 2,
            'missing' => 'exclude',
            'forfeit' => 'disqualify',
            'fields'  => [
                ['key' => 'speed', 'label' => '速度分', 'type' => 'decimal', 'direction' => 'higher'],
            ],
        ],
        'tie' => ['policy' => 'shared'],
    ]));
}

function g3sapiContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3sapiProfile());
    $track = CompetitionTrack::query()->findOrFail($trackId);

    $captainId = Fixtures::customer('展示 API 队长');
    $memberId = Fixtures::customer('展示 API 队员');
    $teamId = Fixtures::team($edition, $trackId, $captainId);
    Fixtures::member($teamId, $memberId);
    $entryId = Fixtures::entry($edition, $trackId, $teamId, $captainId, [
        'rules_version_id' => (int) $version->getKey(),
        'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
    ]);

    $captain = Customer::query()->findOrFail($captainId);
    $team = CompetitionTeam::query()->findOrFail($teamId);
    $entry = CompetitionEntry::query()->findOrFail($entryId);

    app(CompetitionSubmissionService::class)->saveDraft($team, $entry, $captain, [
        'title'       => '展示 API 作品',
        'summary'     => '用于展示授权的作品',
        'description' => '不应出现在公开响应里',
        'repo_url'    => 'https://example.com/private-repo',
    ]);
    $submitted = app(CompetitionSubmissionService::class)->submit($team, $entry, $captain);

    $showcase = app(CompetitionShowcaseService::class)->createDraft($track, [
        'showcase_type'         => CompetitionShowcase::TYPE_RECAP,
        'title'                 => '展示 API 回顾',
        'summary'               => '队伍在赛场上完成的完整作品与复盘内容。',
        'entry_id'              => $entryId,
        'submission_version_id' => (int) $submitted->id,
    ], $admin);

    return [
        'event_id' => (int) $event['id'],
        'captain'  => $captain,
        'member'   => Customer::query()->findOrFail($memberId),
        'showcase' => $showcase,
        'admin'    => $admin,
    ];
}

it('G3-11 API · 只有队长能授权，授权后公开展示最小字段', function () {
    $ctx = g3sapiContext();

    actingAs($ctx['captain'], 'customer');
    $list = getJson('/api/customer/competition/showcases')->assertOk()->json('data.showcases');
    expect($list)->toHaveCount(1);
    expect($list[0]['consent_valid'])->toBeFalse();

    actingAs($ctx['member'], 'customer');
    expect(getJson('/api/customer/competition/showcases')->assertOk()->json('data.showcases'))->toHaveCount(0);
    postJson("/api/customer/competition/showcases/{$ctx['showcase']->id}/consent", [
        'consent'              => true,
        'expected_row_version' => (int) $ctx['showcase']->row_version,
    ])->assertNotFound();

    actingAs($ctx['captain'], 'customer');
    $consented = postJson("/api/customer/competition/showcases/{$ctx['showcase']->id}/consent", [
        'consent'              => true,
        'expected_row_version' => (int) $ctx['showcase']->row_version,
    ])->assertOk()->json('data');

    expect($consented['consent_valid'])->toBeTrue();

    $service = app(CompetitionShowcaseService::class);
    $showcase = CompetitionShowcase::query()->findOrFail($ctx['showcase']->id);
    $showcase = $service->approve($showcase, (int) $showcase->row_version, $ctx['admin']);
    $service->publish($showcase, (int) $showcase->row_version, $ctx['admin']);

    $public = getJson("/api/events/{$ctx['event_id']}/competition/showcases")->assertOk()->json('data.showcases.robowar');
    expect($public)->toHaveCount(1);
    expect($public[0])->toHaveKeys(['title', 'summary', 'team_name', 'track_name', 'award_name']);
    expect(json_encode($public[0]))->not->toContain('private-repo');
    expect(json_encode($public[0]))->not->toContain('不应出现在公开响应里');

    config(['ecosystem_competition.public_showcase_enabled' => false]);
    getJson("/api/events/{$ctx['event_id']}/competition/showcases")->assertNotFound();
});
