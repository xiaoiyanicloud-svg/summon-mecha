<?php

/**
 * 独立停止观察的保守阈值（WBS A2）。
 *
 * ⚠ 这些是平台侧保守默认，不是任何厂商的真机限值。
 *   真实采样频率/断线语义/actual_stopped 定义仍待补充（槽位 1）。
 */
return [
    'max_age_seconds' => (int) env('STOP_OBSERVATION_MAX_AGE_SECONDS', 30),
    'max_future_skew_seconds' => (int) env('STOP_OBSERVATION_MAX_FUTURE_SKEW_SECONDS', 5),
    'velocity_epsilon' => (float) env('STOP_OBSERVATION_VELOCITY_EPSILON', 0.05),
    'current_epsilon' => (float) env('STOP_OBSERVATION_CURRENT_EPSILON', 0.5),
    'require_brake' => (bool) env('STOP_OBSERVATION_REQUIRE_BRAKE', true),
];
