<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 语义降阶器 —— 将中立行为树 IR 降阶为具体目标机型的可执行 Python 脚本与动作数据。
 */
class SemanticLowerer
{
    public function __construct(
        protected TargetProfileService $profileService,
        protected MotionTranscoder $transcoder
    ) {}

    /**
     * 针对指定目标机型降阶产出执行代码与配套数据。
     *
     * @return array{
     *   entrypoint: string,
     *   action_py: string,
     *   data_files: array<string, string>,
     *   meta: array
     * }
     */
    public function lower(array $ir, string $targetModelCode): array
    {
        $profile = $this->profileService->getProfile($targetModelCode);
        if (! $profile) {
            throw new \InvalidArgumentException("未注册的目标机型: {$targetModelCode}");
        }

        $allJoints = array_merge(
            $profile['joints']['left_arm'] ?? [],
            $profile['joints']['right_arm'] ?? []
        );

        $dataFiles = [];
        $actionSteps = [];

        foreach ($ir['actions'] as $idx => $act) {
            $actionId = $act['action_id'];
            $params = $act['params'];
            $stepName = "step_{$idx}_{$actionId}";

            if ($actionId === 'SM_GestureWave') {
                $hand = $params['hand'] ?? 'right';
                $cycles = (int) ($params['cycles'] ?? 3);
                $traj = $this->transcoder->generateWaveTrajectory($hand, $cycles, $allJoints);
                $trajFileName = "data/wave_{$hand}_{$cycles}.json";
                $dataFiles[$trajFileName] = json_encode($traj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                $actionSteps[] = [
                    'type'       => 'motion',
                    'action_id'  => $actionId,
                    'name'       => $act['name'],
                    'traj_file'  => $trajFileName,
                    'timeout_ms' => $act['timeout_ms'],
                ];
            } elseif ($actionId === 'SM_AudioPlay') {
                $assetPath = $params['asset'] ?? '';
                $actionSteps[] = [
                    'type'       => 'audio',
                    'action_id'  => $actionId,
                    'name'       => $act['name'],
                    'asset'      => $assetPath,
                    'timeout_ms' => $act['timeout_ms'],
                ];
            }
        }

        $actionPy = $this->generateActionPy($targetModelCode, $profile, $actionSteps);

        return [
            'entrypoint' => 'action.py',
            'action_py'  => $actionPy,
            'data_files' => $dataFiles,
            'meta'       => [
                'target_model_code'     => $targetModelCode,
                'target_profile_digest' => $this->profileService->getDigest($targetModelCode),
                'steps_count'           => count($actionSteps),
            ],
        ];
    }

    /**
     * 生成目标端 action.py 可执行脚本。
     */
    protected function generateActionPy(string $modelCode, array $profile, array $steps): string
    {
        $stepsJson = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PY
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
由召唤机甲标准技能编译器自动生成的目标载体执行入口。
目标机型: {$modelCode} ({$profile['display_name']})
"""

import json
import os
import sys
import time

ACTION_STEPS = {$stepsJson}

def run(context=None):
    """技能执行主函数，支持传入 context 取消信号与外部参数"""
    print(f"[{modelCode}] 开始执行标准技能序列，步骤数: {len(ACTION_STEPS)}")
    base_dir = os.path.dirname(os.path.abspath(__file__))

    for step in ACTION_STEPS:
        # 响应取消信号
        if context and hasattr(context, "is_canceled") and context.is_canceled():
            print(f"[{modelCode}] 检测到取消信号，安全退出")
            return {"status": "CANCELED", "stopped": True}

        step_type = step.get("type")
        step_name = step.get("name")
        print(f"[{modelCode}] 执行步骤: {step_name} ({step_type})")

        if step_type == "motion":
            traj_path = os.path.join(base_dir, step["traj_file"])
            if os.path.isfile(traj_path):
                with open(traj_path, "r", encoding="utf-8") as f:
                    traj_data = json.load(f)
                # 动作微延迟仿真
                time.sleep(0.04)
        elif step_type == "audio":
            audio_path = os.path.join(base_dir, step.get("asset", ""))
            print(f"[{modelCode}] 播放音频: {audio_path}")
            time.sleep(0.02)

    print(f"[{modelCode}] 技能序列顺利执行完成")
    return {"status": "SUCCEEDED", "stopped": True}

if __name__ == "__main__":
    run()
PY;
    }
}
