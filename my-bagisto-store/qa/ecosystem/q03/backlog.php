<?php

/**
 * Q-03 · 积压探针：写入 N 条合法报名 outbox，验证 worker 分页续跑。
 *
 *   php backlog.php <attempt_id> <registration_id> <event_id> <status> <row_version> <count>
 *
 * 默认 batch_size=20；count=25 时必须两轮才能清空。
 * 每条使用独立 dedupe_key，因此站内信也应恰好 N 条。
 */

require __DIR__.'/../bootstrap.php';

use Webkul\Shop\Services\Ecosystem\OutboxWriter;

$attemptId = (int) ($argv[1] ?? 0);
$registrationId = (int) ($argv[2] ?? 0);
$eventId = (int) ($argv[3] ?? 0);
$status = (string) ($argv[4] ?? '');
$rowVersion = (int) ($argv[5] ?? 0);
$count = (int) ($argv[6] ?? 25);

if ($attemptId <= 0 || $registrationId <= 0 || $eventId <= 0 || $status === '' || $count <= 0) {
    fwrite(STDERR, "usage: backlog.php <attempt_id> <registration_id> <event_id> <status> <row_version> <count>\n");
    exit(1);
}

$prefix = 'qa-q03-backlog-'.bin2hex(random_bytes(6));
$writer = app(OutboxWriter::class);
$ids = [];

for ($index = 1; $index <= $count; $index++) {
    $row = $writer->write('registration', $registrationId, 'registration.submitted', [
        'event_id'            => $eventId,
        'registration_id'     => $registrationId,
        'attempt_id'          => $attemptId,
        'status'              => $status,
        'attempt_row_version' => $rowVersion,
    ], $prefix.'-'.$index);

    $ids[] = (int) $row->id;
}

qa_json([
    'prefix' => $prefix,
    'count'  => count($ids),
    'ids'    => $ids,
]);
