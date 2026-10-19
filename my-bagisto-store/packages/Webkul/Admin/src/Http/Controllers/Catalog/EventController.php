<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Catalog\EventDataGrid;
use Webkul\Admin\DataGrids\Catalog\EventRegistrationDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Event;

/**
 * 赛事(Event)后台管理 —— 紧凑版 CRUD + 报名列表。
 *
 * 数据表 events / event_registrations(迁移在 Webkul\Shop 包),模型 Webkul\Shop\Models\Event。
 */
class EventController extends Controller
{
    public function index()
    {
        $this->authorizePermission('catalog.events');

        if (request()->ajax()) {
            return datagrid(EventDataGrid::class)->process();
        }

        return view('admin::catalog.events.index');
    }

    public function create()
    {
        $this->authorizePermission('catalog.events.create');

        return view('admin::catalog.events.create');
    }

    public function store(Request $request)
    {
        $this->authorizePermission('catalog.events.create');

        $data = $this->validated($request);

        Event::create($data);

        return redirect()
            ->route('admin.catalog.events.index')
            ->with('success', '赛事已创建');
    }

    public function edit($id)
    {
        $this->authorizePermission('catalog.events.edit');

        $event = Event::findOrFail($id);

        return view('admin::catalog.events.edit', compact('event'));
    }

    public function update(Request $request, $id)
    {
        $this->authorizePermission('catalog.events.edit');

        $event = Event::findOrFail($id);

        $event->update($this->validated($request));

        return redirect()
            ->route('admin.catalog.events.index')
            ->with('success', '赛事已更新');
    }

    public function destroy($id)
    {
        $this->authorizePermission('catalog.events.delete');

        $event = Event::findOrFail($id);

        if ($event->registrations()->exists()) {
            return response()->json([
                'message' => '该赛事已有报名，不能直接删除；请先停用/归档（归档能力待 G1）',
            ], 422);
        }

        $event->registrations()->delete();
        $event->delete();

        return response()->json(['message' => '赛事已删除']);
    }

    /**
     * 报名列表(闭环的「转化」一环:前台报名 → 后台跟进)。
     */
    public function registrations()
    {
        $this->authorizePermission('catalog.events.registrations');

        if (request()->ajax()) {
            return datagrid(EventRegistrationDataGrid::class)->process();
        }

        return view('admin::catalog.events.registrations');
    }

    /**
     * 服务端授权:不能只靠隐藏菜单/按钮,直接访问 URL / Ajax 同样拒绝。
     *
     * 读动作的路由已登记在 acl.php,通常由 Bouncer 中间件先以 401 拦截;
     * 写动作(store/update/destroy)刻意不做路由映射,由此处统一返回 403。
     */
    private function authorizePermission(string $permission): void
    {
        abort_unless(
            bouncer()->hasPermission($permission),
            403,
            '无权执行该赛事操作。'
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'      => 'required|string',
            'type'      => 'nullable|string',
            'status'    => 'required|in:registering,ongoing,upcoming,ended',
            'date'      => 'nullable|string',
            'city'      => 'nullable|string',
            'location'  => 'nullable|string',
            'organizer' => 'nullable|string',
            'hot'       => 'nullable|integer|min:0|max:100',
            'teams'     => 'nullable|integer|min:0',
            'prize'     => 'nullable|string',
            'schedule'  => 'nullable|string',
            'history'   => 'nullable|string',
            'detail'    => 'nullable|string',
            'icon'      => 'nullable|string',
            'bg_color'  => 'nullable|string',
            'sort'      => 'nullable|integer',
        ]);
    }
}
