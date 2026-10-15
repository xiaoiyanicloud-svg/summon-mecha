<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\DeviceBinding;

/**
 * per-device 设备凭据服务 —— §4.22 F1「一机一证」的服务端半边。
 *
 * ── 为什么需要它 ──
 *   此前 agent/register|heartbeat|evt|commands 四接口**共用一把 `AGENT_KEY`**
 *   （`VerifyAgentKey` 类注释自认「生产应升级为 per-device 证书」）。共享密钥下，
 *   服务端**分不清真身与冒充者**：任何拿到这把 key 的调用方都能以任意 did 发请求，
 *   泄露一把即全体沦陷，也无法单独吊销某一台设备。
 *
 *   本服务为**每台设备**签发一份独立凭据：
 *     · 一份随机 `device_secret`（bearer，落在设备 config 里）—— 过渡期即用；
 *     · 一张**由平台 CA 签发的 X.509 客户端证书** + 设备私钥 —— 目标形态（mTLS / 请求签名）。
 *   服务端只存 **secret 的哈希** 与 **证书指纹/序列号**（不存明文、不存私钥），
 *   故库被读走也还原不出可用凭据；吊销只影响那一台设备。
 *
 * ── 渐进切换（与中间件配合）──
 *   `deploy_gateways.agent_auth_mode`：
 *     · shared  —— 只认共享密钥（旧行为，回退用）
 *     · auto    —— 有 per-device 凭据就认凭据，否则退回共享密钥（**当前默认，过渡态**）
 *     · device  —— 只认 per-device 凭据，共享密钥被拒（共享密钥「明确弃用」的目标态）
 *
 * ⚠ 诚实边界（本机无真机 / 无 EMQX）：
 *   · 服务端**签发与校验**逻辑在此做实并已被测试覆盖；
 *   · **设备侧 mTLS 握手 / 用私钥对请求签名** 需真机 Agent 落地后验证 —— 见 `AgentSetupController`
 *     生成的参考 agent.py（设备侧标注「待真机验证」）。本服务不假设握手已验证。
 */
class DeviceCredentialService
{
    /** binding.meta 下的凭据键（与 agent_enroll 并列，互不干扰）。 */
    public const META_KEY = 'agent_credential';

    protected const CA_PRIVATE = 'keys/device_ca_private.pem';

    protected const CA_PUBLIC = 'keys/device_ca_public.pem';

    /** 请求签名的 ts 窗口（秒）—— 对齐 §4.22 F1「指令校验 ±5min」口径。 */
    public const TS_WINDOW_SECONDS = 300;

