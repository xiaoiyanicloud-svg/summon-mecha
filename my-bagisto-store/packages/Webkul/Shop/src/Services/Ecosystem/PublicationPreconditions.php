<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeZone;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 提交 / 批准 / 发布共用前置校验（ADR-0001 §8.3 / 附录 B A-06 / G1-08A / G1-08B）。
 *
 * 每次提交、批准、发布、排期或恢复都重做同一组核验，任一项不符 fail-closed：
 *   1. revision 归属与父对象一致；
 *   2. 来源完整：活动要求 source_name / source_verified_at / authorization_basis /
 *      organizer_name_snapshot；内容至少 1 条来源且 name / url / verified_at /
 *      rights_basis 齐全，authorized_repost 还要 rights_scope + evidence_ref；
 *   3. B-01/B-03 未签署时只放行 platform + organizer；
 *   4. 活动 kind / hosting_status / timezone 完整；平台活动必须有起止时间；
 *   5. 媒体：每条 ref approved、媒体自身 approved、私有盘文件真实存在；
 *      不生成任何公开 URL；
 *   6. 重算 media_manifest_hash 与 content_fingerprint，与库内值比较；
 *   7. sanitizer_version 必须等于当前 config 版本。
 *
 * `normalizeDraftManifest=true` 仅用于提交阶段：无媒体引用的 draft 允许
 * `media_manifest_hash=NULL`，此时把它规范成空集合指纹并重算内容指纹（此时
 * revision 仍是 draft，不违反不可变语义）。批准阶段 `false`，只比较不写入。
 */
class PublicationPreconditions
{
    /**
     * @var array<int, string>
     */
    private const EVENT_REQUIRED_SOURCE_FIELDS = [
        'source_name',
        'organizer_name_snapshot',
        'authorization_basis',
    ];

    public function __construct(
        private readonly ContentFingerprint $fingerprints,
        private readonly MediaManifest $manifest,
        private readonly RichTextSanitizer $sanitizer,
        private readonly EventSourceValidator $sourceValidator,
        private readonly PublicationState $state,
    ) {}

    /**
     * @throws PublicationPreconditionException 资料/媒体/指纹/净化版本不满足
     * @throws PublicationTransitionException revision 不属于该活动
     */
    public function assertEventSubmittable(
        Event $event,
        EventRevision $revision,
        bool $normalizeDraftManifest = true,
    ): void {
        $this->assertEventOwnership($event, $revision);
        $this->assertEventFields($event, $revision);
        $this->assertEventSource($event, $revision);
        $this->assertEventTime($event, $revision);
        $this->assertSanitizerVersion('event', $revision->sanitizer_version);
        $this->assertEventMediaAndFingerprint($revision, $normalizeDraftManifest);
    }

    /**
     * @throws PublicationPreconditionException 资料/媒体/指纹/净化版本不满足
     * @throws PublicationTransitionException revision 不属于该文章
     */
    public function assertContentSubmittable(
        ContentPost $post,
        ContentRevision $revision,
        bool $normalizeDraftManifest = true,
    ): void {
        $this->assertContentOwnership($post, $revision);
        $this->assertContentFields($revision);
        $this->assertContentSources($revision);
        $this->assertSanitizerVersion('content', $revision->sanitizer_version);
        $this->assertContentMediaAndFingerprint($revision, $normalizeDraftManifest);
    }

    /**
     * 发布前置：在提交/批准校验之上追加主办方核验与 B-01/B-03 放行组合。
     *
     * 发布 / 排期 / 恢复都必须重新执行本方法，**不得**复用审批时的结论：
     * 批准后正文、来源、媒体、sanitizer 版本或主办方状态都可能变化。
     *
     * @throws PublicationPreconditionException 资料/媒体/指纹/净化版本不满足
     * @throws PublicationTransitionException revision 不属于该活动
     */
    public function assertEventPublishable(Event $event, EventRevision $revision): void
    {
        $this->assertEventOwnership($event, $revision);
        $this->assertEventFields($event, $revision);

        try {
            $this->assertEventSource($event, $revision);
        } catch (EventSourceValidationException $exception) {
            // 来源/主办方状态在发布阶段统一收敛为发布前置异常，
            // 调用方与 HTTP 层只需识别 PublicationPreconditionException。
            throw new PublicationPreconditionException(
                $exception->errorCode,
                $exception->getMessage(),
            );
        }

        $this->assertEventTime($event, $revision);
        $this->assertSanitizerVersion('event', $revision->sanitizer_version);
        $this->assertEventMediaAndFingerprint($revision, false);

        // organizer 必须 verified，且来源/角色命中 B-01/B-03 的放行组合。
        try {
            $this->sourceValidator->assertPublishable($event);
        } catch (EventSourceValidationException $exception) {
            throw new PublicationPreconditionException(
                $exception->errorCode,
                $exception->getMessage(),
            );
        }
    }

