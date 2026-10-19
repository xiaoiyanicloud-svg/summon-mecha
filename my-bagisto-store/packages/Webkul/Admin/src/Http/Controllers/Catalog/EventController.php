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
    /**
     * 非空列的应用层默认值。
     *
     * 不能依赖 DB 默认值兜底:events.icon 在线上/测试库里的实际默认值是 `?`,
     * 与迁移声明的 🏆 不一致;其余字段也存在库间漂移的可能。
     */
    private const CREATE_DEFAULTS = [
        'type'     => '格斗竞技',
        'hot'      => 60,
        'teams'    => 0,
        'icon'     => '🏆',
        'bg_color' => '#E8F0FE',
        'sort'     => 0,
    ];

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

        $data = $this->normalizeForCreate($this->validated($request));

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

        $event->update($this->normalizeForUpdate($this->validated($request)));

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
            'name'      => 'required|string|max:191',
            'type'      => 'nullable|string|max:191',
            'status'    => 'required|string|max:20|in:registering,ongoing,upcoming,ended',
            'date'      => 'nullable|string|max:191',
            'city'      => 'nullable|string|max:191',
            'location'  => 'nullable|string|max:191',
            'organizer' => 'nullable|string|max:191',
            'hot'       => 'nullable|integer|min:0|max:100',
            'teams'     => 'nullable|integer|min:0|max:4294967295',
            'prize'     => 'nullable|string|max:16000',
            'schedule'  => 'nullable|string|max:16000',
            'history'   => 'nullable|string|max:16000',
            'detail'    => 'nullable|string|max:16000',
            'icon'      => 'nullable|string|max:191',
            'bg_color'  => 'nullable|string|max:20',
            'sort'      => 'nullable|integer|min:-2147483648|max:2147483647',
        ]);
    }

    /**
     * 新建:非空列一律由应用层写入值,空/null 时使用默认值。
     */
    private function normalizeForCreate(array $data): array
    {
        foreach (self::CREATE_DEFAULTS as $field => $default) {
            if ($this->isBlank($data[$field] ?? null)) {
                $data[$field] = $default;
            }
        }

        return $data;
    }

    /**
     * 编辑:允许只提交部分字段。非空列收到空/null 时保留原值,
     * 不把「没填」误当成「清空」;可空文本(date/city/…)仍可传空串清空。
     */
    private function normalizeForUpdate(array $data): array
    {
        foreach (array_keys(self::CREATE_DEFAULTS) as $field) {
            if (array_key_exists($field, $data) && $this->isBlank($data[$field])) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
