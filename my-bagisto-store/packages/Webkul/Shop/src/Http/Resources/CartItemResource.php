<?php

namespace Webkul\Shop\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        $summon = $this->summonMetadata();

        return [
            'id'                        => $this->id,
            'quantity'                  => $this->quantity,
            'product_id'                => $this->product_id,
            'sku'                       => $this->product?->sku,
            'order_type'                => $summon['order_type'] ?? $this->inferOrderType(),
            'rent_days'                 => $summon['rent_days'] ?? 1,
            'rent_period'               => $summon['rent_period'] ?? null,
            'start_date'                => $summon['start_date'] ?? null,
            'product_type'              => $summon['product_type'] ?? null,
            'type'                      => $this->type,
            'name'                      => $this->name,
            'price'                     => $this->price,
            'formatted_price'           => core()->formatPrice($this->price),
            'price_incl_tax'            => $this->price_incl_tax,
            'formatted_price_incl_tax'  => core()->formatPrice($this->price_incl_tax),
            'total'                     => $this->total,
            'formatted_total'           => core()->formatPrice($this->total),
            'total_incl_tax'            => $this->total_incl_tax,
            'formatted_total_incl_tax'  => core()->formatPrice($this->total_incl_tax),
            'discount_amount'           => $this->discount_amount,
            'formatted_discount_amount' => core()->formatPrice($this->discount_amount),
            'base_image'                => $this->getTypeInstance()->getBaseImage($this),
            'product_url_key'           => $this->product->url_key,
            'options'                   => $this->formatAdditionalAttributes(),
            'can_change_qty'            => $this->product ? $this->product->getTypeInstance()->showQuantityBox() : false,
        ];
    }

    /**
     * Format the additional attributes.
     */
    public function formatAdditionalAttributes(): array
    {
        $attributes = $this->resource->additional['attributes'] ?? [];

        if (! empty($attributes)) {
            return collect($attributes)
                ->map(function ($attribute) {
                    if (
                        isset($attribute['attribute_type'])
                        && $attribute['attribute_type'] == 'file'
                    ) {
                        $attribute['file_name'] = File::basename($attribute['option_label']);

                        $attribute['file_url'] = Storage::url($attribute['option_label']);
                    }

                    return $attribute;
                })
                ->values()
                ->toArray();
        }

        return [];
    }
    /**
     * 前端加购时写入的业务上下文（租赁周期等）。仅作为展示/结算快照，
     * 不参与 Bagisto 原生商品选项匹配。
     */
    private function summonMetadata(): array
    {
        $additional = $this->resource->additional ?? [];
        $summon = is_array($additional) ? ($additional['summon'] ?? []) : [];

        return is_array($summon) ? $summon : [];
    }

    /**
     * 兼容修复前已存在的购物车条目：旧条目没有 summon.order_type，
     * 按商品所属一级分类回退推断；识别不了时按普通购买处理。
     */
    private function inferOrderType(): string
    {
        $product = $this->product;

        if (! $product) {
            return 'buy';
        }

        foreach (['rent', 'buy', 'accessory', 'skill'] as $type) {
            if ($product->categories->contains(fn ($category) => (string) $category->slug === $type)) {
                return $type;
            }
        }

        return 'buy';
    }
}
