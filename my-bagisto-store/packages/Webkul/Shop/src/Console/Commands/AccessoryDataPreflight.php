<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Shop\Models\AccessoryFitment;
use Webkul\Shop\Models\AccessoryProfile;

/**
 * 配件皮肤 · 上架前置校验（只读）。
 *
 * 检查每个配件商品是否具备「能对外卖」的最小事实：
 *  - 已归入配件子分类；
 *  - 至少 1 张真实图（product_images 有记录）；
 *  - 价格 > 0；
 *  - 有配件档案，且安装方式/包装清单/质保/交期齐全（定制件还要有定制起价）；
 *  - 至少有 1 条可售适配关系，或显式填写了通用适配说明。
 *
 * 默认只报告，不阻塞；加 --strict 时有缺失项返回非 0，供发布门禁/CI 使用。
 */
class AccessoryDataPreflight extends Command
{
    protected $signature = 'accessory:preflight
        {--strict : 存在缺失项时返回非 0}
        {--json= : 把结果写入指定 JSON 文件}';

    protected $description = '只读检查配件商品的上架必要条件';

    public function handle(): int
    {
        $parent = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
            ->first();

        if (! $parent) {
            $this->error('未找到 slug=accessory 的「配件」根分类。');
            return self::FAILURE;
        }

        $childIds = Category::query()->where('parent_id', $parent->id)->pluck('id')->all();
        $productIds = DB::table('product_categories')
            ->where('category_id', $parent->id)
            ->orWhereIn('category_id', $childIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $rows = [];
        $issueCount = 0;

        foreach ($productIds as $productId) {
            $product = \Webkul\Product\Models\Product::find($productId);
            if (! $product) {
                continue;
            }

            $issues = [];

            $inChild = DB::table('product_categories')
                ->where('product_id', $productId)
                ->whereIn('category_id', $childIds)
                ->exists();
            if (! $inChild) {
                $issues[] = '未归入配件子分类';
            }

            $hasImage = DB::table('product_images')->where('product_id', $productId)->exists();
            if (! $hasImage) {
                $issues[] = '没有真实商品图';
            }

            $flat = DB::table('product_flat')
                ->where('product_id', $productId)
                ->where('channel', core()->getCurrentChannel()->code)
                ->where('locale', core()->getCurrentLocale()->code)
                ->first();

            $price = $flat ? (float) (($flat->special_price ?: $flat->price) ?? 0) : 0.0;
            if ($price <= 0) {
                $issues[] = '价格 <= 0';
            }

            $profile = AccessoryProfile::query()->where('product_id', $productId)->first();
            if (! $profile) {
                $issues[] = '缺少配件档案';
            } else {
                if (! $profile->install_method) {
                    $issues[] = '缺少安装方式';
                }
                if (! $profile->package_contents) {
                    $issues[] = '缺少包装清单';
                }
                if (! $profile->warranty_months) {
                    $issues[] = '缺少质保月数';
                }
                if (! $profile->lead_time_days) {
                    $issues[] = '缺少交期';
                }
                if ($profile->custom_service && $profile->custom_price_from === null) {
                    $issues[] = '定制件缺少定制起价';
                }
            }

            $fitmentCount = AccessoryFitment::query()
                ->where('product_id', $productId)
                ->whereIn('fit_level', AccessoryFitment::SELLABLE_LEVELS)
                ->count();

            if ($fitmentCount === 0 && ! ($profile?->compatibility_note)) {
                $issues[] = '没有可售适配关系，且未填写通用适配说明';
            }

            if ($issues !== []) {
                $issueCount += count($issues);
            }

            $rows[] = [
                'id'       => $productId,
                'name'     => $product->name,
                'sku'      => $product->sku,
                'status'   => (int) $product->status,
                'price'    => $price,
                'fitments' => $fitmentCount,
                'issues'   => $issues,
            ];
        }

        $result = [
            'parent_id' => $parent->id,
            'checked'   => count($rows),
            'issues'    => $issueCount,
            'rows'      => $rows,
        ];

        $this->table(
            ['id', '商品', 'SKU', '状态', '价格', '适配数', '缺失项'],
            array_map(fn ($r) => [
                $r['id'], $r['name'], $r['sku'], $r['status'], $r['price'], $r['fitments'],
                $r['issues'] === [] ? '—' : implode('；', $r['issues']),
            ], $rows)
        );

        $this->line(sprintf('共检查 %d 个配件商品，缺失项 %d 条。', count($rows), $issueCount));

        if ($path = $this->option('json')) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('JSON 结果已写入：'.$path);
        }

        if ($issueCount > 0 && $this->option('strict')) {
            $this->error('--strict 模式：存在缺失项。');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
