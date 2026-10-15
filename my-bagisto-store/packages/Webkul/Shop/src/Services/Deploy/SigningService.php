<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\Storage;

/**
 * 下发指令签名服务 —— v3.4 步骤 8。
 *
 * 平台用私钥签"安装指令",Agent 内置公钥验签,失败拒绝安装。
 * 签名内容:task_id + skill_id + version + package_hash + ts(计划 §4.6)。
 *
 * ⚠ 密钥管理:开发期在 storage/app/keys 生成自签密钥对;生产应换成 KMS/受限文件
 *   (计划已列为安全项)。轮换时 Agent 侧需支持多公钥,故导出时带 key_id。
 */
class SigningService
{
    protected const DIR = 'keys';

    protected const PRIVATE = 'keys/platform_private.pem';

    protected const PUBLIC = 'keys/platform_public.pem';

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

        return (string) Storage::disk('local')->get(self::PUBLIC);
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

        return openssl_pkey_get_private(Storage::disk('local')->get(self::PRIVATE));
    }

    protected function publicKey()
    {
        $this->ensureKeypair();

        return openssl_pkey_get_public(Storage::disk('local')->get(self::PUBLIC));
    }

    /**
     * 首次使用时生成密钥对(开发期)。
     */
    protected function ensureKeypair(): void
    {
        if (Storage::disk('local')->exists(self::PRIVATE)) {
            return;
        }

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

        Storage::disk('local')->put(self::PRIVATE, $privatePem);
        Storage::disk('local')->put(self::PUBLIC, $publicPem);
    }
}
