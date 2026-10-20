<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SceneRequestService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneSchemaRegistry $schemas, protected ScenePublicationService $publication) {}

    public function create(ScenePrincipal $owner, string $key, array $input): array
    {
        $this->flow->enabled('requests_enabled');
        $data = $this->validate($input);
        $guestCapability = $owner->customerId ? null : $owner->guestCapability();

        return $this->flow->command($owner->scope(), 'scene.request.create', $key, $data, function () use ($owner, $data, $guestCapability) {
            $revision = null;
            if (! empty($data['scene_id'])) {
                if (config('scenes.publication_v2')) {
                    if (empty($data['scene_revision_id'])) {
                        throw new SceneWorkflowException('SCENE_REVISION_REQUIRED', '请先刷新场景再提交。', 409);
                    }
                    $revision = $this->publication->active((int) $data['scene_id'], (int) $data['scene_revision_id'], true);
                } else {
                    $scene = SceneWorkflow::requireRow('scenes', (int) $data['scene_id']);
                    if (! $scene->status) {
                        throw new SceneWorkflowException('SCENE_UNAVAILABLE', '场景已停用。', 404);
                    }
                    $data['category'] = $scene->category;
                }
            }
            $schema = $revision ? SceneWorkflow::unpack($revision->requirement_schema) : $this->schemas->schema($data['category'] ?? 'enterprise');
            $facts = $this->schemas->validate($data['facts'], $schema);
            $id = DB::table('scene_requests')->insertGetId([
                'request_no'       => 'SR-'.strtoupper(str_replace('-', '', (string) Str::uuid())),
                'customer_id'      => $owner->customerId, 'guest_access_hash' => $owner->customerId ? null : $owner->guestHash,
                'guest_expires_at' => $guestCapability?->expires_at,
                'scene_id'         => $data['scene_id'] ?? null, 'scene_revision_id' => $revision?->id,
                'revision_no'      => 1, 'row_version' => 1, 'state' => 'submitted',
                'source'           => $data['source'], 'consent_version' => $data['consent_version'],
                'created_at'       => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->writeRevision($id, 1, $data['original_text'], $facts, $schema);
            DB::table('scene_request_contacts')->insert([
                'request_id' => $id, 'encrypted_contact' => Crypt::encryptString(SceneWorkflow::json($data['contact'])),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->activity($id, 'submitted', 'customer', $owner->customerId, null, 'customer');
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_request', $id, 1, 'scene.request.submitted');

            return $this->summary(SceneWorkflow::requireRow('scene_requests', $id, false));
        }, fn ($result) => $owner->request((int) $result['id']));
    }

    public function supplement(ScenePrincipal $owner, int $id, int $expected, string $key, array $input): array
    {
        $this->flow->enabled('requests_enabled');
        $data = Validator::make($input, ['original_text' => 'required|string|max:20000', 'facts' => 'present|array|max:30'])->validate();

        return $this->flow->command($owner->scope(), 'scene.request.supplement:'.$id, $key, [$expected, $data], function () use ($owner, $id, $expected, $data) {
            $row = $owner->request($id, true);
            SceneWorkflow::version($row, $expected);
            if (in_array($row->state, ['converted', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '已进入履约或关闭的需求请通过变更或售后入口处理。', 409);
            }
            $previous = DB::table('scene_request_revisions')->where('request_id', $id)->where('revision_no', $row->revision_no)->first();
            $schema = SceneWorkflow::unpack($previous->schema_snapshot);
            $facts = $this->schemas->validate($data['facts'], $schema);
            $next = (int) $row->revision_no + 1;
            $this->writeRevision($id, $next, $data['original_text'], $facts, $schema);
            DB::table('scene_requests')->where('id', $id)->update(['revision_no' => $next, 'row_version' => $expected + 1, 'state' => 'submitted', 'updated_at' => now('UTC')]);
            app(SceneQuoteLifecycleService::class)->invalidate($id, ['draft', 'in_review', 'approved', 'sent', 'accepted', 'returned'],
                'request_revised', $owner->customerId ? 'customer' : 'guest', $owner->customerId);
            $this->activity($id, 'supplemented', 'customer', $owner->customerId, null, 'customer');
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_request', $id, $expected + 1, 'scene.request.supplemented');

            return $this->summary(SceneWorkflow::requireRow('scene_requests', $id, false));
        }, fn () => $owner->request($id));
    }

    public function claim(ScenePrincipal $owner, int $id): array
    {
        $this->flow->enabled('requests_enabled');
        if (! $owner->customerId || ! $owner->guestHash) {
            throw new SceneWorkflowException('SCENE_CLAIM_PROOF_REQUIRED', '登录后仍需提供原需求访问凭证。', 403);
        }
        $owner->guestCapability();

        return DB::transaction(function () use ($owner, $id) {
            $row = SceneWorkflow::requireRow('scene_requests', $id);
            if ((int) $row->customer_id === $owner->customerId) {
                return $this->summary($row);
            }
            if ($row->customer_id || ! $row->guest_access_hash || ! hash_equals($row->guest_access_hash, $owner->guestHash) || $row->guest_expires_at <= now('UTC')) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '原访问凭证无效或已过期。', 404);
            }
            DB::table('scene_requests')->where('id', $id)->update([
                'customer_id' => $owner->customerId, 'guest_access_hash' => null, 'guest_expires_at' => null,
                'row_version' => $row->row_version + 1, 'updated_at' => now('UTC'),
            ]);
            $this->flow->record('customer', $owner->customerId, 'scene_request', $id, $row->row_version + 1, 'scene.request.claimed');

            return $this->summary(SceneWorkflow::requireRow('scene_requests', $id, false));
        }, 3);
    }

    public function operate(int $id, int $expected, string $action, array $input, int $actor): array
    {
        $this->flow->enabled('requests_enabled');
        $data = Validator::make($input, [
            'assignee_id' => 'nullable|integer|exists:admins,id', 'note' => 'nullable|string|max:5000',
            'occurred_at' => 'nullable|date', 'channel' => 'nullable|in:phone,email,in_person,wechat',
        ])->validate();

        return DB::transaction(function () use ($id, $expected, $action, $data, $actor) {
            $row = SceneWorkflow::requireRow('scene_requests', $id);
            SceneWorkflow::version($row, $expected);
            if (in_array($row->state, ['converted', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '该需求已进入履约或已关闭。', 409);
            }
            $patch = ['row_version' => $expected + 1, 'updated_at' => now('UTC')];
            if ($action === 'assign' && ! empty($data['assignee_id'])) {
                $patch += ['assignee_id' => $data['assignee_id'], 'state' => 'triaged'];
            } elseif ($action === 'contact' && ! empty($data['note']) && ! empty($data['occurred_at']) && ! empty($data['channel'])) {
                if (strtotime($data['occurred_at']) > now('UTC')->timestamp) {
                    throw new SceneWorkflowException('SCENE_CONTACT_TIME_INVALID', '联系记录不能使用未来时间。');
                }
            } elseif ($action === 'close' && ! empty($data['note'])) {
                if (DB::table('scene_quotes')->where('request_id', $id)->where('state', 'accepted')->exists()) {
                    throw new SceneWorkflowException('SCENE_ACCEPTED_QUOTE_EXISTS', '已有接受的报价，需先处理商务撤销。', 409);
                }
                $patch['state'] = 'closed';
                app(SceneQuoteLifecycleService::class)->invalidate($id, ['draft', 'in_review', 'approved', 'sent', 'returned'], 'request_closed', 'admin', $actor);
            } else {
                throw new SceneWorkflowException('SCENE_OPERATION_INVALID', '操作或必填资料无效。');
            }
            DB::table('scene_requests')->where('id', $id)->update($patch);
            $this->activity($id, $action, 'admin', $actor, $data['note'] ?? null, 'internal', $data['occurred_at'] ?? null);
            $this->flow->record('admin', $actor, 'scene_request', $id, $expected + 1, 'scene.request.'.$action);

            return $this->summary(SceneWorkflow::requireRow('scene_requests', $id, false));
        }, 3);
    }

    public function detail(ScenePrincipal $owner, int $id): array
    {
        $row = $owner->request($id);
        $revision = DB::table('scene_request_revisions')->where('request_id', $id)->where('revision_no', $row->revision_no)->first();
        $quotes = DB::table('scene_quotes')->where('request_id', $id)->where('customer_visible', true)->orderByDesc('version')->get();
        $projects = DB::table('scene_projects')->where('request_id', $id)->get(['id', 'project_no', 'state', 'commercial_state', 'payment_state']);

        return $this->summary($row) + [
            'original_text' => $revision->original_text, 'facts' => SceneWorkflow::unpack($revision->facts),
            'schema'        => SceneWorkflow::unpack($revision->schema_snapshot),
            'quotes'        => $quotes->map(fn ($q) => app(SceneProposalService::class)->customerDto($q))->all(),
            'projects'      => $projects->all(),
            'activities'    => DB::table('scene_activities')->where('request_id', $id)->where('visibility', 'customer')->orderBy('id')->get(['id', 'kind', 'occurred_at'])->all(),
            'attachments'   => DB::table('scene_attachments')->where('request_id', $id)->where('state', 'clean')->get(['id', 'mime', 'size', 'created_at'])->all(),
        ];
    }

    public function summary(object $r): array
    {
        return ['id'      => (int) $r->id, 'request_no' => $r->request_no, 'state' => $r->state,
            'revision_no' => (int) $r->revision_no, 'row_version' => (int) $r->row_version,
            'scene_id'    => $r->scene_id, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at];
    }

    private function validate(array $input): array
    {
        return Validator::make($input, [
            'scene_id'        => 'nullable|integer|min:1|max:4294967295', 'scene_revision_id' => 'nullable|integer|min:1',
            'category'        => 'nullable|string|max:40', 'original_text' => 'required|string|max:20000',
            'facts'           => 'present|array|max:30', 'source' => 'required|in:home,scene,robot,ai,legacy_custom',
            'consent_version' => 'required|in:scene-contact-v1',
            'contact'         => 'required|array:name,phone,company,wechat',
            'contact.name'    => 'required|string|max:100', 'contact.phone' => 'required|string|regex:/^\+?[0-9 -]{7,25}$/',
            'contact.company' => 'nullable|string|max:255', 'contact.wechat' => 'nullable|string|max:100',
        ])->validate();
    }

    private function writeRevision(int $id, int $no, string $text, array $facts, array $schema): void
    {
        DB::table('scene_request_revisions')->insert(['request_id' => $id, 'revision_no' => $no,
            'original_text'                                        => $text, 'facts' => SceneWorkflow::json($facts), 'schema_snapshot' => SceneWorkflow::json($schema),
            'input_hash'                                           => SceneWorkflow::hash([$text, $facts, $schema]), 'created_at' => now('UTC')]);
    }

    private function activity(int $id, string $kind, string $actorType, ?int $actorId, ?string $note, string $visibility, mixed $occurred = null): void
    {
        DB::table('scene_activities')->insert(['request_id' => $id, 'kind' => $kind, 'actor_type' => $actorType,
            'actor_id'                                      => $actorId, 'note' => $note, 'visibility' => $visibility, 'occurred_at' => $occurred ?? now('UTC'), 'created_at' => now('UTC')]);
    }
}
