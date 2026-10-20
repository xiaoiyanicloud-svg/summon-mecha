<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Services\Scenes\SceneAcceptanceService;
use Webkul\Shop\Services\Scenes\SceneAssessmentService;
use Webkul\Shop\Services\Scenes\SceneOperationsService;
use Webkul\Shop\Services\Scenes\SceneProjectService;
use Webkul\Shop\Services\Scenes\SceneProposalService;
use Webkul\Shop\Services\Scenes\ScenePublicationService;
use Webkul\Shop\Services\Scenes\SceneRequestService;
use Webkul\Shop\Services\Scenes\SceneReservationService;
use Webkul\Shop\Services\Scenes\SceneSupplyService;
use Webkul\Shop\Services\Scenes\SceneWorkflow;

class SceneWorkflowController extends Controller
{
    public function index(Request $request, SceneOperationsService $ops)
    {
        $requests = DB::table('scene_requests')->orderByDesc('id')->paginate(30);
        $todos = DB::table('scene_operation_todos')->where('state', 'open')->orderBy('id')->limit(100)->get();
        $failures = DB::table('business_outbox')->where('event_type', 'like', 'scene.%')->whereNull('processed_at')->where('attempts', '>', 0)->orderBy('id')->limit(50)->get(['id', 'event_type', 'attempts', 'last_error_code', 'available_at']);
        $scenes = DB::table('scenes')->orderBy('id')->get(['id', 'name', 'status', 'active_revision_id']);
        $revisions = DB::table('scene_revisions')->orderByDesc('id')->limit(100)->get(['id', 'scene_id', 'revision_no', 'state', 'row_version']);
        $prices = DB::table('scene_price_items')->whereNull('revoked_at')->orderByDesc('id')->limit(200)->get();
        $resources = DB::table('scene_resources')->orderByDesc('id')->limit(200)->get();
        $metrics = $ops->metrics();

        return view('admin::catalog.scene-workflow.index', compact('requests', 'todos', 'failures', 'scenes', 'revisions', 'prices', 'resources', 'metrics'));
    }

    public function show(int $id)
    {
        $record = SceneWorkflow::requireRow('scene_requests', $id, false);
        $revision = DB::table('scene_request_revisions')->where('request_id', $id)->where('revision_no', $record->revision_no)->first();
        $facts = SceneWorkflow::unpack($revision->facts);
        $schema = SceneWorkflow::unpack($revision->schema_snapshot);
        $activities = DB::table('scene_activities')->where('request_id', $id)->orderByDesc('id')->limit(100)->get();
        $assessments = DB::table('scene_assessments')->where('request_id', $id)->orderByDesc('id')->get();
        $quotes = DB::table('scene_quotes')->where('request_id', $id)->orderByDesc('version')->get();
        $decisions = DB::table('scene_quote_decisions')->whereIn('quote_id', $quotes->pluck('id'))->orderByDesc('id')->get();
        $prices = DB::table('scene_price_items')->whereNull('revoked_at')->where('valid_until', '>', now())->orderBy('code')->get();
        $resources = DB::table('scene_resources')->where('state', 'ready')->orderBy('id')->get();
        $projects = DB::table('scene_projects')->where('request_id', $id)->get();
        $offlineConfirmations = collect();
        if (bouncer()->hasPermission('catalog.scene-workflow.acceptance_record') || bouncer()->hasPermission('catalog.scene-workflow.acceptance_review')) {
            $offlineConfirmations = DB::table('scene_offline_acceptances')->whereIn('project_id', $projects->pluck('id'))->orderByDesc('id')->get();
            foreach ($offlineConfirmations as $confirmation) {
                $confirmation->attestation = SceneWorkflow::unpack(Crypt::decryptString($confirmation->encrypted_attestation));
            }
            if ($offlineConfirmations->isNotEmpty()) {
                app(\Webkul\Shop\Services\Ops\OperationLogger::class)->logOrFail('admin', $this->actor(), 'scene.offline-acceptance.viewed', 'scene_request', $id, []);
            }
        }
        $tasks = DB::table('scene_project_tasks')->whereIn('project_id', $projects->pluck('id'))->orderBy('id')->get();
        $commercial = DB::table('scene_commercial_records')->whereIn('project_id', $projects->pluck('id'))->orderByDesc('id')->get();
        $tickets = DB::table('scene_service_tickets')->whereIn('project_id', $projects->pluck('id'))->orderByDesc('id')->get();
        $attachments = DB::table('scene_attachments')->where('request_id', $id)->where('state', 'clean')->get(['id', 'mime', 'size']);
        $admins = DB::table('admins')->where('status', 1)->get(['id', 'name']);

        return view('admin::catalog.scene-workflow.show', compact('record', 'revision', 'facts', 'schema', 'activities', 'assessments', 'quotes', 'decisions', 'prices', 'resources', 'projects', 'tasks', 'attachments', 'admins', 'commercial', 'tickets', 'offlineConfirmations'));
    }

