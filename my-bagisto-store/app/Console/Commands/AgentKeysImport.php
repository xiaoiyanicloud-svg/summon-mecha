<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\KeyIdentity;
use Webkul\Shop\Services\Deploy\ResolvesDeployKeys;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 从备份目录导入平台密钥材料（签名密钥对 + 设备 CA）—— 任务卡 S-6。
 *
 *   php artisan agent:keys:import --from=/secure/backup/agent-keys-2026-10-17
 *   php artisan agent:keys:import --from=... --replace   # 先自动备份现有密钥
 *
 * 两条硬规则：
 *   1. **先全量校验、后落盘**：私钥能解析、公私钥配对、CA 私钥与证书配对且自签。
 *      校验不过一律不写 —— 半份/错配的密钥进生产不会立刻报错，只会让端侧静默拒装。
 *   2. **默认拒绝覆盖**：目标已有任一密钥文件 → FAILURE。必须显式 `--replace`，
 *      且覆盖前自动备份到同盘 `keys/backup-<时间戳>-<随机>/`。
 *
 * 落盘用"临时文件 → 复验 → 就位"：任一步失败即清理临时文件并回滚已就位的文件，
 * 目标目录不会留下半份新密钥。
 */
class AgentKeysImport extends Command
{
    use ResolvesDeployKeys;

    protected $signature = 'agent:keys:import
        {--from= : 备份目录（必填，含 4 个 .pem，可选 manifest.json）}
        {--replace : 覆盖已存在的密钥（会先自动备份到 keys/backup-<时间戳>/）}';

    protected $description = '从备份目录导入平台密钥（全量校验通过才写；默认拒绝覆盖已存在的密钥）';

