<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Customers\DeveloperInviteDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Developer\DeveloperInviteService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 开发者邀请码运营台（S-11b）。
 *
 * 定位（已拍板）：开发者入驻 = 双通道（自助申请 + 邀请）。邀请码**不是准入闸门**，
 * 它只负责「落地路由 + 归属 + 来源留痕」；准入闸门是「实名 + 协议」。
 * 因此本控制器**只**做三件事：发码、作废、看使用情况 ——
 * **不存在**"持码免审核"的逻辑，也**不允许**后来者往这里加。
 *
 * 运营侧口径：
 *   - UI 只发**一次性**码（`max_uses = 1` 固定），不暴露"多用途"选项；
 *   - 码**明文可见、可复制**（码不是身份凭据，运营要能复制后重发）；
 *   - 但**审计日志不记明文码**，只记掩码（见 `maskCode()`）。
 */
class DeveloperInviteController extends Controller
{
    /**
     * UI 只生成一次性码 —— 这是产品拍板，不是服务层限制。
     * 服务层仍支持多用途（S-11d 的落地链路可能用到），但运营端不暴露。
     */
    protected const ONE_TIME_MAX_USES = 1;

    /**
     * 页面默认有效期（天）。留空 / 选择"不过期"时由 `expiresInDays()` 解析。
     */
    protected const DEFAULT_EXPIRES_DAYS = 7;

    /**
     * 单次生成允许的有效期档位（天）。`0` = 永不过期。
     */
    protected const EXPIRES_DAY_OPTIONS = [0, 7, 30, 90];

    /**
     * 特权写操作所需的 ACL —— 发码 / 作废都必须被它罩住（负责人拍板单独权限位）。
     */
    protected const ACL_KEY = 'customers.developer-invites.manage';

    public function __construct(
        protected DeveloperInviteService $invites,
        protected OperationLogger $oplog
    ) {}

    /**
     * 邀请码列表（DataGrid）。
     *
     * 列表里显示**明文码**：码的作用是"运营复制后发给对方"，
     * 遮起来反而让运营没法用；它不是身份凭据（见类注释）。
     */
    public function index()
    {
        $this->authorizeManage();

        if (request()->ajax()) {
            return datagrid(DeveloperInviteDataGrid::class)->process();
        }

        return view('admin::customers.developer-invites.index');
    }

    /**
     * 生成一条**一次性**邀请码。
     *
     * `created_by` 记当前 admin；`note` 给运营写"发给谁"；有效期见 `EXPIRES_DAY_OPTIONS`。
     * 生成后把**明文码**放进 flash，运营可立刻复制（列表里也一直能看到）。
     */
    public function store(Request $request)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'note'            => ['nullable', 'string', 'max:191'],
            'expires_in_days' => ['nullable', 'integer', 'in:'.implode(',', self::EXPIRES_DAY_OPTIONS)],
        ]);

        $days = $this->expiresInDays($data['expires_in_days'] ?? null);
        $expiresAt = $days > 0 ? now()->addDays($days) : null;

        $invite = $this->invites->generate(
            auth()->guard('admin')->user()->id,
            $data['note'] ?? null,
            self::ONE_TIME_MAX_USES,
            $expiresAt
        );

        // ⚠ 审计只记掩码，不记明文码 —— 日志是长期留存物，码则是可工作凭据。
        $this->log($request, 'developer.invite.create', $invite->id, [
            'code_masked' => $this->maskCode($invite->code),
            'max_uses'    => $invite->max_uses,
            'expires_at'  => $invite->expires_at?->format('Y-m-d H:i:s'),
            'note'        => $invite->note,
        ]);

        session()->flash(
            'success',
            '已生成一次性邀请码：'.$invite->code.'（请复制后发给对方）。'
            .'注意：邀请码不是准入闸门，对方持码仍需完成实名与协议签署。'
        );

        return redirect()->route('admin.customers.developer-invites.index');
    }

    /**
     * 作废一条邀请码。
     *
     * 幂等：已作废的码再点一次 → 只提示"此前已作废"，不重复留痕、不报错。
     * 条件 UPDATE（`status = active`）是为了并发下也不会把同一条码作废两次 / 写两条痕。
     */
    public function revoke(Request $request, int $id)
    {
        $this->authorizeManage();

        $invite = DeveloperInvite::query()->findOrFail($id);

        $affected = DB::table('developer_invite')
            ->where('id', $invite->id)
            ->where('status', DeveloperInvite::STATUS_ACTIVE)
            ->update([
                'status'     => DeveloperInvite::STATUS_REVOKED,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            $message = '该邀请码此前已作废（本次未重复留痕）。';

            session()->flash('success', $message);

            return $this->revokeResponse($request, $message);
        }

        $this->log($request, 'developer.invite.revoke', $invite->id, [
            'code_masked' => $this->maskCode($invite->code),
            'status'      => DeveloperInvite::STATUS_REVOKED,
        ]);

        $message = '邀请码已作废。已领取过的人不受影响；作废只阻止后续继续领取。';

        session()->flash('success', $message);

        return $this->revokeResponse($request, $message);
    }

    /**
     * 作废的响应：列表里的"作废"是 DataGrid 的 POST action（走 axios），
     * 需要 JSON `message`；普通表单请求仍按既有写法 flash + redirect。
     */
    protected function revokeResponse(Request $request, string $message)
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('admin.customers.developer-invites.index');
    }

    /**
     * 有效期档位解析：非法值已在校验层挡掉；这里只把 null 落成默认 7 天。
     * `0` 表示**不过期**（`expires_at = null`）。
     */
    protected function expiresInDays(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_EXPIRES_DAYS;
        }

        return (int) $raw;
    }

    /**
     * 审计用的码掩码：保留首 2 位与末 2 位，中间全部打码。
     *
     * 与 `Shop/dev/realname/form.blade.php` 的证件号掩码口径一致（首尾可见、中间遮蔽）。
     * 目的：日志里能对上是哪一条码（必要时配合 target_id），但**不泄露可用凭据**。
     */
    protected function maskCode(string $code): string
    {
        $length = mb_strlen($code);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($code, 0, 2)
            .str_repeat('*', $length - 4)
            .mb_substr($code, -2);
    }

    /**
     * 特权写操作 / 查看操作统一闸门。
     *
     * 菜单层只是"看不见"，不足以阻止直接拼 URL —— 所以控制器里再挡一次。
     */
    protected function authorizeManage(): void
    {
        abort_unless(
            bouncer()->hasPermission(self::ACL_KEY),
            403,
            '无权管理开发者邀请码。'
        );
    }

    protected function log(Request $request, string $action, int $targetId, array $payload): void
    {
        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            $action,
            'developer_invite',
            $targetId,
            $payload,
            $request->ip()
        );
    }
}
