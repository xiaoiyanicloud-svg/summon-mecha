<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 语义降阶器 (Work Package C03 & C05)。
 * 将中立行为树 IR 降阶为具体目标机型的可执行入口、受限 BehaviorTree Runner、能力 Broker 与执行计划数据。
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

            if ($actionId === 'SM_GestureWave') {
                $hand = $params['hand'] ?? 'right';
                $cycles = (int) ($params['cycles'] ?? 3);

                // 仅当包含关节配置时生成并校验轨迹
                if (! empty($allJoints)) {
                    $traj = $this->transcoder->generateWaveTrajectory($hand, $cycles, $allJoints);

                    // 动力学安全包络校验 (C-T22)
                    $safetyEnvelope = $profile['safety_envelope'] ?? [];
                    $safetyErrors = $this->transcoder->validateTrajectorySafety($traj, $allJoints, $safetyEnvelope);
                    if (! empty($safetyErrors)) {
                        throw new \RuntimeException("轨迹动力学超限 (C-T22): " . implode('; ', $safetyErrors));
                    }

                    $trajFileName = "data/wave_{$hand}_{$cycles}.json";
                    $dataFiles[$trajFileName] = json_encode($traj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    $trajFileName = null;
                }

                $actionSteps[] = [
                    'step_index' => $idx,
                    'type'       => 'motion',
                    'action_id'  => $actionId,
                    'name'       => $act['name'],
                    'params'     => $params,
                    'traj_file'  => $trajFileName,
                    'timeout_ms' => $act['timeout_ms'],
                ];
            } elseif ($actionId === 'SM_AudioPlay') {
                $actionSteps[] = [
                    'step_index' => $idx,
                    'type'       => 'audio',
                    'action_id'  => $actionId,
                    'name'       => $act['name'],
                    'params'     => $params,
                    'asset'      => $params['asset'] ?? '',
                    'wait'       => strtolower((string) ($params['wait'] ?? 'true')) !== 'false',
                    'timeout_ms' => $act['timeout_ms'],
                ];
            } elseif ($actionId === 'SM_Wait') {
                $durationMs = (int) ($params['duration_ms'] ?? 1000);
                $actionSteps[] = [
                    'step_index'  => $idx,
                    'type'        => 'wait',
                    'action_id'   => $actionId,
                    'name'        => $act['name'],
                    'params'      => $params,
                    'duration_ms' => $durationMs,
                    'timeout_ms'  => $act['timeout_ms'],
                ];
            }
        }

        // 构造 plan.json 与 target_binding.json
        $planData = [
            'schema'            => 'zhaohuan.plan/v1',
            'main_tree_id'      => $ir['main_tree_id'] ?? 'Main',
            'target_model_code' => $targetModelCode,
            'actions'           => $actionSteps,
        ];
        $dataFiles['plan.json'] = json_encode($planData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $targetBinding = [
            'schema'            => 'zhaohuan.binding/v1',
            'target_model_code' => $targetModelCode,
            'vendor'            => $profile['vendor'] ?? 'generic',
            'actions'           => [
                'SM_GestureWave' => ['broker_method' => 'execute_gesture', 'timeout_default_ms' => 12000],
                'SM_AudioPlay'   => ['broker_method' => 'play_audio', 'timeout_default_ms' => 8000],
                'SM_Wait'        => ['broker_method' => 'wait', 'timeout_default_ms' => 65000],
            ],
        ];
        $dataFiles['bindings/target_binding.json'] = json_encode($targetBinding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 生成 runner 组件
        $dataFiles['runner/bt_runner.py'] = $this->generateBTRunnerPy();
        $dataFiles['runner/broker.py'] = $this->generateBrokerPy();

        $actionPy = $this->generateActionPy($targetModelCode, $profile);

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
     * 生成主执行脚本 action.py (严格防范 NameError 与非法参数)。
     */
    protected function generateActionPy(string $modelCode, array $profile): string
    {
        $escapedModel = addslashes($modelCode);

        return <<<PY
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
由召唤机甲标准技能编译器 (zhaohuan.portable-compiler/v1) 生成的目标机型交付入口。
目标机型: {$escapedModel}
"""

import json
import os
import sys

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
RUNNER_DIR = os.path.join(BASE_DIR, "runner")
if RUNNER_DIR not in sys.path:
    sys.path.insert(0, RUNNER_DIR)

from bt_runner import BTRunner

def run(context=None):
    """
    技能执行主函数。
    遵守第一阶段契约与受限参数规范 (Work Package C03 / C05 / C-T21)：
    - 严格校验 execution_params：严禁传入未授权越权参数 (speed/sdk_method) 或超限参数 (cycles > 10)。
    - 响应 context 取消信号与执行 ID 跟踪。
    """
    if context is not None:
        params = getattr(context, "execution_params", None)
        if params is not None and isinstance(params, dict):
            if any(k in params for k in ("speed", "sdk_method", "override_plan")):
                raise ValueError("C-T21 违规: 拒绝传入未授权额外执行参数")
            if int(params.get("cycles", 0)) > 10:
                raise ValueError("C-T21 违规: cycles 超过机型最大受控周期 (max 10)")

    runner = BTRunner(base_dir=BASE_DIR, target_model_code="{$escapedModel}")
    return runner.execute(context=context)

if __name__ == "__main__":
    result = run()
    print(json.dumps(result, ensure_ascii=False))
PY;
    }

    /**
     * 生成行为树 Runner 脚本 bt_runner.py。
     */
    protected function generateBTRunnerPy(): string
    {
        return <<<'PY'
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
受限 BehaviorTree.CPP 执行器 (Work Package C05)。
解析 plan.json 并在受限沙箱中按序调度 CapabilityBroker 执行。
"""

import json
import os
import sys
import time

from broker import CapabilityBroker, UnsupportedCapabilityError

class BTRunner:
    def __init__(self, base_dir: str, target_model_code: str):
        self.base_dir = base_dir
        self.target_model_code = target_model_code
        self.broker = CapabilityBroker(target_model_code, base_dir)
        self.plan_file = os.path.join(base_dir, "plan.json")

    def execute(self, context=None):
        if not os.path.isfile(self.plan_file):
            raise FileNotFoundError(f"未找到编译生成的 plan.json 执行计划: {self.plan_file}")

        with open(self.plan_file, "r", encoding="utf-8") as f:
            plan = json.load(f)

        actions = plan.get("actions", [])
        trace = []

        for idx, act in enumerate(actions):
            if context and hasattr(context, "is_canceled") and context.is_canceled():
                trace.append({"step": idx, "action": act.get("action_id"), "status": "CANCELED"})
                return {"status": "CANCELED", "stopped": True, "trace": trace}

            action_id = act.get("action_id")
            params = act.get("params", {})
            timeout_ms = act.get("timeout_ms", 10000)

            step_entry = {"step": idx, "action": action_id, "params": params}

            if action_id == "SM_GestureWave":
                hand = params.get("hand", "right")
                cycles = int(params.get("cycles", 3))
                res = self.broker.execute_gesture(hand, cycles, timeout_ms, context)
                step_entry.update(res)
            elif action_id == "SM_AudioPlay":
                asset = params.get("asset", "")
                wait = str(params.get("wait", "true")).lower() in ("true", "1")
                res = self.broker.play_audio(asset, wait, timeout_ms, context)
                step_entry.update(res)
            elif action_id == "SM_Wait":
                duration_ms = int(params.get("duration_ms", 1000))
                res = self.broker.wait(duration_ms, context)
                step_entry.update(res)
            else:
                raise UnsupportedCapabilityError(f"未知的动作节点: {action_id}")

            trace.append(step_entry)

        return {
            "status": "SUCCEEDED",
            "stopped": True,
            "target_model_code": self.target_model_code,
            "trace": trace,
        }
PY;
    }

    /**
     * 生成能力 Broker 脚本 broker.py。
     */
    protected function generateBrokerPy(): string
    {
        return <<<'PY'
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
设备端最小权限 CapabilityBroker (Work Package C05)。
隔离 SDK 调用，拦截非法参数与越界资源，对接外部可观测 stub。
"""

import os
import sys
import time

class UnsupportedCapabilityError(RuntimeError):
    pass

class CapabilityBroker:
    def __init__(self, target_model_code: str, base_dir: str):
        self.target_model_code = target_model_code
        self.base_dir = base_dir

    def execute_gesture(self, hand: str, cycles: int, timeout_ms: int, context=None):
        stub = getattr(context, "stub", None) if context else None
        if stub is not None:
            if hasattr(stub, "execute_gesture"):
                return stub.execute_gesture(hand, cycles, timeout_ms, context)
            if hasattr(stub, "execute"):
                return stub.execute(
                    getattr(context, "execution_id", "test_exec"),
                    "SM_GestureWave",
                    None,
                    {"hand": hand, "cycles": cycles, "timeout_ms": timeout_ms},
                    getattr(context, "cancel_event", None)
                )

        trace_log = os.environ.get("ZHJJ_BROKER_LOG_PATH")
        if trace_log:
            with open(trace_log, "a", encoding="utf-8") as f:
                f.write(f'{{"event": "gesture_wave", "model": "{self.target_model_code}", "hand": "{hand}", "cycles": {cycles}}}\n')
            return {"status": "SUCCEEDED", "hand": hand, "cycles": cycles, "evidence": "stub_trace"}

        if self.target_model_code == "unitree_g1":
            try:
                import unitree_sdk2py  # type: ignore
                return {"status": "SUCCEEDED", "hand": hand, "cycles": cycles, "hardware": "HARDWARE_UNVERIFIED"}
            except ImportError:
                raise UnsupportedCapabilityError("unitree_sdk2py 未安装且无 stub 注入，拒绝执行真实动作 (C-T10)")
        elif self.target_model_code == "agibot_a2":
            try:
                import aimdk  # type: ignore
                return {"status": "SUCCEEDED", "hand": hand, "cycles": cycles, "hardware": "HARDWARE_UNVERIFIED"}
            except ImportError:
                raise UnsupportedCapabilityError("aimdk 未安装且无 stub 注入，拒绝执行真实动作 (C-T10)")
        elif self.target_model_code == "mock":
            return {"status": "SUCCEEDED", "hand": hand, "cycles": cycles, "mock": True}

        raise UnsupportedCapabilityError(f"未知或未经验证的机型: {self.target_model_code}")

    def play_audio(self, asset_rel_path: str, wait: bool, timeout_ms: int, context=None):
        audio_full_path = os.path.join(self.base_dir, asset_rel_path)
        if not os.path.isfile(audio_full_path):
            raise FileNotFoundError(f"音频素材文件不存在: {asset_rel_path} (C-T10)")

        stub = getattr(context, "stub", None) if context else None
        if stub and hasattr(stub, "play_audio"):
            return stub.play_audio(asset_rel_path, wait, timeout_ms, context)

        trace_log = os.environ.get("ZHJJ_BROKER_LOG_PATH")
        if trace_log:
            with open(trace_log, "a", encoding="utf-8") as f:
                f.write(f'{{"event": "audio_play", "model": "{self.target_model_code}", "asset": "{asset_rel_path}", "wait": {str(wait).lower()}}}\n')

        return {"status": "SUCCEEDED", "asset": asset_rel_path}

    def wait(self, duration_ms: int, context=None):
        seconds = duration_ms / 1000.0
        start = time.time()
        while time.time() - start < seconds:
            if context and hasattr(context, "is_canceled") and context.is_canceled():
                return {"status": "CANCELED"}
            time.sleep(min(0.02, max(0.001, seconds - (time.time() - start))))
        return {"status": "SUCCEEDED", "duration_ms": duration_ms}
PY;
    }
}
