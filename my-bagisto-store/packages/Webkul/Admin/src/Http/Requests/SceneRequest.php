<?php

namespace Webkul\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'name'              => 'required|string',
            'description'       => 'nullable|string',
            'banner_path'       => 'nullable|array',
            'banner_path.*'     => 'mimes:bmp,jpeg,jpg,png,webp',
            'status'            => 'boolean',
            'icon'              => 'nullable|string|max:32',
            'bg_color'          => 'nullable|string|max:32',
            'consult_count'     => 'nullable|integer|min:0',
            'tags'              => 'nullable|string',
            'stats_amp'         => 'nullable|string|max:64',
            'stats_roi'         => 'nullable|string|max:64',
            'stats_scene'       => 'nullable|string|max:64',
            'pains'             => 'nullable|string',
            'benefits'          => 'nullable|string',
            'scheme_scenes'     => 'nullable|string',
            'robots'            => 'nullable|array',
            'sort'              => 'nullable|integer|min:0',
        ];
    }
}
