<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;

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
            'operation'        => ['nullable', 'integer', 'in:1,2,3,4,5'],
            'channel'          => ['nullable', 'string', 'in:agent,manual'],
        ]);

        // 设备必须属于本人。device_id 一律指 devices.id(v1 语义)——
        // 经 devices.binding_id / devices_id / sn 三路找 binding,**不再与 binding.id 混指**
        // (旧写法同时用 devices_id 与 id 匹配,id 撞号时可能取错行)。
        $binding = $this->resolveOwnedBinding($customer->id, (int) $payload['device_id']);

        if (! $binding) {
            return response()->json(['message' => '设备不存在或不属于你。'], 404);
        }

        // 技能授权必须属于本人(防止给别人的技能下发)
        $entitlement = null;
        if (! empty($payload['entitlement_id'])) {
            $entitlement = SkillEntitlement::query()
                ->where('customer_id', $customer->id)
                ->where('id', $payload['entitlement_id'])
                ->first();

            if (! $entitlement) {
                return response()->json(['message' => '技能授权不存在或不属于你。'], 404);
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

        $task = $this->service->create(
            did: $binding->did,
            skillId: (int) $payload['skill_id'],
            skillVersionId: $skillVersionId,
            entitlementId: $entitlement?->id,
            operation: (int) ($payload['operation'] ?? DeployTask::OP_INSTALL),
            channel: $payload['channel'] ?? 'agent',
            createdBy: 'customer:'.$customer->id,
        );

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
        $task = DeployTask::query()->where('task_no', $taskNo)->first();

        if (! $task) {
            return response()->json(['message' => '任务不存在。'], 404);
        }

        return response()->json(['data' => $this->format($task)]);
    }

    /**
     * 取消任务。
     */
    public function cancel(string $taskNo): JsonResponse
    {
        $task = $this->service->cancel($taskNo);

        if (! $task) {
            return response()->json(['message' => '任务不存在。'], 404);
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
            return response()->json(['message' => '设备不存在。'], 404);
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
