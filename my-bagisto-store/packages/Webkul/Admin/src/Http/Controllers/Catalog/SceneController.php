<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Admin\DataGrids\Catalog\SceneDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\SceneRequest;
use Webkul\Shop\Models\Scene;

/**
 * 场景(Scene)后台管理 —— 照抄分类(Category)CRUD,但简化:
 * 只管理「企业商用(TOB)」场景的名称/描述/场景图/启用状态。
 *
 * 数据表 scenes(迁移在 Webkul\Shop 包),模型 Webkul\Shop\Models\Scene。
 */
class SceneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(SceneDataGrid::class)->process();
        }

        return view('admin::catalog.scenes.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $rentProducts = $this->getRentProducts();

        return view('admin::catalog.scenes.create', compact('rentProducts'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SceneRequest $request)
    {
        Event::dispatch('catalog.scene.create.before');

        $data = $request->validated();
        $scene = Scene::create($this->formData($request));

        $this->uploadBanner($data, $scene);

        Event::dispatch('catalog.scene.create.after', $scene);

        session()->flash('success', trans('admin::app.catalog.scenes.success.create'));

        return redirect()->route('admin.catalog.scenes.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        $scene = Scene::findOrFail($id);

        $rentProducts = $this->getRentProducts();

        return view('admin::catalog.scenes.edit', compact('scene', 'rentProducts'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SceneRequest $request, int $id)
    {
        Event::dispatch('catalog.scene.update.before', $id);

        $data = $request->validated();
        $scene = Scene::findOrFail($id);
        if (config('scenes.publication_v2') && array_key_exists('status', $data) && (bool) $scene->status !== $request->boolean('status')) {
            abort_unless(bouncer()->hasPermission('catalog.scene-workflow.publish'), 403);
            app(\Webkul\Shop\Services\Scenes\ScenePublicationService::class)->availability($id, $request->boolean('status'), (int) auth('admin')->id());
            $scene->refresh();
        }
        $scene->fill($this->formData($request, $scene))->save();

        $this->uploadBanner($data, $scene);

        Event::dispatch('catalog.scene.update.after', $scene);

        session()->flash('success', trans('admin::app.catalog.scenes.success.update'));

        return redirect()->route('admin.catalog.scenes.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $scene = Scene::findOrFail($id);
        if ($this->hasWorkflowHistory([$id])) {
            return new JsonResponse(['code' => 'SCENE_HAS_HISTORY', 'message' => '场景已有版本或需求记录，请撤下场景并保留历史资料。'], 409);
        }

        try {
            Event::dispatch('catalog.scene.delete.before', $id);

            if ($scene->banner_path) {
                Storage::delete($scene->banner_path);
            }

            $scene->delete();

            Event::dispatch('catalog.scene.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.catalog.scenes.success.delete'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => trans('admin::app.catalog.scenes.success.delete-failed'),
            ], 500);
        }
    }

    /**
     * 列表「状态」列一键切换启用/停用（DataGrid 开关，POST + CSRF）。
     */
    public function updateStatus(Request $request, int $id)
    {
        $scene = Scene::findOrFail($id);

        $request->validate(['status' => 'required|boolean']);
        if (config('scenes.publication_v2')) {
            abort_unless(bouncer()->hasPermission('catalog.scene-workflow.publish'), 403);
            $result = app(\Webkul\Shop\Services\Scenes\ScenePublicationService::class)->availability($id, $request->boolean('status'), (int) auth('admin')->id());

            return response()->json(['success' => true, 'status' => $result['status']]);
        }
        $scene->status = $request->boolean('status') ? 1 : 0;

        $scene->save();

        // AJAX 请求（状态列开关）→ 返回 JSON，前端不整页刷新
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status'  => $scene->status,
            ]);
        }

        session()->flash('success', trans('admin::app.catalog.scenes.success.update'));

        return redirect()->back();
    }

    /**
     * 列表「热门」列一键切换（DataGrid 开关，POST + CSRF）。
     */
    public function toggleHot(Request $request, int $id)
    {
        $scene = Scene::findOrFail($id);

        $scene->is_hot = ! $scene->is_hot;

        $scene->save();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'is_hot'  => (bool) $scene->is_hot,
            ]);
        }

        session()->flash('success', trans('admin::app.catalog.scenes.success.update'));

        return redirect()->back();
    }

    /**
     * 列表「咨询人数」列内联编辑（AJAX）。
     */
    public function updateConsultCount(Request $request, int $id)
    {
        $scene = Scene::findOrFail($id);

        $request->validate(['consult_count' => 'required|integer|min:0|max:2147483647']);
        $scene->consult_count = (int) $request->input('consult_count');

        $scene->save();

        if ($request->expectsJson()) {
            return response()->json([
                'success'       => true,
                'consult_count' => $scene->consult_count,
            ]);
        }

        session()->flash('success', trans('admin::app.catalog.scenes.success.update'));

        return redirect()->back();
    }

    /**
     * 批量更新状态（DataGrid 批量操作）。
     */
    public function massUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'indices'   => 'required|array|min:1|max:100',
            'indices.*' => 'required|integer|min:1|distinct|exists:scenes,id',
            'value'     => 'required|in:0,1',
        ]);

        if (config('scenes.publication_v2')) {
            abort_unless(bouncer()->hasPermission('catalog.scene-workflow.publish'), 403);
            sort($data['indices'], SORT_NUMERIC);
            DB::transaction(function () use ($data) {
                foreach ($data['indices'] as $id) {
                    app(\Webkul\Shop\Services\Scenes\ScenePublicationService::class)->availability((int) $id, (bool) $data['value'], (int) auth('admin')->id());
                }
            }, 3);
        } else {
            Scene::whereIn('id', $data['indices'])->update(['status' => (int) $data['value']]);
        }

        return new JsonResponse([
            'message' => trans('admin::app.catalog.scenes.success.update'),
        ], 200);
    }

    /**
     * 批量删除（DataGrid 批量操作）。
     */
    public function massDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'indices'   => 'required|array|min:1|max:100',
            'indices.*' => 'required|integer|min:1|distinct|exists:scenes,id',
        ]);

        if ($this->hasWorkflowHistory($data['indices'])) {
            return new JsonResponse(['code' => 'SCENE_HAS_HISTORY', 'message' => '所选场景包含版本或需求记录，请改用撤下操作。'], 409);
        }

        Scene::whereIn('id', $data['indices'])->get()->each(function ($scene) {
            if ($scene->banner_path) {
                Storage::delete($scene->banner_path);
            }

            $scene->delete();
        });

        return new JsonResponse([
            'message' => trans('admin::app.catalog.scenes.success.delete'),
        ], 200);
    }

    private function hasWorkflowHistory(array $ids): bool
    {
        return (Schema::hasTable('scene_revisions') && DB::table('scene_revisions')->whereIn('scene_id', $ids)->exists())
            || (Schema::hasTable('scene_requests') && DB::table('scene_requests')->whereIn('scene_id', $ids)->exists());
    }

    /** 未提交字段保留原值；空字符串和空机型列表是明确的清空操作。 */
    private function formData(SceneRequest $request, ?Scene $scene = null): array
    {
        $input = $request->validated();
        $data = $scene ? [] : ['category' => 'enterprise', 'status' => false, 'is_hot' => false, 'sort' => 0];
        foreach (['name', 'description', 'category', 'icon', 'bg_color', 'consult_count', 'sort'] as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $input[$key];
            }
        }
        foreach (['consult_count', 'sort'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = (int) $data[$key];
            }
        }
        foreach (['status', 'is_hot'] as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $request->boolean($key);
            }
        }
        if (array_key_exists('tags', $input)) {
            $data['tags'] = $this->parseTags($input['tags']);
        }
        $stats = $scene?->stats ?? [];
        foreach (['amp', 'roi', 'scene'] as $key) {
            if (array_key_exists('stats_'.$key, $input)) {
                $stats[$key] = $input['stats_'.$key];
            }
        }
        if (array_intersect(['stats_amp', 'stats_roi', 'stats_scene'], array_keys($input))) {
            $data['stats'] = $stats;
        }
        foreach (['pains' => ['icon', 'text', 'solve'], 'benefits' => ['icon', 'title', 'desc'], 'scheme_scenes' => ['name', 'desc', 'data']] as $key => $fields) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $this->parseBlocks($input[$key], $fields);
            }
        }
        if (array_key_exists('robots', $input) || $request->boolean('robots_present')) {
            $data['robots'] = $this->normalizeRobotIds($input['robots'] ?? []);
        }

        return $data;
    }

    /**
     * 处理场景图上传。
     *
     * media.images 组件把新上传文件以 banner_path[] 数组提交(而非 banner_path.{id}),
     * 因此这里从 request()->files 里取真实文件数组;已存在图片用隐藏字段 banner_path[oldId]
     * 标记保留。完全没提交 banner_path = 用户删图。
     */
    private function uploadBanner(array $data, Scene $scene)
    {
        $files = request()->files->get('banner_path');
        $hasNewFile = false;

        if (is_array($files)) {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $hasNewFile = true;

                $oldPath = $scene->banner_path;

                // 用 Laravel 原生 storeAs 存原图（保留用户上传格式），
                // 避免 Intervention Image + GD 的 encode/webp 在部分环境/大图内存不足时失败。
                $ext = strtolower($file->guessExtension() ?: 'png');
                $name = Str::random(40).'.'.$ext;
                $dir = 'scene/'.$scene->id;

                if (! $file->storeAs($dir, $name)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['banner_path' => '场景图上传失败，请重试']);
                }

                $scene->banner_path = $dir.'/'.$name;
                $scene->save();
                if ($oldPath && ! $this->hasWorkflowHistory([(int) $scene->id])) {
                    Storage::delete($oldPath);
                }
            }
        }

        // 仅当用户明确勾选「删除当前场景图」时才删旧图（原生 file input 无新文件时不应误删）
        if (! $hasNewFile && request()->boolean('remove_banner')) {
            if ($scene->banner_path && ! $this->hasWorkflowHistory([(int) $scene->id])) {
                Storage::delete($scene->banner_path);
            }

            $scene->banner_path = null;

            $scene->save();
        }
    }

    /**
     * 把 textarea 每行一条的记录解析为对象数组。
     * 每行用 | 分隔字段（如「图标|痛点|解法」），空行跳过；字段数不足补 null。
     * 纯字符串解析 → 数组，无 eval，写入走 Eloquent（防注入）。
     */
    private function parseBlocks(?string $text, array $fields): array
    {
        $result = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            $item = [];

            foreach ($fields as $i => $key) {
                $item[$key] = $parts[$i] ?? null;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * 标签：每行一个标签 → 字符串数组。
     */
    private function parseTags(?string $text): array
    {
        $tags = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $tags[] = $line;
            }
        }

        return $tags;
    }

    /**
     * 方案三项指标（效率提升/平均回本/适配场景）→ {amp, roi, scene}。
     */
    private function parseStats(array $data): array
    {
        return [
            'amp'   => $data['stats_amp'] ?? null,
            'roi'   => $data['stats_roi'] ?? null,
            'scene' => $data['stats_scene'] ?? null,
        ];
    }

    /**
     * 机型推荐：每行一个 id → 数字数组。
     */
    private function parseIdList(?string $text): array
    {
        $ids = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);

            if ($line !== '' && is_numeric($line)) {
                $ids[] = (int) $line;
            }
        }

        return $ids;
    }

    /**
     * 获取「租用」分类下的产品（供场景「适用机器」多选）。
     */
    private function getRentProducts()
    {
        $ids = app(\Webkul\Shop\Services\Scenes\SceneCatalogService::class)
            ->rentalProductIds((int) core()->getCurrentChannel()->id);

        return \Webkul\Product\Models\Product::query()->whereIn('id', $ids)->get();
    }

    /**
     * 规范化 robots：表单 checkbox 提交数组；兼容旧 textarea（每行一个 id）。
     */
    private function normalizeRobotIds($robots): array
    {
        if (is_array($robots)) {
            return array_values(array_filter(array_map('intval', $robots)));
        }

        return $this->parseIdList($robots);
    }
}
