<?php

namespace Webkul\Shop\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class WishlistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'product'      => new ProductResource($this->product),
            'catalog_type' => $this->catalogType(),
            'added_at'     => optional($this->created_at)->toDateTimeString(),
            'options'      => $this->formatAdditionalAttributes(),
        ];
    }

    /**
     * Format the additional attributes.
     */
    public function formatAdditionalAttributes(): array
    {
        $additional = $this->resource->additional ?? [];

        if (! empty($additional['attributes'])) {
            $additional['attributes'] = collect($additional['attributes'])
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
                ->toArray();
        }

        return $additional;
    }
    /**
     * 商品详情页需要知道收藏项属于租用、购买、配件还是技能，
     * 以便从“我的收藏”回到正确的详情模式。
     */
    private function catalogType(): string
    {
        $product = $this->product;

        if (! $product) {
            return 'buy';
        }

        $allowed = ['rent', 'buy', 'accessory', 'skill'];
        $categoryIds = collect();

        foreach ($product->categories as $category) {
            $categoryIds->push($category->id);
            $categoryIds = $categoryIds->merge($category->ancestors()->pluck('id'));
        }

        $slugs = $categoryIds->isEmpty()
            ? []
            : DB::table('category_translations')
                ->whereIn('category_id', $categoryIds->unique()->all())
                ->pluck('slug')
                ->filter()
                ->all();

        foreach ($allowed as $type) {
            if (in_array($type, $slugs, true)) {
                return $type;
            }
        }

        return 'buy';
    }
}
