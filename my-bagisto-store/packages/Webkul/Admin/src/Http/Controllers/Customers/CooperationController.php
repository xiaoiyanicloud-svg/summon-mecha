<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Developer\DeveloperInviteService;
use Webkul\Shop\Services\NotificationService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 生态合作申请管理（Admin 运营台）—— 受理本体厂商、RaaS合伙人、网红达人、合伙人推荐、供应链受邀入驻五类申请。
 */
class CooperationController extends Controller
{
    public function __construct(
        protected OperationLogger $oplog,
        protected NotificationService $notifier,
        protected DeveloperInviteService $invites,
    ) {}

    /**
     * 合作申请列表页（支持按类型、状态、跟进人员、关键词筛选，并内嵌邀请码快捷创建与最近发码列表）。
     */
    public function index(Request $request)
    {
        $coopType       = trim((string) $request->query('coop_type', ''));
        $status         = trim((string) $request->query('status', ''));
        $followupPerson = trim((string) $request->query('followup_person', ''));
        $keyword        = trim((string) $request->query('q', ''));

        $query = CooperationApplication::query()->orderByDesc('id');

        if ($coopType !== '' && in_array($coopType, CooperationApplication::ALLOWED_TYPES, true)) {
            $query->where('coop_type', $coopType);
        }

        if ($status !== '' && in_array($status, CooperationApplication::ALLOWED_STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($followupPerson !== '') {
            $query->where(function ($q) use ($followupPerson) {
                $q->where('followup_person', 'like', "%{$followupPerson}%")
                    ->orWhere('meta->follower_name', 'like', "%{$followupPerson}%");
            });
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('application_no', 'like', "%{$keyword}%")
                    ->orWhere('org_name', 'like', "%{$keyword}%")
                    ->orWhere('contact_name', 'like', "%{$keyword}%")
                    ->orWhere('contact_phone', 'like', "%{$keyword}%")
                    ->orWhere('followup_person', 'like', "%{$keyword}%");
            });
        }

        $applications = $query->paginate(20)->withQueryString();

        $counts = [
            'total'            => CooperationApplication::count(),
            'pending'          => CooperationApplication::where('status', CooperationApplication::STATUS_PENDING)->count(),
            'contacting'       => CooperationApplication::where('status', CooperationApplication::STATUS_CONTACTING)->count(),
            'approved'         => CooperationApplication::where('status', CooperationApplication::STATUS_APPROVED)->count(),
            'rejected'         => CooperationApplication::where('status', CooperationApplication::STATUS_REJECTED)->count(),
            'manufacturer'     => CooperationApplication::where('coop_type', CooperationApplication::TYPE_MANUFACTURER)->count(),
            'raas'             => CooperationApplication::where('coop_type', CooperationApplication::TYPE_RAAS)->count(),
            'influencer'       => CooperationApplication::where('coop_type', CooperationApplication::TYPE_INFLUENCER)->count(),
            'partner_referral' => CooperationApplication::where('coop_type', CooperationApplication::TYPE_PARTNER_REFERRAL)->count(),
            'supplier_invite'  => CooperationApplication::where('coop_type', CooperationApplication::TYPE_SUPPLIER_INVITE)->count(),
        ];

        $recentInvites = DeveloperInvite::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $adminUsers = \Webkul\User\Models\Admin::query()->select(['id', 'name', 'email'])->orderBy('name')->get();

        return view('admin::customers.cooperations.index', compact(
            'applications',
            'counts',
            'coopType',
            'status',
            'followupPerson',
            'keyword',
            'recentInvites',
            'adminUsers'
        ));
    }

