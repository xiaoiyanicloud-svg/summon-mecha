<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DowngradeRejectedException;
use Webkul\Shop\Services\Deploy\SkillBlockedException;
use Webkul\Shop\Services\SkillDelivery\SkillAssetAmbiguousException;

/**
 * 下发任务 —— 用户侧(v3.4 步骤 8)。
 *
 * 用户对"本人设备"发起安装/卸载/回滚/启停;查询状态;取消。
 * 只认 device_binding(设备唯一真相源),并校验归属(恒带 owner_customer_id)。
 */
class DeployController extends APIController
{
    public function __construct(protected DeployTaskService $service) {}

    /**
     * 对一个已授权技能发起下发(默认安装)。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'device_id'        => ['required', 'integer'],
            'skill_id'         => ['required', 'integer'],
            'skill_version_id' => ['nullable', 'integer'],
            'entitlement_id'   => ['nullable', 'integer'],
            // ⚠ 白名单必须与 DeployTask::OP_* 对齐,**不写字面量**:
            //   此前写死 'in:1,2,3,4,5',漏了 OP_RECLAIM=6 → 合法的「试用到期回收」被挡死。
            'operation'        => ['nullable', 'integer', Rule::in(DeployTask::OPERATIONS)],
            'channel'          => ['nullable', 'string', 'in:agent,manual'],
        ]);

        // 设备必须属于本人。device_id 一律指 devices.id(v1 语义)——
        // 经 devices.binding_id / devices_id / sn 三路找 binding,**不再与 binding.id 混指**
        // (旧写法同时用 devices_id 与 id 匹配,id 撞号时可能取错行)。
        $binding = $this->resolveOwnedBinding($customer->id, (int) $payload['device_id']);

        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在或不属于你。', 404);
        }

        $operation = (int) ($payload['operation'] ?? DeployTask::OP_INSTALL);

        // 技能授权必须属于本人(防止给别人的技能下发或未购/未付白嫖下发)
        $entitlement = null;
        if (! empty($payload['entitlement_id'])) {
            $entitlement = SkillEntitlement::query()
                ->where('customer_id', $customer->id)
                ->where('id', $payload['entitlement_id'])
                ->first();

            if (! $entitlement) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权不存在或不属于你。', 404);
            }

            if ((int) $entitlement->product_id !== (int) $payload['skill_id']) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '技能授权与目标技能不匹配。', 404);
            }
        } else {
            $entitlement = SkillEntitlement::query()
                ->where('customer_id', $customer->id)
                ->where('product_id', (int) $payload['skill_id'])
                ->orderByDesc('id')
                ->first();
        }

        if ($operation === DeployTask::OP_INSTALL) {
            if ($entitlement) {
                if (! $entitlement->isUsable()) {
                    return $this->fail(ApiErrorCode::ENTITLEMENT_REVOKED, '该技能授权已失效，无法下发。', 403);
                }

                if (! $entitlement->isTrial() && ! $entitlement->isOrderPaid()) {
                    return $this->fail(ApiErrorCode::ENTITLEMENT_UNPAID, '订单尚未支付，请完成支付后再下发技能。', 409);
                }
            } elseif (\Illuminate\Support\Facades\DB::table('products')->where('id', (int) $payload['skill_id'])->exists()) {
                return $this->fail(ApiErrorCode::ENTITLEMENT_NOT_FOUND, '您尚未获取该技能的授权，请先购买或申请试用后再下发。', 403);
            }
        }

        // 版本必须解析出来:为空则 recordInstall 直接跳过(唯一键含 skill_version_id),
        // 不产生 install_record,灰度/回滚将永远看不到这台设备。
        $skillVersionId = $payload['skill_version_id'] ?? null;

        if (! $skillVersionId) {
            $skillVersionId = $entitlement?->skill_version_id
                ?: SkillVersion::query()
                    ->where('product_id', (int) $payload['skill_id'])
                    ->where('status', SkillVersion::STATUS_PUBLISHED)
                    ->orderByDesc('released_at')
                    ->value('id');
        }

        try {
            $task = $this->service->create(
                did: $binding->did,
                skillId: (int) $payload['skill_id'],
                skillVersionId: $skillVersionId,
                entitlementId: $entitlement?->id,
                operation: (int) ($payload['operation'] ?? DeployTask::OP_INSTALL),
                channel: $payload['channel'] ?? 'agent',
                createdBy: 'customer:'.$customer->id,
            );
        } catch (SkillBlockedException $e) {
            // 熔断命中 —— 明确 409,不落任何任务(见 DeployTaskService::assertNotBlocked)
            return $this->fail(ApiErrorCode::SKILL_BLOCKED, $e->getMessage(), 409);
        } catch (DowngradeRejectedException $e) {
            // 版本单调闸(§4.22 F1 / B-13)—— 拒绝向设备装更旧的版本。
            return $this->fail(ApiErrorCode::AGENT_DOWNGRADE_REJECTED, $e->getMessage(), 409, [
                'data' => [
                    'installed_version' => $e->installedVersion,
                    'target_version'    => $e->targetVersion,
                ],
            ]);
        } catch (SkillAssetAmbiguousException $e) {
            // 多产物且没有唯一可交付项 —— 不猜、不取排序第一个，让调用方显式指定。
            return $this->fail(ApiErrorCode::SKILL_ASSET_AMBIGUOUS, $e->getMessage(), 409, [
                'data' => [
                    'version_id' => $e->versionId,
                    'asset_ids'  => $e->assetIds,
                ],
            ]);
        }

        return response()->json([
            'message' => '下发任务已创建。',
            'data'    => $this->format($task),
        ], 201);
    }

    /**
     * 查询任务状态(前端 3s 轮询)。
     */
    public function show(string $taskNo): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $task = $this->findOwnedTask($customer->id, $taskNo);

