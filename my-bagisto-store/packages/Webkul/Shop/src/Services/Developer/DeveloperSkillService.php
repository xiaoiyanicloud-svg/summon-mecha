<?php

namespace Webkul\Shop\Services\Developer;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;
use Webkul\Shop\Services\SkillPackage\PackageInspector;
use Webkul\Shop\Services\SkillSupply\AssetUploadResult;
use Webkul\Shop\Services\SkillSupply\AssetUploadService;

/**
 * 开发者侧技能操作 —— v3.4 步骤 9。
 *
 * ⚠ **归属（ownership）是本类存在的首要理由**：
 *   每个读写都必须先把"这条数据是不是这个开发者的"在**查询层**过滤掉，
 *   而不是查出来再判。跨租户一律返回 null（控制器映射成 404），
 *   不用 403 —— 403/404 的差异可以被用来枚举他人资源 id。
 *
 * ⚠ 归属锚点 `seller_id` 一律取自**已鉴权的 profile**，绝不从请求体取。
 *   既有 `SkillAdminController::resolveSellerId()` 从客户端读 `seller_code` ——
 *   那是 internal.key 通道（单一平台密钥信任整条通道）的设计，开发者通道**不得复用**。
 *
 * 隔离机制：平台自营的 `seller_id` 为 NULL，开发者恒为非 NULL，
 * 等值判定天然让两条通道不相交。
 */
class DeveloperSkillService
{
    public function __construct(
        protected ProductRepository $products,
        protected AssetUploadService $uploads,
        protected PackageInspector $inspector,
        protected AuditService $audit,
        protected SkillVersionState $states,
    ) {}

    /**
     * 开发者的技能挂在这个分类下（categories 表 slug='skill'）。
     */
    protected const SKILL_CATEGORY_SLUG = 'skill';

    // ==================== 归属解析（安全唯一入口） ====================

    /**
     * 取"我的某个技能"。不属于我 → null。
     */
    public function findOwnedSkill(DeveloperProfile $profile, int $productId)
    {
        if (! $profile->seller_id) {
            // ⚠ 必须显式挡：`where('seller_id', null)` 在 Laravel 里会退化成 `is null`，
            //   那就正好匹配到**平台自营**商品 —— 是越权，不是空结果。
            return null;
        }

        return ProductProxy::modelClass()::query()
            ->where('id', $productId)
            ->where('seller_id', $profile->seller_id)
            ->first();
    }

    /**
     * 取"我的某个版本"。不属于我 → null。
     */
    public function findOwnedVersion(DeveloperProfile $profile, int $versionId): ?SkillVersion
    {
        if (! $profile->seller_id) {
            return null;
        }

        return SkillVersion::query()
            ->where('id', $versionId)
            ->where('seller_id', $profile->seller_id)
            ->first();
    }

    /**
     * 我的技能列表（带名字/价格，供门户列表页用）。
     *
     * 直接 join product_flat 取展示字段，避免逐个商品解析属性造成 N+1。
     */
    public function listSkills(DeveloperProfile $profile): Collection
    {
        if (! $profile->seller_id) {
            return collect();
        }

        return DB::table('products')
            ->join('product_flat', function ($join) {
                $join->on('product_flat.product_id', '=', 'products.id')
                    ->where('product_flat.channel', core()->getDefaultChannelCode())
                    ->where('product_flat.locale', $this->defaultLocaleCode());
            })
            ->where('products.seller_id', $profile->seller_id)
            ->select(
                'products.id',
                'products.sku',
                'products.created_at',
                'product_flat.name',
                'product_flat.price',
                'product_flat.status'
            )
            ->orderByDesc('products.id')
            ->get();
    }

    /**
     * 单个技能的展示摘要（带 name/price）—— 详情页与 API 共用。
     *
     * 同样在**查询层**带 seller 过滤；不属于我 → null。
     * 不直接用 `$product->name`：Product 的 `getAttribute` 被重写过（走 EAV），
     * 逐个解析属性既慢又容易拿到 null，直接 join flat 更稳。
     */
    public function skillSummary(DeveloperProfile $profile, int $productId): ?object
    {
        if (! $profile->seller_id) {
            return null;
        }

        return DB::table('products')
            ->join('product_flat', function ($join) {
                $join->on('product_flat.product_id', '=', 'products.id')
                    ->where('product_flat.channel', core()->getDefaultChannelCode())
                    ->where('product_flat.locale', $this->defaultLocaleCode());
            })
            ->where('products.id', $productId)
            ->where('products.seller_id', $profile->seller_id)
            ->select(
                'products.id',
                'products.sku',
                'product_flat.name',
                'product_flat.price',
                'product_flat.status',
                // 编辑表单要回显这两项 —— 一并在这条"带归属过滤"的查询里取，
                // 免得控制器为了拿简介/详情再裸查一次 flat（那会绕开 seller 过滤）。
                'product_flat.short_description',
                'product_flat.description'
            )
            ->first();
    }

