<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * G3-08 ~ G3-11 Admin · 路由权限拆分。
 *
 * 仅查看权不能录分/发布/裁决/发布奖项/发布展示；
 * scoring 与 results 分离；appeal/awards/showcase 各自独立。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config(['ecosystem_competition.write_enabled' => true]);
});

function g3aclAdmin(array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function g3aclTrackId(): int
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');

    return Fixtures::track($edition, $scope, $version);
}

it('G3 Admin · 仅查看权不能调用任何赛务写入口', function () {
    $trackId = g3aclTrackId();
    actingAs(g3aclAdmin(['ecosystem.competition.view']), 'admin');

    get(route('admin.catalog.ecosystem.competition.results', $trackId))->assertOk();

    $this->post(route('admin.catalog.ecosystem.competition.results.draft', $trackId), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.results.submit', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.results.publish', $trackId), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.appeals.decide', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.awards.publish', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.showcases.publish', 1), [])->assertForbidden();
});

it('G3 Admin · scoring 只能录分/提交，不能复核发布', function () {
    $trackId = g3aclTrackId();
    actingAs(g3aclAdmin([
        'ecosystem.competition.view',
        'ecosystem.competition.scoring',
    ]), 'admin');

    $this->post(route('admin.catalog.ecosystem.competition.results.draft', $trackId), [])->assertStatus(302);
    $this->post(route('admin.catalog.ecosystem.competition.results.submit', 999), [])
        ->assertStatus(302);

    $this->post(route('admin.catalog.ecosystem.competition.results.publish', $trackId), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.results.finalize', $trackId), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.results.ranking', $trackId), [])->assertForbidden();
});

it('G3 Admin · results 能复核发布，但不能直接录分/裁决/发奖/发展示', function () {
    $trackId = g3aclTrackId();
    actingAs(g3aclAdmin([
        'ecosystem.competition.view',
        'ecosystem.competition.results',
    ]), 'admin');

    $this->post(route('admin.catalog.ecosystem.competition.results.draft', $trackId), [])->assertForbidden();

    $this->post(route('admin.catalog.ecosystem.competition.results.publish', $trackId), [
        'expected_row_version' => 0,
    ])->assertStatus(302);

    $this->post(route('admin.catalog.ecosystem.competition.appeals.decide', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.awards.publish', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.showcases.publish', 1), [])->assertForbidden();
});

it('G3 Admin · appeal / awards / showcase 权限互不越界', function () {
    $trackId = g3aclTrackId();

    actingAs(g3aclAdmin([
        'ecosystem.competition.view',
        'ecosystem.competition.appeal',
    ]), 'admin');
    $this->post(route('admin.catalog.ecosystem.competition.appeals.decide', 999999), [])->assertStatus(302);
    $this->post(route('admin.catalog.ecosystem.competition.awards.publish', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.showcases.publish', 1), [])->assertForbidden();

    actingAs(g3aclAdmin([
        'ecosystem.competition.view',
        'ecosystem.competition.awards',
    ]), 'admin');
    $this->post(route('admin.catalog.ecosystem.competition.awards.publish', 999999), [])->assertStatus(302);
    $this->post(route('admin.catalog.ecosystem.competition.showcases.publish', 1), [])->assertForbidden();
    $this->post(route('admin.catalog.ecosystem.competition.results.publish', $trackId), [])->assertForbidden();

    actingAs(g3aclAdmin([
        'ecosystem.competition.view',
        'ecosystem.competition.showcase',
    ]), 'admin');
    $this->post(route('admin.catalog.ecosystem.competition.showcases.publish', 999999), [])->assertStatus(302);
    $this->post(route('admin.catalog.ecosystem.competition.awards.publish', 1), [])->assertForbidden();
});
