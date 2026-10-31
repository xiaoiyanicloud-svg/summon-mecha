<?php

// 复用真实迁移、服务和事务；只在隔离内存数据库运行。
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneCancellationPolicy;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneProposalService;
use Webkul\Shop\Services\Scenes\SceneSolutionService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

if (DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('P1 回归仅允许内存环境。');
}

function p1Snapshot(callable $change): void
{
    global $projectId;
    $snapshot = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot);
    $change($snapshot);
    DB::table('scene_projects')->where('id', $projectId)->update(['snapshot' => SceneWorkflow::json($snapshot)]);
}

function p1ZeroPolicy(int $paid = 10000): void
{
    p1Snapshot(function (&$snapshot) use ($paid) {
        $snapshot['commercial']['paid_minor'] = $paid;
        $snapshot['terms']['cancellation'] = ['version' => 'qa-explicit-zero-v1', 'tiers' => [['hours_before' => 0, 'penalty_bps' => 0]], 'default_penalty_bps' => 0];
    });
}

function p1Record(string $kind, int $amount, bool $reviewed = true): void
{
    global $projectId;
    DB::table('scene_commercial_records')->insert(['project_id' => $projectId, 'kind' => $kind, 'amount_minor' => $amount,
        'reference' => 'QA P1 '.$kind, 'evidence' => '[]', 'recorded_by' => 1, 'reviewed_by' => $reviewed ? 2 : null,
        'reviewed_at' => $reviewed ? now('UTC') : null, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
}

function p1CancelRequest(SceneProjectService $service): int
{
    global $projectId, $owner;

    return $service->customerAction($owner, $projectId, 10, 'p1-cancel-request-0001', 'cancel', ['reason' => 'QA 取消结算回归'])['id'];
}

function p1Cancel(SceneProjectService $service, int $change): array
{
    global $projectId;

    return $service->processCancellation($projectId, $change, 11, ['decision' => 'approve', 'note' => 'QA 独立核准取消'], 2);
}

function p1Change(SceneProjectService $service, array $input = []): int
{
    global $projectId, $owner;
    $change = $service->customerAction($owner, $projectId, 10, 'p1-change-request-0001', 'change', ['reason' => 'QA 改期资格回归']);
    $service->evaluateChange($projectId, $change['id'], 11, $input + ['price_delta_minor' => 0, 'evaluation_summary' => 'QA 独立评估'], 1);
    $service->reviewChange($projectId, $change['id'], 12, ['decision' => 'approve', 'note' => 'QA 独立批准'], 2);

    return $change['id'];
}

function p1Schedule(): array
{
    return ['starts_at' => '2026-10-22T09:00:00+08:00', 'ends_at' => '2026-10-22T18:00:00+08:00', 'timezone' => 'Asia/Shanghai'];
}

function p1AdminContext(callable $action, int $actor = 1): void
{
    $app = app();
    $keys = ['auth', Illuminate\Contracts\Routing\ResponseFactory::class];
    $bindings = $app->getBindings();
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = $app->resolved($key) && $app->bound($key) ? $app->make($key) : null;
    }
    $auth = new class($actor)
    {
        public function __construct(private int $actor) {}

        public function guard($name)
        {
            same('admin', $name);

            return $this;
        }

        public function id()
        {
            return $this->actor;
        }
    };
    $app->instance('auth', $auth);
    $view = new Illuminate\View\Factory(new Illuminate\View\Engines\EngineResolver, new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem, []), new Illuminate\Events\Dispatcher($app));
    $app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new Illuminate\Routing\ResponseFactory($view, new Illuminate\Routing\Redirector(app('url'))));
    (new Illuminate\Foundation\Providers\FoundationServiceProvider($app))->registerRequestValidation();
    try {
        $action(app(Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class));
    } finally {
        foreach ($previous as $key => $value) {
            $app->offsetUnset($key);
            if (isset($bindings[$key])) {
                $app->bind($key, $bindings[$key]['concrete'], $bindings[$key]['shared']);
            }
            if ($value) {
                $app->instance($key, $value);
            }
        }
    }
}

