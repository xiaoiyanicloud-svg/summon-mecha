<?php

/**
 * T2 · B3 发布竞态（HTTP 层）。
 *
 * 用 3 个独立 `php -S` 进程 + `curl_multi` 同时打 `shop.api.skill-admin.versions.publish`，
 * 要求：**无 5xx、无重复行、重复发布幂等**。
 *
 * ⚠ 范围说明（诚实）：
 *   本用例针对的发布入口是 `SkillAdminController::publish`（prompt 指定的锚点路由，
 *   见 `SkillFullChainEndToEndTest.php:201`）。该入口**不写 `skill_target_releases`** ——
 *   那张表由构建期 `PortableSkillBuilder` 登记（服务层并发已在第九批实测）。
 *   因此这里对 `skill_target_releases` 的断言是"发布竞态不得产生任何多余行"（0 行），
 *   而不是"恰好 1 行"——后者在当前实现下会是一条**恒真**的空断言，写上去就是造假。
 *   "发布是否有重复行"在本入口的可观测面是：`skill_versions` 该商品下**只有 1 行**，
 *   且版本状态只有一个终值。
 *
 * 数据可见性：PHP 子进程各自提交，父进程不建任何业务行 —— `DatabaseTransactions`
 * 的回滚不会影响它们；清理由 cleanup 子进程完成。
 */

const B10_PUB_PORTS = [8811, 8812, 8813];

function b10pubEnv(): array
{
    $env = getenv();
    $env['DB_DATABASE'] = 'summon_mecha_step10_test';
    $env['APP_ENV'] = 'testing';
    $env['APP_DEBUG'] = 'false';

    return $env;
}

function b10pubChildScript(): string
{
    static $path = null;

    if ($path !== null) {
        return $path;
    }

    $script = <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

require $argv[1];
$app = require $argv[2];
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$payload = json_decode($argv[3], true) ?: [];
$mode = $payload['mode'] ?? 'noop';
$out = ['mode' => $mode, 'ok' => false];

try {
    switch ($mode) {
        case 'setup':
            // 建一个"可发布"的最小技能：自营商品 + in_review 版本 + 两层审核通过 + 非落盘交付物
            Event::dispatch('catalog.product.create.before');
            $product = app(ProductRepository::class)->create([
                'type' => 'simple',
                'attribute_family_id' => 1,
                'sku' => 'B10PUB-'.strtoupper(substr(uniqid(), -10)),
            ]);
            Event::dispatch('catalog.product.create.after', $product);

            $product->seller_id = null;   // 平台自营：跳过开发者实名/协议前置
            $product->save();

            Event::dispatch('catalog.product.update.before', $product->id);
            app(ProductRepository::class)->update([
                'name'                 => 'B10 发布竞态技能 '.substr(uniqid(), -5),
                'url_key'              => 'b10pub-'.strtolower(substr(uniqid(), -8)),
                'short_description'    => 'publish race probe',
                'description'          => 'publish race probe',
                'price'                => 1,
                'status'               => 0,
                'visible_individually' => 1,
                'channel'              => core()->getDefaultChannelCode(),
                'locale'               => core()->getDefaultLocaleCodeFromDefaultChannel(),
                'categories'           => [],
            ], $product->id);
            Event::dispatch('catalog.product.update.after', $product->fresh());

            $version = SkillVersion::create([
                'product_id'        => $product->id,
                'seller_id'         => null,
                'version'           => '1.0.'.random_int(100, 999),
                'channel'           => SkillVersion::CHANNEL_STABLE,
                'delivery_channels' => ['manual', 'agent'],
                'risk_level'        => 'low',
                'status'            => SkillVersion::STATUS_IN_REVIEW,
                'license_mode'      => SkillVersion::LICENSE_PER_DEVICE,
                'pricing_mode'      => 'buyout',
            ]);

            // 非落盘载体：不参与"文件在不在盘上"和包指纹比对
            SkillAsset::create([
                'skill_version_id' => $version->id,
                'carrier_type'     => SkillAsset::CARRIER_CLOUD_CONFIG,
                'asset_role'       => SkillAsset::ROLE_SOURCE,
                'asset_key'        => 'b10-cloud-config',
                'payload'          => ['probe' => true],
                'sort_order'       => 1,
            ]);

            foreach ([AuditRecord::LAYER_MACHINE, AuditRecord::LAYER_HUMAN] as $layer) {
                AuditRecord::create([
                    'skill_id'   => $product->id,
                    'version'    => $version->id,
                    'audit_type' => 2,
                    'layer'      => $layer,
                    'result'     => AuditRecord::RESULT_PASS,
                    'audited_at' => now(),
                ]);
            }

            $out = ['mode' => $mode, 'ok' => true, 'product_id' => (int) $product->id, 'version_id' => (int) $version->id];
            break;

        case 'verify':
            $productId = (int) $payload['product_id'];
            $versionId = (int) $payload['version_id'];

            $version = SkillVersion::query()->find($versionId);
            $flat = DB::table('product_flat')->where('product_id', $productId)->first();

            $out = [
                'mode' => $mode, 'ok' => true,
                'version_status' => $version?->status,
                'versions_for_product' => (int) SkillVersion::query()->where('product_id', $productId)->count(),
                'published_for_product' => (int) SkillVersion::query()->where('product_id', $productId)->where('status', SkillVersion::STATUS_PUBLISHED)->count(),
                'target_releases_for_version' => (int) DB::table('skill_target_releases')->where('skill_version_id', $versionId)->count(),
                'product_status' => $flat ? (int) $flat->status : null,
                'product_visible_individually' => $flat ? (int) $flat->visible_individually : null,
            ];
            break;

        case 'cleanup':
            $productId = (int) $payload['product_id'];
            $versionId = (int) $payload['version_id'];

            DB::table('skill_target_releases')->where('skill_version_id', $versionId)->delete();
            DB::table('skill_assets')->where('skill_version_id', $versionId)->delete();
            DB::table('audit_record')->where('skill_id', $productId)->where('version', $versionId)->delete();
            DB::table('skill_versions')->where('id', $versionId)->delete();

            foreach (['product_flat', 'product_attribute_values', 'product_categories', 'product_inventories', 'product_relations'] as $table) {
                try { DB::table($table)->where('product_id', $productId)->delete(); } catch (\Throwable $e) { /* 表不存在则跳过 */ }
            }
            DB::table('products')->where('id', $productId)->delete();

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

    $path = tempnam(sys_get_temp_dir(), 'b10pub').'.php';
    file_put_contents($path, $script);

    return $path;
}

/**
 * @return array<string, mixed>
 */
function b10pubRunChild(array $payload): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, b10pubChildScript(), base_path('vendor/autoload.php'), base_path('bootstrap/app.php'), json_encode($payload)],
        $descriptors,
        $pipes,
        base_path(),
        b10pubEnv()
    );
    expect($process)->not->toBeFalse();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, "子进程失败: {$stderr}\n{$stdout}");
    $decoded = json_decode(trim($stdout), true);
    expect($decoded)->toBeArray("子进程输出不是 JSON: {$stdout}\n{$stderr}");

    return $decoded;
}

