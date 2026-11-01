<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 统一后台选择入口 · 动态注册表（支持覆盖 config/portal_backends.php 或扩展第 N 个后台）。
 */
class PortalBackendRegistry extends Model
{
    protected $table = 'portal_backend_registry';

    protected $fillable = [
        'code',
        'name',
        'badge',
        'description',
        'icon',
        'route_url',
        'permissions',
        'enabled',
        'disabled_reason',
        'sort',
        'meta',
    ];

    protected $casts = [
        'permissions' => 'array',
        'enabled'     => 'boolean',
        'sort'        => 'integer',
        'meta'        => 'array',
    ];
}
