<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SkillReview;
use Webkul\Shop\Services\Ops\SkillReviewService;

/**
 * 技能评价 —— 用户侧入口（加固计划 §四 A1，增长功能）。
 *
 * 三个口径，与相邻接口刻意不同：
 *   · **买了才能评**（`skill_entitlements`）—— 评价是公开内容，无门槛就会变成
 *     刷分/拉踩的战场（与举报"不要求持有"相反，理由见 SkillReviewService）。
 *   · **一人一技能一条**（库级唯一键兜底），第二次返回 `REVIEW_ALREADY_EXISTS`。
 *   · **内容安全命中不发布**：照 `SkillReviewService` 的机器判定落
 *     visible / hidden / pending 三态，**绝不由列默认值决定可见性**（迁移 000007 的立场）。
 *     命中违规不是"拒收"而是"落档 + 不可见" —— 词表有误判，留档人工才改得回来。
 *
 * 上限（长度/附件）取自模型常量，客户端与后台共用同一处数字，不在这里另写。
 */
class SkillReviewController extends APIController
{
    public function __construct(protected SkillReviewService $reviews) {}

    /**
     * 提交评价。
     *
     * 201 = 已受理（`data.status` 说明是否可见）；422 = 请求本身不合法
     * （没买过 / 已经评过 / 参数越界）。
     */
    public function store(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();

        $data = $request->validate([
            'skill_id'     => ['required', 'integer', 'min:1'],
            'rating'       => ['required', 'integer', 'min:'.SkillReview::RATING_MIN, 'max:'.SkillReview::RATING_MAX],
            'title'        => ['required', 'string', 'max:'.SkillReview::TITLE_MAX],
            'content'      => ['required', 'string', 'max:'.SkillReview::CONTENT_MAX],
            'is_anonymous' => ['nullable', 'boolean'],
            'media'        => ['nullable', 'array', 'max:'.SkillReview::MEDIA_MAX],
            'media.*'      => [
                'file',
                'mimetypes:'.implode(',', SkillReview::MEDIA_MIMES),
                'max:'.SkillReview::MEDIA_MAX_KB,
            ],
        ], [
            'media.max'   => '最多上传 '.SkillReview::MEDIA_MAX.' 张图片。',
            'media.*.max' => '单张图片不能超过 '.(SkillReview::MEDIA_MAX_KB / 1024).'MB。',
        ]);

        $skillId = (int) $data['skill_id'];

        // 买了才能评（与举报相反的口径 —— 举报谁都能报，评价只认买过的人）
        if (! $this->reviews->owns($customer->id, $skillId)) {
            return $this->reject(ApiErrorCode::SKILL_NOT_OWNED, '只有购买过该技能才能评价。');
        }

        ['review' => $review, 'duplicate' => $duplicate] = $this->reviews->submit(
            $customer->id,
            $skillId,
            $data,
            $request->file('media') ?? [],
        );

        // 一人一技能一条：第二次不是"新建失败"，是"你已经评过了"，故明确告知而不是静默接受
        if ($duplicate) {
            return $this->reject(
                ApiErrorCode::REVIEW_ALREADY_EXISTS,
                '你已经评价过这个技能了。',
                ['data' => ['id' => $review->id, 'status' => $review->status]],
            );
        }

        return $this->ok(
            [
                'id'       => $review->id,
                'skill_id' => $review->skill_id,
                'rating'   => $review->rating,
                'status'   => $review->status,
                'visible'  => $review->isVisible(),
                'media'    => $review->media ?? [],
            ],
            $this->messageFor($review->status),
        )->setStatusCode(201);
    }

    /**
     * 三态各自的告知文案 —— 用户必须知道"我这条件现在别人看不看得见、为什么"。
     */
    protected function messageFor(string $status): string
    {
        return match ($status) {
            SkillReview::STATUS_VISIBLE => '评价已发布，谢谢你的反馈。',
            SkillReview::STATUS_HIDDEN  => '评价含违规内容，已暂不显示，我们会人工复核。',
            default                     => '评价已提交，需人工复核后显示。',
        };
    }
}
