<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\SkillReview;
use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\ContentScanRecorder;
use Webkul\Shop\Services\ContentSafety\ScanResult;

/**
 * 评价受理 —— 加固计划 §四 A1（增长功能）。
 *
 * 与举报同源共用内容安全链路，但**口径不同，别顺手统一**：
 *
 * | | 评价（SkillReviewService） | 举报（SkillReportService） |
 * |---|---|---|
 * | 归属 | **要求持有该技能**（买了才能评） | 不要求持有（谁都能报） |
 * | 命中违禁词 | **不可见**（hidden，留档待人工改判） | 照收（引述违规是举报的必需） |
 * | 重复 | 一人一技能一条（库级唯一键） | 同一对象未处置期间只受理一条 |
 * | 附件 | 带图 → 待人工看（本层只扫文本，图过不了词表） | 本轮不收附件 |
 *
 * 为什么评价要求持有：评价是**公开内容**，无门槛就会变成刷分/拉踩的战场；
 * 而"买过的人的评价"才是这个字段对后来者的价值所在。代价是评价量少 ——
 * 这是刻意的取舍（想做"未购买晒单"要另立字段与审核策略，不是把这里放开）。
 *
 * 状态一律**显式写**，不靠列默认值（迁移 000007 已把默认改成 pending，
 * 立场就是"要可见必须有人真的判断过"）：
 *   命中违规 → `hidden`；扫描器没跑成 → `pending`；扫过没问题 → `visible`。
 */
class SkillReviewService
{
    public function __construct(
        protected ContentSafetyManager $contentSafety,
        protected ContentScanRecorder $scanRecorder,
    ) {}

    /**
     * 提交评价。
     *
     * @param  array<int, UploadedFile>  $media  已通过校验的附件（上限见 SkillReview::MEDIA_MAX）
     * @return array{review: SkillReview, duplicate: bool} duplicate=true 表示"这个技能你已经评过"，返回既有那条，调用方按 422 告知用户
     */
    public function submit(int $customerId, int $skillId, array $payload, array $media = []): array
    {
        // 快路径：一人一技能一条（唯一键 uk_skill_review_customer_skill 兜底）
        if ($existing = $this->existing($customerId, $skillId)) {
            return ['review' => $existing, 'duplicate' => true];
        }

        $scan = $this->contentSafety->scanner()->scan($this->scanText($payload));

        $attempts = 0;

        while (true) {
            try {
                $review = DB::transaction(function () use ($customerId, $skillId, $payload, $media, $scan) {
                    $review = SkillReview::create([
                        'skill_id'     => $skillId,
                        'customer_id'  => $customerId,
                        'title'        => $payload['title'],
                        'rating'       => $payload['rating'],
                        'content'      => $payload['content'],
                        'is_anonymous' => (bool) ($payload['is_anonymous'] ?? false),
                        'status'       => $this->statusFor($scan, $media),
                    ]);

                    if ($media !== []) {
                        $review->update(['media' => $this->storeMedia($media)]);
                    }

                    return $review;
                });

                $this->recordScan($review, $scan);

                return ['review' => $review, 'duplicate' => false];
            } catch (QueryException $e) {
                // 唯一键冲突 = 并发的同(客户,技能)请求刚落了单 → 取回它，按"已评过"返回。
                // 与 A2 工单、A1 举报同一套路：唯一键是兜底，不是期望路径。
                if (! $this->isDuplicateKey($e) || ++$attempts >= 3) {
                    throw $e;
                }

                if ($existing = $this->existing($customerId, $skillId)) {
                    return ['review' => $existing, 'duplicate' => true];
                }
            }
        }
    }

