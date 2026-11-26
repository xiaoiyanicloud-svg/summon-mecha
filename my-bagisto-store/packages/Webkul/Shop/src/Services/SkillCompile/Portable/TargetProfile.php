<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use InvalidArgumentException;

/**
 * 目标机型 Profile 强类型契约。
 *
 * L1 只有在硬件验证通过后才允许使用。当前五机型均未取得真机 HIL，
 * 因此运行 Profile 必须保持 L2 + HARDWARE_UNVERIFIED，禁止通过宣传字段升级。
 */
final class TargetProfile
{
    public const SCHEMA = 'zhaohuan.target-profile/v2';

    public const COMPATIBILITY_LEVELS = ['L1', 'L2', 'L3'];

    public const HARDWARE_STATUSES = ['HARDWARE_VERIFIED', 'HARDWARE_UNVERIFIED'];

    public function __construct(
        public readonly string $vendor,
        public readonly string $modelCode,
        public readonly string $modelName,
        public readonly string $sdkName,
        public readonly string $sdkVersionRange,
        public readonly string $firmwareRange,
        public readonly string $compatibilityLevel,
        public readonly array $capabilities,
        public readonly array $jointLimits,
        public readonly array $velocityLimits,
        public readonly array $torqueLimits,
        public readonly array $temperatureLimits,
        public readonly string $artifactAbi,
        public readonly string $profileDigest,
        public readonly string $hardwareVerificationStatus,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data, string $profileDigest): self
    {
        $required = [
            'vendor',
            'model_code',
            'model_name',
            'sdk_name',
            'sdk_version_range',
            'firmware_range',
            'compatibility_level',
            'capabilities',
            'joint_limits',
            'velocity_limits',
            'torque_limits',
            'temperature_limits',
            'artifact_abi',
            'hardware_verification_status',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("TargetProfile 缺少字段: {$field}");
            }
        }

        if (($data['profile_schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('TargetProfile schema 必须为 '.self::SCHEMA);
        }

        foreach (['vendor', 'model_code', 'model_name', 'sdk_name', 'sdk_version_range', 'firmware_range', 'artifact_abi'] as $field) {
            if (! is_string($data[$field]) || trim($data[$field]) === '') {
                throw new InvalidArgumentException("TargetProfile.{$field} 必须为非空字符串");
            }
        }

        if (! in_array($data['compatibility_level'], self::COMPATIBILITY_LEVELS, true)) {
            throw new InvalidArgumentException('TargetProfile.compatibility_level 仅允许 L1/L2/L3');
        }

        if (! in_array($data['hardware_verification_status'], self::HARDWARE_STATUSES, true)) {
            throw new InvalidArgumentException('TargetProfile.hardware_verification_status 非法');
        }

        foreach (['capabilities', 'joint_limits', 'velocity_limits', 'torque_limits', 'temperature_limits'] as $field) {
            if (! is_array($data[$field])) {
                throw new InvalidArgumentException("TargetProfile.{$field} 必须为对象");
            }
        }

        $actions = $data['capabilities']['actions'] ?? null;
        if (! is_array($actions) || $actions === []) {
            throw new InvalidArgumentException('TargetProfile.capabilities.actions 必须为非空对象');
        }

        foreach ($actions as $actionId => $action) {
            if (! is_string($actionId) || ! is_array($action)) {
                throw new InvalidArgumentException('TargetProfile capability action 结构非法');
            }
            if (! isset($action['required_permissions']) || ! is_array($action['required_permissions'])) {
                throw new InvalidArgumentException("TargetProfile action {$actionId} 缺少 required_permissions");
            }
        }

        if ($data['compatibility_level'] === 'L1' && $data['hardware_verification_status'] !== 'HARDWARE_VERIFIED') {
            throw new InvalidArgumentException('L1 TargetProfile 必须具备 HARDWARE_VERIFIED 证据');
        }

        if ($profileDigest === '' || ! str_starts_with($profileDigest, 'sha256:')) {
            throw new InvalidArgumentException('TargetProfile profile_digest 必须为 sha256 摘要');
        }

        return new self(
            vendor: $data['vendor'],
            modelCode: $data['model_code'],
            modelName: $data['model_name'],
            sdkName: $data['sdk_name'],
            sdkVersionRange: $data['sdk_version_range'],
            firmwareRange: $data['firmware_range'],
            compatibilityLevel: $data['compatibility_level'],
            capabilities: $data['capabilities'],
            jointLimits: $data['joint_limits'],
            velocityLimits: $data['velocity_limits'],
            torqueLimits: $data['torque_limits'],
            temperatureLimits: $data['temperature_limits'],
            artifactAbi: $data['artifact_abi'],
            profileDigest: $profileDigest,
            hardwareVerificationStatus: $data['hardware_verification_status'],
            raw: $data,
        );
    }

    public function isProductionReady(): bool
    {
        $verification = $this->raw['verification_status'] ?? [];
        $envelopeStatus = (string) (($this->raw['safety_envelope']['status'] ?? ''));

        return $this->compatibilityLevel === 'L1'
            && $this->hardwareVerificationStatus === 'HARDWARE_VERIFIED'
            && ($verification['sil'] ?? 'NOT_RUN') === 'PASS'
            && ($this->jointLimits['status'] ?? 'UNRESOLVED') !== 'UNRESOLVED'
            && ! str_contains($envelopeStatus, 'UNRESOLVED')
            && ($this->raw['release']['default_enabled'] ?? true) === false
            && ($this->raw['release']['requires_target_review'] ?? false) === true;
    }

    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'model_code' => $this->modelCode,
            'model_name' => $this->modelName,
            'sdk_name' => $this->sdkName,
            'sdk_version_range' => $this->sdkVersionRange,
            'firmware_range' => $this->firmwareRange,
            'compatibility_level' => $this->compatibilityLevel,
            'capabilities' => $this->capabilities,
            'joint_limits' => $this->jointLimits,
            'velocity_limits' => $this->velocityLimits,
            'torque_limits' => $this->torqueLimits,
            'temperature_limits' => $this->temperatureLimits,
            'artifact_abi' => $this->artifactAbi,
            'profile_digest' => $this->profileDigest,
            'hardware_verification_status' => $this->hardwareVerificationStatus,
            'production_ready' => $this->isProductionReady(),
        ];
    }
}