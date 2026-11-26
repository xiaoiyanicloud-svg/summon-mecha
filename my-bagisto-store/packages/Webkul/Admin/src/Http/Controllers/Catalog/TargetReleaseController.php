<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillTargetReleaseEvent;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillCompile\SkillTargetReleaseService;

/**
 * 目标发布审核台（WBS B3）。
 *
 * 与「审核台」分工：审核台判**版本准入**；本台判**某一机型的具体构建产物**。
 * 每次 approve/reject/revoke 都通过 SkillTargetReleaseService 做快照校验，
 * 并只追加 TargetReleaseEvent，不覆盖历史。
 *
 * ACL：
 *   catalog.target-releases          查看列表/详情
 *   catalog.target-releases.approve  通过
 *   catalog.target-releases.reject   驳回
 *   catalog.target-releases.revoke   撤销
 */
class TargetReleaseController extends Controller
{
    public function __construct(
        protected SkillTargetReleaseService $releases,
        protected OperationLogger $oplog,
    ) {}

    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $model = (string) $request->query('model_code', '');
        $seller = $request->query('seller_id');

        $releases = SkillTargetRelease::query()
            ->with(['version', 'artifactAsset'])
            ->when($status !== '', fn ($q) => $q->where('review_status', $status))
            ->when($model !== '', fn ($q) => $q->where('target_model_code', $model))
            ->when($seller !== null && $seller !== '', function ($q) use ($seller) {
                $q->whereHas('version', fn ($v) => $v->where('seller_id', (int) $seller));
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin::catalog.target-releases.index', [
            'releases' => $releases,
            'status'   => $status,
            'model'    => $model,
            'seller'   => $seller,
            'statuses' => [
                SkillTargetRelease::STATUS_PENDING,
                SkillTargetRelease::STATUS_APPROVED,
                SkillTargetRelease::STATUS_REJECTED,
                SkillTargetRelease::STATUS_REVOKED,
            ],
        ]);
    }

    public function show(int $id)
    {
        $release = SkillTargetRelease::with(['version', 'artifactAsset'])->findOrFail($id);

        $events = SkillTargetReleaseEvent::query()
            ->where('skill_version_id', $release->skill_version_id)
            ->where('target_model_code', $release->target_model_code)
            ->orderByDesc('id')
            ->get();

        return view('admin::catalog.target-releases.show', compact('release', 'events'));
    }

    public function approve(Request $request, int $id)
    {
        $request->validate(['seller_id' => 'nullable|integer']);

        return $this->transition($request, $id, 'approve');
    }

    public function reject(Request $request, int $id)
    {
        $request->validate([
            'reason'    => 'required|string|max:1000',
            'seller_id' => 'nullable|integer',
        ]);

        return $this->transition($request, $id, 'reject');
    }

    public function revoke(Request $request, int $id)
    {
        $request->validate([
            'reason'    => 'required|string|max:1000',
            'seller_id' => 'nullable|integer',
        ]);

        return $this->transition($request, $id, 'revoke');
    }

    protected function transition(Request $request, int $id, string $action)
    {
        $release = SkillTargetRelease::with('version')->find($id);

        if (! $release) {
            return $this->respond($request, null, '目标发布不存在。', false, 404);
        }

        if ($scopeError = $this->sellerScopeError($request, $release)) {
            return $scopeError;
        }

        $actorId = (int) auth()->guard('admin')->user()->id;
        $reason = trim((string) $request->input('reason', ''));

        try {
            $updated = match ($action) {
                'approve' => $this->releases->approve($id, $actorId),
                'reject'  => $this->releases->reject($id, $actorId, $reason),
                'revoke'  => $this->releases->revoke($id, $actorId, $reason),
                default   => throw new \InvalidArgumentException('UNKNOWN_TARGET_RELEASE_ACTION'),
            };
        } catch (\DomainException $exception) {
            return $this->respond($request, $release, $exception->getMessage(), false, 409);
        }

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $actorId,
            'skill.target_release.'.$action,
            'skill_target_release',
            $id,
            [
                'build_key'         => $release->build_key,
                'target_model_code' => $release->target_model_code,
                'review_status'     => $updated->review_status,
                'reason'            => $reason !== '' ? $reason : null,
            ],
        );

        return $this->respond($request, $updated, '目标发布已'.$this->label($action).'。', true, 200);
    }

    /**
     * 可选的 seller 作用域校验：显式传入 seller_id 时必须与版本归属一致。
     * Admin 本身是平台级身份；这条校验用于拒绝“拿 A seller 的作用域去操作 B”的越权调用。
     */
    protected function sellerScopeError(Request $request, SkillTargetRelease $release): ?JsonResponse
    {
        $seller = $request->input('seller_id');

        if ($seller === null || $seller === '') {
            return null;
        }

        $versionSeller = (int) ($release->version?->seller_id ?? 0);

        if ((int) $seller !== $versionSeller) {
            return response()->json(['message' => '跨 seller 越权：该目标发布不属于指定 seller。'], 409);
        }

        return null;
    }

    protected function respond(Request $request, ?SkillTargetRelease $release, string $message, bool $success, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'status'  => $release?->review_status,
            ], $status);
        }

        $redirect = $release
            ? redirect()->route('admin.catalog.target-releases.show', $release->id)
            : redirect()->route('admin.catalog.target-releases.index');

        return $redirect->with($success ? 'success' : 'error', $message);
    }

    protected function label(string $action): string
    {
        return match ($action) {
            'approve' => '通过',
            'reject'  => '驳回',
            'revoke'  => '撤销',
            default   => '处理',
        };
    }
}
