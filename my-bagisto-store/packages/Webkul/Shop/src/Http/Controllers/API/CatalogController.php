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
        $brandService = app(\Webkul\Shop\Services\Catalog\CatalogBrandService::class);
        $meta = $brandService->publicMetadata();
        $result = [];

        foreach (['rent', 'buy', 'accessory', 'skill'] as $slug) {
            $result[$slug] = $this->productsByCategorySlug($slug);
        }

        // 场景复用 SceneController@index,与 /api/scenes 返回结构逐字段一致
        $result['scenes'] = (new SceneController)->index()->getData(true)['data'] ?? [];
        $result['brands'] = $meta['brands'];
        $result['robot_types'] = $meta['robot_types'];
        $result['categories_meta'] = $this->categoriesMeta();

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
        app(\Webkul\Shop\Services\Catalog\CatalogBrandService::class)->attachCatalogMeta($rows);

        return array_map(fn ($p) => (new ProductResource($p))->jsonSerialize(), $rows);
    }

    /**
     * 返回核心目录树元信息（tob -> rent/buy, skill, accessory），供前台动态对齐。
     */
    private function categoriesMeta(): array
    {
        $defaults = [
            'tob'       => ['slug' => 'tob', 'name' => '租售 TOB', 'parent_slug' => 'root'],
            'rent'      => ['slug' => 'rent', 'name' => '租赁', 'parent_slug' => 'tob'],
            'buy'       => ['slug' => 'buy', 'name' => '购买', 'parent_slug' => 'tob'],
            'skill'     => ['slug' => 'skill', 'name' => '技能', 'parent_slug' => 'root'],
            'accessory' => ['slug' => 'accessory', 'name' => '配件', 'parent_slug' => 'root'],
        ];

        if (! \Illuminate\Support\Facades\Schema::hasTable('categories')
            || ! \Illuminate\Support\Facades\Schema::hasTable('category_translations')) {
            return $defaults;
        }

        $locale = app()->getLocale() ?: 'zh_CN';
        $rows = \Illuminate\Support\Facades\DB::table('categories as c')
            ->join('category_translations as ct', 'ct.category_id', '=', 'c.id')
            ->whereIn('ct.slug', array_keys($defaults))
            ->where(function ($q) use ($locale) {
                $q->where('ct.locale', $locale)->orWhere('ct.locale', 'zh_CN');
            })
            ->select('c.id', 'c.parent_id', 'c.status', 'c.position', 'ct.slug', 'ct.name', 'ct.locale')
            ->get();

        foreach ($rows as $row) {
            if (! isset($defaults[$row->slug]) || ($defaults[$row->slug]['_locale'] ?? null) === $locale) {
                continue;
            }
            $defaults[$row->slug] = array_merge($defaults[$row->slug], [
                'id'        => (int) $row->id,
                'parent_id' => $row->parent_id ? (int) $row->parent_id : null,
                'status'    => (bool) $row->status,
                'position'  => (int) $row->position,
                'name'      => $row->name ?: $defaults[$row->slug]['name'],
                '_locale'   => $row->locale,
            ]);
        }

        foreach ($defaults as &$item) {
            unset($item['_locale']);
        }

        return $defaults;
    }
}