foreach ([0, 2000, 4000] as $refunded) {
    test('P1 取消：已核准应退 4000，实退 '.$refunded.'，新增责任均为 6000，不重复扣减', fn () => sc4Sandbox(function ($service) use ($refunded, $projectId) {
        p1ZeroPolicy();
        p1Record('refund_due', 4000);
        if ($refunded) {
            p1Record('refund_receipt', $refunded);
        }
        $result = p1Cancel($service, p1CancelRequest($service));
        same(6000, $result['refund_due_minor']);
        same(10000, (int) DB::table('scene_commercial_records')->where('project_id', $projectId)->where('kind', 'refund_due')->sum('amount_minor'));
        $settlement = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot)['cancellation_settlement'];
        same($refunded, $settlement['refunded_minor']);
        same(4000, $settlement['approved_refund_due_minor']);
    }));
}

test('P1 取消：未核准应退阻断结算，不改项目、资源和账目', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId) {
    p1ZeroPolicy();
    p1Record('refund_due', 4000, false);
    $change = p1CancelRequest($service);
    $before = (array) DB::table('scene_projects')->find($projectId);
    error('SCENE_REFUND_REVIEW_PENDING', fn () => p1Cancel($service, $change));
    same($before, (array) DB::table('scene_projects')->find($projectId));
    same('ready', DB::table('scene_resources')->find(1)->state);
    same('confirmed', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
    same(1, DB::table('scene_commercial_records')->where('project_id', $projectId)->count());
}));

test('P1 取消：无既有应退时新建全额责任，异常账目拒绝自动结算', function () {
    sc4Sandbox(function ($service) {
        p1ZeroPolicy();
        same(10000, p1Cancel($service, p1CancelRequest($service))['refund_due_minor']);
    });
    foreach ([[0, 1], [11000, 0]] as [$due, $receipt]) {
        sc4Sandbox(function ($service) use ($due, $receipt) {
            p1ZeroPolicy();
            if ($due) {
                p1Record('refund_due', $due);
            } if ($receipt) {
                p1Record('refund_receipt', $receipt);
            }
            $change = p1CancelRequest($service);
            error('SCENE_REFUND_LEDGER_CONFLICT', fn () => p1Cancel($service, $change));
        });
    }
    sc4Sandbox(function ($service) {
        p1ZeroPolicy();
        p1Snapshot(function (&$snapshot) {
            $snapshot['terms']['cancellation']['default_penalty_bps'] = 2000;
        });
        p1Record('refund_due', 9000);
        $change = p1CancelRequest($service);
        error('SCENE_CANCELLATION_SETTLEMENT_CONFLICT', fn () => p1Cancel($service, $change));
    });
});

test('P1 取消：缺少冻结政策时禁止借当前配置扣罚，客户端不得指定比例', fn () => sc4Sandbox(function ($service) use ($projectId) {
    p1ZeroPolicy();
    p1Snapshot(function (&$snapshot) {
        unset($snapshot['terms']['cancellation'], $snapshot['terms']['policy']['cancellation'], $snapshot['cancellation_policy']);
    });
    $prior = config('scenes.cancellation_policy');
    config(['scenes.cancellation_policy' => ['version' => 'qa-current', 'tiers' => [['hours_before' => 0, 'penalty_ratio' => 0.9]], 'default_penalty_ratio' => 0.9]]);
    try {
        $change = p1CancelRequest($service);
        error('SCENE_CANCELLATION_POLICY_MISSING', fn () => p1Cancel($service, $change));
        try {
            $service->processCancellation($projectId, $change, 11, ['decision' => 'approve', 'note' => 'QA 强行覆盖费率', 'penalty_ratio' => 0], 2);
            throw new RuntimeException('客户端可覆盖冻结费率');
        } catch (Illuminate\Validation\ValidationException) {
        }
        same('delivering', DB::table('scene_projects')->find($projectId)->state);
        same(0, DB::table('scene_commercial_records')->where('project_id', $projectId)->count());
    } finally {
        config(['scenes.cancellation_policy' => $prior]);
    }
}));

