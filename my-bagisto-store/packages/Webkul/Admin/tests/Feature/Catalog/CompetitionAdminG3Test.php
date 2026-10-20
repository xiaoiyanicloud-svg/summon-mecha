<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionMatch;
use Webkul\Shop\Models\CompetitionStage;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * G3-06 Admin · CSV 预览/导入/发布与权限分离。
 *
 * 走真实后台路由（会话认证 + Bouncer + 控制器授权），
 * 不用服务层测试替代运营路径。
 */
beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toMatch('/(?:_test|_testing)$/')->not->toBe('root');

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.public_schedule_enabled' => true,
        'ecosystem.events.public_enabled' => true,
    ]);
});

function g3AdminUser(array $permissions = [], bool $all = true): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $all ? 'all' : 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function g3AdminContext(): array
{
    $adminId = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $adminId);
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
        'track' => CompetitionTrack::query()->findOrFail($trackId),
        'entry_a' => DB::table('competition_entries')->where('id', $entryA)->value('entry_no'),
        'entry_b' => DB::table('competition_entries')->where('id', $entryB)->value('entry_no'),
    ];
}

function g3AdminCsv(string $entryA, string $entryB): string
{
    return "stage_key,stage_name,stage_order,match_key,round_no,starts_at,ends_at,venue,home_entry_no,away_entry_no\n"
        ."group,小组赛,1,m-admin-1,1,2026-11-01T09:00:00+08:00,2026-11-01T10:00:00+08:00,A 场地,{$entryA},{$entryB}\n";
}

function g3AdminUpload(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'g3admin_');
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'schedule.csv', 'text/csv', null, true);
}

it('G3 Admin · 超管可预览/导入/发布赛程', function () {
    $ctx = g3AdminContext();
    $csv = g3AdminCsv($ctx['entry_a'], $ctx['entry_b']);

    actingAs(g3AdminUser(), 'admin');

    $preview = $this->post(
        route('admin.catalog.ecosystem.competition.schedule.preview', $ctx['track']->id),
        ['file' => g3AdminUpload($csv)],
        ['Accept' => 'application/json'],
    )->assertOk()->json('data');

    expect($preview['summary']['valid'])->toBe(1);

    $this->post(
        route('admin.catalog.ecosystem.competition.schedule.import', $ctx['track']->id),
        ['file' => g3AdminUpload($csv), 'preview_hash' => $preview['preview_hash']],
        ['Accept' => 'application/json'],
    )->assertOk();

    $stage = CompetitionStage::query()->where('track_id', $ctx['track']->id)->firstOrFail();
    expect(CompetitionMatch::query()->where('stage_id', $stage->id)->count())->toBe(1);

    $this->post(
        route('admin.catalog.ecosystem.competition.stages.publish', $stage->id),
        ['expected_row_version' => (int) $stage->row_version],
        ['Accept' => 'application/json'],
    )->assertOk();

    expect((string) $stage->fresh()->status)->toBe('published');

    get(route('admin.catalog.ecosystem.competition.schedule', $ctx['track']->id))->assertOk();
});

it('G3 Admin · 只有查看权不能预览/导入/发布', function () {
    $ctx = g3AdminContext();
    $csv = g3AdminCsv($ctx['entry_a'], $ctx['entry_b']);

    actingAs(g3AdminUser(['ecosystem.competition.view'], false), 'admin');

    $this->post(
        route('admin.catalog.ecosystem.competition.schedule.preview', $ctx['track']->id),
        ['file' => g3AdminUpload($csv)],
        ['Accept' => 'application/json'],
    )->assertForbidden();

    $this->post(
        route('admin.catalog.ecosystem.competition.schedule.import', $ctx['track']->id),
        ['file' => g3AdminUpload($csv), 'preview_hash' => str_repeat('a', 64)],
        ['Accept' => 'application/json'],
    )->assertForbidden();

    $this->post(
        route('admin.catalog.ecosystem.competition.stages.publish', 1),
        ['expected_row_version' => 0],
        ['Accept' => 'application/json'],
    )->assertForbidden();

    expect(CompetitionMatch::query()->count())->toBe(0);
});

it('G3 Admin · 写闸关闭时预览返回稳定 403 且不写库', function () {
    $ctx = g3AdminContext();
    $csv = g3AdminCsv($ctx['entry_a'], $ctx['entry_b']);
    config(['ecosystem_competition.write_enabled' => false]);

    actingAs(g3AdminUser(), 'admin');

    $response = $this->post(
        route('admin.catalog.ecosystem.competition.schedule.preview', $ctx['track']->id),
        ['file' => g3AdminUpload($csv)],
        ['Accept' => 'application/json'],
    );

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('COMPETITION_DISABLED');
    expect(CompetitionMatch::query()->count())->toBe(0);
});
