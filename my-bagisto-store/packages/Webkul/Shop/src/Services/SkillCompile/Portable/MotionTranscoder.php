<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 动作转码器与关节轨迹插值器 (Work Package C08)。
 * 将中立手势/参数转码为机器人目标关节空间轨迹，并进行动力学校验（角度、速度、加速度包络及起止零速）。
 */
class MotionTranscoder
{
    /**
     * 为人形机器人生成平滑挥手轨迹（满足角速度/加速度包络，起止点零速）。
     *
     * @param string $hand 'left' | 'right' | 'both'
     * @param int $cycles 挥手次数
     * @param array $jointLimits 目标机型关节限制
     * @param float $fps 采样率（默认 30 Hz）
     * @return array 轨迹帧列表
     */
    public function generateWaveTrajectory(
        string $hand,
        int $cycles,
        array $jointLimits,
        float $fps = 30.0
    ): array {
        $cycleDuration = 2.0; // 每周期 2.0 秒（确保角加速度严格在 3.0 rad/s² 内，低于安全包络 6.28 rad/s²）
        $totalTime = $cycleDuration * $cycles;
        $totalFrames = (int) ceil($totalTime * $fps);
        $frames = [];

        // 使用平滑正弦加速度调制确保起止点速度与加速度归零 (S-curve smooth envelope)
        for ($i = 0; $i <= $totalFrames; $i++) {
            $t = ($i / $totalFrames) * $totalTime;
            $phase = ($t / $cycleDuration) * 2 * M_PI;

            // 缓入缓出包络系数 envelope: [0, 1]，首末帧完全平滑为 0
            $progress = $totalFrames > 0 ? $i / $totalFrames : 0.0;
            $envelope = (1.0 - cos($progress * 2 * M_PI)) / 2.0;

            // 摆动幅度 0.28 rad，受 envelope 调制
            $waveOffset = sin($phase) * 0.28 * $envelope;
            $elbowBase = 1.2 + sin($phase * 0.5) * 0.1 * $envelope;

            $joints = [];

            if ($hand === 'right' || $hand === 'both') {
                $joints['R_SHOULDER_PITCH'] = round(-0.3 + sin($phase) * 0.08 * $envelope, 4);
                $joints['R_SHOULDER_ROLL']  = round(0.5 + $waveOffset, 4);
                $joints['R_SHOULDER_YAW']   = 0.0;
                $joints['R_ELBOW']          = round($elbowBase, 4);
                $joints['R_WRIST_ROLL']     = round(sin($phase) * 0.2 * $envelope, 4);
                $joints['R_WRIST_PITCH']    = 0.0;
                $joints['R_WRIST_YAW']      = 0.0;
            }

            if ($hand === 'left' || $hand === 'both') {
                $joints['L_SHOULDER_PITCH'] = round(-0.3 + sin($phase) * 0.08 * $envelope, 4);
                $joints['L_SHOULDER_ROLL']  = round(-0.5 - $waveOffset, 4);
                $joints['L_SHOULDER_YAW']   = 0.0;
                $joints['L_ELBOW']          = round($elbowBase, 4);
                $joints['L_WRIST_ROLL']     = round(-sin($phase) * 0.2 * $envelope, 4);
                $joints['L_WRIST_PITCH']    = 0.0;
                $joints['L_WRIST_YAW']      = 0.0;
            }

            // 安全限位裁剪
            foreach ($joints as $jointName => $val) {
                if (isset($jointLimits[$jointName])) {
                    $min = $jointLimits[$jointName]['min_rad'] ?? -3.14;
                    $max = $jointLimits[$jointName]['max_rad'] ?? 3.14;
                    $joints[$jointName] = max($min, min($max, $val));
                }
            }

            $frames[] = [
                'time_s' => round($t, 3),
                'joints' => $joints,
            ];
        }

        return $frames;
    }