function b10pubSetup(): array
{
    $result = b10pubRunChild(['mode' => 'setup']);
    expect($result['ok'])->toBeTrue($result['error'] ?? 'setup failed');

    return $result;
}

function b10pubCleanup(array $ids): void
{
    if (isset($ids['product_id'], $ids['version_id'])) {
        b10pubRunChild(['mode' => 'cleanup', 'product_id' => $ids['product_id'], 'version_id' => $ids['version_id']]);
    }
}

function b10pubPing(int $port): bool
{
    $ch = curl_init("http://127.0.0.1:{$port}/up");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 200;
}

function b10pubServers(): array
{
    static $started = null;

    if ($started !== null) {
        return $started;
    }

    $procs = [];
    foreach (B10_PUB_PORTS as $port) {
        $out = tempnam(sys_get_temp_dir(), "b10pub{$port}_").'.out.log';
        $err = tempnam(sys_get_temp_dir(), "b10pub{$port}_").'.err.log';
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public'],
            [1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']],
            $pipes,
            base_path(),
            b10pubEnv()
        );
        expect($process)->not->toBeFalse();
        $status = proc_get_status($process);
        $procs[$port] = [$process, $out, $err, (int) ($status['pid'] ?? 0)];
    }

    $deadline = microtime(true) + 60;
    $ready = [];
    while (microtime(true) < $deadline) {
        foreach (B10_PUB_PORTS as $port) {
            if (! isset($ready[$port]) && b10pubPing($port)) {
                $ready[$port] = true;
            }
        }
        if (count($ready) === count(B10_PUB_PORTS)) {
            break;
        }
        usleep(200000);
    }

    if (count($ready) !== count(B10_PUB_PORTS)) {
        foreach ($procs as [$process]) {
            @proc_terminate($process);
        }
        throw new RuntimeException('publish 用 php -S 未能全部就绪: '.implode(',', array_keys($ready)));
    }

    register_shutdown_function(function () use ($procs) {
        foreach ($procs as [$process]) {
            @proc_terminate($process);
            @proc_close($process);
        }
    });

    $started = $procs;

    return $started;
}

/**
 * 并发发布（同一个 version_id，多个端口同时打）。
 *
 * @param  array<int, int>  $ports
 * @return array<int, array{status: int, body: string, error: string}>
 */
function b10pubFire(int $versionId, array $ports): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($ports as $index => $port) {
        $ch = curl_init("http://127.0.0.1:{$port}/api/skill-admin/versions/{$versionId}/publish");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Internal-Key: '.(string) config('skill_delivery.upload_key'),
            ],
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

