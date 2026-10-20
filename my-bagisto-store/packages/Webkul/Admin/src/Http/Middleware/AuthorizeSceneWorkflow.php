<?php

namespace Webkul\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthorizeSceneWorkflow
{
    public const ACTIONS = [
        'index'      => 'view', 'show' => 'view', 'contact' => 'contact', 'requestAction' => 'requests',
        'assess'     => 'assess', 'quote' => 'quote', 'quoteAction' => 'quote', 'publication' => 'publish',
        'supply'     => 'supply', 'hold' => 'reserve', 'pause' => 'reserve', 'task' => 'deliver', 'replay' => 'operate',
        'commercial' => 'finance', 'reviewCommercial' => 'finance_review', 'returnResources' => 'deliver', 'resolveTicket' => 'support',
        'attachment' => 'view', 'upload' => 'requests',
    ];

    public function handle(Request $request, Closure $next)
    {
        $method = $request->route()->getActionMethod();
        $permission = self::ACTIONS[$method] ?? null;
        if ($method === 'quoteAction' && $request->route('action') === 'approve') {
            $permission = 'approve';
        }
        if ($method === 'publication') {
            $permission = match ($request->route('action')) {
                'draft', 'review' => 'revision_write',
                'approve' => 'revision_review',
                'publish', 'withdraw' => 'publish',
                default => null,
            };
        }
        $key = $permission === 'view' ? 'catalog.scene-workflow' : 'catalog.scene-workflow.'.$permission;
        if (! $permission || ! bouncer()->hasPermission($key)) {
            abort(403);
        }

        return $next($request);
    }
}
