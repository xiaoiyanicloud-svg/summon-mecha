<?php

namespace Webkul\Shop\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Shop\Services\Scenes\SceneOrderWriteContext;

class SceneOrderTransactionGuard
{
    public function __construct(protected SceneOrderWriteContext $context) {}

    public function beforeCreated(array $data): void
    {
        $items = collect($data['items'] ?? []);
        $marked = $items->contains(fn ($item) => ! empty($item['additional']['scene']));
        $service = DB::connection()->getSchemaBuilder()->hasTable('scene_sku_mappings')
            && DB::table('scene_sku_mappings')->where('mode', 'service')->whereNotNull('approved_by')->whereIn('product_id', $items->pluck('product_id')->all())->exists();
        if (($marked || $service) && ! $this->context->allows($data)) {
            throw ValidationException::withMessages(['scene' => '场景服务 SKU 必须通过客户已接受的正式报价确认，不能从普通购买或后台建单绕过。']);
        }
    }

    public function beforeCancelled(object $order): void
    {
        if (app(\Webkul\Shop\Services\Scenes\SceneLinkedOrderService::class)->context((int) $order->id)) {
            throw ValidationException::withMessages(['scene' => '场景订单取消须从交付项目提交申请并核实资源和商务影响。']);
        }
    }
}
