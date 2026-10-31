<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 每条资源是一台设备、一名人员或一件配件；不使用未经核验的聚合库存。 */
class SceneResourceService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function registerLegacy(int $id, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, ['identity_reference' => 'required|string|min:3|max:255', 'external_reference' => 'required|string|min:3|max:255'])->validate();

        return DB::transaction(function () use ($id, $expected, $data, $actor) {
            $r = SceneWorkflow::requireRow('scene_resources', $id);
            SceneWorkflow::version($r, $expected);
            if ($r->created_by || DB::table('scene_reservations')->where('resource_id', $id)->where('state', 'confirmed')->lockForUpdate()->first()) {
                throw new SceneWorkflowException('SCENE_RESOURCE_COMMITTED', '已登记资源不能覆盖来源；历史已承诺资源须先处理影响。', 409);
            }
            DB::table('scene_resources')->where('id', $id)->update(['created_by' => $actor,
                'identity_hash' => SceneWorkflow::hash([$r->kind, mb_strtolower(trim($data['identity_reference']))]),
                'external_reference' => $data['external_reference'], 'state' => 'unverified', 'verified_by' => null, 'verified_at' => null,
                'availability' => null, 'verification_reference' => null, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_resource', $id, $expected + 1, 'scene.resource.registered');

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => 'unverified'];
        }, 3);
    }

    public function verify(int $id, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, [
            'verification_reference' => 'required|string|min:3|max:255',
            'verification_expires_at' => 'required|date|after:now',
            'city' => 'sometimes|string|min:1|max:100', 'timezone' => 'sometimes|string|max:80',
            'buffer_before_minutes' => 'required|integer|min:0|max:10080',
            'buffer_after_minutes' => 'required|integer|min:0|max:10080',
            'availability' => 'required|array|min:1|max:100',
            'availability.*' => 'required|array:starts_at,ends_at',
            'availability.*.starts_at' => 'required|string|max:40', 'availability.*.ends_at' => 'required|string|max:40',
        ])->validate();

        return DB::transaction(function () use ($id, $expected, $data, $actor) {
            $r = SceneWorkflow::requireRow('scene_resources', $id);
            SceneWorkflow::version($r, $expected);
            $city = $data['city'] ?? $r->city;
            $timezone = $data['timezone'] ?? $r->timezone;
            if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw new SceneWorkflowException('SCENE_TIMEZONE_INVALID', '资源服务地时区无效。');
            }
            if (! $r->created_by || (int) $r->created_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '资源登记人不能自行复核；历史资源须先补齐登记来源。', 403);
            }
            if (DB::table('scene_reservations')->where('resource_id', $id)->where('state', 'confirmed')->where('occupies_until', '>', now('UTC'))->lockForUpdate()->first()) {
                throw new SceneWorkflowException('SCENE_RESOURCE_COMMITTED', '已有未完成承诺，资源恢复或核验变更须先处理影响待办。', 409);
            }
            $windows = [];
            foreach ($data['availability'] as $window) {
                $w = SceneTime::schedule($window + ['timezone' => $timezone, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
                $windows[] = ['from' => $w['service_starts_at'], 'until' => $w['service_ends_at']];
            }
            usort($windows, fn ($a, $b) => strcmp($a['from'], $b['from']));
            foreach ($windows as $i => $window) {
                if ($i && $window['from'] < $windows[$i - 1]['until']) {
                    throw new SceneWorkflowException('SCENE_RESOURCE_WINDOWS_OVERLAP', '可服务班次不能重叠。');
                }
            }
            if ($r->binding_id && SceneWorkflow::requireRow('device_binding', (int) $r->binding_id)->bind_status !== 'bound') {
                throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备绑定尚未生效。', 409);
            }
            DB::table('scene_resources')->where('id', $id)->update([
                'city' => $city, 'timezone' => $timezone,
                'availability' => SceneWorkflow::json($windows), 'verification_reference' => $data['verification_reference'],
                'verification_expires_at' => SceneWorkflow::utc($data['verification_expires_at']),
                'buffer_before_minutes' => $data['buffer_before_minutes'], 'buffer_after_minutes' => $data['buffer_after_minutes'],
                'state' => 'ready', 'state_reason' => null, 'verified_by' => $actor, 'verified_at' => now('UTC'),
                'row_version' => $expected + 1, 'updated_at' => now('UTC'),
            ]);
            $this->flow->record('admin', $actor, 'scene_resource', $id, $expected + 1, 'scene.resource.verified');

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => 'ready'];
        }, 3);
    }

    public function interval(object $r, array $schedule, string $city): array
    {
        $from = \Illuminate\Support\Carbon::parse($schedule['service_starts_at'], 'UTC')->subMinutes(max((int) $schedule['buffer_before_minutes'], (int) $r->buffer_before_minutes))->format('Y-m-d H:i:s');
        $until = \Illuminate\Support\Carbon::parse($schedule['service_ends_at'], 'UTC')->addMinutes(max((int) $schedule['buffer_after_minutes'], (int) $r->buffer_after_minutes))->format('Y-m-d H:i:s');
        if ($r->state !== 'ready' || ! $r->verified_at || ! $r->verified_by || ! $r->verification_reference
            || ! $r->verification_expires_at || $r->verification_expires_at < $until || $r->city !== $city || $r->timezone !== $schedule['timezone']) {
            throw new SceneWorkflowException('SCENE_RESOURCE_UNAVAILABLE', '资源未独立核验、停用、核验期不足或服务城市/时区不匹配。', 409);
        }
        foreach (SceneWorkflow::unpack($r->availability) as $w) {
            if ($w['from'] <= $from && $w['until'] >= $until) {
                return ['occupies_from' => $from, 'occupies_until' => $until];
            }
        }
        throw new SceneWorkflowException('SCENE_RESOURCE_OUTSIDE_SHIFT', '含运输、休息和维护缓冲的占用超出已核验可服务班次。', 409);
    }

    public function snapshot(object $r): array
    {
        return ['resource_id' => (int) $r->id, 'resource_no' => $r->resource_no, 'kind' => $r->kind,
            'product_id' => $r->product_id ? (int) $r->product_id : null, 'binding_id' => $r->binding_id ? (int) $r->binding_id : null,
            'row_version' => (int) $r->row_version, 'verified_by' => (int) $r->verified_by,
            'verified_at' => $r->verified_at, 'verification_expires_at' => $r->verification_expires_at,
            'verification_hash' => SceneWorkflow::hash([$r->external_reference, $r->verification_reference, $r->availability, $r->city, $r->timezone, $r->buffer_before_minutes, $r->buffer_after_minutes])];
    }
}
