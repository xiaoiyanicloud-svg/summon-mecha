<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionAwardVersion;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ecosystem\CompetitionAppealService;
use Webkul\Shop\Services\Ecosystem\CompetitionAwardService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\CompetitionScheduleService;
use Webkul\Shop\Services\Ecosystem\CompetitionSeriesService;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;
use Webkul\Shop\Services\Ecosystem\CompetitionSubmissionService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamRegistrationService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamService;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-12 · 一届含异常路径的受控赛事演练（T59–T69 / T72）。
 *
 * 本测试把整条链路串起来，而不是逐模块自证：
 *   规则签署 → 建队/邀请/接受 → 队伍报名/参赛快照 → 作品 → 赛程导入/发布
 *   → 录分/排名/暂定/终审 → 申诉/受理/裁决 → 改分重赛 → 奖项待复核/重新发布
 *   → 展示授权/发布 → 技能提审交接。
 *
 * 覆盖用例：
 *   T59 队伍权限与成员接受状态
 *   T60 赛项隔离与参赛快照冻结
 *   T61 作品提交/版本/私有材料
 *   T62 赛程导入/发布
 *   T63 精度/弃权/同分策略
 *   T64 录分与复核发布分权
 *   T65 暂定成绩与申诉窗口/主体
 *   T66 申诉证据与越权裁决（异常路径）
 *   T67 改分/终审与旧公布版本
 *   T68 改分后旧奖项/展示的版本关联
 *   T69 获奖作品授权与技能提审交接
 *   T72 整届异常演练可回查
 *
 * 不宣称真机比赛验收、不宣称生产已开放、不把 mock 提审当作真实技能审核通过。
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
        'ecosystem_competition.public_results_enabled' => true,
        'ecosystem_competition.public_showcase_enabled' => true,
        'ecosystem.events.public_enabled' => true,
    ]);
});

function g3drillProfile(): array
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

