<?php

/**
 * T3 · 并发密钥轮换 / 吊销（WBS A7 的并发证据）。
 *
 * 做法与 `SourcePackageConcurrencyTest` 同源：**独立 PHP 进程**才叫并发，
 * 同进程线程只是测 PHP 单线程。子进程自带 Laravel bootstrap，各自建连接、各自提交。
 *
 * 为什么数据全在子进程里建：Pest 用 `DatabaseTransactions`，父进程建的行未提交，
 * 另起的 PHP 进程**看不见**。所以 setup / 并发 / 校验 / 清理全部由子进程完成，
 * 父进程只解析子进程的 stdout —— 绝不允许因为"子进程读不到数据"就放宽断言。
 *
 * 并发的同步方式：每个子进程拿到未来的 `start_at` 后忙等，全部就绪后同一瞬间进入
 * `issue()` / `revokeKey()` —— 用 Start-Sleep 制造"同时"不算并发。
 */

function b10CredChildScript(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $script = <<<'PHP'
<?php
require $argv[1];
$app = require $argv[2];
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$payload = json_decode($argv[3], true) ?: [];
$mode = $payload['mode'] ?? 'noop';
$bindingId = (int) ($payload['binding_id'] ?? 0);
$out = ['mode' => $mode, 'ok' => false];

$find = function () use ($bindingId) {
    return $bindingId > 0 ? Webkul\Shop\Models\DeviceBinding::query()->find($bindingId) : null;
};

$waitUntil = function () use ($payload) {
    $startAt = (float) ($payload['start_at'] ?? 0);
    while (microtime(true) < $startAt) {
        usleep(500);
    }
};

try {
    $svc = app(Webkul\Shop\Services\Deploy\DeviceCredentialService::class);

    switch ($mode) {
        case 'setup':
            // CA 必须在并发之前生成：否则 6 个进程会同时自签出不同的 CA，
            // 各自签发的证书互相链不上 —— 那是另一个问题，不该混进"密钥环并发"用例。
            $svc->ensureCa();
            $b = Webkul\Shop\Models\DeviceBinding::create([
                'did' => $payload['did'],
                'sn' => 'B10-'.$payload['did'],
                'source' => Webkul\Shop\Models\DeviceBinding::SOURCE_AGENT,
                'bind_status' => 'bound',
                'bound_at' => now(),
            ]);
            $out = ['mode' => $mode, 'ok' => true, 'binding_id' => $b->id, 'did' => $b->did];
            break;

        case 'issue':
            $waitUntil();
            $binding = $find();
            if (! $binding) { throw new RuntimeException('binding not found'); }
            $cred = $svc->issue($binding);
            // 用**这一把自己的证书**算 key_id（不能读 active —— 并发下 active 可能是别人的）
            $pub = openssl_pkey_get_public($cred['certificate_pem']);
            $details = openssl_pkey_get_details($pub);
            $keyPem = trim(str_replace(["\r\n", "\r"], "\n", (string) ($details['key'] ?? '')));
            $keyId = hash('sha256', $keyPem);
            file_put_contents($payload['key_file'], json_encode([
                'key_id' => $keyId,
                'private' => $cred['private_key_pem'],
                'cert' => $cred['certificate_pem'],
            ]));
            $fresh = $find();
            $ring = $svc->keyRing($fresh);
            $out = [
                'mode' => $mode, 'ok' => true, 'key_id' => $keyId,
                'epoch' => $ring['epoch'], 'key_count' => count($ring['keys']),
                'done_at' => microtime(true),
            ];
            break;

        case 'revoke':
            $waitUntil();
            $binding = $find();
            if (! $binding) { throw new RuntimeException('binding not found'); }
            $ok = $svc->revokeKey($binding, (string) $payload['key_id'], 'b10-concurrency');
            $fresh = $find();
            $ring = $svc->keyRing($fresh);
            $entry = $ring['keys'][(string) $payload['key_id']] ?? [];
            $out = [
                'mode' => $mode, 'ok' => $ok,
                'revoked_at' => $entry['revoked_at'] ?? null,
                'status' => $entry['status'] ?? null,
                'done_at' => microtime(true),
            ];
            break;

        case 'monitor':
            $deadline = microtime(true) + (float) $payload['duration'];
            $prev = -1; $decreases = 0; $parseFailures = 0; $samples = 0;
            while (microtime(true) < $deadline) {
                $ring = $svc->keyRing($find());
                $raw = json_encode($ring);
                if ($raw === false || json_decode($raw, true) === null && $ring !== []) {
                    $parseFailures++;
                }
                $epoch = (int) $ring['epoch'];
                if ($prev >= 0 && $epoch < $prev) { $decreases++; }
                $prev = $epoch; $samples++;
                usleep(10000);
            }
            $out = ['mode' => $mode, 'ok' => true, 'decreases' => $decreases,
                    'parse_failures' => $parseFailures, 'samples' => $samples, 'final_epoch' => $prev];
            break;

        case 'verify':
            $fresh = $find();
            $ring = $svc->keyRing($fresh);
            $out = [
                'mode' => $mode, 'ok' => true,
                'epoch' => $ring['epoch'],
                'count' => count($ring['keys']),
                'active' => $svc->activeKeyId($fresh),
                'keys' => [],
            ];
            foreach ($ring['keys'] as $id => $entry) {
                $out['keys'][$id] = [
                    'epoch' => (int) ($entry['epoch'] ?? 0),
                    'status' => $entry['status'] ?? '',
                    'revoked_at' => $entry['revoked_at'] ?? null,
                ];
            }
            if (! empty($payload['revoke_key_id'])) {
                $out['revoke_result'] = $svc->revokeKey($fresh, (string) $payload['revoke_key_id'], 'b10-verify');
                $fresh = $find();
                $ring = $svc->keyRing($fresh);
                $out['epoch_after_revoke'] = $ring['epoch'];
                $out['keys_after_revoke'] = [];
                foreach ($ring['keys'] as $id => $entry) {
                    $out['keys_after_revoke'][$id] = [
                        'epoch' => (int) ($entry['epoch'] ?? 0),
                        'status' => $entry['status'] ?? '',
                        'revoked_at' => $entry['revoked_at'] ?? null,
                    ];
                }
            }
            if (! empty($payload['key_files'])) {
                $canonical = (string) ($payload['canonical'] ?? 'b10-canonical');
                $out['verify'] = [];
                foreach ((array) $payload['key_files'] as $file) {
                    $k = json_decode((string) @file_get_contents($file), true) ?: [];
                    $id = (string) ($k['key_id'] ?? '');
                    $priv = openssl_pkey_get_private((string) ($k['private'] ?? ''));
                    if ($priv === false) { $out['verify'][$id] = false; continue; }
                    $sig = '';
                    openssl_sign($canonical, $sig, $priv, OPENSSL_ALGO_SHA256);
                    $out['verify'][$id] = $svc->verifyDetachedSignature($fresh, $canonical, base64_encode($sig), $id);
                }
            }
            break;

        case 'cleanup':
            if ($bindingId > 0) {
                Webkul\Shop\Models\DeviceBinding::query()->whereKey($bindingId)->delete();
            }
            $out = ['mode' => $mode, 'ok' => true];
            break;

        default:
            $out = ['mode' => $mode, 'ok' => false, 'error' => 'unknown mode'];
    }
} catch (\Throwable $e) {
    $out = ['mode' => $mode, 'ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
}

echo json_encode($out);
PHP;

    $path = tempnam(sys_get_temp_dir(), 'b10cred').'.php';
    file_put_contents($path, $script);

    return $path;
}

function b10CredEnv(): array
{
    $env = getenv();
    $env['DB_DATABASE'] = 'summon_mecha_step10_test';
    $env['APP_ENV'] = 'testing';

    return $env;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function b10CredRun(array $payload): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, b10CredChildScript(), base_path('vendor/autoload.php'), base_path('bootstrap/app.php'), json_encode($payload)],
        $descriptors,
        $pipes,
        base_path(),
        b10CredEnv()
    );

    expect($process)->not->toBeFalse();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, "子进程失败: {$stderr}\nstdout: {$stdout}");

    $decoded = json_decode(trim($stdout), true);
    expect($decoded)->toBeArray("子进程输出不是 JSON: {$stdout}\nstderr: {$stderr}");

    return $decoded;
}

