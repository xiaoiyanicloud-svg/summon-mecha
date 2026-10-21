<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Validator;
use Webkul\Shop\Models\Scene;

/** 只输出待确认输入；模型价格/承诺/未知 ID 均不进入正式事实。无需调用外部模型。 */
class SceneAiDraftService
{
    public function __construct(protected SceneSchemaRegistry $schemas, protected SceneCatalogService $catalog) {}

    public function build(string $text, array $suggestions, int $channel): array
    {
        Validator::make(['text' => $text], ['text' => 'required|string|max:20000'])->validate();
        $category = in_array($suggestions['category'] ?? '', array_keys(Scene::CATEGORIES), true) ? $suggestions['category'] : 'enterprise';
        $schema = $this->schemas->schema($category);
        $fields = [];
        foreach ($schema['fields'] as $field) {
            $value = ($suggestions['facts'] ?? [])[$field['key']] ?? null;
            if ($field['key'] === 'task_goal' && $value === null) {
                $value = mb_substr($text, 0, 2000);
            }
            if ($value !== null) {
                try {
                    $value = $this->schemas->validate([$field['key'] => $value], $schema)[$field['key']];
                } catch (\Illuminate\Validation\ValidationException) {
                    $value = null;
                }
            }
            $fields[] = ['key' => $field['key'], 'label' => $field['label'], 'value' => $value, 'source' => 'unconfirmed_draft', 'state' => 'requires_customer_confirmation'];
        }
        $scene = null;
        $id = $suggestions['scene_id'] ?? null;
        if (is_int($id) && $id > 0 && $id <= 4294967295) {
            $candidate = Scene::enabled()->where('id', $id)->first();
            if ($candidate) {
                $scene = $this->catalog->summary($candidate);
            }
        }
        $ids = array_slice(array_values(array_filter($suggestions['product_ids'] ?? [], fn ($id) => is_int($id) && $id > 0 && $id <= 4294967295)), 0, 50);

        return ['version' => 'scene-ai-draft-v1', 'source' => 'ai', 'original_text' => $text, 'category' => $category, 'scene' => $scene,
            'suggested_fields' => $fields, 'facts' => [], 'catalog_product_ids' => $ids ? $this->catalog->rentalProductIds($channel, $ids) : [],
            'pending_confirmation' => array_column($fields, 'key'), 'notice' => '草稿字段须逐项确认。目录候选尚未证明现场能力、价格、库存或档期；正式评估与报价另行审核。'];
    }
}