test('P1 取消：显式冻结比例规范化、时间档位和分币四舍五入，当前政策不改变旧费率', fn () => sc4Sandbox(function ($service) {
    p1ZeroPolicy(10003);
    p1Snapshot(function (&$snapshot) {
        $snapshot['terms']['cancellation'] = ['version' => 'accepted-legacy-v1', 'tiers' => [['hours_before' => 48, 'penalty_ratio' => '0.1'], ['hours_before' => 0, 'penalty_ratio' => '0.2']], 'default_penalty_ratio' => '0.3'];
    });
    $result = p1Cancel($service, p1CancelRequest($service));
    same(3001, $result['penalty_minor']);
    same(7002, $result['refund_due_minor']);
    $policy = app(SceneCancellationPolicy::class)->normalize(['version' => 'qa-time', 'tiers' => [['hours_before' => 0, 'penalty_bps' => 2000], ['hours_before' => 48, 'penalty_bps' => 1000]], 'default_penalty_bps' => 3000]);
    same(1000, app(SceneCancellationPolicy::class)->penalty($policy, now('UTC')->addHours(48)->toIso8601String(), 10000));
    same(2000, app(SceneCancellationPolicy::class)->penalty($policy, now('UTC')->toIso8601String(), 10000));
    error('SCENE_CANCELLATION_POLICY_INVALID', fn () => app(SceneCancellationPolicy::class)->normalize(['version' => 'invalid', 'tiers' => [['hours_before' => 0, 'penalty_bps' => 0]]]));
}));

test('P1 取消返库：校验证据、实际时序、重复提交；保留维护状态并关闭返库待办', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $attachmentId) {
    p1ZeroPolicy();
    p1Cancel($service, p1CancelRequest($service));
    $input = ['occurred_at' => '2026-10-19T20:00:00+08:00', 'parts_checked' => true, 'appearance_checked' => true, 'damage_dispute' => false, 'evidence_ids' => [$attachmentId]];
    try {
        $service->returnResources($projectId, 12, array_replace($input, ['evidence_ids' => []]), 2);
        throw new RuntimeException('允许无证据返库');
    } catch (Illuminate\Validation\ValidationException) {
    }
    DB::table('scene_project_tasks')->where('project_id', $projectId)->update(['state' => 'done', 'occurred_at' => '2026-10-19 13:00:00']);
    error('SCENE_RETURN_TIME_CONFLICT', fn () => $service->returnResources($projectId, 12, $input, 2));
    DB::table('scene_project_tasks')->where('project_id', $projectId)->update(['occurred_at' => '2026-10-19 10:00:00']);
    same(true, $service->returnResources($projectId, 12, $input, 2)['returned']);
    same('cancelled', DB::table('scene_projects')->find($projectId)->state);
    same('maintenance', DB::table('scene_resources')->find(1)->state);
    same('released', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
    same('resolved', DB::table('scene_operation_todos')->where('dedupe_key', 'cancellation-return:'.$projectId)->value('state'));
    error('SCENE_RETURN_STATE_INVALID', fn () => $service->returnResources($projectId, 13, $input, 2));
}));

test('P1 取消返库：返库后仍保留未到期的原维护缓冲，损坏争议不消除', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $attachmentId) {
    p1ZeroPolicy();
    DB::table('scene_reservations')->where('quote_id', $quoteId)->update(['occupies_until' => '2026-10-19 22:00:00']);
    p1Cancel($service, p1CancelRequest($service));
    DB::table('scene_project_tasks')->where('project_id', $projectId)->update(['state' => 'pending', 'occurred_at' => null]);
    $service->returnResources($projectId, 12, ['occurred_at' => '2026-10-20T07:00:00+08:00', 'parts_checked' => true, 'appearance_checked' => true, 'damage_dispute' => true, 'evidence_ids' => [$attachmentId]], 2);
    $row = DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
    same('confirmed', $row->state);
    same('2026-10-20 03:00:00', $row->occupies_until);
    same(true, (bool) DB::table('scene_projects')->find($projectId)->open_dispute);
    same('maintenance', DB::table('scene_resources')->find(1)->state);
}));

