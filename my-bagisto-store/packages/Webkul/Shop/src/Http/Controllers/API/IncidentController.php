<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Incident\IncidentService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 事故上报 —— 用户侧（v3.4 计划 §4.19）。
 *
 * 用户报了案，平台把证据链固化下来（`IncidentService::traceFor()`），
 * 运营在后台受理、定责、结案。
 *
 * ⚠ 本轮**不接受附件上传**：不只是"没做"，是**故意不做** ——
 *   事故照片/视频属于敏感内容，要走对象存储 + 内容安全扫描 + 访问控制，
 *   而那套依赖 COS 凭证与 msgSecCheck（都还没有）。先只收文字与链接，
 *   免得开一条无人看守的上传口子。
 */
class IncidentController extends APIController
{
    public function __construct(
        protected IncidentService $incidents,
        protected OperationLogger $oplog,
    ) {}

    /**
     * 我上报过的事故。
     */
    public function index(): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $rows = Incident::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Incident $i) => [
                'id'              => $i->id,
                'incident_no'     => $i->incident_no,
                'severity'        => $i->severity,
                'severity_label'  => $i->severityLabel(),
                'status'          => $i->status,
                'status_label'    => $i->statusLabel(),
                'occurred_at'     => optional($i->occurred_at)->toDateTimeString(),
                'created_at'      => optional($i->created_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    /**
     * 上报一起事故。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $payload = $request->validate([
            'severity'    => 'required|in:'.implode(',', array_keys(Incident::SEVERITY_LABELS)),
            'description' => 'required|string|max:2000',
            'skill_id'    => 'nullable|integer',
            'device_id'   => 'nullable|integer',
            // A9 · 事故只能发生在"过去或现在"。此前仅 `date`,允许未来时间 ——
            //   一个未来的 occurred_at 会让 SLA 计时(SLA 从 occurred_at 起算)整体错乱。
            'occurred_at' => 'nullable|date|before_or_equal:now',
            // 只收文字与链接，不收文件（见类注释）
            'evidence'    => 'nullable|array|max:10',
            'evidence.*'  => 'string|max:500',
        ]);

        // ⚠ 授权边界：只允许关联**自己的**设备。
        //   不校验的话，任何人都能拿别人的 device_id 报案 ——
        //   响应里会带回该设备的型号等信息，那就是一个枚举他人设备的入口。
        if (! empty($payload['device_id'])) {
            $owned = DB::table('devices')
                ->where('id', $payload['device_id'])
                ->where('customer_id', $customer->id)
                ->exists();

            if (! $owned) {
                return $this->fail(ApiErrorCode::DEVICE_NOT_OWNED, '该设备不在你的设备列表里。', 422);
            }
        }

        // A4 · 授权边界:skill_id 必须落在**当前客户**的授权里。
        //   与上面 device_id 的校验同源。后果不止脏数据:`IncidentService::traceFor()`
        //   会按这个 product_id 去查技能版本 / 交付物 sha256 并**固化进证据链** ——
        //   不校验的话,任何人都能把一次事故"挂"到自己无关的技能版本上,污染定责依据。
        if (! empty($payload['skill_id'])) {
            $ownedSkill = DB::table('skill_entitlements')
                ->where('customer_id', $customer->id)
                ->where('product_id', $payload['skill_id'])
                ->exists();

            if (! $ownedSkill) {
                return $this->fail(ApiErrorCode::SKILL_NOT_OWNED, '该技能不在你的技能列表中。', 422);
            }
        }

        $incident = $this->incidents->report($customer->id, [
            'skill_id'    => $payload['skill_id'] ?? null,
            'device_id'   => $payload['device_id'] ?? null,
            'severity'    => $payload['severity'],
            'occurred_at' => $payload['occurred_at'] ?? null,
            'description' => $payload['description'],
            'evidence'    => $payload['evidence'] ?? null,
        ]);

        // X4 · 用户报案留痕。此前 Shop 侧零留痕,出事追责时"谁在何时报了哪起案"查无此人,
        //   且历史无法补。用 log()(非 logOrFail):留痕是旁路,不能因为审计表写不进去
        //   就把"刚出过事的人"的报案打回 —— 与 PaymentCallback 同一取舍。
        $this->oplog->log(
            OperationLog::ACTOR_CUSTOMER,
            $customer->id,
            'incident.report',
            'incident',
            $incident->id,
            [
                'incident_no' => $incident->incident_no,
                'severity'    => $incident->severity,
                'skill_id'    => $incident->skill_id,
                'device_id'   => $payload['device_id'] ?? null,
            ],
            request()->ip(),
        );

        return response()->json([
            'message' => '事故已上报，我们会尽快联系你。',
            'data'    => [
                'id'          => $incident->id,
                'incident_no' => $incident->incident_no,
                'severity'    => $incident->severity,
                'status'      => $incident->status,
            ],
        ], 201);
    }

    /**
     * 事故详情。
     *
     * ⚠ **不回 `trace`，也不回 `handling_log`**。
     *   `trace` 是证据链（含授权、订单、设备归属等内部信息），
     *   `handling_log` 是平台的内部处理记录（含定责意见）。
     *   两者都是**内部材料**，对外只给状态、描述、以及真的认定之后的结论。
     *   把它们混进响应，就是把内部定责讨论发到了当事人手里。
     */
    public function show(int $id): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $incident = Incident::query()
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->first();

        if (! $incident) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '事故记录不存在。', 404);
        }

        return response()->json([
            'data' => [
                'id'             => $incident->id,
                'incident_no'    => $incident->incident_no,
                'severity'       => $incident->severity,
                'severity_label' => $incident->severityLabel(),
                'status'         => $incident->status,
                'status_label'   => $incident->statusLabel(),
                'occurred_at'    => optional($incident->occurred_at)->toDateTimeString(),
                'description'    => $incident->description,
                // 报案人自己提交的证据，原样回给他
                'evidence'       => $incident->evidence,
                // 责任认定对报案人是重要的 —— 但没认定前不给一个"尚未认定"的假答案，给 null
                'responsibility' => $incident->responsibility === Incident::RESPONSIBILITY_UNKNOWN
                    ? null
                    : $incident->responsibilityLabel(),
                'resolved_at'    => optional($incident->resolved_at)->toDateTimeString(),
            ],
        ]);
    }
}
