<?php

namespace Webkul\Shop\Http\Resources\Ecosystem;

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;

/**
 * 本人报名 DTO（G2-05 / G2-10）。
 *
 * 显式构造，禁止序列化模型。本人可见：
 *   · 报名编号、活动、轮次状态、时间、表单/隐私版本；
 *   · 遮罩后的联系人与城市；
 *   · 当前可执行动作（取消/重报/凭证）。
 * 永不返回：
 *   · 联系人密文/明文、request_key、审核备注、内部决策人、其他客户数据。
 */
final class RegistrationResource
{
    public function __construct(
        private readonly RegistrationContactVault $vault,
    ) {}

    /**
     * 报名提交/重报的即时回执（不回显联系人）。
     *
     * @return array<string, mixed>
     */
    public function submitted(EventRegistration $registration, EventRegistrationAttempt $attempt): array
    {
        return [
            'public_no'      => (string) $registration->public_no,
            'event_id'       => (int) $registration->event_id,
            'status'         => (string) $attempt->status,
            'attempt_no'     => (int) $attempt->attempt_no,
            'attempt_id'     => (int) $attempt->getKey(),
            'form_version'   => (int) $attempt->form_version,
            'privacy_version'=> (string) $attempt->privacy_version,
            'submitted_at'   => $this->iso($attempt->submitted_at),
            'decided_at'     => $this->iso($attempt->decided_at),
            'canceled_at'    => $this->iso($attempt->canceled_at),
            'decision_reason_code' => $attempt->decision_reason_code,
            'can_reapply'    => $this->canReapply($attempt),
        ];
    }

    /**
     * 我的报名列表/详情（含遮罩联系人）。
     *
     * @return array<string, mixed>
     */
    public function own(EventRegistration $registration, EventRegistrationAttempt $attempt): array
    {
        $event = $registration->relationLoaded('event') ? $registration->event : null;

        return array_merge($this->submitted($registration, $attempt), [
            'event' => [
                'id'   => (int) $registration->event_id,
                'name' => $event !== null ? (string) $event->name : null,
                'city' => $event !== null && $event->city !== null ? (string) $event->city : null,
            ],
            'contact' => [
                'name'  => $this->vault->maskName($this->vault->decryptName((string) $attempt->contact_name_enc)),
                'phone' => $this->vault->maskPhone($this->vault->decryptPhone((string) $attempt->contact_phone_enc)),
                'city'  => (string) ($attempt->contact_city ?? ''),
            ],
            'can_cancel' => $this->canCancel($attempt),
        ]);
    }

    public function canReapply(EventRegistrationAttempt $attempt): bool
    {
        return in_array((string) $attempt->status, [
            EventRegistrationAttempt::STATUS_CANCELED,
            EventRegistrationAttempt::STATUS_REJECTED,
        ], true);
    }

    public function canCancel(EventRegistrationAttempt $attempt): bool
    {
        return in_array((string) $attempt->status, [
            EventRegistrationAttempt::STATUS_SUBMITTED,
            EventRegistrationAttempt::STATUS_CONFIRMED,
        ], true);
    }

    private function iso(mixed $value): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()
            ->timezone((string) config('app.timezone', 'Asia/Shanghai'))
            ->toIso8601String();
    }
}
