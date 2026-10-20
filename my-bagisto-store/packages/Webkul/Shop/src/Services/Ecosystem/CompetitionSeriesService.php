<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionEdition;
use Webkul\Shop\Models\CompetitionSeries;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-01 / G3-02 · 系列、届次、赛项与规则确认。
 *
 * 写入口统一遵守：
 *   · 全局写闸 `ecosystem_competition.write_enabled`；
 *   · 事务内按 series → edition → event → track 顺序加锁；
 *   · 规则完整性由 CompetitionRuleProfile 校验，缺项不补默认；
 *   · 规则/规模实质变化会清除确认并重算指纹，旧参赛快照继续引用旧指纹。
 */
class CompetitionSeriesService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly RegistrationScopeService $scopes,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSeries(array $data, int $adminId): CompetitionSeries
    {
        $this->rules->assertWriteEnabled();

        $name = trim((string) ($data['name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw CompetitionException::invalid('系列名称长度必须在 2~120 个字符之间。');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,98}$/', $slug)) {
            throw CompetitionException::invalid('系列 slug 只允许小写字母、数字和连字符，长度 2~99。');
        }

        $organizerId = isset($data['organizer_id']) ? (int) $data['organizer_id'] : null;

        if ($organizerId !== null && ! DB::table('ecosystem_organizers')->where('id', $organizerId)->exists()) {
            throw CompetitionException::invalid('主办主体不存在。');
        }

        $organizerName = trim((string) ($data['organizer_name'] ?? ''));

        if ($organizerName === '' && $organizerId !== null) {
            $organizerName = (string) DB::table('ecosystem_organizers')->where('id', $organizerId)->value('name');
        }

        if ($organizerName === '') {
            throw CompetitionException::invalid('必须提供系列主办主体名称。');
        }

        return DB::transaction(function () use ($data, $name, $slug, $organizerId, $organizerName, $adminId): CompetitionSeries {
            if (CompetitionSeries::query()->where('slug', $slug)->exists()) {
                throw CompetitionException::invalid('系列 slug 已存在。');
            }

            $series = new CompetitionSeries();
            $series->fill([
                'organizer_id'   => $organizerId,
                'organizer_name' => $organizerName,
                'name'           => $name,
                'name_key'       => $this->nameKey($name),
                'slug'           => $slug,
                'description'    => isset($data['description']) ? trim((string) $data['description']) : null,
                'status'         => 'draft',
                'row_version'    => 0,
            ]);
            $series->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.series.created',
                'competition_series',
                (int) $series->getKey(),
                ['slug' => $slug],
            );

            return $series->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEdition(CompetitionSeries $series, Event $event, array $data, int $adminId): CompetitionEdition
    {
        $this->rules->assertWriteEnabled();

        $editionNo = (int) ($data['edition_no'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $timezone = trim((string) ($data['timezone'] ?? 'Asia/Shanghai'));

        if ($editionNo < 1) {
            throw CompetitionException::invalid('届次编号必须是正整数。');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw CompetitionException::invalid('届次名称不能为空且不得超过 120 个字符。');
        }

        if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw CompetitionException::invalid('届次时区必须是合法 IANA 名称。');
        }

        [$startsAt, $endsAt] = $this->parseRange($data['starts_at'] ?? null, $data['ends_at'] ?? null);

        return DB::transaction(function () use ($series, $event, $editionNo, $name, $timezone, $startsAt, $endsAt, $adminId): CompetitionEdition {
            $lockedSeries = CompetitionSeries::query()->whereKey($series->getKey())->lockForUpdate()->firstOrFail();
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedEvent->kind !== 'competition') {
                throw CompetitionException::invalid('只有 kind=competition 的活动可以建立赛务届次。');
            }

            if (CompetitionEdition::query()->where('event_id', $lockedEvent->getKey())->exists()) {
                throw CompetitionException::invalid('该活动已经绑定了一个届次。');
            }

            if (CompetitionEdition::query()
                ->where('series_id', $lockedSeries->getKey())
                ->where('edition_no', $editionNo)
                ->exists()) {
                throw CompetitionException::invalid('同一系列内届次编号已存在。');
            }

            $edition = new CompetitionEdition();
            $edition->fill([
                'series_id'   => (int) $lockedSeries->getKey(),
                'event_id'    => (int) $lockedEvent->getKey(),
                'edition_no'  => $editionNo,
                'name'        => $name,
                'starts_at'   => $startsAt,
                'ends_at'     => $endsAt,
                'timezone'    => $timezone,
                'status'      => 'draft',
                'row_version' => 0,
            ]);
            $edition->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.edition.created',
                'competition_edition',
                (int) $edition->getKey(),
                [
                    'series_id' => (int) $lockedSeries->getKey(),
                    'event_id'  => (int) $lockedEvent->getKey(),
                    'edition_no'=> $editionNo,
                ],
            );

            return $edition->refresh();
        });
    }

    /**
     * 创建赛项。
     *
     * `scope` 必须提供本赛项专属报名范围属性；如果同时提供 `registration_scope_id`，
     * 则复用已存在的 scope（仍会校验归属/单位）。两者都没有时拒绝。
     *
     * @param  array<string, mixed>  $data
     */
    public function createTrack(CompetitionEdition $edition, array $data, int $adminId): CompetitionTrack
    {
        $this->rules->assertWriteEnabled();

        $trackKey = trim((string) ($data['track_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,49}$/', $trackKey)) {
            throw CompetitionException::invalid('赛项 key 只允许字母、数字、下划线、连字符，长度 1~50。');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw CompetitionException::invalid('赛项名称不能为空且不得超过 120 个字符。');
        }

        $rulesVersionId = isset($data['rules_version_id']) ? (int) $data['rules_version_id'] : null;
        $profile = isset($data['rules_profile']) && is_array($data['rules_profile'])
            ? $data['rules_profile']
            : null;
        $confirmRules = (bool) ($data['confirm_rules'] ?? false);

        return DB::transaction(function () use (
            $edition,
            $data,
            $trackKey,
            $name,
            $rulesVersionId,
            $profile,
            $confirmRules,
            $adminId,
        ): CompetitionTrack {
            $lockedEdition = CompetitionEdition::query()->whereKey($edition->getKey())->lockForUpdate()->firstOrFail();
            $event = Event::query()->whereKey($lockedEdition->event_id)->lockForUpdate()->firstOrFail();

            if (CompetitionTrack::query()
                ->where('edition_id', $lockedEdition->getKey())
                ->where('track_key', $trackKey)
                ->exists()) {
                throw CompetitionException::invalid('同一届次内赛项 key 已存在。');
            }

            $track = new CompetitionTrack();
            $track->fill([
                'edition_id'    => (int) $lockedEdition->getKey(),
                'track_key'     => $trackKey,
                'name'          => $name,
                'discipline'    => isset($data['discipline']) ? trim((string) $data['discipline']) : null,
                'summary'       => isset($data['summary']) ? trim((string) $data['summary']) : null,
                'status'        => 'draft',
                'allow_multi_team' => (bool) ($data['allow_multi_team'] ?? false),
                'allow_correction' => (bool) ($data['allow_correction'] ?? true),
                'submission_deadline_at' => $this->parseUtc($data['submission_deadline_at'] ?? null),
                'row_version'   => 0,
            ]);

            // 报名范围：优先复用显式 scope_id，否则按 track_key 新建。
            if (isset($data['registration_scope_id'])) {
                $scope = EventRegistrationScope::query()
                    ->whereKey((int) $data['registration_scope_id'])
                    ->lockForUpdate()
                    ->first();

                $this->assertScopeFitsTrack($scope, $lockedEdition);

                if ($rulesVersionId !== null && (int) $scope->rule_version_id !== $rulesVersionId) {
                    throw CompetitionException::invalid('复用报名范围时必须绑定与赛项相同的规则版本。');
                }

                $track->registration_scope_id = (int) $scope->getKey();
            } else {
                $scopeAttributes = isset($data['scope']) && is_array($data['scope']) ? $data['scope'] : [];

                if ($scopeAttributes === []) {
                    throw CompetitionException::invalid('创建赛项必须提供 scope 属性或 registration_scope_id。');
                }

                if ($rulesVersionId !== null) {
                    $scopeAttributes['rule_version_id'] = $rulesVersionId;
                }

                $scope = $this->scopes->createForTrack($event, $trackKey, $scopeAttributes, $adminId);
                $track->registration_scope_id = (int) $scope->getKey();
            }

            if ($rulesVersionId !== null || $profile !== null) {
                $version = $this->assertRuleVersion($lockedEdition, $rulesVersionId);

                if ($profile !== null) {
                    $profile = $this->rules->assertComplete($profile, $version);
                    $track->rules_profile = $profile;
                    $track->rules_fingerprint = $this->rules->fingerprint($profile, $version);
                    $track->min_team_size = (int) $profile['eligibility']['min_members'];
                    $track->max_team_size = (int) $profile['eligibility']['max_members'];

                    if ($confirmRules) {
                        if ($version === null) {
                            throw CompetitionException::invalid('确认规则必须绑定已批准的规则版本。');
                        }

                        $track->rules_confirmed_at = now('UTC');
                        $track->rules_confirmed_by = $adminId;
                    }
                }

                $track->rules_version_id = $rulesVersionId;
            }

            $this->assertTrackConfiguration($track);
            $track->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.track.created',
                'competition_track',
                (int) $track->getKey(),
                [
                    'edition_id' => (int) $lockedEdition->getKey(),
                    'track_key'  => $trackKey,
                    'scope_id'   => (int) $scope->getKey(),
                ],
            );

            return $track->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTrack(
        CompetitionTrack $track,
        int $expectedRowVersion,
        array $data,
        int $adminId,
    ): CompetitionTrack {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedRowVersion, $data, $adminId): CompetitionTrack {
            $scopeOverride = array_key_exists('registration_scope_id', $data)
                ? (int) $data['registration_scope_id']
                : null;

            [$event, $edition, $scope, $locked] = $this->lockTrackAggregate($track, $scopeOverride);

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $before = [
                'rules_version_id' => $locked->rules_version_id,
                'rules_fingerprint'=> $locked->rules_fingerprint,
                'min_team_size'    => $locked->min_team_size,
                'max_team_size'    => $locked->max_team_size,
            ];

            foreach (['name', 'discipline', 'summary'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = trim((string) $data[$field]);
                }
            }

            if (array_key_exists('allow_multi_team', $data)) {
                $locked->allow_multi_team = (bool) $data['allow_multi_team'];
            }

            if (array_key_exists('allow_correction', $data)) {
                $locked->allow_correction = (bool) $data['allow_correction'];
            }

            if (array_key_exists('submission_deadline_at', $data)) {
                $locked->submission_deadline_at = $this->parseUtc($data['submission_deadline_at']);
            }

            $rulesChanged = false;

            if (array_key_exists('rules_version_id', $data)) {
                $locked->rules_version_id = $this->assertRuleVersion($edition, isset($data['rules_version_id']) ? (int) $data['rules_version_id'] : null)?->getKey();
                $rulesChanged = (int) ($before['rules_version_id'] ?? 0) !== (int) ($locked->rules_version_id ?? 0);
            }

            if (array_key_exists('rules_profile', $data)) {
                $version = $locked->rules_version_id !== null
                    ? EventRuleVersion::query()->find($locked->rules_version_id)
                    : null;

                $profile = is_array($data['rules_profile']) ? $data['rules_profile'] : [];
                $profile = $this->rules->assertComplete($profile, $version);
                $locked->rules_profile = $profile;
                $locked->rules_fingerprint = $this->rules->fingerprint($profile, $version);
                $locked->min_team_size = (int) $profile['eligibility']['min_members'];
                $locked->max_team_size = (int) $profile['eligibility']['max_members'];
                $rulesChanged = true;
            }

            if ($rulesChanged) {
                // 规则实质变化必须重新确认；旧 entry 仍引用旧指纹。
                $locked->rules_confirmed_at = null;
                $locked->rules_confirmed_by = null;
            }

            if (array_key_exists('registration_scope_id', $data)) {
                $this->assertScopeFitsTrack($scope, $edition, (int) $locked->getKey());
                $locked->registration_scope_id = (int) $scope->getKey();
            }

            $this->assertTrackConfiguration($locked);
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.track.updated',
                'competition_track',
                (int) $locked->getKey(),
                [
                    'fields'        => array_keys($data),
                    'rules_changed' => $rulesChanged,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * 显式确认规则（负责人签字后的工程动作）。
     *
     * @param  array<string, mixed>  $profile
     */
    public function confirmRules(
        CompetitionTrack $track,
        int $expectedRowVersion,
        array $profile,
        int $rulesVersionId,
        int $adminId,
    ): CompetitionTrack {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedRowVersion, $profile, $rulesVersionId, $adminId): CompetitionTrack {
            [$event, $edition, $scope, $locked] = $this->lockTrackAggregate($track);

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $version = $this->assertRuleVersion($edition, $rulesVersionId);
            $profile = $this->rules->assertComplete($profile, $version);

            $locked->rules_version_id = (int) $version->getKey();
            $locked->rules_profile = $profile;
            $locked->rules_fingerprint = $this->rules->fingerprint($profile, $version);
            $locked->min_team_size = (int) $profile['eligibility']['min_members'];
            $locked->max_team_size = (int) $profile['eligibility']['max_members'];
            $locked->rules_confirmed_at = now('UTC');
            $locked->rules_confirmed_by = $adminId;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            // 报名 scope 必须与赛项引用同一规则版本；确认规则时同步更新，
            // 并递增 form_version，让旧页面/旧同意在下次提交时重新取版本。
            if ($scope instanceof EventRegistrationScope) {
                $this->assertScopeFitsTrack($scope, $edition, (int) $locked->getKey());

                if ((int) $scope->rule_version_id !== (int) $version->getKey()) {
                    $scope->rule_version_id = (int) $version->getKey();
                    $scope->form_version = (int) $scope->form_version + 1;
                    $scope->row_version = (int) $scope->row_version + 1;
                    $scope->save();
                }
            }

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.track.rules_confirmed',
                'competition_track',
                (int) $locked->getKey(),
                [
                    'rules_version_id' => (int) $version->getKey(),
                    'rules_fingerprint'=> (string) $locked->rules_fingerprint,
                    'scope_synced'     => $scope instanceof EventRegistrationScope,
                ],
            );

            return $locked->refresh();
        });
    }

    public function activate(CompetitionTrack $track, int $expectedRowVersion, int $adminId): CompetitionTrack
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedRowVersion, $adminId): CompetitionTrack {
            [$event, $edition, $scope, $locked] = $this->lockTrackAggregate($track);

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $this->assertScopeFitsTrack($scope, $edition, (int) $locked->getKey());

            if ($scope->capacity_confirmed_at === null) {
                throw CompetitionException::invalid('赛项报名容量尚未确认，不能激活。');
            }

            $this->rules->assertTrackReady($locked);

            if ($locked->submission_deadline_at === null) {
                throw CompetitionException::invalid('赛项未配置作品提交截止时间，不能激活。');
            }

            if ((string) $locked->status === 'active') {
                throw CompetitionException::invalid('赛项已激活，无需重复操作。');
            }

            $locked->status = 'active';
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.track.activated',
                'competition_track',
                (int) $locked->getKey(),
                ['edition_id' => (int) $locked->edition_id],
            );

            return $locked->refresh();
        });
    }

    public function close(CompetitionTrack $track, int $expectedRowVersion, int $adminId, string $reason = ''): CompetitionTrack
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($track, $expectedRowVersion, $adminId, $reason): CompetitionTrack {
            [$event, $edition, $scope, $locked] = $this->lockTrackAggregate($track);

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            $locked->status = 'closed';
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.track.closed',
                'competition_track',
                (int) $locked->getKey(),
                ['reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    /**
     * 统一锁顺序：event → scope → track。
     *
     * 与队伍报名（event → scope → track → team → …）保持一致，避免
     * “后台先锁 track 再锁 scope”与报名事务形成环。
     *
     * @return array{0: Event, 1: CompetitionEdition, 2: EventRegistrationScope|null, 3: CompetitionTrack}
     */
    private function lockTrackAggregate(CompetitionTrack $track, ?int $scopeIdOverride = null): array
    {
        $edition = CompetitionEdition::query()->findOrFail($track->edition_id);
        $event = Event::query()->whereKey($edition->event_id)->lockForUpdate()->firstOrFail();

        $scopeId = $scopeIdOverride ?? ($track->registration_scope_id !== null
            ? (int) $track->registration_scope_id
            : null);

        $scope = $scopeId !== null
            ? EventRegistrationScope::query()->whereKey($scopeId)->lockForUpdate()->first()
            : null;

        $locked = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();

        return [$event, $edition, $scope, $locked];
    }

    private function assertScopeFitsTrack(
        ?EventRegistrationScope $scope,
        CompetitionEdition $edition,
        ?int $exceptTrackId = null,
    ): void {
        if (! $scope instanceof EventRegistrationScope) {
            throw CompetitionException::invalid('赛项报名范围不存在。');
        }

        if ((int) $scope->event_id !== (int) $edition->event_id) {
            throw CompetitionException::invalid('赛项报名范围不属于该届次的活动。');
        }

        if ((string) $scope->unit !== 'team') {
            throw CompetitionException::invalid('赛项报名范围必须使用 team 计量单位。');
        }

        $boundElsewhere = CompetitionTrack::query()
            ->where('registration_scope_id', $scope->getKey())
            ->when($exceptTrackId !== null, fn ($query) => $query->where('id', '!=', $exceptTrackId))
            ->exists();

        if ($boundElsewhere) {
            throw CompetitionException::invalid('该报名范围已被其他赛项占用。');
        }
    }

    private function assertRuleVersion(CompetitionEdition $edition, ?int $rulesVersionId): ?EventRuleVersion
    {
        if ($rulesVersionId === null) {
            return null;
        }

        $version = EventRuleVersion::query()
            ->whereKey($rulesVersionId)
            ->where('event_id', $edition->event_id)
            ->where('status', 'approved')
            ->first();

        if ($version === null) {
            throw CompetitionException::invalid('规则版本不存在、不属于该活动或未批准。');
        }

        return $version;
    }

    private function assertTrackConfiguration(CompetitionTrack $track): void
    {
        if ($track->min_team_size !== null && $track->max_team_size !== null
            && (int) $track->max_team_size < (int) $track->min_team_size) {
            throw CompetitionException::invalid('赛项最大队伍人数不得小于最小人数。');
        }

        if ($track->name === null || trim((string) $track->name) === '') {
            throw CompetitionException::invalid('赛项名称不能为空。');
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseRange(mixed $startsAt, mixed $endsAt): array
    {
        $start = $this->parseUtc($startsAt);
        $end = $this->parseUtc($endsAt);

        if (($start === null) !== ($end === null)) {
            throw CompetitionException::invalid('届次起止时间必须同时提供或同时留空。');
        }

        if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
            throw CompetitionException::invalid('届次结束时间必须晚于开始时间。');
        }

        return [$start, $end];
    }

    private function parseUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            throw CompetitionException::invalid('时间格式不合法：'.(string) $value);
        }
    }

    private function nameKey(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_substr(mb_strtolower($normalized), 0, 64);
    }
}
