<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Sales\Models\Order;
use Webkul\Shop\Http\Requests\Customer\LoginRequest;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\Invoice;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantOrder;
use Webkul\Shop\Models\MerchantProfile;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;
use Webkul\Shop\Services\Ops\OperationLogger;

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

    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository,
        protected OperationLogger $oplog,
    ) {}

    /**
     * Login Customer
     */
    public function login(LoginRequest $request)
    {
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

        Event::dispatch('customer.after.login', auth()->guard('customer')->user());

        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();
        $token = $customer->createToken('uniapp-api');

        return response()->json([
            'data' => [
                'token'    => $token->plainTextToken,
                'customer' => $this->formatCustomer($customer),
            ],
        ]);
    }

    /**
     * Logout customer: revoke the current Sanctum API token.
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
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name'   => 'required|string|max:255',
            'last_name'    => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:customers,email',
            'password'     => 'required|min:6',
            'phone'        => ['nullable', 'string', 'regex:/^1\d{10}$/', 'unique:customers,phone'],
            'company_name' => 'nullable|string|max:191',
        ], [
            'email.unique' => '该邮箱已被注册，请直接登录。',
            'phone.regex'  => '请输入正确的 11 位手机号。',
            'phone.unique' => '该手机号已被绑定。',
        ]);

        if ($validator->fails()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $validator->errors()->first() ?: 'The given data was invalid.', Response::HTTP_UNPROCESSABLE_ENTITY, ['errors' => $validator->errors()]);
        }

        $customerGroup = core()->getConfigData('customer.settings.create_new_account_options.default_group');

        $validated = $validator->validated();
        $data = array_merge($validated, [
            'password'                  => bcrypt($request->input('password')),
            'api_token'                 => Str::random(80),
            'is_verified'               => true,
            'customer_group_id'         => $this->customerGroupRepository->findOneWhere(['code' => $customerGroup])->id,
            'channel_id'                => core()->getCurrentChannel()->id,
            'token'                     => md5(uniqid((string) rand(), true)),
            'subscribed_to_news_letter' => false,
        ]);

        Event::dispatch('customer.registration.before');

        /** @var Customer $customer */
        $customer = $this->customerRepository->create($data);

        if (Schema::hasColumn('customers', 'company_name') && ! empty($validated['company_name'])) {
            DB::table('customers')->where('id', $customer->id)->update([
                'company_name' => trim((string) $validated['company_name']),
            ]);
            $customer->refresh();
        }

        Event::dispatch('customer.create.after', $customer);
        Event::dispatch('customer.registration.after', $customer);

        $token = $customer->createToken('uniapp-api');

        return response()->json([
            'data' => [
                'token'    => $token->plainTextToken,
                'customer' => $this->formatCustomer($customer),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/customer/profile —— 当前登录客户完整档案与多端身份标识。
     */
    public function profile(): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();

        return response()->json([
            'data' => $this->formatCustomer($customer),
        ]);
    }

    /**
     * PUT /api/customer/profile —— 修改个人/企业资料（姓名、手机号、企业名称、职务）。
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();

        $validator = Validator::make($request->all(), [
            'name'         => ['nullable', 'string', 'min:1', 'max:120'],
            'first_name'   => ['nullable', 'string', 'max:120'],
            'last_name'    => ['nullable', 'string', 'max:120'],
            'phone'        => ['nullable', 'string', 'regex:/^1\d{10}$/', 'unique:customers,phone,'.$customer->id],
            'gender'       => ['nullable', 'string', 'in:Male,Female,Other'],
            'company_name' => ['nullable', 'string', 'max:191'],
            'job_title'    => ['nullable', 'string', 'max:96'],
        ], [
            'phone.regex'  => '请输入正确的 11 位手机号码。',
            'phone.unique' => '该手机号已被其他账号绑定。',
        ]);

        if ($validator->fails()) {
            return $this->fail(
                ApiErrorCode::VALIDATION_ERROR,
                $validator->errors()->first(),
                422,
                ['errors' => $validator->errors()]
            );
        }

        $payload = $validator->validated();
        $updates = [];

        if (! empty($payload['name'])) {
            [$firstName, $lastName] = $this->splitDisplayName((string) $payload['name']);
            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        } else {
            if (array_key_exists('first_name', $payload) && trim((string) $payload['first_name']) !== '') {
                $updates['first_name'] = trim((string) $payload['first_name']);
            }
            if (array_key_exists('last_name', $payload) && trim((string) $payload['last_name']) !== '') {
                $updates['last_name'] = trim((string) $payload['last_name']);
            }
        }

        if (array_key_exists('phone', $payload)) {
            $phone = trim((string) ($payload['phone'] ?? ''));
            $updates['phone'] = $phone !== '' ? $phone : null;
        }

        if (array_key_exists('gender', $payload)) {
            $updates['gender'] = $payload['gender'] ?: null;
        }

        if (Schema::hasColumn('customers', 'company_name') && array_key_exists('company_name', $payload)) {
            $company = trim((string) ($payload['company_name'] ?? ''));
            $updates['company_name'] = $company !== '' ? $company : null;
        }

        if (Schema::hasColumn('customers', 'job_title') && array_key_exists('job_title', $payload)) {
            $jobTitle = trim((string) ($payload['job_title'] ?? ''));
            $updates['job_title'] = $jobTitle !== '' ? $jobTitle : null;
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('customers')->where('id', $customer->id)->update($updates);
        }

        $customer->refresh();

        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'customer.profile_update',
            'customer',
            $customer->id,
            ['fields' => array_keys($updates)],
            $request->ip()
        );

        return response()->json([
            'message' => '账户资料已保存。',
            'data'    => $this->formatCustomer($customer),
        ]);
    }

    /**
     * PUT /api/customer/password —— 修改登录密码。
     */
    public function updatePassword(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();

        if (! $request->filled('password') && $request->filled('new_password')) {
            $request->merge([
                'password'              => $request->input('new_password'),
                'password_confirmation' => $request->input('new_password_confirmation', $request->input('password_confirmation')),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'current_password.required' => '请输入当前登录密码。',
            'password.required'         => '请输入新密码。',
            'password.min'              => '新密码长度至少 6 位。',
            'password.confirmed'        => '两次输入的新密码不一致。',
        ]);

        if ($validator->fails()) {
            return $this->fail(
                ApiErrorCode::VALIDATION_ERROR,
                $validator->errors()->first(),
                422,
                ['errors' => $validator->errors()]
            );
        }

        if (! Hash::check((string) $request->input('current_password'), (string) $customer->password)) {
            return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '当前密码不正确。', 422);
        }

        if (Hash::check((string) $request->input('password'), (string) $customer->password)) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, '新密码不能与当前密码相同。', 422);
        }

        $customer->password = Hash::make((string) $request->input('password'));
        $customer->save();

        // 保留当前会话 token，吊销其它旧设备 token
        $currentToken = $request->user('sanctum')?->currentAccessToken();
        if ($currentToken) {
            $customer->tokens()->where('id', '!=', $currentToken->id)->delete();
        }

        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'customer.password_change',
            'customer',
            $customer->id,
            [],
            $request->ip()
        );

        return response()->json([
            'message' => '密码已修改，其他登录设备已安全下线。',
        ]);
    }

    /**
     * POST /api/customer/forgot-password —— 发起密码重置验证码（防枚举：无论邮箱是否存在均返回中性提示）。
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => '请输入注册邮箱。',
            'email.email'    => '邮箱格式不正确。',
        ]);

        if ($validator->fails()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $validator->errors()->first(), 422, ['errors' => $validator->errors()]);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $customer = Customer::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($customer) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put('customer_pwd_reset:'.$email, [
                'code'        => $code,
                'customer_id' => (int) $customer->id,
                'attempts'    => 0,
                'issued_at'   => now()->timestamp,
            ], now()->addMinutes(15));

            try {
                \Illuminate\Support\Facades\Mail::raw(
                    "【召唤机甲】您的账户密码重置验证码为：{$code}（15 分钟内有效）。如非本人操作请忽略本邮件。",
                    function ($message) use ($email) {
                        $message->to($email)->subject('召唤机甲账户密码重置验证码');
                    }
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => '若该邮箱已注册，6 位密码重置验证码已发送（15 分钟内有效），请查收邮箱。',
        ]);
    }

    /**
     * POST /api/customer/reset-password —— 凭 6 位邮箱验证码或重置令牌设置新密码。
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code'     => ['nullable', 'string', 'max:16'],
            'token'    => ['nullable', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'email.required'     => '请输入注册邮箱。',
            'password.min'       => '新密码至少 6 位。',
            'password.confirmed' => '两次输入的密码不一致。',
        ]);

        if ($validator->fails()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $validator->errors()->first(), 422, ['errors' => $validator->errors()]);
        }

        if (! $request->filled('code') && ! $request->filled('token')) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, '请输入 6 位邮箱验证码。', 422);
        }

        if ($request->filled('code')) {
            $email = strtolower(trim((string) $request->input('email')));
            $cacheKey = 'customer_pwd_reset:'.$email;
            $cached = Cache::get($cacheKey);

            if (! is_array($cached) || (int) ($cached['attempts'] ?? 0) >= 5) {
                Cache::forget($cacheKey);

                return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '验证码无效或已过期，请重新获取。', 422);
            }

            if (! hash_equals((string) ($cached['code'] ?? ''), trim((string) $request->input('code')))) {
                $cached['attempts'] = (int) ($cached['attempts'] ?? 0) + 1;
                if ($cached['attempts'] >= 5) {
                    Cache::forget($cacheKey);
                } else {
                    Cache::put($cacheKey, $cached, now()->addMinutes(15));
                }

                return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '验证码不正确，请核对后重试。', 422);
            }

            $customer = Customer::query()->find((int) ($cached['customer_id'] ?? 0));
            if (! $customer) {
                Cache::forget($cacheKey);

                return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '验证码无效或已过期，请重新获取。', 422);
            }

            $customer->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();
            $customer->tokens()->delete();
            Cache::forget($cacheKey);

            $this->oplog->log(
                OperationLog::ACTOR_CUSTOMER,
                $customer->id,
                'customer.password_reset',
                'customer',
                $customer->id,
                ['via' => 'email_code'],
                $request->ip()
            );

            return response()->json([
                'message' => '密码已重置，请使用新密码登录。',
            ]);
        }

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '重置凭证无效或已过期，请重新获取。', 422);
        }

        return response()->json([
            'message' => '密码已重置，请使用新密码登录。',
        ]);
    }

    /**
     * POST /api/customer/deletion-request —— 账号注销申请（《个保法》合规闭环：前置核查在途订单与在租设备，落审计与工单）。
     */
    public function deletionRequest(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();

        $validator = Validator::make($request->all(), [
            'reason'   => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string'],
        ], [
            'password.required' => '请输入当前登录密码以确认本人操作。',
        ]);

        if ($validator->fails()) {
            return $this->fail(ApiErrorCode::VALIDATION_ERROR, $validator->errors()->first(), 422, ['errors' => $validator->errors()]);
        }

        if (! Hash::check((string) $request->input('password'), (string) $customer->password)) {
            return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '登录密码校验未通过，无法提交注销申请。', 422);
        }

        // 前置阻断：存在未完结订单或在租履约单时不得注销，防止债务/押金悬空
        $activeOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PENDING_PAYMENT, Order::STATUS_PROCESSING])
            ->count();

        $activeMerchantOrders = Schema::hasTable('merchant_orders')
            ? MerchantOrder::query()
                ->where('buyer_id', $customer->id)
                ->whereIn('status', [
                    MerchantOrder::STATUS_PENDING_SHIPMENT,
                    MerchantOrder::STATUS_SHIPPED,
                    MerchantOrder::STATUS_IN_USE,
                    MerchantOrder::STATUS_RETURNED_INSPECTING,
                ])
                ->count()
            : 0;

        if ($activeOrders > 0 || $activeMerchantOrders > 0) {
            return $this->fail(
                ApiErrorCode::CONFLICT,
                '当前账号名下仍有未完结订单或在租履约设备，请先结清订单与押金后再申请注销。',
                409
            );
        }

        $reason = trim((string) $request->input('reason', '')) ?: '用户主动申请注销账号';

        if (Schema::hasColumn('customers', 'deletion_requested_at')) {
            DB::table('customers')->where('id', $customer->id)->update([
                'deletion_requested_at' => now(),
                'deletion_reason'       => $reason,
            ]);
        }

        // 同步建立客服合规核销工单，确保人工在承诺周期内完成清退
        $ticketNo = 'T'.now()->format('ymdHis').strtoupper(Str::random(4));
        $ticket = SupportTicket::query()->create([
            'ticket_no'    => $ticketNo,
            'customer_id'  => $customer->id,
            'category'     => SupportTicket::CATEGORY_OTHER,
            'service_mode' => SupportTicket::SERVICE_ASYNC,
            'status'       => SupportTicket::STATUS_OPEN,
        ]);

        TicketMessage::query()->create([
            'ticket_id'  => $ticket->id,
            'actor_type' => TicketMessage::ACTOR_CUSTOMER,
            'actor_id'   => $customer->id,
            'content'    => "【账号注销申请】申请原因：{$reason}",
        ]);

        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'customer.deletion_requested',
            'customer',
            $customer->id,
            ['ticket_id' => $ticket->id, 'reason' => $reason],
            $request->ip()
        );

        return response()->json([
            'message' => '注销申请已受理（单号 '.$ticket->ticket_no.'），平台将在核验无未结权益后完成注销，期间可在客服中心撤回。',
            'data'    => [
                'status'    => 'pending_review',
                'ticket_id' => $ticket->id,
                'ticket_no' => $ticket->ticket_no,
            ],
        ], 201);
    }

    /**
     * GET /api/customer/overview —— “我的”页面驾驶舱聚合概览（一次往返拿齐身份、资产、订单角标、消息未读与合作跟进态）。
     */
    public function overview(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth()->guard('customer')->user();
        $cid = (int) $customer->id;

        // 1. 设备与在线态（读唯一真相源 device_binding::isOnlineNow 口径）
        $devices = Device::query()
            ->where('customer_id', $cid)
            ->where('bind_status', Device::STATUS_BOUND)
            ->get(['id', 'binding_id']);
        $devicesTotal = $devices->count();
        $devicesOnline = 0;
        $ownedDids = [];

        if (Schema::hasTable('device_binding')) {
            $bindings = DeviceBinding::query()
                ->where('owner_customer_id', $cid)
                ->where('bind_status', DeviceBinding::BIND_STATUS_BOUND)
                ->get(['id', 'did', 'last_online_at']);

            $devicesOnline = $bindings->filter(fn (DeviceBinding $b) => $b->isOnlineNow())->count();
            $ownedDids = $bindings->pluck('did')->filter()->values()->all();
        }

        // 2. 技能资产
        $skillsQuery = SkillEntitlement::query()->where('customer_id', $cid);
        $skillsTotal = (clone $skillsQuery)->count();
        $skillsPendingBind = (clone $skillsQuery)
            ->whereNull('device_id')
            ->where('status', SkillEntitlement::STATUS_PENDING_BIND)
            ->count();

        // 3. 场景定制需求（进行中 / 待客户确认报价或待验收）
        $sceneActiveCount = 0;
        $sceneActionRequired = 0;
        if (Schema::hasTable('scene_requests')) {
            $sceneActiveCount = (int) DB::table('scene_requests')
                ->where('customer_id', $cid)
                ->whereNotIn('state', ['closed', 'cancelled'])
                ->count();

            if (Schema::hasTable('scene_quotes')) {
                $sceneActionRequired += (int) DB::table('scene_quotes')
                    ->join('scene_requests', 'scene_requests.id', '=', 'scene_quotes.request_id')
                    ->where('scene_requests.customer_id', $cid)
                    ->where('scene_quotes.state', 'sent')
                    ->count();
            }
            if (Schema::hasTable('scene_projects')) {
                $sceneActionRequired += (int) DB::table('scene_projects')
                    ->join('scene_requests', 'scene_requests.id', '=', 'scene_projects.request_id')
                    ->where('scene_requests.customer_id', $cid)
                    ->whereIn('scene_projects.state', ['awaiting_acceptance', 'rectification'])
                    ->count();
            }
        }

        // 4. 发票统计
        $invoicesTotal = 0;
        $invoicesPending = 0;
        if (Schema::hasTable('invoice')) {
            $invoicesTotal = Invoice::query()->where('customer_id', $cid)->count();
            $invoicesPending = Invoice::query()->where('customer_id', $cid)->where('status', Invoice::STATUS_APPLIED)->count();
        }

        // 5. 订单各状态计数
        $orderRows = Order::query()
            ->where('customer_id', $cid)
            ->select('status', 'order_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status', 'order_type')
            ->get();

        $ordersStats = ['pending' => 0, 'confirm' => 0, 'active' => 0, 'completed' => 0, 'total' => 0];
        foreach ($orderRows as $row) {
            $cnt = (int) $row->cnt;
            $ordersStats['total'] += $cnt;
            if ($row->order_type === 'custom') {
                $ordersStats['completed'] += $cnt;
            } elseif (in_array($row->status, ['pending', 'pending_payment'], true)) {
                $ordersStats['pending'] += $cnt;
            } elseif ($row->status === 'processing') {
                $ordersStats['confirm'] += $cnt;
            } elseif (in_array($row->status, ['completed', 'closed'], true)) {
                $ordersStats['completed'] += $cnt;
            } elseif (! in_array($row->status, ['canceled', 'fraud'], true)) {
                $ordersStats['active'] += $cnt;
            }
        }

        // 叠加商家在租履约单作为“服务中”真实提醒
        if (Schema::hasTable('merchant_orders')) {
            $inUseRentals = MerchantOrder::query()
                ->where('buyer_id', $cid)
                ->whereIn('status', [MerchantOrder::STATUS_SHIPPED, MerchantOrder::STATUS_IN_USE])
                ->count();
            if ($inUseRentals > $ordersStats['active']) {
                $ordersStats['active'] = $inUseRentals;
            }
        }

        // 6. 未读通知、未读客服、进行中售后、下发任务与生态合作进度
        $unreadNotifications = Schema::hasTable('notification')
            ? Notification::query()
                ->where('recipient_type', 'customer')
                ->where('recipient_id', $cid)
                ->whereNull('read_at')
                ->count()
            : 0;

        $unreadTickets = 0;
        if (Schema::hasTable('support_ticket') && Schema::hasTable('ticket_message')) {
            $unreadTickets = TicketMessage::query()
                ->whereIn('ticket_id', SupportTicket::query()->where('customer_id', $cid)->select('id'))
                ->whereNotIn('actor_type', [TicketMessage::ACTOR_CUSTOMER, TicketMessage::ACTOR_NOTE])
                ->whereNull('read_at')
                ->count();
        }

        $pendingAfterSales = Schema::hasTable('after_sale')
            ? AfterSale::query()
                ->where('customer_id', $cid)
                ->whereIn('status', [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING])
                ->count()
            : 0;

        $activeDeployTasks = ($ownedDids !== [] && Schema::hasTable('deploy_task'))
            ? DeployTask::query()
                ->whereIn('did', $ownedDids)
                ->whereIn('status', ['pending', 'pending_online', 'dispatching', 'downloading', 'installing'])
                ->count()
            : 0;

        $coopUpdates = false;
        $supplierIssuedCode = false;
        if (Schema::hasTable('cooperation_applications')) {
            $guestKey = trim((string) $request->header('X-Guest-Key', ''));
            $coops = CooperationApplication::query()
                ->where(function ($q) use ($cid, $guestKey) {
                    $q->where('customer_id', $cid);
                    if (strlen($guestKey) >= 6) {
                        $q->orWhere('guest_key', $guestKey);
                    }
                })
                ->limit(30)
                ->get(['coop_type', 'status', 'meta']);

            foreach ($coops as $c) {
                $meta = is_array($c->meta) ? $c->meta : [];
                $hasIssued = ! empty($meta['issued_invite_code']);
                if ($c->coop_type === CooperationApplication::TYPE_SUPPLIER_INVITE) {
                    if ($hasIssued) {
                        $supplierIssuedCode = true;
                    }
                } elseif (in_array($c->status, [CooperationApplication::STATUS_PENDING, CooperationApplication::STATUS_CONTACTING], true) || $hasIssued) {
                    $coopUpdates = true;
                }
            }
        }

        $formattedCustomer = $this->formatCustomer($customer);

        return response()->json([
            'data' => [
                'customer'   => $formattedCustomer,
                'identities' => $formattedCustomer['identities'] ?? [],
                'counts'     => [
                    'devices_total'        => $devicesTotal,
                    'devices_online'       => $devicesOnline,
                    'active_skills'        => $skillsTotal,
                    'running_deploy_tasks' => $activeDeployTasks,
                    'invoices_total'       => $invoicesTotal,
                    'unread_notifications' => $unreadNotifications,
                    'open_after_sales'     => $pendingAfterSales,
                    'open_tickets'         => $unreadTickets,
                    'scene_requests'       => $sceneActiveCount,
                    'order_counts'         => [
                        'all'        => $ordersStats['total'],
                        'pending'    => $ordersStats['pending'],
                        'processing' => $ordersStats['confirm'] + $ordersStats['active'],
                        'completed'  => $ordersStats['completed'],
                        'canceled'   => 0,
                    ],
                ],
                'assets'     => [
                    'devices_total'         => $devicesTotal,
                    'devices_online'        => $devicesOnline,
                    'skills_total'          => $skillsTotal,
                    'skills_pending_bind'   => $skillsPendingBind,
                    'scene_active_count'    => $sceneActiveCount,
                    'scene_action_required' => $sceneActionRequired,
                    'invoices_total'        => $invoicesTotal,
                    'invoices_pending'      => $invoicesPending,
                ],
                'orders'     => $ordersStats,
                'badges'     => [
                    'unread_notifications' => $unreadNotifications,
                    'unread_tickets'       => $unreadTickets,
                    'pending_after_sales'  => $pendingAfterSales,
                    'active_deploy_tasks'  => $activeDeployTasks,
                    'coop_updates'         => $coopUpdates,
                    'supplier_issued_code' => $supplierIssuedCode,
                ],
            ],
        ]);
    }

    /**
     * 统一输出客户信息与真实角色认证标识（个人 / 企业 / 签约商家 / 开发者）。
     */
    protected function formatCustomer(Customer $customer): array
    {
        $raw = DB::table('customers')->where('id', $customer->id)->first();
        $companyName = $raw && property_exists($raw, 'company_name') ? ($raw->company_name ?? null) : null;
        $jobTitle = $raw && property_exists($raw, 'job_title') ? ($raw->job_title ?? null) : null;
        $deletionRequestedAt = $raw && property_exists($raw, 'deletion_requested_at') ? ($raw->deletion_requested_at ?? null) : null;

        $isMerchant = false;
        $merchantShopName = null;
        if (Schema::hasTable('merchant_members') && Schema::hasTable('merchant_shops')) {
            $shop = DB::table('merchant_members')
                ->join('merchant_shops', 'merchant_shops.id', '=', 'merchant_members.shop_id')
                ->where('merchant_members.customer_id', $customer->id)
                ->where('merchant_members.status', MerchantMember::STATUS_ACTIVE)
                ->where('merchant_shops.status', 'active')
                ->select('merchant_shops.name')
                ->first();
            if ($shop) {
                $isMerchant = true;
                $merchantShopName = $shop->name;
            }
        }

        if (! $companyName && Schema::hasTable('merchant_profiles')) {
            $mp = MerchantProfile::query()->where('customer_id', $customer->id)->first();
            if ($mp && $mp->company_name) {
                $companyName = $mp->company_name;
            }
        }

        $isDeveloper = false;
        $developerStatus = null;
        if (Schema::hasTable('developer_profile')) {
            $dp = DeveloperProfile::query()->where('customer_id', $customer->id)->first();
            if ($dp) {
                $isDeveloper = $dp->isActive();
                $developerStatus = $dp->realname_status;
            }
        }

        $isEnterprise = ! empty($companyName) || $isMerchant;

        $identityBadges = [];
        if ($isMerchant) {
            $identityBadges[] = ['code' => 'merchant', 'label' => '签约商家'];
        }
        if ($isDeveloper) {
            $identityBadges[] = ['code' => 'developer', 'label' => '技能开发者'];
        }
        if ($isEnterprise && ! $isMerchant) {
            $identityBadges[] = ['code' => 'enterprise', 'label' => '企业客户'];
        }
        if ($identityBadges === []) {
            $identityBadges[] = ['code' => 'personal', 'label' => '个人账户'];
        }

        return [
            'id'                    => (int) $customer->id,
            'name'                  => $customer->name,
            'first_name'            => $customer->first_name,
            'last_name'             => $customer->last_name,
            'email'                 => $customer->email,
            'phone'                 => $customer->phone ?: null,
            'gender'                => $customer->gender ?: null,
            'company_name'          => $companyName,
            'job_title'             => $jobTitle,
            'deletion_requested_at' => $deletionRequestedAt,
            'identities'            => [
                'is_enterprise'      => $isEnterprise,
                'is_merchant'        => $isMerchant,
                'merchant_shop_name' => $merchantShopName,
                'is_developer'       => $isDeveloper,
                'developer_status'   => $developerStatus,
                'badges'             => $identityBadges,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitDisplayName(string $name): array
    {
        $trimmed = trim($name);
        $parts = preg_split('/\s+/', $trimmed) ?: [];

        if (count($parts) > 1) {
            return [$parts[0], implode(' ', array_slice($parts, 1))];
        }

        if (mb_strlen($trimmed) > 1) {
            return [mb_substr($trimmed, 0, 1), mb_substr($trimmed, 1)];
        }

        return [$trimmed, '先生/女士'];
    }
}
