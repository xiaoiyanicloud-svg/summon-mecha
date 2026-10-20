<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ecosystem\CompetitionAwardService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;
use Webkul\Shop\Services\Ecosystem\CompetitionSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-11 · 展示授权、公开字段最小化、技能提审交接与“不自动上架/下发”。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['ecosystem_competition.write_enabled' => true]);
});

function g3ShowcaseProfile(): array
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

function g3ShowcaseContext(bool $withAward = true): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3ShowcaseProfile());
    $track = CompetitionTrack::query()->findOrFail($trackId);

    $captainId = Fixtures::customer('展示队长');
    $memberId = Fixtures::customer('展示队员');
    $teamId = Fixtures::team($edition, $trackId, $captainId);
    Fixtures::member($teamId, $memberId);
    $entryId = Fixtures::entry($edition, $trackId, $teamId, $captainId, [
        'rules_version_id' => (int) $version->getKey(),
        'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
    ]);

    $captain = Customer::query()->findOrFail($captainId);
    $team = CompetitionTeam::query()->findOrFail($teamId);
    $entry = CompetitionEntry::query()->findOrFail($entryId);

    $recorder = Fixtures::adminId();
    $reviewer = Fixtures::adminId();
    $result = app(CompetitionResultService::class)->saveDraft(
        $track,
        $entry,
        null,
        ['speed' => '77.5'],
        'none',
        0,
        $recorder,
    );
    $result = app(CompetitionResultService::class)->submit($result, (int) $result->row_version, $recorder);
    app(CompetitionResultService::class)->applyRanking($track, [
        ['entry_id' => $entryId, 'rank_no' => 1, 'tie_group' => null],
    ], $reviewer);
    app(CompetitionResultService::class)->publishProvisional($track, (int) $track->row_version, $reviewer);
    app(CompetitionResultService::class)->finalize($track, (int) $track->row_version, $reviewer);

    $awardId = null;

    if ($withAward) {
        $award = app(CompetitionAwardService::class)->createVersion($track, [
            'award_key' => 'champion',
            'name'      => '冠军',
            'entry_id'  => $entryId,
        ], $admin);
        $awardVersionId = (int) DB::table('competition_award_versions')->where('award_id', $award->id)->value('id');
        $award = app(CompetitionAwardService::class)->publish($award->fresh(), $awardVersionId, 0, $admin);
        $awardId = (int) $award->id;
    }

    $submission = app(CompetitionSubmissionService::class)->saveDraft($team, $entry, $captain, [
        'title'       => '机甲竞速作品',
        'summary'     => '自动循迹与避障',
        'description' => '作品说明',
        'repo_url'    => 'https://example.com/team-repo',
    ]);
    $submittedVersion = app(CompetitionSubmissionService::class)->submit(
        $team,
        $entry,
        $captain,
    );

    return [
        'track'     => $track,
        'entry'     => $entry,
        'team'      => $team,
        'captain'   => $captain,
        'member'    => Customer::query()->findOrFail($memberId),
        'version'   => $submittedVersion,
        'award_id'  => $awardId,
        'event_id'  => (int) $event['id'],
        'admin'     => $admin,
    ];
}

it('G3-11 · 未授权不能审核/发布；队长授权后公开只含最小字段', function () {
    $ctx = g3ShowcaseContext();
    $service = app(CompetitionShowcaseService::class);
    $admin = Fixtures::adminId();

    $showcase = $service->createDraft($ctx['track'], [
        'showcase_type'         => CompetitionShowcase::TYPE_AWARD,
        'title'                 => '冠军作品：机甲竞速',
        'summary'               => '展示队伍在复杂赛道上的循迹与避障能力。',
        'entry_id'              => (int) $ctx['entry']->id,
        'submission_version_id' => (int) $ctx['version']->id,
        'award_id'              => $ctx['award_id'],
    ], $admin);

    expect(fn () => $service->approve($showcase, (int) $showcase->row_version, $admin))
        ->toThrow(CompetitionException::class);

    // 非队长不能授权。
    expect(fn () => $service->setConsent($showcase, $ctx['member'], true, (int) $showcase->row_version))
        ->toThrow(CompetitionException::class);

    $showcase = $service->setConsent($showcase, $ctx['captain'], true, (int) $showcase->row_version);
    $showcase = $service->approve($showcase, (int) $showcase->row_version, $admin);
    $showcase = $service->publish($showcase, (int) $showcase->row_version, $admin);

    $public = $service->publicShowcases($ctx['event_id'])['robowar'];
    expect($public)->toHaveCount(1);
    expect($public[0])->toHaveKeys(['title', 'summary', 'team_name', 'track_name', 'award_name']);
    expect(json_encode($public[0]))->not->toContain('example.com/team-repo');
    expect(json_encode($public[0]))->not->toContain('description');
});

