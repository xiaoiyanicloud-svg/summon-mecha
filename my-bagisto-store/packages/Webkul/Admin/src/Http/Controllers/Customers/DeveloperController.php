<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Customers\DeveloperProfileDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 开发者主体管理 —— 入驻审核（v3.4 步骤 9 的平台端侧）。
 *
 * 在此之前 `realname_status` **永远停在 pending**：常量 `REALNAME_APPROVED/REJECTED`
 * 定义了但零使用，平台端一个入口都没有。这意味着"开发者是谁"这件事从未被确认过。
 *
 * ⚠ **诚实边界，别在界面上含糊过去**：
 *   当前的入驻表单只收集 `contact_name` / `contact_phone`（见 `Dev\OnboardingController::store`），
 *   没有身份证/营业执照，也没有接 OCR 或工商核验。
 *   所以这里的"通过" = **对已提交信息的人工确认**，**不等于完成了身份核验**。
 *   界面与文档都必须按这个口径说话 —— 把它说成"实名认证通过"是虚假陈述。
 *
 * 要真正做到实名，需要（按依赖顺序）：
 *   ① 隐私政策与个人信息保护告知上线（第 10 步）；
 *   ② 收集字段扩展 + 敏感字段加密存储；
 *   ③ OCR / 工商核验服务商接入（外部依赖）。
 */
class DeveloperController extends Controller
{
    public function __construct(protected OperationLogger $oplog) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(DeveloperProfileDataGrid::class)->process();
        }

        return view('admin::customers.developers.index');
    }

    public function show(int $id)
    {
        $profile = DeveloperProfile::with(['customer', 'seller', 'agreements', 'teamMembers'])->find($id);

        if (! $profile) {
            session()->flash('error', '开发者不存在。');

            return redirect()->route('admin.customers.developers.index');
        }

        // 该开发者已建了多少技能商品（按归属锚点 seller_id 统计）
        $skillCount = DB::table('products')->where('seller_id', $profile->seller_id)->count();

        return view('admin::customers.developers.show', compact('profile', 'skillCount'));
    }

    /**
     * 实名信息审核通过。
     *
     * ⚠ 只是把状态推进到 approved —— 详见类注释里"诚实边界"。
     *   当前这条记录的**证据强度**由 cert_payload 里实际有什么决定，
     *   界面上会把留档内容原样列出来，审核人自己判断。
     */
    public function approveRealname(Request $request, int $id)
    {
        $profile = DeveloperProfile::findOrFail($id);

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

        $this->log($request, 'developer.realname.approve', $id, [
            'note'      => $data['note'] ?? null,
            'cert_keys' => array_keys((array) ($profile->cert_payload ?? [])),
        ]);

        session()->flash('success', '实名信息已标记为通过（人工确认，未接入身份核验服务）。');

        return redirect()->route('admin.customers.developers.show', $id);
    }

    /**
     * 实名信息驳回。
     */
    public function rejectRealname(Request $request, int $id)
    {
        $profile = DeveloperProfile::findOrFail($id);

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $profile->update(['realname_status' => DeveloperProfile::REALNAME_REJECTED]);

        $this->log($request, 'developer.realname.reject', $id, ['reason' => $data['reason']]);

        session()->flash('success', '已驳回。');

        return redirect()->route('admin.customers.developers.show', $id);
    }

    /**
     * 停用 / 恢复开发者账号。
     *
     * 这是"平台能把一个开发者停下来"的手段。停用**不删数据**：
     * 已售出的技能仍要能下载（用户付过钱），停的只是"继续发布"的能力。
     */
    public function toggleStatus(int $id)
    {
        $profile = DeveloperProfile::findOrFail($id);

        $next = $profile->status === DeveloperProfile::STATUS_ACTIVE
            ? DeveloperProfile::STATUS_SUSPENDED
            : DeveloperProfile::STATUS_ACTIVE;

        $profile->update(['status' => $next]);

        $this->log(request(), 'developer.status.toggle', $id, ['status' => $next]);

        session()->flash('success', $next === DeveloperProfile::STATUS_ACTIVE ? '已恢复。' : '已停用。');

        return redirect()->route('admin.customers.developers.show', $id);
    }

    protected function log(Request $request, string $action, int $targetId, array $payload): void
    {
        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            $action,
            'developer_profile',
            $targetId,
            $payload,
            $request->ip()
        );
    }
}
