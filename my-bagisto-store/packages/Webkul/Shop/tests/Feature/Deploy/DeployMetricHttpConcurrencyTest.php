<?php

/**
 * T1 · 指标心跳 HTTP 层多设备并发（WBS A3 的 HTTP 层证据）。
 *
 * 关键点：`php -S` 是**单进程串行**的。所以这里起 **6 个独立的 php -S 进程**，
 * 指向同一个 `public/` 与同一个数据库，再用 `curl_multi` 把请求**同时**发到 6 个端口。
 * 这不是"一个 server 排队"，而是 6 个 PHP 进程并行处理。
 *
 * A3-HTTP-06 是对照组：同样的请求换成 1 个 server 串行发，结果必须与并发一致 ——
 * 用来证明前面的用例不是"碰巧串行通过"。
 *
 * 断言一律是**精确增量**，不是 `>0`。计数器是全局累加值（历史运行会留下基线），
 * 所以所有断言都按前后差值计算。
 *
 * 已知边界（诚实）：
 *   · `php -S` 没有 php-fpm，6 个进程就是本机能构造的最强应用层并发；
 *   · 每个端口在本次运行中只服务少量请求，未做长时间压测。
 *
 * 环境变量 `DB_DATABASE=summon_mecha_step10_test` 必须由调用方注入（见 run-b10-regression.ps1）。
 */

const B10_HTTP_PORTS = [8811, 8812, 8813, 8814, 8815, 8816];

const B10_HTTP_EVIDENCE = 'D:/main/qa/wbs-20261105/b10-concurrency-evidence.json';

// ────────────────────────────── 服务器 ──────────────────────────────

function b10httpEnv(): array
{
    $env = getenv();
    $env['DB_DATABASE'] = 'summon_mecha_step10_test';
    $env['APP_ENV'] = 'testing';
    $env['APP_DEBUG'] = 'false';

    return $env;
}

function b10httpPing(int $port): bool
{
    $ch = curl_init("http://127.0.0.1:{$port}/up");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

/**
 * 惰性启动 6 个 server；进程在脚本结束时统一清理。
 *
 * @return array{ports: int[], procs: array<int, array{0: resource, 1: string, 2: string, 3: int}>}
 */
function b10httpServers(): array
{
    static $servers = null;

    if ($servers !== null) {
        return $servers;
    }

    $procs = [];
    foreach (B10_HTTP_PORTS as $port) {
        $out = tempnam(sys_get_temp_dir(), "b10srv{$port}_").'.out.log';
        $err = tempnam(sys_get_temp_dir(), "b10srv{$port}_").'.err.log';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public'],
            [1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']],
            $pipes,
            base_path(),
            b10httpEnv()
        );

        expect($process)->not->toBeFalse();
        $status = proc_get_status($process);
        $procs[$port] = [$process, $out, $err, (int) ($status['pid'] ?? 0)];
    }

    // 轮询到**每个**端口都返回 200（不许 sleep 猜）
    $deadline = microtime(true) + 60;
    $ready = [];
    while (microtime(true) < $deadline) {
        foreach (B10_HTTP_PORTS as $port) {
            if (! isset($ready[$port]) && b10httpPing($port)) {
                $ready[$port] = true;
            }
        }
        if (count($ready) === count(B10_HTTP_PORTS)) {
            break;
        }
        usleep(200000);
    }

    if (count($ready) !== count(B10_HTTP_PORTS)) {
        foreach ($procs as [$process]) {
            @proc_terminate($process);
        }
        throw new RuntimeException('php -S 未能全部就绪，已就绪端口: '.implode(',', array_keys($ready)));
    }

    $servers = ['ports' => B10_HTTP_PORTS, 'procs' => $procs];

    register_shutdown_function(function () use ($procs) {
        foreach ($procs as [$process]) {
            @proc_terminate($process);
            @proc_close($process);
        }
    });

    return $servers;
}

// ────────────────────────────── HTTP ──────────────────────────────

function b10httpAgentHeaders(): array
{
    return ['X-Agent-Key: '.(string) config('deploy_gateways.agent_key')];
}

/**
 * @return array{status: int, body: string, error: string}
 */
function b10httpPost(int $port, string $path, array $body): array
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], b10httpAgentHeaders()),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $raw, 'error' => $error];
}

