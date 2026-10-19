<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Webkul\Admin\Exceptions\AdminPublishingConflictException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\BeginRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\ContentRevisionFingerprintConflictException;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentSlugTakenException;
use Webkul\Shop\Services\Ecosystem\ContentSourceValidationException;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;
use Webkul\Shop\Services\Ecosystem\EventDraftService;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventTimeValidationException;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\MediaRefService;
use Webkul\Shop\Services\Ecosystem\MediaService;
use Webkul\Shop\Services\Ecosystem\MediaTransitionException;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;
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
 * 本控制器同时提供媒体工作台（上传 / 审核 / 隔离）与 revision 媒体引用维护，
 * 但**不提供**报名写、公开开关。预览只走 Admin 会话路由，不生成公开预览 token。
 */
class PublishingController extends Controller
{
    public function __construct(
        private readonly EventWriteService $eventWrites,
        private readonly EventReviewService $eventReviews,
        private readonly EventPublicationService $eventPublications,
        private readonly EventDraftService $eventDrafts,
        private readonly EventRevisionService $eventRevisions,
        private readonly ContentRevisionService $contentRevisions,
        private readonly ContentPostService $contentPosts,
        private readonly ContentReviewService $contentReviews,
        private readonly ContentPublicationService $contentPublications,
        private readonly BeginRevisionService $beginRevisions,
        private readonly OrganizerService $organizers,
        private readonly MediaService $media,
        private readonly MediaRefService $mediaRefs,
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

    public function createEvent()
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-publishing.events.create', [
            'organizers' => EcosystemOrganizer::query()
                ->whereIn('status', ['pending', 'verified'])
                ->orderBy('name')
                ->get(['id', 'name', 'platform_role', 'status']),
        ]);
    }

    public function storeEvent(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'kind'               => ['required', 'string', Rule::in((array) config('ecosystem_publishing.event_kinds', []))],
            'participation_mode' => ['nullable', 'string', Rule::in((array) config('ecosystem_revisions.participation_modes', []))],
            'hosting_status'     => ['required', 'string', Rule::in((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []))],
            'timezone'           => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'starts_at'          => ['nullable', 'date'],
            'ends_at'            => ['nullable', 'date'],
            'source_type'        => ['required', 'string', Rule::in((array) config('ecosystem_organizers.source_types', []))],
            'platform_role'      => ['required', 'string', Rule::in((array) config('ecosystem_organizers.platform_roles', []))],
            'organizer_id'       => ['required', 'integer', 'min:1'],
        ]);

        try {
            $event = $this->eventDrafts->createDraft($this->adminId(), $data);
        } catch (
            EventSourceValidationException
            |EventTimeValidationException
            |InvalidArgumentException $exception
        ) {
            return $this->failBack(
                route('admin.catalog.ecosystem.events.create'),
                $exception->getMessage(),
            );
        }

        return redirect()
            ->route('admin.catalog.ecosystem.events.show', $event->id)
            ->with('success', '活动草稿已创建；请创建 revision 并走审核后才能发布。');
    }

    public function showEvent(int $id)
    {
        $this->authorizeRoute();

        $event = Event::query()
            ->with([
                'organizerEntity',
                'revisions' => fn ($query) => $query->orderByDesc('revision_no'),
                // 只取展示需要的列：storage_key / disk / sha256 不进入视图数据。
                'revisions.mediaRefs.media' => fn ($query) => $query->select(
                    'id',
                    'original_name',
                    'mime',
                    'byte_size',
                    'width',
                    'height',
                    'status',
                ),
            ])
            ->findOrFail($id);

        return view('admin::catalog.ecosystem-publishing.events.show', [
            'event'         => $event,
            'organizers'    => EcosystemOrganizer::query()
                ->orderBy('name')
                ->get(['id', 'name', 'platform_role', 'status']),
            'approvedMedia' => $this->approvedMediaForSelection(),
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

    /**
     * 二次编辑仍在 draft 的活动 revision。
     *
     * 与创建的关键差异：
     *   · 用 `expected_fingerprint` 做内容 CAS，而不是父行 row_version ——
     *     编辑 revision 不改变父对象版本，指纹才是“页面基于哪一版内容”的凭据；
     *   · 服务层在 revision 行锁内复核 `review_state=draft`，已提审 / 批准 /
     *     拒绝的 revision 即使直接 POST 也会被拒绝；
     *   · 指纹过期时返回明确的“内容已被他人修改”，**不静默覆盖**；
     *   · 只写表单覆盖的内容字段；来源快照（source_type / platform_role /
     *     organizer_*）、授权证据引用与外部报名 URL 保留原值；
     *   · 媒体引用由 MediaRefService 维护，编辑正文不会清空引用，指纹按合并
     *     后的完整内容重算（含 media_manifest_hash）。
     */
    public function updateEventRevisionDraft(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [, $draft] = $this->eventRevision($id, $revision);

        $data = $request->validate([
            'expected_fingerprint'  => ['required', 'string', 'size:64'],
            'name'                  => ['required', 'string', 'max:255'],
            'summary'               => ['nullable', 'string', 'max:500'],
            'detail_html'           => ['nullable', 'string'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'location_text'         => ['nullable', 'string', 'max:255'],
            'participation_mode'    => ['nullable', 'string', Rule::in((array) config('ecosystem_revisions.participation_modes', []))],
            'timezone'              => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'starts_at'             => ['nullable', 'date'],
            'ends_at'               => ['nullable', 'date'],
            'source_name'           => ['required', 'string', 'max:191'],
            'source_url'            => ['nullable', 'url', 'max:2048'],
            'source_verified_at'    => ['required', 'date'],
            'authorization_basis'   => ['required', 'string', Rule::in((array) config('ecosystem_revisions.authorization_bases', []))],
            'change_summary'        => ['required', 'string', 'max:500'],
        ]);

        $timezone = (string) $data['timezone'];
        $startsAt = $this->localTime($data['starts_at'] ?? null, $timezone, 'starts_at');
        $endsAt = $this->localTime($data['ends_at'] ?? null, $timezone, 'ends_at');

        $this->assertTimeRange($startsAt, $endsAt);

        $payload = [
            'name'                => (string) $data['name'],
            'summary'             => $data['summary'] ?? null,
            'detail_html'         => $data['detail_html'] ?? null,
            'city'                => $data['city'] ?? null,
            'location_text'       => $data['location_text'] ?? null,
            'participation_mode'  => $data['participation_mode'] ?? null,
            'timezone'            => $timezone,
            'starts_at'           => $startsAt,
            'ends_at'             => $endsAt,
            'source_name'         => (string) $data['source_name'],
            'source_url'          => $data['source_url'] ?? null,
            'source_verified_at'  => $this->localTime($data['source_verified_at'], $timezone, 'source_verified_at'),
            'authorization_basis' => (string) $data['authorization_basis'],
            'change_summary'      => (string) $data['change_summary'],
        ];

        return $this->runAction(
            fn () => $this->eventRevisions->updateDraft(
                $draft,
                (string) $data['expected_fingerprint'],
                $payload,
            ),
            $this->eventUrl($id),
            '活动草稿 revision '.$draft->id.' 已保存。',
            '内容已被他人修改，请刷新后重试；本次编辑未保存。',
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

    /**
     * 整组替换某条活动 revision 的媒体引用。
     *
     * 只能在 draft 上操作：MediaRefService 与模型守卫双重拒绝非 draft。
     * 替换会重算 media_manifest_hash / content_fingerprint，旧的批准不会继承。
     */
    public function replaceEventRevisionMediaRefs(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [, $revision] = $this->eventRevision($id, $revision);
        $refs = $this->mediaRefInput($request);

        return $this->runAction(
            fn () => $this->mediaRefs->replaceForRevision('event_revision', (int) $revision->id, $refs),
            $this->eventUrl($id),
            '媒体引用已保存；引用审核通过后才能提交 revision。',
        );
    }

    public function reviewEventMediaRef(Request $request, int $id, int $revision, int $ref): RedirectResponse
    {
        $this->authorizeRoute();

        [, $revision] = $this->eventRevision($id, $revision);

        return $this->reviewMediaRef($request, 'event_revision', (int) $revision->id, $ref, $this->eventUrl($id));
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
                // 只取展示需要的列：storage_key / disk / sha256 不进入视图数据。
                'revisions.mediaRefs.media' => fn ($query) => $query->select(
                    'id',
                    'original_name',
                    'mime',
                    'byte_size',
                    'width',
                    'height',
                    'status',
                ),
            ])
            ->findOrFail($id);

        return view('admin::catalog.ecosystem-publishing.content.show', [
            'post'          => $post,
            'approvedMedia' => $this->approvedMediaForSelection(),
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

    /**
     * 编辑文章壳元数据（type / slug / category）。
     *
     * 走 ContentPostService::updateDraft + expected_row_version：
     *   · 只有 draft 文章可编辑；
     *   · slug 首次发布后冻结，错误必须原样回给运营，而不是被泛化成“保存失败”。
     */
    public function updateContentMetadata(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $post = ContentPost::findOrFail($id);

        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'type'                 => ['required', 'string', Rule::in((array) config('ecosystem_content.content_types', []))],
            'slug'                 => ['required', 'string', 'max:120'],
            'category'             => ['nullable', 'string', 'max:64'],
        ]);

        // 已首次发布的 slug 冻结要给出明确原因；否则服务层会先以
        // “非 draft 不可编辑”拒绝，运营看不到真正的问题。
        if ($post->first_published_at !== null && (string) $data['slug'] !== (string) $post->slug) {
            return $this->failBack(
                $this->contentUrl($id),
                sprintf(
                    '文章 %d 已首次发布，slug 不可修改；如需更换公开地址必须走独立迁移策略。',
                    $id,
                ),
            );
        }

        return $this->runAction(
            fn () => $this->contentPosts->updateDraft(
                $post,
                (int) $data['expected_row_version'],
                $this->adminId(),
                [
                    'type'     => (string) $data['type'],
                    'slug'     => (string) $data['slug'],
                    'category' => $data['category'] ?? null,
                ],
            ),
            $this->contentUrl($id),
            '文章元数据已保存。',
        );
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
            // 1~N 条来源。空行由 parseContentSources() 丢弃；至少保留一条。
            'sources'               => ['required', 'array', 'min:1', 'max:20'],
            'sources.*.source_name' => ['nullable', 'string', 'max:191'],
            'sources.*.source_url'  => ['nullable', 'string', 'max:2048'],
            'sources.*.source_verified_at' => ['nullable', 'date'],
            'sources.*.rights_basis' => ['nullable', 'string', Rule::in((array) config('ecosystem_content.rights_bases', []))],
            'sources.*.rights_scope' => ['nullable', 'string', 'max:255'],
            'sources.*.evidence_ref' => ['nullable', 'string', 'max:255'],
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

        $sources = $this->parseContentSources((array) $data['sources']);

        return $this->runAction(
            fn () => $this->beginRevisions->startContentRevision(
                $post,
                $this->adminId(),
                (int) $data['expected_row_version'],
                $payload,
                $sources,
            ),
            $this->contentUrl($id),
            '已创建新的资讯 revision（草稿），共 '.count($sources).' 条来源。',
        );
    }

    /**
     * 二次编辑仍在 draft 的资讯 revision。
     *
     * 来源表单始终提交 1~N 行，因此按“整组替换”写入：服务层在 draft 守卫下
     * 先删旧来源再按输入顺序重排 0..n-1，并把有序来源纳入指纹。
     * stale 指纹与活动同口径：明确提示“内容已被他人修改”，不改库。
     */
    public function updateContentRevisionDraft(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [, $draft] = $this->contentRevision($id, $revision);

        $data = $request->validate([
            'expected_fingerprint'  => ['required', 'string', 'size:64'],
            'title'                 => ['required', 'string', 'max:255'],
            'summary'               => ['nullable', 'string', 'max:500'],
            'body_html'             => ['nullable', 'string'],
            'author_name'           => ['required', 'string', 'max:191'],
            'author_org'            => ['nullable', 'string', 'max:191'],
            'source_type'           => ['required', 'string', Rule::in((array) config('ecosystem_content.source_types', []))],
            'original_published_at' => ['nullable', 'date'],
            'correction_note'       => ['nullable', 'string'],
            'change_summary'        => ['required', 'string', 'max:500'],
            // 1~N 条来源。空行由 parseContentSources() 丢弃；至少保留一条。
            'sources'               => ['required', 'array', 'min:1', 'max:20'],
            'sources.*.source_name' => ['nullable', 'string', 'max:191'],
            'sources.*.source_url'  => ['nullable', 'string', 'max:2048'],
            'sources.*.source_verified_at' => ['nullable', 'date'],
            'sources.*.rights_basis' => ['nullable', 'string', Rule::in((array) config('ecosystem_content.rights_bases', []))],
            'sources.*.rights_scope' => ['nullable', 'string', 'max:255'],
            'sources.*.evidence_ref' => ['nullable', 'string', 'max:255'],
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

        $sources = $this->parseContentSources((array) $data['sources']);

        return $this->runAction(
            fn () => $this->contentRevisions->updateDraft(
                $draft,
                (string) $data['expected_fingerprint'],
                $payload,
                $sources,
            ),
            $this->contentUrl($id),
            '资讯草稿 revision '.$draft->id.' 已保存，共 '.count($sources).' 条来源。',
            '内容已被他人修改，请刷新后重试；本次编辑未保存。',
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

    /**
     * 整组替换某条资讯 revision 的媒体引用（同上：仅 draft，审核不继承）。
     */
    public function replaceContentRevisionMediaRefs(Request $request, int $id, int $revision): RedirectResponse
    {
        $this->authorizeRoute();

        [, $revision] = $this->contentRevision($id, $revision);
        $refs = $this->mediaRefInput($request);

        return $this->runAction(
            fn () => $this->mediaRefs->replaceForRevision('content_revision', (int) $revision->id, $refs),
            $this->contentUrl($id),
            '媒体引用已保存；引用审核通过后才能提交 revision。',
        );
    }

    public function reviewContentMediaRef(Request $request, int $id, int $revision, int $ref): RedirectResponse
    {
        $this->authorizeRoute();

        [, $revision] = $this->contentRevision($id, $revision);

        return $this->reviewMediaRef($request, 'content_revision', (int) $revision->id, $ref, $this->contentUrl($id));
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

    public function rejectOrganizer(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'expected_status' => ['required', 'string', Rule::in((array) config('ecosystem_organizers.statuses', []))],
            'reason'          => ['required', 'string', 'max:5000'],
        ]);

        return $this->reviewOrganizer(
            $id,
            'reject',
            (string) $data['expected_status'],
            (string) $data['reason'],
        );
    }

    public function suspendOrganizer(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            'expected_status' => ['required', 'string', Rule::in((array) config('ecosystem_organizers.statuses', []))],
            'reason'          => ['required', 'string', 'max:5000'],
        ]);

        return $this->reviewOrganizer(
            $id,
            'suspend',
            (string) $data['expected_status'],
            (string) $data['reason'],
        );
    }

    // ==================== 媒体工作台 ====================

    public function mediaIndex()
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-publishing.media.index', [
            // 只查询展示需要的列：storage_key / disk / sha256 不进入视图数据。
            'media' => EcosystemMedia::query()
                ->select('id', 'original_name', 'mime', 'byte_size', 'width', 'height', 'status', 'uploaded_by', 'created_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function storeMedia(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $data = $request->validate([
            // 真实 MIME / 扩展名 / 尺寸 / sha256 由 MediaService 用 finfo 等解码校验，
            // 这里的 max 只是先把超大文件挡在应用层之外（KB）。
            'file' => ['required', 'file', 'max:10240'],
        ]);

        return $this->runAction(
            fn () => $this->media->upload($data['file'], $this->adminId()),
            route('admin.catalog.ecosystem.media.index'),
            '媒体已上传（pending）；审核通过后才能被 revision 引用。',
        );
    }

    public function approveMedia(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        return $this->reviewMedia($request, $id, 'approve');
    }

    public function rejectMedia(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        return $this->reviewMedia($request, $id, 'reject');
    }

    public function quarantineMedia(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoute();

        return $this->reviewMedia($request, $id, 'quarantine');
    }

    /**
     * Admin 媒体预览流（ecosystem.media.view）。
     *
     * 只服务后台已登录且有权用户，从私有盘流式输出：
     *   · `Content-Type` 使用数据库里经 MediaService 解码校验的真实 MIME；
     *   · 图片 inline，PDF（及其他非图片）attachment，避免浏览器内联打开不可信文档；
     *   · `X-Content-Type-Options: nosniff` + `Cache-Control: private, no-store`；
     *   · 响应头 / 响应体都不出现 `disk` / `storage_key` / 磁盘路径；
     *   · 文件缺失统一 404（不区分“记录不存在”与“私有盘缺件”）。
     */
    public function previewMedia(int $id)
    {
        $this->authorizeRoute();

        $media = EcosystemMedia::query()->findOrFail($id);

        $disk = (string) $media->disk;
        $key = (string) $media->storage_key;

        if (! Storage::disk($disk)->exists($key)) {
            abort(404, '媒体文件不存在。');
        }

        try {
            $size = (int) Storage::disk($disk)->size($key);
        } catch (\Throwable) {
            abort(404, '媒体文件不存在。');
        }

        $mime = (string) $media->mime;
        $mime = $mime !== '' ? $mime : 'application/octet-stream';

        // rawurlencode 去掉引号 / 换行等响应头注入字符；原文件名在上传时已净化。
        $filename = rawurlencode((string) ($media->original_name ?: 'media'));

        $headers = [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) $size,
            'Content-Disposition'    => str_starts_with($mime, 'image/')
                ? 'inline'
                : "attachment; filename*=UTF-8''".$filename,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
            'X-Robots-Tag'           => 'noindex, nofollow',
        ];

        return response()->stream(
            function () use ($disk, $key): void {
                $stream = Storage::disk($disk)->readStream($key);

                if (! is_resource($stream)) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            200,
            $headers,
        );
    }

    // ==================== 内部动作 ====================

    private function reviewOrganizer(
        int $id,
        string $action,
        string $expectedStatus,
        string $reason,
    ): RedirectResponse {
        $organizer = EcosystemOrganizer::findOrFail($id);

        // expected_status 是页面状态 CAS：页面过期时不得静默覆盖别人的核验结论。
        if ((string) $organizer->status !== $expectedStatus) {
            return $this->failBack(
                route('admin.catalog.ecosystem.organizers.index'),
                '已被他人修改，请刷新后再试。',
            );
        }

        return $this->runAction(
            fn () => $action === 'reject'
                ? $this->organizers->reject($id, $this->adminId(), $reason)
                : $this->organizers->suspend($id, $this->adminId(), $reason),
            route('admin.catalog.ecosystem.organizers.index'),
            $action === 'reject' ? '主办方已驳回。' : '主办方已暂停。',
        );
    }

    private function reviewMedia(Request $request, int $id, string $action): RedirectResponse
    {
        $data = $request->validate([
            'expected_status' => ['required', 'string', Rule::in((array) config('ecosystem_media.statuses', []))],
        ]);

        $media = EcosystemMedia::findOrFail($id);

        if ((string) $media->status !== (string) $data['expected_status']) {
            return $this->failBack(
                route('admin.catalog.ecosystem.media.index'),
                '已被他人修改，请刷新后再试。',
            );
        }

        [$callback, $message] = match ($action) {
            'approve'    => [fn () => $this->media->approve($media, $this->adminId()), '媒体已批准。'],
            'reject'     => [fn () => $this->media->reject($media, $this->adminId()), '媒体已拒绝。'],
            'quarantine' => [fn () => $this->media->quarantine($media, $this->adminId()), '媒体已隔离；已隔离不能原地恢复。'],
        };

        return $this->runAction($callback, route('admin.catalog.ecosystem.media.index'), $message);
    }

    /**
     * 解析 revision 媒体引用输入。
     *
     * 固定 5 行表单会提交空行；空行必须被忽略而不是当成非法 media_id。
     * `sort_order` 不由调用方决定：MediaRefService 按本数组顺序重排 0..n-1。
     *
     * @return array<int, array{media_id: int, role: string, alt_text: ?string}>
     */
    private function mediaRefInput(Request $request): array
    {
        $raw = $request->input('refs', []);

        if ($raw === null || $raw === '') {
            return [];
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages(['refs' => '媒体引用格式非法。']);
        }

        $max = (int) config('ecosystem_media.max_refs_per_revision', 20);

        if (count($raw) > $max) {
            throw ValidationException::withMessages([
                'refs' => sprintf('一个 revision 最多引用 %d 个媒体。', $max),
            ]);
        }

        $roles = array_values((array) config('ecosystem_media.roles', []));
        $refs = [];

        foreach (array_values($raw) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "refs.$index" => '第 '.($index + 1).' 行媒体引用格式非法。',
                ]);
            }

            $mediaId = $row['media_id'] ?? null;

            // 空行：没有选媒体即不提交该行。
            if ($mediaId === null || $mediaId === '') {
                continue;
            }

            if (
                ! (is_int($mediaId) && $mediaId > 0)
                && ! (is_string($mediaId) && preg_match('/^[1-9]\d*$/', $mediaId) === 1)
            ) {
                throw ValidationException::withMessages([
                    "refs.$index.media_id" => 'media_id 必须是正整数。',
                ]);
            }

            $role = $row['role'] ?? null;

            if (! is_string($role) || ! in_array($role, $roles, true)) {
                throw ValidationException::withMessages([
                    "refs.$index.role" => '选择媒体后必须选择引用角色（'.implode(' / ', $roles).'）。',
                ]);
            }

            $altText = $row['alt_text'] ?? null;

            if ($altText !== null && ! is_string($altText)) {
                throw ValidationException::withMessages([
                    "refs.$index.alt_text" => 'alt_text 必须是字符串或留空。',
                ]);
            }

            $altText = is_string($altText) ? trim($altText) : null;

            if (is_string($altText) && mb_strlen($altText) > 255) {
                throw ValidationException::withMessages([
                    "refs.$index.alt_text" => 'alt_text 不得超过 255 个字符。',
                ]);
            }

            $refs[] = [
                'media_id' => (int) $mediaId,
                'role'     => $role,
                'alt_text' => $altText === '' ? null : $altText,
            ];
        }

        return $refs;
    }

    private function reviewMediaRef(
        Request $request,
        string $ownerType,
        int $ownerId,
        int $refId,
        string $redirect,
    ): RedirectResponse {
        $data = $request->validate([
            'expected_review_status' => ['required', 'string', Rule::in((array) config('ecosystem_media.review_statuses', []))],
            'status'                 => ['required', 'string', Rule::in(['approved', 'rejected'])],
        ]);

        $ref = EcosystemMediaRef::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->findOrFail($refId);

        if ((string) $ref->review_status !== (string) $data['expected_review_status']) {
            return $this->failBack($redirect, '已被他人修改，请刷新后再试。');
        }

        return $this->runAction(
            fn () => $this->mediaRefs->reviewRef((int) $ref->id, (string) $data['status'], $this->adminId()),
            $redirect,
            '媒体引用已标记为 '.$data['status'].'。',
        );
    }

    /**
     * 把表单里的 1~N 行来源整理成 ContentRevisionService 的 sources 数组。
     *
     * 全空行直接丢弃；至少保留一条。必填项（名称 / 核验时间 / 权利依据，
     * 以及 authorized_repost 的 scope + evidence）由服务层按 source_type 校验。
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function parseContentSources(array $rows): array
    {
        $sources = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "sources.$index" => '第 '.($index + 1).' 行来源格式非法。',
                ]);
            }

            $name = $this->trimmedOrNull($row['source_name'] ?? null, "sources.$index.source_name");
            $url = $this->trimmedOrNull($row['source_url'] ?? null, "sources.$index.source_url");
            $verifiedAt = $row['source_verified_at'] ?? null;
            $rightsBasis = $this->trimmedOrNull($row['rights_basis'] ?? null, "sources.$index.rights_basis");
            $rightsScope = $this->trimmedOrNull($row['rights_scope'] ?? null, "sources.$index.rights_scope");
            $evidenceRef = $this->trimmedOrNull($row['evidence_ref'] ?? null, "sources.$index.evidence_ref");

            $verifiedAtEmpty = $verifiedAt === null || $verifiedAt === '';

            if ($name === null && $url === null && $verifiedAtEmpty
                && $rightsBasis === null && $rightsScope === null && $evidenceRef === null) {
                continue;
            }

            $sources[] = [
                'source_name'        => $name,
                'source_url'         => $url,
                'source_verified_at' => $verifiedAtEmpty
                    ? null
                    : $this->appTime($verifiedAt, "sources.$index.source_verified_at"),
                'rights_basis'       => $rightsBasis,
                'rights_scope'       => $rightsScope,
                'evidence_ref'       => $evidenceRef,
            ];
        }

        if ($sources === []) {
            throw ValidationException::withMessages([
                'sources' => '至少填写一条来源。',
            ]);
        }

        return $sources;
    }

    private function trimmedOrNull(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => '该字段必须是字符串。',
            ]);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * 下拉选择用的已批准媒体。刻意不选择 storage_key / disk / sha256。
     */
    private function approvedMediaForSelection()
    {
        return EcosystemMedia::query()
            ->where('status', 'approved')
            ->select('id', 'original_name', 'mime', 'byte_size', 'width', 'height')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
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
     * `$conflictMessage` 只覆盖“并发冲突”一类（指纹 / 版本），让草稿编辑能给出
     * “内容已被他人修改，请刷新”这一明确提示；其他领域异常仍用服务层原文。
     *
     * 注意：`ValidationException` 不在捕获列表里 —— 参数校验由 Laravel 的
     * validate() 统一处理（web 请求自动回填 + 错误包），不能被这里吞成普通错误。
     */
    private function runAction(
        callable $action,
        string $redirect,
        string $success,
        ?string $conflictMessage = null,
    ): RedirectResponse {
        try {
            $action();
        } catch (RevisionConflictException|ContentRevisionFingerprintConflictException $exception) {
            return $this->failBack($redirect, $conflictMessage ?? $exception->getMessage());
        } catch (AdminPublishingConflictException|EventRevisionConflictException|ContentRevisionConflictException $exception) {
            return $this->failBack($redirect, $conflictMessage ?? '已被他人修改，请刷新后再试。');
        } catch (
            PublicationPreconditionException
            |PublicationTransitionException
            |EventSourceValidationException
            |EventTimeValidationException
            |ContentSourceValidationException
            |ContentTransitionException
            |ContentSlugTakenException
            |RevisionImmutableException
            |MediaValidationException
            |MediaTransitionException
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
    private function appTime(mixed $value, string $field = 'scheduled_publish_at'): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value, (string) config('app.timezone', 'Asia/Shanghai'))->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => sprintf('时间「%s」无法解析。', (string) $value),
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
