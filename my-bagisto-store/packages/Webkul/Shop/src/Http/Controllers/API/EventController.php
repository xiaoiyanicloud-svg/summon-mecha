<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;

/**
 * 赛事(Event)接口 —— 生态中心「赛事活动」tab 的平台级闭环:
 * 后台「赛事管理」发布赛事 → 前台展示 → 用户真实报名(event_registrations 落库) → 后台可查。
 */
class EventController extends APIController
{
    /**
     * 赛事列表:按 sort 降序(热门权重),返回 EcoCenter 需要的全字段。
     */
    public function index(): JsonResponse
    {
        $events = Event::orderBy('sort', 'desc')->orderBy('id', 'asc')->get()->map(fn ($e) => $this->format($e));

        return response()->json(['data' => $events]);
    }

    public function show(int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['message' => '赛事不存在。'], 404);
        }

        return response()->json(['data' => $this->format($event)]);
    }

    /**
     * 赛事报名(闭环转化):游客/登录用户均可,落 event_registrations。
     */
    public function register(Request $request, int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['success' => false, 'message' => '赛事不存在。'], 404);
        }

        if ($event->status === 'ended') {
            return response()->json(['success' => false, 'message' => '该赛事已结束报名。'], 422);
        }

        $request->validate([
            'name'  => 'required|string',
            'phone' => 'required|string',
            'city'  => 'nullable|string',
            'note'  => 'nullable|string',
        ]);

        $customer = auth()->guard('customer')->user();

        // 同一人同一赛事只报一次
        $exists = EventRegistration::where('event_id', $id)
            ->where('phone', $request->input('phone'))
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => '该手机号已报名过此赛事。'], 422);
        }

        $registration = EventRegistration::create([
            'event_id'    => $id,
            'customer_id' => $customer?->id,
            'name'        => $request->input('name'),
            'phone'       => $request->input('phone'),
            'city'        => $request->input('city', ''),
            'note'        => $request->input('note', ''),
        ]);

        return response()->json([
            'success'       => true,
            'message'       => '报名成功',
            'registration'  => ['id' => $registration->id, 'event' => $event->name],
        ]);
    }

    private function format($e): array
    {
        return [
            'id'        => $e->id,
            'name'      => $e->name,
            'type'      => $e->type,
            'status'    => $e->status,
            'date'      => $e->date,
            'dateShort' => $e->date,
            'city'      => $e->city,
            'location'  => $e->location,
            'organizer' => $e->organizer,
            'hot'       => $e->hot,
            'teams'     => $e->teams,
            'prize'     => $e->prize,
            'schedule'  => $e->schedule,
            'history'   => $e->history,
            'detail'    => $e->detail,
            'icon'      => $e->icon,
            'bg'        => $e->bg_color,
            'fav'       => false,
        ];
    }
}
