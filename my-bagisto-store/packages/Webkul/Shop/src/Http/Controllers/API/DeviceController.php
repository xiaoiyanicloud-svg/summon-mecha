<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Product\Models\ProductProxy;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;

/**
 * 我的设备 —— 用户登记/管理自己实际拥有的机器。
 *
 * 这是项目里第一个「设备」接口:在此之前只有"机型"(商品),没有"实例"。
 * 技能要"用到机器上",缺的就是这一层。
 *
 * ⚠ 所有查询一律按 customer_id 过滤。项目里有一个反面教材:
 *   OrderController::guestIndex() 用 whereNull('customer_id'),会返回**所有游客的订单**,
 *   没有任何本机过滤 —— 那个坑绝不能在这里复现。
 *
 * ⚠ 语义边界(务必守住,别在界面上说成"绑定"):
 *   SN 由用户自填,平台**没有任何渠道验证这台机器真实存在** —— 填什么 SN 都算数。
 *   所以这里做的是「登记」,不是「绑定」;对外文案也必须这么写。
 *   等厂商开放设备接口(参考宇树/智元那种"机器人播报验证码、厂商云校验"的机制)
 *   之后,才能把 devices.verify_status 写成 verified,那时才谈得上"绑定"。
 *   状态字段见 Device::VERIFY_REGISTERED / VERIFY_VERIFIED。
 *
 * 唯一键按 (customer_id, sn) 划,避免被人抢注别人的 SN。
 */
class DeviceController extends APIController
{
    /**
     * 我的设备列表,每台带上已获得的技能。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $devices = Device::query()
            ->where('customer_id', $customer->id)
            ->where('bind_status', Device::STATUS_BOUND)
            ->with(['product', 'entitlements.product'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $devices->map(fn (Device $device) => $this->format($device))->values(),
        ]);
    }

    /**
     * 登记一台设备。
     *
     * ⚠ 注意措辞:平台无法核验这台机器真实存在,这是「登记」不是「绑定」。
     *   返回体里的 verify_status 会如实标出「未核验」。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'sn'           => ['required', 'string', 'max:64'],
            'product_id'   => ['nullable', 'integer'],
            'display_name' => ['nullable', 'string', 'max:191'],
        ]);

        $sn = trim($payload['sn']);

        if ($sn === '') {
            return $this->fail(ApiErrorCode::DEVICE_SN_REQUIRED, '请填写设备 SN。', 422);
        }

        // 同一账号下 SN 不能重复。这里显式查一次是为了给前端一句人话,
        // 数据库上还有 uk_devices_owner_sn 唯一键兜底并发。
        $exists = Device::query()
            ->where('customer_id', $customer->id)
            ->where('sn', $sn)
            ->where('bind_status', Device::STATUS_BOUND)
            ->exists();

        if ($exists) {
            return $this->fail(ApiErrorCode::DEVICE_SN_DUPLICATE, "设备 {$sn} 已经登记过了。", 422);
        }

        $device = Device::create([
            'customer_id'  => $customer->id,
            'sn'           => $sn,
            'product_id'   => $this->validProductId($payload['product_id'] ?? null),
            'display_name' => $payload['display_name'] ?? null,
            'bind_status'  => Device::STATUS_BOUND,
            'bound_at'     => now(),
        ]);

        // v3.4 步骤 2.3:同步补建 device_binding(设备唯一真相源),回填 devices.binding_id。
        // ⚠ 吞异常:这只是"补建绑定",失败不应影响"登记设备"这条主链路
        //   (与 Listeners/SkillDelivery 同样的防御姿态)。
        try {
            app(\Webkul\Shop\Services\SkillDelivery\DeviceBindingService::class)->ensureForDevice($device);
        } catch (\Throwable $e) {
            report($e);
        }

        // 登记后可自动认领:把该用户此前"待指派"的技能授权落到这台设备上。
        // 认领里每条都有 try/catch(见 claimPendingEntitlements),不会把登记整体打成 500。
        $claim = $this->claimPendingEntitlements($customer->id, $device);

        return response()->json([
            // 有技能没下来就直说 —— 否则用户看到"已登记"、却发现技能页还是"待安装",无从判断
            'message' => $claim['failed'] > 0
                ? "设备已登记,但有 {$claim['failed']} 个技能未能自动下发,可稍后在技能页重试。"
                : '设备已登记。',
            'data'    => $this->format($device->fresh(['product', 'entitlements.product'])),
            'claim'   => $claim,
        ], 201);
    }

    /**
     * 改备注 / 换机型。
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $device = $this->findOwned($customer->id, $id);

        if (! $device) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在。', 404);
        }

        $payload = $request->validate([
            'product_id'   => ['nullable', 'integer'],
            'display_name' => ['nullable', 'string', 'max:191'],
        ]);

        if (array_key_exists('display_name', $payload)) {
            $device->display_name = $payload['display_name'];
        }

        if (array_key_exists('product_id', $payload)) {
            $device->product_id = $this->validProductId($payload['product_id']);
        }

        $device->save();

        return response()->json([
            'message' => '已更新。',
            'data'    => $this->format($device->fresh(['product', 'entitlements.product'])),
        ]);
    }

    /**
     * 解绑。
     *
     * 移除(软处理,置 revoked)而不是物理删:skill_entitlements.device_id 还指着它,
     * 删了会让历史授权失去归属。真正要回收授权请走订单取消那条路。
     */
    public function destroy(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $device = $this->findOwned($customer->id, $id);

        if (! $device) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在。', 404);
        }

