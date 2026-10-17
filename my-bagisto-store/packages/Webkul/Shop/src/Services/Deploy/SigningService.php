<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 下发指令签名服务 —— v3.4 步骤 8。
 *
 * 平台用私钥签"安装指令",Agent 内置公钥验签,失败拒绝安装。
 * 签名内容:task_id + skill_id + version + package_hash + ts(计划 §4.6)。
 *
 * ⚠ 密钥管理（S-6）：私钥落在 `config('deploy_gateways.keys')` 指定的盘/目录
 *   （默认 `local` 盘 = `storage_path('app')`，目录 `keys/`），首次使用时自生成，
 *   写入时**尽力** `chmod 0600`。**生产应把该路径指到受限的持久卷**（受限目录 + 私有挂载），
 *   而非随部署方式漂移的本地盘；**KMS/HSM 未实现**，本轮不上。
 * ⚠ 轮换：参考 Agent **只内置一把公钥**（install 时固定），**当前不支持多公钥** ——
 *   替换这把签名私钥会让**所有已部署 Agent 验签失败**（ERR_SIG_INVALID），直到重新 enroll/重装。
 *   导出清单里的 `key_id`（公钥 SPKI 的 sha256）**仅作备份溯源**，不是轮换机制。
 */
class SigningService
{
    use ResolvesDeployKeys;

    /**
     * 文件名（叶子）。目录由 config 决定 —— 名称本身是签名协议的一部分，不配置。
     */
    protected const PRIVATE_FILE = 'platform_private.pem';

    protected const PUBLIC_FILE = 'platform_public.pem';

    /**
     * 两套密钥的文件名，供运维命令拼路径（盘/目录仍从 config 读）。
     *
     * @return array{private:string,public:string}
     */
    public function keyFiles(): array
    {
        return [
            'private' => self::PRIVATE_FILE,
            'public'  => self::PUBLIC_FILE,
        ];
    }

    /** 完整密钥对是否在位。 */
    public function hasKeypair(): bool
    {
        return $this->keyExists(self::PRIVATE_FILE) && $this->keyExists(self::PUBLIC_FILE);
    }

    /**
     * 是否残留任意一个文件。
     *
     * 命令用它区分「完整 → 跳过」「只剩私钥 → 可重建公钥」「只剩公钥 → 私钥已丢，
     * 绝不能静默生成新对（等于轮换）」。
     */
    public function hasAnyKeyMaterial(): bool
    {
        return $this->keyExists(self::PRIVATE_FILE) || $this->keyExists(self::PUBLIC_FILE);
    }

