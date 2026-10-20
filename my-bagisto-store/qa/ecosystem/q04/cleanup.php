<?php

/**
 * Q-04 · 清理浏览器演练夹具（仅独立测试库）。
 *
 *   php cleanup.php <fixture.json>
 *   php cleanup.php --event=<event_id> [--marker=QA-Q04-XXXX]
 *
 * 支持按夹具 JSON 或仅按活动 ID 清场（用于上次演练失败后的残留）。
 * 先删赛务子表，再删队/赛项/届次/系列与活动，最后删测试 admin / role / customer。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$jsonPath = null;
$eventId = 0;
$marker = '';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--event=')) {
        $eventId = (int) substr($argument, 8);
    } elseif (str_starts_with($argument, '--marker=')) {
        $marker = (string) substr($argument, 9);
    } elseif (is_file($argument)) {
        $jsonPath = $argument;
    }
}

if ($jsonPath !== null) {
    $fixture = json_decode((string) file_get_contents($jsonPath), true);

    if (! is_array($fixture)) {
        fwrite(STDERR, "invalid fixture json\n");
        exit(1);
    }

    $eventId = (int) ($fixture['event_id'] ?? $eventId);
    $marker = (string) ($fixture['marker'] ?? $marker);
}

if ($eventId <= 0) {
    fwrite(STDERR, "usage: cleanup.php <fixture.json> | --event=<id> [--marker=...]\n");
    exit(1);
}

$event = DB::table('events')->where('id', $eventId)->first();

if ($marker === '' && $event !== null) {
    $marker = (string) $event->name;
}

$editionIds = DB::table('competition_editions')->where('event_id', $eventId)->pluck('id')->all();
$seriesIds = $editionIds === [] ? [] : DB::table('competition_editions')->whereIn('id', $editionIds)->pluck('series_id')->unique()->all();
$trackIds = $editionIds === [] ? [] : DB::table('competition_tracks')->whereIn('edition_id', $editionIds)->pluck('id')->all();
$teamIds = $trackIds === [] ? [] : DB::table('competition_teams')->whereIn('track_id', $trackIds)->pluck('id')->all();
$entryIds = $trackIds === [] ? [] : DB::table('competition_entries')->whereIn('track_id', $trackIds)->pluck('id')->all();
$submissionIds = $entryIds === [] ? [] : DB::table('competition_submissions')->whereIn('entry_id', $entryIds)->pluck('id')->all();
$versionIds = $submissionIds === [] ? [] : DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->pluck('id')->all();
$appealIds = $entryIds === [] ? [] : DB::table('competition_appeals')->whereIn('entry_id', $entryIds)->pluck('id')->all();
$awardIds = $trackIds === [] ? [] : DB::table('competition_awards')->whereIn('track_id', $trackIds)->pluck('id')->all();
$resultIds = $trackIds === [] ? [] : DB::table('competition_results')->whereIn('track_id', $trackIds)->pluck('id')->all();

if ($versionIds !== []) {
    DB::table('competition_submission_attachments')->whereIn('version_id', $versionIds)->delete();
}
if ($submissionIds !== []) {
    DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->delete();
    DB::table('competition_submissions')->whereIn('id', $submissionIds)->delete();
}
if ($appealIds !== []) {
    DB::table('competition_appeal_attachments')->whereIn('appeal_id', $appealIds)->delete();
    DB::table('competition_appeal_events')->whereIn('appeal_id', $appealIds)->delete();
    DB::table('competition_appeals')->whereIn('id', $appealIds)->delete();
}
if ($awardIds !== []) {
    DB::table('competition_award_versions')->whereIn('award_id', $awardIds)->delete();
    DB::table('competition_awards')->whereIn('id', $awardIds)->delete();
}
if ($resultIds !== []) {
    DB::table('competition_result_versions')->whereIn('result_id', $resultIds)->delete();
    DB::table('competition_results')->whereIn('id', $resultIds)->delete();
}

if ($trackIds !== []) {
    DB::table('competition_showcases')->whereIn('track_id', $trackIds)->delete();
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

$customerIds = $marker === ''
    ? []
    : DB::table('customers')->where('email', 'like', strtolower($marker).'%')->pluck('id')->all();
$adminEmails = $marker === ''
    ? []
    : DB::table('admins')->where('email', 'like', strtolower($marker).'%')->pluck('email')->all();
$roleIds = $adminEmails === [] ? [] : DB::table('admins')->whereIn('email', $adminEmails)->pluck('role_id')->unique()->all();

$registrationIds = DB::table('event_registrations')->where('event_id', $eventId)->pluck('id')->all();
$attemptIds = DB::table('event_registration_attempts')->where('event_id', $eventId)->pluck('id')->all();

if ($attemptIds !== []) {
    DB::table('event_checkin_tokens')->whereIn('attempt_id', $attemptIds)->delete();
    DB::table('event_change_acknowledgments')->whereIn('attempt_id', $attemptIds)->delete();
}

DB::table('event_registration_requests')->where('target_type', 'event')->where('target_id', $eventId)->delete();
DB::table('event_registration_attempts')->where('event_id', $eventId)->delete();
DB::table('event_registrations')->where('event_id', $eventId)->delete();
DB::table('event_changes')->where('event_id', $eventId)->delete();
DB::table('event_registration_scopes')->where('event_id', $eventId)->delete();

// scope.rule_version_id 是 RESTRICT 外键，规则版本必须在 scope 之后删。
DB::table('event_rule_versions')->where('event_id', $eventId)->delete();

if ($registrationIds !== []) {
    DB::table('ecosystem_outbox')->where('aggregate_type', 'registration')->whereIn('aggregate_id', $registrationIds)->delete();
}
if ($customerIds !== []) {
    DB::table('notification')->where('recipient_type', 'customer')->whereIn('recipient_id', $customerIds)->delete();
}

if ($event !== null) {
    DB::table('events')->where('id', $eventId)->update([
        'published_revision_id' => null,
        'working_revision_id'   => null,
        'approved_revision_id'  => null,
    ]);
    DB::table('event_revisions')->where('event_id', $eventId)->delete();
    DB::table('events')->where('id', $eventId)->delete();
}

if ($adminEmails !== []) {
    DB::table('admins')->whereIn('email', $adminEmails)->delete();
}
if ($roleIds !== []) {
    DB::table('roles')->whereIn('id', $roleIds)->delete();
}
if ($customerIds !== []) {
    DB::table('customers')->whereIn('id', $customerIds)->delete();
}

qa_json([
    'deleted'     => true,
    'event_id'    => $eventId,
    'marker'      => $marker,
    'tracks'      => count($trackIds),
    'teams'       => count($teamIds),
    'entries'     => count($entryIds),
    'customers'   => count($customerIds),
    'admins'      => count($adminEmails),
    'roles'       => count($roleIds),
]);
