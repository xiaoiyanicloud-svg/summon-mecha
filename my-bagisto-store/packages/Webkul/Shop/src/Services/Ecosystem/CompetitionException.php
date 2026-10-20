<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * 赛务域业务异常（G3-01 ~ G3-06）。
 *
 * 与 RegistrationException 相同：只携带稳定错误码、HTTP 语义和原因码，
 * 由 API/Admin 控制器统一映射；服务层不依赖 HTTP。
 */
class CompetitionException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly int $httpStatus,
        string $message,
        private readonly ?string $reason = null,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public static function disabled(string $message = '赛务功能暂未开放。'): self
    {
        return new self('COMPETITION_DISABLED', 403, $message, 'competition_disabled');
    }

    public static function notFound(string $message = '赛务对象不存在。'): self
    {
        return new self('COMPETITION_NOT_FOUND', 404, $message, 'not_found');
    }

    public static function trackNotFound(string $message = '赛项不存在或未公开。'): self
    {
        return new self('COMPETITION_TRACK_NOT_FOUND', 404, $message, 'track_not_found');
    }

    public static function trackNotActive(string $message = '赛项尚未开放。'): self
    {
        return new self('COMPETITION_TRACK_NOT_ACTIVE', 409, $message, 'track_not_active');
    }

    public static function rulesUnconfirmed(string $message = '赛项规则尚未完整确认，不能执行该动作。'): self
    {
        return new self('COMPETITION_RULES_UNCONFIRMED', 422, $message, 'rules_unconfirmed');
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function rulesIncomplete(array $missing): self
    {
        return new self(
            'COMPETITION_RULES_UNCONFIRMED',
            422,
            '赛项规则契约不完整：'.implode(', ', $missing).'。',
            'rules_incomplete',
            ['missing' => $missing],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function eligibilityFailed(string $message, array $details = []): self
    {
        return new self('COMPETITION_ELIGIBILITY_FAILED', 422, $message, 'eligibility_failed', $details);
    }

    public static function teamNotFound(string $message = '队伍不存在。'): self
    {
        return new self('COMPETITION_TEAM_NOT_FOUND', 404, $message, 'team_not_found');
    }

    public static function teamForbidden(string $message = '无权执行该队伍操作。'): self
    {
        return new self('COMPETITION_TEAM_FORBIDDEN', 403, $message, 'team_forbidden');
    }

    public static function teamNameTaken(string $message = '该赛项内队伍名已存在。'): self
    {
        return new self('COMPETITION_TEAM_NAME_TAKEN', 409, $message, 'team_name_taken');
    }

    public static function teamFull(string $message = '队伍人数已达规则上限。'): self
    {
        return new self('COMPETITION_TEAM_FULL', 422, $message, 'team_full');
    }

    public static function memberState(string $message = '当前成员状态不允许该操作。'): self
    {
        return new self('COMPETITION_TEAM_MEMBER_STATE', 409, $message, 'member_state');
    }

    public static function inviteInvalid(string $message = '邀请码无效或已被使用。'): self
    {
        return new self('COMPETITION_INVITE_INVALID', 422, $message, 'invite_invalid');
    }

    public static function inviteExpired(string $message = '邀请码已过期。'): self
    {
        return new self('COMPETITION_INVITE_EXPIRED', 422, $message, 'invite_expired');
    }

    public static function multiTeamNotAllowed(string $message = '该赛项不允许同一客户加入多支队伍。'): self
    {
        return new self('COMPETITION_MULTI_TEAM_NOT_ALLOWED', 422, $message, 'multi_team_not_allowed');
    }

    public static function entryNotFound(string $message = '参赛条目不存在。'): self
    {
        return new self('COMPETITION_ENTRY_NOT_FOUND', 404, $message, 'entry_not_found');
    }

    public static function entryNotActive(string $message = '参赛尚未确认，不能执行该操作。'): self
    {
        return new self('COMPETITION_ENTRY_NOT_ACTIVE', 409, $message, 'entry_not_active');
    }

    public static function entryFrozen(string $message = '参赛成员快照已冻结，不能直接改写。'): self
    {
        return new self('COMPETITION_ENTRY_FROZEN', 409, $message, 'entry_frozen');
    }

    public static function submissionNotFound(string $message = '作品不存在。'): self
    {
        return new self('COMPETITION_SUBMISSION_NOT_FOUND', 404, $message, 'submission_not_found');
    }

    public static function submissionClosed(string $message = '作品提交已截止或尚未配置截止时间。'): self
    {
        return new self('COMPETITION_SUBMISSION_CLOSED', 422, $message, 'submission_closed');
    }

    public static function submissionVersionStale(string $message = '作品已被他人修改，请刷新后重试。'): self
    {
        return new self('COMPETITION_SUBMISSION_VERSION_STALE', 409, $message, 'version_stale');
    }

    public static function submissionImmutable(string $message = '已提交版本不可修改；请创建补正版本。'): self
    {
        return new self('COMPETITION_SUBMISSION_IMMUTABLE', 409, $message, 'immutable');
    }

    public static function attachmentInvalid(string $message): self
    {
        return new self('COMPETITION_ATTACHMENT_INVALID', 422, $message, 'attachment_invalid');
    }

    public static function attachmentNotFound(string $message = '附件不存在。'): self
    {
        return new self('COMPETITION_ATTACHMENT_NOT_FOUND', 404, $message, 'attachment_not_found');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function scheduleInvalid(string $message, array $rows = []): self
    {
        return new self('COMPETITION_SCHEDULE_INVALID', 422, $message, 'schedule_invalid', ['rows' => $rows]);
    }

    public static function schedulePublished(string $message = '已发布赛程不允许被导入覆盖。'): self
    {
        return new self('COMPETITION_SCHEDULE_PUBLISHED', 409, $message, 'schedule_published');
    }

    public static function versionStale(string $message = '数据已被他人修改，请刷新后重试。'): self
    {
        return new self('COMPETITION_VERSION_STALE', 409, $message, 'version_stale');
    }

    public static function resultNotFound(string $message = '成绩记录不存在。'): self
    {
        return new self('COMPETITION_RESULT_NOT_FOUND', 404, $message, 'result_not_found');
    }

    public static function resultState(string $message = '当前成绩状态不允许该操作。'): self
    {
        return new self('COMPETITION_RESULT_STATE', 409, $message, 'result_state');
    }

    public static function dutySeparation(string $message = '录分人与复核发布人必须分离。'): self
    {
        return new self('COMPETITION_DUTY_SEPARATION', 403, $message, 'duty_separation');
    }

    public static function rankingRequired(string $message = '存在未确定名次的参赛条目，不能发布成绩。'): self
    {
        return new self('COMPETITION_RANKING_REQUIRED', 422, $message, 'ranking_required');
    }

    public static function rankingInvalid(string $message): self
    {
        return new self('COMPETITION_RANKING_INVALID', 422, $message, 'ranking_invalid');
    }

    public static function appealNotFound(string $message = '申诉不存在。'): self
    {
        return new self('COMPETITION_APPEAL_NOT_FOUND', 404, $message, 'appeal_not_found');
    }

    public static function appealForbidden(string $message = '无权提交或查看该申诉。'): self
    {
        return new self('COMPETITION_APPEAL_FORBIDDEN', 403, $message, 'appeal_forbidden');
    }

    public static function appealClosed(string $message = '申诉已过期或当前状态不允许该操作。'): self
    {
        return new self('COMPETITION_APPEAL_CLOSED', 422, $message, 'appeal_closed');
    }

    public static function appealEvidenceInvalid(string $message): self
    {
        return new self('COMPETITION_APPEAL_EVIDENCE_INVALID', 422, $message, 'evidence_invalid');
    }

    public static function appealResultChanged(string $message = '成绩已发布新版本，请基于最新结果重新裁决。'): self
    {
        return new self('COMPETITION_APPEAL_RESULT_CHANGED', 409, $message, 'result_changed');
    }

    public static function awardNotFound(string $message = '奖项不存在。'): self
    {
        return new self('COMPETITION_AWARD_NOT_FOUND', 404, $message, 'award_not_found');
    }

    public static function awardState(string $message = '当前奖项状态不允许该操作。'): self
    {
        return new self('COMPETITION_AWARD_STATE', 409, $message, 'award_state');
    }

    public static function showcaseNotFound(string $message = '展示内容不存在。'): self
    {
        return new self('COMPETITION_SHOWCASE_NOT_FOUND', 404, $message, 'showcase_not_found');
    }

    public static function showcaseState(string $message = '当前展示状态不允许该操作。'): self
    {
        return new self('COMPETITION_SHOWCASE_STATE', 409, $message, 'showcase_state');
    }

    public static function consentRequired(string $message = '队长尚未对当前作品版本授权公开展示。'): self
    {
        return new self('COMPETITION_CONSENT_REQUIRED', 422, $message, 'consent_required');
    }

    public static function invalid(string $message): self
    {
        return new self('COMPETITION_VALIDATION_ERROR', 422, $message, 'invalid');
    }
}