    public function handle(SigningService $signing, DeviceCredentialService $device): int
    {
        $fromOption = trim((string) $this->option('from'));

        if ($fromOption === '') {
            $this->error('必须用 --from 指定备份目录。例如：php artisan agent:keys:import --from=/secure/backup/agent-keys-2026-10-17');

            return self::FAILURE;
        }

        $from = $this->resolveExternalPath($fromOption);
        $this->line('从以下目录导入：'.$from);

        if (! is_dir($from)) {
            $this->error("备份目录不存在：{$from}");

            return self::FAILURE;
        }

        $signingFiles = $signing->keyFiles();
        $caFiles = $device->caFiles();

        /** @var array<string,string> $roles 叶子名 → 角色标签 */
        $roles = [
            $signingFiles['private'] => '签名私钥',
            $signingFiles['public']  => '签名公钥',
            $caFiles['private']      => 'CA 私钥',
            $caFiles['public']       => 'CA 证书',
        ];

        // ---- 1) 读入（全部进内存，尚未写任何东西）
        $contents = [];
        $missing = [];

        foreach ($roles as $leaf => $role) {
            $path = $from.DIRECTORY_SEPARATOR.$leaf;

            if (! is_file($path)) {
                $missing[] = "{$role}（{$path}）";

                continue;
            }

            $data = file_get_contents($path);

            if ($data === false || trim($data) === '') {
                $missing[] = "{$role}（{$path}，不可读或为空）";

                continue;
            }

            $contents[$leaf] = $data;
        }

        if ($missing !== []) {
            $this->error('备份目录不完整，拒绝导入。缺失：');
            foreach ($missing as $item) {
                $this->line('   · '.$item);
            }
            $this->error('已中止：目标密钥逐字未动。');

            return self::FAILURE;
        }

        // ---- 2) 可选清单：有 manifest 就逐文件比对哈希（防备份被截断/篡改）
        if (! $this->verifyManifest($from, $contents, $signingFiles, $caFiles)) {
            $this->error('已中止：目标密钥逐字未动。');

            return self::FAILURE;
        }

        // ---- 3) 全量校验（顺序固定：先证明钥匙是同一把，再谈写不写）
        $signingPrivate = $contents[$signingFiles['private']];
        $signingPublic = $contents[$signingFiles['public']];
        $caPrivate = $contents[$caFiles['private']];
        $caCert = $contents[$caFiles['public']];

        if (KeyIdentity::publicPemFromPrivate($signingPrivate) === null) {
            return $this->reject('签名私钥无法被 openssl 解析（文件损坏或不是 PEM 私钥）。');
        }

        if (! KeyIdentity::pairMatches($signingPrivate, $signingPublic)) {
            return $this->reject('签名私钥与签名公钥不匹配 —— 这是一对错配的密钥，导入后 Agent 会全部验签失败。');
        }

        if (KeyIdentity::publicPemFromPrivate($caPrivate) === null) {
            return $this->reject('CA 私钥无法被 openssl 解析（文件损坏或不是 PEM 私钥）。');
        }

        if (! KeyIdentity::certMatchesPrivate($caCert, $caPrivate)) {
            return $this->reject('CA 证书与 CA 私钥不匹配 —— 老设备的证书会链不上，证书签名鉴权将全部 401。');
        }

        if (! KeyIdentity::isSelfSigned($caCert)) {
            return $this->reject('CA 证书不是自签根证书（与平台生成逻辑不符）。');
        }

        // ---- 4) 目标守卫：默认拒绝覆盖
        $existing = [];

        foreach (array_keys($roles) as $leaf) {
            if ($this->keyExists($leaf)) {
                $existing[] = $this->keyPath($leaf);
            }
        }

        $replace = (bool) $this->option('replace');

        if ($existing !== [] && ! $replace) {
            $this->error('目标位置已存在密钥，拒绝覆盖：');
            foreach ($existing as $path) {
                $this->line('   · '.$path);
            }
            $this->error('已中止：目标密钥逐字未动。');
            $this->line('如确认要用备份替换现有密钥，请加 --replace（会先自动备份现有密钥，但替换后的后果由操作者承担）。');

            return self::FAILURE;
        }

        // ---- 5) --replace：先备份，备份失败就停
        $backup = null;

        if ($replace && $existing !== []) {
            try {
                $backup = $this->backupKeyFiles(array_keys($roles));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                $this->error('已中止：目标密钥逐字未动。');

                return self::FAILURE;
            }

            $backup === null
                ? $this->line('没有需要备份的现有密钥。')
                : $this->line('已备份现有密钥到：'.$backup);
        }

        // ---- 6) 临时文件（同目录同盘）→ 读回复验
        $token = bin2hex(random_bytes(6));
        $tempLeaves = [];

        foreach ($contents as $leaf => $data) {
            $tempLeaf = '.agent-keys-import-'.$token.'-'.$leaf;

            if ($this->keyDisk()->put($this->keyPath($tempLeaf), $data) === false) {
                $this->discardTemps($tempLeaves);
                $this->error("临时文件写入失败：{$tempLeaf}（已清理；目标密钥未动）");

                return self::FAILURE;
            }

            if ($this->keyGet($tempLeaf) !== $data) {
                $this->discardTemps(array_merge($tempLeaves, [$tempLeaf]));
                $this->error("临时文件复验失败：{$tempLeaf}（已清理；目标密钥未动）");

                return self::FAILURE;
            }

            $tempLeaves[$leaf] = $tempLeaf;
        }

        // ---- 7) 就位；失败则回滚（已就位的恢复备份/删除，临时文件清掉）
        $written = [];

        foreach ($tempLeaves as $leaf => $tempLeaf) {
            if (! $this->keyDisk()->move($this->keyPath($tempLeaf), $this->keyPath($leaf))) {
                $this->rollback($written, $tempLeaves, $backup);
                $this->error("落盘失败：{$leaf}（已尝试回滚；请核对目录内文件）");

                return self::FAILURE;
            }

            $written[] = $leaf;

            $mode = str_contains($leaf, 'private') ? 0600 : 0640;
            $chmod = $this->keyChmod($this->keyPath($leaf), $mode);

            if ($chmod === false) {
                $this->warn(sprintf('chmod %04o 失败：%s —— 请手动收紧该文件权限', $mode, $this->keyPath($leaf)));
            } elseif ($chmod === null) {
                $this->line(sprintf('chmod %04o 未执行：非本地盘，依赖盘自身访问控制', $mode));
            }
        }

        // ---- 8) 落盘后复验（防"写进去的东西和校验过的不是同一份"）
        $reSigningOk = KeyIdentity::pairMatches($this->keyGet($signingFiles['private']), $this->keyGet($signingFiles['public']));
        $reCaOk = KeyIdentity::certMatchesPrivate($this->keyGet($caFiles['public']), $this->keyGet($caFiles['private']));

        if (! $reSigningOk || ! $reCaOk) {
            $this->rollback($written, [], $backup);
            $this->error('落盘后复验失败，已回滚。请检查存储盘是否可靠（对象存储/只读挂载/磁盘满？）。');

            return self::FAILURE;
        }

        // ---- 9) 清单
        $this->newLine();
        $this->line('导入完成，文件清单：');

        foreach (array_keys($roles) as $leaf) {
            $absolute = $this->keyAbsolutePath($this->keyPath($leaf)) ?? $this->keyPath($leaf);
            $this->line(sprintf(
                '   · %s（%s，%d 字节，sha256=%s）',
                $absolute,
                $roles[$leaf],
                strlen($this->keyGet($leaf)),
                hash('sha256', $this->keyGet($leaf)),
            ));
        }

        if ($replace) {
            $this->warn('⚠ 已替换现有密钥。若替换的是签名密钥，已部署 Agent 内置的旧公钥将无法验证新指令（ERR_SIG_INVALID），直到重新 enroll/重装。');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->line('⚠ Windows 下 PHP 的 chmod 基本无效；请在 Linux 上复核权限（私钥 0600）。');
        }

        return self::SUCCESS;
    }

