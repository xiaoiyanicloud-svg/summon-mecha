<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\RejectionReason;
use Webkul\Shop\Services\SkillAudit\ReviewGate;
use Webkul\Shop\Services\SkillPackage\PackageInspector;

/**
 * 审核台 —— 「技能准入」的人工端。
 *
 * 为什么必须有它：开发者/CI 提审后，L2 人审原先**只有一个 internal.key 的 API**
 * （`POST /api/skill-supply/versions/{id}/review`）—— 运营得敲 curl 才能审。
 * 那条通道还有个结构性问题：`auditor_id` 来自**请求体**，没有服务端身份校验，
 * 即"谁审的"是可以随便填的。本控制器把 auditor 换成**登录的 Admin 身份**。
 *
 * 与 `SkillController`（版本/交付物管理）的分工：
 *   - `SkillController`  = 维护（建版本、传包、发布）
 *   - `SkillReviewController` = 判定（跑 L0、判 L2）
 * 两者写同一批表，发布动作复用 `SkillController::publish`，不另开一条发布路径。
 */
class SkillReviewController extends Controller
{
    public function __construct(
        protected ReviewGate $gate,
        protected AuditService $audit,
        protected PackageInspector $inspector,
        protected OperationLogger $oplog,
    ) {}

    /**
     * 待审队列。
     *
     * 队列的定义是「**已提审**的版本」—— 体现为生命周期状态 `in_review`（在审）或
     * `rejected`（被驳回）。⚠ 不再是 `STATUS_DRAFT`：
     *   2026-10-16 起提审会把状态从 draft 迁到 in_review（写入收敛在 `AuditService`）。
     *   队列若还按 draft 取，提审后的版本就再也进不了审核台 —— 整条供给链**静默断掉**。
     *   （下面 `$records` 的过滤再兜一层：没有任何 audit_record 的版本仍不出现在队列。）
     */
    public function index(Request $request)
    {
        $filter = (string) $request->query('status', 'pending');

        $versions = SkillVersion::query()
            ->whereIn('status', [SkillVersion::STATUS_IN_REVIEW, SkillVersion::STATUS_REJECTED])
            ->with('product')
            ->orderByDesc('id')
            ->get();

        $records = $this->auditRecordsFor($versions);

        // 开发者「申请上架」交接信号（任务卡 S-3a）—— 按版本批量取，逐版本查库会退化成 N+1。
        // 读法与门户**同源**（同一 action 常量 + 同一 target_type），两边不会漂移。
        $listingRequestedAt = $this->listingRequestedAt($versions->pluck('id')->all());

        $rows = $versions
            ->filter(fn ($v) => $records->has($this->key($v->product_id, $v->id)))
            ->map(function (SkillVersion $v) use ($records, $listingRequestedAt) {
                $gate = $this->gate->evaluate($v->product_id, $v->id);
                $own = $records->get($this->key($v->product_id, $v->id));

                return [
                    'version'      => $v,
                    'gate'         => $gate,
                    'bucket'       => $this->bucket($gate),
                    'submitted_at' => $own->max('submitted_at'),
                    'record_count' => $own->count(),
                    // 该版本开发者是否已申请上架（时间字符串或 null）—— 见 S-3a
                    'listing_requested_at' => $listingRequestedAt[$v->id] ?? null,
                ];
            })
            ->values();

        $counts = [
            'pending'  => $rows->where('bucket', 'pending')->count(),
            'rejected' => $rows->where('bucket', 'rejected')->count(),
            'approved' => $rows->where('bucket', 'approved')->count(),
            'all'      => $rows->count(),
        ];

        $visible = $filter === 'all' ? $rows : $rows->where('bucket', $filter)->values();

        return view('admin::catalog.skill-reviews.index', [
            'rows'     => $visible,
            'counts'   => $counts,
            'filter'   => $filter,
            'gate'     => $this->gate,
        ]);
    }

    /**
     * 单个版本的审核详情 —— 三层状态 + 全部审核流水 + L2 判定表单。
     */
    public function show(int $versionId)
    {
        $version = SkillVersion::with(['assets', 'product'])->find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skill-reviews.index');
        }

        $evaluation = $this->gate->evaluate($version->product_id, $version->id);

        $records = AuditRecord::query()
            ->forVersion($version->product_id, $version->id)
            ->orderByDesc('id')
            ->get();

