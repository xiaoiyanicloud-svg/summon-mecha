<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

if (DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('P2 回归仅允许内存环境。');
}

foreach (['ordinary_item', 'foreign_project', 'foreign_owner', 'revoked', 'expired', 'expiring', 'past_expiry', 'wrong_version', 'wrong_product', 'wrong_item', 'unpaid'] as $invalid) {
    test('P2 技能售后：'.$invalid.' 不得凭任意自有订单冒充本项目有效授权', fn () => sc4Sandbox(function ($service) use ($invalid, $projectId, $owner) {
        $f = sceneSkillAfterSaleFixture($projectId);
        $entitlement = $f['entitlement'];
        $item = $f['item'];
        if ($invalid === 'ordinary_item') {
            DB::table('order_items')->where('id', $item)->update(['type' => 'simple']);
        }
        if ($invalid === 'foreign_project') {
            DB::table('scene_order_parts')->where('order_id', $f['order'])->delete();
        }
        if ($invalid === 'foreign_owner') {
            DB::table('orders')->where('id', $f['order'])->update(['customer_id' => 2]);
        }
        if (in_array($invalid, ['revoked', 'expired', 'expiring'], true)) {
            DB::table('skill_entitlements')->where('id', $entitlement)->update(['status' => $invalid]);
        }
        if ($invalid === 'past_expiry') {
            DB::table('skill_entitlements')->where('id', $entitlement)->update(['expires_at' => now('UTC')->subSecond()]);
        }
        if ($invalid === 'wrong_version') {
            DB::table('skill_entitlements')->where('id', $entitlement)->update(['skill_version_id' => 99999]);
        }
        if ($invalid === 'wrong_product') {
            DB::table('products')->where('id', $f['product'])->update(['type' => 'simple']);
        }
        if ($invalid === 'wrong_item') {
            $item += 10000;
        }
        if ($invalid === 'unpaid') {
            DB::table('orders')->where('id', $f['order'])->update(['status' => 'pending']);
            DB::table('scene_projects')->where('id', $projectId)->update(['payment_state' => 'unpaid']);
        }
        $events = DB::table('business_outbox')->count();
        error('SCENE_SKILL_ENTITLEMENT_INVALID', fn () => $service->customerAction($owner, $projectId, 10, 'p2-invalid-skill-0001', 'after_sale', ['scope' => 'skill_fault', 'order_item_id' => $item, 'entitlement_id' => $entitlement, 'reason' => 'QA 资格校验']));
        same(0, DB::table('scene_service_tickets')->where('project_id', $projectId)->count());
        same(10, (int) DB::table('scene_projects')->find($projectId)->row_version);
        same($events, DB::table('business_outbox')->count());
    }));
}

test('P2 技能售后：只给订单明细也必须解析本项目有效授权；同键重试返回同一工单', fn () => sc4Sandbox(function ($service) use ($projectId, $owner) {
    $f = sceneSkillAfterSaleFixture($projectId);
    $data = ['scope' => 'skill_fault', 'order_item_id' => $f['item'], 'reason' => 'QA 已激活技能故障'];
    $result = $service->customerAction($owner, $projectId, 10, 'p2-valid-skill-0001', 'after_sale', $data);
    same($result, $service->customerAction($owner, $projectId, 10, 'p2-valid-skill-0001', 'after_sale', $data));
    $ticket = DB::table('scene_service_tickets')->find($result['id']);
    same($f['entitlement'], (int) $ticket->entitlement_id);
    same($f['item'], (int) $ticket->order_item_id);
    same(1, DB::table('scene_service_tickets')->where('project_id', $projectId)->count());
    error('SCENE_SKILL_REFUND_REQUIRES_D10', fn () => $service->resolveTicket($result['id'], 1, 'QA 试图绕过 D10 补偿', 2, 1000));
    same('open', DB::table('scene_service_tickets')->find($result['id'])->state);
    same('resolved', $service->resolveTicket($result['id'], 1, 'QA 技术问题已修复，退款请走技能售后', 2)['state']);
    same(0, DB::table('scene_commercial_records')->where('project_id', $projectId)->count());
}));