        $device->bind_status = Device::STATUS_REVOKED;
        $device->save();

        return response()->json(['message' => '已解绑。']);
    }

    /**
     * 签发一次性 Agent 安装 token(v3.4 步骤 8)。
     *
     * 装 Agent 前设备还没有共享密钥,先用这个一次性 token 到 /api/agent/enroll 换身份。
     * token **只存哈希**(meta.agent_enroll),过期即废、用完即清。
     */
    public function agentToken(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $device = $this->findOwned($customer->id, $id);

        if (! $device) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在。', 404);
        }

        // 确保 binding 存在(登记时补建若被吞掉异常,这里再兜一次)
        $binding = ($device->binding_id ? DeviceBinding::find($device->binding_id) : null)
            ?: app(\Webkul\Shop\Services\SkillDelivery\DeviceBindingService::class)->ensureForDevice($device);

        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_BINDING_MISSING, '设备绑定尚未建立,请重新登记设备。', 422);
        }

        $plain = bin2hex(random_bytes(24));
        $expiresAt = now()->addMinutes(30);

        $meta = is_array($binding->meta) ? $binding->meta : [];
        $meta['agent_enroll'] = [
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt->toDateTimeString(),
        ];
        $binding->meta = $meta;
        $binding->save();

        $base = rtrim(config('app.url'), '/');

        return response()->json([
            'message' => '安装 token 已签发(30 分钟内有效,一次性)。',
            'data'    => [
                'token'      => $plain,
                'expires_at' => $expiresAt->toDateTimeString(),
                'server_url' => $base,
                // ⚠ 两处易错:
                //   1. 路径是 /agent/setup.sh 而非 install.sh —— 含 `/install` 的路径会被
                //      Bagisto Installer 的 CanInstall 中间件重定向到 /admin(见 store-front-routes.php);
                //   2. `--token` 必须经 `bash -s --` 传入,否则被 bash 自己当选项吃掉。
                'command'    => "curl -fsSL {$base}/agent/setup.sh | bash -s -- --token {$plain}",
            ],
        ]);
    }

    /**
     * 取本账号下的一台设备 —— 永远带 customer_id 条件。
     */
    protected function findOwned(int $customerId, int $id): ?Device
    {
        return Device::query()
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();
    }

    /**
     * 校验机型商品存在,否则置 NULL(用户没选机型是合法状态)。
     */
    protected function validProductId($productId): ?int
    {
        if (! $productId) {
            return null;
        }

        $exists = ProductProxy::modelClass()::whereKey($productId)->exists();

        return $exists ? (int) $productId : null;
    }

    /**
     * 把该用户尚未指派设备的技能授权,认领到刚登记的这台设备上。
     *
     * 只在该用户名下且 device_id 为空的授权上生效 —— 多台设备时不会互相抢。
     * 认领后重跑一次交付,让状态从 pending_bind 走到 granted。
     *
     * @return array{claimed:int, failed:int} 下发成功 / 失败的条数
     */
    protected function claimPendingEntitlements(int $customerId, Device $device): array
    {
        $pending = SkillEntitlement::query()
            ->where('customer_id', $customerId)
            ->whereNull('device_id')
            ->where('status', SkillEntitlement::STATUS_PENDING_BIND)
            ->get();

        if ($pending->isEmpty()) {
            return ['claimed' => 0, 'failed' => 0];
        }

        $manager = app(\Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager::class);

        $claimed = 0;
        $failed = 0;

        foreach ($pending as $entitlement) {
            $entitlement->device_id = $device->id;
            $entitlement->save();

            // ⚠ D7 防御式 try/catch,与 Listeners\SkillDelivery 同一姿态 —— 而且这里
            //   **必须逐条兜住**,不能让一条失败拖垮整批:
            //     · `channel()` 在通道未注册(配置写错)时抛 InvalidArgumentException;
            //     · `deliver()` 还会因磁盘缺件 / 厂商接口报错而抛。
            //   此时授权**已经落库**指向本设备。若放任异常冒泡,`store()` 会返回 500,
            //   而设备其实已经建好了 —— 用户以为登记失败,于是重复登记,账号里堆出一串同 SN 设备。
            //   所以:失败写日志 + 计数,继续处理下一条,最后如实回报。
            try {
                $manager->channel($entitlement->delivery_channel)
                    ->deliver($entitlement, ['device_id' => $device->id]);

                $claimed++;
            } catch (\Throwable $e) {
                report($e);

                $failed++;
            }
        }

        return ['claimed' => $claimed, 'failed' => $failed];
    }

    /**
     * 统一的输出结构 —— 设备 + 它已获得的技能。
     */
    protected function format(Device $device): array
    {
        // Agent 配对/在线态的唯一真相源是 device_binding(不在这里做写操作)
        $binding = $device->binding_id
            ? DeviceBinding::find($device->binding_id)
            : DeviceBinding::query()->where('devices_id', $device->id)->first();

        return [
            'id'            => $device->id,
            'sn'            => $device->sn,
            'display_name'  => $device->display_name,
            'bind_status'   => $device->bind_status,
            // ⚠ 核验状态必须透出:平台无法验证这台机器真实存在,
            //   界面要如实标注「未核验」,不能让用户以为平台已经连上了他的机器人
            'verify_status' => $device->verify_status,
            'verify_label'  => $device->verifyLabel(),
            'verified_via'  => $device->verified_via,
            'verified_at'   => optional($device->verified_at)->toDateTimeString(),
            'bound_at'      => optional($device->bound_at)->toDateTimeString(),
            // Agent 已配对 = 这台机器真跑起过 Agent(与"已核验"是两回事)
            'agent_paired'  => (bool) ($binding && $binding->source === DeviceBinding::SOURCE_AGENT),
            // ⚠ 在线是**读时判断**(最近一次心跳在 90 秒窗口内),不是读 online_status 列 ——
            //   那一列只有写入 true，从没写过 false，断线后会永远显示"在线"。
            //   详见 DeviceBinding 的类注释。
            'agent_online'  => (bool) $binding?->isOnlineNow(),
            'last_online_at' => optional($binding?->last_online_at)->toDateTimeString(),
            'product'       => $device->product ? [
                'id'   => $device->product->id,
                'name' => $device->product->name,
            ] : null,
            'skills'        => $device->entitlements->map(fn (SkillEntitlement $e) => [
                'id'          => $e->id,
                'product_id'  => $e->product_id,
                'name'        => $e->product?->name,
                'status'      => $e->status,
                'license_key' => $e->license_key,
                'granted_at'  => optional($e->granted_at)->toDateTimeString(),
                'expires_at'  => optional($e->expires_at)->toDateTimeString(),
            ])->values(),
        ];
    }
}
