<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Services\Ecosystem\MetricsService;

/**
 * G4-06 · 运营指标页。
 *
 * 只读；口径与数字一起输出（见 MetricsService::definitions）。
 * 指标开关关闭时页面明确显示“未启用”，不渲染 0 冒充真实数据。
 */
class EcosystemMetricsAdminController extends Controller
{
    public function __construct(
        private readonly MetricsService $metrics,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeRoute();

        $from = $request->query('from');
        $to = $request->query('to');

        $data = $this->metrics->build([
            'from' => is_string($from) ? $from : '',
            'to'   => is_string($to) ? $to : '',
        ]);

        return view('admin::catalog.ecosystem-metrics.index', [
            'data' => $data,
        ]);
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '运营指标路由未登记权限：'.$routeName);
        abort_unless(bouncer()->hasPermission($permission), 403, '无权查看运营指标。');
    }
}