test('P2 技能售后：复用 D10 的试用拒绝与已激活人工审核结论；普通服务工单仍可提待复核退款', fn () => sc4Sandbox(function ($service) use ($projectId, $owner) {
    $f = sceneSkillAfterSaleFixture($projectId);
    $model = Webkul\Shop\Models\SkillEntitlement::find($f['entitlement']);
    $policy = Webkul\Shop\Services\SkillDelivery\AfterSaleService::policyFor($model);
    same('activated', $policy['policy_basis']);
    same(true, $policy['requires_manual_review']);
    $model->status = 'pending_bind';
    $model->activations = 0;
    same('not_installed', Webkul\Shop\Services\SkillDelivery\AfterSaleService::policyFor($model)['policy_basis']);
    DB::table('skill_entitlements')->where('id', $f['entitlement'])->update(['meta' => '{"trial":true}']);
    same(false, Webkul\Shop\Services\SkillDelivery\AfterSaleService::policyFor($model->fresh())['ok']);
    error('SCENE_SKILL_REFUND_POLICY', fn () => $service->customerAction($owner, $projectId, 10, 'p2-trial-skill-0001', 'after_sale', ['scope' => 'skill_fault', 'entitlement_id' => $f['entitlement'], 'reason' => 'QA 试用绕过 D10']));
    $ticket = $service->customerAction($owner, $projectId, 10, 'p2-platform-fault-0001', 'after_sale', ['reason' => 'QA 现场服务问题']);
    same(500, $service->resolveTicket($ticket['id'], 1, 'QA 平台服务协商待财务复核', 2, 500)['refund_proposal_minor']);
    same(null, DB::table('scene_commercial_records')->where('project_id', $projectId)->sole()->reviewed_at);
}));

test('P2 技能售后：强审计失败不留下工单或版本变化', fn () => sc4Sandbox(function ($service) use ($projectId, $owner) {
    $f = sceneSkillAfterSaleFixture($projectId);
    sceneAuditFailure(fn () => $service->customerAction($owner, $projectId, 10, 'p2-audit-skill-0001', 'after_sale', ['scope' => 'skill_fault', 'entitlement_id' => $f['entitlement'], 'reason' => 'QA 原子审计']));
    same(0, DB::table('scene_service_tickets')->where('project_id', $projectId)->count());
    same(10, (int) DB::table('scene_projects')->find($projectId)->row_version);
}));

test('P2 D10 原入口回归：试用拒绝、未下发受理、已激活人工审核，重复申请不重复建单', fn () => sc4Sandbox(function () use ($projectId, $owner) {
    $f = sceneSkillAfterSaleFixture($projectId);
    $d10 = app(Webkul\Shop\Services\SkillDelivery\AfterSaleService::class);
    DB::table('skill_entitlements')->where('id', $f['entitlement'])->update(['meta' => '{"trial":true}']);
    same(false, $d10->apply($owner->customerId, $f['entitlement'], 'qa', 'QA 试用退款拒绝')['ok']);
    same(0, Webkul\Shop\Models\AfterSale::count());
    DB::table('skill_entitlements')->where('id', $f['entitlement'])->update(['meta' => '{}', 'status' => 'pending_bind', 'activations' => 0]);
    $uninstalled = $d10->apply($owner->customerId, $f['entitlement'], 'qa', 'QA 未下发受理');
    same(true, $uninstalled['ok']);
    same('not_installed', $uninstalled['data']['policy_basis']);
    same('applied', $uninstalled['data']['status']);
    same(false, $d10->apply($owner->customerId, $f['entitlement'], 'qa', 'QA 重复申请')['ok']);
    same(1, Webkul\Shop\Models\AfterSale::count());
    Webkul\Shop\Models\AfterSale::query()->delete();
    DB::table('skill_entitlements')->where('id', $f['entitlement'])->update(['status' => 'active', 'activations' => 1]);
    $active = $d10->apply($owner->customerId, $f['entitlement'], 'qa', 'QA 已激活人工审核');
    same('activated', $active['data']['policy_basis']);
    same('reviewing', $active['data']['status']);
    same(0, Webkul\Shop\Models\RefundRequest::count());
    same('active', DB::table('skill_entitlements')->find($f['entitlement'])->status);
}));

function p2RealProject(): object
{
    global $projectId;
    DB::table('scene_requests')->update(['is_test' => true]);
    $project = DB::table('scene_projects')->find($projectId);
    DB::table('scene_requests')->where('id', $project->request_id)->update(['is_test' => false, 'source' => 'enterprise', 'state' => 'accepted']);

    return $project;
}

function p2CloneProject(object $project): int
{
    $quote = (array) DB::table('scene_quotes')->find($project->quote_id);
    unset($quote['id']);
    $quote['quote_no'] = (string) Illuminate\Support\Str::uuid();
    $quote['version'] += 1000;
    $quoteId = DB::table('scene_quotes')->insertGetId($quote);
    $copy = (array) $project;
    unset($copy['id']);
    $copy['quote_id'] = $quoteId;
    $copy['project_no'] = (string) Illuminate\Support\Str::uuid();

    return DB::table('scene_projects')->insertGetId($copy);
}

