<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\RollbackRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\GrayReleaseService;

/**
 * 灰度发布 / 自动回滚接口(v3.4 步骤 8,计划 §4.10)—— 挂 internal.key(平台端运维用)。
 *
 * 范围:开启灰度 → 上报批次指标(驱动 evaluate)→ 自动/手动回滚。
 * **不做**"按比例自动筛选下发目标" —— 下发由各自通道发起,灰度只管评估与回滚。
 *
 * 自动回滚有两条触发路径:
 *   1. 设备回报后由 DeployTaskService 自驱(本项目无 cron,这是主路径);
 *   2. 平台端主动 POST metrics(拿到明确计数时最可控)。
 */
class GrayReleaseController extends APIController
{
    public function __construct(protected GrayReleaseService $gray) {}

    /**
     * 开启一次灰度。
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'skill_version_id'   => [
                'required',
                'integer',
                // 必须指向一个**已发布**的版本:草稿/归档版本的灰度没有意义 ——
                // 它压根不会被下发,于是指标恒为 0、评估恒 0、回滚靶子恒空,
                // 而平台端看到的却是一条"正在灰度"的记录。
                Rule::exists('skill_versions', 'id')->where('status', SkillVersion::STATUS_PUBLISHED),
            ],
            'strategy'           => ['nullable', 'array'],
            'strategy.percent'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'strategy.whitelist' => ['nullable', 'array'],
            'batch_no'           => ['nullable', 'string', 'max:40'],
        ], [
            'skill_version_id.exists' => '技能版本不存在或尚未发布。',
        ]);

        $release = $this->gray->start(
            (int) $payload['skill_version_id'],
            $payload['strategy'] ?? ['percent' => 5],
            $payload['batch_no'] ?? null,
            // P10:发起者留痕(调用方自报,见 APIController::internalOperator)
            $this->internalOperator($request),
            $request->ip(),
        );

        return response()->json([
            'message' => '灰度已开启。',
            'data'    => $this->format($release),
        ], 201);
    }

    /**
     * 查灰度状态与指标。
     */
    public function show(int $id): JsonResponse
    {
        $release = GrayRelease::find($id);

        if (! $release) {
            return $this->fail(ApiErrorCode::GRAY_RELEASE_NOT_FOUND, '灰度不存在。', 404);
        }

        return response()->json(['data' => $this->format($release)]);
    }

    /**
     * 上报批次指标 → 评估(可能触发暂停 / 自动回滚)。
     */
    public function metrics(Request $request, int $id): JsonResponse
    {
        $release = GrayRelease::find($id);

        if (! $release) {
            return $this->fail(ApiErrorCode::GRAY_RELEASE_NOT_FOUND, '灰度不存在。', 404);
        }

        $payload = $request->validate([
            'installs'  => ['required', 'integer', 'min:0'],
            // 跨字段:成功数不可能超过安装数。缺这条时 `GrayReleaseService::recordMetric()`
            // 会算出 >100% 的 success_rate,而成功率又是自动回滚的判据 —— 一个脏计数
            // 能把灰度判成"健康"或"该回滚",两种都是错的。
            'successes' => ['required', 'integer', 'min:0', 'lte:installs'],
            'failures'  => ['required', 'integer', 'min:0'],
        ], [
            'successes.lte' => '成功数不能大于安装数。',
        ]);

        $result = $this->gray->recordMetric(
            $release->id,
            (int) $payload['installs'],
            (int) $payload['successes'],
            (int) $payload['failures'],
        );

        return response()->json([
            'message' => '指标已记录。',
            'data'    => ($result ?? []) + ['release' => $this->format($release->fresh())],
        ]);
    }

    /**
     * 人工回滚(trigger=manual)。
     */
    public function abort(Request $request, int $id): JsonResponse
    {
        $release = GrayRelease::find($id);

        if (! $release) {
            return $this->fail(ApiErrorCode::GRAY_RELEASE_NOT_FOUND, '灰度不存在。', 404);
        }

        $payload = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $affected = $this->gray->abort(
            $release,
            $payload['reason'] ?? '人工回滚',
            'manual',
            // P10:人工回滚必须记到"人" —— 回滚是拿别人的设备执行动作
            $this->internalOperator($request),
            $request->ip(),
        );

        return response()->json([
            'message' => '已回滚。',
            'data'    => [
                'affected_devices' => $affected,
                'release'          => $this->format($release->fresh()),
            ],
        ]);
    }

    protected function format(GrayRelease $r): array
    {
        return [
            'id'               => $r->id,
            'skill_version_id' => $r->skill_version_id,
            'batch_no'         => $r->batch_no,
            'status'           => $r->status,
            'strategy'         => $r->strategy,
            'metrics'          => $r->metrics,
            'rollback_count'   => RollbackRecord::query()->where('gray_release_id', $r->id)->count(),
            'created_at'       => optional($r->created_at)->toDateTimeString(),
        ];
    }
}
