<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Webkul\Admin\DataGrids\Catalog\ContentSecurityDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 内容安全处置台（v3.4 计划 §4.16）。
 *
 * 在此之前：扫描结果**根本没落库**（`content_security_record` 全仓零写入），
 * 也就谈不上"处置" —— 内容安全只有机器判定那半截。
 * 现在扫描会留痕（见 `ContentScanRecorder`），这里是人的那半截。
 *
 * 一条设计原则：
 *   **推翻机器判定（block → 放行）必须写理由**，而按判定处置不强制。
 *   放行是把风险收回来自己扛，留痕是唯一能事后解释的凭据。
 */
class ContentSecurityController extends Controller
{
    public function __construct(protected OperationLogger $oplog) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(ContentSecurityDataGrid::class)->process();
        }

        return view('admin::catalog.content-security.index');
    }

    public function show(int $id)
    {
        $record = ContentSecurityRecord::find($id);

        if (! $record) {
            session()->flash('error', '记录不存在。');

            return redirect()->route('admin.catalog.content-security.index');
        }

        // target_type=skill_meta 时 target_id 是 skill_versions.id（见模型注释）
        $version = $record->target_type === ContentSecurityRecord::TARGET_SKILL_META
            ? SkillVersion::with('product')->find($record->target_id)
            : null;

        return view('admin::catalog.content-security.show', compact('record', 'version'));
    }

    /**
     * 人工处置。
     *
     * ⚠ 处置**不自动改变技能的上架状态**。想下架请去「审核台」走驳回/改状态 ——
     *   两个动作的理由不同：一个是"这条内容违规"，一个是"这个版本不该卖"。
     *   自动联动看起来省事，但会让"为什么下架"变得查不清。
     */
    public function dispose(Request $request, int $id)
    {
        $record = ContentSecurityRecord::findOrFail($id);

        $data = $request->validate([
            'disposition' => 'required|in:cleared,actioned',
            'note'        => 'nullable|string|max:1000',
        ]);

        // 推翻机器拦截 = 把风险收回来自己扛 —— 必须留下书面理由
        if ($data['disposition'] === ContentSecurityRecord::DISPOSITION_CLEARED
            && empty($data['note'])
        ) {
            session()->flash('error', '放行（推翻机器拦截）必须写明理由 —— 这是事后唯一能解释的依据。');

            return redirect()->route('admin.catalog.content-security.show', $id);
        }

        $adminId = auth()->guard('admin')->user()->id;

        $record->update([
            'disposition'      => $data['disposition'],
            'disposition_note' => $data['note'] ?? null,
            'disposition_by'   => $adminId,
            'disposition_at'   => now(),
        ]);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $adminId,
            'content_security.dispose',
            'content_security_record',
            $record->id,
            [
                'disposition' => $record->disposition,
                'machine'     => $record->result,
                'note'        => $data['note'] ?? null,
            ],
            $request->ip()
        );

        session()->flash('success', '已记录处置结果。');

        return redirect()->route('admin.catalog.content-security.show', $id);
    }
}
