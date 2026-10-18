<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillDelivery\DeliveryResult;
use Webkul\Shop\Services\SkillDelivery\DeviceBindingService;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

/**
 * 用户侧的技能接口 —— 「我的技能」与技能包的鉴权下载。
 *
 * 下载安全模型:
 *   1. 文件在 private 盘,没有公开 URL(这是技能包不进 public 盘的全部意义)
 *   2. 必须先登录,且该授权必须属于当前用户 —— 一律带 customer_id 条件
 *   3. 走 PHP 流式读出,不签发公开直链
 *
 * P1 设备拉取通道会复用同一套校验,只是把身份从"登录用户"换成"license_key",
 * 并改成签发限时 URL。
 */
class SkillController extends APIController
{
    public function __construct(protected OperationLogger $oplog) {}

    /**
     * 我的技能授权列表。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $entitlements = SkillEntitlement::query()
            ->where('customer_id', $customer->id)
            ->with(['product', 'version', 'device'])
            ->orderByDesc('id')
            ->get();

        // 设备在线/配对态的唯一真相源是 device_binding —— 批量取,避免逐行查询(N+1)
        $bindings = DeviceBinding::query()
            ->whereIn('id', $entitlements->pluck('device.binding_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $entitlements->map(function (SkillEntitlement $e) use ($bindings) {
                // 生效版本:授权锁了版本用它,没锁则取该商品当前已发布版本
                $version = $this->versionOf($e);
                $binding = $e->device?->binding_id ? $bindings->get($e->device->binding_id) : null;

                return [
                    'id'                => $e->id,
                    'product_id'        => $e->product_id,
                    'name'              => $e->product?->name,
                    'status'            => $e->status,
                    'version'           => $version?->version,
                    // 下发链路需要具体版本 id(entitlement 的 skill_version_id 可为 NULL=跟随最新)
                    'skill_version_id'  => $version?->id,
                    // 该版本是否声明了 agent 通道 —— 决定「一键下发」按钮是否出现
                    'agent_deliverable' => in_array(SkillAsset::DELIVERY_AGENT, (array) ($version?->delivery_channels ?? []), true),
                    'delivery_channel'  => $e->delivery_channel,
                    'license_key'       => $e->license_key,
                    'device_id'         => $e->device_id,
                    'device_sn'         => $e->device?->sn,
                    'device_paired'     => (bool) ($binding && $binding->source === DeviceBinding::SOURCE_AGENT),
                    // ⚠ 读时判断（最近心跳在 90 秒窗口内），不是读 online_status 粘滞列 —— 见 DeviceBinding 类注释
                    'device_online'         => (bool) $binding?->isOnlineNow(),
                    'device_last_online_at' => optional($binding?->last_online_at)->toDateTimeString(),
                    // 真相源如实透出：有 binding = 已确权；只有 devices 行 = 仅自报登记
                    'device_truth_source' => $binding ? 'binding' : 'registration_only',
                    'device_did'          => $binding?->did,
                    'granted_at'          => optional($e->granted_at)->toDateTimeString(),
                    'expires_at'          => optional($e->expires_at)->toDateTimeString(),
                    // The purchase entitlement, rather than today's version, owns this limit.
                    'activations'           => $e->activations,
                    'max_activations'       => $e->max_activations,
                    'remaining_activations' => $e->remainingActivations(),
                    // B-3 · 试用：是试用就不该被当成买断展示（前端据此显示倒计时/「试用」角标）。
                    // `trial_remaining_seconds` 由服务端按当前时间算，避免端侧时钟偏差把倒计时算歪。
                    'trial'                   => $e->isTrial(),
                    'trial_remaining_seconds' => ($e->isTrial() && $e->expires_at && ! $e->expires_at->isPast())
                        ? (int) now()->diffInSeconds($e->expires_at)
                        : 0,
                    'usable'            => $e->isUsable(),
                ];
            })->values(),
        ]);
    }

    /**
     * 一个授权下可下载的交付物清单(只给元信息,不给路径)。
     */
    public function assets(int $entitlementId): JsonResponse
    {
        $entitlement = $this->findOwnedEntitlement($entitlementId);

        if (! $entitlement) {
            return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在。', 404);
        }

        $assets = $this->assetsOf($entitlement);

        // S-8 · 熔断提示:block_all 及以上时,该技能的包已停止分发 —— 列表仍展示(权益还在),
        //   但**明确告知**此刻下载会被拒,别让用户点了下载却只看到一个干巴巴的错误。
        //   block_new(仅停新增)不在此列:那种强度下既有授权的下载**不受影响**。
        $blocked = app(KillSwitchService::class)->isBlockedFor((int) $entitlement->product_id, 'download');

        return response()->json([
            // 安装指引跟的是"这个技能怎么装进机器" —— 第三方成品机器人改不了固件,
            // 兜底交付就是靠这份说明让用户自己装,所以它和交付物一起返回。
            'install_guide' => optional($this->versionOf($entitlement))->install_guide,
            // 熔断提示(新增字段,不改既有契约):true 时前端应显眼展示下面的说明。
            'blocked'       => $blocked,
            'block_notice'  => $blocked
                ? '该技能已被平台暂停分发,暂不能下载。如有需要请联系客服处理售后。'
                : null,
            'data'          => $assets->map(fn (SkillAsset $a) => [
                'id'           => $a->id,
                'carrier_type' => $a->carrier_type,
                'file_name'    => $a->file_name,
                'file_size'    => $a->file_size,
                'entrypoint'   => $a->entrypoint,
                'downloadable' => $a->isFile() && $a->fileExists(),
            ])->values(),
        ]);
    }

