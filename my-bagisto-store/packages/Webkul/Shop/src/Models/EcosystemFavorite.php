<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * G4-02 · 用户收藏关系。
 *
 * 目标用 `target_type + target_key` 多态引用；目标是否仍公开由读取侧统一判断。
 * 本模型不做可见性判断，也不序列化给客户端。
 */
class EcosystemFavorite extends Model
{
    protected $table = 'ecosystem_favorites';

    protected $fillable = [
        'customer_id',
        'target_type',
        'target_key',
    ];

    protected $casts = [
        'customer_id' => 'integer',
    ];
}
