<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CompetitionEdition;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionMatch;
use Webkul\Shop\Models\CompetitionSeries;
use Webkul\Shop\Models\CompetitionStage;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionScheduleService;
use Webkul\Shop\Services\Ecosystem\CompetitionSeriesService;

/**
 * G3 赛务后台（G3-02 / G3-04 / G3-06）。
 *
 * 每个入口显式授权：路由 → 权限登记在 `config/ecosystem_admin.php`，
 * 与 Bouncer 的通用 ACL 分开，避免“菜单可见即可操作”。
 *
 * 本控制器只做参数校验/授权/编排；状态与锁在领域服务内。
 */
class CompetitionAdminController extends Controller
{
    public function __construct(
        private readonly CompetitionSeriesService $series,
        private readonly CompetitionScheduleService $schedule,
        private readonly \Webkul\Shop\Services\Ecosystem\RegistrationScopeService $registrationScopes,
    ) {}

    public function index(): View
    {
        $this->authorizeRoute();

        return view('admin::catalog.ecosystem-competition.index', [
            'series'   => CompetitionSeries::query()->orderByDesc('id')->limit(50)->get(),
            'editions' => CompetitionEdition::query()->with(['series', 'event'])->orderByDesc('id')->limit(50)->get(),
            'tracks'   => CompetitionTrack::query()->with(['edition.series', 'edition.event', 'scope'])->orderByDesc('id')->limit(100)->get(),
            'events'   => Event::query()->where('kind', 'competition')->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function storeSeries(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'name'          => ['required', 'string', 'min:2', 'max:120'],
            'slug'          => ['required', 'string', 'max:99', 'regex:/^[a-z0-9][a-z0-9-]+$/'],
            'organizer_name'=> ['required', 'string', 'max:120'],
            'description'   => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->series->createSeries($request->only(['name', 'slug', 'organizer_name', 'description']), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '系列已创建（草稿）。');
    }

    public function storeEdition(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'series_id'   => ['required', 'integer', 'min:1'],
            'event_id'    => ['required', 'integer', 'min:1'],
            'edition_no'  => ['required', 'integer', 'min:1'],
            'name'        => ['required', 'string', 'max:120'],
            'timezone'    => ['nullable', 'string', 'max:64'],
            'starts_at'   => ['nullable', 'date'],
            'ends_at'     => ['nullable', 'date'],
        ]);

        $series = CompetitionSeries::query()->find((int) $request->input('series_id'));
        $event = Event::query()->find((int) $request->input('event_id'));

        if ($series === null || $event === null) {
            return back()->withInput()->withErrors(['competition' => '系列或活动不存在。']);
        }

        try {
            $this->series->createEdition($series, $event, $request->only([
                'edition_no', 'name', 'timezone', 'starts_at', 'ends_at',
            ]), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '届次已创建（草稿）。');
    }

    public function storeTrack(Request $request): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'edition_id'             => ['required', 'integer', 'min:1'],
            'track_key'              => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'name'                   => ['required', 'string', 'max:120'],
            'discipline'             => ['nullable', 'string', 'max:64'],
            'summary'                => ['nullable', 'string', 'max:5000'],
            'capacity'               => ['nullable', 'integer', 'min:0'],
            'confirm_capacity'       => ['nullable', 'boolean'],
            'review_mode'            => ['required', 'in:auto,manual'],
            'registration_closes_at' => ['nullable', 'date'],
            'review_closes_at'       => ['nullable', 'date'],
            'privacy_version'        => ['nullable', 'string', 'max:50'],
            'privacy_summary'        => ['nullable', 'string', 'max:2000'],
            'submission_deadline_at' => ['required', 'date'],
            'allow_multi_team'       => ['nullable', 'boolean'],
            'rules_version_id'       => ['nullable', 'integer', 'min:1'],
            'rules_profile'          => ['nullable', 'string'],
        ]);

        $edition = CompetitionEdition::query()->find((int) $request->input('edition_id'));

        if ($edition === null) {
            return back()->withInput()->withErrors(['competition' => '届次不存在。']);
        }

        $profile = $this->decodeProfile($request->input('rules_profile'));

        if ($profile instanceof \Throwable) {
            return back()->withInput()->withErrors(['competition' => '规则 profile 不是合法 JSON。']);
        }

        $scope = [
            'review_mode'            => (string) $request->input('review_mode'),
            'registration_closes_at' => $request->input('registration_closes_at'),
            'review_closes_at'       => $request->input('review_closes_at'),
            'privacy_version'        => $request->input('privacy_version') ?: null,
            'privacy_summary'        => $request->input('privacy_summary') ?: null,
        ];

        if ($request->filled('capacity')) {
            $scope['capacity'] = (int) $request->input('capacity');
            $scope['confirm_capacity'] = (bool) $request->boolean('confirm_capacity');
        }

