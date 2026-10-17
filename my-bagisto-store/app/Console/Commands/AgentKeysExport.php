<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\KeyIdentity;
use Webkul\Shop\Services\Deploy\ResolvesDeployKeys;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 导出平台密钥材料（签名密钥对 + 设备 CA）到备份目录 —— 任务卡 S-6。
 *
 *   php artisan agent:keys:export --to=/secure/backup/agent-keys-2026-10-17
 *
 * 为什么写目录、不打印到终端：私钥一旦进 stdout，就会留在终端回滚、CI 日志、
 * `tee` 文件和 shell history 里。写文件才能收紧权限并整体带走。
 *
 * 只导出**完整**的两套密钥：任一文件缺失即 FAILURE（拒绝导出一份看起来像备份、
 * 恢复时才发现不完整的半套）。目标目录已有同名文件也 FAILURE（不覆盖旧备份，
 * 请用带时间戳的目录）。
 *
 * 导出目录里的 `manifest.json` 只含哈希与 key_id，**不含任何密钥材料**。
 */
class AgentKeysExport extends Command
{
    use ResolvesDeployKeys;

    protected $signature = 'agent:keys:export
        {--to= : 导出目录（必填，建议带时间戳；现有同名文件会被拒绝覆盖）}';

    protected $description = '把平台签名密钥对与设备 CA 整体导出到指定目录（含只读清单 manifest.json）';

