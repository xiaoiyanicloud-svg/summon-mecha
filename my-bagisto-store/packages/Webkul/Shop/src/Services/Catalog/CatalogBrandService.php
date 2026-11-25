<?php

namespace Webkul\Shop\Services\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webkul\Attribute\Models\Attribute;
use Webkul\Category\Models\Category;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Shop\Models\CatalogBrand;
use Webkul\Shop\Models\DeviceModel;

/**
 * RaaS 分类与品牌目录核心服务。
 *
 * 承担四件事（单一数据源，避免前后端、Seeder、AdminController、API 重复实现）：
 * 1. 维护 `catalog_brands` 与 Bagisto EAV `brand` 属性选项的双向一致；
 * 2. 幂等安装/对齐 `tob -> (rent, buy)` 与 `skill` / `accessory` 分类树及 `robot_type` 属性；
 * 3. 为列表/聚合接口批量注入真实销量、`brand`、`robot_type` 与 RaaS `raas_meta`；
 * 4. 为前台提供启用的品牌列表与机器人形态字典。
 */
class CatalogBrandService
{
    /** 初始标准 TOP 20 机器人品牌种子（支持后台随时增删改、启停与上传 Logo）。 */
    public static function defaultBrandSeeds(): array
    {
        return [
            ['code' => 'yushu', 'name' => '宇树', 'name_en' => 'Unitree', 'device_brand' => 'unitree', 'aliases' => ['宇树', 'Unitree'], 'is_hot' => true, 'sort_order' => 10],
            ['code' => 'zhiyuan', 'name' => '智元', 'name_en' => 'Agibot', 'device_brand' => 'agibot', 'aliases' => ['智元', 'Agibot'], 'is_hot' => true, 'sort_order' => 20],
            ['code' => 'youbixuan', 'name' => '优必选', 'name_en' => 'UBTECH', 'device_brand' => 'ubtech', 'aliases' => ['优必选', 'UBTECH', 'Walker'], 'is_hot' => true, 'sort_order' => 30],
            ['code' => 'zhongqing', 'name' => '众擎', 'name_en' => 'EngineAI', 'device_brand' => 'engineai', 'aliases' => ['众擎', 'EngineAI'], 'is_hot' => true, 'sort_order' => 40],
            ['code' => 'leju', 'name' => '乐聚', 'name_en' => 'Leju', 'device_brand' => 'leju', 'aliases' => ['乐聚', 'Kuavo'], 'is_hot' => true, 'sort_order' => 50],
            ['code' => 'galaxy', 'name' => '银河通用', 'name_en' => 'Galbot', 'device_brand' => null, 'aliases' => ['银河', '银河通用', 'Galbot'], 'is_hot' => true, 'sort_order' => 60],
            ['code' => 'engine', 'name' => '加速进化', 'name_en' => 'Booster Robotics', 'device_brand' => null, 'aliases' => ['加速进化', 'Booster'], 'is_hot' => true, 'sort_order' => 70],
            ['code' => 'fourier', 'name' => '傅利叶', 'name_en' => 'Fourier', 'device_brand' => 'fourier', 'aliases' => ['傅利叶', 'Fourier'], 'is_hot' => true, 'sort_order' => 80],
            ['code' => 'xiaomi', 'name' => '小米', 'name_en' => 'Xiaomi', 'device_brand' => null, 'aliases' => ['小米', 'CyberOne', 'CyberDog'], 'is_hot' => true, 'sort_order' => 90],
            ['code' => 'figure', 'name' => 'Figure AI', 'name_en' => 'Figure AI', 'device_brand' => 'figure', 'aliases' => ['Figure'], 'is_hot' => true, 'sort_order' => 100],
            ['code' => 'dajiang', 'name' => '大疆', 'name_en' => 'DJI', 'device_brand' => null, 'aliases' => ['大疆', 'DJI'], 'is_hot' => true, 'sort_order' => 110],
            ['code' => 'qinglang', 'name' => '擎朗', 'name_en' => 'KEENON', 'device_brand' => null, 'aliases' => ['擎朗', 'KEENON'], 'is_hot' => true, 'sort_order' => 120],
            ['code' => 'pudu', 'name' => '普渡', 'name_en' => 'Pudu', 'device_brand' => null, 'aliases' => ['普渡', 'Pudu'], 'is_hot' => true, 'sort_order' => 130],
            ['code' => 'gaoxian', 'name' => '高仙', 'name_en' => 'Gaussian', 'device_brand' => null, 'aliases' => ['高仙', 'Gaussian'], 'is_hot' => true, 'sort_order' => 140],
            ['code' => 'yunjii', 'name' => '云迹', 'name_en' => 'Yunji', 'device_brand' => null, 'aliases' => ['云迹', 'Yunji'], 'is_hot' => true, 'sort_order' => 150],
            ['code' => 'estun', 'name' => '埃斯顿', 'name_en' => 'ESTUN', 'device_brand' => null, 'aliases' => ['埃斯顿', 'ESTUN'], 'is_hot' => true, 'sort_order' => 160],
            ['code' => 'inovance', 'name' => '汇川', 'name_en' => 'Inovance', 'device_brand' => null, 'aliases' => ['汇川', 'Inovance'], 'is_hot' => true, 'sort_order' => 170],
            ['code' => 'siasun', 'name' => '新松', 'name_en' => 'SIASUN', 'device_brand' => null, 'aliases' => ['新松', 'SIASUN'], 'is_hot' => true, 'sort_order' => 180],
            ['code' => 'efort', 'name' => '埃夫特', 'name_en' => 'EFORT', 'device_brand' => null, 'aliases' => ['埃夫特', 'EFORT'], 'is_hot' => true, 'sort_order' => 190],
            ['code' => 'jaka', 'name' => '节卡', 'name_en' => 'JAKA', 'device_brand' => null, 'aliases' => ['节卡', 'JAKA'], 'is_hot' => true, 'sort_order' => 200],
        ];
    }

