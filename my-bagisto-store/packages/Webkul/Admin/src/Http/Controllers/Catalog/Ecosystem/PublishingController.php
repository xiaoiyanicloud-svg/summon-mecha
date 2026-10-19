<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Exceptions\AdminPublishingConflictException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\BeginRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\ContentRevisionFingerprintConflictException;
use Webkul\Shop\Services\Ecosystem\ContentSlugTakenException;
use Webkul\Shop\Services\Ecosystem\ContentSourceValidationException;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventTimeValidationException;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\OrganizerService;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;
use Webkul\Shop\Services\Ecosystem\RevisionConflictException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * 生态发布工作台（G1 Admin）。
 *
 * 这是 G1-08 ~ G1-11 唯一的管理端操作面：活动 / 资讯的元数据、revision 创建、
 * 提交审核、批准 / 拒绝、排期 / 发布、暂缓 / 恢复 / 撤稿，以及主办方创建与核验。
 *
 * 三条硬约束：
 *   1. **授权在控制器入口显式执行**（`authorizeRoute()`）。路由 → 权限映射登记在
 *      `config/ecosystem_admin.php`；同样这张表也被 `User\Http\Middleware\Bouncer`
 *      用于 403 前置防线（防止某个动作忘记调用本方法），这里再做一次更细的
 *      校验。漏登记直接 500，不静默放行。必须 direct URL / Ajax 同权拒绝，
 *      不能只靠隐藏菜单。
 *   2. **所有写动作都带 `expected_row_version` / `expected_revision_id`**。
 *      状态机服务自己做父行锁 + row_version CAS；revision 目标由本控制器先做
 *      CAS，防止“页面上的 revision 已被别的版本取代”时误操作。
 *   3. **业务异常转回表单错误**，不 500；并发冲突统一提示“已被他人修改，请刷新后
 *      再试”。真正的未知异常仍然向上抛，不能被吞成“保存成功”。
 *
 * 本控制器不含：媒体上传 / 审核（G1 媒体面未在本批范围内）、报名写、公开开关。
 * 预览只走 Admin 会话路由，不生成公开预览 token。
 */
class PublishingController extends Controller
{
    public function __construct(
        private readonly EventWriteService $eventWrites,
        private readonly EventReviewService $eventReviews,
        private readonly EventPublicationService $eventPublications,
        private readonly ContentPostService $contentPosts,
        private readonly ContentReviewService $contentReviews,
        private readonly ContentPublicationService $contentPublications,
        private readonly BeginRevisionService $beginRevisions,
        private readonly OrganizerService $organizers,
    ) {}

    // ==================== 工作台 / 列表 ====================

    public function index()
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-publishing.index', [
            'events'     => Event::query()->orderByDesc('id')->limit(50)->get(),
            'posts'      => ContentPost::query()->orderByDesc('id')->limit(50)->get(),
            'organizers' => EcosystemOrganizer::query()->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function contentIndex()
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-publishing.content-index', [
            'posts' => ContentPost::query()->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function organizerIndex()
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-publishing.organizer-index', [
            'organizers' => EcosystemOrganizer::query()->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    // ==================== 活动 ====================

    public function showEvent(int $id)
    {
        $this->authorizeRoute();

        $event = Event::query()
            ->with([
                'organizerEntity',
                'revisions' => fn ($query) => $query->orderByDesc('revision_no'),
            ])
            ->findOrFail($id);

        return view('admin::catalog.ecosystem-publishing.events.show', [
            'event'      => $event,
            'organizers' => EcosystemOrganizer::query()->orderBy('name')->get(),
        ]);
    }

    public function updateEventMetadata(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);

