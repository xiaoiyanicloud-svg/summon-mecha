<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Catalog\SkillCategoryDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\SkillCategory;

/**
 * 技能类目后台管理（v3.4 步骤 6 的 Admin CRUD）。
 *
 * ⚠ 这套类目**不是** Bagisto 的商品分类（`categories` 表）。
 *   两套并存且各管一摊，别混：
 *     - Bagisto `categories` —— 商品归属，"技能"是一个商品分类（技能商品挂它下面）
 *     - `skill_category`   —— **技能市场的品类**（展演/接待/互动/定制/生产力），
 *                             前端按它做筛选，公开接口 `GET /api/skill-catalog/categories`
 *
 * 在这之前只能靠 `SkillCatalogSeeder` 维护。
 */
class SkillCategoryController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SkillCategoryDataGrid::class)->process();
        }

        return view('admin::catalog.skill-categories.index');
    }

    public function create()
    {
        return view('admin::catalog.skill-categories.create', [
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        SkillCategory::create([
            'parent_id'  => $data['parent_id'] ?? null,
            'code'       => $data['code'],
            'name'       => $this->namePayload($data),
            'sort_order' => $data['sort_order'] ?? 0,
            'status'     => $data['status'],
        ]);

        return redirect()
            ->route('admin.catalog.skill-categories.index')
            ->with('success', '类目已创建。');
    }

    public function edit(int $id)
    {
        $category = SkillCategory::findOrFail($id);

        return view('admin::catalog.skill-categories.edit', [
            'category' => $category,
            'parents'  => $this->parentOptions($id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $category = SkillCategory::findOrFail($id);

        $data = $request->validate($this->rules($id));

        $category->update([
            'parent_id'  => $data['parent_id'] ?? null,
            'code'       => $data['code'],
            'name'       => $this->namePayload($data),
            'sort_order' => $data['sort_order'] ?? 0,
            'status'     => $data['status'],
        ]);

        return redirect()
            ->route('admin.catalog.skill-categories.index')
            ->with('success', '类目已更新。');
    }

    /**
     * 删除类目。
     *
     * 有子类目时拒绝 —— 父级没了子级就成了孤儿，它们不会再出现在任何层级列表里，
     * 但会继续出现在公开的分类接口中（查的是 `status=active`，不查父子完整性）。
     */
    public function destroy(int $id)
    {
        $category = SkillCategory::findOrFail($id);

        $children = SkillCategory::where('parent_id', $category->id)->count();

        if ($children > 0) {
            return response()->json([
                'message' => "该类目下还有 {$children} 个子类目，不能删除。请先处理子类目（改挂到别的父级或删除）。",
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => '类目已删除。']);
    }

    // ==================== 内部 ====================

    private function rules(?int $ignoreId = null): array
    {
        $unique = 'unique:skill_category,code'.($ignoreId ? ','.$ignoreId : '');

        return [
            'code'       => 'required|string|max:64|'.$unique,
            'name_zh'    => 'required|string|max:64',
            'name_en'    => 'nullable|string|max:64',
            'parent_id'  => 'nullable|integer|exists:skill_category,id',
            'sort_order' => 'nullable|integer|min:0',
            'status'     => 'required|in:active,inactive',
        ];
    }

    /**
     * 表单的两栏名称 → JSON（表里 `name` 是多语言 JSON）。
     */
    private function namePayload(array $data): array
    {
        $name = ['zh_CN' => $data['name_zh']];

        if (! empty($data['name_en'])) {
            $name['en'] = $data['name_en'];
        }

        return $name;
    }

    /**
     * 父级候选。编辑时排除自己 —— 允许把自己设成自己的父级会造出环。
     */
    private function parentOptions(?int $excludeId = null)
    {
        return SkillCategory::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('sort_order')
            ->get();
    }
}
