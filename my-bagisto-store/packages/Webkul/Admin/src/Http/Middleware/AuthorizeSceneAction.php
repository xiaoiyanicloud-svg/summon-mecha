<?php

namespace Webkul\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthorizeSceneAction
{
    public const PERMISSIONS = [
        'index' => 'catalog.scenes',
        'create' => 'catalog.scenes.create',
        'store' => 'catalog.scenes.create',
        'edit' => 'catalog.scenes.edit',
        'update' => 'catalog.scenes.edit',
        'updateStatus' => 'catalog.scenes.edit',
        'toggleHot' => 'catalog.scenes.edit',
        'updateConsultCount' => 'catalog.scenes.edit',
        'massUpdate' => 'catalog.scenes.edit',
        'destroy' => 'catalog.scenes.delete',
        'massDestroy' => 'catalog.scenes.delete',
    ];

    public function handle(Request $request, Closure $next)
    {
        $permission = self::PERMISSIONS[$request->route()->getActionMethod()] ?? null;
        abort_unless($permission && bouncer()->hasPermission($permission), 403);

        return $next($request);
    }
}
