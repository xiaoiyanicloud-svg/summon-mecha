<?php

namespace Webkul\Admin\Http\Controllers\Ops;

use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Services\Ops\OpsMetricsService;

/**
 * 平台运营总览（v3.4 第 10 步）。
 *
 * 与 Bagisto 原生仪表盘的区别：原生那个是**电商口径**（销量/客户/库存），
 * 这里是**技能平台口径**（审核队列深度、内容安全待处置、设备绑定、
 * 未结案事故、超 SLA 工单）。
 *
 * ⚠ 本页最重要的设计约束：**每个指标都带口径，未接入的维度显示「未接入」而不是 0**。
 *   详见 `OpsMetricsService` 的类注释 —— "没有数据"与"数据是零"对决策的含义完全相反。
 */
class OverviewController extends Controller
{
    public function __construct(protected OpsMetricsService $metrics) {}

    public function index()
    {
        return view('admin::ops.overview.index', [
            'trade'        => $this->metrics->trade(),
            'supply'       => $this->metrics->supply(),
            'review'       => $this->metrics->review(),
            'devices'      => $this->metrics->devices(),
            'entitlements' => $this->metrics->entitlements(),
            'service'      => $this->metrics->service(),
            'developers'   => $this->metrics->developers(),
            'compliance'   => $this->metrics->compliance(),
            'notConnected' => $this->metrics->notConnected(),
        ]);
    }
}
