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
            'code' => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/', 'name' => 'required|string|max:255',
            'kind' => 'required|in:robot,service,transport,operator,accessory,skill',
            'product_id' => 'nullable|integer|exists:products,id', 'unit' => 'required|in:day,shift,trip,item,project,hour',
            'currency' => 'required|in:CNY', 'unit_minor' => 'required|integer|min:0|max:1000000000',
            'tax_bps' => 'required|integer|min:0|max:10000', 'charge_type' => 'required|in:fee,deposit',
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
            'product_id' => 'required|integer|exists:products,id', 'binding_id' => 'required|integer|exists:device_binding,id',
            'capability' => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/', 'level' => 'required|in:L1,L2,L3',
            'scope' => 'required|string|max:10000', 'source_reference' => 'required|string|max:255',
            'artifact_hash' => 'required|regex:/^[a-f0-9]{64}$/',
            'verified_at' => 'required|date|before_or_equal:now', 'expires_at' => 'required|date|after:now|after:verified_at',
            'constraints' => 'sometimes|array:categories,max_payload_kg,min_clearance_mm,max_slope_deg,max_throughput_units_hour,max_route_length_m,max_sessions,max_participants,max_floors,outdoor_verified,hazardous_environment_verified,production_verified',
            'constraints.categories' => 'sometimes|array|min:1|max:9', 'constraints.categories.*' => ['required', \Illuminate\Validation\Rule::in(array_keys(\Webkul\Shop\Models\Scene::CATEGORIES))],
            'constraints.max_payload_kg' => 'nullable|numeric|min:0|max:100000', 'constraints.min_clearance_mm' => 'nullable|integer|min:1|max:100000',
            'constraints.max_slope_deg' => 'nullable|numeric|min:0|max:90', 'constraints.max_throughput_units_hour' => 'nullable|integer|min:1|max:1000000',
            'constraints.max_route_length_m' => 'nullable|numeric|min:1|max:100000', 'constraints.max_sessions' => 'nullable|integer|min:1|max:1000',
            'constraints.max_participants' => 'nullable|integer|min:1|max:100000', 'constraints.max_floors' => 'nullable|integer|min:1|max:200',
            'constraints.outdoor_verified' => 'nullable|boolean', 'constraints.hazardous_environment_verified' => 'nullable|boolean', 'constraints.production_verified' => 'nullable|boolean',
            'skill' => 'sometimes|array:version_id,asset_id,device_id', 'skill.version_id' => 'required_with:skill|integer|min:1',
            'skill.asset_id' => 'required_with:skill|integer|min:1', 'skill.device_id' => 'required_with:skill|integer|min:1',
        ])->validate();

        $data['verified_at'] = SceneWorkflow::utc($data['verified_at']);
        $data['expires_at'] = SceneWorkflow::utc($data['expires_at']);
        foreach (['outdoor_verified', 'hazardous_environment_verified', 'production_verified'] as $key) {
            if (isset($data['constraints'][$key])) {
                $data['constraints'][$key] = (bool) $data['constraints'][$key];
            }
        }

        return DB::transaction(function () use ($data, $actor) {
            $binding = SceneWorkflow::requireRow('device_binding', (int) $data['binding_id']);
            if ($binding->bind_status !== 'bound') {
                throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备绑定尚未生效。');
            }
            $qualifications = app(SceneQualificationService::class);
            $data['binding_snapshot'] = SceneWorkflow::json($qualifications->bindingSnapshot($binding));
            $data['constraint_snapshot'] = SceneWorkflow::json($data['constraints'] ?? []);
            $data['skill_snapshot'] = SceneWorkflow::json($qualifications->skillSnapshot($data['skill'] ?? [], $binding));
            unset($data['constraints'],$data['skill']);
            $id = DB::table('scene_evidence')->insertGetId($data + ['kind' => 'manual_task_verification', 'verified_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_evidence', $id, 1, 'scene.evidence.recorded');

            return ['id' => $id, 'source' => 'manual_task_verification'];
        }, 3);
    }

    public function reviewEvidence(int $id, array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, ['source_verified' => 'required|accepted', 'reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($id, $data, $actor) {
            $ref = SceneWorkflow::requireRow('scene_evidence', $id, false);
            $binding = SceneWorkflow::requireRow('device_binding', (int) $ref->binding_id);
            $e = SceneWorkflow::requireRow('scene_evidence', $id);
            if ($e->approved_at || $e->revoked_at || $e->expires_at <= now('UTC')) {
                throw new SceneWorkflowException('SCENE_EVIDENCE_UNAVAILABLE', '证据已处理、撤销或到期。', 409);
            }
            if ((int) $e->verified_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '任务证据录入人不能自行复核。', 403);
            }
            $q = app(SceneQualificationService::class);
            $bindingSnapshot = $q->bindingSnapshot($binding);
            if (! $bindingSnapshot['model_code'] || ! $bindingSnapshot['did'] || $binding->bind_status !== 'bound'
                || ! hash_equals(SceneWorkflow::hash(SceneWorkflow::unpack($e->binding_snapshot)), SceneWorkflow::hash($bindingSnapshot))) {
                throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备机型未知、绑定已改变或凭据缺失，不能复核为可交付。', 409);
            }
            if (empty(SceneWorkflow::unpack($e->constraint_snapshot)['categories'])) {
                throw new SceneWorkflowException('SCENE_EVIDENCE_SCOPE_REQUIRED', '须明确核验适用场景与定量边界。');
            }
            $skill = SceneWorkflow::unpack($e->skill_snapshot);
            if ($skill && ! hash_equals(SceneWorkflow::hash($skill), SceneWorkflow::hash($q->skillSnapshot($skill, $binding)))) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '技能产物或依据已经变化。', 409);
            }
            DB::table('scene_evidence')->where('id', $id)->update(['approved_by' => $actor, 'approved_at' => now('UTC'), 'review_note' => $data['reason'], 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_evidence', $id, 2, 'scene.evidence.approved');

            return ['id' => $id, 'state' => 'approved', 'source' => 'independently_reviewed_manual_task_verification'];
        }, 3);
    }

    public function resource(array $input, int $actor): array
    {
        $this->flow->enabled('reservations_enabled');
        $data = Validator::make($input, [
            'resource_no' => 'required|string|max:80|unique:scene_resources,resource_no', 'kind' => 'required|in:robot,operator,accessory',
            'product_id' => 'nullable|integer|exists:products,id', 'binding_id' => 'nullable|integer|exists:device_binding,id|unique:scene_resources,binding_id',
            'external_reference' => 'required|string|max:255', 'city' => 'required|string|max:100', 'timezone' => 'required|string|max:80',
            'identity_reference' => 'required|string|min:3|max:255',
            'verification_expires_at' => 'required|date|after:now',
        ])->validate();
        if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new SceneWorkflowException('SCENE_TIMEZONE_INVALID', '资源时区无效。');
        }
        if ($data['kind'] === 'robot' && (empty($data['binding_id']) || empty($data['product_id']))) {
            throw new SceneWorkflowException('SCENE_BINDING_REQUIRED', '可确认的机器人资源必须引用真实 binding 和商品。');
        }
        if ($data['kind'] !== 'robot' && ! empty($data['binding_id'])) {
            throw new SceneWorkflowException('SCENE_RESOURCE_IDENTITY_INVALID', '人员和配件不能占用机器人 binding。');
        }
        $data['identity_hash'] = SceneWorkflow::hash([$data['kind'], mb_strtolower(trim($data['identity_reference']))]);
        unset($data['identity_reference']);
        $data['verification_expires_at'] = SceneWorkflow::utc($data['verification_expires_at']);

        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['binding_id'])) {
                $binding = SceneWorkflow::requireRow('device_binding', (int) $data['binding_id']);
                if ($binding->bind_status !== 'bound') {
                    throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '设备绑定尚未生效。');
                }
            }
            $id = DB::table('scene_resources')->insertGetId($data + ['state' => 'unverified', 'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_resource', $id, 1, 'scene.resource.registered');

            return ['id' => $id, 'row_version' => 1, 'state' => 'unverified'];
        }, 3);
    }

    public function revoke(string $kind, int $id, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        if (! in_array($kind, ['evidence', 'price'], true) || trim($reason) === '' || strlen($reason) > 5000) {
            throw new SceneWorkflowException('SCENE_REVOKE_INVALID', '撤销类型或原因无效。');
        }
        $table = $kind === 'evidence' ? 'scene_evidence' : 'scene_price_items';

        return DB::transaction(function () use ($kind, $id, $table, $actor, $reason) {
            $row = SceneWorkflow::requireRow($table, $id);
            if ($row->revoked_at) {
                return ['id' => $id, 'state' => 'revoked'];
            }
            DB::table($table)->where('id', $id)->update(['revoked_at' => now('UTC'), 'revocation_reason' => $reason]);
            // 已承诺对象保留，待办由操作台扫描当前事实产生。
            $this->flow->record('admin', $actor, 'scene_'.$kind, $id, 1, 'scene.'.$kind.'.revoked');

            return ['id' => $id, 'state' => 'revoked'];
        }, 3);
    }
}
