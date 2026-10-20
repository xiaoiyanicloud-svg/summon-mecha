<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionMatch;
use Webkul\Shop\Models\CompetitionStage;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionScheduleService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G3-06 · CSV 预览导入、冲突定位、幂等与发布门禁。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.public_schedule_enabled' => true,
        'ecosystem.events.public_enabled' => true,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);
});

function g3ScheduleContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version);

    $captainA = Fixtures::customer('A 队队长');
    $teamA = Fixtures::team($edition, $trackId, $captainA);
    $entryA = Fixtures::entry($edition, $trackId, $teamA, $captainA, ['status' => 'confirmed']);

    $captainB = Fixtures::customer('B 队队长');
    $teamB = Fixtures::team($edition, $trackId, $captainB);
    $entryB = Fixtures::entry($edition, $trackId, $teamB, $captainB, ['status' => 'confirmed']);

    return [
        'admin' => $admin,
        'event_id' => $event['id'],
        'edition_id' => $edition,
        'track' => CompetitionTrack::query()->findOrFail($trackId),
        'entry_a' => DB::table('competition_entries')->where('id', $entryA)->value('entry_no'),
        'entry_b' => DB::table('competition_entries')->where('id', $entryB)->value('entry_no'),
    ];
}

function g3Csv(array $rows): string
{
    $header = 'stage_key,stage_name,stage_order,match_key,round_no,starts_at,ends_at,venue,home_entry_no,away_entry_no';
    $lines = [$header];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn ($value): string => (string) $value, $row));
    }

    return implode("\n", $lines)."\n";
}

function g3Row(array $overrides = []): array
{
    $row = [
        0 => 'group',
        1 => '小组赛',
        2 => 1,
        3 => 'm-001',
        4 => 1,
        5 => '2026-11-01T09:00:00+08:00',
        6 => '2026-11-01T10:00:00+08:00',
        7 => 'A 场地',
        8 => '',
        9 => '',
    ];

    foreach ($overrides as $index => $value) {
        $row[$index] = $value;
    }

    return $row;
}

it('G3-06 · 预览不写库；导入后重试不重复；阶段版本不空转', function () {
    $ctx = g3ScheduleContext();
    $service = app(CompetitionScheduleService::class);
    $csv = g3Csv([
        g3Row([8 => $ctx['entry_a'], 9 => $ctx['entry_b']]),
        g3Row([3 => 'm-002', 4 => 2, 5 => '2026-11-01T11:00:00+08:00', 6 => '2026-11-01T12:00:00+08:00', 8 => $ctx['entry_b'], 9 => $ctx['entry_a']]),
    ]);

    $preview = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    expect($preview['summary'])->toMatchArray(['total' => 2, 'valid' => 2, 'errors' => 0, 'creates' => 2]);
    expect(CompetitionStage::query()->count())->toBe(0);
    expect(CompetitionMatch::query()->count())->toBe(0);

    $imported = $service->import($ctx['track'], Fixtures::uploadedCsv($csv), $preview['preview_hash'], $ctx['admin']);
    expect($imported['summary']['creates'])->toBe(2);
    expect(CompetitionStage::query()->where('track_id', $ctx['track']->id)->count())->toBe(1);
    expect(CompetitionMatch::query()->where('track_id', $ctx['track']->id)->count())->toBe(2);

    $stageVersion = (int) CompetitionStage::query()->where('track_id', $ctx['track']->id)->value('row_version');

    $retry = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    expect($retry['summary'])->toMatchArray(['valid' => 2, 'skips' => 2, 'creates' => 0, 'updates' => 0]);

    $service->import($ctx['track'], Fixtures::uploadedCsv($csv), $retry['preview_hash'], $ctx['admin']);
    expect(CompetitionMatch::query()->where('track_id', $ctx['track']->id)->count())->toBe(2);
    expect((int) CompetitionStage::query()->where('track_id', $ctx['track']->id)->value('row_version'))->toBe($stageVersion);
});

it('G3-06 · 文件与预览不一致拒绝；同场次不同载荷更新草稿', function () {
    $ctx = g3ScheduleContext();
    $service = app(CompetitionScheduleService::class);
    $csv = g3Csv([g3Row([8 => $ctx['entry_a'], 9 => $ctx['entry_b']])]);

    $preview = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    $service->import($ctx['track'], Fixtures::uploadedCsv($csv), $preview['preview_hash'], $ctx['admin']);

    $changed = g3Csv([g3Row([5 => '2026-11-01T13:00:00+08:00', 6 => '2026-11-01T14:00:00+08:00', 8 => $ctx['entry_a'], 9 => $ctx['entry_b']])]);
    $preview2 = $service->preview($ctx['track'], Fixtures::uploadedCsv($changed));
    expect($preview2['summary']['updates'])->toBe(1);

    $service->import($ctx['track'], Fixtures::uploadedCsv($changed), $preview2['preview_hash'], $ctx['admin']);
    expect((int) CompetitionMatch::query()->where('match_key', 'm-001')->value('row_version'))->toBe(1);

    expect(fn () => $service->import(
        $ctx['track'],
        Fixtures::uploadedCsv($changed.' '),
        $preview2['preview_hash'],
        $ctx['admin'],
    ))->toThrow(CompetitionException::class);
});

