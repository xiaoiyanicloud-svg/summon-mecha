<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;

/** 运营收款口径：报价凭证为原始事实，项目快照为副本，项目后收款为新增事实。 */
class SceneFinancialMetrics
{
    public function summarize(array $quoteIds, array $projects): array
    {
        $amounts = ['payment_receipt' => 0, 'refund_due' => 0, 'refund_receipt' => 0, 'pending_refund_due' => 0];
        $seen = [];
        $duplicates = $conflicts = $legacy = $mismatches = 0;
        $add = function (string $kind, string $reference, int $amount) use (&$seen, &$amounts, &$duplicates, &$conflicts) {
            $key = $kind.':'.$reference;
            if (array_key_exists($key, $seen)) {
                $duplicates++;
                if ($seen[$key] !== $amount) {
                    $conflicts++;
                }

                return;
            }
            $seen[$key] = $amount;
            $amounts[$kind] += $amount;
        };
        $quotePaid = [];
        foreach (DB::table('scene_quote_commercial')->whereIn('quote_id', $quoteIds)->where('kind', 'offline_receipt')
            ->where('state', 'approved')->whereNotNull('reviewed_at')->whereNotNull('reviewed_by')->whereColumn('reviewed_by', '!=', 'recorded_by')->orderBy('id')->get() as $row) {
            $add('payment_receipt', $row->quote_id.':'.$row->reference_hash, (int) $row->amount_minor);
            $quotePaid[(int) $row->quote_id] = ($quotePaid[(int) $row->quote_id] ?? 0) + (int) $row->amount_minor;
        }
        foreach ($projects as $project) {
            $frozen = SceneWorkflow::unpack($project->snapshot)['commercial'] ?? [];
            $paid = (int) ($frozen['paid_minor'] ?? 0);
            if (array_key_exists((int) $project->quote_id, $quotePaid)) {
                // 原始凭证和转换快照是同一笔收款；不相加，差异交由核账处理。
                if ($paid !== $quotePaid[(int) $project->quote_id]) {
                    $mismatches++;
                }
            } elseif ($paid > 0) {
                // 老项目只有冻结总额时保留其一次性历史事实，同时显式暴露待核账口径。
                $add('payment_receipt', 'frozen-project:'.$project->id, $paid);
                $legacy += $paid;
            }
        }
        $projectIds = array_map(fn ($p) => (int) $p->id, $projects);
        $projectQuotes = array_column(array_map(fn ($p) => (array) $p, $projects), 'quote_id', 'id');
        foreach (DB::table('scene_commercial_records')->whereIn('project_id', $projectIds)->orderBy('id')->get() as $row) {
            $kind = $row->kind;
            if (! in_array($kind, ['payment_receipt', 'refund_due', 'refund_receipt'], true)) {
                continue;
            }
            $reference = $projectQuotes[$row->project_id].':'.hash('sha256', mb_strtolower(trim($row->reference)));
            if (! $row->reviewed_at || ! $row->reviewed_by || (int) $row->reviewed_by === (int) $row->recorded_by) {
                if ($kind === 'refund_due') {
                    $add('pending_refund_due', $reference, (int) $row->amount_minor);
                }

                continue;
            }
            // 同一报价内与报价录入采用相同的凭证规范化，其他项目的同名凭证仍独立计入。
            $add($kind, $reference, (int) $row->amount_minor);
        }

        return ['total_payment_receipt_minor' => $amounts['payment_receipt'], 'total_refund_due_minor' => $amounts['refund_due'],
            'total_refund_receipt_minor' => $amounts['refund_receipt'], 'pending_refund_due_minor' => $amounts['pending_refund_due'],
            'net_cash_received_minor' => $amounts['payment_receipt'] - $amounts['refund_receipt'],
            'duplicate_receipt_records' => $duplicates, 'conflicting_receipt_records' => $conflicts,
            'legacy_snapshot_payment_minor' => $legacy, 'snapshot_payment_mismatch_count' => $mismatches,
            'cash_includes_deposits' => true, 'requires_reconciliation' => $legacy > 0 || $mismatches > 0 || $conflicts > 0];
    }
}
