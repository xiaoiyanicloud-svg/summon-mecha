<?php

/**
 * G3 Admin 录分/发布浏览器演练 · 夹具（仅独立测试库）。
 *
 * 创建一个已发布活动、已确认规则的赛项、两支队伍与两条 entry；
 * **不预写成绩**，让后台页面从保存草稿开始走完整流程。
 *
 * 输出 JSON 的键与 qa/ecosystem/e2e/cleanup.php 兼容，演练结束直接复用清场。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as PublicFixtures;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as CompetitionFixtures;

$marker = 'QA-ADMIN-'.strtoupper(bin2hex(random_bytes(4)));
$now = qa_now();
$futureStart = now('UTC')->addDays(7)->format('Y-m-d H:i:s');
$futureEnd = now('UTC')->addDays(7)->addHours(8)->format('Y-m-d H:i:s');

$event = PublicFixtures::event(
    [
        'kind'           => 'competition',
        'status'         => 'registering',
        'hosting_status' => 'scheduled',
        'publish_status' => 'published',
        'disposition'    => 'normal',
    ],
    [
        'name'      => $marker.' 后台录分演练',
        'summary'   => 'Admin 录分/发布浏览器演练',
        'starts_at' => $futureStart,
        'ends_at'   => $futureEnd,
    ],
);

$makeCustomer = static function (string $label) use ($marker): array {
    $email = strtolower($marker).'-'.$label.'@example.test';
    $id = (int) DB::table('customers')->insertGetId([
        'first_name'  => 'ADMIN',
        'last_name'   => ucfirst($label),
        'email'       => $email,
        'phone'       => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'status'      => 1,
        'is_verified' => 1,
        'password'    => bcrypt('secret123'),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => 'secret123'];
};

$captainA = $makeCustomer('captain-a');
$captainB = $makeCustomer('captain-b');

$adminRecorderId = CompetitionFixtures::adminId();
$adminReviewerId = CompetitionFixtures::adminId();
$adminRecorderEmail = (string) DB::table('admins')->where('id', $adminRecorderId)->value('email');
$adminReviewerEmail = (string) DB::table('admins')->where('id', $adminReviewerId)->value('email');

$ruleVersion = CompetitionFixtures::ruleVersion($event['id'], $adminRecorderId);
$series = CompetitionFixtures::series();
$editionId = CompetitionFixtures::edition($series['id'], $event['id']);
$teamScopeId = CompetitionFixtures::scope($event['id'], 'robowar');

$profile = CompetitionFixtures::rulesProfile([
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
]);

$trackId = CompetitionFixtures::track($editionId, $teamScopeId, $ruleVersion, [], $profile);
$track = CompetitionTrack::query()->findOrFail($trackId);

$teamAName = $marker.' A队';
$teamBName = $marker.' B队';
$teamAId = CompetitionFixtures::team($editionId, $trackId, $captainA['id'], ['name' => $teamAName]);
$teamBId = CompetitionFixtures::team($editionId, $trackId, $captainB['id'], ['name' => $teamBName]);

$entryIds = [];

foreach ([[$teamAId, $captainA['id'], $teamAName], [$teamBId, $captainB['id'], $teamBName]] as [$teamId, $captainId, $teamName]) {
    $entryIds[] = CompetitionFixtures::entry($editionId, $trackId, $teamId, $captainId, [
        'rules_version_id'  => (int) $ruleVersion->getKey(),
        'rules_fingerprint' => (string) $track->rules_fingerprint,
        'eligibility_snapshot' => json_encode([
            'team_name'    => $teamName,
            'member_count' => 1,
            'min_members'  => 1,
            'max_members'  => 4,
        ], JSON_UNESCAPED_UNICODE),
    ]);
}

qa_json([
    'marker'             => $marker,
    'event_main_id'      => $event['id'],
    'event_secondary_id' => 0,
    'event_draft_id'     => 0,
    'event_retracted_id' => 0,
    'content_slug'       => '',
    'draft_content_slug' => '',
    'person_scope_id'    => 0,
    'team_scope_id'      => $teamScopeId,
    'series_id'          => $series['id'],
    'edition_id'         => $editionId,
    'track_id'           => $trackId,
    'team_a_id'          => $teamAId,
    'team_b_id'          => $teamBId,
    'team_a_name'        => $teamAName,
    'team_b_name'        => $teamBName,
    'entry_a_id'         => $entryIds[0],
    'entry_b_id'         => $entryIds[1],
    'rule_version_id'    => (int) $ruleVersion->getKey(),
    'device_model_id'    => 0,
    'scene_id'           => 0,
    'skill_product_id'   => 0,
    'registrant'         => $captainA,
    'other'              => $captainB,
    'captain_a'          => $captainA,
    'member_a'           => $captainA,
    'captain_b'          => $captainB,
    'admin_recorder'     => ['id' => $adminRecorderId, 'email' => $adminRecorderEmail, 'password' => 'secret123'],
    'admin_reviewer'     => ['id' => $adminReviewerId, 'email' => $adminReviewerEmail, 'password' => 'secret123'],
]);
