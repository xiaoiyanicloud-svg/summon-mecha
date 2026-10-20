<?php

namespace Webkul\Shop\Http\Resources\Ecosystem;

use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmission;
use Webkul\Shop\Models\CompetitionSubmissionAttachment;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;

/**
 * G3 赛务 DTO 构造器。
 *
 * 只输出页面需要的字段；绝不直接 serialize 模型：
 *   · 不返回 storage_key / sha256 / 内部审核备注 / 规则安全联系引用；
 *   · 队伍成员只返回 customer_id + 显示名 + 角色/状态；
 *   · 作品描述是纯文本，不渲染 HTML。
 */
final class CompetitionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function team(CompetitionTeam $team, ?CompetitionTeamMember $viewer = null, ?CompetitionEntry $entry = null, ?CompetitionSubmission $submission = null, int $pendingInvites = 0): array
    {
        $team->loadMissing(['track.scope', 'edition', 'members.customer']);

        $members = $team->members
            ->map(static fn (CompetitionTeamMember $member): array => [
                'customer_id' => (int) $member->customer_id,
                'display_name'=> $member->customer !== null
                    ? trim((string) $member->customer->first_name.' '.(string) $member->customer->last_name)
                    : '',
                'role'        => (string) $member->role,
                'status'      => (string) $member->status,
                'joined_at'   => $member->joined_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'id'           => (int) $team->getKey(),
            'name'         => (string) $team->name,
            'status'       => (string) $team->status,
            'row_version'  => (int) $team->row_version,
            'my_role'      => $viewer !== null ? (string) $viewer->role : null,
            'is_captain'   => $viewer !== null && (string) $viewer->role === CompetitionTeamMember::ROLE_CAPTAIN,
            'edition'      => [
                'id'         => (int) $team->edition_id,
                'edition_no' => $team->edition?->edition_no,
                'name'       => $team->edition?->name,
            ],
            'track'        => self::track($team->track),
            'members'      => $members,
            'pending_invites' => max(0, $pendingInvites),
            'entry'        => $entry !== null ? self::entry($entry) : null,
            'submission'   => $submission !== null ? [
                'id'              => (int) $submission->getKey(),
                'status'          => (string) $submission->status,
                'deadline_at'     => $submission->deadline_at?->toIso8601String(),
                'current_version_id' => $submission->current_version_id !== null ? (int) $submission->current_version_id : null,
                'submitted_at'    => $submission->submitted_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function track(?CompetitionTrack $track): ?array
    {
        if ($track === null) {
            return null;
        }

        $track->loadMissing('scope');
        $scope = $track->scope;

        return [
            'id'               => (int) $track->getKey(),
            'track_key'        => (string) $track->track_key,
            'name'             => (string) $track->name,
            'discipline'       => $track->discipline,
            'summary'          => $track->summary,
            'status'           => (string) $track->status,
            'min_team_size'    => $track->min_team_size,
            'max_team_size'    => $track->max_team_size,
            'allow_multi_team' => (bool) $track->allow_multi_team,
            'allow_correction' => (bool) $track->allow_correction,
            'submission_deadline_at' => $track->submission_deadline_at?->toIso8601String(),
            'rules_confirmed'  => $track->rulesConfirmed(),
            // 报名表单版本与公开同意摘要：队伍报名提交必须带版本号，不能猜。
            'registration'     => [
                'status'          => $scope?->status,
                'review_mode'     => $scope?->review_mode,
                'form_version'    => $scope?->form_version,
                'privacy_version' => $scope?->privacy_version,
                'privacy_summary' => $scope?->privacy_summary,
                'opens_at'        => $scope?->registration_opens_at?->toIso8601String(),
                'closes_at'       => $scope?->registration_closes_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(CompetitionEntry $entry): array
    {
        $snapshot = (array) $entry->eligibility_snapshot;
        $roster = (array) $entry->roster_snapshot;

        return [
            'id'            => (int) $entry->getKey(),
            'entry_no'      => (string) $entry->entry_no,
            'status'        => (string) $entry->status,
            'row_version'   => (int) $entry->row_version,
            'frozen_at'     => $entry->frozen_at?->toIso8601String(),
            'member_count'  => count($roster),
            'min_members'   => $snapshot['min_members'] ?? null,
            'max_members'   => $snapshot['max_members'] ?? null,
            'rules_version_id' => $entry->rules_version_id,
            'rules_fingerprint' => $entry->rules_fingerprint,
            'robot_declaration_present' => $entry->robot_declaration !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function submission(CompetitionSubmission $submission): array
    {
        $versions = collect();

        if ($submission->exists) {
            $submission->loadMissing(['versions.attachments']);

            $versions = $submission->versions
                ->sortByDesc('version_no')
                ->values()
                ->map(static fn (CompetitionSubmissionVersion $version): array => [
                'id'            => (int) $version->getKey(),
                'version_no'    => (int) $version->version_no,
                'title'         => (string) $version->title,
                'summary'       => $version->summary,
                'description'   => $version->description,
                'repo_url'      => $version->repo_url,
                'video_url'     => $version->video_url,
                'status'        => (string) $version->status,
                'submitted_at'  => $version->submitted_at?->toIso8601String(),
                'attachments'   => $version->attachments
                    ->where('status', CompetitionSubmissionAttachment::STATUS_ACTIVE)
                    ->map(static fn (CompetitionSubmissionAttachment $attachment): array => [
                        'id'            => (int) $attachment->getKey(),
                        'original_name' => (string) $attachment->original_name,
                        'mime'          => (string) $attachment->mime,
                        'byte_size'     => (int) $attachment->byte_size,
                        'download_url'  => '/api/customer/competition/attachments/'.(int) $attachment->getKey(),
                    ])
                    ->values()
                    ->all(),
                ])
                ->all();
        }

        return [
            'id'             => (int) $submission->getKey(),
            'entry_id'       => (int) $submission->entry_id,
            'status'         => (string) $submission->status,
            'deadline_at'    => $submission->deadline_at?->toIso8601String(),
            'submitted_at'   => $submission->submitted_at?->toIso8601String(),
            'row_version'    => (int) $submission->row_version,
            'current_version_id' => $submission->current_version_id !== null ? (int) $submission->current_version_id : null,
            'versions'       => $versions,
        ];
    }
}
