<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves a Sanctum bearer-token customer for API requests and binds it into the
 * `customer` guard, so existing Bagisto controller code (CartController,
 * OnepageController, ProductResource, ...) that calls `auth()->guard('customer')`
 * sees the token-authenticated user — while the web / session flow stays untouched.
 */
class ResolveApiCustomer
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user('sanctum')) {
            Auth::guard('customer')->setUser($user);
        }

        return $next($request);
    }
}