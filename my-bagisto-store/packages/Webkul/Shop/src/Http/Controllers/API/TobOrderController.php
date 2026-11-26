<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Services\Billing\SkillCouponService;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;

/**
 * TOB 统一下单接口(新增,不改动现有 onepage/后台逻辑)。
 *
 * 背景:Bagisto 原生 checkout(onepage)依赖 购物车 + 配送方式 + 支付方式 全链路,
 * 任一项未启用都会导致下单失败。本项目所有 TOB 订单(租/购/方案/定制)都要求落进
 * 后台订单,故本控制器直接调用 OrderRepository::create() 生成真实 Sales 订单:
 *   - 不依赖购物车 / 配送 / 支付配置;
 *   - 支持未登录(游客)下单(customer_id 为空);
 *   - 租用按「日租价 × 数量 × 天数」计价;类型/租期写进订单条目名称(后台可见)。
 *
 * 多机合单:场景租用一次选多台 → 前端传 items 数组,一单多条目,全场共用租期;
 * 单商品走顶层参数老路径,两条路径在内部归一化成同一份「行」结构后统一建单。
 *
 * 注意:Bagisto orders 表无 additional 列,故不做迁移,类型信息放在条目名称里。
 */
class TobOrderController extends APIController
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected OrderRepository $orderRepository,
        protected SkillCouponService $couponService
    ) {}

    /**
     * 创建 TOB 订单。
     */
    public function store(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', 'rent');
        $all = $request->all();
        $itemsInput = $all['items'] ?? $request->input('items');
        $hasItems = is_array($itemsInput) && ! empty($itemsInput);

        if (in_array($type, ['custom', 'plan'], true)) {
            $candidateIds = array_values(array_unique(array_filter(array_merge(
                [(int) $request->input('product_id', 0)],
                $hasItems ? array_map(fn ($it) => is_array($it) ? (int) ($it['product_id'] ?? 0) : 0, $itemsInput) : []
            ))));
            foreach ($candidateIds as $checkId) {
                if (app(\Webkul\Shop\Services\SkillDelivery\SkillMarketService::class)->isSkill($checkId)) {
                    return $this->fail(ApiErrorCode::SKILL_ORDER_TYPE_MISMATCH, '技能商品请通过技能详情页购买。', 409);
                }
            }
        }

        // 旧 custom 只适配成独立需求，永不创建零元订单；旧客户端必须展示需求回执。
        if ($request->input('type') === 'custom') {
            return app(SceneWorkflowController::class)->store($this->adaptLegacyCustom($request), app(\Webkul\Shop\Services\Scenes\SceneRequestService::class));
        }

        // SC3 报价履约专用元数据只能由 SceneOrderConversionService 写入，严禁客户端直接伪造透传。
        $hasQuoteToken = $request->filled('scene_quote_id')
            || $request->filled('additional.scene')
            || collect(is_array($itemsInput) ? $itemsInput : [])->contains(
                fn ($line) => is_array($line) && (! empty($line['scene_quote_id']) || ! empty($line['additional']['scene']))
            );
        $isEmptyPlanRequest = $request->input('type') === 'plan' && ! $request->filled('product_id') && ! $hasItems;
        if ($hasQuoteToken || $isEmptyPlanRequest) {
            return response()->json(['code' => 'SCENE_QUOTE_REQUIRED', 'message' => '场景定制及正式报价履约请先提交需求并确认正式报价。'], 409);
        }

        return DB::transaction(fn () => $this->storeWithinTransaction($request));
    }

    private function adaptLegacyCustom(Request $request): Request
    {
        // 幂等键与高熵访问凭证为必需；不把旧 X-Guest-Key 当作授权。
        $request->merge([
            'original_text' => $request->input('demand') ?: $request->input('product_name', '定制咨询'),
            'contact' => ['name' => $request->input('name'), 'phone' => $request->input('phone'),
                'company' => $request->input('company'), 'wechat' => $request->input('wechat')],
            'facts' => $request->input('facts', []), 'source' => 'legacy_custom',
        ]);

        return $request;
    }

    protected function storeWithinTransaction(Request $request): JsonResponse
    {
        // 客户联系方式和原始需求不写入请求日志。

        $type = (string) $request->input('type');
        $isCustom = $type === 'custom';
        $isRentalFlow = in_array($type, ['rent', 'plan'], true);

        // 多机合单:items 数组(场景租用/+方案一次选多台),否则走单商品老路径
        // 用 merge 后的 all() 取 items(兼容 input() 对嵌套数组的剥离,避免多机被误判为单商品导致 422)
        $all = $request->all();
        $itemsInput = $all['items'] ?? $request->input('items');
        $isMulti = is_array($itemsInput) && ! empty($itemsInput);

        $request->validate([
            // product_id 必须指向后台真实存在的商品,否则订单无法关联商品(会破坏后台订单页渲染)
            // 例外:custom 定制线索挂锚点商品;多机合单逐行校验 items.*.product_id
            'product_id' => (($isCustom || $isMulti) ? 'nullable' : 'required').'|integer|exists:products,id',
            'qty' => ($isMulti ? 'nullable' : 'required').'|integer|min:1|max:999',
            'type' => 'required|in:rent,buy,plan,custom,skill,accessory',
            'items' => $isMulti ? 'required|array|min:1|max:50' : 'nullable',
            'items.*.product_id' => ($isMulti ? 'required' : 'nullable').'|integer|exists:products,id',
            'items.*.qty' => ($isMulti ? 'required' : 'nullable').'|integer|min:1|max:999',
            // M1:成交价不能无界。下限 0(0 表示"用服务端目录价",前端既有契约),
            // 上限挡住天文数字;负数(如 -1)被 min:0 直接拒。
            'items.*.price' => 'nullable|numeric|min:0|max:10000000',
            // 租期加上限,防 rent_days=99999 撑出天价或压出天价
            'items.*.rent_days' => 'nullable|integer|min:1|max:3650',
            'scene_id' => 'nullable|integer|min:1|max:4294967295',
            'scene_revision_id' => 'nullable|integer|min:1|max:4294967295',
            'from' => 'nullable|in:scene,custom_plan',
            'name' => 'required|string|max:255',
            'product_name' => 'nullable|string',
            'sku' => 'nullable|string',
            'price' => 'nullable|numeric|min:0|max:10000000',
            'rent_days' => 'nullable|integer|min:1|max:3650',
            'startDate' => 'nullable|string|max:20',
            'endDate' => 'nullable|string|max:20',
            'phone' => 'required|string',
            'city' => ($isCustom ? 'nullable' : 'required').'|string',
            'address' => ($isCustom ? 'nullable' : 'required').'|string',
            // B-18:技能单优惠码(仅技能单可带,见下方处理)。规则校验不写在这里 ——
            // 券的有效性要读库(券/规则/渠道/客群/用量),那是 SkillCouponService 的职责。
            'coupon_code' => 'nullable|string|max:64',
        ]);

        $startDate = trim((string) $request->input('startDate', ''));
        $endDate = trim((string) $request->input('endDate', ''));
        $parsedStart = null;
        $parsedEnd = null;
        if ($isRentalFlow && $startDate !== '') {
            try {
                $parsedStart = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $startDate);
            } catch (\Throwable) {
                $parsedStart = null;
            }
            if (! $parsedStart || $parsedStart->format('Y-m-d') !== $startDate) {
                return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '起租日期格式无效，请重新选择日期。', 422);
            }
            if ($endDate !== '') {
                try {
                    $parsedEnd = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $endDate);
                } catch (\Throwable) {
                    $parsedEnd = null;
                }
                if (! $parsedEnd || $parsedEnd->format('Y-m-d') !== $endDate) {
                    return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '结束日期格式无效，请重新选择日期。', 422);
                }
                if ($parsedEnd->lt($parsedStart)) {
                    return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '结束日期不能早于起租日期。', 422);
                }
            }
        }

        // 技能交易强制登录(已与业务确认)。技能要绑设备、要产生授权,需要一个稳定身份;
        // 游客单 customer_id 为空,授权无处挂靠 —— 与其事后补,不如在下单口就拦住。
        // 其余类型(租/买/配件/定制)仍允许游客,行为不变。
        if ($type === 'skill' && ! auth()->guard('customer')->user()) {
            return $this->fail(ApiErrorCode::SKILL_LOGIN_REQUIRED, '购买技能需要先登录。', 401);
        }

        try {
            $customer = auth()->guard('customer')->user();
            $channel = core()->getCurrentChannel();
            $baseCurrency = core()->getBaseCurrencyCode();

            // 技能可指定装到哪台设备。必须是本人的设备,不合法就当作没选(走"待绑定")
            // 而不是报错打断下单 —— 用户可以先买后绑。
            $deviceId = null;
            if ($type === 'skill' && $customer) {
                $requestedDeviceId = (int) $request->input('device_id', 0);

                if ($requestedDeviceId) {
                    $deviceId = Device::query()
                        ->where('customer_id', $customer->id)
                        ->where('id', $requestedDeviceId)
                        ->where('bind_status', Device::STATUS_BOUND)
                        ->value('id');
                }
            }

            $rentPeriod = (string) $request->input('rentPeriod', '');
            $sceneName = (string) $request->input('scene', '');

            $defaultRentDays = $isRentalFlow
                ? max(1, (int) ($request->input('rent_days') ?: (($isMulti && isset($itemsInput[0]['rent_days'])) ? $itemsInput[0]['rent_days'] : 1)))
                : 1;

            // 统一归一化成「行」:多机合单合并同 product_id 行并强制共用同一租期;单商品来自顶层参数
            if ($isMulti) {
                $mergedByProduct = [];
                foreach ($itemsInput as $it) {
                    $pid = (int) ($it['product_id'] ?? 0);
                    $lineQty = max(1, (int) ($it['qty'] ?? 1));
                    $lineDays = $isRentalFlow ? max(1, (int) ($it['rent_days'] ?? $defaultRentDays)) : 1;
                    if ($isRentalFlow && $lineDays !== $defaultRentDays) {
                        return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '多机合单须使用同一租期天数。', 422);
                    }
                    if (! isset($mergedByProduct[$pid])) {
                        $mergedByProduct[$pid] = [
                            'product_id' => $pid,
                            'qty'        => $lineQty,
                            'price'      => (float) ($it['price'] ?? 0),
                            'rent_days'  => $lineDays,
                        ];
                    } else {
                        $mergedByProduct[$pid]['qty'] += $lineQty;
                        if ($mergedByProduct[$pid]['qty'] > 999) {
                            return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '单款设备合并数量不能超过 999 台。', 422);
                        }
                    }
                }
                $lines = array_values($mergedByProduct);
            } else {
                $lines = [[
                    'product_id' => (int) $request->input('product_id'),
                    'qty'        => max(1, (int) $request->input('qty', 1)),
                    'price'      => (float) $request->input('price', 0),
                    'rent_days'  => $defaultRentDays,
                ]];
            }

            if ($isRentalFlow && $parsedStart && $parsedEnd) {
                $expectedEnd = $parsedStart->copy()->addDays($defaultRentDays)->format('Y-m-d');
                if ($endDate !== $expectedEnd) {
                    return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, '结束日期与租期天数不匹配，请重新确认租期。', 422);
                }
            }

            if ($problem = app(SkillMarketService::class)->purchaseProblem(array_column($lines, 'product_id'), $customer?->id, $type)) {
                return $this->fail($problem['code'], $problem['message'], $problem['status']);
            }

            $productIds = array_values(array_filter(array_column($lines, 'product_id')));
            if ($productIds !== []
                && \Illuminate\Support\Facades\Schema::hasTable('scene_sku_mappings')
                && DB::table('scene_sku_mappings')->where('mode', 'service')->whereNotNull('approved_by')->whereIn('product_id', $productIds)->exists()) {
                return response()->json(['code' => 'SCENE_QUOTE_REQUIRED', 'message' => '场景定制服务 SKU 必须通过正式报价单确认。'], 409);
            }

            // 配件单的类型安全:只接受「配件」分类下、已上架的商品。
            // 前端曾把 mock/旧缓存商品的 id 传进来,而 products 表里恰好存在同 id 的
            // 租用/购买商品 —— 不校验分类就会把订单挂到完全不同的商品上。
            $accessoryCategoryId = null;
            if ($type === 'accessory') {
                $accessoryCategoryId = \Webkul\Category\Models\Category::query()
                    ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
                    ->value('id');

                if (! $accessoryCategoryId) {
                    return $this->fail(ApiErrorCode::ACCESSORY_UNAVAILABLE, '配件目录暂不可用，请稍后再试。', 503);
                }
            }

            $typeLabels = ['rent' => '租赁', 'buy' => '购买', 'plan' => '方案', 'custom' => '定制', 'skill' => '技能', 'accessory' => '配件'];

            // 定制线索:无商品 → 挂锚点商品(后台需存在 sku=LEAD-CUSTOM 的商品,如「定制咨询线索」)
            if ($isCustom && ! $isMulti && empty($lines[0]['product_id'])) {
                $anchor = $this->productRepository->findByField('sku', 'LEAD-CUSTOM')->first();

                if (! $anchor) {
                    return $this->fail(ApiErrorCode::LEAD_ANCHOR_MISSING, '缺少线索锚点商品:请在后台创建 sku 为 LEAD-CUSTOM 的商品', 422);
                }

                $lines[0]['product_id'] = $anchor->id;
            }

            $sceneContext = app(\Webkul\Shop\Services\Scenes\SceneOrderGuard::class)->check(
                $request->only(['type', 'from', 'scene_id', 'scene_revision_id']), array_column($lines, 'product_id'), (int) $channel->id
            );
            if (isset($sceneContext['code'])) {
                return $this->fail($sceneContext['code'], $sceneContext['message'], 422);
            }
            if ($sceneContext) {
                $sceneName = $sceneContext['scene_name'];
            }

            $email = $customer?->email ?? $request->input('email', 'guest@example.com');
            [$firstName, $lastName] = $this->splitName((string) $request->input('name'));

            $orderItems = [];
            $pendingMerchantLocks = [];
            $total = 0;
            $totalQty = 0;
            $totalDepositCents = 0;

            $hasExtTable = \Illuminate\Support\Facades\Schema::hasTable('merchant_product_exts');
            $hasPlanTable = \Illuminate\Support\Facades\Schema::hasTable('merchant_rental_plans');
            $hasInvTable = \Illuminate\Support\Facades\Schema::hasTable('product_inventories');
            $extHasSeller = $hasExtTable && \Illuminate\Support\Facades\Schema::hasColumn('merchant_product_exts', 'seller_id');
            $extHasShelf = $hasExtTable && \Illuminate\Support\Facades\Schema::hasColumn('merchant_product_exts', 'shelf_status');
            $extHasLockedStock = $hasExtTable && \Illuminate\Support\Facades\Schema::hasColumn('merchant_product_exts', 'locked_stock');
            $extHasVersion = $hasExtTable && \Illuminate\Support\Facades\Schema::hasColumn('merchant_product_exts', 'version');

            foreach ($lines as $line) {
                $product = $this->productRepository->with('parent')->find($line['product_id']);

                if (! $product) {
                    return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在(ID: '.$line['product_id'].')', 422);
                }

                if (! $isCustom && isset($product->status) && (int) $product->status === 0) {
                    return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, "「{$product->name}」已下架，暂不可下单。", 422);
                }

                if ($type === 'accessory') {
                    $isSellableAccessory = (int) $product->status === 1
                        && $product->categories()->where('categories.id', $accessoryCategoryId)->exists();

                    if (! $isSellableAccessory) {
                        return $this->fail(
                            ApiErrorCode::ACCESSORY_ORDER_TYPE_MISMATCH,
                            '该商品不是可售配件，不能按配件下单。',
                            422
                        );
                    }
                }

                if (! $isCustom && ! empty($product->manage_stock) && $hasInvTable) {
                    $invRows = DB::table('product_inventories')
                        ->where('product_id', $product->id)
                        ->where(fn ($q) => $q->whereNull('channel_id')->orWhere('channel_id', (int) $channel->id))
                        ->lockForUpdate()
                        ->get(['qty']);
                    $availInv = (int) $invRows->sum('qty');
                    if ($availInv < $line['qty']) {
                        return $this->fail(
                            ApiErrorCode::ORDER_VALIDATION_FAILED,
                            "「{$product->name}」库存不足（当前可用 {$availInv} 台）。",
                            422
                        );
                    }
                }

                $days = $isRentalFlow ? $line['rent_days'] : 1;
                $unitDepositYuan = null;
                $lineDepositYuan = null;
                $lineDepositCents = 0;
                $extDailyRentYuan = 0.0;
                $extPriceYuan = 0.0;
                $minRentDays = 1;
                $maxRentDays = null;
                $extRow = null;

                if ($hasExtTable && ! $isCustom && $type !== 'skill') {
                    $extRow = DB::table('merchant_product_exts')
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();
                    if ($extRow) {
                        if ($extHasSeller && $extHasShelf && ! empty($extRow->seller_id) && ($extRow->shelf_status ?? null) === 'off_shelf') {
                            return $this->fail(ApiErrorCode::ORDER_VALIDATION_FAILED, "「{$product->name}」已下架，暂不可下单。", 422);
                        }
                        if ((int) ($extRow->daily_rent_cents ?? 0) > 0) {
                            $extDailyRentYuan = ((int) $extRow->daily_rent_cents) / 100;
                        }
                        if (property_exists($extRow, 'price_cents') && (int) ($extRow->price_cents ?? 0) > 0) {
                            $extPriceYuan = ((int) $extRow->price_cents) / 100;
                        }
                        if ($isRentalFlow) {
                            $extMin = (int) ($extRow->min_rent_days ?? 1);
                            if ($extMin > $minRentDays) {
                                $minRentDays = $extMin;
                            }
                            $unitDepCents = max(0, (int) ($extRow->deposit_cents ?? 0));
                            if ($unitDepCents > 0) {
                                $lineDepositCents = $unitDepCents * $line['qty'];
                                $unitDepositYuan = number_format($unitDepCents / 100, 2, '.', '');
                                $lineDepositYuan = number_format($lineDepositCents / 100, 2, '.', '');
                            }
                            $ft = (string) ($extRow->fulfillment_type ?? '');
                            if (property_exists($extRow, 'rentable_stock') && (in_array($ft, ['rent', 'rental', 'both'], true) || ($extHasSeller && ! empty($extRow->seller_id)))) {
                                $rentStock = (int) ($extRow->rentable_stock ?? 0);
                                if ($rentStock < $line['qty']) {
                                    return $this->fail(
                                        ApiErrorCode::ORDER_VALIDATION_FAILED,
                                        "「{$product->name}」当前可租库存不足（剩余 {$rentStock} 台）。",
                                        422
                                    );
                                }
                            }
                        } elseif (in_array($type, ['buy', 'accessory'], true) && $extHasSeller && ! empty($extRow->seller_id)) {
                            $ft = (string) ($extRow->fulfillment_type ?? '');
                            if (in_array($ft, ['sale', 'both'], true) && property_exists($extRow, 'sellable_stock')) {
                                $sellStock = (int) ($extRow->sellable_stock ?? 0);
                                if ($sellStock < $line['qty']) {
                                    return $this->fail(
                                        ApiErrorCode::ORDER_VALIDATION_FAILED,
                                        "「{$product->name}」当前可售库存不足（剩余 {$sellStock} 件）。",
                                        422
                                    );
                                }
                            }
                        }
                    }
                }

                if ($isRentalFlow) {
                    if ($hasPlanTable) {
                        $enabledPlans = DB::table('merchant_rental_plans')
                            ->where('product_id', $product->id)
                            ->where('enabled', 1)
                            ->get(['min_days', 'max_days']);
                        if ($enabledPlans->isNotEmpty()) {
                            $planMin = (int) $enabledPlans->min('min_days');
                            if ($planMin > $minRentDays) {
                                $minRentDays = $planMin;
                            }
                            $planMax = (int) $enabledPlans->max('max_days');
                            if ($planMax > 0) {
                                $maxRentDays = max($minRentDays, $planMax);
                            }
                        }
                    }
                    if ($minRentDays > 1 && $days < $minRentDays) {
                        return $this->fail(
                            ApiErrorCode::ORDER_VALIDATION_FAILED,
                            "「{$product->name}」最低起租 {$minRentDays} 天（当前选择了 {$days} 天）。",
                            422
                        );
                    }
                    if ($maxRentDays !== null && $days > $maxRentDays) {
                        return $this->fail(
                            ApiErrorCode::ORDER_VALIDATION_FAILED,
                            "「{$product->name}」单次最长可租 {$maxRentDays} 天（当前选择了 {$days} 天）。",
                            422
                        );
                    }
                }

                // 单价:技能、配件、场景选型及多机合单均以服务端目录价为准,防止客户端篡改价格。
                // 其余普通单机租/买保持既有议价流兼容(仅在传入正数时覆盖目录价)。
                $catalogPrice = (float) ($product->getTypeInstance()->getMinimalPrice() ?: $product->price);
                if ($catalogPrice <= 0) {
                    $catalogPrice = $isRentalFlow
                        ? ($extDailyRentYuan > 0 ? $extDailyRentYuan : $extPriceYuan)
                        : ($extPriceYuan > 0 ? $extPriceYuan : $extDailyRentYuan);
                }
                $useCatalogPrice = in_array($type, ['skill', 'accessory'], true)
                    || ! empty($sceneContext)
                    || $isMulti
                    || ! ($line['price'] > 0);
                $unitPrice = $useCatalogPrice ? $catalogPrice : $line['price'];

                $pName = $product->name;

                // 定制线索:金额恒为 0(线索不是销售),条目标题用前端传入的线索名(而非锚点商品名)
                if ($isCustom) {
                    $unitPrice = 0;
                    $pName = (string) $request->input('product_name', '定制咨询');
                }

                // 租用按天计价(一条订单项 = 一台机器人 × 租用天数)
                $linePrice = $isRentalFlow ? $unitPrice * $days : $unitPrice;
                $lineTotal = $linePrice * $line['qty'];

                // 权威归还日由服务端按 startDate + days 推导,不盲信客户端字符串
                $lineEndDate = ($isRentalFlow && $parsedStart)
                    ? $parsedStart->copy()->addDays($days)->format('Y-m-d')
                    : '';

                // 条目名称:场景名 + 商品名 + 类型 + 租期,后台一眼可见
                $badgeLabel = ($isRentalFlow && ! empty($sceneContext)) ? '租赁' : ($typeLabels[$type] ?? '购买');
                $itemName = ($sceneName !== '' ? $sceneName.' · ' : '').$pName.'【'.$badgeLabel.'】';

                if ($isRentalFlow) {
                    $itemName .= $rentPeriod !== '' ? '（租期'.$rentPeriod.'）' : '（'.$days.'天）';
                    if ($startDate !== '' && $lineEndDate !== '') {
                        $itemName .= '（'.$startDate.'~'.$lineEndDate.'）';
                    }
                }

                // 定制线索:公司/微信/需求拼进条目名,后台订单列表直接可见,无需点开详情
                if ($isCustom) {
                    $extra = collect([
                        $request->input('company') ? '公司：'.$request->input('company') : '',
                        $request->input('wechat') ? '微信：'.$request->input('wechat') : '',
                        $request->input('demand') ? '需求：'.mb_substr((string) $request->input('demand'), 0, 80) : '',
                    ])->filter()->implode('｜');
                    if ($extra !== '') {
                        $itemName .= '（'.mb_substr($extra, 0, 160).'）';
                    }
                }

                // 场景直租写 additional.scene_rental（与 SC3 正式报价协议 additional.scene 严格隔离）；
                // 普通租赁/+方案写 additional.rental，让订单列表与详情页均能读取真实租期与起止日。
                $additional = null;
                if ($sceneContext) {
                    $additional = [
                        'scene_rental' => [
                            'source'            => $sceneContext['source'],
                            'scene_id'          => $sceneContext['scene_id'],
                            'scene_name'        => $sceneContext['scene_name'],
                            'scene_revision_id' => $sceneContext['scene_revision_id'] ?? null,
                            'rent_days'         => $days,
                            'start_date'        => $startDate !== '' ? $startDate : null,
                            'end_date'          => $lineEndDate !== '' ? $lineEndDate : null,
                            'daily_price'       => $unitPrice,
                            'qty'               => $line['qty'],
                            'min_rent_days'     => $minRentDays,
                            'max_rent_days'     => $maxRentDays,
                            'unit_deposit_yuan' => $unitDepositYuan,
                            'deposit_yuan'      => $lineDepositYuan,
                        ],
                    ];
                } elseif ($isRentalFlow) {
                    $additional = [
                        'rental' => [
                            'rent_days'         => $days,
                            'rent_period'       => $rentPeriod !== '' ? $rentPeriod : ($days.'天'),
                            'start_date'        => $startDate !== '' ? $startDate : null,
                            'end_date'          => $lineEndDate !== '' ? $lineEndDate : null,
                            'daily_price'       => $unitPrice,
                            'qty'               => $line['qty'],
                            'min_rent_days'     => $minRentDays,
                            'max_rent_days'     => $maxRentDays,
                            'unit_deposit_yuan' => $unitDepositYuan,
                            'deposit_yuan'      => $lineDepositYuan,
                        ],
                    ];
                }

                if ($type === 'skill') {
                    $additional = [
                        'skill' => [
                            'device_id'  => $deviceId,
                            'ordered_at' => now()->toDateTimeString(),
                        ],
                    ];
                }

                if ($extRow && $extHasSeller && ! empty($extRow->seller_id) && ! empty($extRow->shop_id)) {
                    $pendingMerchantLocks[] = [
                        'ext'               => $extRow,
                        'product_id'        => (int) $product->id,
                        'product_name'      => (string) ($pName ?: ('商品 #'.$product->id)),
                        'sku_code'          => (string) ($product->sku ?: ('SKU-'.$product->id)),
                        'fulfillment_type'  => $isRentalFlow ? 'rental' : 'sale',
                        'qty'               => $line['qty'],
                        'days'              => $days,
                        'start_date'        => $startDate,
                        'end_date'          => $lineEndDate,
                        'unit_price_cents'  => (int) round($unitPrice * 100),
                        'subtotal_cents'    => (int) round($lineTotal * 100),
                        'deposit_cents'     => $lineDepositCents,
                    ];
                }

                $orderItems[] = [
                    'product_id'   => $product->id,
                    'product_type' => get_class($product),
                    'type'         => $product->type,
                    'sku'          => $product->sku,
                    'name'         => $itemName,
                    'qty_ordered'  => $line['qty'],
                    'price'        => $linePrice,
                    'base_price'   => $linePrice,
                    'total'        => $lineTotal,
                    'base_total'   => $lineTotal,
                    'additional'   => $additional,
                ];

                $total += $lineTotal;
                $totalQty += $line['qty'];
                $totalDepositCents += $lineDepositCents;
            }

            // B-18 · 技能单用券。
            // 技能单走 tob-order、不经购物车,原生券链路(`CartRule\Helpers\CartRule`)整条绑在
            // 购物车上,够不着这里 —— 于是券数据在库里、技能单却没有任何入口能用它。
            // 本段按既有券机制(同一批 cart_rules/cart_rule_coupons 表 + 同一套校验语义)算出折抵;
            // **用量记账不在这里** —— 下单成功后原生监听 `checkout.order.save.after` 会按
            // order 上的 discount_amount / applied_cart_rule_ids / coupon_code 自动 +1。
            $couponCode = trim((string) $request->input('coupon_code', ''));
            $discount = 0.0;
            $appliedRuleId = null;

            if ($couponCode !== '') {
                // 券只对技能单开放:其余类型(租/买/配件)走的是"前端确认价优先"的议价流(M1),
                // 折扣语义不同,贸然套券会与议价叠加出无法追溯的价。非技能单带了券 = 明确拒绝,不静默吞掉。
                if ($type !== 'skill') {
                    return $this->fail(ApiErrorCode::COUPON_INVALID, '优惠码仅适用于技能订单。', 422);
                }

                $couponResult = $this->couponService->resolve(
                    $couponCode,
                    $customer?->id,
                    $customer?->customer_group_id ? (int) $customer->customer_group_id : null,
                    (int) $channel->id,
                    (float) $total,
                    (int) $totalQty,
                );

                if (! $couponResult['ok']) {
                    return $this->fail(ApiErrorCode::COUPON_INVALID, (string) $couponResult['message'], 422);
                }

                $discount = (float) $couponResult['discount'];
                $appliedRuleId = (int) $couponResult['rule']->id;

                // 折抵按行小计比例摊到条目(单条目时即整笔落在该条),后台条目视图与订单口径一致。
                // ⚠ 只摊 discount_amount,total 保持行原价 —— 与原生的"小计不动、折扣单列"同义。
                if ($total > 0) {
                    foreach ($orderItems as &$line) {
                        $line['discount_amount'] = round($discount * ($line['total'] / $total), 4);
                        $line['base_discount_amount'] = $line['discount_amount'];
                    }
                    unset($line);
                }
            }

            $addressColumn = \Illuminate\Support\Facades\Schema::hasColumn('addresses', 'address') ? 'address' : 'address1';
            $address = [
                $addressColumn => $request->input('address', $isCustom ? '定制咨询(无交付地址)' : ''),
                'country' => $request->input('country', 'CN'),
                'state' => $request->input('state', ''),
                'city' => $request->input('city', $isCustom ? '定制咨询' : ''),
                'postcode' => $request->input('postcode', '100000'),
                'phone' => $request->input('phone'),
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];

            // 订单归类:场景租用(from=scene/custom_plan 或带有效 scene_id)的租用单归入「方案」,计价仍按租期
            $orderType = ($isRentalFlow && (in_array($request->input('from'), ['scene', 'custom_plan'], true) || ! empty($sceneContext['scene_id'])))
                ? 'plan'
                : $type;

            $data = [
                // 订单类型(后台 order_type 列,分类筛选用):rent/buy/plan/custom/skill/accessory
                'order_type' => $orderType,
                'customer_id' => $customer?->id,
                'customer_type' => $customer ? get_class($customer) : null,
                'customer_email' => $email,
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'is_guest' => ! $customer,
                // 游客单必须记下本机标识:否则游客订单列表只能靠 customer_id IS NULL 去捞,
                // 那会把所有人的游客单都返回给任何一个访客(见 OrderController::guestIndex 的修复)
                'guest_key' => $customer ? null : $this->guestKey($request),
                'channel_id' => $channel->id,
                'channel_name' => $channel->name ?? $channel->code ?? 'Default',
                'channel_type' => \Webkul\Core\Models\Channel::class,
                'base_currency_code' => $baseCurrency,
                'channel_currency_code' => $baseCurrency,
                'order_currency_code' => $baseCurrency,
                'total_item_count' => $totalQty,
                'total_qty_ordered' => $totalQty,
                // 小计 = 折前;实付 = 小计 − 券折抵(B-18)
                'grand_total' => $total - $discount,
                'base_grand_total' => $total - $discount,
                'sub_total' => $total,
                'base_sub_total' => $total,
                'tax_amount' => 0,
                'base_tax_amount' => 0,
                'shipping_amount' => 0,
                'base_shipping_amount' => 0,
                'discount_amount' => $discount,
                'base_discount_amount' => $discount,
                // B-18:券落订单 —— coupon_code 供展示,applied_cart_rule_ids 供原生用量监听识别。
                // ⚠ 两者必须**同时**有值,否则 `CartRule\Listeners\Order` 不会给券记账(times_used 不增)。
                'coupon_code' => $discount > 0 ? $couponCode : null,
                'applied_cart_rule_ids' => $appliedRuleId ? (string) $appliedRuleId : null,
                'shipping_method' => '',
                'shipping_title' => '',
                'payment' => ['method' => 'cashondelivery', 'method_title' => 'Cash on Delivery'],
                'billing_address' => $address,
                'shipping_address' => $address,
                'items' => $orderItems,
            ];

            $order = $this->orderRepository->create($data);

            // 若订单包含入驻商家的商品，同事务扣减商家可用库存→锁定库存，并生成关联 MerchantOrder 供商家工作台发货履约
            if ($pendingMerchantLocks !== []) {
                $hasMerchantOrders = \Illuminate\Support\Facades\Schema::hasTable('merchant_orders');
                $hasLockLedger = \Illuminate\Support\Facades\Schema::hasTable('merchant_inventory_locks');
                $shippingText = trim($request->input('city', '').' '.$request->input('address', ''));

                foreach ($pendingMerchantLocks as $mLock) {
                    $ext = $mLock['ext'];
                    $isRentItem = $mLock['fulfillment_type'] === 'rental';
                    $nextSellable = $isRentItem
                        ? (int) ($ext->sellable_stock ?? 0)
                        : max(0, (int) ($ext->sellable_stock ?? 0) - $mLock['qty']);
                    $nextRentable = $isRentItem
                        ? max(0, (int) ($ext->rentable_stock ?? 0) - $mLock['qty'])
                        : (int) ($ext->rentable_stock ?? 0);
                    $nextLocked = $extHasLockedStock
                        ? ((int) ($ext->locked_stock ?? 0) + $mLock['qty'])
                        : 0;

                    $extPatch = $isRentItem
                        ? ['rentable_stock' => $nextRentable]
                        : ['sellable_stock' => $nextSellable];
                    if ($extHasLockedStock) {
                        $extPatch['locked_stock'] = $nextLocked;
                    }
                    if ($extHasVersion) {
                        $extPatch['version'] = (int) ($ext->version ?? 1) + 1;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('merchant_product_exts', 'updated_at')) {
                        $extPatch['updated_at'] = now();
                    }
                    DB::table('merchant_product_exts')->where('id', $ext->id)->update($extPatch);

                    $mOrderNo = ($isRentItem ? 'RNT-' : 'ORD-').date('Ymd').'-'.strtoupper(\Illuminate\Support\Str::random(6));
                    if ($hasMerchantOrders) {
                        \Webkul\Shop\Models\MerchantOrder::create([
                            'order_no'             => $mOrderNo,
                            'bagisto_order_id'     => (int) $order->id,
                            'seller_id'            => (int) $ext->seller_id,
                            'shop_id'              => (int) $ext->shop_id,
                            'product_id'           => $mLock['product_id'],
                            'fulfillment_type'     => $mLock['fulfillment_type'],
                            'product_name'         => $mLock['product_name'],
                            'sku_code'             => $mLock['sku_code'],
                            'buyer_id'             => $customer?->id,
                            'buyer_name'           => mb_substr(trim((string) $request->input('name')), 0, 64),
                            'buyer_phone'          => mb_substr(trim((string) $request->input('phone')), 0, 32),
                            'shipping_address'     => mb_substr($shippingText, 0, 255),
                            'qty'                  => $mLock['qty'],
                            'rental_days'          => $mLock['days'],
                            'rental_start_at'      => $isRentItem ? ($mLock['start_date'] !== '' ? \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $mLock['start_date']) : now()) : null,
                            'rental_end_at'        => $isRentItem ? ($mLock['end_date'] !== '' ? \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $mLock['end_date']) : now()->addDays($mLock['days'])) : null,
                            'unit_price_cents'     => $mLock['unit_price_cents'],
                            'subtotal_cents'       => $mLock['subtotal_cents'],
                            'deposit_cents'        => $mLock['deposit_cents'],
                            'damage_deduct_cents'  => 0,
                            'overdue_fee_cents'    => 0,
                            'refund_deposit_cents' => $mLock['deposit_cents'],
                            'status'               => \Webkul\Shop\Models\MerchantOrder::STATUS_PENDING_SHIPMENT,
                            'contract_no'          => $isRentItem ? ('CTR-'.date('Ymd').'-'.strtoupper(\Illuminate\Support\Str::random(6))) : null,
                            'contract_status'      => $isRentItem ? 'signed' : 'not_required',
                            'contract_signed_at'   => $isRentItem ? now() : null,
                            'version'              => 1,
                        ]);
                    }

                    if ($hasLockLedger) {
                        \Webkul\Shop\Models\MerchantInventoryLock::create([
                            'seller_id'         => (int) $ext->seller_id,
                            'shop_id'           => (int) $ext->shop_id,
                            'product_id'        => $mLock['product_id'],
                            'action_type'       => $isRentItem ? 'rental_lock' : 'sale_lock',
                            'qty_change'        => -$mLock['qty'],
                            'sellable_after'    => $nextSellable,
                            'rentable_after'    => $nextRentable,
                            'locked_after'      => $nextLocked,
                            'maintenance_after' => (int) ($ext->maintenance_stock ?? 0),
                            'reference_no'      => $mOrderNo,
                            'reason'            => ($isRentItem ? 'C端/场景租赁下单锁定库存' : 'C端售卖下单锁定库存').' (商城单 '.$order->increment_id.')',
                            'actor_id'          => $customer?->id,
                        ]);
                    }
                }
            }

            $totalDepositYuan = $totalDepositCents > 0 ? number_format($totalDepositCents / 100, 2, '.', '') : null;
            $firstSceneRental = $orderItems[0]['additional']['scene_rental'] ?? null;
            if ($firstSceneRental) {
                $firstSceneRental['total_qty'] = $totalQty;
                $firstSceneRental['deposit_yuan'] = $totalDepositYuan;
            }
            $firstRental = $orderItems[0]['additional']['rental'] ?? null;
            if ($firstRental) {
                $firstRental['total_qty'] = $totalQty;
                $firstRental['deposit_yuan'] = $totalDepositYuan;
            }

            return response()->json([
                'success'     => true,
                'order_id'    => $order->id,
                'increment_id'=> $order->increment_id,
                'order_type'  => $order->order_type,
                'grand_total' => $order->grand_total,
                'discount'    => $discount,
                'coupon_code' => $discount > 0 ? $couponCode : null,
                'status'      => $order->status,
                'scene_rental'=> $firstSceneRental,
                'rental_info' => $firstSceneRental ?: $firstRental,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorId = (string) \Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\Log::error('TOB_ORDER_FAILED', [
                'error_id' => $errorId,
                'exception' => get_class($e),
                'at' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return $this->fail(ApiErrorCode::ORDER_CREATE_FAILED, '订单未能创建，请稍后重试', 500, ['error_id' => $errorId]);
        }
    }

    /**
     * 把「姓名」拆成 first_name / last_name(空格分隔;单值回退,保证非空)。
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) >= 2) {
            $first = array_shift($parts);

            return [$first, implode(' ', $parts)];
        }

        return [$name !== '' ? $name : '未命名', '用户'];
    }
}
