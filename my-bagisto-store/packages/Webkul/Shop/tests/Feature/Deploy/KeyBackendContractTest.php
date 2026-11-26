<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Services\Deploy\Contracts\AsymmetricSigner;
use Webkul\Shop\Services\Deploy\Contracts\CertificateAuthoritySigner;
use Webkul\Shop\Services\Deploy\Signing\KeyBackendManager;
use Webkul\Shop\Services\Deploy\Signing\LocalAsymmetricSigner;
use Webkul\Shop\Services\Deploy\Signing\LocalCertificateAuthoritySigner;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * C2 · KMS/HSM 抽象层（只做本地后端 + 契约）。
 *
 * 重点：
 *   · 本地签名后端与既有 `SigningService` **逐字节等价**（同一把钥匙、同一算法）；
 *   · CA 后端签出的证书能链到平台 CA，且 CSR 私钥签名可被证书公钥验证；
 *   · capabilities 不撒谎；未知后端直接抛异常，不静默回落。
 */

function c2KeyOpts(): array
{
    $opts = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
    if (is_file($cnf)) {
        $opts['config'] = $cnf;
    }

    return $opts;
}

beforeEach(function () {
    Storage::fake('local');
    config(['deploy_gateways.key_backend' => 'local']);
});

it('C2-K-01 本地签名后端与 SigningService 逐字节等价', function () {
    $signing = app(SigningService::class);
    $signer = app(LocalAsymmetricSigner::class);
    $canonical = 'summon-mecha/agent-task/v2'."\n".'{"a":1}';

    $raw = $signer->sign($canonical);

    // RSA PKCS#1 v1.5 是确定性的：base64(原始字节) 必须与既有服务完全一致
    expect(base64_encode($raw))->toBe($signing->sign($canonical))
        ->and($signer->publicKeyPem())->toBe($signing->publicKeyPem())
        ->and($signer->keyId())->toBe($signing->keyId())
        ->and(openssl_verify($canonical, $raw, $signer->publicKeyPem(), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('C2-K-02 CA 后端签发的证书可链到平台 CA，且 CSR 私钥签名可被证书验证', function () {
    $ca = app(LocalCertificateAuthoritySigner::class);
    $opts = c2KeyOpts();

    $deviceKey = openssl_pkey_new($opts);
    $csr = openssl_csr_new(['commonName' => 'did_c2_test'], $deviceKey, $opts);
    openssl_csr_export($csr, $csrPem);

    $certPem = $ca->signCsr($csrPem, 424242, 30);

    expect($certPem)->toContain('BEGIN CERTIFICATE')
        ->and(openssl_x509_verify($certPem, $ca->caCertPem()))->toBe(1);

    $canonical = 'c2|proof-of-possession';
    openssl_sign($canonical, $signature, $deviceKey, OPENSSL_ALGO_SHA256);
    expect(openssl_verify($canonical, $signature, openssl_pkey_get_public($certPem), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('C2-K-03 capabilities 如实声明 local，不冒充 KMS/HSM', function () {
    $signerCaps = app(LocalAsymmetricSigner::class)->capabilities();
    $caCaps = app(LocalCertificateAuthoritySigner::class)->capabilities();

    expect($signerCaps['backend'])->toBe('local-pem')
        ->and($signerCaps['kms'])->toBeFalse()
        ->and($signerCaps['hsm'])->toBeFalse()
        ->and($caCaps['kms'])->toBeFalse()
        ->and($caCaps['hsm'])->toBeFalse()
        ->and($caCaps['x509_issue'])->toBeTrue();
});

it('C2-K-04 未知后端直接抛异常，不静默回落本地', function () {
    config(['deploy_gateways.key_backend' => 'kms']);

    expect(fn () => app(KeyBackendManager::class)->signer())
        ->toThrow(RuntimeException::class, '未实现或未拍板');
});

it('C2-K-05 KeyBackendManager 默认 local，且契约实例可注入', function () {
    $manager = app(KeyBackendManager::class);

    expect($manager->backend())->toBe('local')
        ->and($manager->signer())->toBeInstanceOf(AsymmetricSigner::class)
        ->and($manager->caSigner())->toBeInstanceOf(CertificateAuthoritySigner::class)
        ->and($manager->capabilities()['backend'])->toBe('local');
});