        return view('admin::catalog.skill-reviews.show', [
            'version'      => $version,
            'evaluation'   => $evaluation,
            'records'      => $records,
            'gate'         => $this->gate,
            'reasons'      => RejectionReason::LABELS,
            'hasPackage'   => $version->assets->contains(fn ($a) => $a->carrier_type === SkillAsset::CARRIER_FILE && $a->file_path),
        ]);
    }

    /**
     * L2 人审判定。
     *
     * auditor 取自登录身份 —— 不接受请求体传入（这是与 internal.key 通道的关键差别）。
     */
    public function judge(Request $request, int $versionId)
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skill-reviews.index');
        }

        $data = $request->validate([
            'result'          => 'required|integer|in:1,2,3',
            'reason_category' => 'nullable|string|max:64',
            'reason_detail'   => 'nullable|string|max:2000',
            'suggestion'      => 'nullable|string|max:2000',
        ]);

        $result = (int) $data['result'];

        // 驳回与"待补充"必须给出理由码 —— 否则开发者收到一个无法行动的结论
        if (in_array($result, [AuditRecord::RESULT_REJECT, AuditRecord::RESULT_SUPPLEMENT], true)) {
            if (empty($data['reason_category'])) {
                session()->flash('error', '驳回或要求补充时，必须选择理由类别。');

                return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
            }

            if (! array_key_exists($data['reason_category'], RejectionReason::LABELS)) {
                session()->flash('error', '理由类别不在拒绝清单里。');

                return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
            }
        }

        $auditorId = auth()->guard('admin')->user()->id;

        $this->audit->recordLayer2(
            $version->product_id,
            $version->id,
            $result,
            $auditorId,
            $data['reason_category'] ?? null,
            $data['reason_detail'] ?? null,
            $data['suggestion'] ?? null
        );

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $auditorId,
            'skill.review.layer2',
            'skill_version',
            $version->id,
            [
                'product_id'      => $version->product_id,
                'result'          => $result,
                'reason_category' => $data['reason_category'] ?? null,
            ]
        );

        $label = match ($result) {
            AuditRecord::RESULT_PASS       => '已通过 L2 人审。',
            AuditRecord::RESULT_REJECT     => '已驳回。',
            AuditRecord::RESULT_SUPPLEMENT => '已要求补充材料。',
            default                        => '已记录。',
        };

        session()->flash('success', $label);

        return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
    }

    /**
     * 跑 L0 机审。
     *
     * 平台自营的技能由运营直接维护，没有"开发者提审"这一步 ——
     * 所以运营必须能自己触发机审，否则发布闸门会把平台自己的技能也挡死。
     *
     * ⚠ 机审的对象是**交付物里的 `.skill` 包**，取它的中立清单。
     *   交付物不是合法 `.skill` 包时**拒绝**，而不是拿版本字段拼一个清单去"通过" ——
     *   那样 L0「格式合格」就不再是真的了。
     */
    public function runLayer0(int $versionId)
    {
        $version = SkillVersion::with('assets')->find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skill-reviews.index');
        }

        $asset = $version->assets
            ->first(fn ($a) => $a->carrier_type === SkillAsset::CARRIER_FILE && $a->file_path);

        if (! $asset) {
            session()->flash('error', '该版本还没有可读的交付物文件，无法做 L0 机审。请先上传 .skill 包。');

            return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
        }

        $path = Storage::disk(SkillAsset::disk())->path($asset->file_path);

        if (! is_file($path)) {
            session()->flash('error', '交付物文件在磁盘上找不到（'.basename($asset->file_path).'），无法机审。');

            return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
        }

        $inspected = $this->inspector->inspect($path);

        if (! ($inspected['ok'] ?? false)) {
            // 不落一条"机审通过" —— 包本身不合法就不该进入内容扫描那一步
            $errors = implode('；', (array) ($inspected['errors'] ?? [])) ?: '原因未知';

            session()->flash('error', '技能包检查未通过，L0 机审中止：'.$errors
                .'（注意：平台自营技能的交付物也必须是合法 .skill 包，不能拿任意压缩包顶替）');

            return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
        }

        $result = $this->audit->submitLayer0(
            $version->product_id,
            $version->id,
            $inspected['manifest'] ?? []
        );

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'skill.review.layer0',
            'skill_version',
            $version->id,
            ['product_id' => $version->product_id, 'result' => $result['result'] ?? null]
        );

        $passed = ($result['result'] ?? null) === AuditRecord::RESULT_PASS;

        session()->flash(
            $passed ? 'success' : 'error',
            $passed ? 'L0 机审通过。' : 'L0 机审未通过：'.implode('；', (array) ($result['reasons'] ?? []))
        );

        return redirect()->route('admin.catalog.skill-reviews.show', $versionId);
    }

    // ==================== 内部 ====================

    /**
     * 一次把这些草稿版本的审核记录全查出来，按 "productId:versionId" 分组。
     * 逐版本查库在这里会退化成 N+1。
     */
    protected function auditRecordsFor($versions)
    {
        $productIds = $versions->pluck('product_id')->unique()->values()->all();
        $versionIds = $versions->pluck('id')->all();

        if ($productIds === [] || $versionIds === []) {
            return collect();
        }

        return AuditRecord::query()
            ->whereIn('skill_id', $productIds)
            ->whereIn('version', $versionIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AuditRecord $r) => $this->key($r->skill_id, $r->version));
    }

    protected function key(int $productId, int $versionId): string
    {
        return $productId.':'.$versionId;
    }

    /**
     * 一组版本的「申请上架」时间（versionId => 'Y-m-d H:i'）—— 任务卡 S-3a。
     *
     * ⚠ action / target_type 引用 `DeveloperSkillService` 的常量，**不在这里另抄字面量**：
     *   两处各写一份字符串，改了生产端忘了读端，队列上就再也标不出"开发者已申请上架"，
     *   而且**不会报错**（静默失效）。
     *
     * @param  array<int, int|string>  $versionIds
     * @return array<int, string>
     */
    protected function listingRequestedAt(array $versionIds): array
    {
        $versionIds = array_values(array_filter(array_map('intval', $versionIds)));

        if ($versionIds === []) {
            return [];
        }

        return OperationLog::query()
            ->where('action', DeveloperSkillService::ACTION_LISTING_REQUESTED)
            ->where('target_type', DeveloperSkillService::TARGET_SKILL_VERSION)
            ->whereIn('target_id', $versionIds)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (OperationLog $log) => [
                (int) $log->target_id => optional($log->created_at)->format('Y-m-d H:i'),
            ])
            ->all();
    }

    /**
     * 队列分组：
     *   approved = 闸门已过
     *   rejected = 任一层的最新判定是"已驳回"（哪怕那层不参与闸门 —— 驳回就是驳回）
     *   pending  = 其余（提审了，还没有结论）
     */
    protected function bucket(array $gate): string
    {
        if ($gate['ok']) {
            return 'approved';
        }

        foreach ($gate['layers'] as $state) {
            if ($state['result'] === AuditRecord::RESULT_REJECT) {
                return 'rejected';
            }
        }

        return 'pending';
    }
}