it('G3-11 · 授权绑定具体版本与内容；标题改动会让授权失效', function () {
    $ctx = g3ShowcaseContext(false);
    $service = app(CompetitionShowcaseService::class);
    $admin = Fixtures::adminId();

    $showcase = $service->createDraft($ctx['track'], [
        'showcase_type'         => CompetitionShowcase::TYPE_RECAP,
        'title'                 => '赛季回顾',
        'summary'               => '回顾本队从建队到完赛的完整过程。',
        'entry_id'              => (int) $ctx['entry']->id,
        'submission_version_id' => (int) $ctx['version']->id,
    ], $admin);

    $showcase = $service->setConsent($showcase, $ctx['captain'], true, (int) $showcase->row_version);
    expect($service->consentIsValid($showcase))->toBeTrue();

    // 授权后再改标题（模拟运营改稿）→ 旧授权失效，必须重新授权。
    DB::table('competition_showcases')->where('id', $showcase->id)->update(['title' => '赛季回顾（改稿）']);
    $showcase = CompetitionShowcase::query()->findOrFail($showcase->id);

    expect($service->consentIsValid($showcase))->toBeFalse();
    expect(fn () => $service->approve($showcase, (int) $showcase->row_version, $admin))
        ->toThrow(CompetitionException::class);

    // 撤回授权后已发布展示立即从公开面消失。
    $showcase = $service->setConsent($showcase, $ctx['captain'], true, (int) $showcase->row_version);
    $showcase = $service->approve($showcase, (int) $showcase->row_version, $admin);
    $showcase = $service->publish($showcase, (int) $showcase->row_version, $admin);
    expect($service->publicShowcases($ctx['event_id'])['robowar'])->toHaveCount(1);

    $showcase = $service->setConsent($showcase, $ctx['captain'], false, (int) $showcase->row_version);
    expect((string) $showcase->status)->toBe(CompetitionShowcase::STATUS_WITHDRAWN);
    expect($service->publicShowcases($ctx['event_id'])['robowar'])->toHaveCount(0);
});

it('G3-11 · 技能提审走既有闸门，不自动上架/发 license/下发真机', function () {
    $ctx = g3ShowcaseContext(false);
    $service = app(CompetitionShowcaseService::class);
    $admin = Fixtures::adminId();

    $showcase = $service->createDraft($ctx['track'], [
        'showcase_type'         => CompetitionShowcase::TYPE_RECAP,
        'title'                 => '可提审作品',
        'summary'               => '队伍希望把比赛作品接入开发者技能提审流程。',
        'entry_id'              => (int) $ctx['entry']->id,
        'submission_version_id' => (int) $ctx['version']->id,
    ], $admin);

    $showcase = $service->setConsent($showcase, $ctx['captain'], true, (int) $showcase->row_version);
    $showcase = $service->approve($showcase, (int) $showcase->row_version, $admin);
    $showcase = $service->publish($showcase, (int) $showcase->row_version, $admin);

    // 没有开发者身份：明确拒绝，不产生任何商品/授权/下发。
    expect(fn () => $service->requestSkillReview($showcase, $ctx['captain'], 1))
        ->toThrow(CompetitionException::class);

    $entitlementsBefore = Schema::hasTable('skill_entitlements')
        ? DB::table('skill_entitlements')->count()
        : 0;
    $productsBefore = DB::table('products')->count();

    DB::table('developer_profile')->insert([
        'customer_id'  => (int) $ctx['captain']->id,
        'seller_id'    => null,
        'dev_type'     => 'personal',
        'realname_status' => 'approved',
        'status'       => 'active',
        'agreement_signed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $skillVersion = (new SkillVersion())->forceFill(['id' => 987654]);
    $mock = \Mockery::mock(DeveloperSkillService::class);
    $mock->shouldReceive('findOwnedVersion')->once()->andReturn($skillVersion);
    $mock->shouldReceive('submitForReview')->once()->andReturn([
        'ok'      => true,
        'code'    => 'SUBMITTED',
        'message' => '已提交审核，L0 机审通过。',
    ]);

    app()->instance(DeveloperSkillService::class, $mock);
    $service = app(CompetitionShowcaseService::class);

    $result = $service->requestSkillReview($showcase, $ctx['captain'], 987654);

    expect($result['ok'])->toBeTrue();
    $fresh = CompetitionShowcase::query()->findOrFail($showcase->id);
    expect((int) $fresh->skill_version_id)->toBe(987654);
    expect($fresh->developer_handoff_at)->not->toBeNull();

    $entitlementsAfter = Schema::hasTable('skill_entitlements')
        ? DB::table('skill_entitlements')->count()
        : 0;

    expect($entitlementsAfter)->toBe($entitlementsBefore);
    expect(DB::table('products')->count())->toBe($productsBefore);
});
