<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\Contracts\KeyMaterial;
use Webkul\Shop\Services\Deploy\Contracts\KeyProvider;

/**
 * 本地 DeviceKeyProvider —— A7 接口的唯一实现。
 *
 * 后端是「平台 CA + `device_binding.meta.agent_key_ring`」：
 *   · 每把设备公钥按 key_id 登记代次与状态；
 *   · 轮换 = 发新证书 + 旧密钥 retired 进窗口；
 *   · 吊销 = 立即失效，不等窗口。
 *
 * ⚠ A7.3：**这不是 KMS/HSM**。私钥仍由平台在签发瞬间生成并一次性返回给配对流程，
 *   并没有托管、审计、双人授权或硬件隔离。`capabilities()` 如实返回 kms=false / hsm=false；
 *   真实密钥托管接入前，任何"密钥已托管"的表述都是假的。
 */
class DeviceKeyProvider implements KeyProvider
{
    public function __construct(protected DeviceCredentialService $credentials) {}

    public function resolve(string $scope, string $keyId): ?KeyMaterial
    {
        $binding = $this->binding($scope);
        if ($binding === null || $keyId === '') {
            return null;
        }

        $raw = $this->credentials->resolveKeyMaterial($binding, $keyId);
        if ($raw === null || ($raw['cert_pem'] ?? '') === '') {
            return null;
        }

        return new KeyMaterial(
            keyId: (string) $raw['key_id'],
            certPem: (string) $raw['cert_pem'],
            epoch: (int) $raw['epoch'],
            status: (string) $raw['status'],
            notBefore: $raw['not_before'] ?? null,
            notAfter: $raw['not_after'] ?? null,
            revokedAt: $raw['revoked_at'] ?? null,
            reason: (string) ($raw['reason'] ?? ''),
        );
    }

    public function activeKeyId(string $scope): ?string
    {
        $binding = $this->binding($scope);

        return $binding === null ? null : $this->credentials->activeKeyId($binding);
    }

    public function currentEpoch(string $scope): int
    {
        $binding = $this->binding($scope);

        return $binding === null ? 1 : $this->credentials->currentEpoch($binding);
    }

    public function rotate(string $scope, string $reason = ''): ?KeyMaterial
    {
        $binding = $this->binding($scope);
        if ($binding === null) {
            return null;
        }

        // issue() 会：签发新证书 → 旧活动密钥 retired（not_after = now + 窗口）→ 代次 +1
        $this->credentials->issue($binding);
        $binding->refresh();

        $keyId = $this->credentials->activeKeyId($binding);

        return $keyId === null ? null : $this->resolve($scope, $keyId);
    }

    public function revoke(string $scope, string $keyId, string $reason = ''): bool
    {
        $binding = $this->binding($scope);

        return $binding !== null && $this->credentials->revokeKey($binding, $keyId, $reason);
    }

    public function capabilities(): array
    {
        return [
            'kms'              => false,
            'hsm'              => false,
            'rotation'         => true,
            'revocation'       => true,
            'epochs'           => true,
            'offline_recovery' => true,
        ];
    }

    protected function binding(string $scope): ?DeviceBinding
    {
        if ($scope === '') {
            return null;
        }

        return DeviceBinding::query()->where('did', $scope)->first();
    }
}
