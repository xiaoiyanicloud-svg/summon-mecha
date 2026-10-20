<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Models\Scene;
use Webkul\Shop\Services\Scenes\SceneCatalogService;

/**
 * 场景(Scene)接口 —— 供 uni-app 机器页「企业商用(TOB)场景详情」展示后台维护的真实图 + 描述。
 *
 * 数据来自后端 scenes 表(后台「场景管理」维护):场景名、场景图(banner)、场景说明(description)。
 * 接口保持返回 matched/name/banner/description 结构,便于前端复用。
 *
 * 未启用或不存在的场景统一返回 404，不允许回退为可交易的本地示例。
 */
class SceneController extends APIController
{
    /**
     * 场景列表:返回所有启用(status=1)场景,供机器页「场景」Tab 卡片展示。
     * 可选 ?category= 过滤;banner 为后台上传的场景图 URL。
     */
    public function index(): JsonResponse
    {
        $query = Scene::enabled()->orderBy('sort', 'desc')->orderBy('id', 'asc');

        $category = request()->query('category');
        if ($category !== null && $category !== '' && $category !== 'all') {
            if (! is_string($category) || ! array_key_exists($category, Scene::CATEGORIES)) {
                return $this->fail(ApiErrorCode::VALIDATION_ERROR, '场景分类无效', 422);
            }
            if (config('scenes.publication_v2')) {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('scene_revisions as category_revision')
                    ->whereColumn('category_revision.id', 'scenes.active_revision_id')->where('category_revision.content->category', $category));
            } else {
                $query->where('category', $category);
            }
        }

        $scenes = $query->get()->map(fn ($scene) => app(SceneCatalogService::class)->summary($scene));

        return response()->json(['data' => $scenes]);
    }

    /**
     * 根据 sceneId 返回后台维护的场景数据(图 + 描述)。
     */
    public function show(string $id): JsonResponse
    {
        if (! preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (float) $id > 4294967295) {
            return $this->fail(ApiErrorCode::SCENE_UNAVAILABLE, '场景不存在或已停用', 404);
        }

        $scene = Scene::enabled()->find($id);
        if (! $scene) {
            return $this->fail(ApiErrorCode::SCENE_UNAVAILABLE, '场景不存在或已停用', 404);
        }

        $data = app(SceneCatalogService::class)->detail($scene, (int) core()->getCurrentChannel()->id);
        $data['products'] = \Webkul\Product\Models\Product::query()
            ->whereIn('id', $data['scheme']['robots'])->get()
            ->map(fn ($product) => (new \Webkul\Shop\Http\Resources\ProductResource($product))->jsonSerialize()
                + ['type' => $product->type])->values()->all();

        return response()->json($data);
    }
}
