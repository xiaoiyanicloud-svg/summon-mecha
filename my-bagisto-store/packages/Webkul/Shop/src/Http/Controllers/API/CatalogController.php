<?php

namespace Webkul\Shop\Http\Controllers\API;

use Webkul\Category\Models\Category;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Webkul\Shop\Services\Deploy\KillSwitchService;

/**
 * 目录聚合接口 —— 机器页性能优化专用。
 *
 * 背景:机器页原先要发 6 个请求(分类列表 → 4 个分类商品 → 场景列表),
 * 单进程 php serve 下请求排队,首屏 4s+。本接口 1 个请求返回全部。
 *
 * 数据层与原生 /api/products 完全一致(ProductRepository::getAll + ProductResource),
 * 前端 mapCatalogProduct 无需任何适配;scenes 段与 /api/scenes 结构一致。
 *
 * 返回: {data: {rent: [], buy: [], accessory: [], skill: [], scenes: []}}
 */
class CatalogController extends APIController
{
    public function __construct(protected ProductRepository $productRepository) {}

    public function index()
    {
        $result = [];

        foreach (['rent', 'buy', 'accessory', 'skill'] as $slug) {
            $result[$slug] = $this->productsByCategorySlug($slug);
        }

        // 场景复用 SceneController@index,与 /api/scenes 返回结构逐字段一致
        $result['scenes'] = (new SceneController)->index()->getData(true)['data'] ?? [];

        return response()->json(['data' => $result]);
    }

    /**
     * 按分类 slug 取在售商品(与原生 ProductController@index 同一数据层与过滤条件)。
     */
    private function productsByCategorySlug(string $slug): array
    {
        $categoryId = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->value('id');

        if (! $categoryId) {
            return [];
        }

        $products = $this->productRepository
            ->setSearchEngine('database')
            ->getAll(array_merge(request()->query(), [
                'category_id'          => $categoryId,
                'limit'                => 200,
                'page'                 => 1,
                'channel_id'           => core()->getCurrentChannel()->id,
                'status'               => 1,
                'visible_individually' => 1,
            ]));

        $rows = method_exists($products, 'items') ? $products->items() : $products;

        // S-8 · 熔断即下架：被熔断的技能**不得出现在首页聚合里**（用户第一眼就能看到、点进去）。
        //   此前只有 `skill-catalog/search` 做了过滤，本聚合（首页数据源）漏了 —— 停售只挡住了搜索一条路。
        //
        // ⚠ **只对 `skill` 段生效**：熔断只针对技能商品，租 / 买 / 配件不受影响。
        //   这里刻意按 slug 分支，绝不把过滤套到整张聚合上（那会把租/买/配件也一起藏了）。
        //   规则复用 `KillSwitchService::saleBlockedSkillIds()`（"停售"口径的唯一读取入口），
        //   本控制器不自己判强度。
        if ($slug === 'skill') {
            $blocked = app(KillSwitchService::class)->saleBlockedSkillIds();

            if (! empty($blocked)) {
                $rows = collect($rows)
                    ->reject(fn ($p) => in_array((int) $p->id, $blocked, true))
                    ->values()
                    ->all();
            }
        }

        return array_map(fn ($p) => (new ProductResource($p))->jsonSerialize(), $rows);
    }
}
