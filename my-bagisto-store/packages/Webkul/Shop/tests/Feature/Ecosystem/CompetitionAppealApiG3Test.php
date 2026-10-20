<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\EcosystemOutboxProcessor;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G3-08 / G3-09 · 公开成绩、申诉 HTTP 与站内信。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.public_results_enabled' => true,
        'ecosystem_competition.appeal.disk' => 'local',
        'ecosystem.events.public_enabled' => true,
    ]);

    Storage::fake('local');
});

function g3prProfile(): array
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

function g3prContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3prProfile());
    $track = CompetitionTrack::query()->findOrFail($trackId);

    $captainId = Fixtures::customer('公开队长');
    $memberId = Fixtures::customer('公开队员');
    $teamId = Fixtures::team($edition, $trackId, $captainId);
    Fixtures::member($teamId, $memberId);
    $entryId = Fixtures::entry($edition, $trackId, $teamId, $captainId, [
        'rules_version_id' => (int) $version->getKey(),
        'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
    ]);

    $entry = CompetitionEntry::query()->findOrFail($entryId);
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();

    $result = app(CompetitionResultService::class)->saveDraft(
        $track,
        $entry,
        null,
        ['speed' => '81.25'],
        'none',
        0,
        $recorder,
    );
    $result = app(CompetitionResultService::class)->submit($result, (int) $result->row_version, $recorder);
    app(CompetitionResultService::class)->applyRanking($track, [
        ['entry_id' => $entryId, 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);
    app(CompetitionResultService::class)->publishProvisional($track, (int) $track->row_version, $reviewer);

    return [
        'event_id' => (int) $event['id'],
        'track'    => $track,
        'entry'    => $entry,
        'captain'  => Customer::query()->findOrFail($captainId),
        'member'   => Customer::query()->findOrFail($memberId),
        'result'   => CompetitionResult::query()->findOrFail($result->id),
    ];
}

function g3prAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['ecosystem.competition.appeal'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

it('G3-08 API · 公开成绩受独立开关控制，只返回已公布版本', function () {
    $ctx = g3prContext();

    $response = getJson("/api/events/{$ctx['event_id']}/competition/results")->assertOk();
    $track = $response->json('data.results.0');

    expect($track['track_key'])->toBe('robowar');
    expect($track['results'])->toHaveCount(1);
    expect($track['results'][0]['status'])->toBe('provisional');
    expect($track['results'][0]['total'])->toBe('81.25');
    expect($track['results'][0]['rank_no'])->toBe(1);
    expect($track['results'][0]['result_version_id'])->toBe((int) $ctx['result']->published_version_id);

    config(['ecosystem_competition.public_results_enabled' => false]);
    getJson("/api/events/{$ctx['event_id']}/competition/results")->assertNotFound();
});

it('G3-09 API · 队长提交/撤回申诉，队员与路人被拒，证据私有', function () {
    $ctx = g3prContext();
    $versionId = (int) $ctx['result']->published_version_id;

    actingAs($ctx['member'], 'customer');
    postJson('/api/customer/competition/appeals', [
        'result_id'         => (int) $ctx['result']->id,
        'result_version_id' => $versionId,
        'reason'            => '队员不能代表队伍提交申诉。',
    ])->assertStatus(403);

    $outsider = Customer::query()->findOrFail(Fixtures::customer('公开路人'));
    actingAs($outsider, 'customer');
    postJson('/api/customer/competition/appeals', [
        'result_id'         => (int) $ctx['result']->id,
        'result_version_id' => $versionId,
        'reason'            => '路人不应该能提交申诉。',
    ])->assertStatus(403);

    actingAs($ctx['captain'], 'customer');
    $created = postJson('/api/customer/competition/appeals', [
        'result_id'         => (int) $ctx['result']->id,
        'result_version_id' => $versionId,
        'reason'            => '计时器读数疑似异常，申请复核本场成绩。',
    ])->assertOk()->json('data');

    expect($created['status'])->toBe('submitted');

    $path = tempnam(sys_get_temp_dir(), 'g3appeal_');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $attachment = $this->post(
        "/api/customer/competition/appeals/{$created['id']}/attachments",
        ['file' => new UploadedFile($path, 'evidence.png', 'image/png', null, true)],
        ['Accept' => 'application/json'],
    )->assertOk()->json('data');

    $download = $this->get($attachment['download_url'], ['Accept' => 'application/octet-stream']);
    $download->assertOk();
    expect($download->headers->get('Cache-Control'))->toContain('no-store');

    $mine = getJson('/api/customer/competition/appeals')->assertOk()->json('data.appeals');
    expect($mine)->toHaveCount(1);
    expect($mine[0]['attachments'])->toHaveCount(1);
    expect(json_encode($mine[0]))->not->toContain('storage_key');
});

it('G3-09 API · 管理员受理裁决后队长的站内信只投递一次', function () {
    $ctx = g3prContext();
    $admin = g3prAdmin();

    actingAs($ctx['captain'], 'customer');
    $appeal = postJson('/api/customer/competition/appeals', [
        'result_id'         => (int) $ctx['result']->id,
        'result_version_id' => (int) $ctx['result']->published_version_id,
        'reason'            => '对成绩有异议，申请正式复核与裁决。',
    ])->assertOk()->json('data');

    actingAs($admin, 'admin');
    $this->post(route('admin.catalog.ecosystem.competition.appeals.accept', $appeal['id']), [
        'expected_row_version' => 0,
        'note'                 => '受理并调查',
    ])->assertRedirect();

    $appealModel = CompetitionAppeal::query()->findOrFail($appeal['id']);

    $this->post(route('admin.catalog.ecosystem.competition.appeals.decide', $appeal['id']), [
        'expected_row_version'       => (int) $appealModel->row_version,
        'decision_result'            => 'upheld',
        'decision_reason'            => '复核录像后确认计时异常，修正成绩。',
        'decision_result_version_id' => (int) $ctx['result']->published_version_id,
    ])->assertRedirect();

    expect(CompetitionAppeal::query()->findOrFail($appeal['id'])->status)->toBe(CompetitionAppeal::STATUS_DECIDED);

    app(EcosystemOutboxProcessor::class)->run();
    app(EcosystemOutboxProcessor::class)->run();

    $notifications = Notification::query()
        ->where('recipient_id', (int) $ctx['captain']->id)
        ->where('type', 'competition_appeal.decided')
        ->count();

    expect($notifications)->toBe(1);

    // 暂定成绩事件也由同一 worker 核验并投递，重复执行不重复通知。
    expect(Notification::query()
        ->where('recipient_id', (int) $ctx['captain']->id)
        ->where('type', 'competition_result.provisional')
        ->count())->toBe(1);
});
