<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 跨机型能力仲裁与匹配解析器 —— 校验 IR 所需能力是否在目标 Profile 允许范围内。
 */
class CapabilityResolver
{
    public function __construct(protected TargetProfileService $profileService) {}

    /**
     * 仲裁 IR 在指定目标机型上的可用性。
     */
    public function resolve(array $ir, string $targetModelCode): array
    {
        $profile = $this->profileService->getProfile($targetModelCode);
        if (! $profile) {
            return [
                'compatible' => false,
                'reasons'    => ["目标机型 {$targetModelCode} 缺少已注册的 TargetProfile"],
            ];
        }

        $reasons = [];
        $resolvedActions = [];

        foreach ($ir['actions'] as $action) {
            $actionId = $action['action_id'];
            $params = $action['params'];

            if (! $this->profileService->hasCapability($targetModelCode, $actionId, $params)) {
                $reasons[] = "UNSUPPORTED_CAPABILITY: 机型 {$targetModelCode} 不支持动作 {$actionId} 或参数超出范围: " . json_encode($params, JSON_UNESCAPED_UNICODE);
            } else {
                $resolvedActions[] = [
                    'action_id' => $actionId,
                    'name'      => $action['name'],
                    'params'    => $params,
                ];
            }
        }

        // 校验权限超限
        $profileActions = $profile['capabilities']['actions'] ?? [];
        $targetAllowedPerms = [];
        foreach ($profileActions as $act) {
            foreach ($act['required_permissions'] ?? [] as $perm) {
                $targetAllowedPerms[$perm] = true;
            }
        }

        foreach ($ir['required_permissions'] as $reqPerm) {
            if (! isset($targetAllowedPerms[$reqPerm])) {
                $reasons[] = "目标机型 {$targetModelCode} 未开放所需权限: {$reqPerm}";
            }
        }

        return [
            'compatible'          => empty($reasons),
            'reasons'             => $reasons,
            'resolved_actions'    => $resolvedActions,
            'target_model_code'   => $targetModelCode,
            'target_profile_digest' => $this->profileService->getDigest($targetModelCode),
        ];
    }
}
