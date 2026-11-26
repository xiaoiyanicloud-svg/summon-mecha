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
        $sceneRevisionId = ! empty($input['scene_revision_id']) ? (int) $input['scene_revision_id'] : null;

        if (! $sceneId && ! in_array($from, ['scene', 'custom_plan'], true) && $type !== 'plan') {
            return [];
        }

        $isRentFlow = in_array($type, ['rent', 'plan'], true);
        if ((! $isRentFlow && $type !== 'custom') || ($from === 'custom_plan' && ! $isRentFlow)) {
            return ['code' => ApiErrorCode::SCENE_REQUIRED, 'message' => '请从有效场景重新选择服务'];
        }

        $scene = null;
        $configuredRobots = null;
        $sceneName = '自定义方案';
        $resolvedRevisionId = null;

        if ($from !== 'custom_plan' || $sceneId) {
            if (! $sceneId) {
                return ['code' => ApiErrorCode::SCENE_REQUIRED, 'message' => '场景信息已更新，请重新进入场景后提交'];
            }
            $scene = Scene::enabled()->lockForUpdate()->find($sceneId);
            if (! $scene) {
                return ['code' => ApiErrorCode::SCENE_UNAVAILABLE, 'message' => '场景不存在或已停用'];
            }

            if (config('scenes.publication_v2', false)) {
                try {
                    $revision = app(ScenePublicationService::class)->active((int) $scene->id, $sceneRevisionId, true);
                    $content = SceneWorkflow::unpack($revision->content);
                    $configuredRobots = array_values(array_map('intval', (array) ($content['robots'] ?? [])));
                    $sceneName = (string) ($content['name'] ?? $scene->name);
                    $resolvedRevisionId = (int) $revision->id;
                } catch (SceneWorkflowException $e) {
                    return ['code' => ApiErrorCode::SCENE_UNAVAILABLE, 'message' => $e->getMessage()];
                }
            } else {
                $configuredRobots = (array) $scene->robots;
                $sceneName = (string) $scene->name;
            }
        }

        if ($isRentFlow) {
            $allowed = $this->catalog->rentalProductIds($channelId, $scene ? $configuredRobots : null, current: true);
            if (! $productIds || array_diff(array_map('intval', $productIds), $allowed)) {
                return ['code' => ApiErrorCode::SCENE_PRODUCT_UNAVAILABLE, 'message' => '所选机型已失效或未配置到本场景，请重新选择'];
            }
        }

        return [
            'source'            => $scene ? 'scene' : 'custom_plan',
            'scene_id'          => $scene?->id,
            'scene_name'        => $sceneName,
            'scene_revision_id' => $resolvedRevisionId,
        ];
    }
}