/**
 * 并发启动一组子进程；每个 payload 里带同一个未来 `start_at`，子进程忙等到点后一起执行。
 *
 * @param  array<int, array<string, mixed>>  $payloads
 * @return array<int, array<string, mixed>>
 */
function b10CredRunConcurrently(array $payloads, float $leadSeconds = 1.2): array
{
    $startAt = microtime(true) + $leadSeconds;
    $processes = [];

    foreach ($payloads as $index => $payload) {
        $payload['start_at'] = $startAt;
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, b10CredChildScript(), base_path('vendor/autoload.php'), base_path('bootstrap/app.php'), json_encode($payload)],
            $descriptors,
            $pipes,
            base_path(),
            b10CredEnv()
        );
        expect($process)->not->toBeFalse();
        $processes[$index] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as $index => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, "子进程 #{$index} 失败: {$stderr}");
        $decoded = json_decode(trim($stdout), true);
        expect($decoded)->toBeArray("子进程 #{$index} 输出不是 JSON: {$stdout}");
        $results[$index] = $decoded;
    }

    return $results;
}

function b10CredSetup(): int
{
    $result = b10CredRun(['mode' => 'setup', 'did' => 'did_b10_cred_'.uniqid()]);

    expect($result['ok'])->toBeTrue($result['error'] ?? 'setup failed');

    return (int) $result['binding_id'];
}

