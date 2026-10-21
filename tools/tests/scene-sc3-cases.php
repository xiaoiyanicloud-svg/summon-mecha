<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Shop\Services\Scenes\SceneCommercialService;
use Webkul\Shop\Services\Scenes\SceneOrderConversionService;
use Webkul\Shop\Services\Scenes\SceneOrderWriter;
use Webkul\Shop\Services\Scenes\ScenePrincipal;
use Webkul\Shop\Services\Scenes\SceneReservationService;
use Webkul\Shop\Services\Scenes\SceneResourceService;
use Webkul\Shop\Services\Scenes\SceneSkuService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

function sc3Key(): string
{
    return 'sc3-'.bin2hex(random_bytes(12));
}

test('SC3 独立核验逐台资源与真实服务 SKU；来源与班次不默认完成', function () use ($supply) {
    DB::table('customers')->where('id', 1)->update(['email' => 'sc3-one@example.invalid']);
    DB::table('customers')->where('id', 2)->update(['email' => 'sc3-two@example.invalid']);
    DB::table('products')->where('id', 11)->update(['sku' => 'SC3-ROBOT', 'manage_stock' => true]);
    foreach ([101, 102, 103] as $id) {
        DB::table('products')->insert(['id' => $id, 'type' => 'virtual', 'sku' => 'SC3-SERVICE-'.$id, 'manage_stock' => false]);
        DB::table('product_flat')->insert(['product_id' => $id, 'channel' => 'default', 'status' => 1, 'visible_individually' => 1]);
        DB::table('product_channels')->insert(['product_id' => $id, 'channel_id' => 1]);
    }
    DB::table('categories')->insert(['id' => 2]);
    DB::table('category_translations')->insert(['category_id' => 2, 'slug' => 'skill']);
    $GLOBALS['sc3Resource'] = verifiedResourceFixture($supply, ['resource_no' => 'SC3-ROBOT', 'kind' => 'robot', 'product_id' => 11, 'binding_id' => 10, 'external_reference' => '独立设备核验报告', 'city' => '上海', 'timezone' => 'Asia/Shanghai', 'verification_expires_at' => '2026-12-31 00:00:00'], 2)['id'];
    $GLOBALS['sc3Operator'] = verifiedResourceFixture($supply, ['resource_no' => 'SC3-OPERATOR', 'kind' => 'operator', 'external_reference' => '隔离人员资质与排班', 'city' => '上海', 'timezone' => 'Asia/Shanghai', 'verification_expires_at' => '2026-12-31 00:00:00'], 2)['id'];
    $price = $supply->price(['code' => 'operator.test', 'name' => '人员', 'kind' => 'operator', 'unit' => 'shift', 'currency' => 'CNY', 'unit_minor' => 3001, 'tax_bps' => 600, 'charge_type' => 'fee', 'valid_from' => '2026-10-01 00:00:00', 'valid_until' => '2026-12-31 00:00:00'], 1);
    $GLOBALS['sc3OperatorPrice'] = $price['id'];
    foreach ([1 => 101, 2 => 102, $price['id'] => 103] as $priceId => $productId) {
        $m = app(SceneSkuService::class)->register(['price_item_id' => $priceId, 'channel_id' => 1, 'product_id' => $productId, 'mode' => 'service', 'reason' => '隔离服务费用真实 SKU 映射'], 1);
        error('SCENE_SELF_APPROVAL', fn () => app(SceneSkuService::class)->review($m['id'], 1, 'approve', 1));
        app(SceneSkuService::class)->review($m['id'], 1, 'approve', 2);
    }
    same('ready', DB::table('scene_resources')->find($GLOBALS['sc3Resource'])->state);
});

