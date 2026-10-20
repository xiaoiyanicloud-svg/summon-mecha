<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventChange;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G2-01 · 活动报名范围配置与激活。
 *
 * 所有写动作都在活动行 + scope 行的锁内完成：
 *   · 激活必须显式确认政策与容量（policy_confirmed_at / capacity_confirmed_at）；
 *   · 容量不得低于已占席位；
 *   · 计量单位创建后不可改（改单位等于改业务含义，应新建活动）；
 *   · 表单/隐私/规则版本变化时递增 form_version，旧 attempt 保留自己的快照。
 *
 * 本服务是 scope 的唯一写入口；Admin 控制器只做参数校验与授权。
 */
class RegistrationScopeService
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly OperationLogger $logger,
    ) {}

    public function findDefault(int $eventId): ?EventRegistrationScope
    {
        return EventRegistrationScope::query()
            ->where('event_id', $eventId)
            ->where('scope_key', 'default')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDefault(Event $event, array $attributes, int $adminId): EventRegistrationScope
    {
        $unit = $this->policy->unitForKind($event->kind !== null ? (string) $event->kind : null);

        if ($unit === null) {
            throw RegistrationException::invalid('活动类型未核定，不能创建报名范围。');
        }

        return $this->createScoped($event, 'default', $unit, $attributes, $adminId);
    }

    /**
     * G3-02 · 为赛项创建独立报名范围。
     *
     * scope_key 直接使用 track_key（去掉 `default` 保留字），单位固定 `team`。
     * 每个赛项必须有自己的 scope，不能与普通活动或个人报名混用容量/审核配置。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createForTrack(Event $event, string $trackKey, array $attributes, int $adminId): EventRegistrationScope
    {
        if ((string) $event->kind !== 'competition') {
            throw RegistrationException::invalid('只有机器人赛事可以为赛项创建队伍报名范围。');
        }

        $trackKey = trim($trackKey);

        if ($trackKey === '' || $trackKey === 'default' || mb_strlen($trackKey) > 50) {
            throw RegistrationException::invalid('赛项 key 不能为空、不能是 default，且不得超过 50 个字符。');
        }

        return $this->createScoped($event, $trackKey, 'team', $attributes, $adminId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createScoped(
        Event $event,
        string $scopeKey,
        string $unit,
        array $attributes,
        int $adminId,
    ): EventRegistrationScope {
        return DB::transaction(function () use ($event, $scopeKey, $unit, $attributes, $adminId): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if (EventRegistrationScope::query()
                ->where('event_id', $lockedEvent->getKey())
                ->where('scope_key', $scopeKey)
                ->exists()) {
                throw RegistrationException::invalid(sprintf('该活动已存在报名范围 %s。', $scopeKey));
            }

            $capacityProvided = array_key_exists('capacity', $attributes);
            $confirmCapacity = (bool) ($attributes['confirm_capacity'] ?? false);
            unset($attributes['confirm_capacity']);

            $scope = new EventRegistrationScope();
            $scope->fill($this->filterCreateAttributes($attributes));
            $scope->event_id = (int) $lockedEvent->getKey();
            $scope->scope_key = $scopeKey;
            $scope->unit = $unit;
            $scope->status = 'draft';
            $scope->confirmed_count = 0;
            $scope->form_version = 1;
            $scope->form_schema = $scope->form_schema ?? $this->defaultFormSchema();
            $scope->row_version = 0;
            $scope->capacity_confirmed_at = $capacityProvided && $confirmCapacity ? now('UTC') : null;

            $this->assertBasicConfiguration($scope);
            $scope->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'registration.scope.created',
                'event_registration_scope',
                (int) $scope->getKey(),
                [
                    'event_id'  => (int) $lockedEvent->getKey(),
                    'scope_key' => $scopeKey,
                    'unit'      => $unit,
                ],
            );

            return $scope->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        EventRegistrationScope $scope,
        int $expectedRowVersion,
        array $attributes,
        int $adminId,
    ): EventRegistrationScope {
        return DB::transaction(function () use ($scope, $expectedRowVersion, $attributes, $adminId): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($scope->event_id)->lockForUpdate()->firstOrFail();
            $locked = EventRegistrationScope::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            $scopeAttributes = $this->filterUpdateAttributes($attributes);
            unset($scopeAttributes['confirm_capacity']);

            if (array_key_exists('capacity', $scopeAttributes)) {
                $capacity = $scopeAttributes['capacity'];

                if ($capacity !== null && (int) $capacity < (int) $locked->confirmed_count) {
                    throw RegistrationException::invalid(sprintf(
                        '容量不能低于已确认席位（当前 %d）。',
                        (int) $locked->confirmed_count,
                    ));
                }

                // 容量变更必须重新确认：避免“改了数但没人复看”。
                $locked->capacity_confirmed_at = (bool) ($attributes['confirm_capacity'] ?? false)
                    ? now('UTC')
                    : null;
            }

            // 表单/隐私/规则实质变化 → 新版本；旧 attempt 的快照不受影响。
            $formChanged = array_key_exists('form_schema', $scopeAttributes)
                && json_encode($scopeAttributes['form_schema']) !== json_encode($locked->form_schema);
            $privacyChanged = array_key_exists('privacy_version', $scopeAttributes)
                && (string) $scopeAttributes['privacy_version'] !== (string) $locked->privacy_version;
            $rulesChanged = array_key_exists('rule_version_id', $scopeAttributes)
                && (int) $scopeAttributes['rule_version_id'] !== (int) $locked->rule_version_id;

            $locked->fill($scopeAttributes);

            if ($formChanged || $privacyChanged || $rulesChanged) {
                $locked->form_version = (int) $locked->form_version + 1;
            }

            $this->assertBasicConfiguration($locked);
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'registration.scope.updated',
                'event_registration_scope',
                (int) $locked->getKey(),
                [
                    'event_id'     => (int) $lockedEvent->getKey(),
                    'form_version' => (int) $locked->form_version,
                    'fields'       => array_keys($scopeAttributes),
                ],
            );

            return $locked->refresh();
        });
    }

    public function activate(EventRegistrationScope $scope, int $expectedRowVersion, int $adminId): EventRegistrationScope
    {
        return DB::transaction(function () use ($scope, $expectedRowVersion, $adminId): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($scope->event_id)->lockForUpdate()->firstOrFail();
            $locked = EventRegistrationScope::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            if ((string) $locked->status === 'open') {
                throw RegistrationException::transition('报名范围已开放，无需重复激活。', 'already_open');
            }

            if ((string) $lockedEvent->publish_status !== 'published'
                || (string) $lockedEvent->disposition !== 'normal') {
                throw RegistrationException::invalid('活动未发布或已暂缓/撤稿，不能激活报名。');
            }

            $this->policy->assertActivatable($lockedEvent, $locked);

            $locked->status = 'open';
            $locked->policy_confirmed_at = now('UTC');
            $locked->policy_confirmed_by = $adminId;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'registration.scope.activated',
                'event_registration_scope',
                (int) $locked->getKey(),
                ['event_id' => (int) $lockedEvent->getKey()],
            );

            return $locked->refresh();
        });
    }

    public function close(EventRegistrationScope $scope, int $expectedRowVersion, int $adminId, string $reason = ''): EventRegistrationScope
    {
        return $this->setStatus($scope, $expectedRowVersion, $adminId, 'closed', 'registration.scope.closed', $reason);
    }

    public function reopen(EventRegistrationScope $scope, int $expectedRowVersion, int $adminId): EventRegistrationScope
    {
        return DB::transaction(function () use ($scope, $expectedRowVersion, $adminId): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($scope->event_id)->lockForUpdate()->firstOrFail();
            $locked = EventRegistrationScope::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            $this->policy->assertActivatable($lockedEvent, $locked);

            $locked->status = 'open';
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->logger->logOrFail(
                'admin', $adminId, 'registration.scope.reopened',
                'event_registration_scope', (int) $locked->getKey(),
                ['event_id' => (int) $lockedEvent->getKey()],
            );

            return $locked->refresh();
        });
    }

    /**
     * 调整容量。降低时不得低于已占席位；变更后必须重新确认容量。
     */
    public function adjustCapacity(
        EventRegistrationScope $scope,
        int $expectedRowVersion,
        ?int $capacity,
        bool $confirm,
        int $adminId,
        string $reason,
    ): EventRegistrationScope {
        return DB::transaction(function () use ($scope, $expectedRowVersion, $capacity, $confirm, $adminId, $reason): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($scope->event_id)->lockForUpdate()->firstOrFail();
            $locked = EventRegistrationScope::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            if ($capacity !== null && $capacity < (int) $locked->confirmed_count) {
                throw RegistrationException::invalid(sprintf(
                    '容量不能低于已确认席位（当前 %d）。',
                    (int) $locked->confirmed_count,
                ));
            }

            $before = $locked->capacity;
            $locked->capacity = $capacity;
            $locked->capacity_confirmed_at = $confirm ? now('UTC') : null;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->recordScopeChange(
                $lockedEvent,
                'capacity',
                sprintf('容量调整：%s → %s', $before === null ? '不限' : $before, $capacity === null ? '不限' : $capacity),
                ['before' => $before, 'after' => $capacity, 'reason' => $reason],
                $adminId,
            );

            $this->logger->logOrFail(
                'admin', $adminId, 'registration.scope.capacity_changed',
                'event_registration_scope', (int) $locked->getKey(),
                [
                    'event_id' => (int) $lockedEvent->getKey(),
                    'before'   => $before,
                    'after'    => $capacity,
                    'reason'   => $reason,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * 对账：从 attempts 重算 confirmed_count。
     *
     * @return array{scope_id: int, stored: int, computed: int, changed: bool}
     */
    public function reconcileConfirmedCount(EventRegistrationScope $scope, bool $apply = false): array
    {
        $computed = (int) EventRegistrationAttempt::query()
            ->where('scope_id', $scope->getKey())
            ->whereIn('status', EventRegistrationAttempt::SEAT_STATUSES)
            ->count();

        $stored = (int) $scope->confirmed_count;
        $changed = $computed !== $stored;

        if ($changed && $apply) {
            EventRegistrationScope::query()
                ->whereKey($scope->getKey())
                ->update([
                    'confirmed_count' => $computed,
                    'row_version'     => DB::raw('row_version + 1'),
                    'updated_at'      => now(),
                ]);
        }

        return [
            'scope_id' => (int) $scope->getKey(),
            'stored'   => $stored,
            'computed' => $computed,
            'changed'  => $changed,
        ];
    }

    /**
     * 默认报名表：只接受白名单字段，服务端固定必填与长度上限。
     *
     * @return array<string, mixed>
     */
    public function defaultFormSchema(): array
    {
        return [
            'fields'     => (array) config('ecosystem_registration.form.fields', ['name', 'phone', 'city', 'note']),
            'required'   => (array) config('ecosystem_registration.form.required', ['name', 'phone']),
            'max_lengths'=> (array) config('ecosystem_registration.form.max_lengths', []),
        ];
    }

    private function setStatus(
        EventRegistrationScope $scope,
        int $expectedRowVersion,
        int $adminId,
        string $status,
        string $action,
        string $reason,
    ): EventRegistrationScope {
        return DB::transaction(function () use ($scope, $expectedRowVersion, $adminId, $status, $action, $reason): EventRegistrationScope {
            $lockedEvent = Event::query()->whereKey($scope->event_id)->lockForUpdate()->firstOrFail();
            $locked = EventRegistrationScope::query()->whereKey($scope->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw RegistrationException::versionStale();
            }

            if ((string) $locked->status === $status) {
                throw RegistrationException::transition('状态未变化。', 'no_change');
            }

            $locked->status = $status;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->recordScopeChange(
                $lockedEvent,
                $status === 'closed' ? 'close' : 'reopen',
                $status === 'closed' ? '报名范围关闭' : '报名范围恢复',
                ['status' => $status, 'reason' => $reason],
                $adminId,
            );

            $this->logger->logOrFail(
                'admin', $adminId, $action,
                'event_registration_scope', (int) $locked->getKey(),
                ['event_id' => (int) $lockedEvent->getKey(), 'reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterCreateAttributes(array $attributes): array
    {
        $allowed = [
            'capacity', 'review_mode', 'registration_opens_at', 'registration_closes_at',
            'review_closes_at', 'cancel_policy', 'reapply_policy', 'max_attempts',
            'change_ack_required', 'decline_releases_seat', 'no_response_action',
            'form_schema', 'privacy_version', 'privacy_summary', 'rule_version_id',
        ];

        return array_intersect_key($attributes, array_flip($allowed));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterUpdateAttributes(array $attributes): array
    {
        $allowed = [
            'capacity', 'review_mode', 'registration_opens_at', 'registration_closes_at',
            'review_closes_at', 'cancel_policy', 'reapply_policy', 'max_attempts',
            'change_ack_required', 'decline_releases_seat', 'no_response_action',
            'form_schema', 'privacy_version', 'privacy_summary', 'rule_version_id',
        ];

        $filtered = array_intersect_key($attributes, array_flip($allowed));

        // 只有显式传入才参与比较；否则 fill 不会覆盖已有值。
        return $filtered;
    }

    /**
     * 在 scope 写事务内记录容量/关闭/恢复变更事实，保证与 scope 状态原子。
     *
     * @param  array<string, mixed>  $details
     */
    private function recordScopeChange(
        Event $event,
        string $type,
        string $summary,
        array $details,
        int $adminId,
    ): void {
        $nextVersion = (int) EventChange::query()
            ->where('event_id', $event->getKey())
            ->max('change_version') + 1;

        EventChange::query()->create([
            'event_id'              => (int) $event->getKey(),
            'change_version'        => $nextVersion,
            'type'                  => $type,
            'summary'               => $summary,
            'details'               => $details,
            'requires_ack'          => false,
            'ack_deadline'          => null,
            'decline_releases_seat' => false,
            'no_response_action'    => 'none',
            'status'                => 'published',
            'created_by'            => $adminId,
            'published_at'          => now('UTC'),
        ]);
    }

    private function assertBasicConfiguration(EventRegistrationScope $scope): void
    {        if ($scope->capacity !== null && (int) $scope->capacity < 0) {
            throw RegistrationException::invalid('容量不能为负数。');
        }

        if (! in_array((string) $scope->review_mode, ['auto', 'manual'], true)) {
            throw RegistrationException::invalid('审核方式必须是 auto 或 manual。');
        }

        if ($scope->max_attempts !== null
            && ((int) $scope->max_attempts < 1 || (int) $scope->max_attempts > 100)) {
            throw RegistrationException::invalid('最大报名次数必须在 1~100 之间，或留空表示不限。');
        }

        if ($scope->registration_closes_at !== null
            && $scope->registration_opens_at !== null
            && $scope->registration_opens_at->greaterThanOrEqualTo($scope->registration_closes_at)) {
            throw RegistrationException::invalid('报名开始时间必须早于报名截止时间。');
        }

        if ($scope->registration_closes_at !== null
            && $scope->review_closes_at !== null
            && $scope->review_closes_at->lessThan($scope->registration_closes_at)) {
            throw RegistrationException::invalid('审核截止时间不得早于报名截止时间。');
        }
    }
}