        try {
            $this->series->createTrack($edition, [
                'track_key'              => (string) $request->input('track_key'),
                'name'                   => (string) $request->input('name'),
                'discipline'             => $request->input('discipline') ?: null,
                'summary'                => $request->input('summary') ?: null,
                'allow_multi_team'       => (bool) $request->boolean('allow_multi_team'),
                'submission_deadline_at' => $request->input('submission_deadline_at'),
                'rules_version_id'       => $request->filled('rules_version_id') ? (int) $request->input('rules_version_id') : null,
                'rules_profile'          => $request->filled('rules_profile') ? $profile : null,
                'confirm_rules'          => false,
                'scope'                  => $scope,
            ], $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项已创建（草稿）。规则确认与激活是独立动作。');
    }

    public function confirmRules(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'rules_version_id'     => ['required', 'integer', 'min:1'],
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'rules_profile'        => ['required', 'string'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        $profile = $this->decodeProfile($request->input('rules_profile'));

        if ($profile instanceof \Throwable) {
            return back()->withErrors(['competition' => '规则 profile 不是合法 JSON。']);
        }

        try {
            $this->series->confirmRules(
                $track,
                (int) $request->input('expected_row_version'),
                $profile,
                (int) $request->input('rules_version_id'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项规则已确认并写入指纹。');
    }

    public function activateTrack(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->series->activate($track, (int) $request->input('expected_row_version'), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项已激活。注意：报名仍需 G2 scope 单独激活。');
    }

    public function closeTrack(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:255'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->series->close(
                $track,
                (int) $request->input('expected_row_version'),
                $this->adminId(),
                (string) $request->input('reason', ''),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项已关闭。');
    }

    /**
     * 开放赛项报名范围（仍在 G2 双闸与活动发布状态之下）。
     */
    public function activateScope(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $track = CompetitionTrack::query()->find($trackId);
        $scope = $track !== null && $track->registration_scope_id !== null
            ? \Webkul\Shop\Models\EventRegistrationScope::query()->find($track->registration_scope_id)
            : null;

        if ($track === null || $scope === null) {
            return back()->withErrors(['competition' => '赛项的报名范围不存在。']);
        }

        try {
            $this->registrationScopes->activate(
                $scope,
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (\Webkul\Shop\Services\Ecosystem\RegistrationException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项报名范围已开放。');
    }

    public function closeScope(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:255'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);
        $scope = $track !== null && $track->registration_scope_id !== null
            ? \Webkul\Shop\Models\EventRegistrationScope::query()->find($track->registration_scope_id)
            : null;

        if ($track === null || $scope === null) {
            return back()->withErrors(['competition' => '赛项的报名范围不存在。']);
        }

        try {
            $this->registrationScopes->close(
                $scope,
                (int) $request->input('expected_row_version'),
                $this->adminId(),
                (string) $request->input('reason', ''),
            );
        } catch (\Webkul\Shop\Services\Ecosystem\RegistrationException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '赛项报名范围已关闭。');
    }

    public function schedule(int $trackId): View
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->with(['edition.series', 'edition.event', 'scope'])->findOrFail($trackId);

        return view('admin::catalog.ecosystem-competition.schedule', [
            'track'   => $track,
            'stages'  => CompetitionStage::query()
                ->where('track_id', $track->getKey())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'matches' => CompetitionMatch::query()
                ->where('track_id', $track->getKey())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(),
            'entries' => CompetitionEntry::query()
                ->with('team')
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionEntry::STATUS_CONFIRMED)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function previewImport(Request $request, int $trackId): JsonResponse
    {
        $this->authorizeRoute();

        $request->validate(['file' => ['required', 'file', 'max:2048']]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return response()->json(['success' => false, 'message' => '赛项不存在。'], 404);
        }

        try {
            return response()->json(['success' => true, 'data' => $this->schedule->preview($track, $request->file('file'))]);
        } catch (CompetitionException $exception) {
            return response()->json([
                'success' => false,
                'code'    => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        }
    }

    public function importSchedule(Request $request, int $trackId): JsonResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'file'         => ['required', 'file', 'max:2048'],
            'preview_hash' => ['required', 'string', 'size:64'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return response()->json(['success' => false, 'message' => '赛项不存在。'], 404);
        }

        try {
            $result = $this->schedule->import(
                $track,
                $request->file('file'),
                (string) $request->input('preview_hash'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return response()->json([
                'success' => false,
                'code'    => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function publishStage(Request $request, int $stageId): JsonResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $stage = CompetitionStage::query()->find($stageId);

        if ($stage === null) {
            return response()->json(['success' => false, 'message' => '阶段不存在。'], 404);
        }

        $track = CompetitionTrack::query()->find($stage->track_id);

        if ($track === null) {
            return response()->json(['success' => false, 'message' => '赛项不存在。'], 404);
        }

        try {
            $stage = $this->schedule->publishStage(
                $track,
                $stage,
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return response()->json([
                'success' => false,
                'code'    => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
            ], $exception->httpStatus());
        }

        return response()->json([
            'success' => true,
            'data'    => ['stage_id' => (int) $stage->getKey(), 'status' => (string) $stage->status],
        ]);
    }

    /**
     * @return array<string, mixed>|\Throwable
     */
    private function decodeProfile(mixed $raw): array|\Throwable
    {
        if ($raw === null || trim((string) $raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $exception;
        }

        return is_array($decoded) ? $decoded : new \RuntimeException('rules_profile 必须是 JSON 对象。');
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '赛务路由未登记权限：'.$routeName);

        abort_unless(
            bouncer()->hasPermission($permission),
            403,
            '无权执行该赛务操作。',
        );
    }

    private function adminId(): int
    {
        return (int) auth()->guard('admin')->user()->id;
    }
}
