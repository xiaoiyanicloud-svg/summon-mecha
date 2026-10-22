<?php

// 由 scene-workflow.php 的内存数据库环境加载，不可独立 bootstrap 业务应用。
if (! isset($schema, $app, $projectId, $owner) || \Illuminate\Support\Facades\DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('仅允许内存测试环境。');
}

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

function sc4Sandbox(callable $fn): void
{
    global $projectId, $attachmentId, $quoteId;
    sceneSandbox(function () use ($fn, $projectId, $quoteId) {
        $snapshot = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot);
        $snapshot['commercial'] = ['paid_minor' => 0];
        $snapshot['terms']['cancellation'] = ['version' => 'qa-frozen-cancellation-v1', 'basis' => 'verified_paid_total',
            'tiers' => [['hours_before' => 0, 'penalty_bps' => 2000]], 'default_penalty_bps' => 2000];
        $snapshot['schedule'] = [
            'starts_at' => '2026-10-19 09:00:00',
            'ends_at' => '2026-10-19 18:00:00',
            'timezone' => 'Asia/Shanghai',
        ];
        $snapshot['components'] = [
            ['type' => 'robot', 'product_id' => 11],
        ];
        DB::table('scene_projects')->where('id', $projectId)->update([
            'state' => 'delivering',
            'row_version' => 10,
            'returned' => false,
            'open_dispute' => false,
            'paused_from_state' => null,
            'incident_ref' => null,
            'snapshot' => SceneWorkflow::json($snapshot),
        ]);
        DB::table('scene_project_changes')->where('project_id', $projectId)->delete();
        DB::table('scene_service_tickets')->where('project_id', $projectId)->delete();
        DB::table('scene_commercial_records')->where('project_id', $projectId)->delete();
        DB::table('scene_reservations')->where('quote_id', $quoteId)->delete();
        // 原主流程的可服务班次从 10 月 20 日开始；本组明确使用 10 月 19 日的历史履约夹具。
        DB::table('scene_resources')->where('id', 1)->update(['availability' => SceneWorkflow::json([['from' => '2026-10-18 00:00:00', 'until' => '2026-12-30 00:00:00']])]);
        DB::table('scene_reservations')->insert([
            'resource_id' => 1,
            'quote_id' => $quoteId,
            'hold_group' => 'sc4-hold-group',
            'service_starts_at' => '2026-10-19 09:00:00',
            'service_ends_at' => '2026-10-19 18:00:00',
            'occupies_from' => '2026-10-19 09:00:00',
            'occupies_until' => '2026-10-19 18:00:00',
            'state' => 'confirmed',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $fn(app(SceneProjectService::class));
    });
}

try {
    test('SC4-03 变更全链路：申请、评估价差、自审阻断、独立审核、客户接受及排期/预留差量原子应用', fn () => sc4Sandbox(function ($s) use ($projectId, $owner, $quoteId) {
        // 1. 客户发起变更申请
        $req = $s->customerAction($owner, $projectId, 10, 'change-key-00000001', 'change', [
            'reason' => '客户申请延长活动服务时间至晚间',
            'requested_change' => ['extra_hours' => 2],
        ]);
        same(false, $req['effective']);
        same('requested', $req['state']);
        $changeId = $req['id'];

        // 2. 方案/商务评估变更（评估人：Admin 1）
        $eval = $s->evaluateChange($projectId, $changeId, 11, [
            'price_delta_minor' => 30000,
            'schedule_delta' => [
                'starts_at' => '2026-10-19T09:00:00+08:00',
                'ends_at' => '2026-10-19T21:00:00+08:00',
                'timezone' => 'Asia/Shanghai',
            ],
            'evaluation_summary' => '加时2小时，需调配晚间服务班次并加收费用300元',
        ], 1);
        same('evaluated', $eval['state']);
        same(30000, $eval['price_delta_minor']);

        // 3. 评估人自审阻断：Admin 1 试图审核自己的评估，被拒绝
        error('SCENE_REVIEW_SELF_FORBIDDEN', fn () => $s->reviewChange($projectId, $changeId, 12, [
            'decision' => 'approve',
            'note' => '自己审批通过',
        ], 1));

        // 4. 独立审核人（Admin 2）审核通过，因有价差及排期变动，状态进入 pending_customer_acceptance
        $reviewed = $s->reviewChange($projectId, $changeId, 12, [
            'decision' => 'approve',
            'note' => '经商务核算，加时方案及加价合规',
        ], 2);
        same('pending_customer_acceptance', $reviewed['state']);
        same(true, $reviewed['requires_customer_acceptance']);

        // 5. 客户拒绝变更分支：若客户拒绝，变更置为 declined，原项目排期及预留不受损
        $declined = $s->customerAcceptChange($owner, $projectId, $changeId, 13, 'change-decision-key-00001', [
            'decision' => 'decline',
            'note' => '加时费用超出预算，暂不加时',
        ]);
        same('declined', $declined['state']);
        same(false, $declined['effective']);
        $projRow = DB::table('scene_projects')->find($projectId);
        $snapshot = SceneWorkflow::unpack($projRow->snapshot);
        same('2026-10-19 18:00:00', $snapshot['schedule']['ends_at']); // 原排期未变

        // 6. 客户重新发起变更并最终接受
        $req2 = $s->customerAction($owner, $projectId, 14, 'change-key-00000002', 'change', [
            'reason' => '客户再次申请并确认加时',
        ]);
        $s->evaluateChange($projectId, $req2['id'], 15, [
            'price_delta_minor' => 30000,
            'schedule_delta' => [
                'starts_at' => '2026-10-19T09:00:00+08:00',
                'ends_at' => '2026-10-19T21:00:00+08:00',
                'timezone' => 'Asia/Shanghai',
            ],
            'evaluation_summary' => '加时2小时',
        ], 1);
        $s->reviewChange($projectId, $req2['id'], 16, ['decision' => 'approve', 'note' => '核准合规方案'], 2);

        // 客户接受变更
        $accepted = $s->customerAcceptChange($owner, $projectId, $req2['id'], 17, 'change-decision-key-00002', [
            'decision' => 'accept',
        ]);
        same('applied', $accepted['state']);
        same(true, $accepted['effective']);

        // 幂等重试同键返回同结果
        $retry = $s->customerAcceptChange($owner, $projectId, $req2['id'], 17, 'change-decision-key-00002', [
            'decision' => 'accept',
        ]);
        same($accepted, $retry);

        // 验证项目排期与预留时间均已原子更新为 21:00:00 (UTC 13:00:00)
        $projAfter = DB::table('scene_projects')->find($projectId);
        $snapAfter = SceneWorkflow::unpack($projAfter->snapshot);
        same('2026-10-19 13:00:00', SceneWorkflow::utc($snapAfter['schedule']['ends_at']));
        $resAfter = DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
        same('2026-10-19 13:00:00', $resAfter->service_ends_at);
        same('2026-10-19 13:00:00', $resAfter->occupies_until);
    }));

    test('SC4-03 降价变更产生退款差额：客户缩短服务，自动生成 refund_due 记录', fn () => sc4Sandbox(function ($s) use ($projectId, $owner) {
        $req = $s->customerAction($owner, $projectId, 10, 'change-refund-001', 'change', [
            'reason' => '现场条件限制，服务提前结束并扣减费用',
        ]);
        $s->evaluateChange($projectId, $req['id'], 11, [
            'price_delta_minor' => -15000, // 减收150元
            'evaluation_summary' => '提前结束核减服务费150元',
        ], 1);
        $s->reviewChange($projectId, $req['id'], 12, ['decision' => 'approve', 'note' => '核减核准'], 2);
        $accepted = $s->customerAcceptChange($owner, $projectId, $req['id'], 13, 'change-refund-decision-001', ['decision' => 'accept']);
        same('applied', $accepted['state']);

        // 检查自动生成 refund_due 商业记录
        $refundRecord = DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', 'refund_due')->first();
        same(15000, (int) $refundRecord->amount_minor);
        same(null, $refundRecord->reviewed_at); // 待财务复核，不冒充已退款
    }));

    test('SC4-06 取消与政策联动：依政策核算违约扣费、释放预留、生成 refund_due 并关闭项目', fn () => sc4Sandbox(function ($s) use ($projectId, $owner, $quoteId) {
        // 先录入已支付款项
        DB::table('scene_commercial_records')->insert([
            'project_id' => $projectId,
            'kind' => 'payment_receipt',
            'amount_minor' => 100000, // 已付款 1000 元
            'reference' => 'TEST-PAY-001',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'reviewed_by' => 2,
            'reviewed_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // 客户发起取消申请
        $cancelReq = $s->customerAction($owner, $projectId, 10, 'cancel-key-00000001', 'cancel', [
            'reason' => '展会临时取消，申请全流程退单',
        ]);
        same('requested', $cancelReq['state']);

        // 拒绝分支：审核拒绝取消，项目保持原样
        $s->processCancellation($projectId, $cancelReq['id'], 11, [
            'decision' => 'reject',
            'note' => '现场已经开始布展，按合同不可全额取消',
        ], 2);
        same('rejected', DB::table('scene_project_changes')->find($cancelReq['id'])->state);
        same('delivering', DB::table('scene_projects')->find($projectId)->state);
        same('confirmed', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));

        // 重新发起并批准取消（扣除 20% 违约金）
        $cancelReq2 = $s->customerAction($owner, $projectId, 12, 'cancel-key-00000002', 'cancel', [
            'reason' => '双方协商按20%违约金取消',
        ]);
        $cancelled = $s->processCancellation($projectId, $cancelReq2['id'], 13, [
            'decision' => 'approve',
            'note' => '双方已签署取消协议，扣除200元违约金，退还800元',
        ], 2);

        same('cancelled', $cancelled['project_state']);
        same(80000, $cancelled['refund_due_minor']); // 应退 800 元
        same(20000, $cancelled['penalty_minor']);    // 扣除 200 元

        // 关键防护（Issue 6）：实物设备处于交付中且尚未返库，严禁直接释放预留！实物锁定维护状态并生成返库待办
        same('confirmed', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
        same('maintenance', DB::table('scene_resources')->where('id', 1)->value('state'));
        same(true, DB::table('scene_operation_todos')->where('dedupe_key', 'cancellation-return:'.$projectId)->exists());

        // 验证已生成对应 refund_due
        $refundRow = DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', 'refund_due')->orderByDesc('id')->first();
        same(80000, (int) $refundRow->amount_minor);

        // 已取消项目不可再次发起任务或变更
        error('SCENE_STATE_CONFLICT', fn () => $s->customerAction($owner, $projectId, 14, 'cancel-key-00000003', 'change', ['reason' => '已取消项目试图变更']));
    }));

    test('SC4-07 售后工单与技能 D10 校验：无授权报障被拒、合法技能关联工单、客服技术闭环不绕过退款政策', fn () => sc4Sandbox(function ($s) use ($projectId, $owner) {
        // 1. 无有效授权或未指定技能时，报障被拒
        error('SCENE_SKILL_ENTITLEMENT_INVALID', fn () => $s->customerAction($owner, $projectId, 10, 'ticket-invalid-01', 'after_sale', [
            'reason' => '技能无法启动',
            'scope' => 'skill_fault',
        ]));
        error('SCENE_SKILL_ENTITLEMENT_INVALID', fn () => $s->customerAction($owner, $projectId, 10, 'ticket-invalid-02', 'after_sale', [
            'reason' => '他人技能无法启动',
            'scope' => 'skill_fault',
            'entitlement_id' => 99999, // 不存在的授权
        ]));

        // 2. 模拟客户拥有的合法技能授权
        $skillFixture = sceneSkillAfterSaleFixture($projectId);
        $entitlementId = $skillFixture['entitlement'];

        // 技能报障成功建立
        $ticket = $s->customerAction($owner, $projectId, 10, 'ticket-valid-00001', 'after_sale', [
            'reason' => '特定技能场景下语音唤醒无响应',
            'scope' => 'skill_fault',
            'entitlement_id' => $entitlementId,
        ]);
        same('open', $ticket['state']);
        $ticketId = $ticket['id'];

        // 3. 技能退款继续走 D10，场景客服仅关闭技术支持工单。
        error('SCENE_SKILL_REFUND_REQUIRES_D10', fn () => $s->resolveTicket($ticketId, 1, 'QA 尝试从场景绕过技能退款审核', 2, 5000));
        $resolved = $s->resolveTicket($ticketId, 1, '已排查为麦克风降噪参数问题，现场已更新配置；退款需另走技能售后', 2);
        same('resolved', $resolved['state']);
        same(0, $resolved['refund_proposal_minor']);

        // 检查退款记录产生
        same(0, DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', 'refund_due')->count());
    }));

    test('SC4-08 现场事故关联与安全暂停/恢复：设备进入维护状态、交付任务暂停并能精准恢复', fn () => sc4Sandbox(function ($s) use ($projectId) {
        // 1. 上报事故并暂停项目
        $incident = $s->reportIncident($projectId, 10, [
            'incident_ref' => 'INC-20261022-SH-001',
            'reason' => '机器人传感器受强光直射出现避障漂移，已急停排查',
            'pause_project' => true,
        ], 1);
        same('paused', $incident['state']);
        same('INC-20261022-SH-001', $incident['incident_ref']);

        // 验证涉事实物资源自动被置为 maintenance 暂停状态
        $resource = DB::table('scene_resources')->find(1);
        same('maintenance', $resource->state);
        same(true, str_contains($resource->state_reason, 'INC-20261022-SH-001'));

        // 验证运营待办产生
        same(1, DB::table('scene_operation_todos')->where('dedupe_key', 'incident:'.$projectId.':INC-20261022-SH-001')->where('state', 'open')->count());

        // 2. 项目暂停状态下，交付任务被阻断
        $firstTask = DB::table('scene_project_tasks')->where('project_id', $projectId)->first();
        if ($firstTask) {
            error('SCENE_PROJECT_PAUSED', fn () => $s->completeTask($projectId, (int) $firstTask->id, 1, [
                'checks' => ['chk1'],
                'evidence_ids' => [1],
                'occurred_at' => '2026-10-19T10:00:00+08:00',
            ], 1));
        }

        // 3. 事故排查排除后恢复项目
        $resumed = $s->resumeProject($projectId, 11, 2);
        same('delivering', $resumed['state']); // 成功恢复至原阶段 delivering
        same(null, DB::table('scene_projects')->find($projectId)->paused_from_state);
    }));

    test('SC4-08 技能安全熔断：当项目涉及的技能处于熔断状态时阻断任务推进', fn () => sc4Sandbox(function ($s) use ($projectId, $attachmentId) {
        $snapshot = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot);
        // 使用真实转单结构 proposal.components 嵌套
        $snapshot['proposal'] = [
            'components' => [
                ['type' => 'skill', 'product_id' => 99],
            ],
        ];
        DB::table('scene_projects')->where('id', $projectId)->update([
            'snapshot' => SceneWorkflow::json($snapshot),
        ]);

        if (Schema::hasTable('skill_blocklist')) {
            DB::table('skill_blocklist')->insertOrIgnore([
                'skill_id' => 99,
                'status' => 'active',
                'action' => 'block_all',
                'reason' => '安全漏洞紧急熔断',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }

        $task = DB::table('scene_project_tasks')->where('project_id', $projectId)->where('task_key', 'arrival')->first();
        if ($task) {
            $checklist = SceneWorkflow::unpack($task->checklist);
            error('SCENE_SKILL_CIRCUIT_BROKEN', fn () => $s->completeTask($projectId, (int) $task->id, (int) $task->row_version, [
                'checks' => $checklist,
                'evidence_ids' => [$attachmentId],
                'occurred_at' => '2026-10-19T10:00:00+08:00',
            ], 1));
        }
    }));

    test('SC4-09 变更与退款严密性：项目终态守卫、取消退款扣减前期已退额、正价差应收核销', fn () => sc4Sandbox(function ($s) use ($projectId, $owner, $quoteId) {
        // 1. 测试取消退款扣除此前已退款（Issue 4）
        DB::table('scene_commercial_records')->insert([
            'project_id' => $projectId,
            'kind' => 'payment_receipt',
            'amount_minor' => 100000, // 已付 1000 元
            'reference' => 'PAY-FULL',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'reviewed_by' => 2,
            'reviewed_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        // 此前已因变更退款 400 元
        DB::table('scene_commercial_records')->insert([
            'project_id' => $projectId,
            'kind' => 'refund_due',
            'amount_minor' => 40000,
            'reference' => 'PREV-REFUND',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'reviewed_by' => 2,
            'reviewed_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $cancelReq = $s->customerAction($owner, $projectId, 10, 'cancel-net-key-000001', 'cancel', ['reason' => '取消']);
        $cancelled = $s->processCancellation($projectId, $cancelReq['id'], 11, ['decision' => 'approve', 'note' => '扣20%'], 2);
        // 按冻结政策对已复核总收款扣 20%；累计应退 80000，扣除已核准 40000，新增责任 40000。
        same(40000, $cancelled['refund_due_minor']);
        same(20000, $cancelled['penalty_minor']);

        // 2. 项目终态守卫（Issue 8）：项目已取消，不能再处理变更或接受旧变更
        $changeReq = DB::table('scene_project_changes')->insertGetId([
            'project_id' => $projectId,
            'kind' => 'change',
            'state' => 'pending_customer_acceptance',
            'base_version' => 10,
            'reason' => '旧变更',
            'requested_change' => SceneWorkflow::json(['schedule_delta' => []]),
            'principal_scope' => $owner->scope(),
            'price_delta_minor' => -10000,
            'evaluation' => SceneWorkflow::json([]),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        error('SCENE_STATE_CONFLICT', fn () => $s->customerAcceptChange($owner, $projectId, $changeReq, 12, 'stale-accept-key-00001', ['decision' => 'accept']));

        // 3. 正价差累加应收总额并在财务复核中放行（Issue 7）
        // 模拟一个正常履约中项目，清空此前取消测试商事记录
        DB::table('scene_projects')->where('id', $projectId)->update(['state' => 'delivering']);
        DB::table('scene_commercial_records')->where('project_id', $projectId)->delete();
        $quoteRow = DB::table('scene_quotes')->find($quoteId);
        $totalCapBefore = (int) $quoteRow->total_minor + (int) $quoteRow->deposit_minor;

        // 原报价全额付清
        DB::table('scene_commercial_records')->insert([
            'project_id' => $projectId,
            'kind' => 'payment_receipt',
            'amount_minor' => $totalCapBefore,
            'reference' => 'PAY-INIT',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'reviewed_by' => 2,
            'reviewed_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $changePositive = DB::table('scene_project_changes')->insertGetId([
            'project_id' => $projectId,
            'kind' => 'change',
            'state' => 'pending_customer_acceptance',
            'base_version' => 10,
            'reason' => '加时加收费用',
            'requested_change' => SceneWorkflow::json(['schedule_delta' => []]),
            'principal_scope' => $owner->scope(),
            'price_delta_minor' => 5000, // +50 元
            'evaluation' => SceneWorkflow::json([]),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $s->customerAcceptChange($owner, $projectId, $changePositive, 12, 'positive-accept-key-01', ['decision' => 'accept']);

        // 财务录入超出原报价但低于（原报价+正价差）的收款（追加30元）
        $recId = DB::table('scene_commercial_records')->insertGetId([
            'project_id' => $projectId,
            'kind' => 'payment_receipt',
            'amount_minor' => 3000,
            'reference' => 'EXTRA-PAYMENT-001',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        // 财务独立复核成功放行，不被旧报价上限拒绝
        $commReview = $s->reviewCommercial($projectId, $recId, 13, 2);
        same('manually_verified', $commReview['state']);

        // 再次录入超出新增总上限的收款，严格阻断
        $recExcess = DB::table('scene_commercial_records')->insertGetId([
            'project_id' => $projectId,
            'kind' => 'payment_receipt',
            'amount_minor' => 5000,
            'reference' => 'EXCESS-PAYMENT-001',
            'evidence' => SceneWorkflow::json([]),
            'recorded_by' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        error('SCENE_PAYMENT_LIMIT', fn () => $s->reviewCommercial($projectId, $recExcess, 14, 2));
    }));
} catch (Throwable $e) {
    echo 'SC4 测试加载失败: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine().PHP_EOL;
    throw $e;
}
