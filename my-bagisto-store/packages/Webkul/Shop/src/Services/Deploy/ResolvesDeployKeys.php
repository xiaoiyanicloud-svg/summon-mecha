<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * 平台密钥文件的位置解析与读写 —— 任务卡 S-6。
 *
 * 以前两套密钥各自硬编码 `Storage::disk('local')` + `keys/...`:
 *   · 部署换机 / 本地盘不持久 → 密钥跟着消失,而库里没有任何可恢复副本;
 *   · 没有任何地方检查 `put()` 的返回值(`filesystems.disks.local.throw=false` 时它返回 false
 *     而不是抛异常)—— 写失败会留下一份**空文件**,下一次读取才炸,且现场已丢。
 *
 * 本 trait 把「盘 / 目录」集中到 `config('deploy_gateways.keys.*')`,
 * 并把写入失败变成**响亮的异常**。文件名仍是常量(它们是签名/证书协议的一部分)。
 *
 * ⚠ 只在本地盘上尽力 `chmod`:Windows 上基本无效是正常的,best-effort 不能让开发机 brick。
 */
trait ResolvesDeployKeys
{
    protected function keyDiskName(): string
    {
        return (string) config('deploy_gateways.keys.disk', 'local');
    }

    protected function keyDisk(): Filesystem
    {
        return Storage::disk($this->keyDiskName());
    }

    /**
     * 目录相对盘根;空值视为盘根。
     */
    protected function keyDir(): string
    {
        return trim((string) config('deploy_gateways.keys.dir', 'keys'), "/\\");
    }

    protected function keyPath(string $leaf): string
    {
        $dir = $this->keyDir();

        return $dir === '' ? $leaf : $dir.'/'.$leaf;
    }

    protected function keyExists(string $leaf): bool
    {
        return $this->keyDisk()->exists($this->keyPath($leaf));
    }

    protected function keyGet(string $leaf): string
    {
        return (string) $this->keyDisk()->get($this->keyPath($leaf));
    }

    /**
     * 写一个密钥文件。写失败**必须抛** —— 静默失败会留下半份密钥进生产。
     *
     * @param  bool  $secret  私钥 → 0600;公钥/证书 → 0640(best-effort)
     */
    protected function keyPut(string $leaf, string $contents, bool $secret = true): void
    {
        $path = $this->keyPath($leaf);

        if ($this->keyDisk()->put($path, $contents) === false) {
            throw new \RuntimeException("密钥写入失败：{$path}（盘 {$this->keyDiskName()}）");
        }

        $this->keyChmod($path, $secret ? 0600 : 0640);
    }

    /**
     * 本地盘上尽力设置权限。返回 null 表示非本地盘(未尝试),否则返回 chmod 的结果。
     *
     * ⚠ Windows 上 PHP 的 chmod 基本是 no-op(只切只读位);返回值 true 也不代表
     *   真的强制了 0600。调用方应把 null / false 如实打印出来,不许装作成功。
     */
    protected function keyChmod(string $path, int $mode): ?bool
    {
        $absolute = $this->keyAbsolutePath($path);

        if ($absolute === null) {
            return null;
        }

        return @chmod($absolute, $mode);
    }

    /**
     * 磁盘路径对应的绝对路径;非本地盘返回 null(对象存储没有 POSIX 权限/原子 rename)。
     */
    protected function keyAbsolutePath(string $path): ?string
    {
        $driver = config("filesystems.disks.{$this->keyDiskName()}.driver");

        if ($driver !== 'local') {
            return null;
        }

        try {
            return $this->keyDisk()->path($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 把导出/导入命令的外部目录参数解析成绝对路径。
     *
     * 备份目录**不在** Storage 盘里（要在盘外/异地留存），所以这里走原生文件系统：
     * 盘内路径交给 `keyDisk()`，盘外交给调用方。相对路径按项目根目录解析。
     */
    protected function resolveExternalPath(string $path): string
    {
        $path = rtrim(trim($path), "/\\");

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return $path;
        }

        return rtrim(base_path($path), "/\\");
    }

    /**
     * 把现有密钥文件复制到同盘 `keys/backup-<时间戳>-<随机>/`。
     *
     * @param  list<string>  $leaves
     * @return string|null 备份目录(相对盘根);没有可备份的文件时返回 null
     *
     * @throws \RuntimeException 备份写入失败(调用方必须**停止**后续覆盖动作)
     */
    protected function backupKeyFiles(array $leaves): ?string
    {
        $existing = array_values(array_filter($leaves, fn (string $leaf) => $this->keyExists($leaf)));

        if ($existing === []) {
            return null;
        }

        $dir = $this->keyDir();
        $backupDir = ($dir === '' ? '' : $dir.'/').'backup-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));

        foreach ($existing as $leaf) {
            $contents = $this->keyGet($leaf);
            $path = $backupDir.'/'.$leaf;

            if ($this->keyDisk()->put($path, $contents) === false) {
                throw new \RuntimeException("密钥备份失败：{$path}（未改动原密钥）");
            }

            // 写进去的要与源逐字一致,否则备份是假的;不等就中止。
            // ⚠ 这里 $path 已是相对盘根的完整路径,不能再走 keyGet()(它会再拼一次 keyDir)。
            if ((string) $this->keyDisk()->get($path) !== $contents) {
                throw new \RuntimeException("密钥备份校验失败：{$path}（未改动原密钥）");
            }

            $this->keyChmod($path, str_contains($leaf, 'private') ? 0600 : 0640);
        }

        return $backupDir;
    }
}
