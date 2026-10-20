<?php

namespace Webkul\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\Scene;

/**
 * 场景(Scene)后台表单校验 —— 照抄分类(Category)的图片规则,去掉分类特有的 slug/属性。
 */
class SceneRequest extends FormRequest
{
    /**
     * Determine if the Configuration is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'category' => ['sometimes', Rule::in(array_keys(Scene::CATEGORIES))],
            'description' => 'nullable|string|max:20000',
            'banner_path' => 'nullable|array|max:1',
            'banner_path.*' => 'nullable|mimes:bmp,jpeg,jpg,png,webp|max:5120',
            'status' => 'boolean',
            'is_hot' => 'boolean',
            'remove_banner' => 'boolean',
            'icon' => 'nullable|string|max:32',
            'bg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'consult_count' => 'nullable|integer|min:0|max:2147483647',
            'tags' => 'nullable|string|max:2000',
            'stats_amp' => 'nullable|string|max:64',
            'stats_roi' => 'nullable|string|max:64',
            'stats_scene' => 'nullable|string|max:64',
            'pains' => 'nullable|string|max:10000',
            'benefits' => 'nullable|string|max:10000',
            'scheme_scenes' => 'nullable|string|max:10000',
            'robots' => 'nullable|array|max:50',
            'robots_present' => 'sometimes|boolean',
            'robots.*' => ['integer', 'min:1', 'distinct', Rule::exists('products', 'id')->where('type', 'simple')],
            'sort' => 'nullable|integer|min:0|max:2147483647',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = $this->input('robots', []);
            if ($ids) {
                $allowed = app(\Webkul\Shop\Services\Scenes\SceneCatalogService::class)
                    ->rentalProductIds((int) core()->getCurrentChannel()->id);
                if (array_diff(array_map('intval', $ids), $allowed)) {
                    $validator->errors()->add('robots', '请选择当前渠道已启用的租用目录机型。');
                }
            }
            foreach (['pains', 'benefits', 'scheme_scenes'] as $field) {
                $lines = array_values(array_filter(preg_split('/\R/u', (string) $this->input($field, '')), fn ($line) => trim($line) !== ''));
                if (count($lines) > 50) {
                    $validator->errors()->add($field, '最多填写 50 条内容。');
                }
                foreach ($lines as $line) {
                    $parts = explode('|', $line);
                    if (count($parts) !== 3 || trim($parts[1]) === '' || trim($parts[2]) === '') {
                        $validator->errors()->add($field, '每行须包含三个以 | 分隔的字段，正文不得为空。');
                        break;
                    }
                }
            }
        });
    }
}
