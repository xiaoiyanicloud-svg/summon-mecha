<?php

namespace Webkul\Shop\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Http\Resources\Ecosystem\EventPublicResource;
use Webkul\Shop\Http\Resources\Ecosystem\MediaPublicResource;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\ContentViewRecorder;
use Webkul\Shop\Services\Ecosystem\PublicBodyRenderer;
use Webkul\Shop\Services\Ecosystem\PublicContentQuery;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;
use Webkul\Shop\Services\Ecosystem\PublicMediaResolver;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;
use Webkul\Shop\Services\Ecosystem\PublicRegistrationState;
use Webkul\Shop\Services\Ecosystem\PublicSiteUrl;
use Webkul\Shop\Services\Ecosystem\RelationService;

/**
 * G4-04 · 公开 SSR 页面。
 *
 * 与 uni-app 同 Laravel 服务，但**可见性完全复用 G1 查询**：
 *   · kill switch 先判断（503）；
 *   · `ecosystem_g4.ssr.enabled=false` 时整组 404；
 *   · 活动/资讯域开关关闭时 404（不渲染空壳冒充已上线）；
 *   · 详情只认 published revision + approved + 当前 sanitizer；
 *   · 撤稿 410 中性页，不输出旧标题/摘要/来源/图片。
 *
 * 本批不启用页面缓存：撤稿后同一请求立即反映。未来上 CDN 必须带撤稿 purge。
 */
