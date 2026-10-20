<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * G3-03 / G3-04 / G3-05 · 客户 API 端到端（真实路由 + 认证 + 归属）。
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
        'ecosystem_competition.public_schedule_enabled' => true,
        'ecosystem.events.public_enabled' => true,
    ]);

    Storage::fake('local');
});

function g3ApiContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scopeId = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scopeId, $version);
    $scope = DB::table('event_registration_scopes')->where('id', $scopeId)->first();

    return [
        'captain' => Customer::query()->findOrFail(Fixtures::customer('队长')),
        'member' => Customer::query()->findOrFail(Fixtures::customer('队员')),
        'outsider' => Customer::query()->findOrFail(Fixtures::customer('路人')),
        'track' => CompetitionTrack::query()->findOrFail($trackId),
        'scope' => $scope,
        'event_id' => $event['id'],
    ];
}

it('G3 API · 建队→邀请→加入→报名→作品，跨账号访问被拒', function () {
    $ctx = g3ApiContext();

    actingAs($ctx['captain'], 'customer');

    $created = postJson('/api/customer/competition/teams', [
        'track_id' => (int) $ctx['track']->id,
        'name' => 'API 机甲队',
    ])->assertOk()->json('data');

    $teamId = (int) $created['id'];
    expect($created['is_captain'])->toBeTrue();

    $invite = postJson("/api/customer/competition/teams/{$teamId}/invites", [
        'expected_row_version' => (int) $created['row_version'],
    ])->assertOk()->json('data.token');

    expect($invite)->toBeString()->not->toBe('');

    actingAs($ctx['member'], 'customer');
    postJson('/api/customer/competition/teams/join', ['token' => $invite])->assertOk();

    // 未接受成员看不到队伍；非成员也看不到。
    actingAs($ctx['outsider'], 'customer');
    getJson("/api/customer/competition/teams/{$teamId}")->assertNotFound();
    getJson("/api/customer/competition/teams/{$teamId}/submission")->assertNotFound();

    actingAs($ctx['captain'], 'customer');
    $detail = getJson("/api/customer/competition/teams/{$teamId}")->assertOk()->json('data');
    expect($detail['members'])->toHaveCount(2);
    expect($detail['is_captain'])->toBeTrue();

    $registration = postJson("/api/customer/competition/teams/{$teamId}/register", [
        'request_key'      => 'g3-api-'.bin2hex(random_bytes(4)),
        'form_version'     => (int) $ctx['scope']->form_version,
        'privacy_version'  => (string) $ctx['scope']->privacy_version,
        'rules_version_id' => (int) $ctx['track']->rules_version_id,
        'consent'          => true,
        'name'             => '队长联系人',
        'phone'            => '13800000009',
        'robot'            => ['model' => 'M2', 'asset_no' => 'A2'],
    ])->assertOk()->json('data');

    expect($registration['status'])->toBe('confirmed');
    expect($registration['team_id'])->toBe($teamId);
    expect($registration['entry']['status'])->toBe('confirmed');

    // 队员可以查看作品；提交仍只允许队长。
    actingAs($ctx['member'], 'customer');
    getJson("/api/customer/competition/teams/{$teamId}/submission")->assertOk();

    actingAs($ctx['captain'], 'customer');
    $workspace = getJson("/api/customer/competition/teams/{$teamId}/submission")->assertOk()->json('data');
    expect($workspace['status'])->toBe('draft');

    $draft = postJson("/api/customer/competition/teams/{$teamId}/submission/draft", [
        'title' => 'API 作品',
        'summary' => '摘要',
    ])->assertOk()->json('data');

    postJson("/api/customer/competition/teams/{$teamId}/submission/submit", [
        'expected_row_version' => (int) $draft['submission_row_version'],
    ])->assertOk();

    $versions = getJson("/api/customer/competition/teams/{$teamId}/submission")
        ->assertOk()
        ->json('data.versions');

    expect($versions[0]['status'])->toBe('submitted');

    $path = tempnam(sys_get_temp_dir(), 'g3api_');
    file_put_contents($path, "api attachment\n");

    $upload = $this->post(
        "/api/customer/competition/teams/{$teamId}/submission/attachments",
        [
            'version_id' => (int) $versions[0]['id'],
            'file' => new UploadedFile($path, 'api.txt', 'text/plain', null, true),
        ],
        ['Accept' => 'application/json'],
    );

    // 已提交版本不可再上传。
    $upload->assertStatus(409);
});

it('G3 API · 未登录访问赛务写入口返回 401', function () {
    $ctx = g3ApiContext();

    getJson('/api/customer/competition/teams')->assertStatus(401);
    postJson('/api/customer/competition/teams', [
        'track_id' => (int) $ctx['track']->id,
        'name' => '未登录队',
    ])->assertStatus(401);
});

it('G3 API · 公开赛务信息只返回 active 赛项与公开字段', function () {
    $ctx = g3ApiContext();

    $response = getJson("/api/events/{$ctx['event_id']}/competition")->assertOk();
    $data = $response->json('data');

    expect($data['tracks'])->toHaveCount(1);
    expect($data['tracks'][0]['track_key'])->toBe('robowar');
    expect($data['tracks'][0]['registration']['privacy_version'])->toBe((string) $ctx['scope']->privacy_version);

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('emergency_contact_ref');
    expect($json)->not->toContain('plan_ref');
    expect($json)->not->toContain('rules_fingerprint');
});
