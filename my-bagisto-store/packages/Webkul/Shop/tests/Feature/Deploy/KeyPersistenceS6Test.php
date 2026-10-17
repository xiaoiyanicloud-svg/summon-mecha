<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\KeyIdentity;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * S-6 · 密钥持久化与命令化。
 *
 * 覆盖：
 *   · 密钥位置从 config 读（改 AGENT_KEYS_DIR 即换目录）；
 *   · 缺一个文件时**从同一把私钥重建**（公钥/证书），不重签整对/整张 CA；
 *   · 三条命令的幂等 / 拒绝覆盖 / --replace 先备份 / 导入前全量校验 / 导出导入往返。
 *
 * ⚠ 每个用例都用 `Storage::fake('local')` + 独立子目录，绝不碰真实密钥。
 */

beforeEach(function () {
    $this->keysDir = 'keys_s6_'.bin2hex(random_bytes(6));

    Storage::fake('local');

    config([
        'deploy_gateways.keys.disk' => 'local',
        'deploy_gateways.keys.dir'  => $this->keysDir,
    ]);

    $this->tempDirs = [];
});

afterEach(function () {
    foreach ($this->tempDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

function s6TempDir(object $test): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'s6-'.bin2hex(random_bytes(8));

    $test->tempDirs[] = $dir;

    return $dir;
}

/**
 * Windows 上 php 的 openssl 常缺 openssl.cnf —— 与生产代码同一处置。
 */
function s6KeyOpts(): array
{
    $opts = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
    if (is_file($cnf)) {
        $opts['config'] = $cnf;
    }

    return $opts;
}

it('a) 配置 AGENT_KEYS_DIR 后，读写都落在新目录', function () {
    $signing = app(SigningService::class);

    $publicPem = $signing->publicKeyPem();

    expect($publicPem)->toContain('BEGIN PUBLIC KEY')
        ->and(Storage::disk('local')->exists($this->keysDir.'/platform_private.pem'))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->keysDir.'/platform_public.pem'))->toBeTrue()
        // 旧硬编码位置不得被写入
        ->and(Storage::disk('local')->exists('keys/platform_private.pem'))->toBeFalse()
        ->and(Storage::disk('local')->exists('keys/platform_public.pem'))->toBeFalse();

    // 配置指向哪里，签名/验签就在哪里成立
    $signature = $signing->sign('s6-config-dir');
    expect($signing->verify('s6-config-dir', $signature))->toBeTrue();
});

it('b) 私钥在、公钥缺 → 公钥被重建且与私钥匹配（私钥字节不变）', function () {
    $signing = app(SigningService::class);
    $signing->publicKeyPem(); // 先完整生成

    $privatePath = $this->keysDir.'/platform_private.pem';
    $publicPath = $this->keysDir.'/platform_public.pem';

    $privateBefore = Storage::disk('local')->get($privatePath);
    Storage::disk('local')->delete($publicPath);

    $rebuilt = $signing->publicKeyPem();

    $derived = KeyIdentity::publicPemFromPrivate($privateBefore);

    expect($derived)->not->toBeNull()
        ->and(KeyIdentity::samePublicKey($rebuilt, $derived))->toBeTrue()
        ->and(Storage::disk('local')->get($privatePath))->toBe($privateBefore)
        ->and(Storage::disk('local')->exists($publicPath))->toBeTrue();
});

it('c) CA 私钥在、证书缺 → 重签后 CA 公钥不变，老设备证书仍能验证', function () {
    $device = app(DeviceCredentialService::class);
    $oldCaCert = $device->caCertPem(); // 触发首次生成

    $caPrivatePath = $this->keysDir.'/device_ca_private.pem';
    $caCertPath = $this->keysDir.'/device_ca_public.pem';
    $caPrivateBefore = Storage::disk('local')->get($caPrivatePath);

    // 用旧 CA 签一张"老设备"证书
    $opts = s6KeyOpts();
    $caKey = openssl_pkey_get_private($caPrivateBefore);
    $caCert = openssl_x509_read($oldCaCert);

    $deviceKey = openssl_pkey_new($opts);
    $csr = openssl_csr_new(['commonName' => 'did_s6_old_device'], $deviceKey, $opts);
    $deviceCert = openssl_csr_sign($csr, $caCert, $caKey, 30, $opts, 424242);
    openssl_x509_export($deviceCert, $deviceCertPem);

    expect(openssl_x509_verify($deviceCertPem, $oldCaCert))->toBe(1);

    // 只删证书，私钥保留
    Storage::disk('local')->delete($caCertPath);

    $newCaCert = $device->caCertPem();

    $derivedPublic = openssl_pkey_get_details(openssl_pkey_get_private($caPrivateBefore))['key'];
    $newCertPublic = openssl_pkey_get_details(openssl_pkey_get_public($newCaCert))['key'];

    expect($newCertPublic)->toBe($derivedPublic)
        // 证书内容确实重签了（序列号/有效期不同），但公钥没变
        ->and(KeyIdentity::certFingerprint($newCaCert))->not->toBe(KeyIdentity::certFingerprint($oldCaCert))
        // 老设备的证书仍能链到新证书（因为 CA 公钥未变）
        ->and(openssl_x509_verify($deviceCertPem, $newCaCert))->toBe(1)
        // CA 私钥逐字未动
        ->and(Storage::disk('local')->get($caPrivatePath))->toBe($caPrivateBefore);
});

