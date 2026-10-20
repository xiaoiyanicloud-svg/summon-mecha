<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAppealService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;

/**
 * G3-09 · 申诉受理/调查/裁决后台。
 *
 * 裁决必须写理由与适用成绩版本；申诉证据只对具裁决权限的管理员开放。
 */
class CompetitionAppealAdminController extends Controller
{
    public function __construct(private readonly CompetitionAppealService $appeals) {}

    public function index(int $trackId): View
    {
        $this->authorizeRoute();

        $track = CompetitionTrack::query()->with(['edition.series', 'edition.event'])->findOrFail($trackId);

        return view('admin::catalog.ecosystem-competition.appeals', [
            'track'   => $track,
            'appeals' => $this->appeals->adminList($trackId),
        ]);
    }

    public function accept(Request $request, int $appealId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'note'                 => ['nullable', 'string', 'max:2000'],
        ]);

        $appeal = CompetitionAppeal::query()->find($appealId);

        if ($appeal === null) {
            return back()->withErrors(['competition' => '申诉不存在。']);
        }

        try {
            $this->appeals->accept(
                $appeal,
                $this->adminId(),
                (int) $request->input('expected_row_version'),
                (string) $request->input('note', ''),
            );
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '申诉已受理。');
    }

    public function decide(Request $request, int $appealId): RedirectResponse
    {
        $this->authorizeRoute();

        $request->validate([
            'expected_row_version'           => ['required', 'integer', 'min:0'],
            'decision_result'                => ['required', 'in:upheld,partially_upheld,rejected,inadmissible'],
            'decision_reason'                => ['required', 'string', 'min:4', 'max:4000'],
            'decision_result_version_id'     => ['required', 'integer', 'min:1'],
        ]);

        $appeal = CompetitionAppeal::query()->find($appealId);

        if ($appeal === null) {
            return back()->withErrors(['competition' => '申诉不存在。']);
        }

        try {
            $this->appeals->decide($appeal, $this->adminId(), (int) $request->input('expected_row_version'), [
                'decision_result'            => (string) $request->input('decision_result'),
                'decision_reason'            => (string) $request->input('decision_reason'),
                'decision_result_version_id' => (int) $request->input('decision_result_version_id'),
            ]);
        } catch (CompetitionException $exception) {
            return back()->withErrors(['competition' => $exception->getMessage()]);
        }

        return back()->with('success', '申诉已裁决，理由与适用版本已记录。');
    }

    public function downloadAttachment(int $attachmentId): StreamedResponse|JsonResponse
    {
        $this->authorizeRoute();

        try {
            $file = $this->appeals->openEvidenceForAdmin($attachmentId);
        } catch (CompetitionException $exception) {
            return response()->json([
                'success' => false,
                'code'    => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        }

        $name = rawurlencode((string) $file['name']);

        return response()->stream(static function () use ($file): void {
            fpassthru($file['stream']);

            if (is_resource($file['stream'])) {
                fclose($file['stream']);
            }
        }, 200, [
            'Content-Type'           => 'application/octet-stream',
            'Content-Length'         => (string) $file['size'],
            'Content-Disposition'    => "attachment; filename*=UTF-8''".$name,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
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
