#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
由召唤机甲标准技能编译器自动生成的目标载体执行入口。
目标机型: unitree_g1 (Unitree G1 EDU (23/29 DOF))
"""

import json
import os
import sys
import time

ACTION_STEPS = [
    {
        "type": "motion",
        "action_id": "SM_GestureWave",
        "name": "main",
        "traj_file": "data/wave_right_3.json",
        "timeout_ms": 12000
    },
    {
        "type": "motion",
        "action_id": "SM_GestureWave",
        "name": "must_not_execute",
        "traj_file": "data/wave_left_2.json",
        "timeout_ms": 12000
    }
]

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