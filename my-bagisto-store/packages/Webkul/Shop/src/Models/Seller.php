<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 商户(上传方)模型 —— 为「平台入驻多商户」预留的归属主体。
 *
 * 现状:平台自营,本表为空,所有 skill_* 与 products.seller_id 均为 NULL。
 * 引入它的唯一目的是让"谁上传的技能"从数据第一天就是对的,将来接 Marketplace
 * 或自建卖家后台时只需 backfill,不用推翻模型。
 *
 * code 对应上传凭证(X-Internal-Key 之外的卖家身份);commission_rate 预留分账。
 */
class Seller extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'sellers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'status',
        'owner_customer_id',
        'commission_rate',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'commission_rate' => 'decimal:2',
    ];

    /**
     * 反向：这个商户对应的开发者主体（平台自营的商户不会有 profile）。
     */
    public function developerProfile(): HasOne
    {
        return $this->hasOne(DeveloperProfile::class, 'seller_id');
    }

    /**
     * 是否是平台的商铺(NULL seller_id 的语义来源)。
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
