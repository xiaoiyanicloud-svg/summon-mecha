<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\SkillBlockedException;

/**
 * 试用（D4 / 计划 §4.7）—— 「发一条带 expires_at 的授权 + 到期回收」。
 *
 * 这一条链在加固十轮里只铺了一半（`OP_RECLAIM=6` 有了、`trialExpired()` 通知有了、
 * `(status,expires_at)` 索引有了），**缺的是两端**：
 *   · 前端 —— 没有任何东西**发放** `meta.trial` 的授权（`meta.trial` 只被 `AfterSaleService` 读）；
 *   · 后端 —— 没有任何东西**扫描到期**并建回收任务。
 * 本服务补的就是这两端。全流程：
 *
 *   ① start()      用户点「试用」→ 校验（在线通道 / 冷却 / 是否已拥有）→
 *                  发一条 20 分钟授权（`meta.trial=true`）→ **直接走下发**（计划 §4.15）
 *   ② reclaimDue() 定时（每分钟）扫描到期试用 → `status='expiring'` →
 *                  发 `operation=6` 回收任务 → 设备回报成功后落 `expired`
 *                  （见 `DeployTaskService::onEvent()`，与 `trialExpired()` 通知同处）
 *
 * ⚠ 试用**绝不能**给人工通道技能：20 分钟到了，平台没有任何手段把技能从一台
 *   用户自己拷进去的机器上收回来。这就是 D4「仅限在线通道」的全部理由。
 *   `start()` 用**版本声明的 `delivery_channels` 里必须有 `agent`** 作硬闸，不靠前端隐藏按钮。
 */
class TrialService
{
    public function __construct(
        protected DeviceBindingService $bindings,
        protected DeployTaskService $deploy,
    ) {}

    /**
     * 发起试用。
     *
     * @return array{ok: bool, code: ?string, message: string, entitlement: ?SkillEntitlement, task: ?DeployTask}
     */
    public function start(int $customerId, int $productId, int $deviceId, ?int $skillVersionId = null): array
    {
        $version = $this->resolveVersion($productId, $skillVersionId);

        if (! $version) {
            return $this->fail('VERSION_NOT_FOUND', '该技能暂未发布可交付版本，无法试用。');
        }

        // ① 仅在线通道（D4）—— 版本必须真的声明了 agent 通道
        if (! in_array(SkillAsset::DELIVERY_AGENT, (array) ($version->delivery_channels ?? []), true)) {
            return $this->fail(
                'TRIAL_CHANNEL_UNSUPPORTED',
                '该技能不支持试用：试用仅限在线下发通道，人工安装通道无法在到期后回收。',
            );
        }

        // ② 设备归属 + 必须已确权（binding）—— 试用要真下发，未确权设备发不出去
        $device = Device::query()
            ->where('customer_id', $customerId)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return $this->fail('DEVICE_NOT_FOUND', '设备不存在或不属于你。', 404);
        }

        $binding = $this->bindings->bindingFor($device);

        if (! $binding) {
            return $this->fail(
                'DEVICE_BINDING_MISSING',
                '该设备尚未确权（仅自报登记），无法走在线下发，请先完成设备确权。',
            );
        }

        // ③ 已买断 → 不必也不该再开试用（否则"我的技能"里同技能会出现两条授权）
        $owned = SkillEntitlement::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->whereNotNull('order_id')
            ->whereNotIn('status', [SkillEntitlement::STATUS_REVOKED, SkillEntitlement::STATUS_EXPIRED])
            ->exists();

        if ($owned) {
            return $this->fail('TRIAL_ALREADY_OWNED', '你已拥有该技能，无需试用。');
        }

        // ④ 冷却：同一用户、同一技能 7 天内 1 次（计划 §4.15）
        if ($this->trialedRecently($customerId, $productId)) {
            return $this->fail(
                'TRIAL_LIMIT_REACHED',
                '同一技能 '.SkillEntitlement::TRIAL_COOLDOWN_DAYS.' 天内只能试用一次，请稍后再试。',
            );
        }

