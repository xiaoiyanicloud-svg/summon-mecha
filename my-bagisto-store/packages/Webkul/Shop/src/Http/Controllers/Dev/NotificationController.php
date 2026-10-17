<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\Notification;

/**
 * 开发者门户 · 通知收件箱（任务卡 D-4）。
 *
 * 收件人是**开发者本人的 customer 账号**（见 `LifecycleNotifier::customerIdForSkillVersion`
 * 的注释：开发者就是那个客户，用同一批 `notification` 行，用户侧与门户读同一份数据）。
 *
 * ⚠ 三重过滤（recipient_type + recipient_id + 可见类型前缀 `skill.%` / `realname.%`）**全部在查询层**完成
 *   —— 收件人过滤丢给 PHP 会把"他人的通知 id"变成可枚举的存在性预言机。查不到一律 404
 *   （不用 403：403 等于告诉对方"这条 id 存在但不属于你"）。过滤的口径收敛在
 *   `Notification::scopeDeveloperInbox`，本控制器与顶栏角标共用同一把尺子。
 */
class NotificationController extends Controller
{
    /**
     * 我的通知列表（默认只给最近 50 条，与 API 侧 NotificationController 同口径）。
     */
    public function index(Request $request)
    {
        $notifications = Notification::query()
            ->developerInbox($this->customerId())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('shop::dev.notifications.index', [
            'notifications' => $notifications,
            'unread'        => $notifications->whereNull('read_at')->count(),
        ]);
    }

    /**
     * 标记一条通知已读。
     *
     * 归属过滤（含可见类型前缀 `skill.%` / `realname.%`）在查询层：他人的通知、买家类通知都**查不到** → 404。
     * 用 `whereKey` 而不是 `find`，是为了让"这条 id 在不在我的可见范围"由同一段
     * 查询来回答，避免"先 find 再判归属"那种能被时序/作用域绕过两步写法。
     */
    public function read(Request $request, int $id)
    {
        $notification = Notification::query()
            ->developerInbox($this->customerId())
            ->whereKey($id)
            ->first();

        if (! $notification) {
            abort(404);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return back()->with('success', '已标记为已读。');
    }

    /**
     * 当前登录开发者的 customer_id —— `developer` 中间件已保证已登录，故不必判空。
     */
    protected function customerId(): int
    {
        return (int) auth('developer')->user()->id;
    }
}
