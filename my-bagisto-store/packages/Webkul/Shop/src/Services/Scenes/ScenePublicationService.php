<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\Scene;

class ScenePublicationService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneSchemaRegistry $schemas) {}

    public function draft(int $sceneId, array $input, int $actor): array
    {
        $this->flow->enabled('publication_v2');
        $data = Validator::make($input, [
            'name'               => 'required|string|max:255', 'description' => 'required|string|max:20000',
            'category'           => ['required', Rule::in(array_keys(Scene::CATEGORIES))],
            'robots'             => 'present|array|max:50', 'robots.*' => 'required|integer|distinct|exists:products,id',
            'tags'               => 'present|array|max:20', 'tags.*' => 'required|string|max:80',
            'deliverables'       => 'required|array|min:1|max:50', 'deliverables.*' => 'required|string|max:1000',
            'acceptance'         => 'required|array|min:1|max:50',
            'acceptance.*.key'   => 'required|regex:/^[a-z][a-z0-9_]{0,79}$/|distinct',
            'acceptance.*.label' => 'required|string|max:1000', 'acceptance.*.required' => 'required|boolean',
            'workflow_template'  => ['required', Rule::in(array_keys(config('scenes.workflows', [])))],
        ])->validate();
        $acceptance = array_map(fn ($a) => array_intersect_key($a, array_flip(['key', 'label', 'required'])), $data['acceptance']);
        unset($data['acceptance']);
        $workflow = config('scenes.workflows.'.$data['workflow_template']);

        return DB::transaction(function () use ($sceneId, $data, $acceptance, $workflow, $actor) {
            SceneWorkflow::requireRow('scenes', $sceneId);
            $data['banner'] = Scene::findOrFail($sceneId)->banner_url;
            $no = (int) DB::table('scene_revisions')->where('scene_id', $sceneId)->max('revision_no') + 1;
            $id = DB::table('scene_revisions')->insertGetId([
                'scene_id'            => $sceneId, 'revision_no' => $no, 'state' => 'draft', 'row_version' => 1,
                'schema_version'      => 1, 'content' => SceneWorkflow::json($data),
                'requirement_schema'  => SceneWorkflow::json($this->schemas->schema($data['category'])),
                'acceptance_template' => SceneWorkflow::json($acceptance),
                'workflow'            => SceneWorkflow::json($workflow),
                'content_hash'        => SceneWorkflow::hash([$data, $acceptance, $workflow]), 'created_by' => $actor,
                'created_at'          => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->flow->record('admin', $actor, 'scene_revision', $id, 1, 'scene.revision.drafted');

            return ['id' => $id, 'revision_no' => $no, 'row_version' => 1, 'state' => 'draft'];
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
            $from = ['review' => 'draft', 'approve' => 'in_review', 'publish' => 'approved', 'withdraw' => 'published'];
            $to = ['review' => 'in_review', 'approve' => 'approved', 'publish' => 'published', 'withdraw' => 'withdrawn'];
            if (! isset($from[$action]) || $revision->state !== $from[$action]) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '当前版本状态不支持此操作。', 409);
            }
            $patch = ['state' => $to[$action], 'row_version' => $expected + 1, 'updated_at' => now('UTC')];
            if ($action === 'approve') {
                if ((int) $revision->created_by === $actor) {
                    throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '编制人不能审批自己的版本。', 403);
                }
                $patch += ['approved_by' => $actor, 'approved_at' => now('UTC')];
            }
            if ($action === 'publish') {
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
