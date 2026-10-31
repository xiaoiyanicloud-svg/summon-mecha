<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 建单前的商务事实；客户上传文件不能自行确认收款。 */
class SceneCommercialService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function record(int $quoteId, array $input, string $key, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'kind' => 'required|in:offline_contract,offline_receipt', 'amount_minor' => 'required|integer|min:0|max:1000000000000',
            'reference' => 'required|string|min:3|max:255', 'occurred_at' => 'required|string|max:40',
            'evidence_ids' => 'required|array|min:1|max:20', 'evidence_ids.*' => 'required|integer|distinct|min:1',
        ])->validate();
        $data['occurred_at'] = SceneTime::occurredAt($data['occurred_at']);
        $data['amount_minor'] = (int) $data['amount_minor'];
        sort($data['evidence_ids'], SORT_NUMERIC);
        if (($data['kind'] === 'offline_contract' && $data['amount_minor'] !== 0) || ($data['kind'] === 'offline_receipt' && $data['amount_minor'] < 1)) {
            throw new SceneWorkflowException('SCENE_COMMERCIAL_AMOUNT_INVALID', '合同确认不登记收款金额；实际收款凭证须明确正金额。');
        }

        return $this->flow->command('admin:'.$actor, 'scene.commercial.record:'.$quoteId, $key, $data, function () use ($quoteId, $data, $actor) {
            [$q, $r] = $this->quote($quoteId);
            $evidence = $this->evidence((int) $r->id, $data['evidence_ids']);
            if ($data['amount_minor'] > (int) $q->total_minor + (int) $q->deposit_minor) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_AMOUNT_INVALID', '凭证金额超过本版应收总额。');
            }
            $referenceHash = hash('sha256', mb_strtolower(trim($data['reference'])));
            if (DB::table('scene_quote_commercial')->where('reference_hash', $referenceHash)->exists()) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_REFERENCE_USED', '此商务凭证已登记，请查询原记录，不能重复使用。', 409);
            }
            $id = DB::table('scene_quote_commercial')->insertGetId([
                'quote_id' => $quoteId, 'kind' => $data['kind'], 'amount_minor' => $data['amount_minor'],
                'reference_hash' => $referenceHash, 'encrypted_reference' => Crypt::encryptString(trim($data['reference'])),
                'evidence' => SceneWorkflow::json($evidence), 'content_hash' => $q->content_hash, 'recorded_by' => $actor,
                'occurred_at' => $data['occurred_at'], 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->flow->record('admin', $actor, 'scene_quote', $quoteId, (int) $q->row_version, 'scene.quote.commercial.recorded', ['record_id' => $id]);

            return ['id' => $id, 'state' => 'pending'];
        });
    }

    public function review(int $quoteId, int $id, array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, ['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($quoteId, $id, $data, $actor) {
            [$q, $r] = $this->quote($quoteId);
            $record = SceneWorkflow::requireRow('scene_quote_commercial', $id);
            if ((int) $record->quote_id !== $quoteId || $record->state !== 'pending' || ! hash_equals($record->content_hash, $q->content_hash)) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_STATE_CONFLICT', '凭证不属于此报价、已复核或报价指纹变化。', 409);
            }
            if ((int) $record->recorded_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '凭证录入人不能自行复核。', 403);
            }
            if ($data['decision'] === 'approve') {
                $this->assertEvidence((int) $r->id, SceneWorkflow::unpack($record->evidence));
                $paid = (int) DB::table('scene_quote_commercial')->where('quote_id', $quoteId)->where('kind', 'offline_receipt')->where('state', 'approved')->sum('amount_minor');
                if ($record->kind === 'offline_receipt' && $paid + (int) $record->amount_minor > (int) $q->total_minor + (int) $q->deposit_minor) {
                    throw new SceneWorkflowException('SCENE_COMMERCIAL_AMOUNT_INVALID', '累计确认收款超过本版应收总额。', 409);
                }
            }
            $state = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('scene_quote_commercial')->where('id', $id)->update(['state' => $state, 'reviewed_by' => $actor, 'reviewed_at' => now('UTC'), 'review_note' => $data['reason'], 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_quote', $quoteId, (int) $q->row_version, 'scene.quote.commercial.reviewed', ['record_id' => $id]);

            return ['id' => $id, 'state' => $state];
        }, 3);
    }

    public function assertConfirmed(object $q, object $r): array
    {
        $terms = SceneWorkflow::unpack($q->terms_snapshot);
        $policy = $terms['confirmation'] ?? null;
        if (! $policy || ! $q->confirmation_deadline || ($policy['deadline'] ?? null) !== $q->confirmation_deadline) {
            throw new SceneWorkflowException('SCENE_CONFIRMATION_POLICY_REQUIRED', '报价未冻结商务条件和确认期限，请协商完整新版报价。', 409);
        }
        if ($q->confirmation_deadline <= now('UTC')) {
            throw new SceneWorkflowException('SCENE_CONFIRMATION_EXPIRED', '商务与资源确认期限已过，请协商新版安排。', 409);
        }
        $rows = DB::table('scene_quote_commercial')->where('quote_id', $q->id)->where('state', 'approved')->orderBy('id')->lockForUpdate()->get();
        $paid = 0;
        $contract = false;
        foreach ($rows as $row) {
            if (! $row->reviewed_by || (int) $row->reviewed_by === (int) $row->recorded_by || ! hash_equals($row->content_hash, $q->content_hash)) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_INTEGRITY', '商务复核或报价指纹不符。', 409);
            }
            $this->assertEvidence((int) $r->id, SceneWorkflow::unpack($row->evidence));
            $paid += $row->kind === 'offline_receipt' ? (int) $row->amount_minor : 0;
            $contract = $contract || $row->kind === 'offline_contract';
        }
        if (($policy['method'] === 'offline_contract' && ! $contract) || ($policy['method'] === 'offline_receipt' && $paid < (int) $policy['required_minor'])) {
            throw new SceneWorkflowException('SCENE_COMMERCIAL_UNCONFIRMED', '本版要求的线下商务条件尚未独立复核完成。', 409);
        }
        $due = (int) $q->total_minor + (int) $q->deposit_minor;
        if ($paid > $due) {
            throw new SceneWorkflowException('SCENE_COMMERCIAL_AMOUNT_INVALID', '累计收款超过本版应收总额。', 409);
        }

        return ['method' => $policy['method'], 'record_ids' => $rows->pluck('id')->all(), 'paid_minor' => $paid,
            'payment_state' => $paid === $due && $paid > 0 ? 'offline_verified' : ($paid > 0 ? 'offline_partial' : 'unpaid')];
    }

    public function evidence(int $requestId, array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $a = SceneWorkflow::requireRow('scene_attachments', (int) $id);
            if ((int) $a->request_id !== $requestId || $a->state !== 'clean' || $a->revoked_at || $a->expires_at <= now('UTC') || ! preg_match('/^[a-f0-9-]{36}$/D', $a->storage_key)) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_EVIDENCE_INVALID', '商务原件未通过扫描、跨需求、撤销或到期。', 409);
            }
            $path = storage_path('app/private/scenes/'.$a->storage_key);
            if (! is_file($path) || ! hash_equals($a->sha256, hash_file('sha256', $path))) {
                throw new SceneWorkflowException('SCENE_COMMERCIAL_EVIDENCE_INVALID', '商务原件缺失或指纹变化。', 409);
            }
            $result[] = ['id' => (int) $a->id, 'sha256' => $a->sha256];
        }

        return $result;
    }

    private function assertEvidence(int $requestId, array $frozen): void
    {
        if (! $frozen || ! hash_equals(SceneWorkflow::hash($frozen), SceneWorkflow::hash($this->evidence($requestId, array_column($frozen, 'id'))))) {
            throw new SceneWorkflowException('SCENE_COMMERCIAL_EVIDENCE_INVALID', '商务原件与复核快照不符。', 409);
        }
    }

    private function quote(int $id): array
    {
        $ref = SceneWorkflow::requireRow('scene_quotes', $id, false);
        $r = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
        $q = SceneWorkflow::requireRow('scene_quotes', $id);
        if ($q->state !== 'accepted' || in_array($r->state, ['closed', 'cancelled', 'converted'], true)) {
            throw new SceneWorkflowException('SCENE_QUOTE_UNAVAILABLE', '须先取得客户接受且尚未转换的报价。', 409);
        }

        return [$q, $r];
    }
}