/** 全部经过需求→原件勘察→评估→独立批准 BOM→报价→接受→商务复核；不直接拼接 accepted 状态。 */
function sc3Quote(bool $operator = false, int $customer = 1, bool $commercial = true, bool $hold = true, bool $withSkill = false): array
{
    global $facts, $requests, $supply, $proposals, $solutionId, $sceneTestFiles;
    $owner = new ScenePrincipal($customer, null);
    $r = $requests->create($owner, sc3Key(), requestPayload('SC3 隔离完整链路需求'));
    $source = DB::table('scene_attachments')->orderBy('id')->first();
    $storageKey = (string) Illuminate\Support\Str::uuid();
    $path = storage_path('app/private/scenes/'.$storageKey);
    copy(storage_path('app/private/scenes/'.$source->storage_key), $path);
    $sceneTestFiles[] = $path;
    $proof = DB::table('scene_attachments')->insertGetId(['request_id' => $r['id'], 'storage_key' => $storageKey, 'sha256' => $source->sha256, 'mime' => $source->mime, 'size' => $source->size, 'state' => 'clean', 'expires_at' => '2027-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $survey = app(Webkul\Shop\Services\Scenes\SceneSurveyService::class)->record($r['id'], 1, ['facts' => $facts, 'verified_keys' => array_keys($facts), 'evidence_ids' => [$proof], 'occurred_at' => '2026-10-19T18:00:00+08:00', 'note' => 'SC3 隔离现场原件'], 1);
    $e = DB::table('scene_evidence')->orderBy('id')->first();
    $candidates = [['product_id' => 11, 'binding_id' => 10, 'capability' => 'greeting', 'evidence_id' => $e->id]];
    $skill = $withSkill ? sc3SkillFixture() : null;
    if ($skill) {
        foreach (['greeting', 'script_skill'] as $capability) {
            $evidence = $supply->evidence(['product_id' => 11, 'binding_id' => 10, 'capability' => $capability, 'level' => 'L1', 'scope' => '隔离设备与技能脚本任务', 'source_reference' => sc3Key(), 'artifact_hash' => str_repeat('c', 64), 'verified_at' => '2026-10-19T00:00:00Z', 'expires_at' => '2026-12-31T00:00:00Z',
                'constraints' => ['categories' => ['enterprise'], 'min_clearance_mm' => 1000, 'max_floors' => 1]] + ($capability === 'script_skill' ? ['skill' => $skill['input']] : []), 1);
            $supply->reviewEvidence($evidence['id'], ['source_verified' => 1, 'reason' => '独立复核任务与指定技能产物'], 2);
            $candidates[$capability === 'greeting' ? 0 : 1] = ['product_id' => 11, 'binding_id' => 10, 'capability' => $capability, 'evidence_id' => $evidence['id']];
        }
    }
    $a = app(Webkul\Shop\Services\Scenes\SceneAssessmentService::class)->assess($r['id'], (int) DB::table('scene_requests')->find($r['id'])->row_version,
        ['survey_id' => $survey['id'], 'candidates' => $candidates], 1, 1);
    $original = DB::table('scene_proposals')->find($solutionId);
    $components = SceneWorkflow::unpack($original->components);
    $items = [['price_item_id' => 1, 'quantity' => 1, 'billing_units' => 1], ['price_item_id' => 2, 'quantity' => 1, 'billing_units' => 1]];
    if ($skill) {
        $components[] = ['key' => 'script', 'label' => '已核验技能', 'kind' => 'skill', 'product_id' => 12, 'skill_version_id' => $skill['input']['version_id'], 'price_code' => 'skill.sc3', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'required' => true, 'selected' => true, 'dependencies' => ['robot']];
        $price = $supply->price(['code' => 'skill.sc3', 'name' => '技能', 'kind' => 'skill', 'product_id' => 12, 'unit' => 'item', 'currency' => 'CNY', 'unit_minor' => 101, 'tax_bps' => 0, 'charge_type' => 'fee', 'valid_from' => '2026-10-01T00:00:00Z', 'valid_until' => '2026-12-31T00:00:00Z'], 1);
        $items[] = ['price_item_id' => $price['id'], 'quantity' => 1, 'billing_units' => 1];
        $mapping = app(SceneSkuService::class)->register(['price_item_id' => $price['id'], 'channel_id' => 1, 'product_id' => 12, 'mode' => 'skill', 'reason' => '原技能商品与正式版本一致'], 1);
        app(SceneSkuService::class)->review($mapping['id'], 1, 'approve', 2);
    }
    if ($operator) {
        $components[] = ['key' => 'operator', 'label' => '人员', 'kind' => 'operator', 'price_code' => 'operator.test', 'quantity' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'required' => true, 'selected' => true, 'dependencies' => ['robot']];
        $items[] = ['price_item_id' => $GLOBALS['sc3OperatorPrice'], 'quantity' => 1, 'billing_units' => 1];
    }
    $solution = app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->draft($r['id'], (int) DB::table('scene_requests')->find($r['id'])->row_version,
        ['assessment_id' => $a['id'], 'title' => 'SC3 完整履约方案', 'scope' => $original->scope, 'exclusions' => $original->exclusions,
            'components' => array_map(fn ($c) => array_diff_key($c, ['unit' => true]), $components), 'acceptance' => SceneWorkflow::unpack($original->acceptance)], 1, 1);
    app(Webkul\Shop\Services\Scenes\SceneSolutionService::class)->decide($solution['id'], 1, 'approve', 'SC3 独立复核完整方案', 2, 1);
    $q = $proposals->draft($r['id'], (int) DB::table('scene_requests')->find($r['id'])->row_version,
        quoteFixtureInput($a['id'], ['proposal_id' => $solution['id'], 'items' => $items, 'confirmation' => ['method' => 'offline_contract', 'required_minor' => 0], 'confirmation_deadline' => '2026-10-31T18:00:00+08:00']), 1, 1);
    sendFixtureQuote($q['id']);
    $row = DB::table('scene_quotes')->find($q['id']);
    $proposals->accept($owner, $q['id'], sc3Key(), $row->terms_hash, $row->content_hash, 1);
    $data = ['terms_hash' => $row->terms_hash, 'content_hash' => $row->content_hash, 'hold_group' => null];
    if ($commercial) {
        $c = app(SceneCommercialService::class)->record($q['id'], ['kind' => 'offline_contract', 'amount_minor' => 0, 'reference' => sc3Key(), 'occurred_at' => '2026-10-19T18:00:00+08:00', 'evidence_ids' => [$proof]], sc3Key(), 1);
        app(SceneCommercialService::class)->review($q['id'], $c['id'], ['decision' => 'approve', 'reason' => '已核对真实隔离合同原件'], 2);
    }
    if ($hold) {
        $h = app(SceneReservationService::class)->hold($q['id'], ['resource_ids' => $operator ? [$GLOBALS['sc3Resource'], $GLOBALS['sc3Operator']] : [$GLOBALS['sc3Resource']]], sc3Key(), 2, 1);
        $data['hold_group'] = $h['hold_group'];
    }

    return ['id' => $q['id'], 'request_id' => $r['id'], 'owner' => $owner, 'data' => $data, 'proof' => $proof];
}

function sc3SkillFixture(): array
{
    global $sceneTestFiles;
    DB::table('products')->insert(['id' => 12, 'type' => 'simple', 'sku' => 'SC3-SKILL', 'manage_stock' => true]);
    DB::table('product_flat')->insert(['product_id' => 12, 'channel' => 'default', 'locale' => 'en', 'name' => '隔离技能', 'price' => 1.01, 'status' => 1, 'visible_individually' => 1]);
    DB::table('product_channels')->insert(['product_id' => 12, 'channel_id' => 1]);
    DB::table('product_categories')->insert(['product_id' => 12, 'category_id' => 2]);
    DB::table('product_inventories')->insert(['product_id' => 12, 'inventory_source_id' => 1, 'qty' => 10]);
    DB::table('device_model')->where('id', 1)->update(['comm_channels' => '["manual"]', 'firmware_versions' => '["1.0"]']);
    DB::table('device_binding')->where('id', 10)->update(['meta' => SceneWorkflow::json(['capability_snapshot' => ['firmware_version' => '1.0', 'sdk_version' => '1.0']])]);
    $device = DB::table('devices')->insertGetId(['customer_id' => 1, 'binding_id' => 10, 'sn' => 'SC3-DEVICE', 'model_code' => 'isolated-test-model', 'firmware_version' => '1.0']);
    $version = DB::table('skill_versions')->insertGetId(['product_id' => 12, 'version' => '1.0', 'status' => 'published', 'license_mode' => 'per_device', 'pricing_mode' => 'buyout', 'released_at' => '2026-10-19 00:00:00',
        'compatibility' => SceneWorkflow::json(['models' => [['model' => 'isolated-test-model', 'min_firmware' => '1.0']]]), 'delivery_channels' => '["manual"]']);
    $name = 'sc3-skill-'.bin2hex(random_bytes(8)).'.bin';
    $path = storage_path('app/private/'.$name);
    file_put_contents($path, 'SC3 ISOLATED SKILL ARTIFACT');
    $sceneTestFiles[] = $path;
    $asset = DB::table('skill_assets')->insertGetId(['skill_version_id' => $version, 'carrier_type' => 'file', 'file_path' => $name, 'file_name' => 'sc3.bin', 'file_hash' => hash_file('sha256', $path), 'sort_order' => 0]);
    DB::table('skill_compatibility_evidence')->insert(['skill_version_id' => $version, 'product_id' => 12, 'model_code' => 'isolated-test-model', 'asset_id' => $asset, 'asset_hash' => hash_file('sha256', $path), 'source' => 'device', 'evidence_type' => 'install', 'result' => 'match', 'observed_at' => '2026-10-19 00:00:00']);

    return ['input' => ['version_id' => $version, 'asset_id' => $asset, 'device_id' => $device]];
}

function sc3Convert(array $f, ?string $key = null): array
{
    return app(SceneOrderConversionService::class)->convert($f['owner'], $f['id'], $f['data'], $key ?? sc3Key(), 1);
}

test('SC3 服务端核价、订单/项目/资源/任务同事务；重复键、新键与丢响应返回同一订单', fn () => sceneSandbox(function () {
    $f = sc3Quote(true);
    $key = sc3Key();
    $f['data']['price'] = 1;
    $result = sc3Convert($f, $key);
    same($result, sc3Convert($f, $key));
    same($result, sc3Convert($f));
    same('confirmed', $result['delivery_status']);
    same('unpaid', $result['payment_state']);
    same(1, DB::table('scene_order_links')->where('quote_id', $f['id'])->count());
    same(2, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'confirmed')->count());
    same(5, DB::table('scene_project_tasks')->where('project_id', $result['project_id'])->count());
    same('pending', DB::table('orders')->find($result['order_id'])->status);
    same('order_shipping', DB::table('addresses')->where('order_id', $result['order_id'])->where('address_type', 'order_shipping')->value('address_type'));
    same(0, DB::table('product_ordered_inventories')->where('product_id', 11)->count());
    $q = DB::table('scene_quotes')->find($f['id']);
    same((int) $q->total_minor, (int) round((float) DB::table('orders')->find($result['order_id'])->grand_total * 100));
    error('SCENE_OBJECT_NOT_FOUND', fn () => app(SceneOrderConversionService::class)->convert(new ScenePrincipal(2, null), $f['id'], $f['data'], sc3Key(), 1));
}));

test('SC3 商务未复核/自审/跨需求原件/重复凭证均拒绝，客户上传不置已收款', fn () => sceneSandbox(function () {
    $f = sc3Quote(false, 1, false);
    error('SCENE_COMMERCIAL_UNCONFIRMED', fn () => sc3Convert($f));
    $input = ['kind' => 'offline_contract', 'amount_minor' => 0, 'reference' => sc3Key(), 'occurred_at' => '2026-10-19T18:00:00+08:00', 'evidence_ids' => [$f['proof']]];
    $s = app(SceneCommercialService::class);
    $key = sc3Key();
    $c = $s->record($f['id'], $input, $key, 1);
    same($c, $s->record($f['id'], $input, $key, 1));
    error('SCENE_SELF_APPROVAL', fn () => $s->review($f['id'], $c['id'], ['decision' => 'approve', 'reason' => '自审拒绝'], 1));
    error('SCENE_COMMERCIAL_REFERENCE_USED', fn () => $s->record($f['id'], $input, sc3Key(), 2));
    $bad = $input;
    $bad['reference'] = sc3Key();
    $bad['evidence_ids'] = [DB::table('scene_attachments')->orderBy('id')->value('id')];
    error('SCENE_COMMERCIAL_EVIDENCE_INVALID', fn () => $s->record($f['id'], $bad, sc3Key(), 1));
    $s->review($f['id'], $c['id'], ['decision' => 'approve', 'reason' => '独立核对真实合同'], 2);
    same('unpaid', sc3Convert($f)['payment_state']);
}));

test('SC3 已有效接受不因报价期限过去失效；商务确认期限仍约束；新内容版本保留已接受承诺', fn () => sceneSandbox(function () {
    $f = sc3Quote(false, 1, true, false);
    $q = DB::table('scene_quotes')->find($f['id']);
    try {
        Carbon::setTestNow('2026-10-30T08:00:00Z');
        app(Webkul\Shop\Services\Scenes\SceneProposalService::class)->assertUsable($q, DB::table('scene_requests')->find($f['request_id']), 1);
        $h = app(SceneReservationService::class)->hold($f['id'], ['resource_ids' => [$GLOBALS['sc3Resource']]], sc3Key(), 2, 1);
        same(true, Carbon::parse($h['expires_at'])->greaterThan(now('UTC')));
        Carbon::setTestNow('2026-10-31T10:00:00Z');
        $f['data']['hold_group'] = $h['hold_group'];
        error('SCENE_CONFIRMATION_EXPIRED', fn () => sc3Convert($f));
        same('accepted', DB::table('scene_quotes')->find($f['id'])->state);
    } finally {
        Carbon::setTestNow('2026-10-20T00:00:00Z');
    }
}));

test('SC3 历史资源显式补来源后仍须独立核验；重复来源和自审不产生可承诺容量', fn () => sceneSandbox(function () {
    $id = DB::table('scene_resources')->insertGetId(['resource_no' => 'SC3-LEGACY', 'kind' => 'accessory', 'external_reference' => '历史待补', 'city' => '上海', 'timezone' => 'Asia/Shanghai', 'state' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
    $s = app(SceneResourceService::class);
    $r = $s->registerLegacy($id, 1, ['identity_reference' => 'SC3-LEGACY-ASSET', 'external_reference' => '隔离原件的实际补录来源'], 1);
    same('unverified', $r['state']);
    $input = ['verification_reference' => '隔离核验原件', 'verification_expires_at' => '2026-12-31T00:00:00Z', 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20,
        'availability' => [['starts_at' => '2026-11-01T08:00:00+08:00', 'ends_at' => '2026-11-01T22:00:00+08:00']]];
    error('SCENE_SELF_APPROVAL', fn () => $s->verify($id, 2, $input, 1));
    same('ready', $s->verify($id, 2, $input, 2)['state']);
    error('SCENE_RESOURCE_COMMITTED', fn () => $s->registerLegacy($id, 3, ['identity_reference' => '不能覆盖', 'external_reference' => '不能覆盖已登记来源'], 2));
}));

test('SC3 履约任务和验收由报价冻结，修改运行配置不改变已接受承诺；客户不见内部来源', fn () => sceneSandbox(function () {
    $f = sc3Quote(true);
    $original = config('scenes.workflows.manual_site_v1');
    try {
        config(['scenes.workflows.manual_site_v1' => ['version' => 999, 'tasks' => []]]);
        $result = sc3Convert($f);
        $detail = app(Webkul\Shop\Services\Scenes\SceneProjectService::class)->detail($f['owner'], $result['project_id']);
        same(5, count($detail['tasks']));
        same(2, count($detail['resources']));
        same(1, count($detail['orders']));
        same(false, str_contains(SceneWorkflow::json($detail), 'verification_reference'));
        same(false, str_contains(SceneWorkflow::json($detail), 'external_reference'));
        same('system', DB::table('operation_log')->where('target_id', $result['project_id'])->where('action', 'scene.project.tasks.initialized')->value('actor_type'));
    } finally {
        config(['scenes.workflows.manual_site_v1' => $original]);
    }
}));

test('SC3 held 到期即不占用，停止 worker 仍正确；新客户抢占后旧 hold 不可转确认', fn () => sceneSandbox(function () {
    $one = sc3Quote();
    DB::table('scene_reservations')->where('quote_id', $one['id'])->update(['hold_expires_at' => now()->subSecond()]);
    $two = sc3Quote(false, 2);
    error('SCENE_RESOURCE_CONFLICT', fn () => sc3Convert($one));
    same(0, DB::table('scene_order_links')->where('quote_id', $one['id'])->count());
    sc3Convert($two);
    app(SceneReservationService::class)->expire();
    same(1, DB::table('scene_reservations')->where('quote_id', $two['id'])->where('state', 'confirmed')->count());
}));

test('SC3 多资源一项故障则全部不确认；已承诺资源不替换，影响待办可查', fn () => sceneSandbox(function () {
    $f = sc3Quote(true);
    $r = DB::table('scene_resources')->find($GLOBALS['sc3Operator']);
    app(SceneReservationService::class)->pause((int) $r->id, (int) $r->row_version, '隔离班次人员不可用', 1);
    error('SCENE_RESOURCE_UNAVAILABLE', fn () => sc3Convert($f));
    same(2, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'held')->count());
    same(0, DB::table('scene_projects')->where('quote_id', $f['id'])->count());
    same(1, DB::table('scene_operation_todos')->where('request_id', $f['request_id'])->where('kind', 'resource_impact')->count());
}));

test('SC3 人员班次、城市、行程和休息缓冲拒绝超范围；相邻半开区间可用', fn () => sceneSandbox(function () {
    $r = DB::table('scene_resources')->find($GLOBALS['sc3Operator']);
    $s = Webkul\Shop\Services\Scenes\SceneTime::schedule(['starts_at' => '2026-11-01T10:00:00+08:00', 'ends_at' => '2026-11-01T11:00:00+08:00', 'timezone' => 'Asia/Shanghai', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
    $r->availability = SceneWorkflow::json([['from' => '2026-11-01 02:00:00', 'until' => '2026-11-01 03:00:00']]);
    same('2026-11-01 03:00:00', app(SceneResourceService::class)->interval($r, $s, '上海')['occupies_until']);
    $r->buffer_after_minutes = 1;
    error('SCENE_RESOURCE_OUTSIDE_SHIFT', fn () => app(SceneResourceService::class)->interval($r, $s, '上海'));
    error('SCENE_RESOURCE_UNAVAILABLE', fn () => app(SceneResourceService::class)->interval($r, $s, '北京'));
}));

test('SC3 客户身份、渠道、指纹与冻结金额不可绕过；停写仍可查原确认结果', fn () => sceneSandbox(function () {
    $f = sc3Quote();
    $wrong = $f;
    $wrong['data']['content_hash'] = str_repeat('a', 64);
    error('SCENE_TERMS_CHANGED', fn () => sc3Convert($wrong));
    error('SCENE_ASSESSMENT_STALE', fn () => app(SceneOrderConversionService::class)->convert($f['owner'], $f['id'], $f['data'], sc3Key(), 2));
    DB::table('scene_quotes')->where('id', $f['id'])->increment('total_minor');
    error('SCENE_QUOTE_INTEGRITY', fn () => sc3Convert($f));
    DB::table('scene_quotes')->where('id', $f['id'])->decrement('total_minor');
    $result = sc3Convert($f);
    config(['scenes.order_conversion_enabled' => false]);
    try {
        error('SCENE_WRITES_DISABLED', fn () => sc3Convert($f));
        same($result, app(SceneOrderConversionService::class)->status($f['owner'], $f['id'])['receipt']);
    } finally {
        config(['scenes.order_conversion_enabled' => true]);
    }
}));

test('SC3 SKU 缺失/撤销/类型不符拒绝且不留半套资源；已确认组不能当 hold 释放', fn () => sceneSandbox(function () {
    $f = sc3Quote();
    $m = DB::table('scene_sku_mappings')->where('price_item_id', 1)->first();
    app(SceneSkuService::class)->review((int) $m->id, (int) $m->row_version, 'revoke', 2);
    error('SCENE_SKU_MAPPING_REQUIRED', fn () => sc3Convert($f));
    same(1, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'held')->count());
    DB::table('scene_sku_mappings')->where('id', $m->id)->update(['state' => 'approved']);
    sc3Convert($f);
    error('SCENE_CHANGE_REQUIRED', fn () => app(SceneReservationService::class)->release($f['id'], $f['data']['hold_group'], sc3Key(), 1));
}));

// 每个故障在独立转换事务触发；MySQL DDL 放在事务外，避免触发器隐式提交污染断言。
foreach ([['orders', '1=1'], ['order_payment', '1=1'], ['addresses', "NEW.address_type='order_shipping'"], ['addresses', "NEW.address_type='order_billing'"],
    ['order_items', 'NEW.product_id=101'], ['order_items', 'NEW.product_id=102'], ['scene_projects', '1=1'], ['scene_order_links', '1=1'],
    ['scene_project_tasks', "NEW.task_key='dispatch'"], ['operation_log', "NEW.action='scene.order.converted'"], ['business_outbox', "NEW.event_type='scene.order.converted'"]] as [$table,$condition]) {
    test('SC3 故障回滚 '.$table.' / '.$condition, function () use ($table, $condition) {
        $f = sc3Quote();
        $mysql = DB::connection()->getDriverName() === 'mysql';
        DB::unprepared($mysql ? "CREATE TRIGGER sc3_fault BEFORE INSERT ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SC3_INJECTED'; END IF; END"
            : "CREATE TRIGGER sc3_fault BEFORE INSERT ON $table WHEN $condition BEGIN SELECT RAISE(ABORT,'SC3_INJECTED'); END");
        try {
            try {
                sc3Convert($f);
                throw new LogicException('故障未注入');
            } catch (Illuminate\Database\QueryException) {
            }
            same(0, DB::transactionLevel());
            same(0, DB::table('scene_order_links')->where('quote_id', $f['id'])->count());
            same(0, DB::table('scene_projects')->where('quote_id', $f['id'])->count());
            same(1, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'held')->count());
            same(0, DB::table('order_items')->where('additional', 'like', '%'.$f['data']['content_hash'].'%')->count());
            same(0, DB::table('business_idempotency_keys')->where('operation', 'scene.quote.convert:'.$f['id'])->count());
        } finally {
            DB::unprepared('DROP TRIGGER sc3_fault');
            app(SceneReservationService::class)->release($f['id'], $f['data']['hold_group'], sc3Key(), 1);
        }
    });
}

test('SC3 listener Throwable 回滚真实仓储、地址、款项、关联、强审计及 outbox', function () {
    $f = sc3Quote();
    $hits = 0;
    Event::listen('checkout.order.save.after', function () use (&$hits) {
        $hits++;
        throw new Error('SC3_LISTENER_FAILURE');
    });
    try {
        try {
            sc3Convert($f);
            throw new LogicException('监听故障未注入');
        } catch (Error $e) {
            same('SC3_LISTENER_FAILURE', $e->getMessage());
        }
        same(1, $hits);
        same(0, DB::transactionLevel());
        same(0, DB::table('scene_projects')->where('quote_id', $f['id'])->count());
        same(1, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'held')->count());
    } finally {
        Event::forget('checkout.order.save.after');
        Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\Order::class, 'afterCreated']);
        Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\SkillDelivery::class, 'afterOrderCreated']);
        app(SceneReservationService::class)->release($f['id'], $f['data']['hold_group'], sc3Key(), 1);
    }
});

