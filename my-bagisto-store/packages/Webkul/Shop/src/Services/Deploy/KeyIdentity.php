<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 密钥材料的纯计算工具 —— 不读写文件、不读配置、不依赖容器。
 *
 * 存在的理由只有一个:**"这半套密钥到底是不是同一把钥匙"必须能被独立回答**。
 *   · 服务层重建缺失文件时(私钥在、公钥丢),要先证明派生出的公钥与私钥配对;
 *   · `agent:keys:import` 在落盘前必须证明"导入的私钥 / 公钥 / CA 证书"彼此配对,
 *     否则会把半份/错配的密钥写进生产 —— 那种事故没有响亮的报错,只有端侧静默拒装。
 *
 * 比较公钥一律走 DER(openssl 导出的 `details['key']`),对换行、头尾、PEM 包裹方式免疫。
 */
final class KeyIdentity
{
    /**
     * 从私钥 PEM 派生公钥 PEM;私钥无法解析时返回 null。
     */
    public static function publicPemFromPrivate(string $privatePem): ?string
    {
        $key = @openssl_pkey_get_private($privatePem);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return (is_array($details) && ! empty($details['key'])) ? (string) $details['key'] : null;
    }

    /**
     * 私钥与公钥是否配对。
     */
    public static function pairMatches(string $privatePem, string $publicPem): bool
    {
        $derived = self::publicPemFromPrivate($privatePem);

        return $derived !== null && self::samePublicKey($derived, $publicPem);
    }

    /**
     * 两个公钥 PEM(或证书 PEM)是否指向同一把公钥。
     */
    public static function samePublicKey(string $a, string $b): bool
    {
        $ka = @openssl_pkey_get_public($a);
        $kb = @openssl_pkey_get_public($b);

        if ($ka === false || $kb === false) {
            return false;
        }

        $da = openssl_pkey_get_details($ka);
        $db = openssl_pkey_get_details($kb);

        if (! is_array($da) || ! is_array($db)) {
            return false;
        }

        return hash_equals((string) ($da['key'] ?? ''), (string) ($db['key'] ?? ''));
    }

    /**
     * CA 证书里的公钥是否就是 CA 私钥对应的那把。
     *
     * 这不是"证书能解析"级别的小事:`DeviceCredentialService::chainsToPlatformCa()`
     * 用的是证书里的公钥;若证书与私钥错配,老设备全都链不上(401)。
     */
    public static function certMatchesPrivate(string $certPem, string $privatePem): bool
    {
        $derived = self::publicPemFromPrivate($privatePem);

        if ($derived === null || @openssl_pkey_get_public($certPem) === false) {
            return false;
        }

        return self::samePublicKey($derived, $certPem);
    }

    /**
     * 证书是否自签自证(根证书必须如此)。
     */
    public static function isSelfSigned(string $certPem): bool
    {
        return @openssl_x509_verify($certPem, $certPem) === 1;
    }

    /**
     * key_id = `sha256:` + sha256(公钥 SPKI 的 DER)。
     *
     * ⚠ 只用于导出清单里的**备份溯源**,绝不进线上协议:参考 Agent 只内置一把公钥,
     *   没有 key_id 协商能力(多公钥/平滑轮换依赖真机 Agent E7,单独立卡)。
     */
    public static function keyId(string $publicPem): string
    {
        $key = @openssl_pkey_get_public($publicPem);

        if ($key === false) {
            return '';
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || empty($details['key'])) {
            return '';
        }

        $der = self::pemBodyDer((string) $details['key']);

        return $der === null ? '' : 'sha256:'.hash('sha256', $der);
    }

    /**
     * 证书指纹 = sha256(整张证书的 DER) —— 与 `DeviceCredentialService::fingerprint()` 同口径。
     */
    public static function certFingerprint(string $certPem): string
    {
        $der = self::pemBodyDer($certPem);

        return $der === null ? '' : hash('sha256', $der);
    }

    /**
     * 去掉 PEM 头尾与空白,返回 DER 二进制;非法 PEM 返回 null。
     */
    private static function pemBodyDer(string $pem): ?string
    {
        $body = (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        if ($body === '') {
            return null;
        }

        $der = base64_decode($body, true);

        return $der === false ? null : $der;
    }
}
