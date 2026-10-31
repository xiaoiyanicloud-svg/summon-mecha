<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Category\Models\Category;
use Webkul\Shop\Models\AccessoryFitment;
use Webkul\Shop\Models\AccessoryProfile;
use Webkul\Shop\Models\DeviceModel;

/**
 * 配件皮肤 · 后台维护（P1）。
 *
 * 只做两件事：维护配件档案、维护「商品 × 机型」适配关系。
 * 商品本身仍由 Bagisto 原生商品编辑页维护（分类/图片/价格/描述）。
 */
class AccessoryController extends Controller
{
    public function updateProfile(Request $request, int $productId): JsonResponse
    {
        if (! $this->isAccessoryProduct($productId)) {
            return response()->json(['message' => '该商品不属于配件分类。'], 422);
        }

        $data = $request->validate([
            'summary'            => ['nullable', 'string', 'max:255'],
            'install_method'     => ['required', 'in:'.implode(',', AccessoryProfile::INSTALL_METHODS)],
            'package_contents'   => ['nullable', 'string', 'max:2000'],
            'warranty_months'    => ['nullable', 'integer', 'min:0', 'max:120'],
            'lead_time_days'     => ['nullable', 'integer', 'min:0', 'max:365'],
            'material'           => ['nullable', 'string', 'max:64'],
            'official'           => ['nullable', 'boolean'],
            'custom_service'     => ['nullable', 'boolean'],
            'custom_price_from'  => ['nullable', 'numeric', 'min:0'],
            'compatibility_note' => ['nullable', 'string', 'max:2000'],
            'power_requirement'  => ['nullable', 'string', 'max:128'],
        ]);

        $data['official'] = (bool) ($data['official'] ?? false);
        $data['custom_service'] = (bool) ($data['custom_service'] ?? false);

        if ($data['custom_service'] && ! isset($data['custom_price_from'])) {
            return response()->json(['message' => '定制件必须填写定制起价。'], 422);
        }

        $profile = AccessoryProfile::updateOrCreate(
            ['product_id' => $productId],
            $data
        );

        return response()->json(['message' => '配件档案已保存。', 'data' => $profile]);
    }

    public function storeFitment(Request $request, int $productId): JsonResponse
    {
        if (! $this->isAccessoryProduct($productId)) {
            return response()->json(['message' => '该商品不属于配件分类。'], 422);
        }

        $data = $request->validate([
            'model_code' => ['required', 'string', 'max:64', 'exists:device_model,model_code'],
            'fit_level'  => ['required', 'in:'.implode(',', AccessoryFitment::FIT_LEVELS)],
            'adapter_sku'=> ['nullable', 'string', 'max:64'],
            'note'       => ['nullable', 'string', 'max:255'],
            'source'     => ['nullable', 'in:'.implode(',', array_keys(AccessoryFitment::SOURCE_LABELS))],
            'verified_at'=> ['nullable', 'date'],
        ]);

        $fitment = AccessoryFitment::updateOrCreate(
            ['product_id' => $productId, 'model_code' => $data['model_code']],
            [
                'fit_level'   => $data['fit_level'],
                'adapter_sku' => $data['adapter_sku'] ?? null,
                'note'        => $data['note'] ?? null,
                'source'      => $data['source'] ?? AccessoryFitment::SOURCE_PLATFORM,
                'verified_at' => $data['verified_at'] ?? null,
            ]
        );

        return response()->json(['message' => '适配关系已保存。', 'data' => $fitment]);
    }

    public function destroyFitment(int $productId, int $fitmentId): JsonResponse
    {
        $deleted = AccessoryFitment::query()
            ->where('product_id', $productId)
            ->where('id', $fitmentId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => '适配关系不存在。'], 404);
        }

        return response()->json(['message' => '适配关系已删除。']);
    }

    private function isAccessoryProduct(int $productId): bool
    {
        $categoryId = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
            ->value('id');

        if (! $categoryId) {
            return false;
        }

        $childIds = Category::query()->where('parent_id', $categoryId)->pluck('id')->all();

        return DB::table('product_categories')
            ->where('product_id', $productId)
            ->whereIn('category_id', [$categoryId, ...$childIds])
            ->exists();
    }
}