test('SC3 删除全部场景元数据、修改普通类型或后台直调仓储仍不能购买服务 SKU', fn () => sceneSandbox(function () {
    $f = sc3Quote();
    $orders = app(SceneOrderWriter::class)->prepare(DB::table('scene_quotes')->find($f['id']), DB::table('scene_requests')->find($f['request_id']), 1);
    $data = $orders['plan'];
    foreach ($data['items'] as &$item) {
        $item['additional'] = [];
    } unset($item);
    foreach (['buy', 'rent', 'plan', 'accessory'] as $type) {
        $data['order_type'] = $type;
        try {
            app(Webkul\Shop\Services\Scenes\SceneOrderRepository::class)->create($data);
            throw new LogicException('服务 SKU 绕过报价');
        } catch (Illuminate\Validation\ValidationException $e) {
            same(true, isset($e->errors()['scene']));
        }
    }
    same(0, DB::table('orders')->count());
}));

test('SC3 技能费用独立拆单，服务建单不发授权；实际收款独立复核后复用既有授权且幂等', fn () => sceneSandbox(function () {
    $f = sc3Quote(false, 1, true, true, true);
    $result = sc3Convert($f);
    same(true, $result['skill_order_id'] > 0);
    same('skill', DB::table('orders')->find($result['skill_order_id'])->order_type);
    same(0, DB::table('skill_entitlements')->count());
    same(1, (int) DB::table('product_ordered_inventories')->where('product_id', 12)->value('qty'));
    DB::transaction(fn () => app(Webkul\Shop\Services\Scenes\SceneSkillFulfillmentService::class)->fulfill($result['project_id']));
    same(0, DB::table('skill_entitlements')->count());
    $q = DB::table('scene_quotes')->find($f['id']);
    $s = app(Webkul\Shop\Services\Scenes\SceneProjectService::class);
    $receipt = $s->recordCommercial($result['project_id'], 1, ['kind' => 'payment_receipt', 'amount_minor' => (int) $q->total_minor, 'reference' => sc3Key(), 'evidence_ids' => [$f['proof']]], 1);
    same('unpaid', DB::table('scene_projects')->find($result['project_id'])->payment_state);
    $s->reviewCommercial($result['project_id'], $receipt['id'], 2, 2);
    same('offline_verified', DB::table('scene_projects')->find($result['project_id'])->payment_state);
    DB::transaction(fn () => app(Webkul\Shop\Services\Scenes\SceneSkillFulfillmentService::class)->fulfill($result['project_id']));
    DB::transaction(fn () => app(Webkul\Shop\Services\Scenes\SceneSkillFulfillmentService::class)->fulfill($result['project_id']));
    same(1, DB::table('skill_entitlements')->count());
    $entitlement = DB::table('skill_entitlements')->first();
    same($result['skill_order_id'], (int) $entitlement->order_id);
    same('pending_bind', $entitlement->status);
    same(null, $entitlement->device_id);
    $paid = (int) round((float) DB::table('orders')->whereIn('id', [$result['order_id'], $result['skill_order_id']])->sum('grand_total') * 100);
    same((int) $q->total_minor, $paid);
}));

