<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 技能类目 —— v3.4 步骤 6。
 */
class SkillCategory extends Model
{
    protected $table = 'skill_category';

    protected $fillable = ['parent_id', 'code', 'name', 'sort_order', 'status'];

    protected $casts = [
        'parent_id'  => 'integer',
        'name'       => 'array',
        'sort_order' => 'integer',
    ];

    public function nameIn(string $locale = 'zh_CN'): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? '';
    }
}