it('A7-CONC-01 · 6 进程并发 issue() 同一 binding：密钥环恰好 6 把且 key_id 无重复', function () {
    $bindingId = b10CredSetup();
    $keyFiles = [];
    $payloads = [];

    try {
        for ($i = 0; $i < 6; $i++) {
            $keyFiles[$i] = tempnam(sys_get_temp_dir(), "b10key{$i}").'.json';
            $payloads[] = [
                'mode'       => 'issue',
                'binding_id' => $bindingId,
                'key_file'   => $keyFiles[$i],
            ];
        }

        $results = b10CredRunConcurrently($payloads);

        $reportedIds = [];
        foreach ($results as $index => $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? "issue #{$index} failed");
            expect($result['key_id'])->toMatch('/^[0-9a-f]{64}$/');
            $reportedIds[] = $result['key_id'];
        }

        expect(array_unique($reportedIds))->toHaveCount(6, '6 个进程各自签出的 key_id 必须互不相同');

        $verify = b10CredRun(['mode' => 'verify', 'binding_id' => $bindingId]);

        expect($verify['ok'])->toBeTrue()
            ->and($verify['count'])->toBe(6, '并发 issue 丢更新：密钥环里必须恰好 6 把钥匙')
            ->and(array_keys($verify['keys']))->toHaveCount(6);

        // 每个进程自报的 key 都必须真的在环里（不能是"写进去了又被别人覆盖掉"）
        foreach ($reportedIds as $keyId) {
            expect(array_key_exists($keyId, $verify['keys']))->toBeTrue("key_id {$keyId} 不在最终密钥环中");
        }

        // epoch 必须严格递增到 6（每次 issue +1），且全部 active→retired/active
        expect($verify['epoch'])->toBe(6);
    } finally {
        b10CredRun(['mode' => 'cleanup', 'binding_id' => $bindingId]);
        foreach ($keyFiles as $file) {
            @unlink($file);
        }
    }
});

