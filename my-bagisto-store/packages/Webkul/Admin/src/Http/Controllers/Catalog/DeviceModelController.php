<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Catalog\DeviceModelDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\DeviceModel;

/**
 * 机型库后台管理（v3.4 步骤 5 的 Admin CRUD）。
 *
 * 为什么这件事重要：机型库是**兼容性校验的输入** ——
 *   `CompatibilityService` 用「技能声明的通道 ∩ 机型声明的通道」决定"能不能装"。
 *   所以在后台改一个通道勾选，等价于改动"用户能不能买到这个技能"。
 *
 * 在这之前机型只能靠 `DeviceModelSeeder` 或手写 SQL 维护，本控制器补上界面。
 */
class DeviceModelController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(DeviceModelDataGrid::class)->process();
        }

        return view('admin::catalog.device-models.index');
    }

    public function create()
    {
        return view('admin::catalog.device-models.create', [
            'channels' => DeviceModel::COMM_CHANNELS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        // model_code 是唯一键 —— 重复会让校验逻辑认错机型
        if (DeviceModel::where('model_code', $data['model_code'])->exists()) {
            return back()->withInput()->withErrors(['model_code' => '该机型编码已存在。']);
        }

        DeviceModel::create($this->normalize($data));

        return redirect()
            ->route('admin.catalog.device-models.index')
            ->with('success', '机型已创建。');
    }

    public function edit(int $id)
    {
        $model = DeviceModel::findOrFail($id);

        return view('admin::catalog.device-models.edit', [
            'model'    => $model,
            'channels' => DeviceModel::COMM_CHANNELS,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $model = DeviceModel::findOrFail($id);

        $data = $request->validate($this->rules($id));

        $model->update($this->normalize($data));

        return redirect()
            ->route('admin.catalog.device-models.index')
            ->with('success', '机型已更新。');
    }

    /**
     * 删除机型。
     *
     * ⚠ **有引用就不许删**。机型不是可以随手删的字典项：
     *   - `devices.model_code` 按编码引用它（用户已登记的设备）
     *   - `device_binding.model_id` 按主键引用它（设备归属的真相源）
     *   删掉会把这些行指向一个不存在的机型，而 `CompatibilityService` 会
     *   静默降级成"未知机型"，**用户看不出区别但判定结果变了**。
     *   要下架机型请改成「仅展示」，别删。
     */
    public function destroy(int $id)
    {
        $model = DeviceModel::findOrFail($id);

        $deviceCount = DB::table('devices')->where('model_code', $model->model_code)->count();
        $bindingCount = DB::table('device_binding')->where('model_id', $model->id)->count();

        if ($deviceCount > 0 || $bindingCount > 0) {
            return response()->json([
                'message' => "该机型已被引用（设备 {$deviceCount} 台 / 设备绑定 {$bindingCount} 条），不能删除。"
                    .'若只是不想再让人选购该机型，请把分级改成「仅展示」。',
            ], 422);
        }

        $model->delete();

        return response()->json(['message' => '机型已删除。']);
    }

    // ==================== 内部 ====================

    private function rules(?int $ignoreId = null): array
    {
        $unique = 'unique:device_model,model_code'.($ignoreId ? ','.$ignoreId : '');

        return [
            'model_code'         => 'required|string|max:64|'.$unique,
            'model_name'         => 'required|string|max:191',
            'brand'              => 'required|string|max:64',
            'vendor_code'        => 'nullable|string|max:32',
            'degrees_of_freedom' => 'nullable|integer|min:0|max:200',
            'compute_platform'   => 'nullable|string|max:64',
            'comm_channels'      => 'nullable|array',
            'comm_channels.*'    => 'in:'.implode(',', DeviceModel::COMM_CHANNELS),
            'firmware_versions'  => 'nullable|string|max:500',
            'min_agent_version'  => 'nullable|string|max:32',
            'icon'               => 'nullable|string|max:191',
            'status'             => 'required|in:'.DeviceModel::STATUS_ACTIVE.','.DeviceModel::STATUS_DISPLAY_ONLY,
            'sort_order'         => 'nullable|integer|min:0',
        ];
    }

    /**
     * 表单字段 → 入库字段。
     *
     * `firmware_versions` 在表单里是**一行一个版本的文本框**（运营不该手写 JSON），
     * 这里转成数组。空输入存 null 而不是 `[]` —— "未知"与"已知但为空"要能区分，
     * `CompatibilityService` 正是靠 null 才不误判"固件过低"。
     */
    private function normalize(array $data): array
    {
        $versions = collect(preg_split('/[\r\n,]+/', (string) ($data['firmware_versions'] ?? '')))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->values()
            ->all();

        return [
            'brand'              => $data['brand'],
            'model_code'         => $data['model_code'],
            'model_name'         => $data['model_name'],
            'vendor_code'        => $data['vendor_code'] ?? null,
            'degrees_of_freedom' => $data['degrees_of_freedom'] ?? null,
            'compute_platform'   => $data['compute_platform'] ?? null,
            'comm_channels'      => array_values($data['comm_channels'] ?? []),
            'firmware_versions'  => $versions === [] ? null : $versions,
            'min_agent_version'  => $data['min_agent_version'] ?? null,
            'icon'               => $data['icon'] ?? null,
            'status'             => $data['status'],
            'sort_order'         => $data['sort_order'] ?? 0,
        ];
    }
}