test('P1 取消返库：提前返库不产生倒置的资源占用区间', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $attachmentId) {
    p1ZeroPolicy();
    DB::table('scene_reservations')->where('quote_id', $quoteId)->update(['service_starts_at' => '2026-10-22 01:00:00', 'service_ends_at' => '2026-10-22 10:00:00', 'occupies_from' => '2026-10-22 00:00:00', 'occupies_until' => '2026-10-22 12:00:00']);
    DB::table('scene_project_tasks')->where('project_id', $projectId)->update(['state' => 'pending', 'occurred_at' => null]);
    p1Cancel($service, p1CancelRequest($service));
    $service->returnResources($projectId, 12, ['occurred_at' => '2026-10-19T20:00:00+08:00', 'parts_checked' => true, 'appearance_checked' => true, 'damage_dispute' => false, 'evidence_ids' => [$attachmentId]], 2);
    $row = DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
    same('2026-10-19 12:00:00', $row->occupies_from);
    same('2026-10-19 14:00:00', $row->occupies_until);
    same('released', $row->state);
}));

test('P1 取消与返库：强审计失败回滚资金、项目、预留与返库事实', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $attachmentId) {
    p1ZeroPolicy();
    $change = p1CancelRequest($service);
    sceneAuditFailure(fn () => p1Cancel($service, $change));
    same('delivering', DB::table('scene_projects')->find($projectId)->state);
    same(0, DB::table('scene_commercial_records')->where('project_id', $projectId)->count());
    same('ready', DB::table('scene_resources')->find(1)->state);
    p1Cancel($service, $change);
    $before = (array) DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
    sceneAuditFailure(fn () => $service->returnResources($projectId, 12, ['occurred_at' => '2026-10-19T20:00:00+08:00', 'parts_checked' => true, 'appearance_checked' => true, 'damage_dispute' => false, 'evidence_ids' => [$attachmentId]], 2));
    same(false, (bool) DB::table('scene_projects')->find($projectId)->returned);
    same($before, (array) DB::table('scene_reservations')->where('quote_id', $quoteId)->first());
}));

foreach (['maintenance', 'expired', 'shift', 'city', 'binding'] as $invalid) {
    test('P1 改期：'.$invalid.' 资格失败，客户决定、价格与预留全部回滚', fn () => sc4Sandbox(function ($service) use ($invalid, $projectId, $quoteId, $owner) {
        $change = p1Change($service, ['price_delta_minor' => 30000, 'schedule_delta' => p1Schedule()]);
        $code = 'SCENE_RESOURCE_UNAVAILABLE';
        if ($invalid === 'maintenance') {
            DB::table('scene_resources')->where('id', 1)->update(['state' => 'maintenance']);
        }
        if ($invalid === 'expired') {
            DB::table('scene_resources')->where('id', 1)->update(['verification_expires_at' => '2026-10-21 00:00:00']);
        }
        if ($invalid === 'shift') {
            DB::table('scene_resources')->where('id', 1)->update(['availability' => SceneWorkflow::json([['from' => '2026-10-22 01:00:00', 'until' => '2026-10-22 09:59:59']])]);
            $code = 'SCENE_RESOURCE_OUTSIDE_SHIFT';
        }
        if ($invalid === 'city') {
            DB::table('scene_resources')->where('id', 1)->update(['city' => '北京']);
        }
        if ($invalid === 'binding') {
            DB::table('device_binding')->where('id', DB::table('scene_resources')->find(1)->binding_id)->update(['bind_status' => 'unbound']);
            $code = 'SCENE_BINDING_UNAVAILABLE';
        }
        $project = (array) DB::table('scene_projects')->find($projectId);
        $reservation = (array) DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
        $events = DB::table('business_outbox')->count();
        error($code, fn () => $service->customerAcceptChange($owner, $projectId, $change, 13, 'p1-change-decision-0001', ['decision' => 'accept']));
        same($project, (array) DB::table('scene_projects')->find($projectId));
        same($reservation, (array) DB::table('scene_reservations')->where('quote_id', $quoteId)->first());
        same('pending_customer_acceptance', DB::table('scene_project_changes')->find($change)->state);
        same($events, DB::table('business_outbox')->count());
    }));
}

