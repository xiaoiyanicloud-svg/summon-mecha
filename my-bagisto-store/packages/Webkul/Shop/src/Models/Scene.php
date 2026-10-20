<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * 场景(Scene)模型 —— 支撑机器页「企业商用(TOB)场景详情」。
 *
 * 背景:Bagisto 无场景实体,以往场景是前端硬编码 + 后端借商品名匹配。本模型把场景落库,
 * 供后台(Admin)管理与前台(Shop API)读取。
 *
 * 图片只存磁盘相对路径 banner_path,URL 由 accessor 拼出(参考 Category 的 logo/banner path 写法)。
 */
class Scene extends Model
{
    public const CATEGORIES = [
        'business_event' => '商业活动', 'enterprise' => '企业商用',
        'culture_tourism' => '文旅景区', 'education' => '科普教育',
        'security' => '安全巡检', 'warehouse_logistics' => '仓储物流',
        'industry' => '工业现场', 'medical' => '医疗康养', 'home' => '家庭个人',
    ];

    public function scopeEnabled($query)
    {
        $query->where('status', 1);
        if (config('scenes.publication_v2', false)) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('scene_revisions as sr')
                ->whereColumn('sr.id', 'scenes.active_revision_id')->whereColumn('sr.scene_id', 'scenes.id')->where('sr.state', 'published'));
        }

        return $query;
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'scenes';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'banner_path',
        'description',
        'category',
        'status',
        'icon',
        'bg_color',
        'consult_count',
        'tags',
        'stats',
        'pains',
        'benefits',
        'scheme_scenes',
        'robots',
        'sort',
        'is_hot',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => 'boolean',
        'consult_count' => 'integer',
        'tags' => 'array',
        'stats' => 'array',
        'pains' => 'array',
        'benefits' => 'array',
        'scheme_scenes' => 'array',
        'robots' => 'array',
        'sort' => 'integer',
        'is_hot' => 'boolean',
    ];

    /**
     * Get banner url attribute.
     */
    public function getBannerUrlAttribute()
    {
        if (! $this->banner_path) {
            return null;
        }

        return Storage::url($this->banner_path);
    }

    /**
     * 组装与前端「方案定制」同构的完整方案内容对象。
     * desc 复用 description；收益场景列名 scheme_scenes（避开表名 scenes）。
     */
    public function getSchemeAttribute()
    {
        return [
            'desc' => $this->description,
            'icon' => $this->icon,
            'bgColor' => $this->bg_color,
            'consultCount' => $this->consult_count,
            'tags' => $this->tags,
            'stats' => $this->stats,
            'pains' => $this->pains,
            'benefits' => $this->benefits,
            'scenes' => $this->scheme_scenes,
            'robots' => $this->robots,
        ];
    }
}