test('P2 初验率：两个项目使用同名标准各通过一次，结果为 2/2=100%，复验不改变分母', fn () => sc4Sandbox(function () use ($projectId) {
    $project = p2RealProject();
    $second = p2CloneProject($project);
    DB::table('scene_acceptance_records')->whereIn('project_id', [$projectId, $second])->delete();
    foreach ([$projectId, $second] as $id) {
        DB::table('scene_acceptance_records')->insert(['project_id' => $id, 'item_key' => 'greeting', 'attempt' => 1, 'result' => 'pass', 'method' => 'customer_online', 'principal_scope' => 'customer:1', 'evidence' => '[]', 'created_at' => now('UTC')]);
    }
    $metrics = app(SceneOperationsService::class)->metrics()['quality'];
    same(2, $metrics['unique_acceptance_items']);
    same(2, $metrics['first_pass_acceptance_count']);
    same(1.0, (float) $metrics['first_pass_rate']);
    DB::table('scene_acceptance_records')->where('project_id', $second)->update(['result' => 'fail']);
    DB::table('scene_acceptance_records')->insert(['project_id' => $second, 'item_key' => 'greeting', 'attempt' => 2, 'result' => 'pass', 'method' => 'customer_online', 'principal_scope' => 'customer:1', 'evidence' => '[]', 'created_at' => now('UTC')]);
    $metrics = app(SceneOperationsService::class)->metrics()['quality'];
    same(2, $metrics['unique_acceptance_items']);
    same(1, $metrics['first_pass_acceptance_count']);
    same(0.5, $metrics['first_pass_rate']);
    same(3, $metrics['total_acceptance_attempts']);
}));

test('P2 初验率：没有验收事实时为零，测试需求和旧线索不参与分子分母', fn () => sc4Sandbox(function () use ($projectId) {
    $project = p2RealProject();
    DB::table('scene_acceptance_records')->where('project_id', $projectId)->delete();
    same(0.0, (float) app(SceneOperationsService::class)->metrics()['quality']['first_pass_rate']);
    DB::table('scene_acceptance_records')->insert(['project_id' => $projectId, 'item_key' => 'greeting', 'attempt' => 1, 'result' => 'pass', 'method' => 'customer_online', 'principal_scope' => 'customer:1', 'evidence' => '[]', 'created_at' => now('UTC')]);
    foreach ([['is_test' => true], ['is_test' => false, 'source' => 'legacy_custom']] as $patch) {
        DB::table('scene_requests')->where('id', $project->request_id)->update($patch);
        same(0, app(SceneOperationsService::class)->metrics()['quality']['unique_acceptance_items']);
    }
}));

function p2QuoteReceipt(int $quoteId, string $reference, int $amount): int
{
    $proof = scenePrivateProof((int) DB::table('scene_quotes')->find($quoteId)->request_id);
    DB::table('scene_quotes')->where('id', $quoteId)->update(['state' => 'accepted']);
    $service = app(Webkul\Shop\Services\Scenes\SceneCommercialService::class);
    $row = $service->record($quoteId, ['kind' => 'offline_receipt', 'amount_minor' => $amount, 'reference' => $reference, 'occurred_at' => '2026-10-19T20:00:00+08:00', 'evidence_ids' => [$proof['id']]], 'p2-quote-'.Illuminate\Support\Str::uuid(), 1);
    $service->review($quoteId, $row['id'], ['decision' => 'approve', 'reason' => 'QA 核验实际线下到账'], 2);

    return $row['id'];
}

test('P2 收款：真实报价收款复核在转单前可见，转单冻结总额不重复相加，后收款及退款分账', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $attachmentId) {
    p2RealProject();
    $a = p2QuoteReceipt($quoteId, 'QA-P2-RECEIPT-A', 7000);
    $b = p2QuoteReceipt($quoteId, 'QA-P2-RECEIPT-B', 3000);
    same(10000, app(SceneOperationsService::class)->metrics()['financial']['total_payment_receipt_minor']);
    p1Snapshot(function (&$snapshot) use ($a, $b) {
        $snapshot['commercial'] = ['paid_minor' => 10000, 'record_ids' => [$a, $b]];
    });
    $expected = 10;
    foreach ([['payment_receipt', 1000], ['refund_due', 4000], ['refund_receipt', 2000]] as [$kind, $amount]) {
        $row = $service->recordCommercial($projectId, $expected++, ['kind' => $kind, 'amount_minor' => $amount, 'reference' => 'QA-P2-PROJECT-'.$kind, 'evidence_ids' => [$attachmentId]], 1);
        $service->reviewCommercial($projectId, $row['id'], $expected++, 2);
    }
    p1Record('refund_due', 500, false);
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(11000, $metrics['total_payment_receipt_minor']);
    same(4000, $metrics['total_refund_due_minor']);
    same(2000, $metrics['total_refund_receipt_minor']);
    same(500, $metrics['pending_refund_due_minor']);
    same(9000, $metrics['net_cash_received_minor']);
    same(0, $metrics['legacy_snapshot_payment_minor']);
    same(true, $metrics['cash_includes_deposits']);
    same(false, $metrics['requires_reconciliation']);
}));

