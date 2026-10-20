<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 方案版本钉住评估及 BOM；报价只选用已独立批准版本，不采用客户端自带约束。 */
class SceneSolutionService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneAssessmentService $assessments) {}

    public function draft(int $requestId, int $expected, array $input, int $actor, int $channel): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'assessment_id' => 'required|integer|min:1', 'title' => 'required|string|max:255', 'scope' => 'required|string|min:3|max:10000', 'exclusions' => 'required|string|min:3|max:10000',
            'components' => 'required|array|min:1|max:50', 'components.*' => 'array:key,label,kind,product_id,skill_version_id,price_code,quantity,min_quantity,max_quantity,required,selected,dependencies,exclusive_group,group_required,equivalent_to,equivalence_note',
            'components.*.key' => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/D|distinct', 'components.*.label' => 'required|string|max:255',
            'components.*.kind' => 'required|in:robot,accessory,operator,service,transport,skill', 'components.*.product_id' => 'nullable|integer|min:1',
            'components.*.skill_version_id' => 'nullable|integer|min:1', 'components.*.price_code' => 'required|regex:/^[a-z][a-z0-9_.-]{0,79}$/D',
            'components.*.quantity' => 'required|integer|min:0|max:1000', 'components.*.min_quantity' => 'required|integer|min:1|max:1000',
            'components.*.max_quantity' => 'required|integer|min:1|max:1000', 'components.*.required' => 'required|boolean', 'components.*.selected' => 'required|boolean',
            'components.*.dependencies' => 'present|array|max:50', 'components.*.dependencies.*' => 'required|string|max:80',
            'components.*.exclusive_group' => 'nullable|string|max:80', 'components.*.group_required' => 'sometimes|boolean',
            'components.*.equivalent_to' => 'nullable|string|max:80', 'components.*.equivalence_note' => 'nullable|string|max:5000',
            'acceptance' => 'required|array|min:1|max:50', 'acceptance.*' => 'array:key,label,required',
            'acceptance.*.key' => 'required|regex:/^[a-z][a-z0-9_]{0,79}$/D|distinct', 'acceptance.*.label' => 'required|string|max:1000', 'acceptance.*.required' => 'required|boolean',
        ])->validate();
        foreach ($data['components'] as &$component) {
            foreach (['required', 'selected', 'group_required'] as $key) {
                if (array_key_exists($key, $component)) {
                    $component[$key] = (bool) $component[$key];
                }
            }
            foreach (['quantity', 'min_quantity', 'max_quantity', 'product_id', 'skill_version_id'] as $key) {
                if (isset($component[$key])) {
                    $component[$key] = (int) $component[$key];
                }
            }
        }
        unset($component);
        foreach ($data['acceptance'] as &$item) {
            $item['required'] = (bool) $item['required'];
        }
        unset($item);

        return DB::transaction(function () use ($requestId, $expected, $data, $actor, $channel) {
            $request = SceneWorkflow::requireRow('scene_requests', $requestId);
            SceneWorkflow::version($request, $expected);
            if (in_array($request->state, ['closed', 'cancelled', 'converted', 'accepted'], true) || DB::table('scene_projects')->where('request_id', $requestId)->exists()) {
                throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '已有接受承诺或项目，须先处理商务变更。', 409);
            }
            $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $data['assessment_id']);
            $this->assessments->assertCurrent($assessment, $request, $channel);
            $components = $this->validateBom($data['components'], $assessment);
            $version = (int) DB::table('scene_proposals')->where('request_id', $requestId)->orderByDesc('version')->lockForUpdate()->value('version') + 1;
            $content = [$data['title'], $data['scope'], $data['exclusions'], $components, $data['acceptance'], $request->revision_no, $assessment->id];
            $id = DB::table('scene_proposals')->insertGetId(['request_id' => $requestId, 'request_revision_no' => $request->revision_no, 'assessment_id' => $assessment->id,
                'version' => $version, 'title' => $data['title'], 'scope' => $data['scope'], 'exclusions' => $data['exclusions'], 'components' => SceneWorkflow::json($components),
                'acceptance' => SceneWorkflow::json($data['acceptance']), 'content_hash' => SceneWorkflow::hash($content), 'created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_proposal', $id, 1, 'scene.proposal.drafted');

            return ['id' => $id, 'version' => $version, 'row_version' => 1, 'state' => 'draft'];
        }, 3);
    }

    public function decide(int $id, int $expected, string $action, string $reason, int $actor, int $channel): array
    {
        $this->flow->enabled('quotes_enabled');
        Validator::make(['action' => $action, 'reason' => $reason], ['action' => 'required|in:approve,return', 'reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($id, $expected, $action, $reason, $actor, $channel) {
            $ref = SceneWorkflow::requireRow('scene_proposals', $id, false);
            $request = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            $row = SceneWorkflow::requireRow('scene_proposals', $id);
            SceneWorkflow::version($row, $expected);
            if ($row->state !== 'draft') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '方案版本已审阅，不可覆盖。', 409);
            }
            if ((int) $row->created_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '方案编制人不能自行审阅。', 403);
            }
            $latest = DB::table('scene_proposals')->where('request_id', $request->id)->orderByDesc('version')->lockForUpdate()->value('id');
            if ($action === 'approve') {
                if (in_array($request->state, ['closed', 'cancelled', 'converted', 'accepted'], true) || DB::table('scene_quotes')->where('request_id', $request->id)->where('state', 'accepted')->exists()
                    || DB::table('scene_projects')->where('request_id', $request->id)->exists()) {
                    throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '需求已关闭或形成承诺，不能继续批准原草案。', 409);
                }
                if ((int) $latest !== $id || (int) $row->request_revision_no !== (int) $request->revision_no) {
                    throw new SceneWorkflowException('SCENE_PROPOSAL_STALE', '方案不是当前需求对应的最新版本。', 409);
                }
                $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $row->assessment_id);
                $this->assessments->assertCurrent($assessment, $request, $channel);
                $this->validateBom(SceneWorkflow::unpack($row->components), $assessment);
            }
            $state = $action === 'approve' ? 'approved' : 'returned';
            DB::table('scene_proposals')->where('id', $id)->update(['state' => $state, 'row_version' => $expected + 1, 'approved_by' => $action === 'approve' ? $actor : null,
                'approved_at' => $action === 'approve' ? now('UTC') : null, 'review_note' => $reason, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_proposal', $id, $expected + 1, 'scene.proposal.'.$action);

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => $state];
        }, 3);
    }

    public function forQuote(int $id, object $request, object $assessment, array $items): array
    {
        $row = SceneWorkflow::requireRow('scene_proposals', $id);
        if ($row->state !== 'approved' || ! $row->approved_at || (int) $row->request_id !== (int) $request->id || (int) $row->request_revision_no !== (int) $request->revision_no || (int) $row->assessment_id !== (int) $assessment->id) {
            throw new SceneWorkflowException('SCENE_PROPOSAL_STALE', '方案尚未独立批准或不匹配当前需求/评估。', 409);
        }
        $components = $this->validateBom(SceneWorkflow::unpack($row->components), $assessment);
        $expected = [];
        foreach ($components as $component) {
            if ($component['selected']) {
                $expected[$component['price_code']] = ['quantity' => (int) $component['quantity'], 'kind' => $component['kind'], 'product_id' => (int) ($component['product_id'] ?? 0)];
            }
        }
        $actual = [];
        foreach ($items as $item) {
            $price = SceneWorkflow::requireRow('scene_price_items', (int) $item['price_item_id']);
            if ($price->charge_type === 'deposit') {
                continue;
            }
            if (isset($actual[$price->code])) {
                throw new SceneWorkflowException('SCENE_PRICE_CODE_DUPLICATE', '同一费用编码不能重复。');
            }
            $actual[$price->code] = ['quantity' => (int) $item['quantity'], 'kind' => $price->kind, 'product_id' => (int) $price->product_id];
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new SceneWorkflowException('SCENE_QUOTE_BOM_MISMATCH', '费用项、实际数量或商品与已批准 BOM 不一致。', 409);
        }
        $hash = SceneWorkflow::hash([$row->title, $row->scope, $row->exclusions, $components, SceneWorkflow::unpack($row->acceptance), $row->request_revision_no, $row->assessment_id]);
        if (! hash_equals($row->content_hash, $hash)) {
            throw new SceneWorkflowException('SCENE_PROPOSAL_INTEGRITY', '方案内容指纹不符，不能报价。', 409);
        }

        return ['id' => (int) $row->id, 'version' => (int) $row->version, 'content_hash' => $row->content_hash, 'title' => $row->title, 'scope' => $row->scope,
            'exclusions' => $row->exclusions, 'components' => $components, 'acceptance' => SceneWorkflow::unpack($row->acceptance)];
    }

    public function validateBom(array $components, object $assessment): array
    {
        foreach ($components as &$component) {
            $component['unit'] = match ($component['kind']) {
                'robot' => 'device','operator' => 'person','accessory' => 'item','skill' => 'license',default => 'project',
            };
        }
        unset($component);
        if (count(array_column($components, 'key')) !== count(array_unique(array_column($components, 'key')))) {
            throw new SceneWorkflowException('SCENE_BOM_DEPENDENCY_INVALID', 'BOM 组件编号不能重复。');
        }
        $byKey = array_column($components, null, 'key');
        $selected = [];
        $groups = [];
        $codes = [];
        $candidates = SceneWorkflow::unpack($assessment->candidate_snapshot);
        $robotQuantities = [];
        foreach ($components as $component) {
            $key = $component['key'];
            if (count($component['dependencies']) !== count(array_unique($component['dependencies']))) {
                throw new SceneWorkflowException('SCENE_BOM_DEPENDENCY_INVALID', '同一组件内的依赖不能重复。');
            }
            if ($component['min_quantity'] > $component['max_quantity'] || ($component['required'] && ! $component['selected'])
                || ($component['selected'] && ($component['quantity'] < $component['min_quantity'] || $component['quantity'] > $component['max_quantity']))
                || (! $component['selected'] && (int) $component['quantity'] !== 0)) {
                throw new SceneWorkflowException('SCENE_BOM_QUANTITY_INVALID', '必选项/数量/选配状态与方案约束不一致。');
            }
            foreach ($component['dependencies'] as $dependency) {
                if (! isset($byKey[$dependency])) {
                    throw new SceneWorkflowException('SCENE_BOM_DEPENDENCY_INVALID', 'BOM 依赖引用未知组件。');
                }
                if ($component['selected'] && ! $byKey[$dependency]['selected']) {
                    throw new SceneWorkflowException('SCENE_BOM_DEPENDENCY_INVALID', '所选组件的必需依赖未选择。');
                }
            }
            if (! empty($component['exclusive_group'])) {
                $group = $component['exclusive_group'];
                $groups[$group]['required'] = ($groups[$group]['required'] ?? false) || ($component['group_required'] ?? false);
                $groups[$group]['count'] = ($groups[$group]['count'] ?? 0) + (int) (bool) $component['selected'];
            }
            if (! $component['selected']) {
                continue;
            }
            if (in_array($component['price_code'], $codes, true)) {
                throw new SceneWorkflowException('SCENE_PRICE_CODE_DUPLICATE', '已选 BOM 费用编码不能重复。');
            }
            $codes[] = $component['price_code'];
            $selected[] = $component;
            if (in_array($component['kind'], ['robot', 'accessory', 'skill'], true) && empty($component['product_id'])) {
                throw new SceneWorkflowException('SCENE_PRODUCT_REQUIRED', '实物与技能组件必须绑定真实商品。');
            }
            if ($component['kind'] === 'robot') {
                $matches = array_filter($candidates, fn ($c) => (int) $c['product_id'] === (int) $component['product_id']);
                $robotQuantities[$component['product_id']] = ($robotQuantities[$component['product_id']] ?? 0) + (int) $component['quantity'];
                if (count(array_unique(array_column($matches, 'binding_id'))) < $robotQuantities[$component['product_id']]) {
                    throw new SceneWorkflowException('SCENE_BOM_UNASSESSED', 'BOM 台数超过已核验的不同实物设备数，SKU 不是实物容量。');
                }
                if (! empty($component['equivalent_to'])) {
                    $source = $byKey[$component['equivalent_to']] ?? null;
                    $sourceCandidates = $source ? array_filter($candidates, fn ($c) => (int) $c['product_id'] === (int) ($source['product_id'] ?? 0)) : [];
                    $capabilities = array_column($sourceCandidates, 'capability');
                    if (! $source || $source['kind'] !== 'robot' || $source['selected'] || $source['key'] === $key || mb_strlen(trim($component['equivalence_note'] ?? '')) < 3
                        || ! array_intersect($capabilities, array_column($matches, 'capability'))) {
                        throw new SceneWorkflowException('SCENE_BOM_EQUIVALENCE_REQUIRED', '替代机型须有相同任务能力的双方有效评估及明确等效说明。');
                    }
                }
            }
            if ($component['kind'] === 'skill') {
                $matches = array_filter($candidates, fn ($c) => (int) ($c['skill']['version_id'] ?? 0) === (int) ($component['skill_version_id'] ?? 0) && (int) ($c['skill']['product_id'] ?? 0) === (int) $component['product_id']);
                if (! $matches) {
                    throw new SceneWorkflowException('SCENE_BOM_UNASSESSED', '技能版本/产物未包含在当前任务评估中。');
                }
                $skill = reset($matches)['skill'];
                $count = count(array_unique(array_column($matches, 'binding_id')));
                if (! in_array($skill['license_mode'] ?? '', ['per_device', 'per_order', 'per_account', 'unlimited'], true)
                    || ($skill['license_mode'] === 'per_device' ? $component['quantity'] > $count : $component['quantity'] !== 1)) {
                    throw new SceneWorkflowException('SCENE_BOM_SKILL_LICENSE_INVALID', '技能数量须与已冻结授权范围及不同设备数一致。');
                }
            }
        }
        if (! $selected) {
            throw new SceneWorkflowException('SCENE_BOM_EMPTY', '方案至少选择一个交付组件。');
        }
        foreach ($groups as $group) {
            if ($group['count'] > 1 || ($group['required'] && $group['count'] !== 1)) {
                throw new SceneWorkflowException('SCENE_BOM_EXCLUSIVE', '互斥替代组只能选择一个，必选组不能为空。');
            }
        }
        $visiting = [];
        $done = [];
        $visit = function ($key) use (&$visit, &$visiting, &$done, $byKey) {
            if (isset($visiting[$key])) {
                throw new SceneWorkflowException('SCENE_BOM_DEPENDENCY_INVALID', 'BOM 依赖不能有环。');
            }
            if (isset($done[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($byKey[$key]['dependencies'] as $next) {
                $visit($next);
            } unset($visiting[$key]);
            $done[$key] = true;
        };
        foreach (array_keys($byKey) as $key) {
            $visit($key);
        }

        return $components;
    }
}