test('SC3 未批准的错误 SKU 草案不影响普通租买，普通机器人订单继续进入既有仓储', fn () => sceneSandbox(function () {
    $f = sc3Quote();
    $price = app(Webkul\Shop\Services\Scenes\SceneSupplyService::class)->price(['code' => 'draft.sku.test', 'name' => '未批准错误映射测试', 'kind' => 'service', 'unit' => 'item', 'currency' => 'CNY', 'unit_minor' => 1, 'tax_bps' => 0, 'charge_type' => 'fee', 'valid_from' => '2026-10-01T00:00:00Z', 'valid_until' => '2026-12-31T00:00:00Z'], 1);
    app(SceneSkuService::class)->register(['price_item_id' => $price['id'], 'channel_id' => 1, 'product_id' => 11, 'mode' => 'service', 'reason' => '未批准的错误映射草案'], 1);
    $orders = app(SceneOrderWriter::class)->prepare(DB::table('scene_quotes')->find($f['id']), DB::table('scene_requests')->find($f['request_id']), 1);
    $data = $orders['plan'];
    $item = $data['items'][0];
    $item['product_id'] = 11;
    $item['type'] = 'simple';
    $item['sku'] = 'SC3-ROBOT';
    $item['additional'] = [];
    $data['items'] = [$item];
    foreach (['rent', 'buy'] as $type) {
        $data['order_type'] = $type;
        $order = app(Webkul\Shop\Services\Scenes\SceneOrderRepository::class)->create($data);
        same($type, $order->order_type);
    }
    same(2, DB::table('orders')->count());
    same(0, DB::table('skill_entitlements')->count());
}));

