<?php

/**
 * Q-01 ~ Q-05 质量演练脚本的公共入口。
 *
 * ⚠ 所有脚本只允许跑在**独立测试库**：
 *   · APP_ENV 必须是 testing（显式，不接受“本机看起来像测试”的猜测）；
 *   · 数据库名必须以 `_test` 结尾，且不能是 root / mysql / information_schema。
 *
 * 不满足时立即 exit(2)，不执行任何 SQL。这是 Q-02 的“专用库硬校验”：
 * 演练会插入夹具、制造锁等待、重放 outbox；跑在主库上等于生产事故。
 *
 * 用法（Git Bash / PowerShell 均可）：
 *   DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q02/run.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = (string) config('database.connections.mysql.database');
$environment = (string) app()->environment();

$forbidden = ['root', 'mysql', 'information_schema', 'performance_schema', 'sys'];

if ($environment !== 'testing'
    || ! str_ends_with(strtolower($database), '_test')
    || in_array(strtolower($database), $forbidden, true)) {
    fwrite(STDERR, sprintf(
        "[qa-guard] 拒绝执行：APP_ENV=%s，DB_DATABASE=%s。\n".
        "[qa-guard] 必须显式设置 APP_ENV=testing 且使用以 _test 结尾的独立库。\n",
        $environment,
        $database,
    ));
    exit(2);
}

if (! function_exists('qa_json')) {
    /**
     * 输出稳定的单行 JSON（脚本之间通过 stdout 交换结果）。
     *
     * @param  array<string, mixed>  $payload
     */
    function qa_json(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    function qa_json_pretty(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    }

    function qa_now(): string
    {
        return now('UTC')->format('Y-m-d H:i:s');
    }

    /**
     * @return array{exit:int, stdout:string, stderr:string}
     */
    function qa_spawn(string $script, array $arguments = [], array $environment = []): array
    {
        $command = array_merge([PHP_BINARY, $script], $arguments);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = null;

        if ($environment !== []) {
            $env = array_merge(getenv(), $environment);
        }

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (! is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    /**
     * 从子进程 stdout 取 JSON：优先整体解析（支持 pretty 多行），
     * 失败时回退到逐行取最后一个可解析对象。
     *
     * @return array<string, mixed>|null
     */
    function qa_last_json(string $stdout): ?array
    {
        $trimmed = trim($stdout);

        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = json_decode($lines[$i], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
