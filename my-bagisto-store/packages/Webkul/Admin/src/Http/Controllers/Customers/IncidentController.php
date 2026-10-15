<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Customers\IncidentDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Incident\IncidentService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 事故受理台（计划 §4.19）。
 *
 * 在此之前：表建了、零代码 —— 用户连报案入口都没有，更谈不上受理。
 * 现在用户侧可上报（`POST /api/incidents`，证据链在报案时固化），这里是平台侧。
 *
 * 两条硬规则：
 *   ① **定责必须写说明** —— 把责任判给某一方会影响赔偿与法律关系，
 *      没有书面依据的"已定责"等于没定。
 *   ② **推翻不了追溯** —— `trace` 是报案那一刻的快照，只读展示，不提供编辑。
 *      能改的证据链不是证据链。
 */
class IncidentController extends Controller
{
    public function __construct(
        protected IncidentService $incidents,
        protected OperationLogger $oplog,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(IncidentDataGrid::class)->process();
        }

        return view('admin::customers.incidents.index');
    }

    public function show(int $id)
    {
        $incident = Incident::find($id);

        if (! $incident) {
            session()->flash('error', '事故记录不存在。');

            return redirect()->route('admin.customers.incidents.index');
        }

        $customer = $incident->customer_id
            ? \Webkul\Customer\Models\Customer::find($incident->customer_id)
            : null;

        // 关联的技能商品（trace 里已有快照，这里只是给个可点的名字）
        $productName = $incident->skill_id
            ? \Webkul\Product\Models\ProductProxy::modelClass()::find($incident->skill_id)?->name
            : null;

        return view('admin::customers.incidents.show', compact('incident', 'customer', 'productName'));
    }

    /**
     * 推进处理 / 定责。
     */
    public function handle(Request $request, int $id)
    {
        $incident = Incident::findOrFail($id);

        $data = $request->validate([
            'status'         => 'required|in:'.implode(',', array_keys(Incident::STATUS_LABELS)),
            'responsibility' => 'nullable|in:'.implode(',', array_keys(Incident::RESPONSIBILITY_LABELS)),
            'note'           => 'nullable|string|max:2000',
        ]);

        $responsibility = $data['responsibility'] ?? null;
        $note = $data['note'] ?? null;

        // A6 · 状态机:先挡非法流转,再谈细则。此前只校验"目标在枚举里"不校验方向,
        //   于是一个已结案的案子能被打回"待受理",而 resolved_at 不会被清 → SLA 口径失真。
        if (! $incident->canTransitionTo($data['status'])) {
            session()->flash('error', sprintf(
                '不能从「%s」直接流转到「%s」。',
                $incident->statusLabel(),
                Incident::STATUS_LABELS[$data['status']] ?? $data['status'],
            ));

            return redirect()->route('admin.customers.incidents.show', $id);
        }

        // ① 定责必须写说明
        $isAttributing = $responsibility !== null
            && $responsibility !== Incident::RESPONSIBILITY_UNKNOWN
            && $responsibility !== $incident->responsibility;

        if ($isAttributing && empty($note)) {
            session()->flash('error', '认定责任方必须写明依据 —— 这会影响赔偿与责任划分，没有书面依据的"已定责"等于没定。');

            return redirect()->route('admin.customers.incidents.show', $id);
        }

        // 结案也要求说明：一个没有结论的"已结案"，对当事人和后来的人都毫无信息量
        if (in_array($data['status'], [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED], true)
            && empty($note)
            && $incident->status !== $data['status']
        ) {
            session()->flash('error', '结案必须写明处理结论。');

            return redirect()->route('admin.customers.incidents.show', $id);
        }

        $this->incidents->advance($incident, $data['status'], $responsibility, $note);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'incident.handle',
            'incident',
            $incident->id,
            [
                'status'         => $data['status'],
                'responsibility' => $responsibility,
                'severity'       => $incident->severity,
            ],
            $request->ip()
        );

        session()->flash('success', '已记录处理进展。');

        return redirect()->route('admin.customers.incidents.show', $id);
    }
}
