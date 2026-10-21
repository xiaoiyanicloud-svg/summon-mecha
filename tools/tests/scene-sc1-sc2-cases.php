<?php

// 仅由 scene-workflow.php 的 :memory: 环境加载，绝不 bootstrap 业务库。
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneAssessmentService;
use Webkul\Shop\Services\Scenes\SceneAttachmentRetentionService;
use Webkul\Shop\Services\Scenes\SceneAttachmentUploadService;
use Webkul\Shop\Services\Scenes\SceneBusinessCalendar;
use Webkul\Shop\Services\Scenes\SceneContentEvidenceService;
use Webkul\Shop\Services\Scenes\ScenePolicyService;
use Webkul\Shop\Services\Scenes\ScenePublicationService;
use Webkul\Shop\Services\Scenes\SceneSchemaRegistry;
use Webkul\Shop\Services\Scenes\SceneSolutionService;
use Webkul\Shop\Services\Scenes\SceneSurveyService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

if (DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('仅允许内存测试。');
}

// 在同一个硬隔离内存库运行既有技能/通知迁移与真实读取服务，不 mock 能力判定。
foreach (['2026_09_30_000001_create_skill_delivery_tables', '2026_10_13_000001_add_v34_columns_to_skill_versions_and_devices',
    '2026_10_13_000004_create_skill_asset_tables', '2026_10_13_000005_create_ops_tables', '2026_10_14_000002_create_blocklist_developer_tables',
    '2026_10_18_000001_add_compatibility_to_skill_versions', '2026_10_18_000002_create_skill_compatibility_evidence_table'] as $migration) {
    (require app()->basePath('packages/Webkul/Shop/src/Database/Migrations/'.$migration.'.php'))->up();
}
$schema->table('device_model', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->json('comm_channels')->nullable();
    $t->json('firmware_versions')->nullable();
    $t->string('status')->default('active');
});
$schema->table('product_flat', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->string('locale')->nullable();
    $t->string('name')->nullable();
    $t->decimal('price', 12, 2)->nullable();
});
$app->instance('filesystem', new Illuminate\Filesystem\FilesystemManager($app));
config(['filesystems.disks.private' => ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => true]]);
$app->instance(Webkul\Shop\Services\Deploy\KillSwitchService::class, (new ReflectionClass(Webkul\Shop\Services\Deploy\KillSwitchService::class))->newInstanceWithoutConstructor());

test('受理日历冻结、分派不重置截止、超期去重与真实联系关闭时钟', fn () => sceneSandbox(function () {
    global $owner;
    $calendar = (require app()->basePath('config/scene_policy_drafts.php'))['service_calendar'];
    $calendar['owner_id'] = 1;
    $calendar['escalation_owner_id'] = 2;
    scenePolicy('service_calendar', $calendar);
    $payload = requestPayload();
    unset($payload['scene_id'],$payload['scene_revision_id']);
    $payload['category'] = 'enterprise';
    $s = app(Webkul\Shop\Services\Scenes\SceneRequestService::class);
    $r = $s->create($owner, 'clock-freeze-test001', $payload);
    $clock = DB::table('scene_service_clocks')->where('request_id', $r['id'])->first();
    same('2026-10-20 06:00:00', $clock->due_at);
    same(1, (int) DB::table('scene_requests')->find($r['id'])->assignee_id);
    $s->operate($r['id'], 1, 'assign', ['assignee_id' => 2], 1);
    same($clock->due_at, DB::table('scene_service_clocks')->find($clock->id)->due_at);
    $now = Carbon::getTestNow();
    try {
        Carbon::setTestNow(Carbon::parse('2026-10-21T00:00:00Z'));
        $c = app(Webkul\Shop\Services\Scenes\SceneIntakeClockService::class);
        same(['overdue' => 1, 'escalated' => 1], $c->scan());
        same(['overdue' => 0, 'escalated' => 0], $c->scan());
        $todo = DB::table('scene_operation_todos')->where('dedupe_key', 'contact-escalated:'.$clock->id)->first();
        app(Webkul\Shop\Services\Scenes\SceneOperationsService::class)->assignTodo((int) $todo->id, 1, 1, '升级人员临时转派', 2);
        same(1, (int) DB::table('scene_service_clocks')->find($clock->id)->escalation_assignee_id);
        error('SCENE_VERSION_CONFLICT', fn () => app(Webkul\Shop\Services\Scenes\SceneOperationsService::class)->assignTodo((int) $todo->id, 1, 2, '旧版本不能覆盖', 1));
        $s->operate($r['id'], 2, 'contact', ['occurred_at' => '2026-10-20T16:00:00+08:00', 'channel' => 'phone', 'note' => '实际已与客户沟通'], 1);
        same('contacted', DB::table('scene_service_clocks')->find($clock->id)->state);
        DB::table('business_outbox')->whereNotIn('event_type', ['scene.request.contact.overdue', 'scene.request.contact.escalated'])->update(['processed_at' => now()]);
        $ops = app(Webkul\Shop\Services\Scenes\SceneOperationsService::class);
        same('processed', $ops->consumeOne()['state']);
        same('processed', $ops->consumeOne()['state']);
        same(0, DB::table('notification')->count());
    } finally {
        Carbon::setTestNow($now);
    }
}));

