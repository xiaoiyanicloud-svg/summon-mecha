<?php

namespace Webkul\Shop\Http\Resources\Ecosystem;

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开活动 DTO（ADR-0001 G1-D08 / 规划 §6.3 / G1-10）。
 *
 * **显式构造，禁止序列化模型**。字段只来自：
 *   · 父对象：`id` / `kind` / `hosting_status` / `published_at` /
 *     `first_published_at`；
 *   · 当前 published revision：名称、摘要、主办方快照、来源快照、
 *     参与方式、城市、地点、时区、起止时间、正文、`revision_no`。
 *
 * 永不返回：`hot` / `teams` / `date` / `status` / 旧 `detail` /
 * `working_revision_id` / `approved_revision_id` / `review_note` /
 * `reviewed_by` / `evidence_ref` / `authorization_evidence_ref` /
 * `storage_key` / 联系人。
 *
 * `external_registration_url` 是**受控可选字段**：只有 revision 里该值非空且
 * 是合法的 http/https URL 时才输出。它只是把已批准版本里的外链展示给前端，
 * **不改变发布前置** —— B-01/B-03 未签署时，外部活动仍然在提交/发布阶段被
 * `PublicationPreconditions` 拒绝，本字段不会让未签署的外部活动变成可发布。
 */
final class EventPublicResource
{
    /**
     * @param  array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}  $registration
     * @param  array<int, array{id: int, role: string, alt_text: string|null, url: string}>  $media
     */
    public function __construct(
        private readonly Event $event,
        private readonly EventRevision $revision,
        private readonly array $registration,
        private readonly ?string $bodyHtml,
        private readonly array $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $timezone = $this->timezone();

        $data = [
            'id'                   => (int) $this->event->id,
            'kind'                 => $this->event->kind !== null ? (string) $this->event->kind : null,
            'name'                 => (string) $this->revision->name,
            'summary'              => $this->nullableString($this->revision->summary),
            'organizer'            => [
                'name'          => (string) $this->revision->organizer_name_snapshot,
                'platform_role' => (string) $this->revision->platform_role,
            ],
            'source'               => [
                'name' => (string) $this->revision->source_name,
                'url'  => $this->nullableString($this->revision->source_url),
            ],
            'participation_mode'   => $this->nullableString($this->revision->participation_mode),
            'city'                 => $this->nullableString($this->revision->city),
            'location_text'        => $this->nullableString($this->revision->location_text),
            'timezone'             => $this->nullableString($this->revision->timezone),
            'starts_at'            => $this->iso($this->revision->starts_at, $timezone),
            'ends_at'              => $this->iso($this->revision->ends_at, $timezone),
            'published_at'         => $this->iso($this->event->published_at, $this->platformTimezone()),
            'first_published_at'   => $this->iso($this->event->first_published_at, $this->platformTimezone()),
            'revision_no'          => (int) $this->revision->revision_no,
            'hosting_status'       => $this->event->hosting_status !== null ? (string) $this->event->hosting_status : null,
            'body_html'            => $this->bodyHtml,
            'registration'         => [
                'can_register'        => (bool) $this->registration['can_register'],
                'registration_reason' => (string) $this->registration['registration_reason'],
                'allowed_actions'     => array_values($this->registration['allowed_actions']),
            ],
            'media'                => $this->media,
        ];

        $externalUrl = $this->externalRegistrationUrl();

        if ($externalUrl !== null) {
            $data['external_registration_url'] = $externalUrl;
        }

        return $data;
    }

    /**
     * 只输出字面合法的 http/https URL；其余（空、相对路径、javascript:、
     * 带空白/控制字符）一律不输出该字段。
     */
    private function externalRegistrationUrl(): ?string
    {
        $url = $this->revision->external_registration_url;

        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return (string) $parts['host'] === '' ? null : $url;
    }

    private function timezone(): string
    {
        $timezone = (string) ($this->revision->timezone ?? '');

        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : $this->platformTimezone();
    }

    private function platformTimezone(): string
    {
        return (string) config('app.timezone', 'Asia/Shanghai');
    }

    private function iso(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toIso8601String();
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
