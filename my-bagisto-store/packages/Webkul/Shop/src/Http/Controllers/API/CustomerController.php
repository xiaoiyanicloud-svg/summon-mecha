<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Shop\Http\Requests\Customer\LoginRequest;

class CustomerController extends APIController
{
    /**
     * X3 · 连续失败达到该次数即锁定(限速之外的慢速撞库防线)。
     */
    public const MAX_LOGIN_FAILURES = 5;

    /**
     * X3 · 锁定与失败计数的存活时长(分钟)。
     */
    public const LOGIN_LOCK_MINUTES = 15;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository
    ) {}

    /**
     * Login Customer
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        // X3 · 失败计数锁定:限速挡不住"慢速撞库"(每 IP 低频、分散 IP)。
        // 以 email+IP 为单位累计连续失败,达到阈值即锁定一段时间,不再尝试。
        $lockKey = 'login:fail:'.sha1(Str::lower((string) $request->input('email')).'|'.$request->ip());

        if (Cache::get($lockKey.':lock')) {
            return $this->fail(ApiErrorCode::ACCOUNT_LOCKED, '失败次数过多,账号已临时锁定,请稍后再试。', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (! auth()->guard('customer')->attempt($request->only(['email', 'password']))) {
            $fails = (int) Cache::get($lockKey, 0) + 1;
            Cache::put($lockKey, $fails, now()->addMinutes(15));

            if ($fails >= self::MAX_LOGIN_FAILURES) {
                Cache::put($lockKey.':lock', true, now()->addMinutes(self::LOGIN_LOCK_MINUTES));
                Cache::forget($lockKey);
            }

            return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, trans('shop::app.customers.login-form.invalid-credentials'), Response::HTTP_FORBIDDEN);
        }

        // 登录成功:清掉失败计数
        Cache::forget($lockKey);

        if (! auth()->guard('customer')->user()->status) {
            auth()->guard('customer')->logout();

            return $this->fail(ApiErrorCode::ACCOUNT_NOT_ACTIVATED, trans('shop::app.customers.login-form.not-activated'), Response::HTTP_FORBIDDEN);
        }

        if (! auth()->guard('customer')->user()->is_verified) {
            Cookie::queue(Cookie::make('enable-resend', 'true', 1));

            Cookie::queue(Cookie::make('email-for-resend', $request->get('email'), 1));

            auth()->guard('customer')->logout();

            return $this->fail(ApiErrorCode::ACCOUNT_NOT_VERIFIED, trans('shop::app.customers.login-form.verify-first'), Response::HTTP_FORBIDDEN);
        }

        /**
         * Event passed to prepare cart after login.
         */
        Event::dispatch('customer.after.login', auth()->guard('customer')->user());

        $customer = auth()->guard('customer')->user();

        $token = $customer->createToken('uniapp-api');

        return response()->json([
            'data' => [
                'token'    => $token->plainTextToken,
                'customer' => [
                    'id'    => $customer->id,
                    'name'  => $customer->name,
                    'email' => $customer->email,
                ],
            ],
        ]);
    }

    /**
     * Logout customer: revoke the current Sanctum API token so the
     * uni-app 端清掉本地登录态后，后端不会继续认可该 token。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        if ($user = $request->user('sanctum')) {
            if ($token = $user->currentAccessToken()) {
                $token->delete();
            } else {
                $user->tokens()->delete();
            }
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Register a new customer and return an API token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:customers,email',
            'password'   => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, 'The given data was invalid.', Response::HTTP_UNPROCESSABLE_ENTITY, ['errors' => $validator->errors()]);
        }

        $customerGroup = core()->getConfigData('customer.settings.create_new_account_options.default_group');

        $data = array_merge($validator->validated(), [
            'password'                  => bcrypt($request->input('password')),
            'api_token'                 => Str::random(80),
            'is_verified'               => true,
            'customer_group_id'         => $this->customerGroupRepository->findOneWhere(['code' => $customerGroup])->id,
            'channel_id'                => core()->getCurrentChannel()->id,
            'token'                     => md5(uniqid(rand(), true)),
            'subscribed_to_news_letter' => false,
        ]);

        Event::dispatch('customer.registration.before');

        $customer = $this->customerRepository->create($data);

        Event::dispatch('customer.create.after', $customer);

        Event::dispatch('customer.registration.after', $customer);

        $token = $customer->createToken('uniapp-api');

        return response()->json([
            'data' => [
                'token'    => $token->plainTextToken,
                'customer' => [
                    'id'    => $customer->id,
                    'name'  => $customer->name,
                    'email' => $customer->email,
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}