it('d) 两个文件都缺 → 生成新密钥对/新 CA（既有行为不回归）', function () {
    $signing = app(SigningService::class);

    $signature = $signing->sign('s6-fresh');
    expect($signing->verify('s6-fresh', $signature))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->keysDir.'/platform_private.pem'))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->keysDir.'/platform_public.pem'))->toBeTrue();

    $device = app(DeviceCredentialService::class);
    $caCert = $device->caCertPem();

    expect(KeyIdentity::isSelfSigned($caCert))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->keysDir.'/device_ca_private.pem'))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->keysDir.'/device_ca_public.pem'))->toBeTrue();
});

it('e1) agent:keys:generate 幂等：已存在不覆盖（hash 与 mtime 不变）', function () {
    expect(Artisan::call('agent:keys:generate'))->toBe(0);

    $leaves = [
        'platform_private.pem',
        'platform_public.pem',
        'device_ca_private.pem',
        'device_ca_public.pem',
    ];

    $before = [];
    foreach ($leaves as $leaf) {
        $path = $this->keysDir.'/'.$leaf;
        $before[$leaf] = [
            'sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'mtime'  => Storage::disk('local')->lastModified($path),
        ];
    }

    // 让 mtime 有可观测的差异窗口
    sleep(1);

    expect(Artisan::call('agent:keys:generate'))->toBe(0);

    foreach ($leaves as $leaf) {
        $path = $this->keysDir.'/'.$leaf;

        expect(hash('sha256', Storage::disk('local')->get($path)))->toBe($before[$leaf]['sha256'])
            ->and(Storage::disk('local')->lastModified($path))->toBe($before[$leaf]['mtime']);
    }

    expect(Artisan::output())->toContain('已存在');
});

it('e2) agent:keys:generate --replace 会先备份再生成', function () {
    expect(Artisan::call('agent:keys:generate'))->toBe(0);

    $privatePath = $this->keysDir.'/platform_private.pem';
    $oldPrivate = Storage::disk('local')->get($privatePath);

    expect(Artisan::call('agent:keys:generate', ['--replace' => true]))->toBe(0);

    $newPrivate = Storage::disk('local')->get($privatePath);

    expect($newPrivate)->not->toBe($oldPrivate);

    $backupDirs = collect(Storage::disk('local')->directories($this->keysDir))
        ->filter(fn (string $dir) => str_starts_with(basename($dir), 'backup-'))
        ->values();

    expect($backupDirs)->not->toBeEmpty()
        ->and($backupDirs->contains(fn (string $dir) => Storage::disk('local')->get($dir.'/platform_private.pem') === $oldPrivate))->toBeTrue();
});

it('f) agent:keys:import 遇已存在默认 FAILURE；--replace 成功且旧文件进 backup-*', function () {
    // 备份源：生成一套并导出
    expect(Artisan::call('agent:keys:generate'))->toBe(0);
    $sourceDir = s6TempDir($this);
    expect(Artisan::call('agent:keys:export', ['--to' => $sourceDir]))->toBe(0);

    $exportedPrivate = file_get_contents($sourceDir.'/platform_private.pem');
    expect($exportedPrivate)->not->toBeFalse();

    // 目标：换一个目录，生成另一套（与备份不同）
    $targetDir = 'keys_s6_target_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $targetDir]);

    expect(Artisan::call('agent:keys:generate'))->toBe(0);

    $targetPrivatePath = $targetDir.'/platform_private.pem';
    $targetOldPrivate = Storage::disk('local')->get($targetPrivatePath);

    expect($targetOldPrivate)->not->toBe($exportedPrivate);

    // 默认拒绝覆盖
    expect(Artisan::call('agent:keys:import', ['--from' => $sourceDir]))->toBe(1);
    expect(Storage::disk('local')->get($targetPrivatePath))->toBe($targetOldPrivate);

    // --replace：成功，且旧文件在 backup-* 里
    expect(Artisan::call('agent:keys:import', ['--from' => $sourceDir, '--replace' => true]))->toBe(0);
    expect(Storage::disk('local')->get($targetPrivatePath))->toBe($exportedPrivate);

    $backupDirs = collect(Storage::disk('local')->directories($targetDir))
        ->filter(fn (string $dir) => str_starts_with(basename($dir), 'backup-'))
        ->values();

    expect($backupDirs)->not->toBeEmpty()
        ->and($backupDirs->contains(fn (string $dir) => Storage::disk('local')->get($dir.'/platform_private.pem') === $targetOldPrivate))->toBeTrue();
});

