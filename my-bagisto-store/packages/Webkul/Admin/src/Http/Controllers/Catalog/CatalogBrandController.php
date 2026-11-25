<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\DataGrids\Catalog\CatalogBrandDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CatalogBrand;
use Webkul\Shop\Services\Catalog\CatalogBrandService;

/**
 * 后台「品牌管理」CRUD 控制器。
 *
 * 维护机器人生态品牌档案，并与 Bagisto 商品 `brand` EAV 属性及机型库 `device_model.brand` 对齐。
 */
class CatalogBrandController extends Controller
{
    public function __construct(protected CatalogBrandService $brandService) {}

    public function index()
    {
        if (request()->ajax()) {
            return datagrid(CatalogBrandDataGrid::class)->process();
        }

        return view('admin::catalog.brands.index');
    }

    public function create()
    {
        return view('admin::catalog.brands.create', [
            'deviceBrands' => $this->deviceBrandSuggestions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $logoPath = $this->storeUploadedLogo($request);

        $brand = DB::transaction(function () use ($data, $logoPath) {
            $brand = CatalogBrand::create($this->normalize($data, $logoPath));
            $this->brandService->syncAttributeOption($brand);

            return $brand;
        });

        return redirect()
            ->route('admin.catalog.brands.index')
            ->with('success', "品牌「{$brand->name}」已创建并同步至商品属性。");
    }

    public function edit(int $id)
    {
        $brand = CatalogBrand::findOrFail($id);

        return view('admin::catalog.brands.edit', [
            'brand'        => $brand,
            'deviceBrands' => $this->deviceBrandSuggestions(),
            'refs'         => $this->brandService->referenceCounts($brand),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $brand = CatalogBrand::findOrFail($id);
        $data = $request->validate($this->rules($id));

        $oldLogo = $brand->logo_path;
        $newLogo = $this->storeUploadedLogo($request);
        $removeLogo = (bool) $request->boolean('remove_logo');

        $logoPath = $newLogo ?: ($removeLogo ? null : $oldLogo);

        DB::transaction(function () use ($brand, $data, $logoPath) {
            $brand->update($this->normalize($data, $logoPath));
            $this->brandService->syncAttributeOption($brand);
        });

        if (($newLogo || $removeLogo) && $oldLogo && $oldLogo !== $logoPath) {
            Storage::disk('public')->delete($oldLogo);
        }

        return redirect()
            ->route('admin.catalog.brands.index')
            ->with('success', "品牌「{$brand->name}」已更新。");
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $brand = CatalogBrand::findOrFail($id);
        $brand->status = $request->has('status') ? $request->boolean('status') : ! $brand->status;
        $brand->save();

        return response()->json(['success' => true, 'status' => (bool) $brand->status]);
    }

    public function toggleHot(int $id): JsonResponse
    {
        $brand = CatalogBrand::findOrFail($id);
        $brand->is_hot = ! $brand->is_hot;
        $brand->save();

        return response()->json(['success' => true, 'is_hot' => (bool) $brand->is_hot]);
    }

    public function destroy(int $id): JsonResponse
    {
        $brand = CatalogBrand::findOrFail($id);
        $refs = $this->brandService->referenceCounts($brand);

        if ($refs['products'] > 0 || $refs['models'] > 0) {
            return response()->json([
                'message' => "该品牌已被引用（关联商品 {$refs['products']} 个 / 机型库 {$refs['models']} 条），不可删除；若需下线请改成「停用」。",
            ], 422);
        }

        $logo = $brand->logo_path;

        DB::transaction(function () use ($brand) {
            $this->brandService->removeSyncedAttributeOption($brand);
            $brand->delete();
        });

        if ($logo) {
            Storage::disk('public')->delete($logo);
        }

        return response()->json(['message' => '品牌已删除。']);
    }

    public function syncTaxonomy(Request $request)
    {
        $result = $this->brandService->installRaaSTaxonomy(true);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => $result]);
        }

        return redirect()
            ->route('admin.catalog.brands.index')
            ->with('success', '已完成 租售TOB(tob)->租赁(rent)/购买(buy) 分类与默认品牌/形态属性同步。');
    }

    private function rules(?int $ignoreId = null): array
    {
        $uniqueCode = 'unique:catalog_brands,code'.($ignoreId ? ','.$ignoreId : '');

        return [
            'code'         => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/i', $uniqueCode],
            'name'         => ['required', 'string', 'max:120'],
            'name_en'      => ['nullable', 'string', 'max:120'],
            'aliases'      => ['nullable', 'string', 'max:1000'],
            'device_brand' => ['nullable', 'string', 'max:64'],
            'official_url' => ['nullable', 'url', 'max:255'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'sort_order'   => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_hot'       => ['nullable', 'boolean'],
            'status'       => ['nullable', 'boolean'],
            'logo_file'    => ['nullable', 'image', 'max:2048'],
        ];
    }

    private function normalize(array $data, ?string $logoPath): array
    {
        $aliases = collect(preg_split('/[,\r\n，、]+/', (string) ($data['aliases'] ?? '')))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $deviceBrand = trim((string) ($data['device_brand'] ?? ''));

        return [
            'code'         => strtolower(trim((string) $data['code'])),
            'name'         => trim((string) $data['name']),
            'name_en'      => trim((string) ($data['name_en'] ?? '')) ?: null,
            'aliases'      => $aliases,
            'device_brand' => $deviceBrand !== '' ? strtolower($deviceBrand) : null,
            'logo_path'    => $logoPath,
            'official_url' => trim((string) ($data['official_url'] ?? '')) ?: null,
            'description'  => trim((string) ($data['description'] ?? '')) ?: null,
            'sort_order'   => (int) ($data['sort_order'] ?? 100),
            'is_hot'       => (bool) ($data['is_hot'] ?? false),
            'status'       => array_key_exists('status', $data) ? (bool) $data['status'] : true,
        ];
    }

    private function storeUploadedLogo(Request $request): ?string
    {
        $file = $request->file('logo_file');
        if (! $file || ! $file->isValid()) {
            return null;
        }

        return $file->store('brands', 'public');
    }

    private function deviceBrandSuggestions(): array
    {
        if (! Schema::hasTable('device_model')) {
            return [];
        }

        return DB::table('device_model')
            ->select('brand')
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->all();
    }
}
