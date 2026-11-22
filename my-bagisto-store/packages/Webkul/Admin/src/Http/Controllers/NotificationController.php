<?php

namespace Webkul\Admin\Http\Controllers;

use Webkul\Notification\Repositories\NotificationRepository;
use Webkul\Shop\Models\Notification as PlatformNotification;

class NotificationController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected NotificationRepository $notificationRepository) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('admin::notifications.index');
    }

    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function getNotifications()
    {
        $params = request()->except('page');

        $searchResults = count($params)
            ? $this->notificationRepository->getParamsData($params)
            : $this->notificationRepository->getAll();

        $results = isset($searchResults['notifications']) ? $searchResults['notifications'] : $searchResults;

        $statusCount = isset($searchResults['status_counts']) ? $searchResults['status_counts'] : '';

        $orderUnread = $this->notificationRepository->where('read', 0)->count();

        $adminAlerts = [];
        $alertsUnread = 0;
        $adminId = auth()->guard('admin')->id();

        if ($adminId && function_exists('bouncer') && bouncer()->hasPermission('dashboard.alerts')) {
            $alertsUnread = PlatformNotification::query()
                ->where('recipient_type', 'admin')
                ->where('recipient_id', $adminId)
                ->whereNull('read_at')
                ->count();

            $adminAlerts = PlatformNotification::query()
                ->where('recipient_type', 'admin')
                ->where('recipient_id', $adminId)
                ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(function (PlatformNotification $item) {
                    return [
                        'id'         => $item->id,
                        'title'      => $item->title,
                        'content'    => mb_strimwidth((string) ($item->content ?? ''), 0, 72, '...'),
                        'type'       => $item->type,
                        'is_unread'  => $item->read_at === null,
                        'created_at' => $item->created_at?->format('m-d H:i') ?? '',
                        'read_url'   => route('admin.alerts.read', $item->id),
                        'index_url'  => route('admin.alerts.index'),
                    ];
                })
                ->values()
                ->all();
        }

        $combinedUnread = $orderUnread + $alertsUnread;

        return [
            'search_results'  => $results,
            'status_count'    => $statusCount,
            'total_unread'    => $combinedUnread,
            'order_unread'    => $orderUnread,
            'alerts_unread'   => $alertsUnread,
            'combined_unread' => $combinedUnread,
            'admin_alerts'    => $adminAlerts,
        ];
    }

    /**
     * Update the notification is reade or not.
     *
     * @param  int  $orderId
     * @return \Illuminate\View\View
     */
    public function viewedNotifications($orderId)
    {
        if ($notification = $this->notificationRepository->where('order_id', $orderId)->first()) {
            $notification->read = 1;

            $notification->save();

            return redirect()->route('admin.sales.orders.view', $orderId);
        }

        abort(404);
    }

    /**
     * Update the notification is reade or not.
     *
     * @return array
     */
    public function readAllNotifications()
    {
        $this->notificationRepository->where('read', 0)->update(['read' => 1]);

        $searchResults = $this->notificationRepository->getParamsData([
            'limit' => 5,
            'read'  => 0,
        ]);

        return [
            'search_results'  => $searchResults,
            'total_unread'    => $this->notificationRepository->where('read', 0)->count(),
            'success_message' => trans('admin::app.notifications.marked-success'),
        ];
    }
}
