<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

$app = require __DIR__.'/bootstrap.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::table('migrations')->where('migration', '2026_10_21_100008_complete_scene_resources_and_transactions')->count() !== 1 || DB::table('admins')->count() !== 0) {
    throw new RuntimeException('QA seed requires new fully migrated database');
}
DB::beginTransaction();
register_shutdown_function(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});
Schema::disableForeignKeyConstraints();
try {
    foreach ([Webkul\Installer\Database\Seeders\Attribute\DatabaseSeeder::class, Webkul\Installer\Database\Seeders\Category\DatabaseSeeder::class, Webkul\Installer\Database\Seeders\Inventory\DatabaseSeeder::class, Webkul\Installer\Database\Seeders\Core\DatabaseSeeder::class, Webkul\Installer\Database\Seeders\Customer\DatabaseSeeder::class] as $seeder) {
        app($seeder)->run(['default_locale' => 'en', 'allowed_locales' => ['en'], 'default_currency' => 'CNY', 'allowed_currencies' => ['CNY']]);
    }
} finally {
    Schema::enableForeignKeyConstraints();
}
$pass = bin2hex(random_bytes(16));
$admins = [];
foreach (['author' => null, 'reviewer' => null, 'viewer' => [], 'supply' => ['supply'], 'supply_review' => ['supply_review'], 'reserve' => ['reserve'], 'finance' => ['finance'], 'finance_review' => ['finance_review'], 'none' => ['none']] as $name => $permissions) {
    $role = Webkul\User\Models\Role::create(['name' => 'SC3 QA '.$name, 'description' => '独立 QA 专用', 'permission_type' => $permissions === null ? 'all' : 'custom', 'permissions' => $permissions === null ? [] : array_merge(['dashboard'], $name === 'none' ? [] : array_merge(['catalog.scene-workflow'], array_map(fn ($p) => 'catalog.scene-workflow.'.$p, $permissions)))]);
    $admin = Webkul\User\Models\Admin::create(['name' => 'SC3 QA '.$name, 'email' => $name.'@sc3.invalid', 'password' => Hash::make($pass), 'role_id' => $role->id, 'status' => 1]);
    $admins[$name] = ['id' => $admin->id, 'email' => $admin->email];
}
$customers = [];
foreach ([1, 2] as $n) {
    $customer = Webkul\Customer\Models\Customer::create(['first_name' => 'SC3', 'last_name' => 'QA'.$n, 'email' => 'customer'.$n.'@sc3.invalid', 'password' => Hash::make($pass), 'status' => 1, 'is_verified' => 1, 'customer_group_id' => 2, 'channel_id' => 1]);
    $customers[] = ['id' => $customer->id, 'token' => $customer->createToken('isolated-sc3-qa')->plainTextToken];
}
$cat = Webkul\Category\Models\Category::create(['position' => 1, 'status' => 1, 'parent_id' => 1, 'en' => ['name' => 'QA Rental', 'slug' => 'rent']]);
$products = [];
foreach (['robot' => 'simple', 'service' => 'virtual', 'transport' => 'virtual'] as $name => $type) {
    $repo = app(Webkul\Product\Repositories\ProductRepository::class);
    $product = $repo->create(['type' => $type, 'sku' => 'SC3-HTTP-'.strtoupper($name), 'attribute_family_id' => 1]);
    $product = $repo->update(['channel' => 'default', 'locale' => 'en', 'sku' => $product->sku, 'name' => 'SC3 QA '.$name, 'url_key' => 'sc3-http-'.$name, 'status' => 1, 'visible_individually' => 1, 'manage_stock' => 0, 'price' => 100, 'weight' => 1, 'description' => '独立集成测试商品，不是正式供给。', 'short_description' => 'SC3 QA', 'channels' => [1], 'categories' => $name === 'robot' ? [$cat->id] : [], 'inventories' => [1 => 5]], $product->id);
    app(Webkul\Product\Helpers\Indexers\Flat::class)->refresh($product);
    $products[$name] = $product->id;
}
$model = DB::table('device_model')->insertGetId(['brand' => 'SC3 QA', 'model_code' => 'sc3_http_qa', 'model_name' => 'Isolated QA', 'comm_channels' => '["manual"]', 'firmware_versions' => '["1.0"]', 'created_at' => now(), 'updated_at' => now()]);
$binding = DB::table('device_binding')->insertGetId(['did' => 'sn_sc3_http_qa', 'model_id' => $model, 'owner_customer_id' => $customers[0]['id'], 'bind_status' => 'bound', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
$policy = app(Webkul\Shop\Services\Scenes\ScenePolicyService::class);
$commercial = $policy->create('commercial', ['supplier_name' => '独立 QA 测试主体', 'supplier_contact' => 'qa@sc3.invalid', 'text' => '仅供独立集成测试，范围、取消、损坏、退款均以测试报价冻结内容为准，不用于真实客户签约。', 'required_price_codes' => ['robot.http', 'transport.http']], $admins['author']['id']);
$policy->decide($commercial['id'], 1, 'approve', '独立 QA 政策复核', $admins['reviewer']['id']);
$retention = $policy->create('retention', ['days' => 60, 'purpose' => '独立 QA 原件验证', 'deletion_notice' => '测试完成按本次运行标记清理'], $admins['author']['id']);
$policy->decide($retention['id'], 1, 'approve', '独立 QA 资料政策复核', $admins['reviewer']['id']);
$scene = Webkul\Shop\Models\Scene::create(['name' => 'SC3 HTTP QA', 'category' => 'enterprise', 'description' => '独立端到端场景', 'status' => 1, 'robots' => [$products['robot']]]);
$pub = app(Webkul\Shop\Services\Scenes\ScenePublicationService::class);
$revision = $pub->draft($scene->id, ['workflow_template' => 'manual_site_v1', 'name' => 'SC3 HTTP QA', 'description' => '独立端到端场景', 'category' => 'enterprise', 'robots' => [$products['robot']], 'tags' => ['QA'], 'deliverables' => ['到场与受控迎宾'], 'acceptance' => [['key' => 'greeting', 'label' => '完成冻结脚本', 'required' => true]]], $admins['author']['id']);
$pub->transition($scene->id, $revision['id'], 1, 'review', $admins['author']['id']);
$pub->transition($scene->id, $revision['id'], 2, 'approve', $admins['reviewer']['id']);
$pub->transition($scene->id, $revision['id'], 3, 'publish', $admins['reviewer']['id']);
$start = now('Asia/Shanghai')->addDays(10)->setTime(10, 0);
$facts = ['city' => '上海', 'site' => '独立 QA 展厅', 'timezone' => 'Asia/Shanghai', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->setTime(18, 0)->toIso8601String(), 'indoor' => true, 'floor_level' => true, 'power' => true, 'safety_contact' => true, 'task_goal' => '受控迎宾与现场讲解', 'languages' => '中文', 'route_width_mm' => 2000, 'floors' => 1, 'network_available' => true, 'knowledge_owner_confirmed' => true];
$supply = app(Webkul\Shop\Services\Scenes\SceneSupplyService::class);
$common = ['currency' => 'CNY', 'tax_bps' => 600, 'valid_from' => now('UTC')->subDay()->toIso8601String(), 'valid_until' => now('UTC')->addDays(60)->toIso8601String(), 'charge_type' => 'fee', 'unit' => 'day'];
$prices = [];
foreach (['robot' => 10001, 'transport' => 2000] as $kind => $minor) {
    $prices[$kind] = $supply->price(['code' => $kind.'.http', 'name' => 'QA '.$kind, 'kind' => $kind, 'unit_minor' => $minor] + ($kind === 'robot' ? ['product_id' => $products['robot']] : []) + $common, $admins['author']['id'])['id'];
}
$owner = new Webkul\Shop\Services\Scenes\ScenePrincipal($customers[0]['id'], null);
$request = app(Webkul\Shop\Services\Scenes\SceneRequestService::class)->create($owner, 'sc3-http-request-'.bin2hex(random_bytes(8)), ['scene_id' => $scene->id, 'scene_revision_id' => $revision['id'], 'original_text' => '独立 HTTP 集成测试完整需求', 'facts' => $facts, 'source' => 'scene', 'consent_version' => 'scene-contact-v1', 'contact' => ['name' => 'QA 联系人', 'phone' => '13800000000', 'company' => 'QA']]);
DB::table('scene_requests')->where('id', $request['id'])->update(['is_test' => true]);
$pdf = storage_path('app/qa-proof.pdf');
file_put_contents($pdf, "%PDF-1.4\nSC3 INTEGRATION FIXTURE\n%%EOF");
$proof = app(Webkul\Shop\Services\Scenes\SceneAttachmentService::class)->upload($owner, $request['id'], new Illuminate\Http\UploadedFile($pdf, 'qa.pdf', 'application/pdf', null, true), 'sc3-http-proof-'.bin2hex(random_bytes(8)));
$survey = app(Webkul\Shop\Services\Scenes\SceneSurveyService::class)->record($request['id'], 1, ['facts' => $facts, 'verified_keys' => array_keys($facts), 'evidence_ids' => [$proof['id']], 'occurred_at' => now('UTC')->subMinute()->toIso8601String(), 'note' => '独立 QA 实际原件'], $admins['author']['id']);
$e = $supply->evidence(['product_id' => $products['robot'], 'binding_id' => $binding, 'capability' => 'greeting', 'level' => 'L1', 'scope' => '独立测试现场', 'source_reference' => 'SC3-HTTP-QA-EVIDENCE', 'artifact_hash' => str_repeat('b', 64), 'verified_at' => now('UTC')->subHour()->toIso8601String(), 'expires_at' => now('UTC')->addDays(60)->toIso8601String(), 'constraints' => ['categories' => ['enterprise'], 'min_clearance_mm' => 1000, 'max_floors' => 1]], $admins['author']['id']);
$supply->reviewEvidence($e['id'], ['source_verified' => true, 'reason' => '独立 QA 核验指定设备'], $admins['reviewer']['id']);
$assessment = app(Webkul\Shop\Services\Scenes\SceneAssessmentService::class)->assess($request['id'], (int) DB::table('scene_requests')->find($request['id'])->row_version, ['survey_id' => $survey['id'], 'candidates' => [['product_id' => $products['robot'], 'binding_id' => $binding, 'capability' => 'greeting', 'evidence_id' => $e['id']]]], $admins['author']['id'], 1);
$components = [];
foreach (array_keys($prices) as $kind) {
    $components[] = ['key' => $kind, 'label' => 'QA '.$kind, 'kind' => $kind, 'price_code' => $kind.'.http', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'required' => true, 'selected' => true, 'dependencies' => $kind === 'robot' ? [] : ['robot']] + ($kind === 'robot' ? ['product_id' => $products['robot']] : []);
}
$solution = app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->draft($request['id'], (int) DB::table('scene_requests')->find($request['id'])->row_version, ['assessment_id' => $assessment['id'], 'title' => 'SC3 HTTP QA', 'scope' => '受控迎宾', 'exclusions' => '不包含自主跨楼层', 'components' => $components, 'acceptance' => [['key' => 'greeting', 'label' => '完成冻结脚本', 'required' => true]]], $admins['author']['id'], 1);
app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->decide($solution['id'], 1, 'approve', '独立 QA 方案复核', $admins['reviewer']['id'], 1);
$quotes = app(Webkul\Shop\Services\Scenes\SceneProposalService::class);
$quote = $quotes->draft($request['id'], (int) DB::table('scene_requests')->find($request['id'])->row_version, ['assessment_id' => $assessment['id'], 'proposal_id' => $solution['id'], 'items' => array_map(fn ($p) => ['price_item_id' => $p, 'quantity' => 1, 'billing_units' => 1], array_values($prices)), 'discount_bps' => 1000, 'expires_at' => now('UTC')->addDays(2)->toIso8601String(), 'confirmation' => ['method' => 'offline_contract', 'required_minor' => 0], 'confirmation_deadline' => now('UTC')->addDays(3)->toIso8601String(), 'schedule' => array_intersect_key($facts, array_flip(['starts_at', 'ends_at', 'timezone'])) + ['buffer_before_minutes' => 60, 'buffer_after_minutes' => 60]], $admins['author']['id'], 1);
foreach ([[1, 'review', $admins['author']['id']], [2, 'approve', $admins['reviewer']['id']], [3, 'send', $admins['author']['id']]] as [$version,$action,$actor]) {
    $quotes->transition($quote['id'], $version, $action, $actor, 1);
}
$row = DB::table('scene_quotes')->find($quote['id']);
$result = compact('admins', 'customers', 'products', 'prices', 'binding', 'model', 'facts', 'pass');
$result += ['scene_id' => $scene->id, 'request_id' => $request['id'], 'quote_id' => $quote['id'], 'proof_id' => $proof['id'], 'quote' => ['row_version' => $row->row_version, 'terms_hash' => $row->terms_hash, 'content_hash' => $row->content_hash], 'seed_ok' => true, 'eav_values' => DB::table('product_attribute_values')->count()];
DB::commit();
file_put_contents(dirname(storage_path()).'/fixtures.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
touch(storage_path('installed'));
echo 'SEEDED '.count($admins).' admins, EAV '.$result['eav_values'].PHP_EOL;
