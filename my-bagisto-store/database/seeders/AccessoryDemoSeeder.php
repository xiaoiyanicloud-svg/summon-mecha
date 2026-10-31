<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Shop\Models\AccessoryFitment;
use Webkul\Shop\Models\AccessoryProfile;

/**
 * 配件皮肤 · 演示数据（只针对 TEST-ACC-* 测试商品）。
 *
 * 目的：让本地/测试环境能看到「适配矩阵 / 安装 / 质保 / 交期」的真实渲染。
 * **不会**给真实商品（如 3D视觉相机）编造档案或适配关系；真实商品仍由运营在后台维护。
 * 所有适配备注都显式写着“演示数据，待运营核实”，避免被误当成对外承诺。
 */
class AccessoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            'TEST-ACC-BATTERY' => [
                'summary'          => '通用型锂电池组，适配主流四足与人形机器人（演示数据）',
                'install_method'   => AccessoryProfile::INSTALL_SELF,
                'package_contents' => "电池包 ×1\n说明书 ×1",
                'warranty_months'  => 12,
                'lead_time_days'   => 3,
                'material'         => '锂电池组',
                'official'         => false,
                'custom_service'   => false,
                'compatibility_note' => '演示数据，待运营核实具体机型与接口。',
                'power_requirement'=> '48V / 20Ah',
            ],
            'TEST-ACC-CHARGER' => [
                'summary'          => '自动对接充电，支持定时与低电量回充（演示数据）',
                'install_method'   => AccessoryProfile::INSTALL_ENGINEER,
                'package_contents' => "充电桩 ×1\n安装底座 ×1\n说明书 ×1",
                'warranty_months'  => 12,
                'lead_time_days'   => 7,
                'material'         => '金属 + 工程塑料',
                'official'         => false,
                'custom_service'   => false,
                'compatibility_note' => '演示数据，待运营核实具体机型与场地要求。',
                'power_requirement'=> '220V 市电',
            ],
            'TEST-ACC-GRIPPER' => [
                'summary'          => '二指/三指可换夹爪，含安装法兰与转接件（演示数据）',
                'install_method'   => AccessoryProfile::INSTALL_ENGINEER,
                'package_contents' => "夹爪 ×1\n安装法兰 ×1\n转接件 ×1\n说明书 ×1",
                'warranty_months'  => 6,
                'lead_time_days'   => 10,
                'material'         => '铝合金',
                'official'         => false,
                'custom_service'   => false,
                'compatibility_note' => '演示数据，待运营核实法兰规格与接口。',
                'power_requirement'=> '24V / 需主机预留接口',
            ],
        ];

        foreach ($profiles as $sku => $data) {
            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                continue;
            }

            AccessoryProfile::firstOrCreate(['product_id' => $product->id], $data);
        }

        $fitments = [
            'TEST-ACC-BATTERY' => [
                ['unitree_go2', AccessoryFitment::FIT_DIRECT, '演示数据：接口待运营核实'],
                ['unitree_g1', AccessoryFitment::FIT_ADAPTER, '演示数据：可能需要转接件'],
            ],
            'TEST-ACC-CHARGER' => [
                ['unitree_go2', AccessoryFitment::FIT_DIRECT, '演示数据：场地条件待核实'],
            ],
            'TEST-ACC-GRIPPER' => [
                ['unitree_g1', AccessoryFitment::FIT_ADAPTER, '演示数据：需安装法兰'],
            ],
        ];

        foreach ($fitments as $sku => $rows) {
            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                continue;
            }

            foreach ($rows as [$modelCode, $level, $note]) {
                AccessoryFitment::firstOrCreate(
                    ['product_id' => $product->id, 'model_code' => $modelCode],
                    [
                        'fit_level' => $level,
                        'note'      => $note,
                        'source'    => AccessoryFitment::SOURCE_PLATFORM,
                    ]
                );
            }
        }
    }
}
