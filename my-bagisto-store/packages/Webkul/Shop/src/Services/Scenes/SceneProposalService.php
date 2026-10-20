<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SceneProposalService
{
    public function __construct(protected SceneWorkflow $flow, protected SceneAssessmentService $assessments, protected ScenePublicationService $publication) {}

    /** 金额以最小货币单位计算；逐行折扣及税额四舍五入，押金不作收入。 */
    public function draft(int $requestId, int $expected, array $input, int $actor, int $channel): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, [
            'assessment_id'    => 'required|integer|min:1', 'items' => 'required|array|min:1|max:50',
            'items.*'          => 'array:price_item_id,quantity,billing_units', 'items.*.price_item_id' => 'required|integer|distinct|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:1000', 'items.*.billing_units' => 'required|integer|min:1|max:366',
            'discount_bps'     => 'required|integer|min:0|max:10000', 'expires_at' => 'required|date|after:now', 'schedule' => 'required|array',
        ])->validate();
        $schedule = SceneTime::schedule($data['schedule']);
        $data['expires_at'] = SceneWorkflow::utc($data['expires_at']);
        $terms = config('scenes.terms');
        $policy = config('scenes.commercial_policy');
        if (empty($terms['version']) || empty($terms['text']) || ! is_array($policy) || empty($policy['version']) || empty($policy['required_price_codes'])) {
            throw new SceneWorkflowException('SCENE_COMMERCIAL_POLICY_MISSING', '商务条款和必需费用尚未配置，不能出具正式报价。', 409);
        }

        return DB::transaction(function () use ($requestId, $expected, $data, $schedule, $terms, $policy, $actor, $channel) {
            $request = SceneWorkflow::requireRow('scene_requests', $requestId);
            SceneWorkflow::version($request, $expected);
            if (in_array($request->state, ['converted', 'closed', 'cancelled'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '当前需求不支持报价。', 409);
            }
            if (DB::table('scene_quotes')->where('request_id', $requestId)->where('state', 'accepted')->exists()) {
                throw new SceneWorkflowException('SCENE_ACCEPTED_QUOTE_EXISTS', '客户已接受报价，请通过需求修订或商务变更处理。', 409);
            }
            $assessment = SceneWorkflow::requireRow('scene_assessments', (int) $data['assessment_id']);
            if ((int) $assessment->request_id !== $requestId) {
                throw new SceneWorkflowException('SCENE_ASSESSMENT_MISMATCH', '评估不属于此需求。');
            }
            $this->assessments->assertCurrent($assessment, $request, $channel);
            $requestRevision = DB::table('scene_request_revisions')->where('request_id', $requestId)->where('revision_no', $request->revision_no)->first();
            $facts = SceneWorkflow::unpack($requestRevision->facts);
            foreach (['starts_at', 'ends_at', 'timezone'] as $key) {
                if (($facts[$key] ?? null) !== $schedule[$key]) {
                    throw new SceneWorkflowException('SCENE_SCHEDULE_CHANGED', '报价时间必须与当前已评估的需求一致。', 409);
                }
            }
            $currency = config('scenes.currency', 'CNY');
            $lines = [];
            $codes = [];
            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $deposit = 0;
            $candidates = array_column(SceneWorkflow::unpack($assessment->candidate_snapshot), 'product_id');
            foreach ($data['items'] as $index => $item) {
                $price = SceneWorkflow::requireRow('scene_price_items', (int) $item['price_item_id']);
                if ($price->revoked_at || $price->valid_from > now('UTC') || $price->valid_until <= now('UTC') || strtotime($price->valid_until) < strtotime($data['expires_at']) || $price->currency !== $currency) {
                    throw new SceneWorkflowException('SCENE_PRICE_UNAVAILABLE', '价目已失效、币种不同或有效期短于报价。', 409);
                }
                if ($price->kind === 'skill') {
                    throw new SceneWorkflowException('SCENE_SKILL_BUNDLE_NOT_READY', '技能组合订单尚未完成授权与事务验收，请通过既有技能入口处理。', 409);
                }
                if ($price->kind === 'robot' && ! in_array((int) $price->product_id, array_map('intval', $candidates), true)) {
                    throw new SceneWorkflowException('SCENE_BOM_UNASSESSED', 'BOM 包含未评估的机型。');
                }
                $amount = (int) $price->unit_minor * (int) $item['quantity'] * (int) $item['billing_units'];
                if ($amount > 1000000000000) {
                    throw new SceneWorkflowException('SCENE_AMOUNT_LIMIT', '金额超出单项报价限制。');
                }
                $lineDiscount = $price->charge_type === 'deposit' ? 0 : intdiv($amount * (int) $data['discount_bps'] + 5000, 10000);
                $lineTax = $price->charge_type === 'deposit' ? 0 : intdiv(($amount - $lineDiscount) * (int) $price->tax_bps + 5000, 10000);
                if ($price->charge_type === 'deposit') {
                    $deposit += $amount;
                } else {
                    $subtotal += $amount;
                    $discount += $lineDiscount;
                    $tax += $lineTax;
                }
                $codes[] = $price->code;
                if (count($codes) !== count(array_unique($codes))) {
                    throw new SceneWorkflowException('SCENE_PRICE_CODE_DUPLICATE', '同一费用编码不能以不同价目版本重复计费。');
                }
                $snapshot = ['code'  => $price->code, 'version' => (int) $price->version, 'name' => $price->name,
                    'kind'           => $price->kind, 'product_id' => $price->product_id, 'unit' => $price->unit, 'currency' => $price->currency,
                    'unit_minor'     => (int) $price->unit_minor, 'tax_bps' => (int) $price->tax_bps, 'charge_type' => $price->charge_type,
                    'discount_minor' => $lineDiscount, 'tax_minor' => $lineTax];
                $lines[] = ['price_item_id' => $price->id, 'line_no' => $index + 1, 'quantity' => $item['quantity'], 'billing_units' => $item['billing_units'], 'snapshot' => SceneWorkflow::json($snapshot), 'line_minor' => $amount - $lineDiscount + $lineTax];
            }
            if (array_diff($policy['required_price_codes'], $codes)) {
                throw new SceneWorkflowException('SCENE_FEES_INCOMPLETE', '报价缺少商务政策要求的费用项；免费项目也须明确配置价目。');
            }
            $version = (int) DB::table('scene_quotes')->where('request_id', $requestId)->orderByDesc('version')->lockForUpdate()->value('version') + 1;
            $frozenTerms = ['terms' => $terms, 'policy' => $policy];
            $quote = [
                'quote_no'       => (string) Str::uuid(), 'request_id' => $requestId, 'request_revision_no' => $request->revision_no,
                'assessment_id'  => $assessment->id, 'version' => $version, 'row_version' => 1, 'state' => 'draft', 'currency' => $currency,
                'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'tax_minor' => $tax,
                'total_minor'    => $subtotal - $discount + $tax, 'deposit_minor' => $deposit,
                'terms_snapshot' => SceneWorkflow::json($frozenTerms), 'terms_hash' => SceneWorkflow::hash($frozenTerms),
                'content_hash'   => SceneWorkflow::hash([$request->revision_no, $assessment->id, $lines, $schedule, $frozenTerms, $data['expires_at']]),
                'schedule'       => SceneWorkflow::json($schedule), 'expires_at' => $data['expires_at'], 'created_by' => $actor,
                'created_at'     => now('UTC'), 'updated_at' => now('UTC'),
            ];
            if ($quote['total_minor'] + $deposit > 1000000000000) {
                throw new SceneWorkflowException('SCENE_AMOUNT_LIMIT', '总额超出报价限制。');
            }
            $id = DB::table('scene_quotes')->insertGetId($quote);
            foreach ($lines as $line) {
                DB::table('scene_quote_items')->insert(['quote_id' => $id] + $line);
            }
            $this->flow->record('admin', $actor, 'scene_quote', $id, 1, 'scene.quote.drafted');

            return ['id' => $id, 'row_version' => 1, 'state' => 'draft', 'content_hash' => $quote['content_hash']];
        }, 3);
    }

    public function transition(int $id, int $expected, string $action, int $actor, int $channel, ?string $reason = null): array
    {
        $this->flow->enabled('quotes_enabled');

        $reason = $reason === null ? null : trim($reason);
        if (in_array($action, ['return', 'void'], true)) {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
        }

        return DB::transaction(function () use ($id, $expected, $action, $actor, $channel, $reason) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $request = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
            $quote = SceneWorkflow::requireRow('scene_quotes', $id);
            SceneWorkflow::version($quote, $expected);
            $states = ['review' => ['draft', 'in_review'], 'approve' => ['in_review', 'approved'], 'return' => ['in_review', 'returned'], 'send' => ['approved', 'sent'], 'void' => ['sent', 'voided']];
            if (! isset($states[$action]) || $quote->state !== $states[$action][0]) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '报价当前状态不支持操作。', 409);
            }
            if (in_array($action, ['approve', 'return'], true) && (int) $quote->created_by === $actor) {
                throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '编制人不能审批自己的报价。', 403);
            }
            if (! in_array($action, ['void', 'return'], true)) {
                if ((int) DB::table('scene_quotes')->where('request_id', $request->id)->orderByDesc('version')->lockForUpdate()->value('version') !== (int) $quote->version) {
                    throw new SceneWorkflowException('SCENE_NEWER_QUOTE', '已有新版报价，请审核并发送最新版本。', 409);
                }
                $this->assertUsable($quote, $request, $channel);
            }
            if ($action === 'send') {
                if (DB::table('scene_quotes')->where('request_id', $request->id)->where('state', 'accepted')->exists()) {
                    throw new SceneWorkflowException('SCENE_ACCEPTED_QUOTE_EXISTS', '已接受的报价需走商务变更。', 409);
                }
                app(SceneQuoteLifecycleService::class)->invalidate((int) $request->id, ['sent'], 'superseded', 'admin', $actor, $id);
            }
            $patch = ['state' => $states[$action][1], 'row_version' => $expected + 1, 'updated_at' => now('UTC')];
            if ($action === 'approve') {
                $patch['approved_by'] = $actor;
            }
            if ($action === 'send') {
                $patch += ['customer_visible' => true, 'sent_at' => now('UTC')];
            }
            if ($action === 'void') {
                app(SceneQuoteLifecycleService::class)->releaseHolds($id);
            }
            if (in_array($action, ['return', 'void'], true)) {
                $this->decision($quote, $action === 'return' ? 'review_returned' : 'admin_voided', 'admin:'.$actor, $reason);
            }
            DB::table('scene_quotes')->where('id', $id)->update($patch);
            $this->flow->record('admin', $actor, 'scene_quote', $id, $expected + 1, 'scene.quote.'.$action);

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => $patch['state']];
        }, 3);
    }

    public function accept(ScenePrincipal $owner, int $id, string $key, string $termsHash, string $contentHash, int $channel): array
    {
        $this->flow->enabled('quotes_enabled');

        return $this->flow->command($owner->scope(), 'scene.quote.accept:'.$id, $key, [$termsHash, $contentHash], function () use ($owner, $id, $termsHash, $contentHash, $channel) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $request = $owner->request((int) $ref->request_id, true);
            $quote = SceneWorkflow::requireRow('scene_quotes', $id);
            $this->assertVisible($quote);
            if (! hash_equals($quote->terms_hash, $termsHash) || ! hash_equals($quote->content_hash, $contentHash)) {
                throw new SceneWorkflowException('SCENE_TERMS_CHANGED', '报价或条款已变化，请刷新确认。', 409);
            }
            if ($quote->state === 'accepted') {
                return ['id' => $id, 'state' => 'accepted', 'delivery_status' => 'awaiting_confirmation'];
            }
            if ($quote->state !== 'sent') {
                throw new SceneWorkflowException('SCENE_QUOTE_UNAVAILABLE', '该报价已失效或尚未发送。', 409);
            }
            $this->assertUsable($quote, $request, $channel);
            DB::table('scene_quote_acceptances')->insert(['quote_id' => $id, 'principal_scope' => $owner->scope(), 'terms_hash' => $termsHash, 'content_hash' => $contentHash, 'created_at' => now('UTC')]);
            DB::table('scene_quotes')->where('id', $id)->update(['state' => 'accepted', 'row_version' => $quote->row_version + 1, 'updated_at' => now('UTC')]);
            DB::table('scene_requests')->where('id', $request->id)->update(['state' => 'accepted', 'row_version' => $request->row_version + 1, 'updated_at' => now('UTC')]);
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_quote', $id, $quote->row_version + 1, 'scene.quote.accepted');

            return ['id' => $id, 'state' => 'accepted', 'delivery_status' => 'awaiting_confirmation'];
        }, function () use ($owner, $id) {
            $quote = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $owner->request((int) $quote->request_id);
        });
    }

    public function reject(ScenePrincipal $owner, int $id, string $key, array $input): array
    {
        $this->flow->enabled('quotes_enabled');
        if (isset($input['reason']) && is_string($input['reason'])) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, [
            'row_version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000',
            'terms_hash'  => 'required|regex:/^[a-f0-9]{64}$/', 'content_hash' => 'required|regex:/^[a-f0-9]{64}$/',
        ])->validate();

        return $this->flow->command($owner->scope(), 'scene.quote.reject:'.$id, $key, $data, function () use ($owner, $id, $data) {
            $ref = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $request = $owner->request((int) $ref->request_id, true);
            $quote = SceneWorkflow::requireRow('scene_quotes', $id);
            $this->assertVisible($quote);
            SceneWorkflow::version($quote, (int) $data['row_version']);
            if ($quote->state !== 'sent' || in_array($request->state, ['closed', 'cancelled', 'converted'], true)) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '只有待确认报价可拒绝；已接受报价请走商务变更。', 409);
            }
            if (! hash_equals($quote->terms_hash, $data['terms_hash']) || ! hash_equals($quote->content_hash, $data['content_hash'])) {
                throw new SceneWorkflowException('SCENE_TERMS_CHANGED', '报价或条款已变化，请刷新确认。', 409);
            }
            app(SceneQuoteLifecycleService::class)->releaseHolds($id);
            $this->decision($quote, 'customer_rejected', $owner->scope(), $data['reason']);
            DB::table('scene_quotes')->where('id', $id)->update(['state' => 'rejected', 'row_version' => $quote->row_version + 1, 'updated_at' => now('UTC')]);
            DB::table('scene_requests')->where('id', $request->id)->update(['state' => 'assessing', 'row_version' => $request->row_version + 1, 'updated_at' => now('UTC')]);
            $this->flow->record($owner->customerId ? 'customer' : 'guest', $owner->customerId, 'scene_quote', $id,
                $quote->row_version + 1, 'scene.quote.rejected', ['request_id' => (int) $request->id]);

            return ['id' => $id, 'state' => 'rejected', 'row_version' => $quote->row_version + 1];
        }, function () use ($owner, $id) {
            $quote = SceneWorkflow::requireRow('scene_quotes', $id, false);
            $owner->request((int) $quote->request_id);
        });
    }

    public function compare(ScenePrincipal $owner, int $requestId, int $leftId, int $rightId): array
    {
        $request = $owner->request($requestId);
        if ($leftId === $rightId) {
            throw new SceneWorkflowException('SCENE_COMPARE_DISTINCT', '请选择两个不同的已发送报价版本。');
        }
        $read = function (int $id) use ($requestId) {
            $quote = SceneWorkflow::requireRow('scene_quotes', $id, false);
            if ((int) $quote->request_id !== $requestId) {
                throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '报价不存在或无权访问。', 404);
            }
            $this->assertVisible($quote);

            return $this->customerDto($quote);
        };
        $left = $read($leftId);
        $right = $read($rightId);
        $deltas = [];
        foreach (['subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'deposit_minor'] as $field) {
            $deltas[$field] = $left['currency'] === $right['currency'] ? $right[$field] - $left[$field] : null;
        }

        return ['left'      => $left, 'right' => $right, 'request_revision_no' => (int) $request->revision_no,
            'amount_deltas' => $deltas, 'same_currency' => $left['currency'] === $right['currency'],
            'changes'       => ['terms' => $left['terms_hash'] !== $right['terms_hash'],
                'request_revision'      => $left['request_revision_no'] !== $right['request_revision_no'],
                'schedule'              => SceneWorkflow::hash($left['schedule']) !== SceneWorkflow::hash($right['schedule']),
                'items'                 => SceneWorkflow::hash($left['items']) !== SceneWorkflow::hash($right['items'])]];
    }

    public function assertUsable(object $quote, object $request, int $channel): void
    {
        if (strtotime($quote->expires_at.' UTC') <= now('UTC')->timestamp || (int) $quote->request_revision_no !== (int) $request->revision_no || in_array($request->state, ['closed', 'cancelled'], true)) {
            throw new SceneWorkflowException('SCENE_QUOTE_EXPIRED', '报价已到期或需求已更新。', 409);
        }
        if ($request->scene_id) {
            if (! $request->scene_revision_id) {
                throw new SceneWorkflowException('SCENE_TEMPLATE_VERSION_REQUIRED', '该需求未冻结发布版本，请补充确认当前场景后再报价。', 409);
            }
            $this->publication->active((int) $request->scene_id, (int) $request->scene_revision_id, true);
        }
        $this->assessments->assertCurrent(SceneWorkflow::requireRow('scene_assessments', (int) $quote->assessment_id), $request, $channel);
        foreach (DB::table('scene_quote_items')->where('quote_id', $quote->id)->get() as $item) {
            $price = SceneWorkflow::requireRow('scene_price_items', (int) $item->price_item_id);
            if ($price->revoked_at) {
                throw new SceneWorkflowException('SCENE_PRICE_UNAVAILABLE', '报价包含已撤销的价目，需要重新核价。', 409);
            }
        }
    }

    public function customerDto(object $quote): array
    {
        $this->assertVisible($quote);
        $terms = SceneWorkflow::unpack($quote->terms_snapshot);
        $rejection = DB::table('scene_quote_decisions')->where('quote_id', $quote->id)->where('kind', 'customer_rejected')->first(['reason', 'created_at']);

        return [
            'id'             => (int) $quote->id, 'quote_no' => $quote->quote_no, 'version' => (int) $quote->version,
            'state'          => $quote->state, 'row_version' => (int) $quote->row_version, 'request_revision_no' => (int) $quote->request_revision_no,
            'currency'       => $quote->currency, 'total_minor' => (int) $quote->total_minor,
            'subtotal_minor' => (int) $quote->subtotal_minor, 'discount_minor' => (int) $quote->discount_minor,
            'tax_minor'      => (int) $quote->tax_minor, 'deposit_minor' => (int) $quote->deposit_minor,
            // 只返回正式客户条款及政策版本，配置内的成本/内部审核资料不进入 DTO。
            'terms'              => ['terms' => ['version' => $terms['terms']['version'] ?? null, 'text' => $terms['terms']['text'] ?? null], 'policy_version' => $terms['policy']['version'] ?? null],
            'terms_hash'         => $quote->terms_hash, 'content_hash' => $quote->content_hash,
            'expires_at'         => $quote->expires_at, 'schedule' => SceneWorkflow::unpack($quote->schedule),
            'sent_at'            => $quote->sent_at, 'is_expired' => strtotime($quote->expires_at.' UTC') <= now('UTC')->timestamp,
            'customer_rejection' => $rejection ? ['reason' => $rejection->reason, 'created_at' => $rejection->created_at] : null,
            'delivery_status'    => 'awaiting_confirmation',
            'items'              => DB::table('scene_quote_items')->where('quote_id', $quote->id)->orderBy('line_no')->get()->map(function ($i) {
                $s = SceneWorkflow::unpack($i->snapshot);

                return ['price_code' => $s['code'], 'price_version' => (int) $s['version'],
                    'name'           => $s['name'], 'kind' => $s['kind'], 'unit' => $s['unit'], 'quantity' => (int) $i->quantity,
                    'billing_units'  => (int) $i->billing_units, 'unit_minor' => $s['unit_minor'], 'line_minor' => (int) $i->line_minor, 'charge_type' => $s['charge_type']];
            })->all(),
        ];
    }

    private function assertVisible(object $quote): void
    {
        if (! $quote->customer_visible) {
            throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '报价不存在或无权访问。', 404);
        }
    }

    private function decision(object $quote, string $kind, string $scope, string $reason): void
    {
        DB::table('scene_quote_decisions')->insert(['quote_id' => $quote->id, 'kind' => $kind, 'principal_scope' => $scope,
            'reason'                                           => $reason, 'terms_hash' => $quote->terms_hash, 'content_hash' => $quote->content_hash, 'created_at' => now('UTC')]);
    }
}
