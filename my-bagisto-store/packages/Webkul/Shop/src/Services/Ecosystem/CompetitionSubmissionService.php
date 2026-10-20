<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmission;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-05 · 作品草稿 / 版本 / 提交 / 补正。
 *
 * 规则：
 *   · 只有 frozen 快照中的队长可以写；队员只读；
 *   · 截止时间在取得 entry/submission 锁后重新读取，不用请求开始时快照；
 *   · 提交后版本不可变；补正必须新建 version_no + 1；
 *   · 截止时间未配置 = 规则未确认，拒绝提交（不猜默认）。
 *
 * 公开展示不在本服务；G3-11 才允许把获授权版本输出到公开域。
 */
class CompetitionSubmissionService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * 读取/创建作品主体。创建空主体不等于创建版本。
     */
    public function workspace(CompetitionTeam $team, CompetitionEntry $entry, Customer $customer): array
    {
        // 队员只读也能打开作品页；写动作在 saveDraft/submit 内单独要求队长。
        $this->assertEntryUsable($team, $entry, $customer, false);

        $submission = CompetitionSubmission::query()
            ->where('entry_id', $entry->getKey())
            ->first();

        if ($submission === null) {
            // GET 不写库：返回一个未持久化的空主体，第一次保存草稿时才创建。
            $track = CompetitionTrack::query()->findOrFail($entry->track_id);
            $submission = new CompetitionSubmission();
            $submission->fill([
                'entry_id'    => (int) $entry->getKey(),
                'track_id'    => (int) $entry->track_id,
                'status'      => CompetitionSubmission::STATUS_DRAFT,
                'deadline_at' => $track->submission_deadline_at,
                'row_version' => 0,
            ]);

            return ['submission' => $submission, 'versions' => collect()];
        }

        return [
            'submission' => $submission,
            'versions'   => CompetitionSubmissionVersion::query()
                ->where('submission_id', $submission->getKey())
                ->orderByDesc('version_no')
                ->get(),
        ];
    }

    /**
     * 保存草稿：当前版本是 draft 则更新，否则新建 version_no + 1。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(
        CompetitionTeam $team,
        CompetitionEntry $entry,
        Customer $customer,
        array $data,
        ?int $expectedRowVersion = null,
    ): CompetitionSubmissionVersion {
        $this->rules->assertWriteEnabled();
        $this->assertEntryUsable($team, $entry, $customer, true);

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 160) {
            throw CompetitionException::invalid('作品标题必填且不得超过 160 个字符。');
        }

        $summary = isset($data['summary']) ? trim((string) $data['summary']) : null;

        if ($summary !== null && mb_strlen($summary) > 500) {
            throw CompetitionException::invalid('作品摘要不得超过 500 个字符。');
        }

        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        if ($description !== null && mb_strlen($description) > 20000) {
            throw CompetitionException::invalid('作品描述不得超过 20000 个字符。');
        }

        $repoUrl = $this->safeUrl($data['repo_url'] ?? null, '代码仓库地址');
        $videoUrl = $this->safeUrl($data['video_url'] ?? null, '演示视频地址');

        return DB::transaction(function () use (
            $team,
            $entry,
            $customer,
            $title,
            $summary,
            $description,
            $repoUrl,
            $videoUrl,
            $expectedRowVersion,
        ): CompetitionSubmissionVersion {
            $lockedEntry = CompetitionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $submission = $this->lockOrCreateSubmission($lockedEntry);

            if ($expectedRowVersion !== null && (int) $submission->row_version !== $expectedRowVersion) {
                throw CompetitionException::submissionVersionStale();
            }

            $this->assertDeadline($lockedEntry, $submission);

            $current = $submission->current_version_id !== null
                ? CompetitionSubmissionVersion::query()
                    ->whereKey($submission->current_version_id)
                    ->where('submission_id', $submission->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($current !== null && (string) $current->status === CompetitionSubmissionVersion::STATUS_SUBMITTED) {
                $track = CompetitionTrack::query()->findOrFail($lockedEntry->track_id);

                if (! (bool) $track->allow_correction) {
                    throw CompetitionException::submissionImmutable('该赛项不允许补正。');
                }

                $current = null;
            }

            if ($current === null) {
                $nextNo = (int) CompetitionSubmissionVersion::query()
                    ->where('submission_id', $submission->getKey())
                    ->max('version_no') + 1;

                $current = new CompetitionSubmissionVersion();
                $current->submission_id = (int) $submission->getKey();
                $current->version_no = $nextNo;
                $current->status = CompetitionSubmissionVersion::STATUS_DRAFT;
                $current->created_by_customer_id = (int) $customer->getKey();
                $current->row_version = 0;
            }

            $current->title = $title;
            $current->summary = $summary;
            $current->description = $description;
            $current->repo_url = $repoUrl;
            $current->video_url = $videoUrl;
            $current->content_fingerprint = $this->fingerprint($current);
            $current->row_version = (int) $current->row_version + 1;
            $current->save();

            $submission->current_version_id = (int) $current->getKey();
            $submission->status = CompetitionSubmission::STATUS_DRAFT;
            $submission->row_version = (int) $submission->row_version + 1;
            $submission->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.submission.draft_saved',
                'competition_submission_version',
                (int) $current->getKey(),
                [
                    'entry_id'      => (int) $lockedEntry->getKey(),
                    'submission_id' => (int) $submission->getKey(),
                    'version_no'    => (int) $current->version_no,
                ],
            );

            return $current->refresh();
        });
    }

    /**
     * 提交当前草稿。提交后版本不可变。
     */
    public function submit(
        CompetitionTeam $team,
        CompetitionEntry $entry,
        Customer $customer,
        ?int $expectedRowVersion = null,
    ): CompetitionSubmissionVersion {
        $this->rules->assertWriteEnabled();
        $this->assertEntryUsable($team, $entry, $customer, true);

        return DB::transaction(function () use ($entry, $customer, $expectedRowVersion): CompetitionSubmissionVersion {
            $lockedEntry = CompetitionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $submission = $this->lockOrCreateSubmission($lockedEntry);

            if ($expectedRowVersion !== null && (int) $submission->row_version !== $expectedRowVersion) {
                throw CompetitionException::submissionVersionStale();
            }

            // 等锁后重新核验截止时间：请求开始时未截止不代表取得锁时仍未截止。
            $this->assertDeadline($lockedEntry, $submission);

            $version = $submission->current_version_id !== null
                ? CompetitionSubmissionVersion::query()
                    ->whereKey($submission->current_version_id)
                    ->where('submission_id', $submission->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($version === null) {
                throw CompetitionException::submissionNotFound('还没有可提交的作品草稿。');
            }

            if ((string) $version->status !== CompetitionSubmissionVersion::STATUS_DRAFT) {
                throw CompetitionException::submissionImmutable('当前版本已提交，不能重复提交。');
            }

            if (trim((string) $version->title) === '') {
                throw CompetitionException::invalid('作品标题不能为空。');
            }

            $version->status = CompetitionSubmissionVersion::STATUS_SUBMITTED;
            $version->submitted_at = now('UTC');
            $version->row_version = (int) $version->row_version + 1;
            $version->save();

            $submission->status = CompetitionSubmission::STATUS_SUBMITTED;
            $submission->submitted_at = now('UTC');
            $submission->row_version = (int) $submission->row_version + 1;
            $submission->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.submission.submitted',
                'competition_submission_version',
                (int) $version->getKey(),
                [
                    'entry_id'      => (int) $lockedEntry->getKey(),
                    'submission_id' => (int) $submission->getKey(),
                    'version_no'    => (int) $version->version_no,
                    'fingerprint'   => (string) $version->content_fingerprint,
                ],
            );

            return $version->refresh();
        });
    }

    public function submissionForEntry(CompetitionEntry $entry): ?CompetitionSubmission
    {
        return CompetitionSubmission::query()->where('entry_id', $entry->getKey())->first();
    }

    /**
     * 调用方必须已持有 entry 行锁。
     */
    private function lockOrCreateSubmission(CompetitionEntry $entry): CompetitionSubmission
    {
        $submission = CompetitionSubmission::query()
            ->where('entry_id', $entry->getKey())
            ->lockForUpdate()
            ->first();

        if ($submission !== null) {
            return $submission;
        }

        $track = CompetitionTrack::query()->findOrFail($entry->track_id);

        $submission = new CompetitionSubmission();
        $submission->fill([
            'entry_id'    => (int) $entry->getKey(),
            'track_id'    => (int) $entry->track_id,
            'status'      => CompetitionSubmission::STATUS_DRAFT,
            'deadline_at' => $track->submission_deadline_at,
            'row_version' => 0,
        ]);
        $submission->save();

        return $submission;
    }

    private function assertEntryUsable(
        CompetitionTeam $team,
        CompetitionEntry $entry,
        Customer $customer,
        bool $write,
    ): void {
        if ((int) $entry->team_id !== (int) $team->getKey()) {
            throw CompetitionException::entryNotFound();
        }

        if (! $entry->isActive()) {
            throw CompetitionException::entryNotActive();
        }

        if ((string) $team->status !== CompetitionTeam::STATUS_ACTIVE) {
            throw CompetitionException::entryNotActive('队伍已解散。');
        }

        if (! $write) {
            return;
        }

        if ((int) $team->captain_customer_id !== (int) $customer->getKey()) {
            throw CompetitionException::teamForbidden('只有队长可以编辑或提交作品。');
        }

        if ((int) $entry->captain_customer_id !== (int) $customer->getKey()) {
            throw CompetitionException::teamForbidden('该参赛快照的提交人不是当前账号；请先完成队长移交前的确认。');
        }
    }

    private function assertDeadline(CompetitionEntry $entry, CompetitionSubmission $submission): void
    {
        $track = CompetitionTrack::query()->findOrFail($entry->track_id);
        $deadline = $track->submission_deadline_at ?? $submission->deadline_at;

        if ($deadline === null) {
            throw CompetitionException::submissionClosed('赛项未配置作品提交截止时间。');
        }

        if (Carbon::now('UTC')->greaterThan($deadline)) {
            throw CompetitionException::submissionClosed();
        }
    }

    private function safeUrl(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $url = trim((string) $value);

        if (mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw CompetitionException::invalid($label.'不是合法 URL。');
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw CompetitionException::invalid($label.'只允许 http/https。');
        }

        return $url;
    }

    private function fingerprint(CompetitionSubmissionVersion $version): string
    {
        return hash('sha256', json_encode([
            'title'       => (string) $version->title,
            'summary'     => (string) $version->summary,
            'description' => (string) $version->description,
            'repo_url'    => (string) $version->repo_url,
            'video_url'   => (string) $version->video_url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
