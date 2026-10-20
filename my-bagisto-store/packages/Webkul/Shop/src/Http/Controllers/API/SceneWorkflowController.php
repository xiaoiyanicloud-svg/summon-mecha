<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneAttachmentService;
use Webkul\Shop\Services\Scenes\SceneOrderConversionService;
use Webkul\Shop\Services\Scenes\ScenePrincipal;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneProposalService;
use Webkul\Shop\Services\Scenes\SceneRequestService;
use Webkul\Shop\Services\Scenes\SceneSchemaRegistry;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

class SceneWorkflowController extends APIController
{
    public function capabilities()
    {
        return response()->json(['data' => [
            'requests'        => (bool) config('scenes.requests_enabled'), 'quotes' => (bool) config('scenes.quotes_enabled'),
            'projects'        => (bool) config('scenes.project_actions_enabled'), 'publication_v2' => (bool) config('scenes.publication_v2'),
            'attachments'     => is_array(config('scenes.attachment_scan_command')), 'order_conversion' => false,
            'consent_version' => 'scene-contact-v1',
            'consent_text'    => '我同意平台为本次服务评估、方案沟通及交付联系我；现场资料仅供授权服务人员处理。',
        ]]);
    }

    public function access(SceneWorkflow $flow)
    {
        $flow->enabled('requests_enabled');
        $token = bin2hex(random_bytes(32));
        DB::table('scene_guest_capabilities')->insert(['token_hash' => hash('sha256', $token),
            'expires_at'                                            => now('UTC')->addDays((int) config('scenes.guest_days', 30)), 'created_at' => now('UTC')]);

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }

    public function schema(string $category, SceneSchemaRegistry $schemas)
    {
        return response()->json(['data' => $schemas->schema($category)]);
    }

    public function index(Request $request, SceneRequestService $service)
    {
        $request->validate(['before_id' => 'nullable|integer|min:1']);
        $owner = ScenePrincipal::fromRequest($request);
        if (! $owner->customerId) {
            $owner->guestCapability();
        }
        $query = $owner->constrain(DB::table('scene_requests'))->orderByDesc('id');
        if ($request->filled('before_id')) {
            $query->where('id', '<', $request->integer('before_id'));
        }
        $rows = $query->limit(21)->get();
        $hasMore = $rows->count() > 20;
        $rows = $rows->take(20);

        return response()->json(['data' => $rows->map(fn ($r) => $service->summary($r))->values()->all(), 'next_cursor' => $hasMore ? $rows->last()->id : null])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, SceneRequestService $service)
    {
        return response()->json(['success' => true, 'data' => $service->create(ScenePrincipal::fromRequest($request), (string) $request->header('Idempotency-Key'), $request->all())], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id, SceneRequestService $service)
    {
        return response()->json(['data' => $service->detail(ScenePrincipal::fromRequest($request), $id)])->header('Cache-Control', 'no-store');
    }

    public function supplement(Request $request, int $id, SceneRequestService $service)
    {
        $request->validate(['row_version' => 'required|integer|min:1']);

        return response()->json(['data' => $service->supplement(ScenePrincipal::fromRequest($request), $id, $request->integer('row_version'), (string) $request->header('Idempotency-Key'), $request->all())]);
    }

    public function claim(Request $request, int $id, SceneRequestService $service)
    {
        return response()->json(['data' => $service->claim(ScenePrincipal::fromRequest($request), $id)])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, int $id, SceneProposalService $service)
    {
        $request->validate(['terms_hash' => 'required|regex:/^[a-f0-9]{64}$/', 'content_hash' => 'required|regex:/^[a-f0-9]{64}$/']);

        return response()->json(['data' => $service->accept(ScenePrincipal::fromRequest($request), $id,
            (string) $request->header('Idempotency-Key'), $request->input('terms_hash'), $request->input('content_hash'), (int) core()->getCurrentChannel()->id)]);
    }

    public function convert(SceneOrderConversionService $service): never
    {
        $service->convert();
    }

    public function reject(Request $request, int $id, SceneProposalService $service)
    {
        return response()->json(['data' => $service->reject(ScenePrincipal::fromRequest($request), $id,
            (string) $request->header('Idempotency-Key'), $request->all())])->header('Cache-Control', 'no-store');
    }

    public function compare(Request $request, int $id, SceneProposalService $service)
    {
        $request->validate(['left_id' => 'required|integer|min:1|max:999999999999999', 'right_id' => 'required|integer|min:1|max:999999999999999']);

        return response()->json(['data' => $service->compare(ScenePrincipal::fromRequest($request), $id,
            $request->integer('left_id'), $request->integer('right_id'))])->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request, int $id, SceneAttachmentService $service)
    {
        $request->validate(['file' => 'required|file|max:8192']);

        return response()->json(['data' => $service->upload(ScenePrincipal::fromRequest($request), $id, $request->file('file'))], 201);
    }

    public function attachment(Request $request, int $id, int $attachmentId, SceneAttachmentService $service)
    {
        $file = $service->read(ScenePrincipal::fromRequest($request), $id, $attachmentId);

        return response()->file($file['path'], ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function project(Request $request, int $id, SceneProjectService $service)
    {
        return response()->json(['data' => $service->detail(ScenePrincipal::fromRequest($request), $id)])->header('Cache-Control', 'no-store');
    }

    public function projectAction(Request $request, int $id, string $action, SceneProjectService $service)
    {
        $request->validate(['row_version' => 'required|integer|min:1']);
        $owner = ScenePrincipal::fromRequest($request);
        $key = (string) $request->header('Idempotency-Key');
        $result = $action === 'acceptance'
            ? $service->acceptance($owner, $id, $request->integer('row_version'), $key, $request->all())
            : $service->customerAction($owner, $id, $request->integer('row_version'), $key, $action, $request->all());

        return response()->json(['data' => $result]);
    }
}
