<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;

/** 同一需求的状态变更先锁需求，再锁报价，最后按升序锁资源父行。 */
class SceneQuoteLifecycleService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function invalidate(int $requestId, array $states, string $reasonCode, string $actorType, ?int $actorId, ?int $exceptId = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('报价失效必须属于原业务事务。');
        }
        SceneWorkflow::requireRow('scene_requests', $requestId);
        if (DB::table('scene_projects')->where('request_id', $requestId)->exists()) {
            throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '需求已有交付项目，请通过商务变更或售后处理。', 409);
        }
        $quotes = DB::table('scene_quotes')->where('request_id', $requestId)->whereIn('state', $states)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->orderBy('id')->lockForUpdate()->get();
        $resourceIds = DB::table('scene_reservations')->whereIn('quote_id', $quotes->pluck('id'))->where('state', 'held')->distinct()->orderBy('resource_id')->pluck('resource_id');
        DB::table('scene_resources')->whereIn('id', $resourceIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($quotes as $quote) {
            $this->releaseHolds((int) $quote->id);
            DB::table('scene_quotes')->where('id', $quote->id)->update([
                'state' => 'voided', 'row_version' => $quote->row_version + 1, 'updated_at' => now('UTC'),
            ]);
            $this->flow->record($actorType, $actorId, 'scene_quote', (int) $quote->id, $quote->row_version + 1,
                'scene.quote.invalidated', ['reason_code' => $reasonCode, 'request_id' => $requestId]);
        }
    }

    public function releaseHolds(int $quoteId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('资源释放必须属于报价事务。');
        }
        if (DB::table('scene_reservations')->where('quote_id', $quoteId)->where('state', 'confirmed')->exists()
            || DB::table('scene_projects')->where('quote_id', $quoteId)->exists()) {
            throw new SceneWorkflowException('SCENE_CHANGE_REQUIRED', '报价已有确认资源或项目，请通过商务变更处理。', 409);
        }
        $ids = DB::table('scene_reservations')->where('quote_id', $quoteId)->where('state', 'held')->distinct()->orderBy('resource_id')->pluck('resource_id');
        if ($ids->isNotEmpty()) {
            DB::table('scene_resources')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            DB::table('scene_reservations')->where('quote_id', $quoteId)->where('state', 'held')->update(['state' => 'released', 'updated_at' => now('UTC')]);
        }
    }
}
