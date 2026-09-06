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
        if (request()->ajax()) {
            return datagrid(EventDataGrid::class)->process();
        }

        return view('admin::catalog.events.index');
    }

    public function create()
    {
        return view('admin::catalog.events.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        Event::create($data);

        return redirect()
            ->route('admin.catalog.events.index')
            ->with('success', '赛事已创建');
    }

    public function edit($id)
    {
        $event = Event::findOrFail($id);

        return view('admin::catalog.events.edit', compact('event'));
    }

    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $event->update($this->validated($request));

        return redirect()
            ->route('admin.catalog.events.index')
            ->with('success', '赛事已更新');
    }

    public function destroy($id)
    {
        $event = Event::findOrFail($id);

        $event->registrations()->delete();
        $event->delete();

        return response()->json(['message' => '赛事已删除']);
    }

    /**
     * 报名列表(闭环的「转化」一环:前台报名 → 后台跟进)。
     */
    public function registrations()
    {
        if (request()->ajax()) {
            return datagrid(EventRegistrationDataGrid::class)->process();
        }

        return view('admin::catalog.events.registrations');
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