        $data = $request->validate([
            'expected_row_version'  => ['required', 'integer', 'min:0'],
            'kind'                  => ['required', 'string', Rule::in((array) config('ecosystem_publishing.event_kinds', []))],
            'participation_mode'    => ['nullable', 'string', Rule::in((array) config('ecosystem_revisions.participation_modes', []))],
            'hosting_status'        => ['required', 'string', Rule::in((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []))],
            'timezone'              => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'starts_at'             => ['nullable', 'date'],
            'ends_at'               => ['nullable', 'date'],
            'source_type'           => ['required', 'string', Rule::in((array) config('ecosystem_organizers.source_types', []))],
            'platform_role'         => ['required', 'string', Rule::in((array) config('ecosystem_organizers.platform_roles', []))],
            'organizer_id'          => ['required', 'integer', 'min:1'],
        ]);

        $timezone = (string) $data['timezone'];
        $startsAt = $this->localTime($data['starts_at'] ?? null, $timezone, 'starts_at');
        $endsAt = $this->localTime($data['ends_at'] ?? null, $timezone, 'ends_at');

        $this->assertTimeRange($startsAt, $endsAt);

        $attributes = [
            'kind'               => (string) $data['kind'],
            'participation_mode' => $data['participation_mode'] ?? null,
            'hosting_status'     => (string) $data['hosting_status'],
            'timezone'           => $timezone,
            'starts_at'          => $startsAt,
            'ends_at'            => $endsAt,
            'source_type'        => (string) $data['source_type'],
            'platform_role'      => (string) $data['platform_role'],
            'organizer_id'       => (int) $data['organizer_id'],
        ];