    /**
     * 为一条 binding 签发 per-device 凭据，落库(只存哈希/指纹)并返回**一次性明文**。
     *
     * @return array{secret:string,certificate_pem:string,private_key_pem:string,ca_pem:string,serial:int,fingerprint:string,expires_at:string}
     */
    public function issue(DeviceBinding $binding): array
    {
        $ttlDays = (int) config('deploy_gateways.device_credential.ttl_days', 365);

        $secret = bin2hex(random_bytes(32));

        $opts = $this->keyOpts();
        $deviceKey = openssl_pkey_new($opts);
        if ($deviceKey === false) {
            throw new \RuntimeException('设备密钥生成失败：'.(openssl_error_string() ?: '未知'));
        }

        $csr = openssl_csr_new($this->subject($binding->did), $deviceKey, $opts);
        if ($csr === false) {
            throw new \RuntimeException('设备证书请求(CSR)生成失败：'.(openssl_error_string() ?: '未知'));
        }

        $serial = random_int(1, PHP_INT_MAX >> 1);
        $cert = openssl_csr_sign($csr, $this->caCert(), $this->caKey(), $ttlDays, $opts, $serial);
        if ($cert === false) {
            throw new \RuntimeException('设备证书签发失败：'.(openssl_error_string() ?: '未知'));
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($deviceKey, $keyPem, null, $opts);

        $fingerprint = $this->fingerprint($certPem);
        $expiresAt = now()->addDays($ttlDays);

        $meta = is_array($binding->meta) ? $binding->meta : [];
        $meta[self::META_KEY] = [
            'secret_hash'      => hash('sha256', $secret),
            'cert_serial'      => $serial,
            'cert_fingerprint' => $fingerprint,
            // 证书本身是**公开**信息(私钥不出设备),服务端存一份用于**验签**。
            // 这样设备只需带 did + ts + 签名,不必在 HTTP 头里塞多行 PEM(头不能含换行)。
            'cert_pem'         => $certPem,
            'issued_at'        => now()->toDateTimeString(),
            'expires_at'       => $expiresAt->toDateTimeString(),
            'revoked_at'       => null,
        ];
        $binding->meta = $meta;
        $binding->save();

        return [
            'secret'          => $secret,
            'certificate_pem' => $certPem,
            'private_key_pem' => $keyPem,
            'ca_pem'          => $this->caCertPem(),
            'serial'          => $serial,
            'fingerprint'     => $fingerprint,
            'expires_at'      => $expiresAt->toDateTimeString(),
        ];
    }

    /**
     * 校验 per-device **bearer secret**。
     */
    public function verifySecret(DeviceBinding $binding, string $secret): bool
    {
        $cred = $this->credential($binding);

        if (! $cred || $this->isUsable($cred) === false) {
            return false;
        }

        if ($secret === '' || empty($cred['secret_hash'])) {
            return false;
        }

        return hash_equals((string) $cred['secret_hash'], hash('sha256', $secret));
    }

    /**
     * 校验 per-device **证书 + 请求签名**（proof-of-possession）。
     *
     * 比 bearer secret 更强：私钥不出设备，签名与「did|ts|method|path」绑定 ——
     * 即使证书落到别人手里（证书是公开的），没有私钥也签不出合法请求；
     * 且签名绑定到具体端点与时间窗，无法跨端点重放。
     *
     * 证书取自服务端登记的那张（签发时已存），设备只需带 did + ts + 签名。
     * 这是「服务端证书校验」的可测实现（mTLS 握手本身待真机）。
     */
    public function verifyRequestSignature(
        DeviceBinding $binding,
        string $signatureB64,
        string $ts,
        string $method,
        string $path,
    ): bool {
        $cred = $this->credential($binding);

        if (! $cred || $this->isUsable($cred) === false) {
            return false;
        }

        $certPem = (string) ($cred['cert_pem'] ?? '');

        if ($certPem === '') {
            return false;
        }

        // 登记在库的证书必须**仍链到平台 CA**（防库被篡改塞入伪造证书）。
        if (! $this->chainsToPlatformCa($certPem)) {
            return false;
        }

        $tsInt = (int) $ts;
        if ($tsInt <= 0 || abs(now()->timestamp - $tsInt) > self::TS_WINDOW_SECONDS) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            return false;
        }

        $canonical = self::requestCanonical($binding->did, $tsInt, $method, $path);

        return openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 吊销某设备的凭据（泄露/换机时可单独吊销，不影响其它设备）。
     */
    public function revoke(DeviceBinding $binding): bool
    {
        $meta = is_array($binding->meta) ? $binding->meta : [];

        if (empty($meta[self::META_KEY])) {
            return false;
        }

        $meta[self::META_KEY]['revoked_at'] = now()->toDateTimeString();
        $binding->meta = $meta;
        $binding->save();

        return true;
    }

    /** 这台设备当前是否持有可用 per-device 凭据。 */
    public function hasCredential(DeviceBinding $binding): bool
    {
        $cred = $this->credential($binding);

        return $cred !== null && $this->isUsable($cred);
    }

    /**
     * 请求签名规范串 —— 端云**必须逐字一致**（设备侧镜像此函数）。
     *
     * 形如：`did_xxx|1700000000|GET|api/agent/commands`
     */
    public static function requestCanonical(string $did, int $ts, string $method, string $path): string
    {
        return implode('|', [$did, (string) $ts, strtoupper($method), ltrim($path, '/')]);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function credential(DeviceBinding $binding): ?array
    {
        $meta = is_array($binding->meta) ? $binding->meta : [];
        $cred = $meta[self::META_KEY] ?? null;

        return is_array($cred) ? $cred : null;
    }

    /**
     * 凭据是否仍可用（未吊销、未过期）。吊销或过期的凭据一律判为**不可用**，
     * 而不是回退到共享密钥 —— 否则吊销等于形同虚设。
     *
     * @param  array<string,mixed>  $cred
     */
    protected function isUsable(array $cred): bool
    {
        if (! empty($cred['revoked_at'])) {
            return false;
        }

        if (! empty($cred['expires_at']) && now()->greaterThan(\Illuminate\Support\Carbon::parse($cred['expires_at']))) {
            return false;
        }

        return true;
    }

    protected function fingerprint(string $certPem): string
    {
        $der = base64_decode(
            (string) preg_replace('/-----[^-]+-----|\s+/', '', $certPem),
            true
        );

        return $der === false ? '' : hash('sha256', $der);
    }

    protected function chainsToPlatformCa(string $certPem): bool
    {
        // openssl_x509_verify 用 CA 公钥校验这张证书的签名 —— 非本 CA 签发的证书一律失败。
        return openssl_x509_verify($certPem, $this->caPublicKey()) === 1;
    }

    /**
     * 平台设备 CA 的私钥（首次使用时自签生成）。
     */
    protected function caKey()
    {
        $this->ensureCa();

        return openssl_pkey_get_private(Storage::disk('local')->get(self::CA_PRIVATE));
    }

    protected function caCert()
    {
        $this->ensureCa();

        return openssl_x509_read($this->caCertPem());
    }

    protected function caCertPem(): string
    {
        $this->ensureCa();

        return (string) Storage::disk('local')->get(self::CA_PUBLIC);
    }

    protected function caPublicKey()
    {
        return openssl_pkey_get_public($this->caCertPem());
    }

    /**
     * 生成/确保平台设备 CA（自签根证书）。开发期在 storage/app/keys 下；
     * 生产应换 KMS/HSM（与 SigningService 同一处置）。
     */
    protected function ensureCa(): void
    {
        if (Storage::disk('local')->exists(self::CA_PRIVATE)) {
            return;
        }

        $opts = $this->keyOpts();

        $caKey = openssl_pkey_new($opts);
        if ($caKey === false) {
            throw new \RuntimeException('CA 密钥生成失败：'.(openssl_error_string() ?: '未知(openssl.cnf 缺失?)'));
        }

        $dn = [
            'countryName'    => 'CN',
            'organizationName' => 'Summon Mecha',
            'commonName'     => 'Summon Mecha Device CA',
        ];

        $csr = openssl_csr_new($dn, $caKey, $opts);
        if ($csr === false) {
            throw new \RuntimeException('CA CSR 生成失败：'.(openssl_error_string() ?: '未知'));
        }

        // 自签根证书：签发者即自身（$ca_certificate = null）
        $caCert = openssl_csr_sign($csr, null, $caKey, 3650, $opts, random_int(1, PHP_INT_MAX >> 1));
        if ($caCert === false) {
            throw new \RuntimeException('CA 自签失败：'.(openssl_error_string() ?: '未知'));
        }

        openssl_pkey_export($caKey, $caPrivatePem, null, $opts);
        openssl_x509_export($caCert, $caCertPem);

        Storage::disk('local')->put(self::CA_PRIVATE, $caPrivatePem);
        Storage::disk('local')->put(self::CA_PUBLIC, $caCertPem);
    }

    protected function subject(string $did): array
    {
        return [
            'countryName'      => 'CN',
            'organizationName' => 'Summon Mecha',
            'commonName'       => $did,
        ];
    }

    /**
     * openssl 选项 —— Windows 上 php 的 openssl 常缺 openssl.cnf，显式指向 php 自带的那份，
     * 否则 openssl_csr_new / openssl_csr_sign 会失败（与 SigningService 同一处置）。
     */
    protected function keyOpts(): array
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
}
