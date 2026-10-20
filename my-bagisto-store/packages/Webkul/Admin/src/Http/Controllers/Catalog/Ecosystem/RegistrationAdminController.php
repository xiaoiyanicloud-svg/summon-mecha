<?php

namespace Webkul\Admin\Http\Controllers\Catalog\Ecosystem;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventStaffAssignment;
use Webkul\Shop\Services\Ecosystem\RegistrationCancellationService;
use Webkul\Shop\Services\Ecosystem\RegistrationChangeService;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;
use Webkul\Shop\Services\Ecosystem\RegistrationCredentialService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationExportService;
use Webkul\Shop\Services\Ecosystem\RegistrationReviewService;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;
use Webkul\Shop\Services\Ecosystem\RegistrationStaffScope;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2 报名运营后台（G2-01 / G2-14）。
 *
 * 授权两层：
 *   1. 路由 → 全局 ACL（config/ecosystem_admin.php + acl.php）；
 *   2. 活动人员范围（event_staff_assignments 的 settings / staff 能力）。
 * 超管可越过范围，但所有动作仍写 operation_log。
 */
class RegistrationAdminController extends Controller
{
    public function __construct(
        private readonly RegistrationScopeService $scopes,
        private readonly RegistrationStaffScope $staff,
        private readonly RegistrationReviewService $reviews,
        private readonly RegistrationCancellationService $cancellations,
        private readonly RegistrationChangeService $changes,
        private readonly RegistrationContactVault $vault,
        private readonly RegistrationCredentialService $credentials,
        private readonly RegistrationExportService $exports,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * 活动报名范围配置页。
     */
    public function scope(int $eventId)
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $event = Event::query()->findOrFail($eventId);
        $scope = $this->scopes->findDefault($eventId);

        return view('admin::catalog.ecosystem-publishing.registrations.scope', [
            'event' => $event,
            'scope' => $scope,
            'ruleVersions' => $event->ruleVersions()->orderByDesc('version_no')->get(),
            'lifecycleEnabled' => $this->changes->lifecycleWritesEnabled(),
            'changes' => $event->changes()->orderByDesc('change_version')->limit(20)->get(),
        ]);
    }

