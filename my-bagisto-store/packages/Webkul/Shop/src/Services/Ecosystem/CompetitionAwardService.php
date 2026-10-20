<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionAwardVersion;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-10 · 奖项版本与成绩修订联动。
 *
 * 关键规则：
 *   · 奖项发布必须引用当前已终审的成绩版本；
 *   · 成绩修订后，引用旧版本的已发布奖项转 pending_review，不自动改写旧结论；
 *   · 新奖项版本发布后，旧 published 版本标 superseded，历史保留；
 *   · 公开只认“奖项 published + 当前版本 published + 成绩仍是当前终审”。
 */
class CompetitionAwardService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createVersion(CompetitionTrack $track, array $data, int $adminId): CompetitionAward
    {
        $this->rules->assertWriteEnabled();

        $awardKey = trim((string) ($data['award_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $entryId = (int) ($data['entry_id'] ?? 0);
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $expectedAwardRowVersion = isset($data['expected_row_version']) ? (int) $data['expected_row_version'] : null;

        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $awardKey)) {
            throw CompetitionException::invalid('奖项 key 只允许小写字母/数字/下划线，长度 1~64。');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw CompetitionException::invalid('奖项名称不能为空且不得超过 120 字。');
        }

        return DB::transaction(function () use ($track, $awardKey, $name, $entryId, $sortOrder, $reason, $expectedAwardRowVersion, $adminId): CompetitionAward {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $entry = CompetitionEntry::query()->whereKey($entryId)->lockForUpdate()->first();

            if ($entry === null
                || (int) $entry->track_id !== (int) $lockedTrack->getKey()
                || (string) $entry->status !== CompetitionEntry::STATUS_CONFIRMED) {
                throw CompetitionException::awardState('奖项只能授予本赛项已确认的参赛条目。');
            }

            $result = CompetitionResult::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('entry_id', $entry->getKey())
                ->where('scope_key', 'track')
                ->lockForUpdate()
                ->first();

            if ($result === null || ! in_array((string) $result->status, [
                CompetitionResult::STATUS_PROVISIONAL,
                CompetitionResult::STATUS_FINAL,
            ], true) || $result->current_version_id === null) {
                throw CompetitionException::awardState('该参赛条目还没有已公布的成绩，不能授予奖项。');
            }

            $resultVersion = CompetitionResultVersion::query()
                ->whereKey($result->current_version_id)
                ->lockForUpdate()
                ->firstOrFail();

            $award = CompetitionAward::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('award_key', $awardKey)
                ->lockForUpdate()
                ->first();

            if ($award === null) {
                if ($expectedAwardRowVersion !== null && $expectedAwardRowVersion !== 0) {
                    throw CompetitionException::versionStale();
                }

                $award = new CompetitionAward();
                $award->fill([
                    'edition_id' => (int) $lockedTrack->edition_id,
                    'track_id'   => (int) $lockedTrack->getKey(),
                    'award_key'  => $awardKey,
                    'name'       => $name,
                    'sort_order' => $sortOrder,
                    'status'     => CompetitionAward::STATUS_DRAFT,
                    'row_version'=> 0,
                ]);
                $award->save();
            } else {
                if ($expectedAwardRowVersion !== null && (int) $award->row_version !== $expectedAwardRowVersion) {
                    throw CompetitionException::versionStale();
                }

                $oldName = (string) $award->name;
                $oldSort = (int) $award->sort_order;
                $award->name = $name;
                $award->sort_order = $sortOrder;

                if ((string) $award->name !== $oldName || (int) $award->sort_order !== $oldSort) {
                    $award->row_version = (int) $award->row_version + 1;
                }

                $award->save();
            }

            $nextNo = (int) CompetitionAwardVersion::query()
                ->where('award_id', $award->getKey())
                ->max('version_no') + 1;

            $entrySnapshot = (array) ($entry->eligibility_snapshot ?? []);

            $version = new CompetitionAwardVersion();
            $version->fill([
                'award_id'          => (int) $award->getKey(),
                'version_no'        => $nextNo,
                'entry_id'          => (int) $entry->getKey(),
                'result_version_id' => (int) $resultVersion->getKey(),
                'rank_no'           => $resultVersion->rank_no,
                'status'            => CompetitionAwardVersion::STATUS_DRAFT,
                'reason'            => $reason,
                'snapshot'          => [
                    'entry_no'          => (string) $entry->entry_no,
                    'team_name'         => (string) ($entrySnapshot['team_name'] ?? ''),
                    'result_version_id' => (int) $resultVersion->getKey(),
                    'rank_no'           => $resultVersion->rank_no,
                    'total_scaled'      => $resultVersion->total_scaled,
                ],
                'created_by_admin_id' => $adminId,
                'row_version'       => 0,
            ]);
            $version->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.award.version_created',
                'competition_award',
                (int) $award->getKey(),
                [
                    'version_id'        => (int) $version->getKey(),
                    'entry_id'          => (int) $entry->getKey(),
                    'result_version_id' => (int) $resultVersion->getKey(),
                ],
            );

            return $award->refresh();
        });
    }

    public function publish(CompetitionAward $award, int $versionId, int $expectedRowVersion, int $adminId): CompetitionAward
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($award, $versionId, $expectedRowVersion, $adminId): CompetitionAward {
            $lockedTrack = CompetitionTrack::query()->whereKey($award->track_id)->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $lockedAward = CompetitionAward::query()->whereKey($award->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedAward->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $version = CompetitionAwardVersion::query()
                ->whereKey($versionId)
                ->where('award_id', $lockedAward->getKey())
                ->lockForUpdate()
                ->first();

            if ($version === null) {
                throw CompetitionException::awardNotFound('奖项版本不存在或不属于该奖项。');
            }

            if ((string) $version->status !== CompetitionAwardVersion::STATUS_DRAFT) {
                throw CompetitionException::awardState('只有草稿奖项版本可以发布；待复核版本请先创建新版本。');
            }

            $resultVersion = CompetitionResultVersion::query()
                ->whereKey($version->result_version_id)
                ->lockForUpdate()
                ->first();

            if ($resultVersion === null) {
                throw CompetitionException::awardState('奖项引用的成绩版本不存在。');
            }

            $result = CompetitionResult::query()
                ->whereKey($resultVersion->result_id)
                ->lockForUpdate()
                ->first();

            if ($result === null
                || (string) $result->status !== CompetitionResult::STATUS_FINAL
                || (int) $result->published_version_id !== (int) $resultVersion->getKey()
                || (string) $resultVersion->status !== CompetitionResultVersion::STATUS_FINAL) {
                throw CompetitionException::awardState('奖项发布必须引用当前已终审的成绩版本。');
            }

            // 旧的已发布/待复核版本转 superseded，历史保留。
            $previous = CompetitionAwardVersion::query()
                ->where('award_id', $lockedAward->getKey())
                ->whereIn('status', [
                    CompetitionAwardVersion::STATUS_PUBLISHED,
                    CompetitionAwardVersion::STATUS_PENDING_REVIEW,
                ])
                ->lockForUpdate()
                ->get();

            foreach ($previous as $old) {
                $old->status = CompetitionAwardVersion::STATUS_SUPERSEDED;
                $old->row_version = (int) $old->row_version + 1;
                $old->save();
            }

            $version->status = CompetitionAwardVersion::STATUS_PUBLISHED;
            $version->published_by_admin_id = $adminId;
            $version->published_at = now('UTC');
            $version->row_version = (int) $version->row_version + 1;
            $version->save();

            $lockedAward->status = CompetitionAward::STATUS_PUBLISHED;
            $lockedAward->current_version_id = (int) $version->getKey();
            $lockedAward->row_version = (int) $lockedAward->row_version + 1;
            $lockedAward->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.award.published',
                'competition_award',
                (int) $lockedAward->getKey(),
                [
                    'version_id'        => (int) $version->getKey(),
                    'result_version_id' => (int) $version->result_version_id,
                    'superseded'        => $previous->count(),
                ],
            );

            return $lockedAward->refresh();
        });
    }

    /**
     * 成绩修订联动：引用旧成绩版本的已发布奖项转待复核。
     */
    public function markPendingForResultVersion(int $resultVersionId, int $adminId): int
    {
        return DB::transaction(function () use ($resultVersionId, $adminId): int {
            $versions = CompetitionAwardVersion::query()
                ->where('result_version_id', $resultVersionId)
                ->where('status', CompetitionAwardVersion::STATUS_PUBLISHED)
                ->lockForUpdate()
                ->get();

            $affected = 0;

            foreach ($versions as $version) {
                $version->status = CompetitionAwardVersion::STATUS_PENDING_REVIEW;
                $version->row_version = (int) $version->row_version + 1;
                $version->save();

                $award = CompetitionAward::query()->whereKey($version->award_id)->lockForUpdate()->first();

                if ($award !== null && (int) $award->current_version_id === (int) $version->getKey()) {
                    $award->status = CompetitionAward::STATUS_PENDING_REVIEW;
                    $award->row_version = (int) $award->row_version + 1;
                    $award->save();
                }

                $affected++;

                $this->logger->logOrFail(
                    'admin',
                    $adminId,
                    'competition.award.pending_review',
                    'competition_award_version',
                    (int) $version->getKey(),
                    [
                        'award_id'          => (int) $version->award_id,
                        'result_version_id' => $resultVersionId,
                    ],
                );
            }

            return $affected;
        });
    }

    public function revoke(CompetitionAward $award, int $expectedRowVersion, string $reason, int $adminId): CompetitionAward
    {
        $this->rules->assertWriteEnabled();

        $reason = trim($reason);

        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 2000) {
            throw CompetitionException::invalid('撤销奖项必须填写 4~2000 字理由。');
        }

        return DB::transaction(function () use ($award, $expectedRowVersion, $reason, $adminId): CompetitionAward {
            $locked = CompetitionAward::query()->whereKey($award->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $locked->status === CompetitionAward::STATUS_REVOKED) {
                throw CompetitionException::awardState('奖项已撤销。');
            }

            if ($locked->current_version_id !== null) {
                $version = CompetitionAwardVersion::query()
                    ->whereKey($locked->current_version_id)
                    ->lockForUpdate()
                    ->first();

                if ($version !== null) {
                    $version->status = CompetitionAwardVersion::STATUS_REVOKED;
                    $version->revoked_by_admin_id = $adminId;
                    $version->revoked_at = now('UTC');
                    $version->revocation_reason = $reason;
                    $version->row_version = (int) $version->row_version + 1;
                    $version->save();
                }
            }

            $locked->status = CompetitionAward::STATUS_REVOKED;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.award.revoked',
                'competition_award',
                (int) $locked->getKey(),
                ['reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminAwards(CompetitionTrack $track): array
    {
        return CompetitionAward::query()
            ->where('track_id', $track->getKey())
            ->with(['versions.entry.team', 'currentVersion'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CompetitionAward $award): array => [
                'id'          => (int) $award->getKey(),
                'award_key'   => (string) $award->award_key,
                'name'        => (string) $award->name,
                'status'      => (string) $award->status,
                'sort_order'  => (int) $award->sort_order,
                'row_version' => (int) $award->row_version,
                'current_version_id' => $award->current_version_id,
                'versions'    => $award->versions
                    ->sortByDesc('version_no')
                    ->map(fn (CompetitionAwardVersion $version): array => [
                        'id'                => (int) $version->getKey(),
                        'version_no'        => (int) $version->version_no,
                        'status'            => (string) $version->status,
                        'entry_no'          => (string) ($version->entry?->entry_no ?? ''),
                        'team_name'         => (string) ($version->entry?->team?->name ?? ''),
                        'result_version_id' => (int) $version->result_version_id,
                        'rank_no'           => $version->rank_no,
                        'published_at'      => $version->published_at?->toIso8601String(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * 公开奖项：按 track_key 分组。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function publicAwards(int $eventId): array
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

            $awards = CompetitionAward::query()
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionAward::STATUS_PUBLISHED)
                ->with(['currentVersion.entry.team', 'currentVersion.resultVersion.result'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $rows = [];

            foreach ($awards as $award) {
                $version = $award->currentVersion;

                if ($version === null
                    || (string) $version->status !== CompetitionAwardVersion::STATUS_PUBLISHED
                    || $version->resultVersion === null) {
                    continue;
                }

                $result = $version->resultVersion->result;

                if ($result === null
                    || (string) $result->status !== CompetitionResult::STATUS_FINAL
                    || (int) $result->published_version_id !== (int) $version->result_version_id) {
                    continue;
                }

                $snapshot = (array) ($version->snapshot ?? []);

                $rows[] = [
                    'award_key'    => (string) $award->award_key,
                    'name'         => (string) $award->name,
                    'entry_no'     => (string) ($snapshot['entry_no'] ?? $version->entry?->entry_no ?? ''),
                    'team_name'    => (string) ($snapshot['team_name'] ?? $version->entry?->team?->name ?? ''),
                    'rank_no'      => $version->rank_no,
                    'published_at' => $version->published_at?->toIso8601String(),
                ];
            }

            $output[(string) $track->track_key] = $rows;
        }

        return $output;
    }

    private function assertTrackUsable(CompetitionTrack $track): void
    {
        if (! in_array((string) $track->status, ['active', 'closed'], true)) {
            throw CompetitionException::trackNotActive('赛项尚未激活或已归档，不能管理奖项。');
        }

        $this->rules->assertTrackReady($track);
    }
}
