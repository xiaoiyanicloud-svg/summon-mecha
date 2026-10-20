<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAppealService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-09 · 申诉窗口/主体/证据/受理/裁决。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.appeal.disk' => 'local',
    ]);

    Storage::fake('local');
});

function g3AppealProfile(): array
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

function g3AppealContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3AppealProfile());

    $captainId = Fixtures::customer('申诉队长');
    $memberId = Fixtures::customer('申诉队员');
    $outsiderId = Fixtures::customer('路人');
    $teamId = Fixtures::team($edition, $trackId, $captainId);
    Fixtures::member($teamId, $memberId);

    $entryId = Fixtures::entry($edition, $trackId, $teamId, $captainId, [
        'rules_version_id' => (int) $version->getKey(),
        'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
    ]);

    $track = CompetitionTrack::query()->findOrFail($trackId);
    $entry = CompetitionEntry::query()->findOrFail($entryId);
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();
    $recorded = app(CompetitionResultService::class)->saveDraft(
        $track,
        $entry,
        null,
        ['speed' => '88.5'],
        'none',
        0,
        $recorder,
    );
    $recorded = app(CompetitionResultService::class)->submit($recorded, (int) $recorded->row_version, $recorder);
    app(CompetitionResultService::class)->applyRanking($track, [
        ['entry_id' => $entryId, 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);
    app(CompetitionResultService::class)->publishProvisional($track, (int) $track->row_version, $reviewer);

    $result = CompetitionResult::query()->findOrFail($recorded->id);

    return [
        'track'    => $track,
        'result'   => $result,
        'captain'  => Customer::query()->findOrFail($captainId),
        'member'   => Customer::query()->findOrFail($memberId),
        'outsider' => Customer::query()->findOrFail($outsiderId),
    ];
}

it('G3-09 · 只有队长能在窗口内对当前公布版本提交申诉', function () {
    $ctx = g3AppealContext();
    $service = app(CompetitionAppealService::class);

    expect(fn () => $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['member'],
        '我是队员，不能代表队伍申诉。',
    ))->toThrow(CompetitionException::class);

    expect(fn () => $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['outsider'],
        '路人也不能提交申诉。',
    ))->toThrow(CompetitionException::class);

    expect(fn () => $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id - 1,
        $ctx['captain'],
        '绑定旧版本应被拒绝。',
    ))->toThrow(CompetitionException::class);

    $appeal = $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['captain'],
        '计时器在最后一圈疑似故障，申请复核成绩。',
    );

    expect($appeal->status)->toBe(CompetitionAppeal::STATUS_SUBMITTED);
    expect($appeal->deadline_at)->not->toBeNull();

    expect(fn () => $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['captain'],
        '重复提交同一版本申诉。',
    ))->toThrow(CompetitionException::class);

    // 窗口过期：把另一个赛事的公布时间调到两天前。
    $another = g3AppealContext();
    DB::table('competition_result_versions')
        ->where('id', $another['result']->published_version_id)
        ->update(['published_at' => now('UTC')->subDays(2)->format('Y-m-d H:i:s')]);

    expect(fn () => $service->submit(
        (int) $another['result']->id,
        (int) $another['result']->published_version_id,
        $another['captain'],
        '窗口已经过期，不能再申诉。',
    ))->toThrow(CompetitionException::class);
});

it('G3-09 · 证据按规则类型校验，私有下载仅本人或裁决管理员', function () {
    $ctx = g3AppealContext();
    $service = app(CompetitionAppealService::class);

    $appeal = $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['captain'],
        '需要补充现场录像截图作为证据。',
    );

    $png = tempnam(sys_get_temp_dir(), 'g3png_');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $attachment = $service->addEvidence(
        $appeal,
        $ctx['captain'],
        new UploadedFile($png, 'shot.png', 'image/png', null, true),
    );

    expect($attachment->mime)->toBe('image/png');
    expect($attachment->storage_key)->not->toContain('..');

    $file = $service->openEvidenceForCustomer($ctx['captain'], (int) $attachment->id);
    expect($file['size'])->toBeGreaterThan(0);
    fclose($file['stream']);

    expect(fn () => $service->openEvidenceForCustomer($ctx['outsider'], (int) $attachment->id))
        ->toThrow(CompetitionException::class);

    $zip = tempnam(sys_get_temp_dir(), 'g3zip_');
    file_put_contents($zip, "PK\x03\x04not-really-a-zip");

    expect(fn () => $service->addEvidence(
        $appeal,
        $ctx['captain'],
        new UploadedFile($zip, 'evidence.zip', 'application/zip', null, true),
    ))->toThrow(CompetitionException::class);
});

it('G3-09 · 受理→裁决记录理由与适用版本；旧版本变化拒绝裁决', function () {
    $ctx = g3AppealContext();
    $service = app(CompetitionAppealService::class);
    $admin = Fixtures::adminId();

    $appeal = $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['captain'],
        '对最终名次有异议，申请复核计分。',
    );

    expect(fn () => $service->decide($appeal, $admin, (int) $appeal->row_version, [
        'decision_result' => 'upheld',
        'decision_reason' => '未受理就裁决应被拒绝。',
        'decision_result_version_id' => (int) $ctx['result']->published_version_id,
    ]))->toThrow(CompetitionException::class);

    $appeal = $service->accept($appeal, $admin, (int) $appeal->row_version, '进入调查');

    expect(fn () => $service->decide($appeal, $admin, (int) $appeal->row_version, [
        'decision_result' => 'upheld',
        'decision_reason' => '版本已变化，应重新核对。',
        'decision_result_version_id' => 999,
    ]))->toThrow(CompetitionException::class);

    $decided = $service->decide($appeal, $admin, (int) $appeal->row_version, [
        'decision_result' => 'upheld',
        'decision_reason' => '复核录像后确认计时异常，成绩修订。',
        'decision_result_version_id' => (int) $ctx['result']->published_version_id,
    ]);

    expect($decided->status)->toBe(CompetitionAppeal::STATUS_DECIDED);
    expect($decided->decision_result)->toBe('upheld');
    expect((int) $decided->decision_result_version_id)->toBe((int) $ctx['result']->published_version_id);

    // 时间线保留提交/受理/裁决三条。
    expect($decided->events()->count())->toBe(3);

    // 裁决通知写入 outbox。
    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'competition_appeal')
        ->where('event_type', 'competition_appeal.decided')
        ->count())->toBe(1);
});

it('G3-09 · 客户可撤回未终结的申诉，撤回后不能再裁决', function () {
    $ctx = g3AppealContext();
    $service = app(CompetitionAppealService::class);
    $admin = Fixtures::adminId();

    $appeal = $service->submit(
        (int) $ctx['result']->id,
        (int) $ctx['result']->published_version_id,
        $ctx['captain'],
        '先提交，随后与主办方沟通后撤回。',
    );

    $withdrawn = $service->withdraw($appeal, $ctx['captain'], (int) $appeal->row_version, '已线下沟通');

    expect($withdrawn->status)->toBe(CompetitionAppeal::STATUS_WITHDRAWN);
    expect(fn () => $service->accept($withdrawn, $admin, (int) $withdrawn->row_version, '不应受理'))
        ->toThrow(CompetitionException::class);
});