    /**
     * 对一段规范串签名,返回 base64。
     */
    public function sign(string $canonical): string
    {
        $private = $this->privateKey();

        $signature = '';
        openssl_sign($canonical, $signature, $private, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 校验(供 Agent 侧复用/自测)。
     */
    public function verify(string $canonical, string $signatureB64): bool
    {
        $public = $this->publicKey();

        return openssl_verify($canonical, base64_decode($signatureB64), $public, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 平台上分发的公钥(Agent 内置这份)。
     *
     * 首次调用即确保密钥对已生成 —— 否则"还没签过任何指令"时会导出空公钥。
     */
    public function publicKeyPem(): string
    {
        $this->ensureKeypair();

        return $this->keyGet(self::PUBLIC_FILE);
    }

    /**
     * 构造待签名的规范串 —— 集中一处,避免端上各写各的。
     *
     * 串的构成:`task_id|skill_id|version|package_hash|package_sha256|ts`(六段,`|` 连接)。
     *
     * ⚠ 为什么 `package_sha256` **必须**进签名串(任务卡 S-1 追加):
     *   端侧"下载 → 复算 sha256 → 与指令比对"这道防线,**只有在比对靶子被签住时才成立**。
     *   若 `package_sha256` 不在串里,能改写指令的攻击者可以**同时**把 `package_url`
     *   指到自己的服务器、并把 `package_sha256` 改成那个恶意文件的哈希 —— 端侧复算
     *   会**通过**,然后装上恶意包。那样这道防线在有主动攻击者时形同虚设,只挡得住传输损坏。
     *
     *   `package_url` **本身不入串**,也是刻意的:URL 是每次取指令时**重新签发**的短时地址
     *   (见 `DeployTaskService::buildCommand`),把它写死进签名会让"离线后重新取"失效。
     *   而"地址指向哪里"并不重要 —— 重要的是**字节必须匹配签住的 `package_sha256`**。
     *   签住哈希,就同时锁死了内容,无论 URL 被改成什么。
     *
     * ⚠ `package_hash`(集合指纹)与 `package_sha256`(单文件字节 sha256)分工见
     *   `DeployTaskService::buildCommand`;两者都签,前者锁"审的是哪一套交付物",
     *   后者锁"发出去的这份字节"。
     *
     * ⚠ 无包的 dry install:两个哈希都为空,拼成空段。**签名照常成立、照常可验**
     *   (`?? ''` 只是让缺字段退化成空段,不会让整条串无法构造)。这不构成弱化:
     *   端侧对"两个哈希皆空"的指令**不下载任何东西**(见参考 Agent 的判据 —— 触发条件是
     *   签住的 `package_sha256`,不是未签名的 `package_url`)。
     */
    public function canonical(array $fields): string
    {
        return implode('|', [
            (string) ($fields['task_id'] ?? ''),
            (string) ($fields['skill_id'] ?? ''),
            (string) ($fields['version'] ?? ''),
            (string) ($fields['package_hash'] ?? ''),
            (string) ($fields['package_sha256'] ?? ''),
            (string) ($fields['ts'] ?? ''),
        ]);
    }

    protected function privateKey()
    {
        $this->ensureKeypair();

        return openssl_pkey_get_private($this->keyGet(self::PRIVATE_FILE));
    }

    protected function publicKey()
    {
        $this->ensureKeypair();

        return openssl_pkey_get_public($this->keyGet(self::PUBLIC_FILE));
    }

    /**
     * 确保密钥对可用；缺一个文件时**只补缺的那个**，不重签整对。
     *
     * @param  bool  $force  true = 强制重新生成整对（`agent:keys:generate --replace` 用；
     *                      等价于轮换，已部署 Agent 会全部验签失败）
     */
    public function ensureKeypair(bool $force = false): void
    {
        $privateExists = $this->keyExists(self::PRIVATE_FILE);
        $publicExists = $this->keyExists(self::PUBLIC_FILE);

        if (! $force && $privateExists && $publicExists) {
            return;
        }

        // 缺公钥、但私钥还在 → **从同一把私钥派生公钥**（同一把钥匙，不是新的一对）。
        // 以前这里直接 return，`publicKeyPem()` 会吐出空串（enroll 响应里的
        // server_public_key 为空，新设备拿不到公钥）；而重签整对会把已部署 Agent
        // 内置的旧公钥直接作废 —— 这两条都绝不能做。
        if (! $force && $privateExists) {
            $publicPem = KeyIdentity::publicPemFromPrivate($this->keyGet(self::PRIVATE_FILE));

            if ($publicPem === null) {
                throw new \RuntimeException('平台私钥无法解析，无法派生公钥：'.$this->keyPath(self::PRIVATE_FILE));
            }

            $this->keyPut(self::PUBLIC_FILE, $publicPem, false);

            return;
        }

        // 私钥也不在（或强制重建）→ 生成新的一对。
        $opts = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Windows 上 php 的 openssl 常缺 openssl.cnf —— 显式指向 php 自带的那份,否则 openssl_pkey_new 返回 false
        $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($cnf)) {
            $opts['config'] = $cnf;
        }

        $res = openssl_pkey_new($opts);

        if ($res === false) {
            throw new \RuntimeException('RSA 密钥生成失败:'.(openssl_error_string() ?: '未知(openssl.cnf 缺失?)'));
        }

        openssl_pkey_export($res, $privatePem, null, $opts);
        $publicPem = openssl_pkey_get_details($res)['key'];

        // 先私钥后公钥：若公钥写失败，下一次调用会走上面的「从私钥派生」分支恢复；
        // 反过来则会留下「有公钥无私钥」——那意味着这把钥匙已经不可用且会被误判为在位。
        $this->keyPut(self::PRIVATE_FILE, $privatePem, true);
        $this->keyPut(self::PUBLIC_FILE, $publicPem, false);
    }
}
