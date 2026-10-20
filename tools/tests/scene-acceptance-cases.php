<?php

// 由 scene-workflow.php 的内存数据库环境加载，不可独立 bootstrap 业务应用。
if (! isset($schema, $app, $projectId) || \Illuminate\Support\Facades\DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('仅允许内存测试环境。');
}

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneAcceptanceService;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneTime;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

$originalStorage = $app->storagePath();
$acceptanceStorage = tempnam(sys_get_temp_dir(), 'scene-offline-');
unlink($acceptanceStorage);
mkdir($acceptanceStorage, 0700);
mkdir($acceptanceStorage.'/app', 0700);
mkdir($acceptanceStorage.'/app/private', 0700);
mkdir($acceptanceStorage.'/app/private/scenes', 0700);
$proofKey = '00000000-0000-4000-8000-000000000299';
$proofPath = $acceptanceStorage.'/app/private/scenes/'.$proofKey;
$proofContent = "%PDF-1.4\n隔离客户线下验收凭证\n%%EOF";
file_put_contents($proofPath, $proofContent);
$app->useStoragePath($acceptanceStorage);

function acceptanceSandbox(callable $fn): void
{
    global $projectId, $attachmentId, $proofKey, $proofPath, $proofContent;
    sceneSandbox(function () use ($fn, $projectId, $attachmentId, $proofKey, $proofPath, $proofContent) {
        $snapshot = SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot);
        $snapshot['acceptance_template'] = [['key'=>'greeting', 'label'=>'迎宾', 'required'=>true], ['key'=>'safety', 'label'=>'安全', 'required'=>true]];
        DB::table('scene_projects')->where('id', $projectId)->update(['state'=>'awaiting_acceptance', 'row_version'=>1, 'returned'=>false, 'open_dispute'=>false, 'snapshot'=>SceneWorkflow::json($snapshot)]);
        DB::table('scene_acceptance_records')->where('project_id', $projectId)->delete();
        DB::table('scene_attachments')->where('id', $attachmentId)->update(['storage_key'=>$proofKey, 'sha256'=>hash('sha256',$proofContent), 'state'=>'clean', 'expires_at'=>'2027-01-01 00:00:00']);
        file_put_contents($proofPath, $proofContent);
        try {
            $fn(app(SceneAcceptanceService::class));
        } finally {
            file_put_contents($proofPath, $proofContent);
        }
    });
}
function offlinePayload(array $items = []): array
{
    global $attachmentId;

    return ['signatory_name'=>'测试客户代表', 'authority_basis'=>'隔离测试授权文件已核对', 'authority_confirmed'=>true, 'medium'=>'signed_document',
        'occurred_at'=>'2026-10-19T18:00:00+08:00', 'evidence_ids'=>[$attachmentId],
        'items'=>$items ?: [['key'=>'greeting','result'=>'pass'], ['key'=>'safety','result'=>'pass']]];
}
function proofReview(SceneAcceptanceService $service, array $record, string $decision = 'approve', string $key = 'offline-review-key-0001'): array
{
    global $projectId;

    return $service->reviewOffline($projectId, $record['id'], $record['project_row_version'], $record['row_version'], $key, ['decision'=>$decision, 'note'=>'已核验原始凭证和客户代表资格'], 2);
}