        if (! $task) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        return response()->json(['data' => $this->format($task)]);
    }

    /**
     * 取消任务。
     */
    public function cancel(string $taskNo): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        // 先过归属:不是本人的任务直接 404 —— 不给"这个单号存在但你没权限"的差异
        if (! $this->findOwnedTask($customer->id, $taskNo)) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        $task = $this->service->cancel($taskNo);

        if (! $task) {
            return $this->fail(ApiErrorCode::TASK_NOT_FOUND, '任务不存在。', 404);
        }

        return response()->json(['message' => '已取消。', 'data' => $this->format($task)]);
    }

    /**
     * 某设备已安装的技能(install_record)。
     */
    public function installs(int $deviceId): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $binding = $this->resolveOwnedBinding($customer->id, $deviceId);

        if (! $binding) {
            return $this->fail(ApiErrorCode::DEVICE_NOT_FOUND, '设备不存在。', 404);
        }

        $rows = InstallRecord::query()->where('did', $binding->did)->get();

        return response()->json([
            'data' => $rows->map(fn (InstallRecord $r) => [
                'id'               => $r->id,
                'skill_version_id' => $r->skill_version_id,
                'status'           => $r->status,
                'current_version'  => $r->current_version,
                'installed_at'     => optional($r->installed_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * 找"本人设备"对应的 binding(下发链路的唯一入口)。
     *
     * device_id 一律指 devices.id(v1 语义),经 devices.binding_id / devices_id / sn 三路兜底 ——
     * **不与 binding.id 混指**,否则同一用户内 id 撞号时可能取错行。
     */
    protected function resolveOwnedBinding(int $customerId, int $deviceId): ?DeviceBinding
    {
        $device = Device::query()
            ->where('customer_id', $customerId)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return null;
        }

        return DeviceBinding::query()
            ->where('owner_customer_id', $customerId)
            ->where(function ($q) use ($device) {
                $q->where('devices_id', $device->id);

                if ($device->binding_id) {
                    $q->orWhere('id', $device->binding_id);
                }

                $q->orWhere('sn', $device->sn);
            })
            ->first();
    }

    /**
     * 按 task_no 取"本人设备"的下发任务 —— show / cancel 的唯一入口(防 IDOR)。
     *
     * 下发任务只带 did(设备真相源是 binding),所以归属经 **binding.owner_customer_id 反查**:
     * 取本人全部 did,任务 did 不在其中即视为不存在。单号规则每天只有约 9000 种组合、
     * 可被枚举,因此不匹配时返回 404(而非 403)—— 不给"存在与否"的差异信号。
     */
    protected function findOwnedTask(int $customerId, string $taskNo): ?DeployTask
    {
        $ownedDids = DeviceBinding::query()
            ->where('owner_customer_id', $customerId)
            ->pluck('did')
            ->all();

        if (empty($ownedDids)) {
            return null;
        }

        return DeployTask::query()
            ->where('task_no', $taskNo)
            ->whereIn('did', $ownedDids)
            ->first();
    }

    protected function format(DeployTask $task): array
    {
        return [
            'task_no'   => $task->task_no,
            'did'       => $task->did,
            'operation' => $task->operation,
            'status'    => $task->status,
            'skill_id'  => $task->skill_id,
            'error_code' => $task->error_code,
            'error_msg'  => $task->error_msg,
            'created_at' => optional($task->created_at)->toDateTimeString(),
            'completed_at' => optional($task->completed_at)->toDateTimeString(),
        ];
    }
}