test('P1 改期：合法排期保持运输与维护缓冲并冻结新的核验事实', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $owner) {
    DB::table('scene_reservations')->where('quote_id', $quoteId)->update(['occupies_from' => '2026-10-19 08:00:00', 'occupies_until' => '2026-10-19 20:00:00']);
    $change = p1Change($service, ['schedule_delta' => p1Schedule()]);
    same(true, $service->customerAcceptChange($owner, $projectId, $change, 13, 'p1-change-decision-0001', ['decision' => 'accept'])['effective']);
    $row = DB::table('scene_reservations')->where('quote_id', $quoteId)->first();
    same('2026-10-22 00:00:00', $row->occupies_from);
    same('2026-10-22 12:00:00', $row->occupies_until);
    $snapshot = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot);
    same('2026-10-19 09:00:00', $snapshot['resource_changes'][0]['previous_schedule']['starts_at']);
    same('2026-10-22 10:00:00', $snapshot['schedule']['service_ends_at']);
    same(1, $snapshot['resources'][0]['resource']['resource_id']);
    same(true, isset(SceneWorkflow::unpack($row->resource_snapshot)['verification_hash']));
}));

test('P1 改期：其他有效预留冲突仍拒绝，失效 hold 不误阻断', fn () => sc4Sandbox(function ($service) use ($projectId, $quoteId, $owner) {
    $quote = (array) DB::table('scene_quotes')->find($quoteId);
    unset($quote['id']);
    $quote['version'] += 100;
    $quote['quote_no'] = (string) Illuminate\Support\Str::uuid();
    $otherQuote = DB::table('scene_quotes')->insertGetId($quote);
    $id = DB::table('scene_reservations')->insertGetId(['resource_id' => 1, 'quote_id' => $otherQuote, 'hold_group' => 'p1-collision', 'state' => 'held', 'hold_expires_at' => now('UTC')->addHour(), 'service_starts_at' => '2026-10-22 01:00:00', 'service_ends_at' => '2026-10-22 10:00:00', 'occupies_from' => '2026-10-22 00:00:00', 'occupies_until' => '2026-10-22 11:00:00', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $change = p1Change($service, ['schedule_delta' => p1Schedule()]);
    error('SCENE_RESOURCE_CONFLICT', fn () => $service->customerAcceptChange($owner, $projectId, $change, 13, 'p1-change-decision-0001', ['decision' => 'accept']));
    DB::table('scene_reservations')->where('id', $id)->update(['hold_expires_at' => now('UTC')->subSecond()]);
    same(true, $service->customerAcceptChange($owner, $projectId, $change, 13, 'p1-change-decision-0001', ['decision' => 'accept'])['effective']);
}));

test('P1 正价差：真实生产事件可消费，重投不重复应收或 receipt', fn () => sc4Sandbox(function ($service) use ($projectId, $owner) {
    DB::table('business_outbox')->delete();
    $before = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot)['commercial']['additional_receivable_minor'] ?? 0;
    $change = p1Change($service, ['price_delta_minor' => 30000]);
    $service->customerAcceptChange($owner, $projectId, $change, 13, 'p1-change-decision-0001', ['decision' => 'accept']);
    $event = DB::table('business_outbox')->where('event_type', 'scene.project.receivable_increased')->sole();
    $operations = app(SceneOperationsService::class);
    for ($i = 0; $i < 20; $i++) {
        $result = $operations->consumeOne();
        if ($result['state'] === 'idle') {
            break;
        } same('processed', $result['state']);
    }
    same(true, DB::table('business_outbox')->find($event->id)->processed_at !== null);
    same(1, DB::table('business_event_receipts')->where('event_id', $event->event_id)->count());
    DB::table('business_outbox')->where('id', $event->id)->update(['processed_at' => null]);
    same('processed', $operations->consumeOne()['state']);
    same(1, DB::table('business_event_receipts')->where('event_id', $event->event_id)->count());
    same($before + 30000, SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot)['commercial']['additional_receivable_minor']);
}));