test('SC3 即使 worker 停止，已晚归且未核实返库的实物不能确认下一个档期', fn () => sceneSandbox(function () {
    $first = sc3Quote();
    $result = sc3Convert($first);
    // 独立 QA 构造已超原返库时限的历史占用；不伪造客户的真实返库事实。
    DB::table('scene_reservations')->where('quote_id', $first['id'])->update(['occupies_from' => '2026-10-19 00:00:00', 'occupies_until' => '2026-10-19 10:00:00']);
    $next = sc3Quote(false, 2, true, false);
    error('SCENE_RESOURCE_RETURN_PENDING', fn () => app(SceneReservationService::class)->hold($next['id'], ['resource_ids' => [$GLOBALS['sc3Resource']]], sc3Key(), 2, 1));
    same(false, (bool) DB::table('scene_projects')->find($result['project_id'])->returned);
}));

test('SC3 假设已付款状态不能替代收款事实；待退款、争议和技能版本变化阻止新授权', fn () => sceneSandbox(function () {
    $f = sc3Quote(false, 1, true, true, true);
    $result = sc3Convert($f);
    DB::table('scene_projects')->where('id', $result['project_id'])->update(['payment_state' => 'offline_verified']);
    $order = Webkul\Sales\Models\Order::with('items')->find($result['skill_order_id']);
    same(false, app(Webkul\Shop\Services\Scenes\SceneSkillOrderGate::class)->allows($order));
    same(0, DB::table('skill_entitlements')->count());
    $q = DB::table('scene_quotes')->find($f['id']);
    $c = app(Webkul\Shop\Services\Scenes\SceneProjectService::class)->recordCommercial($result['project_id'], 1, ['kind' => 'payment_receipt', 'amount_minor' => (int) $q->total_minor, 'reference' => sc3Key(), 'evidence_ids' => [$f['proof']]], 1);
    app(Webkul\Shop\Services\Scenes\SceneProjectService::class)->reviewCommercial($result['project_id'], $c['id'], 2, 2);
    same(true, app(Webkul\Shop\Services\Scenes\SceneSkillOrderGate::class)->allows($order));
    $s = app(Webkul\Shop\Services\Scenes\SceneProjectService::class);
    $refund = $s->recordCommercial($result['project_id'], 3, ['kind' => 'refund_due', 'amount_minor' => 1, 'reference' => sc3Key(), 'evidence_ids' => [$f['proof']]], 1);
    $s->reviewCommercial($result['project_id'], $refund['id'], 4, 2);
    same(false, app(Webkul\Shop\Services\Scenes\SceneSkillOrderGate::class)->allows($order));
    app(Webkul\Shop\Services\Scenes\SceneSkillFulfillmentService::class)->fulfill($result['project_id']);
    same(0, DB::table('skill_entitlements')->count());
}));

