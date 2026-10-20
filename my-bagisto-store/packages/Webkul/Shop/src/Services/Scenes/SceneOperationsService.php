<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SceneOperationsService
{
    private const EVENTS = [
        'scene'                => ['scene.revision.review', 'scene.revision.approve', 'scene.revision.publish', 'scene.revision.withdraw', 'scene.availability.changed'],
        'scene_revision'       => ['scene.revision.drafted'],
        'scene_request'        => ['scene.request.submitted', 'scene.request.supplemented', 'scene.request.claimed', 'scene.request.assign', 'scene.request.contact', 'scene.request.close', 'scene.assessment.created', 'scene.attachment.added'],
        'scene_quote'          => ['scene.quote.drafted', 'scene.quote.review', 'scene.quote.approve', 'scene.quote.return', 'scene.quote.send', 'scene.quote.void', 'scene.quote.accepted', 'scene.quote.rejected', 'scene.quote.invalidated', 'scene.resources.held'],
        'scene_price'          => ['scene.price.created', 'scene.price.revoked'],
        'scene_evidence'       => ['scene.evidence.recorded', 'scene.evidence.revoked'],
        'scene_resource'       => ['scene.resource.verified', 'scene.resource.paused'],
        'scene_project'        => ['scene.project.tasks.initialized', 'scene.project.task.completed', 'scene.project.acceptance.recorded', 'scene.project.offline-acceptance.recorded', 'scene.project.offline-acceptance.reviewed', 'scene.project.change.requested', 'scene.project.cancel.requested', 'scene.project.after_sale.requested', 'scene.commercial.recorded', 'scene.commercial.reviewed', 'scene.project.returned'],
        'scene_service_ticket' => ['scene.service-ticket.resolved'],
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
                    DB::table('business_event_receipts')->insert($receipt + ['created_at' => now('UTC')]);
                }
                DB::table('business_outbox')->where('id', $row->id)->where('lease_token', $lease['token'])->update(['processed_at' => now('UTC'), 'lease_until' => null, 'lease_token' => null, 'last_error_code' => null, 'updated_at' => now('UTC')]);

                return ['state' => 'processed', 'event_id' => $row->event_id];
            }, 3);
        } catch (\Throwable $e) {
            DB::table('business_outbox')->where('id', $lease['id'])->where('lease_token', $lease['token'])->update([
                'lease_until'     => null, 'lease_token' => null, 'available_at' => now('UTC')->addSeconds(min(3600, 2 ** $lease['attempts'] * 10)),
                'last_error_code' => $e instanceof SceneWorkflowException ? $e->errorCode : 'OUTBOX_PROJECTION_FAILED', 'updated_at' => now('UTC'),
            ]);

            return ['state' => 'failed', 'id' => $lease['id']];
        }
    }

    public function sweep(): array
    {
        $this->flow->enabled('operations_enabled');
        $expired = DB::table('scene_reservations')->where('state', 'held')->where('hold_expires_at', '<=', now('UTC'))->update(['state' => 'expired', 'updated_at' => now('UTC')]);
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
                $assessment = DB::table('scene_assessments')->where('id', $quote->assessment_id)->first();
                foreach (SceneWorkflow::unpack($assessment?->candidate_snapshot) as $candidate) {
                    $e = DB::table('scene_evidence')->where('id', $candidate['evidence_id'] ?? 0)->first();
                    if (! $e || $e->revoked_at || $e->expires_at <= now('UTC')) {
                        $this->todo('qualification:quote:'.$quote->id, (int) $quote->request_id, 'accepted_qualification_invalid');
                    }
                }
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

        return ['expired_holds' => $expired, 'open_todos' => DB::table('scene_operation_todos')->where('state', 'open')->count()];
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
        $ids = (clone $real)->select('id');

        return [
            'requests'           => (clone $real)->count(),
            'accepted_quotes'    => DB::table('scene_quote_acceptances')->whereIn('quote_id', DB::table('scene_quotes')->whereIn('request_id', clone $ids)->select('id'))->count(),
            'confirmed_projects' => DB::table('scene_projects')->whereIn('request_id', clone $ids)->whereNotIn('state', ['cancelled'])->count(),
            'open_todos'         => DB::table('scene_operation_todos')->where('state', 'open')->count(),
            'outbox_pending'     => DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->count(),
            'outbox_failed'      => DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->where('attempts', '>=', 8)->count(),
            'definitions'        => ['requests' => '非测试且非旧线索适配的独立需求数', 'accepted_quotes' => '客户接受事实，非订单成交或收款', 'confirmed_projects' => '已建立项目，非验收或收款'],
            'worker_status'      => 'requires_process_verification', 'sla_status' => 'calendar_not_configured',
        ];
    }

    private function todo(string $key, int $requestId, string $kind): void
    {
        DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => $key, 'request_id' => $requestId, 'kind' => $kind,
            'state'                                                      => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
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
