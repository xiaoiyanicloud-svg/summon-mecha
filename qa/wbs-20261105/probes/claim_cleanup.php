<?php

/** B1 claim 探针：查询命令终态并清理（只删该 id）。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$id = (int) ($argv[1] ?? 0);

if ($id <= 0) {
    fwrite(STDERR, "command id required\n");
    exit(2);
}

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$row = DB::table('skill_build_commands')->where('id', $id)->first();

$evidence = [
    'id'          => $id,
    'status'      => $row?->status,
    'worker_id'   => $row?->worker_id,
    'started_at'  => $row?->started_at,
    'finished_at' => $row?->finished_at,
];

$deleted = DB::table('skill_build_commands')->where('id', $id)->delete();

echo json_encode($evidence + ['deleted' => $deleted]).PHP_EOL;
