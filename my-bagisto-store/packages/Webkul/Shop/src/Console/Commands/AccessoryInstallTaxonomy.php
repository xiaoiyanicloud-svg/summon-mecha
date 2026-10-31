<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Category\Repositories\CategoryRepository;

/**
 * 配件皮肤 · 分类树安装器（幂等，默认 dry-run）。
 *
 * 做两件事：
 *  1. 在「配件」根分类下创建 6 个稳定子分类（外观皮肤/防护结构/动力续航/感知导航/执行末端/工具耗材）；
 *  2. 把已经在「配件」根分类下的商品按名称归入子分类；**保留**原有的根分类关系，不删任何 pivot。
 *
 * 用法：
 *   php artisan accessory:install-taxonomy                 # 只看会做什么
 *   php artisan accessory:install-taxonomy --apply         # 真正写入
 *   php artisan accessory:install-taxonomy --apply --json=/path/result.json
 */
class AccessoryInstallTaxonomy extends Command
{
    protected $signature = 'accessory:install-taxonomy
        {--apply : 真正写入数据库；不加此参数只做 dry-run}
        {--json= : 把结果写入指定 JSON 文件}';

    protected $description = '安装配件皮肤子分类，并把现有配件商品归类（默认 dry-run）';

    /** code => [名称, 排序, 名称匹配正则] */
    private const TAXONOMY = [
        'skin'         => ['外观皮肤', 10, '/皮肤|涂装|贴膜|外观|炫彩|彩绘/u'],
        'armor'        => ['防护结构', 20, '/护甲|外壳|防护|防撞|装甲/u'],
        'power'        => ['动力续航', 30, '/电池|充电|电源|续航|电芯/u'],
        'sensor'       => ['感知导航', 40, '/相机|视觉|雷达|传感器|摄像头|IMU|激光|导航/u'],
        'end-effector' => ['执行末端', 50, '/夹爪|机械臂|灵巧手|末端|关节|夹具/u'],
        'tools'        => ['工具耗材', 60, '/工具|耗材|配件包|螺丝|支架|线材/u'],
    ];

    public function handle(CategoryRepository $categories): int
    {
        $apply = (bool) $this->option('apply');

        $parent = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
            ->first();

        if (! $parent) {
            $this->error('未找到 slug=accessory 的「配件」根分类。');
            return self::FAILURE;
        }

        $result = [
            'apply'      => $apply,
            'parent_id'  => $parent->id,
            'categories' => [],
            'products'   => [],
            'warnings'   => [],
        ];

        $childIds = [];

        foreach (self::TAXONOMY as $code => [$name, $sort, $pattern]) {
            $slug = 'accessory-'.$code;

            $existing = Category::query()
                ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
                ->first();

            if ($existing) {
                $childIds[$code] = $existing->id;
                $result['categories'][] = [
                    'code' => $code, 'name' => $name, 'slug' => $slug,
                    'id' => $existing->id, 'action' => 'exists',
                ];
                continue;
            }

            if (! $apply) {
                $childIds[$code] = null;
                $result['categories'][] = [
                    'code' => $code, 'name' => $name, 'slug' => $slug,
                    'id' => null, 'action' => 'would_create',
                ];
                continue;
            }

            $category = $categories->create([
                'parent_id'    => $parent->id,
                'status'       => 1,
                'position'     => $sort,
                'display_mode' => 'products_and_description',
                'locale'       => 'all',
                'name'         => $name,
                'slug'         => $slug,
                'url_path'     => $slug,
            ]);

            $childIds[$code] = $category->id;
            $result['categories'][] = [
                'code' => $code, 'name' => $name, 'slug' => $slug,
                'id' => $category->id, 'action' => 'created',
            ];
        }

        // 已在「配件」根分类下的商品
        $productIds = DB::table('product_categories')
            ->where('category_id', $parent->id)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 已归入任一配件子分类的商品（按 slug 找，避免依赖本次新建的 id）
        $existingChildIds = Category::query()
            ->where('parent_id', $parent->id)
            ->pluck('id')
            ->all();

        $assigned = DB::table('product_categories')
            ->whereIn('product_id', $productIds)
            ->whereIn('category_id', $existingChildIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $assignedMap = array_flip($assigned);

        foreach ($productIds as $productId) {
            $product = \Webkul\Product\Models\Product::find($productId);
            if (! $product) {
                continue;
            }

            if (isset($assignedMap[$productId])) {
                $result['products'][] = [
                    'id' => $productId, 'name' => $product->name, 'action' => 'already_classified',
                ];
                continue;
            }

            $matched = null;
            foreach (self::TAXONOMY as $code => [, , $pattern]) {
                if (preg_match($pattern, (string) $product->name)) {
                    $matched = $code;
                    break;
                }
            }

            if (! $matched) {
                $result['products'][] = [
                    'id' => $productId, 'name' => $product->name, 'action' => 'unmatched',
                ];
                $result['warnings'][] = "商品 #{$productId}「{$product->name}」无法按名称归类，保持只挂在根分类。";
                continue;
            }

            $targetId = $childIds[$matched] ?? null;

            if (! $apply) {
                $result['products'][] = [
                    'id' => $productId, 'name' => $product->name,
                    'action' => 'would_assign', 'category' => $matched, 'category_id' => $targetId,
                ];
                continue;
            }

            if (! $targetId) {
                $result['warnings'][] = "商品 #{$productId} 的目标子分类 {$matched} 未创建，跳过。";
                continue;
            }

            DB::table('product_categories')->insertOrIgnore([
                'product_id'  => $productId,
                'category_id' => $targetId,
            ]);

            $result['products'][] = [
                'id' => $productId, 'name' => $product->name,
                'action' => 'assigned', 'category' => $matched, 'category_id' => $targetId,
            ];
        }

        $this->render($result);

        if ($path = $this->option('json')) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('JSON 结果已写入：'.$path);
        }

        if (! $apply) {
            $this->warn('当前是 dry-run。确认无误后加 --apply 真正写入。');
        }

        return self::SUCCESS;
    }

    private function render(array $result): void
    {
        $this->line('配件根分类 id='.$result['parent_id']);
        $this->table(
            ['code', '名称', 'slug', 'id', '动作'],
            array_map(fn ($c) => [$c['code'], $c['name'], $c['slug'], $c['id'] ?? '-', $c['action']], $result['categories'])
        );

        if ($result['products'] !== []) {
            $this->table(
                ['id', '商品', '动作', '归类'],
                array_map(fn ($p) => [$p['id'], $p['name'], $p['action'], $p['category'] ?? '-'], $result['products'])
            );
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
    }
}
