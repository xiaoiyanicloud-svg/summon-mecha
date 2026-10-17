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
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\ReviewGate;
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
        protected ReviewGate $gate,
        protected OperationLogger $ops,
    ) {}

    /**
     * 开发者的技能挂在这个分类下（categories 表 slug='skill'）。
     */
    protected const SKILL_CATEGORY_SLUG = 'skill';

    /**
     * 开发者「申请上架」的**交接信号** —— 写进 `operation_log.action` 的值（任务卡 S-3a）。
     *
     * ⚠ 这个字符串是**唯一定义处**：门户写入（本类）、运营审核台读取（SkillReviewController）
     *   都引用它。两边各写一份字面量迟早漂移 —— 读的那边会静默读不到（队列上再也标不出
     *   "开发者已申请上架"），且不会报错。
     */
    public const ACTION_LISTING_REQUESTED = 'skill.listing.requested';

    /**
     * 留痕/信号的目标类型 —— 信号挂在**版本**上（闸门是逐版本判的）。
     */
    public const TARGET_SKILL_VERSION = 'skill_version';

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
     * 建技能的**主体前置判定**（S-12a · 2026-10-16 拍板改判）——
     * 门户 `shop.dev.skills.store` 与 API `shop.api.dev.skills.store` **共用这一处**，
     * 不各写一份（两份判定迟早漂移）。
     *
     * 判定顺序 **归属 → 实名**，两条各自成码（不混）：
     *   - 归属缺失（seller_id 为 NULL）→ `DEVELOPER_SELLER_MISSING`：
     *     账号没建全，处置是"去补入驻"，**不是**实名问题，混码会把人引去实名页空转；
     *   - 实名未通过 → `OWNER_NOT_VERIFIED`（伞码，与上架闸门同码）：
     *     与"未签协议""归属异常"等区分开（见 ApiErrorCode::OWNER_NOT_VERIFIED）。
     *
     * ⚠ 判据走 `DeveloperProfile::isRealnameApproved()`（读总状态 `realname_status`）——
     *   存量不追溯、且与上架闸门口径一致，理由见该方法注释。
     *
     * @return array{ok:bool, code:?string, message:?string, realname:array{company:?string, operator:?string}}
     */
    public function canCreateSkill(DeveloperProfile $profile): array
    {
        $realname = [
            'company'  => $profile->company_realname_status,
            'operator' => $profile->operator_realname_status,
        ];

        if (! $profile->seller_id) {
            return [
                'ok'       => false,
                'code'     => 'DEVELOPER_SELLER_MISSING',
                'message'  => '开发者主体不完整（缺少归属），无法建技能；请先完成开发者入驻。',
                'realname' => $realname,
            ];
        }

        if (! $profile->isRealnameApproved()) {
            return [
                'ok'       => false,
                'code'     => 'OWNER_NOT_VERIFIED',
                'message'  => '请先完成实名认证（公司认证 + 操作人认证两层均需通过），再创建技能。',
                'realname' => $realname,
            ];
        }

        return ['ok' => true, 'code' => null, 'message' => null, 'realname' => $realname];
    }

    /**
     * 建技能（= 建一个 Bagisto 商品，归属到我的 seller）。
     *
     * 走框架自己的 ProductRepository：EAV 属性值 / product_flat / inventory
     * 这些由它负责，不手搓（手搓必出半截商品）。
     *
     * ⚠ 入口**再**加一道主体前置（S-12a）：控制器层已拦，这里**漏斗式 fail-closed** ——
     *   未来任何新调用方（如新的 API 通道、批量导入）绕开控制器时，也不会建出
     *   "未实名主体的技能"。与控制器共用 `canCreateSkill()`，不各判一套。
     */
    public function createSkill(DeveloperProfile $profile, array $data)
    {
        if (! $this->canCreateSkill($profile)['ok']) {
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
                'channel'       => $data['channel'] ?? SkillVersion::CHANNEL_STABLE,
                'status'        => SkillVersion::STATUS_DRAFT,
                'license_mode'  => $data['license_mode'] ?? SkillVersion::LICENSE_PER_DEVICE,
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

        // 提审的**唯一**流程闸门：协议必须签全。
        // ⚠ 实名**不拦提审**，但**拦建技能**（S-12a）—— 提审的前提是先建出技能，
        //   所以"未实名"实际走不到这里（见 DeveloperProfile 类注释）。
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

    /**
     * 撤回提审 —— 把版本从「审核中」拉回「草稿」（任务卡 D-3 · D）。
     *
     * 只有 `in_review` 可撤回：其它状态没有"待审"可撤（draft 本就未提交，rejected 已是终局待整改）。
     * 撤回后版本**自动离开审核台队列** —— 队列口径是 in_review|rejected（见 SkillReviewController），
     * 状态一改成 draft 就掉了，无需额外动队列。
     *
     * 归属过滤在查询层（findOwnedVersion），拿不到 → null（控制器 404，不是 403）。
     *
     * @return array{ok:bool,code:string,message:string,version?:SkillVersion}|null
     */
    public function withdrawVersion(DeveloperProfile $profile, int $versionId): ?array
    {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        if ($version->status !== SkillVersion::STATUS_IN_REVIEW) {
            return [
                'ok'      => false,
                'code'    => 'VERSION_NOT_WITHDRAWABLE',
                'message' => '只有审核中的版本可以撤回。',
            ];
        }

        // 状态写入收敛到 SkillVersionState（唯一入口）；in_review → draft 是白名单边。
        $transition = $this->states->transition($version, SkillVersion::STATUS_DRAFT);

        if (! $transition['ok']) {
            return [
                'ok'      => false,
                'code'    => $transition['code'],
                'message' => '撤回失败：'.$transition['message'],
            ];
        }

        return [
            'ok'      => true,
            'code'    => 'WITHDRAWN',
            'message' => '已撤回提审，版本回到草稿状态。',
            'version' => $version,
        ];
    }

    /**
     * 下架我的技能 —— 把商品置为**不可售**（任务卡 D-3 · E）。
     *
     * ⚠ **不回收已售授权**：`skill_entitlements` 一律不动。买断的设备继续可用，
     *   本动作只停"新增销售"。任何"下架即回收"的实现都违反 D3（买断制）与 D10（已激活可申诉）。
     *
     * ⚠ **开发者不能自己重新上架**：本类**不提供** online 入口。重新上架走
     *   "运营发布" 或 "重新提审 → 发布"，避免"下架 → 立刻自己上架"的反复横跳
     *   （上下架是影响买家可见性的动作，语义上是运营/发布链的一环，不是开发者的自由开关）。
     *
     * 归属过滤在查询层（findOwnedSkill），拿不到 → null（控制器 404）。
     *
     * @return array{ok:bool,code:string,message:string}|null
     */
    public function takeOffline(DeveloperProfile $profile, int $productId): ?array
    {
        $product = $this->findOwnedSkill($profile, $productId);

        if (! $product) {
            return null;
        }

        // 与"发布置可售"（SkillPublisher::makeProductSellable）同一条商品更新通道 + 事件三步：
        //   flat 是派生表，绕过事件只改 EAV 会留下不一致（列表/搜索读的仍是旧 status）。
        // ⚠ 必须带上商品当前分类，否则 AbstractType::update 会把它从「技能」分类里摘掉。
        $categories = $product->categories->pluck('id')->all();

        Event::dispatch('catalog.product.update.before', $productId);

        $this->products->update([
            'status'     => 0,
            'channel'    => core()->getDefaultChannelCode(),
            'locale'     => $this->defaultLocaleCode(),
            'categories' => $categories,
        ], $productId);

        Event::dispatch('catalog.product.update.after', $product->fresh());

        // skill_entitlements 刻意不动 —— 见方法注释（下架不回收已售授权）。

        return [
            'ok'      => true,
            'code'    => 'OFFLINED',
            'message' => '技能已下架（只停新增销售，已售授权不受影响）。',
        ];
    }

    // ==================== 申请上架（交接信号 · 任务卡 S-3a） ====================

    /**
     * 申请上架 —— 开发者把「闸门已过、但还没上架」的版本**交接给运营**。
     *
     * **为什么只是一个信号，而不是发布**：发布 = 承担交易与合规责任的对外动作。
     *   2026-10-16 拍板「上架权归运营」——开发者侧只给「申请上架」这一个**交接信号**，
     *   不做自助上架。所以本方法刻意：
     *     · 只在**闸门已过**、且商品**尚不可售**时受理；
     *     · 作用**仅限**写一条 `operation_log`（谁 / 何时 / 哪个技能与版本）——
     *       绝不改 `skill_versions.status`、不改商品 `status`、**不调用 `SkillPublisher`**；
     *     · **幂等**：同一版本重复申请不产生第二条信号，如实回"已于 … 申请过"。
     *
     * 归属过滤在查询层：技能不属于我、或版本不属于该技能 → null（控制器 404）。
     *
     * @return array{ok:bool,code:string,message:string,requested_at?:string|null}|null
     */
    public function requestListing(DeveloperProfile $profile, int $productId, int $versionId): ?array
    {
        // 技能归属（查询层过滤）：不属于我 → null
        if (! $this->findOwnedSkill($profile, $productId)) {
            return null;
        }

        // 版本归属（查询层）：必须属于这个技能、且属于本主体 —— 三条件一起过滤，
        // 避免"拿自己的技能 id 配别人的版本 id"这种拼接越权。
        $version = SkillVersion::query()
            ->where('id', $versionId)
            ->where('product_id', $productId)
            ->where('seller_id', $profile->seller_id)
            ->first();

        if (! $version) {
            return null;
        }

        // 幂等：同版本已有信号 → 不重复写，如实回上次时间（不静默、不报错）
        $existing = $this->listingRequestLog($versionId);

        if ($existing) {
            return [
                'ok'           => true,
                'code'         => 'ALREADY_REQUESTED',
                'message'      => '已于 '.$existing->created_at->format('Y-m-d H:i').' 申请上架，请等待平台处理。',
                'requested_at' => $existing->created_at->format('Y-m-d H:i'),
            ];
        }

        // 闸门必须已过 —— 复用唯一定义处 `ReviewGate::evaluate()`，门户/后台**不另判一套**
        if (! $this->gate->evaluate($productId, $versionId)['ok']) {
            return [
                'ok'      => false,
                'code'    => 'GATE_NOT_PASSED',
                'message' => '发布闸门尚未通过，还不能申请上架。请先按「发布闸门」表补齐未通过的审核层。',
            ];
        }

        // 商品必须**尚未可售**：已可售 = 已上架，没有可交接的东西
        //（判据与门户「已上架」角标同源：product_flat.status，见 isProductSellable）。
        if ($this->isProductSellable($productId)) {
            return [
                'ok'      => false,
                'code'    => 'ALREADY_SELLABLE',
                'message' => '该技能当前已可售（已上架），无需申请上架。',
            ];
        }

        // 信号 + 留痕**同一条**：`operation_log` 既是给运营的交接信号，也是审计留痕。
        // 走唯一入口 OperationLogger（与下架/撤回同源）。
        $log = $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            self::ACTION_LISTING_REQUESTED,
            self::TARGET_SKILL_VERSION,
            $versionId,
            [
                'product_id' => $productId,
                'version'    => $version->version,
            ]
        );

        // ⚠ 这个动作的**全部意义**就是留下这条信号 —— 写不进去就等于什么都没做。
        //   绝不静默报成"已申请"（那会让开发者以为交出去了，运营那边其实什么都没有）。
        if (! $log) {
            return [
                'ok'      => false,
                'code'    => 'SIGNAL_WRITE_FAILED',
                'message' => '申请信号写入失败，请稍后重试（平台未收到这次申请）。',
            ];
        }

        return [
            'ok'           => true,
            'code'         => 'REQUESTED',
            'message'      => '已申请上架。审核通过 ≠ 自动上架 —— 上架由平台运营完成，请等待处理。',
            'requested_at' => $log->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * 某版本已有的「申请上架」信号（幂等判据）。
     */
    public function listingRequestLog(int $versionId): ?OperationLog
    {
        return OperationLog::query()
            ->where('action', self::ACTION_LISTING_REQUESTED)
            ->where('target_type', self::TARGET_SKILL_VERSION)
            ->where('target_id', $versionId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 批量取一组版本的「申请上架」时间（versionId => 'Y-m-d H:i'）——
     * 门户详情页与运营审核台**共用同一读法**，避免逐版本查库（N+1），
     * 也避免两处各写一份 WHERE（action 字符串漂移时就有一边读不到）。
     *
     * @param  array<int, int|string>  $versionIds
     * @return array<int, string>
     */
    public function listingRequestedAt(array $versionIds): array
    {
        $versionIds = array_values(array_filter(array_map('intval', $versionIds)));

        if ($versionIds === []) {
            return [];
        }

        // 幂等保证同版本至多一条，但万一有历史多条，orderBy('id') + mapWithKeys
        // 让**后写（更大 id）覆盖先写**，留在手里的是最新一次申请时间。
        return OperationLog::query()
            ->where('action', self::ACTION_LISTING_REQUESTED)
            ->where('target_type', self::TARGET_SKILL_VERSION)
            ->whereIn('target_id', $versionIds)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (OperationLog $log) => [
                (int) $log->target_id => optional($log->created_at)->format('Y-m-d H:i'),
            ])
            ->all();
    }

    /**
     * 商品当前是否**可售**（= 已上架）。
     *
     * 判据刻意与门户「已上架」角标同源（`product_flat.status`，见 skillSummary）——
     * 若这里另判 `visible_individually`，会出现"页面显示未上架、按钮却说已上架"的自相矛盾。
     */
    protected function isProductSellable(int $productId): bool
    {
        $status = DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('channel', core()->getDefaultChannelCode())
            ->where('locale', $this->defaultLocaleCode())
            ->value('status');

        return (int) $status === 1;
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
