<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

function sceneSkillAfterSaleFixture(int $projectId): array
{
    if (DB::connection()->getConfig('database') !== ':memory:') {
        throw new RuntimeException('仅允许隔离内存夹具。');
    }
    foreach (['customer_id', 'status'] as $column) {
        if (! Schema::hasColumn('orders', $column)) {
            Schema::table('orders', fn (Blueprint $t) => $column === 'customer_id' ? $t->unsignedInteger($column)->nullable() : $t->string($column)->nullable());
        }
    }
    if (! Schema::hasTable('order_items')) {
        Schema::create('order_items', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('order_id');
            $t->unsignedInteger('product_id');
            $t->string('type');
            $t->json('additional');
        });
    }
    $project = DB::table('scene_projects')->find($projectId);
    $request = DB::table('scene_requests')->find($project->request_id);
    $quote = DB::table('scene_quotes')->find($project->quote_id);
    $product = DB::table('products')->insertGetId(['type' => 'downloadable']);
    $version = DB::table('skill_versions')->insertGetId(['product_id' => $product, 'version' => 'qa-p2-v1', 'status' => 'published']);
    $order = DB::table('orders')->insertGetId(['customer_id' => $request->customer_id, 'status' => 'processing']);
    $item = DB::table('order_items')->insertGetId(['order_id' => $order, 'product_id' => $product, 'type' => 'downloadable', 'additional' => SceneWorkflow::json([
        'scene' => ['protocol' => 'scene-sc3-v1', 'request_id' => (int) $request->id, 'quote_id' => (int) $quote->id, 'quote_hash' => $quote->content_hash], 'skill' => ['version_id' => $version]])]);
    DB::table('scene_order_parts')->insert(['quote_id' => $quote->id, 'order_id' => $order, 'kind' => 'skill', 'created_at' => now('UTC')]);
    $entitlement = DB::table('skill_entitlements')->insertGetId(['customer_id' => $request->customer_id, 'order_id' => $order, 'order_item_id' => $item, 'product_id' => $product, 'skill_version_id' => $version, 'status' => 'active', 'activations' => 1, 'meta' => '{}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $snapshot = SceneWorkflow::unpack($project->snapshot);
    $snapshot['quote_hash'] = $quote->content_hash;
    $snapshot['proposal']['components'][] = ['kind' => 'skill', 'selected' => true, 'product_id' => $product, 'skill_version_id' => $version];
    DB::table('scene_projects')->where('id', $projectId)->update(['snapshot' => SceneWorkflow::json($snapshot)]);

    return compact('product', 'version', 'order', 'item', 'entitlement');
}