function b10pubPost(int $port, int $versionId): array
{
    $ch = curl_init("http://127.0.0.1:{$port}/api/skill-admin/versions/{$versionId}/publish");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Internal-Key: '.(string) config('skill_delivery.upload_key'),
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'error' => $error];
}

// ────────────────────────────── 用例 ──────────────────────────────

it('B3-HTTP-01 · 3 进程并发发布同一 version：无 5xx、无重复行、恰好一条 published', function () {
    b10pubServers();
    $ids = b10pubSetup();

    try {
        $results = b10pubFire($ids['version_id'], B10_PUB_PORTS);

        $statuses = array_column($results, 'status');
        foreach ($results as $index => $result) {
            expect($result['status'])->toBeIn([200, 201, 409], "并发发布 #{$index} 返回了非预期状态: {$result['status']} {$result['body']} {$result['error']}");
        }

        $state = b10pubRunChild(['mode' => 'verify'] + $ids);

        expect($state['version_status'])->toBe('published')
            ->and($state['versions_for_product'])->toBe(1, '发布竞态产生了重复的版本行')
            ->and($state['published_for_product'])->toBe(1)
            ->and($state['product_status'])->toBe(1, '发布后商品必须可售')
            ->and($state['product_visible_individually'])->toBe(1)
            // 该入口不写目标发布快照；竞态绝不能在这里凭空造行
            ->and($state['target_releases_for_version'])->toBe(0);

        expect($statuses)->not->toContain(500)
            ->and(array_filter($statuses, fn (int $s) => $s >= 500))->toBe([]);
    } finally {
        b10pubCleanup($ids);
    }
});

it('B3-HTTP-02 · 串行发布两次：第二次不产生新行（幂等）', function () {
    b10pubServers();
    $ids = b10pubSetup();
    $port = B10_PUB_PORTS[0];

    try {
        $first = b10pubPost($port, $ids['version_id']);
        expect($first['status'])->toBeIn([200, 201], "首次发布失败: {$first['status']} {$first['body']}");

        $afterFirst = b10pubRunChild(['mode' => 'verify'] + $ids);
        expect($afterFirst['version_status'])->toBe('published')
            ->and($afterFirst['versions_for_product'])->toBe(1);

        $second = b10pubPost($port, $ids['version_id']);
        // 已发布版本再发布：明确结果码，而不是 500
        expect($second['status'])->toBe(422, "重复发布必须是可读的 4xx: {$second['status']} {$second['body']}");
        expect(json_decode($second['body'], true)['code'] ?? null)->toBe('VERSION_ALREADY_PUBLISHED');

        $afterSecond = b10pubRunChild(['mode' => 'verify'] + $ids);
        expect($afterSecond['versions_for_product'])->toBe(1, '幂等失败：第二次发布产生了新行')
            ->and($afterSecond['version_status'])->toBe('published')
            ->and($afterSecond['target_releases_for_version'])->toBe(0);
    } finally {
        b10pubCleanup($ids);
    }
});

it('B3-HTTP-03 · 并发发布 3 个不同 version：各自成功，互不影响（不是全局锁）', function () {
    b10pubServers();
    $sets = [b10pubSetup(), b10pubSetup(), b10pubSetup()];

    try {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($sets as $index => $ids) {
            $port = B10_PUB_PORTS[$index];
            $ch = curl_init("http://127.0.0.1:{$port}/api/skill-admin/versions/{$ids['version_id']}/publish");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([]),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Internal-Key: '.(string) config('skill_delivery.upload_key'),
                ],
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

        $statuses = [];
        foreach ($handles as $index => $ch) {
            $statuses[$index] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        foreach ($statuses as $index => $status) {
            expect($status)->toBeIn([200, 201], "不同版本并发发布 #{$index} 失败: {$status}");
        }

        foreach ($sets as $index => $ids) {
            $state = b10pubRunChild(['mode' => 'verify'] + $ids);
            expect($state['version_status'])->toBe('published', "版本 #{$index} 没有发布成功")
                ->and($state['versions_for_product'])->toBe(1)
                ->and($state['product_status'])->toBe(1);
        }
    } finally {
        foreach ($sets as $ids) {
            b10pubCleanup($ids);
        }
    }
});

it('B3-HTTP-04 · 数据一致性：发布后版本状态与商品可售状态不矛盾', function () {
    b10pubServers();
    $ids = b10pubSetup();

    try {
        $result = b10pubPost(B10_PUB_PORTS[0], $ids['version_id']);
        expect($result['status'])->toBeIn([200, 201]);

        $state = b10pubRunChild(['mode' => 'verify'] + $ids);

        // 版本 published ⇒ 商品在 flat 里可售；且同一商品最多一个 published 版本
        expect($state['version_status'])->toBe('published')
            ->and($state['product_status'])->toBe(1)
            ->and($state['product_visible_individually'])->toBe(1)
            ->and($state['published_for_product'])->toBe(1)
            ->and($state['published_for_product'])->toBeLessThanOrEqual($state['versions_for_product']);
    } finally {
        b10pubCleanup($ids);
    }
});