    public function storeScope(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $event = Event::query()->findOrFail($eventId);
        $data = $this->validatedScope($request);

        try {
            $this->scopes->createDefault($event, $data, $this->adminId());
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.scope', $eventId)
            ->with('success', '报名范围已创建（draft，未开放）。');
    }

    public function updateScope(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $scope = $this->scopeOrFail($eventId);
        $data = $this->validatedScope($request, true);

        try {
            $this->scopes->update($scope, (int) $data['expected_row_version'], $data, $this->adminId());
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.scope', $eventId)
            ->with('success', '报名范围已更新。');
    }

    public function activateScope(Request $request, int $eventId): RedirectResponse
    {
        return $this->scopeAction($request, $eventId, function (EventRegistrationScope $scope, int $expected) use ($request): void {
            // 激活是政策确认动作：必须由操作人显式勾选。
            $request->validate(['policy_confirmed' => ['accepted']]);
            $this->scopes->activate($scope, $expected, $this->adminId());
        }, '报名范围已开放。');
    }

    public function closeScope(Request $request, int $eventId): RedirectResponse
    {
        return $this->scopeAction($request, $eventId, function (EventRegistrationScope $scope, int $expected) use ($request): void {
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
            $this->scopes->close($scope, $expected, $this->adminId(), (string) ($data['reason'] ?? ''));
        }, '报名范围已关闭。');
    }

    public function reopenScope(Request $request, int $eventId): RedirectResponse
    {
        return $this->scopeAction($request, $eventId, function (EventRegistrationScope $scope, int $expected): void {
            $this->scopes->reopen($scope, $expected, $this->adminId());
        }, '报名范围已恢复开放。');
    }

    public function capacity(Request $request, int $eventId): RedirectResponse
    {
        return $this->scopeAction($request, $eventId, function (EventRegistrationScope $scope, int $expected) use ($request): void {
            $data = $request->validate([
                'capacity'       => ['nullable', 'integer', 'min:0'],
                'confirm_capacity' => ['accepted'],
                'reason'         => ['required', 'string', 'max:500'],
            ]);

            $this->scopes->adjustCapacity(
                $scope,
                $expected,
                array_key_exists('capacity', $data) && $data['capacity'] !== null
                    ? (int) $data['capacity']
                    : null,
                true,
                $this->adminId(),
                (string) $data['reason'],
            );
        }, '容量已调整并重新确认。');
    }

    /**
     * 活动人员范围页。
     */
    public function staff(int $eventId)
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $event = Event::query()->findOrFail($eventId);

        return view('admin::catalog.ecosystem-publishing.registrations.staff', [
            'event' => $event,
            'assignments' => EventStaffAssignment::query()
                ->where('event_id', $eventId)
                ->orderBy('admin_id')
                ->get(),
            'admins' => Admin::query()->orderBy('name')->get(['id', 'name', 'email', 'status']),
            'capabilities' => EventStaffAssignment::CAPABILITIES,
            'isSuperAdmin' => $this->staff->isSuperAdmin($this->admin()),
        ]);
    }

    public function grantStaff(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        // 人员范围只能由超管写；有 settings 能力也不等于能自增范围。
        try {
            $this->staff->assertSuperAdmin($this->admin());
        } catch (RegistrationException $exception) {
            abort($exception->httpStatus(), $exception->getMessage());
        }

        $data = $request->validate([
            'admin_id'      => ['required', 'integer', 'min:1'],
            'capabilities'  => ['required', 'array', 'min:1'],
            'capabilities.*'=> ['string', Rule::in(EventStaffAssignment::CAPABILITIES)],
            'valid_from'    => ['nullable', 'date'],
            'valid_until'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->staff->grant(
                $eventId,
                (int) $data['admin_id'],
                (array) $data['capabilities'],
                isset($data['valid_from']) ? \Illuminate\Support\Carbon::parse($data['valid_from']) : null,
                isset($data['valid_until']) ? \Illuminate\Support\Carbon::parse($data['valid_until']) : null,
                $this->adminId(),
                $data['note'] ?? null,
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.staff', $eventId)
            ->with('success', '活动人员范围已保存。');
    }

    public function revokeStaff(int $eventId, int $adminId): RedirectResponse
    {
        $this->authorizeRoute();

        try {
            $this->staff->assertSuperAdmin($this->admin());
        } catch (RegistrationException $exception) {
            abort($exception->httpStatus(), $exception->getMessage());
        }

        $this->staff->revoke($eventId, $adminId, $this->adminId());

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.staff', $eventId)
            ->with('success', '活动人员范围已撤销。');
    }

    /**
     * 活动报名列表（只含本活动；默认遮罩联系人）。
     */
    public function registrations(Request $request, int $eventId)
    {
        $this->authorizeRoute();
        $this->assertView($eventId);

        $event = Event::query()->findOrFail($eventId);

        $status = (string) $request->query('status', '');
        $allowedStatuses = [
            EventRegistrationAttempt::STATUS_SUBMITTED,
            EventRegistrationAttempt::STATUS_CONFIRMED,
            EventRegistrationAttempt::STATUS_REJECTED,
            EventRegistrationAttempt::STATUS_CANCELED,
            EventRegistrationAttempt::STATUS_CHECKED_IN,
        ];

        if ($status !== '' && ! in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $query = EventRegistrationAttempt::query()
            ->with('registration')
            ->where('event_id', $eventId)
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $attempts = $query->paginate(30)->withQueryString();

        $rows = [];

        foreach ($attempts->items() as $attempt) {
            $registration = $attempt->registration;
            $masked = $this->vault->maskedContact(
                (string) $attempt->contact_name_enc,
                (string) $attempt->contact_phone_enc,
                $attempt->contact_city,
            );

            $rows[] = [
                'attempt'      => $attempt,
                'public_no'    => $registration?->public_no,
                'name'         => $masked['name'],
                'phone'        => $masked['phone'],
                'city'         => $masked['city'],
            ];
        }

        return view('admin::catalog.ecosystem-publishing.registrations.index', [
            'event'        => $event,
            'rows'         => $rows,
            'attempts'     => $attempts,
            'status'       => $status,
            'capabilities' => $this->staff->capabilitiesFor($eventId, $this->admin()),
        ]);
    }

    /**
     * 报名详情。完整联系人只对具备本活动 pii 能力的人解密，并写审计。
     */
    public function showRegistration(int $eventId, int $attemptId)
    {
        $this->authorizeRoute();
        $this->assertView($eventId);

        $event = Event::query()->findOrFail($eventId);
        $attempt = EventRegistrationAttempt::query()
            ->with('registration')
            ->where('event_id', $eventId)
            ->whereKey($attemptId)
            ->firstOrFail();

        $canViewPii = $this->staff->hasCapability($eventId, 'pii', $this->admin());

        $rawName = $this->vault->decryptName((string) $attempt->contact_name_enc);
        $rawPhone = $this->vault->decryptPhone((string) $attempt->contact_phone_enc);

        if ($canViewPii) {
            $this->logger->log(
                'admin',
                $this->adminId(),
                'registration.pii.viewed',
                'event_registration_attempt',
                (int) $attempt->getKey(),
                ['event_id' => $eventId],
                request()->ip(),
            );
        }

        return view('admin::catalog.ecosystem-publishing.registrations.show', [
            'event'       => $event,
            'attempt'     => $attempt,
            'registration'=> $attempt->registration,
            'name'        => $canViewPii ? $rawName : $this->vault->maskName($rawName),
            'phone'       => $canViewPii ? $rawPhone : $this->vault->maskPhone($rawPhone),
            'city'        => (string) ($attempt->contact_city ?? ''),
            'canViewPii'  => $canViewPii,
            'capabilities'=> $this->staff->capabilitiesFor($eventId, $this->admin()),
        ]);
    }

    public function approveRegistration(Request $request, int $eventId, int $attemptId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertReview($eventId);

        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ]);

        $attempt = $this->attemptInEventOrFail($eventId, $attemptId);

        try {
            $this->reviews->approve(
                (int) $attempt->getKey(),
                $this->admin(),
                (int) $data['expected_row_version'],
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.show', [$eventId, $attemptId])
            ->with('success', '报名已批准并占位。');
    }

    public function rejectRegistration(Request $request, int $eventId, int $attemptId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertReview($eventId);

        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason_code'          => ['required', 'string', 'max:50'],
            'note'                 => ['nullable', 'string', 'max:2000'],
        ]);

        $attempt = $this->attemptInEventOrFail($eventId, $attemptId);

        try {
            $this->reviews->reject(
                (int) $attempt->getKey(),
                $this->admin(),
                (string) $data['reason_code'],
                $data['note'] ?? null,
                (int) $data['expected_row_version'],
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.show', [$eventId, $attemptId])
            ->with('success', '报名已拒绝。');
    }

    public function cancelRegistration(Request $request, int $eventId, int $attemptId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertCancel($eventId);

        $data = $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason_code'          => ['required', 'string', 'max:50'],
            'note'                 => ['nullable', 'string', 'max:2000'],
        ]);

        $attempt = $this->attemptInEventOrFail($eventId, $attemptId);

        try {
            $this->cancellations->cancelByAdmin(
                (int) $attempt->getKey(),
                $this->admin(),
                (string) $data['reason_code'],
                $data['note'] ?? null,
                (int) $data['expected_row_version'],
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.show', [$eventId, $attemptId])
            ->with('success', '报名已取消并释放席位（如有）。');
    }

    public function checkinForm(int $eventId)
    {
        $this->authorizeRoute();
        $this->assertCapabilityOrAbort($eventId, 'checkin');

        $event = Event::query()->findOrFail($eventId);

        return view('admin::catalog.ecosystem-publishing.registrations.checkin', [
            'event' => $event,
        ]);
    }

    public function checkin(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertCapabilityOrAbort($eventId, 'checkin');

        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
        ]);

        try {
            $attempt = $this->credentials->checkIn(
                (string) $data['token'],
                $this->admin(),
                'admin_manual',
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.checkin', $eventId)
            ->with('success', sprintf(
                '签到成功：轮次 #%d，状态 %s。',
                (int) $attempt->attempt_no,
                (string) $attempt->status,
            ));
    }

    public function cancelEvent(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $data = $request->validate([
            'summary'      => ['required', 'string', 'max:500'],
            'policy_confirmed' => ['accepted'],
        ]);

        $event = Event::query()->findOrFail($eventId);

        try {
            $this->changes->cancelEvent($event, $this->admin(), (string) $data['summary']);
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.scope', $eventId)
            ->with('success', '活动已取消；有效报名已置为已取消并释放席位。');
    }

    public function recordReschedule(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $data = $request->validate([
            'summary'               => ['required', 'string', 'max:500'],
            'requires_ack'          => ['sometimes', 'boolean'],
            'ack_deadline'          => ['nullable', 'date'],
            'decline_releases_seat' => ['sometimes', 'boolean'],
            'no_response_action'    => ['required', 'in:none,release'],
        ]);

        $event = Event::query()->findOrFail($eventId);
        $revision = $event->published_revision_id !== null
            ? \Webkul\Shop\Models\EventRevision::query()->find($event->published_revision_id)
            : null;

        if ($revision === null) {
            return back()->withInput()->withErrors(['action' => '活动没有已发布版本，不能记录改期。']);
        }

        try {
            $this->changes->recordReschedule(
                $event,
                $this->admin(),
                (string) $data['summary'],
                [
                    'revision_id' => (int) $revision->getKey(),
                    'starts_at'   => $revision->starts_at?->toIso8601String(),
                    'ends_at'     => $revision->ends_at?->toIso8601String(),
                ],
                $request->boolean('requires_ack'),
                isset($data['ack_deadline']) && $data['ack_deadline'] !== null
                    ? \Illuminate\Support\Carbon::parse($data['ack_deadline'])
                    : null,
                $request->boolean('decline_releases_seat'),
                (string) $data['no_response_action'],
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.scope', $eventId)
            ->with('success', '改期变更已记录。');
    }

    public function export(Request $request, int $eventId): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertCapabilityOrAbort($eventId, 'export');

        $data = $request->validate([
            'status'     => ['nullable', 'string', 'max:20'],
            'include_pii'=> ['sometimes', 'boolean'],
        ]);

        $includePii = $request->boolean('include_pii');

        if ($includePii) {
            $this->assertCapabilityOrAbort($eventId, 'pii');
        }

        try {
            $export = $this->exports->create(
                $eventId,
                $this->admin(),
                ['status' => $data['status'] ?? null],
                $includePii,
            );
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.index', $eventId)
            ->with('success', sprintf('导出已生成：%d 行，有效期至 %s。', $export['rows'], $export['expires_at']))
            ->with('export_download_url', route('admin.catalog.ecosystem.registrations.export.download', $export['token']));
    }

    public function downloadExport(string $token)
    {
        $this->authorizeRoute();

        try {
            $file = $this->exports->download($token, $this->admin());
        } catch (RegistrationException $exception) {
            abort($exception->httpStatus(), $exception->getMessage());
        }

        return \Illuminate\Support\Facades\Storage::disk($file['disk'])
            ->download($file['path'], $file['filename']);
    }

    private function attemptInEventOrFail(int $eventId, int $attemptId): EventRegistrationAttempt
    {
        return EventRegistrationAttempt::query()
            ->where('event_id', $eventId)
            ->whereKey($attemptId)
            ->firstOrFail();
    }

    private function scopeAction(Request $request, int $eventId, callable $action, string $message): RedirectResponse
    {
        $this->authorizeRoute();
        $this->assertSettings($eventId);

        $scope = $this->scopeOrFail($eventId);
        $expected = (int) $request->validate([
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ])['expected_row_version'];

        try {
            $action($scope, $expected);
        } catch (RegistrationException $exception) {
            return back()->withInput()->withErrors(['action' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.catalog.ecosystem.registrations.scope', $eventId)
            ->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedScope(Request $request, bool $withVersion = false): array
    {
        $rules = [
            'capacity'               => ['nullable', 'integer', 'min:0'],
            'confirm_capacity'       => ['sometimes', 'boolean'],
            'review_mode'            => ['required', Rule::in(['auto', 'manual'])],
            'registration_opens_at'  => ['nullable', 'date'],
            'registration_closes_at' => ['required', 'date'],
            'review_closes_at'       => ['nullable', 'date'],
            'cancel_policy'          => ['required', Rule::in(['before_close', 'never'])],
            'reapply_policy'         => ['required', Rule::in(['allowed', 'never'])],
            'max_attempts'           => ['nullable', 'integer', 'min:1', 'max:100'],
            'change_ack_required'    => ['sometimes', 'boolean'],
            'decline_releases_seat'  => ['sometimes', 'boolean'],
            'no_response_action'     => ['required', Rule::in(['none', 'release'])],
            'privacy_version'        => ['required', 'string', 'max:50'],
            'privacy_summary'        => ['nullable', 'string', 'max:5000'],
            'rule_version_id'        => ['nullable', 'integer', 'min:1'],
        ];

        if ($withVersion) {
            $rules['expected_row_version'] = ['required', 'integer', 'min:0'];
        }

        $data = $request->validate($rules);

        // 复选框未勾选时浏览器不提交；统一成布尔值，避免把 null 当成“保留旧值”。
        foreach (['confirm_capacity', 'change_ack_required', 'decline_releases_seat'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }

    private function scopeOrFail(int $eventId): EventRegistrationScope
    {
        $scope = $this->scopes->findDefault($eventId);

        if ($scope === null) {
            abort(404, '该活动尚未配置报名范围。');
        }

        return $scope;
    }

    private function assertSettings(int $eventId): void
    {
        $this->assertCapabilityOrAbort($eventId, 'settings');
    }

    private function assertView(int $eventId): void
    {
        // 任一活动能力都可以查看本活动报名；完全没有活动范围的人不行。
        $capabilities = $this->staff->capabilitiesFor($eventId, $this->admin());

        abort_if($capabilities === [], 403, '没有该活动的报名查看授权。');
    }

    private function assertReview(int $eventId): void
    {
        $this->assertCapabilityOrAbort($eventId, 'review');
    }

    private function assertCancel(int $eventId): void
    {
        $this->assertCapabilityOrAbort($eventId, 'cancel');
    }

    private function assertCapabilityOrAbort(int $eventId, string $capability): void
    {
        try {
            $this->staff->assertCapability($eventId, $capability, $this->admin());
        } catch (RegistrationException $exception) {
            // 直接 URL / Ajax 与页面同权拒绝；不把业务异常冒成 500。
            abort($exception->httpStatus(), $exception->getMessage());
        }
    }

    private function admin(): Admin
    {
        return auth()->guard('admin')->user();
    }

    private function adminId(): int
    {
        return (int) $this->admin()->getKey();
    }

    private function authorizeRoute(): void
    {
        $routeName = (string) optional(request()->route())->getName();
        $map = (array) config('ecosystem_admin.route_permissions', []);
        $permission = (string) ($map[$routeName] ?? '');

        abort_if($permission === '', 500, '报名运营路由未登记权限：'.$routeName);

        abort_unless(
            bouncer()->hasPermission($permission),
            403,
            '无权执行该报名运营操作。',
        );
    }
}
