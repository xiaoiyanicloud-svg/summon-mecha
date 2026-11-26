<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

/**
 * 一把可用公钥的**生命周期视图**（WBS A7）。
 *
 * 只有三件事实：它是谁（key_id）、它还能不能用（status/window）、它是第几代（epoch）。
 * 刻意不携带私钥 —— 私钥永远不进入平台侧存储。
 */
final class KeyMaterial
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        public readonly string $keyId,
        public readonly string $certPem,
        public readonly int $epoch,
        public readonly string $status,
        public readonly ?int $notBefore = null,
        public readonly ?int $notAfter = null,
        public readonly ?int $revokedAt = null,
        public readonly string $reason = '',
    ) {}

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED || $this->revokedAt !== null;
    }

    /** 轮换窗口内仍可验签：已退休但还没到 `not_after`。 */
    public function isWithinRotationWindow(int $now): bool
    {
        return $this->status === self::STATUS_RETIRED
            && $this->notAfter !== null
            && $now <= $this->notAfter;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isRevoked();
    }

    public function isUsableAt(int $now, int $minAcceptedEpoch): bool
    {
        if ($this->isRevoked()) {
            return false;
        }

        if ($this->notBefore !== null && $now < $this->notBefore) {
            return false;
        }

        if ($this->status === self::STATUS_ACTIVE) {
            return $this->epoch >= $minAcceptedEpoch;
        }

        return $this->isWithinRotationWindow($now) && $this->epoch >= $minAcceptedEpoch;
    }
}
