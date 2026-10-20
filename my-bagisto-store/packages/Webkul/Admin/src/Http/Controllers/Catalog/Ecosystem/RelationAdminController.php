<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemRelation;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\RelationService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G4-03 · 关联维护（活动 / 资讯）。
 *
 * 只允许选择真实存在的目标；不提供自由文本 URL，避免把关联变成软广外链。
 * 目标当前是否公开不在这里判断 —— 公开解析会逐项复核，失效项不输出。
 * 路由 → 权限登记在 `config/ecosystem_admin.php`，控制器入口再次显式授权。
 */
class RelationAdminController extends Controller
{
    public function __construct(
        private readonly RelationService $relations,
        private readonly OperationLogger $logger,
    ) {}

    public function eventRelations(int $eventId)
    {
        $this->authorizeRoute();
        $event = Event::query()->findOrFail($eventId);

        return view('admin::catalog.ecosystem-publishing.relations', [
            'sourceType'  => RelationService::SOURCE_EVENT,
            'sourceLabel' => '活动',
            'source'      => $event,
            'sourceName'  => (string) $event->name,
            'relations'   => $this->rows(RelationService::SOURCE_EVENT, $eventId),
            'targetTypes' => RelationService::TARGET_TYPES,
            'backRoute'   => route('admin.catalog.ecosystem.events.show', $eventId),
        ]);
    }

    public function storeEventRelation(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        Event::query()->findOrFail($eventId);

        return $this->store($request, RelationService::SOURCE_EVENT, $eventId, 'admin.catalog.ecosystem.relations.events.index');
    }

    public function destroyEventRelation(int $eventId, int $relationId): RedirectResponse
    {
        $this->authorizeRoute();

        return $this->destroy(RelationService::SOURCE_EVENT, $eventId, $relationId, 'admin.catalog.ecosystem.relations.events.index');
    }

    public function contentRelations(int $postId)
    {
        $this->authorizeRoute();
        $post = ContentPost::query()->findOrFail($postId);

        return view('admin::catalog.ecosystem-publishing.relations', [
            'sourceType'  => RelationService::SOURCE_CONTENT,
            'sourceLabel' => '资讯',
            'source'      => $post,
            'sourceName'  => (string) $post->slug,
            'relations'   => $this->rows(RelationService::SOURCE_CONTENT, $postId),
            'targetTypes' => RelationService::TARGET_TYPES,
            'backRoute'   => route('admin.catalog.ecosystem.content.show', $postId),
        ]);
    }

    public function storeContentRelation(Request $request, int $postId): RedirectResponse
    {
        $this->authorizeRoute();
        ContentPost::query()->findOrFail($postId);

        return $this->store($request, RelationService::SOURCE_CONTENT, $postId, 'admin.catalog.ecosystem.relations.content.index');
    }

    public function destroyContentRelation(int $postId, int $relationId): RedirectResponse
    {
        $this->authorizeRoute();

        return $this->destroy(RelationService::SOURCE_CONTENT, $postId, $relationId, 'admin.catalog.ecosystem.relations.content.index');
    }

    private function store(Request $request, string $sourceType, int $sourceId, string $routeName): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'string', Rule::in(RelationService::TARGET_TYPES)],
            'target_key'  => ['required', 'string', 'max:191'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $targetKey = trim((string) $data['target_key']);

        if (! $this->relations->targetExists((string) $data['target_type'], $targetKey)) {
            return back()->withInput()->withErrors([
                'action' => '目标不存在。活动/机型/技能/场景请填 ID；资讯请填 slug。',
            ]);
        }

        $relation = EcosystemRelation::query()->firstOrNew([
            'source_type' => $sourceType,
            'source_key'  => (string) $sourceId,
            'target_type' => (string) $data['target_type'],
            'target_key'  => $targetKey,
        ]);

        $created = ! $relation->exists;

        $relation->fill([
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status'     => EcosystemRelation::STATUS_ACTIVE,
            'created_by' => $this->adminId(),
        ])->save();

        $this->logger->log(
            'admin',
            $this->adminId(),
            $created ? 'ecosystem.relation.create' : 'ecosystem.relation.reactivate',
            $sourceType,
            $sourceId,
            [
                'target_type' => (string) $data['target_type'],
                'target_key'  => $targetKey,
            ],
        );

        return redirect()->route($routeName, $sourceId)
            ->with('success', $created ? '关联已添加。' : '关联已重新启用。');
    }

    private function destroy(string $sourceType, int $sourceId, int $relationId, string $routeName): RedirectResponse
    {
        $relation = EcosystemRelation::query()
            ->whereKey($relationId)
            ->where('source_type', $sourceType)
            ->where('source_key', (string) $sourceId)
            ->firstOrFail();

        $payload = [
            'target_type' => (string) $relation->target_type,
            'target_key'  => (string) $relation->target_key,
        ];

        $relation->delete();

        $this->logger->log(
            'admin',
            $this->adminId(),
            'ecosystem.relation.delete',
            $sourceType,
            $sourceId,
            $payload,
        );

        return redirect()->route($routeName, $sourceId)->with('success', '关联已删除。');
    }

    private function rows(string $sourceType, int $sourceId)
    {
        return EcosystemRelation::query()
            ->where('source_type', $sourceType)
            ->where('source_key', (string) $sourceId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '关联维护路由未登记权限：'.$routeName);

        abort_unless(bouncer()->hasPermission($permission), 403, '无权维护生态关联。');
    }

    private function adminId(): int
    {
        return (int) auth()->guard('admin')->user()->id;
    }
}
