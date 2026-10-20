<?php

/**
 * G2/G3/G4 浏览器端到端演练 · 清场（仅独立测试库）。
 *
 *   php cleanup.php <fixture.json>
 *
 * 按外键顺序删除 prepare.php 创建的全部夹具；marker 用于兜底清理测试客户/后台账号。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$jsonPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (is_file($argument)) {
        $jsonPath = $argument;
    }
}

if ($jsonPath === null) {
    fwrite(STDERR, "usage: cleanup.php <fixture.json>\n");
    exit(1);
}

$fixture = json_decode((string) file_get_contents($jsonPath), true);

if (! is_array($fixture)) {
    fwrite(STDERR, "invalid fixture json\n");
    exit(1);
}

$marker = (string) ($fixture['marker'] ?? '');
$eventIds = array_values(array_filter([
    (int) ($fixture['event_main_id'] ?? 0),
    (int) ($fixture['event_secondary_id'] ?? 0),
    (int) ($fixture['event_draft_id'] ?? 0),
    (int) ($fixture['event_retracted_id'] ?? 0),
]));

$mainEventId = (int) ($fixture['event_main_id'] ?? 0);
$trackIds = array_values(array_filter([(int) ($fixture['track_id'] ?? 0)]));
$teamIds = array_values(array_filter([(int) ($fixture['team_a_id'] ?? 0), (int) ($fixture['team_b_id'] ?? 0)]));
$entryIds = array_values(array_filter([(int) ($fixture['entry_a_id'] ?? 0), (int) ($fixture['entry_b_id'] ?? 0)]));
$editionIds = array_values(array_filter([(int) ($fixture['edition_id'] ?? 0)]));
$seriesIds = array_values(array_filter([(int) ($fixture['series_id'] ?? 0)]));
$contentSlugs = array_values(array_filter([
    (string) ($fixture['content_slug'] ?? ''),
    (string) ($fixture['draft_content_slug'] ?? ''),
]));

// ── 赛务 ─────────────────────────────────────────────────────────────
$submissionIds = $entryIds === [] ? [] : DB::table('competition_submissions')->whereIn('entry_id', $entryIds)->pluck('id')->all();
$versionIds = $submissionIds === [] ? [] : DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->pluck('id')->all();
$appealIds = $entryIds === [] ? [] : DB::table('competition_appeals')->whereIn('entry_id', $entryIds)->pluck('id')->all();
$awardIds = $trackIds === [] ? [] : DB::table('competition_awards')->whereIn('track_id', $trackIds)->pluck('id')->all();
$resultIds = $trackIds === [] ? [] : DB::table('competition_results')->whereIn('track_id', $trackIds)->pluck('id')->all();

// 展示引用了 submission_version / award / result，必须先删。
if ($trackIds !== []) {
    DB::table('competition_showcases')->whereIn('track_id', $trackIds)->delete();
}

if ($awardIds !== []) {
    DB::table('competition_award_versions')->whereIn('award_id', $awardIds)->delete();
    DB::table('competition_awards')->whereIn('id', $awardIds)->delete();
}

if ($appealIds !== []) {
    DB::table('competition_appeal_attachments')->whereIn('appeal_id', $appealIds)->delete();
    DB::table('competition_appeal_events')->whereIn('appeal_id', $appealIds)->delete();
    DB::table('competition_appeals')->whereIn('id', $appealIds)->delete();
}

if ($versionIds !== []) {
    DB::table('competition_submission_attachments')->whereIn('version_id', $versionIds)->delete();
}

if ($submissionIds !== []) {
    DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->delete();
    DB::table('competition_submissions')->whereIn('id', $submissionIds)->delete();
}

if ($resultIds !== []) {
    DB::table('competition_result_versions')->whereIn('result_id', $resultIds)->delete();
    DB::table('competition_results')->whereIn('id', $resultIds)->delete();
    DB::table('ecosystem_outbox')->where('aggregate_type', 'competition_result')->whereIn('aggregate_id', $resultIds)->delete();
}

if ($trackIds !== []) {
    DB::table('competition_matches')->whereIn('track_id', $trackIds)->delete();
    DB::table('competition_stages')->whereIn('track_id', $trackIds)->delete();
    DB::table('competition_entries')->whereIn('track_id', $trackIds)->delete();
}

if ($teamIds !== []) {
    DB::table('competition_team_invites')->whereIn('team_id', $teamIds)->delete();
    DB::table('competition_team_members')->whereIn('team_id', $teamIds)->delete();
    DB::table('competition_teams')->whereIn('id', $teamIds)->delete();
}

if ($trackIds !== []) {
    DB::table('competition_tracks')->whereIn('id', $trackIds)->delete();
}

if ($editionIds !== []) {
    DB::table('competition_editions')->whereIn('id', $editionIds)->delete();
}

if ($seriesIds !== []) {
    DB::table('competition_series')->whereIn('id', $seriesIds)->delete();
}

// ── 报名 ─────────────────────────────────────────────────────────────
$registrationIds = $mainEventId <= 0 ? [] : DB::table('event_registrations')->where('event_id', $mainEventId)->pluck('id')->all();
$attemptIds = $mainEventId <= 0 ? [] : DB::table('event_registration_attempts')->where('event_id', $mainEventId)->pluck('id')->all();

if ($attemptIds !== []) {
    DB::table('event_checkin_tokens')->whereIn('attempt_id', $attemptIds)->delete();
    DB::table('event_change_acknowledgments')->whereIn('attempt_id', $attemptIds)->delete();
}

if ($registrationIds !== []) {
    DB::table('ecosystem_outbox')->where('aggregate_type', 'registration')->whereIn('aggregate_id', $registrationIds)->delete();
}

if ($mainEventId > 0) {
    DB::table('event_registration_requests')->where('target_type', 'event')->where('target_id', $mainEventId)->delete();
    DB::table('event_registration_attempts')->where('event_id', $mainEventId)->delete();
    DB::table('event_registrations')->where('event_id', $mainEventId)->delete();
    DB::table('event_changes')->where('event_id', $mainEventId)->delete();
    DB::table('event_registration_scopes')->where('event_id', $mainEventId)->delete();
    DB::table('event_rule_versions')->where('event_id', $mainEventId)->delete();
}

// ── G4 关联 / 内容 ───────────────────────────────────────────────────
if ($mainEventId > 0) {
    DB::table('ecosystem_relations')->where('source_type', 'event')->where('source_key', (string) $mainEventId)->delete();
}

if ($contentSlugs !== []) {
    $postIds = DB::table('content_posts')->whereIn('slug', $contentSlugs)->pluck('id')->all();
    $revisionIds = $postIds === [] ? [] : DB::table('content_revisions')->whereIn('post_id', $postIds)->pluck('id')->all();

    if ($postIds !== []) {
        DB::table('content_posts')->whereIn('id', $postIds)->update([
            'published_revision_id' => null,
            'working_revision_id'   => null,
            'approved_revision_id'  => null,
        ]);
    }

    if ($revisionIds !== []) {
        DB::table('content_sources')->whereIn('revision_id', $revisionIds)->delete();
        DB::table('content_revisions')->whereIn('id', $revisionIds)->delete();
    }

    if ($postIds !== []) {
        DB::table('ecosystem_content_view_daily')->where('target_type', 'content')->whereIn('target_key', $contentSlugs)->delete();
        DB::table('content_posts')->whereIn('id', $postIds)->delete();
    }
}

// ── 事件 ─────────────────────────────────────────────────────────────
if ($eventIds !== []) {
    DB::table('ecosystem_content_view_daily')->where('target_type', 'event')->whereIn('target_key', array_map('strval', $eventIds))->delete();
    DB::table('ecosystem_outbox')->where('aggregate_type', 'event')->whereIn('aggregate_id', $eventIds)->delete();
    DB::table('events')->whereIn('id', $eventIds)->update([
        'published_revision_id' => null,
        'working_revision_id'   => null,
        'approved_revision_id'  => null,
    ]);
    DB::table('event_revisions')->whereIn('event_id', $eventIds)->delete();
    DB::table('events')->whereIn('id', $eventIds)->delete();
}

// ── 关联目标 ─────────────────────────────────────────────────────────
$deviceModelId = (int) ($fixture['device_model_id'] ?? 0);
$sceneId = (int) ($fixture['scene_id'] ?? 0);
$skillProductId = (int) ($fixture['skill_product_id'] ?? 0);

if ($deviceModelId > 0) {
    DB::table('device_model')->where('id', $deviceModelId)->delete();
}

if ($sceneId > 0) {
    DB::table('scenes')->where('id', $sceneId)->delete();
}

if ($skillProductId > 0) {
    DB::table('skill_versions')->where('product_id', $skillProductId)->delete();
    DB::table('product_flat')->where('product_id', $skillProductId)->delete();
    DB::table('products')->where('id', $skillProductId)->delete();
}

// ── 客户 / 后台账号 ──────────────────────────────────────────────────
$customerIds = [];

foreach (['registrant', 'other', 'captain_a', 'member_a', 'captain_b'] as $key) {
    $id = (int) ($fixture[$key]['id'] ?? 0);

    if ($id > 0) {
        $customerIds[] = $id;
    }
}

$adminIds = array_values(array_filter([
    (int) ($fixture['admin_recorder'] ?? 0),
    (int) ($fixture['admin_reviewer'] ?? 0),
]));

if ($customerIds !== []) {
    DB::table('notification')->where('recipient_type', 'customer')->whereIn('recipient_id', $customerIds)->delete();
    DB::table('ecosystem_favorites')->whereIn('customer_id', $customerIds)->delete();
    DB::table('customers')->whereIn('id', $customerIds)->delete();
}

if ($adminIds !== []) {
    $roleIds = DB::table('admins')->whereIn('id', $adminIds)->pluck('role_id')->unique()->all();
    DB::table('admins')->whereIn('id', $adminIds)->delete();

    if ($roleIds !== []) {
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }
}

// 兜底：marker 关联但上面未覆盖的测试账号。
if ($marker !== '') {
    $leftoverCustomers = DB::table('customers')->where('email', 'like', strtolower($marker).'%')->pluck('id')->all();

    if ($leftoverCustomers !== []) {
        DB::table('ecosystem_favorites')->whereIn('customer_id', $leftoverCustomers)->delete();
        DB::table('notification')->where('recipient_type', 'customer')->whereIn('recipient_id', $leftoverCustomers)->delete();
        DB::table('customers')->whereIn('id', $leftoverCustomers)->delete();
    }

    $leftoverAdmins = DB::table('admins')->where('email', 'like', strtolower($marker).'%')->pluck('id')->all();

    if ($leftoverAdmins !== []) {
        $leftoverRoles = DB::table('admins')->whereIn('id', $leftoverAdmins)->pluck('role_id')->unique()->all();
        DB::table('admins')->whereIn('id', $leftoverAdmins)->delete();

        if ($leftoverRoles !== []) {
            DB::table('roles')->whereIn('id', $leftoverRoles)->delete();
        }
    }
}

qa_json([
    'deleted'   => true,
    'marker'    => $marker,
    'events'    => count($eventIds),
    'tracks'    => count($trackIds),
    'teams'     => count($teamIds),
    'entries'   => count($entryIds),
    'contents'  => count($contentSlugs),
    'customers' => count($customerIds),
    'admins'    => count($adminIds),
]);
