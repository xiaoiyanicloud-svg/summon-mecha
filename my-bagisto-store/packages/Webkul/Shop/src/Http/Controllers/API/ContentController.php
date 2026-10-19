<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Http\Resources\Ecosystem\ContentPublicResource;
use Webkul\Shop\Http\Resources\Ecosystem\MediaPublicResource;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Services\Ecosystem\PublicBodyRenderer;
use Webkul\Shop\Services\Ecosystem\PublicContentQuery;
use Webkul\Shop\Services\Ecosystem\PublicMediaResolver;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;

/**
 * 公开资讯 API（ADR-0001 G1-D08 / 规划 §6 / G1-10）。
 *
 * 与活动公开 API 同构：
 *   · 列表只返回 published + normal 且公开版本已批准的文章，按
 *     `first_published_at DESC, id DESC` 稳定排序；
 *   · 详情未公开统一 404 `CONTENT_NOT_FOUND`；已撤稿且曾发布 → 410
 *     `CONTENT_UNAVAILABLE`，中性文案，不回显旧标题/正文/来源/媒体；
 *   · kill switch 最先检查（503，不查库）；域开关关闭时列表 `{data:[]}`、
 *     详情 404，同样不查库。
 *
 * 本期不做全文搜索 / SSR / OG / sitemap / 收藏。
 */
class ContentController extends APIController
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly PublicContentQuery $contents,
        private readonly PublicMediaResolver $media,
        private readonly PublicBodyRenderer $renderer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! $this->publicEnabled()) {
            return response()->json(['data' => []]);
        }

        $validator = Validator::make($request->query(), $this->indexRules());

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        $filters = $this->filters($validator->validated());

        if ($rangeError = $this->assertDateRange($filters)) {
            return $rangeError;
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? PublicContentQuery::DEFAULT_PER_PAGE);

        unset($filters['page'], $filters['per_page']);

        $paginator = $this->contents->paginate($filters, $page, $perPage);

        $stale = $this->contents->countIncompatibleSanitizer($filters);

        if ($stale > 0) {
            Log::error('ecosystem.public_content.sanitizer_version_mismatch', [
                'scope' => 'list',
                'count' => $stale,
            ]);
        }

        $revisionIds = $paginator->getCollection()
            ->pluck('published_revision_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $refsByRevision = $this->media->approvedRefsByRevision('content_revision', $revisionIds);

        $data = [];

        foreach ($paginator->items() as $post) {
            /** @var ContentPost $post */
            $revision = $post->publishedRevision;

            if (! $revision instanceof ContentRevision) {
                continue;
            }

            $data[] = $this->toDto($post, $revision, $refsByRevision[(int) $revision->id] ?? []);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        if ($unavailable = $this->publicReadUnavailable()) {
            return $unavailable;
        }

        if (! $this->publicEnabled()) {
            return $this->contentNotFound();
        }

        $post = $this->contents->findPublishedBySlug($slug);

        if (! $post) {
            return $this->contents->findRetractedBySlug($slug)
                ? $this->fail(ApiErrorCode::CONTENT_UNAVAILABLE, '该文章已撤稿，内容不再提供。', 410)
                : $this->contentNotFound();
        }

        $revision = $post->publishedRevision;

        if (! $revision instanceof ContentRevision) {
            return $this->contentNotFound();
        }

        if (! $this->contents->bodyIsRenderable($revision)) {
            Log::error('ecosystem.public_content.sanitizer_version_mismatch', [
                'scope'             => 'detail',
                'post_id'           => (int) $post->id,
                'revision_id'       => (int) $revision->id,
                'sanitizer_version' => (string) $revision->sanitizer_version,
            ]);

            return $this->contentNotFound();
        }

        $refs = $this->media->approvedRefsByRevision('content_revision', [(int) $revision->id]);

        return response()->json([
            'data' => $this->toDto($post, $revision, $refs[(int) $revision->id] ?? []),
        ]);
    }

    /**
     * @param  array<int, \Webkul\Shop\Models\EcosystemMediaRef>  $refs
     * @return array<string, mixed>
     */
    private function toDto(ContentPost $post, ContentRevision $revision, array $refs): array
    {
        $media = MediaPublicResource::forContent((string) $post->slug, $refs);

        $allowedMediaIds = array_map(
            static fn (\Webkul\Shop\Models\EcosystemMediaRef $ref): int => (int) $ref->media_id,
            $refs,
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

        $timezone = (string) config('app.timezone', 'Asia/Shanghai');

        return (new ContentPublicResource(
            $post,
            $revision,
            ContentPublicResource::sources($revision->sources, $timezone),
            $bodyHtml,
            $media,
        ))->toArray();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function indexRules(): array
    {
        return [
            'page'           => ['sometimes', 'integer', 'min:1'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:'.PublicContentQuery::MAX_PER_PAGE],
            'type'           => ['sometimes', 'string', Rule::in((array) config('ecosystem_content.content_types', []))],
            'category'       => ['sometimes', 'string', 'max:64'],
            'published_from' => ['sometimes', 'date'],
            'published_to'   => ['sometimes', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filters(array $validated): array
    {
        return array_filter(
            $validated,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function assertDateRange(array $filters): ?JsonResponse
    {
        if (! isset($filters['published_from'], $filters['published_to'])) {
            return null;
        }

        if (Carbon::parse((string) $filters['published_from'])
            ->greaterThan(Carbon::parse((string) $filters['published_to']))) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '筛选参数不合法。',
                ['errors' => ['published_from' => ['开始时间不得晚于结束时间。']]],
            );
        }

        return null;
    }

    private function publicReadUnavailable(): ?JsonResponse
    {
        try {
            $this->gate->assertAvailable();
        } catch (PublicReadUnavailableException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return null;
    }

    private function contentNotFound(): JsonResponse
    {
        return $this->fail(ApiErrorCode::CONTENT_NOT_FOUND, '文章尚未公开发布或不存在。', 404);
    }

    private function publicEnabled(): bool
    {
        return (bool) config('ecosystem.content.public_enabled', false);
    }
}
