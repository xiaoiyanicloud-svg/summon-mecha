<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shop\Http\Controllers\API\AddressController;
use Webkul\Shop\Http\Controllers\API\AfterSaleController;
use Webkul\Shop\Http\Controllers\API\AgentController;
use Webkul\Shop\Http\Controllers\API\DeployController;
use Webkul\Shop\Http\Controllers\API\NotificationController;
use Webkul\Shop\Http\Controllers\API\PaymentCallbackController;
use Webkul\Shop\Http\Controllers\API\PaymentController;
use Webkul\Shop\Http\Controllers\API\AsrController;
use Webkul\Shop\Http\Controllers\API\CatalogController;
use Webkul\Shop\Http\Controllers\API\CartController;
use Webkul\Shop\Http\Controllers\API\CategoryController;
use Webkul\Shop\Http\Controllers\API\CmsPageController;
use Webkul\Shop\Http\Controllers\API\EventController;
use Webkul\Shop\Http\Controllers\API\CompareController;
use Webkul\Shop\Http\Controllers\API\CoreController;
use Webkul\Shop\Http\Controllers\API\CustomerController;
use Webkul\Shop\Http\Controllers\API\Dev\AgreementController as DevAgreementController;
use Webkul\Shop\Http\Controllers\API\Dev\AuditController as DevAuditController;
use Webkul\Shop\Http\Controllers\API\Dev\AuthController as DevAuthController;
use Webkul\Shop\Http\Controllers\API\Dev\DeveloperSkillController;
use Webkul\Shop\Http\Controllers\API\Dev\ProfileController as DevProfileController;
use Webkul\Shop\Http\Controllers\API\DeviceController;
use Webkul\Shop\Http\Controllers\API\GrayReleaseController;
use Webkul\Shop\Http\Controllers\API\IncidentController;
use Webkul\Shop\Http\Controllers\API\OnepageController;
use Webkul\Shop\Http\Controllers\API\OrderController;
use Webkul\Shop\Http\Controllers\API\ProductController;
use Webkul\Shop\Http\Controllers\API\ReviewController;
use Webkul\Shop\Http\Controllers\API\SceneController;
use Webkul\Shop\Http\Controllers\API\SkillAdminController;
use Webkul\Shop\Http\Controllers\API\SkillCatalogController;
use Webkul\Shop\Http\Controllers\API\SkillController;
use Webkul\Shop\Http\Controllers\API\SkillSupplyController;
use Webkul\Shop\Http\Controllers\API\SummonAiController;
use Webkul\Shop\Http\Controllers\API\SummonController;
use Webkul\Shop\Http\Controllers\API\TicketController;
use Webkul\Shop\Http\Controllers\API\TobOrderController;
use Webkul\Shop\Http\Controllers\API\TtsController;
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

    /**
     * 静态内容页（隐私政策 / 使用条款 / 关于我们 …）—— uni-app 的只读出口。
     *
     * ⚠ 公开路由、**不挂鉴权**：这些页面在网页端 `/page/{slug}` 本来就公开可读，
     *   这里是那条路由的 JSON 镜像（同样的频道过滤 + `url_key` 取翻译），
     *   因此**不比网页多暴露一页**。
     *
     * 存在的理由：App 不得在自己代码里写死协议正文，否则就有了第二个正文出处 ——
     * 律师定稿后改了后台，网页端更新了、App 还显示旧条款，没人能发现。
     */
    Route::controller(CmsPageController::class)->prefix('cms/pages')->group(function () {
        Route::get('{slug}', 'show')
            ->where('slug', '[A-Za-z0-9_-]+')
            ->name('shop.api.cms.pages.show');
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

    // 小召·召唤引擎:意图→场景→机型(确定性规则匹配 v1,后续可平滑升级 LLM)
    Route::post('summon', [SummonController::class, 'summon'])->name('shop.api.summon');

    // 小召·召唤引擎 v2(AI 版,Coze Agent 驱动)——独立新增,不影响上面的 rule-v1
    Route::post('summon-ai', [SummonAiController::class, 'summon'])->name('shop.api.summon-ai');

    // 小召·召唤引擎 v2 流式版(SSE):边生成边返回,改善 8~20 秒的等待体验
    Route::post('summon-stream', [SummonAiController::class, 'summonStream'])->name('shop.api.summon-stream');

    // 语音识别:录音上传 → 腾讯云「一句话识别」→ 文字。
    // 两端共用的兜底链路:H5 的唯一路径;小程序优先走微信「同声传译」插件,插件不可用时走这里。
    // 公开路由(不挂 middleware)——语音是游客也能用的入口,与上面的 summon 一致。
    // 密钥未配置时返回 code=ASR_NOT_CONFIGURED,不报错。
    Route::post('asr', [AsrController::class, 'recognize'])->name('shop.api.asr');

    // 语音合成:AI 回复正文 → 腾讯云「语音合成」→ base64 音频数组,前端顺序播放。
    // 客户端不直连(代理的用意就是不让 AK/SK 进小程序包),超长文本由后端拆段。
    // 公开路由(不挂 middleware),与 asr / summon 一致 —— 朗读是游客也能用的附加功能。
    // 密钥未配置时返回 code=TTS_NOT_CONFIGURED;资源包用尽返回 TTS_FAILED,前端静默跳过。
    // ⚠ 前端可能把它指到**另一个后端进程**(见 utils/config.js 的 TTS_BASE_URL 注释)。
    Route::post('tts', [TtsController::class, 'synthesize'])->name('shop.api.tts');

    // 赛事(Event):生态中心「赛事活动」tab + 真实报名(平台级闭环)
    Route::controller(EventController::class)->prefix('events')->group(function () {
        Route::get('', 'index')->name('shop.api.events.index');
        Route::get('{id}', 'show')->name('shop.api.events.show');
        Route::post('{id}/register', 'register')->name('shop.api.events.register')->middleware(['api.customer']);
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

    // 游客订单列表(放行,无需登录):返回本机游客单——场景租用游客单也在此通用查询,前端订单页无登录也能显示真实单
    Route::controller(OrderController::class)->prefix('guest-orders')->middleware(['api.customer'])->group(function () {
        Route::get('', 'guestIndex')->name('shop.api.guest-orders.index');
        // 游客自助取消(游客单 customer_id 为空)——api.customer 放行,与登录 cancel 逻辑一致(复用 cancel)
        Route::post('{id}/cancel', 'cancel')->name('shop.api.guest-orders.cancel');
    });

    /**
     * 技能上传通道 —— 本项目第一个商品写接口。
     *
     * 鉴权走 internal.key(X-Internal-Key),**不挂 api.customer**:
     * 那是买家身份,上传方是平台/合作方系统,两者身份模型不同。
     * 未配置 SKILL_UPLOAD_KEY 时整组返回 503。
     */
    Route::controller(SkillAdminController::class)->prefix('skill-admin')->middleware(['internal.key'])->group(function () {
        Route::post('skills/{productId}/versions', 'storeVersion')->name('shop.api.skill-admin.versions.store');

        Route::post('versions/{versionId}/assets', 'storeAsset')->name('shop.api.skill-admin.assets.store');

        // 安装指引/变更日志往往在传完包之后才写,而 storeVersion 是幂等的 firstOrCreate,
        // 所以单独开一个更新口
        Route::put('versions/{versionId}', 'updateVersion')->name('shop.api.skill-admin.versions.update');

        Route::post('versions/{versionId}/publish', 'publish')->name('shop.api.skill-admin.versions.publish');

        Route::get('skills/{productId}/manifest', 'manifest')->name('shop.api.skill-admin.manifest');
    });

    /**
     * 我的设备 —— 用户绑定并管理自己实际拥有的机器。
     * 技能交易强制登录,故统一要求 customer 身份。
     */
    Route::controller(DeviceController::class)->prefix('devices')->middleware(['customer'])->group(function () {
        Route::get('', 'index')->name('shop.api.devices.index');

        Route::post('', 'store')->name('shop.api.devices.store');

        Route::put('{id}', 'update')->name('shop.api.devices.update');

        Route::delete('{id}', 'destroy')->name('shop.api.devices.destroy');

        // v3.4 步骤 8:签发一次性 Agent 安装 token(供 agent-setup 页展示安装命令)
        Route::post('{id}/agent-token', 'agentToken')->name('shop.api.devices.agent-token');
    });

    /**
     * 我的技能 —— 授权列表与技能包的鉴权下载(文件在 private 盘,无公开 URL)。
     */
    Route::controller(SkillController::class)->prefix('skills')->middleware(['customer'])->group(function () {
        Route::get('mine', 'index')->name('shop.api.skills.mine');

        // v3.4 步骤 5:兼容性校验(机型 × 技能通道)
        Route::get('{productId}/compatibility', 'compatibility')->name('shop.api.skills.compatibility');

        Route::get('entitlements/{entitlementId}/assets', 'assets')->name('shop.api.skills.assets');

        Route::get('entitlements/{entitlementId}/assets/{assetId}/download', 'download')->name('shop.api.skills.download');

        Route::put('entitlements/{entitlementId}/bind', 'bindDevice')->name('shop.api.skills.bind');

        // 兜底通道终点:用户自报"已装到机器上"。平台无法验证,见控制器注释。
        Route::post('entitlements/{entitlementId}/confirm-install', 'confirmInstall')->name('shop.api.skills.confirm-install');
    });

    /**
     * v3.4 步骤 4 · 支付下单(资质未就绪时走 mock 网关,见 config/payment_gateways.php)。
     */
    Route::controller(PaymentController::class)->prefix('payment')->middleware(['customer'])->group(function () {
        Route::post('orders/{orderId}', 'create')->name('shop.api.payment.create');
    });

    /**
     * 支付回调 —— 不挂 customer 鉴权(来自支付网关)。生产须验签 + 幂等。
     */
    Route::post('callback/payment', [PaymentCallbackController::class, 'handle'])->name('shop.api.callback.payment');

    /**
     * v3.4 步骤 4 · 售后/退款(虚拟商品政策)。
     */
    Route::controller(AfterSaleController::class)->prefix('after-sale')->middleware(['customer'])->group(function () {
        Route::get('', 'index')->name('shop.api.after-sale.index');
        Route::post('', 'store')->name('shop.api.after-sale.store');
    });

    /**
     * v3.4 步骤 4 · 通知中心(站内信;订阅消息待小程序配置)。
     */
    Route::controller(NotificationController::class)->prefix('notifications')->middleware(['customer'])->group(function () {
        Route::get('', 'index')->name('shop.api.notifications.index');
        Route::put('{id}/read', 'markRead')->name('shop.api.notifications.read');
    });

    /**
     * v3.4 步骤 6 · 技能类目/标签(公开读取)。
     */
    Route::controller(SkillCatalogController::class)->prefix('skill-catalog')->group(function () {
        Route::get('categories', 'categories')->name('shop.api.skill-catalog.categories');
        Route::get('tags', 'tags')->name('shop.api.skill-catalog.tags');
        Route::get('search', 'search')->name('shop.api.skill-catalog.search');
    });

    /**
     * v3.4 步骤 7 · 客服工单(用户侧)。
     */
    Route::controller(TicketController::class)->prefix('tickets')->middleware(['customer'])->group(function () {
        Route::get('', 'index')->name('shop.api.tickets.index');
        Route::post('', 'store')->name('shop.api.tickets.store');
        Route::get('{id}', 'show')->name('shop.api.tickets.show');
    });

    /**
     * 事故上报(用户侧)—— 计划 §4.19。
     *
     * 技能是在真实机器人上物理执行的,出事故必须有可追溯的上报链。
     * 证据链在报案那一刻由 IncidentService::traceFor() 固化。
     */
    Route::controller(IncidentController::class)->prefix('incidents')->middleware(['customer'])->group(function () {
        Route::get('', 'index')->name('shop.api.incidents.index');
        Route::post('', 'store')->name('shop.api.incidents.store');
        Route::get('{id}', 'show')->name('shop.api.incidents.show');
    });

    /**
     * v3.4 步骤 8 · 下发任务(用户侧)。
     */
    Route::controller(DeployController::class)->prefix('deploy')->middleware(['customer'])->group(function () {
        Route::post('tasks', 'store')->name('shop.api.deploy.store');
        Route::get('tasks/{taskNo}', 'show')->name('shop.api.deploy.show');
        Route::post('tasks/{taskNo}/cancel', 'cancel')->name('shop.api.deploy.cancel');
        Route::get('devices/{deviceId}/installs', 'installs')->name('shop.api.deploy.installs');
    });

    /**
     * v3.4 步骤 8 · Agent 接入(设备端/模拟器,共享密钥鉴权)。
     */
    Route::controller(AgentController::class)->prefix('agent')->middleware(['agent.key'])->group(function () {
        Route::post('register', 'register')->name('shop.api.agent.register');
        Route::post('heartbeat', 'heartbeat')->name('shop.api.agent.heartbeat');
        Route::post('evt', 'event')->name('shop.api.agent.event');
        Route::get('commands', 'commands')->name('shop.api.agent.commands');
    });

    /**
     * v3.4 步骤 8 · Agent 引导(façade)—— 凭一次性 enrollment token,故意**不挂 agent.key**。
     * 装上 Agent 前设备还没有共享密钥,这是唯一入口。
     */
    Route::controller(AgentController::class)->prefix('agent')->group(function () {
        Route::post('enroll', 'enroll')->name('shop.api.agent.enroll');
    });

    /**
     * v3.4 步骤 6 · 技能供给侧管控(internal.key)—— 包检查/导入/编译/提审/审核。
     */
    Route::controller(SkillSupplyController::class)->prefix('skill-supply')->middleware(['internal.key'])->group(function () {
        Route::post('inspect', 'inspect')->name('shop.api.skill-supply.inspect');
        Route::post('import', 'import')->name('shop.api.skill-supply.import');
        Route::post('versions/{versionId}/compile', 'compile')->name('shop.api.skill-supply.compile');
        Route::post('versions/{versionId}/submit', 'submit')->name('shop.api.skill-supply.submit');
        Route::post('versions/{versionId}/review', 'review')->name('shop.api.skill-supply.review');
        Route::get('versions/{versionId}/builds', 'builds')->name('shop.api.skill-supply.builds');
        // v3.4 步骤 8:熔断
        Route::post('skills/{skillId}/block', 'block')->name('shop.api.skill-supply.block');
        Route::post('skills/{skillId}/lift', 'lift')->name('shop.api.skill-supply.lift');
    });

    /**
     * v3.4 步骤 8 · 灰度发布 / 自动回滚(internal.key,平台端运维)。
     */
    Route::controller(GrayReleaseController::class)->prefix('skill-supply/gray-releases')->middleware(['internal.key'])->group(function () {
        Route::post('', 'store')->name('shop.api.gray-releases.store');
        Route::get('{id}', 'show')->name('shop.api.gray-releases.show');
        Route::post('{id}/metrics', 'metrics')->name('shop.api.gray-releases.metrics');
        Route::post('{id}/abort', 'abort')->name('shop.api.gray-releases.abort');
    });

    /**
     * v3.4 步骤 9 · 开发者门户对外 API（只认 Bearer token，见 ResolveDeveloper）。
     *
     * 放在既有 `api` 组内，继承其 `api.customer` 等中间件 —— 独立新组最容易漏挂中间件。
     * 这一层是**对外合同**：将来门户换 SPA / 小程序 web-view 直接消费，数据层不用动。
     *
     * 留位（本轮不实现，仅登记路径占位）：team / apps / credentials / analytics / finance / tools / beta
     */
    Route::controller(DevAuthController::class)->prefix('dev/auth')->group(function () {
        // 发 token：凭账号密码，故不挂开发者中间件；限流防撞库
        Route::post('token', 'token')->middleware('throttle:10,1')->name('shop.api.dev.auth.token');
        Route::post('logout', 'logout')->middleware(['api.developer.login'])->name('shop.api.dev.auth.logout');
    });

    /**
     * ⚠ 入驻端点挂的是**轻量**中间件 `api.developer.login`（只要求 token 有效）。
     *   曾经挂 `api.developer`，结果是**死锁**：中间件因"没有 developer_profile"返回 403，
     *   而创建 profile 的正是这个请求 —— 新开发者永远无法入驻。
     *   Web 侧一直用 `developer.session` 避开了这个坑（见 Routes/dev.php 注释），
     *   这里补上 API 侧的对应物。
     */
    Route::controller(DevProfileController::class)->prefix('dev')->middleware(['api.developer.login'])->group(function () {
        Route::get('profile', 'show')->name('shop.api.dev.profile.show');
        Route::post('profile', 'store')->name('shop.api.dev.profile.store');
    });

    Route::controller(DevAgreementController::class)->prefix('dev')->middleware(['api.developer'])->group(function () {
        Route::get('agreements', 'index')->name('shop.api.dev.agreements.index');
        Route::post('agreements', 'store')->name('shop.api.dev.agreements.store');
    });

    Route::controller(DeveloperSkillController::class)->prefix('dev')->middleware(['api.developer'])->group(function () {
        Route::get('skills', 'index')->name('shop.api.dev.skills.index');
        Route::post('skills', 'store')->name('shop.api.dev.skills.store');
        Route::get('skills/{skillId}', 'show')->whereNumber('skillId')->name('shop.api.dev.skills.show');
        Route::post('skills/{skillId}/versions', 'storeVersion')->whereNumber('skillId')->name('shop.api.dev.skills.versions.store');

        Route::post('versions/{versionId}/assets', 'storeAsset')->whereNumber('versionId')->name('shop.api.dev.versions.assets.store');
        Route::post('versions/{versionId}/submit', 'submit')->whereNumber('versionId')->name('shop.api.dev.versions.submit');
    });

    Route::controller(DevAuditController::class)->prefix('dev')->middleware(['api.developer'])->group(function () {
        Route::get('audits', 'index')->name('shop.api.dev.audits.index');
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
            Route::post('{id}/cancel', 'cancel')->name('shop.api.customers.orders.cancel');
        });
    });
});
