<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionAwardVersion;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAwardService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-10 · 奖项版本、成绩修订联动、旧奖项不冒充最新结论。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['ecosystem_competition.write_enabled' => true]);
});

function g3AwardProfile(): array
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

function g3AwardContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3AwardProfile());
    $track = CompetitionTrack::query()->findOrFail($trackId);

    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();

    $entries = [];

    foreach ([['A 队', '90'], ['B 队', '80']] as [$name, $score]) {
        $captain = Fixtures::customer($name);
        $team = Fixtures::team($edition, $trackId, $captain);
        $entryId = Fixtures::entry($edition, $trackId, $team, $captain, [
            'rules_version_id' => (int) $version->getKey(),
            'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
        ]);
        $entry = CompetitionEntry::query()->findOrFail($entryId);
        $result = app(CompetitionResultService::class)->saveDraft(
            $track,
            $entry,
            null,
            ['speed' => $score],
            'none',
            0,
            $recorder,
        );
        $result = app(CompetitionResultService::class)->submit($result, (int) $result->row_version, $recorder);
        $entries[] = ['entry_id' => $entryId, 'result_id' => (int) $result->id, 'team' => $name];
    }

    app(CompetitionResultService::class)->applyRanking($track, [
        ['entry_id' => $entries[0]['entry_id'], 'rank_no' => 1, 'tie_group' => null],
        ['entry_id' => $entries[1]['entry_id'], 'rank_no' => 2, 'tie_group' => null],
    ], $reviewer);
    app(CompetitionResultService::class)->publishProvisional($track, (int) $track->row_version, $reviewer);
    app(CompetitionResultService::class)->finalize($track, (int) $track->row_version, $reviewer);

    return [
        'track'    => $track->fresh(),
        'entries'  => $entries,
        'recorder' => $recorder,
        'reviewer' => $reviewer,
        'event_id' => (int) $event['id'],
    ];
}

it('G3-10 · 奖项发布引用终审成绩；暂定成绩不能发布奖项', function () {
    $ctx = g3AwardContext();
    $service = app(CompetitionAwardService::class);
    $admin = Fixtures::adminId();

    $award = $service->createVersion($ctx['track'], [
        'award_key' => 'champion',
        'name'      => '冠军',
        'entry_id'  => $ctx['entries'][0]['entry_id'],
        'sort_order'=> 1,
    ], $admin);

    $versionId = (int) CompetitionAwardVersion::query()
        ->where('award_id', $award->id)
        ->value('id');

    $published = $service->publish($award->fresh(), $versionId, (int) $award->fresh()->row_version, $admin);

    expect($published->status)->toBe(CompetitionAward::STATUS_PUBLISHED);

    $public = $service->publicAwards($ctx['event_id']);
    expect($public['robowar'])->toHaveCount(1);
    expect($public['robowar'][0]['name'])->toBe('冠军');
    expect($public['robowar'][0]['rank_no'])->toBe(1);

    // 暂定成绩上的奖项草稿不能发布。
    $ctx2 = g3AwardContext();
    $result = CompetitionResult::query()->findOrFail($ctx2['entries'][0]['result_id']);
    DB::table('competition_results')->where('id', $result->id)->update(['status' => 'provisional']);
    DB::table('competition_result_versions')->where('id', $result->published_version_id)->update(['status' => 'provisional']);

    $draftAward = $service->createVersion($ctx2['track'], [
        'award_key' => 'champion',
        'name'      => '冠军',
        'entry_id'  => $ctx2['entries'][0]['entry_id'],
    ], $admin);
    $draftVersion = (int) CompetitionAwardVersion::query()->where('award_id', $draftAward->id)->value('id');

    expect(fn () => $service->publish($draftAward->fresh(), $draftVersion, (int) $draftAward->fresh()->row_version, $admin))
        ->toThrow(CompetitionException::class);
});

