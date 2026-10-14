<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 机型库种子(v3.4 步骤 5)—— 数据来源 docs/device-model-seed.md。
 *
 * 分级(计划 §0.5):
 *   P0 三家(宇树/智元/众擎) → status=active,声明 agent+manual 通道
 *   其余(乐聚/傅利叶/优必选/云深处/BD Spot/1X/海外的) → status=display_only(仅展示)
 *
 * ⚠ compute_platform / firmware_versions 大量"未知"(厂商不公开)→ 留 null;
 *   兼容性校验逻辑必须能处理 null(见 CompatibilityService)。
 * ⚠ 幂等:按 model_code 存在则更新,不重复插入。
 */
class DeviceModelSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ===== P0:宇树(开放 EDU,唯一有官方 App Store)=====
            ['unitree', 'unitree_g1', '宇树 G1', 'unitree', 23, 'ARM(Orin,EDU)', ['agent', 'manual'], null, 'active', 10],
            ['unitree', 'unitree_go2', '宇树 Go2', 'unitree', 12, 'ARM(Orin,EDU)', ['agent', 'manual'], null, 'active', 11],
            ['unitree', 'unitree_h1', '宇树 H1', 'unitree', 19, 'x86(i5+i7)', ['manual'], null, 'active', 12],
            ['unitree', 'unitree_h2', '宇树 H2', 'unitree', 31, 'x86(i5+i7)/ARM(Thor)', ['manual'], null, 'active', 13],
            ['unitree', 'unitree_b2', '宇树 B2', 'unitree', 12, 'x86(i5+i7)', ['manual'], null, 'active', 14],

            // ===== P0:智元 =====
            ['agibot', 'agibot_a2', '智元 远征A2', 'agibot', 40, 'ARM(Orin)+x86', ['agent', 'manual'], null, 'active', 20],
            ['agibot', 'agibot_a3_ultra', '智元 远征A3-Ultra', 'agibot', 40, 'ARM(Orin)+x86', ['agent', 'manual'], null, 'active', 21],
            ['agibot', 'agibot_x2', '智元 灵犀X2', 'agibot', 30, 'ARM(RK3588/Orin NX)', ['manual'], null, 'active', 22],

            // ===== P0:众擎 =====
            ['engineai', 'engineai_pm01', '众擎 PM01', 'engineai', 24, 'ARM(Orin NX)+x86(N97)', ['agent', 'manual'], null, 'active', 30],
            ['engineai', 'engineai_t800', '众擎 T800', 'engineai', null, 'ARM(AGX Orin)+x86', ['manual'], null, 'active', 31],

            // ===== 仅展示(开放但非 P0;或封闭)=====
            ['leju', 'leju_kuavo4pro', '乐聚 Kuavo4Pro', null, 28, 'ARM(AGX Orin)+x86', ['manual'], null, 'display_only', 40],
            ['fourier', 'fourier_gr3', '傅利叶 GR-3', null, 55, 'x86(Ryzen7)', ['manual'], null, 'display_only', 41],
            ['ubtech', 'ubtech_walker_s2', '优必选 Walker S2', null, 42, 'x86+ARM(Orin)', ['manual'], null, 'display_only', 42],
            ['deeprobotics', 'deeprobotics_x30', '云深处 绝影X30', null, 12, null, ['manual'], null, 'display_only', 43],
            ['bostondynamics', 'bd_spot', '波士顿动力 Spot', null, 12, null, ['manual'], null, 'display_only', 44],
            ['1x', 'onex_neo', '1X NEO', null, null, 'ARM(Jetson Thor)', [], null, 'display_only', 45],
            ['tesla', 'tesla_optimus', 'Tesla Optimus', null, null, null, [], null, 'display_only', 46],
            ['figure', 'figure_02', 'Figure 02', null, null, null, [], null, 'display_only', 47],
        ];

        $now = now();

        foreach ($rows as $r) {
            [$brand, $code, $name, $vendor, $dof, $compute, $channels, $fw, $status, $sort] = $r;

            DB::table('device_model')->updateOrInsert(
                ['model_code' => $code],
                [
                    'brand'              => $brand,
                    'model_name'         => $name,
                    'vendor_code'        => $vendor,
                    'degrees_of_freedom' => $dof,
                    'compute_platform'   => $compute,
                    'comm_channels'      => json_encode($channels),
                    'firmware_versions'  => $fw ? json_encode($fw) : null,
                    'status'             => $status,
                    'sort_order'         => $sort,
                    'updated_at'         => $now,
                    'created_at'         => $now,
                ]
            );
        }
    }
}
