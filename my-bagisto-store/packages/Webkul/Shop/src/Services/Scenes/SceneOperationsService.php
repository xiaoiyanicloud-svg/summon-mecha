<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SceneOperationsService
{
    private const EVENTS = [
        'scene' => ['scene.revision.review', 'scene.revision.approve', 'scene.revision.publish', 'scene.revision.withdraw', 'scene.availability.changed'],
        'scene_revision' => ['scene.revision.drafted'],
        'scene_request' => ['scene.request.submitted', 'scene.request.supplemented', 'scene.request.claimed', 'scene.request.assign', 'scene.request.contact', 'scene.request.close', 'scene.assessment.created', 'scene.survey.recorded', 'scene.attachment.added', 'scene.request.contact.overdue', 'scene.request.contact.escalated'],
        'scene_policy' => ['scene.policy.drafted', 'scene.policy.approve', 'scene.policy.reject', 'scene.policy.revoke'],
        'scene_content_evidence' => ['scene.content-evidence.created', 'scene.content-evidence.approve', 'scene.content-evidence.revoke'],
        'scene_proposal' => ['scene.proposal.drafted', 'scene.proposal.approve', 'scene.proposal.return'],
        'scene_quote' => ['scene.quote.drafted', 'scene.quote.review', 'scene.quote.approve', 'scene.quote.return', 'scene.quote.send', 'scene.quote.void', 'scene.quote.accepted', 'scene.quote.rejected', 'scene.quote.invalidated', 'scene.resources.held', 'scene.resources.released', 'scene.quote.commercial.recorded', 'scene.quote.commercial.reviewed'],
        'scene_price' => ['scene.price.created', 'scene.price.revoked'],
        'scene_evidence' => ['scene.evidence.recorded', 'scene.evidence.approved', 'scene.evidence.revoked'],
        'scene_resource' => ['scene.resource.registered', 'scene.resource.verified', 'scene.resource.paused', 'scene.resources.expired'],
        'scene_sku' => ['scene.sku.registered', 'scene.sku.approve', 'scene.sku.revoke'],
        'scene_project' => [
            'scene.project.tasks.initialized', 'scene.project.task.completed',
            'scene.project.acceptance.recorded', 'scene.project.offline-acceptance.recorded',
            'scene.project.offline-acceptance.reviewed', 'scene.project.change.requested',
            'scene.project.cancel.requested', 'scene.project.after_sale.requested',
            'scene.commercial.recorded', 'scene.commercial.reviewed',
            'scene.project.returned', 'scene.order.converted', 'scene.skill.fulfillment.pending',
            'scene.project.cancelled', 'scene.project.incident.reported', 'scene.project.resumed', 'scene.project.receivable_increased',
        ],
        'scene_project_change' => [
            'scene.project.change.evaluated', 'scene.project.change.rejected',
            'scene.project.change.approved', 'scene.project.change.declined',
            'scene.project.change.applied', 'scene.project.cancel.rejected',
        ],
        'scene_service_ticket' => ['scene.service-ticket.resolved'],
        'scene_attachment' => ['scene.attachment.held', 'scene.attachment.hold-released', 'scene.attachment.revoked', 'scene.attachment.deletion-requested', 'scene.attachment.deleted'],
        'scene_todo' => ['scene.todo.assigned', 'scene.todo.resolved'],
    ];

    public function __construct(protected SceneWorkflow $flow) {}

    /** 租约只保护投递；业务投影、receipt 与 processed 标记在同一事务提交。 */
    public function consumeOne(): array
    {
        $this->flow->enabled('operations_enabled');
        $lease = DB::transaction(function () {
            $row = DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->where('attempts', '<', 8)->where('available_at', '<=', now('UTC'))
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now('UTC')))->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $token = (string) Str::uuid();
            DB::table('business_outbox')->where('id', $row->id)->update(['lease_token' => $token, 'lease_until' => now('UTC')->addSeconds(60), 'attempts' => $row->attempts + 1, 'updated_at' => now('UTC')]);

            return ['id' => $row->id, 'token' => $token, 'attempts' => $row->attempts + 1];
        }, 3);
        if (! $lease) {
            return ['state' => 'idle'];
        }
        try {
            return DB::transaction(function () use ($lease) {
                $row = DB::table('business_outbox')->where('id', $lease['id'])->where('lease_token', $lease['token'])->whereNull('processed_at')->lockForUpdate()->first();
                if (! $row || $row->lease_until <= now('UTC')) {
                    return ['state' => 'lease_lost'];
                }
                if (! in_array($row->event_type, self::EVENTS[$row->aggregate_type] ?? [], true)) {
                    throw new SceneWorkflowException('OUTBOX_CONSUMER_UNSUPPORTED', '未配置此事件的消费者。');
                }
                $payload = SceneWorkflow::unpack($row->payload);
                if ((int) $row->schema_version !== 1 || ! is_array($payload)
                    || ($payload['id'] ?? null) !== (int) $row->aggregate_id || ($payload['version'] ?? null) !== (int) $row->aggregate_seq) {
                    throw new SceneWorkflowException('OUTBOX_SCHEMA_UNSUPPORTED', '事件版本或对象引用不符合当前契约。');
                }
                $receipt = ['consumer' => 'scene.operations.v1', 'event_id' => $row->event_id];
                if (! DB::table('business_event_receipts')->where($receipt)->exists()) {
                    // 发布指针只由同步发布命令写入，旧事件重放绝不恢复已撤版本。
                    if ($row->aggregate_type === 'scene_request') {
                        $request = DB::table('scene_requests')->where('id', $row->aggregate_id)->first();
                        if ($request && ! $request->assignee_id && ! in_array($request->state, ['closed', 'cancelled', 'converted'], true)) {
                            $this->todo('unassigned:'.$request->id, (int) $request->id, 'unassigned_request');
                        }
                        if (in_array($row->event_type, ['scene.request.contact.overdue', 'scene.request.contact.escalated'], true)) {
                            $clock = DB::table('scene_service_clocks')->where('request_id', $row->aggregate_id)->first();
                            $active = $request && ! in_array($request->state, ['closed', 'cancelled', 'converted'], true) && $clock?->state === 'awaiting_contact';
                            $recipient = $active ? (int) ($row->event_type === 'scene.request.contact.overdue' ? $clock->assignee_id : $clock->escalation_assignee_id) : null;
                            if ($active) {
                                if (! $recipient || ! DB::table('admins')->where('id', $recipient)->where('status', 1)->exists()) {
                                    throw new SceneWorkflowException('SCENE_NOTIFICATION_RECIPIENT_INVALID', '提醒负责人无效，须重新分派。', 409);
                                }
                                app(\Webkul\Shop\Services\NotificationService::class)->send('admin', $recipient, $row->event_type, '场景需求联系待处理', '请进入场景服务工作台核实实际联系进展。', ['request_id' => (int) $row->aggregate_id]);
                            }
                        }
                    }
                    if ($row->aggregate_type === 'scene_quote') {
                        $quote = DB::table('scene_quotes')->where('id', $row->aggregate_id)->first();
                        if ($quote) {
                            $this->quoteTodo($quote);
                        }
                    }
                    if ($row->aggregate_type === 'scene_project' && isset($payload['confirmation_id'])) {
                        if (! is_int($payload['confirmation_id'])) {
                            throw new SceneWorkflowException('OUTBOX_SCHEMA_UNSUPPORTED', '线下确认引用类型无效。');
                        }
                        $this->offlineTodo($payload['confirmation_id'], (int) $row->aggregate_id);
                    }
                    if ($row->aggregate_type === 'scene_project' && in_array($row->event_type, ['scene.skill.fulfillment.pending', 'scene.commercial.reviewed'], true)) {
                        app(SceneSkillFulfillmentService::class)->fulfill((int) $row->aggregate_id);
                    }
                    if ($row->event_type === 'scene.order.converted') {
                        $p = DB::table('scene_projects')->where('id', $row->aggregate_id)->first();
                        $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                        if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                            app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.order.converted', '场景服务已确认', '请进入交付项目查看安排及款项事实。', ['project_id' => (int) $p->id]);
                        }
                    }
                    if ($row->event_type === 'scene.project.incident.reported') {
                        $p = DB::table('scene_projects')->where('id', $row->aggregate_id)->first();
                        $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                        if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                            app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.project.incident.reported', '项目现场安全暂停通知', '您的场景项目因现场事故已暂时置为安全暂停状态，现场团队正在排查处置。', ['project_id' => (int) $p->id, 'incident_ref' => $p->incident_ref ?? null]);
                        }
                    }
                    if ($row->event_type === 'scene.project.resumed') {
                        $p = DB::table('scene_projects')->where('id', $row->aggregate_id)->first();
                        $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                        if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                            app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.project.resumed', '项目现场恢复执行通知', '您的场景项目事故已排除完毕，现场服务已恢复正常推进。', ['project_id' => (int) $p->id]);
                        }
                    }
                    if ($row->event_type === 'scene.project.cancelled') {
                        $p = DB::table('scene_projects')->where('id', $row->aggregate_id)->first();
                        $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                        if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                            app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.project.cancelled', '场景项目取消已确认', '您的场景项目已依据取消政策处理完毕，相关应退款项已生成并进入财务流程。', ['project_id' => (int) $p->id]);
                        }
                    }
                    if ($row->event_type === 'scene.project.change.approved') {
                        $change = DB::table('scene_project_changes')->where('id', $row->aggregate_id)->first();
                        if ($change && ($payload['requires_customer_acceptance'] ?? false)) {
                            $p = DB::table('scene_projects')->where('id', $change->project_id)->first();
                            $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                            if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                                app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.project.change.pending_acceptance', '工程变更待您确认', '您的项目已生成变更方案及调价，请进入项目详情查阅并确认。', ['project_id' => (int) $p->id, 'change_id' => (int) $change->id]);
                            }
                        }
                    }
                    if ($row->event_type === 'scene.service-ticket.resolved') {
                        $ticket = DB::table('scene_service_tickets')->where('id', $row->aggregate_id)->first();
                        if ($ticket) {
                            $p = DB::table('scene_projects')->where('id', $ticket->project_id)->first();
                            $r = $p ? DB::table('scene_requests')->where('id', $p->request_id)->first() : null;
                            if ($r?->customer_id && class_exists(\Webkul\Shop\Services\NotificationService::class) && Schema::hasTable('notifications')) {
                                app(\Webkul\Shop\Services\NotificationService::class)->send('customer', (int) $r->customer_id, 'scene.service-ticket.resolved', '售后工单已处理完毕', '您提交的售后报障工单已处理，处理结论：'.$ticket->resolution, ['project_id' => (int) $p->id, 'ticket_id' => (int) $ticket->id]);
                            }
                        }
                    }
                    DB::table('business_event_receipts')->insert($receipt + ['created_at' => now('UTC')]);
                }
                DB::table('business_outbox')->where('id', $row->id)->where('lease_token', $lease['token'])->update(['processed_at' => now('UTC'), 'lease_until' => null, 'lease_token' => null, 'last_error_code' => null, 'updated_at' => now('UTC')]);

                return ['state' => 'processed', 'event_id' => $row->event_id];
            }, 3);
        } catch (\Throwable $e) {
            DB::table('business_outbox')->where('id', $lease['id'])->where('lease_token', $lease['token'])->update([
                'lease_until' => null, 'lease_token' => null, 'available_at' => now('UTC')->addSeconds(min(3600, 2 ** $lease['attempts'] * 10)),
                'last_error_code' => $e instanceof SceneWorkflowException ? $e->errorCode : 'OUTBOX_PROJECTION_FAILED', 'updated_at' => now('UTC'),
            ]);

            return ['state' => 'failed', 'id' => $lease['id']];
        }
    }

    public function sweep(): array
    {
        $this->flow->enabled('operations_enabled');
        $expired = app(SceneReservationService::class)->expire();
        DB::table('scene_requests')->whereNull('assignee_id')->whereNotIn('state', ['closed', 'cancelled', 'converted'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $request) {
                $this->todo('unassigned:'.$request->id, (int) $request->id, 'unassigned_request');
            }
        });
        DB::table('scene_operation_todos')->where('kind', 'unassigned_request')->where('state', 'open')
            ->whereIn('request_id', DB::table('scene_requests')->whereNotNull('assignee_id')->orWhereIn('state', ['closed', 'cancelled', 'converted'])->select('id'))
            ->update(['state' => 'resolved', 'resolution_code' => 'request_routed_or_closed', 'updated_at' => now('UTC')]);
        DB::table('scene_quotes')->where('state', 'accepted')->orderBy('id')->chunkById(100, function ($quotes) {
            foreach ($quotes as $quote) {
                DB::transaction(function () use ($quote) {
                    $request = SceneWorkflow::requireRow('scene_requests', (int) $quote->request_id);
                    $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $quote->assessment_id, false);
                    try {
                        app(SceneAssessmentService::class)->assertCurrent($assessment, $request, (int) $assessment->channel_id);
                    } catch (SceneWorkflowException) {
                        $this->todo('qualification:quote:'.$quote->id, (int) $quote->request_id, 'accepted_qualification_invalid');
                    }
                }, 3);
            }
        });
        DB::table('scene_quotes')->whereIn('state', ['returned', 'rejected'])->orderBy('id')->chunkById(100, function ($quotes) {
            foreach ($quotes as $quote) {
                $this->quoteTodo($quote);
            }
        });
        DB::table('scene_offline_acceptances')->where('state', 'pending_review')->orderBy('id')->chunkById(100, function ($records) {
            foreach ($records as $record) {
                $this->offlineTodo((int) $record->id);
            }
        });

        $contacts = app(SceneIntakeClockService::class)->scan();

        return ['expired_holds' => $expired, 'contacts' => $contacts, 'open_todos' => DB::table('scene_operation_todos')->where('state', 'open')->count()];
    }

    public function replay(int $id, int $actor): array
    {
        $this->flow->enabled('operations_enabled');

        return DB::transaction(function () use ($id, $actor) {
            $row = SceneWorkflow::requireRow('business_outbox', $id);
            if (! str_starts_with($row->event_type, 'scene.')) {
                throw new SceneWorkflowException('OUTBOX_SCOPE_FORBIDDEN', '场景工作台不能重放其他业务的事件。', 403);
            }
            if ($row->processed_at || ($row->lease_until && $row->lease_until > now('UTC'))) {
                throw new SceneWorkflowException('OUTBOX_REPLAY_CONFLICT', '事件已完成或正被处理。', 409);
            }
            app(\Webkul\Shop\Services\Ops\OperationLogger::class)->logOrFail('admin', $actor, 'scene.outbox.replayed', 'business_outbox', $id, []);
            DB::table('business_outbox')->where('id', $id)->update(['attempts' => 0, 'available_at' => now('UTC'), 'lease_until' => null, 'lease_token' => null, 'last_error_code' => null, 'updated_at' => now('UTC')]);

            return ['id' => $id, 'state' => 'pending'];
        }, 3);
    }

    public function metrics(): array
    {
        $real = DB::table('scene_requests')->where('is_test', false)->where('source', '!=', 'legacy_custom');
        $realIds = (clone $real)->pluck('id')->all();

        // 1. 漏斗与需求 (Funnel)
        $requestsCount = count($realIds);
        $assignedCount = (clone $real)->whereNotNull('assignee_id')->count();
        $contactedCount = DB::table('scene_service_clocks')->whereIn('request_id', $realIds)->where('state', 'contacted')->count();
        $overdueCount = DB::table('scene_service_clocks')->whereIn('request_id', $realIds)
            ->where(fn ($q) => $q->where('state', 'overdue')->orWhere(fn ($sub) => $sub->whereNotNull('contacted_at')->whereRaw('contacted_at > due_at')))->count();
        $convertedCount = (clone $real)->where('state', 'converted')->count();
        $conversionRate = $requestsCount > 0 ? round($convertedCount / $requestsCount, 4) : 0.0;

        // 2. 报价与接受 (Quotes)
        $quotesQuery = DB::table('scene_quotes')->whereIn('request_id', $realIds);
        $totalQuotes = (clone $quotesQuery)->count();
        $acceptedQuoteIds = (clone $quotesQuery)->pluck('id')->all();
        $acceptedQuotesCount = DB::table('scene_quote_acceptances')->whereIn('quote_id', $acceptedQuoteIds)->count();
        $totalAcceptedMinor = (int) (clone $quotesQuery)->where('state', 'accepted')->sum('total_minor');
        $avgQuoteMinor = $acceptedQuotesCount > 0 ? (int) round($totalAcceptedMinor / $acceptedQuotesCount) : 0;
        $quoteAcceptanceRate = $totalQuotes > 0 ? round($acceptedQuotesCount / $totalQuotes, 4) : 0.0;

        // 3. 交付履约 (Delivery)
        $projectsQuery = DB::table('scene_projects')->whereIn('request_id', $realIds);
        $confirmedProjectsCount = (clone $projectsQuery)->whereNotIn('state', ['cancelled'])->count();
        $projectsByState = (clone $projectsQuery)->select('state', DB::raw('count(*) as count'))->groupBy('state')->pluck('count', 'state')->all();
        $pausedIncidentProjects = (clone $projectsQuery)->where(fn ($q) => $q->where('state', 'paused')->orWhereNotNull('incident_ref'))->count();

        $projectIds = (clone $projectsQuery)->pluck('id')->all();
        $tasksQuery = DB::table('scene_project_tasks')->whereIn('project_id', $projectIds);
        $totalTasks = (clone $tasksQuery)->count();
        $completedTasks = (clone $tasksQuery)->where('state', 'done')->count();
        $taskCompletionRate = $totalTasks > 0 ? round($completedTasks / $totalTasks, 4) : 0.0;

        // 4. 质量与验收 (Quality & Acceptance)
        $acceptanceRecords = DB::table('scene_acceptance_records')->whereIn('project_id', $projectIds);
        $totalAcceptanceAttempts = (clone $acceptanceRecords)->count();
        $acceptanceItems = (clone $acceptanceRecords)->select('project_id', 'item_key')
            ->selectRaw("MAX(CASE WHEN attempt = 1 AND result = 'pass' THEN 1 ELSE 0 END) AS first_pass")
            ->groupBy('project_id', 'item_key')->get();
        $firstPassAcceptanceCount = (int) $acceptanceItems->sum('first_pass');
        $uniqueAcceptanceItems = $acceptanceItems->count();
        $firstPassRate = $uniqueAcceptanceItems > 0 ? round($firstPassAcceptanceCount / $uniqueAcceptanceItems, 4) : 0.0;
        $rectificationCount = DB::table('scene_operation_todos')->whereIn('request_id', $realIds)->where('kind', 'acceptance_rectification')->count();

        // 5. 财务真实账目 (Financial Audit)
        $financial = app(SceneFinancialMetrics::class)->summarize($acceptedQuoteIds, (clone $projectsQuery)->get()->all());

        // 6. 实物资源 (Physical Resources)
        $totalResources = DB::table('scene_resources')->count();
        $resourcesByState = DB::table('scene_resources')->select('state', DB::raw('count(*) as count'))->groupBy('state')->pluck('count', 'state')->all();
        $activeReservations = DB::table('scene_reservations')->where('occupies_until', '>', now('UTC'))->whereIn('state', ['held', 'confirmed'])->count();

        // 7. 售后与工单 (Service Tickets)
        $ticketsQuery = DB::table('scene_service_tickets')->whereIn('project_id', $projectIds);
        $totalTickets = (clone $ticketsQuery)->count();
        $ticketsByScope = (clone $ticketsQuery)->select('scope', DB::raw('count(*) as count'))->groupBy('scope')->pluck('count', 'scope')->all();
        $resolvedTickets = (clone $ticketsQuery)->where('state', 'resolved')->count();
        $totalTicketRefundMinor = (int) (clone $ticketsQuery)->sum('refund_proposal_minor');

        // 8. 队列待办 (Queue & Todos)
        $openTodos = DB::table('scene_operation_todos')->where('state', 'open')->count();
        $outboxPending = DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->count();
        $outboxFailed = DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->where('attempts', '>=', 8)->count();

        return [
            'requests' => $requestsCount,
            'accepted_quotes' => $acceptedQuotesCount,
            'confirmed_projects' => $confirmedProjectsCount,
            'open_todos' => $openTodos,
            'outbox_pending' => $outboxPending,
            'outbox_failed' => $outboxFailed,
            'worker_status' => 'requires_process_verification',
            'sla_status' => app(ScenePolicyService::class)->effective('service_calendar') ? 'calendar_approved' : 'calendar_not_configured',
            'funnel' => [
                'total_requests' => $requestsCount,
                'assigned_requests' => $assignedCount,
                'contacted_requests' => $contactedCount,
                'overdue_contact_requests' => $overdueCount,
                'converted_requests' => $convertedCount,
                'conversion_rate' => $conversionRate,
            ],
            'quotes' => [
                'total_quotes' => $totalQuotes,
                'accepted_quotes' => $acceptedQuotesCount,
                'quote_acceptance_rate' => $quoteAcceptanceRate,
                'total_accepted_minor' => $totalAcceptedMinor,
                'avg_quote_minor' => $avgQuoteMinor,
            ],
            'delivery' => [
                'confirmed_projects' => $confirmedProjectsCount,
                'projects_by_state' => $projectsByState,
                'paused_incident_projects' => $pausedIncidentProjects,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'task_completion_rate' => $taskCompletionRate,
            ],
            'quality' => [
                'total_acceptance_attempts' => $totalAcceptanceAttempts,
                'first_pass_acceptance_count' => $firstPassAcceptanceCount,
                'unique_acceptance_items' => $uniqueAcceptanceItems,
                'first_pass_rate' => $firstPassRate,
                'rectification_count' => $rectificationCount,
            ],
            'financial' => $financial,
            'resources' => [
                'total_resources' => $totalResources,
                'resources_by_state' => $resourcesByState,
                'active_reservations' => $activeReservations,
            ],
            'tickets' => [
                'total_tickets' => $totalTickets,
                'tickets_by_scope' => $ticketsByScope,
                'resolved_tickets' => $resolvedTickets,
                'total_ticket_refund_minor' => $totalTicketRefundMinor,
            ],
            'definitions' => [
                'requests' => '非测试且非旧线索适配的独立需求数',
                'accepted_quotes' => '客户接受事实，非订单成交或收款',
                'confirmed_projects' => '已建立项目，非验收或收款',
                'conversion_rate' => '已转换项目数 / 独立真实需求总数',
                'first_pass_rate' => '按 project_id + item_key 去重：初验通过项数 / 已有验收事实的独立项目验收项数；复验通过不算初验通过',
                'financial' => '整数分；已独立复核的报价收款与项目收款按同类凭证去重，冻结快照不重复叠加；收款含押金，不等于收入。已核准应退、待复核应退、实际退款分别汇总；净现金仅减实退，差异标记待核账',
                'clean_data' => '严格排除 is_test=true 与 source=legacy_custom 记录',
            ],
        ];
    }

    public function todoList(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->flow->enabled('operations_enabled');
        $query = DB::table('scene_operation_todos')->orderByDesc('id');
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['state'])) {
            $query->where('state', $filters['state']);
        } else {
            $query->where('state', 'open');
        }
        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', (int) $filters['assignee_id']);
        }
        if (! empty($filters['request_id'])) {
            $query->where('request_id', (int) $filters['request_id']);
        }
        $total = (clone $query)->count();
        $items = $query->limit(min(100, max(1, $limit)))->offset(max(0, $offset))->get()->all();

        return [
            'total' => $total,
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function resolveTodo(int $id, int $expected, string $resolutionCode, string $note, int $actor): array
    {
        $this->flow->enabled('operations_enabled');
        if (trim($resolutionCode) === '' || trim($note) === '') {
            throw new SceneWorkflowException('SCENE_TODO_RESOLVE_INVALID', '必须填写解决编码与处理说明。', 422);
        }

        return DB::transaction(function () use ($id, $expected, $resolutionCode, $note, $actor) {
            $todo = SceneWorkflow::requireRow('scene_operation_todos', $id);
            SceneWorkflow::version($todo, $expected);
            if ($todo->state !== 'open') {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '待办已经处理。', 409);
            }

            DB::table('scene_operation_todos')->where('id', $id)->update([
                'state' => 'resolved',
                'resolution_code' => $resolutionCode,
                'row_version' => $expected + 1,
                'updated_at' => now('UTC'),
            ]);

            if ($todo->request_id) {
                DB::table('scene_activities')->insert([
                    'request_id' => $todo->request_id,
                    'kind' => 'todo_resolved',
                    'actor_type' => 'admin',
                    'actor_id' => $actor,
                    'note' => '待办 #'.$id.' ['.$todo->kind.'] 已解决：'.$note.' ('.$resolutionCode.')',
                    'visibility' => 'internal',
                    'occurred_at' => now('UTC'),
                    'created_at' => now('UTC'),
                ]);
            }

            $this->flow->record('admin', $actor, 'scene_todo', $id, $expected + 1, 'scene.todo.resolved', [
                'resolution_code' => $resolutionCode,
            ]);

            return [
                'id' => $id,
                'state' => 'resolved',
                'resolution_code' => $resolutionCode,
                'row_version' => $expected + 1,
            ];
        }, 3);
    }

    private function todo(string $key, int $requestId, string $kind): void
    {
        DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => $key, 'request_id' => $requestId, 'kind' => $kind,
            'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function assignTodo(int $id, int $expected, int $assignee, string $reason, int $actor): array
    {
        $this->flow->enabled('operations_enabled');

        return DB::transaction(function () use ($id, $expected, $assignee, $reason, $actor) {
            $ref = SceneWorkflow::requireRow('scene_operation_todos', $id, false);
            if ($ref->request_id) {
                SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            }
            $todo = SceneWorkflow::requireRow('scene_operation_todos', $id);
            SceneWorkflow::version($todo, $expected);
            if ($todo->state !== 'open' || ! DB::table('admins')->where('id', $assignee)->where('status', 1)->exists() || mb_strlen(trim($reason)) < 3) {
                throw new SceneWorkflowException('SCENE_TODO_ASSIGN_INVALID', '待办已处理、负责人无效或缺少转派依据。', 409);
            }
            DB::table('scene_operation_todos')->where('id', $id)->update(['assignee_id' => $assignee, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            if (in_array($todo->kind, ['contact_overdue', 'contact_escalation'], true)) {
                DB::table('scene_service_clocks')->where('request_id', $todo->request_id)->where('state', 'awaiting_contact')->update([
                    ($todo->kind === 'contact_overdue' ? 'assignee_id' : 'escalation_assignee_id') => $assignee, 'updated_at' => now('UTC')]);
            }
            if ($todo->request_id) {
                DB::table('scene_activities')->insert(['request_id' => $todo->request_id, 'kind' => 'todo_assigned', 'actor_type' => 'admin', 'actor_id' => $actor, 'note' => $reason, 'visibility' => 'internal', 'occurred_at' => now('UTC'), 'created_at' => now('UTC')]);
            }
            $this->flow->record('admin', $actor, 'scene_todo', $id, $expected + 1, 'scene.todo.assigned', ['assignee_id' => $assignee]);

            return ['id' => $id, 'state' => 'open', 'row_version' => $expected + 1];
        }, 3);
    }

    private function quoteTodo(object $quote): void
    {
        if (! in_array($quote->state, ['rejected', 'returned'], true)) {
            return;
        }
        $request = DB::table('scene_requests')->where('id', $quote->request_id)->first();
        $key = 'quote-rework:'.$quote->id;
        $done = ! $request || in_array($request->state, ['closed', 'cancelled', 'converted'], true)
            || (int) $request->revision_no !== (int) $quote->request_revision_no
            || DB::table('scene_quotes')->where('request_id', $quote->request_id)->where('version', '>', $quote->version)->where('customer_visible', true)->exists();
        if ($done) {
            DB::table('scene_operation_todos')->where('dedupe_key', $key)->where('state', 'open')
                ->update(['state' => 'resolved', 'resolution_code' => 'new_quote_or_request_changed', 'updated_at' => now('UTC')]);
        } else {
            $this->todo($key, (int) $quote->request_id, 'quote_revision_requested');
        }
    }

    private function offlineTodo(int $id, ?int $projectId = null): void
    {
        $record = DB::table('scene_offline_acceptances')->where('id', $id)->first();
        if (! $record) {
            return;
        }
        if ($projectId !== null && (int) $record->project_id !== $projectId) {
            throw new SceneWorkflowException('OUTBOX_SCHEMA_UNSUPPORTED', '线下确认引用不属于事件项目。');
        }
        $key = 'offline-acceptance:'.$id;
        if ($record->state === 'pending_review') {
            $project = SceneWorkflow::requireRow('scene_projects', (int) $record->project_id, false);
            $this->todo($key, (int) $project->request_id, 'offline_acceptance_review');
        } else {
            // 读取当前凭证事实；旧 recorded 事件重放不能恢复已复核的待办。
            DB::table('scene_operation_todos')->where('dedupe_key', $key)->where('state', 'open')->update([
                'state' => 'resolved', 'resolution_code' => 'offline_confirmation_'.$record->state, 'updated_at' => now('UTC'),
            ]);
        }
    }
}