    /**
     * 有 manifest.json 就逐文件比对哈希与 key_id/指纹；没有则跳过（仅告警）。
     *
     * @param  array<string,string>  $contents
     * @param  array{private:string,public:string}  $signingFiles
     * @param  array{private:string,public:string}  $caFiles
     */
    protected function verifyManifest(string $from, array $contents, array $signingFiles, array $caFiles): bool
    {
        $path = $from.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($path)) {
            $this->line('提示：目录内没有 manifest.json，跳过哈希比对（仍会做密钥配对校验）。');

            return true;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest) || ! isset($manifest['files']) || ! is_array($manifest['files'])) {
            $this->error('manifest.json 无法解析或缺少 files 清单，拒绝导入。');

            return false;
        }

        foreach ($contents as $leaf => $data) {
            $entry = $manifest['files'][$leaf] ?? null;

            if (! is_array($entry) || empty($entry['sha256'])) {
                $this->error("manifest.json 缺少 {$leaf} 的哈希，拒绝导入。");

                return false;
            }

            if (! hash_equals((string) $entry['sha256'], hash('sha256', $data))) {
                $this->error("{$leaf} 的 sha256 与 manifest.json 不符（备份可能损坏或被改动），拒绝导入。");

                return false;
            }
        }

        if (! empty($manifest['signing']['key_id'])
            && ! hash_equals((string) $manifest['signing']['key_id'], KeyIdentity::keyId($contents[$signingFiles['public']]))) {
            $this->error('签名公钥的 key_id 与 manifest.json 不符，拒绝导入。');

            return false;
        }

        if (! empty($manifest['device_ca']['cert_fingerprint'])
            && ! hash_equals((string) $manifest['device_ca']['cert_fingerprint'], KeyIdentity::certFingerprint($contents[$caFiles['public']]))) {
            $this->error('CA 证书指纹与 manifest.json 不符，拒绝导入。');

            return false;
        }

        return true;
    }

    protected function reject(string $reason): int
    {
        $this->error('导入校验失败：'.$reason);
        $this->error('已中止：没有写入任何文件（目标密钥逐字未动）。');

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $tempLeaves
     */
    protected function discardTemps(array $tempLeaves): void
    {
        foreach ($tempLeaves as $tempLeaf) {
            $this->keyDisk()->delete($this->keyPath($tempLeaf));
        }
    }

    /**
     * 回滚本次已就位的文件：备份里有同名文件就恢复，否则删除。
     *
     * @param  list<string>  $writtenLeaves
     * @param  array<string,string>  $tempLeaves
     */
    protected function rollback(array $writtenLeaves, array $tempLeaves, ?string $backup): void
    {
        $this->discardTemps(array_values($tempLeaves));

        foreach ($writtenLeaves as $leaf) {
            $backupPath = $backup === null ? null : $backup.'/'.$leaf;

            if ($backupPath !== null && $this->keyDisk()->exists($backupPath)) {
                // $backupPath 已是相对盘根的完整路径,不能走 keyGet()(会再拼一次 keyDir)。
                $this->keyDisk()->put($this->keyPath($leaf), (string) $this->keyDisk()->get($backupPath));
            } else {
                $this->keyDisk()->delete($this->keyPath($leaf));
            }
        }
    }
}