it('G3-06 · 不存在/跨赛项/重复 match_key/时间冲突逐行定位且不导入', function () {
    $ctx = g3ScheduleContext();
    $service = app(CompetitionScheduleService::class);

    $otherVersion = Fixtures::ruleVersion($ctx['event_id'], $ctx['admin'], [
        'version_no' => 2,
        'content_fingerprint' => str_repeat('d', 64),
    ]);
    $otherScope = Fixtures::scope($ctx['event_id'], 'other-track');
    $otherTrack = Fixtures::track($ctx['edition_id'], $otherScope, $otherVersion, ['track_key' => 'other']);
    $otherCaptain = Fixtures::customer('外赛项队长');
    $otherTeam = Fixtures::team($ctx['edition_id'], $otherTrack, $otherCaptain);
    $otherEntry = Fixtures::entry($ctx['edition_id'], $otherTrack, $otherTeam, $otherCaptain);
    $otherEntryNo = DB::table('competition_entries')->where('id', $otherEntry)->value('entry_no');

    $csv = g3Csv([
        g3Row([3 => 'm-100', 8 => 'EN-DOES-NOT-EXIST', 9 => '']),
        g3Row([3 => 'm-101', 5 => '2026-11-01T11:00:00+08:00', 6 => '2026-11-01T12:00:00+08:00', 8 => $otherEntryNo, 9 => $ctx['entry_a']]),
        g3Row([3 => 'm-102', 5 => '2026-11-01T13:00:00+08:00', 6 => '2026-11-01T14:00:00+08:00', 8 => $ctx['entry_a'], 9 => $ctx['entry_b']]),
        g3Row([3 => 'm-102', 5 => '2026-11-01T15:00:00+08:00', 6 => '2026-11-01T16:00:00+08:00', 8 => $ctx['entry_b'], 9 => '']),
        g3Row([3 => 'm-103', 5 => '2026-11-01T13:30:00+08:00', 6 => '2026-11-01T14:30:00+08:00', 8 => $ctx['entry_a'], 9 => '']),
    ]);

    $preview = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    expect($preview['summary']['errors'])->toBeGreaterThanOrEqual(4);

    $errors = collect($preview['rows'])->flatMap(fn (array $row) => $row['errors'])->implode('|');
    expect($errors)->toContain('不存在');
    expect($errors)->toContain('属于其它赛项');
    expect($errors)->toContain('重复');
    expect($errors)->toContain('时间冲突');

    expect(fn () => $service->import(
        $ctx['track'],
        Fixtures::uploadedCsv($csv),
        $preview['preview_hash'],
        $ctx['admin'],
    ))->toThrow(CompetitionException::class);

    expect(CompetitionMatch::query()->count())->toBe(0);
});

it('G3-06 · 发布前公开为空，发布后只返回已发布阶段/场次；无确认 entry 不能发布', function () {
    $ctx = g3ScheduleContext();
    $service = app(CompetitionScheduleService::class);
    $csv = g3Csv([
        g3Row([8 => $ctx['entry_a'], 9 => $ctx['entry_b']]),
    ]);

    $preview = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    $service->import($ctx['track'], Fixtures::uploadedCsv($csv), $preview['preview_hash'], $ctx['admin']);

    $before = $service->publicSchedule($ctx['event_id']);
    expect($before)->toHaveCount(1);
    expect($before[0]['stages'])->toBe([]);

    $stage = CompetitionStage::query()->where('track_id', $ctx['track']->id)->firstOrFail();
    $service->publishStage($ctx['track'], $stage, (int) $stage->row_version, $ctx['admin']);

    $public = $service->publicSchedule($ctx['event_id']);
    expect($public)->toHaveCount(1);
    expect($public[0]['stages'])->toHaveCount(1);
    expect($public[0]['stages'][0]['matches'])->toHaveCount(1);

    // 已发布阶段不允许通过导入覆盖。
    $previewAgain = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    expect($previewAgain['summary']['errors'])->toBeGreaterThan(0);
    expect(collect($previewAgain['rows'])->flatMap(fn (array $row) => $row['errors'])->implode('|'))
        ->toContain('已发布');
});

it('G3-06 · 公开赛程接口在开关关闭时不查库并返回 404', function () {
    $ctx = g3ScheduleContext();
    config(['ecosystem_competition.public_schedule_enabled' => false]);

    getJson('/api/events/'.$ctx['event_id'].'/competition/schedule')
        ->assertStatus(404)
        ->assertJsonPath('code', 'COMPETITION_NOT_FOUND');
});

it('G3-06 · 公开赛程接口只输出已发布内容', function () {
    $ctx = g3ScheduleContext();
    $service = app(CompetitionScheduleService::class);
    $csv = g3Csv([g3Row([8 => $ctx['entry_a'], 9 => $ctx['entry_b']])]);

    $preview = $service->preview($ctx['track'], Fixtures::uploadedCsv($csv));
    $service->import($ctx['track'], Fixtures::uploadedCsv($csv), $preview['preview_hash'], $ctx['admin']);

    getJson('/api/events/'.$ctx['event_id'].'/competition/schedule')
        ->assertOk()
        ->assertJsonPath('data.schedule.0.stages', []);

    $stage = CompetitionStage::query()->where('track_id', $ctx['track']->id)->firstOrFail();
    $service->publishStage($ctx['track'], $stage, (int) $stage->row_version, $ctx['admin']);

    $response = getJson('/api/events/'.$ctx['event_id'].'/competition/schedule')->assertOk();
    expect($response->json('data.schedule.0.stages.0.matches.0.match_key'))->toBe('m-001');
});