class EcosystemSsrController extends Controller
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly PublicEventQuery $events,
        private readonly PublicContentQuery $contents,
        private readonly PublicMediaResolver $media,
        private readonly PublicRegistrationState $registration,
        private readonly PublicBodyRenderer $renderer,
        private readonly RelationService $relations,
        private readonly PublicSiteUrl $siteUrl,
        private readonly ContentViewRecorder $views,
    ) {}

    public function events(Request $request)
    {
        if ($unavailable = $this->guard('events')) {
            return $unavailable;
        }

        $filters = $this->eventFilters($request);

        if (! is_array($filters)) {
            return $filters;
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, (int) config('ecosystem_g4.ssr.list_per_page', 20));

        $paginator = $this->events->paginate($filters, $page, $perPage);

        $revisionIds = $paginator->getCollection()
            ->pluck('published_revision_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $refsByRevision = $this->media->approvedRefsByRevision('event_revision', $revisionIds);

        $cards = [];

        foreach ($paginator->items() as $event) {
            $revision = $event->publishedRevision;

            if (! $revision instanceof EventRevision) {
                continue;
            }

            $refs = $refsByRevision[(int) $revision->id] ?? [];

            $cards[] = [
                'id'         => (int) $event->id,
                'name'       => (string) $revision->name,
                'summary'    => $revision->summary !== null ? (string) $revision->summary : null,
                'kind'       => $event->kind !== null ? (string) $event->kind : null,
                'city'       => $revision->city !== null ? (string) $revision->city : null,
                'starts_at'  => $this->iso($revision->starts_at, (string) ($revision->timezone ?: config('app.timezone'))),
                'cover'      => $this->cover($refs, static fn (int $mediaId): string => sprintf(
                    '/api/events/%d/media/%d',
                    (int) $event->id,
                    $mediaId,
                )),
            ];
        }

        $hasFilters = $filters !== [];

        return response()
            ->view('shop::ecosystem.events', [
                'cards'      => $cards,
                'filters'    => $filters,
                'paginator'  => $paginator,
                'hasFilters' => $hasFilters,
                'seo'        => $this->seoBase('赛事活动', '/events', $hasFilters),
            ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function event(int $id)
    {
        if ($unavailable = $this->guard('events')) {
            return $unavailable;
        }

        $event = $this->events->findPublished($id);

        if (! $event) {
            if ($this->events->findRetracted($id)) {
                return $this->gone('该赛事已撤稿，内容不再提供。');
            }

            return $this->missing('赛事尚未公开发布或不存在。');
        }

        $revision = $event->publishedRevision;

        if (! $revision instanceof EventRevision || ! $this->events->bodyIsRenderable($revision)) {
            return $this->missing('赛事尚未公开发布或不存在。');
        }

        $refs = $this->media->approvedRefsByRevision('event_revision', [(int) $revision->id]);
        $media = MediaPublicResource::forEvent((int) $event->id, $refs[(int) $revision->id] ?? []);

        $allowedMediaIds = array_map(
            static fn (\Webkul\Shop\Models\EcosystemMediaRef $ref): int => (int) $ref->media_id,
            $refs[(int) $revision->id] ?? [],
        );

        $bodyHtml = $this->renderer->render(
            $revision->detail_html,
            $allowedMediaIds,
            static fn (int $mediaId): string => sprintf('/api/events/%d/media/%d', (int) $event->id, $mediaId),
        );

        $dto = (new EventPublicResource(
            $event,
            $revision,
            $this->registration->forEvent($event, $revision, null),
            $bodyHtml,
            $media,
            false,
            $this->relations->forSource(RelationService::SOURCE_EVENT, (int) $event->id),
        ))->toArray();

        $this->views->record(ContentViewRecorder::TARGET_EVENT, (string) $event->id);

        $canonical = $this->siteUrl->absolute('/events/'.$event->id);
        $description = $this->truncate((string) ($dto['summary'] ?? ''), 200);
        $cover = $this->firstCoverUrl($dto['media'] ?? []);

        return response()
            ->view('shop::ecosystem.event', [
                'event'     => $dto,
                'canonical' => $canonical,
                'seo'       => [
                    'title'       => (string) $dto['name'],
                    'description' => $description,
                    'canonical'   => $canonical,
                    'robots'      => 'index,follow',
                    'og'          => $this->og('article', (string) $dto['name'], $description, $canonical, $cover),
                    'jsonLd'      => $this->eventJsonLd($dto, $canonical),
                ],
            ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function contents(Request $request)
    {
        if ($unavailable = $this->guard('content')) {
            return $unavailable;
        }

        $filters = $this->contentFilters($request);

        if (! is_array($filters)) {
            return $filters;
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, (int) config('ecosystem_g4.ssr.list_per_page', 20));

        $paginator = $this->contents->paginate($filters, $page, $perPage);

        $revisionIds = $paginator->getCollection()
            ->pluck('published_revision_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $refsByRevision = $this->media->approvedRefsByRevision('content_revision', $revisionIds);

        $cards = [];

        foreach ($paginator->items() as $post) {
            $revision = $post->publishedRevision;

            if (! $revision instanceof ContentRevision) {
                continue;
            }

            $refs = $refsByRevision[(int) $revision->id] ?? [];

            $cards[] = [
                'slug'         => (string) $post->slug,
                'title'        => (string) $revision->title,
                'summary'      => $revision->summary !== null ? (string) $revision->summary : null,
                'type'         => (string) $post->type,
                'published_at' => $this->iso($post->published_at, (string) config('app.timezone')),
                'cover'        => $this->cover($refs, static fn (int $mediaId): string => sprintf(
                    '/api/content/posts/%s/media/%d',
                    rawurlencode((string) $post->slug),
                    $mediaId,
                )),
            ];
        }

        $hasFilters = $filters !== [];

        return response()
            ->view('shop::ecosystem.contents', [
                'cards'      => $cards,
                'filters'    => $filters,
                'paginator'  => $paginator,
                'hasFilters' => $hasFilters,
                'seo'        => $this->seoBase('动态资讯', '/content', $hasFilters),
            ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function content(string $slug)
    {
        if ($unavailable = $this->guard('content')) {
            return $unavailable;
        }

        $post = $this->contents->findPublishedBySlug($slug);

        if (! $post) {
            if ($this->contents->findRetractedBySlug($slug)) {
                return $this->gone('该文章已撤稿，内容不再提供。');
            }

            return $this->missing('文章尚未公开发布或不存在。');
        }

        $revision = $post->publishedRevision;

        if (! $revision instanceof ContentRevision || ! $this->contents->bodyIsRenderable($revision)) {
            return $this->missing('文章尚未公开发布或不存在。');
        }

        $refs = $this->media->approvedRefsByRevision('content_revision', [(int) $revision->id]);
        $media = MediaPublicResource::forContent((string) $post->slug, $refs[(int) $revision->id] ?? []);

        $allowedMediaIds = array_map(
            static fn (\Webkul\Shop\Models\EcosystemMediaRef $ref): int => (int) $ref->media_id,
            $refs[(int) $revision->id] ?? [],
        );

        $bodyHtml = $this->renderer->render(
            $revision->body_html,
            $allowedMediaIds,
            static fn (int $mediaId): string => sprintf(
                '/api/content/posts/%s/media/%d',
                rawurlencode((string) $post->slug),
                $mediaId,
            ),
        );

        $dto = (new \Webkul\Shop\Http\Resources\Ecosystem\ContentPublicResource(
            $post,
            $revision,
            \Webkul\Shop\Http\Resources\Ecosystem\ContentPublicResource::sources(
                $revision->sources,
                (string) config('app.timezone', 'Asia/Shanghai'),
            ),
            $bodyHtml,
            $media,
            false,
            $this->relations->forSource(RelationService::SOURCE_CONTENT, (int) $post->id),
        ))->toArray();

        $this->views->record(ContentViewRecorder::TARGET_CONTENT, (string) $post->slug);

        $canonical = $this->siteUrl->absolute('/content/'.$post->slug);
        $description = $this->truncate((string) ($dto['summary'] ?? ''), 200);
        $cover = $this->firstCoverUrl($dto['media'] ?? []);

        return response()
            ->view('shop::ecosystem.content', [
                'post'      => $dto,
                'canonical' => $canonical,
                'seo'       => [
                    'title'       => (string) $dto['title'],
                    'description' => $description,
                    'canonical'   => $canonical,
                    'robots'      => 'index,follow',
                    'og'          => $this->og('article', (string) $dto['title'], $description, $canonical, $cover),
                    'jsonLd'      => $this->articleJsonLd($dto, $canonical),
                ],
            ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * 统一前置：SSR 开关、kill switch、域开关。
     */
    private function guard(string $domain): mixed
    {
        if (! (bool) config('ecosystem_g4.ssr.enabled', false)) {
            return $this->missing('页面不存在。');
        }

        try {
            $this->gate->assertAvailable();
        } catch (PublicReadUnavailableException $exception) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '公开内容暂时不可用。',
            ], 503, ['Cache-Control' => 'no-store']);
        }

        $enabled = $domain === 'events'
            ? (bool) config('ecosystem.events.public_enabled', false)
            : (bool) config('ecosystem.content.public_enabled', false);

        if (! $enabled) {
            return $this->missing('页面不存在。');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    private function eventFilters(Request $request)
    {
        $keywordMax = max(1, (int) config('ecosystem_g4.search.keyword_max_length', 50));
        $maxPage = max(1, (int) config('ecosystem_g4.search.max_page', 200));

        $validator = Validator::make($request->query(), [
            'q'                  => ['sometimes', 'string', 'max:'.$keywordMax],
            'kind'               => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_kinds', []))],
            'city'               => ['sometimes', 'string', 'max:100'],
            'participation_mode' => ['sometimes', 'string', Rule::in(PublicEventQuery::PARTICIPATION_MODES)],
            'hosting_status'     => ['sometimes', 'string', Rule::in((array) config('ecosystem_publishing.event_hosting_statuses', []))],
            'registration_state' => ['sometimes', 'string', Rule::in(PublicEventQuery::REGISTRATION_STATES)],
            'starts_from'        => ['sometimes', 'date'],
            'starts_to'          => ['sometimes', 'date'],
            'page'               => ['sometimes', 'integer', 'min:1', 'max:'.$maxPage],
        ]);

        if ($validator->fails()) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '筛选参数不合法。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        $filters = array_filter(
            $validator->validated(),
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0,
        );

        unset($filters['page']);

        if ((isset($filters['q']) || isset($filters['registration_state']))
            && ! (bool) config('ecosystem_g4.search.enabled', false)) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '搜索与报名状态筛选暂未启用。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        if (isset($filters['starts_from'], $filters['starts_to'])
            && Carbon::parse((string) $filters['starts_from'])->greaterThan(Carbon::parse((string) $filters['starts_to']))) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '开始时间不得晚于结束时间。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    private function contentFilters(Request $request)
    {
        $keywordMax = max(1, (int) config('ecosystem_g4.search.keyword_max_length', 50));
        $maxPage = max(1, (int) config('ecosystem_g4.search.max_page', 200));

        $validator = Validator::make($request->query(), [
            'q'              => ['sometimes', 'string', 'max:'.$keywordMax],
            'type'           => ['sometimes', 'string', Rule::in((array) config('ecosystem_content.content_types', []))],
            'category'       => ['sometimes', 'string', 'max:64'],
            'published_from' => ['sometimes', 'date'],
            'published_to'   => ['sometimes', 'date'],
            'page'           => ['sometimes', 'integer', 'min:1', 'max:'.$maxPage],
        ]);

        if ($validator->fails()) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '筛选参数不合法。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        $filters = array_filter(
            $validator->validated(),
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0,
        );

        unset($filters['page']);

        if (isset($filters['q']) && ! (bool) config('ecosystem_g4.search.enabled', false)) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '搜索暂未启用。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        if (isset($filters['published_from'], $filters['published_to'])
            && Carbon::parse((string) $filters['published_from'])->greaterThan(Carbon::parse((string) $filters['published_to']))) {
            return response()->view('shop::ecosystem.unavailable', [
                'message' => '开始时间不得晚于结束时间。',
            ], 422, ['Cache-Control' => 'no-store']);
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function seoBase(string $title, string $path, bool $hasFilters): array
    {
        $description = $hasFilters
            ? '筛选结果页，请访问无筛选的列表获取完整内容。'
            : $this->siteUrl->siteName().' · '.$title;

        return [
            'title'       => $hasFilters ? $title.'（筛选）' : $title,
            'description' => $description,
            'canonical'   => $this->siteUrl->absolute($path),
            'robots'      => $hasFilters ? 'noindex,follow' : 'index,follow',
            'og'          => $this->og('website', $title, $description, $this->siteUrl->absolute($path), null),
            'jsonLd'      => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function og(string $type, string $title, string $description, string $url, ?string $image): array
    {
        $og = [
            'og:type'        => $type,
            'og:title'       => $title,
            'og:description' => $description,
            'og:url'         => $url,
            'og:site_name'   => $this->siteUrl->siteName(),
        ];

        if ($image !== null && $image !== '') {
            $og['og:image'] = $image;
        }

        return $og;
    }

    /**
     * @param  array<string, mixed>  $dto
     * @return array<string, mixed>
     */
    private function eventJsonLd(array $dto, string $canonical): array
    {
        $status = match ((string) ($dto['hosting_status'] ?? '')) {
            'canceled'  => 'EventCancelled',
            'postponed' => 'EventPostponed',
            default     => 'EventScheduled',
        };

        $mode = match ((string) ($dto['participation_mode'] ?? '')) {
            'online' => 'OnlineEventAttendanceMode',
            'hybrid' => 'MixedEventAttendanceMode',
            default  => 'OfflineEventAttendanceMode',
        };

        $location = (string) ($dto['participation_mode'] ?? '') === 'online'
            ? ['@type' => 'VirtualLocation', 'url' => (string) ($dto['source']['url'] ?? $canonical)]
            : [
                '@type'   => 'Place',
                'name'    => (string) ($dto['location_text'] ?? $dto['city'] ?? ''),
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => (string) ($dto['city'] ?? ''),
                ],
            ];

        $data = [
            '@context'            => 'https://schema.org',
            '@type'               => 'Event',
            'name'                => (string) $dto['name'],
            'description'         => (string) ($dto['summary'] ?? ''),
            'url'                 => $canonical,
            'eventAttendanceMode' => 'https://schema.org/'.$mode,
            'eventStatus'         => 'https://schema.org/'.$status,
            'location'            => $location,
            'organizer'           => [
                '@type' => 'Organization',
                'name'  => (string) ($dto['organizer']['name'] ?? ''),
            ],
        ];

        if (! empty($dto['starts_at'])) {
            $data['startDate'] = (string) $dto['starts_at'];
        }

        if (! empty($dto['ends_at'])) {
            $data['endDate'] = (string) $dto['ends_at'];
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $dto
     * @return array<string, mixed>
     */
    private function articleJsonLd(array $dto, string $canonical): array
    {
        $data = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => (string) $dto['title'],
            'description'   => (string) ($dto['summary'] ?? ''),
            'url'           => $canonical,
            'datePublished' => (string) ($dto['first_published_at'] ?? ''),
            'dateModified'  => (string) ($dto['published_at'] ?? ''),
            'author'        => [
                '@type' => 'Person',
                'name'  => (string) ($dto['author']['name'] ?? ''),
            ],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => $this->siteUrl->siteName(),
            ],
        ];

        return array_filter($data, static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private function firstCoverUrl(array $media): ?string
    {
        if ($media === []) {
            return null;
        }

        $cover = null;

        foreach ($media as $item) {
            if ((string) ($item['role'] ?? '') === 'cover') {
                $cover = $item;
                break;
            }
        }

        $cover ??= $media[0];
        $url = (string) ($cover['url'] ?? '');

        return $url !== '' ? $this->siteUrl->absolute($url) : null;
    }

    /**
     * @param  array<int, \Webkul\Shop\Models\EcosystemMediaRef>  $refs
     * @param  callable(int): string  $url
     */
    private function cover(array $refs, callable $url): ?string
    {
        if ($refs === []) {
            return null;
        }

        $cover = null;

        foreach ($refs as $ref) {
            if ((string) $ref->role === 'cover') {
                $cover = $ref;
                break;
            }
        }

        $cover ??= $refs[0];

        return $this->siteUrl->absolute($url((int) $cover->media_id));
    }

    private function truncate(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit).'…';
    }

    private function iso(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toIso8601String();
    }

    private function missing(string $message)
    {
        return response()->view('shop::ecosystem.unavailable', [
            'message' => $message,
        ], 404, ['Cache-Control' => 'no-store']);
    }

    private function gone(string $message)
    {
        return response()->view('shop::ecosystem.unavailable', [
            'message' => $message,
        ], 410, ['Cache-Control' => 'no-store']);
    }
}