/**
 * 同时发出全部请求（curl_multi：同一个事件循环里并发在途）。
 *
 * @param  array<int, array{port: int, path: string, body: array}>  $requests
 * @return array<int, array{status: int, body: string, error: string}>
 */
function b10httpFire(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $request) {
        $ch = curl_init("http://127.0.0.1:{$request['port']}{$request['path']}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($request['body'], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], b10httpAgentHeaders()),
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            curl_multi_select($multi, 1.0);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $ch) {
        $results[$index] = [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body'   => (string) curl_multi_getcontent($ch),
            'error'  => (string) curl_error($ch),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    return $results;
}

// ────────────────────────────── 数据库（独立连接） ──────────────────────────────

function b10httpPdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = config('database.connections.mysql');
        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    return $pdo;
}

function b10httpCounter(string $key): int
{
    $stmt = b10httpPdo()->prepare('SELECT metric_value FROM deploy_metric_counters WHERE metric_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? 0 : (int) $value;
}

function b10httpGauge(string $did, string $key): ?int
{
    $stmt = b10httpPdo()->prepare('SELECT metric_value FROM deploy_agent_metric_gauges WHERE did = ? AND metric_key = ?');
    $stmt->execute([$did, $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (int) $value;
}

function b10httpBatchExists(string $did, string $batchId): bool
{
    $stmt = b10httpPdo()->prepare('SELECT COUNT(*) FROM deploy_agent_metric_batches WHERE did = ? AND batch_id = ?');
    $stmt->execute([$did, $batchId]);

    return (int) $stmt->fetchColumn() > 0;
}

function b10httpRegister(string $prefix = 'b10http'): string
{
    $port = b10httpServers()['ports'][0];
    $response = b10httpPost($port, '/api/agent/register', ['sn' => $prefix.'-'.uniqid()]);

    expect($response['status'])->toBe(201, "注册失败: {$response['status']} {$response['body']} {$response['error']}");

    $json = json_decode($response['body'], true);
    expect($json['data']['did'] ?? null)->toBeString();

    return (string) $json['data']['did'];
}

function b10httpCleanup(array $dids): void
{
    if ($dids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($dids), '?'));
    $pdo = b10httpPdo();

    foreach ([
        "DELETE FROM deploy_agent_metric_batches WHERE did IN ({$placeholders})",
        "DELETE FROM deploy_agent_metric_gauges WHERE did IN ({$placeholders})",
        "DELETE FROM device_binding WHERE did IN ({$placeholders})",
    ] as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dids);
    }
}

function b10httpHeartbeat(string $did, array $metrics): array
{
    return ['did' => $did, 'agent_version' => '0.2.0', 'metrics' => $metrics];
}

function b10httpRecord(array $case): void
{
    static $evidence = [
        'wbs'          => 'A3-HTTP',
        'generated_at' => null,
        'servers'      => ['count' => 6, 'ports' => B10_HTTP_PORTS, 'pids' => []],
        'cases'        => [],
    ];

    if ($evidence['generated_at'] === null) {
        $evidence['generated_at'] = date('c');
        $servers = b10httpServers();
        $evidence['servers']['pids'] = array_map(fn ($p) => $p[3], array_values($servers['procs']));

        register_shutdown_function(function () use (&$evidence) {
            @file_put_contents(B10_HTTP_EVIDENCE, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });
    }

    $evidence['cases'][$case['id']] = $case;
}

// ────────────────────────────── 用例 ──────────────────────────────

it('A3-HTTP-01 · 同一设备 6 并发不同 batch 各 +1 → 恰好 +6', function () {
    b10httpServers();
    $did = b10httpRegister('b10http01');
    $before = b10httpCounter('command_received_total');
    $batches = [];

    try {
        $requests = [];
        foreach (B10_HTTP_PORTS as $index => $port) {
            $batchId = 'b10h01_'.substr(uniqid(), -12).'i'.$index;
            $batches[] = $batchId;
            $requests[] = [
                'port' => $port,
                'path' => '/api/agent/heartbeat',
                'body' => b10httpHeartbeat($did, [
                    'batch_id' => $batchId,
                    'counters' => ['command_received_total' => 1],
                ]),
            ];
        }

        $results = b10httpFire($requests);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(200, "请求 #{$index} 失败: {$result['status']} {$result['body']} {$result['error']}");
        }

        $after = b10httpCounter('command_received_total');

        expect($after - $before)->toBe(6, '并发上报丢了计数：6 个不同批次各 +1，必须恰好 +6');

        b10httpRecord([
            'id' => 'A3-HTTP-01', 'did' => $did, 'requests' => 6,
            'batch_ids' => $batches, 'delta' => $after - $before, 'expected_delta' => 6,
        ]);
    } finally {
        b10httpCleanup([$did]);
    }
});

it('A3-HTTP-02 · 同一设备 6 并发同一个 batch → 恰好 +1，6 个响应全 2xx', function () {
    b10httpServers();
    $did = b10httpRegister('b10http02');
    $before = b10httpCounter('command_received_total');
    $batchId = 'b10h02_'.substr(uniqid(), -12);

    try {
        $requests = array_map(fn (int $port) => [
            'port' => $port,
            'path' => '/api/agent/heartbeat',
            'body' => b10httpHeartbeat($did, [
                'batch_id' => $batchId,
                'counters' => ['command_received_total' => 1],
            ]),
        ], B10_HTTP_PORTS);

        $results = b10httpFire($requests);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBeGreaterThanOrEqual(200)
                ->and($result['status'])->toBeLessThan(300, "重复批次必须仍回 2xx（Agent 才能安全清空增量）: #{$index} {$result['status']} {$result['body']}");
        }

        $after = b10httpCounter('command_received_total');

        expect($after - $before)->toBe(1, '(did,batch_id) 幂等失效：同一批次被累加了多次');
        expect(b10httpBatchExists($did, $batchId))->toBeTrue();

        b10httpRecord([
            'id' => 'A3-HTTP-02', 'did' => $did, 'requests' => 6,
            'batch_id' => $batchId, 'delta' => $after - $before, 'expected_delta' => 1,
            'statuses' => array_column($results, 'status'),
        ]);
    } finally {
        b10httpCleanup([$did]);
    }
});

it('A3-HTTP-03 · 并发上报 gauge 100/200/300 → 最终值 ∈ {100,200,300}，绝不是 600', function () {
    b10httpServers();
    $did = b10httpRegister('b10http03');

    try {
        $values = [100, 200, 300, 100, 200, 300];
        $requests = [];
        foreach (B10_HTTP_PORTS as $index => $port) {
            $requests[] = [
                'port' => $port,
                'path' => '/api/agent/heartbeat',
                'body' => b10httpHeartbeat($did, [
                    'gauges' => ['outbox_pending_age_seconds' => $values[$index]],
                ]),
            ];
        }

        $results = b10httpFire($requests);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(200, "gauge 请求 #{$index} 失败: {$result['status']} {$result['body']}");
        }

        $stored = b10httpGauge($did, 'outbox_pending_age_seconds');

        expect($stored)->not->toBeNull('gauge 必须落库')
            ->and($stored)->toBeIn([100, 200, 300], 'gauge 是覆盖语义，最终值只能是某一次上报的值')
            ->and($stored)->not->toBe(600, 'gauge 被累加了（100+200+300=600）');

        b10httpRecord([
            'id' => 'A3-HTTP-03', 'did' => $did, 'reported_values' => $values,
            'stored' => $stored, 'allowed' => [100, 200, 300],
        ]);
    } finally {
        b10httpCleanup([$did]);
    }
});

