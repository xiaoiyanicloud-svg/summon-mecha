<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

/**
 * 证书签发后端（WBS C2 / T10 草案 A）。
 *
 * 抽象"用托管的 CA 私钥对 CSR 签发 X.509"。生产默认实现仍是本地 PEM
 * （`LocalCertificateAuthoritySigner`）；云 KMS / 硬件 HSM 未接入。
 *
 * ⚠ 多数云 KMS 只提供 `Sign`、不提供"签发证书"；自行组装 X.509 风险高。
 *   本接口不假设任何后端能力，`capabilities()` 必须如实声明。
 */
interface CertificateAuthoritySigner
{
    /**
     * 用托管 CA 私钥签发证书。
     *
     * @param  string  $csrPem  PEM 或 DER 编码的 CSR
     * @return string 证书 PEM
     */
    public function signCsr(string $csrPem, int $serial, int $ttlDays): string;

    /** 平台 CA 证书 PEM（公开，供 Agent 验链）。 */
    public function caCertPem(): string;

    /**
     * @return array{backend: string, kms: bool, hsm: bool, x509_issue: bool, note: string}
     */
    public function capabilities(): array;
}
