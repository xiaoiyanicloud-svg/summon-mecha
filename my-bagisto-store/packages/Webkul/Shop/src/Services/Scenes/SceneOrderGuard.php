<?php

namespace Webkul\Shop\Services\Scenes;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Scene;

class SceneOrderGuard
{
    public function __construct(protected SceneCatalogService $catalog) {}

    /** 在建单事务内调用。普通租购保持既有约定；场景来源必须可核验。 */
    public function check(array $input, array $productIds, int $channelId): array
    {
        $from = $input['from'] ?? '';
        $type = $input['type'] ?? '';
        $sceneId = $input['scene_id'] ?? null;
        if (! $sceneId && ! in_array($from, ['scene', 'custom_plan'], true) && $type !== 'plan') {
            return [];
        }

        if (! in_array($type, ['rent', 'custom'], true) || ($from === 'custom_plan' && $type !== 'rent')) {
            return ['code' => ApiErrorCode::SCENE_REQUIRED, 'message' => '请从有效场景重新选择服务'];
        }

        $scene = null;
        if ($from !== 'custom_plan' || $sceneId) {
            if (! $sceneId) {
                return ['code' => ApiErrorCode::SCENE_REQUIRED, 'message' => '场景信息已更新，请重新进入场景后提交'];
            }
            $scene = Scene::enabled()->lockForUpdate()->find($sceneId);
            if (! $scene) {
                return ['code' => ApiErrorCode::SCENE_UNAVAILABLE, 'message' => '场景不存在或已停用'];
            }
        }

        if ($type === 'rent') {
            $allowed = $this->catalog->rentalProductIds($channelId, $scene ? (array) $scene->robots : null);
            if (! $productIds || array_diff(array_map('intval', $productIds), $allowed)) {
                return ['code' => ApiErrorCode::SCENE_PRODUCT_UNAVAILABLE, 'message' => '所选机型已失效或未配置到本场景，请重新选择'];
            }
        }

        return [
            'source' => $scene ? 'scene' : 'custom_plan',
            'scene_id' => $scene?->id,
            'scene_name' => $scene?->name ?? '自定义方案',
        ];
    }
}
