<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use InvalidArgumentException;
use RuntimeException;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 目标机型 Profile 服务 —— 管理五机型硬件/SDK 快照与摘要。
 */
class TargetProfileService
{
    public const GA_TARGET_MODELS = [
        'unitree_g1',
        'unitree_r1',
        'agibot_x2',
        'agibot_a3',
        'engineai_t800',
    ];

    /** A2 已废止为当前 GA 目标；物理素材可保留为历史证据，但不得再注册为运行 Profile。 */
    public const DEPRECATED_MODEL_CODES = ['agibot_a2'];

    protected string $profileDir;

    public function __construct(?string $profileDir = null)
    {
        if ($profileDir && is_dir($profileDir)) {
            $this->profileDir = $profileDir;
            return;
        }

        $candidates = [
            $profileDir,
            function_exists('resource_path') ? resource_path('skill-target-profiles') : null,
            function_exists('base_path') ? base_path('resources/skill-target-profiles') : null,
            dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'skill-target-profiles',
            dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'skill-target-profiles',
            dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'skill-target-profiles',
        ];

        foreach ($candidates as $cand) {
            if ($cand && is_dir($cand)) {
                $this->profileDir = $cand;
                return;
            }
        }

        $this->profileDir = resource_path('skill-target-profiles');
    }

    public function getProfile(string $modelCode): ?array
    {
        if (in_array($modelCode, self::DEPRECATED_MODEL_CODES, true)) {
            return null;
        }
        if (! preg_match('/^[a-z0-9_]+$/', $modelCode)) {
            return null;
        }

        $file = $this->profileDir . DIRECTORY_SEPARATOR . $modelCode . '.json';
        if (! is_file($file)) {
            if ($modelCode === 'mock' || str_starts_with($modelCode, 'mock_')) {
                return $this->readProfileFile($this->profileDir . DIRECTORY_SEPARATOR . 'mock_humanoid.json', 'mock');
            }

            return null;
        }

        return $this->readProfileFile($file, $modelCode);
    }

    public function getProfileDto(string $modelCode): TargetProfile
    {
        $profile = $this->getProfile($modelCode);
        if (! $profile) {
            throw new InvalidArgumentException("未找到机型 {$modelCode} 的 TargetProfile");
        }

        return TargetProfile::fromArray($profile, $this->digestRawProfile($profile));
    }

    /**
     * 计算 Profile 的规范化 JCS SHA-256 摘要（target_profile_digest）。
     */
    public function getDigest(string $modelCode): string
    {
        $profile = $this->getProfile($modelCode);
        if (! $profile) {
            throw new InvalidArgumentException("未找到机型 {$modelCode} 的 TargetProfile");
        }

        return $this->digestRawProfile($profile);
    }

    public function hasCapability(string $modelCode, string $actionId, array $params = []): bool
    {
        $profile = $this->getProfile($modelCode);
        if (! $profile) {
            return false;
        }

        $actions = $profile['capabilities']['actions'] ?? [];
        if (! isset($actions[$actionId])) {
            return false;
        }

        $spec = $actions[$actionId];

        if (isset($params['hand']) && isset($spec['supported_hands'])) {
            if (! in_array($params['hand'], $spec['supported_hands'], true)) {
                return false;
            }
        }

        if (isset($params['cycles'])) {
            if (! is_int($params['cycles']) && ! preg_match('/^[1-9]\d*$/', (string) $params['cycles'])) {
                return false;
            }
            $cycles = (int) $params['cycles'];
            if ($cycles <= 0) {
                return false;
            }
            if (isset($spec['max_cycles']) && $cycles > (int) $spec['max_cycles']) {
                return false;
            }
        }

        if (isset($params['duration_ms'])) {
            if (! is_int($params['duration_ms']) && ! preg_match('/^[1-9]\d*$/', (string) $params['duration_ms'])) {
                return false;
            }
            $duration = (int) $params['duration_ms'];
            if ($duration <= 0 || (isset($spec['max_duration_ms']) && $duration > (int) $spec['max_duration_ms'])) {
                return false;
            }
        }

        return true;
    }

    public function listProfiles(): array
    {
        if (! is_dir($this->profileDir)) {
            return [];
        }

        $files = glob($this->profileDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $out = [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data) || ! isset($data['model_code'])) {
                continue;
            }
            if (in_array($data['model_code'], self::DEPRECATED_MODEL_CODES, true)) {
                continue;
            }
            $out[$data['model_code']] = $data;
        }

        return $out;
    }

    /** @return array<int, TargetProfile> */
    public function gaProfiles(): array
    {
        $profiles = [];
        foreach (self::GA_TARGET_MODELS as $modelCode) {
            $profiles[] = $this->getProfileDto($modelCode);
        }

        return $profiles;
    }

    protected function readProfileFile(string $file, string $expectedModelCode): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("无法读取 TargetProfile: {$file}");
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new RuntimeException("TargetProfile JSON 非法: {$file}");
        }

        if (($data['model_code'] ?? null) !== $expectedModelCode) {
            throw new RuntimeException("TargetProfile model_code 与文件名不一致: {$file}");
        }

        if (in_array($data['model_code'], self::DEPRECATED_MODEL_CODES, true)) {
            return null;
        }

        return $data;
    }

    protected function digestRawProfile(array $profile): string
    {
        $canonicalJson = app(SigningService::class)->jcsCanonicalize($profile);

        return 'sha256:' . hash('sha256', $canonicalJson);
    }
}