test('P2 收款：跨报价/项目复制的同一凭证只计一次，同额不同凭证仍分别计入', fn () => sc4Sandbox(function () use ($projectId, $quoteId) {
    p2RealProject();
    p2QuoteReceipt($quoteId, 'QA-P2-COPY', 5000);
    p2QuoteReceipt($quoteId, 'QA-P2-DIFFERENT', 5000);
    p1Snapshot(function (&$s) {
        $s['commercial']['paid_minor'] = 10000;
    });
    $row = ['project_id' => $projectId, 'kind' => 'payment_receipt', 'amount_minor' => 5000, 'reference' => ' qa-p2-copy ', 'evidence' => '[]', 'recorded_by' => 1, 'reviewed_by' => 2, 'reviewed_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')];
    DB::table('scene_commercial_records')->insert($row);
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(10000, $metrics['total_payment_receipt_minor']);
    same(1, $metrics['duplicate_receipt_records']);
    $row['amount_minor'] = 6000;
    DB::table('scene_commercial_records')->insert($row);
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(10000, $metrics['total_payment_receipt_minor']);
    same(1, $metrics['conflicting_receipt_records']);
    same(true, $metrics['requires_reconciliation']);
}));

test('P2 收款：历史冻结额只计一次，押金不额外充当收入，未复核和测试数据不计现金', fn () => sc4Sandbox(function () {
    $project = p2RealProject();
    p1ZeroPolicy(10000);
    p1Record('payment_receipt', 500, false);
    p1Record('refund_receipt', 500, false);
    DB::table('scene_quotes')->where('id', $project->quote_id)->update(['deposit_minor' => 2000]);
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(10000, $metrics['total_payment_receipt_minor']);
    same(10000, $metrics['legacy_snapshot_payment_minor']);
    same(0, $metrics['total_refund_receipt_minor']);
    same(true, $metrics['requires_reconciliation']);
    DB::table('scene_requests')->where('id', $project->request_id)->update(['is_test' => true]);
    same(0, app(SceneOperationsService::class)->metrics()['financial']['total_payment_receipt_minor']);
}));

test('P2 收款：不同项目同名凭证独立核算，快照与原始凭证异额明确要求核账', fn () => sc4Sandbox(function () use ($projectId, $quoteId) {
    $project = p2RealProject();
    $second = p2CloneProject($project);
    foreach ([$projectId, $second] as $id) {
        DB::table('scene_commercial_records')->insert(['project_id' => $id, 'kind' => 'payment_receipt', 'amount_minor' => 1000, 'reference' => 'QA 本项目付款单1', 'evidence' => '[]', 'recorded_by' => 1, 'reviewed_by' => 2, 'reviewed_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(2000, $metrics['total_payment_receipt_minor']);
    same(0, $metrics['duplicate_receipt_records']);
    p2QuoteReceipt($quoteId, 'QA-P2-MISMATCH', 1000);
    p1Snapshot(function (&$snapshot) {
        $snapshot['commercial']['paid_minor'] = 2000;
    });
    $metrics = app(SceneOperationsService::class)->metrics()['financial'];
    same(3000, $metrics['total_payment_receipt_minor']);
    same(1, $metrics['snapshot_payment_mismatch_count']);
    same(true, $metrics['requires_reconciliation']);
}));

test('P2 运维手册：实际停写错误码与文档一致，已移除无效转换错误码及裸 SQL 重放', function () {
    $manual = file_get_contents(dirname(__DIR__, 2).'/docs/scenes/SC5-Operations-Runbook.md');
    same(false, str_contains($manual, 'SCENE_CONVERSION_DISABLED'));
    same(false, preg_match('/```sql\s+UPDATE/i', $manual) === 1);
    foreach (['workflow_enabled', 'order_conversion_enabled', 'operations_enabled'] as $flag) {
        $prior = config('scenes.'.$flag);
        config(['scenes.'.$flag => false]);
        try {
            error('SCENE_WRITES_DISABLED', fn () => app(SceneWorkflow::class)->enabled($flag === 'workflow_enabled' ? 'project_actions_enabled' : $flag));
        } finally {
            config(['scenes.'.$flag => $prior]);
        }
    }
});