it('g1) agent:keys:import 公私钥不匹配 → FAILURE 且不落盘', function () {
    $sourceDir = s6TempDir($this);
    mkdir($sourceDir, 0700, true);

    $opts = s6KeyOpts();

    $keyA = openssl_pkey_new($opts);
    openssl_pkey_export($keyA, $privateA);

    $keyB = openssl_pkey_new($opts);
    $publicB = openssl_pkey_get_details($keyB)['key'];

    file_put_contents($sourceDir.'/platform_private.pem', $privateA);
    file_put_contents($sourceDir.'/platform_public.pem', $publicB);

    // CA 用一套合法的（证明失败原因只可能是公私钥错配）
    $caSourceDir = 'keys_s6_casrc_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $caSourceDir]);
    app(DeviceCredentialService::class)->caCertPem();

    file_put_contents($sourceDir.'/device_ca_private.pem', Storage::disk('local')->get($caSourceDir.'/device_ca_private.pem'));
    file_put_contents($sourceDir.'/device_ca_public.pem', Storage::disk('local')->get($caSourceDir.'/device_ca_public.pem'));

    $targetDir = 'keys_s6_bad_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $targetDir]);

    expect(Artisan::call('agent:keys:import', ['--from' => $sourceDir]))->toBe(1)
        ->and(Storage::disk('local')->allFiles($targetDir))->toBe([])
        ->and(Storage::disk('local')->directories($targetDir))->toBe([]);
});

it('g2) agent:keys:import 私钥损坏 → FAILURE 且不落盘', function () {
    $sourceDir = s6TempDir($this);
    mkdir($sourceDir, 0700, true);

    file_put_contents($sourceDir.'/platform_private.pem', "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----\n");
    file_put_contents($sourceDir.'/platform_public.pem', "-----BEGIN PUBLIC KEY-----\nnot-a-real-key\n-----END PUBLIC KEY-----\n");

    $caSourceDir = 'keys_s6_casrc_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $caSourceDir]);
    app(DeviceCredentialService::class)->caCertPem();

    file_put_contents($sourceDir.'/device_ca_private.pem', Storage::disk('local')->get($caSourceDir.'/device_ca_private.pem'));
    file_put_contents($sourceDir.'/device_ca_public.pem', Storage::disk('local')->get($caSourceDir.'/device_ca_public.pem'));

    $targetDir = 'keys_s6_bad2_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $targetDir]);

    expect(Artisan::call('agent:keys:import', ['--from' => $sourceDir]))->toBe(1)
        ->and(Storage::disk('local')->allFiles($targetDir))->toBe([])
        ->and(Storage::disk('local')->directories($targetDir))->toBe([]);
});

it('h) export → import 往返：导出目录能原样导入（关键文件 hash 一致）', function () {
    expect(Artisan::call('agent:keys:generate'))->toBe(0);

    $sourceDir = s6TempDir($this);
    expect(Artisan::call('agent:keys:export', ['--to' => $sourceDir]))->toBe(0);

    $leaves = [
        'platform_private.pem',
        'platform_public.pem',
        'device_ca_private.pem',
        'device_ca_public.pem',
    ];

    $expected = [];
    foreach ($leaves as $leaf) {
        $expected[$leaf] = Storage::disk('local')->get($this->keysDir.'/'.$leaf);
    }

    // 导入到一个空目录
    $newDir = 'keys_s6_roundtrip_'.bin2hex(random_bytes(4));
    config(['deploy_gateways.keys.dir' => $newDir]);

    expect(Artisan::call('agent:keys:import', ['--from' => $sourceDir]))->toBe(0);

    foreach ($leaves as $leaf) {
        expect(hash('sha256', Storage::disk('local')->get($newDir.'/'.$leaf)))
            ->toBe(hash('sha256', $expected[$leaf]));
    }

    // 导入后的密钥真的能签能验
    $signing = app(SigningService::class);
    $signature = $signing->sign('s6-roundtrip');
    expect($signing->verify('s6-roundtrip', $signature))->toBeTrue();
});
