<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;

/**
 * 测试用商品种子：租用 / 配件 / 技能 各上架几个可下单商品。
 *
 * 只做测试数据，用 TEST- 前缀 SKU 区分，便于一次性清理：
 *     DELETE FROM products WHERE sku LIKE 'TEST-%';
 *
 * 技能商品额外写入一条 published 的 skill_versions，否则会被
 * SkillMarketService 判定为「未发布技能」而从目录里排除。
 *
 * 幂等：按 sku 存在则更新。
 * 用法：php artisan db:seed --class=TestCatalogSeeder
 */
class TestCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Laravel 在执行 db:seed 时会全局 Model::unguard()；商品更新走的是 Eloquent
        // mass assignment，若不恢复 guard，name/price 等会直接写进 products 表并报列不存在。
        Model::reguard();

        $repository = app(ProductRepository::class);

        $categoryIds = DB::table('category_translations')
            ->whereIn('slug', ['rent', 'accessory', 'skill'])
            ->pluck('category_id', 'slug')
            ->all();

        $channelId = (int) DB::table('channels')->where('code', 'default')->value('id');

        if (! isset($categoryIds['rent'], $categoryIds['accessory'], $categoryIds['skill']) || ! $channelId) {
            $this->command?->error('缺少 rent/accessory/skill 分类或 default 渠道，先跑 SkillCatalogSeeder 与基础安装。');

            return;
        }

        $items = [
            // ===== 租用（分类 rent，前端按「元/天」展示）=====
            [
                'sku'         => 'TEST-RENT-GO2',
                'name'        => '宇树 Go2 四足机器人 · 日租',
                'category'    => 'rent',
                'price'       => 399,
                'url_key'     => 'test-rent-go2',
                'description' => '四足巡检/陪伴机器人，含基础遥控与跟随，交付前完成调试。适合园区巡检、展会互动、拍摄跟拍等短期场景。',
            ],
            [
                'sku'         => 'TEST-RENT-X2',
                'name'        => '智元 灵犀X2 人形机器人 · 日租',
                'category'    => 'rent',
                'price'       => 1299,
                'url_key'     => 'test-rent-x2',
                'description' => '人形机器人整机租赁，支持迎宾、讲解、互动表演，随机器材包含运输箱与快充。',
            ],
            [
                'sku'         => 'TEST-RENT-WELCOME',
                'name'        => '展厅迎宾机器人 · 日租',
                'category'    => 'rent',
                'price'       => 599,
                'url_key'     => 'test-rent-welcome',
                'description' => '展厅、门店、政务大厅迎宾导览，含语音讲解与路线带路，可按场地地图定制路径。',
            ],

            // ===== 配件（分类 accessory）=====
            [
                'sku'         => 'TEST-ACC-BATTERY',
                'name'        => '机器人备用电池包 48V/20Ah',
                'category'    => 'accessory',
                'price'       => 899,
                'url_key'     => 'test-acc-battery',
                'description' => '通用型锂电池组，适配主流四足与人形机器人，单包续航约 2 小时，可热插拔。',
            ],
            [
                'sku'         => 'TEST-ACC-CHARGER',
                'name'        => '智能快充充电桩',
                'category'    => 'accessory',
                'price'       => 1299,
                'url_key'     => 'test-acc-charger',
                'description' => '自动对接充电，支持定时与低电量回充，适合长时间驻场作业。',
            ],
            [
                'sku'         => 'TEST-ACC-GRIPPER',
                'name'        => '通用机械臂夹爪套装',
                'category'    => 'accessory',
                'price'       => 1599,
                'url_key'     => 'test-acc-gripper',
                'description' => '二指/三指可换夹爪，含安装法兰与转接件，适配大部分协作机械臂。',
            ],

            // ===== 技能（分类 skill，需 published 版本才可见）=====
            [
                'sku'         => 'TEST-SKILL-WELCOME',
                'name'        => '迎宾接待技能包',
                'category'    => 'skill',
                'price'       => 499,
                'url_key'     => 'test-skill-welcome',
                'description' => '主动迎宾、问候、指引与合影 pose，含常用话术模板，可在后台替换文案。',
            ],
            [
                'sku'         => 'TEST-SKILL-DANCE',
                'name'        => '舞蹈表演技能包',
                'category'    => 'skill',
                'price'       => 799,
                'url_key'     => 'test-skill-dance',
                'description' => '三套预设舞蹈动作，支持音乐触发与节拍同步，适用于商场、年会、展会暖场。',
            ],
            [
                'sku'         => 'TEST-SKILL-GUIDE',
                'name'        => '导览讲解技能包',
                'category'    => 'skill',
                'price'       => 699,
                'url_key'     => 'test-skill-guide',
                'description' => '按点位讲解与路线引导，支持中英双语切换，讲解内容可在后台维护。',
            ],
        ];

        $created = [];
        $updated = [];

        foreach ($items as $item) {
            $product = $repository->findByField('sku', $item['sku'])->first();

            if (! $product) {
                $product = $repository->create([
                    'type'                => 'simple',
                    'attribute_family_id' => 1,
                    'sku'                 => $item['sku'],
                ]);
                $created[] = $item['sku'];
            } else {
                $updated[] = $item['sku'];
            }

            $product = $repository->update([
                'sku'                 => $item['sku'],
                'name'                => $item['name'],
                'url_key'             => $item['url_key'],
                'price'               => $item['price'],
                'weight'              => 1,
                'status'              => 1,
                'visible_individually'=> 1,
                'description'         => '<p>'.$item['description'].'</p>',
                'short_description'   => $item['description'],
                'locale'              => 'zh_CN',
                'channels'            => [$channelId],
                'categories'          => [$categoryIds[$item['category']]],
                'inventories'         => [$channelId => 50],
            ], $product->id);

            // 后台商品保存后会抛这个事件来刷新 product_flat；
            // 技能市场只认 product_flat，不抛就查不到。
            Event::dispatch('catalog.product.update.after', $product);

            // 技能必须有一条已发布版本，才会出现在前台的技能市场里
            if ($item['category'] === 'skill') {
                $hasPublished = DB::table('skill_versions')
                    ->where('product_id', $product->id)
                    ->where('status', 'published')
                    ->exists();

                if (! $hasPublished) {
                    DB::table('skill_versions')->insert([
                        'product_id'  => $product->id,
                        'version'     => '1.0.0',
                        'channel'     => 'stable',
                        'license_mode'=> 'per_device',
                        'status'      => 'published',
                        'changelog'   => '测试数据：上架初始版本',
                        'released_at' => now(),
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        }

        $this->command?->info('测试商品已就绪。新建：'.(implode(', ', $created) ?: '无').'；更新：'.(implode(', ', $updated) ?: '无'));
    }
}