    public function handle(SigningService $signing, DeviceCredentialService $device): int
    {
        $to = trim((string) $this->option('to'));

        if ($to === '') {
            $this->error('必须用 --to 指定导出目录。例如：php artisan agent:keys:export --to=/secure/backup/agent-keys-'.now()->format('Ymd-His'));

            return self::FAILURE;
        }

        $to = $this->resolveExternalPath($to);
        $this->line('导出到：'.$to);

        $signingFiles = $signing->keyFiles();
        $caFiles = $device->caFiles();

        $leaves = [
            'signing.private' => $signingFiles['private'],
            'signing.public'  => $signingFiles['public'],
            'device_ca.private' => $caFiles['private'],
            'device_ca.public'  => $caFiles['public'],
        ];

        // ---- 1) 完整性：缺一个就拒绝（绝不导出半套）
        $missing = [];

        foreach ($leaves as $label => $leaf) {
            if (! $this->keyExists($leaf)) {
                $missing[] = "{$label}（".$this->keyPath($leaf).'）';
            }
        }

        if ($missing !== []) {
            $this->error('密钥不完整，拒绝导出半套。缺失：');
            foreach ($missing as $item) {
                $this->line('   · '.$item);
            }
            $this->line('先跑 php artisan agent:keys:generate 补齐缺失文件（只剩公钥的丢私钥场景不会被自动补齐，属预期）。');

            return self::FAILURE;
        }

        // ---- 2) 自洽性：配对/证书错配的密钥不许被当成"备份"带走
        $signingPrivate = $this->keyGet($signingFiles['private']);
        $signingPublic = $this->keyGet($signingFiles['public']);
        $caPrivate = $this->keyGet($caFiles['private']);
        $caCert = $this->keyGet($caFiles['public']);

        if (! KeyIdentity::pairMatches($signingPrivate, $signingPublic)) {
            $this->error('签名私钥与公钥不匹配，拒绝导出。');
            $this->error('这份密钥在线上已经签不出能被 Agent 验证的指令；请先人工确认，不要用现状覆盖旧备份。');

            return self::FAILURE;
        }

        if (! KeyIdentity::certMatchesPrivate($caCert, $caPrivate)) {
            $this->error('设备 CA 证书与 CA 私钥不匹配，拒绝导出。');
            $this->error('这份 CA 无法续签设备证书，且老设备可能已链不上；请先人工确认。');

            return self::FAILURE;
        }

        if (! KeyIdentity::isSelfSigned($caCert)) {
            $this->error('设备 CA 证书不是自签根证书，拒绝导出（与平台生成逻辑不符，需人工核查）。');

            return self::FAILURE;
        }

        // ---- 3) 目标目录：不存在则建；已有同名文件则拒绝覆盖
        if (! is_dir($to) && ! @mkdir($to, 0700, true) && ! is_dir($to)) {
            $this->error("无法创建导出目录：{$to}");

            return self::FAILURE;
        }

        $conflicts = [];

        foreach (array_unique(array_values($leaves)) as $leaf) {
            if (file_exists($to.DIRECTORY_SEPARATOR.$leaf)) {
                $conflicts[] = $leaf;
            }
        }

        if (file_exists($to.DIRECTORY_SEPARATOR.'manifest.json')) {
            $conflicts[] = 'manifest.json';
        }

        if ($conflicts !== []) {
            $this->error('目标目录已有同名文件，拒绝覆盖旧备份：'.implode('、', $conflicts));
            $this->line('请换一个带时间戳的新目录，例如 --to=/secure/backup/agent-keys-'.now()->format('Ymd-His'));

            return self::FAILURE;
        }

        // ---- 4) 写文件（任一步失败 → 清掉本次已写的文件，不留半份导出）
        $written = [];
        $payload = [
            $leaves['signing.private'] => $signingPrivate,
            $leaves['signing.public']  => $signingPublic,
            $leaves['device_ca.private'] => $caPrivate,
            $leaves['device_ca.public']  => $caCert,
        ];

        foreach ($payload as $leaf => $contents) {
            $target = $to.DIRECTORY_SEPARATOR.$leaf;

            if (file_put_contents($target, $contents) === false) {
                $this->cleanup($written);
                $this->error("写入失败：{$target}（已清理本次导出的部分文件，原密钥未动）");

                return self::FAILURE;
            }

            $written[] = $target;

            $mode = str_contains($leaf, 'private') ? 0600 : 0640;
            if (! @chmod($target, $mode)) {
                $this->warn("chmod {$mode} 失败：{$target}（请手动收紧权限）");
            }
        }

        // ---- 5) 清单（只含哈希，不含密钥材料）
        $manifest = [
            'schema'     => 'summon-mecha.agent-keys.v1',
            'created_at' => now()->toIso8601String(),
            'source'     => [
                'disk' => $this->keyDiskName(),
                'dir'  => $this->keyDir(),
            ],
            'signing' => [
                'key_id' => KeyIdentity::keyId($signingPublic),
            ],
            'device_ca' => [
                'cert_fingerprint' => KeyIdentity::certFingerprint($caCert),
            ],
            'files' => [
                $signingFiles['private'] => ['role' => 'signing.private', 'sha256' => hash('sha256', $signingPrivate), 'bytes' => strlen($signingPrivate)],
                $signingFiles['public']  => ['role' => 'signing.public', 'sha256' => hash('sha256', $signingPublic), 'bytes' => strlen($signingPublic)],
                $caFiles['private']      => ['role' => 'device_ca.private', 'sha256' => hash('sha256', $caPrivate), 'bytes' => strlen($caPrivate)],
                $caFiles['public']       => ['role' => 'device_ca.public', 'sha256' => hash('sha256', $caCert), 'bytes' => strlen($caCert)],
            ],
            'note' => '本清单只含哈希与标识，不含任何密钥材料。私钥文件请连同本目录一起加密保管。',
        ];

        $manifestPath = $to.DIRECTORY_SEPARATOR.'manifest.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($manifestPath, $json."\n") === false) {
            $this->cleanup($written);
            $this->error('manifest.json 写入失败（已清理本次导出的文件，原密钥未动）');

            return self::FAILURE;
        }

        $written[] = $manifestPath;

        // ---- 6) 输出清单
        $this->newLine();
        $this->line('导出完成，文件清单：');

        foreach ($written as $path) {
            $sha256 = hash_file('sha256', $path) ?: '(读取失败)';
            $this->line(sprintf('   · %s  sha256=%s', $path, $sha256));
        }

        $this->newLine();
        $this->line('signing_key_id：'.$manifest['signing']['key_id'].'（仅作备份溯源，不是轮换机制）');
        $this->line('device_ca_fingerprint：'.$manifest['device_ca']['cert_fingerprint']);
        $this->warn('导出目录里含私钥（0600 best-effort）。请立即加密留存到密码管理器/异地，并从临时位置妥善处理。');

        if (PHP_OS_FAMILY === 'Windows') {
            $this->line('⚠ Windows 下 PHP 的 chmod 基本无效；请在 Linux 上复核导出目录权限。');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $paths
     */
    protected function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
