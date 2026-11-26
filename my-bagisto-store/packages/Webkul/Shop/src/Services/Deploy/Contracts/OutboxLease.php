<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/** `deploy_task` 作为事务 Outbox 时的领取租约。 */
final class OutboxLease
{
    public function __construct(
        public readonly int $taskId,
        public readonly string $token,
        public readonly int $attempt,
        public readonly int $acquiredAt,
        public readonly int $expiresAt,
    ) {
        if ($taskId <= 0 || $attempt <= 0 || $acquiredAt <= 0 || $expiresAt <= $acquiredAt) {
            throw new InvalidArgumentException('OutboxLease 身份或时间窗口非法');
        }
        if (! preg_match('/^[A-Za-z0-9]{32,128}$/', $token)) {
            throw new InvalidArgumentException('OutboxLease token 非法');
        }
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'token' => $this->token,
            'attempt' => $this->attempt,
            'acquired_at' => $this->acquiredAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}