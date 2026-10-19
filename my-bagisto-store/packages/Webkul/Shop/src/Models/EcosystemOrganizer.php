<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 主办/承办主体（ADR-0001 §4.4 / G1-D05）。
 *
 * 与商品 seller 是**两个域**：这里只记录活动主办权与核验事实，
 * 不合并 seller 名称、不自动授予商品/技能权利。
 *
 * 状态流转由 `Services\Ecosystem\OrganizerService` 统一维护：
 *   pending → verified / rejected；verified → suspended / pending（敏感字段变更）；
 *   rejected / suspended → verified（重新核验）。
 * 模型本身不拦截状态写入 —— 服务层是唯一写入口。
 */
class EcosystemOrganizer extends Model
{
    protected $table = 'ecosystem_organizers';

    protected $fillable = [
        'name',
        'platform_role',
        'source_url',
        'public_materials',
        'private_evidence_ref',
        'verified_by',
        'verified_at',
        'status',
        'verification_note',
    ];

    protected $casts = [
        // 核验时间以 UTC 存储（ADR G1-D04）；应用时区是 Asia/Shanghai，不能用默认 datetime cast。
        'verified_at' => UtcDateTime::class,
        'verified_by' => 'integer',
    ];

    public function events()
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }
}