foreach (['inventory', 'listener_after_grant'] as $phase) {
    test('SC3 真实 Bagisto 库存与授权监听器故障回滚：'.$phase, fn () => sceneSandbox(function () use ($phase) {
        $f = sc3Quote(false, 1, true, false, true);
        $orders = app(SceneOrderWriter::class)->prepare(DB::table('scene_quotes')->find($f['id']), DB::table('scene_requests')->find($f['request_id']), 1);
        $data = $orders['skill'];
        foreach ($data['items'] as &$item) {
            unset($item['additional']['scene']);
        } unset($item);
        $hits = 0;
        if ($phase === 'inventory') {
            Webkul\Product\Models\ProductOrderedInventory::created(function () use (&$hits) {
                $hits++;
                throw new RuntimeException('SC3_INVENTORY_FAILURE');
            });
        } else {
            Event::listen('checkout.order.save.after', function () use (&$hits) {
                $hits++;
                same(1, DB::table('skill_entitlements')->count());
                throw new RuntimeException('SC3_AFTER_GRANT_FAILURE');
            });
        }
        try {
            try {
                app(Webkul\Shop\Services\Scenes\SceneOrderRepository::class)->create($data);
                throw new LogicException('库存/授权故障未注入');
            } catch (RuntimeException $e) {
                same($phase === 'inventory' ? 'SC3_INVENTORY_FAILURE' : 'SC3_AFTER_GRANT_FAILURE', $e->getMessage());
            }
            same(1, $hits);
            same(0, DB::table('orders')->count());
            same(0, DB::table('order_items')->count());
            same(0, DB::table('order_payment')->count());
            same(0, DB::table('addresses')->count());
            same(0, DB::table('product_ordered_inventories')->count());
            same(0, DB::table('skill_entitlements')->count());
        } finally {
            if ($phase === 'inventory') {
                Webkul\Product\Models\ProductOrderedInventory::flushEventListeners();
            } else {
                Event::forget('checkout.order.save.after');
                Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\Order::class, 'afterCreated']);
                Event::listen('checkout.order.save.after', [Webkul\Shop\Listeners\SkillDelivery::class, 'afterOrderCreated']);
            }
        }
    }));
}