it('A7-CONC-02 · 6 进程并发吊销同一把 key：只写一次 revoked_at、结果幂等、无崩溃', function () {
    $bindingId = b10CredSetup();
    $keyFile = tempnam(sys_get_temp_dir(), 'b10revoke').'.json';

    try {
        $issued = b10CredRun([
            'mode'       => 'issue',
            'binding_id' => $bindingId,
            'key_file'   => $keyFile,
        ]);
        expect($issued['ok'])->toBeTrue($issued['error'] ?? 'issue failed');
        $keyId = (string) $issued['key_id'];

        $payloads = array_fill(0, 6, [
            'mode'       => 'revoke',
            'binding_id' => $bindingId,
            'key_id'     => $keyId,
        ]);

        $results = b10CredRunConcurrently($payloads);

        $timestamps = [];
        foreach ($results as $index => $result) {
            expect($result['ok'])->toBeTrue("并发吊销 #{$index} 必须幂等成功: ".($result['error'] ?? ''))
                ->and($result['status'])->toBe('revoked');
            $timestamps[] = (string) $result['revoked_at'];
        }

        // 只写一次：6 个进程看到的吊销时间必须是同一个值
        expect(array_unique($timestamps))->toHaveCount(1, '并发吊销把 revoked_at 写了多次: '.implode(' / ', array_unique($timestamps)));

        $first = b10CredRun(['mode' => 'verify', 'binding_id' => $bindingId]);
        $revokedAt = $first['keys'][$keyId]['revoked_at'] ?? null;
        expect($revokedAt)->not->toBeNull();

        // 前后两次读取一致（吊销时间不会再被改写）
        usleep(1100000);
        $second = b10CredRun(['mode' => 'verify', 'binding_id' => $bindingId]);

        expect($second['keys'][$keyId]['revoked_at'] ?? null)->toBe($revokedAt)
            ->and($second['keys'][$keyId]['status'] ?? null)->toBe('revoked');
    } finally {
        b10CredRun(['mode' => 'cleanup', 'binding_id' => $bindingId]);
        @unlink($keyFile);
    }
});

it('A7-CONC-03 · 并发 issue + revoke 混跑：epoch 单调不减，密钥环 JSON 每次都能解析', function () {
    $bindingId = b10CredSetup();
    $keyFile = tempnam(sys_get_temp_dir(), 'b10mixed').'.json';

    try {
        $issued = b10CredRun([
            'mode'       => 'issue',
            'binding_id' => $bindingId,
            'key_file'   => $keyFile,
        ]);
        expect($issued['ok'])->toBeTrue($issued['error'] ?? 'issue failed');
        $victimKey = (string) $issued['key_id'];
        $baseEpoch = (int) $issued['epoch'];

        // 3 个 issue + 3 个 revoke（吊销同一把旧 key：幂等，不会崩）
        $payloads = [];
        for ($i = 0; $i < 3; $i++) {
            $payloads[] = [
                'mode'       => 'issue',
                'binding_id' => $bindingId,
                'key_file'   => tempnam(sys_get_temp_dir(), "b10mix{$i}").'.json',
            ];
        }
        for ($i = 0; $i < 3; $i++) {
            $payloads[] = ['mode' => 'revoke', 'binding_id' => $bindingId, 'key_id' => $victimKey];
        }

        $results = b10CredRunConcurrently($payloads);

        foreach (array_slice($results, 0, 3) as $index => $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? "issue #{$index} failed");
        }

        // 混跑期间由独立进程持续采样：epoch 不能减小、JSON 不能解析失败
        $monitor = b10CredRun(['mode' => 'monitor', 'binding_id' => $bindingId, 'duration' => 0.2]);

        expect($monitor['ok'])->toBeTrue()
            ->and($monitor['decreases'])->toBe(0, 'epoch 出现了回退')
            ->and($monitor['parse_failures'])->toBe(0, '密钥环 JSON 解析失败');

        $verify = b10CredRun(['mode' => 'verify', 'binding_id' => $bindingId]);

        expect($verify['ok'])->toBeTrue()
            ->and($verify['epoch'])->toBe($baseEpoch + 3)
            ->and($verify['count'])->toBe(4);
    } finally {
        b10CredRun(['mode' => 'cleanup', 'binding_id' => $bindingId]);
        @unlink($keyFile);
        foreach ($payloads ?? [] as $payload) {
            if (($payload['mode'] ?? '') === 'issue' && isset($payload['key_file'])) {
                @unlink($payload['key_file']);
            }
        }
    }
});

