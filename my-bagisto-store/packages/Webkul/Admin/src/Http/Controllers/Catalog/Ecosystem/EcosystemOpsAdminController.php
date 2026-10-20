<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Services\Ecosystem\OpsService;

/**
 * G4-07 · 运营工作台。
 *
 * 每个分区给出可定位事实与入口；报名行还受活动人员范围约束，
 * 无 pii 能力时只显示遮罩。重排通知需要额外权限，且只接受未租用的 failed。
 */
class EcosystemOpsAdminController extends Controller
{
    public function __construct(
        private readonly OpsService $ops,
    ) {}

    public function index()
    {
        $this->authorizeRoute();

        $admin = auth()->guard('admin')->user();

        return view('admin::catalog.ecosystem-ops.index', [
            'data' => $this->ops->build($admin, bouncer()->hasPermission('ecosystem.registrations.pii')),
            'canRetry' => bouncer()->hasPermission('ecosystem.ops.retry'),
        ]);
    }

    public function retryOutbox(int $id): RedirectResponse
    {
        $this->authorizeRoute();

        try {
            $this->ops->retryOutbox($id, (int) auth()->guard('admin')->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }

        return back()->with('success', '通知已重排（不会重复发送：消费端按 dedupe_key 去重）。');
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '运营工作台路由未登记权限：'.$routeName);
        abort_unless(bouncer()->hasPermission($permission), 403, '无权访问运营工作台。');
    }
}
