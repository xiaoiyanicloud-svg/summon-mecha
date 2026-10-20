<?php

/**
 * G2/G3/G4 浏览器端到端演练 · 夹具（仅独立测试库）。
 *
 * 覆盖：
 *   · G2：可报名活动 + person scope；客户甲 / 客户乙（切号隔离）；
 *   · G3：系列 / 届次 / 赛项 / 队伍 / entry，已公布成绩；
 *   · G4：公开活动（主/副）、草稿、撤稿、公开资讯、草稿资讯、关联（活动/资讯/机型/技能/场景）。
 *
 * 所有写入都在独立测试库；bootstrap.php 会硬校验 APP_ENV=testing 且库名以 _test 结尾。
 * 输出单行 JSON 供 e2e/run.mjs 驱动浏览器。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as PublicFixtures;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as CompetitionFixtures;

$marker = 'QA-E2E-'.strtoupper(bin2hex(random_bytes(4)));
$now = qa_now();
$futureStart = now('UTC')->addDays(7)->format('Y-m-d H:i:s');
$futureEnd = now('UTC')->addDays(7)->addHours(8)->format('Y-m-d H:i:s');
$opens = now('UTC')->subHour()->format('Y-m-d H:i:s');
$closes = now('UTC')->addDays(2)->format('Y-m-d H:i:s');

// ── G4：公开活动（主赛事，兼作 G2 报名 / G3 赛务） ─────────────────────
$main = PublicFixtures::event(
    [
        'kind'           => 'competition',
        'status'         => 'registering',
        'hosting_status' => 'scheduled',
        'publish_status' => 'published',
        'disposition'    => 'normal',
    ],
    [
        'name'        => $marker.' 主赛事',
        'summary'     => '端到端演练主赛事',
        'detail_html' => '<p>主赛事正文</p>',
        'starts_at'   => $futureStart,
        'ends_at'     => $futureEnd,
    ],
);

$secondary = PublicFixtures::event(
    [
        'kind'           => 'salon',
        'status'         => 'scheduled',
        'hosting_status' => 'scheduled',
        'publish_status' => 'published',
        'disposition'    => 'normal',
    ],
    [
        'name'      => $marker.' ONLY-E2 沙龙',
        'summary'   => '端到端演练副活动',
        'starts_at' => $futureStart,
        'ends_at'   => $futureEnd,
    ],
);

$draft = PublicFixtures::event(
    ['publish_status' => 'draft'],
    ['name' => $marker.' 草稿活动'],
);

$retracted = PublicFixtures::event(
    ['disposition' => 'retracted'],
    [
        'name'    => $marker.' 撤稿前旧标题',
        'summary' => $marker.' 撤稿前旧摘要',
    ],
);

// ── G4：资讯 ─────────────────────────────────────────────────────────
$content = PublicFixtures::content([], [
    'title'   => $marker.' 公开资讯',
    'summary' => $marker.' 公开资讯摘要',
]);
$contentSlug = (string) DB::table('content_posts')->where('id', $content['id'])->value('slug');

$draftContent = PublicFixtures::content(['publish_status' => 'draft'], [
    'title' => $marker.' 草稿资讯',
]);
$draftContentSlug = (string) DB::table('content_posts')->where('id', $draftContent['id'])->value('slug');

// ── G4：关联目标（机型 / 技能 / 场景） ────────────────────────────────
$deviceModelId = (int) DB::table('device_model')->insertGetId([
    'brand'      => '召唤机甲',
    'model_code' => 'E2E-'.bin2hex(random_bytes(3)),
    'model_name' => $marker.' 关联机型',
    'status'     => 'display_only',
    'sort_order' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);

$sceneId = (int) DB::table('scenes')->insertGetId([
    'name'       => $marker.' 关联场景',
    'category'   => 'enterprise',
    'status'     => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$skillSku = 'E2E-SKILL-'.bin2hex(random_bytes(4));
$skillProductId = (int) DB::table('products')->insertGetId([
    'sku'                 => $skillSku,
    'type'                => 'simple',
    'attribute_family_id' => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);
DB::table('product_flat')->insert([
    'sku'        => $skillSku,
    'name'       => $marker.' 关联技能',
    'product_id' => $skillProductId,
    'locale'     => 'en',
    'channel'    => 'default',
    'created_at' => $now,
    'updated_at' => $now,
]);
DB::table('skill_versions')->insert([
    'product_id' => $skillProductId,
    'version'    => '1.0.0',
    'channel'    => 'stable',
    'status'     => 'published',
    'created_at' => $now,
    'updated_at' => $now,
]);

// ── G4：关联（撤稿目标必须被整项省略） ───────────────────────────────
$relationRow = static function (int $sourceId, string $targetType, string $targetKey, int $sort) use ($now): array {
    return [
        'source_type' => 'event',
        'source_key'  => (string) $sourceId,
        'target_type' => $targetType,
        'target_key'  => $targetKey,
        'sort_order'  => $sort,
        'status'      => 'active',
        'created_by'  => 1,
        'created_at'  => $now,
        'updated_at'  => $now,
    ];
};

DB::table('ecosystem_relations')->insert([
    $relationRow($main['id'], 'event', (string) $secondary['id'], 0),
    $relationRow($main['id'], 'content', $contentSlug, 1),
    $relationRow($main['id'], 'device_model', (string) $deviceModelId, 2),
    $relationRow($main['id'], 'skill', (string) $skillProductId, 3),
    $relationRow($main['id'], 'scene', (string) $sceneId, 4),
    // 撤稿目标：公开解析必须省略，不出现旧标题。
    $relationRow($main['id'], 'event', (string) $retracted['id'], 5),
]);

// ── G2：报名 scope ───────────────────────────────────────────────────
$personScopeId = (int) DB::table('event_registration_scopes')->insertGetId([
    'event_id'               => $main['id'],
    'scope_key'              => 'default',
    'unit'                   => 'person',
    'capacity'               => 20,
    'capacity_confirmed_at'  => $now,
    'confirmed_count'        => 0,
    'review_mode'            => 'auto',
    'registration_opens_at'  => $opens,
    'registration_closes_at' => $closes,
    'review_closes_at'       => null,
    'cancel_policy'          => 'before_close',
    'reapply_policy'         => 'allowed',
    'max_attempts'           => 3,
    'change_ack_required'    => 0,
    'decline_releases_seat'  => 0,
    'no_response_action'     => 'none',
    'form_version'           => 1,
    'form_schema'            => json_encode(['fields' => ['name', 'phone', 'city', 'note'], 'required' => ['name', 'phone']]),
    'privacy_version'        => '2026-10-19',
    'privacy_summary'        => '报名信息仅用于本次活动联络与签到。',
    'rule_version_id'        => null,
    'status'                 => 'open',
    'policy_confirmed_at'    => $now,
    'policy_confirmed_by'    => 1,
    'row_version'            => 0,
    'created_at'             => $now,
    'updated_at'             => $now,
]);

// ── 客户 ─────────────────────────────────────────────────────────────
$makeCustomer = static function (string $label) use ($marker): array {
    $email = strtolower($marker).'-'.$label.'@example.test';
    $id = (int) DB::table('customers')->insertGetId([
        'first_name'  => 'E2E',
        'last_name'   => ucfirst($label),
        'email'       => $email,
        'phone'       => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'status'      => 1,
        'is_verified' => 1,
        'password'    => bcrypt('secret123'),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => 'secret123'];
};

$registrant = $makeCustomer('registrant');
$otherCustomer = $makeCustomer('other');
$captainA = $makeCustomer('captain-a');
$memberA = $makeCustomer('member-a');
$captainB = $makeCustomer('captain-b');

// ── G3：系列 / 届次 / 赛项 / 队伍 / entry ────────────────────────────
$adminRecorder = CompetitionFixtures::adminId();
$adminReviewer = CompetitionFixtures::adminId();
$ruleVersion = CompetitionFixtures::ruleVersion($main['id'], $adminRecorder);

$series = CompetitionFixtures::series();
$editionId = CompetitionFixtures::edition($series['id'], $main['id']);
$teamScopeId = CompetitionFixtures::scope($main['id'], 'robowar');

$resultProfile = CompetitionFixtures::rulesProfile([
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

$trackId = CompetitionFixtures::track($editionId, $teamScopeId, $ruleVersion, [], $resultProfile);
$track = CompetitionTrack::query()->findOrFail($trackId);

$teamAName = $marker.' A队';
$teamBName = $marker.' B队';
$teamAId = CompetitionFixtures::team($editionId, $trackId, $captainA['id'], ['name' => $teamAName]);
$teamBId = CompetitionFixtures::team($editionId, $trackId, $captainB['id'], ['name' => $teamBName]);
CompetitionFixtures::member($teamAId, $memberA['id']);

$entryAId = CompetitionFixtures::entry($editionId, $trackId, $teamAId, $captainA['id'], [
    'rules_version_id'  => (int) $ruleVersion->getKey(),
    'rules_fingerprint' => (string) $track->rules_fingerprint,
    'eligibility_snapshot' => json_encode([
        'team_name'    => $teamAName,
        'member_count' => 2,
        'min_members'  => 2,
        'max_members'  => 4,
    ], JSON_UNESCAPED_UNICODE),
]);
$entryBId = CompetitionFixtures::entry($editionId, $trackId, $teamBId, $captainB['id'], [
    'rules_version_id'  => (int) $ruleVersion->getKey(),
    'rules_fingerprint' => (string) $track->rules_fingerprint,
    'eligibility_snapshot' => json_encode([
        'team_name'    => $teamBName,
        'member_count' => 1,
        'min_members'  => 2,
        'max_members'  => 4,
    ], JSON_UNESCAPED_UNICODE),
]);

$entryA = CompetitionEntry::query()->findOrFail($entryAId);
$entryB = CompetitionEntry::query()->findOrFail($entryBId);

// 已公布成绩：录分与发布分权，排名后暂定发布（产生申诉窗口）。
$results = app(CompetitionResultService::class);
$resultA = $results->saveDraft($track, $entryA, null, ['speed' => '80', 'damage' => '20'], 'none', 0, $adminRecorder);
$resultB = $results->saveDraft($track, $entryB, null, ['speed' => '70', 'damage' => '19'], 'none', 0, $adminRecorder);
$resultA = $results->submit($resultA, (int) $resultA->row_version, $adminRecorder);
$resultB = $results->submit($resultB, (int) $resultB->row_version, $adminRecorder);
$ranking = $results->previewRanking($track);
$results->applyRanking($track, $ranking, $adminReviewer);
$results->publishProvisional($track->fresh(), (int) $track->row_version, $adminReviewer);

qa_json([
    'marker'           => $marker,
    'event_main_id'    => $main['id'],
    'event_secondary_id' => $secondary['id'],
    'event_draft_id'   => $draft['id'],
    'event_retracted_id' => $retracted['id'],
    'content_slug'     => $contentSlug,
    'draft_content_slug' => $draftContentSlug,
    'person_scope_id'  => $personScopeId,
    'team_scope_id'    => $teamScopeId,
    'series_id'        => $series['id'],
    'edition_id'       => $editionId,
    'track_id'         => $trackId,
    'team_a_id'        => $teamAId,
    'team_b_id'        => $teamBId,
    'team_a_name'      => $teamAName,
    'team_b_name'      => $teamBName,
    'entry_a_id'       => $entryAId,
    'entry_b_id'       => $entryBId,
    'rule_version_id'  => (int) $ruleVersion->getKey(),
    'device_model_id'  => $deviceModelId,
    'scene_id'         => $sceneId,
    'skill_product_id' => $skillProductId,
    'registrant'       => $registrant,
    'other'            => $otherCustomer,
    'captain_a'        => $captainA,
    'member_a'         => $memberA,
    'captain_b'        => $captainB,
    'admin_recorder'   => $adminRecorder,
    'admin_reviewer'   => $adminReviewer,
]);
