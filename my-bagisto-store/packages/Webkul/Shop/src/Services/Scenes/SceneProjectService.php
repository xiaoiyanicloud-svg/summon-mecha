<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SceneProjectService
{
    public function __construct(protected SceneWorkflow $flow) {}

    /** 必须由经过准入的转换事务调用；此方法不提供跳过订单确认的公开入口。 */
    public function initializeTasks(int $projectId, int $actor): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('任务生成须属于项目确认事务。');
        }
        $project = SceneWorkflow::requireRow('scene_projects', $projectId);
        $workflow = SceneWorkflow::unpack($project->snapshot)['workflow'] ?? null;
        $tasks = $workflow['tasks'] ?? [];
        if (! $tasks || empty($workflow['version'])) {
            throw new SceneWorkflowException('SCENE_WORKFLOW_MISSING', '项目未冻结履约任务模板。', 409);
        }
        $keys = array_column($tasks, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            throw new SceneWorkflowException('SCENE_WORKFLOW_INVALID', '履约模板任务编号重复。');
        }
        $visited = [];
        $visiting = [];
        $byKey = array_column($tasks, null, 'key');
        $visit = function (string $key) use (&$visit, &$visited, &$visiting, $byKey) {
            if (isset($visiting[$key]) || ! isset($byKey[$key])) {
                throw new SceneWorkflowException('SCENE_WORKFLOW_INVALID', '履约模板依赖有环或引用未知任务。');
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($byKey[$key]['dependencies'] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        foreach ($keys as $key) {
            $visit($key);
        }
        foreach ($tasks as $task) {
            if (! in_array($task['project_state_after'], ['delivering', 'servicing', 'awaiting_acceptance'], true) || ! $task['checklist']) {
                throw new SceneWorkflowException('SCENE_WORKFLOW_INVALID', '任务状态或检查项无效。');
            }
            DB::table('scene_project_tasks')->insertOrIgnore([
                'project_id' => $projectId, 'task_key' => $task['key'], 'name' => $task['name'], 'dependencies' => SceneWorkflow::json($task['dependencies']),
                'checklist'  => SceneWorkflow::json($task['checklist']), 'project_state_after' => $task['project_state_after'],
                'state'      => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
        $this->flow->record('admin', $actor, 'scene_project', $projectId, $project->row_version, 'scene.project.tasks.initialized');
    }

    public function completeTask(int $projectId, int $taskId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'checks'       => 'required|array|min:1|max:30', 'checks.*' => 'required|string|max:80',
            'evidence_ids' => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1',
            'occurred_at'  => 'required|date|before_or_equal:now',
        ])->validate();

        return DB::transaction(function () use ($projectId, $taskId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            if (! in_array($project->state, ['preparing', 'delivering', 'servicing'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目当前状态不支持完成交付任务。', 409);
            }
            $task = SceneWorkflow::requireRow('scene_project_tasks', $taskId);
            if ((int) $task->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '任务不存在。', 404);
            }
            SceneWorkflow::version($task, $expected);
            if ($task->state === 'done') {
                throw new SceneWorkflowException('SCENE_TASK_ALREADY_DONE', '任务已完成，不能覆盖原始记录。', 409);
            }
            $dependencies = SceneWorkflow::unpack($task->dependencies);
            if (DB::table('scene_project_tasks')->where('project_id', $projectId)->whereIn('task_key', $dependencies)->where('state', 'done')->count() !== count($dependencies)) {
                throw new SceneWorkflowException('SCENE_TASK_DEPENDENCY', '前置交付任务尚未完成。', 409);
            }
            if (array_diff(SceneWorkflow::unpack($task->checklist), $data['checks'])) {
                throw new SceneWorkflowException('SCENE_TASK_CHECKS_INCOMPLETE', '必需检查项尚未逐项确认。');
            }
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            $next = $task->project_state_after;
            if ($next === 'awaiting_acceptance' && DB::table('scene_project_tasks')->where('project_id', $projectId)->where('id', '!=', $taskId)->where('state', '!=', 'done')->exists()) {
                throw new SceneWorkflowException('SCENE_TASKS_INCOMPLETE', '全部履约任务完成后才能进入验收。', 409);
            }
            DB::table('scene_project_tasks')->where('id', $taskId)->update([
                'state'       => 'done', 'row_version' => $expected + 1, 'assignee_id' => $actor,
                'occurred_at' => $data['occurred_at'], 'recorded_at' => now('UTC'),
                'evidence'    => SceneWorkflow::json(['source' => 'manual', 'attachments' => $evidence, 'checks' => $data['checks']]), 'updated_at' => now('UTC'),
            ]);
            DB::table('scene_projects')->where('id', $projectId)->update(['state' => $next, 'row_version' => $project->row_version + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $project->row_version + 1, 'scene.project.task.completed', ['task_id' => $taskId]);

            return ['id' => $taskId, 'row_version' => $expected + 1, 'state' => 'done', 'project_state' => $next];
        }, 3);
    }

    public function acceptance(ScenePrincipal $owner, int $projectId, int $expected, string $key, array $input): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'items'                  => 'required|array|min:1|max:50', 'items.*' => 'array:key,result,note,evidence_ids',
            'items.*.key'            => 'required|string|max:80|distinct', 'items.*.result' => 'required|in:pass,fail',
            'items.*.note'           => 'nullable|string|max:5000', 'items.*.evidence_ids' => 'present|array|max:30',
            'items.*.evidence_ids.*' => 'required|integer|distinct|min:1',
        ])->validate();

        return $this->flow->command($owner->scope(), 'scene.project.accept:'.$projectId, $key, [$expected, $data], function () use ($owner, $projectId, $expected, $data) {
            $ref = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $ref->request_id, true);
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (! in_array($project->state, ['awaiting_acceptance', 'rectification'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目尚未进入客户验收。', 409);
            }
            $template = SceneWorkflow::unpack($project->snapshot)['acceptance_template'] ?? [];
            if (! $template) {
                throw new SceneWorkflowException('SCENE_ACCEPTANCE_TEMPLATE_MISSING', '项目未冻结验收标准，不能通过验收。', 409);
            }
            $defined = array_column($template, null, 'key');
            foreach ($data['items'] as $item) {
                if (! isset($defined[$item['key']])) {
                    throw new SceneWorkflowException('SCENE_ACCEPTANCE_ITEM_UNKNOWN', '验收项不属于冻结标准。');
                }
                $evidence = $this->attachmentEvidence((int) $project->request_id, $item['evidence_ids']);
                $attempt = (int) DB::table('scene_acceptance_records')->where('project_id', $projectId)->where('item_key', $item['key'])->max('attempt') + 1;
                DB::table('scene_acceptance_records')->insert(['project_id' => $projectId, 'item_key' => $item['key'], 'attempt' => $attempt,
                    'result'                                                => $item['result'], 'method' => 'customer_online', 'principal_scope' => $owner->scope(),
                    'note'                                                  => $item['note'] ?? null, 'evidence' => SceneWorkflow::json($evidence), 'created_at' => now('UTC')]);
                if ($item['result'] === 'fail') {
                    DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'rectify:'.$projectId.':'.$item['key'].':'.$attempt,
                        'request_id'                                                 => $project->request_id, 'kind' => 'acceptance_rectification', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                } else {
                    // 按具体初验记录关闭投影，原 fail 记录与整改历史保留，不能按模糊前缀误关其他项。
                    $keys = DB::table('scene_acceptance_records')->where('project_id', $projectId)->where('item_key', $item['key'])->where('result', 'fail')->pluck('attempt')
                        ->map(fn ($number) => 'rectify:'.$projectId.':'.$item['key'].':'.$number)->all();
                    DB::table('scene_operation_todos')->whereIn('dedupe_key', $keys)->where('state', 'open')->update([
                        'state' => 'resolved', 'resolution_code' => 'customer_retest_passed', 'updated_at' => now('UTC'),
                    ]);
                }
            }
            $passed = true;
            $failed = false;
            foreach ($defined as $key => $definition) {
                $last = DB::table('scene_acceptance_records')->where('project_id', $projectId)->where('item_key', $key)->orderByDesc('attempt')->first();
                if ($last?->result === 'fail') {
                    $failed = true;
                }
                if (($definition['required'] ?? true) && $last?->result !== 'pass') {
                    $passed = false;
                }
            }
            $state = $failed ? 'rectification' : ($passed ? 'accepted' : 'awaiting_acceptance');
            DB::table('scene_projects')->where('id', $projectId)->update(['state' => $state, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_project', $projectId, $expected + 1, 'scene.project.acceptance.recorded');

            return ['id' => $projectId, 'row_version' => $expected + 1, 'state' => $state];
        }, function () use ($owner, $projectId) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $project->request_id);
        });
    }

    public function customerAction(ScenePrincipal $owner, int $projectId, int $expected, string $key, string $action, array $input): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, ['reason' => 'required|string|max:10000', 'requested_change' => 'nullable|array|max:20'])->validate();
        if (! in_array($action, ['change', 'cancel', 'after_sale'], true)) {
            throw new SceneWorkflowException('SCENE_OPERATION_INVALID', '项目操作无效。');
        }

        return $this->flow->command($owner->scope(), 'scene.project.'.$action.':'.$projectId, $key, [$expected, $data], function () use ($owner, $projectId, $expected, $action, $data) {
            $ref = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $ref->request_id, true);
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if ($action === 'after_sale') {
                $id = DB::table('scene_service_tickets')->insertGetId(['project_id' => $projectId, 'kind' => 'service', 'description' => $data['reason'], 'state' => 'open', 'principal_scope' => $owner->scope(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            } else {
                if (in_array($project->state, ['closed', 'cancelled'], true)) {
                    throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已关闭，请使用售后入口。', 409);
                }
                $id = DB::table('scene_project_changes')->insertGetId(['project_id' => $projectId, 'base_version' => $expected,
                    'kind'                                                          => $action, 'reason' => $data['reason'], 'requested_change' => SceneWorkflow::json($data['requested_change'] ?? []),
                    'principal_scope'                                               => $owner->scope(), 'state' => 'requested', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }
            DB::table('scene_projects')->where('id', $projectId)->update(['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_project', $projectId, $expected + 1, 'scene.project.'.$action.'.requested');

            return ['id' => $id, 'state' => 'requested', 'project_row_version' => $expected + 1, 'effective' => false];
        }, function () use ($owner, $projectId) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $project->request_id);
        });
    }

    public function detail(ScenePrincipal $owner, int $id): array
    {
        $project = SceneWorkflow::requireRow('scene_projects', $id, false);
        $owner->request((int) $project->request_id);
        $snapshot = SceneWorkflow::unpack($project->snapshot);

        return ['id'              => (int) $project->id, 'project_no' => $project->project_no, 'request_id' => (int) $project->request_id,
            'state'               => $project->state, 'row_version' => (int) $project->row_version,
            'payment_state'       => $project->payment_state, 'commercial_state' => $project->commercial_state, 'returned' => (bool) $project->returned,
            'acceptance_template' => $snapshot['acceptance_template'] ?? [],
            'tasks'               => DB::table('scene_project_tasks')->where('project_id', $id)->orderBy('id')->get(['id', 'task_key', 'name', 'state', 'occurred_at'])->all(),
            'acceptances'         => DB::table('scene_acceptance_records')->where('project_id', $id)->orderBy('id')->get(['id', 'item_key', 'attempt', 'result', 'method', 'note', 'created_at'])->all(),
            'changes'             => DB::table('scene_project_changes')->where('project_id', $id)->orderByDesc('id')->get(['id', 'kind', 'state', 'reason', 'created_at'])->all(),
            'tickets'             => DB::table('scene_service_tickets')->where('project_id', $id)->orderByDesc('id')->get(['id', 'kind', 'description', 'state', 'resolution', 'created_at'])->all()];
    }

    public function recordCommercial(int $projectId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, ['kind' => 'required|in:payment_receipt,refund_due,refund_receipt',
            'amount_minor'                      => 'required|integer|min:1|max:1000000000000', 'reference' => 'required|string|max:255',
            'evidence_ids'                      => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1'])->validate();

        return DB::transaction(function () use ($projectId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            $id = DB::table('scene_commercial_records')->insertGetId(['project_id' => $projectId, 'kind' => $data['kind'],
                'amount_minor'                                                     => $data['amount_minor'], 'reference' => $data['reference'], 'evidence' => SceneWorkflow::json($evidence),
                'recorded_by'                                                      => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('scene_projects')->where('id', $projectId)->update(['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.commercial.recorded', ['record_id' => $id]);

            return ['id' => $id, 'state' => 'pending_review', 'project_row_version' => $expected + 1];
        }, 3);
    }

    public function reviewCommercial(int $projectId, int $recordId, int $expected, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');

        return DB::transaction(function () use ($projectId, $recordId, $expected, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            $record = SceneWorkflow::requireRow('scene_commercial_records', $recordId);
            if ((int) $record->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '记录不存在。', 404);
            }
            if ($record->reviewed_at || (int) $record->recorded_by === $actor) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_REVIEW_CONFLICT', '凭证已复核或不能由录入人自行复核。', 409);
            }
            $sum = fn ($kind) => (int) DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', $kind)->whereNotNull('reviewed_at')->sum('amount_minor');
            $quote = SceneWorkflow::requireRow('scene_quotes', (int) $project->quote_id, false);
            $paid = $sum('payment_receipt');
            $due = $sum('refund_due');
            $refunded = $sum('refund_receipt');
            $patch = ['row_version' => $expected + 1, 'updated_at' => now('UTC')];
            $amount = (int) $record->amount_minor;
            if ($record->kind === 'payment_receipt') {
                if ($paid + $amount > (int) $quote->total_minor + (int) $quote->deposit_minor) {
                    throw new SceneWorkflowException('SCENE_PAYMENT_LIMIT', '累计复核收款超过冻结报价及押金总额。');
                }
                $patch['payment_state'] = $paid + $amount === (int) $quote->total_minor + (int) $quote->deposit_minor ? 'offline_verified' : 'offline_partial';
            } elseif ($record->kind === 'refund_due') {
                if ($due + $amount > $paid) {
                    throw new SceneWorkflowException('SCENE_REFUND_LIMIT', '应退金额不能超过已复核收款。');
                }
            } elseif ($record->kind === 'refund_receipt' && ($refunded + $amount > $due || $refunded + $amount > $paid)) {
                throw new SceneWorkflowException('SCENE_REFUND_LIMIT', '实际退款不能超过已复核应退额和收款。');
            }
            DB::table('scene_commercial_records')->where('id', $recordId)->update(['reviewed_by' => $actor, 'reviewed_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('scene_projects')->where('id', $projectId)->update($patch);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.commercial.reviewed', ['record_id' => $recordId]);

            return ['id' => $recordId, 'state' => 'manually_verified', 'project_row_version' => $expected + 1];
        }, 3);
    }

    public function returnResources(int $projectId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, ['occurred_at' => 'required|date|before_or_equal:now', 'parts_checked' => 'required|accepted',
            'appearance_checked'                       => 'required|accepted', 'damage_dispute' => 'required|boolean',
            'evidence_ids'                             => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1'])->validate();

        return DB::transaction(function () use ($projectId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if ($project->returned || in_array($project->state, ['preparing', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_RETURN_STATE_INVALID', '项目尚未出库或已经返库。', 409);
            }
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            $reservations = DB::table('scene_reservations')->where('quote_id', $project->quote_id)->where('state', 'confirmed')->orderBy('resource_id')->get();
            foreach ($reservations as $reservation) {
                $resource = SceneWorkflow::requireRow('scene_resources', (int) $reservation->resource_id);
                // 返库不提前消除运输/维护缓冲，延误影响交由待办处理。
                if (strtotime($data['occurred_at']) > strtotime($reservation->service_ends_at.' UTC')) {
                    DB::table('scene_resources')->where('id', $reservation->resource_id)->update(['state' => 'maintenance', 'state_reason' => '晚归，需重新核验维护与后续档期', 'row_version' => $resource->row_version + 1, 'updated_at' => now('UTC')]);
                    DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'late-return:'.$projectId.':'.$reservation->resource_id,
                        'request_id'                                                 => $project->request_id, 'kind' => 'late_return_impact', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                }
            }
            $snapshot = SceneWorkflow::unpack($project->snapshot);
            $snapshot['return_record'] = ['source' => 'manual', 'occurred_at' => SceneWorkflow::utc($data['occurred_at']), 'recorded_at' => now('UTC')->toIso8601String(),
                'parts_checked'                    => true, 'appearance_checked' => true, 'damage_dispute' => (bool) $data['damage_dispute'], 'attachments' => $evidence, 'actor_id' => $actor];
            DB::table('scene_projects')->where('id', $projectId)->update(['returned' => true, 'open_dispute' => (bool) $project->open_dispute || (bool) $data['damage_dispute'],
                'snapshot'                                                           => SceneWorkflow::json($snapshot), 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.returned');

            return ['id' => $projectId, 'returned' => true, 'row_version' => $expected + 1, 'refund_status' => 'requires_financial_review'];
        }, 3);
    }

    public function resolveTicket(int $id, int $expected, string $resolution, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        if (trim($resolution) === '' || mb_strlen($resolution) > 10000) {
            throw new SceneWorkflowException('SCENE_RESOLUTION_REQUIRED', '请填写实际处理结果。');
        }

        return DB::transaction(function () use ($id, $expected, $resolution, $actor) {
            $ticket = SceneWorkflow::requireRow('scene_service_tickets', $id);
            SceneWorkflow::version($ticket, $expected);
            if ($ticket->state === 'resolved') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '工单已经处理。', 409);
            }
            DB::table('scene_service_tickets')->where('id', $id)->update(['state' => 'resolved', 'resolution' => $resolution,
                'assignee_id'                                                     => $actor, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_service_ticket', $id, $expected + 1, 'scene.service-ticket.resolved');

            return ['id' => $id, 'state' => 'resolved', 'row_version' => $expected + 1];
        }, 3);
    }

    private function attachmentEvidence(int $requestId, array $ids): array
    {
        $rows = DB::table('scene_attachments')->where('request_id', $requestId)->whereIn('id', $ids)->where('state', 'clean')->where('expires_at', '>', now('UTC'))->get(['id', 'sha256']);
        if ($rows->count() !== count($ids)) {
            throw new SceneWorkflowException('SCENE_EVIDENCE_INVALID', '证据附件未扫描通过、已过期或不属于本项目需求。');
        }

        return $rows->map(fn ($r) => ['attachment_id' => (int) $r->id, 'sha256' => $r->sha256])->all();
    }
}
