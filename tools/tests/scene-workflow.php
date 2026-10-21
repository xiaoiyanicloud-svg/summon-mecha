<?php

declare(strict_types=1);

// 硬隔离：不加载 .env / bootstrap/app.php，只运行内存 SQLite；不证明 MySQL 锁语义。
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use Webkul\Shop\Services\Scenes\SceneAssessmentService;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\ScenePrincipal;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneProposalService;
use Webkul\Shop\Services\Scenes\ScenePublicationService;
use Webkul\Shop\Services\Scenes\SceneRequestService;
use Webkul\Shop\Services\Scenes\SceneReservationService;
use Webkul\Shop\Services\Scenes\SceneSupplyService;
use Webkul\Shop\Services\Scenes\SceneTime;
use Webkul\Shop\Services\Scenes\SceneWorkflowException;

if (! extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('必须显式加载 pdo_sqlite。');
}
$base = dirname(__DIR__, 2).'/my-bagisto-store';
$app = new Application($base);
$sceneTestStorage = tempnam(sys_get_temp_dir(), 'scene-suite-');
unlink($sceneTestStorage);
mkdir($sceneTestStorage, 0700);
mkdir($sceneTestStorage.'/app', 0700);
mkdir($sceneTestStorage.'/app/private', 0700);
mkdir($sceneTestStorage.'/app/private/scenes', 0700);
$app->useStoragePath($sceneTestStorage);
$sceneTestFiles = [];
register_shutdown_function(function () use ($sceneTestStorage, &$sceneTestFiles) {
    foreach ($sceneTestFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (['/app/private/scenes', '/app/private', '/app', ''] as $suffix) {
        if (is_dir($sceneTestStorage.$suffix)) {
            @rmdir($sceneTestStorage.$suffix);
        }
    }
});
$sceneConfig = require $base.'/config/scenes.php';
foreach (['publication_v2', 'requests_enabled', 'quotes_enabled', 'reservations_enabled', 'project_actions_enabled', 'operations_enabled'] as $flag) {
    $sceneConfig[$flag] = true;
}
$sceneConfig['terms'] = ['version' => 'test-v1', 'text' => '仅为隔离测试条款'];
$sceneConfig['commercial_policy'] = ['version' => 'test-v1', 'required_price_codes' => ['robot.test', 'transport.test']];
$app->instance('config', new Repository(['app' => ['key' => 'test-only', 'locale'=>'en', 'timezone'=>'UTC'], 'scenes' => $sceneConfig]));
$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->instance('db.schema', $db->schema());
Facade::setFacadeApplication($app);
if (DB::connection()->getConfig('database') !== ':memory:') {
    throw new RuntimeException('拒绝非内存数据库。');
}
$app->instance('encrypter', new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
$validator = new Factory(new Translator(new ArrayLoader, 'en'), $app);
$validator->setPresenceVerifier(new DatabasePresenceVerifier($db->getDatabaseManager()));
$app->instance('validator', $validator);
Carbon::setTestNow(Carbon::parse('2026-10-20T00:00:00Z'));
$schema = $db->schema();
foreach (['customers', 'products', 'orders', 'admins', 'device_model', 'device_binding', 'channels', 'product_flat', 'product_channels', 'product_categories', 'category_translations'] as $table) {
    $schema->create($table, function (Blueprint $t) use ($table) {
        $t->increments('id');
        if ($table === 'products') {
            $t->string('type');
        }
        if ($table === 'admins') {
            $t->string('name');
            $t->boolean('status')->default(true);
        }
        if ($table === 'device_binding') {
            $t->string('bind_status');
            $t->unsignedInteger('owner_customer_id');
            $t->string('did')->nullable();
            $t->unsignedInteger('model_id')->nullable();
            $t->json('meta')->nullable();
        }
        if ($table === 'device_model') {
            $t->string('model_code');
        }
        if ($table === 'channels') {
            $t->string('code');
        }
        if ($table === 'product_flat') {
            $t->integer('product_id');
            $t->string('channel');
            $t->integer('status');
            $t->integer('visible_individually');
        }
        if ($table === 'product_channels') {
            $t->integer('product_id');
            $t->integer('channel_id');
        }
        if ($table === 'product_categories') {
            $t->integer('product_id');
            $t->integer('category_id');
        }
        if ($table === 'category_translations') {
            $t->integer('category_id');
            $t->string('slug');
        }
    });
}
$schema->create('operation_log', function (Blueprint $t) {
    $t->bigIncrements('id');
    $t->string('actor_type');
    $t->integer('actor_id')->nullable();
    $t->string('action');
    $t->string('target_type');
    $t->bigInteger('target_id');
    $t->json('payload')->nullable();
    $t->string('ip')->nullable();
    $t->timestamp('created_at');
});
foreach (glob($base.'/packages/Webkul/Shop/src/Database/Migrations/*scenes_table.php') as $migration) {
    (require $migration)->up();
}
foreach (array_merge(glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_20_10000*.php'), glob($base.'/packages/Webkul/Shop/src/Database/Migrations/2026_10_21_10000*.php')) as $migration) {
    (require $migration)->up();
}
DB::table('customers')->insert([['id'=>1], ['id'=>2]]);
DB::table('admins')->insert([['id'=>1, 'name'=>'编制'], ['id'=>2, 'name'=>'审核']]);
DB::table('products')->insert([['id'=>11, 'type'=>'simple']]);
DB::table('device_model')->insert(['id' => 1, 'model_code' => 'isolated-test-model']);
DB::table('device_binding')->insert(['id' => 10, 'bind_status' => 'bound', 'owner_customer_id' => 1, 'did' => 'TEST-SCENE-DID', 'model_id' => 1, 'meta' => '{}']);
DB::table('channels')->insert(['id'=>1, 'code'=>'default']);
DB::table('product_flat')->insert(['product_id'=>11, 'channel'=>'default', 'status'=>1, 'visible_individually'=>1]);
DB::table('product_channels')->insert(['product_id'=>11, 'channel_id'=>1]);
DB::table('product_categories')->insert(['product_id'=>11, 'category_id'=>1]);
DB::table('category_translations')->insert(['category_id'=>1, 'slug'=>'rent']);
DB::table('scenes')->insert(['id'=>1, 'name'=>'场景', 'status'=>1, 'category'=>'enterprise', 'created_at'=>now(), 'updated_at'=>now()]);
$owner = new ScenePrincipal(1, null);
$other = new ScenePrincipal(2, null);
$guest = new ScenePrincipal(null, hash('sha256', str_repeat('a', 64)));
DB::table('scene_guest_capabilities')->insert(['token_hash'=>$guest->guestHash, 'expires_at'=>'2026-11-19 00:00:00', 'created_at'=>now()]);
$publication = app(ScenePublicationService::class);
$requests = app(SceneRequestService::class);
$supply = app(SceneSupplyService::class);
$proposals = app(SceneProposalService::class);
$tests = [];
function same($a, $b): void
{
    if ($a !== $b) {
        throw new RuntimeException('断言不符：'.json_encode([$a, $b], JSON_UNESCAPED_UNICODE));
    }
}
function error(string $code, callable $fn): void
{
    try {
        $fn();
    } catch (SceneWorkflowException $e) {
        same($code, $e->errorCode);

        return;
    } throw new RuntimeException('预期拒绝 '.$code);
}
function test(string $name, callable $fn): void
{
    global $tests;
    try {
        $fn();
        $tests[] = ['name'=>$name, 'pass'=>true];
    } catch (Throwable $e) {
        $tests[] = ['name'=>$name, 'pass'=>false, 'error'=>$e->getMessage(), 'line'=>$e->getFile().':'.$e->getLine()];
    }
}
function sceneSandbox(callable $fn): void
{
    $level = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $fn();
    } finally {
        DB::rollBack($level);
    }
}
$publicId = 0;
$requestId = 0;
$quoteId = 0;
$assessmentId = 0;
$solutionId = 0;
$resourceId = 0;
test('实际增量迁移及 FK 生效，客户不能插入孤儿需求', function () use ($schema) {
    same(true, $schema->hasTable('scene_quote_acceptances'));
    try {
        DB::table('scene_request_revisions')->insert(['request_id'=>999, 'revision_no'=>1, 'original_text'=>'x', 'facts'=>'{}', 'schema_snapshot'=>'{}', 'input_hash'=>str_repeat('a', 64), 'created_at'=>now()]);
    } catch (Illuminate\Database\QueryException) {
        return;
    }
    throw new RuntimeException('外键未生效');
});
test('发布须独立批准；旧 row_version 冲突；批准内容不可覆盖', function () use ($publication, &$publicId) {
    $draft = $publication->draft(1, ['workflow_template'=>'manual_site_v1', 'name'=>'展会迎宾', 'description'=>'人工交付样板', 'category'=>'enterprise', 'robots'=>[11], 'tags'=>['迎宾'], 'deliverables'=>['到场与彩排'], 'acceptance'=>[['key'=>'greeting', 'label'=>'按冻结脚本完成迎宾', 'required'=>true]]], 1);
    $publicId = $draft['id'];
    error('SCENE_VERSION_CONFLICT', fn () =>$publication->transition(1, $publicId, 2, 'review', 1));
    $publication->transition(1, $publicId, 1, 'review', 1);
    error('SCENE_SELF_APPROVAL', fn () =>$publication->transition(1, $publicId, 2, 'approve', 1));
    $publication->transition(1, $publicId, 2, 'approve', 2);
    $publication->transition(1, $publicId, 3, 'publish', 2);
    same($publicId, (int) $publication->active(1)->id);
});
$facts = ['city' => '上海', 'site' => '隔离测试展厅', 'timezone' => 'Asia/Shanghai', 'starts_at' => '2026-11-01T10:00:00+08:00', 'ends_at' => '2026-11-01T18:00:00+08:00', 'indoor' => true, 'floor_level' => true, 'power' => true, 'safety_contact' => true,
    'task_goal' => '受控迎宾和现场讲解', 'languages' => '中文，知识库由测试负责人审核', 'route_width_mm' => 2000, 'floors' => 1, 'network_available' => true, 'knowledge_owner_confirmed' => true];
function requestPayload(string $text = '完整原始需求'): array
{
    global $publicId,$facts;

    return ['scene_id'=>1, 'scene_revision_id'=>$publicId, 'original_text'=>$text, 'facts'=>$facts, 'source'=>'scene', 'consent_version'=>'scene-contact-v1', 'contact'=>['name'=>'测试联系人', 'phone'=>'13800000000', 'company'=>'测试']];
}
test('需求幂等、载荷冲突、跨主体与完整原文；联系方式加密且无客资 outbox', function () use ($requests, $owner, $other, &$requestId) {
    $text = str_repeat('完整需求文本', 1000);
    $result = $requests->create($owner, 'request-key-00001', requestPayload($text));
    $requestId = $result['id'];
    same($result, $requests->create($owner, 'request-key-00001', requestPayload($text)));
    same($text, DB::table('scene_request_revisions')->where('request_id', $requestId)->value('original_text'));
    error('IDEMPOTENCY_CONFLICT', fn () =>$requests->create($owner, 'request-key-00001', requestPayload('不同')));
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$requests->detail($other, $requestId));
    same(false, str_contains(DB::table('scene_request_contacts')->where('request_id', $requestId)->value('encrypted_contact'), '13800000000'));
    same(false, str_contains(json_encode(DB::table('business_outbox')->get()), '13800000000'));
});
test('强审计故障使需求、幂等键、outbox 同事务回滚', function () use ($requests, $owner) {
    $before = DB::table('scene_requests')->count();
    $outbox = DB::table('business_outbox')->count();
    DB::unprepared("CREATE TRIGGER audit_failure BEFORE INSERT ON operation_log BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");
    try {
        $requests->create($owner, 'request-key-fail1', requestPayload());
        throw new RuntimeException('未注入失败');
    } catch (Illuminate\Database\QueryException) {
    } finally {
        DB::unprepared('DROP TRIGGER audit_failure');
    }
    same($before, DB::table('scene_requests')->count());
    same($outbox, DB::table('business_outbox')->count());
    same(0, DB::table('business_idempotency_keys')->where('key_hash', hash('sha256', 'request-key-fail1'))->count());
});
test('游客认领必须登录并证明原能力；认领撤销旧能力且不按手机号认领', function () use ($requests, $guest) {
    $r = $requests->create($guest, 'guest-request-0001', requestPayload());
    error('SCENE_CLAIM_PROOF_REQUIRED', fn () =>$requests->claim(new ScenePrincipal(1, null), $r['id']));
    error('SCENE_ACCESS_INVALID', fn () =>$requests->claim(new ScenePrincipal(1, hash('sha256', 'wrong')), $r['id']));
    $requests->claim(new ScenePrincipal(1, $guest->guestHash), $r['id']);
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$requests->detail($guest, $r['id']));
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$requests->create($guest, 'guest-request-0001', requestPayload()));
});
test('未签发或已过期游客能力不能创建需求，不能用自选弱 token 冒充签发凭证', function () use ($requests, $guest) {
    error('SCENE_ACCESS_INVALID', fn () => $requests->create(new ScenePrincipal(null, hash('sha256', str_repeat('0', 64))), 'forged-guest-0001', requestPayload()));
    DB::table('scene_guest_capabilities')->where('token_hash', $guest->guestHash)->update(['expires_at'=>'2026-10-19 00:00:00']);
    error('SCENE_ACCESS_INVALID', fn () => $requests->create($guest, 'expired-guest-001', requestPayload()));
    DB::table('scene_guest_capabilities')->where('token_hash', $guest->guestHash)->update(['expires_at'=>'2026-11-19 00:00:00']);
});
test('未知设备或无证据只能待人工核实，硬条件失败优先', function () use ($requestId) {
    $s = app(SceneAssessmentService::class);
    $r = DB::table('scene_requests')->find($requestId);
    $out = $s->assess($requestId, (int) $r->row_version, ['candidates'=>[['product_id'=>11, 'capability'=>'greeting']]], 1, 1);
    same('needs_manual_review', $out['state']);
});
test('具体设备证据通过后评估与服务端定点报价；客户端价格不被读取', function () use ($supply, $requestId, $proposals, &$quoteId, &$assessmentId, &$solutionId, $facts) {
    global $sceneTestFiles;
    $file = storage_path('app/private/scenes/00000000-0000-4000-8000-000000000488');
    file_put_contents($file, "%PDF-1.4\nSCENE SURVEY FIXTURE\n%%EOF");
    $sceneTestFiles[] = $file;
    $proof = DB::table('scene_attachments')->insertGetId(['request_id' => $requestId, 'storage_key' => basename($file), 'sha256' => hash_file('sha256', $file), 'mime' => 'application/pdf', 'size' => filesize($file), 'state' => 'clean', 'expires_at' => '2027-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $r = DB::table('scene_requests')->find($requestId);
    $survey = app(Webkul\Shop\Services\Scenes\SceneSurveyService::class)->record($requestId, (int) $r->row_version, ['facts' => $facts, 'verified_keys' => array_keys($facts), 'evidence_ids' => [$proof], 'occurred_at' => '2026-10-19T18:00:00+08:00', 'note' => '隔离测试现场勘察原件'], 1);
    $e = $supply->evidence(['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'level' => 'L1', 'scope' => '本测试现场', 'source_reference' => '隔离报告', 'artifact_hash' => str_repeat('b', 64), 'verified_at' => '2026-10-19 00:00:00', 'expires_at' => '2026-12-31 00:00:00', 'constraints' => ['categories' => ['enterprise'], 'min_clearance_mm' => 1000, 'max_floors' => 1]], 2);
    $supply->reviewEvidence($e['id'], ['source_verified' => true, 'reason' => '已核实测试设备与任务核验记录'], 1);
    $r = DB::table('scene_requests')->find($requestId);
    $a = app(SceneAssessmentService::class)->assess($requestId, (int) $r->row_version, ['survey_id' => $survey['id'], 'candidates' => [['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'evidence_id' => $e['id']]]], 1, 1);
    same('feasible', $a['state']);
    $assessmentId = $a['id'];
    $common = ['currency'=>'CNY', 'tax_bps'=>600, 'valid_from'=>'2026-10-01 00:00:00', 'valid_until'=>'2026-12-31 00:00:00', 'charge_type'=>'fee', 'unit'=>'day'];
    $p = $supply->price(['code'=>'robot.test', 'name'=>'机器人', 'kind'=>'robot', 'product_id'=>11, 'unit_minor'=>10001] + $common, 1);
    $t = $supply->price(['code'=>'transport.test', 'name'=>'运输', 'kind'=>'transport', 'unit_minor'=>2000] + $common, 1);
    $solution = app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->draft($requestId, (int) DB::table('scene_requests')->find($requestId)->row_version,
        ['assessment_id' => $assessmentId, 'title' => '隔离迎宾方案', 'scope' => '按冻结脚本完成受控迎宾', 'exclusions' => '不包含自主跨楼层和无人值守',
            'components' => [
                ['key' => 'robot', 'label' => '机器人', 'kind' => 'robot', 'product_id' => 11, 'price_code' => 'robot.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'required' => true, 'selected' => true, 'dependencies' => []],
                ['key' => 'transport', 'label' => '运输', 'kind' => 'transport', 'price_code' => 'transport.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'required' => true, 'selected' => true, 'dependencies' => ['robot']]],
            'acceptance' => [['key' => 'greeting', 'label' => '完成冻结迎宾脚本', 'required' => true]]], 1, 1);
    $solutionId = $solution['id'];
    app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->decide($solutionId, 1, 'approve', '已复核范围、BOM 与验收标准', 2, 1);
    $r = DB::table('scene_requests')->find($requestId);
    $q = $proposals->draft($requestId, (int) $r->row_version, ['assessment_id' => $a['id'], 'proposal_id' => $solutionId, 'items' => [['price_item_id' => $p['id'], 'quantity' => 1, 'billing_units' => 1], ['price_item_id' => $t['id'], 'quantity' => 1, 'billing_units' => 1]], 'discount_bps' => 1000, 'expires_at' => '2026-10-30 00:00:00', 'schedule' => array_intersect_key($facts, array_flip(['starts_at', 'ends_at', 'timezone'])) + ['buffer_before_minutes' => 60, 'buffer_after_minutes' => 60]], 1, 1);
    $quoteId = $q['id'];
    $row = DB::table('scene_quotes')->find($quoteId);
    // 10001 - 1000 + 540 + 2000 - 200 +108 = 11449，逐行半入。
    same(11449, (int) $row->total_minor);
});
function quoteFixtureInput(int $assessmentId, array $overrides = []): array
{
    global $facts,$solutionId;

    return array_replace(['assessment_id' => $assessmentId, 'proposal_id' => $solutionId,
        'items'=>[['price_item_id'=>1, 'quantity'=>1, 'billing_units'=>1], ['price_item_id'=>2, 'quantity'=>1, 'billing_units'=>1]],
        'discount_bps'=>1000, 'expires_at'=>'2026-10-30 00:00:00',
        'schedule'=>array_intersect_key($facts, array_flip(['starts_at', 'ends_at', 'timezone'])) + ['buffer_before_minutes'=>60, 'buffer_after_minutes'=>60]], $overrides);
}
function sendFixtureQuote(int $id): void
{
    $s = app(SceneProposalService::class);
    $s->transition($id, 1, 'review', 1, 1);
    $s->transition($id, 2, 'approve', 2, 1);
    $s->transition($id, 3, 'send', 1, 1);
}
test('未发送草稿即使失效也不能进入客户历史或比较接口', function () use ($requests, $owner, $proposals, $quoteId, $requestId) {
    sceneSandbox(function () use ($requests, $owner, $proposals, $quoteId, $requestId) {
        same([], $requests->detail($owner, $requestId)['quotes']);
        app(Webkul\Shop\Services\Scenes\SceneQuoteLifecycleService::class)->invalidate($requestId, ['draft'], 'test', 'admin', 1);
        same('voided', DB::table('scene_quotes')->find($quoteId)->state);
        same([], $requests->detail($owner, $requestId)['quotes']);
        error('SCENE_OBJECT_NOT_FOUND', fn () => $proposals->customerDto(DB::table('scene_quotes')->find($quoteId)));
        error('SCENE_OBJECT_NOT_FOUND', fn () => $proposals->compare($owner, $requestId, $quoteId, 999));
    });
});
test('独立审批人可有理由退回；不可自退或重审原版，须创建新版', function () use ($proposals, $quoteId, $requestId, $assessmentId) {
    sceneSandbox(function () use ($proposals, $quoteId, $requestId, $assessmentId) {
        $proposals->transition($quoteId, 1, 'review', 1, 1);
        error('SCENE_SELF_APPROVAL', fn () => $proposals->transition($quoteId, 2, 'return', 1, 1, '待调整服务范围'));
        $hash = DB::table('scene_quotes')->find($quoteId)->content_hash;
        same('returned', $proposals->transition($quoteId, 2, 'return', 2, 1, '需要补足运输说明')['state']);
        same($hash, DB::table('scene_quotes')->find($quoteId)->content_hash);
        error('SCENE_STATE_CONFLICT', fn () => $proposals->transition($quoteId, 3, 'review', 1, 1));
        same(1, DB::table('scene_quote_decisions')->where('quote_id', $quoteId)->count());
        $new = $proposals->draft($requestId, (int) DB::table('scene_requests')->find($requestId)->row_version, quoteFixtureInput($assessmentId), 1, 1);
        same(2, (int) DB::table('scene_quotes')->find($new['id'])->version);
        same(false, str_contains(DB::table('business_outbox')->where('event_type', 'scene.quote.return')->value('payload'), '运输说明'));
    });
});
test('退回审计失败整体回滚报价、决策和 outbox', function () use ($proposals, $quoteId) {
    sceneSandbox(function () use ($proposals, $quoteId) {
        $proposals->transition($quoteId, 1, 'review', 1, 1);
        DB::unprepared("CREATE TRIGGER fail_quote_return BEFORE INSERT ON operation_log WHEN NEW.action = 'scene.quote.return' BEGIN SELECT RAISE(ABORT, 'TEST_AUDIT_FAILURE'); END");
        try {
            try {
                $proposals->transition($quoteId, 2, 'return', 2, 1, '需修改条款');
                throw new RuntimeException('审计失败未回滚');
            } catch (Illuminate\Database\QueryException) {
            }
            same('in_review', DB::table('scene_quotes')->find($quoteId)->state);
            same(0, DB::table('scene_quote_decisions')->where('quote_id', $quoteId)->count());
            same(0, DB::table('business_outbox')->where('event_type', 'scene.quote.return')->count());
        } finally {
            DB::unprepared('DROP TRIGGER fail_quote_return');
        }
    });
});
test('客户拒绝核验对象、版本和指纹；幂等一次，释放 held 而不伪造接受', function () use ($proposals, $supply, $owner, $other, $quoteId) {
    sceneSandbox(function () use ($proposals, $supply, $owner, $other, $quoteId) {
        sendFixtureQuote($quoteId);
        $q = DB::table('scene_quotes')->find($quoteId);
        $r = $supply->resource(['resource_no'=>'TEST-REJECT-ROBOT', 'kind'=>'robot', 'product_id'=>11, 'binding_id'=>10, 'external_reference'=>'隔离夹具', 'city'=>'上海', 'timezone'=>'Asia/Shanghai', 'verification_expires_at'=>'2026-12-31 00:00:00'], 2);
        app(SceneReservationService::class)->hold($quoteId, ['resource_ids'=>[$r['id']]], 'reject-hold-00001', 1, 1);
        $data = ['row_version'=>4, 'reason'=>'服务内容需要调整', 'terms_hash'=>$q->terms_hash, 'content_hash'=>$q->content_hash];
        error('SCENE_OBJECT_NOT_FOUND', fn () => $proposals->reject($other, $quoteId, 'quote-reject-0001', $data));
        error('SCENE_VERSION_CONFLICT', fn () => $proposals->reject($owner, $quoteId, 'quote-reject-old1', ['row_version'=>1] + $data));
        error('SCENE_TERMS_CHANGED', fn () => $proposals->reject($owner, $quoteId, 'quote-reject-hash', ['content_hash'=>str_repeat('a',64)] + $data));
        $result = $proposals->reject($owner, $quoteId, 'quote-reject-0001', $data);
        same($result, $proposals->reject($owner, $quoteId, 'quote-reject-0001', $data));
        same(1, DB::table('scene_quote_decisions')->where('quote_id', $quoteId)->where('kind','customer_rejected')->count());
        same('released', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
        same(0, DB::table('scene_quote_acceptances')->where('quote_id', $quoteId)->count());
        error('SCENE_QUOTE_UNAVAILABLE', fn () => $proposals->accept($owner, $quoteId, 'rejected-accept01', $q->terms_hash, $q->content_hash, 1));
    });
});
test('新版发送同事务释放旧 hold、递增旧版本并保留公开历史；比较无内部政策和审核意见', function () use ($proposals, $supply, $owner, $other, $quoteId, $requestId, $assessmentId) {
    sceneSandbox(function () use ($proposals, $supply, $owner, $other, $quoteId, $requestId, $assessmentId) {
        sendFixtureQuote($quoteId);
        $r = $supply->resource(['resource_no'=>'TEST-SUPERSEDE-ROBOT', 'kind'=>'robot', 'product_id'=>11, 'binding_id'=>10, 'external_reference'=>'隔离夹具', 'city'=>'上海', 'timezone'=>'Asia/Shanghai', 'verification_expires_at'=>'2026-12-31 00:00:00'], 2);
        app(SceneReservationService::class)->hold($quoteId, ['resource_ids'=>[$r['id']]], 'supersede-hold01', 1, 1);
        $new = $proposals->draft($requestId, (int) DB::table('scene_requests')->find($requestId)->row_version, quoteFixtureInput($assessmentId, ['discount_bps'=>0]), 1, 1);
        sendFixtureQuote($new['id']);
        $old = DB::table('scene_quotes')->find($quoteId);
        same('voided', $old->state);
        same(5, (int) $old->row_version);
        same(true, (bool) $old->customer_visible);
        same('released', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
        $terms = Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($old->terms_snapshot);
        $terms['terms']['internal_notes'] = 'PRIVATE_NOTE';
        $terms['policy']['cost_minor'] = 'PRIVATE_COST';
        DB::table('scene_quotes')->where('id', $quoteId)->update(['terms_snapshot'=>json_encode($terms)]);
        $result = $proposals->compare($owner, $requestId, $quoteId, $new['id']);
        same(1272, $result['amount_deltas']['total_minor']);
        same(0, $result['amount_deltas']['deposit_minor']);
        same(true, $result['changes']['items']);
        same(false, str_contains(json_encode($result), 'PRIVATE_'));
        error('SCENE_OBJECT_NOT_FOUND', fn () => $proposals->compare($other, $requestId, $quoteId, $new['id']));
        error('SCENE_COMPARE_DISTINCT', fn () => $proposals->compare($owner, $requestId, $quoteId, $quoteId));
    });
});
test('新版发送审计失败同时恢复旧报价、原 hold 与新报价审批状态', function () use ($proposals, $supply, $quoteId, $requestId, $assessmentId) {
    sceneSandbox(function () use ($proposals, $supply, $quoteId, $requestId, $assessmentId) {
        sendFixtureQuote($quoteId);
        $r = $supply->resource(['resource_no'=>'TEST-SEND-ROLLBACK', 'kind'=>'robot', 'product_id'=>11, 'binding_id'=>10, 'external_reference'=>'隔离夹具', 'city'=>'上海', 'timezone'=>'Asia/Shanghai', 'verification_expires_at'=>'2026-12-31 00:00:00'], 2);
        app(SceneReservationService::class)->hold($quoteId, ['resource_ids'=>[$r['id']]], 'rollback-hold001', 1, 1);
        $new = $proposals->draft($requestId, (int) DB::table('scene_requests')->find($requestId)->row_version, quoteFixtureInput($assessmentId), 1, 1);
        $proposals->transition($new['id'], 1, 'review', 1, 1);
        $proposals->transition($new['id'], 2, 'approve', 2, 1);
        DB::unprepared("CREATE TRIGGER fail_quote_send BEFORE INSERT ON operation_log WHEN NEW.action = 'scene.quote.send' BEGIN SELECT RAISE(ABORT, 'TEST_AUDIT_FAILURE'); END");
        try {
            try {
                $proposals->transition($new['id'], 3, 'send', 1, 1);
                throw new RuntimeException('发送失败未回滚');
            } catch (Illuminate\Database\QueryException) {
            }
            same('sent', DB::table('scene_quotes')->find($quoteId)->state);
            same(4, (int) DB::table('scene_quotes')->find($quoteId)->row_version);
            same('held', DB::table('scene_reservations')->where('quote_id',$quoteId)->value('state'));
            same('approved', DB::table('scene_quotes')->find($new['id'])->state);
            same(false, (bool) DB::table('scene_quotes')->find($new['id'])->customer_visible);
        } finally {
            DB::unprepared('DROP TRIGGER fail_quote_send');
        }
    });
});
test('拒绝产生可处理重编待办，新版发送后扫描关闭；旧事件不重新打开', function () use ($proposals, $owner, $quoteId, $requestId, $assessmentId) {
    sceneSandbox(function () use ($proposals, $owner, $quoteId, $requestId, $assessmentId) {
        sendFixtureQuote($quoteId);
        $q = DB::table('scene_quotes')->find($quoteId);
        $proposals->reject($owner, $quoteId, 'todo-reject-00001', ['row_version'=>4, 'reason'=>'请调整服务范围', 'terms_hash'=>$q->terms_hash, 'content_hash'=>$q->content_hash]);
        $ops = app(SceneOperationsService::class);
        for ($i = 0; $i < 100 && $ops->consumeOne()['state'] !== 'idle'; $i++) {
        }
        $todoKey = 'quote-rework:'.$quoteId;
        same('open', DB::table('scene_operation_todos')->where('dedupe_key', $todoKey)->value('state'));
        $new = $proposals->draft($requestId, (int) DB::table('scene_requests')->find($requestId)->row_version, quoteFixtureInput($assessmentId), 1, 1);
        sendFixtureQuote($new['id']);
        $ops->sweep();
        same('resolved', DB::table('scene_operation_todos')->where('dedupe_key', $todoKey)->value('state'));
        $event = DB::table('business_outbox')->where('event_type','scene.quote.rejected')->where('aggregate_id',$quoteId)->first();
        DB::table('business_outbox')->where('id',$event->id)->update(['processed_at'=>null]);
        for ($i = 0; $i < 100 && $ops->consumeOne()['state'] !== 'idle'; $i++) {
        }
        same('resolved', DB::table('scene_operation_todos')->where('dedupe_key', $todoKey)->value('state'));
    });
});
test('有 confirmed 预留的需求修订必须走变更；不能清掉已承诺资源', function () use ($requests, $owner, $requestId, $quoteId, $facts, $supply) {
    sceneSandbox(function () use ($requests, $owner, $requestId, $quoteId, $facts, $supply) {
        sendFixtureQuote($quoteId);
        $r = $supply->resource(['resource_no'=>'TEST-CONFIRMED-ROBOT', 'kind'=>'robot', 'product_id'=>11, 'binding_id'=>10, 'external_reference'=>'隔离夹具', 'city'=>'上海', 'timezone'=>'Asia/Shanghai', 'verification_expires_at'=>'2026-12-31 00:00:00'], 2);
        app(SceneReservationService::class)->hold($quoteId, ['resource_ids'=>[$r['id']]], 'confirmed-hold01', 1, 1);
        DB::table('scene_reservations')->where('quote_id', $quoteId)->update(['state'=>'confirmed']);
        $row = DB::table('scene_requests')->find($requestId);
        error('SCENE_CHANGE_REQUIRED', fn () => $requests->supplement($owner, $requestId, (int) $row->row_version, 'confirmed-supp01', ['original_text'=>'不得直接改期', 'facts'=>$facts]));
        same((int) $row->revision_no, (int) DB::table('scene_requests')->find($requestId)->revision_no);
        same('confirmed', DB::table('scene_reservations')->where('quote_id', $quoteId)->value('state'));
        same('sent', DB::table('scene_quotes')->find($quoteId)->state);
    });
});
test('自审拒绝；审批与发送后客户确认 hash；重试不重复接受', function () use ($proposals, &$quoteId, $owner, $other) {
    $proposals->transition($quoteId, 1, 'review', 1, 1);
    error('SCENE_SELF_APPROVAL', fn () =>$proposals->transition($quoteId, 2, 'approve', 1, 1));
    $proposals->transition($quoteId, 2, 'approve', 2, 1);
    $proposals->transition($quoteId, 3, 'send', 1, 1);
    $row = DB::table('scene_quotes')->find($quoteId);
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$proposals->accept($other, $quoteId, 'accept-key-000001', $row->terms_hash, $row->content_hash, 1));
    error('SCENE_TERMS_CHANGED', fn () =>$proposals->accept($owner, $quoteId, 'accept-key-wrong1', str_repeat('c', 64), $row->content_hash, 1));
    $a = $proposals->accept($owner, $quoteId, 'accept-key-000001', $row->terms_hash, $row->content_hash, 1);
    same('awaiting_confirmation', $a['delivery_status']);
    same($a, $proposals->accept($owner, $quoteId, 'accept-key-000001', $row->terms_hash, $row->content_hash, 1));
    same(1, DB::table('scene_quote_acceptances')->where('quote_id', $quoteId)->count());
});
test('已接受报价不能改走拒绝入口，客户决定和需求状态保持原事实', function () use ($proposals, $owner, $quoteId, $requestId) {
    $q = DB::table('scene_quotes')->find($quoteId);
    error('SCENE_STATE_CONFLICT', fn () => $proposals->reject($owner, $quoteId, 'accepted-reject01', ['row_version'=>(int) $q->row_version, 'reason'=>'不能直接撤销接受', 'terms_hash'=>$q->terms_hash, 'content_hash'=>$q->content_hash]));
    same('accepted', DB::table('scene_quotes')->find($quoteId)->state);
    same('accepted', DB::table('scene_requests')->find($requestId)->state);
    same(1, DB::table('scene_quote_acceptances')->where('quote_id',$quoteId)->count());
    same(0, DB::table('scene_quote_decisions')->where('quote_id',$quoteId)->count());
});
test('半开区间、缓冲、时区与 DST 非法时间明确拒绝', function () {
    $s = SceneTime::schedule(['starts_at'=>'2026-11-01T10:00:00+08:00', 'ends_at'=>'2026-11-01T11:00:00+08:00', 'timezone'=>'Asia/Shanghai', 'buffer_before_minutes'=>60, 'buffer_after_minutes'=>30]);
    same('2026-11-01 01:00:00', $s['occupies_from']);
    same('2026-11-01 03:30:00', $s['occupies_until']);
    error('SCENE_LOCAL_TIME_INVALID', fn () =>SceneTime::schedule(['starts_at'=>'2026-03-08T02:30:00-05:00', 'ends_at'=>'2026-03-08T04:00:00-04:00', 'timezone'=>'America/New_York', 'buffer_before_minutes'=>0, 'buffer_after_minutes'=>0]));
});
test('逐台 BOM 预留、活跃冲突拒绝、过期即时可用；不证明 MySQL 并发', function () use ($supply, &$resourceId, $quoteId) {
    $r = $supply->resource(['resource_no'=>'TEST-ROBOT-1', 'kind'=>'robot', 'product_id'=>11, 'binding_id'=>10, 'external_reference'=>'独立夹具', 'city'=>'上海', 'timezone'=>'Asia/Shanghai', 'verification_expires_at'=>'2026-12-31 00:00:00'], 2);
    $resourceId = $r['id'];
    $service = app(SceneReservationService::class);
    $data = ['resource_ids'=>[$resourceId]];
    $hold = $service->hold($quoteId, $data, 'hold-key-00000001', 1, 1);
    same('held', $hold['state']);
    error('SCENE_RESOURCE_CONFLICT', fn () =>$service->hold($quoteId, $data, 'hold-key-00000002', 2, 1));
    DB::table('scene_reservations')->update(['hold_expires_at'=>now()->subSecond()]);
    same('held', $service->hold($quoteId, $data, 'hold-key-00000003', 2, 1)['state']);
});
test('补充创建新修订并失效报价与预留；旧版本拒绝覆盖', function () use ($requests, $owner, $requestId, $facts, $quoteId) {
    $row = DB::table('scene_requests')->find($requestId);
    error('SCENE_VERSION_CONFLICT', fn () =>$requests->supplement($owner, $requestId, 1, 'supplement-key-01', ['original_text'=>'补充', 'facts'=>$facts]));
    $requests->supplement($owner, $requestId, (int) $row->row_version, 'supplement-key-02', ['original_text'=>'补充后的完整需求', 'facts'=>$facts]);
    same(2, DB::table('scene_request_revisions')->where('request_id', $requestId)->count());
    same('voided', DB::table('scene_quotes')->where('id', $quoteId)->value('state'));
    same(0, DB::table('scene_reservations')->where('state', 'held')->count());
});
$projectId = 0;
$attachmentId = 0;
test('交付夹具独立于订单转换；冻结模板生成依赖任务一次', function () use ($requestId, $quoteId, &$projectId, &$attachmentId) {
    // 明确是测试夹具，不经生产 API 建项目，不证明订单转换。
    $projectId = DB::table('scene_projects')->insertGetId(['project_no'=>'project-test-0001', 'request_id'=>$requestId, 'quote_id'=>$quoteId, 'state'=>'preparing', 'snapshot'=>json_encode(['workflow'=>config('scenes.workflows.manual_site_v1'), 'acceptance_template'=>[['key'=>'greeting', 'label'=>'完成迎宾', 'required'=>true]]]), 'created_at'=>now(), 'updated_at'=>now()]);
    $attachmentId = DB::table('scene_attachments')->insertGetId(['request_id'=>$requestId, 'storage_key'=>'00000000-0000-4000-8000-000000000001', 'sha256'=>str_repeat('d', 64), 'mime'=>'application/pdf', 'size'=>128, 'state'=>'clean', 'expires_at'=>'2027-01-01 00:00:00', 'created_at'=>now(), 'updated_at'=>now()]);
    DB::transaction(function () use ($projectId) {
        $s = app(SceneProjectService::class);
        $s->initializeTasks($projectId, 1);
        $s->initializeTasks($projectId, 1);
    });
    same(5, DB::table('scene_project_tasks')->where('project_id', $projectId)->count());
});
test('已有项目即使旧需求状态不一致，也不能直接补充或关闭并覆盖原承诺', function () use ($requests, $owner, $requestId, $facts) {
    $row = DB::table('scene_requests')->find($requestId);
    $before = DB::table('scene_request_revisions')->where('request_id', $requestId)->count();
    error('SCENE_CHANGE_REQUIRED', fn () => $requests->supplement($owner, $requestId, (int) $row->row_version, 'project-supp-0001', ['original_text'=>'已有项目不可直接重编', 'facts'=>$facts]));
    error('SCENE_CHANGE_REQUIRED', fn () => $requests->operate($requestId, (int) $row->row_version, 'close', ['note'=>'已有项目不可直接关闭'], 1));
    same($before, DB::table('scene_request_revisions')->where('request_id', $requestId)->count());
    same((int) $row->row_version, (int) DB::table('scene_requests')->find($requestId)->row_version);
});
test('交付任务不能跳过前置、漏检查或使用跨对象证据；保留实际时间与人工来源', function () use ($projectId, $attachmentId) {
    $s = app(SceneProjectService::class);
    $tasks = DB::table('scene_project_tasks')->where('project_id', $projectId)->orderBy('id')->get();
    $valid = ['checks'=>['scope_confirmed', 'safety_checked', 'evidence_recorded'], 'evidence_ids'=>[$attachmentId], 'occurred_at'=>'2026-10-19T12:00:00+08:00'];
    error('SCENE_TASK_DEPENDENCY', fn () =>$s->completeTask($projectId, (int) $tasks->last()->id, 1, $valid, 1));
    error('SCENE_TASK_CHECKS_INCOMPLETE', fn () =>$s->completeTask($projectId, (int) $tasks->first()->id, 1, ['checks'=>['scope_confirmed']] + $valid, 1));
    error('SCENE_EVIDENCE_INVALID', fn () =>$s->completeTask($projectId, (int) $tasks->first()->id, 1, ['evidence_ids'=>[999]] + $valid, 1));
    foreach ($tasks as $task) {
        $s->completeTask($projectId, (int) $task->id, 1, $valid, 1);
    }
    same('awaiting_acceptance', DB::table('scene_projects')->where('id', $projectId)->value('state'));
    $proof = json_decode(DB::table('scene_project_tasks')->where('id', $tasks->first()->id)->value('evidence'), true);
    same('manual', $proof['source']);
    same(str_repeat('d', 64), $proof['attachments'][0]['sha256']);
});
test('客户逐项验收失败进入整改，复验追加记录；另一客户不能代验收', function () use ($projectId, $owner, $other) {
    $s = app(SceneProjectService::class);
    $row = DB::table('scene_projects')->find($projectId);
    $fail = ['items'=>[['key'=>'greeting', 'result'=>'fail', 'note'=>'需整改', 'evidence_ids'=>[]]]];
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$s->acceptance($other, $projectId, (int) $row->row_version, 'project-accept-x1', $fail));
    same('rectification', $s->acceptance($owner, $projectId, (int) $row->row_version, 'project-accept-f1', $fail)['state']);
    $row = DB::table('scene_projects')->find($projectId);
    same('accepted', $s->acceptance($owner, $projectId, (int) $row->row_version, 'project-accept-p1', ['items'=>[['key'=>'greeting', 'result'=>'pass', 'evidence_ids'=>[]]]])['state']);
    same(['fail', 'pass'], DB::table('scene_acceptance_records')->where('project_id', $projectId)->orderBy('id')->pluck('result')->all());
    same(false, (bool) DB::table('scene_projects')->where('id', $projectId)->value('returned'));
    same(0, DB::table('scene_operation_todos')->where('kind', 'acceptance_rectification')->where('state', 'open')->count());
});
test('线下凭证录入不等于收款；独立复核及退款上限逐项约束', function () use ($projectId, $attachmentId) {
    $s = app(SceneProjectService::class);
    $version = fn () =>(int) DB::table('scene_projects')->where('id', $projectId)->value('row_version');
    $record = $s->recordCommercial($projectId, $version(), ['kind'=>'payment_receipt', 'amount_minor'=>11449, 'reference'=>'TEST-BANK-1', 'evidence_ids'=>[$attachmentId]], 1);
    same('unpaid', DB::table('scene_projects')->where('id', $projectId)->value('payment_state'));
    error('SCENE_COMMERCIAL_REVIEW_CONFLICT', fn () =>$s->reviewCommercial($projectId, $record['id'], $version(), 1));
    $s->reviewCommercial($projectId, $record['id'], $version(), 2);
    same('offline_verified', DB::table('scene_projects')->where('id', $projectId)->value('payment_state'));
    $refund = $s->recordCommercial($projectId, $version(), ['kind'=>'refund_receipt', 'amount_minor'=>100, 'reference'=>'TEST-REFUND-1', 'evidence_ids'=>[$attachmentId]], 1);
    error('SCENE_REFUND_LIMIT', fn () =>$s->reviewCommercial($projectId, $refund['id'], $version(), 2));
    same(null, DB::table('scene_commercial_records')->where('id', $refund['id'])->value('reviewed_at'));
});
test('取消申请只产生待评估变更；服务售后有真实处理及客户时间线', function () use ($projectId, $owner) {
    $s = app(SceneProjectService::class);
    $version = fn () =>(int) DB::table('scene_projects')->where('id', $projectId)->value('row_version');
    $r = $s->customerAction($owner, $projectId, $version(), 'project-cancel-01', 'cancel', ['reason'=>'申请取消']);
    same(false, $r['effective']);
    same('accepted', DB::table('scene_projects')->where('id', $projectId)->value('state'));
    $ticket = $s->customerAction($owner, $projectId, $version(), 'project-ticket-01', 'after_sale', ['reason'=>'现场服务问题']);
    $s->resolveTicket($ticket['id'], 1, '已人工回访处理', 1);
    same('已人工回访处理', $s->detail($owner, $projectId)['tickets'][0]->resolution);
});
test('返库必须有检查和有效证据；不会自动退款或关闭项目', function () use ($projectId, $attachmentId) {
    $s = app(SceneProjectService::class);
    $row = DB::table('scene_projects')->find($projectId);
    $r = $s->returnResources($projectId, (int) $row->row_version, ['occurred_at'=>'2026-10-19T18:00:00+08:00', 'parts_checked'=>true, 'appearance_checked'=>true, 'damage_dispute'=>true, 'evidence_ids'=>[$attachmentId]], 1);
    same('requires_financial_review', $r['refund_status']);
    $row = DB::table('scene_projects')->find($projectId);
    same(true, (bool) $row->returned);
    same(true, (bool) $row->open_dispute);
    same('accepted', $row->state);
});
test('outbox 同事务投影与 receipt 去重；旧发布事件不恢复撤下指针', function () use ($publication, $publicId) {
    $publication->transition(1, $publicId, 4, 'withdraw', 2);
    $ops = app(SceneOperationsService::class);
    for ($i = 0; $i < 100; $i++) {
        if ($ops->consumeOne()['state'] === 'idle') {
            break;
        }
    }
    same(null, DB::table('scenes')->where('id', 1)->value('active_revision_id'));
    $row = DB::table('business_outbox')->where('event_type', 'scene.revision.publish')->first();
    DB::table('business_outbox')->where('id', $row->id)->update(['processed_at'=>null]);
    $ops->consumeOne();
    same(1, DB::table('business_event_receipts')->where('event_id', $row->event_id)->count());
    error('SCENE_UNAVAILABLE', fn () =>$publication->active(1));
    same('calendar_not_configured', $ops->metrics()['sla_status']);
});
test('订单转换硬阻断；不增加任何项目记录', function () {
    $before = DB::table('scene_projects')->count();
    error('SCENE_ORDER_CONVERSION_NOT_VERIFIED', fn () =>app(Webkul\Shop\Services\Scenes\SceneOrderConversionService::class)->convert());
    same($before, DB::table('scene_projects')->count());
});
test('停写开关阻止新旧业务写入，但原主体历史详情仍可查询', function () use ($requests, $owner, $requestId) {
    app('config')->set('scenes.requests_enabled', false);
    error('SCENE_WRITES_DISABLED', fn () =>$requests->create($owner, 'disabled-key-0001', requestPayload()));
    same($requestId, $requests->detail($owner, $requestId)['id']);
    app('config')->set('scenes.requests_enabled', true);
    same(0, Webkul\Shop\Models\Scene::enabled()->count());
});
test('旧状态入口停用后清空发布指针，重新启用不复活已撤版本', function () use ($publication) {
    DB::table('scenes')->insert(['id'=>2, 'name'=>'状态测试', 'status'=>1, 'category'=>'enterprise', 'created_at'=>now(), 'updated_at'=>now()]);
    $r = $publication->draft(2, ['workflow_template'=>'manual_site_v1', 'name'=>'状态测试', 'description'=>'服务说明', 'category'=>'enterprise', 'robots'=>[], 'tags'=>[], 'deliverables'=>['人工服务'], 'acceptance'=>[['key'=>'scope', 'label'=>'范围确认', 'required'=>true]]], 1);
    $publication->transition(2, $r['id'], 1, 'review', 1);
    $publication->transition(2, $r['id'], 2, 'approve', 2);
    $publication->transition(2, $r['id'], 3, 'publish', 2);
    $publication->availability(2, false, 2);
    $publication->availability(2, true, 2);
    same(null, DB::table('scenes')->where('id', 2)->value('active_revision_id'));
    same('withdrawn', DB::table('scene_revisions')->where('id', $r['id'])->value('state'));
    error('SCENE_UNAVAILABLE', fn () =>$publication->active(2));
});
test('额外客户端价格字段被拒，不参与报价金额计算', function () use ($proposals, $requestId, $assessmentId, $facts) {
    $before = DB::table('scene_quotes')->count();
    try {
        $proposals->draft($requestId, 1, ['assessment_id'=>$assessmentId, 'items'=>[['price_item_id'=>1, 'quantity'=>1, 'billing_units'=>1, 'unit_minor'=>0]], 'discount_bps'=>0, 'expires_at'=>'2026-10-30T00:00:00Z', 'schedule'=>array_intersect_key($facts, array_flip(['starts_at', 'ends_at', 'timezone'])) + ['buffer_before_minutes'=>0, 'buffer_after_minutes'=>0]], 1, 1);
        throw new RuntimeException('未拒绝客户端金额');
    } catch (Illuminate\Validation\ValidationException) {
    }
    same($before, DB::table('scene_quotes')->count());
});
test('私有附件读取先核对象权限；未配置扫描器拒绝上传且无可用记录', function () use ($requestId, $other, $owner, $attachmentId) {
    $s = app(Webkul\Shop\Services\Scenes\SceneAttachmentService::class);
    error('SCENE_OBJECT_NOT_FOUND', fn () =>$s->read($other, $requestId, $attachmentId));
    $file = tempnam(sys_get_temp_dir(), 'scene-test-');
    file_put_contents($file, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    $count = DB::table('scene_attachments')->count();
    try {
        error('SCENE_ATTACHMENT_SCANNER_UNAVAILABLE', fn () =>$s->upload($owner, $requestId, new Illuminate\Http\UploadedFile($file, '现场.pdf', 'application/pdf', null, true)));
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
    same($count, DB::table('scene_attachments')->count());
});
test('outbox 消费故障释放租约、保留失败原因和重试，不伪造处理成功', function () {
    for ($i = 0; $i < 100; $i++) {
        if (app(SceneOperationsService::class)->consumeOne()['state'] === 'idle') {
            break;
        }
    }
    DB::table('business_outbox')->insert(['event_id'=>'00000000-0000-4000-8000-000000000099', 'aggregate_type'=>'scene_request', 'aggregate_id'=>1, 'aggregate_seq'=>1, 'event_type'=>'scene.unknown.consumer', 'schema_version'=>1, 'payload'=>'{}', 'available_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
    $result = app(SceneOperationsService::class)->consumeOne();
    same('failed', $result['state']);
    $row = DB::table('business_outbox')->where('id', $result['id'])->first();
    same(null, $row->processed_at);
    same(null, $row->lease_token);
    same('OUTBOX_CONSUMER_UNSUPPORTED', $row->last_error_code);
    same(0, DB::table('business_event_receipts')->where('event_id', $row->event_id)->count());
});
test('场景 worker 不租赁、计数或重放其他业务事件', function () {
    $id = DB::table('business_outbox')->insertGetId(['event_id'=>'00000000-0000-4000-8000-000000000101', 'aggregate_type'=>'ecosystem_event', 'aggregate_id'=>1, 'aggregate_seq'=>1, 'event_type'=>'ecosystem.registration.created', 'schema_version'=>1, 'payload'=>'{}', 'available_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
    $before = (array) DB::table('business_outbox')->find($id);
    $ops = app(SceneOperationsService::class);
    same('idle', $ops->consumeOne()['state']);
    error('OUTBOX_SCOPE_FORBIDDEN', fn () => $ops->replay($id, 1));
    same($before, (array) DB::table('business_outbox')->find($id));
    same(DB::table('business_outbox')->whereNull('processed_at')->where('event_type','like','scene.%')->count(), $ops->metrics()['outbox_pending']);
});
test('场景事件版本或对象指纹异常进入可重试失败，不签发 receipt', function () use ($requestId) {
    $id = DB::table('business_outbox')->insertGetId(['event_id'=>'00000000-0000-4000-8000-000000000102', 'aggregate_type'=>'scene_request', 'aggregate_id'=>$requestId, 'aggregate_seq'=>1, 'event_type'=>'scene.request.submitted', 'schema_version'=>99, 'payload'=>json_encode(['id'=>$requestId,'version'=>1]), 'available_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
    $ops = app(SceneOperationsService::class);
    same('failed', $ops->consumeOne()['state']);
    $row = DB::table('business_outbox')->find($id);
    same(null, $row->processed_at);
    same(null, $row->lease_token);
    same('OUTBOX_SCHEMA_UNSUPPORTED', $row->last_error_code);
    same(0, DB::table('business_event_receipts')->where('event_id',$row->event_id)->count());
});
test('崩溃租约到期后重领并处理一次；重放已处理投影不重复建待办', function () use ($requestId) {
    $id = DB::table('business_outbox')->insertGetId(['event_id'=>'00000000-0000-4000-8000-000000000103', 'aggregate_type'=>'scene_request', 'aggregate_id'=>$requestId, 'aggregate_seq'=>1, 'event_type'=>'scene.request.submitted', 'schema_version'=>1, 'payload'=>json_encode(['id'=>$requestId,'version'=>1]), 'attempts'=>1, 'lease_token'=>'crashed-worker', 'lease_until'=>now()->subSeconds(10), 'available_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
    $ops = app(SceneOperationsService::class);
    same('processed', $ops->consumeOne()['state']);
    same(2, (int) DB::table('business_outbox')->find($id)->attempts);
    $count = DB::table('scene_operation_todos')->count();
    DB::table('business_outbox')->where('id',$id)->update(['processed_at'=>null]);
    same('processed', $ops->consumeOne()['state']);
    same($count, DB::table('scene_operation_todos')->count());
    same(1, DB::table('business_event_receipts')->where('event_id','00000000-0000-4000-8000-000000000103')->count());
});
test('真实注册路由均有显式鉴权/安全中间件，Admin 动作映射完整', function () use ($base, $app) {
    $router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
    $app->instance('router', $router);
    Illuminate\Support\Facades\Route::swap($router);
    require $base.'/packages/Webkul/Shop/src/Routes/scene-workflow.php';
    require $base.'/packages/Webkul/Admin/src/Routes/scene-workflow-routes.php';
    $public = 0;
    $admin = 0;
    foreach ($router->getRoutes() as $route) {
        if (str_starts_with($route->getName(), 'admin.')) {
            $admin++;
            same(true, in_array(Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow::class, $route->middleware(), true));
            same(true, array_key_exists($route->getActionMethod(), Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow::ACTIONS));
        } else {
            $public++;
            same(true, in_array(Webkul\Shop\Http\Middleware\SceneWorkflowSafety::class, $route->middleware(), true));
        }
    }
    same(true, $public >= 14);
    same(true, $admin >= 20);
});
test('仅查看不能直接批准；报价编制和批准分权；未知 Admin 动作拒绝', function () use ($base) {
    require_once $base.'/packages/Webkul/User/src/Http/helpers.php';
    $bouncer = new class
    {
        public array $allowed = [];

        public function hasPermission($key)
        {
            return in_array($key, $this->allowed, true);
        }
    };
    Webkul\User\Facades\Bouncer::swap($bouncer);
    $guard = new Webkul\Admin\Http\Middleware\AuthorizeSceneWorkflow;
    $request = Illuminate\Http\Request::create('/quotes/1/approve', 'POST');
    $controller = Webkul\Admin\Http\Controllers\Catalog\SceneWorkflowController::class.'@quoteAction';
    $route = new Illuminate\Routing\Route('POST', 'quotes/{id}/{action}', ['uses'=>$controller, 'controller'=>$controller]);
    $route->bind($request);
    $request->setRouteResolver(fn () =>$route);
    foreach ([[], ['catalog.scene-workflow'], ['catalog.scene-workflow.quote']] as $allowed) {
        $bouncer->allowed = $allowed;
        try {
            $guard->handle($request, fn () =>true);
            throw new RuntimeException('越权批准');
        } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
            same(403, $e->getStatusCode());
        }
    }
    same('quoteAction', $route->getActionMethod());
    same('approve', $request->route('action'));
    $bouncer->allowed = ['catalog.scene-workflow.approve'];
    same(true, bouncer()->hasPermission('catalog.scene-workflow.approve'));
    same(true, $guard->handle($request, fn () =>true));
    $returnedRequest = Illuminate\Http\Request::create('/quotes/1/return', 'POST');
    $returnedRoute = new Illuminate\Routing\Route('POST', 'quotes/{id}/{action}', ['uses'=>$controller, 'controller'=>$controller]);
    $returnedRoute->bind($returnedRequest);
    $returnedRequest->setRouteResolver(fn () => $returnedRoute);
    $bouncer->allowed = ['catalog.scene-workflow.quote'];
    try {
        $guard->handle($returnedRequest, fn () => true);
        throw new RuntimeException('报价编制角色可越权审核退回');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        same(403, $e->getStatusCode());
    }
    $bouncer->allowed = ['catalog.scene-workflow.approve'];
    same(true, $guard->handle($returnedRequest, fn () =>true));
    $unknown = new Illuminate\Routing\Route('POST', 'unknown', ['uses'=>$controller, 'controller'=>'Unknown@unmapped']);
    $request->setRouteResolver(fn () =>$unknown);
    try {
        $guard->handle($request, fn () =>true);
        throw new RuntimeException('未知动作被放行');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        same(403, $e->getStatusCode());
    }
});
require __DIR__.'/scene-acceptance-cases.php';
require __DIR__.'/scene-sc1-sc2-cases.php';

$failed = count(array_filter($tests, fn ($t) =>! $t['pass']));
echo json_encode(['environment'=>'isolated SQLite :memory:', 'tests'=>$tests, 'passed'=>count($tests) - $failed, 'failed'=>$failed, 'mysql_concurrency_verified'=>false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
exit($failed ? 1 : 0);
