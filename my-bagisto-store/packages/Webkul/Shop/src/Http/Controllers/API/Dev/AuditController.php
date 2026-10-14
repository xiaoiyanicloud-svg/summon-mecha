<?php

namespace Webkul\Shop\Http\Controllers\API\Dev;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperSkillService;

/**
 * `/api/dev/audits` —— 审核状态与驳回理由（v3.4 步骤 9，计划 C 组）。
 *
 * 驳回理由对开发者可见（对标 App Store Connect 的 Resolution Center）——
 * 这是"开发者自己能改到过"的前提，否则只能靠群里问人。
 */
class AuditController extends APIController
{
    public function __construct(protected DeveloperSkillService $skills) {}

    public function index(Request $request): JsonResponse
    {
        $records = $this->skills->listAudits($this->profile($request));

        return response()->json([
            'message' => 'ok',
            'data'    => $records->map(fn (AuditRecord $r) => [
                'id'              => $r->id,
                'skill_id'        => $r->skill_id,
                'version'         => $r->version,
                'layer'           => $r->layer,
                'result'          => $r->result,
                'reason_category' => $r->reason_category,
                'reason_detail'   => $r->reason_detail,
                'suggestion'      => $r->suggestion,
                'submitted_at'    => optional($r->submitted_at)->toDateTimeString(),
                'audited_at'      => optional($r->audited_at)->toDateTimeString(),
            ])->values(),
        ]);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        return $request->attributes->get('developer_profile');
    }
}
