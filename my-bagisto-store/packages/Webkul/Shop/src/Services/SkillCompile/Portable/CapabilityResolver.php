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
                continue;
            }

            $physicalFailure = $this->physicalLimitsFailure($profile, $actionId, $params);
            if ($physicalFailure !== null) {
                $reasons[] = $physicalFailure;
                continue;
            }

            $resolvedActions[] = [
                'action_id' => $actionId,
                'name'      => $action['name'],
                'params'    => $params,
            ];
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

    /**
     * 运动能力只有在目标侧具备可审计的臂部关节/速度/力矩/温度边界时才允许构建。
     * 缺失值不得回退到 G1、行业通用值或“运行期再问 SDK”。
     */
    protected function physicalLimitsFailure(array $profile, string $actionId, array $params): ?string
    {
        $spec = $profile['capabilities']['actions'][$actionId] ?? [];
        $permissions = $spec['required_permissions'] ?? [];
        if (! in_array('motion_control', $permissions, true)) {
            return null;
        }

        $model = (string) ($profile['model_code'] ?? 'unknown');
        // Mock 仅允许在隔离测试中生成模拟产物；生产环境没有物理限值时必须 fail-closed。
        if ($model === 'mock' && app()->environment('testing')) {
            return null;
        }
        $jointLimits = $profile['joint_limits'] ?? [];
        if (($jointLimits['status'] ?? 'UNRESOLVED') === 'UNRESOLVED') {
            return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 的关节限值未获厂商原始依据";
        }

        $hand = $params['hand'] ?? null;
        $arms = match ($hand) {
            'left' => ['left_arm'],
            'right' => ['right_arm'],
            'both' => ['left_arm', 'right_arm'],
            default => ['left_arm', 'right_arm'],
        };

        foreach ($arms as $arm) {
            $limits = $jointLimits[$arm] ?? [];
            if (! is_array($limits) || $limits === []) {
                return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 缺少 {$arm} 关节限值";
            }
            foreach ($limits as $joint => $limit) {
                if (! is_array($limit)
                    || ! isset($limit['min_rad'], $limit['max_rad'])
                    || ! is_numeric($limit['min_rad'])
                    || ! is_numeric($limit['max_rad'])
                    || (float) $limit['min_rad'] >= (float) $limit['max_rad']) {
                    return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 的关节 {$joint} 限值非法";
                }
            }
        }

        $velocity = $profile['velocity_limits'] ?? [];
        $torque = $profile['torque_limits'] ?? [];
        $temperature = $profile['temperature_limits'] ?? [];
        $envelope = $profile['safety_envelope'] ?? [];

        if (! isset($velocity['max_rad_s']) || ! is_numeric($velocity['max_rad_s']) || (float) $velocity['max_rad_s'] <= 0) {
            return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 缺少速度上限";
        }
        if (! isset($torque['max_nm']) || ! is_numeric($torque['max_nm']) || (float) $torque['max_nm'] <= 0) {
            return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 缺少力矩上限";
        }
        if (! isset($temperature['max_c']) || ! is_numeric($temperature['max_c']) || (float) $temperature['max_c'] <= 0) {
            return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 缺少温度上限";
        }
        foreach (['max_jerk_rad_s3', 'min_collision_clearance_mm'] as $field) {
            if (! isset($envelope[$field]) || ! is_numeric($envelope[$field]) || (float) $envelope[$field] <= 0) {
                return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 缺少 {$field}";
            }
        }
        if (isset($envelope['status']) && str_contains((string) $envelope['status'], 'UNRESOLVED')) {
            return "PHYSICAL_LIMITS_UNRESOLVED: 机型 {$model} 的安全包络未关闭";
        }

        return null;
    }
}
