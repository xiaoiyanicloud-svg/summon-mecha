<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SceneOperationsService
{
    public function __construct(protected SceneWorkflow $flow) {}

    /** 租约只保护投递；业务投影、receipt 与 processed 标记在同一事务提交。 */
    public function consumeOne(): array
    {
        $this->flow->enabled('operations_enabled');
        $lease = DB::transaction(function () {
            $row = DB::table('business_outbox')->whereNull('processed_at')->where('attempts', '<', 8)->where('available_at', '<=', now('UTC'))
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
                if (! str_starts_with($row->event_type, 'scene.')) {
                    throw new SceneWorkflowException('OUTBOX_CONSUMER_UNSUPPORTED', '未配置此事件的消费者。');
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

        return ['expired_holds' => $expired, 'open_todos' => DB::table('scene_operation_todos')->where('state', 'open')->count()];
    }

    public function replay(int $id, int $actor): array
    {
        $this->flow->enabled('operations_enabled');

        return DB::transaction(function () use ($id, $actor) {
            $row = SceneWorkflow::requireRow('business_outbox', $id);
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
            'outbox_pending'     => DB::table('business_outbox')->whereNull('processed_at')->count(),
            'outbox_failed'      => DB::table('business_outbox')->whereNull('processed_at')->where('attempts', '>=', 8)->count(),
            'definitions'        => ['requests' => '非测试且非旧线索适配的独立需求数', 'accepted_quotes' => '客户接受事实，非订单成交或收款', 'confirmed_projects' => '已建立项目，非验收或收款'],
            'worker_status'      => 'requires_process_verification', 'sla_status' => 'calendar_not_configured',
        ];
    }

    private function todo(string $key, int $requestId, string $kind): void
    {
        DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => $key, 'request_id' => $requestId, 'kind' => $kind,
            'state'                                                      => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
}
