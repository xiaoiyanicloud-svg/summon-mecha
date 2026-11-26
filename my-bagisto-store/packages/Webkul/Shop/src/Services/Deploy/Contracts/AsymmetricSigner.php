<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

/**
 * 非对称签名后端（WBS C2 / T10 草案 B）。
 *
 * 抽象的是"**用托管私钥对字节签名**"，不是"密钥状态"——后者仍归 `KeyProvider`。
 * 生产默认实现是本地 PEM（`LocalAsymmetricSigner`）；KMS/HSM 后端未接入。
 *
 * ⚠ `capabilities()` 必须如实回答；**禁止**把本地文件后端说成 KMS/HSM。
 */
interface AsymmetricSigner
{
    /** 对任意字节签名，返回**原始签名字节**（调用方自己决定 base64/hex）。 */
    public function sign(string $bytes): string;

    /** 签名公钥（PEM）。 */
    public function publicKeyPem(): string;

    /** SPKI 公钥的 sha256（与 Agent 侧 key_id 同口径）。 */
    public function keyId(): string;

    /**
     * @return array{backend: string, kms: bool, hsm: bool, offline_recovery: bool, note: string}
     */
    public function capabilities(): array;
}