    public static function robotTypeOptions(): array
    {
        $out = [];
        foreach (CatalogBrand::ROBOT_TYPES as $code => $label) {
            $out[] = ['code' => $code, 'label' => $label];
        }

        return $out;
    }

    /** 将单个品牌同步到 Bagisto `brand` EAV 属性选项，使商品编辑表单可直接勾选该品牌。 */
    public function syncAttributeOption(CatalogBrand $brand): ?int
    {
        if (! Schema::hasTable('attributes') || ! Schema::hasTable('attribute_options')) {
            return null;
        }

        $attributeId = (int) DB::table('attributes')->where('code', 'brand')->value('id');
        if (! $attributeId) {
            return null;
        }

        $locales = Schema::hasTable('locales')
            ? DB::table('locales')->pluck('code')->filter()->values()->all()
            : [];
        if ($locales === []) {
            $locales = [app()->getLocale() ?: 'zh_CN'];
        }

        $optionId = $brand->attribute_option_id
            ? (int) DB::table('attribute_options')->where('id', $brand->attribute_option_id)->where('attribute_id', $attributeId)->value('id')
            : 0;

        if (! $optionId) {
            $optionId = (int) DB::table('attribute_options')
                ->where('attribute_id', $attributeId)
                ->where('admin_name', $brand->name)
                ->value('id');
        }

        if ($optionId) {
            DB::table('attribute_options')->where('id', $optionId)->update([
                'admin_name' => $brand->name,
                'sort_order' => (int) $brand->sort_order,
            ]);
        } else {
            $optionId = (int) DB::table('attribute_options')->insertGetId([
                'attribute_id' => $attributeId,
                'admin_name'   => $brand->name,
                'sort_order'   => (int) $brand->sort_order,
            ]);
        }

        if (Schema::hasTable('attribute_option_translations')) {
            foreach ($locales as $locale) {
                $label = ($locale === 'en' && $brand->name_en) ? $brand->name_en : $brand->name;
                DB::table('attribute_option_translations')->updateOrInsert(
                    ['attribute_option_id' => $optionId, 'locale' => $locale],
                    ['label' => $label]
                );
            }
        }

        if ((int) $brand->attribute_option_id !== $optionId) {
            $brand->forceFill(['attribute_option_id' => $optionId])->saveQuietly();
        }

        return $optionId;
    }

    /** 清理品牌对应的 EAV 选项（仅确认无任何商品引用后由控制器删除前调用）。 */
    public function removeSyncedAttributeOption(CatalogBrand $brand): void
    {
        $optionId = (int) ($brand->attribute_option_id ?? 0);
        if (! $optionId || ! Schema::hasTable('attribute_options')) {
            return;
        }

        if (Schema::hasTable('product_attribute_values')) {
            $attrId = (int) DB::table('attributes')->where('code', 'brand')->value('id');
            if ($attrId && DB::table('product_attribute_values')->where('attribute_id', $attrId)->where('integer_value', $optionId)->exists()) {
                return;
            }
        }

        DB::table('attribute_options')->where('id', $optionId)->delete();
    }

    /** 统计品牌在商品属性与机型库中的被引用数，防止误删正在使用的品牌。 */
    public function referenceCounts(CatalogBrand $brand): array
    {
        $products = 0;
        if ($brand->attribute_option_id && Schema::hasTable('product_attribute_values') && Schema::hasTable('attributes')) {
            $attrId = (int) DB::table('attributes')->where('code', 'brand')->value('id');
            if ($attrId) {
                $products = (int) DB::table('product_attribute_values')
                    ->where('attribute_id', $attrId)
                    ->where('integer_value', (int) $brand->attribute_option_id)
                    ->count();
            }
        }

        $models = ($brand->device_brand && Schema::hasTable('device_model'))
            ? (int) DB::table('device_model')->where('brand', $brand->device_brand)->count()
            : 0;

        return ['products' => $products, 'models' => $models];
    }

