<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Webkul\Shop\Services\Deploy\KillSwitchService;

class SceneProjectService
{
    public function __construct(protected SceneWorkflow $flow) {}

    /** 必须由经过准入的转换事务调用；此方法不提供跳过订单确认的公开入口。 */
    public function initializeTasks(int $projectId, ?int $actor): void
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
                'checklist' => SceneWorkflow::json($task['checklist']), 'project_state_after' => $task['project_state_after'],
                'state' => 'pending', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
        $this->flow->record($actor ? 'admin' : 'system', $actor ?: null, 'scene_project', $projectId, $project->row_version, 'scene.project.tasks.initialized');
    }

    public function completeTask(int $projectId, int $taskId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'checks' => 'required|array|min:1|max:30', 'checks.*' => 'required|string|max:80',
            'evidence_ids' => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1',
            'occurred_at' => 'required|string|max:40',
        ])->validate();
        $data['occurred_at'] = SceneTime::occurredAt($data['occurred_at']);

        return DB::transaction(function () use ($projectId, $taskId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            if ($project->state === 'paused') {
                throw new SceneWorkflowException('SCENE_PROJECT_PAUSED', '项目处于事故暂停状态，需排除风险并恢复后方可继续执行任务。', 409);
            }
            if (! in_array($project->state, ['preparing', 'delivering', 'servicing'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目当前状态不支持完成交付任务。', 409);
            }
            $snapshot = SceneWorkflow::unpack($project->snapshot);
            $components = array_merge(
                $snapshot['proposal']['components'] ?? [],
                $snapshot['components'] ?? []
            );
            $skillProductIds = [];
            foreach ($components as $comp) {
                if (($comp['type'] ?? '') === 'skill' && ! empty($comp['product_id'])) {
                    $skillProductIds[] = (int) $comp['product_id'];
                }
            }
            if (empty($skillProductIds) && ! empty($project->quote_id)) {
                $quoteItems = DB::table('scene_quote_items')->where('quote_id', $project->quote_id)->get();
                foreach ($quoteItems as $qi) {
                    $s = SceneWorkflow::unpack($qi->snapshot);
                    if (($s['kind'] ?? '') === 'skill' && ! empty($s['product_id'])) {
                        $skillProductIds[] = (int) $s['product_id'];
                    }
                }
            }
            $skillProductIds = array_unique(array_filter($skillProductIds));
            foreach ($skillProductIds as $skillId) {
                $isFused = false;
                if (Schema::hasTable('skill_blocklist') && DB::table('skill_blocklist')->where('skill_id', $skillId)->where('status', 'active')->exists()) {
                    $isFused = true;
                } elseif (class_exists(KillSwitchService::class) && app()->bound(KillSwitchService::class)) {
                    $isFused = app(KillSwitchService::class)->isBlockedFor($skillId, 'sale', true);
                }
                if ($isFused) {
                    throw new SceneWorkflowException('SCENE_SKILL_CIRCUIT_BROKEN', '交付涉及的技能已触发平台安全熔断，任务暂停推进。', 409);
                }
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
            if (DB::table('scene_project_tasks')->where('project_id', $projectId)->whereIn('task_key', $dependencies)
                ->where(fn ($q) => $q->whereNull('occurred_at')->orWhere('occurred_at', '>', $data['occurred_at']))->exists()) {
                throw new SceneWorkflowException('SCENE_TASK_TIME_CONFLICT', '实际发生时间早于前置任务，或前置任务实际时间未核实。', 409);
            }
            if (array_diff(SceneWorkflow::unpack($task->checklist), $data['checks'])) {
                throw new SceneWorkflowException('SCENE_TASK_CHECKS_INCOMPLETE', '必需检查项尚未逐项确认。');
            }
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            // 并行分支完成顺序不等于项目阶段顺序，较早阶段的任务不能让项目退回。
            $states = ['preparing' => 0, 'delivering' => 1, 'servicing' => 2, 'awaiting_acceptance' => 3];
            if (! isset($states[$task->project_state_after])) {
                throw new SceneWorkflowException('SCENE_WORKFLOW_INVALID', '任务阶段不属于有效履约状态。', 409);
            }
            $next = $states[$project->state] > $states[$task->project_state_after] ? $project->state : $task->project_state_after;
            if ($next === 'awaiting_acceptance' && DB::table('scene_project_tasks')->where('project_id', $projectId)->where('id', '!=', $taskId)->where('state', '!=', 'done')->exists()) {
                throw new SceneWorkflowException('SCENE_TASKS_INCOMPLETE', '全部履约任务完成后才能进入验收。', 409);
            }
            DB::table('scene_project_tasks')->where('id', $taskId)->update([
                'state' => 'done', 'row_version' => $expected + 1, 'assignee_id' => $actor,
                'occurred_at' => $data['occurred_at'], 'recorded_at' => now('UTC'),
                'evidence' => SceneWorkflow::json(['source' => 'manual', 'attachments' => $evidence, 'checks' => $data['checks']]), 'updated_at' => now('UTC'),
            ]);
            DB::table('scene_projects')->where('id', $projectId)->update(['state' => $next, 'row_version' => $project->row_version + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $project->row_version + 1, 'scene.project.task.completed', ['task_id' => $taskId]);

            return ['id' => $taskId, 'row_version' => $expected + 1, 'state' => 'done', 'project_state' => $next];
        }, 3);
    }

    public function acceptance(ScenePrincipal $owner, int $projectId, int $expected, string $key, array $input): array
    {
        return app(SceneAcceptanceService::class)->online($owner, $projectId, $expected, $key, $input);
    }

    public function customerAction(ScenePrincipal $owner, int $projectId, int $expected, string $key, string $action, array $input): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'reason' => 'required|string|max:10000',
            'requested_change' => 'nullable|array|max:20',
            'scope' => 'nullable|in:platform_service,skill_fault',
            'order_item_id' => 'nullable|integer|min:1',
            'entitlement_id' => 'nullable|integer|min:1',
        ])->validate();
        if (! in_array($action, ['change', 'cancel', 'after_sale'], true)) {
            throw new SceneWorkflowException('SCENE_OPERATION_INVALID', '项目操作无效。');
        }

        return $this->flow->command($owner->scope(), 'scene.project.'.$action.':'.$projectId, $key, [$expected, $data], function () use ($owner, $projectId, $expected, $action, $data) {
            $ref = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $ref->request_id, true);
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if ($action === 'after_sale') {
                $scope = $data['scope'] ?? 'platform_service';
                $orderItemId = $data['order_item_id'] ?? null;
                $entitlementId = $data['entitlement_id'] ?? null;
                if ($scope === 'skill_fault') {
                    $identity = app(SceneSkillAfterSaleService::class)->assertEligible($owner, $project, $orderItemId, $entitlementId);
                    $orderItemId = $identity['order_item_id'];
                    $entitlementId = $identity['entitlement_id'];
                }
                $id = DB::table('scene_service_tickets')->insertGetId([
                    'project_id' => $projectId,
                    'kind' => 'service',
                    'scope' => $scope,
                    'order_item_id' => $orderItemId,
                    'entitlement_id' => $entitlementId,
                    'description' => $data['reason'],
                    'state' => 'open',
                    'principal_scope' => $owner->scope(),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            } else {
                if (in_array($project->state, ['closed', 'cancelled'], true)) {
                    throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已关闭，请使用售后入口。', 409);
                }
                $id = DB::table('scene_project_changes')->insertGetId([
                    'project_id' => $projectId,
                    'base_version' => $expected,
                    'kind' => $action,
                    'reason' => $data['reason'],
                    'requested_change' => SceneWorkflow::json($data['requested_change'] ?? []),
                    'principal_scope' => $owner->scope(),
                    'state' => 'requested',
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }
            DB::table('scene_projects')->where('id', $projectId)->update(['row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_project', $projectId, $expected + 1, 'scene.project.'.$action.'.requested');

            return ['id' => $id, 'state' => $action === 'after_sale' ? 'open' : 'requested', 'project_row_version' => $expected + 1, 'effective' => false];
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
        $link = DB::table('scene_order_links')->where('project_id', $id)->first();
        $part = $link ? DB::table('scene_order_parts')->where('quote_id', $link->quote_id)->first() : null;

        return ['id' => (int) $project->id, 'project_no' => $project->project_no, 'request_id' => (int) $project->request_id,
            'state' => $project->state, 'row_version' => (int) $project->row_version,
            'paused_from_state' => $project->paused_from_state ?? null,
            'incident_ref' => $project->incident_ref ?? null,
            'payment_state' => $project->payment_state, 'commercial_state' => $project->commercial_state, 'returned' => (bool) $project->returned,
            'acceptance_template' => $snapshot['acceptance_template'] ?? [],
            'schedule' => $snapshot['schedule'] ?? null,
            'orders' => array_values(array_filter([$link ? ['id' => (int) $link->order_id, 'kind' => 'service'] : null, $part ? ['id' => (int) $part->order_id, 'kind' => 'skill'] : null])),
            'resources' => array_map(fn ($item) => ['resource_id' => $item['resource']['resource_id'], 'kind' => $item['resource']['kind'],
                'product_id' => $item['resource']['product_id'], 'occupies_from' => $item['occupies_from'], 'occupies_until' => $item['occupies_until']], $snapshot['resources'] ?? []),
            'resource_attention' => ! $project->returned && DB::table('scene_operation_todos')->where('request_id', $project->request_id)->where('kind', 'resource_impact')->where('state', 'open')->exists(),
            'tasks' => DB::table('scene_project_tasks')->where('project_id', $id)->orderBy('id')->get(['id', 'task_key', 'name', 'state', 'occurred_at'])->all(),
            'acceptances' => DB::table('scene_acceptance_records')->where('project_id', $id)->orderBy('id')->get(['id', 'item_key', 'attempt', 'result', 'method', 'note', 'occurred_at', 'created_at', 'offline_acceptance_id'])->all(),
            'offline_confirmations' => DB::table('scene_offline_acceptances')->where('project_id', $id)->orderByDesc('id')->get(['id', 'state', 'occurred_at', 'created_at', 'reviewed_at'])->all(),
            'changes' => DB::table('scene_project_changes')->where('project_id', $id)->orderByDesc('id')->get(['id', 'kind', 'state', 'reason', 'price_delta_minor', 'evaluation', 'reviewed_at', 'customer_accepted_at', 'created_at'])->all(),
            'tickets' => DB::table('scene_service_tickets')->where('project_id', $id)->orderByDesc('id')->get(['id', 'kind', 'scope', 'description', 'state', 'resolution', 'refund_proposal_minor', 'created_at'])->all()];
    }

    public function recordCommercial(int $projectId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, ['kind' => 'required|in:payment_receipt,refund_due,refund_receipt',
            'amount_minor' => 'required|integer|min:1|max:1000000000000', 'reference' => 'required|string|max:255',
            'evidence_ids' => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1'])->validate();

        return DB::transaction(function () use ($projectId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            $id = DB::table('scene_commercial_records')->insertGetId(['project_id' => $projectId, 'kind' => $data['kind'],
                'amount_minor' => $data['amount_minor'], 'reference' => $data['reference'], 'evidence' => SceneWorkflow::json($evidence),
                'recorded_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
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
            $paid = $sum('payment_receipt') + (int) (SceneWorkflow::unpack($project->snapshot)['commercial']['paid_minor'] ?? 0);
            $due = $sum('refund_due');
            $refunded = $sum('refund_receipt');
            $patch = ['row_version' => $expected + 1, 'updated_at' => now('UTC')];
            $amount = (int) $record->amount_minor;

            // 正价差累加进入应收总额上限核算
            $appliedChangesDelta = (int) DB::table('scene_project_changes')
                ->where('project_id', $projectId)
                ->where('state', 'applied')
                ->sum('price_delta_minor');
            $totalReceivableCap = (int) $quote->total_minor + (int) $quote->deposit_minor + max(0, $appliedChangesDelta);

            if ($record->kind === 'payment_receipt') {
                if ($paid + $amount > $totalReceivableCap) {
                    throw new SceneWorkflowException('SCENE_PAYMENT_LIMIT', '累计复核收款超过冻结报价、押金及已生效变更增额总计。');
                }
                $patch['payment_state'] = $paid + $amount >= $totalReceivableCap ? 'offline_verified' : 'offline_partial';
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
        $data = Validator::make($input, ['occurred_at' => 'required|string|max:40', 'parts_checked' => 'required|accepted',
            'appearance_checked' => 'required|accepted', 'damage_dispute' => 'required|boolean',
            'evidence_ids' => 'required|array|min:1|max:30', 'evidence_ids.*' => 'required|integer|distinct|min:1'])->validate();
        $data['occurred_at'] = SceneTime::occurredAt($data['occurred_at']);

        return DB::transaction(function () use ($projectId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            $cancelledReturn = $project->state === 'cancelled' && DB::table('scene_reservations')->where('quote_id', $project->quote_id)->where('state', 'confirmed')->exists();
            if ($project->returned || in_array($project->state, ['preparing', 'closed'], true) || ($project->state === 'cancelled' && ! $cancelledReturn)) {
                throw new SceneWorkflowException('SCENE_RETURN_STATE_INVALID', '项目尚未出库或已经返库。', 409);
            }
            if (DB::table('scene_project_tasks')->where('project_id', $projectId)->where('state', 'done')
                ->where(fn ($q) => $q->whereNull('occurred_at')->orWhere('occurred_at', '>', $data['occurred_at']))->exists()) {
                throw new SceneWorkflowException('SCENE_RETURN_TIME_CONFLICT', '返库时间早于已记录交付任务，或任务实际时间未核实。', 409);
            }
            $evidence = $this->attachmentEvidence((int) $project->request_id, $data['evidence_ids']);
            $reservations = DB::table('scene_reservations')->where('quote_id', $project->quote_id)->where('state', 'confirmed')->orderBy('resource_id')->get();
            foreach ($reservations as $reservation) {
                $resource = SceneWorkflow::requireRow('scene_resources', (int) $reservation->resource_id);
                if (! in_array($resource->kind, ['robot', 'accessory'], true)) {
                    if ($cancelledReturn) {
                        DB::table('scene_reservations')->where('id', $reservation->id)->update(['state' => 'released', 'updated_at' => now('UTC')]);
                    }

                    continue;
                }
                // 返库不提前消除运输/维护缓冲，延误影响交由待办处理。
                $late = $data['occurred_at'] > $reservation->service_ends_at;
                if ($late || $data['damage_dispute']) {
                    // 损坏争议不能继续作为 ready 资源出售；保留 confirmed，不偷偷释放已签档期。
                    $reason = $data['damage_dispute'] ? '返库损坏争议，需复核设备状态与后续档期' : '晚归，需重新核验维护与后续档期';
                    DB::table('scene_resources')->where('id', $reservation->resource_id)->update(['state' => $resource->state === 'ready' ? 'maintenance' : $resource->state,
                        'state_reason' => $resource->state === 'ready' ? $reason : ($resource->state_reason ? $resource->state_reason.'；'.$reason : $reason),
                        'row_version' => $resource->row_version + 1, 'updated_at' => now('UTC')]);
                    DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'return-risk:'.$projectId.':'.$reservation->resource_id,
                        'request_id' => $project->request_id, 'kind' => $data['damage_dispute'] ? 'return_damage_review' : 'late_return_impact', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                    foreach (DB::table('scene_reservations')->where('resource_id', $reservation->resource_id)->where('quote_id', '!=', $project->quote_id)
                        ->where('occupies_until', '>', now('UTC'))->where(fn ($q) => $q->where('state', 'confirmed')->orWhere(fn ($held) => $held->where('state', 'held')->where('hold_expires_at', '>', now('UTC'))))->get() as $affected) {
                        $quote = SceneWorkflow::requireRow('scene_quotes', (int) $affected->quote_id, false);
                        DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'return-impact:'.$projectId.':'.$affected->id,
                            'request_id' => $quote->request_id, 'kind' => 'resource_impact', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                    }
                    $this->flow->record('admin', $actor, 'scene_resource', (int) $resource->id, $resource->row_version + 1, 'scene.resource.paused');
                }
                if ($cancelledReturn) {
                    // 已取消服务在实际返库后结束占用；仍保留原运输/维护缓冲，资源恢复另须核验。
                    $originalBuffer = max(0, strtotime($reservation->occupies_until.' UTC') - strtotime($reservation->service_ends_at.' UTC'));
                    $until = \Illuminate\Support\Carbon::parse($data['occurred_at'], 'UTC')->addSeconds(max($originalBuffer, (int) $resource->buffer_after_minutes * 60));
                    DB::table('scene_reservations')->where('id', $reservation->id)->update([
                        // 提前取消返库可能早于原预留开始，实际缓冲区间仍须保持有效。
                        'occupies_from' => min($reservation->occupies_from, $data['occurred_at']),
                        'occupies_until' => $until->format('Y-m-d H:i:s'),
                        'state' => $until->lte(now('UTC')) ? 'released' : 'confirmed',
                        'updated_at' => now('UTC'),
                    ]);
                }
            }
            $snapshot = SceneWorkflow::unpack($project->snapshot);
            $snapshot['return_record'] = ['source' => 'manual', 'occurred_at' => SceneWorkflow::utc($data['occurred_at']), 'recorded_at' => now('UTC')->toIso8601String(),
                'parts_checked' => true, 'appearance_checked' => true, 'damage_dispute' => (bool) $data['damage_dispute'], 'attachments' => $evidence, 'actor_id' => $actor];
            DB::table('scene_projects')->where('id', $projectId)->update(['returned' => true, 'open_dispute' => (bool) $project->open_dispute || (bool) $data['damage_dispute'],
                'snapshot' => SceneWorkflow::json($snapshot), 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($cancelledReturn) {
                DB::table('scene_operation_todos')->where('dedupe_key', 'cancellation-return:'.$projectId)->where('state', 'open')
                    ->update(['state' => 'resolved', 'resolution_code' => 'return_recorded_resource_review_required', 'updated_at' => now('UTC')]);
            }
            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.returned');

            return ['id' => $projectId, 'returned' => true, 'row_version' => $expected + 1, 'refund_status' => 'requires_financial_review'];
        }, 3);
    }

    public function resolveTicket(int $id, int $expected, string $resolution, int $actor, int $refundProposalMinor = 0): array
    {
        $this->flow->enabled('project_actions_enabled');
        if (trim($resolution) === '' || mb_strlen($resolution) > 10000) {
            throw new SceneWorkflowException('SCENE_RESOLUTION_REQUIRED', '请填写实际处理结果。');
        }

        return DB::transaction(function () use ($id, $expected, $resolution, $actor, $refundProposalMinor) {
            $ticket = SceneWorkflow::requireRow('scene_service_tickets', $id);
            SceneWorkflow::version($ticket, $expected);
            if ($ticket->state === 'resolved') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '工单已经处理。', 409);
            }
            if ($ticket->scope === 'skill_fault' && $refundProposalMinor > 0) {
                throw new SceneWorkflowException('SCENE_SKILL_REFUND_REQUIRES_D10', '技能退款请通过既有技能售后申请及独立审核流程处理，场景工单只记录技术支持结果。', 409);
            }
            $refund = max(0, $refundProposalMinor);
            DB::table('scene_service_tickets')->where('id', $id)->update([
                'state' => 'resolved',
                'resolution' => $resolution,
                'assignee_id' => $actor,
                'refund_proposal_minor' => $refund,
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            if ($refund > 0) {
                DB::table('scene_commercial_records')->insert([
                    'project_id' => $ticket->project_id,
                    'kind' => 'refund_due',
                    'amount_minor' => $refund,
                    'reference' => '售后工单 #'.$id.' 协商赔付/退款',
                    'evidence' => SceneWorkflow::json([]),
                    'recorded_by' => $actor,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            $this->flow->record('admin', $actor, 'scene_service_ticket', $id, $expected + 1, 'scene.service-ticket.resolved', [
                'refund_proposal_minor' => $refund,
            ]);

            return ['id' => $id, 'state' => 'resolved', 'refund_proposal_minor' => $refund, 'row_version' => $expected + 1];
        }, 3);
    }

    public function evaluateChange(int $projectId, int $changeId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'price_delta_minor' => 'required|integer|between:-1000000000000,1000000000000',
            'schedule_delta' => 'nullable|array',
            'schedule_delta.starts_at' => 'required_with:schedule_delta|string|max:40',
            'schedule_delta.ends_at' => 'required_with:schedule_delta|string|max:40',
            'schedule_delta.timezone' => 'required_with:schedule_delta|string|max:50',
            'evaluation_summary' => 'required|string|max:5000',
        ])->validate();

        if (isset($data['schedule_delta'])) {
            $tz = $data['schedule_delta']['timezone'];
            if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                throw new SceneWorkflowException('SCENE_TIMEZONE_INVALID', '请提供服务地的 IANA 时区。', 422);
            }
            $zone = new \DateTimeZone($tz);
            $parseFuture = function ($text) use ($zone) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $text)) {
                    throw new SceneWorkflowException('SCENE_TIME_OFFSET_REQUIRED', '时间须使用含 UTC offset 的 ISO 8601 秒级格式。', 422);
                }
                $normalized = preg_replace('/Z$/', '+00:00', $text);
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
                $errors = \DateTimeImmutable::getLastErrors();
                if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->setTimezone($zone)->format('Y-m-d\TH:i:sP') !== $normalized) {
                    throw new SceneWorkflowException('SCENE_LOCAL_TIME_INVALID', '时间不存在、格式无效或 offset 与服务地时区不匹配。', 422);
                }

                return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            };
            $starts = $parseFuture($data['schedule_delta']['starts_at']);
            $ends = $parseFuture($data['schedule_delta']['ends_at']);
            if ($ends <= $starts) {
                throw new SceneWorkflowException('SCENE_SCHEDULE_INVALID', '变更后的结束时间必须晚于开始时间。', 422);
            }
            $data['schedule_delta']['starts_at'] = $starts;
            $data['schedule_delta']['ends_at'] = $ends;
        }

        return DB::transaction(function () use ($projectId, $changeId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (in_array($project->state, ['closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已处于终态，不能处理变更。', 409);
            }
            $change = SceneWorkflow::requireRow('scene_project_changes', $changeId);
            if ((int) $change->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '变更记录不存在。', 404);
            }
            if (! in_array($change->state, ['requested', 'evaluated'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '变更记录当前状态不可重新评估。', 409);
            }

            $evaluation = [
                'summary' => $data['evaluation_summary'],
                'schedule_delta' => $data['schedule_delta'] ?? null,
                'evaluator_id' => $actor,
                'evaluated_at' => now('UTC')->toIso8601String(),
            ];

            DB::table('scene_project_changes')->where('id', $changeId)->update([
                'state' => 'evaluated',
                'price_delta_minor' => $data['price_delta_minor'],
                'evaluation' => SceneWorkflow::json($evaluation),
                'updated_at' => now('UTC'),
            ]);

            DB::table('scene_projects')->where('id', $projectId)->update([
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record('admin', $actor, 'scene_project_change', $changeId, 1, 'scene.project.change.evaluated', [
                'price_delta_minor' => $data['price_delta_minor'],
            ]);

            return [
                'id' => $changeId,
                'state' => 'evaluated',
                'price_delta_minor' => (int) $data['price_delta_minor'],
                'project_row_version' => $expected + 1,
            ];
        }, 3);
    }

    public function reviewChange(int $projectId, int $changeId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'decision' => 'required|in:approve,reject',
            'note' => 'required|string|min:3|max:2000',
        ])->validate();

        return DB::transaction(function () use ($projectId, $changeId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (in_array($project->state, ['closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已处于终态，不能处理变更。', 409);
            }
            $change = SceneWorkflow::requireRow('scene_project_changes', $changeId);
            if ((int) $change->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '变更记录不存在。', 404);
            }
            if ($change->state !== 'evaluated') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '变更记录须经方案评估后方可审核。', 409);
            }
            $eval = SceneWorkflow::unpack($change->evaluation);
            if ((int) ($eval['evaluator_id'] ?? 0) === $actor) {
                throw new SceneWorkflowException('SCENE_REVIEW_SELF_FORBIDDEN', '评估人不可自行审核变更方案。', 409);
            }

            if ($data['decision'] === 'reject') {
                DB::table('scene_project_changes')->where('id', $changeId)->update([
                    'state' => 'rejected',
                    'reviewed_by' => $actor,
                    'reviewed_at' => now('UTC'),
                    'review_note' => $data['note'],
                    'updated_at' => now('UTC'),
                ]);
                DB::table('scene_projects')->where('id', $projectId)->update([
                    'row_version' => $expected + 1,
                    'updated_at' => now('UTC'),
                ]);
                $this->flow->record('admin', $actor, 'scene_project_change', $changeId, 1, 'scene.project.change.rejected');

                return ['id' => $changeId, 'state' => 'rejected', 'project_row_version' => $expected + 1];
            }

            $requiresCustomerAcceptance = ((int) $change->price_delta_minor !== 0) || ! empty($eval['schedule_delta']);
            $nextState = $requiresCustomerAcceptance ? 'pending_customer_acceptance' : 'applied';

            DB::table('scene_project_changes')->where('id', $changeId)->update([
                'state' => $nextState,
                'reviewed_by' => $actor,
                'reviewed_at' => now('UTC'),
                'review_note' => $data['note'],
                'updated_at' => now('UTC'),
            ]);

            if (! $requiresCustomerAcceptance) {
                $this->applyChangeDirectly($project, $change, $eval);
            }

            DB::table('scene_projects')->where('id', $projectId)->update([
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record('admin', $actor, 'scene_project_change', $changeId, 1, 'scene.project.change.approved', [
                'requires_customer_acceptance' => $requiresCustomerAcceptance,
            ]);

            return [
                'id' => $changeId,
                'state' => $nextState,
                'requires_customer_acceptance' => $requiresCustomerAcceptance,
                'project_row_version' => $expected + 1,
            ];
        }, 3);
    }

    public function customerAcceptChange(ScenePrincipal $owner, int $projectId, int $changeId, int $expected, string $key, array $input): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'decision' => 'required|in:accept,decline',
            'note' => 'nullable|string|max:1000',
        ])->validate();

        return $this->flow->command($owner->scope(), 'scene.project.change-decision:'.$changeId, $key, [$expected, $data], function () use ($owner, $projectId, $changeId, $expected, $data) {
            $ref = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $ref->request_id, true);
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (in_array($project->state, ['closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已处于终态，不能处理变更。', 409);
            }
            $change = SceneWorkflow::requireRow('scene_project_changes', $changeId);
            if ((int) $change->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '变更记录不存在。', 404);
            }
            if ($change->state !== 'pending_customer_acceptance') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '变更记录未处于等待客户确认状态。', 409);
            }

            if ($data['decision'] === 'decline') {
                DB::table('scene_project_changes')->where('id', $changeId)->update([
                    'state' => 'declined',
                    'decision_note' => $data['note'] ?? null,
                    'updated_at' => now('UTC'),
                ]);
                DB::table('scene_projects')->where('id', $projectId)->update([
                    'row_version' => $expected + 1,
                    'updated_at' => now('UTC'),
                ]);
                $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_project_change', $changeId, 1, 'scene.project.change.declined');

                return ['id' => $changeId, 'state' => 'declined', 'effective' => false, 'project_row_version' => $expected + 1];
            }

            $eval = SceneWorkflow::unpack($change->evaluation);
            $this->applyChangeDirectly($project, $change, $eval);

            DB::table('scene_project_changes')->where('id', $changeId)->update([
                'state' => 'applied',
                'customer_accepted_at' => now('UTC'),
                'decision_note' => $data['note'] ?? null,
                'updated_at' => now('UTC'),
            ]);

            DB::table('scene_projects')->where('id', $projectId)->update([
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_project_change', $changeId, 1, 'scene.project.change.applied');

            return [
                'id' => $changeId,
                'state' => 'applied',
                'effective' => true,
                'project_row_version' => $expected + 1,
            ];
        }, function () use ($owner, $projectId) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId, false);
            $owner->request((int) $project->request_id);
        });
    }

    protected function applyChangeDirectly(object $project, object $change, array $eval): void
    {
        $snapshot = SceneWorkflow::unpack($project->snapshot);
        if (! empty($eval['schedule_delta'])) {
            $newStarts = $eval['schedule_delta']['starts_at'];
            $newEnds = $eval['schedule_delta']['ends_at'];
            $newTz = $eval['schedule_delta']['timezone'];
            $reservations = DB::table('scene_reservations')
                ->where('quote_id', $project->quote_id)
                ->where('state', 'confirmed')
                ->orderBy('resource_id')
                ->get();
            // 所有父行先按固定次序上锁，再读取和调整预留，沿用统一资源核验服务。
            $parents = [];
            foreach ($reservations->pluck('resource_id')->unique()->sort()->values() as $resourceId) {
                $parents[(int) $resourceId] = SceneWorkflow::requireRow('scene_resources', (int) $resourceId);
            }
            $reservations = DB::table('scene_reservations')->whereIn('id', $reservations->pluck('id'))->orderBy('resource_id')->lockForUpdate()->get();
            $quote = SceneWorkflow::requireRow('scene_quotes', (int) $project->quote_id, false);
            $revision = DB::table('scene_request_revisions')->where('request_id', $project->request_id)->where('revision_no', $quote->request_revision_no)->first();
            $city = SceneWorkflow::unpack($revision?->facts)['city'] ?? '';
            $schedule = SceneTime::schedule([
                'starts_at' => \Illuminate\Support\Carbon::parse($newStarts, 'UTC')->setTimezone($newTz)->toIso8601String(),
                'ends_at' => \Illuminate\Support\Carbon::parse($newEnds, 'UTC')->setTimezone($newTz)->toIso8601String(),
                'timezone' => $newTz,
                'buffer_before_minutes' => (int) ($snapshot['schedule']['buffer_before_minutes'] ?? 0),
                'buffer_after_minutes' => (int) ($snapshot['schedule']['buffer_after_minutes'] ?? 0),
            ]);
            $changedResources = [];
            foreach ($reservations as $rRow) {
                $resObj = $parents[(int) $rRow->resource_id];
                // 保持原预留的运输及维护缓冲时长
                $leadSeconds = max(0, strtotime($rRow->service_starts_at.' UTC') - strtotime($rRow->occupies_from.' UTC'));
                $returnSeconds = max(0, strtotime($rRow->occupies_until.' UTC') - strtotime($rRow->service_ends_at.' UTC'));
                $resourceSchedule = $schedule;
                $resourceSchedule['buffer_before_minutes'] = max($schedule['buffer_before_minutes'], (int) ceil($leadSeconds / 60));
                $resourceSchedule['buffer_after_minutes'] = max($schedule['buffer_after_minutes'], (int) ceil($returnSeconds / 60));
                $interval = app(SceneResourceService::class)->interval($resObj, $resourceSchedule, $city);
                if ($resObj->kind === 'robot' && (! $resObj->binding_id || SceneWorkflow::requireRow('device_binding', (int) $resObj->binding_id)->bind_status !== 'bound')) {
                    throw new SceneWorkflowException('SCENE_BINDING_UNAVAILABLE', '改期设备的绑定已失效，请重新评估履约资源。', 409);
                }
                $occupiesFrom = $interval['occupies_from'];
                $occupiesUntil = $interval['occupies_until'];

                // 档期冲突锁行核验
                $collision = DB::table('scene_reservations')
                    ->where('resource_id', $resObj->id)
                    ->where('id', '!=', $rRow->id)
                    ->where('occupies_from', '<', $occupiesUntil)
                    ->where('occupies_until', '>', $occupiesFrom)
                    ->where(function ($q) {
                        $q->where('state', 'confirmed')
                            ->orWhere(fn ($held) => $held->where('state', 'held')->where('hold_expires_at', '>', now('UTC')));
                    });
                if ($collision->lockForUpdate()->first()) {
                    throw new SceneWorkflowException('SCENE_RESOURCE_CONFLICT', '变更后的时段（含运输/维护缓冲）与资源现有预留冲突。', 409);
                }

                DB::table('scene_reservations')->where('id', $rRow->id)->update([
                    'service_starts_at' => $newStarts,
                    'service_ends_at' => $newEnds,
                    'occupies_from' => $occupiesFrom,
                    'occupies_until' => $occupiesUntil,
                    'resource_snapshot' => SceneWorkflow::json(app(SceneResourceService::class)->snapshot($resObj)),
                    'updated_at' => now('UTC'),
                ]);
                $changedResources[] = ['reservation_id' => (int) $rRow->id, 'resource' => app(SceneResourceService::class)->snapshot($resObj)] + $interval;
            }
            $snapshot['resource_changes'][] = ['change_id' => (int) $change->id, 'previous_schedule' => $snapshot['schedule'] ?? null, 'previous_resources' => $snapshot['resources'] ?? []];
            $snapshot['schedule'] = $schedule;
            $snapshot['resources'] = $changedResources;
        }

        $priceDelta = (int) $change->price_delta_minor;
        if ($priceDelta < 0) {
            $refundAmount = abs($priceDelta);
            DB::table('scene_commercial_records')->insert([
                'project_id' => $project->id,
                'kind' => 'refund_due',
                'amount_minor' => $refundAmount,
                'reference' => '变更调价应退差额：change#'.$change->id,
                'evidence' => SceneWorkflow::json([]),
                'recorded_by' => $change->reviewed_by ?: 1,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } elseif ($priceDelta > 0) {
            $snapshot['commercial']['additional_receivable_minor'] = (int) ($snapshot['commercial']['additional_receivable_minor'] ?? 0) + $priceDelta;
            $this->flow->record('admin', $change->reviewed_by ?: 1, 'scene_project', $project->id, (int) $project->row_version, 'scene.project.receivable_increased', [
                'change_id' => $change->id,
                'price_delta_minor' => $priceDelta,
            ]);
        }

        DB::table('scene_projects')->where('id', $project->id)->update([
            'snapshot' => SceneWorkflow::json($snapshot),
        ]);
    }

    public function processCancellation(int $projectId, int $changeId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'decision' => 'required|in:approve,reject',
            'penalty_ratio' => 'prohibited',
            'note' => 'required|string|min:3|max:2000',
        ])->validate();

        return DB::transaction(function () use ($projectId, $changeId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (in_array($project->state, ['closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已处于终态，不可取消。', 409);
            }
            $change = SceneWorkflow::requireRow('scene_project_changes', $changeId);
            if ((int) $change->project_id !== $projectId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '变更/取消记录不存在。', 404);
            }
            if ($change->kind !== 'cancel' || $change->state !== 'requested') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '取消申请未处于待审核状态。', 409);
            }

            if ($data['decision'] === 'reject') {
                DB::table('scene_project_changes')->where('id', $changeId)->update([
                    'state' => 'rejected',
                    'reviewed_by' => $actor,
                    'reviewed_at' => now('UTC'),
                    'review_note' => $data['note'],
                    'updated_at' => now('UTC'),
                ]);
                DB::table('scene_projects')->where('id', $projectId)->update([
                    'row_version' => $expected + 1,
                    'updated_at' => now('UTC'),
                ]);
                $this->flow->record('admin', $actor, 'scene_project_change', $changeId, 1, 'scene.project.cancel.rejected');

                return ['id' => $changeId, 'state' => 'rejected', 'project_row_version' => $expected + 1];
            }

            $snapshot = SceneWorkflow::unpack($project->snapshot);
            $policy = app(SceneCancellationPolicy::class)->frozen($snapshot);
            $serviceStartsAt = $snapshot['schedule']['service_starts_at'] ?? $snapshot['schedule']['starts_at'] ?? null;
            if (! $serviceStartsAt) {
                throw new SceneWorkflowException('SCENE_CANCELLATION_POLICY_INVALID', '项目未冻结服务开始时间，不能计算取消责任。', 409);
            }
            if (DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', 'refund_due')->whereNull('reviewed_at')->exists()) {
                throw new SceneWorkflowException('SCENE_REFUND_REVIEW_PENDING', '已有待独立复核的应退记录，请先核实原退款责任再计算取消差额。', 409);
            }
            $sum = fn ($kind) => (int) DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', $kind)->whereNotNull('reviewed_at')->sum('amount_minor');
            $paid = $sum('payment_receipt') + (int) ($snapshot['commercial']['paid_minor'] ?? 0);
            $approvedDue = $sum('refund_due');
            $refunded = $sum('refund_receipt');
            if ($refunded > $approvedDue || $approvedDue > $paid) {
                throw new SceneWorkflowException('SCENE_REFUND_LEDGER_CONFLICT', '已核准应退、实际退款和收款不一致，须先复核原账目。', 409);
            }
            $penalty = app(SceneCancellationPolicy::class)->penalty($policy, $serviceStartsAt, $paid);
            $totalRefundDue = $paid - $penalty;
            if ($approvedDue > $totalRefundDue) {
                throw new SceneWorkflowException('SCENE_CANCELLATION_SETTLEMENT_CONFLICT', '既有已核准应退超过本次取消结算范围，请人工协商责任差额。', 409);
            }
            // 应退是责任，实退是其结清事实；同一笔金额只从累计应退责任中扣除一次。
            $refundDue = $totalRefundDue - $approvedDue;

            $hasDispatched = DB::table('scene_project_tasks')
                ->where('project_id', $projectId)
                ->where('task_key', 'dispatch')
                ->where('state', 'done')
                ->exists();
            $equipmentOutside = (! in_array($project->state, ['preparing', 'closed', 'cancelled'], true) || $hasDispatched) && ! $project->returned;
            $hasDispute = (bool) $project->open_dispute;

            if ($equipmentOutside || $hasDispute) {
                // 实物尚未返库或存在争议，锁定为维护状态并生成返库待办，不能直接 released 预留
                $reservations = DB::table('scene_reservations')
                    ->where('quote_id', $project->quote_id)
                    ->whereIn('state', ['held', 'confirmed'])
                    ->get();
                foreach ($reservations as $res) {
                    $rObj = SceneWorkflow::requireRow('scene_resources', (int) $res->resource_id);
                    if (in_array($rObj->kind, ['robot', 'accessory'], true)) {
                        DB::table('scene_resources')->where('id', $rObj->id)->update([
                            'state' => 'maintenance',
                            'state_reason' => '项目取消但实物尚未返库，锁定维护直至返库核验',
                            'row_version' => $rObj->row_version + 1,
                            'updated_at' => now('UTC'),
                        ]);
                        $this->flow->record('admin', $actor, 'scene_resource', (int) $rObj->id, $rObj->row_version + 1, 'scene.resource.paused');
                    }
                }
                DB::table('scene_operation_todos')->insertOrIgnore([
                    'dedupe_key' => 'cancellation-return:'.$projectId,
                    'request_id' => $project->request_id,
                    'kind' => 'return_damage_review',
                    'state' => 'open',
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            } else {
                DB::table('scene_reservations')->where('quote_id', $project->quote_id)
                    ->whereIn('state', ['held', 'confirmed'])
                    ->update([
                        'state' => 'released',
                        'updated_at' => now('UTC'),
                    ]);
            }

            if ($refundDue > 0) {
                DB::table('scene_commercial_records')->insert([
                    'project_id' => $projectId,
                    'kind' => 'refund_due',
                    'amount_minor' => $refundDue,
                    'reference' => '取消政策 '.$policy['version'].' 应退差额：change#'.$changeId,
                    'evidence' => SceneWorkflow::json([]),
                    'recorded_by' => $actor,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            $snapshot['cancellation_settlement'] = ['change_id' => $changeId, 'policy' => $policy, 'paid_minor' => $paid,
                'approved_refund_due_minor' => $approvedDue, 'refunded_minor' => $refunded, 'penalty_minor' => $penalty, 'additional_refund_due_minor' => $refundDue];
            DB::table('scene_projects')->where('id', $projectId)->update([
                'state' => 'cancelled',
                'snapshot' => SceneWorkflow::json($snapshot),
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            DB::table('scene_project_changes')->where('id', $changeId)->update([
                'state' => 'approved',
                'reviewed_by' => $actor,
                'reviewed_at' => now('UTC'),
                'review_note' => $data['note'],
                'price_delta_minor' => -$refundDue,
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.cancelled', [
                'change_id' => $changeId,
                'refund_due_minor' => $refundDue,
                'penalty_minor' => $penalty,
            ]);

            return [
                'id' => $changeId,
                'state' => 'approved',
                'project_state' => 'cancelled',
                'refund_due_minor' => $refundDue,
                'penalty_minor' => $penalty,
                'project_row_version' => $expected + 1,
            ];
        }, 3);
    }

    public function reportIncident(int $projectId, int $expected, array $input, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');
        $data = Validator::make($input, [
            'incident_ref' => 'required|string|max:80',
            'reason' => 'required|string|max:2000',
            'pause_project' => 'required|boolean',
        ])->validate();

        return DB::transaction(function () use ($projectId, $expected, $data, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if (in_array($project->state, ['closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目已终结，无法关联现场事故。', 409);
            }

            $patch = [
                'incident_ref' => $data['incident_ref'],
                'open_dispute' => true,
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ];

            if ($data['pause_project'] && $project->state !== 'paused') {
                $patch['paused_from_state'] = $project->state;
                $patch['state'] = 'paused';

                $reservations = DB::table('scene_reservations')->where('quote_id', $project->quote_id)->where('state', 'confirmed')->get();
                foreach ($reservations as $res) {
                    $resource = DB::table('scene_resources')->where('id', $res->resource_id)->first();
                    if ($resource && in_array($resource->kind, ['robot', 'accessory'], true)) {
                        DB::table('scene_resources')->where('id', $resource->id)->update([
                            'state' => 'maintenance',
                            'state_reason' => '现场事故暂停：'.$data['incident_ref'].' - '.$data['reason'],
                            'row_version' => $resource->row_version + 1,
                            'updated_at' => now('UTC'),
                        ]);
                        $this->flow->record('admin', $actor, 'scene_resource', (int) $resource->id, $resource->row_version + 1, 'scene.resource.paused');
                    }
                }
            }

            DB::table('scene_projects')->where('id', $projectId)->update($patch);

            DB::table('scene_operation_todos')->insertOrIgnore([
                'dedupe_key' => 'incident:'.$projectId.':'.$data['incident_ref'],
                'request_id' => $project->request_id,
                'kind' => 'incident_review',
                'state' => 'open',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.incident.reported', [
                'incident_ref' => $data['incident_ref'],
            ]);

            return [
                'id' => $projectId,
                'incident_ref' => $data['incident_ref'],
                'state' => $patch['state'] ?? $project->state,
                'row_version' => $expected + 1,
            ];
        }, 3);
    }

    public function resumeProject(int $projectId, int $expected, int $actor): array
    {
        $this->flow->enabled('project_actions_enabled');

        return DB::transaction(function () use ($projectId, $expected, $actor) {
            $project = SceneWorkflow::requireRow('scene_projects', $projectId);
            SceneWorkflow::version($project, $expected);
            if ($project->state !== 'paused') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '项目未处于暂停状态。', 409);
            }

            $next = $project->paused_from_state ?: 'delivering';

            DB::table('scene_projects')->where('id', $projectId)->update([
                'state' => $next,
                'paused_from_state' => null,
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            $this->flow->record('admin', $actor, 'scene_project', $projectId, $expected + 1, 'scene.project.resumed', [
                'resumed_to' => $next,
            ]);

            return [
                'id' => $projectId,
                'state' => $next,
                'row_version' => $expected + 1,
            ];
        }, 3);
    }

    private function attachmentEvidence(int $requestId, array $ids): array
    {
        $rows = DB::table('scene_attachments')->where('request_id', $requestId)->whereIn('id', $ids)->where('state', 'clean')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->get(['id', 'sha256']);
        if ($rows->count() !== count($ids)) {
            throw new SceneWorkflowException('SCENE_EVIDENCE_INVALID', '证据附件未扫描通过、已过期或不属于本项目需求。');
        }

        return $rows->map(fn ($r) => ['attachment_id' => (int) $r->id, 'sha256' => $r->sha256])->all();
    }
}
