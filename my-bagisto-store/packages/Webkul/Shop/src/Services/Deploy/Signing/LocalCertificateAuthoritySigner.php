<?php

namespace Webkul\Shop\Services\Deploy\Signing;

use Webkul\Shop\Services\Deploy\Contracts\CertificateAuthoritySigner;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;

/**
 * 本地 PEM CA 后端（C2 默认实现）。
 *
 * 复用 `DeviceCredentialService` 的 CA 材料与 `signCsr()`；不另生成一套 CA，
 * 否则同一平台会出现两张互不信任的根证书。
 *
 * ⚠ 私钥仍是磁盘 PEM；`capabilities()` 如实标 kms/hsm = false。
 */
final class LocalCertificateAuthoritySigner implements CertificateAuthoritySigner
{
    public function __construct(protected DeviceCredentialService $credentials) {}

    public function signCsr(string $csrPem, int $serial, int $ttlDays): string
    {
        return $this->credentials->signCsr($csrPem, $serial, $ttlDays);
    }

    public function caCertPem(): string
    {
        return $this->credentials->caCertPem();
    }

    public function capabilities(): array
    {
        return [
            'backend'      => 'local-pem',
            'kms'          => false,
            'hsm'          => false,
            'x509_issue'   => true,
            'note'         => 'CA 私钥为本地 PEM 文件，X.509 在 PHP 进程内签发；云 KMS/硬件 HSM 未接入。',
        ];
    }
}
