<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 赛事系列（G3-02）。
 *
 * 系列是跨届次的稳定身份；主办主体允许为空（平台自办），但保留
 * `organizer_name` 快照。名称比较使用规范化后的 `name_key`，不以展示名去重。
 */
class CompetitionSeries extends Model
{
    protected $table = 'competition_series';

    protected $fillable = [
        'organizer_id',
        'organizer_name',
        'name',
        'name_key',
        'slug',
        'description',
        'status',
        'row_version',
    ];

    protected $casts = [
        'organizer_id' => 'integer',
        'row_version'  => 'integer',
    ];

    public function editions()
    {
        return $this->hasMany(CompetitionEdition::class, 'series_id');
    }

    public function organizer()
    {
        return $this->belongsTo(EcosystemOrganizer::class, 'organizer_id');
    }
}
