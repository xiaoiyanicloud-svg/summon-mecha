<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-04 · 参赛条目与当届快照。
 *
 * 快照在队伍报名提交事务内写入：
 *   · roster：仅 active 成员（含队长），记录冻结时角色与加入时间；
 *   · eligibility：人数区间、规则版本/指纹、机器人声明；
 *   · 冻结后队伍改名/换人不回溯覆盖；成员/队长变更在 G3-03 服务层被拒绝。
 *
 * 资格检查完全由 `rules_profile.eligibility` 驱动；规则不是 confirmed 时
 * 一律拒绝，不用代码默认值补空。
 */
class CompetitionEntryService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
    ) {}

    public function forTeam(CompetitionTeam $team): ?CompetitionEntry
    {
        return CompetitionEntry::query()
            ->with(['track', 'submission'])
            ->where('team_id', $team->getKey())
            ->first();
    }

    public function forAttempt(int $attemptId): ?CompetitionEntry
    {
        return CompetitionEntry::query()->where('attempt_id', $attemptId)->first();
    }

    /**
     * 在队伍报名事务内冻结/更新参赛快照。
     *
     * 调用方必须已经持有 event → scope → track → team 的锁，并已确认
     * registration/attempt 归属。本方法不自行开事务。
     *
     * @param  array<string, mixed>  $robotDeclaration
     */
    public function freezeForRegistration(
        CompetitionTeam $team,
        CompetitionTrack $track,
        EventRegistration $registration,
        EventRegistrationAttempt $attempt,
        array $robotDeclaration,
    ): CompetitionEntry {
        $this->rules->assertTrackReady($track);

        $profile = (array) $track->rules_profile;
        $eligibility = $this->evaluate($track, $team, $robotDeclaration);

        $roster = CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->map(static fn (CompetitionTeamMember $member): array => [
                'customer_id' => (int) $member->customer_id,
                'role'        => (string) $member->role,
                'joined_at'   => $member->joined_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $entry = CompetitionEntry::query()
            ->where('team_id', $team->getKey())
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            $entry = new CompetitionEntry();
            $entry->entry_no = $this->newEntryNo();
            $entry->edition_id = (int) $track->edition_id;
            $entry->track_id = (int) $track->getKey();
            $entry->team_id = (int) $team->getKey();
            $entry->captain_customer_id = (int) $team->captain_customer_id;
            $entry->row_version = 0;
        } elseif ($entry->frozen_at !== null && (int) $entry->attempt_id === (int) $attempt->getKey()) {
            throw CompetitionException::entryFrozen('该参赛快照已冻结，不能重复冻结。');
        }

        $entry->registration_id = (int) $registration->getKey();
        $entry->attempt_id = (int) $attempt->getKey();
        $entry->status = (string) $attempt->status === EventRegistrationAttempt::STATUS_CONFIRMED
            ? CompetitionEntry::STATUS_CONFIRMED
            : CompetitionEntry::STATUS_SUBMITTED;
        $entry->roster_snapshot = $roster;
        $entry->eligibility_snapshot = array_merge($eligibility, [
            'team_name'             => (string) $team->name,
            'checked_at'            => now('UTC')->toIso8601String(),
            'rules_profile_version' => (string) config('ecosystem_competition.rule_profile_version', 'g3-01-v1'),
        ]);
        $entry->robot_declaration = $robotDeclaration !== [] ? $robotDeclaration : null;
        $entry->rules_version_id = $track->rules_version_id;
        $entry->rules_fingerprint = $track->rules_fingerprint;
        $entry->frozen_at = now('UTC');
        $entry->frozen_by_customer_id = (int) $team->captain_customer_id;
        $entry->row_version = (int) $entry->row_version + 1;
        $entry->save();

        $this->logger->logOrFail(
            'customer',
            (int) $team->captain_customer_id,
            'competition.entry.frozen',
            'competition_entry',
            (int) $entry->getKey(),
            [
                'team_id'          => (int) $team->getKey(),
                'track_id'         => (int) $track->getKey(),
                'registration_id'  => (int) $registration->getKey(),
                'attempt_id'       => (int) $attempt->getKey(),
                'rules_fingerprint'=> (string) $track->rules_fingerprint,
                'member_count'     => count($roster),
            ],
        );

        return $entry->refresh();
    }

    /**
     * 人工审核批准后调用：submitted → confirmed。
     */
    public function syncForAttempt(EventRegistrationAttempt $attempt, string $targetStatus): ?CompetitionEntry
    {
        $entry = CompetitionEntry::query()->where('attempt_id', $attempt->getKey())->first();

        if ($entry === null) {
            return null;
        }

        $allowed = match ($targetStatus) {
            CompetitionEntry::STATUS_CONFIRMED => [CompetitionEntry::STATUS_SUBMITTED],
            CompetitionEntry::STATUS_WITHDRAWN => [
                CompetitionEntry::STATUS_SUBMITTED,
                CompetitionEntry::STATUS_CONFIRMED,
            ],
            default => [],
        };

        if (! in_array((string) $entry->status, $allowed, true)) {
            return $entry;
        }

        $entry->status = $targetStatus;
        $entry->row_version = (int) $entry->row_version + 1;
        $entry->save();

        return $entry->refresh();
    }

    /**
     * 资格评估（只读）。供报名冻结、后台预览与测试共用。
     *
     * @param  array<string, mixed>  $robotDeclaration
     * @return array<string, mixed>
     */
    public function evaluate(CompetitionTrack $track, CompetitionTeam $team, array $robotDeclaration): array
    {
        $this->rules->assertTrackReady($track);

        $profile = (array) $track->rules_profile;
        $eligibility = (array) ($profile['eligibility'] ?? []);

        $min = (int) ($eligibility['min_members'] ?? 0);
        $max = (int) ($eligibility['max_members'] ?? 0);

        $active = CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->count();

        if ($active < $min) {
            throw CompetitionException::eligibilityFailed(sprintf(
                '有效成员不足：规则要求至少 %d 人，当前 %d 人。',
                $min,
                $active,
            ), ['active' => $active, 'min' => $min]);
        }

        if ($max > 0 && $active > $max) {
            throw CompetitionException::eligibilityFailed(sprintf(
                '有效成员超过上限：规则最多 %d 人，当前 %d 人。',
                $max,
                $active,
            ), ['active' => $active, 'max' => $max]);
        }

        $robotRequired = (bool) ($eligibility['robot_declaration_required'] ?? false);
        $requiredFields = (array) ($eligibility['robot_declaration_fields'] ?? []);

        if ($robotRequired) {
            $missing = [];

            foreach ($requiredFields as $field) {
                $value = $robotDeclaration[$field] ?? null;

                if (! is_scalar($value) || trim((string) $value) === '') {
                    $missing[] = (string) $field;
                }
            }

            if ($missing !== []) {
                throw CompetitionException::eligibilityFailed(
                    '机器人声明缺少必填字段：'.implode(', ', $missing).'。',
                    ['missing_robot_fields' => $missing],
                );
            }
        }

        return [
            'member_count'              => $active,
            'min_members'               => $min,
            'max_members'               => $max,
            'robot_declaration_required'=> $robotRequired,
            'robot_declaration_fields'  => $robotRequired ? $requiredFields : [],
            'rules_version_id'          => $track->rules_version_id !== null ? (int) $track->rules_version_id : null,
            'rules_fingerprint'         => (string) $track->rules_fingerprint,
        ];
    }

    private function newEntryNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'EN'.strtoupper(bin2hex(random_bytes(8)));

            if (! CompetitionEntry::query()->where('entry_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('无法生成唯一参赛编号。');
    }
}
