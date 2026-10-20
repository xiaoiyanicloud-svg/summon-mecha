<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-08 · 成绩草稿/提交/排名/暂定/终审/修订、分权与通知。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.public_results_enabled' => true,
        'ecosystem.events.public_enabled' => true,
    ]);
});

function g3ResultProfile(): array
{
    return Fixtures::rulesProfile(array_replace_recursive([
        'scoring' => [
            'policy'  => 'total_desc',
            'scale'   => 2,
            'missing' => 'exclude',
            'forfeit' => 'disqualify',
            'fields'  => [
                ['key' => 'speed', 'label' => '速度分', 'type' => 'decimal', 'direction' => 'higher', 'min' => '0', 'max' => '100'],
                ['key' => 'damage', 'label' => '伤害分', 'type' => 'decimal', 'direction' => 'higher', 'min' => '0', 'max' => '100'],
            ],
        ],
        'tie' => ['policy' => 'shared'],
    ]));
}

function g3ResultContext(int $teams = 2): array
{
    $eventAdmin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $eventAdmin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3ResultProfile());

    $entries = [];

    for ($i = 0; $i < $teams; $i++) {
        $captain = Fixtures::customer('队长'.$i);
        $team = Fixtures::team($edition, $trackId, $captain);
        $entry = Fixtures::entry($edition, $trackId, $team, $captain, [
            'rules_version_id' => (int) $version->getKey(),
            'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
        ]);

        $entries[] = [
            'captain_id' => $captain,
            'captain'    => Customer::query()->findOrFail($captain),
            'team_id'    => $team,
            'entry_id'   => $entry,
            'entry'      => CompetitionEntry::query()->findOrFail($entry),
        ];
    }

    return [
        'track'      => CompetitionTrack::query()->findOrFail($trackId),
        'event_id'   => (int) $event['id'],
        'edition_id' => $edition,
        'entries'    => $entries,
    ];
}

function g3ResultDraft(CompetitionTrack $track, CompetitionEntry $entry, int $adminId, string $speed, string $damage): CompetitionResult
{
    return app(CompetitionResultService::class)->saveDraft(
        $track,
        $entry,
        null,
        ['speed' => $speed, 'damage' => $damage],
        'none',
        0,
        $adminId,
    );
}

it('G3-08 · 草稿→排名→暂定→终审，公开结果只返回已公布版本', function () {
    $ctx = g3ResultContext();
    $service = app(CompetitionResultService::class);
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();

    $resultA = g3ResultDraft($ctx['track'], $ctx['entries'][0]['entry'], $recorder, '80', '20');
    $resultB = g3ResultDraft($ctx['track'], $ctx['entries'][1]['entry'], $recorder, '70', '19');

    $resultA = $service->submit($resultA, (int) $resultA->row_version, $recorder);
    $resultB = $service->submit($resultB, (int) $resultB->row_version, $recorder);

    $preview = $service->previewRanking($ctx['track']);
    expect($preview)->toHaveCount(2);

    $service->applyRanking($ctx['track'], $preview, $reviewer);

    $published = $service->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $reviewer);
    expect($published['published'])->toBe(2);

    $public = $service->publicResults($ctx['event_id']);
    $rows = $public[0]['results'];
    expect($rows)->toHaveCount(2);
    expect($rows[0]['total'])->toBe('100.00');
    expect($rows[0]['rank_no'])->toBe(1);
    expect($rows[0]['status'])->toBe('provisional');
    expect($rows[0]['appeal_deadline_at'])->not->toBeNull();
    expect($rows[1]['rank_no'])->toBe(2);

    $finalized = $service->finalize($ctx['track'], (int) $ctx['track']->row_version, $reviewer);
    expect($finalized['finalized'])->toBe(2);

    $public = $service->publicResults($ctx['event_id']);
    expect($public[0]['results'][0]['status'])->toBe('final');

    // 暂定与终审各产生一条 outbox 事实（未接渠道，只代表事实被消费）。
    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'competition_result')
        ->count())->toBe(4);
});

