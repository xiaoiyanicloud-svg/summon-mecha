<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\API\AddressController;
use Webkul\Shop\Http\Controllers\API\CatalogController;
use Webkul\Shop\Http\Controllers\API\CartController;
use Webkul\Shop\Http\Controllers\API\CategoryController;
use Webkul\Shop\Http\Controllers\API\CompareController;
use Webkul\Shop\Http\Controllers\API\CoreController;
use Webkul\Shop\Http\Controllers\API\CustomerController;
use Webkul\Shop\Http\Controllers\API\OnepageController;
use Webkul\Shop\Http\Controllers\API\OrderController;
use Webkul\Shop\Http\Controllers\API\ProductController;
use Webkul\Shop\Http\Controllers\API\ReviewController;
use Webkul\Shop\Http\Controllers\API\SceneController;
use Webkul\Shop\Http\Controllers\API\TobOrderController;
use Webkul\Shop\Http\Controllers\API\WishlistController;

Route::group(['prefix' => 'api'], function () {
    Route::controller(CoreController::class)->prefix('core')->group(function () {
        Route::get('countries', 'getCountries')->name('shop.api.core.countries');

        Route::get('states', 'getStates')->name('shop.api.core.states');
    });

    Route::controller(CategoryController::class)->prefix('categories')->group(function () {
        Route::get('', 'index')->name('shop.api.categories.index');

        Route::get('tree', 'tree')->name('shop.api.categories.tree');

        Route::get('attributes', 'getAttributes')->name('shop.api.categories.attributes');

        Route::get('attributes/{attribute_id}/options', 'getAttributeOptions')->name('shop.api.categories.attribute_options');

        Route::get('max-price/{id?}', 'getProductMaxPrice')->name('shop.api.categories.max_price');
    });

    Route::controller(ProductController::class)->prefix('products')->group(function () {
        Route::get('', 'index')->name('shop.api.products.index');

        Route::get('{id}', 'show')->name('shop.api.products.show');

        Route::get('{id}/related', 'relatedProducts')->name('shop.api.products.related.index');

        Route::get('{id}/up-sell', 'upSellProducts')->name('shop.api.products.up-sell.index');
    });

    Route::controller(ReviewController::class)->prefix('product/{id}')->group(function () {
        Route::get('reviews', 'index')->name('shop.api.products.reviews.index');

        Route::post('review', 'store')->name('shop.api.products.reviews.store');

        Route::get('reviews/{review_id}/translate', 'translate')->name('shop.api.products.reviews.translate');
    });

    // 场景(Scene):机器页「场景」Tab 卡片列表 + TOB 场景详情(后台维护图 + 描述)
    Route::controller(SceneController::class)->prefix('scenes')->group(function () {
        Route::get('', 'index')->name('shop.api.scenes.index');
        Route::get('{id}', 'show')->name('shop.api.scenes.show');
    });

    // 目录聚合:1 个请求返回租用/购买/配件/技能四类商品 + 场景(机器页性能优化,替代 6 连发)
    Route::controller(CatalogController::class)->prefix('tob-catalog')->group(function () {
        Route::get('', 'index')->name('shop.api.tob-catalog.index');
    });

    Route::controller(CompareController::class)->prefix('compare-items')->group(function () {
        Route::get('', 'index')->name('shop.api.compare.index');

        Route::post('', 'store')->name('shop.api.compare.store');

        Route::delete('', 'destroy')->name('shop.api.compare.destroy');

        Route::delete('all', 'destroyAll')->name('shop.api.compare.destroy_all');
    });

    Route::controller(CartController::class)->prefix('checkout/cart')->group(function () {
        Route::get('', 'index')->name('shop.api.checkout.cart.index');

        Route::post('', 'store')->name('shop.api.checkout.cart.store');

        Route::put('', 'update')->name('shop.api.checkout.cart.update');

        Route::delete('', 'destroy')->name('shop.api.checkout.cart.destroy');

        Route::delete('selected', 'destroySelected')->name('shop.api.checkout.cart.destroy_selected');

        Route::post('move-to-wishlist', 'moveToWishlist')->name('shop.api.checkout.cart.move_to_wishlist');

        Route::post('coupon', 'storeCoupon')->name('shop.api.checkout.cart.coupon.apply');

        Route::post('estimate-shipping-methods', 'estimateShippingMethods')->name('shop.api.checkout.cart.estimate_shipping');

        Route::delete('coupon', 'destroyCoupon')->name('shop.api.checkout.cart.coupon.remove');

        Route::get('cross-sell', 'crossSellProducts')->name('shop.api.checkout.cart.cross-sell.index');
    });

    Route::controller(OnepageController::class)->prefix('checkout/onepage')->group(function () {
        Route::get('summary', 'summary')->name('shop.checkout.onepage.summary');

        Route::post('addresses', 'storeAddress')->name('shop.checkout.onepage.addresses.store');

        Route::post('shipping-methods', 'storeShippingMethod')->name('shop.checkout.onepage.shipping_methods.store');

        Route::post('payment-methods', 'storePaymentMethod')->name('shop.checkout.onepage.payment_methods.store');

        Route::post('orders', 'storeOrder')->name('shop.checkout.onepage.orders.store');
    });

    // TOB 统一下单:租/买/配件/技能等直接创建真实后台订单(不依赖购物车/配送/支付配置)
    // api.customer 中间件:带 token 则绑定登录用户(customer_id 正确→前端订单页能按用户拉到);游客也放行
    Route::controller(TobOrderController::class)->prefix('tob-order')->middleware(['api.customer'])->group(function () {
        Route::post('', 'store')->name('shop.api.tob-order.store');
    });

    /**
     * Login routes.
     */
    Route::controller(CustomerController::class)->prefix('customer')->group(function () {
        Route::post('login', 'login')->name('shop.api.customers.session.create');

        Route::post('register', 'register')->name('shop.api.customers.register');
    });

    Route::group(['middleware' => ['customer'], 'prefix' => 'customer'], function () {
        Route::post('logout', [CustomerController::class, 'logout'])->name('shop.api.customers.logout');

        Route::controller(AddressController::class)->prefix('addresses')->group(function () {
            Route::get('', 'index')->name('shop.api.customers.account.addresses.index');

            Route::post('', 'store')->name('shop.api.customers.account.addresses.store');

            Route::put('edit/{id?}', 'update')->name('shop.api.customers.account.addresses.update');

            Route::delete('{id}', 'destroy')->name('shop.api.customers.account.addresses.destroy');
        });

        Route::controller(WishlistController::class)->prefix('wishlist')->group(function () {
            Route::get('', 'index')->name('shop.api.customers.account.wishlist.index');

            Route::post('', 'store')->name('shop.api.customers.account.wishlist.store');

            Route::post('{id}/move-to-cart', 'moveToCart')->name('shop.api.customers.account.wishlist.move_to_cart');

            Route::delete('all', 'destroyAll')->name('shop.api.customers.account.wishlist.destroy_all');

            Route::delete('{id}', 'destroy')->name('shop.api.customers.account.wishlist.destroy');
        });

        Route::controller(OrderController::class)->prefix('orders')->group(function () {
            Route::get('', 'index')->name('shop.api.customers.orders.index');

            Route::get('{id}', 'show')->name('shop.api.customers.orders.show');
        });
    });
});