test('P1 商务政策：独立批准后真实报价冻结取消规则及 hash，当前新版不追改原约定', fn () => sceneSandbox(function () {
    $defaults = (require app()->basePath('config/scene_policy_drafts.php'))['commercial'];
    $defaults['supplier_name'] = 'QA 隔离供应方';
    $defaults['supplier_contact'] = 'QA 站内联系';
    $defaults['required_price_codes'] = ['robot.test', 'transport.test'];
    $defaults['cancellation'] = ['tiers' => [['hours_before' => 48, 'penalty_bps' => 1000]], 'default_penalty_bps' => 2000];
    $draft = null;
    p1AdminContext(function ($controller) use ($defaults, &$draft) {
        $payload = $defaults;
        unset($payload['cancellation'], $payload['required_price_codes']);
        $payload['cancellation_json'] = json_encode($defaults['cancellation']);
        $payload['required_price_codes_text'] = implode("\n", $defaults['required_price_codes']);
        $request = Illuminate\Http\Request::create(route('admin.catalog.scene-workflow.policy-draft', 'commercial'), 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $draft = json_decode($controller->policyDraft($request, 'commercial', app(Webkul\Shop\Services\Scenes\ScenePolicyService::class))->getContent(), true)['data'];
    });
    app(Webkul\Shop\Services\Scenes\ScenePolicyService::class)->decide($draft['id'], 1, 'approve', 'QA 独立批准取消政策', 2);
    $approved = app(Webkul\Shop\Services\Scenes\ScenePolicyService::class)->effective('commercial');
    $fixture = sc2Fixture();
    $solution = app(SceneSolutionService::class)->draft($fixture['request']->id, 3, sceneSolutionPayload($fixture['assessment']), 1, 1);
    app(SceneSolutionService::class)->decide($solution['id'], 1, 'approve', 'QA 独立批准方案', 2, 1);
    $service = app(SceneProposalService::class);
    $quote = $service->draft($fixture['request']->id, 3, quoteFixtureInput($fixture['assessment']->id, ['proposal_id' => $solution['id']]), 1, 1);
    $row = DB::table('scene_quotes')->find($quote['id']);
    $frozen = SceneWorkflow::unpack($row->terms_snapshot);
    same($approved['version'], $frozen['cancellation']['version']);
    same(1000, $frozen['cancellation']['tiers'][0]['penalty_bps']);
    same(SceneWorkflow::hash($frozen), $row->terms_hash);
    sendFixtureQuote($quote['id']);
    same($frozen['cancellation'], $service->customerDto(DB::table('scene_quotes')->find($quote['id']))['cancellation']);
    $defaults['cancellation']['default_penalty_bps'] = 9000;
    scenePolicy('commercial', $defaults);
    same($row->terms_snapshot, DB::table('scene_quotes')->find($quote['id'])->terms_snapshot);
    same($row->terms_hash, DB::table('scene_quotes')->find($quote['id'])->terms_hash);
}));

test('P1 Admin：真实详情 Blade 渲染六个表单分支；URL、字段与服务端权限契约一致', fn () => sc4Sandbox(function ($service) use ($projectId) {
    global $app;
    $project = DB::table('scene_projects')->find($projectId);
    $record = DB::table('scene_requests')->find($project->request_id);
    $revision = DB::table('scene_request_revisions')->where('request_id', $record->id)->where('revision_no', $record->revision_no)->first();
    $data = ['record' => $record, 'revision' => $revision, 'facts' => SceneWorkflow::unpack($revision->facts), 'schema' => SceneWorkflow::unpack($revision->schema_snapshot),
        'projects' => collect([$project]), 'attachments' => DB::table('scene_attachments')->where('request_id', $record->id)->get(), 'admins' => DB::table('admins')->get()];
    foreach (['surveys', 'assessments', 'solutions', 'quotes', 'prices', 'resources', 'decisions', 'tasks', 'offlineConfirmations', 'commercial', 'activities'] as $key) {
        $data[$key] = collect();
    }
    $data['projectChanges'] = collect(array_map(fn ($values) => (object) ($values + ['project_id' => $projectId, 'reason' => 'QA <unsafe>', 'price_delta_minor' => 100]), [
        ['id' => 701, 'kind' => 'cancel', 'state' => 'requested'], ['id' => 702, 'kind' => 'change', 'state' => 'requested'], ['id' => 703, 'kind' => 'change', 'state' => 'evaluated'],
    ]));
    $data['tickets'] = collect([(object) ['id' => 704, 'state' => 'open', 'scope' => 'delivery', 'description' => 'QA 工单', 'row_version' => 1]]);
    $cache = app()->storagePath('blade-p1');
    if (! is_dir($cache)) {
        mkdir($cache, 0700);
    }
    $compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $cache);
    $compiler->withoutComponentTags();
    $resolver = new Illuminate\View\Engines\EngineResolver;
    $resolver->register('blade', fn () => new Illuminate\View\Engines\CompilerEngine($compiler));
    $finder = new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem, []);
    $finder->addNamespace('admin', app()->basePath('packages/Webkul/Admin/src/Resources/views'));
    $view = new Illuminate\View\Factory($resolver, $finder, new Illuminate\Events\Dispatcher($app));
    $view->setContainer($app);
    $view->share('errors', new Illuminate\Support\ViewErrorBag);
    $priorBouncer = Webkul\User\Facades\Bouncer::getFacadeRoot();
    $bouncer = new class
    {
        public array $allowed = [];

        public function hasPermission($key)
        {
            return in_array($key, $this->allowed, true);
        }
    };
    Webkul\User\Facades\Bouncer::swap($bouncer);
    $routes = ['incident' => 'deliver', 'resume' => 'deliver', 'cancel-execute' => 'approve', 'change-evaluate' => 'assess', 'change-review' => 'approve', 'ticket' => 'support'];
    $guard = new Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
    try {
        foreach (['deliver', 'approve', 'assess', 'support', 'requests'] as $permission) {
            $bouncer->allowed = ['catalog.scene-workflow.'.$permission];
            foreach (['delivering', 'paused'] as $state) {
                $project->state = $state;
                $html = $view->make('admin::catalog.scene-workflow.show', $data)->render();
                same(false, str_contains($html, 'QA <unsafe>'));
                foreach ($routes as $name => $required) {
                    $url = route('admin.catalog.scene-workflow.'.$name, ['id' => $name === 'ticket' ? 704 : $projectId, 'changeId' => ['cancel-execute' => 701, 'change-evaluate' => 702, 'change-review' => 703][$name] ?? 1]);
                    $url = strtok($url, '?');
                    $visible = $permission === $required && ($name !== 'resume' || $state === 'paused') && ($name !== 'incident' || $state !== 'paused');
                    same($visible, str_contains($html, 'action="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"'));
                    $request = Illuminate\Http\Request::create($url, 'POST');
                    $route = app('router')->getRoutes()->match($request);
                    $request->setRouteResolver(fn () => $route);
                    if ($permission === $required) {
                        same(true, $guard->handle($request, fn () => true));
                    } else {
                        try {
                            $guard->handle($request, fn () => true);
                            throw new RuntimeException('越权表单路由 '.$name);
                        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
                            same(403, $e->getStatusCode());
                        }
                    }
                }
                if ($permission === 'deliver' && $state === 'delivering') {
                    preg_match('~<form[^>]+action="[^"]+/incident"[\s\S]*?</form>~', $html, $match);
                    same(true, isset($match[0]));
                    $incidentHtml = $match[0];
                    same(true, str_contains($match[0], 'name="reason"'));
                    same(true, str_contains($match[0], 'name="pause_project" value="1"'));
                    same(false, str_contains($match[0], 'incident_summary'));
                }
            }
        }
        $bouncer->allowed = ['catalog.scene-workflow.support'];
        $data['tickets'][0]->scope = 'skill_fault';
        $data['tickets'][0]->entitlement_id = 900;
        $data['tickets'][0]->order_item_id = 901;
        $skillHtml = $view->make('admin::catalog.scene-workflow.show', $data)->render();
        same(true, str_contains($skillHtml, '技能授权 #900'));
        same(true, str_contains($skillHtml, '订单明细 #901'));
        same(true, str_contains($skillHtml, '既有 D10 售后申请与独立审核'));
        same(true, str_contains($skillHtml, '<input type="hidden" name="refund_proposal_minor" value="0">'));
        same(false, str_contains($skillHtml, 'name="refund_proposal_minor" type="number"'));
        p1AdminContext(function ($controller) use ($service, $projectId, $incidentHtml) {
            preg_match_all('~<input[^>]+name="([^"]+)"[^>]+value="([^"]*)"[^>]*>~', $incidentHtml, $inputs, PREG_SET_ORDER);
            $payload = ['reason' => 'QA 表单字段契约验证', 'incident_ref' => 'QA-P1-INCIDENT'];
            foreach ($inputs as $input) {
                $payload[$input[1]] = html_entity_decode($input[2]);
            }
            $request = Illuminate\Http\Request::create(route('admin.catalog.scene-workflow.incident', $projectId), 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
            $response = $controller->reportIncident($request, $projectId, $service);
            same(200, $response->getStatusCode());
            same('paused', json_decode($response->getContent(), true)['data']['state']);
        });
    } finally {
        Webkul\User\Facades\Bouncer::swap($priorBouncer);
        foreach (glob($cache.'/*.php') as $file) {
            unlink($file);
        } rmdir($cache);
    }
}));