        try {
            return DB::transaction(function () use ($customerId, $productId, $device, $binding, $version) {
                $now = now();

                $entitlement = SkillEntitlement::create([
                    'customer_id'      => $customerId,
                    // 试用无来源订单 —— 这正是"回收扫描能靠 whereNull(order_id) 缩小范围"的依据
                    'order_id'         => null,
                    'order_item_id'    => null,
                    'product_id'       => $productId,
                    'skill_version_id' => $version->id,
                    'device_id'        => $device->id,
                    // active = 授权生效中（不是 pending_bind —— 试用必须绑到具体设备才能下发）
                    'status'           => SkillEntitlement::STATUS_ACTIVE,
                    'license_key'      => SkillEntitlement::newLicenseKey(),
                    'delivery_channel' => SkillAsset::DELIVERY_AGENT,
                    'granted_at'       => $now,
                    'expires_at'       => $now->copy()->addMinutes(SkillEntitlement::TRIAL_MINUTES),
                    'meta'             => [
                        'trial'            => true,
                        'trial_started_at' => $now->toDateTimeString(),
                    ],
                ]);

                // 直接走下发（计划 §4.15「点击即发 20 分钟试用授权并直接走下发」）。
                // 熔断命中会抛 SkillBlockedException → 整个事务回滚，不留一条发不出去的试用授权。
                $task = $this->deploy->create(
                    did: $binding->did,
                    skillId: $productId,
                    skillVersionId: $version->id,
                    entitlementId: $entitlement->id,
                    operation: DeployTask::OP_INSTALL,
                    channel: 'agent',
                    createdBy: 'customer:'.$customerId,
                );

                return [
                    'ok'          => true,
                    'code'        => null,
                    'message'     => '试用已开通（'.SkillEntitlement::TRIAL_MINUTES.' 分钟）。',
                    'entitlement' => $entitlement,
                    'task'        => $task,
                ];
            });
        } catch (SkillBlockedException $e) {
            return $this->fail('SKILL_BLOCKED', $e->getMessage(), 409);
        }
    }

    /**
     * 扫描到期的试用授权并回收（每分钟由 `ops:reclaim-expired-trials` 调用）。
     *
     * 扫描走 `(status, expires_at)` 索引 + `whereNull(order_id)`：
     *   · 索引：计划 §3.4 就是为"每分钟回收"建的，别用 `meta.trial`（JSON）过滤 —— 会全表扫；
     *   · `order_id IS NULL`：买断授权一律带来源订单，试用一定不带 —— 一串等值的
     *     真正试验判据是 `isTrial()`（PHP 侧复查），`order_id` 只是把候选集压到最小。
     *
     * @param  bool  $dryRun  只统计候选数、不做任何写操作（命令的 --dry-run）
     * @return array{candidates: int, reclaimed: int, tasks: int, expired: int, failed: int}
     */
    public function reclaimDue(bool $dryRun = false): array
    {
        $now = now();

        $candidates = SkillEntitlement::query()
            ->whereNull('order_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->whereNotIn('status', [
                SkillEntitlement::STATUS_EXPIRING,
                SkillEntitlement::STATUS_EXPIRED,
                SkillEntitlement::STATUS_REVOKED,
            ])
            ->orderBy('id')
            ->get()
            // 真正判据：meta.trial=true（上面几步只负责把候选集压小）
            ->filter(fn (SkillEntitlement $e) => $e->isTrial());

        $result = ['candidates' => $candidates->count(), 'reclaimed' => 0, 'tasks' => 0, 'expired' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($candidates as $entitlement) {
            // 原子认领：条件更新（读到的状态为条件），命中 0 行 = 已被别人抢走 → 跳过。
            // 单机调度下这层也防"上一轮没跑完下一轮又起来"（配合 withoutOverlapping）。
            $claimed = SkillEntitlement::query()
                ->whereKey($entitlement->id)
                ->where('status', $entitlement->status)
                ->update([
                    'status'     => SkillEntitlement::STATUS_EXPIRING,
                    'updated_at' => $now,
                ]);

            if ($claimed === 0) {
                continue;
            }

            $result['reclaimed']++;

            $did = $this->didFor($entitlement);

            // 从未装到任何设备（没有可确权设备）→ 没有可回收的实体，直接终态
            if (! $did) {
                SkillEntitlement::query()->whereKey($entitlement->id)
                    ->update(['status' => SkillEntitlement::STATUS_EXPIRED]);

                $result['expired']++;

                continue;
            }

            try {
                $this->deploy->create(
                    did: $did,
                    skillId: (int) $entitlement->product_id,
                    skillVersionId: $entitlement->skill_version_id ?: null,
                    entitlementId: (int) $entitlement->id,
                    operation: DeployTask::OP_RECLAIM,
                    channel: 'agent',
                    createdBy: 'system:trial-reclaim',
                );

                $result['tasks']++;
            } catch (Throwable $e) {
                // 建任务失败（熔断 / 设备状态异常等）→ 退回待回收，下一轮重试。
                // 不能卡在 expiring：那会让这条授权永远"回收中"，也再不会被扫到。
                report($e);

                SkillEntitlement::query()->whereKey($entitlement->id)
                    ->update(['status' => $entitlement->status]);

                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 试用授权的目标设备 did（真相源 binding）—— 取不到就返回 null（无需回收）。
     */
    protected function didFor(SkillEntitlement $entitlement): ?string
    {
        if (! $entitlement->device_id) {
            return null;
        }

        $device = Device::find($entitlement->device_id);

        if (! $device) {
            return null;
        }

        return $this->bindings->bindingFor($device)?->did;
    }

    /**
     * 该用户对该技能在冷却期内是否已试用过。
     *
     * 按 customer+product 取候选（数量极小），再在 PHP 侧用 `isTrial()` 判 ——
     * 不依赖 JSON 查询（跨驱动的 JSON 过滤行为不一致，本项目踩过一次）。
     */
    protected function trialedRecently(int $customerId, int $productId): bool
    {
        return SkillEntitlement::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('created_at', '>=', now()->subDays(SkillEntitlement::TRIAL_COOLDOWN_DAYS))
            ->orderByDesc('id')
            ->get()
            ->contains(fn (SkillEntitlement $e) => $e->isTrial());
    }

    protected function resolveVersion(int $productId, ?int $skillVersionId): ?SkillVersion
    {
        if ($skillVersionId) {
            return SkillVersion::query()
                ->where('id', $skillVersionId)
                ->where('product_id', $productId)
                ->where('status', SkillVersion::STATUS_PUBLISHED)
                ->first();
        }

        return SkillVersion::query()
            ->where('product_id', $productId)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  int  $status  建议的 HTTP 状态码（业务性失败默认 200，请求非法才 4xx）
     * @return array{ok: false, code: string, message: string, status: int, entitlement: null, task: null}
     */
    protected function fail(string $code, string $message, int $status = 200): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status, 'entitlement' => null, 'task' => null];
    }
}
