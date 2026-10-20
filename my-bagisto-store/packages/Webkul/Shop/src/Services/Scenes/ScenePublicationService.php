<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\Scene;

class ScenePublicationService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneSchemaRegistry $schemas) {}

    public function draft(int $sceneId, array $input, int $actor, ?int $editId = null, ?int $expected = null): array
    {
        $this->flow->enabled('publication_v2');
        $data = Validator::make($input, [
            'name' => 'required|string|max:255', 'description' => 'required|string|max:20000',
            'category' => ['required', Rule::in(array_keys(Scene::CATEGORIES))],
            'robots' => 'present|array|max:50', 'robots.*' => 'required|integer|distinct|exists:products,id',
            'tags' => 'present|array|max:20', 'tags.*' => 'required|string|max:80',
            'deliverables' => 'required|array|min:1|max:50', 'deliverables.*' => 'required|string|max:1000',
            'acceptance' => 'required|array|min:1|max:50',
            'acceptance.*' => 'array:key,label,required',
            'acceptance.*.key' => 'required|regex:/^[a-z][a-z0-9_]{0,79}$/|distinct',
            'acceptance.*.label' => 'required|string|max:1000', 'acceptance.*.required' => 'required|boolean',
            'workflow_template' => ['required', Rule::in(array_keys(config('scenes.workflows', [])))],
            'evidence_ids' => 'sometimes|array|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1',
            'offer_mode' => 'sometimes|in:rental_package,solution_project,consultation,display_only',
        ])->validate();
        $acceptance = array_map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'required' => (bool) $a['required']], $data['acceptance']);
        unset($data['acceptance']);
        $workflow = config('scenes.workflows.'.$data['workflow_template']);

        return DB::transaction(function () use ($sceneId, $data, $acceptance, $workflow, $actor, $editId, $expected) {
            SceneWorkflow::requireRow('scenes', $sceneId);
            $editing = $editId ? SceneWorkflow::requireRow('scene_revisions', $editId) : null;
            if ($editing) {
                if ((int) $editing->scene_id !== $sceneId || $editing->state !== 'draft') {
                    throw new SceneWorkflowException('SCENE_REVISION_IMMUTABLE', '只能编辑本场景草稿；已送审或批准须新建版本。', 409);
                }
                SceneWorkflow::version($editing, (int) $expected);
            }
            $data['content_evidence'] = app(SceneContentEvidenceService::class)->freeze($sceneId, $data['evidence_ids'] ?? []);
            unset($data['evidence_ids']);
            $data['offer_mode'] ??= 'consultation';
            $data['banner'] = Scene::findOrFail($sceneId)->banner_url;
            $no = $editing ? (int) $editing->revision_no : (int) DB::table('scene_revisions')->where('scene_id', $sceneId)->max('revision_no') + 1;
            $version = $editing ? (int) $editing->row_version + 1 : 1;
            $schema = $this->schemas->schema($data['category']);
            $values = [
                'scene_id' => $sceneId, 'revision_no' => $no, 'state' => 'draft', 'row_version' => $version,
                'schema_version' => $schema['version'], 'content' => SceneWorkflow::json($data),
                'requirement_schema' => SceneWorkflow::json($schema),
                'acceptance_template' => SceneWorkflow::json($acceptance),
                'workflow' => SceneWorkflow::json($workflow),
                'content_hash' => SceneWorkflow::hash([$data, $acceptance, $workflow, $schema]),
                'last_edited_by' => $actor, 'updated_at' => now('UTC'),
            ];
            if ($editing) {
                $id = $editId;
                DB::table('scene_revisions')->where('id', $id)->update($values);
            } else {
                $id = DB::table('scene_revisions')->insertGetId($values + ['created_by' => $actor, 'created_at' => now('UTC')]);
            }
            $this->flow->record('admin', $actor, 'scene_revision', $id, $version, 'scene.revision.drafted');

            return ['id' => $id, 'revision_no' => $no, 'row_version' => $version, 'state' => 'draft'];
        }, 3);
    }

    public function transition(int $sceneId, int $revisionId, int $expected, string $action, int $actor): array
    {
        $this->flow->enabled('publication_v2');

        return DB::transaction(function () use ($sceneId, $revisionId, $expected, $action, $actor) {
            $scene = SceneWorkflow::requireRow('scenes', $sceneId);
            $revision = SceneWorkflow::requireRow('scene_revisions', $revisionId);
            if ((int) $revision->scene_id !== $sceneId) {
                throw new SceneWorkflowException('SCENE_REVISION_MISMATCH', '版本不属于该场景。');
            }
            SceneWorkflow::version($revision, $expected);
            if (in_array($action, ['review', 'approve', 'publish'], true) && ! hash_equals($revision->content_hash, SceneWorkflow::hash([
                SceneWorkflow::unpack($revision->content), SceneWorkflow::unpack($revision->acceptance_template), SceneWorkflow::unpack($revision->workflow), SceneWorkflow::unpack($revision->requirement_schema),
            ]))) {
                throw new SceneWorkflowException('SCENE_REVISION_INTEGRITY', '场景内容指纹不符，请重新编制审核。', 409);
            }
            $from = ['review' => 'draft', 'approve' => 'in_review', 'publish' => 'approved', 'withdraw' => 'published'];
            $to = ['review' => 'in_review', 'approve' => 'approved', 'publish' => 'published', 'withdraw' => 'withdrawn'];
            if (! isset($from[$action]) || $revision->state !== $from[$action]) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '当前版本状态不支持此操作。', 409);
            }
            $patch = ['state' => $to[$action], 'row_version' => $expected + 1, 'updated_at' => now('UTC')];
            if ($action === 'approve') {
                if ((int) $revision->created_by === $actor || (int) $revision->last_edited_by === $actor) {
                    throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '编制人不能审批自己的版本。', 403);
                }
                $patch += ['approved_by' => $actor, 'approved_at' => now('UTC')];
            }
            if ($action === 'publish') {
                $content = SceneWorkflow::unpack($revision->content);
                app(SceneContentEvidenceService::class)->freeze($sceneId, array_column($content['content_evidence'] ?? [], 'id'));
                if (! $scene->status) {
                    throw new SceneWorkflowException('SCENE_UNAVAILABLE', '场景已停用，不能发布。');
                }
                $latest = DB::table('scene_revisions')->where('scene_id', $sceneId)->max('revision_no');
                if ((int) $latest !== (int) $revision->revision_no) {
                    throw new SceneWorkflowException('SCENE_NEWER_REVISION', '已有新版本，请审核最新版本。', 409);
                }
                DB::table('scene_revisions')->where('scene_id', $sceneId)->where('state', 'published')
                    ->update(['state' => 'withdrawn', 'updated_at' => now('UTC')]);
                DB::table('scenes')->where('id', $sceneId)->update(['active_revision_id' => $revisionId, 'public_seq' => $scene->public_seq + 1]);
                $patch['published_at'] = now('UTC');
            }
            if ($action === 'withdraw') {
                if ((int) $scene->active_revision_id !== $revisionId) {
                    throw new SceneWorkflowException('SCENE_VERSION_CONFLICT', '当前发布指针已改变。', 409);
                }
                DB::table('scenes')->where('id', $sceneId)->update(['active_revision_id' => null, 'public_seq' => $scene->public_seq + 1]);
            }
            DB::table('scene_revisions')->where('id', $revisionId)->update($patch);
            $this->flow->record('admin', $actor, 'scene', $sceneId, (int) $scene->public_seq + 1, 'scene.revision.'.$action, ['revision_id' => $revisionId]);

            return ['id' => $revisionId, 'row_version' => $expected + 1, 'state' => $patch['state']];
        }, 3);
    }

    public function active(int $sceneId, ?int $expectedRevision = null, bool $lock = false): object
    {
        $scene = SceneWorkflow::requireRow('scenes', $sceneId, $lock);
        $revision = $scene->active_revision_id ? DB::table('scene_revisions')->where('id', $scene->active_revision_id)->first() : null;
        if (! $scene->status || ! $revision || $revision->state !== 'published' || (int) $revision->scene_id !== $sceneId) {
            throw new SceneWorkflowException('SCENE_UNAVAILABLE', '场景尚未发布或已撤下。', 404);
        }
        if ($expectedRevision !== null && (int) $revision->id !== $expectedRevision) {
            throw new SceneWorkflowException('SCENE_VERSION_CONFLICT', '场景已更新，请重新查看服务范围。', 409);
        }

        return $revision;
    }

    public function compare(int $sceneId, int $left, int $right): array
    {
        $rows = DB::table('scene_revisions')->where('scene_id', $sceneId)->whereIn('id', [$left, $right])->get()->keyBy('id');
        if (! isset($rows[$left], $rows[$right])) {
            throw new SceneWorkflowException('SCENE_REVISION_MISMATCH', '对比版本须属于同一场景。', 404);
        }
        $changes = [];
        foreach (['content', 'requirement_schema', 'acceptance_template', 'workflow'] as $key) {
            $before = SceneWorkflow::unpack($rows[$left]->$key);
            $after = SceneWorkflow::unpack($rows[$right]->$key);
            if (SceneWorkflow::hash($before) !== SceneWorkflow::hash($after)) {
                $changes[$key] = ['before' => $before, 'after' => $after];
            }
        }

        return ['left' => $left, 'right' => $right, 'changes' => $changes];
    }

    public function availability(int $id, bool $enabled, int $actor): array
    {
        $this->flow->enabled('publication_v2');

        return DB::transaction(function () use ($id, $enabled, $actor) {
            $scene = SceneWorkflow::requireRow('scenes', $id);
            if ((bool) $scene->status === $enabled) {
                return ['id' => $id, 'status' => $enabled];
            }
            // 旧 DataGrid 的停用同样撤销指针；重新启用只恢复可编辑资格，必须重新批准发布。
            if ($scene->active_revision_id) {
                $r = SceneWorkflow::requireRow('scene_revisions', (int) $scene->active_revision_id);
                DB::table('scene_revisions')->where('id', $r->id)->update(['state' => 'withdrawn', 'row_version' => $r->row_version + 1, 'updated_at' => now('UTC')]);
            }
            DB::table('scenes')->where('id', $id)->update(['status' => $enabled, 'active_revision_id' => null, 'public_seq' => $scene->public_seq + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene', $id, $scene->public_seq + 1, 'scene.availability.changed');

            return ['id' => $id, 'status' => $enabled];
        }, 3);
    }
}
