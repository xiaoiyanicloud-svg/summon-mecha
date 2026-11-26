<?php

use Webkul\Shop\Services\SkillCompile\Portable\SourcePackageReader;

/**
 * B4.4：并发读取源包。
 *
 * 这里用**4 个独立 PHP 进程**同时读同一个 ZIP —— 同进程内的并发（协程/多线程）证明不了
 * "没有共享可变状态"，只有独立进程才是真并发。
 *
 * 说明（诚实边界）：`SourcePackageReader` 全程内存读取、不落临时目录，
 * 因此"清理失败"在该类上没有可触发的分支；磁盘满由 B4 已有的"产物回读校验"覆盖，
 * 不在这里重复制造一个假的 ENOSPC。
 */

function b44MakeArchive(): string
{
    $path = tempnam(sys_get_temp_dir(), 'b44src').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('behavior/main.xml', '<?xml version="1.0"?><root><action name="wave"/></root>');
    $zip->addFromString('behavior/assets/a.txt', str_repeat('a', 1024));
    $zip->addFromString('behavior/assets/b.txt', str_repeat('b', 2048));
    $zip->close();

    return $path;
}

function b44ChildScript(string $autoload, string $bootstrap): string
{
    $script = <<<PHP
<?php
require '$autoload';
\$app = require '$bootstrap';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$reader = new Webkul\\Shop\\Services\\SkillCompile\\Portable\\SourcePackageReader();
try {
    \$out = \$reader->read(\$argv[1], 'behavior/main.xml');
    echo hash('sha256', \$out['xml_content']), '|', count(\$out['assets']);
} catch (\\Throwable \$e) {
    fwrite(STDERR, get_class(\$e).': '.\$e->getMessage());
    exit(3);
}
PHP;

    $path = tempnam(sys_get_temp_dir(), 'b44child').'.php';
    file_put_contents($path, $script);

    return $path;
}

it('B4-4 · 4 个独立进程并发读取同一源包：全部成功且结果完全一致', function () {
    $archive = b44MakeArchive();
    $script = b44ChildScript(base_path('vendor/autoload.php'), base_path('bootstrap/app.php'));

    try {
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([PHP_BINARY, $script, $archive], $descriptors, $pipes);
            expect($process)->not->toBeFalse();
            $processes[] = [$process, $pipes];
        }

        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            expect($exit)->toBe(0, "子进程失败: {$stderr}");
            $outputs[] = trim($stdout);
        }

        $unique = array_values(array_unique($outputs));
        expect($unique)->toHaveCount(1, '并发读取结果必须完全一致：'.implode(' / ', $outputs));
        expect($unique[0])->toMatch('/^[0-9a-f]{64}\|\d+$/');
    } finally {
        @unlink($archive);
        @unlink($script);
    }
});

it('B4-4 · 并发读取时一个进程读到坏包，不影响其它进程的成功读取', function () {
    $good = b44MakeArchive();
    $bad = tempnam(sys_get_temp_dir(), 'b44bad').'.zip';
    file_put_contents($bad, 'PK not a zip');

    $script = b44ChildScript(base_path('vendor/autoload.php'), base_path('bootstrap/app.php'));

    try {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $goodProc = proc_open([PHP_BINARY, $script, $good], $descriptors, $goodPipes);
        $badProc = proc_open([PHP_BINARY, $script, $bad], $descriptors, $badPipes);

        $goodOut = stream_get_contents($goodPipes[1]);
        stream_get_contents($goodPipes[2]);
        fclose($goodPipes[1]);
        fclose($goodPipes[2]);
        $goodExit = proc_close($goodProc);

        stream_get_contents($badPipes[1]);
        $badErr = stream_get_contents($badPipes[2]);
        fclose($badPipes[1]);
        fclose($badPipes[2]);
        $badExit = proc_close($badProc);

        expect($goodExit)->toBe(0)
            ->and(trim($goodOut))->toMatch('/^[0-9a-f]{64}\|\d+$/')
            ->and($badExit)->not->toBe(0)
            ->and($badErr)->toContain('SourcePackageRejected');
    } finally {
        @unlink($good);
        @unlink($bad);
        @unlink($script);
    }
});
