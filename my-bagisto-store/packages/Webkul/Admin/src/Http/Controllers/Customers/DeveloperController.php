<?php

namespace Webkul\Admin\Http\Controllers\Customers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\DataGrids\Customers\DeveloperProfileDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\LifecycleNotifier;
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
     * 实名**按层**通过（S-12c）—— `layer` = 企业层 or 操作人层。
     *
     * 与上面 `approveRealname`（直接置总状态）**不是一回事**：这里只推进**一层**，
     * 再由 `recomputeRealnameStatus()` 合并回总状态（唯一规则在模型里，本处不重写）。
     * 每次动作写**一条** `operation_log`（含 layer）—— 两层动作就是两条痕，
     * 事后能分清"哪一层是谁何时审的"。
     *
     * ⚠ **存量主体护栏（S-12c 修）**：对**存量形态**（总状态 approved、两层皆 NULL ——
     *   S-12a「存量不追溯」留下的历史主体）**只单层通过**，`recomputeRealnameStatus()`
     *   会因"两层不齐 → pending"使其**瞬时降为 pending**，该主体立即**建不了技能、也上不了架** ——
     *   运营点错一下就撤了别人的资格。故此处**拒绝单层通过**，只说清"请用『两层一起通过』补审"。
     *   **只禁单层通过，不禁单层驳回**（驳回是有意的资格撤销，保持 S-12c 行为不变）。
     *   非存量形态（如两层都是 pending 的新主体）单层通过**照旧允许**。
     */
    public function approveRealnameLayer(int $id, string $layer)
    {
        $profile = $this->profileForLayerAction($id, $layer);

        if ($this->isLegacySingleLayerSubject($profile)) {
            session()->flash(
                'error',
                '这是历史已通过的主体（总状态 approved、两层为空）。请用「两层一起通过」补审 —— '
                    .'单独通过一层会使其降为待审核（pending），该主体将立刻失去"创建技能 / 上架"资格。'
            );

            return redirect()->route('admin.customers.developers.show', $id);
        }

        $this->applyLayerDecision($profile, $layer, DeveloperProfile::REALNAME_APPROVED);

        session()->flash('success', $this->layerResultMessage($layer, true, $profile));

        return redirect()->route('admin.customers.developers.show', $id);
    }

    /**
     * 实名**按层**驳回（S-12c）—— **理由必填**。
     *
     * 校验刻意放在**任何状态变更与留痕之前**：`validate()` 抛 ValidationException 时
     * 状态一字未改、`operation_log` 一行未写（R1 的硬要求）。
     *
     * 驳回后**通知开发者**（R4）：复用 `LifecycleNotifier` → `NotificationService::toCustomer`
     * 那条线，收件人是开发者本人的 customer 账号；通知是**副作用**，写不进去也不回滚判定。
     */
    public function rejectRealnameLayer(Request $request, int $id, string $layer)
    {
        $profile = $this->profileForLayerAction($id, $layer);

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->applyLayerDecision($profile, $layer, DeveloperProfile::REALNAME_REJECTED, $data['reason']);

        app(LifecycleNotifier::class)->developerRealnameRejected($profile->fresh(), $layer, $data['reason']);

        session()->flash('success', $this->layerResultMessage($layer, false, $profile->fresh()));

        return redirect()->route('admin.customers.developers.show', $id);
    }

    /**
     * 「两层一起通过」便捷按钮（S-12c）。
     *
     * 运营日常就是一次看两份资料，所以给一个一次点完的入口；但它**绝不是**一条"总通过"痕 ——
     * 内部逐层调用，**两层各写一条** `operation_log`（见 R2/R3，不许为了省事合并成一条）。
     */
    public function approveAllRealnameLayers(int $id)
    {
        $profile = DeveloperProfile::findOrFail($id);

        // 逐层落：每层各自 recompute + 各写一条痕
        foreach ($this->allowedLayers() as $layer) {
            $this->applyLayerDecision($profile, $layer, DeveloperProfile::REALNAME_APPROVED);
        }

        $profile->refresh();

        session()->flash(
            'success',
            '两层已一并通过（企业层 / 操作人层各留一条审核痕）；实名总状态：'.$this->totalLabel($profile->realname_status).'。'
        );

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

    /**
     * 下载开发者的营业执照附件（S-12b）—— 运营**只读**查看用。
     *
     * ⚠ 附件在**私有盘**（`SkillAsset::disk()`），没有公开 URL；只能经本鉴权路由流式下载。
     *   `company_cert_path` **绝不出现在任何买家侧接口**里（本方法只在 Admin 后台）。
     *   文件不存在（历史主体没提交过 / 已被覆盖删掉）→ 如实提示，不 500。
     */
    public function downloadCert(int $id)
    {
        $profile = DeveloperProfile::findOrFail($id);

        $path = $profile->company_cert_path;

        if (! $path || ! Storage::disk(SkillAsset::disk())->exists($path)) {
            session()->flash('error', '营业执照附件不存在（可能未提交，或已被后续提交覆盖）。');

            return redirect()->route('admin.customers.developers.show', $id);
        }

        return Storage::disk(SkillAsset::disk())
            ->download($path, $profile->company_cert_name ?: basename($path));
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

    // ───────────────────────── S-12c · 逐层审批的内部支撑 ─────────────────────────

    /**
     * 是否"存量形态"主体 —— 总状态 approved 且**两层皆 NULL**（S-12a「存量不追溯」的历史主体）。
     *
     * 判据严格照拍板定义：三列同时满足才算。任一列非 NULL 就不是存量形态
     * （例如两层都在 pending 的新主体，或已补审过半层的主体）—— 那些单层通过无副作用，
     * 不该被本护栏误伤。
     *
     * ⚠ 这是**动作层护栏**，不改模型规则：`recomputeRealnameStatus()` 的合并规则一字未动
     *   （两层不齐 → pending 仍成立），本方法只在"谁允许触发它"上加一道闸。
     */
    protected function isLegacySingleLayerSubject(DeveloperProfile $profile): bool
    {
        return $profile->realname_status === DeveloperProfile::REALNAME_APPROVED
            && $profile->company_realname_status === null
            && $profile->operator_realname_status === null;
    }

    /**
     * 合法的实名层 —— **只认模型上的常量**，别处不许写裸字符串 `'company'`/`'operator'`。
     *
     * @return array<int, string>
     */
    protected function allowedLayers(): array
    {
        return [
            DeveloperProfile::REALNAME_LAYER_COMPANY,
            DeveloperProfile::REALNAME_LAYER_OPERATOR,
        ];
    }

    /**
     * 解析并校验 `layer`，拿到要处置的主体。
     *
     * 非法 layer → **明确报错**（422，不改任何东西）；主体不存在 → 404（`findOrFail`）。
     */
    protected function profileForLayerAction(int $id, string $layer): DeveloperProfile
    {
        if (! in_array($layer, $this->allowedLayers(), true)) {
            abort(422, '非法实名层级：'.$layer.'（只接受 company / operator）。');
        }

        return DeveloperProfile::findOrFail($id);
    }

    /**
     * 层 → 列名。写操作只经这里落列，避免字符串散落。
     */
    protected function layerColumn(string $layer): string
    {
        return match ($layer) {
            DeveloperProfile::REALNAME_LAYER_COMPANY  => 'company_realname_status',
            DeveloperProfile::REALNAME_LAYER_OPERATOR => 'operator_realname_status',
        };
    }

    /**
     * 层 → 人话标签（界面、flash、留痕、通知共用一处，避免四处各写一份迟早漂移）。
     */
    protected function layerLabel(string $layer): string
    {
        return match ($layer) {
            DeveloperProfile::REALNAME_LAYER_COMPANY  => '企业层（公司认证）',
            DeveloperProfile::REALNAME_LAYER_OPERATOR => '操作人层（操作人认证）',
        };
    }

    /**
     * 总状态 → 人话标签。
     */
    protected function totalLabel(string $status): string
    {
        return match ($status) {
            DeveloperProfile::REALNAME_APPROVED => '已通过',
            DeveloperProfile::REALNAME_REJECTED => '已驳回',
            DeveloperProfile::REALNAME_PENDING  => '待审核',
            default                             => (string) $status,
        };
    }

    /**
     * 落一层的判定：改该层列 → `recomputeRealnameStatus()` 重算总状态 → 存 → **写一条痕**。
     *
     * 单层动作与「两层一起通过」都走这里：后者调两次 = 两条痕（这是刻意的，不许合并）。
     */
    protected function applyLayerDecision(DeveloperProfile $profile, string $layer, string $status, ?string $reason = null): void
    {
        $profile->{$this->layerColumn($layer)} = $status;

        // R2：每次动作后显式重算总状态（两层都 approved → approved；任一层 rejected → rejected；否则 pending）
        $profile->recomputeRealnameStatus();
        $profile->save();

        $this->log(
            request(),
            $status === DeveloperProfile::REALNAME_APPROVED
                ? 'developer.realname.layer.approve'
                : 'developer.realname.layer.reject',
            $profile->id,
            [
                'layer'           => $layer,
                'layer_label'     => $this->layerLabel($layer),
                'reason'          => $reason,
                'realname_status' => $profile->realname_status,
            ]
        );
    }

    /**
     * 单层动作后的 flash 文案 —— 如实说清后果（R5）：
     * 驳回任一层 → 总状态 rejected → 该主体**既不能建技能、也不能上架**（有意语义，不是提示）。
     */
    protected function layerResultMessage(string $layer, bool $approved, DeveloperProfile $profile): string
    {
        $label = $this->layerLabel($layer);
        $total = $this->totalLabel($profile->realname_status);

        if ($approved) {
            return "「{$label}」已通过；实名总状态：{$total}。";
        }

        return "已驳回「{$label}」；实名总状态：{$total}。"
            .'驳回任一层即失去资格 —— 该主体既不能创建技能、也不能上架（需修正后重新提交）。';
    }
}
