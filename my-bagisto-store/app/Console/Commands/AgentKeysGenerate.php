<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\ResolvesDeployKeys;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 生成平台密钥材料（签名密钥对 + 设备 CA）—— 任务卡 S-6。
 *
 *   php artisan agent:keys:generate            # 缺什么补什么，绝不覆盖
 *   php artisan agent:keys:generate --replace  # 先备份，再重新生成（= 轮换，端侧会全挂）
 *
 * 为什么不叫 `key:generate`：那是 Laravel 自带的 APP_KEY 命令，撞名会让运维敲错。
 *
 * 核心边界：
 *   · 完整 → 跳过（幂等）；
 *   · 只剩私钥 → 从**同一把私钥**重建公钥/证书（不是新的一对，老设备不受影响）；
 *   · 只剩公钥/证书、私钥已丢 → **拒绝**自动生成。那等于轮换：签名密钥会让所有已部署
 *     Agent 验签失败（ERR_SIG_INVALID），CA 会让老设备全部 401。必须人工加 `--replace`
 *     并接受后果（先自动备份）。
 */
class AgentKeysGenerate extends Command
{
    use ResolvesDeployKeys;

    protected $signature = 'agent:keys:generate
        {--replace : 先备份再重新生成（签名密钥轮换会让已部署 Agent 验签失败，仅在确认后果后使用）}';

    protected $description = '生成平台签名密钥对与设备 CA（默认绝不覆盖；缺公钥/证书时从同一把私钥重建）';

    public function handle(SigningService $signing, DeviceCredentialService $device): int
    {
        $replace = (bool) $this->option('replace');

        $this->line(sprintf('密钥位置：盘 %s / 目录 %s', $this->keyDiskName(), $this->keyDir()));

        $absoluteDir = $this->keyAbsolutePath($this->keyDir());
        if ($absoluteDir !== null) {
            $this->line('绝对路径：'.$absoluteDir);
        }

        if ($replace) {
            $this->warn('⚠ --replace 会更换签名密钥：已部署 Agent 内置的是旧公钥，轮换后将全部验签失败（ERR_SIG_INVALID），直到重新 enroll / 重装。');
            $this->warn('⚠ 同时会重签整张设备 CA：已登记设备的旧证书链不上新 CA，证书签名鉴权路径全部 401（bearer secret 路径不受影响）。');

            try {
                $backup = $this->backupKeyFiles($this->allLeaves($signing, $device));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                $this->error('已中止：没有改动任何密钥。');

                return self::FAILURE;
            }

            $backup === null
                ? $this->line('没有需要备份的现有密钥。')
                : $this->line('已备份现有密钥到：'.$backup);
        }

        $ok = true;

        [$okSigning, $message] = $this->handleSigning($signing, $replace);
        $this->line('签名密钥：'.$message);
        $ok = $ok && $okSigning;

        [$okCa, $message] = $this->handleCa($device, $replace);
        $this->line('设备 CA：'.$message);
        $ok = $ok && $okCa;

        $this->reportFiles($signing, $device);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function handleSigning(SigningService $signing, bool $replace): array
    {
        $files = $signing->keyFiles();
        $hasPrivate = $this->keyExists($files['private']);
        $hasPublic = $this->keyExists($files['public']);

        if ($replace) {
            $signing->ensureKeypair(true);

            return [true, '已重新生成新的一对（旧密钥已备份）。'];
        }

        if ($hasPrivate && $hasPublic) {
            return [true, '已存在，跳过（不覆盖）。'];
        }

        if ($hasPrivate && ! $hasPublic) {
            $signing->ensureKeypair();

            return [true, '公钥缺失，已从同一把私钥派生重建（私钥未变）。'];
        }

        if (! $hasPrivate && $hasPublic) {
            return [false, "私钥缺失、公钥仍在：拒绝自动生成新的一对（那等于轮换，会让所有已部署 Agent 验签失败）。"
                .'如确认要轮换，请用 --replace（会先备份）。'];
        }

        $signing->ensureKeypair();

        return [true, '原先没有密钥，已生成新的一对。'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function handleCa(DeviceCredentialService $device, bool $replace): array
    {
        $files = $device->caFiles();
        $hasPrivate = $this->keyExists($files['private']);
        $hasCert = $this->keyExists($files['public']);

        if ($replace) {
            $device->ensureCa(true);

            return [true, '已重签整张 CA（旧 CA 已备份）。'];
        }

        if ($hasPrivate && $hasCert) {
            return [true, '已存在，跳过（不覆盖）。'];
        }

        if ($hasPrivate && ! $hasCert) {
            $device->ensureCa();

            return [true, '证书缺失，已用同一把 CA 私钥重签（CA 公钥不变，老设备仍可验签）。'];
        }

        if (! $hasPrivate && $hasCert) {
            return [false, 'CA 私钥缺失、证书仍在：拒绝自动重签整张 CA（老设备的证书会全部链不上 → 401）。'
                .'如确认要重签，请用 --replace（会先备份）。'];
        }

        $device->ensureCa();

        return [true, '原先没有 CA，已生成新的自签根证书。'];
    }

    /**
     * @return list<string>
     */
    protected function allLeaves(SigningService $signing, DeviceCredentialService $device): array
    {
        return array_merge(array_values($signing->keyFiles()), array_values($device->caFiles()));
    }

    protected function reportFiles(SigningService $signing, DeviceCredentialService $device): void
    {
        $this->newLine();
        $this->line('当前文件：');

        foreach ($this->allLeaves($signing, $device) as $leaf) {
            $path = $this->keyPath($leaf);
            $exists = $this->keyExists($leaf);
            $mode = str_contains($leaf, 'private') ? 0600 : 0640;

            if (! $exists) {
                $this->warn(sprintf('   · %s：不存在', $path));

                continue;
            }

            $this->line(sprintf('   · %s（%d 字节）', $path, strlen($this->keyGet($leaf))));

            // 权限 best-effort：失败必须如实打印，不许静默。
            $chmod = $this->keyChmod($path, $mode);
            if ($chmod === null) {
                $this->line(sprintf('     chmod %04o 未执行（非本地盘，依赖盘自身访问控制）', $mode));
            } elseif ($chmod === false) {
                $this->warn(sprintf('     chmod %04o 失败 —— 请手动收紧该文件权限', $mode));
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->line('⚠ Windows 下 PHP 的 chmod 基本无效（返回值 true 也不代表强制了权限）；生产 Linux 上才会真正生效。');
        }

        $this->newLine();
        $this->line('备份/恢复：php artisan agent:keys:export --to=<安全目录> / php artisan agent:keys:import --from=<安全目录>');
    }
}
