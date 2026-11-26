<?php

/** B1 claim 探针：创建一条 queued 命令，打印 id/key。 */

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillBuildCommand;

if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
    fwrite(STDERR, "refuse: not isolated test db\n");
    exit(3);
}

$record = SkillBuildCommand::create([
    'seller_id'             => 1,
    'idempotency_key'       => 'claim_probe_'.bin2hex(random_bytes(8)),
    'request_hash'          => str_repeat('0', 64),
    'status'                => SkillBuildCommand::STATUS_QUEUED,
    'skill_version_id'      => 960001,
    'source_asset_id'       => 960002,
    'source_digest'         => 'sha256:'.str_repeat('a', 64),
    'target_model_code'     => 'unitree_g1',
    'target_profile_digest' => 'sha256:'.str_repeat('b', 64),
    'compiler_version'      => 'portable-v1',
    'requested_by'          => 'claim-probe',
]);

echo json_encode(['id' => (int) $record->id, 'key' => $record->idempotency_key]).PHP_EOL;