it('A7-CONC-04 · 吊销后该 key 验签必失败，其它未吊销 key 仍可验证（吊销不误伤）', function () {
    $bindingId = b10CredSetup();
    $keyFiles = [];

    try {
        for ($i = 0; $i < 3; $i++) {
            $keyFiles[$i] = tempnam(sys_get_temp_dir(), "b10v{$i}").'.json';
        }

        $results = b10CredRunConcurrently(array_map(fn (string $file) => [
            'mode'       => 'issue',
            'binding_id' => $bindingId,
            'key_file'   => $file,
        ], $keyFiles));

        $ids = [];
        foreach ($results as $index => $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? "issue #{$index} failed");
            $ids[] = (string) $result['key_id'];
        }

        $verify = b10CredRun([
            'mode'          => 'verify',
            'binding_id'    => $bindingId,
            'revoke_key_id' => $ids[0],
            'key_files'     => $keyFiles,
            'canonical'     => 'b10|detached|'.uniqid(),
        ]);

        expect($verify['ok'])->toBeTrue($verify['error'] ?? '')
            ->and($verify['revoke_result'])->toBeTrue()
            ->and($verify['keys_after_revoke'][$ids[0]]['status'] ?? null)->toBe('revoked');

        // 被吊销的那把：验签必须失败（fail-closed）
        expect($verify['verify'][$ids[0]])->toBeFalse('吊销后的密钥仍然验签通过 —— 吊销形同虚设');

        // 未吊销的其它把：仍可验证，且吊销没有改变 epoch
        expect($verify['verify'][$ids[1]])->toBeTrue()
            ->and($verify['verify'][$ids[2]])->toBeTrue()
            ->and($verify['epoch_after_revoke'])->toBe($verify['epoch']);
    } finally {
        b10CredRun(['mode' => 'cleanup', 'binding_id' => $bindingId]);
        foreach ($keyFiles as $file) {
            @unlink($file);
        }
    }
});

it('A7-CONC-05 · 并发签发后 activeKeyId 恰好指向 epoch 最大的未吊销 key', function () {
    $bindingId = b10CredSetup();
    $keyFiles = [];

    try {
        for ($i = 0; $i < 6; $i++) {
            $keyFiles[$i] = tempnam(sys_get_temp_dir(), "b10a{$i}").'.json';
        }

        $results = b10CredRunConcurrently(array_map(fn (string $file) => [
            'mode'       => 'issue',
            'binding_id' => $bindingId,
            'key_file'   => $file,
        ], $keyFiles));

        foreach ($results as $index => $result) {
            expect($result['ok'])->toBeTrue($result['error'] ?? "issue #{$index} failed");
        }

        $verify = b10CredRun(['mode' => 'verify', 'binding_id' => $bindingId]);
        $active = $verify['active'];

        expect($active)->not->toBeNull('activeKeyId 不得为 null');

        // 计算"epoch 最大的未吊销 key"
        $expected = null;
        $bestEpoch = -1;
        foreach ($verify['keys'] as $id => $entry) {
            $isRevoked = ($entry['status'] ?? '') === 'revoked' || ! empty($entry['revoked_at']);
            if ($isRevoked) {
                continue;
            }
            $epoch = (int) $entry['epoch'];
            if ($epoch > $bestEpoch) {
                $bestEpoch = $epoch;
                $expected = $id;
            }
        }

        expect($expected)->not->toBeNull()
            ->and($active)->toBe($expected)
            ->and($verify['keys'][$active]['status'] ?? null)->toBe('active')
            ->and(empty($verify['keys'][$active]['revoked_at']))->toBeTrue()
            ->and((int) $verify['keys'][$active]['epoch'])->toBe($verify['epoch']);
    } finally {
        b10CredRun(['mode' => 'cleanup', 'binding_id' => $bindingId]);
        foreach ($keyFiles as $file) {
            @unlink($file);
        }
    }
});
