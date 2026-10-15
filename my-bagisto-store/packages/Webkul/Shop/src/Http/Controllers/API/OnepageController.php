<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Webkul\Checkout\Facades\Cart;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Payment\Facades\Payment;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Shipping\Facades\Shipping;
use Webkul\Shop\Http\Requests\CartAddressRequest;
use Webkul\Shop\Http\Resources\CartResource;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\OrderValidationException;

class OnepageController extends APIController
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected CustomerRepository $customerRepository,
        protected OperationLogger $oplog,
    ) {}

    /**
     * Return cart summary.
     */
    public function summary(): JsonResource
    {
        $cart = Cart::getCart();

        return new CartResource($cart);
    }

    /**
     * Store address.
     */
    public function storeAddress(CartAddressRequest $cartAddressRequest): JsonResource
    {
        $params = $cartAddressRequest->all();

        if (
            ! auth()->guard('customer')->check()
            && ! Cart::getCart()->hasGuestCheckoutItems()
        ) {
            return new JsonResource([
                'redirect' => true,
                'data'     => '/admin' // 前台已停用,
            ]);
        }

        if (Cart::hasError()) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => '/admin',
            ]);
        }

        Cart::saveAddresses($params);

        $cart = Cart::getCart();

        Cart::collectTotals();

        if ($cart->haveStockableItems()) {
            if (! $rates = Shipping::collectRates()) {
                return new JsonResource([
                    'redirect'     => true,
                    'redirect_url' => '/admin',
                ]);
            }

            return new JsonResource([
                'redirect' => false,
                'data'     => $rates,
            ]);
        }

        return new JsonResource([
            'redirect' => false,
            'data'     => Payment::getSupportedPaymentMethods(),
        ]);
    }

    /**
     * Store shipping method.
     *
     * @return \Illuminate\Http\Response
     */
    public function storeShippingMethod()
    {
        $validatedData = $this->validate(request(), [
            'shipping_method' => 'required',
        ]);

        if (
            Cart::hasError()
            || ! $validatedData['shipping_method']
            || ! Cart::saveShippingMethod($validatedData['shipping_method'])
        ) {
            // ⚠ 保留 403 与 redirect_url(前端靠它跳转),只补上信封的 code/message ——
            //   此前这个响应体只有 redirect_url,`utils/request.js` 拿不到任何提示文案。
            return $this->fail(ApiErrorCode::CHECKOUT_STEP_FAILED, '购物车状态已变化,请返回重新选择配送方式。', Response::HTTP_FORBIDDEN, [
                'redirect_url' => '/admin',
            ]);
        }

        Cart::collectTotals();

        return response()->json(Payment::getSupportedPaymentMethods());
    }

    /**
     * Store payment method.
     *
     * @return array
     */
    public function storePaymentMethod()
    {
        $validatedData = $this->validate(request(), [
            'payment' => 'required',
        ]);

        if (
            Cart::hasError()
            || ! $validatedData['payment']
            || ! Cart::savePaymentMethod($validatedData['payment'])
        ) {
            return $this->fail(ApiErrorCode::CHECKOUT_STEP_FAILED, '购物车状态已变化,请返回重新选择支付方式。', Response::HTTP_FORBIDDEN, [
                'redirect_url' => '/admin',
            ]);
        }

        Cart::collectTotals();

        $cart = Cart::getCart();

        return [
            'cart' => new CartResource($cart),
        ];
    }

    /**
     * Store order
     */
    public function storeOrder()
    {
        if (Cart::hasError()) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => '/admin',
            ]);
        }

        Cart::collectTotals();

        try {
            $this->validateOrder();
        } catch (OrderValidationException $e) {
            // M7:此前一律包成 500 —— 而 validateOrder 抛的是"账号被停用 / 未选地址"
            // 这类**用户可纠正**的错误,500 会让前端把它当服务端故障去重试/上报。
            // 现在只有这里声明的业务校验失败走 4xx;其它异常**不再被吞**,
            // 交给全局异常处理 → 500(那才是真正的"未预期")。
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        $cart = Cart::getCart();

        if ($redirectUrl = Payment::getRedirectUrl($cart)) {
            return new JsonResource([
                'redirect'     => true,
                'redirect_url' => $redirectUrl,
            ]);
        }

        $data = (new OrderResource($cart))->jsonSerialize();

        $order = $this->orderRepository->create($data);

        // X4 · 下单留痕。订单是所有后续动作(授权发放、退款、事故追溯)的源头,
        //   此前 Shop 侧零留痕 —— 出事要追"谁在何时下了哪一单"查无此人。
        //   actor_id 可能为 null(游客下单),此时按 customer 类型记 null 即可。
        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $order->customer_id,
            'order.create',
            'order',
            $order->id,
            [
                'order_increment_id' => $order->increment_id,
                'grand_total'        => $order->grand_total,
                'channel'            => $order->channel_name ?? null,
            ],
            request()->ip(),
        );

        Cart::deActivateCart();

        session()->flash('order_id', $order->id);

        return new JsonResource([
            'redirect'     => true,
            'redirect_url' => '/admin' // 前台已停用,
        ]);
    }

    /**
     * Validate order before creation.
     *
     * ⚠ M7:每一条都抛 {@see OrderValidationException}(带错误码 + 状态码),
     *   不再抛裸 `\Exception` —— 否则调用方只能连"用户可纠正的校验失败"和
     *   "服务端真出事了"一起 catch,只能给出 500。
     *
     * @return void
     *
     * @throws OrderValidationException 任一校验不通过
     */
    public function validateOrder()
    {
        $cart = Cart::getCart();

        $minimumOrderAmount = core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0;

        if (
            auth()->guard('customer')->check()
            && auth()->guard('customer')->user()->is_suspended
        ) {
            // 账号状态类 → 409:请求没错,是与账号当前状态冲突(用户需联系客服,重试无用)
            throw new OrderValidationException(
                ApiErrorCode::ACCOUNT_SUSPENDED,
                trans('shop::app.checkout.cart.suspended-account-message'),
                409,
            );
        }

        if (
            auth()->guard('customer')->user()
            && ! auth()->guard('customer')->user()->status
        ) {
            throw new OrderValidationException(
                ApiErrorCode::ACCOUNT_INACTIVE,
                trans('shop::app.checkout.cart.inactive-account-message'),
                409,
            );
        }

        if (! Cart::haveMinimumOrderAmount()) {
            throw new OrderValidationException(
                ApiErrorCode::MINIMUM_ORDER_AMOUNT_NOT_MET,
                trans('shop::app.checkout.cart.minimum-order-message', ['amount' => core()->currency($minimumOrderAmount)]),
            );
        }

        if ($cart->haveStockableItems() && ! $cart->shipping_address) {
            throw new OrderValidationException(
                ApiErrorCode::SHIPPING_ADDRESS_REQUIRED,
                trans('shop::app.checkout.onepage.address.check-shipping-address'),
            );
        }

        if (! $cart->billing_address) {
            throw new OrderValidationException(
                ApiErrorCode::BILLING_ADDRESS_REQUIRED,
                trans('shop::app.checkout.onepage.address.check-billing-address'),
            );
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->selected_shipping_rate
        ) {
            throw new OrderValidationException(
                ApiErrorCode::SHIPPING_METHOD_REQUIRED,
                trans('shop::app.checkout.cart.specify-shipping-method'),
            );
        }

        if (! $cart->payment) {
            throw new OrderValidationException(
                ApiErrorCode::PAYMENT_METHOD_REQUIRED,
                trans('shop::app.checkout.cart.specify-payment-method'),
            );
        }
    }
}
