<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/**
 * 独立停止观察（WBS A2）。
 *
 * 只有设备侧**独立观察器**给出的物理证据才能作为终态依据：
 *   - source_type 白名单外的“SDK 返回 / 按钮取消 / 云端 ACK / 脚本断言 / mock / SIL”一律拒绝；
 *   - 形状非法、质量不合格、过期、速度/电流/抱闸不满足 → 由调用方降级为 UNKNOWN；
 *   - 原始证据哈希只校验格式；**字节级比对需要原始证据上传，当前 BLOCKED**。
 *
 * ⚠ 硬件接口、采样频率、断线语义、actual_stopped 的厂商级定义仍待补充（槽位 1）。
 *   本类实现的是平台侧可验证的**契约形状**，不代表任何真机 HIL 已验证。
 */
final class StopObservation
{
    public const SOURCE_TYPES = [
        'hardware_estop',
        'motor_controller_feedback',
        'safety_plc',
        'independent_watchdog',
    ];

    public const FORBIDDEN_SOURCE_TYPES = [
        'sdk_return',
        'sdk_true',
        'bt_halt',
        'cancel_requested',
        'cloud_ack',
        'script_assertion',
        'mock',
        'sil',
    ];

    public const QUALITY_LEVELS = ['good', 'degraded', 'bad'];

    public function __construct(
        public readonly string $executionId,
        public readonly string $sourceType,
        public readonly string $sensorId,
        public readonly int $observedAt,
        public readonly bool $actualStarted,
        public readonly bool $actualStopped,
        public readonly ?float $velocity,
        public readonly ?float $current,
        public readonly ?bool $contact,
        public readonly ?bool $brake,
        public readonly ?bool $power,
        public readonly string $quality,
        public readonly string $rawEvidenceSha256,
        public readonly string $collectorVersion,
    ) {
        $sourceType = strtolower(trim($sourceType));

        if (in_array($sourceType, self::FORBIDDEN_SOURCE_TYPES, true)) {
            throw new StopObservationSourceForbiddenException(
                "StopObservation.source_type={$sourceType} 不是物理停止证据，必须拒绝。"
            );
        }

        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('StopObservation.source_type 不在允许的独立观察器白名单内。');
        }

        if (trim($executionId) === '') {
            throw new InvalidArgumentException('StopObservation.execution_id 不得为空');
        }

        if (trim($sensorId) === '') {
            throw new InvalidArgumentException('StopObservation.sensor_id 不得为空');
        }

        if ($observedAt <= 0) {
            throw new InvalidArgumentException('StopObservation.observed_at 非法');
        }

        if (! in_array($quality, self::QUALITY_LEVELS, true)) {
            throw new InvalidArgumentException('StopObservation.quality 非法');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $rawEvidenceSha256)) {
            throw new InvalidArgumentException('StopObservation.raw_evidence_sha256 必须是 64 位十六进制');
        }

        if (trim($collectorVersion) === '') {
            throw new InvalidArgumentException('StopObservation.collector_version 不得为空');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            executionId: (string) ($data['execution_id'] ?? ''),
            sourceType: (string) ($data['source_type'] ?? ''),
            sensorId: (string) ($data['sensor_id'] ?? ''),
            observedAt: (int) ($data['observed_at'] ?? 0),
            actualStarted: (bool) ($data['actual_started'] ?? false),
            actualStopped: (bool) ($data['actual_stopped'] ?? false),
            velocity: isset($data['velocity']) && is_numeric($data['velocity']) ? (float) $data['velocity'] : null,
            current: isset($data['current']) && is_numeric($data['current']) ? (float) $data['current'] : null,
            contact: array_key_exists('contact', $data) && $data['contact'] !== null ? (bool) $data['contact'] : null,
            brake: array_key_exists('brake', $data) && $data['brake'] !== null ? (bool) $data['brake'] : null,
            power: array_key_exists('power', $data) && $data['power'] !== null ? (bool) $data['power'] : null,
            quality: (string) ($data['quality'] ?? ''),
            rawEvidenceSha256: (string) ($data['raw_evidence_sha256'] ?? ''),
            collectorVersion: (string) ($data['collector_version'] ?? ''),
        );
    }

    /**
     * 质量合格 + 物理量满足“已停”的保守判据。
     *
     * 阈值可在 config/stop_observation.php 调整；这是平台侧保守默认，
     * 不是任何厂商的真机限值。
     */
    public function isAcceptable(): bool
    {
        if ($this->quality === 'bad') {
            return false;
        }

        if (! $this->actualStopped) {
            return true;
        }

        $config = (array) config('stop_observation', []);

        $velocityEpsilon = (float) ($config['velocity_epsilon'] ?? 0.05);
        $currentEpsilon = (float) ($config['current_epsilon'] ?? 0.5);
        $requireBrake = (bool) ($config['require_brake'] ?? true);

        if ($this->velocity === null || abs($this->velocity) > $velocityEpsilon) {
            return false;
        }

        if ($this->current === null || abs($this->current) > $currentEpsilon) {
            return false;
        }

        if ($requireBrake && $this->brake !== true) {
            return false;
        }

        return true;
    }

    /** 相对 given 时间不得过期，也不得明显来自未来。 */
    public function isFresh(int $now): bool
    {
        $config = (array) config('stop_observation', []);
        $maxAge = (int) ($config['max_age_seconds'] ?? 30);
        $maxFutureSkew = (int) ($config['max_future_skew_seconds'] ?? 5);

        $age = $now - $this->observedAt;

        return $age <= $maxAge && $age >= -$maxFutureSkew;
    }

    public function toArray(): array
    {
        return [
            'execution_id'        => $this->executionId,
            'source_type'         => $this->sourceType,
            'sensor_id'           => $this->sensorId,
            'observed_at'         => $this->observedAt,
            'actual_started'      => $this->actualStarted,
            'actual_stopped'      => $this->actualStopped,
            'velocity'            => $this->velocity,
            'current'             => $this->current,
            'contact'             => $this->contact,
            'brake'               => $this->brake,
            'power'               => $this->power,
            'quality'             => $this->quality,
            'raw_evidence_sha256' => $this->rawEvidenceSha256,
            'collector_version'   => $this->collectorVersion,
        ];
    }
}
