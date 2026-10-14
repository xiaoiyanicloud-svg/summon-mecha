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
     */
    public function canonical(array $fields): string
    {
        return implode('|', [
            (string) ($fields['task_id'] ?? ''),
            (string) ($fields['skill_id'] ?? ''),
            (string) ($fields['version'] ?? ''),
            (string) ($fields['package_hash'] ?? ''),
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