try {
    test('实际时间严格含 offset，拒绝非法日期、未来时间与超范围 offset；统一存 UTC', function () {
        same('2026-10-19 10:00:00', SceneTime::occurredAt('2026-10-19T18:00:00+08:00'));
        foreach (['2026-10-19 18:00:00', '2026-02-30T12:00:00Z', '2026-10-20T00:00:01Z', '2026-10-19T12:00:00+15:00', '2026-10-19T12:00:00+08:99'] as $value) {
            error('SCENE_EVENT_TIME_INVALID', fn () => SceneTime::occurredAt($value));
        }
    });
    test('线下代录冻结凭证、加密确认人并强审计；尚不产生通过事实或改变验收状态', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $record = $s->recordOffline($projectId, 1, 'offline-record-key-0001', offlinePayload(), 1);
        same(false, $record['effective']);
        same('pending_review', $record['state']);
        same('awaiting_acceptance', DB::table('scene_projects')->find($projectId)->state);
        same(0, DB::table('scene_acceptance_records')->where('project_id',$projectId)->count());
        $row = DB::table('scene_offline_acceptances')->find($record['id']);
        same('2026-10-19 10:00:00', $row->occurred_at);
        same(false, str_contains($row->encrypted_attestation,'测试客户代表'));
        same('测试客户代表', SceneWorkflow::unpack(Crypt::decryptString($row->encrypted_attestation))['signatory_name']);
        same(1, DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$record['id'])->where('state','open')->count());
        $events = DB::table('business_outbox')->where('event_type','scene.project.offline-acceptance.recorded')->pluck('payload')->implode('');
        same(false, str_contains($events, '测试客户代表'));
        same(false, str_contains($events, '授权文件'));
    }));
    test('线下代录丢响应原键返回原回执；变载荷冲突，另键不能并行堆积待复核记录', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $payload = offlinePayload();
        $first = $s->recordOffline($projectId, 1, 'offline-record-key-0002', $payload, 1);
        same($first,$s->recordOffline($projectId,1,'offline-record-key-0002',$payload,1));
        error('IDEMPOTENCY_CONFLICT',fn () => $s->recordOffline($projectId,1,'offline-record-key-0002',['signatory_name'=>'另一个人']+$payload,1));
        error('SCENE_OFFLINE_REVIEW_PENDING',fn () => $s->recordOffline($projectId,2,'offline-record-key-other',$payload,1));
        same(1,DB::table('scene_offline_acceptances')->where('project_id',$projectId)->count());
    }));
    test('无客户代表确认、无原件、重复或未知验收项均拒绝代录且无半记录', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        foreach ([['authority_confirmed'=>false], ['evidence_ids'=>[]], ['items'=>[['key'=>'greeting','result'=>'pass'],['key'=>'greeting','result'=>'fail']]]] as $patch) {
            try {
                $s->recordOffline($projectId, 1, 'offline-invalid-key-001', $patch + offlinePayload(), 1);
                throw new RuntimeException('非法代录未拒绝');
            } catch (Illuminate\Validation\ValidationException) {
            }
        }
        error('SCENE_ACCEPTANCE_ITEM_UNKNOWN', fn () => $s->recordOffline($projectId,1,'offline-invalid-key-002',offlinePayload([['key'=>'unknown','result'=>'pass']]),1));
        same(0,DB::table('scene_offline_acceptances')->where('project_id',$projectId)->count());
        same(1,(int)DB::table('scene_projects')->find($projectId)->row_version);
    }));
    test('凭证必须属于本需求、扫描通过且未过期，磁盘原件缺失亦不能代录', fn () => acceptanceSandbox(function ($s) use ($projectId,$attachmentId,$proofPath) {
        error('SCENE_EVIDENCE_INVALID',fn () => $s->recordOffline($projectId,1,'offline-bad-proof-001',['evidence_ids'=>[999]]+offlinePayload(),1));
        DB::table('scene_attachments')->where('id',$attachmentId)->update(['state'=>'rejected']);
        error('SCENE_EVIDENCE_INVALID',fn () => $s->recordOffline($projectId,1,'offline-bad-proof-002',offlinePayload(),1));
        DB::table('scene_attachments')->where('id',$attachmentId)->update(['state'=>'clean','expires_at'=>'2026-10-19 00:00:00']);
        error('SCENE_EVIDENCE_INVALID',fn () => $s->recordOffline($projectId,1,'offline-bad-proof-003',offlinePayload(),1));
        DB::table('scene_attachments')->where('id',$attachmentId)->update(['expires_at'=>'2027-01-01 00:00:00']);
        unlink($proofPath);
        error('SCENE_ATTACHMENT_UNAVAILABLE',fn () => $s->recordOffline($projectId,1,'offline-bad-proof-004',offlinePayload(),1));
    }));
    test('录入人不能自行复核，旧凭证版本及错误项目引用拒绝', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $r=$s->recordOffline($projectId,1,'offline-separation-001',offlinePayload(),1);
        error('SCENE_OFFLINE_REVIEW_CONFLICT',fn () => $s->reviewOffline($projectId,$r['id'],2,1,'offline-self-review-001',['decision'=>'approve','note'=>'自己审核不允许'],1));
        error('SCENE_VERSION_CONFLICT',fn () => $s->reviewOffline($projectId,$r['id'],2,2,'offline-stale-version-01',['decision'=>'approve','note'=>'版本不匹配'],2));
        error('SCENE_OBJECT_NOT_FOUND',fn () => $s->reviewOffline($projectId,999,2,1,'offline-wrong-object001',['decision'=>'approve','note'=>'对象不存在'],2));
    }));
    test('独立复核逐项写入验收且来源与时间真实；重复请求不追加；不自动退款或返库', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $r=$s->recordOffline($projectId,1,'offline-approve-record01',offlinePayload(),1);
        $review = proofReview($s, $r);
        same(true, $review['effective']);
        same('accepted', $review['project_state']);
        same($review,proofReview($s,$r));
        $rows = DB::table('scene_acceptance_records')->where('project_id', $projectId)->get();
        same(2, $rows->count());
        foreach ($rows as $row) {
            same('customer_offline_recorded', $row->method);
            same('admin:1', $row->principal_scope);
            same('2026-10-19 10:00:00', $row->occurred_at);
            same($r['id'], (int) $row->offline_acceptance_id);
        }
        same(false,(bool)DB::table('scene_projects')->find($projectId)->returned);
        same('resolved',DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$r['id'])->value('state'));
        error('SCENE_VERSION_CONFLICT',fn () => $s->reviewOffline($projectId,$r['id'],3,1,'offline-second-review01',['decision'=>'approve','note'=>'不得重复复核'],2));
    }));
    test('线下只确认部分必需项不会全项目通过；失败整改与后来在线复验保留所有原记录', fn () => acceptanceSandbox(function ($s) use ($projectId,$owner) {
        $r=$s->recordOffline($projectId,1,'offline-partial-record01',offlinePayload([['key'=>'greeting','result'=>'pass']]),1);
        same('awaiting_acceptance',proofReview($s,$r)['project_state']);
        $r2=$s->recordOffline($projectId,3,'offline-failure-record01',offlinePayload([['key'=>'safety','result'=>'fail']]),1);
        same('rectification',proofReview($s,$r2,'approve','offline-failure-review01')['project_state']);
        same('accepted',$s->online($owner,$projectId,5,'offline-online-retest01',['items'=>[['key'=>'safety','result'=>'pass','evidence_ids'=>[]]]])['state']);
        same(['pass','fail','pass'],DB::table('scene_acceptance_records')->where('project_id',$projectId)->orderBy('id')->pluck('result')->all());
        same('customer_retest_passed',DB::table('scene_operation_todos')->where('dedupe_key','rectify:'.$projectId.':safety:1')->value('resolution_code'));
    }));
    test('项目在代录后发生其他操作，旧凭证不能盖过最新事实；仍能有理由退回', fn () => acceptanceSandbox(function ($s) use ($projectId,$owner) {
        $r=$s->recordOffline($projectId,1,'offline-stale-record-001',offlinePayload(),1);
        $s->online($owner,$projectId,2,'offline-new-online-001',['items'=>[['key'=>'greeting','result'=>'fail','evidence_ids'=>[]]]]);
        $r['project_row_version']=3;
        error('SCENE_OFFLINE_ACCEPTANCE_STALE',fn () => proofReview($s,$r));
        same('rejected',proofReview($s,$r,'reject','offline-reject-stale01')['state']);
        same('rectification',DB::table('scene_projects')->find($projectId)->state);
        same(1,DB::table('scene_acceptance_records')->where('project_id',$projectId)->count());
    }));
    test('确认时间早于最近验收不得倒盖；旧记录无实际时间仍允许客户当前在线复验', fn () => acceptanceSandbox(function ($s) use ($projectId,$owner) {
        $s->online($owner,$projectId,1,'offline-later-online01',['items'=>[['key'=>'greeting','result'=>'fail','evidence_ids'=>[]]]]);
        $r=$s->recordOffline($projectId,2,'offline-backdated-001',offlinePayload(),1);
        error('SCENE_ACCEPTANCE_TIME_CONFLICT',fn () => proofReview($s,$r));
        proofReview($s,$r,'reject','offline-backdate-reject');
        DB::table('scene_acceptance_records')->where('project_id',$projectId)->update(['occurred_at'=>null]);
        same('accepted',$s->online($owner,$projectId,4,'offline-legacy-online01',['items'=>[['key'=>'greeting','result'=>'pass','evidence_ids'=>[]],['key'=>'safety','result'=>'pass','evidence_ids'=>[]]]])['state']);
    }));
    test('代录后原件内容或存储指纹变化、凭证到期均阻止复核，验收记录保持为空', fn () => acceptanceSandbox(function ($s) use ($projectId,$attachmentId,$proofPath,$proofContent) {
        $r=$s->recordOffline($projectId,1,'offline-proof-change001',offlinePayload(),1);
        file_put_contents($proofPath,'tampered');
        error('SCENE_ATTACHMENT_UNAVAILABLE',fn () => proofReview($s,$r));
        DB::table('scene_attachments')->where('id',$attachmentId)->update(['sha256'=>hash('sha256','tampered')]);
        error('SCENE_OFFLINE_EVIDENCE_CHANGED',fn () => proofReview($s,$r));
        file_put_contents($proofPath,$proofContent);
        DB::table('scene_attachments')->where('id',$attachmentId)->update(['sha256'=>hash('sha256',$proofContent),'expires_at'=>'2026-10-19 00:00:00']);
        error('SCENE_EVIDENCE_INVALID',fn () => proofReview($s,$r));
        same('pending_review',DB::table('scene_offline_acceptances')->find($r['id'])->state);
        same(0,DB::table('scene_acceptance_records')->where('project_id',$projectId)->count());
    }));
    test('冻结条款或代录内容被意外改动时指纹守卫拒绝复核', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $r=$s->recordOffline($projectId,1,'offline-tamper-record01',offlinePayload(),1);
        DB::table('scene_offline_acceptances')->where('id',$r['id'])->update(['items'=>'[]']);
        error('SCENE_OFFLINE_EVIDENCE_CHANGED',fn () => proofReview($s,$r));
        DB::table('scene_offline_acceptances')->where('id',$r['id'])->update(['template_hash'=>str_repeat('0',64)]);
        error('SCENE_OFFLINE_ACCEPTANCE_STALE',fn () => proofReview($s,$r));
    }));
    test('强审计失败回滚代录、复核、逐项结果、版本、待办和幂等回执', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $original=app(Webkul\Shop\Services\Ops\OperationLogger::class);
        $rejecting = new class extends Webkul\Shop\Services\Ops\OperationLogger
        {
            public string $target='scene.project.offline-acceptance.recorded';

            public function logOrFail(string $actorType, ?int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, array $payload = [], ?string $ip = null): Webkul\Shop\Models\OperationLog
            {
                if ($action === $this->target) {
                    throw new RuntimeException('TEST_AUDIT_FAILURE');
                }

                return parent::logOrFail($actorType,$actorId,$action,$targetType,$targetId,$payload,$ip);
            }
        };
        app()->instance(Webkul\Shop\Services\Ops\OperationLogger::class,$rejecting);
        try {
            $s->recordOffline($projectId, 1, 'offline-audit-fail-001', offlinePayload(), 1);
            throw new LogicException('未回滚');
        } catch (RuntimeException $e) {
            same('TEST_AUDIT_FAILURE', $e->getMessage());
        } finally {
            app()->instance(Webkul\Shop\Services\Ops\OperationLogger::class, $original);
        }
        same(0,DB::table('scene_offline_acceptances')->where('project_id',$projectId)->count());
        $r=$s->recordOffline($projectId,1,'offline-audit-record001',offlinePayload(),1);
        $before=DB::table('business_outbox')->count();
        $rejecting->target='scene.project.offline-acceptance.reviewed';
        app()->instance(Webkul\Shop\Services\Ops\OperationLogger::class,$rejecting);
        try {
            proofReview($s, $r);
            throw new LogicException('未回滚');
        } catch (RuntimeException $e) {
            same('TEST_AUDIT_FAILURE', $e->getMessage());
        } finally {
            app()->instance(Webkul\Shop\Services\Ops\OperationLogger::class, $original);
        }
        same('pending_review',DB::table('scene_offline_acceptances')->find($r['id'])->state);
        same(2,(int)DB::table('scene_projects')->find($projectId)->row_version);
        same(0, DB::table('scene_acceptance_records')->where('project_id', $projectId)->count());
        same($before, DB::table('business_outbox')->count());
        same('open',DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$r['id'])->value('state'));
        same('approved',proofReview($s,$r)['state']);
    }));
    test('客户 DTO 可见确认来源和复核进展，但不含确认人、资格资料、私有凭证路径和内部意见', fn () => acceptanceSandbox(function ($s) use ($projectId,$owner,$other) {
        $r = $s->recordOffline($projectId, 1, 'offline-dto-record-001', offlinePayload(), 1);
        proofReview($s, $r);
        $detail=app(SceneProjectService::class)->detail($owner,$projectId);
        same('approved',$detail['offline_confirmations'][0]->state);
        $json=SceneWorkflow::json($detail);
        foreach (['测试客户代表', '授权文件', 'review_note', 'encrypted_attestation', 'storage_key', 'authority_basis', 'recorded_by', 'reviewed_by'] as $private) {
            same(false, str_contains($json, $private));
        }
        error('SCENE_OBJECT_NOT_FOUND',fn () => app(SceneProjectService::class)->detail($other,$projectId));
    }));
    test('停写同时拒绝代录与复核，原客户历史来源仍可读', fn () => acceptanceSandbox(function ($s) use ($projectId,$owner) {
        $r=$s->recordOffline($projectId,1,'offline-disabled-record01',offlinePayload(),1);
        app('config')->set('scenes.project_actions_enabled',false);
        try {
            error('SCENE_WRITES_DISABLED',fn () => $s->recordOffline($projectId,2,'offline-disabled-record02',offlinePayload(),1));
            error('SCENE_WRITES_DISABLED',fn () => proofReview($s,$r));
            same('pending_review',app(SceneProjectService::class)->detail($owner,$projectId)['offline_confirmations'][0]->state);
        } finally {
            app('config')->set('scenes.project_actions_enabled', true);
        }
    }));
    test('运营扫描补回待复核投影，旧代录事件重放不重开已复核待办', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        $r=$s->recordOffline($projectId,1,'offline-worker-record001',offlinePayload(),1);
        DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$r['id'])->delete();
        $ops = app(SceneOperationsService::class);
        $ops->sweep();
        same('open',DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$r['id'])->value('state'));
        proofReview($s,$r);
        for ($i = 0; $i < 300; $i++) {
            if ($ops->consumeOne()['state'] === 'idle') {
                break;
            }
        }
        $event=DB::table('business_outbox')->where('event_type','scene.project.offline-acceptance.recorded')->orderByDesc('id')->first();
        DB::table('business_outbox')->where('id',$event->id)->update(['processed_at'=>null]);
        same('processed',$ops->consumeOne()['state']);
        same('resolved',DB::table('scene_operation_todos')->where('dedupe_key','offline-acceptance:'.$r['id'])->value('state'));
        same(1,DB::table('business_event_receipts')->where('event_id',$event->event_id)->count());
    }));
    test('交付任务实际时间不能早于前置；并行分支不能使 servicing 倒退 delivering', fn () => acceptanceSandbox(function () use ($projectId,$attachmentId) {
        $tasks=DB::table('scene_project_tasks')->where('project_id',$projectId)->orderBy('id')->get();
        $task=$tasks->last();
        DB::table('scene_projects')->where('id',$projectId)->update(['state'=>'servicing']);
        DB::table('scene_project_tasks')->where('id',$task->id)->update(['state'=>'pending','row_version'=>1,'project_state_after'=>'delivering']);
        $payload=['checks'=>SceneWorkflow::unpack($task->checklist),'evidence_ids'=>[$attachmentId],'occurred_at'=>'2026-10-19T11:59:59+08:00'];
        $service=app(SceneProjectService::class);
        error('SCENE_TASK_TIME_CONFLICT',fn () => $service->completeTask($projectId,(int)$task->id,1,$payload,1));
        $payload['occurred_at']='2026-10-19T14:00:00+08:00';
        same('servicing',$service->completeTask($projectId,(int)$task->id,1,$payload,1)['project_state']);
        same('2026-10-19 06:00:00',DB::table('scene_project_tasks')->find($task->id)->occurred_at);
    }));
    test('客户线下确认不能早于已记录现场服务，即使材料签章完备也不能通过', fn () => acceptanceSandbox(function ($s) use ($projectId) {
        error('SCENE_ACCEPTANCE_TIME_CONFLICT',fn () => $s->recordOffline($projectId,1,'offline-before-service01',['occurred_at'=>'2026-10-19T11:59:59+08:00']+offlinePayload(),1));
        same(0,DB::table('scene_offline_acceptances')->where('project_id',$projectId)->count());
    }));
    test('返库拒绝早于实际任务的时间；损坏暂停资源并为有效后续档期建待办，保留确认占用', fn () => acceptanceSandbox(function () use ($projectId,$attachmentId,$resourceId,$quoteId) {
        DB::table('scene_projects')->where('id',$projectId)->update(['state'=>'accepted']);
        $nextQuote = (array) DB::table('scene_quotes')->find($quoteId);
        unset($nextQuote['id']);
        $nextQuote['version'] = 1000;
        $nextQuote['quote_no'] = '00000000-0000-4000-8000-000000000399';
        $nextId=DB::table('scene_quotes')->insertGetId($nextQuote);
        DB::table('scene_resources')->where('id',$resourceId)->update(['state'=>'ready']);
        DB::table('scene_reservations')->where('resource_id',$resourceId)->delete();
        $reservation=['resource_id'=>$resourceId,'quote_id'=>$quoteId,'hold_group'=>'return-source-fixture','state'=>'confirmed',
            'service_starts_at'=>'2026-10-19 03:00:00','service_ends_at'=>'2026-10-19 10:00:00','occupies_from'=>'2026-10-19 02:00:00','occupies_until'=>'2026-10-19 11:00:00','created_at'=>now(),'updated_at'=>now()];
        DB::table('scene_reservations')->insert($reservation);
        $next=DB::table('scene_reservations')->insertGetId(['quote_id'=>$nextId,'hold_group'=>'return-next-fixture','service_starts_at'=>'2026-10-21 03:00:00','service_ends_at'=>'2026-10-21 10:00:00','occupies_from'=>'2026-10-21 02:00:00','occupies_until'=>'2026-10-21 11:00:00']+$reservation);
        $expired=DB::table('scene_reservations')->insertGetId(['quote_id'=>$nextId,'hold_group'=>'return-expired-fixture','state'=>'held','hold_expires_at'=>'2026-10-19 23:59:59','service_starts_at'=>'2026-10-21 03:00:00','service_ends_at'=>'2026-10-21 10:00:00','occupies_from'=>'2026-10-21 02:00:00','occupies_until'=>'2026-10-21 11:00:00']+$reservation);
        $person = (array) DB::table('scene_resources')->find($resourceId);
        unset($person['id']);
        $person['resource_no'] = 'operator-return-fixture';
        $person['kind'] = 'operator';
        $person['binding_id'] = null;
        $personId=DB::table('scene_resources')->insertGetId($person);
        DB::table('scene_reservations')->insert(['resource_id'=>$personId,'hold_group'=>'return-operator-fixture']+$reservation);
        $payload=['parts_checked'=>true,'appearance_checked'=>true,'damage_dispute'=>true,'evidence_ids'=>[$attachmentId],'occurred_at'=>'2026-10-19T11:59:59+08:00'];
        $service=app(SceneProjectService::class);
        error('SCENE_RETURN_TIME_CONFLICT',fn () => $service->returnResources($projectId,1,$payload,1));
        $payload['occurred_at']='2026-10-19T18:00:00+08:00';
        $service->returnResources($projectId,1,$payload,1);
        same('maintenance',DB::table('scene_resources')->find($resourceId)->state);
        same('ready',DB::table('scene_resources')->find($personId)->state);
        same(2,DB::table('scene_reservations')->where('resource_id',$resourceId)->where('state','confirmed')->count());
        same('open',DB::table('scene_operation_todos')->where('dedupe_key','return-impact:'.$projectId.':'.$next)->value('state'));
        same(0,DB::table('scene_operation_todos')->where('dedupe_key','return-impact:'.$projectId.':'.$expired)->count());
        same('return_damage_review',DB::table('scene_operation_todos')->where('dedupe_key','return-risk:'.$projectId.':'.$resourceId)->value('kind'));
        same(true,(bool)DB::table('scene_projects')->find($projectId)->open_dispute);
        same('2026-10-19 10:00:00',SceneWorkflow::unpack(DB::table('scene_projects')->find($projectId)->snapshot)['return_record']['occurred_at']);
    }));
    test('新代录与复核接口 ACL 独立：仅查看、交付、财务或录入不能直接复核', function () {
        $bouncer = new class
        {
            public array $allowed = [];

            public function hasPermission($key)
            {
                return in_array($key, $this->allowed, true);
            }
        };
        Webkul\User\Facades\Bouncer::swap($bouncer);
        $guard=new Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
        foreach (['recordOfflineAcceptance'=>'acceptance_record','reviewOfflineAcceptance'=>'acceptance_review'] as $method=>$permission) {
            $request=Illuminate\Http\Request::create('/test','POST');
            $handler=Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class.'@'.$method;
            $route = new Illuminate\Routing\Route('POST', 'test', ['uses' => $handler, 'controller' => $handler]);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            foreach (['view','deliver','finance','finance_review', $permission==='acceptance_review'?'acceptance_record':'acceptance_review'] as $wrong) {
                $bouncer->allowed=[$wrong==='view'?'catalog.scene-workflow':'catalog.scene-workflow.'.$wrong];
                try {
                    $guard->handle($request, fn () => true);
                    throw new RuntimeException('权限错误放行');
                } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    same(403, $e->getStatusCode());
                }
            }
            $bouncer->allowed = ['catalog.scene-workflow.'.$permission];
            same(true, $guard->handle($request, fn () => true));
        }
    });
    test('Admin Blade 实际渲染线下录入与复核表单、私有原件链接和转义；仅查看角色不输出确认人资料', fn () => acceptanceSandbox(function ($s) use ($projectId,$app,$acceptanceStorage) {
        $r=$s->recordOffline($projectId,1,'offline-blade-record001',['signatory_name'=>'客户<script>alert(1)</script>']+offlinePayload(),1);
        $router = app('router');
        $router->getRoutes()->refreshNameLookups();
        $app->instance('url',new Illuminate\Routing\UrlGenerator($router->getRoutes(),Illuminate\Http\Request::create('https://qa.invalid')));
        $session = new Illuminate\Session\Store('isolated-scene-qa', new Illuminate\Session\ArraySessionHandler(120));
        $session->start();
        $app->instance('session', $session);
        $compiler=new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem,$acceptanceStorage);
        $compiler->withoutComponentTags();
        $compiled=$compiler->compileString(file_get_contents($app->basePath('packages/Webkul/Admin/src/Resources/views/catalog/scene-workflow/show.blade.php')));
        $record=DB::table('scene_requests')->find(DB::table('scene_projects')->find($projectId)->request_id);
        $revision=DB::table('scene_request_revisions')->where('request_id',$record->id)->where('revision_no',$record->revision_no)->first();
        $facts = SceneWorkflow::unpack($revision->facts);
        $schema = SceneWorkflow::unpack($revision->schema_snapshot);
        $projects=collect([DB::table('scene_projects')->find($projectId)]);
        $offlineConfirmations=DB::table('scene_offline_acceptances')->where('project_id',$projectId)->get();
        foreach ($offlineConfirmations as $confirmation) {
            $confirmation->attestation = SceneWorkflow::unpack(Crypt::decryptString($confirmation->encrypted_attestation));
        }
        $attachments=DB::table('scene_attachments')->where('request_id',$record->id)->get();
        $activities=$assessments=$quotes=$decisions=$prices=$resources=$tasks=$admins=$commercial=$tickets=collect();
        $errors=new Illuminate\Support\ViewErrorBag;
        $__env=new Illuminate\View\Factory(new Illuminate\View\Engines\EngineResolver,new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem,[]),new Illuminate\Events\Dispatcher($app));
        $__env->getFinder()->addNamespace('admin', $app->basePath('packages/Webkul/Admin/src/Resources/views'));
        $__env->getEngineResolver()->register('blade', fn () => new Illuminate\View\Engines\CompilerEngine($compiler));
        $__env->setContainer($app);
        $bouncer = new class
        {
            public array $allowed = [];

            public function hasPermission($key)
            {
                return in_array($key, $this->allowed, true);
            }
        };
        Webkul\User\Facades\Bouncer::swap($bouncer);
        $bouncer->allowed=['catalog.scene-workflow.acceptance_record','catalog.scene-workflow.acceptance_review'];
        ob_start();
        try {
            eval('?>'.$compiled);
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        foreach (['name="authority_confirmed"', 'name="items[0][result]"', 'name="items[1][result]"', 'name="confirmation_version"', 'name="idempotency_key"', '客户&lt;script&gt;alert(1)&lt;/script&gt;', '/offline-acceptances/'.$r['id'].'/review'] as $expected) {
            same(true, str_contains($html, $expected));
        }
        same(false, str_contains($html, '客户<script>'));
        same(false, str_contains($html, 'encrypted_attestation'));
        $bouncer->allowed=['catalog.scene-workflow'];
        ob_start();
        try {
            eval('?>'.$compiled);
            $viewOnly = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        same(false, str_contains($viewOnly, '客户&lt;script&gt;'));
        same(false, str_contains($viewOnly, 'name="authority_confirmed"'));
    }));
} finally {
    $app->useStoragePath($originalStorage);
    if (is_file($proofPath)) {
        unlink($proofPath);
    }
    foreach (glob($acceptanceStorage.'/*.php') as $compiledView) {
        unlink($compiledView);
    }
    foreach (['/app/private/scenes', '/app/private', '/app', ''] as $suffix) {
        rmdir($acceptanceStorage.$suffix);
    }
}