    /**
     * 管理端处置（可见 / 不可见）。
     *
     * ⚠ 值域不含 `pending`：处置只能向前。把已结案的退回"待人工看"会让
     *   "机审没跑成"与"人已经判过"两种状态混在一起，事后分不清谁判的。
     *
     * ⚠ `visible`（恢复显示）必须带理由 —— 与「内容安全处置台」"放行必须写理由"、
     *   「举报处置台」"驳回必须写理由"同一条原则：把风险收回来自己扛，要留下凭据。
     *   **谁处置的**记在 `operation_log`（actor_id），不在这张表里另开列 ——
     *   审计的事实源只有一处，两处就会不一致。
     *
     * @param  string  $status  取值 {@see SkillReview::STATUS_LABELS} 中的 visible / hidden
     */
    public function moderate(int $reviewId, string $status, ?string $note): SkillReview
    {
        $review = SkillReview::findOrFail($reviewId);

        $review->update([
            'status'          => $status,
            'moderation_note' => $note,
        ]);

        return $review;
    }

    /**
     * 该客户是否持有这个技能（`skill_entitlements`）—— 买了才能评。
     */
    public function owns(int $customerId, int $skillId): bool
    {
        return DB::table('skill_entitlements')
            ->where('customer_id', $customerId)
            ->where('product_id', $skillId)
            ->exists();
    }

    public function existing(int $customerId, int $skillId): ?SkillReview
    {
        return SkillReview::query()
            ->where('customer_id', $customerId)
            ->where('skill_id', $skillId)
            ->first();
    }

    /**
     * 机器判定 → 状态。**这是"能不能被看见"的唯一决定处**。
     *
     * @param  array<int, UploadedFile>  $media
     */
    protected function statusFor(ScanResult $scan, array $media = []): string
    {
        if ($scan->isViolation()) {
            // 不可见，但**留档**：词表是有误判的（LocalKeywordScanner 自己也承认），
            // 留一条 hidden 记录，人工才发现得了误判、才改判得回来。
            return SkillReview::STATUS_HIDDEN;
        }

        if ($scan->degraded) {
            // 「没查」≠「合规」：扫描器没跑成（词表缺失/驱动不可用）→ 等人看
            return SkillReview::STATUS_PENDING;
        }

        // 「机器扫过了」和「这条内容被查过了」不是一回事：本层内容安全**只扫文本**
        // （见 ContentScanner / LocalKeywordScanner 的能力边界）。带图评价若直接可见，
        // 等于图片零审核就公开 —— 那正是"先审后发"最不该漏的地方。
        // 故：带图 → pending，交人过一眼；不带图才由机器直接放行。
        if ($media !== []) {
            return SkillReview::STATUS_PENDING;
        }

        return SkillReview::STATUS_VISIBLE;
    }

    /**
     * 附件落盘，返回相对路径（进 `media` json）。
     *
     * ⚠ 落盘放在事务内：先落盘再写库，一旦写库失败会留下孤儿文件；反过来则是
     *   库里存着指向不存在文件的路径（对用户可见的坏数据）。两害相权取前者 ——
     *   孤儿文件只是磁盘占用，坏路径是用户能看到的破图。
     */
    protected function storeMedia(array $files): array
    {
        $paths = [];

        foreach ($files as $file) {
            $path = $file->store('skill-reviews');

            // store() 失败会返回 false —— 静默丢掉用户的照片比报错更坏（用户以为传上去了）
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('评价附件保存失败，请重试。');
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * 留痕到 `content_security_record`（标题 + 正文一起扫、一起留）。
     */
    protected function recordScan(SkillReview $review, ScanResult $scan): void
    {
        $this->scanRecorder->record(
            ContentSecurityRecord::TARGET_REVIEW,
            $review->id,
            $scan,
            'text',
            [
                'skill_id'  => $review->skill_id,
                'author_id' => $review->customer_id,
                'driver'    => $this->contentSafety->driver(),
            ]
        );
    }

    /**
     * 扫描文本 = 标题 + 正文。
     *
     * ⚠ 标题必须一起扫：它和正文一样对外可见，只扫正文等于留了一个一字不改的绕过口
     *   （把违规文案挪到标题就过审了）。
     */
    protected function scanText(array $payload): string
    {
        return trim(((string) ($payload['title'] ?? ''))."\n".((string) ($payload['content'] ?? '')));
    }

    /**
     * 唯一键冲突（MySQL 1062 / SQLite 唯一约束）—— 与 `TicketController::isDuplicateKey` 同源。
     */
    protected function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
