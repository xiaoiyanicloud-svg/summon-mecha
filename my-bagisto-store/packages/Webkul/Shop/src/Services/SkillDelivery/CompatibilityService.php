<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\SkillVersion;

/**
 * 兼容性校验(v3.4 步骤 5)。
 *
 * 输入:用户设备 + 技能商品 → 输出 {compatible, reason, channels[], notes[]}
 *
 * 判定(计划 §4.3):
 *   可用通道 = 技能声明通道 ∩ 机型可用通道(∩ 设备实际在线通道,离线仅提示)
 *
 * ⚠「未知」降级(关键):
 *   - 设备没填/填错机型 → 不判"机型不匹配",降级为"仅人工通道"+ 提示
 *   - 固件版本未知(多数厂商不公开)→ 不判"固件过低",只提示"请确认固件版本"
 */
class CompatibilityService
{
    /**
     * @return array{compatible: bool, reason: string, channels: array, notes: array, model: ?string}
     */
    public function check(int $customerId, int $productId, int $deviceId): array
    {
        $device = Device::query()
            ->where('customer_id', $customerId)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return $this->fail('设备不存在或不属于你。');
        }

        // v3.4 步骤 2：设备归属以 binding 为准（**只读** —— GET 不该写库，故不 heal）
        $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customerId);

        if (! $resolved['ok']) {
            return $this->fail($resolved['reason'] ?: '设备不存在或不属于你。');
        }

        $binding = $resolved['binding'];

        $version = SkillVersion::query()
            ->where('product_id', $productId)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        if (! $version) {
            return $this->fail('该技能暂未发布可交付版本。');
        }

        $notes = [];

        // 未确权（只有自报登记）→ 如实提示：自动下发这一步走不通，别让用户以为能一键装
        if (! $binding) {
            $notes[] = '该设备尚未确权（仅自报登记），自动下发通道不可用，请先完成设备确权。';
        }

        // 技能声明的交付通道(v3.4 新列);缺省视为仅人工
        $declared = is_array($version->delivery_channels) && $version->delivery_channels
            ? $version->delivery_channels
            : ['manual'];

        // 机型可用通道
        $model = null;
        if ($device->model_code) {
            $model = DeviceModel::query()->where('model_code', $device->model_code)->first();
        }

        if (! $model) {
            // 「未知机型」降级:不判不兼容,只允许人工通道
            $notes[] = '未识别机型(设备未关联机型库),仅提供人工安装通道。';
            $deviceChannels = ['manual'];
        } else {
            $deviceChannels = is_array($model->comm_channels) ? $model->comm_channels : ['manual'];

            if ($model->status === 'display_only') {
                $notes[] = '该机型暂为仅展示(尚无自动下发通道),仅提供人工安装。';
            }

            // 固件"未知"降级:不判固件过低
            if (! $model->firmware_versions) {
                $notes[] = '该机型固件版本信息未知,请在安装前自行确认固件版本。';
            }
        }

        // 交集
        $channels = array_values(array_intersect($declared, $deviceChannels));

        if (empty($channels)) {
            return [
                'compatible' => false,
                'reason'     => '无可用通道(该技能声明的交付方式与你的机型不匹配)。',
                'channels'   => [],
                'notes'      => $notes,
                'model'      => $model?->model_code,
            ];
        }

        // 设备离线仅提示(不影响 manual;影响 agent)
        //
        // ⚠ 这里此前调的是 `$this->isOnline($device)`，而那个方法**硬编码 `return false`**
        //   （注释给的理由是"binding 未接在线心跳"）。心跳早就接上了 —— 于是这条提示
        //   变成一句**恒定输出**的假话：设备明明在线，界面也告诉用户"设备当前离线"。
        //   现在与其余几处读点用同一个判据（`DeviceBinding::isOnlineNow()`）。
        //
        //   ⚠ 离线仍然只是"提示"，不是"拒绝"：该做的是挂起下发（`pending_online`），
        //     而不是让用户装不了 —— 所以这里不改成 fail。
        if (in_array('agent', $channels, true) && ! $binding?->isOnlineNow()) {
            $notes[] = '设备当前离线,自动下发将在设备上线后进行。';
        }

        return [
            'compatible' => true,
            'reason'     => '兼容。',
            'channels'   => $channels,
            'notes'      => $notes,
            'model'      => $model?->model_code,
        ];
    }

    protected function fail(string $reason): array
    {
        return ['compatible' => false, 'reason' => $reason, 'channels' => [], 'notes' => [], 'model' => null];
    }
}
