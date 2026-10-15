<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductProxy;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillImport;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillAudit\ReviewGate;
use Webkul\Shop\Services\SkillImport\SkillImportService;
use Webkul\Shop\Services\SkillSupply\AssetUploadService;

/**
 * 技能包的运营后台 —— 「技能管理」。
 *
 * 背景:技能版本/交付物/安装指引原先只有 API(/api/skill-admin/*),运营点不进去,
 * 必须敲 curl 才能传包。这个控制器把同一套能力搬到后台界面上。
 *
 * 命名与数据表沿用 Webkul\Shop(迁移与模型都在那个包),与 EventController 的做法一致。
 *
 * 与 API 通道的分工:
 *   - 后台界面(本控制器)= 运营手工维护,人少、量小
 *   - API(/api/skill-admin/*)= 开发者/合作方批量上传,CI 可跑
 *   两者写的是同一批表和同一套校验,不存在两套真相。
 *
 * ⚠ 发布有闸门(`publish` 方法),闸门定义在 `Services\SkillAudit\ReviewGate` +
 *   `config/skill_audit.php`。审核判定在「审核台」(`SkillReviewController`)。
 */
class SkillController extends Controller
{
    public function __construct(
        protected ReviewGate $reviewGate,
        protected AssetUploadService $assetUpload,
        protected OperationLogger $oplog,
    ) {}
    /**
     * 技能列表:skill 分类下的全部商品 + 各自的版本情况。
     */
    public function index()
    {
        $categoryId = $this->skillCategoryId();

        $productIds = $categoryId
            ? DB::table('product_categories')->where('category_id', $categoryId)->pluck('product_id')->all()
            : [];

        $products = ProductProxy::modelClass()::query()
            ->whereIn('products.id', $productIds ?: [0])
            ->get();

        // 一次把版本查出来按 product_id 分组,避免逐商品查库
        $versionsByProduct = SkillVersion::query()
            ->whereIn('product_id', $productIds ?: [0])
            ->orderByDesc('id')
            ->get()
            ->groupBy('product_id');

        return view('admin::catalog.skills.index', compact('products', 'versionsByProduct'));
    }

    /**
     * 单个技能商品的版本管理页。
     */
    public function edit(int $productId)
    {
        $product = ProductProxy::modelClass()::find($productId);

        if (! $product) {
            session()->flash('error', '商品不存在。');

            return redirect()->route('admin.catalog.skills.index');
        }

        $versions = SkillVersion::with('assets')
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->get();

        return view('admin::catalog.skills.edit', compact('product', 'versions'));
    }

    /**
     * 新建版本(草稿)。
     */
    public function storeVersion(Request $request, int $productId)
    {
        $data = $request->validate([
            'version'         => 'required|string|max:32',
            'channel'         => 'nullable|string|in:stable,beta',
            'license_mode'    => 'nullable|string|in:per_device,per_order,per_account,unlimited',
            'max_activations' => 'nullable|integer|min:1',
            'valid_days'      => 'nullable|integer|min:1',
            'changelog'       => 'nullable|string',
            'install_guide'   => 'nullable|string',
        ]);

        // (product_id, version) 上有唯一键,重复提交不该报错,提示一下即可
        $exists = SkillVersion::where('product_id', $productId)
            ->where('version', $data['version'])
            ->exists();

        if ($exists) {
            session()->flash('error', "版本 {$data['version']} 已存在。");

            return redirect()->route('admin.catalog.skills.edit', $productId);
        }

        SkillVersion::create([
            'product_id'      => $productId,
            'version'         => $data['version'],
            'channel'         => $data['channel'] ?? 'stable',
            'status'          => SkillVersion::STATUS_DRAFT,
            'license_mode'    => $data['license_mode'] ?? 'per_device',
            'max_activations' => $data['max_activations'] ?? null,
            'valid_days'      => $data['valid_days'] ?? null,
            'changelog'       => $data['changelog'] ?? null,
            'install_guide'   => $data['install_guide'] ?? null,
        ]);

        session()->flash('success', '版本已创建(草稿)。上传交付物后即可发布。');

        return redirect()->route('admin.catalog.skills.edit', $productId);
    }