it('G3-12 · 整届演练：规则→队伍→作品→赛程→成绩→申诉→改分→奖项→展示→提审', function () {
    // ── 1. 规则签署与赛项激活（T63 规则完整性） ──
    $seriesAdmin = Fixtures::adminId();
    $event = Fixtures::event();
    $ruleVersion = Fixtures::ruleVersion($event['id'], $seriesAdmin);
    $profile = g3drillProfile();

    $seriesService = app(CompetitionSeriesService::class);
    $series = $seriesService->createSeries([
        'name' => '整届演练系列', 'slug' => 'g3-drill-'.bin2hex(random_bytes(3)), 'organizer_name' => '召唤机甲',
    ], $seriesAdmin);
    $edition = $seriesService->createEdition($series, Event::query()->findOrFail($event['id']), [
        'edition_no' => 1, 'name' => '2026 演练届', 'timezone' => 'Asia/Shanghai',
    ], $seriesAdmin);

    $now = now('UTC');
    $track = $seriesService->createTrack($edition, [
        'track_key' => 'robowar',
        'name' => '机甲对战',
        'discipline' => '对抗',
        'submission_deadline_at' => $now->copy()->addDays(5)->format('Y-m-d H:i:s'),
        'rules_version_id' => (int) $ruleVersion->getKey(),
        'rules_profile' => $profile,
        'confirm_rules' => true,
        'scope' => [
            'capacity' => 8,
            'confirm_capacity' => true,
            'review_mode' => 'auto',
            'registration_closes_at' => $now->copy()->addDay()->format('Y-m-d H:i:s'),
            'privacy_version' => '2026-10-19',
            'privacy_summary' => '演练同意摘要',
        ],
    ], $seriesAdmin);

    $track = $seriesService->activate($track, (int) $track->row_version, $seriesAdmin);
    expect((string) $track->status)->toBe('active');
    expect($track->rules_fingerprint)->toHaveLength(64);

    $scope = \Webkul\Shop\Models\EventRegistrationScope::query()->findOrFail($track->registration_scope_id);
    $scope = app(RegistrationScopeService::class)->activate($scope, (int) $scope->row_version, $seriesAdmin);
    expect((string) $scope->status)->toBe('open');

    // ── 2. 建队 / 邀请 / 接受 / 队伍报名 / 参赛快照（T59 / T60） ──
    $captainId = Fixtures::customer('演练队长');
    $memberId = Fixtures::customer('演练队员');
    $captain = Customer::query()->findOrFail($captainId);
    $member = Customer::query()->findOrFail($memberId);

    $teamService = app(CompetitionTeamService::class);
    $team = $teamService->createTeam($captain, $track, '演练机甲队');
    $invite = $teamService->issueInvite($team, $captain, (int) $team->row_version);
    $teamService->acceptInvite($member, (string) $invite['token']);

    $registration = app(CompetitionTeamRegistrationService::class)->submit(
        Event::query()->findOrFail($event['id']),
        $track,
        $team->fresh(),
        $captain,
        [
            'request_key' => 'g3-drill-'.bin2hex(random_bytes(4)),
            'form_version' => (int) $scope->form_version,
            'privacy_version' => (string) $scope->privacy_version,
            'rules_version_id' => (int) $track->rules_version_id,
            'consent' => true,
            'name' => '演练联系人',
            'phone' => '13800001111',
            'city' => '上海',
            'robot' => ['model' => 'DR-01', 'asset_no' => 'DRILL-0001'],
        ],
    );

    $entry = $registration['entry'];
    expect((string) $registration['attempt']->status)->toBe('confirmed');
    expect((string) $entry->status)->toBe(CompetitionEntry::STATUS_CONFIRMED);
    expect((int) $entry->rules_version_id)->toBe((int) $track->rules_version_id);
    expect((string) $entry->rules_fingerprint)->toBe((string) $track->rules_fingerprint);
    expect(count((array) $entry->roster_snapshot))->toBe(2);

    // 快照冻结后换人必须被拒绝，且不回溯覆盖快照（T60 异常路径）。
    expect(fn () => $teamService->transferCaptain(
        $team->fresh(),
        $captain,
        (int) $member->id,
        (int) $team->fresh()->row_version,
    ))->toThrow(CompetitionException::class);

    expect(count((array) CompetitionEntry::query()->findOrFail($entry->id)->roster_snapshot))->toBe(2);

    // ── 3. 作品提交（T61） ──
    $captain = Customer::query()->findOrFail($captainId);
    $member = Customer::query()->findOrFail($memberId);
    $team = CompetitionTeam::query()->findOrFail($team->id);
    // 队员不能提交作品（T59 异常路径）；队长可以。
    $submissionService = app(CompetitionSubmissionService::class);
    expect(fn () => $submissionService->saveDraft($team, $entry, $member, ['title' => '队员越权']))
        ->toThrow(CompetitionException::class);

    $draft = $submissionService->saveDraft($team, $entry, $captain, [
        'title' => '演练作品 v1',
        'summary' => '循迹与避障',
        'description' => '演练作品说明',
        'repo_url' => 'https://example.com/drill-repo',
    ]);
    $submittedVersion = $submissionService->submit($team, $entry, $captain);

    // ── 4. 赛程 CSV 导入与发布（T62） ──
    $csv = "stage_key,stage_name,stage_order,match_key,round_no,starts_at,ends_at,venue,home_entry_no,away_entry_no\n"
        ."final,决赛,1,drill-match-1,1,2026-11-01T09:00:00+08:00,2026-11-01T10:00:00+08:00,A 场地,{$entry->entry_no},\n";
    $file = Fixtures::uploadedCsv($csv);
    $scheduleService = app(CompetitionScheduleService::class);
    $preview = $scheduleService->preview($track, $file);
    expect($preview['summary']['valid'])->toBe(1);

    $import = $scheduleService->import($track, Fixtures::uploadedCsv($csv), $preview['preview_hash'], $seriesAdmin);
    expect($import['summary']['creates'])->toBe(1);

    $stage = \Webkul\Shop\Models\CompetitionStage::query()->where('track_id', $track->id)->firstOrFail();
    $scheduleService->publishStage($track, $stage, (int) $stage->row_version, $seriesAdmin);

    // ── 5. 录分 / 排名 / 暂定 / 终审（T63 / T64） ──
    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();
    $resultService = app(CompetitionResultService::class);

    $result = $resultService->saveDraft(
        $track,
        $entry->fresh(),
        null,
        ['speed' => '88.50', 'damage' => '10.25'],
        'none',
        0,
        $recorder,
    );
    expect((string) $result->currentVersion->total_scaled)->toBe('9875');

    $result = $resultService->submit($result, (int) $result->row_version, $recorder);

    // 同一人录分又发布 → 拒绝（T64）。
    expect(fn () => $resultService->publishProvisional($track, (int) $track->row_version, $recorder))
        ->toThrow(CompetitionException::class);

    $resultService->applyRanking($track, [
        ['entry_id' => (int) $entry->id, 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);
    $resultService->publishProvisional($track, (int) $track->row_version, $reviewer);
    $resultService->finalize($track, (int) $track->row_version, $reviewer);

    $result = CompetitionResult::query()->findOrFail($result->id);
    $oldPublishedVersionId = (int) $result->published_version_id;
    expect((string) $result->status)->toBe(CompetitionResult::STATUS_FINAL);

    // ── 6. 申诉 / 受理 / 裁决（T65 / T66） ──
    $appealService = app(CompetitionAppealService::class);
    $appealAdmin = Fixtures::adminId();

    // 队员不是合法申诉主体（T65 异常路径）。
    expect(fn () => $appealService->submit(
        (int) $result->id,
        $oldPublishedVersionId,
        $member,
        '队员不能代表队伍提交申诉。',
    ))->toThrow(CompetitionException::class);

    $appeal = $appealService->submit(
        (int) $result->id,
        $oldPublishedVersionId,
        $captain,
        '最后一圈计时器读数异常，申请复核原始记录。',
    );
    expect((string) $appeal->status)->toBe(CompetitionAppeal::STATUS_SUBMITTED);

    // 非法证据类型被拒绝（T66 异常路径）。
    $bad = tempnam(sys_get_temp_dir(), 'g3drill_');
    file_put_contents($bad, "PK\x03\x04not-a-real-zip");
    expect(fn () => $appealService->addEvidence(
        $appeal,
        $captain,
        new \Illuminate\Http\UploadedFile($bad, 'bad.zip', 'application/zip', null, true),
    ))->toThrow(CompetitionException::class);

    $appeal = $appealService->accept($appeal, $appealAdmin, (int) $appeal->row_version, '受理并调取录像');
    $appeal = $appealService->decide($appeal, $appealAdmin, (int) $appeal->row_version, [
        'decision_result' => 'upheld',
        'decision_reason' => '复核原始计时记录后确认异常，同意改分重赛。',
        'decision_result_version_id' => $oldPublishedVersionId,
    ]);
    expect((string) $appeal->status)->toBe(CompetitionAppeal::STATUS_DECIDED);
    expect($appeal->events()->count())->toBe(3);

    // ── 7. 改分重赛 / 终审 / 旧版本保留（T67） ──
    $revised = $resultService->revise($result, (int) $result->row_version, '申诉成立，重赛改分', $reviewer);
    expect((string) $revised->status)->toBe(CompetitionResult::STATUS_REVISING);
    expect((string) CompetitionResultVersion::query()->findOrFail($oldPublishedVersionId)->status)
        ->toBe(CompetitionResultVersion::STATUS_SUPERSEDED);

    $publisher = Fixtures::adminId();
    $revised = $resultService->saveDraft(
        $track,
        $entry->fresh(),
        null,
        ['speed' => '92.00', 'damage' => '11.00'],
        'none',
        (int) $revised->row_version,
        $reviewer,
        '重赛成绩',
    );
    $revised = $resultService->submit($revised, (int) $revised->row_version, $reviewer);
    $resultService->applyRanking($track, [
        ['entry_id' => (int) $entry->id, 'rank_no' => 1, 'tie_group' => null],
    ], $publisher);
    $resultService->publishProvisional($track, (int) $track->row_version, $publisher);
    $resultService->finalize($track, (int) $track->row_version, $publisher);

    $newPublishedVersionId = (int) CompetitionResult::query()->findOrFail($result->id)->published_version_id;
    expect($newPublishedVersionId)->not->toBe($oldPublishedVersionId);

    // ── 8. 奖项版本：旧奖项待复核，新奖项指向新结论（T68） ──
    $awardService = app(CompetitionAwardService::class);
    $awardAdmin = Fixtures::adminId();

    // 先创建一个引用新成绩的奖项并发布。
    $award = $awardService->createVersion($track, [
        'award_key' => 'champion',
        'name' => '冠军',
        'entry_id' => (int) $entry->id,
    ], $awardAdmin);
    $awardVersionId = (int) CompetitionAwardVersion::query()->where('award_id', $award->id)->value('id');
    $award = $awardService->publish($award->fresh(), $awardVersionId, 0, $awardAdmin);
    expect((int) CompetitionAwardVersion::query()->findOrFail($awardVersionId)->result_version_id)->toBe($newPublishedVersionId);

    // 旧成绩版本上的已发布奖项（模拟上一版公布）在改分时应转待复核。
    // 这里用旧版本再建一个历史奖项版本，验证联动逻辑。
    $historicalAward = new CompetitionAwardVersion();
    $historicalAward->fill([
        'award_id' => (int) $award->id,
        'version_no' => 2,
        'entry_id' => (int) $entry->id,
        'result_version_id' => $oldPublishedVersionId,
        'rank_no' => 1,
        'status' => CompetitionAwardVersion::STATUS_PUBLISHED,
        'snapshot' => ['entry_no' => (string) $entry->entry_no],
        'created_by_admin_id' => $awardAdmin,
        'row_version' => 0,
    ]);
    $historicalAward->save();
    $awardService->markPendingForResultVersion($oldPublishedVersionId, $awardAdmin);
    expect((string) $historicalAward->fresh()->status)->toBe(CompetitionAwardVersion::STATUS_PENDING_REVIEW);

    // ── 9. 展示授权与发布（T68 / T69） ──
    $showcaseService = app(CompetitionShowcaseService::class);
    $showcaseAdmin = Fixtures::adminId();

    $showcase = $showcaseService->createDraft($track, [
        'showcase_type' => CompetitionShowcase::TYPE_AWARD,
        'title' => '演练冠军作品',
        'summary' => '展示改分重赛后的冠军作品与完整复盘。',
        'entry_id' => (int) $entry->id,
        'submission_version_id' => (int) $submittedVersion->id,
        'award_id' => (int) $award->id,
    ], $showcaseAdmin);

    // 未授权不能审核（T69 异常路径）。
    expect(fn () => $showcaseService->approve($showcase, (int) $showcase->row_version, $showcaseAdmin))
        ->toThrow(CompetitionException::class);

    $showcase = $showcaseService->setConsent($showcase, $captain, true, (int) $showcase->row_version);
    $showcase = $showcaseService->approve($showcase, (int) $showcase->row_version, $showcaseAdmin);
    $showcase = $showcaseService->publish($showcase, (int) $showcase->row_version, $showcaseAdmin);
    expect($showcaseService->consentIsValid($showcase->fresh()))->toBeTrue();

    // ── 10. 技能提审交接：无开发者身份拒绝 → 有身份走既有闸门（T69） ──
    expect(fn () => $showcaseService->requestSkillReview($showcase, $captain, 1))
        ->toThrow(CompetitionException::class);

    DB::table('developer_profile')->insert([
        'customer_id' => (int) $captain->id,
        'seller_id' => null,
        'dev_type' => 'personal',
        'realname_status' => 'approved',
        'status' => 'active',
        'agreement_signed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mock = \Mockery::mock(DeveloperSkillService::class);
    $mock->shouldReceive('findOwnedVersion')->once()->andReturn((new SkillVersion())->forceFill(['id' => 424242]));
    $mock->shouldReceive('submitForReview')->once()->andReturn([
        'ok' => true, 'code' => 'SUBMITTED', 'message' => '已提交审核（演练 mock，不代表真实审核通过）。',
    ]);
    app()->instance(DeveloperSkillService::class, $mock);

    $handoff = app(CompetitionShowcaseService::class)->requestSkillReview($showcase, $captain, 424242);
    expect($handoff['ok'])->toBeTrue();
    expect((int) CompetitionShowcase::query()->findOrFail($showcase->id)->skill_version_id)->toBe(424242);

    // ── 11. 可回查与公开面（T72） ──
    expect($resultService->publicResults((int) $event['id'])[0]['results'][0]['rank_no'])->toBe(1);
    expect($awardService->publicAwards((int) $event['id'])['robowar'])->toHaveCount(1);
    expect($showcaseService->publicShowcases((int) $event['id'])['robowar'])->toHaveCount(1);

    $actions = DB::table('operation_log')->pluck('action')->unique()->values()->all();

    foreach ([
        'competition.track.activated',
        'competition.team.created',
        'competition.team.invite_issued',
        'competition.submission.submitted',
        'competition.schedule.imported',
        'competition.schedule.stage_published',
        'competition.result.draft_saved',
        'competition.result.provisional_published',
        'competition.result.finalized',
        'competition.appeal.submitted',
        'competition.appeal.accepted',
        'competition.appeal.decided',
        'competition.result.revised',
        'competition.award.pending_review',
        'competition.award.published',
        'competition.showcase.created',
        'competition.showcase.consent_granted',
        'competition.showcase.approved',
        'competition.showcase.published',
        'competition.showcase.skill_review_requested',
    ] as $action) {
        expect($actions)->toContain($action);
    }

    // 历史成绩与版本不删除：旧公布版本仍在且标 superseded。
    expect(CompetitionResultVersion::query()->find($oldPublishedVersionId))->not->toBeNull();
});
