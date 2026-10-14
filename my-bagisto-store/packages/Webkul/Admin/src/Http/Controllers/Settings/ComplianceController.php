<?php

namespace Webkul\Admin\Http\Controllers\Settings;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Settings\ComplianceFilingDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\ComplianceFiling;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 合规备案台账（计划 §4.17）。
 *
 * 在这之前表建了、零代码 —— 平台办过哪些备案、备案号是多少、什么时候年检，
 * 没有任何地方可记。
 *
 * ⚠ **没有删除入口，这是刻意的**：备案记录是"你确实办过这件事"的凭据，
 *   能被抹掉的台账不是台账。作废请改状态为「已作废」并写明原因 ——
 *   作废本身也是一条要留档的事实。
 */
class ComplianceController extends Controller
{
    public function __construct(protected OperationLogger $oplog) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(ComplianceFilingDataGrid::class)->process();
        }

        return view('admin::settings.compliance.index');
    }

    public function create()
    {
        return view('admin::settings.compliance.create', [
            'types' => ComplianceFiling::TYPE_LABELS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $filing = ComplianceFiling::create($this->normalize($data));

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'compliance.filing.create',
            'compliance_filing',
            $filing->id,
            ['type' => $filing->filing_type, 'status' => $filing->status],
            $request->ip()
        );

        return redirect()
            ->route('admin.settings.compliance.index')
            ->with('success', '备案记录已登记。');
    }

    public function edit(int $id)
    {
        $filing = ComplianceFiling::findOrFail($id);

        return view('admin::settings.compliance.edit', [
            'filing' => $filing,
            'types'  => ComplianceFiling::TYPE_LABELS,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $filing = ComplianceFiling::findOrFail($id);

        $data = $request->validate($this->rules($id));

        $before = ['status' => $filing->status, 'filing_no' => $filing->filing_no];

        $filing->update($this->normalize($data));

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'compliance.filing.update',
            'compliance_filing',
            $filing->id,
            [
                'before' => $before,
                'after'  => ['status' => $filing->status, 'filing_no' => $filing->filing_no],
            ],
            $request->ip()
        );

        return redirect()
            ->route('admin.settings.compliance.index')
            ->with('success', '备案记录已更新。');
    }

    // ==================== 内部 ====================

    private function rules(?int $ignoreId = null): array
    {
        return [
            'filing_type'      => 'required|in:'.implode(',', array_keys(ComplianceFiling::TYPE_LABELS)),
            'name'             => 'required|string|max:191',
            // ⚠ 备案号与状态必须自洽：**没有备案号的记录，不许标成"已备案"**。
            //   此前这里是无条件 nullable —— 也就是说一次提交（或一次 PUT）
            //   就能造出一条"已备案但不知道备案号"的记录，而平台的合规看板、
            //   到期提醒、"是否已备案"的判断全都读这个字段。
            //   状态是结论，结论必须先过"号存在"这道门。
            //   「审批中/被驳回/待提交」不带号是正常的（本来就没拿到号），不拦。
            'filing_no'        => 'nullable|string|max:64|required_if:status,'.ComplianceFiling::STATUS_FILED,
            'authority'        => 'nullable|string|max:191',
            'scope'            => 'nullable|string|max:191',
            'filed_at'         => 'nullable|date',
            'expires_at'       => 'nullable|date',
            'annual_review_at' => 'nullable|date',
            'status'           => 'required|in:'.implode(',', array_keys(ComplianceFiling::STATUS_LABELS)),
            'attachments'      => 'nullable|string|max:2000',
        ];
    }

    /**
     * 表单字段 → 入库字段。
     *
     * `attachments` 在表单里是**一行一条的文本框**（运维不该手写 JSON）。
     * 本轮只收文字与链接，不收文件 —— 见下方注释。
     */
    private function normalize(array $data): array
    {
        $attachments = collect(preg_split('/[\r\n]+/', (string) ($data['attachments'] ?? '')))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->values()
            ->all();

        return [
            'filing_type'      => $data['filing_type'],
            'name'             => $data['name'],
            'filing_no'        => $data['filing_no'] ?? null,
            'authority'        => $data['authority'] ?? null,
            'scope'            => $data['scope'] ?? null,
            'filed_at'         => $data['filed_at'] ?? null,
            'expires_at'       => $data['expires_at'] ?? null,
            'annual_review_at' => $data['annual_review_at'] ?? null,
            'status'           => $data['status'],
            // ⚠ 只存文字/链接，**不做附件上传**：备案回执扫描件属于证件类材料，
            //   上线前需要访问控制与加密存储方案（依赖对象存储与等保要求）。
            //   现在开一个无人看守的上传口子，风险大于收益。
            'attachments'      => $attachments === [] ? null : $attachments,
        ];
    }
}
