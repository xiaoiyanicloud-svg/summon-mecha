<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\SkillDelivery\CompatibilityService;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAssetResolver;

/** 目录、任务能力和技能安装依据分别判断；任何指纹变化都需要重新核验。 */
class SceneQualificationService
{
    public function bindingSnapshot(object $binding): array
    {
        $meta = SceneWorkflow::unpack($binding->meta ?? null);
        $capabilities = is_array($meta['capability_snapshot'] ?? null) ? $meta['capability_snapshot'] : [];
        $model = ! empty($binding->model_id) ? DB::table('device_model')->where('id', $binding->model_id)->first() : null;

        return ['id' => (int) $binding->id, 'did' => $binding->did ?? null, 'owner_customer_id' => (int) $binding->owner_customer_id,
            'model_id' => $binding->model_id ?? null, 'model_code' => $model?->model_code, 'bind_status' => $binding->bind_status,
            'capabilities' => array_intersect_key($capabilities, array_flip(['model_code', 'firmware_version', 'sdk_version', 'agent_version', 'development_version']))];
    }

    public function skillSnapshot(array $input, object $binding): array
    {
        if (! $input) {
            return [];
        }
        $version = SceneWorkflow::requireRow('skill_versions', (int) $input['version_id'], false);
        SceneWorkflow::requireRow('products', (int) $version->product_id);
        $version = SceneWorkflow::requireRow('skill_versions', (int) $input['version_id']);
        if ($version->status !== 'published' || app(KillSwitchService::class)->isBlockedFor((int) $version->product_id, 'sale', true)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能版本未发布或已被熔断。', 409);
        }
        if (! in_array($version->pricing_mode, ['buyout', 'free'], true)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能定价模式尚未明确。', 409);
        }
        $latest = DB::table('skill_versions')->where('product_id', $version->product_id)->where('status', 'published')->orderByDesc('released_at')->orderByDesc('id')->lockForUpdate()->value('id');
        if ((int) $latest !== (int) $version->id) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能当前发布版本已变化，需重评。', 409);
        }
        $device = SceneWorkflow::requireRow('devices', (int) $input['device_id']);
        if ((int) $device->binding_id !== (int) $binding->id || (int) $device->customer_id !== (int) $binding->owner_customer_id) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能依据中的设备登记与绑定主体不一致。', 409);
        }
        $fingerprint = $this->bindingSnapshot($binding);
        $asset = app(SkillDeliveryAssetResolver::class)->resolveForVersion((int) $version->id, $fingerprint['model_code'], (int) $input['asset_id']);
        if (! $asset || ! $asset->fileExists() || ! preg_match('/^[a-f0-9]{64}$/D', (string) $asset->file_hash)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能产物缺失或没有有效指纹。', 409);
        }
        $stream = Storage::disk(SkillAsset::disk())->readStream($asset->file_path);
        if (! is_resource($stream)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能产物当前不可读。', 409);
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actual = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($asset->file_hash, $actual)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能产物实际内容与登记指纹不符。', 409);
        }
        $compatibility = app(CompatibilityService::class)->check((int) $binding->owner_customer_id, (int) $version->product_id, (int) $device->id);
        $statuses = array_column($compatibility['checks'] ?? [], 'status');
        if (! $compatibility['compatible'] || ($compatibility['adaptation_status'] ?? 'unverified') !== CompatibilityService::STATUS_VERIFIED
            || in_array('fail', $statuses, true) || in_array('unknown', $statuses, true) || ! in_array('manual', $compatibility['channels'], true)) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能通道或适配依据不完整，不能作为已验证任务能力。', 409);
        }

        return ['version_id' => (int) $version->id, 'product_id' => (int) $version->product_id, 'device_id' => (int) $device->id,
            'asset_id' => (int) $asset->id, 'asset_hash' => $asset->file_hash, 'binding_hash' => SceneWorkflow::hash($fingerprint),
            'declaration_hash' => SceneWorkflow::hash([$version->compatibility, $version->delivery_channels]),
            'device_hash' => SceneWorkflow::hash([$device->binding_id, $device->customer_id, $device->model_code, $device->firmware_version]),
            'compatibility_hash' => SceneWorkflow::hash(DB::table('skill_compatibility_evidence')->where('skill_version_id', $version->id)->where('model_code', $fingerprint['model_code'])->orderBy('id')->get()->all()),
            'license_mode' => $version->license_mode, 'pricing_mode' => $version->pricing_mode,
            'compatibility_status' => $compatibility['adaptation_status'], 'delivery_channel' => 'manual'];
    }

    public function assertEvidence(object $evidence, object $binding, array $candidate, bool $strict): array
    {
        if ($evidence->revoked_at || $evidence->expires_at <= now('UTC') || $evidence->verified_at > now('UTC') || $evidence->level !== 'L1'
            || (int) $evidence->product_id !== (int) $candidate['product_id'] || (int) $evidence->binding_id !== (int) $binding->id || $evidence->capability !== $candidate['capability'] || $binding->bind_status !== 'bound') {
            throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '具体设备与任务证据不匹配或已经失效。', 409);
        }
        if ($strict) {
            if (! $evidence->approved_at || (int) $evidence->approved_by === (int) $evidence->verified_by || ! $evidence->binding_snapshot
                || ! hash_equals(SceneWorkflow::hash(SceneWorkflow::unpack($evidence->binding_snapshot)), SceneWorkflow::hash($this->bindingSnapshot($binding)))) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '任务证据尚未独立复核，或设备归属/机型/固件等已改变。', 409);
            }
            $skill = SceneWorkflow::unpack($evidence->skill_snapshot);
            if ($skill && ! hash_equals(SceneWorkflow::hash($skill), SceneWorkflow::hash($this->skillSnapshot($skill, $binding)))) {
                throw new SceneWorkflowException('SCENE_QUALIFICATION_CHANGED', '技能版本、产物或适配声明已变化。', 409);
            }
        }

        return ['evidence_hash' => $evidence->artifact_hash, 'evidence_expires_at' => $evidence->expires_at,
            'qualification_hash' => SceneWorkflow::hash([$evidence->artifact_hash, $evidence->expires_at, $evidence->scope, $evidence->constraint_snapshot, $evidence->binding_snapshot, $evidence->skill_snapshot]),
            'constraints' => SceneWorkflow::unpack($evidence->constraint_snapshot), 'skill' => SceneWorkflow::unpack($evidence->skill_snapshot)];
    }

    public function assertSkillSale(array $skill, int $channelId): void
    {
        $channel = DB::table('channels')->where('id', $channelId)->value('code');
        if (! $channel || ! app(\Webkul\Shop\Services\SkillDelivery\SkillMarketService::class)->visibleRows($channel)->where('product_id', $skill['product_id'])->lockForUpdate()->first(['product_id'])) {
            throw new SceneWorkflowException('SCENE_SKILL_QUALIFICATION_INVALID', '技能在当前服务渠道已不可售，须重新评估。', 409);
        }
    }
}
