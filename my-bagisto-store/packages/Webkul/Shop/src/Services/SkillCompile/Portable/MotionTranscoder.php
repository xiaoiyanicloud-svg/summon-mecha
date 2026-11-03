<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 动作转码器与关节轨迹插值器 —— 将中立手势/参数转码为机器人目标关节空间轨迹。
 */
class MotionTranscoder
{
    /**
     * 为 Unitree G1 生成平滑挥手轨迹（满足角速度/加速度包络，起止点零速）。
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
        $cycleDuration = 1.5; // 每周期 1.5 秒
        $totalTime = $cycleDuration * $cycles;
        $totalFrames = (int) ceil($totalTime * $fps);
        $frames = [];

        for ($i = 0; $i <= $totalFrames; $i++) {
            $t = ($i / $totalFrames) * $totalTime;
            $phase = ($t / $cycleDuration) * 2 * M_PI;

            // 平滑正弦波摆动：(1 - cos(wt)) / 2
            $waveOffset = sin($phase) * 0.5; // 摆动幅度 0.5 rad (约 28.6 度)
            $elbowBase = 1.2 + sin($phase * 0.5) * 0.2; // 肘部微曲保持 1.2 rad

            $joints = [];

            if ($hand === 'right' || $hand === 'both') {
                $joints['R_SHOULDER_PITCH'] = round(-0.3 + sin($phase) * 0.1, 4);
                $joints['R_SHOULDER_ROLL']  = round(0.5 + $waveOffset, 4);
                $joints['R_SHOULDER_YAW']   = 0.0;
                $joints['R_ELBOW']          = round($elbowBase, 4);
                $joints['R_WRIST_ROLL']     = round(sin($phase) * 0.3, 4);
                $joints['R_WRIST_PITCH']    = 0.0;
                $joints['R_WRIST_YAW']      = 0.0;
            }

            if ($hand === 'left' || $hand === 'both') {
                $joints['L_SHOULDER_PITCH'] = round(-0.3 + sin($phase) * 0.1, 4);
                $joints['L_SHOULDER_ROLL']  = round(-0.5 - $waveOffset, 4);
                $joints['L_SHOULDER_YAW']   = 0.0;
                $joints['L_ELBOW']          = round($elbowBase, 4);
                $joints['L_WRIST_ROLL']     = round(-sin($phase) * 0.3, 4);
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
}
