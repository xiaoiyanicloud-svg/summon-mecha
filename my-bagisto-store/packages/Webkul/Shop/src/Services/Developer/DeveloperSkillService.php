<?php

namespace Webkul\Shop\Services\Developer;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillCompatibilityEvidence;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\ReviewGate;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;
use Webkul\Shop\Services\SkillPackage\PackageInspector;
use Webkul\Shop\Services\SkillPackage\SkillManifest;
use Webkul\Shop\Services\SkillPackage\SkillPackageBuilder;
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
     * 取"我的某个交付物（SkillAsset）"。在查询层通过 skill_versions.seller_id 过滤归属。
     * 不属于我 → null。
     */
    public function findOwnedAsset(DeveloperProfile $profile, int $assetId): ?SkillAsset
    {
        if (! $profile->seller_id) {
            return null;
        }

        return SkillAsset::query()
            ->select('skill_assets.*')
            ->join('skill_versions', 'skill_versions.id', '=', 'skill_assets.skill_version_id')
            ->where('skill_assets.id', $assetId)
            ->where('skill_versions.seller_id', $profile->seller_id)
            ->with('version')
            ->first();
    }

    /**
     * 我的技能列表（带名字/价格/版本统计/授权统计，供门户列表页与 API 用）。
     *
     * 直接 join product_flat 取展示字段，并用批量聚合补充版本数与最新状态，避免 N+1。
     */
    public function listSkills(DeveloperProfile $profile): Collection
    {
        if (! $profile->seller_id) {
            return collect();
        }

        $rows = DB::table('products')
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
                'product_flat.status',
                'product_flat.short_description'
            )
            ->orderByDesc('products.id')
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $productIds = $rows->pluck('id')->all();

        $versionsByProduct = SkillVersion::query()
            ->whereIn('product_id', $productIds)
            ->where('seller_id', $profile->seller_id)
            ->orderByDesc('id')
            ->get(['id', 'product_id', 'version', 'status', 'channel'])
            ->groupBy('product_id');

        $entitlementsByProduct = DB::table('skill_entitlements')
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, COUNT(*) as cnt')
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id');

        return $rows->map(function ($row) use ($versionsByProduct, $entitlementsByProduct) {
            $versions = $versionsByProduct->get($row->id, collect());
            $latest = $versions->first();

            $row->versions_count = $versions->count();
            $row->latest_version = $latest?->version;
            $row->latest_version_status = $latest?->status;
            $row->latest_version_channel = $latest?->channel;
            $row->entitlements_count = (int) ($entitlementsByProduct[$row->id] ?? 0);

            return $row;
        });
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
     * 我的版本的审核记录（含驳回理由 + 技能名称与版本号回显 —— 计划 C 组"驳回理由开发者可见"）。
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

        $records = AuditRecord::query()
            ->whereIn('skill_id', $productIds)
            ->orderByDesc('id')
            ->get();

        if ($records->isEmpty()) {
            return $records;
        }

        $skillNames = DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->where('channel', core()->getDefaultChannelCode())
            ->where('locale', $this->defaultLocaleCode())
            ->pluck('name', 'product_id');

        $versionIds = $records->pluck('version_id')->filter()->unique()->values()->all();
        $versionNumbers = $versionIds
            ? SkillVersion::query()->whereIn('id', $versionIds)->pluck('version', 'id')
            : collect();

        return $records->map(function (AuditRecord $rec) use ($skillNames, $versionNumbers) {
            $rec->setAttribute('skill_name', $skillNames[$rec->skill_id] ?? ('技能 #'.$rec->skill_id));
            $rec->setAttribute('version_number', $rec->version_id ? ($versionNumbers[$rec->version_id] ?? null) : null);

            return $rec;
        });
    }

    /**
     * 开发者本主体的操作审计留痕（改价、下架、撤回提审、申请上架、版本配置更新、交付物移除、适配证据登记）。
     */
    public function listOperationLogs(DeveloperProfile $profile, int $limit = 50): Collection
    {
        if (! $profile->seller_id) {
            return collect();
        }

        return OperationLog::query()
            ->where('actor_type', OperationLog::ACTOR_SELLER)
            ->where('actor_id', $profile->seller_id)
            ->orderByDesc('id')
            ->limit($limit)
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

        $channel = in_array($data['channel'] ?? '', SkillVersion::CHANNELS, true)
            ? $data['channel']
            : SkillVersion::CHANNEL_STABLE;

        $licenseMode = in_array($data['license_mode'] ?? '', SkillVersion::LICENSE_MODES, true)
            ? $data['license_mode']
            : SkillVersion::LICENSE_PER_DEVICE;

        $riskLevel = in_array($data['risk_level'] ?? '', ['low', 'medium', 'high'], true)
            ? $data['risk_level']
            : null;

        $deliveryChannels = is_array($data['delivery_channels'] ?? null)
            ? array_values(array_intersect($data['delivery_channels'], SkillAsset::DELIVERY_CHANNELS))
            : null;

        $permissions = is_array($data['permissions'] ?? null)
            ? array_values(array_intersect($data['permissions'], SkillManifest::PERMISSION_WHITELIST))
            : null;

        $compatibility = $this->normalizeCompatibility($data);

        return SkillVersion::firstOrCreate(
            [
                'product_id' => $product->id,
                'version'    => $data['version'],
            ],
            array_filter([
                'seller_id'         => $profile->seller_id,
                'channel'           => $channel,
                'status'            => SkillVersion::STATUS_DRAFT,
                'license_mode'      => $licenseMode,
                'max_activations'   => isset($data['max_activations']) && $data['max_activations'] !== '' ? (int) $data['max_activations'] : null,
                'valid_days'        => isset($data['valid_days']) && $data['valid_days'] !== '' ? (int) $data['valid_days'] : null,
                'min_robot_version' => ! empty($data['min_robot_version']) ? (string) $data['min_robot_version'] : null,
                'risk_level'        => $riskLevel,
                'delivery_channels' => $deliveryChannels ?: null,
                'permissions'       => $permissions ?: null,
                'compatibility'     => $compatibility,
                'changelog'         => $data['changelog'] ?? null,
                'install_guide'     => $data['install_guide'] ?? null,
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * 更新草稿或被驳回版本的配置与说明（三级/四级闭环）。
     *
     * ⚠ 状态锁：审核中（in_review）与已发布（published）版本禁止修改版本参数与说明，
     *   避免在审核流转中途或对已买断买家静默篡改授权规则/适配承诺。
     *
     * @return array{ok:bool,status:int,code:string,message:string,version?:SkillVersion}|null
     */
    public function updateVersion(DeveloperProfile $profile, int $versionId, array $data): ?array
    {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        if (! in_array($version->status, [SkillVersion::STATUS_DRAFT, SkillVersion::STATUS_REJECTED], true)) {
            return [
                'ok'      => false,
                'status'  => 409,
                'code'    => $version->isPublished() ? 'VERSION_PUBLISHED_IMMUTABLE' : 'CONFLICT',
                'message' => $version->isPublished()
                    ? '已发布的版本不可修改配置；请新建更高版本进行迭代。'
                    : '审核中的版本不可修改配置；如需修改请先撤回提审。',
            ];
        }

        if (isset($data['channel']) && in_array($data['channel'], SkillVersion::CHANNELS, true)) {
            $version->channel = $data['channel'];
        }

        if (isset($data['license_mode']) && in_array($data['license_mode'], SkillVersion::LICENSE_MODES, true)) {
            $version->license_mode = $data['license_mode'];
        }

        if (array_key_exists('max_activations', $data)) {
            $version->max_activations = ($data['max_activations'] !== null && $data['max_activations'] !== '')
                ? (int) $data['max_activations']
                : null;
        }

        if (array_key_exists('valid_days', $data)) {
            $version->valid_days = ($data['valid_days'] !== null && $data['valid_days'] !== '')
                ? (int) $data['valid_days']
                : null;
        }

        if (array_key_exists('min_robot_version', $data)) {
            $version->min_robot_version = ($data['min_robot_version'] !== null && trim((string) $data['min_robot_version']) !== '')
                ? trim((string) $data['min_robot_version'])
                : null;
        }

        if (array_key_exists('risk_level', $data)) {
            $version->risk_level = in_array($data['risk_level'], ['low', 'medium', 'high'], true)
                ? $data['risk_level']
                : $version->risk_level;
        }

        if (array_key_exists('delivery_channels', $data)) {
            $channels = is_array($data['delivery_channels'])
                ? array_values(array_intersect($data['delivery_channels'], SkillAsset::DELIVERY_CHANNELS))
                : [];
            $version->delivery_channels = $channels ?: null;
        }

        if (array_key_exists('permissions', $data)) {
            $perms = is_array($data['permissions'])
                ? array_values(array_intersect($data['permissions'], SkillManifest::PERMISSION_WHITELIST))
                : [];
            $version->permissions = $perms ?: null;
        }

        if (array_key_exists('compatibility', $data) || array_key_exists('target_models', $data)) {
            $version->compatibility = $this->normalizeCompatibility($data);
        }

        if (array_key_exists('changelog', $data)) {
            $version->changelog = $data['changelog'];
        }

        if (array_key_exists('install_guide', $data)) {
            $version->install_guide = $data['install_guide'];
        }

        $version->save();

        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.version.update',
            self::TARGET_SKILL_VERSION,
            $version->id,
            [
                'product_id'   => $version->product_id,
                'version'      => $version->version,
                'channel'      => $version->channel,
                'license_mode' => $version->license_mode,
                'risk_level'   => $version->risk_level,
            ]
        );

        return [
            'ok'      => true,
            'status'  => 200,
            'code'    => 'UPDATED',
            'message' => '版本配置与说明已更新。',
            'version' => $version->fresh(),
        ];
    }

    /**
     * 移除未锁定的版本交付物（仅允许 draft / rejected 状态版本）。
     *
     * @return array{ok:bool,status:int,code:string,message:string}|null
     */
    public function deleteAsset(DeveloperProfile $profile, int $assetId): ?array
    {
        $asset = $this->findOwnedAsset($profile, $assetId);

        if (! $asset || ! $asset->version) {
            return null;
        }

        $version = $asset->version;

        if (! in_array($version->status, [SkillVersion::STATUS_DRAFT, SkillVersion::STATUS_REJECTED], true)) {
            return [
                'ok'      => false,
                'status'  => 409,
                'code'    => $version->isPublished() ? 'VERSION_PUBLISHED_IMMUTABLE' : 'CONFLICT',
                'message' => $version->isPublished()
                    ? '已发布版本的交付物不可移除。'
                    : '审核中版本的交付物不可直接移除；如需更换或删除，请先撤回提审。',
            ];
        }

        $meta = [
            'product_id'   => $version->product_id,
            'version_id'   => $version->id,
            'version'      => $version->version,
            'carrier_type' => $asset->carrier_type,
            'file_name'    => $asset->file_name,
            'file_hash'    => $asset->file_hash,
        ];

        if ($asset->file_path) {
            $disk = Storage::disk(SkillAsset::disk());
            if ($disk->exists($asset->file_path)) {
                $disk->delete($asset->file_path);
            }
        }

        $asset->delete();

        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.asset.delete',
            'skill_asset',
            $assetId,
            $meta
        );

        return [
            'ok'      => true,
            'status'  => 200,
            'code'    => 'DELETED',
            'message' => '交付物已移除。',
        ];
    }

    /**
     * 登记版本自测/仿真/真机适配证据（四级闭环 · 钉住当前产物 SHA-256）。
     *
     * @return array{ok:bool,status:int,code:string,message:string,evidence?:SkillCompatibilityEvidence}|null
     */
    public function recordCompatibilityEvidence(DeveloperProfile $profile, int $versionId, array $data): ?array
    {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        $asset = null;
        if (! empty($data['asset_id'])) {
            $asset = SkillAsset::query()
                ->where('id', (int) $data['asset_id'])
                ->where('skill_version_id', $version->id)
                ->first();
        }

        if (! $asset) {
            $asset = SkillAsset::query()
                ->where('skill_version_id', $version->id)
                ->orderByRaw("CASE WHEN carrier_type = 'file' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();
        }

        $evidence = SkillCompatibilityEvidence::create([
            'skill_version_id' => $version->id,
            'product_id'       => $version->product_id,
            'model_code'       => trim((string) $data['model_code']),
            'firmware_version' => ! empty($data['firmware_version']) ? trim((string) $data['firmware_version']) : null,
            'sdk_version'      => ! empty($data['sdk_version']) ? trim((string) $data['sdk_version']) : null,
            'agent_version'    => ! empty($data['agent_version']) ? trim((string) $data['agent_version']) : null,
            'device_did'       => ! empty($data['device_did']) ? trim((string) $data['device_did']) : null,
            'carrier_type'     => $asset?->carrier_type ?? ($data['carrier_type'] ?? SkillAsset::CARRIER_FILE),
            'asset_id'         => $asset?->id,
            'asset_hash'       => $asset?->file_hash,
            'source'           => $data['source'],
            'evidence_type'    => $data['evidence_type'],
            'result'           => $data['result'],
            'summary'          => ! empty($data['summary']) ? trim((string) $data['summary']) : null,
            'verified_by'      => 'seller:'.$profile->seller_id,
            'observed_at'      => now(),
        ]);

        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.version.evidence',
            self::TARGET_SKILL_VERSION,
            $version->id,
            [
                'evidence_id'   => $evidence->id,
                'model_code'    => $evidence->model_code,
                'source'        => $evidence->source,
                'evidence_type' => $evidence->evidence_type,
                'result'        => $evidence->result,
                'asset_id'      => $evidence->asset_id,
            ]
        );

        return [
            'ok'       => true,
            'status'   => 201,
            'code'     => 'CREATED',
            'message'  => '适配验证记录已登记（已绑定当前版本产物快照）。',
            'evidence' => $evidence,
        ];
    }

    /**
     * 将表单或 JSON 提交的适配机型解析为标准 compatibility 结构。
     */
    protected function normalizeCompatibility(array $data): ?array
    {
        if (isset($data['compatibility']) && is_array($data['compatibility'])) {
            return $data['compatibility'];
        }

        if (isset($data['target_models'])) {
            $raw = $data['target_models'];
            $models = is_array($raw)
                ? $raw
                : preg_split('/[\s,;，；]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);

            $models = array_values(array_unique(array_filter(array_map('trim', (array) $models))));
            if ($models === []) {
                return null;
            }

            return [
                'models' => array_map(fn (string $m) => ['model' => $m], $models),
            ];
        }

        return null;
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

        // DEV-02 上传即安检（Fail-Fast）：
        // 若上传文件且载体为文件类，在落盘前预检包结构与合法性，
        // 避免开发者上传非法文件却提示成功，直到提审才报错。
        if ($file && in_array($fields['carrier_type'] ?? '', SkillAsset::FILE_BACKED_CARRIERS, true)) {
            $inspected = $this->inspector->inspect($file->getRealPath());

            if (! ($inspected['ok'] ?? false)) {
                $err = implode('；', $inspected['errors'] ?? ['技能包格式无效或缺失 manifest.json 清单。']);

                return AssetUploadResult::fail("技能包安检失败：{$err}", 422, $inspected);
            }

            // 若请求未传 entrypoint，但包内 manifest 声明了 runtime.entrypoint，自动回填
            if (empty($fields['entrypoint']) && ! empty($inspected['manifest']['runtime']['entrypoint'])) {
                $fields['entrypoint'] = (string) $inspected['manifest']['runtime']['entrypoint'];
            }
        }

        if (isset($fields['install_guide']) && is_string($fields['install_guide']) && trim($fields['install_guide']) !== '') {
            $version->install_guide = trim($fields['install_guide']);
            $version->save();
        }

        return $this->uploads->store($version, $fields, $file);
    }

    /**
     * 为指定版本生成标准示例 .skill 包（供开发者下载参考）。
     */
    public function buildSampleForVersion(DeveloperProfile $profile, int $versionId, array $options = []): ?UploadedFile
    {
        $version = $this->findOwnedVersion($profile, $versionId);

        if (! $version) {
            return null;
        }

        return app(SkillPackageBuilder::class)->buildSamplePackage($version, $options);
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
            ->orderByRaw("CASE WHEN carrier_type = 'file' THEN 0 ELSE 1 END")
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

    // ==================== 交易与授权订单（闭环 L3/L4） ====================

    /**
     * 开发者名下技能销售指标（GMV、净销售额、授权总数、有效授权、待绑定、已撤销、下载量）。
     */
    public function salesMetrics(DeveloperProfile $profile): array
    {
        if (! $profile->seller_id) {
            return [
                'gmv_cents'            => 0,
                'gmv_yuan'             => '0.00',
                'net_sales_cents'      => 0,
                'net_sales_yuan'       => '0.00',
                'orders_count'         => 0,
                'total_entitlements'   => 0,
                'active_count'         => 0,
                'pending_bind_count'   => 0,
                'revoked_count'        => 0,
                'expired_count'        => 0,
                'downloads_count'      => 0,
            ];
        }

        $sellerId = $profile->seller_id;

        $entitlements = SkillEntitlement::query()
            ->where('seller_id', $sellerId)
            ->with(['orderItem'])
            ->get();

        $gmvCents = 0;
        $revokedCents = 0;
        $orderIds = [];

        foreach ($entitlements as $ent) {
            if ($ent->order_id) {
                $orderIds[$ent->order_id] = true;
            }
            $itemTotal = (float) ($ent->orderItem?->base_total ?? 0);
            $cents = (int) round($itemTotal * 100);
            $gmvCents += $cents;
            if ($ent->status === SkillEntitlement::STATUS_REVOKED) {
                $revokedCents += $cents;
            }
        }

        $netSalesCents = max(0, $gmvCents - $revokedCents);

        $downloadCount = Schema::hasTable('operation_log')
            ? (int) DB::table('operation_log')
                ->where('action', 'skill.download')
                ->where('target_type', 'skill_version')
                ->whereIn('target_id', function ($query) use ($sellerId) {
                    $query->select('id')->from('skill_versions')->where('seller_id', $sellerId);
                })
                ->count()
            : 0;

        return [
            'gmv_cents'            => $gmvCents,
            'gmv_yuan'             => number_format($gmvCents / 100, 2, '.', ''),
            'net_sales_cents'      => $netSalesCents,
            'net_sales_yuan'       => number_format($netSalesCents / 100, 2, '.', ''),
            'orders_count'         => count($orderIds),
            'total_entitlements'   => $entitlements->count(),
            'active_count'         => $entitlements->where('status', SkillEntitlement::STATUS_ACTIVE)->count(),
            'pending_bind_count'   => $entitlements->where('status', SkillEntitlement::STATUS_PENDING_BIND)->count(),
            'revoked_count'        => $entitlements->where('status', SkillEntitlement::STATUS_REVOKED)->count(),
            'expired_count'        => $entitlements->where('status', SkillEntitlement::STATUS_EXPIRED)->count(),
            'downloads_count'      => $downloadCount,
        ];
    }

    /**
     * 开发者名下订单列表（分页 + 状态过滤 + 关键词搜索 + 买家 PII 严格脱敏）。
     */
    public function listOrders(DeveloperProfile $profile, array $filters = [], int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if (! $profile->seller_id) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = SkillEntitlement::query()
            ->where('skill_entitlements.seller_id', $profile->seller_id)
            ->with(['product', 'version', 'device', 'order', 'orderItem']);

        if (! empty($filters['status'])) {
            $query->where('skill_entitlements.status', (string) $filters['status']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('skill_entitlements.product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['keyword'])) {
            $kw = trim((string) $filters['keyword']);
            $query->where(function ($q) use ($kw) {
                $q->where('skill_entitlements.license_key', 'like', '%'.$kw.'%')
                    ->orWhereHas('order', function ($oq) use ($kw) {
                        $oq->where('increment_id', 'like', '%'.$kw.'%')
                            ->orWhere('customer_first_name', 'like', '%'.$kw.'%')
                            ->orWhere('customer_email', 'like', '%'.$kw.'%');
                    })
                    ->orWhereHas('device', function ($dq) use ($kw) {
                        $dq->where('sn', 'like', '%'.$kw.'%');
                    })
                    ->orWhereIn('skill_entitlements.product_id', function ($sub) use ($kw) {
                        $sub->select('product_id')->from('product_flat')->where('name', 'like', '%'.$kw.'%');
                    });
            });
        }

        $paginator = $query->orderByDesc('skill_entitlements.id')->paginate(max(1, min(100, $perPage)));
        $paginator->getCollection()->transform(fn (SkillEntitlement $ent) => $this->formatOrderEntitlement($ent));

        return $paginator;
    }

    /**
     * 开发者查询单个属于自己的授权订单（Anti-IDOR：查不到返回 null）。
     */
    public function findOwnedOrder(DeveloperProfile $profile, int $entitlementId): ?array
    {
        if (! $profile->seller_id) {
            return null;
        }

        $ent = SkillEntitlement::query()
            ->where('seller_id', $profile->seller_id)
            ->where('id', $entitlementId)
            ->with(['product', 'version', 'device', 'order', 'orderItem'])
            ->first();

        return $ent ? $this->formatOrderEntitlement($ent) : null;
    }

    /**
     * 格式化授权订单为结构化数据，含 PII 脱敏、履约状态与完整时间线。
     */
    public function formatOrderEntitlement(SkillEntitlement $ent): array
    {
        $order = $ent->order;
        $orderItem = $ent->orderItem;
        $version = $ent->version;
        $device = $ent->device;

        $amountYuan = $orderItem ? (float) $orderItem->base_total : 0.0;
        $amountCents = (int) round($amountYuan * 100);

        // 买家 PII 严格合规脱敏（智能适配中英文姓名顺序）
        $rawFirstName = trim((string) ($order?->customer_first_name ?? ''));
        $rawLastName = trim((string) ($order?->customer_last_name ?? ''));
        if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $rawFirstName . $rawLastName)) {
            $rawBuyerName = trim($rawLastName . $rawFirstName);
        } else {
            $rawBuyerName = trim($rawFirstName . ' ' . $rawLastName);
        }
        if ($rawBuyerName === '') {
            $rawBuyerName = '机甲开发者用户';
        }
        $nameLen = mb_strlen($rawBuyerName, 'UTF-8');
        if ($nameLen <= 1) {
            $maskedBuyerName = '*';
        } elseif ($nameLen === 2) {
            $maskedBuyerName = mb_substr($rawBuyerName, 0, 1, 'UTF-8') . '*';
        } else {
            $maskedBuyerName = mb_substr($rawBuyerName, 0, 1, 'UTF-8') . str_repeat('*', $nameLen - 2) . mb_substr($rawBuyerName, -1, 1, 'UTF-8');
        }

        $rawEmail = (string) ($order?->customer_email ?? '');
        $maskedBuyerEmail = '—';
        if ($rawEmail !== '') {
            $parts = explode('@', $rawEmail, 2);
            $local = $parts[0] ?? '';
            $domain = $parts[1] ?? '';
            $maskedLocal = mb_substr($local, 0, 1) . '***';
            $maskedBuyerEmail = $maskedLocal . '@' . $domain;
        }

        $statusLabels = [
            SkillEntitlement::STATUS_ACTIVE       => '生效中',
            SkillEntitlement::STATUS_PENDING_BIND => '待绑定设备',
            SkillEntitlement::STATUS_GRANTED      => '已发放',
            SkillEntitlement::STATUS_REVOKED      => '已撤销/退款',
            SkillEntitlement::STATUS_EXPIRED      => '已过期',
            SkillEntitlement::STATUS_EXPIRING     => '回收中',
        ];

        $statusLabel = $statusLabels[$ent->status] ?? $ent->status;

        // 撤销/取消信息溯源
        $meta = is_array($ent->meta) ? $ent->meta : [];
        $cancelReason = $meta['revoke_reason'] ?? $meta['cancel_reason'] ?? ($ent->status === SkillEntitlement::STATUS_REVOKED ? '订单取消或售后退款' : null);

        // 时间线
        $timeline = [];
        if ($order?->created_at) {
            $timeline[] = [
                'title'  => '订单创建与支付',
                'time'   => $order->created_at->format('Y-m-d H:i:s'),
                'status' => 'done',
                'desc'   => '平台订单编号 #' . ($order->increment_id ?? $order->id) . '，金额 ¥' . number_format($amountYuan, 2),
            ];
        }
        if ($ent->granted_at) {
            $timeline[] = [
                'title'  => '授权许可发放',
                'time'   => $ent->granted_at->format('Y-m-d H:i:s'),
                'status' => 'done',
                'desc'   => '凭证已安全生成并就绪，通道：' . ($ent->delivery_channel ?: 'api'),
            ];
        }
        if ($device || ! empty($meta['device_sn'])) {
            $deviceSn = $device?->sn ?? $meta['device_sn'];
            $timeline[] = [
                'title'  => '设备绑定成功',
                'time'   => optional($ent->updated_at)->format('Y-m-d H:i:s') ?: optional($ent->granted_at)->format('Y-m-d H:i:s'),
                'status' => 'done',
                'desc'   => '已挂载至设备（SN: ' . $deviceSn . '）',
            ];
        } elseif ($ent->status === SkillEntitlement::STATUS_PENDING_BIND) {
            $timeline[] = [
                'title'  => '等待设备挂载绑定',
                'time'   => null,
                'status' => 'wait',
                'desc'   => '买家尚未在终端机器人中填入或拉取该授权许可',
            ];
        }

        if ($ent->status === SkillEntitlement::STATUS_REVOKED) {
            $timeline[] = [
                'title'  => '授权撤销 / 订单取消',
                'time'   => optional($ent->revoked_at)->format('Y-m-d H:i:s') ?: optional($ent->updated_at)->format('Y-m-d H:i:s'),
                'status' => 'revoked',
                'desc'   => '撤销原因：' . ($cancelReason ?: '用户发起退款或订单撤单'),
            ];
        } elseif ($ent->status === SkillEntitlement::STATUS_EXPIRED) {
            $timeline[] = [
                'title'  => '授权到期失效',
                'time'   => optional($ent->expires_at)->format('Y-m-d H:i:s'),
                'status' => 'expired',
                'desc'   => '订阅或试用期已满，自动终止设备调用权限',
            ];
        }

        $productFlat = DB::table('product_flat')
            ->where('product_id', $ent->product_id)
            ->first();

        return [
            'id'                 => (int) $ent->id,
            'order_id'           => (int) $ent->order_id,
            'order_no'           => (string) ($order?->increment_id ?? ('#' . $ent->order_id)),
            'order_status'       => (string) ($order?->status ?? 'processing'),
            'product_id'         => (int) $ent->product_id,
            'product_name'       => (string) ($ent->product?->name ?? $productFlat?->name ?? $orderItem?->name ?? '机器人技能商品'),
            'product_sku'        => (string) ($ent->product?->sku ?? $orderItem?->sku ?? ''),
            'version_id'         => $ent->skill_version_id,
            'version'            => (string) ($version?->version ?? '最新稳定版'),
            'version_code'       => $version?->version_code,
            'license_key'        => (string) $ent->license_key,
            'license_key_masked' => substr($ent->license_key, 0, 8) . '••••••••' . substr($ent->license_key, -6),
            'status'             => (string) $ent->status,
            'status_label'       => $statusLabel,
            'amount_cents'       => $amountCents,
            'amount_yuan'        => number_format($amountYuan, 2, '.', ''),
            'delivery_channel'   => (string) ($ent->delivery_channel ?: 'api'),
            'activations'        => (int) $ent->activations,
            'max_activations'    => $ent->max_activations,
            'buyer_id'           => (int) ($ent->customer_id ?? $order?->customer_id ?? 0),
            'buyer_name_masked'  => $maskedBuyerName,
            'buyer_email_masked' => $maskedBuyerEmail,
            'device_id'          => $ent->device_id,
            'device_sn'          => $device?->sn ?? ($meta['device_sn'] ?? null),
            'device_model'       => $device?->model_code ?? ($meta['device_model'] ?? null),
            'granted_at'         => optional($ent->granted_at)->format('Y-m-d H:i:s'),
            'expires_at'         => optional($ent->expires_at)->format('Y-m-d H:i:s'),
            'revoked_at'         => optional($ent->revoked_at)->format('Y-m-d H:i:s'),
            'cancel_reason'      => $cancelReason,
            'timeline'           => $timeline,
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
