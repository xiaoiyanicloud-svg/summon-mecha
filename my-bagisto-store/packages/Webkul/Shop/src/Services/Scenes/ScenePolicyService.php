<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 草案不冒充批准版本；唯一生效指针撤销后不回退复活更旧政策。 */
class ScenePolicyService
{
    public const KINDS = ['commercial', 'retention', 'service_calendar'];

    public function __construct(protected SceneWorkflow $flow, protected SceneBusinessCalendar $calendar) {}

    public function create(string $kind, array $input, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        $data = $this->validate($kind, $input, false);

        return DB::transaction(function () use ($kind, $data, $actor) {
            DB::table('scene_policy_kinds')->insertOrIgnore(['kind' => $kind, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('scene_policy_kinds')->where('kind', $kind)->lockForUpdate()->first();
            $latest = DB::table('scene_policy_versions')->where('kind', $kind)->orderByDesc('version')->lockForUpdate()->first();
            $version = $latest ? (int) $latest->version + 1 : 1;
            $id = DB::table('scene_policy_versions')->insertGetId(['kind' => $kind, 'version' => $version, 'content' => SceneWorkflow::json($data),
                'content_hash' => SceneWorkflow::hash($data), 'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_policy', $id, 1, 'scene.policy.drafted');

            return ['id' => $id, 'version' => $version, 'row_version' => 1, 'state' => 'draft'];
        }, 3);
    }

    public function decide(int $id, int $expected, string $action, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        Validator::make(['action' => $action, 'reason' => $reason], ['action' => 'required|in:approve,reject,revoke', 'reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($id, $expected, $action, $reason, $actor) {
            $ref = SceneWorkflow::requireRow('scene_policy_versions', $id, false);
            $parent = DB::table('scene_policy_kinds')->where('kind', $ref->kind)->lockForUpdate()->first();
            $row = SceneWorkflow::requireRow('scene_policy_versions', $id);
            SceneWorkflow::version($row, $expected);
            if (($action === 'revoke' && $row->state !== 'approved') || ($action !== 'revoke' && $row->state !== 'draft')) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '政策状态不支持本次处理。', 409);
            }
            if ($action !== 'revoke' && (int) $row->created_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '政策编制人不能自行复核。', 403);
            }
            $patch = ['state' => ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'][$action], 'row_version' => $expected + 1, 'decision_note' => $reason, 'updated_at' => now('UTC')];
            if ($action === 'approve') {
                $this->validate($row->kind, SceneWorkflow::unpack($row->content), true);
                $latest = DB::table('scene_policy_versions')->where('kind', $row->kind)->orderByDesc('version')->lockForUpdate()->value('id');
                if ((int) $latest !== $id) {
                    throw new SceneWorkflowException('SCENE_POLICY_STALE', '已有更新草案，请审核最新政策版本。', 409);
                }
                $patch += ['approved_by' => $actor, 'approved_at' => now('UTC')];
                DB::table('scene_policy_kinds')->where('kind', $row->kind)->update(['active_version_id' => $id, 'updated_at' => now('UTC')]);
            } elseif ($action === 'revoke' && (int) $parent->active_version_id === $id) {
                DB::table('scene_policy_kinds')->where('kind', $row->kind)->update(['active_version_id' => null, 'updated_at' => now('UTC')]);
            }
            DB::table('scene_policy_versions')->where('id', $id)->update($patch);
            $this->flow->record('admin', $actor, 'scene_policy', $id, $expected + 1, 'scene.policy.'.$action);

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => $patch['state']];
        }, 3);
    }

    public function effective(string $kind): ?array
    {
        $parent = DB::table('scene_policy_kinds')->where('kind', $kind)->first();
        $row = $parent?->active_version_id ? DB::table('scene_policy_versions')->find($parent->active_version_id) : null;
        if (! $row || $row->kind !== $kind || $row->state !== 'approved' || ! $row->approved_at) {
            return null;
        }
        $content = SceneWorkflow::unpack($row->content);
        if (! hash_equals($row->content_hash, SceneWorkflow::hash($content))) {
            throw new SceneWorkflowException('SCENE_POLICY_INTEGRITY', '政策内容指纹不符，请由运营核实。', 409);
        }

        return ['id' => (int) $row->id, 'version' => $kind.'-v'.$row->version, 'content' => $content, 'approved_at' => $row->approved_at];
    }

    public function validate(string $kind, array $input, bool $approve): array
    {
        $rules = match ($kind) {
            'commercial' => ['supplier_name' => ($approve ? 'required' : 'nullable').'|string|max:255', 'supplier_contact' => ($approve ? 'required' : 'nullable').'|string|max:255',
                'text' => 'required|string|min:20|max:30000', 'required_price_codes' => 'required|array|min:1|max:50', 'required_price_codes.*' => 'required|string|distinct|max:80', 'cancellation' => 'nullable|array'],
            'retention' => ['days' => 'required|integer|min:1|max:3650', 'purpose' => 'required|string|min:3|max:2000', 'deletion_notice' => 'required|string|min:3|max:2000'],
            'service_calendar' => null,
            default => throw new SceneWorkflowException('SCENE_POLICY_KIND_INVALID', '政策类型无效。'),
        };
        $data = $kind === 'service_calendar' ? $this->calendar->validate($input) : Validator::make($input, $rules)->validate();
        if ($kind === 'commercial' && isset($data['cancellation'])) {
            $data['cancellation'] = app(SceneCancellationPolicy::class)->normalize(array_replace($data['cancellation'], ['version' => 'commercial-draft']));
        }
        if ($approve && $kind === 'service_calendar') {
            $ids = [$data['owner_id'] ?? 0, $data['escalation_owner_id'] ?? 0];
            if ($ids[0] === $ids[1] || DB::table('admins')->whereIn('id', $ids)->where('status', 1)->count() !== 2) {
                throw new SceneWorkflowException('SCENE_CALENDAR_OWNERS_REQUIRED', '首次受理及升级须指定两名有效负责人。', 409);
            }
        }

        return $data;
    }
}
