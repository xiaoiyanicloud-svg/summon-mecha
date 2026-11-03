#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MuJoCo 无头高保真物理在环仿真 (SIL Telemetry) (Task 3.2)
使用 DeepMind MuJoCo 物理引擎，加载 Unitree G1 23-DoF 原生 MJCF 模型，
以 1000Hz (dt = 0.001s) 步长在重力与地面接触力学下执行挥手轨迹。
采集并客观断言：
1. 机器人基座倾覆角 (Pitch/Roll) < 0.15 rad（无倾覆、质心平衡）
2. 机器人站立高度 base_z > 0.60 m（未摔倒、接触力平衡）
3. 关节伺服跟踪均方根误差 (RMSE) < 0.02 rad
4. 峰值执行器输出力矩 < 30 N*m
输出时序遥测 CSV 与机器可读报告。
"""

import csv
import json
import math
import os
import sys

try:
    import mujoco
    import numpy as np
except ImportError as e:
    print(f"[ERR] 依赖库缺失: {e}")
    sys.exit(1)


def run_sil_simulation():
    base_dir = os.path.dirname(os.path.abspath(__file__))
    workspace_root = os.path.abspath(os.path.join(base_dir, "..", ".."))

    mjcf_path = os.path.join(workspace_root, "resources", "robot-models", "unitree_g1", "g1_23dof.xml")
    if not os.path.isfile(mjcf_path):
        print(f"[ERR] 未找到 MuJoCo 模型: {mjcf_path}")
        sys.exit(1)

    model = mujoco.MjModel.from_xml_path(mjcf_path)
    data = mujoco.MjData(model)

    # 轨迹生成参数 (30Hz 挥手轨迹, 周期 2.0s, 3 个周期 = 6.0s)
    sim_time = 6.0
    dt = model.opt.timestep # 0.001s (1000Hz)
    total_steps = int(sim_time / dt)

    act_r_sr_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_ACTUATOR, "act_r_sr")
    act_r_el_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_ACTUATOR, "act_r_el")
    joint_r_sr_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_JOINT, "right_shoulder_roll_joint")
    joint_r_el_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_JOINT, "right_elbow_joint")
    qpos_r_sr_idx = model.jnt_qposadr[joint_r_sr_id]
    qpos_r_el_idx = model.jnt_qposadr[joint_r_el_id]

    # 初始化关节至动作基准姿态 (Rest Pose)，消除瞬态跳变
    data.qpos[qpos_r_sr_idx] = -0.2
    data.qpos[qpos_r_el_idx] = 0.85
    data.ctrl[act_r_sr_id] = -0.2
    data.ctrl[act_r_el_id] = 0.85
    mujoco.mj_forward(model, data)

    csv_path = os.path.join(base_dir, "mujoco_telemetry.csv")
    report_path = os.path.join(base_dir, "sil_simulation_report.json")

    telemetry_records = []
    tracking_errors = []
    max_torques = []
    base_tilts = []

    period = 2.0
    amp = 0.28

    # 物理步进仿真循环
    with open(csv_path, "w", newline="", encoding="utf-8") as f_csv:
        writer = csv.writer(f_csv)
        writer.writerow([
            "time_s", "base_z_m", "base_pitch_rad", "base_roll_rad",
            "target_q_rad", "actual_q_rad", "tracking_error_rad", "actuator_force_nm"
        ])

        for step in range(total_steps):
            t = step * dt

            # 目标余弦插值控制
            progress = (t % period) / period
            wave = (amp / 2.0) * (1.0 - math.cos(2.0 * math.pi * progress))
            target_r_sr = -0.2 - wave
            target_r_el = 0.85 + (wave * 0.4)

            # 设定执行器控制输入
            data.ctrl[act_r_sr_id] = target_r_sr
            data.ctrl[act_r_el_id] = target_r_el

            # 执行 1 毫秒高保真牛顿动力学步进
            mujoco.mj_step(model, data)

            actual_q = data.qpos[qpos_r_sr_idx]
            err = abs(target_r_sr - actual_q)
            tracking_errors.append(err)

            # 获取基座四元数并转化为欧拉角 (Pitch, Roll)
            quat = data.qpos[3:7] # freejoint quaternion
            # quat to euler pitch/roll
            w, x, y, z = quat[0], quat[1], quat[2], quat[3]
            roll = math.atan2(2 * (w * x + y * z), 1 - 2 * (x * x + y * y))
            pitch = math.asin(max(-1.0, min(1.0, 2 * (w * y - z * x))))
            tilt = math.sqrt(roll * roll + pitch * pitch)
            base_tilts.append(tilt)

            base_z = data.qpos[2]
            act_force = abs(data.actuator_force[act_r_sr_id])
            max_torques.append(act_force)

            # 每 10ms 记录一行遥测 (100Hz 采样保存)
            if step % 10 == 0:
                writer.writerow([
                    f"{t:.3f}", f"{base_z:.4f}", f"{pitch:.4f}", f"{roll:.4f}",
                    f"{target_r_sr:.4f}", f"{actual_q:.4f}", f"{err:.4f}", f"{act_force:.2f}"
                ])

    rmse = math.sqrt(sum(e * e for e in tracking_errors) / len(tracking_errors))
    max_tilt = max(base_tilts)
    final_base_z = data.qpos[2]
    peak_torque = max(max_torques)

    # 判定准则
    stable_standing = (max_tilt < 0.15) and (final_base_z > 0.60)
    tracking_pass = (rmse < 0.02)
    torque_pass = (peak_torque < 30.0)

    verdict = "PASS" if (stable_standing and tracking_pass and torque_pass) else "FAIL"

    report = {
        "schema": "zhaohuan.sil-telemetry/v1",
        "engine": f"DeepMind MuJoCo v{mujoco.__version__}",
        "robot_model": "Unitree G1 EDU (g1_23dof.xml)",
        "simulation_frequency_hz": 1000,
        "duration_s": sim_time,
        "verdict": verdict,
        "metrics": {
            "tracking_rmse_rad": round(rmse, 4),
            "tracking_rmse_limit_rad": 0.02,
            "max_base_tilt_rad": round(max_tilt, 4),
            "max_base_tilt_limit_rad": 0.15,
            "final_base_height_z_m": round(final_base_z, 4),
            "min_base_height_limit_m": 0.60,
            "peak_actuator_torque_nm": round(peak_torque, 2),
            "max_torque_limit_nm": 30.0,
            "no_tip_over": bool(max_tilt < 0.15),
            "stable_standing": bool(final_base_z > 0.60)
        },
        "telemetry_csv": os.path.relpath(csv_path, workspace_root)
    }

    with open(report_path, "w", encoding="utf-8") as f_rep:
        json.dump(report, f_rep, indent=2, ensure_ascii=False)

    print("==================================================")
    print(f" MuJoCo 1000Hz 物理在环仿真 (SIL) 验收结果: {verdict}")
    print("==================================================")
    print(f" 物理步数: {total_steps} (步长: {dt*1000} ms)")
    print(f" 轨迹跟踪 RMSE: {report['metrics']['tracking_rmse_rad']} rad (合格限额 < 0.02)")
    print(f" 机身最大倾角: {report['metrics']['max_base_tilt_rad']} rad (防倾覆限额 < 0.15)")
    print(f" 终止离地高度: {report['metrics']['final_base_height_z_m']} m (下限 > 0.60 m)")
    print(f" 执行器峰值力矩: {report['metrics']['peak_actuator_torque_nm']} N*m (额定限额 < 30.0)")
    print(f" 遥测 CSV 归档: {csv_path}")
    print(f" 审计报告归档: {report_path}")

    if verdict != "PASS":
        print("[FAIL] 未通过物理稳定性或跟踪精度门槛")
        sys.exit(1)

    sys.exit(0)


if __name__ == "__main__":
    run_sil_simulation()