    /**
     * 在合作申请运营台直接创建一条供应链/生态邀请码。
     */
    public function createInvite(Request $request)
    {
        $data = $request->validate([
            'custom_code'     => 'nullable|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/|unique:developer_invite,code',
            'note'            => 'nullable|string|max:191',
            'expires_in_days' => 'nullable|integer|in:0,7,30,90',
            'max_uses'        => 'nullable|integer|min:1|max:255',
        ]);

        $adminId   = (int) (auth()->guard('admin')->user()?->id ?? 0) ?: null;
        $days      = isset($data['expires_in_days']) && $data['expires_in_days'] !== '' ? (int) $data['expires_in_days'] : 30;
        $expiresAt = $days > 0 ? now()->addDays($days) : null;
        $maxUses   = max(1, (int) ($data['max_uses'] ?? 1));
        $note      = isset($data['note']) && trim((string) $data['note']) !== ''
            ? trim((string) $data['note'])
            : '合作申请运营台快捷发码';

        $invite = $this->invites->generate($adminId, $note, $maxUses, $expiresAt, $data['custom_code'] ?? null);

        try {
            $masked = substr($invite->code, 0, 2) . str_repeat('*', max(0, strlen($invite->code) - 5)) . substr($invite->code, -3);
            $this->oplog->log(
                OperationLog::ACTOR_ADMIN,
                $adminId,
                'developer.invite.create',
                'developer_invite',
                $invite->id,
                [
                    'code_masked' => $masked,
                    'max_uses'    => $invite->max_uses,
                    'expires_at'  => $invite->expires_at?->toIso8601String(),
                    'note'        => $invite->note,
                ],
                $request->ip()
            );
        } catch (\Throwable) {
            // 审计日志异常不阻断主流程
        }

        session()->flash(
            'success',
            "已成功创建邀请码：{$invite->code}（可用 {$invite->max_uses} 次，备注：{$note}，可直接复制发给供应商或合作方）。"
        );

        return redirect()->route('admin.customers.cooperations.index');
    }

    /**
     * 合作申请详情与审核处置页。
     */
    public function show(int $id)
    {
        $application = CooperationApplication::findOrFail($id);
        $adminUsers  = \Webkul\User\Models\Admin::query()->select(['id', 'name', 'email'])->orderBy('name')->get();

        return view('admin::customers.cooperations.show', compact('application', 'adminUsers'));
    }

    /**
     * 为指定合作申请单单独一键签发/重发专属邀请码。
     */
    public function issueInvite(Request $request, int $id)
    {
        $application = CooperationApplication::findOrFail($id);

        $data = $request->validate([
            'custom_code'         => 'nullable|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/|unique:developer_invite,code',
            'invite_expires_days' => 'nullable|integer|in:0,7,30,90',
            'max_uses'            => 'nullable|integer|min:1|max:255',
            'note_suffix'         => 'nullable|string|max:100',
        ]);

        $adminId = (int) (auth()->guard('admin')->user()?->id ?? 0) ?: null;
        $meta    = is_array($application->meta) ? $application->meta : [];
        $days    = isset($data['invite_expires_days']) && $data['invite_expires_days'] !== '' ? (int) $data['invite_expires_days'] : 30;
        $maxUses = max(1, (int) ($data['max_uses'] ?? 1));

        $invite = $this->generateAndBindInvite(
            $application,
            $meta,
            $adminId,
            $days,
            $request->ip(),
            $data['custom_code'] ?? null,
            $maxUses,
            $data['note_suffix'] ?? null
        );

        $application->update([
            'meta'        => $meta,
            'reviewed_by' => $adminId ?? $application->reviewed_by,
            'reviewed_at' => now(),
        ]);

        if ($application->customer_id) {
            try {
                $typeLabel = CooperationApplication::typeLabel($application->coop_type);
                $this->notifier->toCustomer(
                    (int) $application->customer_id,
                    'cooperation.invite_issued',
                    "「{$typeLabel}」专属邀请码已下发：{$invite->code}",
                    "申请单号 {$application->application_no}（{$application->org_name}）已为您签发专属邀请码【{$invite->code}】，可前往「我的 -> 供应商邀请」复制或直接入驻激活。",
                    [
                        'application_id'     => $application->id,
                        'application_no'     => $application->application_no,
                        'coop_type'          => $application->coop_type,
                        'status'             => $application->status,
                        'issued_invite_code' => $invite->code,
                    ]
                );
            } catch (\Throwable) {
                // 通知失败不回滚
            }
        }

        session()->flash('success', "已为「{$application->org_name}」生成并下发专属邀请码：{$invite->code}。");

        return redirect()->route('admin.customers.cooperations.show', $application->id);
    }

