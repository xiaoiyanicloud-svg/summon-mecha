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
        $request->validate([
            // product_id 必须指向后台真实存在的商品,否则订单无法关联商品(会破坏后台订单页渲染)
            'product_id' => 'required|integer|exists:products,id',
            'qty'        => 'required|integer|min:1',
            'type'       => 'required|in:rent,buy,plan,custom',
            'name'         => 'required|string',
            'product_name' => 'nullable|string',
            'sku'          => 'nullable|string',
            'price'      => 'nullable|numeric',
            'phone'      => 'required|string',
            'city'       => 'required|string',
            'address'    => 'required|string',
        ]);

        try {
            $product = $this->productRepository->with('parent')->findOrFail($request->input('product_id'));

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

            // 租用按天计价(一条订单项 = 一台机器人 × 租用天数)
            $linePrice = $type === 'rent' ? $unitPrice * $days : $unitPrice;
            $total = $linePrice * $qty;

            // 条目名称:类型 + 租期,后台一眼可见
            $typeLabels = ['rent' => '租赁', 'buy' => '购买', 'plan' => '方案', 'custom' => '定制'];
            $itemName = $pName.'【'.($typeLabels[$type] ?? '购买').'】';
            if ($type === 'rent') {
                $itemName .= $rentPeriod ? '（租期'.$rentPeriod.'）' : '（'.$days.'天）';
            }

            $email = $customer?->email ?? $request->input('email', 'guest@example.com');
            [$firstName, $lastName] = $this->splitName((string) $request->input('name'));

            $address = [
                'address1'   => $request->input('address'),
                'country'    => $request->input('country', 'CN'),
                'state'      => $request->input('state', ''),
                'city'       => $request->input('city'),
                'postcode'   => $request->input('postcode', '100000'),
                'phone'      => $request->input('phone'),
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ];

            $data = [
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