function sceneSkillFixture(): array
{
    global $sceneTestFiles;
    DB::table('products')->insert(['id' => 12, 'type' => 'simple']);
    DB::table('product_flat')->insert(['product_id' => 12, 'channel' => 'default', 'locale' => 'en', 'name' => '隔离技能', 'price' => 100, 'status' => 1, 'visible_individually' => 1]);
    DB::table('product_categories')->insert(['product_id' => 12, 'category_id' => 2]);
    DB::table('category_translations')->insert(['category_id' => 2, 'slug' => 'skill']);
    DB::table('device_model')->where('id', 1)->update(['comm_channels' => '["manual"]', 'firmware_versions' => '["1.0"]']);
    DB::table('device_binding')->where('id', 10)->update(['meta' => SceneWorkflow::json(['capability_snapshot' => ['firmware_version' => '1.0', 'sdk_version' => '1.0']])]);
    $device = DB::table('devices')->insertGetId(['customer_id' => 1, 'binding_id' => 10, 'sn' => 'ISOLATED-SKILL', 'model_code' => 'isolated-test-model', 'firmware_version' => '1.0']);
    $version = DB::table('skill_versions')->insertGetId(['product_id' => 12, 'version' => '1.0', 'status' => 'published', 'license_mode' => 'per_device', 'pricing_mode' => 'buyout', 'released_at' => '2026-10-19 00:00:00',
        'compatibility' => SceneWorkflow::json(['models' => [['model' => 'isolated-test-model', 'min_firmware' => '1.0']]]), 'delivery_channels' => '["manual"]']);
    $path = storage_path('app/private/skill-scene-test.bin');
    file_put_contents($path, 'ISOLATED SKILL ARTIFACT');
    $sceneTestFiles[] = $path;
    $asset = DB::table('skill_assets')->insertGetId(['skill_version_id' => $version, 'carrier_type' => 'file', 'file_path' => 'skill-scene-test.bin', 'file_name' => 'test.bin', 'file_hash' => hash_file('sha256', $path), 'sort_order' => 0]);
    $evidence = DB::table('skill_compatibility_evidence')->insertGetId(['skill_version_id' => $version, 'product_id' => 12, 'model_code' => 'isolated-test-model', 'asset_id' => $asset, 'asset_hash' => hash_file('sha256', $path), 'source' => 'device', 'evidence_type' => 'install', 'result' => 'match', 'observed_at' => '2026-10-19 00:00:00']);

    return ['input' => ['version_id' => $version, 'asset_id' => $asset, 'device_id' => $device], 'evidence_id' => $evidence, 'path' => $path];
}
test('技能引用真实兼容性、资产解析与文件字节；人工安装通道本身不能证明任务可交付', fn () => sceneSandbox(function () {
    $f = sceneSkillFixture();
    $q = app(Webkul\Shop\Services\Scenes\SceneQualificationService::class);
    $binding = DB::table('device_binding')->find(10);
    $r = $q->skillSnapshot($f['input'], $binding);
    same('verified_match', $r['compatibility_status']);
    same('buyout', $r['pricing_mode']);
    same('per_device', $r['license_mode']);
    $q->assertSkillSale($r, 1);
    DB::table('skill_compatibility_evidence')->where('id', $f['evidence_id'])->update(['source' => 'manual']);
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $q->skillSnapshot($f['input'], $binding));
    DB::table('skill_compatibility_evidence')->where('id', $f['evidence_id'])->update(['source' => 'device']);
    file_put_contents($f['path'], 'TAMPERED');
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $q->skillSnapshot($f['input'], $binding));
}));
test('技能版本/熔断/下架/登记固件改变立即影响资格；不写授权或下发任务', fn () => sceneSandbox(function () {
    $f = sceneSkillFixture();
    $q = app(Webkul\Shop\Services\Scenes\SceneQualificationService::class);
    $binding = DB::table('device_binding')->find(10);
    $original = $q->skillSnapshot($f['input'], $binding);
    DB::table('devices')->where('id', $f['input']['device_id'])->update(['firmware_version' => '1.1']);
    same(false, hash_equals($original['device_hash'], $q->skillSnapshot($f['input'], $binding)['device_hash']));
    DB::table('skill_blocklist')->insert(['skill_id' => 12, 'action' => 'block_new', 'status' => 'active']);
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $q->skillSnapshot($f['input'], $binding));
    DB::table('skill_blocklist')->delete();
    DB::table('product_flat')->where('product_id', 12)->update(['status' => 0]);
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $q->assertSkillSale($original, 1));
    DB::table('skill_versions')->insert(['product_id' => 12, 'version' => '2.0', 'status' => 'published', 'released_at' => '2026-10-20 00:00:00']);
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $q->skillSnapshot($f['input'], $binding));
    same(0, DB::table('skill_entitlements')->count());
}));

function scenePolicy(string $kind, ?array $payload = null): array
{
    $defaults = require app()->basePath('config/scene_policy_drafts.php');
    $p = app(ScenePolicyService::class);
    $r = $p->create($kind, $payload ?? $defaults[$kind], 1);
    $p->decide($r['id'], 1, 'approve', '隔离测试独立复核，无正式运营签收含义', 2);

    return $p->effective($kind);
}

test('受理人停用不丢需求，超时提醒读取现负责人且站内信与 receipt 一次提交', fn () => sceneSandbox(function () {
    global $owner;
    $calendar = (require app()->basePath('config/scene_policy_drafts.php'))['service_calendar'];
    $calendar['owner_id'] = 1;
    $calendar['escalation_owner_id'] = 2;
    scenePolicy('service_calendar', $calendar);
    DB::table('admins')->where('id', 1)->update(['status' => 0]);
    $payload = requestPayload();
    unset($payload['scene_id'],$payload['scene_revision_id']);
    $payload['category'] = 'enterprise';
    $requests = app(Webkul\Shop\Services\Scenes\SceneRequestService::class);
    $r = $requests->create($owner, 'inactive-owner-notify1', $payload);
    same(null, DB::table('scene_requests')->find($r['id'])->assignee_id);
    same(2, (int) DB::table('scene_operation_todos')->where('dedupe_key', 'unassigned:'.$r['id'])->value('assignee_id'));
    $requests->operate($r['id'], 1, 'assign', ['assignee_id' => 2], 2);
    $now = Carbon::getTestNow();
    try {
        Carbon::setTestNow(Carbon::parse('2026-10-20T07:00:00Z'));
        app(Webkul\Shop\Services\Scenes\SceneIntakeClockService::class)->scan();
        DB::table('business_outbox')->where('event_type', '!=', 'scene.request.contact.overdue')->update(['processed_at' => now()]);
        $ops = app(Webkul\Shop\Services\Scenes\SceneOperationsService::class);
        same('processed', $ops->consumeOne()['state']);
        same(1, DB::table('notification')->count());
        same(2, (int) DB::table('notification')->first()->recipient_id);
        $event = DB::table('business_outbox')->where('event_type', 'scene.request.contact.overdue')->first();
        DB::table('business_outbox')->where('id', $event->id)->update(['processed_at' => null]);
        same('processed', $ops->consumeOne()['state']);
        same(1, DB::table('notification')->count());
    } finally {
        Carbon::setTestNow($now);
    }
}));

