<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionMatch;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-08 · 成绩草稿 / 复核 / 暂定发布 / 终审 / 修订。
 *
 * 权限分离由服务层再兜一层：同一管理员不能既录分又发布同一版本。
 * 路由层权限（scoring vs results）是第一道门，服务层是第二道门。
 *
 * 公开读取只认 `published_version_id`；修订期间旧版本保留并标记
 * `under_review`，新草稿不能对外冒充已经公布的结论。
 */
class CompetitionResultService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly CompetitionScoringService $scoring,
        private readonly OperationLogger $logger,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveDraft(
        CompetitionTrack $track,
        CompetitionEntry $entry,
        ?int $matchId,
        array $values,
        string $rowStatus,
        ?int $expectedRowVersion,
        int $adminId,
        ?string $reason = null,
    ): CompetitionResult {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $entry, $matchId, $values, $rowStatus, $expectedRowVersion, $adminId, $reason): CompetitionResult {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $lockedEntry = CompetitionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedEntry->track_id !== (int) $lockedTrack->getKey()
                || (string) $lockedEntry->status !== CompetitionEntry::STATUS_CONFIRMED) {
                throw CompetitionException::entryNotActive('只有本赛项已确认的参赛条目可以录入成绩。');
            }

            $lockedMatch = null;

            if ($matchId !== null && $matchId > 0) {
                $lockedMatch = CompetitionMatch::query()->whereKey($matchId)->lockForUpdate()->first();

                if ($lockedMatch === null || (int) $lockedMatch->track_id !== (int) $lockedTrack->getKey()) {
                    throw CompetitionException::notFound('场次不存在或不属于该赛项。');
                }

                if ((string) $lockedMatch->status === CompetitionMatch::STATUS_CANCELED) {
                    throw CompetitionException::resultState('已取消的场次不能录入成绩。');
                }
            }

            $scopeKey = CompetitionResult::scopeKeyFor($lockedMatch?->getKey());
            $result = CompetitionResult::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('entry_id', $lockedEntry->getKey())
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if ($result === null) {
                if ($expectedRowVersion !== null && $expectedRowVersion !== 0) {
                    throw CompetitionException::versionStale();
                }

                $result = new CompetitionResult();
                $result->fill([
                    'edition_id'    => (int) $lockedTrack->edition_id,
                    'track_id'      => (int) $lockedTrack->getKey(),
                    'entry_id'      => (int) $lockedEntry->getKey(),
                    'match_id'      => $lockedMatch?->getKey(),
                    'scope_key'     => $scopeKey,
                    'status'        => CompetitionResult::STATUS_DRAFT,
                    'row_version'   => 0,
                ]);
                $result->save();
            } elseif ($expectedRowVersion !== null && (int) $result->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $current = $result->current_version_id !== null
                ? CompetitionResultVersion::query()->whereKey($result->current_version_id)->lockForUpdate()->first()
                : null;

            if ($current !== null && (string) $current->status !== CompetitionResultVersion::STATUS_DRAFT) {
                throw CompetitionException::resultState('已提交复核或已发布的成绩不可直接修改；请走成绩修订。');
            }

            $normalized = $this->scoring->normalizeValues($lockedTrack, $values, $rowStatus);
            $snapshot = $this->scoring->snapshot($lockedTrack);
            $contract = $this->scoring->contract($lockedTrack);

            $version = $current ?? new CompetitionResultVersion();
            $version->fill([
                'result_id'             => (int) $result->getKey(),
                'track_id'              => (int) $lockedTrack->getKey(),
                'entry_id'              => (int) $lockedEntry->getKey(),
                'match_id'              => $lockedMatch?->getKey(),
                'version_no'            => $current !== null ? (int) $current->version_no : $this->nextVersionNo($result),
                'status'                => CompetitionResultVersion::STATUS_DRAFT,
                'score_values'          => $normalized['values'],
                'total_scaled'          => $normalized['total_scaled'],
                'forfeit_status'        => $normalized['forfeit_status'],
                'excluded'              => $normalized['excluded'],
                'scoring_policy'        => $contract['policy'],
                'scoring_snapshot'      => $snapshot['snapshot'],
                'scoring_fingerprint'   => $snapshot['fingerprint'],
                'rules_version_id'      => $lockedTrack->rules_version_id,
                'rules_fingerprint'     => $lockedTrack->rules_fingerprint,
                'reason'                => $reason,
                'created_by_admin_id'   => $adminId,
            ]);

            if ($current !== null) {
                $version->row_version = (int) $current->row_version + 1;
                $version->rank_no = null;
                $version->tie_group = null;
            } else {
                $version->row_version = 0;
            }

            $version->save();

            // 修订中的主体必须保持 revising：旧公布版本仍要对外可回查并标 under_review，
            // 不能被“保存草稿”降级为 draft 而从公开面消失。
            $result->status = (string) $result->status === CompetitionResult::STATUS_REVISING
                ? CompetitionResult::STATUS_REVISING
                : CompetitionResult::STATUS_DRAFT;
            $result->current_version_id = (int) $version->getKey();
            $result->row_version = (int) $result->row_version + 1;
            $result->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.draft_saved',
                'competition_result',
                (int) $result->getKey(),
                [
                    'version_id' => (int) $version->getKey(),
                    'version_no' => (int) $version->version_no,
                    'entry_id'   => (int) $lockedEntry->getKey(),
                    'match_id'   => $lockedMatch?->getKey(),
                    'forfeit'    => $normalized['forfeit_status'],
                ],
            );

            return $result->refresh();
        });
    }

    public function submit(CompetitionResult $result, int $expectedRowVersion, int $adminId): CompetitionResult
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($result, $expectedRowVersion, $adminId): CompetitionResult {
            $lockedTrack = CompetitionTrack::query()->whereKey($result->track_id)->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $locked = CompetitionResult::query()->whereKey($result->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $version = $this->currentVersionForUpdate($locked);

            if ((string) $version->status !== CompetitionResultVersion::STATUS_DRAFT) {
                throw CompetitionException::resultState('只有草稿成绩可以提交复核。');
            }

            $version->status = CompetitionResultVersion::STATUS_SUBMITTED;
            $version->submitted_at = now('UTC');
            $version->row_version = (int) $version->row_version + 1;
            $version->save();

            $locked->status = (string) $locked->status === CompetitionResult::STATUS_REVISING
                ? CompetitionResult::STATUS_REVISING
                : CompetitionResult::STATUS_SUBMITTED;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.submitted',
                'competition_result',
                (int) $locked->getKey(),
                ['version_id' => (int) $version->getKey()],
            );

            return $locked->refresh();
        });
    }

    /**
     * 赛项级暂定发布：同一批成绩在同一排名快照下发布，避免逐条发布造成名次漂移。
     */
    public function publishProvisional(CompetitionTrack $track, int $expectedTrackRowVersion, int $adminId): array
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedTrackRowVersion, $adminId): array {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            if ((int) $lockedTrack->row_version !== $expectedTrackRowVersion) {
                throw CompetitionException::versionStale();
            }

            $results = CompetitionResult::query()
                ->where('track_id', $lockedTrack->getKey())
                ->whereIn('status', [
                    CompetitionResult::STATUS_SUBMITTED,
                    CompetitionResult::STATUS_REVISING,
                ])
                ->lockForUpdate()
                ->get();

            if ($results->isEmpty()) {
                throw CompetitionException::resultState('没有待发布的成绩。');
            }

            $versions = [];

            foreach ($results as $result) {
                $version = $this->currentVersionForUpdate($result);

                if ((string) $version->status !== CompetitionResultVersion::STATUS_SUBMITTED) {
                    // 修订已创建但尚未提交复核的草稿：跳过，等提交后再发布。
                    if ((string) $result->status === CompetitionResult::STATUS_REVISING) {
                        continue;
                    }

                    throw CompetitionException::resultState('成绩版本不在已提交复核状态。');
                }

                if ((int) $version->created_by_admin_id === $adminId) {
                    throw CompetitionException::dutySeparation();
                }

                if (! (bool) $version->excluded && $version->rank_no === null) {
                    throw CompetitionException::rankingRequired(sprintf(
                        '参赛条目 %s 尚未确定名次。',
                        $this->entryLabel((int) $version->entry_id),
                    ));
                }

                $versions[] = [$result, $version];
            }

            if ($versions === []) {
                throw CompetitionException::resultState('没有已提交复核的修订成绩可发布。');
            }

            $publishedAt = now('UTC');

            foreach ($versions as [$result, $version]) {
                $version->status = CompetitionResultVersion::STATUS_PROVISIONAL;
                $version->reviewed_by_admin_id = $adminId;
                $version->reviewed_at = $publishedAt;
                $version->published_at = $publishedAt;
                $version->row_version = (int) $version->row_version + 1;
                $version->save();

                $result->status = CompetitionResult::STATUS_PROVISIONAL;
                $result->published_version_id = (int) $version->getKey();
                $result->row_version = (int) $result->row_version + 1;
                $result->save();

                $this->queueNotification($result, $version, 'competition_result.provisional');
            }

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.provisional_published',
                'competition_track',
                (int) $lockedTrack->getKey(),
                ['count' => count($versions)],
            );

            return ['published' => count($versions), 'published_at' => $publishedAt->toIso8601String()];
        });
    }

    public function finalize(CompetitionTrack $track, int $expectedTrackRowVersion, int $adminId): array
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedTrackRowVersion, $adminId): array {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            if ((int) $lockedTrack->row_version !== $expectedTrackRowVersion) {
                throw CompetitionException::versionStale();
            }

            $results = CompetitionResult::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('status', CompetitionResult::STATUS_PROVISIONAL)
                ->lockForUpdate()
                ->get();

            if ($results->isEmpty()) {
                throw CompetitionException::resultState('没有待终审的成绩。');
            }

            $finalizedAt = now('UTC');

            foreach ($results as $result) {
                $version = $this->currentVersionForUpdate($result);

                if ((string) $version->status !== CompetitionResultVersion::STATUS_PROVISIONAL) {
                    throw CompetitionException::resultState('成绩版本不在暂定发布状态。');
                }

                if ((int) $version->created_by_admin_id === $adminId) {
                    throw CompetitionException::dutySeparation();
                }

                $version->status = CompetitionResultVersion::STATUS_FINAL;
                $version->finalized_by_admin_id = $adminId;
                $version->finalized_at = $finalizedAt;
                $version->row_version = (int) $version->row_version + 1;
                $version->save();

                $result->status = CompetitionResult::STATUS_FINAL;
                $result->published_version_id = (int) $version->getKey();
                $result->row_version = (int) $result->row_version + 1;
                $result->save();

                $this->queueNotification($result, $version, 'competition_result.final');
            }

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.finalized',
                'competition_track',
                (int) $lockedTrack->getKey(),
                ['count' => $results->count()],
            );

            return ['finalized' => $results->count(), 'finalized_at' => $finalizedAt->toIso8601String()];
        });
    }

    /**
     * 修订：保留旧版本，新建草稿版本；旧奖项转待复核（G3-10 联动）。
     */
    public function revise(CompetitionResult $result, int $expectedRowVersion, string $reason, int $adminId): CompetitionResult
    {
        $this->rules->assertWriteEnabled();

        $reason = trim($reason);

        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 2000) {
            throw CompetitionException::invalid('成绩修订必须填写 4~2000 字的理由。');
        }

        return DB::transaction(function () use ($result, $expectedRowVersion, $reason, $adminId): CompetitionResult {
            $lockedTrack = CompetitionTrack::query()->whereKey($result->track_id)->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $locked = CompetitionResult::query()->whereKey($result->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if (! in_array((string) $locked->status, [
                CompetitionResult::STATUS_PROVISIONAL,
                CompetitionResult::STATUS_FINAL,
            ], true)) {
                throw CompetitionException::resultState('只有已发布或已终审的成绩可以修订。');
            }

            $old = $this->currentVersionForUpdate($locked);

            if ((int) $old->created_by_admin_id === $adminId) {
                throw CompetitionException::dutySeparation('修订发布必须由原录分人以外的复核人执行。');
            }

            $oldPublishedId = $locked->published_version_id !== null
                ? (int) $locked->published_version_id
                : (int) $old->getKey();

            $contract = $this->scoring->contract($lockedTrack);
            $snapshot = $this->scoring->snapshot($lockedTrack);

            $rawValues = [];

            foreach ((array) $old->score_values as $key => $value) {
                $rawValues[$key] = is_array($value)
                    ? ['value' => $value['value'] ?? null, 'status' => $value['status'] ?? 'scored']
                    : $value;
            }

            // 逐字段状态已保存在 score_values；只有整行取消资格才需要行级覆盖。
            $normalized = $this->scoring->normalizeValues(
                $lockedTrack,
                $rawValues,
                (string) $old->forfeit_status === 'disqualified' ? 'disqualified' : 'none',
            );

            $old->superseded_status = (string) $old->status;
            $old->status = CompetitionResultVersion::STATUS_SUPERSEDED;
            $old->row_version = (int) $old->row_version + 1;
            $old->save();

            $new = new CompetitionResultVersion();
            $new->fill([
                'result_id'             => (int) $locked->getKey(),
                'track_id'              => (int) $lockedTrack->getKey(),
                'entry_id'              => (int) $locked->entry_id,
                'match_id'              => $locked->match_id,
                'version_no'            => $this->nextVersionNo($locked),
                'status'                => CompetitionResultVersion::STATUS_DRAFT,
                'supersedes_version_id' => (int) $old->getKey(),
                'score_values'          => $normalized['values'],
                'total_scaled'          => $normalized['total_scaled'],
                'forfeit_status'        => $normalized['forfeit_status'],
                'excluded'              => $normalized['excluded'],
                'scoring_policy'        => $contract['policy'],
                'scoring_snapshot'      => $snapshot['snapshot'],
                'scoring_fingerprint'   => $snapshot['fingerprint'],
                'rules_version_id'      => $lockedTrack->rules_version_id,
                'rules_fingerprint'     => $lockedTrack->rules_fingerprint,
                'created_by_admin_id'   => $adminId,
                'revision_reason'       => $reason,
                'row_version'           => 0,
            ]);
            $new->save();

            $locked->status = CompetitionResult::STATUS_REVISING;
            $locked->current_version_id = (int) $new->getKey();
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.revised',
                'competition_result',
                (int) $locked->getKey(),
                [
                    'old_version_id' => (int) $old->getKey(),
                    'new_version_id' => (int) $new->getKey(),
                    'reason'         => $reason,
                ],
            );

            // G3-10：引用旧公布版本的奖项转待复核，不自动改写旧结论。
            if (class_exists(CompetitionAwardService::class)) {
                app(CompetitionAwardService::class)->markPendingForResultVersion((int) $oldPublishedId, $adminId);
            }

            return $locked->refresh();
        });
    }

    /**
     * 手工排名（适用于 manual 策略或 strict 并列待人工核准）。
     *
     * @param  array<int, array{entry_id:int, rank_no:int, tie_group?:string|null}>  $ranking
     */
    public function applyRanking(CompetitionTrack $track, array $ranking, int $adminId, ?int $matchId = null): array
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $ranking, $adminId, $matchId): array {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $scopeKey = CompetitionResult::scopeKeyFor($matchId);
            $versionIds = CompetitionResult::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('scope_key', $scopeKey)
                ->whereNotNull('current_version_id')
                ->select('current_version_id');

            $versions = CompetitionResultVersion::query()
                ->whereIn('id', $versionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('entry_id');

            $editable = $versions->filter(fn (CompetitionResultVersion $version): bool => in_array(
                (string) $version->status,
                [CompetitionResultVersion::STATUS_DRAFT, CompetitionResultVersion::STATUS_SUBMITTED],
                true,
            ));

            if ($editable->isEmpty()) {
                throw CompetitionException::resultState('没有可排名的成绩草稿。');
            }

            $seenRank = [];
            $applied = 0;

            foreach ($ranking as $row) {
                $entryId = (int) ($row['entry_id'] ?? 0);
                $rankNo = (int) ($row['rank_no'] ?? 0);
                $tieGroup = isset($row['tie_group']) && trim((string) $row['tie_group']) !== ''
                    ? trim((string) $row['tie_group'])
                    : null;

                $version = $versions->get($entryId);

                if ($version === null) {
                    throw CompetitionException::rankingInvalid(sprintf('参赛条目 %d 没有当前成绩版本。', $entryId));
                }

                // 已暂定/终审的版本不允许被普通排名覆盖；修订后的新草稿才可改。
                if (! $editable->has($entryId)) {
                    continue;
                }

                if ($rankNo < 1) {
                    throw CompetitionException::rankingInvalid('名次必须是正整数。');
                }

                if ((bool) $version->excluded) {
                    throw CompetitionException::rankingInvalid('已取消资格/排除的条目不能给名次。');
                }

                $rankKey = $rankNo.'|'.(string) $tieGroup;

                if (isset($seenRank[$rankKey])) {
                    throw CompetitionException::rankingInvalid('同一名次与并列组只能出现一次。');
                }

                $seenRank[$rankKey] = true;

                if ($version->rank_no !== $rankNo || (string) $version->tie_group !== (string) $tieGroup) {
                    $version->rank_no = $rankNo;
                    $version->tie_group = $tieGroup;
                    $version->row_version = (int) $version->row_version + 1;
                    $version->save();
                    $applied++;
                }
            }

            // 未在本次排名中出现的可排名条目，名次清空，防止旧名次残留。
            // 已暂定/终审版本不在 editable 内，不会被普通排名覆盖。
            foreach ($editable as $entryId => $version) {
                if ((bool) $version->excluded) {
                    continue;
                }

                $present = collect($ranking)->contains(fn (array $row): bool => (int) ($row['entry_id'] ?? 0) === (int) $entryId);

                if (! $present && $version->rank_no !== null) {
                    $version->rank_no = null;
                    $version->tie_group = null;
                    $version->row_version = (int) $version->row_version + 1;
                    $version->save();
                }
            }

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.result.ranking_applied',
                'competition_track',
                (int) $lockedTrack->getKey(),
                ['rows' => count($ranking), 'changed' => $applied],
            );

            return ['applied' => $applied];
        });
    }

    /**
     * 自动排名预览：不改库。策略为 manual 时返回空结果并要求人工排名。
     *
     * @return array<int, array{entry_id:int, rank_no:int|null, tie_group:string|null}>
     */
    public function previewRanking(CompetitionTrack $track, ?int $matchId = null): array
    {
        $contract = $this->scoring->contract($track);
        $registry = (array) config('ecosystem_competition.scoring_policies', []);

        // 草稿赛项可能还没有规则/策略；后台页面应显示空预览，而不是 500。
        if ($contract['policy'] === '' || ! array_key_exists($contract['policy'], $registry)) {
            return [];
        }

        $scopeKey = CompetitionResult::scopeKeyFor($matchId);

        $rows = CompetitionResultVersion::query()
            ->where('track_id', $track->getKey())
            ->whereIn('status', [
                CompetitionResultVersion::STATUS_DRAFT,
                CompetitionResultVersion::STATUS_SUBMITTED,
            ])
            ->whereHas('result', fn ($query) => $query->where('scope_key', $scopeKey))
            ->get()
            ->map(fn (CompetitionResultVersion $version): array => [
                'entry_id'       => (int) $version->entry_id,
                'total_scaled'   => $version->total_scaled,
                'excluded'       => (bool) $version->excluded,
                'forfeit_status' => (string) $version->forfeit_status,
            ])
            ->all();

        $ranking = $this->scoring->rank($track, $rows);

        return collect($ranking)
            ->map(fn (array $row, int $entryId): array => [
                'entry_id'  => $entryId,
                'rank_no'   => $row['rank_no'],
                'tie_group' => $row['tie_group'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicResults(int $eventId): array
    {
        $trackIds = CompetitionTrack::query()
            ->whereIn('edition_id', \Webkul\Shop\Models\CompetitionEdition::query()
                ->where('event_id', $eventId)
                ->select('id'))
            ->whereIn('status', ['active', 'closed'])
            ->orderBy('id')
            ->pluck('id');

        $output = [];

        foreach ($trackIds as $trackId) {
            $track = CompetitionTrack::query()->find($trackId);

            if ($track === null) {
                continue;
            }

            $contract = $this->scoring->contract($track);
            $labels = [];

            foreach ($contract['fields'] as $field) {
                $labels[(string) $field['key']] = (string) $field['label'];
            }

            $results = CompetitionResult::query()
                ->where('track_id', $track->getKey())
                ->whereIn('status', CompetitionResult::PUBLIC_STATUSES)
                ->whereNotNull('published_version_id')
                ->with(['entry', 'publishedVersion'])
                ->get();

            $rows = [];
            $matchRows = [];

            foreach ($results as $result) {
                $version = $result->publishedVersion;

                if ($version === null) {
                    continue;
                }

                $entry = $result->entry;
                $snapshot = (array) ($entry?->eligibility_snapshot ?? []);
                $scoreValues = [];

                foreach ((array) $version->score_values as $key => $value) {
                    $scoreValues[] = [
                        'key'    => (string) $key,
                        'label'  => $labels[(string) $key] ?? (string) $key,
                        'value'  => $value['value'] ?? null,
                        'status' => $value['status'] ?? 'scored',
                    ];
                }

                $appealDeadline = $version->published_at !== null
                    ? $version->published_at->copy()->addMinutes((int) ($track->rules_profile['appeal']['window_minutes'] ?? 0))
                    : null;

                $row = [
                    'result_id'      => (int) $result->getKey(),
                    'result_version_id' => (int) $version->getKey(),
                    'entry_no'       => (string) ($entry?->entry_no ?? ''),
                    'team_name'      => (string) ($snapshot['team_name'] ?? ''),
                    'scope'          => (string) $result->scope_key,
                    'status'         => (string) $version->status === CompetitionResultVersion::STATUS_SUPERSEDED
                        ? (string) ($version->superseded_status ?: CompetitionResultVersion::STATUS_SUPERSEDED)
                        : (string) $version->status,
                    'under_review'   => (string) $result->status === CompetitionResult::STATUS_REVISING,
                    'score_values'   => $scoreValues,
                    'total'          => $version->total_scaled !== null
                        ? $this->scoring->scaledToDecimal((string) $version->total_scaled, $contract['scale'])
                        : null,
                    'rank_no'        => $version->rank_no,
                    'tie_group'      => $version->tie_group,
                    'forfeit'        => $version->forfeit_status !== 'none'
                        ? $this->scoring->forfeitLabel((string) $version->forfeit_status)
                        : null,
                    'published_at'   => $version->published_at?->toIso8601String(),
                    'appeal_deadline_at' => $appealDeadline?->toIso8601String(),
                ];

                if ((string) $result->scope_key === 'track') {
                    $rows[] = $row;
                } else {
                    $matchRows[(string) $result->scope_key][] = $row;
                }
            }

            $sorter = static function (array $left, array $right): int {
                $leftRank = $left['rank_no'] ?? PHP_INT_MAX;
                $rightRank = $right['rank_no'] ?? PHP_INT_MAX;

                return $leftRank <=> $rightRank ?: strcmp((string) $left['entry_no'], (string) $right['entry_no']);
            };

            usort($rows, $sorter);

            foreach ($matchRows as $key => $matchRowSet) {
                usort($matchRowSet, $sorter);
                $matchRows[$key] = $matchRowSet;
            }

            $output[] = [
                'track_key'  => (string) $track->track_key,
                'name'       => (string) $track->name,
                'scoring'    => [
                    'policy' => $contract['policy'],
                    'scale'  => $contract['scale'],
                    'tie_policy' => $contract['tie_policy'],
                    'fields' => $contract['fields'],
                ],
                'results'    => $rows,
                'match_results' => $matchRows,
            ];
        }

        return $output;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminResults(CompetitionTrack $track): array
    {
        return CompetitionResult::query()
            ->where('track_id', $track->getKey())
            ->with(['entry.team', 'currentVersion', 'publishedVersion'])
            ->orderBy('id')
            ->get()
            ->map(fn (CompetitionResult $result): array => [
                'id'             => (int) $result->getKey(),
                'entry_id'       => (int) $result->entry_id,
                'entry_no'       => (string) ($result->entry?->entry_no ?? ''),
                'team_name'      => (string) ($result->entry?->team?->name ?? ''),
                'scope_key'      => (string) $result->scope_key,
                'status'         => (string) $result->status,
                'row_version'    => (int) $result->row_version,
                'current_version'=> $result->currentVersion !== null ? [
                    'id'            => (int) $result->currentVersion->getKey(),
                    'version_no'    => (int) $result->currentVersion->version_no,
                    'status'        => (string) $result->currentVersion->status,
                    'total_scaled'  => $result->currentVersion->total_scaled,
                    'rank_no'       => $result->currentVersion->rank_no,
                    'tie_group'     => $result->currentVersion->tie_group,
                    'forfeit'       => (string) $result->currentVersion->forfeit_status,
                    'created_by'    => (int) $result->currentVersion->created_by_admin_id,
                ] : null,
                'published_version' => $result->publishedVersion !== null ? [
                    'id'          => (int) $result->publishedVersion->getKey(),
                    'status'      => (string) $result->publishedVersion->status,
                    'rank_no'     => $result->publishedVersion->rank_no,
                    'published_at'=> $result->publishedVersion->published_at?->toIso8601String(),
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function assertTrackUsable(CompetitionTrack $track): void
    {
        if (! in_array((string) $track->status, ['active', 'closed'], true)) {
            throw CompetitionException::trackNotActive('赛项尚未激活或已归档，不能录入成绩。');
        }

        $this->rules->assertTrackReady($track);
    }

    private function currentVersionForUpdate(CompetitionResult $result): CompetitionResultVersion
    {
        if ($result->current_version_id === null) {
            throw CompetitionException::resultState('成绩没有当前版本。');
        }

        $version = CompetitionResultVersion::query()
            ->whereKey($result->current_version_id)
            ->lockForUpdate()
            ->first();

        if ($version === null) {
            throw CompetitionException::resultState('成绩当前版本不存在。');
        }

        return $version;
    }

    private function nextVersionNo(CompetitionResult $result): int
    {
        return (int) CompetitionResultVersion::query()
            ->where('result_id', $result->getKey())
            ->max('version_no') + 1;
    }

    private function entryLabel(int $entryId): string
    {
        return (string) (CompetitionEntry::query()->whereKey($entryId)->value('entry_no') ?? ('#'.$entryId));
    }

    private function queueNotification(CompetitionResult $result, CompetitionResultVersion $version, string $eventType): void
    {
        $this->outbox->write(
            'competition_result',
            (int) $result->getKey(),
            $eventType,
            [
                'result_id'  => (int) $result->getKey(),
                'version_id' => (int) $version->getKey(),
                'entry_id'   => (int) $result->entry_id,
                'track_id'   => (int) $result->track_id,
                'status'     => (string) $version->status,
                'row_version'=> (int) $result->row_version,
            ],
            sprintf('competition-result:%d:%d:%s', (int) $result->getKey(), (int) $version->getKey(), $eventType),
        );
    }
}
