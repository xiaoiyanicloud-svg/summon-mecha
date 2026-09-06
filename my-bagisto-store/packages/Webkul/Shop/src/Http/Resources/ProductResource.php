<?php

namespace Webkul\Shop\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Product\Helpers\Review;

class ProductResource extends JsonResource
{
    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     * @return void
     */
    public function __construct($resource)
    {
        $this->reviewHelper = app(Review::class);

        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        $productTypeInstance = $this->getTypeInstance();

        return [
            'id'          => $this->id,
            'sku'         => $this->sku,
            'name'        => $this->name,
            'description' => $this->description,
            'url_key'     => $this->url_key,
            'base_image'  => product_image()->getProductBaseImage($this),
            'images'      => product_image()->getGalleryImages($this),
            'is_new'      => (bool) $this->new,
            'is_featured' => (bool) $this->featured,
            'on_sale'     => (bool) $productTypeInstance->haveDiscount(),
            'is_saleable' => (bool) $productTypeInstance->isSaleable(),
            'is_wishlist' => (bool) auth()->guard()->user()?->wishlist_items
                ->where('channel_id', core()->getCurrentChannel()->id)
                ->where('product_id', $this->id)->count(),
            'min_price'   => core()->formatPrice($productTypeInstance->getMinimalPrice()),
            'prices'      => $productTypeInstance->getProductPrices(),
            'price_html'  => $productTypeInstance->getPriceHtml(),
            'custom_attributes' => $this->customAttributes(),
            'ratings'     => [
                'average' => $this->reviewHelper->getAverageRating($this),
                'total'   => $this->reviewHelper->getTotalRating($this),
            ],
            'reviews'     => [
                'total'   => $this->reviewHelper->getTotalReviews($this),
            ],
        ];
    }

    /**
     * 把商品的属性值以 { code: { code, name, value } } 透出给前端(只读)。
     * 供 uni-app 详情页「产品参数 / 适用场景」等展示;任何异常都回退空数组,不影响主流程。
     */
    private function customAttributes(): array
    {
        try {
            return $this->attribute_values
                ->mapWithKeys(function ($value) {
                    $attr = $value->attribute;
                    if (! $attr) {
                        return [];
                    }

                    $type = $attr->type;
                    $column = \Webkul\Product\Models\ProductAttributeValue::$attributeTypeFields[$type] ?? 'text_value';
                    $raw = $value->{$column};

                    return [$attr->code => [
                        'code'  => $attr->code,
                        'name'  => $attr->admin_name ?: $attr->code,
                        'value' => $this->resolveAttributeValue($attr, $type, $raw),
                    ]];
                })
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 把原始属性值解析成可读文案(select/multiselect 按 option 名称,boolean 转是/否)。
     */
    private function resolveAttributeValue($attr, string $type, $raw)
    {
        if (in_array($type, ['select'])) {
            $option = $attr->options()->find($raw);
            return $option ? $option->admin_name : $raw;
        }

        if (in_array($type, ['multiselect'])) {
            $ids = json_decode((string) $raw, true) ?: [];
            $names = $attr->options()->whereIn('id', (array) $ids)->pluck('admin_name')->implode(' / ');
            return $names ?: $raw;
        }

        if (in_array($type, ['boolean'])) {
            return $raw ? '是' : '否';
        }

        return $raw;
    }
}