test('SC3 DST 回拨两种 offset 表达不同真实时刻；跨日和无效本地时间不默许纠正', function () {
    $s = Webkul\Shop\Services\Scenes\SceneTime::schedule(['timezone' => 'America/New_York', 'starts_at' => '2026-11-01T01:30:00-04:00', 'ends_at' => '2026-11-01T01:30:00-05:00', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
    same('2026-11-01 05:30:00', $s['service_starts_at']);
    same('2026-11-01 06:30:00', $s['service_ends_at']);
    error('SCENE_LOCAL_TIME_INVALID', fn () => Webkul\Shop\Services\Scenes\SceneTime::schedule(['timezone' => 'Asia/Shanghai', 'starts_at' => '2026-02-30T10:00:00+08:00', 'ends_at' => '2026-03-01T10:00:00+08:00', 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]));
});

test('SC3 真实订单取消/支付创建/支付回调入口识别场景关联，普通订单入口不能改变履约或收款', fn () => sceneSandbox(function () {
    $f = sc3Quote();
    $result = sc3Convert($f);
    $linked = app(Webkul\Shop\Services\Scenes\SceneLinkedOrderService::class)->context($result['order_id']);
    same($result['project_id'], $linked['project_id']);
    same(false, $linked['online_payment']);
    $request = Illuminate\Http\Request::create('/orders/'.$result['order_id'].'/cancel', 'POST');
    $response = app(Webkul\Shop\Http\Controllers\API\OrderController::class)->cancel($request, $result['order_id']);
    same(409, $response->getStatusCode());
    same('SCENE_CHANGE_REQUIRED', json_decode($response->getContent(), true)['code']);
    $response = app(Webkul\Shop\Http\Controllers\API\PaymentController::class)->create($result['order_id']);
    same(409, $response->getStatusCode());
    same('SCENE_OFFLINE_ONLY', json_decode($response->getContent(), true)['code']);
    $trade = Webkul\Shop\Services\Payment\MockGateway::makeOutTradeNo(now(), $result['order_id']);
    $callback = Illuminate\Http\Request::create('/api/callback/payment', 'POST', ['out_trade_no' => $trade, 'mock_paid' => 1, 'amount' => DB::table('orders')->find($result['order_id'])->grand_total]);
    $response = app(Webkul\Shop\Http\Controllers\API\PaymentCallbackController::class)->handle($callback);
    same(409, $response->getStatusCode());
    same('SCENE_OFFLINE_ONLY', json_decode($response->getContent(), true)['code']);
    same('pending', DB::table('orders')->find($result['order_id'])->status);
    same('unpaid', DB::table('scene_projects')->find($result['project_id'])->payment_state);
    same(1, DB::table('scene_reservations')->where('quote_id', $f['id'])->where('state', 'confirmed')->count());
    try {
        app(Webkul\Shop\Services\Scenes\SceneOrderRepository::class)->cancel(Webkul\Sales\Models\Order::find($result['order_id']));
        throw new LogicException('后台取消绕过项目');
    } catch (Illuminate\Validation\ValidationException $e) {
        same(true, isset($e->errors()['scene']));
    }
}));

if (SCENE_SC3_MODE === 'mysql') {
    require __DIR__.'/scene-sc3-concurrency.php';
}
