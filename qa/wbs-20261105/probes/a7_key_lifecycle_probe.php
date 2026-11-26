<?php

/**
 * A7 密钥生命周期探针：真实签发 → 轮换 → 再轮换 → 吊销，打印可核对的状态。
 *
 * 全部在一个事务里完成，最后 **rollback** —— 只取证，不往库里留垃圾。
 * 用法：DB_DATABASE=summon_mecha_step10_test php qa/wbs-20261105/probes/a7_key_lifecycle_probe.php
 */

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\DeviceKeyProvider;

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$did = 'did_a7_probe_'.bin2hex(random_bytes(4));
$credentials = app(DeviceCredentialService::class);
$provider = app(DeviceKeyProvider::class);

DB::beginTransaction();

try {
    $binding = DeviceBinding::create([
        'did'         => $did,
        'sn'          => 'SN_A7_PROBE_'.bin2hex(random_bytes(3)),
        'source'      => DeviceBinding::SOURCE_AGENT,
        'bind_status' => 'bound',
    ]);

    $issued = $credentials->issue($binding);
    $binding->refresh();
    $k1 = $credentials->factKeyId($binding);

    $r2 = $provider->rotate($did, 'probe');
    $c1 = $provider->resolve($did, $k1);
    $r3 = $provider->rotate($did, 'probe');
    $c2 = $provider->resolve($did, $r2?->keyId);

    $minEpoch = max(1, $provider->currentEpoch($did) - max(0, (int) config('deploy_gateways.device_credential.epoch_grace', 1)));

    $activeBeforeRevoke = $provider->activeKeyId($did);
    $revoked = $provider->revoke($did, (string) $activeBeforeRevoke, 'probe');

    $out = [
        'db' => DB::connection()->getDatabaseName(),
        'probe' => 'A7 key lifecycle',
        'capabilities' => $provider->capabilities(),
        'epochs' => [
            'after_issue' => 1,
            'after_rotate_1' => $r2?->epoch,
            'after_rotate_2' => $r3?->epoch,
            'current' => $provider->currentEpoch($did),
        ],
        'keys' => [
            'k1' => [
                'key_id_prefix' => substr((string) $k1, 0, 12),
                'status' => $c1?->status,
                'epoch' => $c1?->epoch,
                'usable_under_policy' => $c1?->isUsableAt(now()->timestamp, $minEpoch),
            ],
            'k2' => [
                'key_id_prefix' => substr((string) $r2?->keyId, 0, 12),
                'status' => $c2?->status,
                'epoch' => $c2?->epoch,
                'usable_under_policy' => $c2?->isUsableAt(now()->timestamp, $minEpoch),
            ],
            'k3' => [
                'key_id_prefix' => substr((string) $r3?->keyId, 0, 12),
                'status' => 'revoked (probe)',
            ],
        ],
        'min_accepted_epoch' => $minEpoch,
        'active_key_before_revoke_prefix' => substr((string) $activeBeforeRevoke, 0, 12),
        'active_key_after_revoke' => $provider->activeKeyId($did),
        'revoke_ok' => $revoked,
        'certificate_pem_present' => isset($issued['certificate_pem']) && str_contains($issued['certificate_pem'], 'BEGIN CERTIFICATE'),
    ];

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    DB::rollBack();
}
