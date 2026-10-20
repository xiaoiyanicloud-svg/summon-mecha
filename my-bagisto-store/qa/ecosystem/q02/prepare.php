<?php

/**
 * Q-02 · 并发夹具：一个已发布活动 + 一个容量可控的报名 scope + 两个客户。
 *
 *   php qa/ecosystem/q02/prepare.php [capacity=1] [review_mode=auto]
 *
 * 只在独立测试库运行（bootstrap 硬校验）。输出单行 JSON：
 *   {marker, event_id, revision_id, scope_id, customer_ids:[...]}
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$capacity = array_key_exists(1, $argv) ? (int) $argv[1] : 1;
$reviewMode = (string) ($argv[2] ?? 'auto');

if (! in_array($reviewMode, ['auto', 'manual'], true)) {
    fwrite(STDERR, "review_mode must be auto|manual\n");
    exit(1);
}

$marker = 'QA-Q02-'.bin2hex(random_bytes(6));
$now = qa_now();
$opens = now('UTC')->subDay()->format('Y-m-d H:i:s');
$closes = now('UTC')->addDays(3)->format('Y-m-d H:i:s');
$starts = now('UTC')->addDay()->format('Y-m-d H:i:s');
$ends = now('UTC')->addDays(2)->format('Y-m-d H:i:s');

$eventId = (int) DB::table('events')->insertGetId([
    'name'               => $marker,
    'type'               => 'Q-02 concurrency',
    'status'             => 'registering',
    'kind'               => 'salon',
    'participation_mode' => 'offline',
    'publish_status'     => 'published',
    'disposition'        => 'normal',
    'hosting_status'     => 'scheduled',
    'timezone'           => 'Asia/Shanghai',
    'starts_at'          => $starts,
    'ends_at'            => $ends,
    'source_type'        => 'platform',
    'platform_role'      => 'organizer',
    'first_published_at' => $now,
    'published_at'       => $now,
    'row_version'        => 1,
    'created_at'         => $now,
    'updated_at'         => $now,
]);

$revisionId = (int) DB::table('event_revisions')->insertGetId([
    'event_id'                   => $eventId,
    'revision_no'                => 1,
    'name'                       => $marker,
    'summary'                    => 'Q-02 concurrency fixture',
    'detail_html'                => '<p>Q-02 fixture</p>',
    'city'                       => '上海',
    'location_text'              => '测试场馆',
    'participation_mode'         => 'offline',
    'timezone'                   => 'Asia/Shanghai',
    'starts_at'                  => $starts,
    'ends_at'                    => $ends,
    'source_type'                => 'platform',
    'platform_role'              => 'organizer',
    'organizer_name_snapshot'    => '召唤机甲',
    'source_name'                => 'Q-02 fixture',
    'source_url'                 => 'https://test.invalid/'.$marker,
    'source_verified_at'         => $now,
    'authorization_basis'        => 'self_organized',
    'content_fingerprint'        => hash('sha256', $marker),
    'sanitizer_version'          => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
    'review_state'               => 'approved',
    'change_summary'             => 'Q-02 fixture',
    'published_at'               => $now,
    'created_by'                 => 1,
    'created_at'                 => $now,
    'updated_at'                 => $now,
]);

DB::table('events')->where('id', $eventId)->update(['published_revision_id' => $revisionId]);

$scopeId = (int) DB::table('event_registration_scopes')->insertGetId([
    'event_id'               => $eventId,
    'scope_key'              => 'default',
    'unit'                   => 'person',
    'capacity'               => $capacity,
    'capacity_confirmed_at'  => $now,
    'confirmed_count'        => 0,
    'review_mode'            => $reviewMode,
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
    'form_schema'            => json_encode(['fields' => ['name', 'phone', 'city'], 'required' => ['name', 'phone']]),
    'privacy_version'        => '2026-10-19',
    'privacy_summary'        => 'Q-02 fixture privacy',
    'rule_version_id'        => null,
    'status'                 => 'open',
    'policy_confirmed_at'    => $now,
    'policy_confirmed_by'    => 1,
    'row_version'            => 0,
    'created_at'             => $now,
    'updated_at'             => $now,
]);

$customerIds = [];

foreach ([1, 2] as $index) {
    $customerIds[] = (int) DB::table('customers')->insertGetId([
        'first_name'  => 'QA',
        'last_name'   => 'Customer'.$index,
        'email'       => strtolower($marker).'-'.$index.'@example.test',
        'phone'       => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'status'      => 1,
        'is_verified' => 1,
        'password'    => bcrypt('secret123'),
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
}

qa_json([
    'marker'       => $marker,
    'event_id'     => $eventId,
    'revision_id'  => $revisionId,
    'scope_id'     => $scopeId,
    'capacity'     => $capacity,
    'review_mode'  => $reviewMode,
    'customer_ids' => $customerIds,
]);