test('P1 Admin：评估表单未填可选改期时可正常提交，部分填写不能绕过完整时间校验', fn () => sc4Sandbox(function ($service) use ($projectId, $owner) {
    $change = $service->customerAction($owner, $projectId, 10, 'p1-empty-schedule-0001', 'change', ['reason' => 'QA 只调整服务内容']);
    p1AdminContext(function ($controller) use ($service, $projectId, $change) {
        $payload = ['row_version' => '11', 'price_delta_minor' => '30000', 'evaluation_summary' => 'QA 只调价不改期', 'schedule_delta' => ['starts_at' => '', 'ends_at' => '', 'timezone' => '']];
        $request = Illuminate\Http\Request::create(route('admin.catalog.scene-workflow.change-evaluate', ['id' => $projectId, 'changeId' => $change['id']]), 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
        same('evaluated', json_decode($controller->evaluateChange($request, $projectId, $change['id'], $service)->getContent(), true)['data']['state']);
        same(null, SceneWorkflow::unpack(DB::table('scene_project_changes')->find($change['id'])->evaluation)['schedule_delta']);
        $payload['row_version'] = '12';
        $payload['schedule_delta']['timezone'] = 'Asia/Shanghai';
        $request->replace($payload);
        try {
            $controller->evaluateChange($request, $projectId, $change['id'], $service);
            throw new RuntimeException('不完整改期被接受');
        } catch (Illuminate\Validation\ValidationException) {
        }
        same(12, (int) DB::table('scene_projects')->find($projectId)->row_version);
    });
}));
