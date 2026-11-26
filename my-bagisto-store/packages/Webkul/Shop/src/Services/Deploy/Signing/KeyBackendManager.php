<?php

namespace Webkul\Shop\Services\Deploy\Signing;

use RuntimeException;
use Webkul\Shop\Services\Deploy\Contracts\AsymmetricSigner;
use Webkul\Shop\Services\Deploy\Contracts\CertificateAuthoritySigner;

/**
 * 签名后端选择器（C2）。
 *
 * 现状：只实现 `local`（本地 PEM）。`kms` / `hsm` 故意**没有实现**，并且
 * 未知值**直接抛异常**，绝不静默回落到本地 —— 否则"以为上了 KMS，实际还在用明文私钥"，
 * 比没上更危险。
 *
 * ⚠ 本类目前**不改生产路径**：`SigningService` / `DeviceCredentialService` 的调用方
 *   仍走原实现；这里是未来切换后端的唯一接缝，默认值 `local` 与现状行为一致。
 */
final class KeyBackendManager
{
    public const SUPPORTED = ['local'];

    public function signer(): AsymmetricSigner
    {
        $this->assertSupported();

        return app(LocalAsymmetricSigner::class);
    }

    public function caSigner(): CertificateAuthoritySigner
    {
        $this->assertSupported();

        return app(LocalCertificateAuthoritySigner::class);
    }

    /**
     * @return array{backend: string, signer: array, ca: array}
     */
    public function capabilities(): array
    {
        return [
            'backend' => $this->backend(),
            'signer'  => $this->signer()->capabilities(),
            'ca'      => $this->caSigner()->capabilities(),
        ];
    }

    public function backend(): string
    {
        return (string) config('deploy_gateways.key_backend', 'local');
    }

    protected function assertSupported(): void
    {
        $backend = $this->backend();

        if (! in_array($backend, self::SUPPORTED, true)) {
            throw new RuntimeException(
                "签名后端 '{$backend}' 未实现或未拍板：当前只支持 ".implode(', ', self::SUPPORTED)
                ."。禁止静默回落到本地明文私钥。"
            );
        }
    }
}
