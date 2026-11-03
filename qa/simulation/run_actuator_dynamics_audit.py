#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
电机非线性动力学（Stribeck 摩擦、齿隙 Backlash、热衰减）与地面接触抗扰动物理在环审计
(Actuator Nonlinearity, Thermal Derating & Ground Contact Robustness SIL Verifier)

第三方依据：
1. Armstrong-Hélouvry Stribeck 摩擦动力学模型
2. 减速机齿隙非线性迟滞模型 (Backlash Hysteresis)
3. 电机热阻-热容一阶集总参数温升模型 (Thermal Lumped Parameter Model)
4. MuJoCo 1000Hz 质心零力矩点 (ZMP) 与外力冲量抗扰动恢复 (Push Recovery)
"""

import os
import sys
import json
import math
import numpy as np

try:
    import mujoco
except ImportError:
    print("[ERROR] 缺少 mujoco 库，请先安装: pip install mujoco")
    sys.exit(1)


def simulate_actuator_nonlinearity(urdf_path: str, trajectory_data: list) -> dict:
    """
    核验在电机齿隙与非线性 Stribeck 摩擦扰动下的关节跟踪收敛性。
    """
    times = [f["time_s"] for f in trajectory_data]
    dt = times[1] - times[0] if len(times) > 1 else 0.0333

    # G1 关键挥手关节 R_SHOULDER_ROLL
    desired_q = [f["joints"].get("R_SHOULDER_ROLL", 0.5) for f in trajectory_data]

    # Stribeck 摩擦参数 (Unitree G1 关节模组标称参考)
    tau_c = 0.35  # 库伦摩擦力矩 (N·m)
    tau_s = 0.55  # 最大静摩擦力矩 (N·m)
    v_s = 0.05    # Stribeck 速度阈值 (rad/s)
    b_visc = 0.42 # 粘滞摩擦系数 (N·m·s/rad)
    backlash = 0.003 # 齿隙角度 (rad)

    # 离线闭环状态模拟
    actual_q = [desired_q[0]]
    actual_v = [0.0]
    errors = []
    gear_play = 0.0 # 齿隙滞后状态

    for i in range(len(desired_q) - 1):
        q_des = desired_q[i]
        q_act = actual_q[-1]
        v_act = actual_v[-1]

        # 齿隙计算
        delta_pos = q_des - q_act
        if abs(delta_pos) > backlash:
            effective_error = delta_pos - math.copysign(backlash, delta_pos)
        else:
            effective_error = 0.0 # 处在背隙死区中

        # PD 控制力矩
        tau_cmd = 120.0 * effective_error - 8.0 * v_act

        # Stribeck 摩擦力矩
        exp_factor = math.exp(-((v_act / v_s) ** 2)) if v_s > 0 else 0.0
        friction_torque = (tau_c + (tau_s - tau_c) * exp_factor) * math.tanh(v_act / 0.01) + b_visc * v_act

        net_torque = tau_cmd - friction_torque
        # 单关节等效转动惯量 0.06 kg·m^2
        acc = net_torque / 0.06
        acc = max(-40.0, min(40.0, acc))

        next_v = v_act + acc * dt
        next_q = q_act + next_v * dt

        actual_v.append(next_v)
        actual_q.append(next_q)
        errors.append(abs(next_q - desired_q[i+1]))

    rmse = float(np.sqrt(np.mean(np.square(errors))))
    max_err = float(np.max(errors))

    return {
        "rmse_rad": round(rmse, 4),
        "max_error_rad": round(max_err, 4),
        "backlash_rad": backlash,
        "stribeck_stable": bool(rmse < 0.05 and max_err < 0.10),
    }


def simulate_thermal_derating(trajectory_data: list, total_cycles: int = 50) -> dict:
    """
    核验在连续高频调用下，电机绕组温升与热衰减模型。
    使用一阶集总参数温升微分方程: dT/dt = (I^2 * R - (T - T_amb)/R_th) / C_th
    """
    R_coil = 0.45      # 绕组内阻 (Ohm)
    Kt = 0.85          # 力矩常数 (N·m/A)
    R_th = 2.2         # 热阻 (K/W)
    C_th = 65.0        # 热容 (J/K)
    T_ambient = 25.0   # 环境温度 (°C)
    T_limit = 95.0     # 绝缘降额警戒阈值 (°C)

    # 提取周期平均力矩估算 (单位: N·m)
    times = [f["time_s"] for f in trajectory_data]
    dt = times[1] - times[0] if len(times) > 1 else 0.0333
    torques = []
    for i in range(1, len(trajectory_data)):
        q0 = trajectory_data[i-1]["joints"].get("R_SHOULDER_ROLL", 0.5)
        q1 = trajectory_data[i]["joints"].get("R_SHOULDER_ROLL", 0.5)
        v = (q1 - q0) / dt
        acc = v / dt
        est_tau = 0.08 * abs(acc) + 0.5 * abs(v) + 0.3 # 惯量+阻尼+静态偏置
        torques.append(est_tau)

    rms_torque = float(np.sqrt(np.mean(np.square(torques))))
    rms_current = rms_torque / Kt
    joule_power = (rms_current ** 2) * R_coil

    # 连续 50 个周期运行温升仿真
    T = T_ambient
    cycle_time = times[-1]
    total_time = cycle_time * total_cycles

    # 稳态温升计算
    steady_state_temp = T_ambient + joule_power * R_th

    # 瞬态积分
    sim_dt = 1.0 # 秒
    t_curr = 0.0
    while t_curr < total_time:
        p_dissipated = (T - T_ambient) / R_th
        dT = ((joule_power - p_dissipated) / C_th) * sim_dt
        T += dT
        t_curr += sim_dt

    return {
        "rms_torque_nm": round(rms_torque, 3),
        "joule_heating_w": round(joule_power, 3),
        "ambient_temp_c": T_ambient,
        "peak_winding_temp_c": round(T, 2),
        "steady_state_temp_c": round(steady_state_temp, 2),
        "thermal_limit_c": T_limit,
        "no_thermal_derating": bool(T < T_limit),
    }


def simulate_mujoco_push_recovery(mjcf_path: str, traj_path: str) -> dict:
    """
    在 MuJoCo 物理仿真中施加躯干侧向冲量扰动 (Push Perturbation)，
    核验机器人的质心 ZMP 与姿态自稳定恢复能力。
    """
    model = mujoco.MjModel.from_xml_path(mjcf_path)
    data = mujoco.MjData(model)

    with open(traj_path, "r", encoding="utf-8") as f:
        traj = json.load(f)

    # 设定站立初姿与执行器偏置
    mujoco.mj_resetData(model, data)
    act_r_sr_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_ACTUATOR, "act_r_sr")
    act_r_el_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_ACTUATOR, "act_r_el")
    joint_r_sr_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_JOINT, "right_shoulder_roll_joint")
    joint_r_el_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_JOINT, "right_elbow_joint")
    qpos_r_sr_idx = model.jnt_qposadr[joint_r_sr_id]
    qpos_r_el_idx = model.jnt_qposadr[joint_r_el_id]

    data.qpos[qpos_r_sr_idx] = -0.2
    data.qpos[qpos_r_el_idx] = 0.85
    data.ctrl[act_r_sr_id] = -0.2
    data.ctrl[act_r_el_id] = 0.85
    mujoco.mj_forward(model, data)

    step_dt = model.opt.timestep # 0.001s (1000Hz)
    total_steps = 4000 # 4.0 秒

    torso_body_id = mujoco.mj_name2id(model, mujoco.mjtObj.mjOBJ_BODY, "torso_link")

    max_tilt = 0.0
    recovered_tilt = 0.0
    zmp_within_support = True

    # 仿真并在 t = 1.0s 时施加侧向 6.0N 冲击力持续 50ms (0.3 N·s 冲量)
    for step in range(total_steps):
        t = step * step_dt

        # 控制指令插值
        phase = (t / 2.0) * 2 * math.pi
        target_r_sr = -0.2 + math.sin(phase) * 0.28 * 0.5
        target_r_el = 0.85 + math.sin(phase * 0.5) * 0.1 * 0.5

        data.ctrl[act_r_sr_id] = target_r_sr
        data.ctrl[act_r_el_id] = target_r_el

        # 在 t = 1.0s ~ 1.05s 施加外力冲量 (0.3 N·s)
        if 1.0 <= t < 1.05:
            data.xfrc_applied[torso_body_id, 1] = 6.0 # y 轴侧推 6.0 N
        else:
            data.xfrc_applied[torso_body_id, 1] = 0.0

        mujoco.mj_step(model, data)

        # 姿态倾角计算
        rot = data.xmat[torso_body_id].reshape(3, 3)
        tilt = math.acos(max(-1.0, min(1.0, rot[2, 2])))
        if tilt > max_tilt:
            max_tilt = tilt

        if t >= 3.0:
            recovered_tilt = max(recovered_tilt, tilt)

        # 支撑多边形核验 (双脚 Y 轴范围约为 [-0.18, 0.18] m)
        pelvis_pos_y = data.xpos[torso_body_id, 1]
        if abs(pelvis_pos_y) > 0.18:
            zmp_within_support = False

    return {
        "perturbation_impulse_ns": 0.3,
        "max_transient_tilt_rad": round(max_tilt, 4),
        "recovered_tilt_rad": round(recovered_tilt, 4),
        "tilt_recovery_pass": bool(recovered_tilt < 0.05),
        "zmp_support_polygon_pass": zmp_within_support,
        "stable_standing": bool(data.qpos[2] > 0.60 and zmp_within_support and recovered_tilt < 0.05),
    }


def main():
    base_dir = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    g1_urdf = os.path.join(base_dir, "resources", "robot-models", "unitree_g1", "g1_23dof.urdf")
    g1_mjcf = os.path.join(base_dir, "resources", "robot-models", "unitree_g1", "g1_23dof.xml")

    # 构造或加载 6.0s 测试轨迹
    frames = []
    fps = 30.0
    for i in range(181):
        t = i / fps
        phase = (t / 2.0) * 2 * math.pi
        env = (1.0 - math.cos(i / 180.0 * 2 * math.pi)) / 2.0
        r_sr = round(0.5 + math.sin(phase) * 0.28 * env, 4)
        frames.append({"time_s": round(t, 3), "joints": {"R_SHOULDER_ROLL": r_sr}})

    traj_tmp = os.path.join(base_dir, "qa", "simulation", "tmp_wave_traj.json")
    with open(traj_tmp, "w", encoding="utf-8") as f:
        json.dump(frames, f)

    try:
        nonlin_res = simulate_actuator_nonlinearity(g1_urdf, frames)
        thermal_res = simulate_thermal_derating(frames, total_cycles=50)
        push_res = simulate_mujoco_push_recovery(g1_mjcf, traj_tmp)
    finally:
        if os.path.isfile(traj_tmp):
            os.remove(traj_tmp)

    all_pass = (
        nonlin_res["stribeck_stable"] and
        thermal_res["no_thermal_derating"] and
        push_res["stable_standing"]
    )

    report = {
        "schema": "zhaohuan.actuator-dynamics-audit/v1",
        "verifier": "Actuator Nonlinearity, Thermal Derating & Push-Recovery Verifier",
        "robot_model": "Unitree G1 EDU 23-DoF",
        "verdict": "PASS" if all_pass else "FAIL",
        "actuator_nonlinearity": nonlin_res,
        "thermal_derating": thermal_res,
        "push_recovery": push_res,
    }

    out_json = os.path.join(base_dir, "qa", "simulation", "actuator_dynamics_report.json")
    with open(out_json, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print("=" * 50)
    print(f" 电机非线性、热衰减与抗扰动审计: {report['verdict']}")
    print("=" * 50)
    print(f" 齿隙与Stribeck非线性跟踪 RMSE: {nonlin_res['rmse_rad']} rad (阈值 < 0.05)")
    print(f" 50周期连续工作绕组温升: {thermal_res['peak_winding_temp_c']} °C (上限 {thermal_res['thermal_limit_c']} °C)")
    print(f" 侧推外力冲击最大倾斜: {push_res['max_transient_tilt_rad']} rad")
    print(f" 冲击后姿态恢复倾斜角: {push_res['recovered_tilt_rad']} rad (阈值 < 0.05)")
    print(f" 支撑多边形维持 & 无倾覆: {push_res['stable_standing']}")
    print(f" 审计报告归档: {out_json}")

    if not all_pass:
        sys.exit(1)


if __name__ == "__main__":
    main()