    public function contact(int $id)
    {
        return DB::transaction(function () use ($id) {
            SceneWorkflow::requireRow('scene_requests', $id);
            $contact = DB::table('scene_request_contacts')->where('request_id', $id)->first();
            app(\Webkul\Shop\Services\Ops\OperationLogger::class)->logOrFail('admin', $this->actor(), 'scene.contact.viewed', 'scene_request', $id, []);

            return response()->json(['data' => $contact ? SceneWorkflow::unpack(Crypt::decryptString($contact->encrypted_contact)) : null])->header('Cache-Control', 'no-store');
        });
    }

    public function upload(Request $r, int $id, \Webkul\Shop\Services\Scenes\SceneAttachmentService $s)
    {
        $r->validate(['file' => 'required|file|max:8192']);

        return $this->result($r, $s->uploadForAdmin($id, $r->file('file'), $this->actor()));
    }

    public function attachment(int $id, int $attachmentId, \Webkul\Shop\Services\Scenes\SceneAttachmentService $s)
    {
        $file = $s->readForAdmin($id, $attachmentId, $this->actor());

        return response()->file($file['path'], ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function requestAction(Request $r, int $id, string $action, SceneRequestService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->operate($id, $r->integer('row_version'), $action, $r->all(), $this->actor()));
    }

    public function assess(Request $r, int $id, SceneAssessmentService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->assess($id, $r->integer('row_version'), $r->all(), $this->actor(), $this->channel()));
    }

    public function quote(Request $r, int $id, SceneProposalService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->draft($id, $r->integer('row_version'), $r->all(), $this->actor(), $this->channel()));
    }

    public function quoteAction(Request $r, int $id, string $action, SceneProposalService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        $r->validate(['reason' => 'nullable|string|max:2000']);

        return $this->result($r, $s->transition($id, $r->integer('row_version'), $action, $this->actor(), $this->channel(), $r->input('reason')));
    }

    public function publication(Request $r, int $id, string $action, ScenePublicationService $s)
    {
        if ($action === 'draft') {
            $r->merge(['robots' => $r->input('robots') ?: []]);

            return $this->result($r, $s->draft($id, $r->all(), $this->actor()));
        }
        $r->validate(['revision_id' => 'required|integer|min:1', 'row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->transition($id, $r->integer('revision_id'), $r->integer('row_version'), $action, $this->actor()));
    }

    public function supply(Request $r, string $kind, SceneSupplyService $s)
    {
        return $this->result($r, match ($kind) {
            'prices'    => $s->price($r->all(), $this->actor()),
            'evidence'  => $s->evidence($r->all(), $this->actor()),
            'resources' => $s->resource($r->all(), $this->actor()),
        });
    }

    public function hold(Request $r, int $id, SceneReservationService $s)
    {
        return $this->result($r, $s->hold($id, $r->all(), (string) ($r->header('Idempotency-Key') ?: $r->input('idempotency_key')), $this->actor(), $this->channel()));
    }

    public function pause(Request $r, int $id, SceneReservationService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1', 'reason' => 'required|string|max:5000']);

        return $this->result($r, $s->pause($id, $r->integer('row_version'), $r->input('reason'), $this->actor()));
    }

    public function task(Request $r, int $id, int $taskId, SceneProjectService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->completeTask($id, $taskId, $r->integer('row_version'), $r->all(), $this->actor()));
    }

    public function replay(Request $r, int $id, SceneOperationsService $s)
    {
        return $this->result($r, $s->replay($id, $this->actor()));
    }

    public function recordOfflineAcceptance(Request $r, int $id, SceneAcceptanceService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->recordOffline($id, $r->integer('row_version'), (string) ($r->header('Idempotency-Key') ?: $r->input('idempotency_key')), $r->all(), $this->actor()));
    }

    public function reviewOfflineAcceptance(Request $r, int $id, int $confirmationId, SceneAcceptanceService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1', 'confirmation_version' => 'required|integer|min:1']);

        return $this->result($r, $s->reviewOffline($id, $confirmationId, $r->integer('row_version'), $r->integer('confirmation_version'),
            (string) ($r->header('Idempotency-Key') ?: $r->input('idempotency_key')), $r->all(), $this->actor()));
    }

    public function commercial(Request $r, int $id, SceneProjectService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->recordCommercial($id, $r->integer('row_version'), $r->all(), $this->actor()));
    }

    public function reviewCommercial(Request $r, int $id, int $recordId, SceneProjectService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->reviewCommercial($id, $recordId, $r->integer('row_version'), $this->actor()));
    }

    public function returnResources(Request $r, int $id, SceneProjectService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1']);

        return $this->result($r, $s->returnResources($id, $r->integer('row_version'), $r->all(), $this->actor()));
    }

    public function resolveTicket(Request $r, int $id, SceneProjectService $s)
    {
        $r->validate(['row_version' => 'required|integer|min:1', 'resolution' => 'required|string|max:10000']);

        return $this->result($r, $s->resolveTicket($id, $r->integer('row_version'), $r->input('resolution'), $this->actor()));
    }

    private function actor(): int
    {
        return (int) auth('admin')->id();
    }

    private function channel(): int
    {
        return (int) core()->getCurrentChannel()->id;
    }

    private function result(Request $r, array $data)
    {
        if ($r->expectsJson()) {
            return response()->json(['data' => $data]);
        }
        session()->flash('success', '操作已记录，请查看最新状态。');

        return back();
    }
}
