<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Catalog\SkillTagDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\SkillTag;

/**
 * 技能标签后台管理（v3.4 步骤 6 的 Admin CRUD）。
 *
 * 标签是技能市场的筛选维度（动作形态 / 适用场景 / 难度），由
 * `GET /api/skill-catalog/tags?type=` 公开。
 * 在这之前只能靠 `SkillCatalogSeeder` 维护。
 */
class SkillTagController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SkillTagDataGrid::class)->process();
        }

        return view('admin::catalog.skill-tags.index');
    }

    public function create()
    {
        return view('admin::catalog.skill-tags.create', ['types' => SkillTag::TYPE_LABELS]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        // 同名同组重复会让前端出现两个一模一样的筛选项
        if ($this->duplicateExists($data['name'], $data['type'])) {
            return back()->withInput()->withErrors(['name' => '同分组下已存在同名标签。']);
        }

        SkillTag::create([
            'name'       => $data['name'],
            'type'       => $data['type'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('admin.catalog.skill-tags.index')
            ->with('success', '标签已创建。');
    }

    public function edit(int $id)
    {
        return view('admin::catalog.skill-tags.edit', [
            'tag'   => SkillTag::findOrFail($id),
            'types' => SkillTag::TYPE_LABELS,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $tag = SkillTag::findOrFail($id);

        $data = $request->validate($this->rules());

        if ($this->duplicateExists($data['name'], $data['type'], $tag->id)) {
            return back()->withInput()->withErrors(['name' => '同分组下已存在同名标签。']);
        }

        $tag->update([
            'name'       => $data['name'],
            'type'       => $data['type'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('admin.catalog.skill-tags.index')
            ->with('success', '标签已更新。');
    }

    /**
     * 删除标签。
     *
     * 已被技能使用时拒绝 —— `skill_tag_pivot` 里会留下指向不存在标签的行，
     * 前台的标签筛选会因此显示空白项。频繁增删标签本身也是运营信号：
     * 该整理的是标签体系，不是数据。
     */
    public function destroy(int $id)
    {
        $tag = SkillTag::findOrFail($id);

        $used = DB::table('skill_tag_pivot')->where('tag_id', $tag->id)->count();

        if ($used > 0) {
            return response()->json([
                'message' => "该标签已被 {$used} 个技能使用，不能删除。",
            ], 422);
        }

        $tag->delete();

        return response()->json(['message' => '标签已删除。']);
    }

    // ==================== 内部 ====================

    private function rules(): array
    {
        return [
            'name'       => 'required|string|max:64',
            'type'       => 'required|in:'.implode(',', SkillTag::TYPES),
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    private function duplicateExists(string $name, string $type, ?int $ignoreId = null): bool
    {
        return SkillTag::query()
            ->where('name', $name)
            ->where('type', $type)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
