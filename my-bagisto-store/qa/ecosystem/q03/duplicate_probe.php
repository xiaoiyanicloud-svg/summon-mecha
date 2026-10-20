<?php

/**
 * Q-03 · 重复投递探针：把已 sent 的同一条事实重置为 pending，交给 worker 再消费一次。
 *
 *   php duplicate_probe.php <outbox_id>
 *
 * 通知写入按 dedupe_key firstOrCreate，因此第二轮不得新增站内信。
 * runner 在第二轮前后比较 notification 行数。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$outboxId = (int) ($argv[1] ?? 0);

if ($outboxId <= 0) {
    fwrite(STDERR, "usage: duplicate_probe.php <outbox_id>\n");
    exit(1);
}

$row = DB::table('ecosystem_outbox')->where('id', $outboxId)->first();

if ($row === null) {
    qa_json(['ok' => false, 'error' => 'outbox_not_found', 'outbox_id' => $outboxId]);
    exit(1);
}

$before = (string) $row->status;

DB::table('ecosystem_outbox')->where('id', $outboxId)->update([
    'status'       => 'pending',
    'attempts'     => 0,
    'available_at' => now('UTC')->subSeconds(1)->format('Y-m-d H:i:s'),
    'lease_owner'  => null,
    'lease_until'  => null,
    'last_error'   => null,
    'updated_at'   => now(),
]);

qa_json([
    'outbox_id'     => $outboxId,
    'dedupe_key'    => (string) $row->dedupe_key,
    'event_type'    => (string) $row->event_type,
    'status_before' => $before,
    'reset'         => true,
]);
