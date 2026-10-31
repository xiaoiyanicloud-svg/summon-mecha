<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductImage;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Http\Resources\ProductResource;
use Webkul\Shop\Models\AccessoryFitment;
use Webkul\Shop\Models\AccessoryProfile;
use Webkul\Shop\Models\DeviceModel;

/**
 * 配件皮肤 · 公开目录接口（P1）。
 *
 * 与 `/api/products?category_id=` 的区别：
 *  - 只返回「配件」分类下、已上架、可单独展示的商品；
 *  - 支持按配件子分类 / 机型 / 适配等级 / 关键词 / 价格排序筛选；
 *  - 卡片 DTO 直接带适配等级、安装方式、交期、官方/定制标记；
 *  - 没有适配数据时返回 `fitment=null`，不编造“适配”。
 *
 * 适配的机型真相源是 `device_model.model_code`，与技能兼容性同一口径。
 */
class AccessoryCatalogController extends APIController
{
    public function __construct(protected ProductRepository $productRepository) {}

    /**
     * 筛选面板候选项：配件子分类 / 机型 / 适配等级。
     */
    public function filters(): JsonResponse
    {
        $parent = $this->accessoryCategory();

        if (! $parent) {
            return response()->json([
                'data' => [
                    'categories' => [],
                    'models'     => $this->models(),
                    'fit_levels' => $this->fitLevels(),
                ],
            ]);
        }

        $children = Category::query()
            ->where('parent_id', $parent->id)
            ->where('status', 1)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $counts = DB::table('product_categories')
            ->join('product_flat', 'product_flat.product_id', '=', 'product_categories.product_id')
            ->whereIn('product_categories.category_id', $children->pluck('id')->all())
            ->where('product_flat.status', 1)
            ->where('product_flat.visible_individually', 1)
            ->where('product_flat.channel', core()->getCurrentChannel()->code)
            ->where('product_flat.locale', core()->getCurrentLocale()->code)
            ->select('product_categories.category_id', DB::raw('COUNT(DISTINCT product_categories.product_id) AS total'))
            ->groupBy('product_categories.category_id')
            ->pluck('total', 'product_categories.category_id');

        return response()->json([
            'data' => [
                'categories' => $children->map(fn (Category $c) => [
                    'code'  => $this->categoryCode($c),
                    'name'  => $c->name,
                    'count' => (int) ($counts[$c->id] ?? 0),
                ])->values(),
                'models'     => $this->models(),
                'fit_levels' => $this->fitLevels(),
            ],
        ]);
    }