    /**
     * 保存版本的文案字段(安装指引 / 变更日志 / 授权参数)。
     */
    public function updateVersion(Request $request, int $versionId)
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skills.index');
        }

        $data = $request->validate([
            'license_mode'    => 'nullable|string|in:per_device,per_order,per_account,unlimited',
            'max_activations' => 'nullable|integer|min:1',
            'valid_days'      => 'nullable|integer|min:1',
            'changelog'       => 'nullable|string',
            'install_guide'   => 'nullable|string',
        ]);

        $version->fill([
            'license_mode'    => $data['license_mode'] ?? $version->license_mode,
            'max_activations' => $request->input('max_activations') === '' ? null : ($data['max_activations'] ?? null),
            'valid_days'      => $request->input('valid_days') === '' ? null : ($data['valid_days'] ?? null),
            'changelog'       => $data['changelog'] ?? null,
            'install_guide'   => $data['install_guide'] ?? null,
        ])->save();

        session()->flash('success', '已保存。');

        return redirect()->route('admin.catalog.skills.edit', $version->product_id);
    }

    /**
     * 上传交付物到 private 盘。
     *
     * ⚠ 技能包是付费内容,必须落 private 盘 —— 这是整个技能模块独立于
     *   Bagisto 商品属性体系的原因(file 类型属性落的是 public 盘,会泄露)。
     *
     * ⚠ 落盘逻辑**不在这里内联**，走 `AssetUploadService` —— 与 internal.key 通道、
     *   开发者门户共用同一份实现（sha256 复核 / 文件名清洗 / 私盘落盘 / 旧文件清理）。
     *   这里原先是一份**平行副本**，没有 sha256 复核：同一件事两份实现，
     *   迟早有一份会漂移，而漂移的那份恰好就是安全弱的那份。
     */
    public function storeAsset(Request $request, int $versionId)
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skills.index');
        }

        $data = $request->validate([
            'file'         => 'required|file|max:'.(int) config('skill_delivery.max_asset_kb', 20480),
            'carrier_type' => 'required|string|in:'.implode(',', SkillAsset::CARRIER_TYPES),
            'entrypoint'   => 'nullable|string|max:191',
            // 表单可留空(人工上传没这个习惯)；填了就必须对得上
            'sha256'       => 'nullable|string|size:64',
        ]);

        $result = $this->assetUpload->store($version, [
            'carrier_type' => $data['carrier_type'],
            'entrypoint'   => $data['entrypoint'] ?? null,
            'sha256'       => $data['sha256'] ?? null,
        ], $request->file('file'));

        if (! $result->ok) {
            session()->flash('error', $result->message);

            return redirect()->route('admin.catalog.skills.edit', $version->product_id);
        }

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'skill.asset.store',
            'skill_version',
            $version->id,
            [
                'product_id'   => $version->product_id,
                'carrier_type' => $result->asset->carrier_type,
                'file_name'    => $result->asset->file_name,
                'file_hash'    => $result->asset->file_hash,
            ]
        );

        session()->flash('success', "交付物 {$result->asset->file_name} 已上传。");

        return redirect()->route('admin.catalog.skills.edit', $version->product_id);
    }

    /**
     * 发布版本 —— 只有 published 的版本会被下单锁定。
     *
     * ⚠ **这里是发布闸门生效的地方**。
     *
     * 原先只检查"有没有交付物"就放行 —— 意味着**没过审的技能点一下就能上架**，
     * 而本该拦住它的 `AuditService::isFullyApproved()` 当时全库无人调用。
     * 现在闸门由 `ReviewGate` 按 `config/skill_audit.php` 的策略判定，**没有绕过入口**：
     * 想发布，就把审核做掉。
     */
    public function publish(int $versionId)
    {
        $version = SkillVersion::with('assets')->find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skills.index');
        }

        if ($version->assets->isEmpty()) {
            session()->flash('error', '该版本还没有交付物,不能发布。');

            return redirect()->route('admin.catalog.skills.edit', $version->product_id);
        }

        // 发布闸门 —— 未过审不放行(连平台自营的技能也一样)
        $gate = $this->reviewGate->evaluate($version->product_id, $version->id);

        if (! $gate['ok']) {
            session()->flash('error', '未通过发布闸门，不能发布：'.$gate['message']
                .'　请到「审核台」处理。');

            return redirect()->route('admin.catalog.skill-reviews.show', $version->id);
        }

        // 同一商品同时只保留一个 published,避免下单时锁定到哪个不确定
        DB::transaction(function () use ($version) {
            SkillVersion::where('product_id', $version->product_id)
                ->where('id', '!=', $version->id)
                ->where('status', SkillVersion::STATUS_PUBLISHED)
                ->update(['status' => SkillVersion::STATUS_ARCHIVED]);

            $version->status = SkillVersion::STATUS_PUBLISHED;
            $version->released_at = now();
            $version->save();
        });

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            auth()->guard('admin')->user()->id,
            'skill.version.publish',
            'skill_version',
            $version->id,
            [
                'product_id' => $version->product_id,
                'version'    => $version->version,
                'gate'       => collect($gate['layers'])->map(fn ($s) => $s['result_label'])->all(),
            ]
        );

        session()->flash('success', "版本 {$version->version} 已发布。");

        return redirect()->route('admin.catalog.skills.edit', $version->product_id);
    }

    /**
     * 删除版本(连同它的交付物与磁盘文件)。
     */
    public function deleteVersion(int $versionId)
    {
        $version = SkillVersion::with('assets')->find($versionId);

        if (! $version) {
            session()->flash('error', '版本不存在。');

            return redirect()->route('admin.catalog.skills.index');
        }

        if ($version->status === SkillVersion::STATUS_PUBLISHED) {
            session()->flash('error', '已发布的版本不能直接删除,请先发布另一个版本把它归档。');

            return redirect()->route('admin.catalog.skills.edit', $version->product_id);
        }

        $productId = $version->product_id;

        foreach ($version->assets as $asset) {
            if ($asset->file_path) {
                Storage::disk(SkillAsset::disk())->delete($asset->file_path);
            }
        }

        $version->assets()->delete();
        $version->delete();

        session()->flash('success', '版本已删除。');

        return redirect()->route('admin.catalog.skills.edit', $productId);
    }

    /**
     * 导入台(B-17)—— 他平台 / 动捕技能包导入。
     *
     * 背景:导入器(宇树 UniStore / 通用动捕 BVH·SMPLX)早已实现,注册表
     * `config/skill_importers.php` 也早就绪 —— 但**唯一入口是 internal.key 的
     * `POST /api/skill-supply/import`**,运营得敲 curl 才能用,开发者门户也没有入口。
     * 这个页面把同一条导入能力搬到后台界面上(逻辑仍在 `SkillImportService`,不新造)。
     *
     * ⚠ 只做**平台端自营导入**:按 `CLAUDE.md`,当前阶段是「自营 + 客户需求」两方,
     *   第三方开发者(供给方)目前为 0 —— 不为不存在的供给方建开发者端导入 UI。
     */
    public function importForm()
    {
        // 给个可直接用的样例,省得运营对着空框猜字段(与两个导入器的识别特征一致)
        $exampleUnitree = json_encode([
            'name'             => '演示:宇树迎宾应用',
            'version'          => '1.0.0',
            'base_image'       => 'registry.example.com/g1-reception:1.0',
            'startup_commands' => ['./run.sh'],
            'permissions'      => ['camera', 'audio'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $exampleMotion = json_encode([
            'name'          => '演示:导入舞蹈动作',
            'version'       => '1.0.0',
            'motion_format' => 'bvh',
            'fps'           => 60,
            'risk_level'    => 'low',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $products = $this->skillProducts();
        $imports = SkillImport::query()->orderByDesc('id')->limit(20)->get();

        return view('admin::catalog.skills.import', compact('products', 'imports', 'exampleUnitree', 'exampleMotion'));
    }

    /**
     * 执行导入(平台端)。
     *
     * 走 `SkillImportService`(与 internal.key 通道**同一份实现**):
     * 选导入器 → 中立 manifest → 写 `skill_import` 留痕。
     * 若指定了目标商品,再落一条**草稿版本**承载这份清单(搬运 manifest 的
     * risk_level / delivery_channels / pricing_mode —— 但不自动发布,发布仍走闸门)。
     */
    public function import(\Illuminate\Http\Request $request, SkillImportService $importer)
    {
        $data = $request->validate([
            'metadata'   => ['required', 'string', 'max:65535'],
            'source_ref' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $input = json_decode((string) $data['metadata'], true);

        if (! is_array($input) || $input === []) {
            session()->flash('error', '元数据必须是合法的 JSON 对象(不能是数组或空)。');

            return redirect()->route('admin.catalog.skills.import')->withInput();
        }

        $result = $importer->import($input, null, $data['source_ref'] ?? null);

        if (! $result['ok']) {
            session()->flash('error', $result['message']);

            return redirect()->route('admin.catalog.skills.import')->withInput();
        }

        $manifest = $result['manifest'];
        $createdVersion = null;

        // 可选:把清单落成一条草稿版本(不自动发布 —— 发布有闸门)
        if (! empty($data['product_id'])) {
            $productId = (int) $data['product_id'];

            // 只允许挂到 skill 分类下的商品 —— 否则会给普通商品凭空建技能版本
            if (! $this->skillProducts()->pluck('id')->contains($productId)) {
                session()->flash('error', "商品 #{$productId} 不在「技能」分类下,不能挂技能版本。导入留痕已保留。");

                return redirect()->route('admin.catalog.skills.import')->withInput();
            }

            $versionNo = (string) ($manifest['version'] ?? '1.0.0');

            if (SkillVersion::where('product_id', $productId)->where('version', $versionNo)->exists()) {
                session()->flash('error', "该商品下已有版本 {$versionNo},未重复创建。导入留痕已保留,可改用别的版本号。");

                return redirect()->route('admin.catalog.skills.import')->withInput();
            }

            $createdVersion = SkillVersion::create([
                'product_id'        => $productId,
                'version'           => $versionNo,
                'channel'           => 'stable',
                'status'            => SkillVersion::STATUS_DRAFT,
                'license_mode'      => 'per_device',
                // D3:买断制 —— pricing_mode 表达,不用 license_mode
                'pricing_mode'      => $manifest['pricing_mode'] ?? 'buyout',
                // 导入器可能**自动收紧**通道(如宇树容器只跑宇树),优先用锁定值
                'delivery_channels' => ! empty($result['locked_channels'])
                    ? $result['locked_channels']
                    : ($manifest['delivery_channels'] ?? null),
                'risk_level'        => $manifest['safety']['risk_level'] ?? null,
                'manifest_schema'   => $manifest['schema'] ?? null,
                'imported_meta'     => $manifest,
            ]);

            $this->oplog->log(
                OperationLog::ACTOR_ADMIN,
                auth()->guard('admin')->user()->id,
                'skill.import',
                'skill_version',
                $createdVersion->id,
                [
                    'product_id' => $productId,
                    'version'    => $versionNo,
                    'import_id'  => $result['import_id'],
                    'locked_models'   => $result['locked_models'],
                    'locked_channels' => $result['locked_channels'],
                ],
                $request->ip()
            );
        }

        // 结果预览经 flash 带到页面(重定向 + 一次展示,符合既有的 session flash 模式)
        session()->flash('import_result', [
            'manifest'        => $manifest,
            'warnings'        => $result['warnings'],
            'locked_models'   => $result['locked_models'],
            'locked_channels' => $result['locked_channels'],
            'import_id'       => $result['import_id'],
            'created_version' => $createdVersion?->id,
            'created_product' => $createdVersion?->product_id,
        ]);

        session()->flash('success', $createdVersion
            ? "导入成功,已为商品 #{$createdVersion->product_id} 创建草稿版本 {$createdVersion->version}。"
            : '导入成功,已映射为中立清单(未挂商品,仅留痕)。');

        return redirect()->route('admin.catalog.skills.import');
    }

    /**
     * skill 分类下的商品(导入台的商品下拉)。
     */
    protected function skillProducts()
    {
        $categoryId = $this->skillCategoryId();

        $productIds = $categoryId
            ? DB::table('product_categories')->where('category_id', $categoryId)->pluck('product_id')->all()
            : [];

        return ProductProxy::modelClass()::query()
            ->whereIn('products.id', $productIds ?: [0])
            ->orderBy('id')
            ->get();
    }

    /**
     * skill 分类 id(与 CatalogController 取分类的方式一致)。
     */
    protected function skillCategoryId(): ?int
    {
        return Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'skill'))
            ->value('id');
    }
}
