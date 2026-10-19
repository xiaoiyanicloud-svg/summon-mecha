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
     *
     * G1 可信发布完成前 public_enabled=false:直接返回空列表,不查库,
     * 避免把库里的演示样例当成运营真实赛事展示。
     */
    public function index(): JsonResponse
    {
        if (! $this->publicEnabled()) {
            return response()->json(['data' => []]);
        }

        $events = Event::orderBy('sort', 'desc')->orderBy('id', 'asc')->get()->map(fn ($e) => $this->format($e));

        return response()->json(['data' => $events]);
    }

    /**
     * 未公开时统一 404,且不查库:不通过"赛事不存在/未公开"的差异泄露记录是否存在。
     */
    public function show(int $id): JsonResponse
    {
        if (! $this->publicEnabled()) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事尚未公开发布或不存在。', 404);
        }

        $event = Event::find($id);

        if (! $event) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事不存在。', 404);
        }

        return response()->json(['data' => $this->format($event)]);
    }

    /**
     * 赛事报名(闭环转化):仅 status=registering 且双重闸都打开时允许写库。
     *
     * G0-06:旧站内报名入口默认关闭。停写检查必须放在最前面 ——
     * 在任何赛事查询/参数校验/写库之前返回,避免"活动不存在"或 422
     * 之类的分支泄露赛事是否存在,也避免将来有人把校验挪到写库之后再踩坑。
     */
    public function register(Request $request, int $id): JsonResponse
    {
        if (! $this->registrationWritable()) {
            return $this->fail(
                ApiErrorCode::EVENT_REGISTRATION_DISABLED,
                (string) config('ecosystem.events.registration_disabled_reason', '站内报名正在升级，暂未开放。'),
                403
            );
        }

        $event = Event::find($id);

        if (! $event) {
            return $this->fail(ApiErrorCode::EVENT_NOT_FOUND, '赛事不存在。', 404);
        }

        if ($event->status === 'ended') {
            return $this->fail(ApiErrorCode::EVENT_ENDED, '该赛事已结束报名。', 422);
        }

        if ($event->status !== 'registering') {
            return $this->fail(ApiErrorCode::EVENT_NOT_REGISTERING, '当前不在报名阶段。', 422);
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
            return $this->fail(ApiErrorCode::EVENT_ALREADY_REGISTERED, '该手机号已报名过此赛事。', 422);
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
        // 报名状态只由后端显式下发,前端不得从 status 猜测。
        // hot/teams 是旧样例字段,没有真实数据源,公开合同里不再返回。
        $writable = $this->registrationWritable();
        $statusAllows = $e->status === 'registering';
        $reason = '';

        if (! $writable) {
            $reason = (string) config('ecosystem.events.registration_disabled_reason', '站内报名正在升级，暂未开放。');
        } elseif ($e->status === 'ended') {
            $reason = '该赛事已结束报名。';
        } elseif (! $statusAllows) {
            $reason = '当前不在报名阶段。';
        }

        return [
            'id'                  => $e->id,
            'name'                => $e->name,
            'type'                => $e->type,
            'status'              => $e->status,
            'date'                => $e->date,
            'dateShort'           => $e->date,
            'city'                => $e->city,
            'location'            => $e->location,
            'organizer'           => $e->organizer,
            'prize'               => $e->prize,
            'schedule'            => $e->schedule,
            'history'             => $e->history,
            'detail'              => $e->detail,
            'icon'                => $e->icon,
            'bg'                  => $e->bg_color,
            'can_register'        => $writable && $statusAllows,
            'registration_reason' => $reason,
        ];
    }

    /**
     * 公开读取总开关。关闭时 index/show 不查数据库。
     */
    private function publicEnabled(): bool
    {
        return (bool) config('ecosystem.events.public_enabled', false);
    }

    /**
     * 双重闸:产品开关 + G2 写能力验收都通过才允许写库。
     */
    private function registrationWritable(): bool
    {
        return (bool) config('ecosystem.events.registration_enabled', false)
            && (bool) config('ecosystem.events.registration_write_verified', false);
    }
}
