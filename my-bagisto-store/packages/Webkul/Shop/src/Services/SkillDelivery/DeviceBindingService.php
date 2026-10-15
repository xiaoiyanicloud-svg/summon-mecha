<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceModel;

/**
 * 设备绑定服务 —— 承载"设备唯一真相源"(v3.4 步骤 2.3)。
 *
 * 语义(计划 §3.2):
 *   devices        = 用户自报登记(弱验证)
 *   device_binding = 平台确权设备(强验证) —— 下发链路只认它
 *
 * ⚠ 本服务只做「加性」工作:在用户登记设备时,补建一条 device_binding 并把
 *   devices.binding_id 回填。**不改动 v1 现有以 devices.customer_id 为准的读逻辑**,
 *   避免在 DB 未验证的情况下破坏线上接口。
 *
 * 归属一致性:devices.customer_id ↔ device_binding.owner_customer_id 用事务保证同步。
 */
class DeviceBindingService
{
    /**
     * 用户登记设备后,确保存在一条对应的 binding,并回填 devices.binding_id。
     *
     * 幂等:同一 device 重复调用不会产生第二条 binding。
     */
    public function ensureForDevice(Device $device): ?DeviceBinding
    {
        if (! $device->exists) {
            return null;
        }

        return DB::transaction(function () use ($device) {
            // 已回填过 binding_id 的,直接取回
            if ($device->binding_id) {
                $existing = DeviceBinding::find($device->binding_id);

                if ($existing) {
                    return $existing;
                }
            }

            // ⚠ 归并(与 AgentController::register 对称):Agent 可能已按同一 sn 自注册过
            //   (source=agent)。若存在,直接认领那一行并回填 owner/devices_id,
            //   不再另建 `sn_{sn}` 行 —— 否则同一台机器两条 binding,下发链路认错行。
            $binding = DeviceBinding::query()
                ->where('sn', $device->sn)
                ->where(function ($q) use ($device) {
                    $q->whereNull('devices_id')->orWhere('devices_id', $device->id);
                })
                ->first();

            if ($binding) {
                $binding->owner_customer_id = $binding->owner_customer_id ?: $device->customer_id;
                $binding->devices_id = $binding->devices_id ?: $device->id;

                if (! $binding->model_id) {
                    $binding->model_id = $this->resolveModelId($device);
                }

                if (! $binding->bound_at) {
                    $binding->bound_at = $device->bound_at ?: now();
                }

                $binding->save();
            } else {
                // 人工登记来源的 did:sn_{sn}(计划 §3.2 约定)
                $did = 'sn_'.$device->sn;

                $binding = DeviceBinding::firstOrCreate(
                    ['did' => $did],
                    [
                        'source'            => DeviceBinding::SOURCE_MANUAL,
                        'sn'                => $device->sn,
                        'model_id'          => $this->resolveModelId($device),
                        'owner_customer_id' => $device->customer_id,
                        'devices_id'        => $device->id,
                        'bind_status'       => DeviceBinding::BIND_STATUS_BOUND,
                        'bound_at'          => $device->bound_at ?: now(),
                    ]
                );
            }

            // 回填单向引用(只在 devices 侧存 binding_id,本表不反向指 devices)
            if (! $device->binding_id) {
                $device->binding_id = $binding->id;
                $device->save();
            }

            return $binding;
        });
    }

    /**
     * 解绑设备 —— 把**真相源** `device_binding` 置为 `unbound`（D1）。
     *
     * 为什么必须做：`devices.bind_status` 只是登记视图，下发链路**只认 binding**
     *   （见 `DeployTaskService::dispatch()`）。此前解绑只改 devices，于是出现
     *   "界面已解绑、下发链路仍认它"的分叉。本方法把真相源同步过来。
     *
     * ⚠ 只改绑定状态，**不回收授权**：技能授权归 `skill_entitlements` 管，回收走订单取消那条路
     *   （见 `DeviceController::destroy()` 注释）。`owner_customer_id` 也保留 ——
     *   它是"谁曾经绑过这台设备"的追溯凭据，解绑不该抹掉归属历史。
     *
     * 幂等：binding 不存在直接返回；已是 `unbound` 再调一次不产生副作用。
     */
    public function unbind(Device $device): void
    {
        $binding = $this->bindingFor($device);

        if (! $binding) {
            return;
        }

        if ($binding->bind_status === DeviceBinding::BIND_STATUS_UNBOUND) {
            return;
        }

        $binding->bind_status = DeviceBinding::BIND_STATUS_UNBOUND;
        $binding->unbound_at = now();
        $binding->save();
    }

    /**
     * 只读取 binding，不做任何写入。
     *
     * 查找顺序与 ensureForDevice 一致：devices.binding_id → devices_id → sn。
     * 给**读接口**用（GET 不该有副作用）。
     */
    public function bindingFor(Device $device): ?DeviceBinding
    {
        if ($device->binding_id) {
            $existing = DeviceBinding::find($device->binding_id);

            if ($existing) {
                return $existing;
            }
        }

        return DeviceBinding::query()
            ->where(function ($q) use ($device) {
                $q->where('devices_id', $device->id)->orWhere('sn', $device->sn);
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * 解析"这台设备对该用户是否有效"—— **以 binding 为归属真相源**（v3.4 步骤 2）。
     *
     * 为什么需要统一入口：此前 v1 各处各自读 `devices.customer_id` 判归属，
     * 而 `devices` 只是**用户自报登记**（弱验证），`device_binding` 才是确权记录。
     * 两者可能分叉（例如 Agent 侧注册带来的归属），分叉时必须以 binding 为准 ——
     * 否则"唯一真相源"只是句口号。
     *
     * @param  bool  $heal  找不到 binding 时是否补建。**读接口传 false**（GET 不该写库）；
     *                      写接口传 true —— 这也是历史遗留设备（binding 之前登记的）的自愈路径。
     * @return array{ok: bool, binding: ?DeviceBinding, reason: string}
     */
    public function resolveForCustomer(Device $device, int $customerId, bool $heal = false): array
    {
        // devices 侧的自报归属仍是第一道门（连登记都不是你的，免谈）
        if ((int) $device->customer_id !== $customerId) {
            return ['ok' => false, 'binding' => null, 'reason' => '设备不存在或不属于你。'];
        }

        $binding = $heal
            ? $this->ensureForDevice($device)
            : $this->bindingFor($device);

        // 没有 binding：降级放行（历史设备），但如实告诉调用方"这是未确权的自报设备"
        if (! $binding) {
            return ['ok' => true, 'binding' => null, 'reason' => '该设备尚未确权（仅有自报登记）。'];
        }

        // ⚠ 有 binding 就以它为准：owner 不一致 → 判定失败（这才是"真相源"的意义）
        if ($binding->owner_customer_id !== null && (int) $binding->owner_customer_id !== $customerId) {
            return ['ok' => false, 'binding' => null, 'reason' => '设备不存在或不属于你。'];
        }

        return ['ok' => true, 'binding' => $binding, 'reason' => ''];
    }

    /**
     * 从设备的机型推断 device_model.id(尽力而为,推不出返回 null)。
     *
     * ⚠ 当前 devices 只有 model_code(model_code 可能为空),先按 model_code 精确匹配;
     *   匹配不到就返回 null —— 兼容性校验逻辑必须能处理"未知机型"。
     */
    protected function resolveModelId(Device $device): ?int
    {
        if (! $device->model_code) {
            return null;
        }

        return DeviceModel::query()
            ->where('model_code', $device->model_code)
            ->value('id');
    }
}