    /**
     * 内容发布前置：与 submit/approve 相同的内容/来源/媒体/指纹/净化校验，
     * 但 `normalizeDraftManifest=false` —— 发布阶段只比较，绝不写 revision。
     *
     * B-04 / B-07 的开关判断在 `PublicationState::assertPublishEnabled()`。
     *
     * @throws PublicationPreconditionException 资料/媒体/指纹/净化版本不满足
     * @throws PublicationTransitionException revision 不属于该文章
     */
    public function assertContentPublishable(ContentPost $post, ContentRevision $revision): void
    {
        $this->assertContentOwnership($post, $revision);
        $this->assertContentFields($revision);
        $this->assertContentSources($revision);
        $this->assertSanitizerVersion('content', $revision->sanitizer_version);
        $this->assertContentMediaAndFingerprint($revision, false);
    }

    private function assertEventOwnership(Event $event, EventRevision $revision): void
    {
        if ($event->getKey() === null
            || $revision->getKey() === null
            || (int) $revision->event_id !== (int) $event->getKey()) {
            throw PublicationTransitionException::event(sprintf(
                '活动 %s 与 revision %s 不属于同一对象，不能提交/批准。',
                (string) ($event->getKey() ?? '(未保存)'),
                (string) ($revision->getKey() ?? '(未保存)'),
            ));
        }
    }

    private function assertContentOwnership(ContentPost $post, ContentRevision $revision): void
    {
        if ($post->getKey() === null
            || $revision->getKey() === null
            || (int) $revision->post_id !== (int) $post->getKey()) {
            throw PublicationTransitionException::content(sprintf(
                '文章 %s 与 revision %s 不属于同一对象，不能提交/批准。',
                (string) ($post->getKey() ?? '(未保存)'),
                (string) ($revision->getKey() ?? '(未保存)'),
            ));
        }
    }

    private function assertEventFields(Event $event, EventRevision $revision): void
    {
        $kinds = array_values((array) config('ecosystem_publishing.event_kinds', []));

        if (! is_string($event->kind) || ! in_array($event->kind, $kinds, true)) {
            throw $this->transition('event', '活动 kind 缺失或不在允许枚举中，不能提交审核。');
        }

        $hostingStatuses = array_values((array) config('ecosystem_publishing.event_hosting_statuses', []));
        $reviewAllowed = array_values((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []));

        if (! is_string($event->hosting_status) || ! in_array($event->hosting_status, $hostingStatuses, true)) {
            throw $this->transition('event', '活动 hosting_status 缺失或未知，不能提交审核。');
        }

        // B-06 未签署：postponed / rescheduled / canceled 写能力关闭。
        if (! in_array($event->hosting_status, $reviewAllowed, true)) {
            throw $this->transition('event', sprintf(
                '活动 hosting_status=%s 尚未开放审核（B-06 未签署）；当前只允许 %s。',
                $event->hosting_status,
                implode(' / ', $reviewAllowed),
            ));
        }

        if (! is_string($event->timezone) || ! $this->isValidTimezone($event->timezone)) {
            throw new PublicationPreconditionException(
                ApiErrorCode::EVENT_TIME_INVALID,
                '活动 timezone 缺失或不是合法 IANA 名称。',
            );
        }

        // revision 的时区快照同样必须合法；快照与活动当前时区不一致时，
        // 以 revision 的内容为准审核，但两者都必须是 IANA。
        if (! is_string($revision->timezone) || ! $this->isValidTimezone($revision->timezone)) {
            throw new PublicationPreconditionException(
                ApiErrorCode::EVENT_TIME_INVALID,
                'revision timezone 缺失或不是合法 IANA 名称。',
            );
        }
    }