it('A3-HTTP-04 · 20 台不同设备并发各报一次 → 每台恰好 +1、无串号', function () {
    b10httpServers();
    $before = b10httpCounter('command_received_total');
    $dids = [];
    $batches = [];

    try {
        for ($i = 0; $i < 20; $i++) {
            $dids[$i] = b10httpRegister("b10http04_{$i}");
        }

        $requests = [];
        foreach ($dids as $index => $did) {
            $batchId = 'b10h04_'.substr(uniqid(), -10).'d'.$index;
            $batches[$index] = $batchId;
            $requests[] = [
                'port' => B10_HTTP_PORTS[$index % count(B10_HTTP_PORTS)],
                'path' => '/api/agent/heartbeat',
                'body' => b10httpHeartbeat($did, [
                    'batch_id' => $batchId,
                    'counters' => ['command_received_total' => 1],
                ]),
            ];
        }

        $results = b10httpFire($requests);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(200, "设备 #{$index} 心跳失败: {$result['status']} {$result['body']}");
        }

        $after = b10httpCounter('command_received_total');
        expect($after - $before)->toBe(20, '20 台设备各 +1，全局增量必须恰好 +20');

        // 逐台核对：每个 did 的批次都在，且 did 两两不同（无串号）
        expect(array_unique($dids))->toHaveCount(20);
        foreach ($dids as $index => $did) {
            expect(b10httpBatchExists($did, $batches[$index]))->toBeTrue("设备 {$did} 的批次没有落库（串号/丢请求）");
        }

        $placeholders = implode(',', array_fill(0, 20, '?'));
        $stmt = b10httpPdo()->prepare("SELECT COUNT(DISTINCT did) FROM deploy_agent_metric_batches WHERE did IN ({$placeholders})");
        $stmt->execute($dids);
        expect((int) $stmt->fetchColumn())->toBe(20, '批次台账里必须正好 20 个不同 did');

        b10httpRecord([
            'id' => 'A3-HTTP-04', 'devices' => 20, 'requests' => 20,
            'delta' => $after - $before, 'expected_delta' => 20,
            'distinct_dids_in_batches' => 20,
        ]);
    } finally {
        b10httpCleanup($dids);
    }
});