    /**
     * 我的版本的审核记录（含驳回理由 —— 计划 C 组"驳回理由开发者可见"）。
     */
    public function listAudits(DeveloperProfile $profile): Collection
    {
        if (! $profile->seller_id) {
            return collect();
        }

        $productIds = ProductProxy::modelClass()::query()
            ->where('seller_id', $profile->seller_id)
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return collect();
        }

        return AuditRecord::query()
            ->whereIn('skill_id', $productIds)
            ->orderByDesc('id')
            ->get();
    }

    // ==================== 写操作 ====================

    /**
     * 建技能（= 建一个 Bagisto 商品，归属到我的 seller）。
     *
     * 走框架自己的 ProductRepository：EAV 属性值 / product_flat / inventory
     * 这些由它负责，不手搓（手搓必出半截商品）。
     */
    public function createSkill(DeveloperProfile $profile, array $data)
    {
        if (! $profile->seller_id) {
            return null;
        }

        $sku = $this->generateSku();

        // ⚠ 必须派发 Bagisto 的建品事件：product_flat 行是 `Product@afterCreate`
        //   → `flatIndexer->refresh()` 生成的。不派发 = 建出一个"残品"：
        //   列表查不到、详情页空白（本步的探针正是这样抓出来的）。
        Event::dispatch('catalog.product.create.before');

        $product = $this->products->create([
            'type'                => 'simple',
            'attribute_family_id' => $this->defaultFamilyId(),
            'sku'                 => $sku,
        ]);

        // 归属锚点：从已鉴权的 profile 取
        $product->seller_id = $profile->seller_id;
        $product->save();

        Event::dispatch('catalog.product.create.after', $product);

        // 属性值（name/price/...）走 update，再由 update 事件把 flat 刷新一次
        Event::dispatch('catalog.product.update.before', $product->id);

        $this->products->update([
            'name'                 => $data['name'],
            'url_key'              => $data['url_key'] ?? (Str::slug($data['name']) ?: $sku),
            'short_description'    => $data['short_description'] ?? '',
            'description'          => $data['description'] ?? '',
            'price'                => $data['price'] ?? 0,
            // 新建即草稿：上架由审核通过后决定，开发者不能自己上架
            'status'               => 0,
            'visible_individually' => 1,
            'channel'              => core()->getDefaultChannelCode(),
            'locale'               => $this->defaultLocaleCode(),
            'categories'           => $this->skillCategoryIds(),
        ], $product->id);

        Event::dispatch('catalog.product.update.after', $product->fresh());

        return $product->fresh();
    }

    /**
     * 修改我自己的技能的基础信息（名称 / 简介 / 详情 / 价格）。不属于我 → null。
     *
     * ⚠ 可改字段是**白名单**：`status`（上下架）、`seller_id`（归属）、`sku` 一个都不在
     *   payload 里。改价是开发者对自己商品的运营动作；改归属、改 SKU、上架是**平台动作**，
     *   绝不能让开发者顺着一个表单夹带进来。（请求体里带了也会被 validate 丢掉，双保险。）
     *
     * ⚠ 事件三步与 createSkill 一致，一步都不能省：product_flat 是
     *   `catalog.product.update.after` 里 flatIndexer 刷出来的，不派发就只改了 EAV，
     *   列表/详情读 flat 仍是旧值 —— 本项目踩过这个坑。
     *
     * `categories` 也要显式带上：AbstractType::update 在 `categories` 缺失时会 sync 成空数组，
     * 漏传会把技能从「技能」分类里摘掉（不是报错，是静默丢数据）。
     */
    public function updateSkill(DeveloperProfile $profile, int $productId, array $data): ?object
    {
        // 归属过滤在查询层，不在这里判
        $product = $this->findOwnedSkill($profile, $productId);

        if (! $product) {
            return null;
        }

        // ⚠ 有意不重算 `url_key`：改名是"把填错的字改对"，不该顺带把商品 URL（slug）换掉 ——
        //   已发布的技能一旦换 URL，外部链接就断了。缺省即保留原值。
        Event::dispatch('catalog.product.update.before', $productId);

        $this->products->update([
            'name'              => $data['name'],
            'short_description' => $data['short_description'] ?? '',
            'description'       => $data['description'] ?? '',
            'price'             => $data['price'] ?? 0,
            'channel'           => core()->getDefaultChannelCode(),
            'locale'            => $this->defaultLocaleCode(),
            'categories'        => $this->skillCategoryIds(),
        ], $productId);

        Event::dispatch('catalog.product.update.after', $product->fresh());

        return $product->fresh();
    }

    /**
     * 给我的技能建一个版本（draft）。技能不属于我 → null。
     */
    public function createVersion(DeveloperProfile $profile, int $productId, array $data): ?SkillVersion
    {
        $product = $this->findOwnedSkill($profile, $productId);

        if (! $product) {
            return null;
        }

        return SkillVersion::firstOrCreate(
            [
                'product_id' => $product->id,
                'version'    => $data['version'],
            ],
            [
                'seller_id'     => $profile->seller_id,
                'channel'       => $data['channel'] ?? 'stable',
                'status'        => SkillVersion::STATUS_DRAFT,
                'license_mode'  => $data['license_mode'] ?? 'per_device',
                'changelog'     => $data['changelog'] ?? null,
                'install_guide' => $data['install_guide'] ?? null,
            ]
        );
    }

    /**
     * 上传交付物。版本不属于我 → null（控制器 404）。
     */
    public function uploadAsset(
        DeveloperProfile $profile,
        int $versionId,
        array $fields,
        ?UploadedFile $file
    ): ?AssetUploadResult {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        return $this->uploads->store($version, $fields, $file);
    }

    /**
     * 提审（复用 L0 机审，不另造一套）。
     *
     * 开发者只上传了 .skill 包，manifest 不在请求体里 —— 这里用 PackageInspector
     * 从已上传的包里解出 manifest，再喂给 AuditService::submitLayer0。
     *
     * @return array{ok:bool,status:int,message:string,code?:string,data?:array,errors?:array}|null
     */
    public function submitForReview(DeveloperProfile $profile, int $versionId): ?array
    {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        // 状态前置：只有「草稿」或「被驳回」的版本可以提审。
        // 为什么放在协议/技能包检查**之前**：这是"这个动作能不能做"的问题，比"材料齐不齐"更靠前；
        // 而且它堵掉"已 published 版本可重复提审" —— 那种重复提审会写一条 audit_record，
        // 却因版本状态不是 draft 而永远进不了审核队列（静默黑洞，谁都看不到）。
        // ⚠ 这里**只判不写**：状态的写入收敛在 AuditService（对 L0 判决同步写生命周期）。
        if (! $this->states->canSubmit($version)) {
            return [
                'ok'      => false,
                'status'  => 422,
                'code'    => 'VERSION_NOT_SUBMITTABLE',
                'message' => '只有「草稿」或「被驳回」的版本可以提交审核。',
            ];
        }

        // 唯一的流程闸门：协议必须签全（realname 审核不拦，见 DeveloperProfile 注释）
        if (! $profile->hasSignedAllAgreements()) {
            return [
                'ok'      => false,
                'status'  => 422,
                'code'    => 'AGREEMENT_REQUIRED',
                'message' => '请先签署全部开发者协议，再提交审核。',
            ];
        }

        $asset = SkillAsset::query()
            ->where('skill_version_id', $version->id)
            ->whereNotNull('file_path')
            ->orderByDesc('id')
            ->first();

        if (! $asset) {
            return [
                'ok'      => false,
                'status'  => 422,
                'code'    => 'PACKAGE_REQUIRED',
                'message' => '请先上传技能包（.skill），再提交审核。',
            ];
        }

        $inspected = $this->inspector->inspect(
            Storage::disk(SkillAsset::disk())->path($asset->file_path)
        );

        if (! ($inspected['ok'] ?? false)) {
            return [
                'ok'      => false,
                'status'  => 422,
                'code'    => 'PACKAGE_INVALID',
                'message' => '技能包检查未通过。',
                'errors'  => $inspected['errors'] ?? [],
            ];
        }

        $result = $this->audit->submitLayer0(
            $version->product_id,
            $version->id,
            $inspected['manifest'] ?? []
        );

        $passed = ($result['result'] ?? null) === AuditRecord::RESULT_PASS;

        return [
            'ok'      => $passed,
            'status'  => $passed ? 200 : 422,
            'message' => $passed ? '已提交审核，L0 机审通过。' : '已提交审核，L0 机审未通过。',
            'code'    => $passed ? 'SUBMITTED' : 'L0_REJECTED',
            'data'    => $result,
        ];
    }

    // ==================== 内部 ====================

    protected function defaultFamilyId(): int
    {
        // 属性族沿用 default（全库技能商品都用它）；取不到则回退 1
        return (int) (DB::table('attribute_families')->where('code', 'default')->value('id') ?: 1);
    }

    protected function skillCategoryIds(): array
    {
        $id = DB::table('category_translations')
            ->where('slug', self::SKILL_CATEGORY_SLUG)
            ->value('category_id');

        return $id ? [(int) $id] : [];
    }

    protected function defaultLocaleCode(): string
    {
        return (string) (optional(core()->getDefaultChannel()->default_locale)->code
            ?: core()->getDefaultChannelCode());
    }

    protected function generateSku(): string
    {
        do {
            $sku = 'DEV'.strtoupper(Str::random(10));
        } while (ProductProxy::modelClass()::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
