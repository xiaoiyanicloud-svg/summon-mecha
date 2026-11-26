<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

class SkillTargetReleaseEvent extends Model
{
    protected $table = 'skill_target_release_events';

    public $timestamps = false;

    protected $fillable = [
        'skill_version_id', 'target_model_code', 'artifact_asset_id', 'build_key',
        'target_profile_digest', 'event_type', 'actor_id', 'reason', 'created_at',
    ];

    protected $casts = [
        'skill_version_id' => 'integer',
        'artifact_asset_id' => 'integer',
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];
}