test('同设备多任务/技能评估进入完整方案与报价，授权数量受约束，熔断后不能批准旧报价', fn () => sceneSandbox(function () {
    $skill = sceneSkillFixture();
    $f = sc2Fixture();
    $supply = app(Webkul\Shop\Services\Scenes\SceneSupplyService::class);
    $e = $supply->evidence(['product_id' => 11, 'binding_id' => 10, 'capability' => 'script_skill', 'level' => 'L1', 'scope' => '受控测试脚本', 'source_reference' => 'ISOLATED-SKILL-TASK', 'artifact_hash' => str_repeat('c', 64), 'verified_at' => '2026-10-19T00:00:00Z', 'expires_at' => '2026-12-31T00:00:00Z', 'constraints' => ['categories' => ['enterprise'], 'min_clearance_mm' => 1000, 'max_floors' => 1], 'skill' => $skill['input']], 1);
    $supply->reviewEvidence($e['id'], ['source_verified' => 1, 'reason' => '独立核验指定产物及现场脚本任务'], 2);
    $assessment = app(SceneAssessmentService::class)->assess((int) $f['request']->id, 3, ['survey_id' => $f['survey']->id, 'candidates' => [
        ['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'evidence_id' => $f['evidence']->id], ['product_id' => 11, 'binding_id' => 10, 'capability' => 'script_skill', 'evidence_id' => $e['id']],
    ]], 1, 1);
    same('feasible', $assessment['state']);
    $a = DB::table('scene_assessments')->find($assessment['id']);
    $input = sceneSolutionPayload($a);
    $input['components'][] = ['key' => 'script', 'label' => '已核验脚本技能', 'kind' => 'skill', 'product_id' => 12, 'skill_version_id' => $skill['input']['version_id'], 'price_code' => 'skill.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 2, 'selected' => true, 'required' => true, 'dependencies' => ['robot']];
    $bad = $input['components'];
    $bad[2]['quantity'] = 2;
    error('SCENE_BOM_SKILL_LICENSE_INVALID', fn () => app(SceneSolutionService::class)->validateBom($bad, $a));
    $solution = app(SceneSolutionService::class)->draft((int) $f['request']->id, 4, $input, 1, 1);
    app(SceneSolutionService::class)->decide($solution['id'], 1, 'approve', '核验范围、产物与授权数量', 2, 1);
    $price = $supply->price(['code' => 'skill.test', 'name' => '脚本技能', 'kind' => 'skill', 'product_id' => 12, 'unit' => 'item', 'currency' => 'CNY', 'unit_minor' => 100, 'tax_bps' => 0, 'charge_type' => 'fee', 'valid_from' => '2026-10-01T00:00:00Z', 'valid_until' => '2026-12-31T00:00:00Z'], 1);
    $quoteInput = quoteFixtureInput($a->id, ['proposal_id' => $solution['id']]);
    $quoteInput['items'][] = ['price_item_id' => $price['id'], 'quantity' => 1, 'billing_units' => 1];
    $quotes = app(Webkul\Shop\Services\Scenes\SceneProposalService::class);
    $quote = $quotes->draft((int) $f['request']->id, 4, $quoteInput, 1, 1);
    $quotes->transition($quote['id'], 1, 'review', 1, 1);
    DB::table('skill_blocklist')->insert(['skill_id' => 12, 'action' => 'block_all', 'status' => 'active']);
    error('SCENE_SKILL_QUALIFICATION_INVALID', fn () => $quotes->transition($quote['id'], 2, 'approve', 2, 1));
    same('in_review', DB::table('scene_quotes')->find($quote['id'])->state);
    same(0, DB::table('skill_entitlements')->count());
}));
function scenePrivateProof(int $requestId, string $content = "%PDF-1.4\nSCENE ISOLATED TEST\n%%EOF"): array
{
    global $sceneTestFiles;
    $key = (string) Illuminate\Support\Str::uuid();
    $path = storage_path('app/private/scenes/'.$key);
    file_put_contents($path, $content);
    $sceneTestFiles[] = $path;
    $id = DB::table('scene_attachments')->insertGetId(['request_id' => $requestId, 'storage_key' => $key, 'sha256' => hash('sha256', $content), 'mime' => 'application/pdf', 'size' => strlen($content), 'state' => 'clean', 'expires_at' => '2027-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);

    return ['id' => $id, 'path' => $path, 'content' => $content];
}
function sc2Fixture(): array
{
    global $facts,$owner;
    $payload = requestPayload();
    unset($payload['scene_id'],$payload['scene_revision_id']);
    $payload['category'] = 'enterprise';
    $r = app(Webkul\Shop\Services\Scenes\SceneRequestService::class)->create($owner, 'sc2-'.Illuminate\Support\Str::uuid(), $payload);
    $proof = scenePrivateProof($r['id']);
    $survey = app(SceneSurveyService::class)->record($r['id'], 1, ['facts' => $facts, 'verified_keys' => array_keys($facts), 'evidence_ids' => [$proof['id']], 'occurred_at' => '2026-10-19T18:00:00+08:00', 'note' => '隔离原件支持的现场观察'], 1);
    $supply = app(Webkul\Shop\Services\Scenes\SceneSupplyService::class);
    $e = $supply->evidence(['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'level' => 'L1', 'scope' => '受控测试展厅', 'source_reference' => 'ISOLATED-PRIVATE', 'artifact_hash' => str_repeat('9', 64), 'verified_at' => '2026-10-19T00:00:00Z', 'expires_at' => '2026-12-31T00:00:00Z', 'constraints' => ['categories' => ['enterprise'], 'min_clearance_mm' => 1000, 'max_floors' => 1]], 1);
    $supply->reviewEvidence($e['id'], ['source_verified' => 1, 'reason' => '独立核对实际任务及设备边界'], 2);
    $a = app(SceneAssessmentService::class)->assess($r['id'], 2, ['survey_id' => $survey['id'], 'candidates' => [['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'evidence_id' => $e['id']]]], 1, 1);
    same('feasible', $a['state']);

    return ['request' => DB::table('scene_requests')->find($r['id']), 'assessment' => DB::table('scene_assessments')->find($a['id']), 'survey' => DB::table('scene_site_surveys')->find($survey['id']), 'evidence' => DB::table('scene_evidence')->find($e['id']), 'proof' => $proof];
}
function sceneSolutionPayload(object $assessment): array
{
    return ['assessment_id' => $assessment->id, 'title' => '测试实例方案', 'scope' => '按现场已核验任务执行', 'exclusions' => '不含无人值守和跨层', 'components' => [
        ['key' => 'robot', 'label' => '机器人', 'kind' => 'robot', 'product_id' => 11, 'price_code' => 'robot.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'selected' => true, 'required' => true, 'dependencies' => []],
        ['key' => 'transport', 'label' => '运输', 'kind' => 'transport', 'price_code' => 'transport.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'selected' => true, 'required' => true, 'dependencies' => ['robot']]],
        'acceptance' => [['key' => 'greeting', 'label' => '按冻结脚本完成服务', 'required' => true]]];
}
function sceneAuditFailure(callable $fn): void
{
    DB::unprepared("CREATE TRIGGER scene_closeout_audit_failure BEFORE INSERT ON operation_log BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");
    try {
        $fn();
        throw new RuntimeException('未触发审计失败');
    } catch (Illuminate\Database\QueryException) {
    } finally {
        DB::unprepared('DROP TRIGGER scene_closeout_audit_failure');
    }
}

function sceneUploadedFile(string $content = "%PDF-1.4\nUPLOAD FIXTURE\n%%EOF"): Illuminate\Http\UploadedFile
{
    global $sceneTestFiles;
    $path = tempnam(app()->storagePath(), 'upload-');
    file_put_contents($path, $content);
    $sceneTestFiles[] = $path;

    return new Illuminate\Http\UploadedFile($path, 'scene.pdf', 'application/pdf', null, true);
}
function sceneUpload(int $requestId, string $key, string $content = "%PDF-1.4\nUPLOAD FIXTURE\n%%EOF"): array
{
    global $owner,$sceneTestFiles;
    try {
        return app(SceneAttachmentUploadService::class)->store($requestId, sceneUploadedFile($content), $owner, null, $key);
    } finally {
        foreach (glob(storage_path('app/private/scenes/*')) as $path) {
            if (is_file($path) && ! in_array($path, $sceneTestFiles, true)) {
                $sceneTestFiles[] = $path;
            }
        }
    }
}

test('上传未批准保留规则、伪 MIME、恶意内容和扫描后字节改变全部阻断且无可用残留', fn () => sceneSandbox(function () {
    global $requestId;
    $original = config('scenes.attachment_scan_command');
    config(['scenes.attachment_scan_command' => [PHP_BINARY, __DIR__.'/fixtures/scene-scanner.php']]);
    try {
        error('SCENE_RETENTION_POLICY_REQUIRED', fn () => sceneUpload($requestId, 'upload-no-policy0001'));
        scenePolicy('retention');
        $count = DB::table('scene_attachments')->count();
        error('SCENE_ATTACHMENT_TYPE', fn () => sceneUpload($requestId, 'upload-fake-type0001', 'not a PDF'));
        $dangerous = sceneUploadedFile();
        $dangerous = new Illuminate\Http\UploadedFile($dangerous->getPathname(), 'report.php.pdf', 'application/pdf', null, true);
        error('SCENE_ATTACHMENT_TYPE', fn () => app(SceneAttachmentUploadService::class)->store($requestId, $dangerous, new Webkul\Shop\Services\Scenes\ScenePrincipal(1, null), null, 'upload-double-ext001'));
        error('SCENE_ATTACHMENT_SCAN_REJECTED', fn () => sceneUpload($requestId, 'upload-malware00001', "%PDF-1.4\nEICAR\n%%EOF"));
        config(['scenes.attachment_scan_command' => [PHP_BINARY, __DIR__.'/fixtures/scene-scanner.php', '--tamper']]);
        error('SCENE_ATTACHMENT_SCAN_REJECTED', fn () => sceneUpload($requestId, 'upload-tampered0001'));
        same($count, DB::table('scene_attachments')->count());
    } finally {
        config(['scenes.attachment_scan_command' => $original]);
    }
}));
test('扫描上传幂等同文件一份；变内容冲突；回执先授权、到期/撤销不恢复可读资格', fn () => sceneSandbox(function () {
    global $requestId,$owner,$other;
    $original = config('scenes.attachment_scan_command');
    config(['scenes.attachment_scan_command' => [PHP_BINARY, __DIR__.'/fixtures/scene-scanner.php']]);
    try {
        scenePolicy('retention');
        $r = sceneUpload($requestId, 'upload-idempotent001');
        $same = sceneUpload($requestId, 'upload-idempotent001');
        same($r['id'], $same['id']);
        same('available', $r['availability_state']);
        error('IDEMPOTENCY_CONFLICT', fn () => sceneUpload($requestId, 'upload-idempotent001', "%PDF-1.4\nDIFFERENT\n%%EOF"));
        error('SCENE_OBJECT_NOT_FOUND', fn () => app(SceneAttachmentUploadService::class)->receipt($other, $requestId, 'upload-idempotent001'));
        $retention = app(SceneAttachmentRetentionService::class);
        $retention->revoke($requestId, $r['id'], '原件内容已撤回使用', 2);
        same('revoked', app(SceneAttachmentUploadService::class)->receipt($owner, $requestId, 'upload-idempotent001')['availability_state']);
        error('SCENE_ATTACHMENT_NOT_FOUND', fn () => app(Webkul\Shop\Services\Scenes\SceneAttachmentService::class)->read($owner, $requestId, $r['id']));
        $dto = app(Webkul\Shop\Services\Scenes\SceneRequestService::class)->detail($owner, $requestId);
        same(false, str_contains(SceneWorkflow::json($dto['attachments']), 'storage_key'));
        same(false, str_contains(SceneWorkflow::json($dto['attachments']), 'sha256'));
    } finally {
        config(['scenes.attachment_scan_command' => $original]);
    }
}));
test('上传审计失败回滚元数据/幂等/outbox并清理未引用文件', fn () => sceneSandbox(function () {
    global $requestId;
    $original = config('scenes.attachment_scan_command');
    config(['scenes.attachment_scan_command' => [PHP_BINARY, __DIR__.'/fixtures/scene-scanner.php']]);
    try {
        scenePolicy('retention');
        $count = DB::table('scene_attachments')->count();
        $files = count(glob(storage_path('app/private/scenes/*')));
        sceneAuditFailure(fn () => sceneUpload($requestId, 'upload-audit-failure01'));
        same($count, DB::table('scene_attachments')->count());
        same($files, count(glob(storage_path('app/private/scenes/*'))));
        same(0, DB::table('business_idempotency_keys')->where('key_hash', hash('sha256', 'upload-audit-failure01'))->count());
    } finally {
        config(['scenes.attachment_scan_command' => $original]);
    }
}));
test('资料到期不等于允许删除：活跃需求、争议/工单和保全均阻断，独立解除保全后可重入清理', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $id = $f['proof']['id'];
    $requestId = (int) $f['request']->id;
    $s = app(SceneAttachmentRetentionService::class);
    $policy = scenePolicy('retention');
    same('retention_not_expired', $s->inspect($id)['blocked_reason']);
    DB::table('scene_attachments')->where('id', $id)->update(['expires_at' => '2026-10-19 00:00:00']);
    same('request_still_active', $s->inspect($id)['blocked_reason']);
    DB::table('scene_requests')->where('id', $requestId)->update(['state' => 'closed']);
    $h = $s->hold($requestId, $id, ['reference' => 'ISOLATED-DISPUTE', 'reason' => '仍有客户争议依据'], 1);
    same('explicit_hold', $s->inspect($id)['blocked_reason']);
    error('SCENE_ATTACHMENT_PRESERVATION_REQUIRED', fn () => $s->purge($id, $policy['version']));
    same(true, is_file($f['proof']['path']));
    error('SCENE_HOLD_RELEASE_CONFLICT', fn () => $s->release($requestId, $id, $h['id'], '不能自行解除保全', 1));
    $s->release($requestId, $id, $h['id'], '独立复核争议已结束', 2);
    sceneAuditFailure(fn () => $s->purge($id, $policy['version']));
    same(true, is_file($f['proof']['path']));
    same('clean', DB::table('scene_attachments')->find($id)->state);
    same('deleted', $s->purge($id, $policy['version'])['state']);
    same(false, is_file($f['proof']['path']));
    same('deleted', $s->purge($id, $policy['version'])['state']);
    same(1, DB::table('scene_attachments')->where('id', $id)->count());
}));
test('清理文件后最终审计失败保留 deletion_pending，原文件不会假称回滚；重试完成唯一记录', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $id = $f['proof']['id'];
    $s = app(SceneAttachmentRetentionService::class);
    $p = scenePolicy('retention');
    DB::table('scene_requests')->where('id', $f['request']->id)->update(['state' => 'closed']);
    DB::table('scene_attachments')->where('id', $id)->update(['expires_at' => '2026-10-19 00:00:00']);
    DB::unprepared("CREATE TRIGGER final_delete_audit_failure BEFORE INSERT ON operation_log WHEN NEW.action='scene.attachment.deleted' BEGIN SELECT RAISE(ABORT, 'injected final audit failure'); END");
    try {
        $s->purge($id, $p['version']);
        throw new RuntimeException('未触发最终事务失败');
    } catch (Illuminate\Database\QueryException) {
    } finally {
        DB::unprepared('DROP TRIGGER final_delete_audit_failure');
    }
    same('deletion_pending', DB::table('scene_attachments')->find($id)->state);
    same(false, is_file($f['proof']['path']));
    error('SCENE_ATTACHMENT_NOT_FOUND', fn () => $s->hold((int) $f['request']->id, $id, ['reference' => 'TOO-LATE', 'reason' => '删除已授权不能追加保全'], 1));
    same('deleted', $s->purge($id, $p['version'])['state']);
}));

function sceneContentPayload(): array
{
    return ['kind' => 'metric', 'title' => '隔离样本记录', 'public_summary' => '测试环境的观察结果', 'scope' => '仅适用于隔离展厅和已说明的任务范围',
        'source_reference' => 'PRIVATE-EVIDENCE-ONLY', 'source_hash' => str_repeat('e', 64), 'authorization_reference' => 'PRIVATE-AUTH-ONLY',
        'sample_size' => 10, 'metric_value' => '12.5', 'metric_unit' => '次/场', 'observed_from' => '2026-10-18T10:00:00+08:00',
        'observed_until' => '2026-10-19T18:00:00+08:00', 'expires_at' => '2026-11-30T00:00:00Z'];
}

test('九类专业表单有不同字段与单位；旧 v1 可验证，新字段未知不转换为 0', function () {
    $registry = app(SceneSchemaRegistry::class);
    $hashes = [];
    foreach (array_keys(Webkul\Shop\Models\Scene::CATEGORIES) as $category) {
        $s = $registry->schema($category);
        same(2, $s['version']);
        same(count($s['fields']), count(array_unique(array_column($s['fields'], 'key'))));
        $hashes[] = SceneWorkflow::hash($s['fields']);
        same(['city' => '上海', 'power' => null], $registry->validate(['city' => '上海', 'power' => null], $s));
    }
    same(9, count(array_unique($hashes)));
    same(1, $registry->schema('enterprise', 1)['version']);
    $industry = $registry->schema('industry');
    try {
        $registry->validate(['payload_kg' => '1e6'], $industry);
        throw new RuntimeException('科学记数法未拒绝');
    } catch (Illuminate\Validation\ValidationException) {
    }
    try {
        $registry->validate(['task_context' => 'invented'], $industry);
        throw new RuntimeException('非法选项未拒绝');
    } catch (Illuminate\Validation\ValidationException) {
    }
});

test('内容依据独立批准、父对象约束与撤销即时生效，公共摘要不含私有来源和授权引用', function () {
    sceneSandbox(function () {
        $s = app(SceneContentEvidenceService::class);
        $r = $s->create(1, sceneContentPayload(), 1);
        error('SCENE_CONTENT_EVIDENCE_INVALID', fn () => $s->freeze(1, [$r['id']]));
        error('SCENE_SELF_APPROVAL', fn () => $s->decide($r['id'], 1, 'approve', '已核对样本与授权依据', 1));
        $s->decide($r['id'], 1, 'approve', '已核对样本与授权依据', 2);
        $refs = $s->freeze(1, [$r['id']]);
        $dto = $s->visible($refs);
        same(1, count($dto));
        $json = SceneWorkflow::json($dto);
        same(false, str_contains($json, 'PRIVATE-'));
        same(true, $dto[0]['not_a_performance_guarantee']);
        error('SCENE_CONTENT_EVIDENCE_INVALID', fn () => $s->freeze(2, [$r['id']]));
        $s->decide($r['id'], 2, 'revoke', '客户撤回公开展示授权', 2);
        same([], $s->visible($refs));
        same(1, DB::table('scene_content_evidence')->where('id', $r['id'])->count());
    });
});

test('发布草稿可编辑且 stale 版本拒绝，最后编辑者不能自审，已送审内容不可原地修改', function () {
    sceneSandbox(function () {
        $p = app(ScenePublicationService::class);
        $payload = ['name' => '草稿编辑', 'description' => '服务内容', 'category' => 'enterprise', 'robots' => [], 'tags' => [],
            'deliverables' => ['受控服务'], 'acceptance' => [['key' => 'scope', 'label' => '范围确认', 'required' => true]], 'workflow_template' => 'manual_site_v1'];
        $r = $p->draft(1, $payload, 1);
        $next = $p->draft(1, ['description' => '修订内容'] + $payload, 2, $r['id'], 1);
        same(2, $next['row_version']);
        same($r['revision_no'], $next['revision_no']);
        error('SCENE_VERSION_CONFLICT', fn () => $p->draft(1, $payload, 1, $r['id'], 1));
        $p->transition(1, $r['id'], 2, 'review', 1);
        error('SCENE_SELF_APPROVAL', fn () => $p->transition(1, $r['id'], 3, 'approve', 2));
        error('SCENE_REVISION_IMMUTABLE', fn () => $p->draft(1, $payload, 1, $r['id'], 3));
    });
});

test('工作日历跨午休、周末、节假日与调休；实际秒数保留且无隐含全天服务', function () {
    $c = app(SceneBusinessCalendar::class);
    $data = (require app()->basePath('config/scene_policy_drafts.php'))['service_calendar'];
    same('2026-10-26 02:30:00', $c->deadline('2026-10-23 09:30:00', 120, $data));
    same('2026-10-27 02:30:00', $c->deadline('2026-10-23 09:30:00', 120, ['holidays' => ['2026-10-26']] + $data));
    same('2026-10-24 03:30:00', $c->deadline('2026-10-23 09:30:00', 120, ['overrides' => ['2026-10-24' => [['start' => '10:00', 'end' => '12:00']]]] + $data));
    same('2026-10-20 05:30:30', $c->deadline('2026-10-20 03:30:30', 60, $data));
    error('SCENE_CALENDAR_INVALID', fn () => $c->validate(['weekly' => [1 => [['start' => '13:00', 'end' => '14:00'], ['start' => '12:00', 'end' => '13:30']]]] + $data));
    error('SCENE_CALENDAR_INVALID', fn () => $c->validate(['weekly' => [0 => [['start' => '09:00', 'end' => '18:00']]]] + $data));
});
test('日历夏令时缺失及重复时间明确拒绝，不能静默选择任一 offset', function () {
    $c = app(SceneBusinessCalendar::class);
    $data = (require app()->basePath('config/scene_policy_drafts.php'))['service_calendar'];
    error('SCENE_CALENDAR_DST_INVALID', fn () => $c->deadline('2026-03-08 06:00:00', 60, ['timezone' => 'America/New_York', 'weekly' => [7 => [['start' => '02:30', 'end' => '04:00']]]] + $data));
    error('SCENE_CALENDAR_DST_INVALID', fn () => $c->deadline('2026-11-01 04:00:00', 60, ['timezone' => 'America/New_York', 'weekly' => [7 => [['start' => '01:30', 'end' => '03:00']]]] + $data));
});
test('政策缺主体可保存草案但不能批准；自审、旧版审批及撤销后复活均拒绝', fn () => sceneSandbox(function () {
    $s = app(ScenePolicyService::class);
    $defaults = require app()->basePath('config/scene_policy_drafts.php');
    $r = $s->create('commercial', $defaults['commercial'], 1);
    same(null, $s->effective('commercial'));
    error('SCENE_SELF_APPROVAL', fn () => $s->decide($r['id'], 1, 'approve', '试图自行批准', 1));
    try {
        $s->decide($r['id'], 1, 'approve', '核验主体仍为空', 2);
        throw new RuntimeException('空主体生效');
    } catch (Illuminate\Validation\ValidationException) {
    }
    $a = scenePolicy('retention');
    $b = $s->create('retention', $defaults['retention'], 1);
    $s->decide($b['id'], 1, 'approve', '新版用途与期限已核对', 2);
    $s->decide($b['id'], 2, 'revoke', '取消本期保留政策', 2);
    same(null, $s->effective('retention'));
    same('approved', DB::table('scene_policy_versions')->find($a['id'])->state);
    $old = $s->create('retention', $defaults['retention'], 1);
    $s->create('retention', $defaults['retention'], 1);
    error('SCENE_POLICY_STALE', fn () => $s->decide($old['id'], 1, 'approve', '不能批准旧版', 2));
}));
test('政策生效与审计/outbox 原子提交；批准失败不改变唯一生效指针', fn () => sceneSandbox(function () {
    $p = scenePolicy('retention');
    $s = app(ScenePolicyService::class);
    $r = $s->create('retention', ['days' => 30, 'purpose' => '必要用途', 'deletion_notice' => '受控到期删除'], 1);
    $before = DB::table('business_outbox')->count();
    sceneAuditFailure(fn () => $s->decide($r['id'], 1, 'approve', '批准新保留版本', 2));
    same($p['id'], $s->effective('retention')['id']);
    same('draft', DB::table('scene_policy_versions')->find($r['id'])->state);
    same($before, DB::table('business_outbox')->count());
}));
test('九类条件项覆盖未拍摄、单层、未成年和人员采集；未知及客户自报不冒充核验', function () {
    $r = app(Webkul\Shop\Services\Scenes\SceneSiteRules::class);
    $registry = app(SceneSchemaRegistry::class);
    $checks = $r->site($registry->schema('business_event'), ['photography_planned' => false, 'photography_authorized' => false], ['photography_planned' => ['state' => 'verified']]);
    same('not_applicable', collect($checks)->firstWhere('key', 'conditional.photography_authorized')['state']);
    foreach ([['enterprise', ['floors' => 1], 'conditional.lift_integration', 'not_applicable'], ['education', ['age_min' => 16, 'guardian_consent' => false], 'conditional.guardian_consent', 'fail'], ['medical', ['data_collection' => true], 'conditional.recorded_consents', 'unknown']] as [$category,$facts,$key,$state]) {
        $sources = array_fill_keys(array_keys($facts), ['state' => 'verified']);
        same($state, collect($r->site($registry->schema($category), $facts, $sources))->firstWhere('key', $key)['state']);
    }
    same('unknown', collect($r->site($registry->schema('enterprise'), ['power' => true], []))->firstWhere('key', 'site.power')['state']);
    foreach (['medical' => ['care_scope' => 'clinical'], 'home' => ['task_scope' => 'care']] as $category => $facts) {
        same('fail', collect($r->site($registry->schema($category), $facts, []))->firstWhere('key', 'service.boundary')['state']);
    }
    same(false, $registry->validate(['indoor' => '0'], $registry->schema('enterprise'))['indoor']);
});
test('载荷/通道/坡度/节拍边界、专项工况与零值按规则判断，不能由缺失边界默认通过', function () {
    $r = app(Webkul\Shop\Services\Scenes\SceneSiteRules::class);
    $c = $r->capability('warehouse_logistics', ['payload_kg' => 0, 'aisle_width_mm' => 800, 'flow_units_hour' => 120], ['categories' => ['warehouse_logistics'], 'max_payload_kg' => 0, 'min_clearance_mm' => 900], 'test');
    same('pass', $c[1]['state']);
    same('fail', $c[2]['state']);
    same('unknown', $c[3]['state']);
    $c = $r->capability('security', ['environment' => 'hazardous'], ['categories' => ['security'], 'hazardous_environment_verified' => false], 'test');
    same('fail', $c[1]['state']);
});
test('勘察仅核实本次非空字段；跨父、过期、原件丢失/篡改及指纹变动阻断原评估', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $s = app(SceneSurveyService::class);
    $a = app(SceneAssessmentService::class);
    same('site_survey', SceneWorkflow::unpack($f['survey']->provenance)['power']['source']);
    error('SCENE_SURVEY_FIELD_UNKNOWN', fn () => $s->record((int) $f['request']->id, 3, ['facts' => ['power' => null], 'verified_keys' => ['power'], 'evidence_ids' => [$f['proof']['id']], 'note' => '不能把未知勾为已核实', 'occurred_at' => '2026-10-19T18:00:00+08:00'], 1));
    $other = clone $f['request'];
    $other->id = 999;
    error('SCENE_SURVEY_STALE', fn () => $s->assertCurrent($f['survey'], $other));
    file_put_contents($f['proof']['path'], 'changed');
    error('SCENE_ATTACHMENT_UNAVAILABLE', fn () => $a->assertCurrent($f['assessment'], $f['request'], 1));
    file_put_contents($f['proof']['path'], $f['proof']['content']);
    DB::table('scene_attachments')->where('id', $f['proof']['id'])->update(['expires_at' => '2026-10-19 00:00:00']);
    error('SCENE_SURVEY_EVIDENCE_INVALID', fn () => $a->assertCurrent($f['assessment'], $f['request'], 1));
}));
test('设备归属、固件、机型或能力边界改变使已批准证据失效，不更改原绑定', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $a = app(SceneAssessmentService::class);
    foreach ([['owner_customer_id' => 2], ['model_id' => null], ['meta' => SceneWorkflow::json(['capability_snapshot' => ['firmware_version' => '2.0']])]] as $patch) {
        sceneSandbox(function () use ($patch, $f, $a) {
            DB::table('device_binding')->where('id', 10)->update($patch);
            error('SCENE_QUALIFICATION_CHANGED', fn () => $a->assertCurrent($f['assessment'], $f['request'], 1));
            same($patch[array_key_first($patch)], DB::table('device_binding')->find(10)->{array_key_first($patch)});
        });
    }
    DB::table('scene_evidence')->where('id', $f['evidence']->id)->update(['constraint_snapshot' => SceneWorkflow::json(['categories' => ['enterprise'], 'min_clearance_mm' => 3000])]);
    error('SCENE_QUALIFICATION_CHANGED', fn () => $a->assertCurrent($f['assessment'], $f['request'], 1));
}));
test('方案数量跨组件求和，不把重复 SKU 当实物；必选、依赖环、未知依赖及重复编码拒绝', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $s = app(SceneSolutionService::class);
    $base = sceneSolutionPayload($f['assessment'])['components'];
    $bad = $base;
    $bad[0]['quantity'] = 2;
    error('SCENE_BOM_QUANTITY_INVALID', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $base;
    $bad[] = ['key' => 'robot2', 'price_code' => 'robot.other'] + $base[0];
    error('SCENE_BOM_UNASSESSED', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $base;
    $bad[0]['dependencies'] = ['transport'];
    error('SCENE_BOM_DEPENDENCY_INVALID', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $base;
    $bad[0]['dependencies'] = ['missing'];
    error('SCENE_BOM_DEPENDENCY_INVALID', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $base;
    $bad[1]['price_code'] = $base[0]['price_code'];
    error('SCENE_PRICE_CODE_DUPLICATE', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $base;
    $bad[1]['key'] = $base[0]['key'];
    error('SCENE_BOM_DEPENDENCY_INVALID', fn () => $s->validateBom($bad, $f['assessment']));
}));
test('选配、必选互斥组和无等效证据替代均受约束；报价必须匹配已批准 BOM', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $s = app(SceneSolutionService::class);
    $input = sceneSolutionPayload($f['assessment']);
    $parts = $input['components'];
    $bad = $parts;
    $bad[0]['exclusive_group'] = $bad[1]['exclusive_group'] = 'choice';
    error('SCENE_BOM_EXCLUSIVE', fn () => $s->validateBom($bad, $f['assessment']));
    $bad = $parts;
    $bad[0]['equivalent_to'] = 'missing';
    error('SCENE_BOM_EQUIVALENCE_REQUIRED', fn () => $s->validateBom($bad, $f['assessment']));
    $r = $s->draft((int) $f['request']->id, 3, $input, 1, 1);
    error('SCENE_SELF_APPROVAL', fn () => $s->decide($r['id'], 1, 'approve', '试图批准自己方案', 1, 1));
    $s->decide($r['id'], 1, 'approve', '已核对范围与证据', 2, 1);
    error('SCENE_QUOTE_BOM_MISMATCH', fn () => $s->forQuote($r['id'], $f['request'], $f['assessment'], [['price_item_id' => 1, 'quantity' => 2]]));
    $snapshot = $s->forQuote($r['id'], $f['request'], $f['assessment'], [['price_item_id' => 1, 'quantity' => 1], ['price_item_id' => 2, 'quantity' => 1]]);
    same($r['id'], $snapshot['id']);
    DB::table('scene_proposals')->where('id', $r['id'])->update(['scope' => '被改动的范围']);
    error('SCENE_PROPOSAL_INTEGRITY', fn () => $s->forQuote($r['id'], $f['request'], $f['assessment'], [['price_item_id' => 1, 'quantity' => 1], ['price_item_id' => 2, 'quantity' => 1]]));
}));
test('方案和勘察强审计失败无半版本、无报价失效或多余 outbox', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $before = DB::table('business_outbox')->count();
    $count = DB::table('scene_proposals')->count();
    sceneAuditFailure(fn () => app(SceneSolutionService::class)->draft((int) $f['request']->id, 3, sceneSolutionPayload($f['assessment']), 1, 1));
    same($count, DB::table('scene_proposals')->count());
    same($before, DB::table('business_outbox')->count());
}));
test('AI 草稿忽略编造价格/能力/ID，保留原文与待确认字段；人工事实仍须现场核验', fn () => sceneSandbox(function () {
    global $owner;
    $text = '请安排展厅迎宾，报价由正式方案确认';
    $s = app(Webkul\Shop\Services\Scenes\SceneAiDraftService::class);
    $r = $s->build($text, ['scene_id' => 999999, 'product_ids' => [999999, 11, '11'], 'price' => 1, 'capability' => 'all', 'facts' => ['city' => '上海', 'power' => true, 'invented' => '保证效果']], 1);
    same(null, $r['scene']);
    same([11], $r['catalog_product_ids']);
    same([], $r['facts']);
    same($text, $r['original_text']);
    same(false, array_key_exists('price', $r));
    $snapshot = ['version' => 'scene-ai-draft-v1', 'original_text' => $text, 'suggestions' => ['city' => '上海'], 'confirmed_keys' => ['city']];
    $payload = requestPayload();
    unset($payload['scene_id'],$payload['scene_revision_id']);
    $payload['source'] = 'ai';
    $payload['category'] = 'enterprise';
    $payload['ai_snapshot'] = $snapshot;
    $request = app(Webkul\Shop\Services\Scenes\SceneRequestService::class)->create($owner, 'ai-snapshot-test0001', $payload);
    $stored = DB::table('scene_request_revisions')->where('request_id', $request['id'])->first();
    same($text, SceneWorkflow::unpack($stored->source_snapshot)['original_text']);
    same(false, str_contains(DB::table('business_outbox')->where('aggregate_id', $request['id'])->value('payload'), $text));
}));

test('新评估替代旧结论后不能沿用旧批准方案；已有接受事实不能被重评改为可报价', fn () => sceneSandbox(function () {
    $f = sc2Fixture();
    $assessment = (array) $f['assessment'];
    unset($assessment['id']);
    $assessment['state'] = 'infeasible';
    DB::table('scene_assessments')->insert($assessment);
    error('SCENE_ASSESSMENT_STALE', fn () => app(SceneAssessmentService::class)->assertCurrent($f['assessment'], $f['request'], 1));
    DB::table('scene_requests')->where('id', $f['request']->id)->update(['state' => 'accepted']);
    error('SCENE_CHANGE_REQUIRED', fn () => app(SceneAssessmentService::class)->assess((int) $f['request']->id, 3, ['candidates' => [['product_id' => 11, 'capability' => 'greeting']]], 1, 1));
    same('accepted', DB::table('scene_requests')->find($f['request']->id)->state);
}));
test('旧表单显式升级才使用 v2，保留旧修订与未知字段，不覆盖原文/事实历史', fn () => sceneSandbox(function () {
    global $owner,$facts;
    $payload = requestPayload();
    unset($payload['scene_id'],$payload['scene_revision_id']);
    $payload['category'] = 'enterprise';
    $s = app(Webkul\Shop\Services\Scenes\SceneRequestService::class);
    $r = $s->create($owner, 'legacy-upgrade-test01', $payload);
    $oldSchema = app(SceneSchemaRegistry::class)->schema('enterprise', 1);
    $oldFacts = ['city' => '上海', 'task_goal' => '原始任务'];
    DB::table('scene_request_revisions')->where('request_id', $r['id'])->update(['schema_snapshot' => SceneWorkflow::json($oldSchema), 'facts' => SceneWorkflow::json($oldFacts)]);
    $s->supplement($owner, $r['id'], 1, 'legacy-supplement001', ['original_text' => '保留旧字段', 'facts' => $oldFacts]);
    same(1, $s->detail($owner, $r['id'])['schema']['version']);
    $s->supplement($owner, $r['id'], 2, 'legacy-upgrade00001', ['original_text' => '显式补齐专业信息', 'facts' => $facts + ['lift_integration' => null], 'upgrade_schema_version' => 2]);
    $dto = $s->detail($owner, $r['id']);
    same(2, $dto['schema']['version']);
    same(null, $dto['facts']['lift_integration']);
    same(3, DB::table('scene_request_revisions')->where('request_id', $r['id'])->count());
}));
test('保全读取专权不延长客户普通下载；用途强审计失败不返回原件', fn () => sceneSandbox(function () {
    global $owner;
    $f = sc2Fixture();
    $s = app(Webkul\Shop\Services\Scenes\SceneAttachmentService::class);
    $id = $f['proof']['id'];
    $req = (int) $f['request']->id;
    DB::table('scene_attachments')->where('id', $id)->update(['expires_at' => '2026-10-19 00:00:00']);
    app(SceneAttachmentRetentionService::class)->hold($req, $id, ['reference' => 'ISOLATED-HOLD', 'reason' => '必要争议原件保全'], 1);
    error('SCENE_ATTACHMENT_NOT_FOUND', fn () => $s->read($owner, $req, $id));
    same($f['proof']['path'], $s->readPreservedForAdmin($req, $id, '仅核对当前争议原件', 2)['path']);
    sceneAuditFailure(fn () => $s->readPreservedForAdmin($req, $id, '审计故障不得返回原件', 2));
}));
test('新后台动作逐项分权，只有基本查看/报价/录入权限均不能独立批准或保全读取', function () {
    $guard = new Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
    $bouncer = new class
    {
        public array $allowed = [];

        public function hasPermission($key)
        {
            return in_array($key, $this->allowed, true);
        }
    };
    Webkul\User\Facades\Bouncer::swap($bouncer);
    foreach (['policyDecision' => 'policy_review', 'contentEvidenceDecision' => 'evidence_review', 'evidenceReview' => 'supply_review', 'solutionDecision' => 'solution_review', 'preservedAttachment' => 'preserve_read', 'attachmentAction' => 'preserve', 'survey' => 'assess', 'solution' => 'assess'] as $method => $permission) {
        $r = Illuminate\Http\Request::create('/admin-test', 'POST');
        $handler = Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class.'@'.$method;
        $route = new Illuminate\Routing\Route('POST', 'admin-test', ['uses' => $handler, 'controller' => $handler]);
        $route->bind($r);
        $r->setRouteResolver(fn () => $route);
        foreach (['catalog.scene-workflow', 'catalog.scene-workflow.quote', 'catalog.scene-workflow.requests'] as $wrong) {
            $bouncer->allowed = [$wrong];
            try {
                $guard->handle($r, fn () => true);
                throw new RuntimeException('越权放行 '.$method);
            } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
                same(403, $e->getStatusCode());
            }
        }
        $bouncer->allowed = ['catalog.scene-workflow.'.$permission];
        same(true, $guard->handle($r, fn () => true));
    }
});

test('新 Admin 表单实际渲染 schema、勘察、BOM、政策和内容审核分支，公开字段转义', fn () => sceneSandbox(function () {
    global $app,$sceneTestFiles;
    $f = sc2Fixture();
    $record = $f['request'];
    $revision = DB::table('scene_request_revisions')->where('request_id', $record->id)->first();
    $facts = SceneWorkflow::unpack($revision->facts);
    $schema = SceneWorkflow::unpack($revision->schema_snapshot);
    $cache = app()->storagePath('blade-closeout');
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
    $bouncer = new class
    {
        public function hasPermission($key)
        {
            return true;
        }
    };
    Webkul\User\Facades\Bouncer::swap($bouncer);
    try {
        $data = compact('record', 'revision', 'facts', 'schema') + ['attachments' => DB::table('scene_attachments')->where('id', $f['proof']['id'])->get(), 'surveys' => collect([$f['survey']]), 'assessments' => collect([$f['assessment']]), 'solutions' => collect()];
        $html = $view->make('admin::catalog.scene-workflow.assessment-proposal', $data)->render();
        foreach (['name="verified_keys[]"', 'name="facts[floors]"', 'name="components[0][dependency_keys]"', 'name="components[0][quantity]"', 'name="acceptance[0][required]"'] as $text) {
            same(true, str_contains($html, $text));
        }
        $policy = $view->make('admin::catalog.scene-workflow.configuration', ['policies' => collect(), 'defaults' => require app()->basePath('config/scene_policy_drafts.php'), 'admins' => DB::table('admins')->get()])->render();
        same(true, str_contains($policy, 'name="owner_id"'));
        same(true, str_contains($policy, 'name="supplier_name"'));
        same(true, str_contains($policy, 'name="override_lines"'));
        $e = app(SceneContentEvidenceService::class)->create(1, ['title' => '<script>private</script>'] + sceneContentPayload(), 1);
        $pendingTask = clone $f['evidence'];
        $pendingTask->approved_at = null;
        $evidence = $view->make('admin::catalog.scene-workflow.evidence', ['evidence' => DB::table('scene_content_evidence')->where('id', $e['id'])->get(), 'scenes' => DB::table('scenes')->get(), 'taskEvidence' => collect([$pendingTask])])->render();
        same(true, str_contains($evidence, '&lt;script&gt;private&lt;/script&gt;'));
        same(true, str_contains($evidence, 'name="source_verified"'));
        same(false, str_contains($evidence, '<script>private</script>'));
    } finally {
        foreach (glob($cache.'/*.php') as $file) {
            unlink($file);
        }rmdir($cache);
    }
}));
