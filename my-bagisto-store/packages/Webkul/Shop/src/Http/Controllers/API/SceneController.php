<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Models\Scene;

/**
 * 场景(Scene)接口 —— 供 uni-app 机器页「企业商用(TOB)场景详情」展示后台维护的真实图 + 描述。
 *
 * 数据来自后端 scenes 表(后台「场景管理」维护):场景名、场景图(banner)、场景说明(description)。
 * 接口保持返回 matched/name/banner/description 结构,便于前端复用。
 *
 * 找不到该场景(如未 seed 或 id 超出 TOB 范围)时返回 matched=false 兜底,前端继续用本地数据,不报错。
 */
class SceneController extends APIController
{
    /**
     * 场景列表:返回所有启用(status=1)场景,供机器页「场景」Tab 卡片展示。
     * 可选 ?category= 过滤;banner 为后台上传的场景图 URL。
     */
    public function index(): JsonResponse
    {
        $query = Scene::where('status', 1)->orderBy('sort', 'desc')->orderBy('id', 'asc');

        if ($category = request()->query('category')) {
            $query->where('category', $category);
        }

        $scenes = $query->get()->map(function ($scene) {
            return [
                'id'          => $scene->id,
                'name'        => $scene->name,
                'category'    => $scene->category,
                'description' => $scene->description,
                'banner'      => $scene->banner_url,
                'sort'        => $scene->sort,
                'icon'        => $scene->icon,
                'bg_color'    => $scene->bg_color,
                'is_hot'      => (bool) $scene->is_hot,
                'consult_count' => $scene->consult_count,
            ];
        });

        return response()->json(['data' => $scenes]);
    }

    /**
     * 根据 sceneId 返回后台维护的场景数据(图 + 描述)。
     */
    public function show(int $id): JsonResponse
    {
        $scene = Scene::find($id);

        if (! $scene) {
            return response()->json([
                'id'          => $id,
                'name'        => '企业商用',
                'banner'      => null,
                'description' => '',
                'matched'     => false,
            ]);
        }

        return response()->json([
            'id'          => $id,
            'name'        => $scene->name,
            'banner'      => $scene->banner_url,
            'description' => $scene->description,
            'matched'     => true,
            'sort'        => $scene->sort,
            'is_hot'      => (bool) $scene->is_hot,
            // 完整方案内容（后台「场景管理」配置；前端详情页直接用此 scheme 覆盖本地兜底）
            'scheme'      => $scene->scheme,
        ]);
    }
}
