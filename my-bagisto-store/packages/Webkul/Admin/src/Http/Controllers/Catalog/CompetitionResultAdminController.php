<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionResultService;
use Webkul\Shop\Services\Ecosystem\CompetitionScoringService;

/**
 * G3-08 · 成绩录入 / 复核 / 发布后台。
 *
 * 路由权限拆成两把：
 *   · `ecosystem.competition.scoring` —— 录入草稿、提交复核；
 *   · `ecosystem.competition.results` —— 排名、暂定发布、终审、修订。
 * 服务层再校验“同一管理员不能既录分又发布”。
 */
class CompetitionResultAdminController extends Controller
{
    public function __construct(
        private readonly CompetitionResultService $results,
        private readonly CompetitionScoringService $scoring,
    ) {}

    public function index(int $trackId): View
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->with(['edition.series', 'edition.event', 'scope'])->findOrFail($trackId);

        return view('admin::catalog.ecosystem-competition.results', [
            'track'    => $track,
            'contract' => $this->scoring->contract($track),
            'entries'  => CompetitionEntry::query()
                ->with('team')
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionEntry::STATUS_CONFIRMED)
                ->orderBy('id')
                ->get(),
            'results'  => $this->results->adminResults($track),
            'preview'  => $this->results->previewRanking($track),
        ]);
    }

    public function saveDraft(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'entry_id'             => ['required', 'integer', 'min:1'],
            'match_id'             => ['nullable', 'integer', 'min:1'],
            'row_status'           => ['nullable', 'in:none,absent,forfeit,disqualified'],
            'expected_row_version' => ['nullable', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:1000'],
            'values'               => ['nullable', 'array'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);
        $entry = CompetitionEntry::query()->find((int) $request->input('entry_id'));

        if ($track === null || $entry === null) {
            return back()->withInput()->withErrors(['competition' => '赛项或参赛条目不存在。']);
        }

        try {
            $this->results->saveDraft(
                $track,
                $entry,
                $request->filled('match_id') ? (int) $request->input('match_id') : null,
                (array) $request->input('values', []),
                (string) $request->input('row_status', 'none'),
                $request->filled('expected_row_version') ? (int) $request->input('expected_row_version') : null,
                $this->adminId(),
                $request->input('reason') ? (string) $request->input('reason') : null,
            );
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '成绩草稿已保存。');
    }

    public function submit(Request $request, int $resultId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $result = CompetitionResult::query()->find($resultId);

        if ($result === null) {
            return back()->withErrors(['competition' => '成绩记录不存在。']);
        }

        try {
            $this->results->submit($result, (int) $request->input('expected_row_version'), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '成绩已提交复核，之后不可直接修改。');
    }

    public function previewRanking(int $trackId): JsonResponse
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return response()->json(['success' => false, 'message' => '赛项不存在。'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->results->previewRanking($track),
        ]);
    }

    public function applyRanking(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'match_id' => ['nullable', 'integer', 'min:1'],
            'ranking'  => ['required', 'array', 'min:1'],
            'ranking.*.entry_id' => ['required', 'integer', 'min:1'],
            'ranking.*.rank_no'  => ['required', 'integer', 'min:1'],
            'ranking.*.tie_group'=> ['nullable', 'string', 'max:32'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->results->applyRanking(
                $track,
                (array) $request->input('ranking'),
                $this->adminId(),
                $request->filled('match_id') ? (int) $request->input('match_id') : null,
            );
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '名次已写入草稿/复核版本。');
    }

    public function publishProvisional(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->results->publishProvisional(
                $track,
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '成绩已暂定发布；队长可查看并进入申诉窗口。');
    }

    public function finalize(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->results->finalize($track, (int) $request->input('expected_row_version'), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '成绩已终审公布。');
    }

    public function revise(Request $request, int $resultId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $result = CompetitionResult::query()->find($resultId);

        if ($result === null) {
            return back()->withErrors(['competition' => '成绩记录不存在。']);
        }

        try {
            $this->results->revise(
                $result,
                (int) $request->input('expected_row_version'),
                (string) $request->input('reason'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '成绩已进入修订草稿；旧公布版本保留并标记 superseded。');
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '赛务路由未登记权限：'.$routeName);
        abort_unless(bouncer()->hasPermission($permission), 403, '无权执行该赛务操作。');
    }

    private function adminId(): int
    {
        return (int) auth()->guard('admin')->user()->id;
    }
}
