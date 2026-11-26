<?php

namespace Webkul\Shop\Services\SkillCompile;

use InvalidArgumentException;

/** 目标级审核/发布只追加事件契约。 */
final class TargetReleaseEvent
{
    public const EVENT_TYPES = ['approved', 'rejected', 'revoked'];

    public function __construct(
        public readonly int $skillVersionId,
        public readonly string $targetModelCode,
        public readonly int $artifactAssetId,
        public readonly string $buildKey,
        public readonly string $targetProfileDigest,
        public readonly string $eventType,
        public readonly int $actorId,
        public readonly ?string $reason,
        public readonly int $createdAt,
    ) {
        if ($skillVersionId <= 0 || $artifactAssetId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('TargetReleaseEvent 身份字段必须为正整数');
        }
        foreach ([$targetModelCode, $buildKey, $targetProfileDigest] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('TargetReleaseEvent 关键字段不得为空');
            }
        }
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('TargetReleaseEvent.event_type 非法');
        }
        if (in_array($eventType, ['rejected', 'revoked'], true) && trim((string) $reason) === '') {
            throw new InvalidArgumentException('拒绝或撤销必须记录原因');
        }
        if ($createdAt <= 0) {
            throw new InvalidArgumentException('TargetReleaseEvent.created_at 非法');
        }
    }

    public function toArray(): array
    {
        return [
            'skill_version_id' => $this->skillVersionId,
            'target_model_code' => $this->targetModelCode,
            'artifact_asset_id' => $this->artifactAssetId,
            'build_key' => $this->buildKey,
            'target_profile_digest' => $this->targetProfileDigest,
            'event_type' => $this->eventType,
            'actor_id' => $this->actorId,
            'reason' => $this->reason,
            'created_at' => $this->createdAt,
        ];
    }
}