    private function assertEventSource(Event $event, EventRevision $revision): void
    {
        if ($event->source_type === null
            || $event->platform_role === null
            || $event->organizer_id === null) {
            throw $this->sourceRequired('活动来源三字段不完整，不能提交审核。');
        }

        if ((string) $event->source_type !== (string) $revision->source_type
            || (string) $event->platform_role !== (string) $revision->platform_role
            || (int) $event->organizer_id !== (int) ($revision->organizer_id ?? 0)) {
            throw $this->sourceRequired(
                'revision 来源快照与活动当前 source_type / platform_role / organizer_id 不一致。'
            );
        }

        foreach (self::EVENT_REQUIRED_SOURCE_FIELDS as $field) {
            $value = $revision->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                throw $this->sourceRequired(sprintf('活动 revision 缺少提交必填字段 %s。', $field));
            }
        }

        if ($revision->source_verified_at === null) {
            throw $this->sourceRequired('活动 revision 缺少 source_verified_at（来源核验时间）。');
        }

        $authorizationBases = array_values((array) config('ecosystem_revisions.authorization_bases', []));

        if (! in_array((string) $revision->authorization_basis, $authorizationBases, true)) {
            throw $this->sourceRequired(sprintf(
                '未知的 authorization_basis「%s」。',
                (string) $revision->authorization_basis,
            ));
        }

        // 复用 G1-03 的枚举 / 组合 / 主体存在性 / 状态校验。
        $this->sourceValidator->assertDraftValues(
            $revision->source_type,
            $revision->platform_role,
            $revision->organizer_id === null ? null : (int) $revision->organizer_id,
        );

        $this->assertB01B03Combination((string) $event->source_type, (string) $event->platform_role);

        if ($revision->source_url !== null) {
            $this->assertSourceUrl((string) $revision->source_url, 'event');
        }