it('G3-10 · 成绩修订后旧奖项转待复核，新版本发布指向新结论', function () {
    $ctx = g3AwardContext();
    $awardService = app(CompetitionAwardService::class);
    $resultService = app(CompetitionResultService::class);
    $admin = Fixtures::adminId();
    $publisher = Fixtures::adminId();

    $award = $awardService->createVersion($ctx['track'], [
        'award_key' => 'champion',
        'name'      => '冠军',
        'entry_id'  => $ctx['entries'][0]['entry_id'],
    ], $admin);
    $oldVersionId = (int) CompetitionAwardVersion::query()->where('award_id', $award->id)->value('id');
    $awardService->publish($award->fresh(), $oldVersionId, 0, $admin);

    expect($awardService->publicAwards($ctx['event_id'])['robowar'])->toHaveCount(1);

    $result = CompetitionResult::query()->findOrFail($ctx['entries'][0]['result_id']);
    $oldResultVersionId = (int) $result->published_version_id;

    // 修订成绩：复核人发起，新草稿由复核人创建。
    $revised = $resultService->revise($result, (int) $result->row_version, '计时器故障，重赛改分', $ctx['reviewer']);

    // 旧奖项立刻转待复核，公开面不再返回。
    expect((string) CompetitionAward::query()->find($award->id)->status)->toBe(CompetitionAward::STATUS_PENDING_REVIEW);
    expect((string) CompetitionAwardVersion::query()->find($oldVersionId)->status)->toBe(CompetitionAwardVersion::STATUS_PENDING_REVIEW);
    expect($awardService->publicAwards($ctx['event_id'])['robowar'])->toHaveCount(0);

    // 新成绩走完整流程：草稿→提交→排名→暂定→终审（不同管理员）。
    $revised = $resultService->saveDraft(
        $ctx['track'],
        CompetitionEntry::query()->findOrFail($ctx['entries'][0]['entry_id']),
        null,
        ['speed' => '95'],
        'none',
        (int) $revised->row_version,
        $ctx['reviewer'],
        '重赛成绩',
    );
    $revised = $resultService->submit($revised, (int) $revised->row_version, $ctx['reviewer']);
    $resultService->applyRanking($ctx['track'], [
        ['entry_id' => $ctx['entries'][0]['entry_id'], 'rank_no' => 1, 'tie_group' => null],
        ['entry_id' => $ctx['entries'][1]['entry_id'], 'rank_no' => 2, 'tie_group' => null],
    ], $publisher);
    $resultService->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $publisher);
    $resultService->finalize($ctx['track'], (int) $ctx['track']->row_version, $publisher);

    $newResultVersionId = (int) CompetitionResult::query()->find($result->id)->published_version_id;
    expect($newResultVersionId)->not->toBe($oldResultVersionId);

    // 新奖项版本指向新成绩版本；旧 pending 版本被 superseded。
    $awardService->createVersion($ctx['track'], [
        'award_key'            => 'champion',
        'name'                 => '冠军',
        'entry_id'             => $ctx['entries'][0]['entry_id'],
        'expected_row_version' => (int) CompetitionAward::query()->find($award->id)->row_version,
    ], $admin);

    $newVersionId = (int) CompetitionAwardVersion::query()
        ->where('award_id', $award->id)
        ->orderByDesc('id')
        ->value('id');

    $awardService->publish(
        CompetitionAward::query()->findOrFail($award->id),
        $newVersionId,
        (int) CompetitionAward::query()->find($award->id)->row_version,
        $admin,
    );

    expect((string) CompetitionAwardVersion::query()->find($oldVersionId)->status)->toBe(CompetitionAwardVersion::STATUS_SUPERSEDED);
    expect((int) CompetitionAwardVersion::query()->find($newVersionId)->result_version_id)->toBe($newResultVersionId);

    $public = $awardService->publicAwards($ctx['event_id'])['robowar'];
    expect($public)->toHaveCount(1);
    expect($public[0]['name'])->toBe('冠军');
});

it('G3-10 · 撤销奖项保留历史，公开面不再显示', function () {
    $ctx = g3AwardContext();
    $service = app(CompetitionAwardService::class);
    $admin = Fixtures::adminId();

    $award = $service->createVersion($ctx['track'], [
        'award_key' => 'champion',
        'name'      => '冠军',
        'entry_id'  => $ctx['entries'][0]['entry_id'],
    ], $admin);
    $versionId = (int) CompetitionAwardVersion::query()->where('award_id', $award->id)->value('id');
    $award = $service->publish($award->fresh(), $versionId, 0, $admin);

    $revoked = $service->revoke($award, (int) $award->row_version, '复核发现成绩录入错误', $admin);

    expect($revoked->status)->toBe(CompetitionAward::STATUS_REVOKED);
    expect((string) CompetitionAwardVersion::query()->find($versionId)->status)->toBe(CompetitionAwardVersion::STATUS_REVOKED);
    expect($service->publicAwards($ctx['event_id'])['robowar'])->toHaveCount(0);
});
