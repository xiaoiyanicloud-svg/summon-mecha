<?php

use Webkul\Checkout\Models\Cart;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\Customer;
use Webkul\Faker\Helpers\Product as ProductFaker;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Http\Controllers\API\OnepageController;
use Webkul\Shop\Services\OrderValidationException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 只留代码,去掉注释与空白 —— 源码层断言必须忽略注释。
 *
 * 实测踩过:不带这一步时,`ApiErrorCode` 类注释里举的 `$this->fail(ApiErrorCode::XXX, ...)`
 * 例子会被扫成"引用了不存在的常量"。
 */
function x1CodeOnly(string $path): string
{
    $out = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            // 注释整段丢掉,但保留原有行数,便于出错时定位
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/**
 * 加固第六轮 · X1 统一错误信封 + M7 结账校验不再被包成 500。
 *
 * 断言分两层:
 *   · **源码层** —— 防止有人再手写错误码 / 再拿 abort() 当失败出口(这类退化靠接口测不出来,
 *     因为"没被覆盖的那条失败分支"本来就没请求打到)。
 *   · **接口层** —— 真发请求,验证线上信封就是 {success:false, code, message}。
 */

// ───────────────────────────── 错误码登记表 ─────────────────────────────

it('X1 · ApiErrorCode 无重复取值', function () {
    // 两个常量取了同一个值,前端按码分支就会撞在一起;而常量名不同,肉眼极难发现。
    $all = ApiErrorCode::all();

    expect($all)->not->toBeEmpty()
        ->and($all)->toBe(array_unique($all));
});

it('X1 · 控制器引用的错误码全部已在登记表里定义', function () {
    // 写错常量名(如 SKILL_NOT_EXIST)在 PHP 里是**运行时**才炸的,php -l 查不出来。
    // 这条把"整包扫一遍"提前到测试里。
    $dir = dirname((new ReflectionClass(ApiErrorCode::class))->getFileName());

    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/ApiErrorCode::([A-Z_0-9]+)/', x1CodeOnly($file->getPathname()), $m);

        foreach (array_unique($m[1]) as $const) {
            if (! defined(ApiErrorCode::class.'::'.$const)) {
                $missing[] = $file->getFilename().'::'.$const;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('X1 · 失败出口只剩 fail()/reject(),没有 abort()、没有手写码', function () {
    $dir = dirname((new ReflectionClass(ApiErrorCode::class))->getFileName());

    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $src = x1CodeOnly($file->getPathname());

        // abort() 走的是 Laravel 默认错误页,不是本仓信封。
        // 排除 `public function abort(...)` —— 那是 GrayReleaseController 的路由动作名,不是 helper。
        if (preg_match('/(?<!function )(?<![a-zA-Z_>$])abort\s*\(/', $src)) {
            $offenders[] = $file->getFilename().' → abort()';
        }

        // fail()/reject() 的第一个参数必须是 ApiErrorCode::CONST,不能是字符串字面量
        if (preg_match('/\$this->(?:fail|reject)\(\s*[\'"]/', $src)) {
            $offenders[] = $file->getFilename().' → 手写错误码';
        }
    }

    expect($offenders)->toBe([]);
});

it('X1 · 失败信封带 code,且 success 固定为 false', function () {
    // ① 请求本身非法 → 422(走 reject)
    postJson('/api/tts', ['text' => ''])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'NO_TEXT')
        ->assertJsonStructure(['success', 'code', 'message']);

    // ② 资源不存在 → 404(走 fail($code,$msg,404))
    getJson('/api/cms/pages/__x1-not-exist__')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'CMS_PAGE_NOT_FOUND');
});

it('X1 · 业务性失败保持 HTTP 200 + code(TTS/ASR 的既有契约不被推翻)', function () {
    // 密钥未配置时 TTS 返回 200 + success:false + code —— utils/tts.js 就是靠这两个字段
    // 静默跳过朗读的。X1 只能"加",不能把这个契约改成 4xx。
    config(['tts.enabled' => false]);

    $res = postJson('/api/tts', ['text' => '你好'])->assertOk();

    expect($res->json('success'))->toBeFalse()
        ->and($res->json('code'))->toBe('TTS_NOT_CONFIGURED')
        ->and($res->json('message'))->not->toBe('');
});

// ───────────────────────────── M7 ─────────────────────────────

function x1SuspendedCustomer(): Customer
{
    return Customer::create([
        'first_name'   => 'Sus',
        'last_name'    => 'Pended',
        'email'        => 'x1sus'.uniqid().'@example.com',
        'password'     => bcrypt('secret123'),
        'channel_id'   => core()->getDefaultChannel()->id,
        'status'       => 1,
        'is_verified'  => 1,
        'is_suspended' => 1,
    ]);
}

it('M7 · validateOrder 抛带码的 OrderValidationException,不再是裸 Exception', function () {
    $this->actingAs(x1SuspendedCustomer(), 'customer');

    try {
        app(OnepageController::class)->validateOrder();
        $this->fail('账号已停用时 validateOrder() 应当抛异常');
    } catch (OrderValidationException $e) {
        expect($e->errorCode)->toBe(ApiErrorCode::ACCOUNT_SUSPENDED)
            ->and($e->status)->toBe(409)
            ->and($e->getMessage())->not->toBe('');
    }
});

it('M7 · 账号停用走 409 + code,不是 500', function () {
    // 必须给购物车放一件真商品:storeOrder 的第一道是 `Cart::hasError()`,
    // 没有购物车时它会直接返回 redirect,根本走不到 validateOrder。
    $product = (new ProductFaker([
        'attributes' => [5 => 'new'],
        'attribute_value' => ['new' => ['boolean_value' => true]],
    ]))
        ->getSimpleProductFactory()
        ->create();

    $customer = x1SuspendedCustomer();

    $cart = Cart::factory()->create([
        'customer_id'         => $customer->id,
        'customer_first_name' => $customer->first_name,
        'customer_last_name'  => $customer->last_name,
        'customer_email'      => $customer->email,
        'is_guest'            => 0,
    ]);

    CartItem::factory()->create([
        'cart_id'    => $cart->id,
        'product_id' => $product->id,
        'sku'        => $product->sku,
        'quantity'   => 1,
        'name'       => $product->name,
        'price'      => $product->price,
        'base_price' => $product->price,
        'total'      => $product->price,
        'base_total' => $product->price,
        'type'       => $product->type,
        'additional' => ['product_id' => $product->id, 'quantity' => 1],
    ]);

    cart()->setCart($cart);

    $this->actingAs($customer, 'customer');

    postJson(route('shop.checkout.onepage.orders.store'))
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
});

it('M7 · storeOrder 只兜 OrderValidationException —— 其它异常仍要冒到 500', function () {
    // 这是 M7 的另一半,而且更容易被改坏:有人会图省事写成 catch (\Throwable)。
    // 那样"真出事了"又会被伪装成 4xx,而 4xx 的意思是"你请求写错了"。
    $src = (string) file_get_contents(
        (new ReflectionMethod(OnepageController::class, 'storeOrder'))->getFileName()
    );

    expect($src)->toContain('catch (OrderValidationException $e)')
        ->and($src)->not->toContain('catch (\Throwable')
        ->and($src)->not->toContain('catch (\Exception');
});