it('A3-HTTP-05 · 未知指标键 → 422 且不落库', function () {
    b10httpServers();
    $did = b10httpRegister('b10http05');
    $before = b10httpCounter('command_received_total');
    $batchId = 'b10h05_'.substr(uniqid(), -12);

    try {
        $response = b10httpPost(B10_HTTP_PORTS[0], '/api/agent/heartbeat', b10httpHeartbeat($did, [
            'batch_id' => $batchId,
            'counters' => ['some_dynamic_label_total' => 1],
        ]));

        expect($response['status'])->toBe(422);
        expect(json_decode($response['body'], true)['code'] ?? null)->toBe('VALIDATION_ERROR');

        expect(b10httpBatchExists($did, $batchId))->toBeFalse('未知键被拒后不得留下批次台账');
        expect(b10httpCounter('command_received_total'))->toBe($before);

        b10httpRecord([
            'id' => 'A3-HTTP-05', 'did' => $did, 'batch_id' => $batchId,
            'status' => 422, 'batch_persisted' => false,
        ]);
    } finally {
        b10httpCleanup([$did]);
    }
});

it('A3-HTTP-06 · 对照组：同样的 6 个请求换 1 个 server 串行发，结果与并发一致', function () {
    $servers = b10httpServers();
    $port = $servers['ports'][0];
    $did = b10httpRegister('b10http06');
    $before = b10httpCounter('command_received_total');
    $batches = [];

    try {
        for ($i = 0; $i < 6; $i++) {
            $batchId = 'b10h06_'.substr(uniqid(), -12).'s'.$i;
            $batches[] = $batchId;
            $response = b10httpPost($port, '/api/agent/heartbeat', b10httpHeartbeat($did, [
                'batch_id' => $batchId,
                'counters' => ['command_received_total' => 1],
            ]));
            expect($response['status'])->toBe(200, "串行请求 #{$i} 失败: {$response['status']} {$response['body']}");
        }

        $after = b10httpCounter('command_received_total');

        expect($after - $before)->toBe(6, '串行与并发结果必须一致（说明 +6 是业务语义，不是并发碰巧）');

        b10httpRecord([
            'id' => 'A3-HTTP-06', 'did' => $did, 'server_count' => 1,
            'requests' => 6, 'delta' => $after - $before, 'expected_delta' => 6,
            'note' => '1 server 串行对照组；与 A3-HTTP-01 的 6 server 并发结果同为 +6',
        ]);
    } finally {
        b10httpCleanup([$did]);
    }
});
