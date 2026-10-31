<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;


use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Webkul\CartRule\Repositories\CartRuleCouponRepository;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartAddress;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shipping\Facades\Shipping;
use Webkul\Shop\Http\Resources\CartResource;
use Webkul\Shop\Http\Resources\ProductResource;

class CartController extends APIController
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected CartRuleCouponRepository $cartRuleCouponRepository,
        protected CartRepository $cartRepository
    ) {}

    /**
     * Cart.
     */
    public function index(): JsonResource
    {
        $this->resolveCart();

        Cart::collectTotals();

        $response = [
            'data' => ($cart = Cart::getCart()) ? new CartResource($cart) : null,
        ];

        if (session()->has('info')) {
            $response['message'] = session()->get('info');
        }

        return new JsonResource($response);
    }

    /**
     * Store items in cart.
     */
    public function store()
    {
        $this->resolveCart();

        $this->validate(request(), [
            'product_id' => 'required|integer|exists:products,id',
            'is_buy_now' => 'integer|in:0,1',
            'quantity'   => 'integer|min:1',
            'order_type' => 'nullable|in:rent,buy,accessory,skill',
            'rent_days'  => 'nullable|integer|min:1|max:3650',
            'rent_period'=> 'nullable|string|max:64',
            'start_date' => 'nullable|date_format:Y-m-d',
            'product_type' => 'nullable|string|max:64',
        ]);

        $problem = DB::transaction(fn () => app(SkillMarketService::class)->purchaseProblem(
            [(int) request()->input('product_id')], auth()->guard('customer')->id()
        ));
        if ($problem) {
            return $this->fail($problem['code'], $problem['message'], $problem['status']);
        }

        $product = $this->productRepository->with('parent')->findOrFail(request()->input('product_id'));

        try {
            if (! $product->status) {
                throw new \Exception(trans('shop::app.checkout.cart.inactive-add'));
            }

            $response = [];

            if (request()->get('is_buy_now')) {
                Cart::deActivateCart();

                $response['redirect'] = '/admin'; // 前台已停用
            }

            $cart = Cart::addProduct($product, request()->all());

            $this->stampCartIdentity();
            $this->stampItemMetadata((int) $product->id);

            return new JsonResource(array_merge([
                'data'    => new CartResource($cart),
                'message' => trans('shop::app.checkout.cart.item-add-to-cart'),
            ], $response));
        } catch (\Exception $exception) {
            // X1:统一信封。redirect_uri 是既有契约(前台已停用 → /admin),放进 extra 不丢。
            return $this->fail(ApiErrorCode::CART_ADD_FAILED, $exception->getMessage(), Response::HTTP_BAD_REQUEST, [
                'redirect_uri' => '/admin', // 前台已停用
            ]);
        }
    }

    /**
     * Removes the item from the cart if it exists.
     */
    public function destroy(): JsonResource
    {
        $this->resolveCart();

        $this->validate(request(), [
            'cart_item_id' => 'required|exists:cart_items,id',
        ]);

        $this->assertCartItemBelongsToCurrentCart((int) request()->input('cart_item_id'));

        Cart::removeItem(request()->input('cart_item_id'));

        Cart::collectTotals();

        return new JsonResource([
            'data'    => new CartResource(Cart::getCart()),
            'message' => trans('shop::app.checkout.cart.success-remove'),
        ]);
    }

    /**
     * Method for remove selected items from cart
     */
    public function destroySelected(): JsonResource
    {
        $this->resolveCart();

        foreach ((array) request()->input('ids') as $id) {
            $this->assertCartItemBelongsToCurrentCart((int) $id);
            Cart::removeItem($id);
        }

        return new JsonResource([
            'data'     => new CartResource(Cart::getCart()) ?? null,
            'message'  => trans('shop::app.checkout.cart.index.remove-selected-success'),
        ]);
    }

    /**
     * Method for move to wishlist selected items from cart
     */
    public function moveToWishlist(): JsonResource
    {
        $this->resolveCart();

        foreach (request()->input('ids') as $index => $id) {
            $qty = request()->input('qty')[$index];

            Cart::moveToWishlist($id, $qty);
        }

        return new JsonResource([
            'data'     => new CartResource(Cart::getCart()) ?? null,
            'message'  => trans('shop::app.checkout.cart.index.move-to-wishlist-success'),
        ]);
    }

    /**
     * Updates the quantity of the items present in the cart.
     */
    public function update(): JsonResource
    {
        $this->resolveCart();

        try {
            foreach (array_keys((array) request()->input('qty', [])) as $itemId) {
                $this->assertCartItemBelongsToCurrentCart((int) $itemId);
            }

            Cart::updateItems(request()->input());

            return new JsonResource([
                'data'    => new CartResource(Cart::getCart()),
                'message' => trans('shop::app.checkout.cart.index.quantity-update'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResource([
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Estimate Shipping and Tax amount
     */
    public function estimateShippingMethods(): JsonResource
    {
        $this->resolveCart();

        $this->validate(request(), [
            'country'         => 'required',
            'state'           => 'required',
            'postcode'        => 'required',
            'shipping_method' => 'sometimes|required',
        ]);

        $cart = Cart::getCart();

        $address = (new CartAddress)->fill([
            'country'  => request()->input('country'),
            'state'    => request()->input('state'),
            'postcode' => request()->input('postcode'),
            'cart_id'  => $cart->id,
        ]);

        $cart->setRelation('billing_address', $address);

        $cart->setRelation('shipping_address', $address);

        Cart::setCart($cart);

        if (request()->has('shipping_method')) {
            Cart::saveShippingMethod(request()->input('shipping_method'));
        }

        Cart::collectTotals();

        $cartResource = (new CartResource(Cart::getCart()))->jsonSerialize();

        return new JsonResource([
            'data'     => [
                'cart'             => $cartResource,
                'shipping_methods' => array_values(Shipping::collectRates()['shippingMethods']),
            ],
        ]);
    }

    /**
     * Apply coupon to the cart.
     */
    public function storeCoupon()
    {
        $this->resolveCart();

        $validatedData = $this->validate(request(), [
            'code' => 'required',
        ]);

        try {
            if (strlen($validatedData['code'])) {
                $coupon = $this->cartRuleCouponRepository->findOneByField('code', $validatedData['code']);

                if (! $coupon) {
                    return $this->fail(ApiErrorCode::COUPON_INVALID, trans('shop::app.checkout.coupon.invalid'), Response::HTTP_UNPROCESSABLE_ENTITY, [
                        'data' => new CartResource(Cart::getCart()),
                    ]);
                }

                if ($coupon->cart_rule->status) {
                    if (Cart::getCart()->coupon_code == $coupon->code) {
                        return $this->fail(ApiErrorCode::COUPON_ALREADY_APPLIED, trans('shop::app.checkout.coupon.already-applied'), Response::HTTP_UNPROCESSABLE_ENTITY, [
                            'data' => new CartResource(Cart::getCart()),
                        ]);
                    }

                    Cart::setCouponCode($coupon->code)->collectTotals();

                    if (Cart::getCart()->coupon_code == $coupon->code) {
                        return new JsonResource([
                            'data'     => new CartResource(Cart::getCart()),
                            'message'  => trans('shop::app.checkout.coupon.success-apply'),
                        ]);
                    }
                }

                return $this->fail(ApiErrorCode::COUPON_NOT_FOUND, trans('Coupon not found.'), Response::HTTP_UNPROCESSABLE_ENTITY, [
                    'data' => new CartResource(Cart::getCart()),
                ]);
            }
        } catch (\Exception $e) {
            return $this->fail(ApiErrorCode::COUPON_APPLY_FAILED, trans('shop::app.checkout.coupon.error'), Response::HTTP_INTERNAL_SERVER_ERROR, [
                'data' => new CartResource(Cart::getCart()),
            ]);
        }
    }

    /**
     * Remove applied coupon from the cart.
     */
    public function destroyCoupon(): JsonResource
    {
        $this->resolveCart();

        Cart::removeCouponCode()->collectTotals();

        return new JsonResource([
            'data'     => new CartResource(Cart::getCart()),
            'message'  => trans('shop::app.checkout.coupon.remove'),
        ]);
    }

    /**
     * Cross-sell product listings.
     *
     * @return \Illuminate\Http\Resources\Json\JsonResource::collection
     */
    public function crossSellProducts()
    {
        $this->resolveCart();

        $cart = Cart::getCart();

        if (! $cart) {
            return new JsonResource([
                'data' => [],
            ]);
        }

        $productIds = $cart->items->pluck('product_id')->toArray();

        $products = $this->productRepository
            ->select('products.*', 'product_cross_sells.child_id')
            ->join('product_cross_sells', 'products.id', '=', 'product_cross_sells.child_id')
            ->whereIn('product_cross_sells.parent_id', $productIds)
            ->whereNotIn('product_cross_sells.child_id', $productIds)
            ->groupBy('product_cross_sells.child_id')
            ->take(core()->getConfigData('catalog.products.cart_view_page.no_of_cross_sells_products'))
            ->get();

        return ProductResource::collection($products);
    }
    /**
     * API 请求没有稳定 PHP Session，游客购物车必须以 X-Guest-Key 归属。
     * 登录用户则以 customer_id 归属，避免“加购成功、刷新即空”。
     */
    private function resolveCart(): void
    {
        $customer = auth()->guard('customer')->user();

        if ($customer) {
            $cart = $this->cartRepository->findOneWhere([
                'customer_id' => $customer->id,
                'is_active'   => 1,
            ]);

            if ($cart) {
                Cart::setCart($cart);
            }

            return;
        }

        $guestKey = $this->guestKey(request());

        if (! $guestKey) {
            return;
        }

        $cart = $this->cartRepository->findOneWhere([
            'guest_key' => $guestKey,
            'is_active' => 1,
        ]);

        if ($cart) {
            Cart::setCart($cart);
        } else {
            Cart::resetCart();
        }
    }

    private function stampCartIdentity(): void
    {
        $cart = Cart::getCart();

        if (! $cart) {
            return;
        }

        $customer = auth()->guard('customer')->user();
        $identity = $customer
            ? [
                'is_guest'            => 0,
                'customer_id'         => $customer->id,
                'customer_first_name' => $customer->first_name,
                'customer_last_name'  => $customer->last_name,
                'customer_email'      => $customer->email,
                'guest_key'           => null,
            ]
            : [
                'is_guest'   => 1,
                'customer_id'=> null,
                'guest_key'  => $this->guestKey(request()),
            ];

        if (! $customer && ! $identity['guest_key']) {
            return;
        }

        $updated = $this->cartRepository->update($identity, $cart->id);

        Cart::setCart($updated);
    }

    private function stampItemMetadata(int $productId): void
    {
        $cart = Cart::getCart();

        if (! $cart) {
            return;
        }

        $item = $cart->items()
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            return;
        }

        $additional = is_array($item->additional) ? $item->additional : [];
        $summon = array_filter([
            'order_type'   => request()->input('order_type'),
            'rent_days'    => request()->input('rent_days'),
            'rent_period'  => request()->input('rent_period'),
            'start_date'   => request()->input('start_date'),
            'product_type' => request()->input('product_type'),
        ], fn ($value) => $value !== null && $value !== '');

        $additional['summon'] = array_merge($additional['summon'] ?? [], $summon);
        $item->additional = $additional;
        $item->save();
    }

    private function assertCartItemBelongsToCurrentCart(int $itemId): void
    {
        $cart = Cart::getCart();

        abort_if(! $cart || ! $cart->items()->whereKey($itemId)->exists(), 404, '购物车条目不存在');
    }
}