it('G3-08 · 录分与发布分权；未确定名次不能发布；期望版本冲突可见', function () {
    $ctx = g3ResultContext(1);
    $service = app(CompetitionResultService::class);
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();

    $result = g3ResultDraft($ctx['track'], $ctx['entries'][0]['entry'], $recorder, '50', '50');
    $result = $service->submit($result, (int) $result->row_version, $recorder);

    // 同一人录分又发布 → 拒绝。
    expect(fn () => $service->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $recorder))
        ->toThrow(CompetitionException::class);

    // 未排名 → 拒绝。
    expect(fn () => $service->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $reviewer))
        ->toThrow(CompetitionException::class);

    // 人工排名后发布成功。
    $service->applyRanking($ctx['track'], [
        ['entry_id' => $ctx['entries'][0]['entry_id'], 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);

    // 旧期望版本 → 冲突可见。
    expect(fn () => $service->publishProvisional($ctx['track'], 999, $reviewer))
        ->toThrow(CompetitionException::class);

    $service->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $reviewer);

    // 已提交版本不可直接改。
    expect(fn () => $service->submit(CompetitionResult::query()->findOrFail($result->id), 0, $recorder))
        ->toThrow(CompetitionException::class);
});

it('G3-08 · 修订保留旧版本并新建草稿，旧公布版本仍可回查', function () {
    $ctx = g3ResultContext(1);
    $service = app(CompetitionResultService::class);
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();

    $result = g3ResultDraft($ctx['track'], $ctx['entries'][0]['entry'], $recorder, '60', '20');
    $result = $service->submit($result, (int) $result->row_version, $recorder);
    $service->applyRanking($ctx['track'], [
        ['entry_id' => $ctx['entries'][0]['entry_id'], 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);
    $service->publishProvisional($ctx['track'], (int) $ctx['track']->row_version, $reviewer);

    $oldVersionId = (int) $result->fresh()->published_version_id;

    expect(fn () => $service->revise($result->fresh(), (int) $result->fresh()->row_version, '', $reviewer))
        ->toThrow(CompetitionException::class);

    $revised = $service->revise($result->fresh(), (int) $result->fresh()->row_version, '计时器故障，重赛改分', $reviewer);

    expect((string) $revised->status)->toBe(CompetitionResult::STATUS_REVISING);
    expect((int) $revised->published_version_id)->toBe($oldVersionId);

    $old = CompetitionResultVersion::query()->findOrFail($oldVersionId);
    expect((string) $old->status)->toBe(CompetitionResultVersion::STATUS_SUPERSEDED);

    $new = CompetitionResultVersion::query()->findOrFail($revised->current_version_id);
    expect((int) $new->supersedes_version_id)->toBe($oldVersionId);
    expect((string) $new->status)->toBe(CompetitionResultVersion::STATUS_DRAFT);

    // 公开仍返回旧版本，但明确标记复核中，不让草稿冒充结论。
    $public = $service->publicResults($ctx['event_id']);
    expect($public[0]['results'][0]['under_review'])->toBeTrue();
    expect($public[0]['results'][0]['status'])->toBe('provisional');

    // 保存修订草稿后，主体仍保持 revising，旧公布版本不会从公开面消失。
    $revised = $service->saveDraft(
        $ctx['track'],
        CompetitionEntry::query()->findOrFail($ctx['entries'][0]['entry_id']),
        null,
        ['speed' => '70', 'damage' => '10'],
        'none',
        (int) $revised->row_version,
        $reviewer,
        '重赛草稿',
    );
    expect((string) $revised->status)->toBe(CompetitionResult::STATUS_REVISING);

    $public = $service->publicResults($ctx['event_id']);
    expect($public[0]['results'])->toHaveCount(1);
    expect($public[0]['results'][0]['under_review'])->toBeTrue();
    expect($public[0]['results'][0]['status'])->toBe('provisional');
});
