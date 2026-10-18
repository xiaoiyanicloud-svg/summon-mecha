<?php

namespace Webkul\Shop\Http\Controllers\API;

use Webkul\Category\Models\Category;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;

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
                'exclude_ids' => app(SkillMarketService::class)->unavailableProductIds(),
            ]));

        $rows = method_exists($products, 'items') ? $products->items() : $products;

        return array_map(fn ($p) => (new ProductResource($p))->jsonSerialize(), $rows);
    }
}
