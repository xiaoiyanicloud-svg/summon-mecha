<?php

namespace Webkul\User\Http\Middleware;

use Illuminate\Support\Facades\Route;

class Bouncer
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, \Closure $next, $guard = 'admin')
    {
        if (! auth()->guard($guard)->check()) {
            return redirect()->route('admin.session.create');
        }

        /**
         * If user status is changed by admin. Then session should be
         * logged out.
         */
        if (! (bool) auth()->guard($guard)->user()->status) {
            auth()->guard($guard)->logout();

            return redirect()->route('admin.session.create');
        }

        /**
         * If somehow the user deleted all permissions, then it should be
         * auto logged out and need to contact the administrator again.
         */
        if ($this->isPermissionsEmpty()) {
            auth()->guard('admin')->logout();

            session()->flash('error', __('admin::app.error.403.message'));

            return redirect()->route('admin.session.create');
        }

        return $next($request);
    }

    /**
     * Check for user, if they have empty permissions or not except admin.
     *
     * @return bool
     */
    public function isPermissionsEmpty()
    {
        if (! $role = auth()->guard('admin')->user()->role) {
            abort(401, 'This action is unauthorized.');
        }

        if ($role->permission_type === 'all') {
            return false;
        }

        if (
            $role->permission_type !== 'all'
            && empty($role->permissions)
        ) {
            return true;
        }

        $this->checkIfAuthorized();

        return false;
    }

    /**
     * Check authorization.
     *
     * @return null
     */
    public function checkIfAuthorized()
    {
        $routeName = (string) Route::currentRouteName();

        /*
         * G1 生态发布工作台（admin.catalog.ecosystem.*）。
         *
         * 中间件先做一道 403 防线：权限直接取自与控制器同一张登记表
         * （config/ecosystem_admin.php），这样即便某个动作忘了调用
         * PublishingController::authorizeRoute()，也无法仅凭已登录就执行。
         *
         * 这里不能用 Bouncer::allow() 的统一 401：工作台要求直接 URL / Ajax
         * 一律得到可读的 403（并能在控制器里转成表单错误），而不是裸 401。
         * 因此权限通过后只 return，让控制器继续按 route → permission 做
         * 细粒度校验。漏登记的路由拿不到权限，非超管在这里一律 403；
         * 超管由控制器 fail-closed（500），并由 EcosystemPublishingAclG1Test
         * 逐路由兜住。
         */
        if (str_starts_with($routeName, 'admin.catalog.ecosystem.')) {
            $permissions = (array) config('ecosystem_admin.route_permissions', []);
            $permission = (string) ($permissions[$routeName] ?? '');

            abort_unless(
                bouncer()->hasPermission($permission),
                403,
                '无权执行该生态发布操作。',
            );

            return;
        }

        $roles = acl()->getRoles();

        if (isset($roles[$routeName])) {
            bouncer()->allow((string) $roles[$routeName]);
        }
    }
}
