<?php

/**
 * Q-02 / Q-03 · 清理夹具（仅独立测试库）。
 *
 *   php cleanup.php <event_id> [marker]
 *
 * 按外键顺序删除报名、轮次、请求、凭证、变更、导出、outbox、受众通知，
 * 再清空 events 的三个 revision 指针后删除 revision 与 event；
 * 最后删除 marker 对应的测试客户。不会碰其它赛事 / 真实客户。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$eventId = (int) ($argv[1] ?? 0);
$marker = (string) ($argv[2] ?? '');

if ($eventId <= 0) {
    fwrite(STDERR, "usage: cleanup.php <event_id> [marker]\n");
    exit(1);
}

$event = DB::table('events')->where('id', $eventId)->first();

if ($event === null) {
    qa_json(['deleted' => false, 'reason' => 'event_not_found', 'event_id' => $eventId]);
    exit(0);
}

$registrationIds = DB::table('event_registrations')->where('event_id', $eventId)->pluck('id')->all();
$attemptIds = DB::table('event_registration_attempts')->where('event_id', $eventId)->pluck('id')->all();
$customerIds = [];

if ($marker !== '') {
    $customerIds = DB::table('customers')
        ->where('email', 'like', strtolower($marker).'%')
        ->pluck('id')
        ->all();
}

if ($attemptIds !== []) {
    DB::table('event_checkin_tokens')->whereIn('attempt_id', $attemptIds)->delete();
    DB::table('event_change_acknowledgments')->whereIn('attempt_id', $attemptIds)->delete();
}

DB::table('event_registration_requests')->where('target_type', 'event')->where('target_id', $eventId)->delete();
DB::table('event_registration_attempts')->where('event_id', $eventId)->delete();
DB::table('event_registrations')->where('event_id', $eventId)->delete();
DB::table('event_changes')->where('event_id', $eventId)->delete();
DB::table('event_registration_scopes')->where('event_id', $eventId)->delete();
DB::table('ecosystem_export_files')->where('event_id', $eventId)->delete();

if ($registrationIds !== []) {
    DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'registration')
        ->whereIn('aggregate_id', $registrationIds)
        ->delete();
}

if ($customerIds !== []) {
    // 站内信表名为单数 notification；收件人列是 recipient_type/recipient_id。
    DB::table('notification')
        ->where('recipient_type', 'customer')
        ->whereIn('recipient_id', $customerIds)
        ->delete();
}

DB::table('events')->where('id', $eventId)->update([
    'published_revision_id' => null,
    'working_revision_id'   => null,
    'approved_revision_id'  => null,
]);

DB::table('event_revisions')->where('event_id', $eventId)->delete();
DB::table('events')->where('id', $eventId)->delete();

if ($customerIds !== []) {
    DB::table('customers')->whereIn('id', $customerIds)->delete();
}

qa_json([
    'deleted'       => true,
    'event_id'      => $eventId,
    'registrations' => count($registrationIds),
    'attempts'      => count($attemptIds),
    'customers'     => count($customerIds),
]);
