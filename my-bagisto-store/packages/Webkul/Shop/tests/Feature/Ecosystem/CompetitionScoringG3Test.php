<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionRuleProfile;
use Webkul\Shop\Services\Ecosystem\CompetitionScoringService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-07 · 计分契约、精度、缺赛/弃权与策略注册。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['ecosystem_competition.write_enabled' => true]);
});

function g3ScoringProfile(array $overrides = []): array
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
    ], $overrides));
}

function g3ScoringTrack(array $profileOverrides = []): CompetitionTrack
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version, [], g3ScoringProfile($profileOverrides));

    return CompetitionTrack::query()->findOrFail($trackId);
}

it('G3-07 · 自动策略必须声明字段/精度/缺赛/弃权，缺项拒绝', function () {
    $profile = g3ScoringProfile();
    unset($profile['scoring']['scale'], $profile['scoring']['missing'], $profile['scoring']['forfeit']);

    $errors = app(CompetitionRuleProfile::class)->errors($profile);

    expect(implode(' | ', $errors))->toContain('scoring.scale')
        ->toContain('scoring.missing')
        ->toContain('scoring.forfeit');
});

it('G3-07 · 未登记的计分策略与同分策略拒绝', function () {
    $profile = g3ScoringProfile(['scoring' => ['policy' => 'user_formula']]);
    expect(implode(' | ', app(CompetitionRuleProfile::class)->errors($profile)))
        ->toContain('scoring.policy 必须是已登记策略');

    $profile = g3ScoringProfile(['tie' => ['policy' => 'random']]);
    expect(implode(' | ', app(CompetitionRuleProfile::class)->errors($profile)))
        ->toContain('tie.policy');
});

it('G3-07 · 分数定点计算：拒绝浮点、超精度与越界，0.1+0.2 无浮点误差', function () {
    $track = g3ScoringTrack();
    $service = app(CompetitionScoringService::class);

    $normalized = $service->normalizeValues($track, [
        'speed'  => '0.1',
        'damage' => '0.2',
    ]);

    expect($normalized['total_scaled'])->toBe('30');
    expect($service->scaledToDecimal($normalized['total_scaled'], 2))->toBe('0.30');

    expect(fn () => $service->normalizeValues($track, ['speed' => 12.5, 'damage' => '1']))
        ->toThrow(CompetitionException::class);

    expect(fn () => $service->normalizeValues($track, ['speed' => '1.234', 'damage' => '1']))
        ->toThrow(CompetitionException::class);

    expect(fn () => $service->normalizeValues($track, ['speed' => '101', 'damage' => '1']))
        ->toThrow(CompetitionException::class);

    expect(fn () => $service->normalizeValues($track, ['speed' => '1', 'unknown' => '1']))
        ->toThrow(CompetitionException::class);
});

it('G3-07 · 缺赛/弃权语义按规则执行', function () {
    $track = g3ScoringTrack([
        'scoring' => ['missing' => 'zero', 'forfeit' => 'disqualify'],
    ]);
    $service = app(CompetitionScoringService::class);

    $absent = $service->normalizeValues($track, [
        'speed'  => ['status' => 'absent'],
        'damage' => '10',
    ]);

    expect($absent['excluded'])->toBeFalse();
    expect($absent['total_scaled'])->toBe('1000');

    $forfeit = $service->normalizeValues($track, [
        'speed'  => '10',
        'damage' => ['status' => 'forfeit'],
    ]);

    expect($forfeit['excluded'])->toBeTrue();
    expect($forfeit['total_scaled'])->toBeNull();
    expect($forfeit['forfeit_status'])->toBe('disqualified');
});

it('G3-07 · 同分策略 shared 并列同名次并跳号；strict 不猜顺序', function () {
    $shared = g3ScoringTrack(['tie' => ['policy' => 'shared']]);
    $strict = g3ScoringTrack(['tie' => ['policy' => 'strict']]);
    $service = app(CompetitionScoringService::class);

    $rows = [
        ['entry_id' => 1, 'total_scaled' => '1000', 'excluded' => false, 'forfeit_status' => 'none'],
        ['entry_id' => 2, 'total_scaled' => '1000', 'excluded' => false, 'forfeit_status' => 'none'],
        ['entry_id' => 3, 'total_scaled' => '900', 'excluded' => false, 'forfeit_status' => 'none'],
    ];

    $ranked = $service->rank($shared, $rows);
    expect($ranked[1]['rank_no'])->toBe(1);
    expect($ranked[2]['rank_no'])->toBe(1);
    expect($ranked[3]['rank_no'])->toBe(3);
    expect($ranked[1]['tie_group'])->toBe($ranked[2]['tie_group']);

    $ranked = $service->rank($strict, $rows);
    expect($ranked[1]['rank_no'])->toBeNull();
    expect($ranked[2]['rank_no'])->toBeNull();
    expect($ranked[1]['tie_group'])->not->toBeNull();
    expect($ranked[3]['rank_no'])->toBe(3);
});

it('G3-07 · manual 策略不自动产生名次，必须人工核准', function () {
    $track = g3ScoringTrack([
        'scoring' => ['policy' => 'manual'],
        'tie' => ['policy' => 'manual'],
    ]);
    $profile = (array) $track->rules_profile;
    unset(
        $profile['scoring']['fields'],
        $profile['scoring']['scale'],
        $profile['scoring']['missing'],
        $profile['scoring']['forfeit'],
    );
    $track->rules_profile = $profile;
    $track->save();

    $ranked = app(CompetitionScoringService::class)->rank($track, [
        ['entry_id' => 7, 'total_scaled' => null, 'excluded' => false, 'forfeit_status' => 'none'],
    ]);

    expect($ranked[7]['rank_no'])->toBeNull();
});
