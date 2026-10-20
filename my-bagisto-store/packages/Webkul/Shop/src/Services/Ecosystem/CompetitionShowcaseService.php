<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionAwardVersion;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionSubmission;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-11 · 作品展示授权与技能提审交接。
 *
 * 三条硬边界：
 *   1. 只有队长对**具体作品版本**显式授权后才能审核/发布；
 *   2. 公开只返回标题/摘要/队伍/奖项，不返回附件、repo、video、内部备注；
 *   3. 获奖与展示发布**不会**自动上架、发 license 或下发真机；
 *      进入技能提审是队长显式动作，走既有 DeveloperSkillService 闸门。
 */
class CompetitionShowcaseService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
        private readonly DeveloperSkillService $developerSkills,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(CompetitionTrack $track, array $data, int $adminId): CompetitionShowcase
    {
        $this->rules->assertWriteEnabled();

        $type = (string) ($data['showcase_type'] ?? CompetitionShowcase::TYPE_RECAP);
        $title = trim((string) ($data['title'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        $entryId = (int) ($data['entry_id'] ?? 0);
        $versionId = (int) ($data['submission_version_id'] ?? 0);
        $awardId = isset($data['award_id']) && (int) $data['award_id'] > 0 ? (int) $data['award_id'] : null;

        if (! in_array($type, [CompetitionShowcase::TYPE_AWARD, CompetitionShowcase::TYPE_RECAP], true)) {
            throw CompetitionException::invalid('展示类型只允许 award/recap。');
        }

        if (mb_strlen($title) < 4 || mb_strlen($title) > 160) {
            throw CompetitionException::invalid('展示标题必须 4~160 字。');
        }

        if (mb_strlen($summary) < 10 || mb_strlen($summary) > 2000) {
            throw CompetitionException::invalid('展示摘要必须 10~2000 字。');
        }

        if ($type === CompetitionShowcase::TYPE_AWARD && $awardId === null) {
            throw CompetitionException::invalid('获奖展示必须关联一个已发布奖项。');
        }

        return DB::transaction(function () use ($track, $type, $title, $summary, $entryId, $versionId, $awardId, $adminId): CompetitionShowcase {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $entry = CompetitionEntry::query()->whereKey($entryId)->lockForUpdate()->first();

            if ($entry === null
                || (int) $entry->track_id !== (int) $lockedTrack->getKey()
                || (string) $entry->status !== CompetitionEntry::STATUS_CONFIRMED) {
                throw CompetitionException::showcaseState('展示只能引用本赛项已确认的参赛条目。');
            }

            $version = CompetitionSubmissionVersion::query()->whereKey($versionId)->lockForUpdate()->first();
            $submission = $version !== null
                ? CompetitionSubmission::query()->find($version->submission_id)
                : null;

            if ($version === null
                || $submission === null
                || (int) $submission->entry_id !== (int) $entry->getKey()
                || (string) $version->status !== CompetitionSubmissionVersion::STATUS_SUBMITTED) {
                throw CompetitionException::showcaseState('展示只能引用该条目已提交的作品版本。');
            }

            if (CompetitionShowcase::query()->where('submission_version_id', $version->getKey())->exists()) {
                throw CompetitionException::showcaseState('该作品版本已经有展示记录。');
            }

            if ($type === CompetitionShowcase::TYPE_AWARD) {
                $this->assertAwardMatchesEntry($awardId, $entry);
            }

            $showcase = new CompetitionShowcase();
            $showcase->fill([
                'edition_id'            => (int) $lockedTrack->edition_id,
                'track_id'              => (int) $lockedTrack->getKey(),
                'entry_id'              => (int) $entry->getKey(),
                'submission_version_id' => (int) $version->getKey(),
                'award_id'              => $type === CompetitionShowcase::TYPE_AWARD ? $awardId : null,
                'showcase_type'         => $type,
                'title'                 => $title,
                'summary'               => $summary,
                'status'                => CompetitionShowcase::STATUS_DRAFT,
                'row_version'           => 0,
            ]);
            $showcase->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.showcase.created',
                'competition_showcase',
                (int) $showcase->getKey(),
                [
                    'track_id'   => (int) $lockedTrack->getKey(),
                    'entry_id'   => (int) $entry->getKey(),
                    'version_id' => (int) $version->getKey(),
                    'type'       => $type,
                ],
            );

            return $showcase->refresh();
        });
    }

    /**
     * 队长对具体版本授权/撤回授权。
     */
    public function setConsent(
        CompetitionShowcase $showcase,
        Customer $customer,
        bool $consent,
        int $expectedRowVersion,
    ): CompetitionShowcase {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($showcase, $customer, $consent, $expectedRowVersion): CompetitionShowcase {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $entry = CompetitionEntry::query()->find($locked->entry_id);
            $team = $entry !== null ? CompetitionTeam::query()->find($entry->team_id) : null;

            $isCaptain = $team !== null
                && (string) $team->status === CompetitionTeam::STATUS_ACTIVE
                && CompetitionTeamMember::query()
                    ->where('team_id', $team->getKey())
                    ->where('customer_id', (int) $customer->getKey())
                    ->where('role', CompetitionTeamMember::ROLE_CAPTAIN)
                    ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
                    ->exists();

            if (! $isCaptain) {
                throw CompetitionException::showcaseState('只有当前队长可以对作品授权公开展示。');
            }

            if ($consent) {
                $version = CompetitionSubmissionVersion::query()->find($locked->submission_version_id);

                if ($version === null || (string) $version->status !== CompetitionSubmissionVersion::STATUS_SUBMITTED) {
                    throw CompetitionException::consentRequired('作品版本未提交或已失效，不能授权。');
                }

                $locked->consent_at = now('UTC');
                $locked->consent_by_customer_id = (int) $customer->getKey();
                $locked->consent_fingerprint = $this->consentFingerprint($locked, $version);
                $locked->consent_scope = (array) config('ecosystem_competition.showcase.public_fields', []);
            } else {
                $locked->consent_at = null;
                $locked->consent_by_customer_id = null;
                $locked->consent_fingerprint = null;
                $locked->consent_scope = null;

                if ((string) $locked->status === CompetitionShowcase::STATUS_PUBLISHED) {
                    $locked->status = CompetitionShowcase::STATUS_WITHDRAWN;
                } elseif ((string) $locked->status === CompetitionShowcase::STATUS_APPROVED) {
                    $locked->status = CompetitionShowcase::STATUS_SUBMITTED;
                }
            }

            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                $consent ? 'competition.showcase.consent_granted' : 'competition.showcase.consent_withdrawn',
                'competition_showcase',
                (int) $locked->getKey(),
                [
                    'version_id' => (int) $locked->submission_version_id,
                    'status'     => (string) $locked->status,
                ],
            );

            return $locked->refresh();
        });
    }

    public function approve(CompetitionShowcase $showcase, int $expectedRowVersion, int $adminId): CompetitionShowcase
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($showcase, $expectedRowVersion, $adminId): CompetitionShowcase {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if (! in_array((string) $locked->status, [
                CompetitionShowcase::STATUS_DRAFT,
                CompetitionShowcase::STATUS_SUBMITTED,
            ], true)) {
                throw CompetitionException::showcaseState('只有草稿/待审展示可以审核通过。');
            }

            $this->assertConsentValid($locked);

            if ((string) $locked->showcase_type === CompetitionShowcase::TYPE_AWARD) {
                $this->assertAwardMatchesEntry((int) $locked->award_id, CompetitionEntry::query()->findOrFail($locked->entry_id));
            }

            $locked->status = CompetitionShowcase::STATUS_APPROVED;
            $locked->reviewed_by_admin_id = $adminId;
            $locked->reviewed_at = now('UTC');
            $locked->rejected_reason = null;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.showcase.approved',
                'competition_showcase',
                (int) $locked->getKey(),
                ['version_id' => (int) $locked->submission_version_id],
            );

            return $locked->refresh();
        });
    }

    public function reject(CompetitionShowcase $showcase, string $reason, int $expectedRowVersion, int $adminId): CompetitionShowcase
    {
        $this->rules->assertWriteEnabled();

        $reason = trim($reason);

        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 2000) {
            throw CompetitionException::invalid('驳回理由必须 4~2000 字。');
        }

        return DB::transaction(function () use ($showcase, $reason, $expectedRowVersion, $adminId): CompetitionShowcase {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $locked->status === CompetitionShowcase::STATUS_PUBLISHED) {
                throw CompetitionException::showcaseState('已发布展示请先撤回。');
            }

            $locked->status = CompetitionShowcase::STATUS_REJECTED;
            $locked->rejected_reason = $reason;
            $locked->reviewed_by_admin_id = $adminId;
            $locked->reviewed_at = now('UTC');
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.showcase.rejected',
                'competition_showcase',
                (int) $locked->getKey(),
                ['reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    public function publish(CompetitionShowcase $showcase, int $expectedRowVersion, int $adminId): CompetitionShowcase
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($showcase, $expectedRowVersion, $adminId): CompetitionShowcase {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $locked->status !== CompetitionShowcase::STATUS_APPROVED) {
                throw CompetitionException::showcaseState('只有已审核通过的展示可以发布。');
            }

            $this->assertConsentValid($locked);

            if ((string) $locked->showcase_type === CompetitionShowcase::TYPE_AWARD) {
                $this->assertAwardMatchesEntry((int) $locked->award_id, CompetitionEntry::query()->findOrFail($locked->entry_id));
            }

            $locked->status = CompetitionShowcase::STATUS_PUBLISHED;
            $locked->published_by_admin_id = $adminId;
            $locked->published_at = now('UTC');
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.showcase.published',
                'competition_showcase',
                (int) $locked->getKey(),
                [
                    'version_id' => (int) $locked->submission_version_id,
                    'type'       => (string) $locked->showcase_type,
                ],
            );

            return $locked->refresh();
        });
    }

    public function withdraw(CompetitionShowcase $showcase, string $reason, int $expectedRowVersion, int $adminId): CompetitionShowcase
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($showcase, $reason, $expectedRowVersion, $adminId): CompetitionShowcase {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $locked->status === CompetitionShowcase::STATUS_WITHDRAWN) {
                throw CompetitionException::showcaseState('展示已撤回。');
            }

            $locked->status = CompetitionShowcase::STATUS_WITHDRAWN;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.showcase.withdrawn',
                'competition_showcase',
                (int) $locked->getKey(),
                ['reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    /**
     * 队长显式请求进入既有技能提审流程。
     *
     * 不创建商品、不发 license、不下发真机；只调用既有闸门并记录结果。
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public function requestSkillReview(
        CompetitionShowcase $showcase,
        Customer $customer,
        int $skillVersionId,
    ): array {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($showcase, $customer, $skillVersionId): array {
            $locked = CompetitionShowcase::query()->whereKey($showcase->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->consent_by_customer_id !== (int) $customer->getKey()) {
                throw CompetitionException::showcaseState('只有授权该展示的队长可以发起技能提审。');
            }

            if ((string) $locked->status !== CompetitionShowcase::STATUS_PUBLISHED) {
                throw CompetitionException::showcaseState('展示发布后才能进入技能提审。');
            }

            $profile = DeveloperProfile::query()
                ->where('customer_id', (int) $customer->getKey())
                ->first();

            if ($profile === null) {
                throw CompetitionException::showcaseState('当前账号还不是开发者，请先完成开发者入驻与协议。');
            }

            $version = $this->developerSkills->findOwnedVersion($profile, $skillVersionId);

            if ($version === null) {
                throw CompetitionException::showcaseState('只能提交自己名下的技能版本。');
            }

            $result = $this->developerSkills->submitForReview($profile, $skillVersionId);
            $result = is_array($result) ? $result : [
                'ok'      => false,
                'code'    => 'DEVELOPER_REVIEW_UNAVAILABLE',
                'message' => '技能提审流程暂不可用。',
            ];

            $locked->skill_version_id = $skillVersionId;
            $locked->developer_handoff_at = now('UTC');
            $locked->handoff_result = $result;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.showcase.skill_review_requested',
                'competition_showcase',
                (int) $locked->getKey(),
                [
                    'skill_version_id' => $skillVersionId,
                    'ok'               => (bool) ($result['ok'] ?? false),
                    'code'             => (string) ($result['code'] ?? ''),
                ],
            );

            return [
                'ok'      => (bool) ($result['ok'] ?? false),
                'code'    => (string) ($result['code'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, CompetitionShowcase>
     */
    public function listForCaptain(Customer $customer)
    {
        return CompetitionShowcase::query()
            ->with(['entry.team', 'submissionVersion', 'award'])
            ->whereHas('entry.team', fn ($query) => $query->where('captain_customer_id', (int) $customer->getKey()))
            ->orderByDesc('id')
            ->get();
    }

    public function findForCaptain(Customer $customer, int $showcaseId): CompetitionShowcase
    {
        $showcase = CompetitionShowcase::query()
            ->with(['entry.team', 'submissionVersion', 'award'])
            ->whereKey($showcaseId)
            ->whereHas('entry.team', fn ($query) => $query->where('captain_customer_id', (int) $customer->getKey()))
            ->first();

        if ($showcase === null) {
            throw CompetitionException::showcaseNotFound();
        }

        return $showcase;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminList(int $trackId): array
    {
        return CompetitionShowcase::query()
            ->with(['entry.team', 'submissionVersion', 'award'])
            ->where('track_id', $trackId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompetitionShowcase $showcase): array => $this->adminDto($showcase))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicShowcases(int $eventId): array
    {
        $trackIds = CompetitionTrack::query()
            ->whereIn('edition_id', \Webkul\Shop\Models\CompetitionEdition::query()
                ->where('event_id', $eventId)
                ->select('id'))
            ->whereIn('status', ['active', 'closed'])
            ->pluck('id');

        $output = [];

        foreach ($trackIds as $trackId) {
            $track = CompetitionTrack::query()->find($trackId);

            if ($track === null) {
                continue;
            }

            $showcases = CompetitionShowcase::query()
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionShowcase::STATUS_PUBLISHED)
                ->with(['entry.team', 'submissionVersion', 'award'])
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get();

            $rows = [];

            foreach ($showcases as $showcase) {
                if (! $this->consentIsValid($showcase)) {
                    continue;
                }

                $version = $showcase->submissionVersion;

                if ($version === null || (string) $version->status !== CompetitionSubmissionVersion::STATUS_SUBMITTED) {
                    continue;
                }

                $snapshot = (array) ($showcase->entry?->eligibility_snapshot ?? []);
                $awardLive = $showcase->showcase_type !== CompetitionShowcase::TYPE_AWARD
                    || $this->awardIsLive(
                        $showcase->award_id !== null ? (int) $showcase->award_id : null,
                        (int) $track->getKey(),
                        (int) $showcase->entry_id,
                    );

                $rows[] = [
                    'title'          => (string) $showcase->title,
                    'summary'        => (string) $showcase->summary,
                    'team_name'      => (string) ($snapshot['team_name'] ?? $showcase->entry?->team?->name ?? ''),
                    'track_name'     => (string) $track->name,
                    'showcase_type'  => (string) $showcase->showcase_type,
                    // 奖项被修订/撤下后，不继续展示旧奖项名，明确标记待复核。
                    'award_name'     => $awardLive && $showcase->showcase_type === CompetitionShowcase::TYPE_AWARD
                        ? (string) ($showcase->award?->name ?? '')
                        : null,
                    'under_review'   => ! $awardLive,
                    'published_at'   => $showcase->published_at?->toIso8601String(),
                ];
            }

            $output[(string) $track->track_key] = $rows;
        }

        return $output;
    }

    public function adminDto(CompetitionShowcase $showcase): array
    {
        $showcase->loadMissing(['entry.team', 'submissionVersion', 'award']);
        $version = $showcase->submissionVersion;

        return [
            'id'                    => (int) $showcase->getKey(),
            'status'                => (string) $showcase->status,
            'showcase_type'         => (string) $showcase->showcase_type,
            'row_version'           => (int) $showcase->row_version,
            'title'                 => (string) $showcase->title,
            'summary'               => (string) $showcase->summary,
            'entry_no'              => (string) ($showcase->entry?->entry_no ?? ''),
            'team_name'             => (string) ($showcase->entry?->team?->name ?? ''),
            'award_name'            => $showcase->award?->name,
            'submission_version_id'=> (int) $showcase->submission_version_id,
            'submission_title'      => $version?->title,
            'submission_repo_url'   => $version?->repo_url,
            'submission_video_url'  => $version?->video_url,
            'consent_at'            => $showcase->consent_at?->toIso8601String(),
            'consent_valid'         => $this->consentIsValid($showcase),
            'rejected_reason'       => $showcase->rejected_reason,
            'skill_version_id'      => $showcase->skill_version_id,
            'developer_handoff_at'  => $showcase->developer_handoff_at?->toIso8601String(),
        ];
    }

    public function customerDto(CompetitionShowcase $showcase): array
    {
        $showcase->loadMissing(['entry.team', 'submissionVersion', 'award']);
        $version = $showcase->submissionVersion;

        return [
            'id'                    => (int) $showcase->getKey(),
            'status'                => (string) $showcase->status,
            'showcase_type'         => (string) $showcase->showcase_type,
            'row_version'           => (int) $showcase->row_version,
            'title'                 => (string) $showcase->title,
            'summary'               => (string) $showcase->summary,
            'team_name'             => (string) ($showcase->entry?->team?->name ?? ''),
            'award_name'            => $showcase->award?->name,
            'submission_version_id'=> (int) $showcase->submission_version_id,
            'submission_title'      => $version?->title,
            'consent_at'            => $showcase->consent_at?->toIso8601String(),
            'consent_valid'         => $this->consentIsValid($showcase),
            'skill_version_id'      => $showcase->skill_version_id,
            'developer_handoff_at'  => $showcase->developer_handoff_at?->toIso8601String(),
        ];
    }

    public function consentIsValid(CompetitionShowcase $showcase): bool
    {
        if ($showcase->consent_at === null
            || $showcase->consent_by_customer_id === null
            || $showcase->consent_fingerprint === null) {
            return false;
        }

        $version = CompetitionSubmissionVersion::query()->find($showcase->submission_version_id);

        if ($version === null || (string) $version->status !== CompetitionSubmissionVersion::STATUS_SUBMITTED) {
            return false;
        }

        return hash_equals($this->consentFingerprint($showcase, $version), (string) $showcase->consent_fingerprint);
    }

    private function assertConsentValid(CompetitionShowcase $showcase): void
    {
        if (! $this->consentIsValid($showcase)) {
            throw CompetitionException::consentRequired();
        }
    }

    private function consentFingerprint(CompetitionShowcase $showcase, CompetitionSubmissionVersion $version): string
    {
        return hash('sha256', (string) json_encode([
            'submission_version_id' => (int) $version->getKey(),
            'content_fingerprint'   => (string) $version->content_fingerprint,
            'showcase_type'         => (string) $showcase->showcase_type,
            'title'                 => (string) $showcase->title,
            'summary'               => (string) $showcase->summary,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function assertAwardMatchesEntry(int $awardId, CompetitionEntry $entry): void
    {
        if (! $this->awardIsLive($awardId, (int) $entry->track_id, (int) $entry->getKey())) {
            throw CompetitionException::showcaseState('获奖展示必须关联本赛项当前已发布的奖项与成绩。');
        }
    }

    private function awardIsLive(?int $awardId, int $trackId, int $entryId): bool
    {
        if ($awardId === null) {
            return false;
        }

        $award = CompetitionAward::query()->whereKey($awardId)->first();

        if ($award === null
            || (string) $award->status !== CompetitionAward::STATUS_PUBLISHED
            || (int) $award->track_id !== $trackId) {
            return false;
        }

        $version = $award->current_version_id !== null
            ? CompetitionAwardVersion::query()->find($award->current_version_id)
            : null;

        if ($version === null
            || (string) $version->status !== CompetitionAwardVersion::STATUS_PUBLISHED
            || (int) $version->entry_id !== $entryId) {
            return false;
        }

        $resultVersion = $version->resultVersion;

        if ($resultVersion === null) {
            return false;
        }

        $result = CompetitionResult::query()->find($resultVersion->result_id);

        return $result !== null
            && (string) $result->status === CompetitionResult::STATUS_FINAL
            && (int) $result->published_version_id === (int) $resultVersion->getKey();
    }

    private function assertTrackUsable(CompetitionTrack $track): void
    {
        if (! in_array((string) $track->status, ['active', 'closed'], true)) {
            throw CompetitionException::trackNotActive('赛项尚未激活或已归档，不能管理展示。');
        }

        $this->rules->assertTrackReady($track);
    }
}
