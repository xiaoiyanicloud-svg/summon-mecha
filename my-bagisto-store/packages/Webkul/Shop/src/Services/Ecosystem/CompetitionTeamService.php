<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamInvite;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-03 · 队伍创建、邀请、接受、退出与队长移交。
 *
 * 并发协议：
 *   · 所有成员变更先锁赛项行，再锁队伍行，最后锁成员/邀请行；
 *   · 一人多队判断在该锁内完成，不靠前端或列表去重；
 *   · 邀请码一次性、只存 sha256，不提供按手机号/客户 ID 搜索；
 *   · 参赛快照冻结后，成员与队长变更一律拒绝（快照是当届事实）。
 */
class CompetitionTeamService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * 当前客户的队伍列表。只返回 active 成员关系：
     * 邀请中/已拒绝/已离开都不能读取队伍私有信息；加入必须用邀请码接受。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompetitionTeamMember>
     */
    public function membershipsFor(Customer $customer)
    {
        return CompetitionTeamMember::query()
            ->with(['team.track', 'team.edition'])
            ->where('customer_id', (int) $customer->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get();
    }

    public function accessibleTeam(int $teamId, Customer $customer): ?CompetitionTeam
    {
        // 只认 active 成员：邀请中/已拒绝/已离开都不能读取队伍私有信息。
        $member = CompetitionTeamMember::query()
            ->where('team_id', $teamId)
            ->where('customer_id', (int) $customer->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->first();

        if ($member === null) {
            return null;
        }

        return CompetitionTeam::query()->with(['track.scope', 'edition.event'])->find($teamId);
    }

    public function createTeam(Customer $customer, CompetitionTrack $track, string $name): CompetitionTeam
    {
        $this->rules->assertWriteEnabled();

        $name = trim($name);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw CompetitionException::invalid('队伍名称长度必须在 2~120 个字符之间。');
        }

        $nameKey = $this->nameKey($name);

        return DB::transaction(function () use ($customer, $track, $name, $nameKey): CompetitionTeam {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();

            $this->assertTrackUsable($lockedTrack);

            if (CompetitionTeam::query()
                ->where('track_id', $lockedTrack->getKey())
                ->where('name_key', $nameKey)
                ->exists()) {
                throw CompetitionException::teamNameTaken();
            }

            $this->assertMultiTeamAllowed($lockedTrack, (int) $customer->getKey(), null);

            $team = new CompetitionTeam();
            $team->fill([
                'edition_id'          => (int) $lockedTrack->edition_id,
                'track_id'            => (int) $lockedTrack->getKey(),
                'captain_customer_id' => (int) $customer->getKey(),
                'name'                => $name,
                'name_key'            => $nameKey,
                'status'              => CompetitionTeam::STATUS_ACTIVE,
                'row_version'         => 0,
            ]);
            $team->save();

            $member = new CompetitionTeamMember();
            $member->fill([
                'team_id'                => (int) $team->getKey(),
                'customer_id'            => (int) $customer->getKey(),
                'role'                   => CompetitionTeamMember::ROLE_CAPTAIN,
                'status'                 => CompetitionTeamMember::STATUS_ACTIVE,
                'invited_by_customer_id' => (int) $customer->getKey(),
                'invited_at'             => now('UTC'),
                'responded_at'           => now('UTC'),
                'joined_at'              => now('UTC'),
                'row_version'            => 0,
            ]);
            $member->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.team.created',
                'competition_team',
                (int) $team->getKey(),
                [
                    'track_id' => (int) $lockedTrack->getKey(),
                    'edition_id' => (int) $lockedTrack->edition_id,
                ],
            );

            return $team->refresh();
        });
    }

    /**
     * 签发一次性邀请码。原始 token 只在本次响应返回一次。
     *
     * @return array{invite: CompetitionTeamInvite, token: string}
     */
    public function issueInvite(CompetitionTeam $team, Customer $captain, int $expectedRowVersion): array
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($team, $captain, $expectedRowVersion): array {
            $lockedTrack = CompetitionTrack::query()->whereKey($team->track_id)->lockForUpdate()->firstOrFail();
            $lockedTeam = CompetitionTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedTeam->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $this->assertTrackUsable($lockedTrack);
            $this->assertCaptain($lockedTeam, (int) $captain->getKey());
            $this->assertNoFrozenEntry((int) $lockedTeam->getKey());

            $pending = CompetitionTeamInvite::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('status', CompetitionTeamInvite::STATUS_PENDING)
                ->count();

            if ($pending >= (int) config('ecosystem_competition.hard_limits.invites_per_team', 32)) {
                throw CompetitionException::invalid('该队伍待使用邀请码过多，请先撤销旧邀请。');
            }

            $token = bin2hex(random_bytes((int) config('ecosystem_competition.invite.token_bytes', 24)));

            $invite = new CompetitionTeamInvite();
            $invite->fill([
                'team_id'                => (int) $lockedTeam->getKey(),
                'token_hash'             => hash('sha256', $token),
                'status'                 => CompetitionTeamInvite::STATUS_PENDING,
                'expires_at'             => now('UTC')->addSeconds((int) config('ecosystem_competition.invite.ttl_seconds', 604800)),
                'created_by_customer_id' => (int) $captain->getKey(),
            ]);
            $invite->save();

            $this->logger->logOrFail(
                'customer',
                (int) $captain->getKey(),
                'competition.team.invite_issued',
                'competition_team_invite',
                (int) $invite->getKey(),
                ['team_id' => (int) $lockedTeam->getKey()],
            );

            return ['invite' => $invite->refresh(), 'token' => $token];
        });
    }

    public function acceptInvite(Customer $customer, string $token): CompetitionTeamMember
    {
        $this->rules->assertWriteEnabled();

        $token = trim($token);

        if ($token === '' || mb_strlen($token) > 128) {
            throw CompetitionException::inviteInvalid();
        }

        $hash = hash('sha256', $token);
        $candidate = CompetitionTeamInvite::query()->where('token_hash', $hash)->first();

        if ($candidate === null) {
            throw CompetitionException::inviteInvalid();
        }

        return DB::transaction(function () use ($candidate, $hash, $customer): CompetitionTeamMember {
            $team = CompetitionTeam::query()->findOrFail($candidate->team_id);
            $lockedTrack = CompetitionTrack::query()->whereKey($team->track_id)->lockForUpdate()->firstOrFail();
            $lockedTeam = CompetitionTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            $lockedInvite = CompetitionTeamInvite::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTrackUsable($lockedTrack);

            if ((string) $lockedTeam->status !== CompetitionTeam::STATUS_ACTIVE) {
                throw CompetitionException::teamNotFound('队伍已解散。');
            }

            if ((string) $lockedInvite->status !== CompetitionTeamInvite::STATUS_PENDING) {
                throw CompetitionException::inviteInvalid();
            }

            if ($lockedInvite->expires_at === null
                || Carbon::now('UTC')->greaterThan($lockedInvite->expires_at)) {
                $lockedInvite->status = CompetitionTeamInvite::STATUS_EXPIRED;
                $lockedInvite->save();

                throw CompetitionException::inviteExpired();
            }

            $this->assertNoFrozenEntry((int) $lockedTeam->getKey());

            $customerId = (int) $customer->getKey();
            $member = CompetitionTeamMember::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if ($member !== null && (string) $member->status === CompetitionTeamMember::STATUS_ACTIVE) {
                throw CompetitionException::memberState('你已经是该队伍的有效成员。');
            }

            if ($member !== null && (string) $member->status === CompetitionTeamMember::STATUS_INVITED
                && (int) $member->team_id !== (int) $lockedTeam->getKey()) {
                throw CompetitionException::memberState();
            }

            $this->assertMultiTeamAllowed($lockedTrack, $customerId, (int) $lockedTeam->getKey());
            $this->assertTeamCapacity($lockedTrack, $lockedTeam);

            if ($member === null) {
                $member = new CompetitionTeamMember();
                $member->team_id = (int) $lockedTeam->getKey();
                $member->customer_id = $customerId;
                $member->invited_at = $lockedInvite->created_at;
                $member->invited_by_customer_id = (int) $lockedInvite->created_by_customer_id;
                $member->row_version = 0;
            }

            $member->role = CompetitionTeamMember::ROLE_MEMBER;
            $member->status = CompetitionTeamMember::STATUS_ACTIVE;
            $member->responded_at = now('UTC');
            $member->joined_at = now('UTC');
            $member->left_at = null;
            $member->row_version = (int) $member->row_version + 1;
            $member->save();

            $lockedInvite->status = CompetitionTeamInvite::STATUS_ACCEPTED;
            $lockedInvite->accepted_by_customer_id = $customerId;
            $lockedInvite->accepted_at = now('UTC');
            $lockedInvite->save();

            $this->logger->logOrFail(
                'customer',
                $customerId,
                'competition.team.invite_accepted',
                'competition_team_member',
                (int) $member->getKey(),
                ['team_id' => (int) $lockedTeam->getKey()],
            );

            return $member->refresh();
        });
    }

    public function declineInvite(Customer $customer, string $token): CompetitionTeamInvite
    {
        $this->rules->assertWriteEnabled();

        $hash = hash('sha256', trim($token));
        $candidate = CompetitionTeamInvite::query()->where('token_hash', $hash)->first();

        if ($candidate === null) {
            throw CompetitionException::inviteInvalid();
        }

        return DB::transaction(function () use ($candidate, $hash, $customer): CompetitionTeamInvite {
            $teamId = (int) $candidate->team_id;
            CompetitionTeam::query()->whereKey($teamId)->lockForUpdate()->firstOrFail();
            $invite = CompetitionTeamInvite::query()->where('token_hash', $hash)->lockForUpdate()->firstOrFail();

            if ((string) $invite->status !== CompetitionTeamInvite::STATUS_PENDING) {
                throw CompetitionException::inviteInvalid();
            }

            $member = CompetitionTeamMember::query()
                ->where('team_id', $teamId)
                ->where('customer_id', (int) $customer->getKey())
                ->lockForUpdate()
                ->first();

            if ($member !== null && (string) $member->status === CompetitionTeamMember::STATUS_INVITED) {
                $member->status = CompetitionTeamMember::STATUS_DECLINED;
                $member->responded_at = now('UTC');
                $member->row_version = (int) $member->row_version + 1;
                $member->save();
            }

            $invite->status = 'declined';
            $invite->accepted_by_customer_id = (int) $customer->getKey();
            $invite->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.team.invite_declined',
                'competition_team_invite',
                (int) $invite->getKey(),
                ['team_id' => $teamId],
            );

            return $invite->refresh();
        });
    }

    public function leave(CompetitionTeam $team, Customer $customer, int $expectedRowVersion): CompetitionTeamMember
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($team, $customer, $expectedRowVersion): CompetitionTeamMember {
            CompetitionTrack::query()->whereKey($team->track_id)->lockForUpdate()->firstOrFail();
            $lockedTeam = CompetitionTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedTeam->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((int) $lockedTeam->captain_customer_id === (int) $customer->getKey()) {
                throw CompetitionException::memberState('队长不能直接退出；请先移交队长或解散队伍。');
            }

            $this->assertNoFrozenEntry((int) $lockedTeam->getKey());

            $member = CompetitionTeamMember::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('customer_id', (int) $customer->getKey())
                ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw CompetitionException::memberState('当前不是该队伍的有效成员。');
            }

            $member->status = CompetitionTeamMember::STATUS_LEFT;
            $member->left_at = now('UTC');
            $member->row_version = (int) $member->row_version + 1;
            $member->save();

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.team.member_left',
                'competition_team_member',
                (int) $member->getKey(),
                ['team_id' => (int) $lockedTeam->getKey()],
            );

            return $member->refresh();
        });
    }

    public function transferCaptain(
        CompetitionTeam $team,
        Customer $captain,
        int $newCaptainCustomerId,
        int $expectedRowVersion,
    ): CompetitionTeam {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($team, $captain, $newCaptainCustomerId, $expectedRowVersion): CompetitionTeam {
            CompetitionTrack::query()->whereKey($team->track_id)->lockForUpdate()->firstOrFail();
            $lockedTeam = CompetitionTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedTeam->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $this->assertCaptain($lockedTeam, (int) $captain->getKey());
            $this->assertNoFrozenEntry((int) $lockedTeam->getKey());

            if ($newCaptainCustomerId === (int) $captain->getKey()) {
                throw CompetitionException::memberState('目标成员已经是队长。');
            }

            $newCaptain = CompetitionTeamMember::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('customer_id', $newCaptainCustomerId)
                ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($newCaptain === null) {
                throw CompetitionException::memberState('目标成员不是该队伍的有效成员。');
            }

            $old = CompetitionTeamMember::query()
                ->where('team_id', $lockedTeam->getKey())
                ->where('customer_id', (int) $captain->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $old->role = CompetitionTeamMember::ROLE_MEMBER;
            $old->row_version = (int) $old->row_version + 1;
            $old->save();

            $newCaptain->role = CompetitionTeamMember::ROLE_CAPTAIN;
            $newCaptain->row_version = (int) $newCaptain->row_version + 1;
            $newCaptain->save();

            $lockedTeam->captain_customer_id = $newCaptainCustomerId;
            $lockedTeam->row_version = (int) $lockedTeam->row_version + 1;
            $lockedTeam->save();

            $this->logger->logOrFail(
                'customer',
                (int) $captain->getKey(),
                'competition.team.captain_transferred',
                'competition_team',
                (int) $lockedTeam->getKey(),
                ['from' => (int) $captain->getKey(), 'to' => $newCaptainCustomerId],
            );

            return $lockedTeam->refresh();
        });
    }

    /**
     * @return array<int, int>
     */
    public function activeMemberIds(CompetitionTeam $team): array
    {
        return CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->orderBy('id')
            ->pluck('customer_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assertTrackUsable(CompetitionTrack $track): void
    {
        if ((string) $track->status !== 'active') {
            throw CompetitionException::trackNotActive();
        }

        $this->rules->assertTrackReady($track);

        if ($track->max_team_size === null) {
            throw CompetitionException::rulesUnconfirmed('赛项未确认队伍人数上限。');
        }

        $this->assertEditionPublic($track);
    }

    /**
     * 队伍操作必须发生在已公开的活动下：草稿/暂缓活动的赛项不对客户开放。
     */
    private function assertEditionPublic(CompetitionTrack $track): void
    {
        $edition = \Webkul\Shop\Models\CompetitionEdition::query()->find($track->edition_id);
        $event = $edition !== null
            ? \Webkul\Shop\Models\Event::query()->find($edition->event_id)
            : null;

        if ($event === null
            || (string) $event->publish_status !== 'published'
            || (string) $event->disposition !== 'normal') {
            throw CompetitionException::trackNotActive('赛事尚未公开发布，暂不能组队。');
        }
    }

    private function assertCaptain(CompetitionTeam $team, int $customerId): void
    {
        if ((int) $team->captain_customer_id !== $customerId) {
            throw CompetitionException::teamForbidden('只有队长可以执行该操作。');
        }
    }

    private function assertMultiTeamAllowed(CompetitionTrack $track, int $customerId, ?int $exceptTeamId): void
    {
        if ((bool) $track->allow_multi_team) {
            return;
        }

        $exists = CompetitionTeamMember::query()
            ->join('competition_teams', 'competition_teams.id', '=', 'competition_team_members.team_id')
            ->where('competition_teams.track_id', $track->getKey())
            ->where('competition_team_members.customer_id', $customerId)
            ->whereIn('competition_team_members.status', [
                CompetitionTeamMember::STATUS_INVITED,
                CompetitionTeamMember::STATUS_ACTIVE,
            ])
            ->when($exceptTeamId !== null, fn ($query) => $query->where('competition_teams.id', '!=', $exceptTeamId))
            ->exists();

        if ($exists) {
            throw CompetitionException::multiTeamNotAllowed();
        }
    }

    private function assertTeamCapacity(CompetitionTrack $track, CompetitionTeam $team): void
    {
        $active = CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
            ->count();

        if ($track->max_team_size !== null && $active + 1 > (int) $track->max_team_size) {
            throw CompetitionException::teamFull();
        }
    }

    private function assertNoFrozenEntry(int $teamId): void
    {
        $frozen = CompetitionEntry::query()
            ->where('team_id', $teamId)
            ->whereNotNull('frozen_at')
            ->exists();

        if ($frozen) {
            throw CompetitionException::entryFrozen('该队伍参赛成员快照已冻结，不能变更成员或队长。');
        }
    }

    private function nameKey(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_substr(mb_strtolower($normalized), 0, 64);
    }
}