        return $this->runAction(
            fn () => $this->eventWrites->update($event, (int) $data['expected_row_version'], $attributes),
            route('admin.catalog.ecosystem.events.show', $id),
            '活动元数据已保存。',
        );
    }

    public function storeEventRevision(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::query()->with('organizerEntity')->findOrFail($id);

        $data = $request->validate([
            'expected_row_version'          => ['required', 'integer', 'min:0'],
            'name'                          => ['required', 'string', 'max:255'],
            'summary'                       => ['nullable', 'string', 'max:500'],
            'detail_html'                   => ['nullable', 'string'],
            'city'                          => ['nullable', 'string', 'max:100'],
            'location_text'                 => ['nullable', 'string', 'max:255'],
            'participation_mode'            => ['nullable', 'string', Rule::in((array) config('ecosystem_revisions.participation_modes', []))],
            'timezone'                      => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'starts_at'                     => ['nullable', 'date'],
            'ends_at'                       => ['nullable', 'date'],
            'source_name'                   => ['required', 'string', 'max:191'],
            'source_url'                    => ['nullable', 'url', 'max:2048'],
            'source_verified_at'            => ['required', 'date'],
            'authorization_basis'           => ['required', 'string', Rule::in((array) config('ecosystem_revisions.authorization_bases', []))],
            'authorization_evidence_ref'    => ['nullable', 'string', 'max:255'],
            'external_registration_url'     => ['nullable', 'url', 'max:2048'],
            'change_summary'                => ['required', 'string', 'max:500'],
        ]);

        $organizer = $event->organizerEntity;

        if ($organizer === null || $event->organizer_id === null) {
            throw ValidationException::withMessages([
                'organizer_id' => '活动尚未配置有效主办方，必须先保存元数据后才能创建 revision。',
            ]);
        }

        $timezone = (string) $data['timezone'];
        $startsAt = $this->localTime($data['starts_at'] ?? null, $timezone, 'starts_at');
        $endsAt = $this->localTime($data['ends_at'] ?? null, $timezone, 'ends_at');

        $this->assertTimeRange($startsAt, $endsAt);

        $payload = [
            'name'                       => (string) $data['name'],
            'summary'                    => $data['summary'] ?? null,
            'detail_html'                => $data['detail_html'] ?? null,
            'city'                       => $data['city'] ?? null,
            'location_text'              => $data['location_text'] ?? null,
            'participation_mode'         => $data['participation_mode'] ?? null,
            'timezone'                   => $timezone,
            'starts_at'                  => $startsAt,
            'ends_at'                    => $endsAt,
            // 来源/主办方快照必须与活动当前事实一致（EventRevisionService 会复核）。
            'source_type'                => (string) $event->source_type,
            'platform_role'              => (string) $event->platform_role,
            'organizer_id'               => (int) $event->organizer_id,
            'organizer_name_snapshot'    => (string) $organizer->name,
            'source_name'                => (string) $data['source_name'],
            'source_url'                 => $data['source_url'] ?? null,
            'source_verified_at'         => $this->localTime($data['source_verified_at'], $timezone, 'source_verified_at'),
            'authorization_basis'        => (string) $data['authorization_basis'],
            'authorization_evidence_ref' => $data['authorization_evidence_ref'] ?? null,
            'external_registration_url'  => $data['external_registration_url'] ?? null,
            'change_summary'             => (string) $data['change_summary'],
        ];

        return $this->runAction(
            fn () => $this->beginRevisions->startEventRevision(
                $event,
                $this->adminId(),
                (int) $data['expected_row_version'],
                $payload,
            ),
            route('admin.catalog.ecosystem.events.show', $id),
            '已创建新的活动 revision（草稿）。',
        );
    }

    public function previewEventRevision(int $id, int $revision)
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);
        $revision = EventRevision::query()
            ->where('event_id', $event->id)
            ->findOrFail($revision);

        return $this->previewResponse('活动预览', [
            '类型'       => (string) $event->kind,
            '活动名称'   => (string) $revision->name,
            '摘要'       => (string) ($revision->summary ?? ''),
            '主办方'     => (string) $revision->organizer_name_snapshot,
            '来源'       => (string) $revision->source_name,
            '来源 URL'   => (string) ($revision->source_url ?? ''),
            '时区'       => (string) $revision->timezone,
            '开始时间'   => $this->displayUtc($revision->starts_at),
            '结束时间'   => $this->displayUtc($revision->ends_at),
            'revision'   => '#'.$revision->revision_no.'（ID '.$revision->id.'，'.$revision->review_state.'）',
        ], $revision->detail_html, $revision->sanitizer_version, 'event_revision', (int) $revision->id);
    }

    public function submitEventRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$event, $revision] = $this->eventRevision($id, $revision);

        return $this->runAction(
            fn () => $this->eventReviews->submit(
                $event,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
            ),
            $this->eventUrl($id),
            '活动 revision '.$revision->id.' 已提交审核。',
        );
    }

    public function approveEventRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$event, $revision] = $this->eventRevision($id, $revision);

        $note = $request->input('review_note');
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        return $this->runAction(
            fn () => $this->eventReviews->approve(
                $event,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                $note,
            ),
            $this->eventUrl($id),
            '活动 revision '.$revision->id.' 已批准。',
        );
    }

    public function rejectEventRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$event, $revision] = $this->eventRevision($id, $revision);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        return $this->runAction(
            fn () => $this->eventReviews->reject(
                $event,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                (string) $data['reason'],
            ),
            $this->eventUrl($id),
            '活动 revision '.$revision->id.' 已拒绝；请创建新 revision 后重新提交。',
        );
    }

    public function scheduleEventRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$event, $revision] = $this->eventRevision($id, $revision);

        $data = $request->validate([
            'scheduled_publish_at' => ['required', 'date'],
        ]);

        $publishAt = $this->appTime($data['scheduled_publish_at']);

        return $this->runAction(
            fn () => $this->eventPublications->schedule(
                $event,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                $publishAt,
            ),
            $this->eventUrl($id),
            '活动 revision '.$revision->id.' 已排期（需 worker 或手工扫描到期发布）。',
        );
    }

    public function publishEventRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$event, $revision] = $this->eventRevision($id, $revision);

        return $this->runAction(
            fn () => $this->eventPublications->publishNow(
                $event,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
            ),
            $this->eventUrl($id),
            '活动 revision '.$revision->id.' 已发布。',
        );
    }

    public function unscheduleEvent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);
        $rowVersion = $this->expectedRowVersion($request);

        return $this->runAction(
            fn () => $this->eventPublications->unschedule($event, $this->adminId(), $rowVersion),
            $this->eventUrl($id),
            '活动排期已取消。',
        );
    }

    public function withholdEvent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);
        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'max:500'],
        ]);

        return $this->runAction(
            fn () => $this->eventPublications->withhold($event, $this->adminId(), (int) $data['expected_row_version'], (string) $data['reason']),
            $this->eventUrl($id),
            '活动已暂缓公开。',
        );
    }

    public function restoreEvent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);
        $rowVersion = $this->expectedRowVersion($request);

        return $this->runAction(
            fn () => $this->eventPublications->restore($event, $this->adminId(), $rowVersion),
            $this->eventUrl($id),
            '活动已恢复公开。',
        );
    }

    public function retractEvent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $event = Event::findOrFail($id);
        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'max:500'],
        ]);

        return $this->runAction(
            fn () => $this->eventPublications->retract($event, $this->adminId(), (int) $data['expected_row_version'], (string) $data['reason']),
            $this->eventUrl($id),
            '活动已撤稿（终态，不可恢复）。',
        );
    }

    // ==================== 资讯 ====================

    public function showContent(int $id)
    {
        $this->authorizeRoute();

        $post = ContentPost::query()
            ->with([
                'revisions' => fn ($query) => $query->orderByDesc('revision_no'),
                'revisions.sources',
            ])
            ->findOrFail($id);

        return view('admin::catalog.ecosystem-publishing.content.show', [
            'post' => $post,
        ]);
    }

    public function storeContent(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'type'     => ['required', 'string', Rule::in((array) config('ecosystem_content.content_types', []))],
            'slug'     => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $post = $this->contentPosts->createDraft($this->adminId(), $data);
        } catch (AdminPublishingConflictException|EventRevisionConflictException|ContentRevisionConflictException $exception) {
            return $this->failBack(route('admin.catalog.ecosystem.content.index'), '已被他人修改，请刷新后再试。');
        } catch (ContentSlugTakenException|ContentTransitionException|InvalidArgumentException $exception) {
            return $this->failBack(route('admin.catalog.ecosystem.content.index'), $exception->getMessage());
        }

        return redirect()
            ->route('admin.catalog.ecosystem.content.show', $post->id)
            ->with('success', '文章壳已创建（草稿）。');
    }

    public function storeContentRevision(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);

        $data = $request->validate([
            'expected_row_version'  => ['required', 'integer', 'min:0'],
            'title'                 => ['required', 'string', 'max:255'],
            'summary'               => ['nullable', 'string', 'max:500'],
            'body_html'             => ['nullable', 'string'],
            'author_name'           => ['required', 'string', 'max:191'],
            'author_org'            => ['nullable', 'string', 'max:191'],
            'source_type'           => ['required', 'string', Rule::in((array) config('ecosystem_content.source_types', []))],
            'original_published_at' => ['nullable', 'date'],
            'correction_note'       => ['nullable', 'string'],
            'change_summary'        => ['required', 'string', 'max:500'],
            'source_name'           => ['required', 'string', 'max:191'],
            'source_url'            => ['required', 'url', 'max:2048'],
            'source_verified_at'    => ['required', 'date'],
            'rights_basis'          => ['required', 'string', Rule::in((array) config('ecosystem_content.rights_bases', []))],
            'rights_scope'          => ['nullable', 'string', 'max:255'],
            'evidence_ref'          => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'title'                 => (string) $data['title'],
            'summary'               => $data['summary'] ?? null,
            'body_html'             => $data['body_html'] ?? null,
            'author_name'           => (string) $data['author_name'],
            'author_org'            => $data['author_org'] ?? null,
            'source_type'           => (string) $data['source_type'],
            'original_published_at' => $this->appTime($data['original_published_at'] ?? null),
            'correction_note'       => $data['correction_note'] ?? null,
            'change_summary'        => (string) $data['change_summary'],
        ];

        $sources = [[
            'source_name'        => (string) $data['source_name'],
            'source_url'         => (string) $data['source_url'],
            'source_verified_at' => $this->appTime($data['source_verified_at']),
            'rights_basis'       => (string) $data['rights_basis'],
            'rights_scope'       => $data['rights_scope'] ?? null,
            'evidence_ref'       => $data['evidence_ref'] ?? null,
        ]];

        return $this->runAction(
            fn () => $this->beginRevisions->startContentRevision(
                $post,
                $this->adminId(),
                (int) $data['expected_row_version'],
                $payload,
                $sources,
            ),
            $this->contentUrl($id),
            '已创建新的资讯 revision（草稿）。多来源将在后续批次支持；当前每条 revision 只登记一条来源。',
        );
    }

    public function previewContentRevision(int $id, int $revision)
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);
        $revision = ContentRevision::query()
            ->where('post_id', $post->id)
            ->findOrFail($revision);

        $sources = $revision->sources()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($source): string => sprintf(
                '%s（%s；权利：%s）',
                (string) $source->source_name,
                (string) ($source->source_url ?? '未提供 URL'),
                (string) $source->rights_basis,
            ))
            ->all();

        return $this->previewResponse('资讯预览', [
            '类型'       => (string) $post->type,
            'slug'       => (string) $post->slug,
            '标题'       => (string) $revision->title,
            '摘要'       => (string) ($revision->summary ?? ''),
            '作者'       => trim(((string) $revision->author_name).' '.((string) ($revision->author_org ?? ''))),
            '来源'       => implode('；', $sources),
            '更正说明'   => (string) ($revision->correction_note ?? ''),
            'revision'   => '#'.$revision->revision_no.'（ID '.$revision->id.'，'.$revision->review_state.'）',
        ], $revision->body_html, $revision->sanitizer_version, 'content_revision', (int) $revision->id);
    }

    public function submitContentRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$post, $revision] = $this->contentRevision($id, $revision);

        return $this->runAction(
            fn () => $this->contentReviews->submit(
                $post,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
            ),
            $this->contentUrl($id),
            '资讯 revision '.$revision->id.' 已提交审核。',
        );
    }

    public function approveContentRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$post, $revision] = $this->contentRevision($id, $revision);

        $note = $request->input('review_note');
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        return $this->runAction(
            fn () => $this->contentReviews->approve(
                $post,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                $note,
            ),
            $this->contentUrl($id),
            '资讯 revision '.$revision->id.' 已批准。',
        );
    }

    public function rejectContentRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$post, $revision] = $this->contentRevision($id, $revision);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        return $this->runAction(
            fn () => $this->contentReviews->reject(
                $post,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                (string) $data['reason'],
            ),
            $this->contentUrl($id),
            '资讯 revision '.$revision->id.' 已拒绝；请创建新 revision 后重新提交。',
        );
    }

    public function scheduleContentRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$post, $revision] = $this->contentRevision($id, $revision);

        $data = $request->validate([
            'scheduled_publish_at' => ['required', 'date'],
        ]);

        $publishAt = $this->appTime($data['scheduled_publish_at']);

        return $this->runAction(
            fn () => $this->contentPublications->schedule(
                $post,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
                $publishAt,
            ),
            $this->contentUrl($id),
            '资讯 revision '.$revision->id.' 已排期（需 worker 或手工扫描到期发布）。',
        );
    }

    public function publishContentRevision(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [$post, $revision] = $this->contentRevision($id, $revision);

        return $this->runAction(
            fn () => $this->contentPublications->publishNow(
                $post,
                $revision,
                $this->adminId(),
                $this->expectedRowVersionForRevision($request, $revision),
            ),
            $this->contentUrl($id),
            '资讯 revision '.$revision->id.' 已发布。',
        );
    }

    public function unscheduleContent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);
        $rowVersion = $this->expectedRowVersion($request);

        return $this->runAction(
            fn () => $this->contentPublications->unschedule($post, $this->adminId(), $rowVersion),
            $this->contentUrl($id),
            '资讯排期已取消。',
        );
    }

    public function withholdContent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);
        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'max:500'],
        ]);

        return $this->runAction(
            fn () => $this->contentPublications->withhold($post, $this->adminId(), (int) $data['expected_row_version'], (string) $data['reason']),
            $this->contentUrl($id),
            '资讯已暂缓公开。',
        );
    }

    public function restoreContent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);
        $rowVersion = $this->expectedRowVersion($request);

        return $this->runAction(
            fn () => $this->contentPublications->restore($post, $this->adminId(), $rowVersion),
            $this->contentUrl($id),
            '资讯已恢复公开。',
        );
    }

    public function retractContent(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);
        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'max:500'],
        ]);

        return $this->runAction(
            fn () => $this->contentPublications->retract($post, $this->adminId(), (int) $data['expected_row_version'], (string) $data['reason']),
            $this->contentUrl($id),
            '资讯已撤稿（终态，不可恢复）。',
        );
    }

    // ==================== 主办方 ====================

    public function storeOrganizer(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'platform_role'        => ['required', 'string', Rule::in((array) config('ecosystem_organizers.platform_roles', []))],
            'source_url'           => ['nullable', 'url', 'max:2048'],
            'public_materials'     => ['nullable', 'string'],
            'private_evidence_ref' => ['nullable', 'string', 'max:255'],
            'verification_note'    => ['nullable', 'string'],
        ]);

        return $this->runAction(
            fn () => $this->organizers->create($data),
            route('admin.catalog.ecosystem.organizers.index'),
            '主办方已创建（pending）。核验通过前不能用于发布。',
        );
    }

    public function verifyOrganizer(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'expected_status'   => ['required', 'string', Rule::in((array) config('ecosystem_organizers.statuses', []))],
            'verification_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $organizer = EcosystemOrganizer::findOrFail($id);

        if ((string) $organizer->status !== (string) $data['expected_status']) {
            return $this->failBack(
                route('admin.catalog.ecosystem.organizers.index'),
                '已被他人修改，请刷新后再试。',
            );
        }

        $note = $data['verification_note'] ?? null;
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        return $this->runAction(
            fn () => $this->organizers->verify($id, $this->adminId(), $note),
            route('admin.catalog.ecosystem.organizers.index'),
            '主办方已核验通过。',
        );
    }

    // ==================== 授权 / 并发 / 错误 ====================

    /**
     * 控制器入口授权：路由名 → 权限 key。
     *
     * 漏登记直接 500（fail-closed），而不是静默放行。这样新增路由时忘记登记权限
     * 会立刻在测试与运行中暴露。
     */
    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        // 路由名本身含点号，不能用 config('...'.$routeName) 的嵌套语法读取。
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '生态发布路由未登记权限：'.$routeName);

        abort_unless(
            bouncer()->hasPermission($permission),
            403,
            '无权执行该生态发布操作。',
        );
    }

    private function adminId(): int
    {
        return (int) auth()->guard('admin')->user()->id;
    }

    /**
     * 读取并验证 revision 目标与父行版本。
     *
     * 必须在 `runAction()` 的闭包内调用：`AdminPublishingConflictException`
     * 需要被统一转回“已被他人修改”的表单错误；若在闭包外抛出会变成 500。
     */
    private function expectedRowVersionForRevision(Request $request, EventRevision|ContentRevision $revision): int
    {
        $rowVersion = $this->expectedRowVersion($request);

        $expectedRevisionId = (int) $request->validate([
            'expected_revision_id' => ['required', 'integer', 'min:1'],
        ])['expected_revision_id'];

        if ($expectedRevisionId !== (int) $revision->getKey()) {
            throw new AdminPublishingConflictException();
        }

        return $rowVersion;
    }

    private function expectedRowVersion(Request $request): int
    {
        return (int) $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ])['expected_row_version'];
    }

    /**
     * @return array{0: Event, 1: EventRevision}
     */
    private function eventRevision(int $eventId, int $revisionId): array
    {
        $event = Event::findOrFail($eventId);

        $revision = EventRevision::query()
            ->where('event_id', $event->id)
            ->findOrFail($revisionId);

        return [$event, $revision];
    }

    /**
     * @return array{0: ContentPost, 1: ContentRevision}
     */
    private function contentRevision(int $postId, int $revisionId): array
    {
        $post = ContentPost::findOrFail($postId);

        $revision = ContentRevision::query()
            ->where('post_id', $post->id)
            ->findOrFail($revisionId);

        return [$post, $revision];
    }

    private function eventUrl(int $id): string
    {
        return route('admin.catalog.ecosystem.events.show', $id);
    }

    private function contentUrl(int $id): string
    {
        return route('admin.catalog.ecosystem.content.show', $id);
    }

    /**
     * 执行业务动作；领域异常转回表单错误，未知异常继续向上抛。
     *
     * 注意：`ValidationException` 不在捕获列表里 —— 参数校验由 Laravel 的
     * validate() 统一处理（web 请求自动回填 + 错误包），不能被这里吞成普通错误。
     */
    private function runAction(callable $action, string $redirect, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AdminPublishingConflictException|EventRevisionConflictException|ContentRevisionConflictException $exception) {
            return $this->failBack($redirect, '已被他人修改，请刷新后再试。');
        } catch (
            PublicationPreconditionException
            |PublicationTransitionException
            |EventSourceValidationException
            |EventTimeValidationException
            |ContentSourceValidationException
            |ContentTransitionException
            |ContentSlugTakenException
            |RevisionConflictException
            |ContentRevisionFingerprintConflictException
            |RevisionImmutableException
            |InvalidArgumentException $exception
        ) {
            return $this->failBack($redirect, $exception->getMessage());
        }

        return redirect()->to($redirect)->with('success', $success);
    }

    private function failBack(string $redirect, string $message): RedirectResponse
    {
        return redirect()
            ->to($redirect)
            ->withInput()
            ->withErrors(['action' => $message])
            ->with('error', $message);
    }

    // ==================== 时间 ====================

    private function localTime(mixed $value, string $timezone, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, $timezone)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => sprintf('时间「%s」无法解析。', (string) $value),
            ]);
        }
    }

    /**
     * 后台排期/来源核验时间按应用时区（北京时间）填写；服务层只接受
     * DateTimeInterface 并显式转 UTC，避免“字符串被当成什么时区”的猜测。
     */
    private function appTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, (string) config('app.timezone', 'Asia/Shanghai'))->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'scheduled_publish_at' => sprintf('时间「%s」无法解析。', (string) $value),
            ]);
        }
    }

    private function assertTimeRange(?Carbon $startsAt, ?Carbon $endsAt): void
    {
        if (($startsAt === null) !== ($endsAt === null)) {
            throw ValidationException::withMessages([
                'starts_at' => '起止时间必须同时填写或同时留空。',
            ]);
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lt($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => '结束时间不得早于开始时间。',
            ]);
        }
    }

    private function displayUtc(mixed $value): string
    {
        return $value === null ? '' : Carbon::parse($value)->utc()->format('Y-m-d H:i').' UTC';
    }

    // ==================== 预览 ====================

    /**
     * 安全预览：只渲染与当前净化版本一致的正文；过期版本一律不输出 HTML。
     *
     * 预览响应固定 `no-store` + `noindex`，不出现 storage_key、私有证据、
     * 审核备注等内部字段，也不生成公开预览 token。
     *
     * @param  array<string, string>  $meta
     */
    private function previewResponse(
        string $title,
        array $meta,
        ?string $html,
        ?string $sanitizerVersion,
        string $ownerType,
        int $ownerId,
    ) {
        $currentVersion = (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1');
        $renderable = $html !== null && $sanitizerVersion !== null && hash_equals($currentVersion, (string) $sanitizerVersion);

        $media = DB::table('ecosystem_media_refs')
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['media_id', 'role', 'alt_text', 'review_status']);

        return response()
            ->view('admin::catalog.ecosystem-publishing._preview', [
                'title'       => $title,
                'meta'        => $meta,
                'html'        => $renderable ? $html : null,
                'stale'       => $html !== null && ! $renderable,
                'media'       => $media,
                'revisionId'  => $ownerId,
            ], 200, [
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag'  => 'noindex, nofollow',
            ]);
    }
}
