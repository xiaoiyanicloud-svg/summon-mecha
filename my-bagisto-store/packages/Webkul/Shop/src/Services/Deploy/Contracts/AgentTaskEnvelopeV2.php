<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/** Agent 下行命令 v2 契约。签名字段由 SigningService 生成，本 DTO 不保存私钥。 */
final class AgentTaskEnvelopeV2
{
    public const PROTOCOL = 'agent-task/v2';

    public function __construct(
        public readonly string $taskId,
        public readonly int $commandSeq,
        public readonly string $did,
        public readonly int $operation,
        public readonly string $modelCode,
        public readonly string $packageSha256,
        public readonly string $targetProfileDigest,
        public readonly string $nonce,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly array $payload,
        public readonly string $signature,
        public readonly string $keyId,
    ) {
        if ($taskId === '' || $did === '' || $modelCode === '' || $nonce === '' || $keyId === '') {
            throw new InvalidArgumentException('AgentTaskEnvelopeV2 关键身份字段不得为空');
        }
        if ($commandSeq <= 0 || $issuedAt <= 0 || $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('AgentTaskEnvelopeV2 序号或时间窗口非法');
        }
        if ($packageSha256 !== '' && ! preg_match('/^[a-f0-9]{64}$/i', $packageSha256)) {
            throw new InvalidArgumentException('AgentTaskEnvelopeV2 package_sha256 非法');
        }
        if (! str_starts_with($targetProfileDigest, 'sha256:')) {
            throw new InvalidArgumentException('AgentTaskEnvelopeV2 target_profile_digest 非法');
        }
        if ($signature === '') {
            throw new InvalidArgumentException('AgentTaskEnvelopeV2 必须签名');
        }
    }

    public function signedFields(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'task_id' => $this->taskId,
            'command_seq' => $this->commandSeq,
            'did' => $this->did,
            'operation' => $this->operation,
            'model_code' => $this->modelCode,
            'package_sha256' => $this->packageSha256,
            'target_profile_digest' => $this->targetProfileDigest,
            'nonce' => $this->nonce,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'payload' => $this->payload,
        ];
    }

    public function toArray(): array
    {
        return $this->signedFields() + [
            'signature' => $this->signature,
            'key_id' => $this->keyId,
        ];
    }
}