        if ($event->source_type === 'external') {
            $externalUrl = $revision->external_registration_url;

            if (! is_string($externalUrl) || trim($externalUrl) === '') {
                throw $this->sourceRequired('外部活动提交前必须填写已审核的 external_registration_url。');
            }

            $this->assertSourceUrl($externalUrl, 'event');
        }
    }

    /**
     * B-01 / B-03 未签署时只放行配置中的组合；签署后跳过组合限制。
     */
    private function assertB01B03Combination(string $sourceType, string $platformRole): void
    {
        $bothSigned = (bool) config('ecosystem_publishing.b01_external_links_signed', false)
            && (bool) config('ecosystem_publishing.b03_organizer_verification_signed', false);

        if ($bothSigned) {
            return;
        }

        $allowedTypes = array_values((array) config(
            'ecosystem_publishing.publish_allowed_source_types',
            ['platform'],
        ));
        $allowedRoles = array_values((array) config(
            'ecosystem_publishing.publish_allowed_platform_roles',
            ['organizer'],
        ));

        if (! in_array($sourceType, $allowedTypes, true)
            || ! in_array($platformRole, $allowedRoles, true)) {
            throw new PublicationPreconditionException(
                ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED,
                sprintf(
                    '来源类型 %s / 平台角色 %s 尚未开放（B-01/B-03 未签署）；当前只允许 %s + %s。',
                    $sourceType,
                    $platformRole,
                    implode(' / ', $allowedTypes),
                    implode(' / ', $allowedRoles),
                ),
            );
        }
    }

    private function assertEventTime(Event $event, EventRevision $revision): void
    {
        $startsAt = $revision->starts_at;
        $endsAt = $revision->ends_at;

        if ($startsAt === null && $endsAt === null) {
            if ($event->source_type === 'platform') {
                throw new PublicationPreconditionException(
                    ApiErrorCode::EVENT_TIME_INVALID,
                    '平台活动提交前必须有确定的起止时间（starts_at / ends_at）。',
                );
            }

            return;
        }

        if ($startsAt === null || $endsAt === null) {
            throw new PublicationPreconditionException(
                ApiErrorCode::EVENT_TIME_INVALID,
                'starts_at 与 ends_at 必须同时为空（待定）或同时填写。',
            );
        }

        if ($endsAt->lt($startsAt)) {
            throw new PublicationPreconditionException(
                ApiErrorCode::EVENT_TIME_INVALID,
                'ends_at 不得早于 starts_at。',
            );
        }
    }

    private function assertContentFields(ContentRevision $revision): void
    {
        foreach (['title', 'author_name', 'change_summary'] as $field) {
            $value = $revision->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                throw new PublicationPreconditionException(
                    ApiErrorCode::CONTENT_SOURCE_REQUIRED,
                    sprintf('内容 revision 缺少必填字段 %s。', $field),
                );
            }
        }

        $sourceTypes = array_values((array) config('ecosystem_content.source_types', []));

        if (! in_array((string) $revision->source_type, $sourceTypes, true)) {
            throw new PublicationPreconditionException(
                ApiErrorCode::CONTENT_SOURCE_REQUIRED,
                sprintf('未知的 source_type「%s」。', (string) $revision->source_type),
            );
        }

        if ($revision->source_type === 'link_summary' && $revision->body_html !== null) {
            throw new PublicationPreconditionException(
                ApiErrorCode::CONTENT_SOURCE_REQUIRED,
                'source_type=link_summary 时 body_html 必须为 NULL；链接摘要不得复制全文。',
            );
        }
    }

    private function assertContentSources(ContentRevision $revision): void
    {
        $sources = $revision->sources()->orderBy('sort_order')->orderBy('id')->get();

        if ($sources->isEmpty()) {
            throw new PublicationPreconditionException(
                ApiErrorCode::CONTENT_SOURCE_REQUIRED,
                '内容 revision 提交前至少需要一条来源。',
            );
        }

        $rightsBases = array_values((array) config('ecosystem_content.rights_bases', []));
        $sourceType = (string) $revision->source_type;

        foreach ($sources as $source) {
            if (! is_string($source->source_name) || trim($source->source_name) === '') {
                throw $this->contentSourceRequired('每条来源都必须有 source_name。');
            }

            if (! is_string($source->source_url) || trim($source->source_url) === '') {
                throw $this->contentSourceRequired('进入审核的每条来源都必须有 source_url。');
            }

            $this->assertSourceUrl((string) $source->source_url, 'content');

            if ($source->source_verified_at === null) {
                throw $this->contentSourceRequired('每条来源都必须有 source_verified_at。');
            }

            $rightsBasis = (string) $source->rights_basis;

            if (! in_array($rightsBasis, $rightsBases, true)) {
                throw $this->contentSourceRequired(sprintf('未知的 rights_basis「%s」。', $rightsBasis));
            }

            $matching = array_values((array) config(
                'ecosystem_content.source_type_rights_basis.'.$sourceType,
                [],
            ));

            if (! in_array($rightsBasis, $matching, true)) {
                throw $this->contentSourceRequired(sprintf(
                    'source_type=%s 不能使用 rights_basis=%s。',
                    $sourceType,
                    $rightsBasis,
                ));
            }

            if ($sourceType === 'authorized_repost') {
                if (! is_string($source->rights_scope) || trim($source->rights_scope) === '') {
                    throw $this->contentSourceRequired('authorized_repost 必须填写 rights_scope。');
                }

                if (! is_string($source->evidence_ref) || trim($source->evidence_ref) === '') {
                    throw $this->contentSourceRequired('authorized_repost 必须填写 evidence_ref。');
                }
            }
        }
    }

    private function assertSanitizerVersion(string $domain, mixed $version): void
    {
        if (! is_string($version) || $version === '' || ! hash_equals($this->sanitizer->version(), $version)) {
            throw $this->state->conflict($domain, sprintf(
                '%s revision 的 sanitizer_version=%s 与当前净化版本 %s 不一致，拒绝提交/批准。',
                $domain,
                is_string($version) ? $version : '(NULL)',
                $this->sanitizer->version(),
            ));
        }
    }

    private function assertEventMediaAndFingerprint(EventRevision $revision, bool $normalizeDraftManifest): void
    {
        $refs = $this->mediaRefs('event_revision', (int) $revision->getKey());
        $manifestHash = $this->assertMediaRefs($refs);

        if ($revision->media_manifest_hash === null) {
            if ($refs !== []) {
                throw $this->state->conflict('event', sprintf(
                    '活动 revision %d 存在 %d 条媒体引用，但 media_manifest_hash 为空。',
                    (int) $revision->getKey(),
                    count($refs),
                ));
            }

            if ($normalizeDraftManifest) {
                $revision->media_manifest_hash = $manifestHash;
                $revision->content_fingerprint = $this->fingerprints->eventRevision($revision);
                $revision->save();
            }
        } elseif (! hash_equals((string) $revision->media_manifest_hash, $manifestHash)) {
            throw $this->state->conflict('event', sprintf(
                '活动 revision %d 的 media_manifest_hash 与当前媒体集合不一致；请重新提交审核。',
                (int) $revision->getKey(),
            ));
        }

        $expected = $this->fingerprints->eventRevision($revision);

        if (! hash_equals((string) $revision->content_fingerprint, $expected)) {
            throw $this->state->conflict('event', sprintf(
                '活动 revision %d 的内容指纹与库内内容不一致；审批绑定已失效。',
                (int) $revision->getKey(),
            ));
        }
    }

    private function assertContentMediaAndFingerprint(ContentRevision $revision, bool $normalizeDraftManifest): void
    {
        $refs = $this->mediaRefs('content_revision', (int) $revision->getKey());
        $manifestHash = $this->assertMediaRefs($refs);

        if ($revision->media_manifest_hash === null) {
            if ($refs !== []) {
                throw $this->state->conflict('content', sprintf(
                    '内容 revision %d 存在 %d 条媒体引用，但 media_manifest_hash 为空。',
                    (int) $revision->getKey(),
                    count($refs),
                ));
            }

            if ($normalizeDraftManifest) {
                $revision->media_manifest_hash = $manifestHash;
                $revision->content_fingerprint = $this->fingerprints->contentRevision(
                    $revision,
                    $revision->sources()->orderBy('sort_order')->get()->all(),
                );
                $revision->save();
            }
        } elseif (! hash_equals((string) $revision->media_manifest_hash, $manifestHash)) {
            throw $this->state->conflict('content', sprintf(
                '内容 revision %d 的 media_manifest_hash 与当前媒体集合不一致；请重新提交审核。',
                (int) $revision->getKey(),
            ));
        }

        $expected = $this->fingerprints->contentRevision(
            $revision,
            $revision->sources()->orderBy('sort_order')->get()->all(),
        );

        if (! hash_equals((string) $revision->content_fingerprint, $expected)) {
            throw $this->state->conflict('content', sprintf(
                '内容 revision %d 的内容指纹与库内正文/来源不一致；审批绑定已失效。',
                (int) $revision->getKey(),
            ));
        }
    }

    /**
     * @return array<int, EcosystemMediaRef>
     */
    private function mediaRefs(string $ownerType, int $ownerId): array
    {
        return EcosystemMediaRef::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('media')
            ->get()
            ->all();
    }

    /**
     * 每条引用与媒体都必须 approved，且私有盘文件真实存在。
     *
     * 只做存在性检查，不读取文件内容、不生成公开 URL、不触碰公开盘。
     *
     * @param  array<int, EcosystemMediaRef>  $refs
     * @return string media_manifest_hash
     */
    private function assertMediaRefs(array $refs): string
    {
        foreach ($refs as $ref) {
            if ((string) $ref->review_status !== 'approved') {
                throw PublicationPreconditionException::media(sprintf(
                    '媒体引用 %d 当前 review_status=%s，提交/批准前必须为 approved。',
                    (int) $ref->getKey(),
                    (string) $ref->review_status,
                ));
            }

            $media = $ref->media;

            if ($media === null) {
                throw PublicationPreconditionException::media(sprintf(
                    '媒体引用 %d 指向的媒体不存在。',
                    (int) $ref->getKey(),
                ));
            }

            if ((string) $media->status !== 'approved') {
                throw PublicationPreconditionException::media(sprintf(
                    '媒体 %d 当前 status=%s，提交/批准前必须为 approved。',
                    (int) $media->getKey(),
                    (string) $media->status,
                ));
            }

            if (! Storage::disk((string) $media->disk)->exists((string) $media->storage_key)) {
                throw PublicationPreconditionException::media(sprintf(
                    '媒体 %d 在私有盘上缺少文件，拒绝提交/批准。',
                    (int) $media->getKey(),
                ));
            }
        }

        return $this->manifest->hash($refs);
    }

    private function assertSourceUrl(string $url, string $domain): void
    {
        try {
            $this->sourceValidator->assertSourceUrl($url);
        } catch (EventSourceValidationException $exception) {
            if ($domain === 'event') {
                throw $this->sourceRequired($exception->getMessage());
            }

            throw $this->contentSourceRequired($exception->getMessage());
        }
    }

    private function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    private function sourceRequired(string $message): PublicationPreconditionException
    {
        return new PublicationPreconditionException(ApiErrorCode::EVENT_SOURCE_REQUIRED, $message);
    }

    private function contentSourceRequired(string $message): PublicationPreconditionException
    {
        return new PublicationPreconditionException(ApiErrorCode::CONTENT_SOURCE_REQUIRED, $message);
    }

    private function transition(string $domain, string $message): PublicationTransitionException
    {
        return $this->state->transition($domain, $message);
    }
}