    /**
     * 配件搜索。
     *
     * 参数：q / category(子分类 code) / model(device_model.model_code) /
     *      fit(direct|adapter|custom) /
     *      sort(default|rating_desc|sales_desc|price_asc|price_desc|newest) / page / limit
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q'        => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:64'],
            'model'    => ['nullable', 'string', 'max:64'],
            'fit'      => ['nullable', 'string', 'in:direct,adapter,custom'],
            'sort'     => ['nullable', 'string', 'in:default,rating_desc,sales_desc,price_asc,price_desc,newest'],
            'page'     => ['nullable', 'integer', 'min:1', 'max:10000'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $parent = $this->accessoryCategory();

        if (! $parent) {
            return $this->fail(ApiErrorCode::ACCESSORY_UNAVAILABLE, '配件目录暂不可用，请稍后再试。', 503);
        }

        $q        = trim((string) $request->input('q', ''));
        $category = trim((string) $request->input('category', ''));
        $model    = trim((string) $request->input('model', ''));
        $fit      = trim((string) $request->input('fit', ''));
        $sort     = (string) $request->input('sort', 'default');
        $page     = (int) $request->input('page', 1);
        $limit    = (int) $request->input('limit', 12);

        $categoryIds = $this->categoryIds($parent, $category);
        if ($category !== '' && $categoryIds === []) {
            return response()->json([
                'data'       => [],
                'pagination' => ['page' => $page, 'per_page' => $limit, 'has_more' => false],
                'filters'    => $this->filterEcho($q, $category, $model, $fit, $sort),
            ]);
        }

        $query = DB::table('product_flat')
            ->where('product_flat.channel', core()->getCurrentChannel()->code)
            ->where('product_flat.locale', core()->getCurrentLocale()->code)
            ->where('product_flat.status', 1)
            ->where('product_flat.visible_individually', 1)
            ->whereIn('product_flat.product_id', function ($sub) use ($categoryIds) {
                $sub->select('product_id')
                    ->from('product_categories')
                    ->whereIn('category_id', $categoryIds);
            })
            ->select([
                'product_flat.product_id',
                'product_flat.name',
                'product_flat.sku',
                'product_flat.price',
                'product_flat.special_price',
                'product_flat.status',
                'product_flat.short_description',
                'product_flat.featured',
                'product_flat.new',
                'product_flat.created_at',
            ])
            ->selectRaw('COALESCE(NULLIF(product_flat.special_price, 0), product_flat.price) AS effective_price');

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where(function ($sub) use ($escaped) {
                $sub->where('product_flat.name', 'like', '%'.$escaped.'%')
                    ->orWhere('product_flat.sku', 'like', '%'.$escaped.'%');
            });
        }

        if ($model !== '' || $fit !== '') {
            $query->whereIn('product_id', function ($sub) use ($model, $fit) {
                $sub->select('product_id')->from('accessory_fitments');

                if ($model !== '') {
                    $sub->where('model_code', $model);
                }

                $sub->whereIn('fit_level', $fit !== '' ? [$fit] : AccessoryFitment::SELLABLE_LEVELS);
            });
        }

        // 好评与销量都基于真实业务数据聚合，不在前端做“仅当前页”假排序。
        if ($sort === 'rating_desc') {
            $reviews = DB::table('product_reviews')
                ->where('status', 'approved')
                ->selectRaw('product_id, AVG(rating) AS rating_score, COUNT(*) AS review_count')
                ->groupBy('product_id');

            $query->leftJoinSub($reviews, 'review_stats', 'review_stats.product_id', '=', 'product_flat.product_id')
                ->selectRaw('COALESCE(review_stats.rating_score, 0) AS rating_score')
                ->selectRaw('COALESCE(review_stats.review_count, 0) AS review_count');
        }

        if ($sort === 'sales_desc') {
            $sales = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereNotNull('order_items.product_id')
                ->where('orders.status', '<>', 'canceled')
                ->selectRaw(
                    'order_items.product_id, '.
                    'SUM(CASE WHEN COALESCE(order_items.qty_invoiced, 0) - COALESCE(order_items.qty_refunded, 0) > 0 '.
                    'THEN COALESCE(order_items.qty_invoiced, 0) - COALESCE(order_items.qty_refunded, 0) ELSE 0 END) AS sales_count'
                )
                ->groupBy('order_items.product_id');

            $query->leftJoinSub($sales, 'sales_stats', 'sales_stats.product_id', '=', 'product_flat.product_id')
                ->selectRaw('COALESCE(sales_stats.sales_count, 0) AS sales_count');
        }

        // 同商品可能因多分类/多语言出现多行；先按 product_id 收口，再排序分页。
        // 用 distinct 而不是 groupBy：MySQL strict mode 下 groupBy 会要求所有 select 列都在 GROUP BY 中。
        $query->distinct();

        match ($sort) {
            'rating_desc' => $query->orderByDesc('rating_score')
                ->orderByDesc('review_count')
                ->orderByDesc('product_flat.featured')
                ->orderByDesc('product_flat.product_id'),
            'sales_desc' => $query->orderByDesc('sales_count')
                ->orderByDesc('product_flat.featured')
                ->orderByDesc('product_flat.product_id'),
            'price_asc'  => $query->orderBy('effective_price'),
            'price_desc' => $query->orderByDesc('effective_price'),
            'newest'     => $query->orderByDesc('product_flat.created_at')->orderByDesc('product_flat.product_id'),
            default      => $query->orderByDesc('product_flat.featured')->orderByDesc('product_flat.product_id'),
        };

        $rows = $query->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $ids = $rows->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        $data = $this->cardRows($ids, $rows, $model, $parent->id);

        return response()->json([
            'data'       => $data,
            'pagination' => [
                'page'     => $page,
                'per_page' => $limit,
                'has_more' => $hasMore,
            ],
            'filters' => $this->filterEcho($q, $category, $model, $fit, $sort),
            'compatibility_basis' => 'declared_fitment',
        ]);
    }

    /**
     * 配件详情。
     */
    public function show(int $id): JsonResponse
    {
        $parent = $this->accessoryCategory();

        if (! $parent) {
            return $this->fail(ApiErrorCode::ACCESSORY_UNAVAILABLE, '配件目录暂不可用，请稍后再试。', 503);
        }

        $product = $this->productRepository->with(['attribute_values.attribute'])->find($id);

        if (! $product || ! $product->status) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '配件不存在或已下架。', 404);
        }

        $categoryIds = $this->categoryIds($parent, '');
        $inAccessory = DB::table('product_categories')
            ->where('product_id', $id)
            ->whereIn('category_id', $categoryIds)
            ->exists();

        if (! $inAccessory) {
            return $this->fail(ApiErrorCode::ACCESSORY_ORDER_TYPE_MISMATCH, '该商品不是配件。', 404);
        }

        $resource = (new ProductResource($product))->jsonSerialize();
        $profile = AccessoryProfile::query()->where('product_id', $id)->first();

        $fitments = AccessoryFitment::query()
            ->leftJoin('device_model', 'device_model.model_code', '=', 'accessory_fitments.model_code')
            ->where('accessory_fitments.product_id', $id)
            ->orderByRaw("FIELD(accessory_fitments.fit_level, 'direct', 'adapter', 'custom', 'unsupported')")
            ->get([
                'accessory_fitments.id',
                'accessory_fitments.model_code',
                'accessory_fitments.fit_level',
                'accessory_fitments.adapter_sku',
                'accessory_fitments.note',
                'accessory_fitments.source',
                'accessory_fitments.verified_at',
                'device_model.model_name',
                'device_model.brand',
                'device_model.status as model_status',
            ])
            ->map(fn ($row) => [
                'model_code'  => $row->model_code,
                'model_name'  => $row->model_name ?: $row->model_code,
                'brand'       => $row->brand,
                'model_status'=> $row->model_status,
                'fit_level'   => $row->fit_level,
                'label'       => AccessoryFitment::FIT_LABELS[$row->fit_level] ?? $row->fit_level,
                'adapter_sku' => $row->adapter_sku,
                'note'        => $row->note,
                'source'      => $row->source,
                'source_label'=> AccessoryFitment::SOURCE_LABELS[$row->source] ?? $row->source,
                'verified_at' => $row->verified_at,
            ])
            ->values();

        $category = $this->productCategory($id, $parent);
        $pricing = $this->pricing($resource);

        return response()->json([
            'data' => [
                'id'          => (int) $product->id,
                'sku'         => $product->sku,
                'name'        => $product->name,
                'summary'     => $this->summary($profile?->summary, $product->short_description),
                'description_html' => $resource['description'] ?? '',
                'price'       => $pricing['price'],
                'compare_at_price' => $pricing['compare_at_price'],
                'images'      => $this->galleryFromDatabase($id) ?: $this->galleryFromResource($resource),
                'category'    => $category,
                'fitments'    => $fitments,
                'compatibility_note' => $profile?->compatibility_note,
                'profile'     => $this->profileDto($profile),
                'specs'       => $this->specs($resource, $profile),
                'orderable'   => $this->orderable($product, $profile, $pricing),
                'quote_required' => (bool) ($profile?->custom_service),
                'official'    => (bool) ($profile?->official),
                'created_at'  => $product->created_at,
            ],
        ]);
    }

    /** 当前配件根分类（slug=accessory）。 */
    protected function accessoryCategory(): ?Category
    {
        return Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
            ->first();
    }

    /** 根分类 + 指定子分类的 id 列表；category 为空时返回整棵子树。 */
    protected function categoryIds(Category $parent, string $code): array
    {
        if ($code === '') {
            return [$parent->id, ...$parent->children()->where('status', 1)->pluck('id')->all()];
        }

        $child = $parent->children()
            ->where('status', 1)
            ->whereHas('translations', function ($q) use ($code) {
                $q->where('slug', 'accessory-'.$code)->orWhere('slug', $code);
            })
            ->first();

        return $child ? [$child->id] : [];
    }

    protected function categoryCode(Category $category): string
    {
        $slug = (string) ($category->slug ?: '');
        return str_starts_with($slug, 'accessory-') ? substr($slug, strlen('accessory-')) : $slug;
    }

    protected function models(): array
    {
        return DeviceModel::query()
            ->whereIn('status', [DeviceModel::STATUS_ACTIVE, DeviceModel::STATUS_DISPLAY_ONLY])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['model_code', 'model_name', 'brand', 'status'])
            ->map(fn (DeviceModel $m) => [
                'model_code' => $m->model_code,
                'model_name' => $m->model_name,
                'brand'      => $m->brand,
                'status'     => $m->status,
            ])
            ->values()
            ->all();
    }

    protected function fitLevels(): array
    {
        return collect(AccessoryFitment::SELLABLE_LEVELS)
            ->map(fn ($level) => ['value' => $level, 'label' => AccessoryFitment::FIT_LABELS[$level]])
            ->values()
            ->all();
    }

    protected function filterEcho(string $q, string $category, string $model, string $fit, string $sort): array
    {
        return array_filter([
            'q'        => $q !== '' ? $q : null,
            'category' => $category !== '' ? $category : null,
            'model'    => $model !== '' ? $model : null,
            'fit'      => $fit !== '' ? $fit : null,
            'sort'     => $sort !== 'default' ? $sort : null,
        ]);
    }

    /** 批量组装卡片 DTO。 */
    protected function cardRows(array $ids, $rows, string $model, int $parentId): array
    {
        if ($ids === []) {
            return [];
        }

        $images = ProductImage::query()
            ->whereIn('product_id', $ids)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $profiles = AccessoryProfile::query()
            ->whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        $fitments = AccessoryFitment::query()
            ->whereIn('product_id', $ids)
            ->whereIn('fit_level', AccessoryFitment::SELLABLE_LEVELS)
            ->get()
            ->groupBy('product_id');

        $models = DeviceModel::query()
            ->get(['model_code', 'model_name'])
            ->keyBy('model_code');

        $categories = $this->subcategoryMap($ids, $parentId);

        return $rows->map(function ($row) use ($images, $profiles, $fitments, $models, $categories, $model) {
            $id = (int) $row->product_id;
            $profile = $profiles->get($id);
            $productFitments = $fitments->get($id, collect());

            $selected = $model !== ''
                ? $productFitments->firstWhere('model_code', $model)
                : $productFitments->sortBy(fn ($f) => AccessoryFitment::FIT_SORT[$f->fit_level] ?? 9)->first();

            $pricing = $this->pricingFromFlat($row);
            $image = $images->get($id)?->first();

            return [
                'id'        => $id,
                'name'      => $row->name,
                'sku'       => $row->sku,
                'price'     => $pricing['price'],
                'compare_at_price' => $pricing['compare_at_price'],
                'image'     => $image ? $this->imageUrl($image->path) : null,
                'summary'   => $this->summary($profile?->summary, $row->short_description),
                'category'  => $categories[$id] ?? null,
                'fitment'   => $selected ? [
                    'level'      => $selected->fit_level,
                    'label'      => AccessoryFitment::FIT_LABELS[$selected->fit_level] ?? $selected->fit_level,
                    'model_code' => $selected->model_code,
                    'model_name' => $models->get($selected->model_code)?->model_name ?: $selected->model_code,
                    'note'       => $selected->note,
                ] : null,
                'install'   => $profile ? [
                    'method' => $profile->install_method,
                    'label'  => $profile->installLabel(),
                ] : null,
                'delivery'  => $profile && $profile->lead_time_days
                    ? ['days' => $profile->lead_time_days, 'label' => $profile->lead_time_days.' 天内发货']
                    : null,
                'official'  => (bool) ($profile?->official),
                'custom_service' => (bool) ($profile?->custom_service),
                'orderable' => $this->orderableFromFlat($row, $profile, $pricing),
            ];
        })->values()->all();
    }

    /** product_id → 子分类 {code,name}（只取配件子树）。 */
    protected function subcategoryMap(array $ids, ?int $parentId = null): array
    {
        $rows = DB::table('product_categories')
            ->join('categories', 'categories.id', '=', 'product_categories.category_id')
            ->join('category_translations', function ($join) {
                $join->on('category_translations.category_id', '=', 'categories.id')
                    ->where('category_translations.locale', core()->getCurrentLocale()->code);
            })
            ->whereIn('product_categories.product_id', $ids)
            ->when($parentId, fn ($query) => $query->where('categories.parent_id', $parentId))
            ->get(['product_categories.product_id', 'categories.id as category_id', 'category_translations.name', 'category_translations.slug']);

        $map = [];
        foreach ($rows as $row) {
            if (! isset($map[$row->product_id])) {
                $map[$row->product_id] = [
                    'code' => str_starts_with((string) $row->slug, 'accessory-') ? substr($row->slug, strlen('accessory-')) : $row->slug,
                    'name' => $row->name,
                ];
            }
        }

        return $map;
    }

    protected function productCategory(int $id, Category $parent): ?array
    {
        $map = $this->subcategoryMap([$id], $parent->id);
        return $map[$id] ?? null;
    }

    /**
     * 卡片/详情的摘要：档案摘要优先，其次短描述。
     * 过滤纯数字/长度不足的脏数据（如 `<p>1</p>`），不把占位内容当卖点。
     */
    protected function summary(?string $profileSummary, ?string $shortDescription): ?string
    {
        $text = trim((string) $profileSummary);

        if ($text === '') {
            $text = trim(strip_tags((string) $shortDescription));
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        if ($text === '' || mb_strlen($text) < 2 || ctype_digit($text)) {
            return null;
        }

        return mb_substr($text, 0, 160);
    }

    protected function profileDto(?AccessoryProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'install_method'    => $profile->install_method,
            'install_label'     => $profile->installLabel(),
            'package_contents'  => $profile->package_contents,
            'warranty_months'   => $profile->warranty_months,
            'warranty_label'    => $profile->warranty_months ? $profile->warranty_months.' 个月质保' : null,
            'lead_time_days'    => $profile->lead_time_days,
            'lead_time_label'   => $profile->lead_time_days ? $profile->lead_time_days.' 天内发货' : null,
            'material'          => $profile->material,
            'official'          => (bool) $profile->official,
            'custom_service'    => (bool) $profile->custom_service,
            'custom_price_from' => $profile->custom_price_from !== null ? (float) $profile->custom_price_from : null,
            'compatibility_note'=> $profile->compatibility_note,
            'power_requirement' => $profile->power_requirement,
        ];
    }

    protected function specs(array $resource, ?AccessoryProfile $profile): array
    {
        $specs = [];

        foreach (($resource['custom_attributes'] ?? []) as $code => $attr) {
            $skip = ['sku', 'name', 'url_key', 'description', 'short_description', 'price', 'special_price',
                'cost', 'weight', 'status', 'new', 'featured', 'visible_individually', 'guest_checkout',
                'manage_stock', 'tax_category_id', 'product_number'];

            if (in_array($code, $skip, true) || str_starts_with($code, 'meta_')) {
                continue;
            }

            $value = is_array($attr) ? ($attr['value'] ?? null) : null;
            if ($value !== null && trim((string) $value) !== '') {
                $specs[] = ['name' => $attr['name'] ?? $code, 'value' => (string) $value];
            }
        }

        if ($profile?->material) {
            $specs[] = ['name' => '材质 / 工艺', 'value' => $profile->material];
        }
        if ($profile?->install_method) {
            $specs[] = ['name' => '安装方式', 'value' => $profile->installLabel()];
        }
        if ($profile?->warranty_months) {
            $specs[] = ['name' => '质保', 'value' => $profile->warranty_months.' 个月'];
        }
        if ($profile?->lead_time_days) {
            $specs[] = ['name' => '交期', 'value' => $profile->lead_time_days.' 天内发货'];
        }
        if ($profile?->power_requirement) {
            $specs[] = ['name' => '供电 / 接口', 'value' => $profile->power_requirement];
        }

        return $specs;
    }

    protected function pricing(array $resource): array
    {
        $regular = $this->amount($resource['prices']['regular']['price'] ?? null);
        $final = $this->amount($resource['prices']['final']['price'] ?? null);
        $effective = $final ?? $regular ?? 0;
        $hasDiscount = $regular !== null && $effective > 0 && $regular > $effective;

        return [
            'price' => round($effective, 2),
            'compare_at_price' => $hasDiscount ? round($regular, 2) : null,
        ];
    }

    protected function pricingFromFlat($row): array
    {
        $regular = $this->amount($row->price);
        $special = $this->amount($row->special_price);
        $effective = ($special !== null && $special > 0) ? $special : ($regular ?? 0);
        $hasDiscount = $regular !== null && $effective > 0 && $regular > $effective;

        return [
            'price' => round($effective, 2),
            'compare_at_price' => $hasDiscount ? round($regular, 2) : null,
        ];
    }

    protected function amount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[^\d.\-]/', '', (string) $value);

        return $cleaned === '' || ! is_numeric($cleaned) ? null : (float) $cleaned;
    }

    protected function orderable($product, ?AccessoryProfile $profile, array $pricing): bool
    {
        if (! $product->status || $pricing['price'] <= 0) {
            return false;
        }

        // 定制皮肤只走报价流，不在线下单
        return ! ($profile?->custom_service);
    }

    protected function orderableFromFlat($row, ?AccessoryProfile $profile, array $pricing): bool
    {
        return (int) $row->status === 1
            && $pricing['price'] > 0
            && ! ($profile?->custom_service);
    }

    /**
     * 详情图集：直接用 product_images 的原始路径生成 URL。
     * ProductResource 的 cache/{size} 地址在本机/部分部署下可能没有对应路由，
     * 而 /storage/... 是 public 软链，稳定可访问。
     */
    protected function galleryFromDatabase(int $productId): array
    {
        return ProductImage::query()
            ->where('product_id', $productId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductImage $image) => $this->imageUrl($image->path))
            ->filter(fn ($url) => $url && ! str_contains($url, 'product-placeholder'))
            ->values()
            ->all();
    }

    protected function galleryFromResource(array $resource): array
    {
        $images = [];

        foreach (($resource['images'] ?? []) as $image) {
            $url = $image['large_image_url'] ?? $image['medium_image_url'] ?? $image['original_image_url'] ?? $image['url'] ?? null;
            if ($url && ! str_contains((string) $url, 'product-placeholder')) {
                $images[] = $url;
            }
        }

        if ($images === []) {
            $base = $resource['base_image'] ?? [];
            $url = $base['large_image_url'] ?? $base['medium_image_url'] ?? $base['original_image_url'] ?? null;
            if ($url && ! str_contains((string) $url, 'product-placeholder')) {
                $images[] = $url;
            }
        }

        return $images;
    }

    protected function imageUrl(string $path): string
    {
        return Storage::url($path);
    }
}
