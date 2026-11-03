<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 目标机型 Profile 服务 —— 管理各机型硬件/SDK环境快照与指纹计算。
 */
class TargetProfileService
{
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

    /**
     * 获取指定机型的 Profile 定义。
     */
    public function getProfile(string $modelCode): ?array
    {
        $file = $this->profileDir . DIRECTORY_SEPARATOR . $modelCode . '.json';
        if (! file_exists($file)) {
            // 尝试 mock 前缀匹配
            if ($modelCode === 'mock' || str_starts_with($modelCode, 'mock_')) {
                $mockFile = $this->profileDir . DIRECTORY_SEPARATOR . 'mock_humanoid.json';
                if (file_exists($mockFile)) {
                    $content = file_get_contents($mockFile);
                    return json_decode($content, true);
                }
            }
            return null;
        }

        $content = file_get_contents($file);
        return json_decode($content, true);
    }

    /**
     * 计算 Profile 的规范化 JCS SHA-256 摘要（target_profile_digest）。
     */
    public function getDigest(string $modelCode): string
    {
        $profile = $this->getProfile($modelCode);
        if (! $profile) {
            throw new \InvalidArgumentException("未找到机型 {$modelCode} 的 TargetProfile");
        }

        $signingService = app(SigningService::class);
        $canonicalJson = $signingService->jcsCanonicalize($profile);
        return 'sha256:' . hash('sha256', $canonicalJson);
    }

    /**
     * 校验指定机型是否支持某项能力或动作。
     */
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

        // 校验手部动作
        if (isset($params['hand']) && isset($spec['supported_hands'])) {
            if (! in_array($params['hand'], $spec['supported_hands'], true)) {
                return false;
            }
        }

        // 校验周期数限制与合法性 (cycles 必须为正整数且不超过上限)
        if (isset($params['cycles'])) {
            $cycles = (int) $params['cycles'];
            if ($cycles <= 0) {
                return false;
            }
            if (isset($spec['max_cycles']) && $cycles > (int) $spec['max_cycles']) {
                return false;
            }
        }

        return true;
    }

    /**
     * 列出所有已注册的 Target Profile。
     */
    public function listProfiles(): array
    {
        if (! is_dir($this->profileDir)) {
            return [];
        }

        $files = glob($this->profileDir . DIRECTORY_SEPARATOR . '*.json');
        $out = [];
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data && isset($data['model_code'])) {
                $out[$data['model_code']] = $data;
            }
        }
        return $out;
    }
}
