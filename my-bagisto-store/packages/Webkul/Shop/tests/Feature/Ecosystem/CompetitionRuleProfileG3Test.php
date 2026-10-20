<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionSeries;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionRuleProfile;
use Webkul\Shop\Services\Ecosystem\CompetitionSeriesService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-01 · 规则契约完整性与指纹。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['ecosystem_competition.write_enabled' => true]);
});

it('G3-01 · 缺少任何必填规则键都拒绝，不补默认值', function () {
    $rules = app(CompetitionRuleProfile::class);
    $profile = Fixtures::rulesProfile();
    unset($profile['eligibility']['max_members']);

    expect(fn () => $rules->assertComplete($profile))
        ->toThrow(CompetitionException::class, 'eligibility.max_members');
});

it('G3-01 · 占位符负责人不算确认', function () {
    $rules = app(CompetitionRuleProfile::class);
    $profile = Fixtures::rulesProfile();
    $profile['owners']['safety'] = '待定';

    $errors = $rules->errors($profile);

    expect(implode('|', $errors))->toContain('owners.safety');
});

it('G3-01 · 非 manual 评分/同分策略在本批被拒绝', function () {
    $rules = app(CompetitionRuleProfile::class);

    expect($rules->errors(Fixtures::rulesProfile(['scoring' => ['policy' => 'float']])))
        ->not->toBeEmpty();
    expect($rules->errors(Fixtures::rulesProfile(['tie' => ['policy' => 'time']])))
        ->not->toBeEmpty();
});

it('G3-01 · 指纹与键顺序无关，规则或版本变化会改变指纹', function () {
    $rules = app(CompetitionRuleProfile::class);
    $profile = Fixtures::rulesProfile();
    $reordered = array_reverse($profile, true);

    $a = $rules->fingerprint($profile);
    $b = $rules->fingerprint($reordered);

    expect($a)->toBe($b);

    $changed = Fixtures::rulesProfile(['eligibility' => ['min_members' => 3]]);
    expect($rules->fingerprint($changed))->not->toBe($a);
});

it('G3-01 · 手工改库导致指纹不一致时 assertTrackReady 拒绝', function () {
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version);

    $track = CompetitionTrack::query()->findOrFail($trackId);
    app(CompetitionRuleProfile::class)->assertTrackReady($track);

    DB::table('competition_tracks')->where('id', $trackId)->update([
        'rules_profile' => json_encode(Fixtures::rulesProfile([
            'eligibility' => ['min_members' => 1],
        ]), JSON_UNESCAPED_UNICODE),
    ]);

    expect(fn () => app(CompetitionRuleProfile::class)->assertTrackReady(
        CompetitionTrack::query()->findOrFail($trackId),
    ))->toThrow(CompetitionException::class);
});

it('G3-01 · 写闸关闭时所有赛务写入口稳定拒绝且不落库', function () {
    config(['ecosystem_competition.write_enabled' => false]);

    $service = app(CompetitionSeriesService::class);

    expect(fn () => $service->createSeries([
        'name' => '关闭闸系列',
        'slug' => 'gate-closed-series',
        'organizer_name' => '召唤机甲',
    ], 1))->toThrow(CompetitionException::class);

    expect(CompetitionSeries::query()->where('slug', 'gate-closed-series')->exists())->toBeFalse();
});
