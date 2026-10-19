<?php

namespace Webkul\Admin\DataGrids\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * 赛事 DataGrid 的导出旁路封堵(G0-04)。
 *
 * 共享 Webkul\DataGrid\DataGrid::process() 只要请求里出现 export,就会走导出分支,
 * 与 $exportable 的初始值无关;而导出走的是原始查询结果,会绕过列表层的 PII 遮罩。
 * 因此必须在 parent::process() 之前拒绝,不能只靠 exportable=false 或隐藏按钮。
 */
trait RejectsExportBypass
{
    /**
     * Reject any export/format request before the shared datagrid can build a file.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function process()
    {
        if ($this->hasExportRequest()) {
            return new JsonResponse([
                'message' => '赛事数据不支持导出。',
            ], 403);
        }

        return parent::process();
    }

    /**
     * Whether the current request carries any export-related parameter.
     *
     * 覆盖 export=1/true/0/false、export[]=...、format=csv|xls|xlsx 及其组合:
     * 这里只判断「参数存在」,不判断取值,避免 export=0/false 之类的变形绕过。
     */
    protected function hasExportRequest(): bool
    {
        $input = request()->all();

        return array_key_exists('export', $input)
            || array_key_exists('format', $input);
    }
}