    /**
     * 幂等安装/对齐 RaaS 分类树（`tob -> (rent, buy)` + `skill` + `accessory`）、
     * `robot_type` EAV 属性以及初始品牌种子。
     */
    public function installRaaSTaxonomy(bool $apply = true): array
    {
        $report = ['apply' => $apply, 'categories' => [], 'attributes' => [], 'brands' => []];

        $root = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'root'))
            ->first() ?? Category::query()->whereNull('parent_id')->orderBy('id')->first();

        if (! $root) {
            $report['error'] = '未找到根分类（root）。';

            return $report;
        }

        $locales = Schema::hasTable('locales')
            ? DB::table('locales')->pluck('code')->filter()->values()->all()
            : ['zh_CN'];
        $categoryRepo = app(CategoryRepository::class);

        $ensureCategory = function (string $slug, string $name, int $parentId, int $position) use ($apply, $categoryRepo, $locales, &$report): ?Category {
            $existing = Category::query()->whereHas('translations', fn ($q) => $q->where('slug', $slug))->first();
            if ($existing) {
                $action = ((int) $existing->parent_id !== $parentId) ? 'would_move' : 'exists';
                if ($apply && (int) $existing->parent_id !== $parentId) {
                    $existing->parent_id = $parentId;
                    $existing->position = $position;
                    $existing->save();
                    $action = 'moved';
                }
                $report['categories'][] = ['slug' => $slug, 'name' => $name, 'parent_id' => $parentId, 'id' => $existing->id, 'action' => $action];

                return $existing;
            }

            if (! $apply) {
                $report['categories'][] = ['slug' => $slug, 'name' => $name, 'parent_id' => $parentId, 'id' => null, 'action' => 'would_create'];

                return null;
            }

            $payload = [
                'parent_id'    => $parentId,
                'status'       => 1,
                'position'     => $position,
                'display_mode' => 'products_and_description',
            ];
            foreach ($locales as $locale) {
                $payload[$locale] = ['name' => $name, 'slug' => $slug, 'url_path' => $slug];
            }
            $created = $categoryRepo->create($payload);
            $report['categories'][] = ['slug' => $slug, 'name' => $name, 'parent_id' => $parentId, 'id' => $created->id, 'action' => 'created'];

            return $created;
        };

        $tob = $ensureCategory('tob', '租售 TOB', (int) $root->id, 1);
        $tobParentId = $tob ? (int) $tob->id : 0;
        $ensureCategory('rent', '租赁', $tobParentId ?: (int) $root->id, 1);
        $ensureCategory('buy', '购买', $tobParentId ?: (int) $root->id, 2);
        $ensureCategory('skill', '技能', (int) $root->id, 2);
        $ensureCategory('accessory', '配件', (int) $root->id, 3);

        if ($apply && method_exists(Category::class, 'fixTree')) {
            Category::fixTree();
        }

        // 确保 robot_type（机器人形态）属性与选项存在并加入默认属性族
        $report['attributes'][] = $this->ensureRobotTypeAttribute($apply, $locales);

        // 幂等补齐初始品牌并同步 brand 属性选项
        if (Schema::hasTable('catalog_brands')) {
            foreach (self::defaultBrandSeeds() as $seed) {
                $existingBrand = CatalogBrand::query()->where('code', $seed['code'])->first();
                if ($existingBrand) {
                    if ($apply) {
                        $this->syncAttributeOption($existingBrand);
                    }
                    $report['brands'][] = ['code' => $seed['code'], 'name' => $seed['name'], 'action' => 'exists'];
                    continue;
                }
                if (! $apply) {
                    $report['brands'][] = ['code' => $seed['code'], 'name' => $seed['name'], 'action' => 'would_create'];
                    continue;
                }
                $brand = CatalogBrand::create($seed + ['status' => true]);
                $this->syncAttributeOption($brand);
                $report['brands'][] = ['code' => $seed['code'], 'name' => $seed['name'], 'action' => 'created'];
            }
        }

        return $report;
    }

    private function ensureRobotTypeAttribute(bool $apply, array $locales): array
    {
        if (! Schema::hasTable('attributes')) {
            return ['code' => 'robot_type', 'action' => 'skipped'];
        }

        $attr = DB::table('attributes')->where('code', 'robot_type')->first();
        if (! $attr && ! $apply) {
            return ['code' => 'robot_type', 'action' => 'would_create'];
        }

        $action = $attr ? 'exists' : 'created';
        if (! $attr) {
            $attrId = (int) DB::table('attributes')->insertGetId([
                'code'                => 'robot_type',
                'admin_name'          => '机器人形态',
                'type'                => 'select',
                'is_required'         => 0,
                'is_unique'           => 0,
                'is_filterable'       => 1,
                'is_configurable'     => 0,
                'is_user_defined'     => 1,
                'is_visible_on_front' => 1,
                'use_in_flat'         => 1,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            if (Schema::hasTable('attribute_translations')) {
                foreach ($locales as $locale) {
                    DB::table('attribute_translations')->updateOrInsert(
                        ['attribute_id' => $attrId, 'locale' => $locale],
                        ['name' => $locale === 'en' ? 'Robot Type' : '机器人形态']
                    );
                }
            }
        } else {
            $attrId = (int) $attr->id;
        }

        // 挂载到首个可用属性族的首个分组，让新建/编辑机器商品页直接可见
        if (Schema::hasTable('attribute_groups') && Schema::hasTable('attribute_group_mappings')) {
            $familyId = Schema::hasTable('attribute_families')
                ? ((int) DB::table('attribute_families')->orderBy('id')->value('id') ?: 1)
                : 1;
            $groupId = (int) DB::table('attribute_groups')
                ->where('attribute_family_id', $familyId)
                ->orderBy('position')
                ->orderBy('id')
                ->value('id');
            if ($groupId && ! DB::table('attribute_group_mappings')->where('attribute_id', $attrId)->exists()) {
                $maxPos = (int) DB::table('attribute_group_mappings')->where('attribute_group_id', $groupId)->max('position');
                DB::table('attribute_group_mappings')->insert([
                    'attribute_id'       => $attrId,
                    'attribute_group_id' => $groupId,
                    'position'           => $maxPos + 1,
                ]);
            }
        }

        $pos = 1;
        foreach (CatalogBrand::ROBOT_TYPES as $code => $label) {
            $optId = (int) DB::table('attribute_options')
                ->where('attribute_id', $attrId)
                ->where('admin_name', $label)
                ->value('id');
            if (! $optId) {
                $optId = (int) DB::table('attribute_options')->insertGetId([
                    'attribute_id' => $attrId,
                    'admin_name'   => $label,
                    'sort_order'   => $pos,
                ]);
            }
            if (Schema::hasTable('attribute_option_translations')) {
                foreach ($locales as $locale) {
                    DB::table('attribute_option_translations')->updateOrInsert(
                        ['attribute_option_id' => $optId, 'locale' => $locale],
                        ['label' => $locale === 'en' ? ucfirst($code) : $label]
                    );
                }
            }
            $pos++;
        }

        return ['code' => 'robot_type', 'id' => $attrId, 'action' => $action];
    }

    /** 给商品列表批量补充真实销量（已开票 - 已退款，排除取消订单），供「销量优先」排序使用。 */
    public function attachSalesCounts($products): void
    {
        $items = method_exists($products, 'getCollection') ? $products->getCollection() : collect($products);
        $ids = $items->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === [] || ! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
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
     * 批量解析并注入每个商品的结构化品牌、形态与 RaaS 能力摘要，避免 ProductResource N+1。
     */
    public function attachCatalogMeta($products): void
    {
        $items = method_exists($products, 'getCollection') ? $products->getCollection() : collect($products);
        if ($items->isEmpty()) {
            return;
        }

        $this->attachSalesCounts($items);

        $ids = $items->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $brands = $this->loadBrandCollection(includeDisabled: true);
        $deviceModelsByBrand = $this->loadDeviceModelsByBrand();
        $deviceModelsByCode = $deviceModelsByBrand->flatten(1)->keyBy('model_code');
        $merchantExts = ($ids !== [] && Schema::hasTable('merchant_product_exts'))
            ? DB::table('merchant_product_exts')->whereIn('product_id', $ids)->get()->keyBy('product_id')
            : collect();
        $rentalPlansByProduct = ($ids !== [] && Schema::hasTable('merchant_rental_plans'))
            ? DB::table('merchant_rental_plans')
                ->whereIn('product_id', $ids)
                ->where('enabled', 1)
                ->orderBy('min_days')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
            : collect();

        $optionValues = $this->loadSelectOptionIds($ids, ['brand', 'robot_type']);

        foreach ($items as $product) {
            $pid = (int) $product->id;
            $rawBrandOptId = $optionValues[$pid]['brand']['option_id'] ?? null;
            $rawBrandLabel = $optionValues[$pid]['brand']['label'] ?? null;
            $brand = $this->matchBrand($brands, $rawBrandOptId, $rawBrandLabel, (string) ($product->name ?? ''));

            $rawTypeOpt = $optionValues[$pid]['robot_type']['label'] ?? null;
            $robotType = $this->matchRobotType($rawTypeOpt, (string) ($product->name ?? ''));

            $ext = $merchantExts->get($pid);
            $models = ($brand && $brand->device_brand) ? $deviceModelsByBrand->get($brand->device_brand, collect()) : collect();
            $plans = $rentalPlansByProduct->get($pid, collect());

            $product->setAttribute('resolved_brand', $brand ? $this->serializeBrand($brand, $models) : null);
            $product->setAttribute('resolved_robot_type', $robotType);
            $product->setAttribute('resolved_raas_meta', $this->buildRaasMeta($ext, $models, $deviceModelsByCode, $plans));
        }
    }

    /**
     * 解析买家侧 `/api/products` 的扩展查询参数：
     * - `category_slug` (`tob` 展开为 `tob,rent,buy`；其它 slug 映射到对应 `category_id`)
     * - `brand`（如传编码 `yushu` 自动转换成对应的 `brand` 属性选项 ID）
     * - `robot_type`（如传形态编码 `humanoid` 自动转换成 `robot_type` 属性选项 ID）
     */
    public function resolveProductQueryParams(array $query): array
    {
        $resolved = $query;

        $slug = trim((string) ($query['category_slug'] ?? ''));
        if ($slug !== '' && empty($query['category_id']) && Schema::hasTable('category_translations')) {
            $slugs = $slug === 'tob' ? ['tob', 'rent', 'buy'] : [$slug];
            $catIds = DB::table('category_translations')
                ->whereIn('slug', $slugs)
                ->distinct()
                ->pluck('category_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            if ($catIds === []) {
                $resolved['__force_empty'] = true;
            } else {
                $resolved['category_id'] = implode(',', $catIds);
            }
        }
        unset($resolved['category_slug']);

        if (isset($query['brand']) && (string) $query['brand'] !== '') {
            $rawBrand = trim((string) $query['brand']);
            if (! ctype_digit($rawBrand) && ! str_contains($rawBrand, ',')) {
                $optionId = Schema::hasTable('catalog_brands')
                    ? (int) DB::table('catalog_brands')->where('code', strtolower($rawBrand))->value('attribute_option_id')
                    : 0;
                if (! $optionId && Schema::hasTable('attributes') && Schema::hasTable('attribute_options')) {
                    $brandAttrId = (int) DB::table('attributes')->where('code', 'brand')->value('id');
                    $seedName = collect(self::defaultBrandSeeds())->firstWhere('code', strtolower($rawBrand))['name'] ?? null;
                    if ($brandAttrId && $seedName) {
                        $optionId = (int) DB::table('attribute_options')
                            ->where('attribute_id', $brandAttrId)
                            ->where('admin_name', $seedName)
                            ->value('id');
                    }
                }
                if ($optionId > 0) {
                    $resolved['brand'] = (string) $optionId;
                } else {
                    $resolved['__force_empty'] = true;
                }
            }
        }

        if (isset($query['robot_type']) && (string) $query['robot_type'] !== '') {
            $rawType = trim((string) $query['robot_type']);
            if (! ctype_digit($rawType) && ! str_contains($rawType, ',')) {
                $label = CatalogBrand::ROBOT_TYPES[strtolower($rawType)] ?? null;
                $typeOptionId = 0;
                if ($label && Schema::hasTable('attributes') && Schema::hasTable('attribute_options')) {
                    $typeAttrId = (int) DB::table('attributes')->where('code', 'robot_type')->value('id');
                    if ($typeAttrId) {
                        $typeOptionId = (int) DB::table('attribute_options')
                            ->where('attribute_id', $typeAttrId)
                            ->where('admin_name', $label)
                            ->value('id');
                    }
                }
                if ($typeOptionId > 0) {
                    $resolved['robot_type'] = (string) $typeOptionId;
                } else {
                    $resolved['__force_empty'] = true;
                }
            }
        }

        return $resolved;
    }

    /**
     * 为指定商品写入结构化的 `brand` / `robot_type` EAV 属性值（供商家建品与回扫脚本复用）。
     */
    public function assignProductAttributes(
        int $productId,
        ?string $brandCode = null,
        ?string $robotTypeCode = null,
        bool $onlyIfEmpty = false
    ): array {
        $result = ['brand_assigned' => false, 'robot_type_assigned' => false, 'brand' => null, 'robot_type' => null];
        if (! Schema::hasTable('product_attribute_values') || ! Schema::hasTable('attributes')) {
            return $result;
        }

        if ($brandCode !== null && trim($brandCode) !== '') {
            $normCode = strtolower(trim($brandCode));
            $brand = Schema::hasTable('catalog_brands')
                ? CatalogBrand::query()->where('code', $normCode)->first()
                : null;
            if ($brand) {
                $optId = $this->syncAttributeOption($brand) ?: (int) $brand->attribute_option_id;
                $brandAttrId = (int) DB::table('attributes')->where('code', 'brand')->value('id');
                if ($brandAttrId && $optId > 0) {
                    $written = $this->upsertProductSelectAttribute($productId, $brandAttrId, $optId, 'brand', $onlyIfEmpty);
                    $result['brand_assigned'] = $written;
                    $result['brand'] = ['code' => $brand->code, 'name' => $brand->name, 'option_id' => $optId];
                }
            }
        }

        if ($robotTypeCode !== null && trim($robotTypeCode) !== '') {
            $normType = strtolower(trim($robotTypeCode));
            $label = CatalogBrand::ROBOT_TYPES[$normType] ?? null;
            $typeAttrId = (int) DB::table('attributes')->where('code', 'robot_type')->value('id');
            if ($label && $typeAttrId && Schema::hasTable('attribute_options')) {
                $optId = (int) DB::table('attribute_options')
                    ->where('attribute_id', $typeAttrId)
                    ->where('admin_name', $label)
                    ->value('id');
                if ($optId > 0) {
                    $written = $this->upsertProductSelectAttribute($productId, $typeAttrId, $optId, 'robot_type', $onlyIfEmpty);
                    $result['robot_type_assigned'] = $written;
                    $result['robot_type'] = ['code' => $normType, 'label' => $label, 'option_id' => $optId];
                }
            }
        }

        return $result;
    }

    /**
     * 幂等回扫尚未填写 `brand`/`robot_type` EAV 属性的存量商品（永不覆盖人工已指定值）。
     */
    public function backfillProductAttributes(bool $apply = true): array
    {
        $summary = ['apply' => $apply, 'scanned' => 0, 'brand_matched' => 0, 'type_matched' => 0, 'updated' => 0];
        if (! Schema::hasTable('products')) {
            return $summary;
        }

        $brands = $this->loadBrandCollection(includeDisabled: true);
        if ($apply) {
            foreach ($brands as $b) {
                if ($b->exists) {
                    $this->syncAttributeOption($b);
                }
            }
        }

        $nameExpr = Schema::hasTable('product_flat')
            ? DB::table('products as p')
                ->leftJoin('product_flat as pf', 'pf.product_id', '=', 'p.id')
                ->select('p.id', DB::raw('MAX(pf.name) as name'))
                ->groupBy('p.id')
                ->get()
            : DB::table('products')->select('id', 'name')->get();

        $ids = $nameExpr->pluck('id')->map(fn ($id) => (int) $id)->all();
        $existing = $this->loadSelectOptionIds($ids, ['brand', 'robot_type']);

        foreach ($nameExpr as $row) {
            $pid = (int) $row->id;
            $summary['scanned']++;
            $hasBrand = ! empty($existing[$pid]['brand']['option_id']);
            $hasType = ! empty($existing[$pid]['robot_type']['option_id']);
            $name = (string) ($row->name ?? '');

            $matchedBrand = ! $hasBrand ? $this->matchBrand($brands, null, null, $name) : null;
            $matchedType = ! $hasType ? $this->matchRobotType(null, $name) : null;

            if ($matchedBrand) {
                $summary['brand_matched']++;
            }
            if ($matchedType) {
                $summary['type_matched']++;
            }

            if ($apply && ($matchedBrand || $matchedType)) {
                $res = $this->assignProductAttributes(
                    $pid,
                    $matchedBrand?->code,
                    $matchedType['code'] ?? null,
                    onlyIfEmpty: true
                );
                if ($res['brand_assigned'] || $res['robot_type_assigned']) {
                    $summary['updated']++;
                }
            }
        }

        return $summary;
    }

    /** 公开前台接口用：返回所有启用的品牌（按 sort_order 升序）及标准形态目录。 */
    public function publicMetadata(): array
    {
        $brands = $this->loadBrandCollection(includeDisabled: false);
        $modelsByBrand = $this->loadDeviceModelsByBrand();

        return [
            'brands' => $brands->map(function ($b) use ($modelsByBrand) {
                $models = ! empty($b->device_brand) ? $modelsByBrand->get($b->device_brand, collect()) : collect();

                return $this->serializeBrand($b, $models);
            })->values()->all(),
            'robot_types' => self::robotTypeOptions(),
        ];
    }

    private function loadBrandCollection(bool $includeDisabled): Collection
    {
        if (Schema::hasTable('catalog_brands')) {
            $total = CatalogBrand::query()->count();
            if ($total > 0) {
                $q = CatalogBrand::query()->orderBy('sort_order')->orderBy('id');
                if (! $includeDisabled) {
                    $q->where('status', true);
                }

                return $q->get();
            }
        }

        // 未执行品牌种子时的兼容兜底（保持现有 TOP 20 品牌可用）
        return collect(self::defaultBrandSeeds())->map(fn ($row) => new CatalogBrand($row + ['status' => true]));
    }

    private function loadDeviceModelsByBrand(): Collection
    {
        if (! Schema::hasTable('device_model')) {
            return collect();
        }

        return DB::table('device_model')
            ->select('brand', 'model_code', 'model_name', 'degrees_of_freedom', 'compute_platform', 'comm_channels', 'status')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('brand');
    }

    private function loadSelectOptionIds(array $productIds, array $codes): array
    {
        if ($productIds === [] || ! Schema::hasTable('product_attribute_values') || ! Schema::hasTable('attributes')) {
            return [];
        }

        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->leftJoin('attribute_options as ao', 'ao.id', '=', 'pav.integer_value')
            ->whereIn('pav.product_id', $productIds)
            ->whereIn('a.code', $codes)
            ->select('pav.product_id', 'a.code', 'pav.integer_value', 'pav.text_value', 'ao.admin_name as option_label')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->product_id][$row->code] = [
                'option_id' => $row->integer_value ? (int) $row->integer_value : null,
                'label'     => $row->option_label ?: ($row->text_value ?: null),
            ];
        }

        return $out;
    }

    private function matchBrand(Collection $brands, ?int $optionId, ?string $rawLabel, string $productName): ?CatalogBrand
    {
        if ($optionId) {
            $byOpt = $brands->firstWhere('attribute_option_id', $optionId);
            if ($byOpt) {
                return $byOpt;
            }
        }

        if ($rawLabel) {
            $needle = mb_strtolower(trim($rawLabel));
            $byLabel = $brands->first(fn (CatalogBrand $b) => mb_strtolower($b->name) === $needle
                || mb_strtolower((string) $b->name_en) === $needle
                || mb_strtolower($b->code) === $needle);
            if ($byLabel) {
                return $byLabel;
            }
        }

        foreach ($brands as $brand) {
            if (! $brand->status) {
                continue;
            }
            $keywords = array_filter(array_unique(array_merge([$brand->name, $brand->name_en], (array) $brand->aliases)));
            foreach ($keywords as $kw) {
                if ($kw !== '' && mb_stripos($productName, (string) $kw) !== false) {
                    return $brand;
                }
            }
        }

        return null;
    }

    private function matchRobotType(?string $rawLabel, string $productName): ?array
    {
        if ($rawLabel) {
            foreach (CatalogBrand::ROBOT_TYPES as $code => $label) {
                if ($rawLabel === $label || mb_strtolower($rawLabel) === $code) {
                    return ['code' => $code, 'label' => $label];
                }
            }
        }

        $patterns = [
            'humanoid'   => '/人形|仿生|humanoid|Walker|远征|CyberOne|Figure/iu',
            'quadruped'  => '/四足|机器狗|Go1|Go2|CyberDog|绝影/iu',
            'delivery'   => '/配送|送餐|传菜|欢乐送|贝拉|闪电|T3|T5/iu',
            'cleaning'   => '/清洁|扫地|洗地|高仙/iu',
            'reception'  => '/迎宾|接待|讲解|导览|Cruzr/iu',
            'inspection' => '/巡检|巡逻|巡查|M300|M30T|无人机/iu',
            'arm'        => '/机械臂|机械手|ER\d|IR\d|SR\d/iu',
            'companion'  => '/陪伴|教育|Aelos|Roban/iu',
        ];
        foreach ($patterns as $code => $regex) {
            if (preg_match($regex, $productName)) {
                return ['code' => $code, 'label' => CatalogBrand::ROBOT_TYPES[$code]];
            }
        }

        return null;
    }

    private function serializeBrand(CatalogBrand $brand, Collection $models): array
    {
        $activeModels = $models->where('status', DeviceModel::STATUS_ACTIVE);
        $channels = $activeModels
            ->flatMap(fn ($m) => json_decode((string) ($m->comm_channels ?? '[]'), true) ?: [])
            ->unique()->values()->all();

        return [
            'id'              => $brand->id ?: null,
            'code'            => $brand->code,
            'name'            => $brand->name,
            'name_en'         => $brand->name_en,
            'aliases'         => array_values((array) ($brand->aliases ?? [])),
            'device_brand'    => $brand->device_brand,
            'logo_url'        => $brand->logo_path ? Storage::url($brand->logo_path) : null,
            'description'     => $brand->description,
            'official_url'    => $brand->official_url,
            'is_hot'          => (bool) $brand->is_hot,
            'status'          => (bool) $brand->status,
            'sort_order'      => (int) $brand->sort_order,
            'models_count'    => $models->count(),
            'active_models'   => $activeModels->count(),
            'comm_channels'   => $channels,
        ];
    }

    private function upsertProductSelectAttribute(
        int $productId,
        int $attributeId,
        int $optionId,
        string $code,
        bool $onlyIfEmpty
    ): bool {
        $existing = DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->where('attribute_id', $attributeId)
            ->first();

        if ($onlyIfEmpty && $existing && ! empty($existing->integer_value)) {
            return false;
        }

        if ($existing) {
            DB::table('product_attribute_values')
                ->where('id', $existing->id)
                ->update(['integer_value' => $optionId]);
        } else {
            $payload = [
                'product_id'    => $productId,
                'attribute_id'  => $attributeId,
                'integer_value' => $optionId,
            ];
            if (Schema::hasColumn('product_attribute_values', 'channel')) {
                $payload['channel'] = null;
            }
            if (Schema::hasColumn('product_attribute_values', 'locale')) {
                $payload['locale'] = null;
            }
            if (Schema::hasColumn('product_attribute_values', 'unique_id')) {
                $payload['unique_id'] = $productId.'|'.$attributeId;
            }
            DB::table('product_attribute_values')->insert($payload);
        }

        if (Schema::hasTable('product_flat') && Schema::hasColumn('product_flat', $code)) {
            DB::table('product_flat')->where('product_id', $productId)->update([$code => $optionId]);
        }

        return true;
    }

    private function buildRaasMeta(
        ?object $ext,
        Collection $models,
        ?Collection $deviceModelsByCode = null,
        ?Collection $rentalPlans = null
    ): ?array {
        $explicitModels = collect();
        if ($ext && ! empty($ext->compatible_models) && $deviceModelsByCode) {
            $codes = is_array($ext->compatible_models)
                ? $ext->compatible_models
                : (json_decode((string) $ext->compatible_models, true) ?: []);
            foreach ($codes as $mc) {
                if ($dm = $deviceModelsByCode->get((string) $mc)) {
                    $explicitModels->push($dm);
                }
            }
        }

        $mergedModels = $explicitModels->isNotEmpty()
            ? $explicitModels
            : $models;
        $plans = $rentalPlans ?? collect();

        if (! $ext && $mergedModels->isEmpty() && $plans->isEmpty()) {
            return null;
        }

        $activeModels = $mergedModels->where('status', DeviceModel::STATUS_ACTIVE);
        $channels = $activeModels
            ->flatMap(fn ($m) => json_decode((string) ($m->comm_channels ?? '[]'), true) ?: [])
            ->unique()->values()->all();

        $paramSpecs = [];
        if ($ext && ! empty($ext->param_specs)) {
            $rawSpecs = is_array($ext->param_specs)
                ? $ext->param_specs
                : (json_decode((string) $ext->param_specs, true) ?: []);
            foreach ($rawSpecs as $k => $v) {
                if (is_array($v) && ! empty($v['name']) && isset($v['value']) && trim((string) $v['value']) !== '') {
                    $paramSpecs[] = ['name' => trim((string) $v['name']), 'value' => trim((string) $v['value'])];
                } elseif (is_string($k) && $k !== '' && (is_scalar($v) && trim((string) $v) !== '')) {
                    $paramSpecs[] = ['name' => $k, 'value' => trim((string) $v)];
                }
            }
        }

        $planSummaries = $plans->map(fn ($rp) => [
            'name'            => (string) $rp->name,
            'period_unit'     => (string) $rp->period_unit,
            'min_days'        => (int) $rp->min_days,
            'max_days'        => (int) $rp->max_days,
            'base_price_yuan' => number_format(((int) $rp->base_price_cents) / 100, 2, '.', ''),
        ])->values()->all();

        $effectiveMinDays = $ext ? (int) ($ext->min_rent_days ?? 1) : 1;
        if ($plans->isNotEmpty()) {
            $planMin = (int) $plans->min('min_days');
            if ($planMin > $effectiveMinDays) {
                $effectiveMinDays = $planMin;
            }
        }

        return [
            'fulfillment_type'       => $ext ? (string) $ext->fulfillment_type : null,
            'daily_rent_yuan'        => ($ext && $ext->daily_rent_cents > 0) ? number_format($ext->daily_rent_cents / 100, 2, '.', '') : null,
            'deposit_yuan'           => ($ext && $ext->deposit_cents > 0) ? number_format($ext->deposit_cents / 100, 2, '.', '') : null,
            'min_rent_days'          => $ext || $plans->isNotEmpty() ? $effectiveMinDays : null,
            'rentable_stock'         => $ext ? (int) ($ext->rentable_stock ?? 0) : null,
            'sellable_stock'         => $ext ? (int) ($ext->sellable_stock ?? 0) : null,
            'models_count'           => $mergedModels->count(),
            'active_models'          => $activeModels->count(),
            'compatible_model_names' => $activeModels->pluck('model_name')->values()->all(),
            'comm_channels'          => $channels,
            'param_specs'            => $paramSpecs,
            'rental_plans'           => $planSummaries,
        ];
    }
}
