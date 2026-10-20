<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CompetitionAward;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAwardService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;

/**
 * G3-10 · 奖项后台：版本创建 / 发布 / 撤销。
 */
class CompetitionAwardAdminController extends Controller
{
    public function __construct(private readonly CompetitionAwardService $awards) {}

    public function index(int $trackId): View
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->with(['edition.series', 'edition.event'])->findOrFail($trackId);

        return view('admin::catalog.ecosystem-competition.awards', [
            'track'  => $track,
            'awards' => $this->awards->adminAwards($track),
            'entries'=> CompetitionEntry::query()
                ->with('team')
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionEntry::STATUS_CONFIRMED)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function storeVersion(Request $request, int $trackId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'award_key'            => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name'                 => ['required', 'string', 'max:120'],
            'entry_id'             => ['required', 'integer', 'min:1'],
            'sort_order'           => ['nullable', 'integer', 'min:0'],
            'expected_row_version' => ['nullable', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:2000'],
        ]);

        $track = CompetitionTrack::query()->find($trackId);

        if ($track === null) {
            return back()->withErrors(['competition' => '赛项不存在。']);
        }

        try {
            $this->awards->createVersion($track, $request->only([
                'award_key', 'name', 'entry_id', 'sort_order', 'reason', 'expected_row_version',
            ]), $this->adminId());
        } catch (CompetitionException $exception) {
            return back()->withInput()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '奖项草稿版本已创建。发布前请确认引用的是当前终审成绩。');
    }

    public function publish(Request $request, int $awardId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'version_id'           => ['required', 'integer', 'min:1'],
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ]);

        $award = CompetitionAward::query()->find($awardId);

        if ($award === null) {
            return back()->withErrors(['competition' => '奖项不存在。']);
        }

        try {
            $this->awards->publish(
                $award,
                (int) $request->input('version_id'),
                (int) $request->input('expected_row_version'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '奖项版本已发布。');
    }

    public function revoke(Request $request, int $awardId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $award = CompetitionAward::query()->find($awardId);

        if ($award === null) {
            return back()->withErrors(['competition' => '奖项不存在。']);
        }

        try {
            $this->awards->revoke(
                $award,
                (int) $request->input('expected_row_version'),
                (string) $request->input('reason'),
                $this->adminId(),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '奖项已撤销；历史版本保留。');
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