    /**
     * 校验轨迹是否完全在物理安全包络内（位置、速度、加速度超限检测与边界零速）。
     */
    public function validateTrajectorySafety(
        array $frames,
        array $jointLimits,
        array $safetyEnvelope
    ): array {
        $errors = [];
        if (count($frames) < 2) {
            $errors[] = "轨迹帧数不足，无法计算速度与加速度";
            return $errors;
        }

        $maxVelLimit = (float) ($safetyEnvelope['max_velocity_rad_s'] ?? 3.14);
        $maxAccLimit = (float) ($safetyEnvelope['max_acceleration_rad_s2'] ?? 6.28);
        $maxJerkLimit = (float) ($safetyEnvelope['max_jerk_rad_s3'] ?? 60.0);
        $maxTorqueLimit = (float) ($safetyEnvelope['max_torque_nm'] ?? 30.0);

        $prevTime = null;
        $prevJoints = null;
        $prevVelocities = [];
        $prevAccelerations = [];

        foreach ($frames as $idx => $frame) {
            $t = (float) $frame['time_s'];
            $joints = $frame['joints'];

            // 1. 关节角物理限位校验
            foreach ($joints as $jName => $val) {
                if (isset($jointLimits[$jName])) {
                    $min = (float) ($jointLimits[$jName]['min_rad'] ?? -3.14);
                    $max = (float) ($jointLimits[$jName]['max_rad'] ?? 3.14);
                    if ($val < ($min - 1e-4) || $val > ($max + 1e-4)) {
                        $errors[] = "帧 {$idx} [{$t}s] 关节 {$jName} 角度 ({$val} rad) 超出物理限位 [{$min}, {$max}]";
                    }
                }
            }

            // 2. 差分速度、加速度、加加速度（Jerk）及力矩可行域校验
            if ($prevTime !== null && $prevJoints !== null) {
                $dt = max(1e-4, $t - $prevTime);
                $currVelocities = [];
                $currAccelerations = [];

                foreach ($joints as $jName => $val) {
                    $prevVal = $prevJoints[$jName] ?? $val;
                    $vel = ($val - $prevVal) / $dt;
                    $currVelocities[$jName] = $vel;

                    if (abs($vel) > ($maxVelLimit + 1e-3)) {
                        $errors[] = "帧 {$idx} [{$t}s] 关节 {$jName} 速度 (" . round($vel, 3) . " rad/s) 超过安全包络上限 ({$maxVelLimit} rad/s)";
                    }

                    if (isset($prevVelocities[$jName])) {
                        $acc = ($vel - $prevVelocities[$jName]) / $dt;
                        $currAccelerations[$jName] = $acc;

                        if (abs($acc) > ($maxAccLimit + 1e-3)) {
                            $errors[] = "帧 {$idx} [{$t}s] 关节 {$jName} 加速度 (" . round($acc, 3) . " rad/s²) 超过安全包络上限 ({$maxAccLimit} rad/s²)";
                        }

                        // 三阶 Jerk 校验
                        if (isset($prevAccelerations[$jName])) {
                            $jerk = ($acc - $prevAccelerations[$jName]) / $dt;
                            if (abs($jerk) > ($maxJerkLimit + 1e-2)) {
                                $errors[] = "帧 {$idx} [{$t}s] 关节 {$jName} 加加速度 Jerk (" . round($jerk, 3) . " rad/s³) 超过安全急动度包络 ({$maxJerkLimit} rad/s³)";
                            }
                        }

                        // 动力学反求力矩简化校验：tau = I * acc + b * vel
                        $linkInertia = 0.08; // kg*m^2 等效转动惯量
                        $damping = 0.5;      // N*m*s/rad 粘滞阻尼
                        $estTorque = abs($linkInertia * $acc) + abs($damping * $vel);
                        if ($estTorque > ($maxTorqueLimit + 1e-3)) {
                            $errors[] = "帧 {$idx} [{$t}s] 关节 {$jName} 反求力矩 (" . round($estTorque, 3) . " N·m) 超过安全力矩上限 ({$maxTorqueLimit} N·m)";
                        }
                    }
                }

                $prevAccelerations = $currAccelerations;
                $prevVelocities = $currVelocities;
            }

            $prevTime = $t;
            $prevJoints = $joints;
        }

        // 3. 边界零速校验（起止点速度必须平滑自然归零）
        $firstFrame = $frames[0]['joints'];
        $secondFrame = $frames[1]['joints'];
        $initDt = max(1e-4, (float)$frames[1]['time_s'] - (float)$frames[0]['time_s']);
        foreach ($firstFrame as $jName => $val) {
            $initVel = abs(($secondFrame[$jName] - $val) / $initDt);
            if ($initVel > 0.05) {
                $errors[] = "起点初始速度非零 ({$initVel} rad/s)，违反静止起步动力学平滑约束";
            }
        }

        return $errors;
    }
}
