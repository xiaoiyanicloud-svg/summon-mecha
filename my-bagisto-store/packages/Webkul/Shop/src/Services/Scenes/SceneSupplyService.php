<?php

namespace Webkul\Shop\Services\Scenes;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SceneSupplyService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function price(array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'code'       => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/', 'name' => 'required|string|max:255',
            'kind'       => 'required|in:robot,service,transport,operator,accessory,skill',
            'product_id' => 'nullable|integer|exists:products,id', 'unit' => 'required|in:day,shift,trip,item,project,hour',
            'currency'   => 'required|in:CNY', 'unit_minor' => 'required|integer|min:0|max:1000000000',
            'tax_bps'    => 'required|integer|min:0|max:10000', 'charge_type' => 'required|in:fee,deposit',
            'valid_from' => 'required|date', 'valid_until' => 'required|date|after:valid_from',
        ])->validate();
        if (in_array($data['kind'], ['robot', 'accessory', 'skill'], true) && empty($data['product_id'])) {
            throw new SceneWorkflowException('SCENE_PRODUCT_REQUIRED', '实体或技能价目必须绑定真实商品。');
        }
        $data['valid_from'] = SceneWorkflow::utc($data['valid_from']);
        $data['valid_until'] = SceneWorkflow::utc($data['valid_until']);

        return DB::transaction(function () use ($data, $actor) {
            // 即使还没有任何版本，也先锁稳定编码父行，避免两个首版竞争。
            DB::table('scene_price_codes')->insertOrIgnore(['code' => $data['code'], 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $code = DB::table('scene_price_codes')->where('code', $data['code'])->lockForUpdate()->first();
            $latest = DB::table('scene_price_items')->where('price_code_id', $code->id)->orderByDesc('version')->lockForUpdate()->first();
            $version = $latest ? (int) $latest->version + 1 : 1;
            $id = DB::table('scene_price_items')->insertGetId($data + ['price_code_id' => $code->id, 'version' => $version, 'created_by' => $actor, 'created_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_price', $id, $version, 'scene.price.created');

            return ['id' => $id, 'version' => $version];
        }, 3);
    }

    public function evidence(array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'product_id'    => 'required|integer|exists:products,id', 'binding_id' => 'required|integer|exists:device_binding,id',
            'capability'    => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/', 'level' => 'required|in:L1,L2,L3',
            'scope'         => 'required|string|max:10000', 'source_reference' => 'required|string|max:255',
            'artifact_hash' => 'required|regex:/^[a-f0-9]{64}$/',
            'verified_at'   => 'required|date|before_or_equal:now', 'expires_at' => 'required|date|after:now|after:verified_at',
        ])->validate();

        $data['verified_at'] = SceneWorkflow::utc($data['verified_at']);
        $data['expires_at'] = SceneWorkflow::utc($data['expires_at']);

        return DB::transaction(function () use ($data, $actor) {
            $binding = SceneWorkflow::requireRow('device_binding', (int) $data['binding_id']);
            if ($binding->bind_status !== 'bound') {
                throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备绑定尚未生效。');
            }
            $id = DB::table('scene_evidence')->insertGetId($data + ['kind' => 'manual_task_verification', 'verified_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_evidence', $id, 1, 'scene.evidence.recorded');

            return ['id' => $id, 'source' => 'manual_task_verification'];
        }, 3);
    }

    public function resource(array $input, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, [
            'resource_no'             => 'required|string|max:80|unique:scene_resources,resource_no', 'kind' => 'required|in:robot,operator,accessory',
            'product_id'              => 'nullable|integer|exists:products,id', 'binding_id' => 'nullable|integer|exists:device_binding,id|unique:scene_resources,binding_id',
            'external_reference'      => 'required|string|max:255', 'city' => 'required|string|max:100', 'timezone' => 'required|string|max:80',
            'verification_expires_at' => 'required|date|after:now',
        ])->validate();
        if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new SceneWorkflowException('SCENE_TIMEZONE_INVALID', '资源时区无效。');
        }
        if ($data['kind'] === 'robot' && (empty($data['binding_id']) || empty($data['product_id']))) {
            throw new SceneWorkflowException('SCENE_BINDING_REQUIRED', '可确认的机器人资源必须引用真实 binding 和商品。');
        }
        $data['verification_expires_at'] = SceneWorkflow::utc($data['verification_expires_at']);

        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['binding_id'])) {
                $binding = SceneWorkflow::requireRow('device_binding', (int) $data['binding_id']);
                if ($binding->bind_status !== 'bound') {
                    throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备绑定尚未生效。');
                }
            }
            $id = DB::table('scene_resources')->insertGetId($data + ['state' => 'ready', 'verified_by' => $actor, 'verified_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_resource', $id, 1, 'scene.resource.verified');

            return ['id' => $id, 'row_version' => 1, 'state' => 'ready'];
        }, 3);
    }

    public function revoke(string $kind, int $id, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        if (! in_array($kind, ['evidence', 'price'], true) || trim($reason) === '' || strlen($reason) > 5000) {
            throw new SceneWorkflowException('SCENE_REVOKE_INVALID', '撤销类型或原因无效。');
        }
        $table = $kind === 'evidence' ? 'scene_evidence' : 'scene_price_items';

        return DB::transaction(function () use ($kind, $id, $table, $actor) {
            $row = SceneWorkflow::requireRow($table, $id);
            if ($row->revoked_at) {
                return ['id' => $id, 'state' => 'revoked'];
            }
            DB::table($table)->where('id', $id)->update(['revoked_at' => now('UTC')]);
            // 已承诺对象保留，待办由操作台扫描当前事实产生。
            $this->flow->record('admin', $actor, 'scene_'.$kind, $id, 1, 'scene.'.$kind.'.revoked');

            return ['id' => $id, 'state' => 'revoked'];
        }, 3);
    }
}
