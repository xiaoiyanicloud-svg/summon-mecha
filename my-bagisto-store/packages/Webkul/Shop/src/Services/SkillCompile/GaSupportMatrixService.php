<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Services\SkillCompile\Portable\TargetProfile;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/** 五机型客户可见支持矩阵。只报告已登记事实，不把 L2 或 SIL 外推为 GA。 */
class GaSupportMatrixService
{
    public function __construct(protected TargetProfileService $profiles) {}

    public function build(): array
    {
        $rows = [];

        foreach ($this->profiles->gaProfiles() as $profile) {
            $verification = $profile->raw['verification_status'] ?? [];
            $rows[] = [
                'vendor' => $profile->vendor,
                'model_code' => $profile->modelCode,
                'model_name' => $profile->modelName,
                'sdk_name' => $profile->sdkName,
                'sdk_version_range' => $profile->sdkVersionRange,
                'firmware_range' => $profile->firmwareRange,
                'compatibility_level' => $profile->compatibilityLevel,
                'feature_enabled' => (bool) config("skill_ga.targets.{$profile->modelCode}", false),
                'capability_actions' => array_keys($profile->capabilities['actions'] ?? []),
                'artifact_abi' => $profile->artifactAbi,
                'profile_digest' => $profile->profileDigest,
                'software_complete' => (string) ($verification['software_driver'] ?? 'PARTIAL'),
                'sdk_integrated' => (string) ($verification['sdk_contract'] ?? 'PARTIAL'),
                'sil' => (string) ($verification['sil'] ?? 'NOT_RUN'),
                'hil' => (string) ($verification['hil'] ?? $profile->hardwareVerificationStatus),
                'hardware_verification_status' => $profile->hardwareVerificationStatus,
                'production_ready' => $profile->isProductionReady() ? 'READY' : 'BLOCKED',
                'blocked_reasons' => $this->blockedReasons($profile),
            ];
        }

        return [
            'profile_schema' => 'zhaohuan.support-matrix/v1',
            'generated_at' => now()->toIso8601String(),
            'default_enabled' => false,
            'models' => $rows,
        ];
    }

    protected function blockedReasons(TargetProfile $profile): array
    {
        $reasons = [];
        if ($profile->compatibilityLevel !== 'L1') {
            $reasons[] = 'COMPATIBILITY_NOT_L1';
        }
        if ($profile->hardwareVerificationStatus !== 'HARDWARE_VERIFIED') {
            $reasons[] = 'HIL_HARDWARE_UNVERIFIED';
        }
        if (($profile->jointLimits['status'] ?? null) === 'UNRESOLVED'
            || ($profile->velocityLimits['status'] ?? null) === 'UNRESOLVED'
            || ($profile->torqueLimits['status'] ?? null) === 'UNRESOLVED'
            || ($profile->temperatureLimits['status'] ?? null) === 'UNRESOLVED'
            || str_contains((string) ($profile->raw['safety_envelope']['status'] ?? ''), 'UNRESOLVED')) {
            $reasons[] = 'VENDOR_LIMITS_UNRESOLVED';
        }
        if (($profile->raw['verification_status']['sil'] ?? 'NOT_RUN') !== 'PASS') {
            $reasons[] = 'SIL_NOT_PASSED';
        }
        if (($profile->raw['release']['default_enabled'] ?? true) !== false) {
            $reasons[] = 'RELEASE_DEFAULT_NOT_CLOSED';
        }

        return array_values(array_unique($reasons));
    }
}