<?php

namespace Webkul\Admin\Http\Controllers\Ops;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Ops\AdminAlertDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Notification;

/**
 * 平台告警收件箱。
 *
 * 定时任务（`ops:alert-*`）扫出来的问题落在这里 —— 没有这个界面的话，
 * 那些任务是往一个没人看的信箱投信。
 *
 * ⚠ **只提供「标记已读」，没有「删除」**：告警是系统产出的事实记录。
 *   待办清了就是清了，能对得上账；能删的话，"当时到底报没报过"就查不清了。
 */
class AlertController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(AdminAlertDataGrid::class)->process();
        }

        $unread = Notification::query()
            ->where('recipient_type', 'admin')
            ->where('recipient_id', auth()->guard('admin')->id())
            ->whereNull('read_at')
            ->count();

        return view('admin::ops.alerts.index', compact('unread'));
    }

    /**
     * 标记单条已读。
     *
     * ⚠ 只能标记**发给自己的**那条 —— 否则任何管理员都能把别人的待办消掉，
     *   那就等于可以让问题从别人的视野里消失。
     */
    public function read(int $id)
    {
        $notification = Notification::query()
            ->where('recipient_type', 'admin')
            ->where('recipient_id', auth()->guard('admin')->id())
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json(['message' => '告警不存在。'], 404);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json(['message' => '已标记已读。']);
    }

    /**
     * 全部标记已读。
     */
    public function readAll(Request $request)
    {
        $affected = Notification::query()
            ->where('recipient_type', 'admin')
            ->where('recipient_id', auth()->guard('admin')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        session()->flash('success', $affected > 0 ? "已标记 {$affected} 条为已读。" : '没有未读告警。');

        return redirect()->route('admin.alerts.index');
    }
}
