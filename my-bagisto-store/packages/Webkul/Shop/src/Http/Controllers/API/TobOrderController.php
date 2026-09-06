<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Sales\Repositories\OrderRepository;

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
 * 注意:Bagisto orders 表无 additional 列,故不做迁移,类型信息放在条目名称里。
 */
class TobOrderController extends APIController
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected OrderRepository $orderRepository
    ) {}

    /**
     * 创建 TOB 订单。
     */
    public function store(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::debug('TOB_INPUT', $request->all());

        $type = (string) $request->input('type');
        $isCustom = $type === 'custom';

        $request->validate([
            // product_id 必须指向后台真实存在的商品,否则订单无法关联商品(会破坏后台订单页渲染)
            // 例外:custom 定制线索无商品 → 后端自动挂到锚点商品(sku=LEAD-CUSTOM)
            'product_id' => ($isCustom ? 'nullable' : 'required').'|integer|exists:products,id',
            'qty'        => 'required|integer|min:1',
            'type'       => 'required|in:rent,buy,plan,custom,skill,accessory',
            'name'         => 'required|string',
            'product_name' => 'nullable|string',
            'sku'          => 'nullable|string',
            'price'      => 'nullable|numeric',
            'phone'      => 'required|string',
            'city'       => ($isCustom ? 'nullable' : 'required').'|string',
            'address'    => ($isCustom ? 'nullable' : 'required').'|string',
        ]);

        try {
            if ($request->input('product_id')) {
                $product = $this->productRepository->with('parent')->findOrFail($request->input('product_id'));
            } else {
                // 定制线索:挂到锚点商品(后台需存在 sku=LEAD-CUSTOM 的商品,如「定制咨询线索」)
                $product = $this->productRepository->findByField('sku', 'LEAD-CUSTOM')->first();
                if (! $product) {
                    return response()->json([
                        'success' => false,
                        'message' => '缺少线索锚点商品:请在后台创建 sku 为 LEAD-CUSTOM 的商品',
                    ], 422);
                }
            }

            $customer = auth()->guard('customer')->user();
            $channel = core()->getCurrentChannel();
            $baseCurrency = core()->getBaseCurrencyCode();

            $qty = (int) $request->input('qty');
            $type = $request->input('type');
            $days = $type === 'rent' ? max(1, (int) $request->input('rent_days', 1)) : 1;
            $startDate = (string) $request->input('startDate', '');
            $rentPeriod = (string) $request->input('rentPeriod', '');

            // 单价:优先前端确认价;否则后端商品价;都没有则为 0
            $frontPrice = (float) $request->input('price', 0);
            if ($frontPrice > 0) {
                $unitPrice = $frontPrice;
            } elseif ($product) {
                $unitPrice = (float) ($product->getTypeInstance()->getMinimalPrice() ?: $product->price);
            } else {
                $unitPrice = 0;
            }

            $sku   = $product ? $product->sku : (string) $request->input('sku', '');
            $pName = $product ? $product->name : (string) $request->input('product_name', (string) $request->input('name', ''));
            $pType = $product ? $product->type : 'simple';

            // 定制线索:金额恒为 0(线索不是销售),条目标题用前端传入的线索名(而非锚点商品名)
            if ($isCustom) {
                $unitPrice = 0;
                $pName = (string) $request->input('product_name', '定制咨询');
            }

            // 租用按天计价(一条订单项 = 一台机器人 × 租用天数)
            $linePrice = $type === 'rent' ? $unitPrice * $days : $unitPrice;
            $total = $linePrice * $qty;

            // 条目名称:类型 + 租期,后台一眼可见;场景租用单把场景名放进条目名
            $typeLabels = ['rent' => '租赁', 'buy' => '购买', 'plan' => '方案', 'custom' => '定制', 'skill' => '技能', 'accessory' => '配件'];
            $itemName = ((string) $request->input('scene') !== '' ? $request->input('scene').' · ' : '').$pName.'【'.($typeLabels[$type] ?? '购买').'】';
            if ($type === 'rent') {
                $itemName .= $rentPeriod ? '（租期'.$rentPeriod.'）' : '（'.$days.'天）';
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

            $email = $customer?->email ?? $request->input('email', 'guest@example.com');
            [$firstName, $lastName] = $this->splitName((string) $request->input('name'));

            $address = [
                'address1'   => $request->input('address', $isCustom ? '定制咨询(无交付地址)' : ''),
                'country'    => $request->input('country', 'CN'),
                'state'      => $request->input('state', ''),
                'city'       => $request->input('city', $isCustom ? '定制咨询' : ''),
                'postcode'   => $request->input('postcode', '100000'),
                'phone'      => $request->input('phone'),
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ];

            // 订单归类:场景租用(from=scene)的租用单归入「方案」,计价仍按租期
            $orderType = ($type === 'rent' && $request->input('from') === 'scene') ? 'plan' : $type;

            $data = [
                // 订单类型(后台 order_type 列,分类筛选用):rent/buy/plan/custom/skill/accessory
                'order_type'            => $orderType,
                'customer_id'           => $customer?->id,
                'customer_type'         => $customer ? get_class($customer) : null,
                'customer_email'        => $email,
                'customer_first_name'   => $firstName,
                'customer_last_name'    => $lastName,
                'is_guest'              => ! $customer,
                'channel_id'            => $channel->id,
                'channel_name'          => $channel->name,
                'channel_type'          => get_class($channel),
                'base_currency_code'    => $baseCurrency,
                'channel_currency_code' => $baseCurrency,
                'order_currency_code'   => $baseCurrency,
                'total_item_count'      => $qty,
                'total_qty_ordered'     => $qty,
                'grand_total'           => $total,
                'base_grand_total'      => $total,
                'sub_total'             => $total,
                'base_sub_total'        => $total,
                'tax_amount'            => 0,
                'base_tax_amount'       => 0,
                'shipping_amount'       => 0,
                'base_shipping_amount'  => 0,
                'discount_amount'       => 0,
                'base_discount_amount'  => 0,
                'shipping_method'       => '',
                'shipping_title'        => '',
                'payment'               => ['method' => 'cashondelivery', 'method_title' => 'Cash on Delivery'],
                'billing_address'       => $address,
                'shipping_address'      => $address,
                'items'                 => [[
                    'product_id'   => $product->id,
                    'product_type' => get_class($product),  // morphTo 需要完整类名(如 Webkul\Product\Models\Product), 不能是 'simple'
                    'type'         => $product->type,       // 商品类型码(如 'simple')
                    'sku'          => $sku,
                    'name'         => $itemName,
                    'qty_ordered'  => $qty,
                    'price'        => $linePrice,
                    'base_price'   => $linePrice,
                    'total'        => $total,
                    'base_total'   => $total,
                ]],
            ];

            $order = $this->orderRepository->create($data);

            return response()->json([
                'success'      => true,
                'order_id'     => $order->id,
                'increment_id' => $order->increment_id,
                'grand_total'  => $order->grand_total,
                'status'       => $order->status,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
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
