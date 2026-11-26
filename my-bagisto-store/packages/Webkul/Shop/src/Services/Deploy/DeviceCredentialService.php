<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\Contracts\KeyMaterial;

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
    use ResolvesDeployKeys;

    /** binding.meta 下的凭据键（与 agent_enroll 并列，互不干扰）。 */
    public const META_KEY = 'agent_credential';

    /**
     * binding.meta 下的**密钥环**（WBS A7）。
     *
     * 单钥模型做不到轮换：`issue()` 一覆盖 `agent_credential`，旧公钥就再也取不到，
     * 轮换期间的在途事实只能全拒。这里把"这把设备用过哪些公钥、各自第几代、还在不在窗口内"
     * 单独存一份，验签按 key_id 选钥匙，而不是按"当前唯一那把"。
     *
     * 形状：`{"epoch": 2, "keys": {"<key_id>": {cert_pem, epoch, status, created_at, not_after, revoked_at, reason}}}`
     */
    public const META_KEY_RING = 'agent_key_ring';

    /**
     * 文件名（叶子）。目录由 config('deploy_gateways.keys.dir') 决定。
     * `CA_PUBLIC_FILE` 里存的是 **CA 证书 PEM**（不是裸公钥）—— 这个名字沿用旧称。
     */
    protected const CA_PRIVATE_FILE = 'device_ca_private.pem';

    protected const CA_PUBLIC_FILE = 'device_ca_public.pem';

    /** 请求签名的 ts 窗口（秒）—— 对齐 §4.22 F1「指令校验 ±5min」口径。 */
    public const TS_WINDOW_SECONDS = 300;

    /**
     * CA 两套文件的名字，供运维命令拼路径（盘/目录仍从 config 读）。
     *
     * @return array{private:string,public:string}
     */
    public function caFiles(): array
    {
        return [
            'private' => self::CA_PRIVATE_FILE,
            'public'  => self::CA_PUBLIC_FILE,
        ];
    }

    /**
     * 用平台 CA 私钥签发一张 CSR（C2 · 证书签发后端的本地实现入口）。
     *
     * 这是**纯追加**能力：`issue()` 的现有路径一字未改；本方法只把
     * "用 CA 私钥签一张证书"单独暴露出来，供 `CertificateAuthoritySigner`
     * 的本地后端复用，避免未来接 KMS 时在 `issue()` 里分叉两套逻辑。
     *
     * @param  string  $csr  PEM 或 DER 编码的 CSR
     * @return string 证书 PEM
     */
    public function signCsr(string $csr, int $serial, int $ttlDays): string
    {
        if ($serial <= 0) {
            throw new \InvalidArgumentException('证书序列号必须为正整数。');
        }

        if ($ttlDays <= 0) {
            throw new \InvalidArgumentException('证书有效期必须为正整数天。');
        }

        $csrPem = str_contains($csr, '-----BEGIN')
            ? $csr
            : "-----BEGIN CERTIFICATE REQUEST-----\n"
                .chunk_split(base64_encode($csr), 64, "\n")
                ."-----END CERTIFICATE REQUEST-----\n";

        $cert = openssl_csr_sign($csrPem, $this->caCert(), $this->caKey(), $ttlDays, $this->keyOpts(), $serial);

        if ($cert === false) {
            throw new \RuntimeException('CA 签发证书失败：'.(openssl_error_string() ?: '未知'));
        }

        openssl_x509_export($cert, $certPem);

        return $certPem;
    }

    /** CA 私钥与证书是否都在位。 */
    public function hasCa(): bool
    {
        return $this->keyExists(self::CA_PRIVATE_FILE) && $this->keyExists(self::CA_PUBLIC_FILE);
    }

    /**
     * 是否残留任意一个 CA 文件。
     *
     * 命令用它区分「完整 → 跳过」「只剩私钥 → 同一把私钥重签证书」「只剩证书 → CA 私钥已丢，
     * 绝不能静默重签整张 CA（老设备会全部 401）」。
     */
    public function hasAnyCaMaterial(): bool
    {
        return $this->keyExists(self::CA_PRIVATE_FILE) || $this->keyExists(self::CA_PUBLIC_FILE);
    }

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

        // 密钥环是 binding.meta 里的 JSON：`读 → 改 → 写` 必须在**行锁**内完成，
        // 否则两个并发的轮换/吊销会互相覆盖（后写的把前一个刚登记的新密钥抹掉，
        // 设备拿到的 key_id 平台上根本不存在 —— 表现为"刚轮换完就全量 401"）。
        \Illuminate\Support\Facades\DB::transaction(function () use (
            $binding, $certPem, $secret, $serial, $fingerprint, $expiresAt
        ) {
            $locked = DeviceBinding::query()->whereKey($binding->getKey())->lockForUpdate()->first() ?: $binding;

            $meta = is_array($locked->meta) ? $locked->meta : [];

            // WBS A7：先按**旧 meta** 登记密钥环（历史单钥凭据要在这里补进环），
            // 再覆盖 `agent_credential` —— 顺序反过来会把"新证书"当成历史凭据，代次凭空多一代。
            $meta[self::META_KEY_RING] = $this->appendToKeyRing($meta, $certPem);

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

            $locked->meta = $meta;
            $locked->save();

            // 让调用方手里的实例与库内一致（后续 factKeyId 等读取不能看到旧 meta）
            $binding->setRawAttributes($locked->getAttributes(), true);
        });

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
     * 设备事实签名用的 key_id —— sha256(归一化公钥 PEM)。
     *
     * 与端侧 Python 的 `fact_key_id()` 同一口径：从私钥导出 SubjectPublicKeyInfo PEM，
     * 统一换行/去首尾空白后做 sha256。证书是公开的，key_id 只是选择器，不是秘密。
     */
    public function factKeyId(DeviceBinding $binding): ?string
    {
        // 有密钥环时，"当前用哪把"必须由环回答（轮换后 active 会变）。
        if ($this->keyRing($binding)['keys'] !== []) {
            return $this->activeKeyId($binding);
        }

        $publicPem = $this->publicKeyPem($binding);

        return $publicPem === null ? null : hash('sha256', self::normalizePem($publicPem));
    }

    /**
     * 用设备登记证书的公钥校验任意规范串签名（不绑定 did|ts|method|path）。
     *
     * 传 `$keyId` 时按**那一把**密钥验签（轮换窗口内的旧密钥也能验证）；
     * 不传时沿用"当前凭据"的旧行为。
     */
    public function verifyDetachedSignature(DeviceBinding $binding, string $canonical, string $signatureB64, ?string $keyId = null): bool
    {
        if ($keyId !== null) {
            $material = $this->resolveKeyMaterial($binding, $keyId);

            if ($material === null) {
                return false;
            }

            // A7.2：指定 key_id 验签时，**吊销必须立即生效**。
            //   只验签名不验状态，会让"已吊销的私钥"继续通过 —— 吊销形同虚设。
            //   （AgentFactSignatureVerifier 另有独立的吊销判定，这里补上是为了
            //   让这个方法本身 fail-closed，避免未来调用方直接用它绕开状态检查。）
            if ($material['status'] === KeyMaterial::STATUS_REVOKED || $material['revoked_at'] !== null) {
                return false;
            }

            return $this->verifyDetachedWithCert($material['cert_pem'], $canonical, $signatureB64);
        }

        $cred = $this->credential($binding);

        if (! $cred || ! $this->isUsable($cred)) {
            return false;
        }

        $certPem = (string) ($cred['cert_pem'] ?? '');

        return $this->verifyDetachedWithCert($certPem, $canonical, $signatureB64);
    }

    /**
     * 从设备登记证书提取公钥 PEM（不可用/未登记返回 null）。
     */
    public function publicKeyPem(DeviceBinding $binding): ?string
    {
        $cred = $this->credential($binding);

        if (! $cred || ! $this->isUsable($cred)) {
            return null;
        }

        $certPem = (string) ($cred['cert_pem'] ?? '');

        if ($certPem === '') {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certPem);

        if ($publicKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details($publicKey);

        if (! is_array($details) || empty($details['key'])) {
            return null;
        }

        return (string) $details['key'];
    }

    /**
     * Python / PHP 两侧统一的 PEM 归一化：换行统一为 \n，去掉首尾空白。
     */
    public static function normalizePem(string $pem): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $pem));
    }

    /**
     * 吊销某设备的凭据（泄露/换机时可单独吊销，不影响其它设备）。
     */
    public function revoke(DeviceBinding $binding): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($binding) {
            $locked = DeviceBinding::query()->whereKey($binding->getKey())->lockForUpdate()->first() ?: $binding;
            $meta = is_array($locked->meta) ? $locked->meta : [];

            if (empty($meta[self::META_KEY])) {
                return false;
            }

            $revokedAt = now()->toDateTimeString();

            $meta[self::META_KEY]['revoked_at'] = $revokedAt;

            // A7 一致性：凭据吊销必须**同时吊销密钥环里的全部公钥**。
            // 否则 bearer secret 失效了，设备仍能用旧私钥签事实 —— 等于吊销只吊销了一半。
            $ring = $this->keyRing($locked);
            foreach ($ring['keys'] as $keyId => $entry) {
                $ring['keys'][$keyId]['status'] = KeyMaterial::STATUS_REVOKED;
                $ring['keys'][$keyId]['revoked_at'] = $revokedAt;
                $ring['keys'][$keyId]['reason'] = 'credential_revoked';
            }
            if ($ring['keys'] !== []) {
                $meta[self::META_KEY_RING] = $ring;
            }

            $locked->meta = $meta;
            $locked->save();
            $binding->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** 这台设备的凭据是否已被吊销。 */
    public function isRevoked(DeviceBinding $binding): bool
    {
        $cred = $this->credential($binding);

        return $cred !== null && ! empty($cred['revoked_at']);
    }

    /** 这台设备当前是否持有可用 per-device 凭据。 */
    public function hasCredential(DeviceBinding $binding): bool
    {
        $cred = $this->credential($binding);

        return $cred !== null && $this->isUsable($cred);
    }

    // ── A7 密钥环（key_id / epoch / rotation / revocation） ──────────────────

    /**
     * 读取密钥环（不落库，只读）。
     *
     * @return array{epoch:int,keys:array<string,array<string,mixed>>}
     */
    public function keyRing(DeviceBinding $binding): array
    {
        $meta = is_array($binding->meta) ? $binding->meta : [];
        $ring = $meta[self::META_KEY_RING] ?? [];

        return [
            'epoch' => (int) ($ring['epoch'] ?? 0),
            'keys'  => is_array($ring['keys'] ?? null) ? $ring['keys'] : [],
        ];
    }

    /** 当前活动密钥的 key_id；全部被吊销/过期时返回 null（fail-closed）。 */
    public function activeKeyId(DeviceBinding $binding): ?string
    {
        $ring = $this->keyRing($binding);
        $now = now()->timestamp;
        $bestId = null;
        $bestEpoch = -1;

        foreach ($ring['keys'] as $keyId => $entry) {
            if (($entry['status'] ?? '') !== 'active' || ! empty($entry['revoked_at'])) {
                continue;
            }

            $notAfter = $this->toTimestamp($entry['not_after'] ?? null);
            if ($notAfter !== null && $notAfter < $now) {
                continue;
            }

            $epoch = (int) ($entry['epoch'] ?? 0);
            if ($epoch > $bestEpoch) {
                $bestId = (string) $keyId;
                $bestEpoch = $epoch;
            }
        }

        return $bestId;
    }

    /** 当前代次（没有环时按 1 代处理）。 */
    public function currentEpoch(DeviceBinding $binding): int
    {
        $ring = $this->keyRing($binding);

        if ($ring['epoch'] > 0) {
            return $ring['epoch'];
        }

        return 1;
    }

    /**
     * 解析一把密钥（优先环，其次兼容"没有环的历史单钥凭据"）。
     *
     * @return array{key_id:string,cert_pem:string,epoch:int,status:string,not_before:?int,not_after:?int,revoked_at:?int,reason:string}|null
     */
    public function resolveKeyMaterial(DeviceBinding $binding, string $keyId): ?array
    {
        $ring = $this->keyRing($binding);
        $entry = $ring['keys'][$keyId] ?? null;

        if (is_array($entry)) {
            return [
                'key_id'     => $keyId,
                'cert_pem'   => (string) ($entry['cert_pem'] ?? ''),
                'epoch'      => (int) ($entry['epoch'] ?? 0),
                'status'     => (string) ($entry['status'] ?? KeyMaterial::STATUS_RETIRED),
                'not_before' => $this->toTimestamp($entry['not_before'] ?? null),
                'not_after'  => $this->toTimestamp($entry['not_after'] ?? null),
                'revoked_at' => $this->toTimestamp($entry['revoked_at'] ?? null),
                'reason'     => (string) ($entry['reason'] ?? ''),
            ];
        }

        return $this->legacyEntry($binding, $keyId);
    }

    /** 环里是否有任何密钥材料（含已吊销）：用于"必须提供 v3 签名"的判定。 */
    public function hasAnyKeyMaterial(DeviceBinding $binding): bool
    {
        if ($this->keyRing($binding)['keys'] !== []) {
            return true;
        }

        return $this->credential($binding) !== null;
    }

    /**
     * 吊销一把密钥。**立即**失效，不等轮换窗口。
     * 若吊销的是唯一活动密钥，`activeKeyId()` 随之为 null —— 设备必须重新签发才能上报。
     */
    public function revokeKey(DeviceBinding $binding, string $keyId, string $reason = ''): bool
    {
        // 与 issue() 同一把行锁：并发"轮换 + 吊销"如果各写各的 meta，会把对方的结果抹掉。
        return \Illuminate\Support\Facades\DB::transaction(function () use ($binding, $keyId, $reason) {
            $locked = DeviceBinding::query()->whereKey($binding->getKey())->lockForUpdate()->first() ?: $binding;

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $ring = $this->keyRing($locked);

            if (isset($ring['keys'][$keyId])) {
                // A7.2：吊销是**幂等**的 —— 已被吊销的密钥保留第一次的 `revoked_at`。
                //   并发吊销（或 Agent 重试）若各写各的时间戳，会得到"吊销时间一直在变"，
                //   审计上无法回答"它是什么时候被吊销的"。
                if (($ring['keys'][$keyId]['status'] ?? '') === KeyMaterial::STATUS_REVOKED
                    || ! empty($ring['keys'][$keyId]['revoked_at'])) {
                    return true;
                }

                $ring['keys'][$keyId]['status'] = KeyMaterial::STATUS_REVOKED;
                $ring['keys'][$keyId]['revoked_at'] = now()->toDateTimeString();
                if ($reason !== '') {
                    $ring['keys'][$keyId]['reason'] = $reason;
                }
                $meta[self::META_KEY_RING] = $ring;
                $locked->meta = $meta;
                $locked->save();
                $binding->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            // 兼容历史单钥凭据：它可能就是当前唯一那把
            $legacy = $this->legacyEntry($locked, $keyId);
            if ($legacy !== null && is_array($meta[self::META_KEY] ?? null)) {
                $meta[self::META_KEY]['revoked_at'] = now()->toDateTimeString();
                $locked->meta = $meta;
                $locked->save();
                $binding->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            return false;
        });
    }

    /** 用指定证书验签（证书必须链到平台 CA）。 */
    public function verifyDetachedWithCert(string $certPem, string $canonical, string $signatureB64): bool
    {
        if ($certPem === '' || ! $this->chainsToPlatformCa($certPem)) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 把新证书登记进环，并让旧活动密钥进入 retirement 窗口。
     *
     * @param  array<string,mixed>  $meta  即将写回 binding.meta 的完整数组
     * @return array{epoch:int,keys:array<string,array<string,mixed>>}
     */
    protected function appendToKeyRing(array $meta, string $certPem): array
    {
        $ring = is_array($meta[self::META_KEY_RING] ?? null) ? $meta[self::META_KEY_RING] : [];
        $ring['keys'] = is_array($ring['keys'] ?? null) ? $ring['keys'] : [];
        $epoch = (int) ($ring['epoch'] ?? 0);

        // 第一次轮换时，把历史上的单钥凭据补进环 —— 否则它会在"轮换"瞬间凭空消失，
        // 在途事实（设备还没拿到新密钥）会被全部拒绝。
        $legacy = $meta[self::META_KEY] ?? null;
        if ($ring['keys'] === [] && is_array($legacy) && ! empty($legacy['cert_pem'])) {
            $legacyId = self::keyIdForCert((string) $legacy['cert_pem']);
            if ($legacyId !== null) {
                $ring['keys'][$legacyId] = [
                    'cert_pem'   => (string) $legacy['cert_pem'],
                    'epoch'      => 1,
                    'status'     => empty($legacy['revoked_at']) ? KeyMaterial::STATUS_ACTIVE : KeyMaterial::STATUS_REVOKED,
                    'created_at' => (string) ($legacy['issued_at'] ?? now()->toDateTimeString()),
                    'not_before' => null,
                    'not_after'  => null,
                    'revoked_at' => $legacy['revoked_at'] ?? null,
                    'reason'     => 'legacy',
                ];
                $epoch = max($epoch, 1);
            }
        }

        $window = (int) config('deploy_gateways.device_credential.rotation_window_seconds', 86400);
        $retireAt = now()->addSeconds($window)->toDateTimeString();

        foreach ($ring['keys'] as $id => $entry) {
            if (($entry['status'] ?? '') === KeyMaterial::STATUS_ACTIVE) {
                $ring['keys'][$id]['status'] = KeyMaterial::STATUS_RETIRED;
                $ring['keys'][$id]['not_after'] = $retireAt;
                $ring['keys'][$id]['reason'] = $entry['reason'] ?? 'rotated';
            }
        }

        $newKeyId = self::keyIdForCert($certPem);
        if ($newKeyId !== null) {
            $epoch += 1;
            $ring['keys'][$newKeyId] = [
                'cert_pem'   => $certPem,
                'epoch'      => $epoch,
                'status'     => KeyMaterial::STATUS_ACTIVE,
                'created_at' => now()->toDateTimeString(),
                'not_before' => null,
                'not_after'  => null,
                'revoked_at' => null,
                'reason'     => '',
            ];
        }

        $ring['epoch'] = $epoch;

        return $ring;
    }

    /**
     * 历史单钥凭据的条目（没有密钥环时使用）。
     *
     * @return array{key_id:string,cert_pem:string,epoch:int,status:string,not_before:?int,not_after:?int,revoked_at:?int,reason:string}|null
     */
    protected function legacyEntry(DeviceBinding $binding, string $keyId): ?array
    {
        $cred = $this->credential($binding);
        if ($cred === null || empty($cred['cert_pem'])) {
            return null;
        }

        $legacyId = self::keyIdForCert((string) $cred['cert_pem']);
        if ($legacyId === null || ! hash_equals($legacyId, $keyId)) {
            return null;
        }

        return [
            'key_id'     => $legacyId,
            'cert_pem'   => (string) $cred['cert_pem'],
            'epoch'      => 1,
            'status'     => empty($cred['revoked_at']) ? KeyMaterial::STATUS_ACTIVE : KeyMaterial::STATUS_REVOKED,
            'not_before' => null,
            'not_after'  => $this->toTimestamp($cred['expires_at'] ?? null),
            'revoked_at' => $this->toTimestamp($cred['revoked_at'] ?? null),
            'reason'     => 'legacy',
        ];
    }

    protected static function keyIdForCert(string $certPem): ?string
    {
        $publicKey = @openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details($publicKey);
        if (! is_array($details) || empty($details['key'])) {
            return null;
        }

        return hash('sha256', self::normalizePem((string) $details['key']));
    }

    protected function toTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->timestamp;
        } catch (\Throwable) {
            return null;
        }
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

        return openssl_pkey_get_private($this->keyGet(self::CA_PRIVATE_FILE));
    }

    protected function caCert()
    {
        $this->ensureCa();

        return openssl_x509_read($this->caCertPem());
    }

    public function caCertPem(): string
    {
        $this->ensureCa();

        return $this->keyGet(self::CA_PUBLIC_FILE);
    }

    protected function caPublicKey()
    {
        return openssl_pkey_get_public($this->caCertPem());
    }

    /**
     * 生成/确保平台设备 CA（自签根证书）。开发期默认在 storage/app/keys 下；
     * 生产应把 `deploy_gateways.keys` 指到受限持久卷。**KMS/HSM 未实现**（与 SigningService 同一口径）。
     *
     * @param  bool  $force  true = 强制重签整张 CA（`agent:keys:generate --replace` 用；
     *                      旧 CA 证书会被覆盖，老设备的 cert 将链不上 → 验签路径全 401）
     */
    public function ensureCa(bool $force = false): void
    {
        $privateExists = $this->keyExists(self::CA_PRIVATE_FILE);
        $certExists = $this->keyExists(self::CA_PUBLIC_FILE);

        if (! $force && $privateExists && $certExists) {
            return;
        }

        $opts = $this->keyOpts();

        // CA 私钥还在、只缺证书 → **用同一把私钥重签证书**。
        // 公钥不变，已登记设备的 cert_pem（旧 CA 签发的）仍能链到平台 CA；
        // 重签整张 CA 会让所有老设备 `chainsToPlatformCa()` 为 false（401），绝不能做。
        if (! $force && $privateExists) {
            $caPrivatePem = $this->keyGet(self::CA_PRIVATE_FILE);
            $caKey = openssl_pkey_get_private($caPrivatePem);

            if ($caKey === false) {
                throw new \RuntimeException('CA 私钥无法解析，无法重签证书：'.$this->keyPath(self::CA_PRIVATE_FILE));
            }

            $this->writeCaCert($caKey, $opts);

            return;
        }

        // CA 私钥也不在（或强制重建）→ 重签整张 CA。
        $caKey = openssl_pkey_new($opts);
        if ($caKey === false) {
            throw new \RuntimeException('CA 密钥生成失败：'.(openssl_error_string() ?: '未知(openssl.cnf 缺失?)'));
        }

        openssl_pkey_export($caKey, $caPrivatePem, null, $opts);

        // 先私钥后证书：若证书写失败，下一次调用会走上面的「用同一把私钥重签」分支恢复；
        // 反过来会留下「有证书无私钥」——那意味着这张 CA 已经不可续签且会被误判为在位。
        $this->keyPut(self::CA_PRIVATE_FILE, $caPrivatePem, true);
        $this->writeCaCert($caKey, $opts);
    }

    /**
     * 用给定 CA 私钥自签一张 CA 证书并落盘（公钥由私钥决定，调用方保证不变）。
     */
    protected function writeCaCert($caKey, array $opts): void
    {
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

        openssl_x509_export($caCert, $caCertPem);

        $this->keyPut(self::CA_PUBLIC_FILE, $caCertPem, false);
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
