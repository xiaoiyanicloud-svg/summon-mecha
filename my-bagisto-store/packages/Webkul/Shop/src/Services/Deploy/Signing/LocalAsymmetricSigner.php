<?php

namespace Webkul\Shop\Services\Deploy\Signing;

use RuntimeException;
use Webkul\Shop\Services\Deploy\Contracts\AsymmetricSigner;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 本地 PEM 签名后端（C2 默认实现）。
 *
 * 行为与 `SigningService::sign()` **逐字节等价**：同一把私钥、同一算法
 * （RSA PKCS#1 v1.5 + SHA-256）；区别只在这里返回原始签名字节，而
 * `SigningService` 返回 base64（历史契约）。
 *
 * ⚠ 这不是 KMS/HSM：私钥仍在进程内存与磁盘 PEM 中出现。
 *   `capabilities()` 必须如实回答 false。
 */
final class LocalAsymmetricSigner implements AsymmetricSigner
{
    public function __construct(protected SigningService $signing) {}

    public function sign(string $bytes): string
    {
        $base64 = $this->signing->sign($bytes);
        $raw = base64_decode($base64, true);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('本地签名后端返回空签名。');
        }

        return $raw;
    }

    public function publicKeyPem(): string
    {
        return $this->signing->publicKeyPem();
    }

    public function keyId(): string
    {
        return $this->signing->keyId();
    }

    public function capabilities(): array
    {
        return [
            'backend'         => 'local-pem',
            'kms'             => false,
            'hsm'             => false,
            'offline_recovery' => true,
            'note'            => '私钥为本地 PEM 文件，签名在 PHP 进程内完成；KMS/HSM 未实现。',
        ];
    }
}
