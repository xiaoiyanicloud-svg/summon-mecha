<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * 机器人生态与 RaaS 目录品牌实体。
 *
 * 统一管理前台分类页展示品牌、Logo、排序、热门 TOP 标记、商品名回退别名，
 * 并对接 Bagisto `brand` EAV 属性选项与机型库 `device_model.brand`。
 */
class CatalogBrand extends Model
{
    /** 标准机器人形态枚举，供后台属性选项同步与 Shop/分类页共用。 */
    public const ROBOT_TYPES = [
        'humanoid'   => '人形',
        'quadruped'  => '四足',
        'delivery'   => '配送',
        'cleaning'   => '清洁',
        'reception'  => '迎宾',
        'inspection' => '巡检',
        'arm'        => '机械臂',
        'companion'  => '陪伴',
    ];

    protected $table = 'catalog_brands';

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'aliases',
        'device_brand',
        'logo_path',
        'description',
        'official_url',
        'is_hot',
        'status',
        'sort_order',
        'attribute_option_id',
    ];

    protected $casts = [
        'aliases'             => 'array',
        'is_hot'              => 'boolean',
        'status'              => 'boolean',
        'sort_order'          => 'integer',
        'attribute_option_id' => 'integer',
    ];

    public function deviceModels(): HasMany
    {
        return $this->hasMany(DeviceModel::class, 'brand', 'device_brand');
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::url($this->logo_path);
    }
}
