<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Models\SkillCategory;
use Webkul\Shop\Models\SkillTag;

/**
 * 技能类目/标签 —— 公开读取(v3.4 步骤 6)。
 */
class SkillCatalogController extends APIController
{
    /**
     * 类目列表。
     */
    public function categories(): JsonResponse
    {
        $rows = SkillCategory::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SkillCategory $c) => [
                'id'   => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ])->values(),
        ]);
    }

    /**
     * 标签列表(可按 type 过滤:motion_type/scene/difficulty)。
     */
    public function tags(\Illuminate\Http\Request $request): JsonResponse
    {
        $q = SkillTag::query()->orderBy('sort_order');

        if ($request->filled('type')) {
            $q->where('type', $request->input('type'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (SkillTag $t) => [
                'id'   => $t->id,
                'name' => $t->name,
                'type' => $t->type,
            ])->values(),
        ]);
    }

    /**
     * 技能搜索(v3.4 步骤 7)。
     *
     * 只搜 `skill` 分类下的商品,按 name 模糊匹配。返回精简列表(id/name/price)。
     * 用 product_flat 直查(避免 locale/channel 重复),按 product_id 去重。
     */
    public function search(\Illuminate\Http\Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        // skill 分类 id(取分类翻译里 slug=skill 的 category_id)
        $categoryId = \DB::table('category_translations')->where('slug', 'skill')->value('category_id');

        $query = \DB::table('product_flat')
            ->where('status', 1)
            ->where('visible_individually', 1)
            // ⚠ 只取当前语言行:多语言行里 name 只在对应语言有值,不过滤会取到 null 行
            ->where('locale', app()->getLocale())
            ->whereNotNull('name');

        if ($categoryId) {
            $query->whereIn('product_id', function ($sub) use ($categoryId) {
                $sub->select('product_id')->from('product_categories')->where('category_id', $categoryId);
            });
        }

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where('name', 'like', '%'.$escaped.'%');
        }

        $rows = $query->orderByDesc('product_id')->limit(40)->get(['product_id', 'name', 'price', 'special_price', 'url_key']);

        // 去重(同商品多语言行)
        $seen = [];
        $data = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->product_id])) {
                continue;
            }
            $seen[$r->product_id] = true;
            $data[] = [
                'id'            => (int) $r->product_id,
                'name'          => $r->name,
                'price'         => (float) $r->price,
                'special_price' => $r->special_price !== null ? (float) $r->special_price : null,
            ];
        }

        return response()->json(['data' => $data, 'query' => $q]);
    }
}
