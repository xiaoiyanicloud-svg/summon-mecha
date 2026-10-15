<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Marketing\Jobs\UpdateCreateSearchTerm as UpdateCreateSearchTermJob;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Webkul\Shop\Services\Deploy\KillSwitchService;

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

        if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
            $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
        }

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
                'exclude_ids'          => app(KillSwitchService::class)->saleBlockedSkillIds(),
            ]));

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
        // S-8 · 熔断即下架:被熔断的技能详情对买家**明确报错**(409 + SKILL_BLOCKED),
        //   与 `skill-catalog/versions` **同一语义** —— 不静默 404(静默消失会让用户以为是
        //   网络或平台坏了,而这是平台的有意动作)。熔断只可能命中技能商品(见列注释),故按 id 判即可。
        if (app(KillSwitchService::class)->isBlockedFor($id, 'sale')) {
            return $this->fail(ApiErrorCode::SKILL_BLOCKED, '该技能已被平台下架,暂不可查看。', 409);
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

        $relatedProducts = $product->related_products()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_related_products'))
            ->get();

        return ProductResource::collection($this->excludeBlockedSkills($relatedProducts));
    }

    /**
     * Up-sell product listings.
     *
     * @param  int  $id
     */
    public function upSellProducts($id): JsonResource
    {
        $product = $this->productRepository->findOrFail($id);

        $upSellProducts = $product->up_sells()
            ->take(core()->getConfigData('catalog.products.product_view_page.no_of_up_sells_products'))
            ->get();

        return ProductResource::collection($this->excludeBlockedSkills($upSellProducts));
    }

    /**
     * 从商品集合里剔除被熔断的技能(S-8 · 熔断即下架)。
     *
     * 关联位 / 搭售位也是买家侧展示面,不得露出被熔断的技能。规则复用
     * `KillSwitchService::saleBlockedSkillIds()`(唯一读取入口),不在这里另判强度。
     *
     * @param  \Illuminate\Support\Collection  $products
     * @return \Illuminate\Support\Collection
     */
    protected function excludeBlockedSkills($products)
    {
        $blocked = app(KillSwitchService::class)->saleBlockedSkillIds();

        if (empty($blocked)) {
            return $products;
        }

        return collect($products)
            ->reject(fn ($p) => in_array((int) $p->id, $blocked, true))
            ->values();
    }
}
