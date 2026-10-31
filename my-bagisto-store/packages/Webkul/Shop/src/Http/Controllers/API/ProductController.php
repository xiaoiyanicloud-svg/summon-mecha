<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Marketing\Jobs\UpdateCreateSearchTerm as UpdateCreateSearchTermJob;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;

/**
 * 买家侧商品接口 —— `GET /api/products`(列表 / 详情 / 关联 / 搭售)。
 *
 * ⚠ 这是**买家侧**控制器(Shop 包 api 路由),后台**不走它**:Admin 的商品列表是它自己的
 *   `Admin\Http\Controllers\Catalog\ProductController` + `ProductDataGrid`。
 *   故 S-8 的"熔断停售"过滤加在**本控制器**、而不是共享的 `ProductRepository::getAll` ——
 *   否则会把 Admin 后台的列表也一起过滤掉(运营需要看到被熔断的技能)。
 */
class ProductController extends APIController
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository
    ) {}

    /**
     * Product listings.
     */
    public function index(): JsonResource
    {
        $searchEngine = 'database';

        $searchData = $this->resolveSearchQueryData($searchEngine);

        $query = $searchData['effective_query'] ?? $searchData['original_query'];

        $products = $this->productRepository
            ->setSearchEngine($searchEngine)
            ->getAll(array_merge(request()->query(), [
                'query'                => $query,
                'channel_id'           => core()->getCurrentChannel()->id,
                'status'               => 1,
                'visible_individually' => 1,
                // S-8 · 熔断即下架:买家侧商品列表统一排除被熔断的技能。
                //   规则取 KillSwitchService(唯一读取入口),按 id 排除 —— skill_blocklist 里只可能是
                //   技能商品,故天然只影响技能,非技能分类不受影响。后台不走本控制器(见类注释)。
                'exclude_ids'          => app(SkillMarketService::class)->unavailableProductIds(),
            ]));

        $this->attachSalesCounts($products);

        if (! empty($query)) {
            /**
             * Update or create search term only if
             * there is only one filter that is query param
             */
            if (count(request()->except(['mode', 'sort', 'limit'])) == 1) {
                UpdateCreateSearchTermJob::dispatch([
                    'term'       => $query,
                    'results'    => $products->total(),
                    'channel_id' => core()->getCurrentChannel()->id,
                    'locale'     => app()->getLocale(),
                ]);
            }
        }

        return ProductResource::collection($products);
    }

    /**
     * 给商品列表补充真实销量，供前端“销量优先”排序。
     *
     * 口径与后台商品报表一致：已开票 - 已退款，排除取消订单；没有订单的商品记 0。
     */
    protected function attachSalesCounts($products): void
    {
        $items = method_exists($products, 'getCollection') ? $products->getCollection() : collect($products);
        $ids = $items->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === []) {
            return;
        }

        $counts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $ids)
            ->where('orders.status', '<>', 'canceled')
            ->selectRaw(
                'order_items.product_id, '.
                'SUM(CASE WHEN COALESCE(order_items.qty_invoiced, 0) - COALESCE(order_items.qty_refunded, 0) > 0 '.
                'THEN COALESCE(order_items.qty_invoiced, 0) - COALESCE(order_items.qty_refunded, 0) ELSE 0 END) AS sales_count'
            )
            ->groupBy('order_items.product_id')
            ->pluck('sales_count', 'order_items.product_id');

        foreach ($items as $product) {
            $product->setAttribute('sales_count', (int) ($counts[$product->id] ?? 0));
        }
    }

    /**
     * Resolve search query data.
     */
    protected function resolveSearchQueryData($searchEngine): array
    {
        if (request()->query('suggest', '') === '0') {
            return [
                'original_query'  => request()->query('query', ''),
                'effective_query' => null,
            ];
        }

        $originalQuery = request()->query('query', '');

        return [
            'original_query'  => $originalQuery,
            'effective_query' => $this->getEffectiveQuery($originalQuery, $searchEngine),
        ];
    }

    /**
     * It will return the effective query based on the search engine.
     */
    protected function getEffectiveQuery(string $originalQuery, string $searchEngine): ?string
    {
        $effectiveQuery = $this->productRepository->setSearchEngine($searchEngine)->getSuggestions($originalQuery);

        return $effectiveQuery;
    }

    /**
     * Product detail for a single product.
     *
     * @param  int  $id
     */
    public function show(int $id): JsonResource|JsonResponse
    {
        if ($problem = app(SkillMarketService::class)->availability($id)) {
            return $this->fail($problem['code'], $problem['message'], $problem['status']);
        }

        $product = $this->productRepository->with('parent')->findOrFail($id);

        return new ProductResource($product);
    }

    /**
     * Related product listings.
     *
     * @param  int  $id
     */
    public function relatedProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $relatedProducts = $product->related_products()->whereNotIn('products.id', app(SkillMarketService::class)->unavailableProductIds())
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_related_products'))
            ->get();

        return ProductResource::collection($relatedProducts);
    }

    /**
     * Up-sell product listings.
     *
     * @param  int  $id
     */
    public function upSellProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $upSellProducts = $product->up_sells()->whereNotIn('products.id', app(SkillMarketService::class)->unavailableProductIds())
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_up_sells_products'))
            ->get();

        return ProductResource::collection($upSellProducts);
    }

}
