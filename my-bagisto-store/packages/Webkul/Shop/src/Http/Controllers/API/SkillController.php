<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillDelivery\DeviceBindingService;

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
                    'agent_deliverable' => in_array('agent', (array) ($version?->delivery_channels ?? []), true),
                    'delivery_channel'  => $e->delivery_channel,
                    'license_key'       => $e->license_key,
                    'device_id'         => $e->device_id,
                    'device_sn'         => $e->device?->sn,
                    'device_paired'     => (bool) ($binding && $binding->source === DeviceBinding::SOURCE_AGENT),
                    // ⚠ 读时判断（最近心跳在 90 秒窗口内），不是读 online_status 粘滞列 —— 见 DeviceBinding 类注释
                    'device_online'     => (bool) $binding?->isOnlineNow(),
                    'device_last_online_at' => optional($binding?->last_online_at)->toDateTimeString(),
                    // 真相源如实透出：有 binding = 已确权；只有 devices 行 = 仅自报登记
                    'device_truth_source' => $binding ? 'binding' : 'registration_only',
                    'device_did'          => $binding?->did,
                    'granted_at'        => optional($e->granted_at)->toDateTimeString(),
                    'expires_at'        => optional($e->expires_at)->toDateTimeString(),
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
            return response()->json(['message' => '技能授权不存在。'], 404);
        }

        $assets = $this->assetsOf($entitlement);

        return response()->json([
            // 安装指引跟的是"这个技能怎么装进机器" —— 第三方成品机器人改不了固件,
            // 兜底交付就是靠这份说明让用户自己装,所以它和交付物一起返回。
            'install_guide' => optional($this->versionOf($entitlement))->install_guide,
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
        $entitlement = $this->findOwnedEntitlement($entitlementId);

        if (! $entitlement) {
            return response()->json(['message' => '技能授权不存在。'], 404);
        }

        if (! $entitlement->isUsable()) {
            return response()->json(['message' => '该技能授权已失效。'], 403);
        }

        // 付费墙:交付物(技能包)以"订单已支付"为前提。授权在建单时就落库,
        // 若只认 isUsable() 则"建单未付"也能下包 —— 见 SkillEntitlement::isOrderPaid()。
        if (! $entitlement->isOrderPaid()) {
            return response()->json(['message' => '订单尚未支付,暂不能下载。'], 409);
        }

        $asset = $this->assetsOf($entitlement)
            ->firstWhere('id', $assetId);

        if (! $asset || ! $asset->isFile()) {
            return response()->json(['message' => '该交付物不可下载。'], 404);
        }

        if (! $asset->fileExists()) {
            return response()->json(['message' => '文件不存在,请联系客服。'], 404);
        }

        // P0 只记录"下载过一次",不在这里扣 activations —— 激活次数是 P1 设备侧的语义
        // D8:计数改原子 —— 原先是 read-modify-write,并发下载会丢更新(计数偏小)。
        // 用行锁包住"读 meta → +1 → 写回",使同一授权的并发下载串行化。
        \Illuminate\Support\Facades\DB::transaction(function () use ($entitlement, $asset) {
            $locked = SkillEntitlement::whereKey($entitlement->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $meta['last_download_at'] = now()->toDateTimeString();
            $meta['last_download_asset'] = $asset->id;
            $meta['download_count'] = ($meta['download_count'] ?? 0) + 1;
            $locked->meta = $meta;
            $locked->save();
        });

        return Storage::disk(SkillAsset::disk())->download(
            $asset->file_path,
            $asset->file_name ?: basename($asset->file_path)
        );
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
        $entitlement = $this->findOwnedEntitlement($entitlementId);

        if (! $entitlement) {
            return response()->json(['message' => '技能授权不存在。'], 404);
        }

        if (! $entitlement->isUsable()) {
            return response()->json(['message' => '该技能授权已失效。'], 403);
        }

        $payload = $request->validate([
            'device_id' => ['required', 'integer'],
        ]);

        $customer = auth()->guard('customer')->user();

        $device = Device::query()
            ->where('customer_id', $customer->id)
            ->where('id', $payload['device_id'])
            ->where('bind_status', Device::STATUS_BOUND)
            ->first();

        if (! $device) {
            return response()->json(['message' => '设备不存在或不属于你。'], 404);
        }

        // v3.4 步骤 2「设备唯一真相源=binding」：归属以 binding 为准。
        // heal=true —— 在 binding 机制之前登记的历史设备，在这里补建（自愈），
        // 否则它们的授权指派到设备后，下发链路（只认 binding）根本找不到这台设备。
        $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customer->id, heal: true);

        if (! $resolved['ok']) {
            return response()->json(['message' => $resolved['reason'] ?: '设备不存在或不属于你。'], 404);
        }

        $binding = $resolved['binding'];

        $entitlement->device_id = $device->id;

        // 记下真相源的 did：下发只认它，出问题时能据此对照
        $meta = is_array($entitlement->meta) ? $entitlement->meta : [];
        if ($binding) {
            $meta['bound_did'] = $binding->did;
        }
        $entitlement->meta = $meta;
        $entitlement->save();

        // 重跑交付,让状态从 pending_bind 走到 granted
        $result = app(\Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager::class)
            ->channel($entitlement->delivery_channel)
            ->deliver($entitlement, ['device_id' => $device->id]);

        return response()->json([
            'message' => '已绑定到设备 '.$device->sn.'。',
            'data'    => [
                'id'               => $entitlement->id,
                'device_id'        => $device->id,
                'device_sn'        => $device->sn,
                // 真相源信息：did 是下发链路的唯一凭据；device_confirmed 表示是否已确权
                'device_did'       => $binding?->did,
                'device_confirmed' => (bool) $binding,
                'status'           => $entitlement->fresh()->status,
                'delivery'         => $result->toArray(),
            ],
        ]);
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
        $entitlement = $this->findOwnedEntitlement($entitlementId);

        if (! $entitlement) {
            return response()->json(['message' => '技能授权不存在。'], 404);
        }

        $device = $entitlement->device;

        if (! $device) {
            return response()->json(['message' => '请先给这个技能绑定一台设备。'], 422);
        }

        if (! $entitlement->isUsable()) {
            return response()->json(['message' => '该技能授权已失效。'], 403);
        }

        // D1 幂等:已 active 直接返回既有状态,不再重复计数。
        // 此前同一方法无"已 active 就返回"的分支,连点两次 = activations +2。
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

        // D1 max_activations 硬闸:激活次数是许可与计费的核心账目,不能形同虚设。
        if ($entitlement->max_activations !== null && $entitlement->activations >= $entitlement->max_activations) {
            return response()->json([
                'message' => '激活次数已达上限。',
                'code'    => 'MAX_ACTIVATIONS_REACHED',
            ], 409);
        }

        // v3.4 步骤 2：设备归属以 binding 为准（heal=true —— 历史设备在此自愈）
        $customer = auth()->guard('customer')->user();

        $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customer->id, heal: true);

        if (! $resolved['ok']) {
            return response()->json(['message' => $resolved['reason'] ?: '设备不存在或不属于你。'], 404);
        }

        $binding = $resolved['binding'];

        // D1 原子条件自增:仅当"仍非 active 且未超上限"时才 +1 并置 active。
        // 并发两个请求只有一个命中(affected=1);另一个 affected=0 → 走幂等/超限分支。
        // 此前是 read-modify-write,并发会丢失更新;且 max_activations 未参与判定。
        $affected = SkillEntitlement::query()
            ->where('id', $entitlement->id)
            ->where('status', '!=', SkillEntitlement::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('max_activations')
                    ->orWhereColumn('activations', '<', 'max_activations');
            })
            ->update([
                'status'      => SkillEntitlement::STATUS_ACTIVE,
                'activations' => DB::raw('activations + 1'),
            ]);

        if ($affected === 0) {
            $fresh = $entitlement->fresh();

            // 并发中另一半已把它置为 active → 幂等返回
            if ($fresh && $fresh->status === SkillEntitlement::STATUS_ACTIVE) {
                return response()->json([
                    'message' => '该技能已激活(幂等)。',
                    'data'    => [
                        'id'          => $fresh->id,
                        'status'      => $fresh->status,
                        'activations' => $fresh->activations,
                        'idempotent'  => true,
                    ],
                ]);
            }

            return response()->json([
                'message' => '激活次数已达上限。',
                'code'    => 'MAX_ACTIVATIONS_REACHED',
            ], 409);
        }

        // 安装事实落在 meta(非计数,与上面的原子自增分开写)
        $entitlement->refresh();
        $meta = is_array($entitlement->meta) ? $entitlement->meta : [];
        $meta['installed_at'] = now()->toDateTimeString();
        $meta['installed_by'] = 'user_reported';

        // 如实记录"装到哪台设备"：did 是下发/运维链路认的那把钥匙
        if ($binding) {
            $meta['installed_did'] = $binding->did;
        }
        $meta['installed_device_confirmed'] = (bool) $binding;

        $entitlement->meta = $meta;
        $entitlement->save();

        // D4 · 自报安装同步落 install_record。
        // 此前 confirm-install 只改 meta/status,安装事实分裂成两套:走人工通道的用户
        // 在灰度回滚(GrayReleaseService)、事故影响面(IncidentService)、设备已装列表
        // (GET devices/{id}/installs)里**查无此设备** —— 它们都只读 install_record。
        // 唯一键是 did+skill_version_id,故需有已确权设备(binding.did)与锁定版本才写。
        if ($binding && $entitlement->skill_version_id) {
            InstallRecord::updateOrCreate(
                [
                    'did'              => $binding->did,
                    'skill_version_id' => $entitlement->skill_version_id,
                ],
                [
                    'entitlement_id'  => $entitlement->id,
                    'status'          => 'installed',
                    'current_version' => $entitlement->version?->version,
                    'installed_at'    => now(),
                ]
            );
        }

        return response()->json([
            'message' => '已标记为安装完成。',
            'data'    => [
                'id'          => $entitlement->id,
                'status'      => $entitlement->status,
                'activations' => $entitlement->activations,
                'device_did'  => $binding?->did,
                // 未确权的设备照样能自报安装（兜底通道），但状态如实标注
                'device_confirmed' => (bool) $binding,
            ],
        ]);
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
