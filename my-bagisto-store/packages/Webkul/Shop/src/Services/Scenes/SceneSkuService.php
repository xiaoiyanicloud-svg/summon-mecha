<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Webkul\Product\Models\Product;

class SceneSkuService
{
    public function __construct(protected SceneWorkflow $flow) {}

    public function register(array $input, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        $data = Validator::make($input, ['price_item_id' => 'required|integer|exists:scene_price_items,id',
            'channel_id' => 'required|integer|exists:channels,id', 'product_id' => 'required|integer|exists:products,id',
            'mode' => 'required|in:service,skill', 'reason' => 'required|string|min:3|max:2000'])->validate();

        return DB::transaction(function () use ($data, $actor) {
            SceneWorkflow::requireRow('scene_price_items', (int) $data['price_item_id']);
            if (DB::table('scene_sku_mappings')->where('price_item_id', $data['price_item_id'])->where('channel_id', $data['channel_id'])->lockForUpdate()->first()) {
                throw new SceneWorkflowException('SCENE_SKU_MAPPING_EXISTS', '此价目版本已有映射；修改应建立新版价目并重新报价。', 409);
            }
            $id = DB::table('scene_sku_mappings')->insertGetId($data + ['created_by' => $actor, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_sku', $id, 1, 'scene.sku.registered');

            return ['id' => $id, 'row_version' => 1, 'state' => 'draft'];
        }, 3);
    }

    public function review(int $id, int $expected, string $action, int $actor): array
    {
        $this->flow->enabled('quotes_enabled');
        if (! in_array($action, ['approve', 'revoke'], true)) {
            throw new SceneWorkflowException('SCENE_ACTION_INVALID', '映射操作无效。');
        }

        return DB::transaction(function () use ($id, $expected, $action, $actor) {
            $ref = SceneWorkflow::requireRow('scene_sku_mappings', $id, false);
            SceneWorkflow::requireRow('scene_price_items', (int) $ref->price_item_id);
            $m = SceneWorkflow::requireRow('scene_sku_mappings', $id);
            SceneWorkflow::version($m, $expected);
            if (($action === 'approve' && $m->state !== 'draft') || ($action === 'revoke' && $m->state !== 'approved')) {
                throw new SceneWorkflowException('SCENE_STATE_CONFLICT', '映射当前状态不支持操作。', 409);
            }
            if ($action === 'approve') {
                if ((int) $m->created_by === $actor) {
                    throw new SceneWorkflowException('SCENE_SELF_APPROVAL', '映射录入人不能自行批准。', 403);
                }
                $this->product($m);
            }
            $state = $action === 'approve' ? 'approved' : 'revoked';
            DB::table('scene_sku_mappings')->where('id', $id)->update(['state' => $state, 'approved_by' => $action === 'approve' ? $actor : $m->approved_by, 'row_version' => $expected + 1, 'updated_at' => now('UTC')]);
            $this->flow->record('admin', $actor, 'scene_sku', $id, $expected + 1, 'scene.sku.'.$action);

            return ['id' => $id, 'row_version' => $expected + 1, 'state' => $state];
        }, 3);
    }

    public function resolve(int $priceId, int $channel): array
    {
        $m = DB::table('scene_sku_mappings')->where('price_item_id', $priceId)->where('channel_id', $channel)->lockForUpdate()->first();
        if (! $m || $m->state !== 'approved' || ! $m->approved_by || (int) $m->approved_by === (int) $m->created_by) {
            throw new SceneWorkflowException('SCENE_SKU_MAPPING_REQUIRED', '报价费用缺少当前渠道独立批准的真实 SKU 映射。', 409);
        }

        return [$m, $this->product($m)];
    }

    private function product(object $m): Product
    {
        SceneWorkflow::requireRow('products', (int) $m->product_id);
        $product = Product::findOrFail($m->product_id);
        $price = SceneWorkflow::requireRow('scene_price_items', (int) $m->price_item_id);
        $channel = SceneWorkflow::requireRow('channels', (int) $m->channel_id);
        $flat = DB::table('product_flat')->where('product_id', $m->product_id)->where('channel', $channel->code)->where('status', 1)->lockForUpdate()->first();
        if (! $flat || ! $product->sku || ! DB::table('product_channels')->where('product_id', $m->product_id)->where('channel_id', $m->channel_id)->exists()) {
            throw new SceneWorkflowException('SCENE_SKU_UNAVAILABLE', '映射商品不在当前渠道或已停用。', 409);
        }
        $skill = DB::table('product_categories')->join('category_translations', 'product_categories.category_id', '=', 'category_translations.category_id')
            ->where('product_id', $m->product_id)->whereIn('slug', ['skill', 'skills'])->exists();
        if ($m->mode === 'service') {
            // 租用履约占用由场景逐台台账承担；服务订单不得误扣销售实物库存或误发技能授权。
            if ($price->kind === 'skill' || $skill || $product->type !== 'virtual' || $product->manage_stock) {
                throw new SceneWorkflowException('SCENE_SKU_TYPE_INVALID', '服务费用须映射非技能 virtual 服务 SKU。', 409);
            }
        } elseif ($price->kind !== 'skill' || $price->charge_type === 'deposit' || (int) $price->product_id !== (int) $product->id || ! $skill || ! in_array($product->type, ['virtual', 'simple'], true)) {
            throw new SceneWorkflowException('SCENE_SKU_TYPE_INVALID', '技能费用须映射原技能商品，并独立建技能子单。', 409);
        }

        return $product;
    }
}
