<?php

namespace Webkul\Shop\Services\SkillCompile;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillTargetReleaseEvent;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/** 目标级审核/发布状态机。产物、构建键和 Profile 摘要必须同时匹配。 */
class SkillTargetReleaseService
{
    public function __construct(protected TargetProfileService $profiles) {}

    public function approve(int $releaseId, int $actorId): SkillTargetRelease
    {
        return DB::transaction(function () use ($releaseId, $actorId) {
            $release = $this->locked($releaseId);
            if ($release->review_status === SkillTargetRelease::STATUS_REVOKED) {
                throw new DomainException('TARGET_RELEASE_REVOKED');
            }
            $this->assertSnapshot($release);

            $release->review_status = SkillTargetRelease::STATUS_APPROVED;
            $release->reviewed_at = now();
            $release->reviewer_id = $actorId;
            $release->rejection_reason = null;
            $release->save();
            $this->event($release, 'approved', $actorId);

            return $release->fresh(['artifactAsset']);
        });
    }

    public function reject(int $releaseId, int $actorId, string $reason): SkillTargetRelease
    {
        if (trim($reason) === '') {
            throw new DomainException('TARGET_RELEASE_REJECTION_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($releaseId, $actorId, $reason) {
            $release = $this->locked($releaseId);
            if ($release->review_status === SkillTargetRelease::STATUS_REVOKED) {
                throw new DomainException('TARGET_RELEASE_REVOKED');
            }
            $release->review_status = SkillTargetRelease::STATUS_REJECTED;
            $release->reviewed_at = now();
            $release->reviewer_id = $actorId;
            $release->rejection_reason = $reason;
            $release->save();
            $this->event($release, 'rejected', $actorId, $reason);

            return $release->fresh();
        });
    }

    public function revoke(int $releaseId, int $actorId, string $reason): SkillTargetRelease
    {
        if (trim($reason) === '') {
            throw new DomainException('TARGET_RELEASE_REVOCATION_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($releaseId, $actorId, $reason) {
            $release = $this->locked($releaseId);
            if ($release->review_status !== SkillTargetRelease::STATUS_APPROVED) {
                throw new DomainException('TARGET_RELEASE_NOT_APPROVED');
            }
            $release->review_status = SkillTargetRelease::STATUS_REVOKED;
            $release->reviewed_at = now();
            $release->reviewer_id = $actorId;
            $release->rejection_reason = $reason;
            $release->save();
            $this->event($release, 'revoked', $actorId, $reason);

            return $release->fresh();
        });
    }

    public function assertDeliverable(SkillTargetRelease $release): void
    {
        if (! $release->isApproved()) {
            throw new DomainException('TARGET_RELEASE_NOT_APPROVED');
        }
        $this->assertSnapshot($release);
    }

    protected function locked(int $releaseId): SkillTargetRelease
    {
        return SkillTargetRelease::query()->whereKey($releaseId)->lockForUpdate()->firstOrFail();
    }

    protected function assertSnapshot(SkillTargetRelease $release): void
    {
        $artifact = SkillAsset::query()->whereKey($release->artifact_asset_id)->first();
        if (! $artifact
            || $artifact->asset_role !== SkillAsset::ROLE_ARTIFACT
            || $artifact->target_model_code !== $release->target_model_code
            || $artifact->build_key !== $release->build_key) {
            throw new DomainException('TARGET_RELEASE_ARTIFACT_SNAPSHOT_MISMATCH');
        }

        if (! $release->target_profile_digest
            || $release->target_profile_digest !== $this->profiles->getDigest($release->target_model_code)) {
            throw new DomainException('TARGET_PROFILE_DIGEST_STALE');
        }
    }

    protected function event(SkillTargetRelease $release, string $type, int $actorId, ?string $reason = null): void
    {
        Log::info('skill_target_release.' . $type, [
            'service' => 'skill-compiler',
            'env' => app()->environment(),
            'build_key' => $release->build_key,
            'vendor' => explode('_', (string) $release->target_model_code)[0],
            'model_code' => $release->target_model_code,
            'status' => $type,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);

        $event = new TargetReleaseEvent(
            skillVersionId: (int) $release->skill_version_id,
            targetModelCode: (string) $release->target_model_code,
            artifactAssetId: (int) $release->artifact_asset_id,
            buildKey: (string) $release->build_key,
            targetProfileDigest: (string) $release->target_profile_digest,
            eventType: $type,
            actorId: $actorId,
            reason: $reason,
            createdAt: now()->timestamp,
        );

        SkillTargetReleaseEvent::create($event->toArray());
    }
}