    /**
     * 更新合作申请状态、在跟进人员与商务跟进备注，并向关联客户发送站内通知。
     */
    public function updateStatus(Request $request, int $id)
    {
        $application = CooperationApplication::findOrFail($id);

        $data = $request->validate([
            'status'              => 'required|string|in:' . implode(',', CooperationApplication::ALLOWED_STATUSES),
            'follower_name'       => 'nullable|string|max:60',
            'followup_person'     => 'nullable|string|max:64',
            'follower_contact'    => 'nullable|string|max:80',
            'admin_note'          => 'nullable|string|max:500',
            'issue_invite'        => 'nullable|boolean',
            'invite_expires_days' => 'nullable|integer|in:0,7,30,90',
        ]);

        if ($data['status'] === CooperationApplication::STATUS_REJECTED && empty(trim((string) ($data['admin_note'] ?? '')))) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['admin_note' => '驳回申请时请填写驳回原因或反馈说明。']);
        }

        $oldStatus      = $application->status;
        $adminUser      = auth()->guard('admin')->user();
        $adminId        = $adminUser?->id;
        $meta           = is_array($application->meta) ? $application->meta : [];
        $issuedCode     = null;
        $followupPerson = $application->followup_person;

        if ($request->has('follower_name') || $request->has('followup_person')) {
            $followerName = trim((string) ($data['follower_name'] ?? $data['followup_person'] ?? ''));
            if ($followerName !== '') {
                $meta['follower_name'] = $followerName;
                $followupPerson        = $followerName;
            } else {
                unset($meta['follower_name']);
                $followupPerson = null;
            }
        }

        if ($request->has('follower_contact')) {
            $followerContact = trim((string) ($data['follower_contact'] ?? ''));
            if ($followerContact !== '') {
                $meta['follower_contact'] = $followerContact;
            } else {
                unset($meta['follower_contact']);
            }
        }

        // 运营勾选「同步签发专属供应链/生态邀请码」，或供应链申请审核通过且尚未发码时，自动签发邀请码
        $shouldIssueInvite = $data['status'] !== CooperationApplication::STATUS_REJECTED && (
            $request->boolean('issue_invite')
            || (
                $application->coop_type === CooperationApplication::TYPE_SUPPLIER_INVITE
                && $data['status'] === CooperationApplication::STATUS_APPROVED
                && empty($meta['issued_invite_code'])
            )
        );

        if ($shouldIssueInvite) {
            $days       = (int) ($data['invite_expires_days'] ?? 30);
            $invite     = $this->generateAndBindInvite($application, $meta, (int) ($adminId ?: 0) ?: null, $days, $request->ip());
            $issuedCode = $invite->code;
        }

        $application->update([
            'status'          => $data['status'],
            'followup_person' => $followupPerson,
            'admin_note'      => isset($data['admin_note']) && trim((string) $data['admin_note']) !== ''
                ? trim((string) $data['admin_note'])
                : null,
            'meta'            => $meta,
            'reviewed_by'     => $adminId,
            'reviewed_at'     => now(),
        ]);

        try {
            $this->oplog->log(
                OperationLog::ACTOR_ADMIN,
                $adminId,
                'cooperation.status.update',
                'cooperation_application',
                $application->id,
                [
                    'application_no'     => $application->application_no,
                    'coop_type'          => $application->coop_type,
                    'from_status'        => $oldStatus,
                    'to_status'          => $application->status,
                    'follower_name'      => $meta['follower_name'] ?? null,
                    'follower_contact'   => $meta['follower_contact'] ?? null,
                    'admin_note'         => $application->admin_note,
                    'issued_invite_code' => $issuedCode ? 'issued' : null,
                ],
                $request->ip()
            );
        } catch (\Throwable) {
            // 审计日志异常不阻断主流程
        }

        if ($application->customer_id) {
            try {
                $typeLabel    = CooperationApplication::typeLabel($application->coop_type);
                $statusLabel  = CooperationApplication::statusLabel($application->status);
                $followerInfo = '';
                if (! empty($meta['follower_name'])) {
                    $followerInfo = ' 跟进负责人：' . $meta['follower_name']
                        . (! empty($meta['follower_contact']) ? "（{$meta['follower_contact']}）。" : '。');
                }
                $noteText   = $application->admin_note ? " 商务反馈：{$application->admin_note}" : '';
                $inviteText = $issuedCode ? " 平台已为您签发专属邀请码【{$issuedCode}】，可前往「我的 -> 供应商邀请」复制或直接入驻激活。" : '';
                $this->notifier->toCustomer(
                    (int) $application->customer_id,
                    'cooperation.status_updated',
                    "「{$typeLabel}」合作申请状态更新：{$statusLabel}",
                    trim("申请单号 {$application->application_no}（{$application->org_name}）当前状态已更新为「{$statusLabel}」。{$followerInfo}{$noteText}{$inviteText}"),
                    [
                        'application_id'     => $application->id,
                        'application_no'     => $application->application_no,
                        'coop_type'          => $application->coop_type,
                        'status'             => $application->status,
                        'follower_name'      => $meta['follower_name'] ?? null,
                        'follower_contact'   => $meta['follower_contact'] ?? null,
                        'issued_invite_code' => $issuedCode,
                    ]
                );
            } catch (\Throwable) {
                // 通知失败不回滚
            }
        }

        $flashMsg = '合作申请状态已更新为「' . CooperationApplication::statusLabel($application->status) . '」。';
        if (! empty($meta['follower_name'])) {
            $flashMsg .= " 在跟进人员：{$meta['follower_name']}。";
        }
        if ($issuedCode) {
            $flashMsg .= " 已同步签发专属邀请码：{$issuedCode}。";
        }
        session()->flash('success', $flashMsg);

        return redirect()->route('admin.customers.cooperations.show', $application->id);
    }

    /**
     * 为合作申请生成并绑定受邀入驻邀请码（支持自定义码、可用次数与备注后缀）。
     */
    protected function generateAndBindInvite(
        CooperationApplication $application,
        array &$meta,
        ?int $adminId,
        int $days,
        ?string $ip = null,
        ?string $customCode = null,
        int $maxUses = 1,
        ?string $noteSuffix = null
    ): DeveloperInvite {
        $expiresAt = $days > 0 ? now()->addDays($days) : null;
        $note      = sprintf('供应链/合作申请 %s（%s）定向签发', $application->application_no, mb_substr($application->org_name, 0, 40));
        if ($noteSuffix !== null && trim($noteSuffix) !== '') {
            $note .= ' - ' . trim($noteSuffix);
        }
        $invite    = $this->invites->generate($adminId, $note, $maxUses, $expiresAt, $customCode);

        $meta['issued_invite_code']       = $invite->code;
        $meta['issued_invite_expires_at'] = $invite->expires_at?->format('Y-m-d H:i') ?? '长期有效';

        try {
            $masked = substr($invite->code, 0, 2) . str_repeat('*', max(0, strlen($invite->code) - 5)) . substr($invite->code, -3);
            $this->oplog->log(
                OperationLog::ACTOR_ADMIN,
                $adminId,
                'developer.invite.create',
                'developer_invite',
                $invite->id,
                [
                    'code_masked'    => $masked,
                    'max_uses'       => $invite->max_uses,
                    'expires_at'     => $invite->expires_at?->toIso8601String(),
                    'application_no' => $application->application_no,
                    'org_name'       => $application->org_name,
                ],
                $ip
            );
        } catch (\Throwable) {
            // 审计日志异常不阻断主流程
        }

        return $invite;
    }
}
