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
        // 旧 custom 只适配成独立需求，永不创建零元订单；旧客户端必须展示需求回执。
        if ($request->input('type') === 'custom') {
            return app(SceneWorkflowController::class)->store($this->adaptLegacyCustom($request), app(\Webkul\Shop\Services\Scenes\SceneRequestService::class));
        }
        $sceneLine = collect($request->input('items', []))->contains(fn ($line) => is_array($line) && (! empty($line['scene_id']) || ! empty($line['scene_quote_id']) || ! empty($line['scene']) || ! empty($line['additional']['scene'])));
        if ($sceneLine || $request->filled('scene_id') || $request->filled('scene_revision_id') || $request->filled('scene_quote_id') || $request->filled('scene') || $request->filled('additional.scene')
            || $request->input('type') === 'plan' || in_array($request->input('from'), ['scene', 'custom_plan'], true)) {
            return response()->json(['code' => 'SCENE_QUOTE_REQUIRED', 'message' => '场景服务请先提交需求并确认正式报价。'], 409);
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

        // 多机合单:items 数组(场景租用一次选多台),否则走单商品老路径
        // 用 merge 后的 all() 取 items(兼容 input() 对嵌套数组的剥离,避免多机被误判为单商品导致 422)
        $all = $request->all();
        $itemsInput = $all['items'] ?? $request->input('items');
        $isMulti = is_array($itemsInput) && ! empty($itemsInput);

        $request->validate([
            // product_id 必须指向后台真实存在的商品,否则订单无法关联商品(会破坏后台订单页渲染)
            // 例外:custom 定制线索挂锚点商品;多机合单逐行校验 items.*.product_id
            'product_id' => (($isCustom || $isMulti) ? 'nullable' : 'required').'|integer|exists:products,id',
            'qty' => ($isMulti ? 'nullable' : 'required').'|integer|min:1',
            'type' => 'required|in:rent,buy,plan,custom,skill,accessory',
            'items' => $isMulti ? 'required|array|min:1|max:50' : 'nullable',
            'items.*.product_id' => ($isMulti ? 'required' : 'nullable').'|integer|exists:products,id',
            'items.*.qty' => ($isMulti ? 'required' : 'nullable').'|integer|min:1',
            // M1:成交价不能无界。下限 0(0 表示"用服务端目录价",前端既有契约),
            // 上限挡住天文数字;负数(如 -1)被 min:0 直接拒。
            'items.*.price' => 'nullable|numeric|min:0|max:10000000',
            // 租期加上限,防 rent_days=99999 撑出天价或压出天价
            'items.*.rent_days' => 'nullable|integer|min:1|max:3650',
            'scene_id' => 'nullable|integer|min:1|max:4294967295',
            'from' => 'nullable|in:scene,custom_plan',
            'name' => 'required|string|max:255',
            'product_name' => 'nullable|string',
            'sku' => 'nullable|string',
            'price' => 'nullable|numeric|min:0|max:10000000',
            'rent_days' => 'nullable|integer|min:1|max:3650',
            'phone' => 'required|string',
            'city' => ($isCustom ? 'nullable' : 'required').'|string',
            'address' => ($isCustom ? 'nullable' : 'required').'|string',
            // B-18:技能单优惠码(仅技能单可带,见下方处理)。规则校验不写在这里 ——
            // 券的有效性要读库(券/规则/渠道/客群/用量),那是 SkillCouponService 的职责。
            'coupon_code' => 'nullable|string|max:64',
        ]);

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

            $typeLabels = ['rent' => '租赁', 'buy' => '购买', 'plan' => '方案', 'custom' => '定制', 'skill' => '技能', 'accessory' => '配件'];

            // 统一归一化成「行」:多机合单来自 items[],单商品来自顶层参数
            if ($isMulti) {
                $lines = collect($itemsInput)->map(fn ($it) => [
                    'product_id' => (int) ($it['product_id'] ?? 0),
                    'qty' => max(1, (int) ($it['qty'] ?? 1)),
                    'price' => (float) ($it['price'] ?? 0),
                    'rent_days' => max(1, (int) ($it['rent_days'] ?? 1)),
                ])->values()->all();
            } else {
                $lines = [[
                    'product_id' => (int) $request->input('product_id'),
                    'qty' => max(1, (int) $request->input('qty', 1)),
                    'price' => (float) $request->input('price', 0),
                    'rent_days' => $type === 'rent' ? max(1, (int) $request->input('rent_days', 1)) : 1,
                ]];
            }

            // 定制线索:无商品 → 挂锚点商品(后台需存在 sku=LEAD-CUSTOM 的商品,如「定制咨询线索」)
            if ($isCustom && ! $isMulti && empty($lines[0]['product_id'])) {
                $anchor = $this->productRepository->findByField('sku', 'LEAD-CUSTOM')->first();

                if (! $anchor) {
                    return $this->fail(ApiErrorCode::LEAD_ANCHOR_MISSING, '缺少线索锚点商品:请在后台创建 sku 为 LEAD-CUSTOM 的商品', 422);
                }

                $lines[0]['product_id'] = $anchor->id;
            }

            $sceneContext = app(\Webkul\Shop\Services\Scenes\SceneOrderGuard::class)->check(
                $request->only(['type', 'from', 'scene_id']), array_column($lines, 'product_id'), (int) $channel->id
            );
            if (isset($sceneContext['code'])) {
                return $this->fail($sceneContext['code'], $sceneContext['message'], 422);
            }
            if ($sceneContext) {
                $sceneName = $sceneContext['scene_name'];
            }

            if ($problem = app(SkillMarketService::class)->purchaseProblem(array_column($lines, 'product_id'), $customer?->id, $type)) {
                return $this->fail($problem['code'], $problem['message'], $problem['status']);
            }

            $email = $customer?->email ?? $request->input('email', 'guest@example.com');
            [$firstName, $lastName] = $this->splitName((string) $request->input('name'));

            $orderItems = [];
            $total = 0;
            $totalQty = 0;

            foreach ($lines as $line) {
                $product = $this->productRepository->with('parent')->find($line['product_id']);

                if (! $product) {
                    return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在(ID: '.$line['product_id'].')', 422);
                }

                $days = $type === 'rent' ? $line['rent_days'] : 1;

                // 单价:技能是**标准商品**,一律用服务端目录价,不接受客户端改价(M1)。
                // 此前任何登录用户都能给技能开任意价 —— 技能单不该走议价。
                // 其余类型(租/买/配件)沿用"前端确认价优先"(询价/议价流,设计如此);
                // 客户端价 <=0 时同样回退到服务端目录价,下限由上面的 min/max 校验兜住。
                $catalogPrice = (float) ($product->getTypeInstance()->getMinimalPrice() ?: $product->price);
                $unitPrice = ($type === 'skill' || ! ($line['price'] > 0))
                    ? $catalogPrice
                    : $line['price'];

                $pName = $product->name;

                // 定制线索:金额恒为 0(线索不是销售),条目标题用前端传入的线索名(而非锚点商品名)
                if ($isCustom) {
                    $unitPrice = 0;
                    $pName = (string) $request->input('product_name', '定制咨询');
                }

                // 租用按天计价(一条订单项 = 一台机器人 × 租用天数)
                $linePrice = $type === 'rent' ? $unitPrice * $days : $unitPrice;
                $lineTotal = $linePrice * $line['qty'];

                // 条目名称:场景名 + 商品名 + 类型 + 租期,后台一眼可见
                $itemName = ($sceneName !== '' ? $sceneName.' · ' : '').$pName.'【'.($typeLabels[$type] ?? '购买').'】';

                if ($type === 'rent') {
                    $itemName .= $rentPeriod !== '' ? '（租期'.$rentPeriod.'）' : '（'.$days.'天）';
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

                // 技能条目:把结构化信息写进 order_items.additional。
                // 该列本来就存在(json)且 OrderItem 的 $guarded 不含它,所以零迁移。
                // 这是把类型/租期硬拼进 name 的结构化替代 —— 事实源仍是 skill_entitlements,
                // 这里只是下单瞬间的快照(用户之后换绑设备不该改变历史记录)。
                $additional = $sceneContext ? ['scene' => $sceneContext] : null;

                if ($type === 'skill') {
                    $additional = [
                        'skill' => [
                            'device_id' => $deviceId,
                            'ordered_at' => now()->toDateTimeString(),
                        ],
                    ];
                }

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_type' => get_class($product),  // morphTo 需要完整类名(如 Webkul\Product\Models\Product), 不能是 'simple'
                    'type' => $product->type,       // 商品类型码(如 'simple')
                    'sku' => $product->sku,
                    'name' => $itemName,
                    'qty_ordered' => $line['qty'],
                    'price' => $linePrice,
                    'base_price' => $linePrice,
                    'total' => $lineTotal,
                    'base_total' => $lineTotal,
                    'additional' => $additional,
                ];

                $total += $lineTotal;
                $totalQty += $line['qty'];
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

            $address = [
                'address1' => $request->input('address', $isCustom ? '定制咨询(无交付地址)' : ''),
                'country' => $request->input('country', 'CN'),
                'state' => $request->input('state', ''),
                'city' => $request->input('city', $isCustom ? '定制咨询' : ''),
                'postcode' => $request->input('postcode', '100000'),
                'phone' => $request->input('phone'),
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];

            // 订单归类:场景租用(from=scene)的租用单归入「方案」,计价仍按租期
            $orderType = ($type === 'rent' && in_array($request->input('from'), ['scene', 'custom_plan'], true)) ? 'plan' : $type;

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
                'channel_name' => $channel->name,
                'channel_type' => get_class($channel),
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

            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'increment_id' => $order->increment_id,
                'grand_total' => $order->grand_total,
                'discount' => $discount,
                'coupon_code' => $discount > 0 ? $couponCode : null,
                'status' => $order->status,
            ]);
        } catch (\Throwable $e) {
            $errorId = (string) \Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\Log::error('TOB_ORDER_FAILED', [
                'error_id' => $errorId, 'exception' => get_class($e),
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
