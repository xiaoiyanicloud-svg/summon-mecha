<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;

/**
 * G3-11 · 作品展示后台（创建 / 审核 / 发布 / 撤回）。
 *
 * 发布前必须由队长授权具体版本；获奖不会自动上架或下发。
 */
class CompetitionShowcaseAdminController extends Controller
{
    public function __construct(private readonly CompetitionShowcaseService $showcases) {}

    public function index(int $trackId): View
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->with(['edition.series', 'edition.event'])->findOrFail($trackId);

        return view('admin::catalog.ecosystem-competition.showcases', [
            'track'    => $track,
            'showcases'=> $this->showcases->adminList($trackId),
            'entries'  => CompetitionEntry::query()
                ->with('team')
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionEntry::STATUS_CONFIRMED)
                ->orderBy('id')
                ->get(),
            'versions' => CompetitionSubmissionVersion::query()
                ->whereIn('status', [CompetitionSubmissionVersion::STATUS_SUBMITTED])
                ->whereIn('submission_id', \Webkul\Shop\Models\CompetitionSubmission::query()
                    ->where('track_id', $track->getKey())
                    ->select('id'))
                ->orderByDesc('id')
                ->get(),
            'awards'   => CompetitionAward::query()
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionAward::STATUS_PUBLISHED)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function store(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'showcase_type'        => ['required', 'in:award,recap'],
            'title'                => ['required', 'string', 'min:4', 'max:160'],
            'summary'              => ['required', 'string', 'min:10', 'max:2000'],
            'entry_id'             => ['required', 'integer', 'min:1'],
            'submission_version_id'=> ['required', 'integer', 'min:1'],
            'award_id'             => ['nullable', 'integer', 'min:1'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->showcases->createDraft($track, $request->only([
                'showcase_type', 'title', 'summary', 'entry_id', 'submission_version_id', 'award_id',
            ]), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '展示草稿已创建；需队长授权后才能审核/发布。');
    }

    public function approve(Request $request, int $showcaseId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $showcase = CompetitionShowcase::query()->find($showcaseId);

        if ($showcase === null) {
            return back()->withErrors(['competition' => '展示不存在。']);
        }

        try {
            $this->showcases->approve($showcase, (int) $request->input('expected_row_version'), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '展示已审核通过。');
    }

    public function reject(Request $request, int $showcaseId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $showcase = CompetitionShowcase::query()->find($showcaseId);

        if ($showcase === null) {
            return back()->withErrors(['competition' => '展示不存在。']);
        }

        try {
            $this->showcases->reject(
                $showcase,
                (string) $request->input('reason'),
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '展示已驳回。');
    }

    public function publish(Request $request, int $showcaseId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate(['expected_row_version' => ['required', 'integer', 'min:0']]);

        $showcase = CompetitionShowcase::query()->find($showcaseId);

        if ($showcase === null) {
            return back()->withErrors(['competition' => '展示不存在。']);
        }

        try {
            $this->showcases->publish($showcase, (int) $request->input('expected_row_version'), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '展示已发布。获奖不会自动上架/发 license/下发真机。');
    }

    public function withdraw(Request $request, int $showcaseId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:2000'],
        ]);

        $showcase = CompetitionShowcase::query()->find($showcaseId);

        if ($showcase === null) {
            return back()->withErrors(['competition' => '展示不存在。']);
        }

        try {
            $this->showcases->withdraw(
                $showcase,
                (string) $request->input('reason', ''),
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '展示已撤回。');
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