    /**
     * 下载一个交付物 —— 鉴权后从 private 盘流式读出。
     */
    public function download(int $entitlementId, int $assetId)
    {
        $customer = auth()->guard('customer')->user();

        // The download decision and its count share one current entitlement.
        // A revocation, refund or kill switch committed while this request waits
        // for the purchase locks must be visible before any file is returned.
        return $this->withLockedOwnedEntitlement($entitlementId, $customer->id, function ($entitlement) use ($assetId) {
            if (! $entitlement) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在。', 404);
            }

            if (! $entitlement->isUsable()) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_REVOKED, '该技能授权已失效。', 403);
            }

            // Preserve the existing paid-download policy, including trials:
            // no purchase order still means no downloadable package.
            if (! $entitlement->isOrderPaid()) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_UNPAID, '订单尚未支付,暂不能下载。', 409);
            }

            // block_new retains existing downloads; block_all and stronger stop them.
            // Use a current read even when called inside an existing RR transaction.
            if (app(KillSwitchService::class)->isBlockedFor((int) $entitlement->product_id, 'download', current: true)) {
                return $this->fail(
                    ApiErrorCode::SKILL_BLOCKED,
                    '该技能已被平台暂停分发,暂不能下载。如有需要请联系客服处理售后。',
                    409
                );
            }

            $asset = $this->assetsOf($entitlement)->firstWhere('id', $assetId);

            if (! $asset || ! $asset->isFile()) {
                return $this->fail(ApiErrorCode::SKILL_ASSET_NOT_FOUND, '该交付物不可下载。', 404);
            }

            if (! $asset->fileExists()) {
                return $this->fail(ApiErrorCode::SKILL_ASSET_FILE_MISSING, '文件不存在,请联系客服。', 404);
            }

            // Merge only download statistics into the latest row. Downloads do
            // not consume activations or alter the compensation budget/lease.
            $meta = is_array($entitlement->meta) ? $entitlement->meta : [];
            $meta['last_download_at'] = now()->toDateTimeString();
            $meta['last_download_asset'] = $asset->id;
            $meta['download_count'] = ($meta['download_count'] ?? 0) + 1;
            $entitlement->meta = $meta;
            $entitlement->save();

            $this->oplog->log(
                OperationLog::ACTOR_CUSTOMER,
                $entitlement->customer_id,
                'skill.download',
                'skill_entitlement',
                $entitlement->id,
                ['asset_id' => $asset->id, 'file_name' => $asset->file_name],
                request()->ip(),
            );

            // Construct the streaming response; this helper finishes its
            // transaction before the caller starts transmitting the file.
            return Storage::disk(SkillAsset::disk())->download(
                $asset->file_path,
                $asset->file_name ?: basename($asset->file_path)
            );
        });
    }

    /**
     * 把一个尚未绑定设备的技能授权,指派到本人某台设备上。
     *
     * 为什么需要它:下单时没选设备(或先买后绑)的授权会停在 pending_bind。
     * DeviceController::store 只会在"新绑定一台设备"时认领,已经绑好设备的用户
     * 没有别的入口 —— 这个接口补上那条路。
     */
    public function bindDevice(Request $request, int $entitlementId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        return $this->withLockedOwnedEntitlement($entitlementId, $customer->id, function ($entitlement) use ($customer, $request) {
            if (! $entitlement) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在。', 404);
            }
            if (! $entitlement->isUsable()) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_REVOKED, '该技能授权已失效。', 403);
            }

            $payload = $request->validate(['device_id' => ['required', 'integer']]);

            $device = Device::query()->where('customer_id', $customer->id)
                ->whereKey($payload['device_id'])->where('bind_status', Device::STATUS_BOUND)
                ->lockForUpdate()->first();
            if (! $device) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在或不属于你。', 404);
            }
            $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customer->id, heal: true);
            if (! $resolved['ok']) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, $resolved['reason'] ?: '设备不存在或不属于你。', 404);
            }
            $binding = $resolved['binding']
                ? DeviceBinding::query()->whereKey($resolved['binding']->id)->lockForUpdate()->first()
                : null;
            if ($binding && (($binding->owner_customer_id !== null && (int) $binding->owner_customer_id !== (int) $customer->id)
                || $binding->bind_status !== DeviceBinding::BIND_STATUS_BOUND)) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在或不属于你。', 404);
            }

            // Merge into the latest locked row; never replace concurrent download
            // statistics or the compensation ledger with a pre-lock snapshot.
            $entitlement->device_id = $device->id;
            $meta = (array) $entitlement->meta;
            if ($binding) {
                $meta['bound_did'] = $binding->did;
            }
            $entitlement->meta = $meta;
            $entitlement->save();
            $this->oplog->log(OperationLog::ACTOR_CUSTOMER, $customer->id,
                'skill.bind_device', 'skill_entitlement', $entitlement->id,
                ['device_id' => $device->id, 'device_sn' => $device->sn, 'device_did' => $binding?->did], $request->ip());

            $compensator = app(SkillDeliveryCompensator::class);
            $pendingRecovery = $compensator->hasFailureState($entitlement);
            $delivery = null;
            $deliveryFailed = $pendingRecovery;
            if ($pendingRecovery) {
                // Binding is allowed; another initial delivery is not a way to
                // escape an existing failure cycle or replenish its budget.
                $delivery = DeliveryResult::fail('技能交付待平台处理。', [], 'DELIVERY_PENDING_RECOVERY')->toArray();
            } else {
                try {
                    $result = app(SkillDeliveryManager::class)->channel($entitlement->delivery_channel)
                        ->deliver($entitlement, ['device_id' => $device->id]);
                    $delivery = $result->toArray();
                    $deliveryFailed = ! $result->ok;
                    $entitlement->refresh();
                    $meta = (array) $entitlement->meta;
                    $meta['delivery'] = $delivery;
                    if ($result->ok) {
                        $meta['delivery_failed'] = null;
                    }
                    $entitlement->meta = $meta;
                    $entitlement->save();
                } catch (\Throwable $error) {
                    report($error);
                    // The outer transaction already holds order -> item ->
                    // product -> entitlement, matching recordFailure's lock order.
                    $compensator->recordFailure((int) $entitlement->id, $error);
                    $deliveryFailed = true;
                }
            }

            $data = [
                'id'               => $entitlement->id,
                'device_id'        => $device->id,
                'device_sn'        => $device->sn,
                'device_did'       => $binding?->did,
                'device_confirmed' => (bool) $binding,
                'status'           => $entitlement->fresh()->status,
                'delivery'         => $delivery,
            ];
            if ($deliveryFailed) {
                return $this->fail(ApiErrorCode::SKILL_DELIVERY_FAILED,
                    '已绑定到设备 '.$device->sn.',但技能交付待平台处理。', 200, ['data' => $data]);
            }

            return response()->json(['message' => '已绑定到设备 '.$device->sn.'。', 'data' => $data]);
        });
    }

    /**
     * 用户自报「我已装到机器上」—— 兜底通道的终点。
     *
     * 第三方成品机器人改不了固件、厂家也不开放面向第三方平台的分发接口,
     * 平台**无法验证安装结果**,只能由用户确认。确认后授权从 granted 走到 active。
     *
     * ⚠ 这是自报,不是验证。将来接入厂家通道后,active 应由设备侧回执产生,
     *   这个接口会退化成"手工补录"的兜底。
     */
    public function confirmInstall(int $entitlementId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        return $this->withLockedOwnedEntitlement($entitlementId, $customer->id, function ($entitlement) use ($customer) {
            if (! $entitlement) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在。', 404);
            }
            if (! $entitlement->isUsable()) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_REVOKED, '该技能授权已失效。', 403);
            }
            $device = $entitlement->device_id
                ? Device::query()->whereKey($entitlement->device_id)->where('customer_id', $customer->id)
                    ->where('bind_status', Device::STATUS_BOUND)->lockForUpdate()->first()
                : null;
            if (! $device) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_BINDING_REQUIRED, '请先给这个技能绑定一台设备。', 422);
            }

            // The row lock makes the idempotency and activation limit checks
            // atomic with status, metadata and the installation fact below.
            if ($entitlement->status === SkillEntitlement::STATUS_ACTIVE) {
                $meta = (array) $entitlement->meta;

                return response()->json([
                    'message' => '该技能已激活(幂等)。',
                    'data'    => [
                        'id'               => $entitlement->id,
                        'status'           => $entitlement->status,
                        'activations'      => $entitlement->activations,
                        'device_did'       => $meta['installed_did'] ?? null,
                        'device_confirmed' => (bool) ($meta['installed_device_confirmed'] ?? false),
                        'idempotent'       => true,
                    ],
                ]);
            }
            if (app(SkillDeliveryCompensator::class)->hasFailureState($entitlement)) {
                return $this->fail(ApiErrorCode::SKILL_DELIVERY_FAILED, '该技能交付待平台处理，暂不能确认安装。', 409);
            }
            if ($entitlement->max_activations !== null && $entitlement->activations >= $entitlement->max_activations) {
                return $this->fail(ApiErrorCode::MAX_ACTIVATIONS_REACHED, '激活次数已达上限。', 409);
            }

            $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customer->id, heal: true);
            if (! $resolved['ok']) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, $resolved['reason'] ?: '设备不存在或不属于你。', 404);
            }
            $binding = $resolved['binding']
                ? DeviceBinding::query()->whereKey($resolved['binding']->id)->lockForUpdate()->first()
                : null;
            if ($binding && (($binding->owner_customer_id !== null && (int) $binding->owner_customer_id !== (int) $customer->id)
                || $binding->bind_status !== DeviceBinding::BIND_STATUS_BOUND)) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在或不属于你。', 404);
            }

            $meta = (array) $entitlement->meta;
            $meta['installed_at'] = now()->toDateTimeString();
            $meta['installed_by'] = 'user_reported';
            if ($binding) {
                $meta['installed_did'] = $binding->did;
            }
            $meta['installed_device_confirmed'] = (bool) $binding;
            $entitlement->status = SkillEntitlement::STATUS_ACTIVE;
            $entitlement->activations += 1;
            $entitlement->meta = $meta;
            $entitlement->save();

            // If this write fails, the same transaction rolls back activation
            // and metadata. A later idempotent response cannot hide a half-write.
            if ($binding && $entitlement->skill_version_id) {
                InstallRecord::updateOrCreate([
                    'did'              => $binding->did,
                    'skill_version_id' => $entitlement->skill_version_id,
                ], [
                    'entitlement_id'  => $entitlement->id,
                    'status'          => 'installed',
                    'current_version' => $entitlement->version?->version,
                    'installed_at'    => now(),
                    'channel'         => $entitlement->delivery_channel,
                ]);
            }
            $this->oplog->log(OperationLog::ACTOR_CUSTOMER, $entitlement->customer_id,
                'skill.confirm_install', 'skill_entitlement', $entitlement->id,
                ['activations' => $entitlement->activations, 'device_id' => $device->id, 'device_did' => $binding?->did], request()->ip());

            return response()->json([
                'message' => '已标记为安装完成。',
                'data'    => [
                    'id'               => $entitlement->id,
                    'status'           => $entitlement->status,
                    'activations'      => $entitlement->activations,
                    'device_did'       => $binding?->did,
                    'device_confirmed' => (bool) $binding,
                ],
            ]);
        });
    }

    /**
     * 兼容性校验(v3.4 步骤 5)。
     *
     * GET /api/skills/{productId}/compatibility?deviceId=
     * 输出 {compatible, reason, channels[], notes[], model}
     */
    public function compatibility(Request $request, int $productId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'device_id' => ['required', 'integer'],
        ]);

        $result = app(\Webkul\Shop\Services\SkillDelivery\CompatibilityService::class)
            ->check($customer->id, $productId, (int) $payload['device_id']);

        return response()->json(['data' => $result]);
    }

    /**
     * 发起试用（B-3 · D4 / 计划 §4.7）。
     *
     * 发放一条 20 分钟的在线授权（`meta.trial=true`）并直接下发到指定设备。
     * 全部规则在 {@see \Webkul\Shop\Services\SkillDelivery\TrialService}：
     * 仅 agent 通道、设备须已确权、7 天冷却、已买断不发。
     */
    public function trial(Request $request, int $productId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'device_id'        => ['required', 'integer'],
            'skill_version_id' => ['nullable', 'integer'],
        ]);

        $result = app(\Webkul\Shop\Services\SkillDelivery\TrialService::class)
            ->start(
                (int) $customer->id,
                $productId,
                (int) $payload['device_id'],
                $payload['skill_version_id'] ?? null,
            );

        if (! $result['ok']) {
            return $this->fail($result['code'], $result['message'], $result['status'] ?? 200);
        }

        /** @var \Webkul\Shop\Models\SkillEntitlement $entitlement */
        $entitlement = $result['entitlement'];
        $task = $result['task'];

        return response()->json([
            'message' => $result['message'],
            'data'    => [
                'entitlement_id'   => $entitlement->id,
                'trial'            => true,
                'expires_at'       => optional($entitlement->expires_at)->toDateTimeString(),
                'delivery_channel' => $entitlement->delivery_channel,
                'task_no'          => $task?->task_no,
                'task_status'      => $task?->status,
            ],
        ], 201);
    }

    /** Lock in the same order as recovery: order -> item -> product -> entitlement. */
    protected function withLockedOwnedEntitlement(int $id, int $customerId, callable $callback): Response
    {
        // This is only a lock hint, never an authorization decision. Ownership
        // and immutable purchase links are checked again after taking the locks.
        $identity = SkillEntitlement::query()->whereKey($id)->where('customer_id', $customerId)
            ->first(['id', 'order_id', 'order_item_id', 'product_id']);
        if (! $identity) {
            return $callback(null);
        }

        return DB::transaction(function () use ($identity, $id, $customerId, $callback) {
            $order = $identity->order_id
                ? OrderProxy::modelClass()::query()->whereKey($identity->order_id)->lockForUpdate()->first()
                : null;
            if ($identity->order_item_id) {
                DB::table('order_items')->where('id', $identity->order_item_id)->lockForUpdate()->first();
            }
            // Existing products share the kill-switch mutex before any entitlement lock.
            // Missing historical products retain the existing ownership policy.
            DB::table('products')->where('id', $identity->product_id)->lockForUpdate()->first();
            $entitlement = SkillEntitlement::query()->whereKey($id)->where('customer_id', $customerId)
                ->where('order_id', $identity->order_id)->where('order_item_id', $identity->order_item_id)
                ->where('product_id', $identity->product_id)->lockForUpdate()->first();
            // isOrderPaid must use the current locked row, not a lazy relation
            // read from an older repeatable-read snapshot in a parent transaction.
            $entitlement?->setRelation('order', $order);

            return $callback($entitlement);
        });
    }

    /**
     * 取本账号下的一条授权 —— 永远带 customer_id 条件。
     */
    protected function findOwnedEntitlement(int $id): ?SkillEntitlement
    {
        $customer = auth()->guard('customer')->user();

        return SkillEntitlement::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * 授权锁定的版本;没锁版本则取该商品当前已发布的版本。
     */
    protected function versionOf(SkillEntitlement $entitlement): ?SkillVersion
    {
        if ($entitlement->version) {
            return $entitlement->version;
        }

        return SkillVersion::query()
            ->where('product_id', $entitlement->product_id)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->first();
    }

    protected function assetsOf(SkillEntitlement $entitlement)
    {
        return $this->versionOf($entitlement)?->assets()->get() ?? collect();
    }
}
