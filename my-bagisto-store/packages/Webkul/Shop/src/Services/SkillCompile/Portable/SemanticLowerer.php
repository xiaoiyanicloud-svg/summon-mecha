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
            'schema'                => 'zhaohuan.binding/v2',
            'target_model_code'     => $targetModelCode,
            'vendor'                => $profile['vendor'] ?? 'generic',
            'sdk_name'              => $profile['sdk_name'] ?? ($profile['sdk']['name'] ?? ''),
            'sdk_version_range'     => $profile['sdk_version_range'] ?? ($profile['sdk']['version_range'] ?? ''),
            'firmware_range'        => $profile['firmware_range'] ?? 'vendor-confirmation-required',
            'artifact_abi'          => $profile['artifact_abi'] ?? 'zhaohuan.skill-target/v1',
            'target_profile_digest' => $this->profileService->getDigest($targetModelCode),
            'hardware_verification_status' => $profile['hardware_verification_status'] ?? 'HARDWARE_UNVERIFIED',
            'actions'               => [
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
        $maxCycles = (int) ($profile['capabilities']['actions']['SM_GestureWave']['max_cycles'] ?? 0);
        if ($maxCycles <= 0) {
            $maxCycles = 1;
        }

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
            if int(params.get("cycles", 0)) > {$maxCycles}:
                raise ValueError("C-T21 违规: cycles 超过机型最大受控周期 (max {$maxCycles})")

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
                if res.get("status") in ("FAILED", "CANCELED"):
                    return {"status": res["status"], "stopped": True, "trace": trace + [step_entry]}
            elif action_id == "SM_AudioPlay":
                asset = params.get("asset", "")
                wait = str(params.get("wait", "true")).lower() in ("true", "1")
                res = self.broker.play_audio(asset, wait, timeout_ms, context)
                step_entry.update(res)
                if res.get("status") in ("FAILED", "CANCELED"):
                    return {"status": res["status"], "stopped": True, "trace": trace + [step_entry]}
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
     * 生成能力 Broker 脚本 broker.py（Profile 驱动、默认 fail-closed）。
     */
    protected function generateBrokerPy(): string
    {
        return <<<'PY'
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""目标 Profile 驱动的受限 CapabilityBroker。

安全边界：
- 不根据“SDK 可 import”推断动作成功。
- 无显式测试桩或真实 Adapter 桥时拒绝执行。
- 取消只返回 CANCEL_REQUESTED；物理停止必须由 Adapter.observe_execution 独立确认。
"""

import importlib
import json
import os
import time


class UnsupportedCapabilityError(RuntimeError):
    """明确的能力缺失或安全拒绝。"""


class CapabilityBroker:
    def __init__(self, target_model_code: str, base_dir: str):
        self.target_model_code = target_model_code
        self.base_dir = os.path.abspath(base_dir)
        binding_path = os.path.join(self.base_dir, "bindings", "target_binding.json")
        with open(binding_path, "r", encoding="utf-8") as f:
            self.binding = json.load(f)

        if self.binding.get("target_model_code") != target_model_code:
            raise UnsupportedCapabilityError("编译产物目标机型与 Agent Adapter 不一致")
        if not str(self.binding.get("target_profile_digest", "")).startswith("sha256:"):
            raise UnsupportedCapabilityError("编译产物缺少有效 TargetProfile 摘要")

    def _bridge(self, context):
        stub = getattr(context, "stub", None) if context else None
        if stub is not None:
            return stub
        bridge = getattr(context, "adapter", None) if context else None
        if bridge is not None:
            return bridge
        return None

    def _fail_without_bridge(self):
        sdk_name = self.binding.get("sdk_name") or "unknown-vendor-sdk"
        try:
            importlib.import_module(sdk_name)
        except ImportError as exc:
            raise UnsupportedCapabilityError(
                f"{sdk_name} 未安装；没有显式 Adapter 桥，拒绝执行"
            ) from exc
        raise UnsupportedCapabilityError(
            f"{sdk_name} 可导入，但没有经验证的 Adapter 桥；禁止把 import 成功当作动作成功"
        )

    def execute_gesture(self, hand: str, cycles: int, timeout_ms: int, context=None):
        bridge = self._bridge(context)
        if bridge is None:
            self._fail_without_bridge()
        if hasattr(bridge, "execute_gesture"):
            return bridge.execute_gesture(hand, cycles, timeout_ms, context)
        if hasattr(bridge, "execute_action"):
            return bridge.execute_action(
                "SM_GestureWave",
                {"hand": hand, "cycles": cycles, "timeout_ms": timeout_ms},
                getattr(context, "cancel_event", None),
            )
        if hasattr(bridge, "execute"):
            return bridge.execute(
                getattr(context, "execution_id", "unknown"),
                "SM_GestureWave",
                None,
                {"hand": hand, "cycles": cycles, "timeout_ms": timeout_ms},
                getattr(context, "cancel_event", None),
            )
        raise UnsupportedCapabilityError("Adapter 桥未实现受控手势执行")

    def play_audio(self, asset_rel_path: str, wait: bool, timeout_ms: int, context=None):
        if not asset_rel_path or os.path.isabs(asset_rel_path):
            raise UnsupportedCapabilityError("音频素材必须是包内相对路径")
        base_real = os.path.realpath(self.base_dir)
        audio_full_path = os.path.realpath(os.path.join(base_real, asset_rel_path))
        if not audio_full_path.startswith(base_real + os.sep):
            raise UnsupportedCapabilityError("音频素材路径越界")
        if not os.path.isfile(audio_full_path):
            raise FileNotFoundError(f"音频素材文件不存在: {asset_rel_path}")

        bridge = self._bridge(context)
        if bridge is None:
            self._fail_without_bridge()
        if hasattr(bridge, "play_audio"):
            return bridge.play_audio(asset_rel_path, wait, timeout_ms, context)
        if hasattr(bridge, "execute_action"):
            return bridge.execute_action(
                "SM_AudioPlay",
                {"asset": asset_rel_path, "wait": wait, "timeout_ms": timeout_ms},
                getattr(context, "cancel_event", None),
            )
        raise UnsupportedCapabilityError("Adapter 桥未实现受控音频播放")

    def wait(self, duration_ms: int, context=None):
        seconds = duration_ms / 1000.0
        start = time.monotonic()
        while time.monotonic() - start < seconds:
            if context and hasattr(context, "is_canceled") and context.is_canceled():
                return {"status": "CANCELED", "cancel_requested": True, "stopped": False}
            remaining = seconds - (time.monotonic() - start)
            time.sleep(min(0.02, max(0.001, remaining)))
        return {"status": "SUCCEEDED", "duration_ms": duration_ms, "stopped": True}
PY;
    }
}