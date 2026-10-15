<?php

namespace Webkul\Shop\Services\SkillAudit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\LifecycleNotifier;

/**
 * 版本发布的**唯一入口** —— 2026-10-16 收敛。
 *
 * 为什么必须唯一：发布有两条通道 ——
 *   ① 运营后台 `Admin\Catalog\SkillController::publish()`
 *   ② internal.key 的 API `API\SkillAdminController::publish()`
 * 两者原先各写一份逻辑，结果**只有后台那份带发布闸门**：没过审的技能可以从 API 通道
 * **静默上架**。而 `Admin\Catalog\SkillController` 的类注释当时写着"两者写的是同一批表和
 * 同一套校验，不存在两套真相" —— 那句话在闸门这件事上是不成立的。文档里的断言不等于代码里的断言。
 *
 * 本类**不提供任何"跳过闸门"的开关**：想发布，就把审核做掉。
 * 新增发布通道时必须调本类，不得复制这段逻辑。
 */
class SkillPublisher
{
    /** 发布成功 */
    public const OK = 'PUBLISHED';

    public const ERR_VERSION_ARCHIVED = 'VERSION_ARCHIVED';
    public const ERR_VERSION_HAS_NO_ASSET = 'VERSION_HAS_NO_ASSET';
    public const ERR_GATE_NOT_PASSED = 'GATE_NOT_PASSED';

    /**
     * 声明为文件类载体的交付物在盘上取不到（2026-10-16）。
     *
     * 与 `ERR_VERSION_HAS_NO_ASSET` 是**两回事**：那条是"一件交付物都没有"，
     * 这条是"有交付物记录，但东西给不出去" —— 落盘失败 / 对象存储没切好 / 文件被清掉。
     * 拆成两个码的理由与 OWNER_* 那组一致：运营据此决定去哪修（补交付物 vs 重新上传文件），
     * 混成一个码会把排查引到错的地方。
     */
    public const ERR_ASSET_FILE_MISSING = 'ASSET_FILE_MISSING';

    /**
     * 主体资格不达标（任务卡 D-5 · 裁决 Q6）—— 与"闸门没过"是**两回事**，故各自成码：
     *   OWNER_NOT_VERIFIED    开发者主体实名未通过审核
     *   AGREEMENT_REQUIRED    开发者协议未签全
     *   OWNER_PROFILE_MISSING seller_id 有值却查不到开发者主体（归属异常）
     * ⚠ 调用方**不要**把它们兜成 GATE_NOT_PASSED：那会让运营去审核台找一个根本不存在的审核问题。
     */
    public const ERR_OWNER_NOT_VERIFIED = 'OWNER_NOT_VERIFIED';
    public const ERR_AGREEMENT_REQUIRED = 'AGREEMENT_REQUIRED';
    public const ERR_OWNER_PROFILE_MISSING = 'OWNER_PROFILE_MISSING';

    public function __construct(
        protected ReviewGate $gate,
        protected SkillVersionState $states,
        protected ProductRepository $products,
        protected LifecycleNotifier $notifier,
    ) {}

    /**
     * 发布一个版本。
     *
     * 顺序刻意如此：**先闸门，再改状态**。反过来的话，一旦闸门没过，
     * 库里就已经留下一个"看起来发布过"的痕迹。
     *
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array}
     */
    public function publish(SkillVersion $version): array
    {
        if ($version->status === SkillVersion::STATUS_ARCHIVED) {
            return $this->result(false, self::ERR_VERSION_ARCHIVED, '已归档的版本不能发布。', $version);
        }

        $version->loadMissing('assets');

        if ($version->assets->isEmpty()) {
            return $this->result(false, self::ERR_VERSION_HAS_NO_ASSET, '该版本还没有任何交付物,不能发布。', $version);
        }

        // 可交付性 —— "有交付物" ≠ "交付物拿得到"（2026-10-16）。
        //   一个 `carrier_type=file` 却不在盘上的版本此前照样能发布上架：用户付钱后走鉴权下载
        //   （`API\SkillController::download` 用的就是这一对判据）拿到"文件缺失" ——
        //   钱收了、东西给不出去，是交易事故，不是展示问题。
        //   位置刻意在"有没有东西"之后、"谁在卖"之前：先看**有没有**，再看**给不给得出**，
        //   最后才看**谁在卖**。反过来的话，未实名 + 文件缺失会报成"实名问题"，运营照那个方向
        //   把实名审过了，再点发布才发现真正的原因是文件没了 —— 白跑一趟。
        // ⚠ 判空必须用 isNotEmpty()：`if ($collection)` 恒为真（PHP 里**任何对象**都 truthy），
        //   用错会把每一个版本都拦下来 —— 本卡第一版就踩了这一下。
        $missing = $this->missingAssetFiles($version);

        if ($missing->isNotEmpty()) {
            $detail = $missing
                ->map(fn (SkillAsset $asset) => '交付物 #'.$asset->id.'（'
                    .($asset->file_name ?: basename((string) $asset->file_path)).'，路径 '
                    .($asset->file_path ?: '未记录').'）')
                ->implode('；');

            return $this->result(
                false,
                self::ERR_ASSET_FILE_MISSING,
                "该版本声明为文件的交付物在存储上不存在：{$detail}。请重新上传该交付物后再发布。",
                $version
            );
        }

        // 主体资格 —— 未实名 / 协议未签全的主体**不得上架**（任务卡 D-5 · 裁决 Q6）。
        // 位置刻意在**发布闸门之前**：这是"谁在卖"的问题，比"东西过没过审"更靠前 ——
        // 主体不合格时连"能不能发布"都不必谈。提审不拦（鼓励供给），只在这里拦。
        $qualification = $this->ownerQualification($version);

        if (! $qualification['ok']) {
            // ⚠ code 用 ownerQualification 自己的码，**不能**改写成 GATE_NOT_PASSED ——
            //   调用方按码分支决定文案与跳转，混码会把"开发者没实名"误报成"审核没过"。
            return $this->result(false, $qualification['code'], $qualification['message'], $version);
        }

        // 发布闸门 —— 未过审一律不放行（平台自营的技能也一样）
        $gate = $this->gate->evaluate($version->product_id, $version->id);

        if (! $gate['ok']) {
            return $this->result(
                false,
                self::ERR_GATE_NOT_PASSED,
                '未通过发布闸门：'.$gate['message'],
                $version,
                $gate
            );
        }

        DB::transaction(function () use ($version) {
            // 归档同商品其它 published 版本 —— 保留**批量** update，刻意不逐条走 transition()：
            // 这是一次性收敛（"每个商品同时只留一个 published"），逐条 load→transition 会
            // 退化成 N 次写库，而它们的迁移规则完全一致（published → archived），无需逐条判定。
            // 它绕过唯一入口，但触发点唯一（只有 SkillPublisher）且值域封闭（是白名单里已有的迁移），
            // 属受控例外；真要统一时，改成先批量取 id 再逐个 transition 即可。
            SkillVersion::query()
                ->where('product_id', $version->product_id)
                ->where('id', '!=', $version->id)
                ->where('status', SkillVersion::STATUS_PUBLISHED)
                ->update(['status' => SkillVersion::STATUS_ARCHIVED]);

            // ⚠ 顺序硬要求：**先闸门、后改状态**。反过来会让"draft + 无审核记录"返回
            //   TRANSITION_INVALID 而不是 GATE_NOT_PASSED，语义就错了（闸门在上面的 gate 块里判）。
            // 状态写入收敛到 SkillVersionState（唯一入口）；先设 released_at，让 transition
            // 的那一次 save() 把 status 与 released_at 一并落库（一次写）。
            $version->released_at = now();

            $transition = $this->states->transition($version, SkillVersion::STATUS_PUBLISHED);

            if (! $transition['ok']) {
                // 不可达：能到这里说明闸门已过，版本必然已被 submitLayer0 落到 in_review。
                // 真出现了就是状态机漏了路径 —— 宁可炸（回滚整个事务）也不谎报"已发布"。
                throw new \RuntimeException(
                    "发布状态迁移失败：版本 #{$version->id} 未能迁到 published"
                    ."（{$transition['code']} {$transition['message']}）"
                );
            }

            // 版本 published ≠ 用户看得见。技能市场搜的是 `product_flat` 里
            // `status=1 && visible_individually=1` 的商品行（见 SkillCatalogController::search）；
            // 而技能商品建出来就是 `status=0`（开发者不能自己上架）。所以**发布必须把商品一并置为可售**，
            // 否则"发布"是个空动作：版本是 published，市场却搜不到。
            //
            // 顺序硬要求：**先版本迁移、再置商品可售**（都在同一事务里，任一步失败整体回滚，
            // 绝不留下"版本 published 但商品禁用"的半状态）。
            $this->makeProductSellable($version);
        });

        // 通知开发者（任务卡 D-4）——**放在事务之外**：事务提交后才是"真的上架了"这个事实，
        // 放事务里会发出"通知已发、事务却回滚"的假消息。通知是副作用，LifecycleNotifier
        // 内部 safe() 吞异常，发不出去也不该让已经成功的发布变成失败。
        $this->notifier->skillPublished((int) $version->product_id, (int) $version->id, (string) $version->version);

        return $this->result(true, self::OK, "版本 {$version->version} 已发布。", $version->fresh('assets'), $gate);
    }

    /**
     * 声明为文件类载体、但存储上取不到的那些交付物（发布前置用）。
     *
     * ⚠ 判据一律用 `SkillAsset::isFile()` / `fileExists()` —— C 端鉴权下载
     *   （`API\SkillController::download`）判的就是这一对。平台侧若另写一套，
     *   两套判据迟早对不上，而对不上的那次就是"发布放行了、用户下不到"。
     *
     * 非文件类载体（cloud_config / remote_service / api_bundle）**不参与**本判定：
     *   它们按设计就不落盘，只有 payload（见 `AssetUploadService` 的非文件分支）——
     *   把它们也算进来会把本来正常的版本全部拦死。
     *
     * @return Collection<int, SkillAsset>
     */
    protected function missingAssetFiles(SkillVersion $version): Collection
    {
        return $version->assets
            ->filter(fn (SkillAsset $asset) => $asset->isFile() && ! $asset->fileExists())
            ->values();
    }

    /**
     * 主体资格 —— "这个版本的归属主体够不够格上架"（任务卡 D-5 · 裁决 Q6）。
     *
     * 判定顺序：**归属 → 协议 → 实名**。
     *   - seller_id 为 NULL → 平台自营，跳过（见下方"为什么自营不受限"）；
     *   - seller_id 有值却查不到 DeveloperProfile → **拒绝**（fail-closed，不留 published 半状态）；
     *   - 找到主体但协议未签全 → `ERR_AGREEMENT_REQUIRED`；
     *   - 找到主体但实名未通过 → `ERR_OWNER_NOT_VERIFIED`。
     *
     * 为什么是 public 而不是 protected：开发者门户要在技能详情页**事前**把这道前置显示出来
     * （"还差什么才能上架"）。复用的前提是"只能有一处实现"——门户若自写一份判定，迟早与平台
     * 真正在执行的那份漂移（`ReviewGate` 的类注释讲的就是这个病）。所以让门户直接调这一处，
     * 而不是在旁边复制一段。
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function ownerQualification(SkillVersion $version): array
    {
        // 平台自营不受限：实名与协议约束的是**第三方开发者主体**（DeveloperProfile 挂在 customers 上），
        // 而自营技能的供给方就是平台自己 —— 平台对自己的资质负责，不存在"开发者未实名"这回事。
        // 若把 NULL 也拖去查 profile：要么查不到而误拒全部自营技能、要么被当成"未实名"挡下，
        // 两种结果都会把 M1/M2 的自营供给线整条掐死。
        if (is_null($version->seller_id)) {
            return ['ok' => true, 'code' => '', 'message' => '平台自营，不受开发者主体资格限制。'];
        }

        // ⚠ fail-closed：seller_id **有值**却查不到 profile，绝不能当成"自营"放行。
        //   本仓踩过 `->where('seller_id', null)` 在 Eloquent 里退化成 `where seller_id is null`
        //   的坑 —— 那种写法会把"任意开发者"错查成"平台自营"。这里先判过 NULL，再按**非空值**等值查；
        //   查不到就是归属异常（主体被删 / 数据被破坏），宁可挡住也不放过。
        $profile = DeveloperProfile::where('seller_id', $version->seller_id)->first();

        if (! $profile) {
            return [
                'ok'      => false,
                'code'    => self::ERR_OWNER_PROFILE_MISSING,
                'message' => '该技能的主体归属异常（找不到对应的开发者主体），不能上架；请联系平台核查。',
            ];
        }

        if (! $profile->hasSignedAllAgreements()) {
            return [
                'ok'      => false,
                'code'    => self::ERR_AGREEMENT_REQUIRED,
                'message' => '开发者协议尚未签署完整，不能上架；请先到「协议签署」页补齐。',
            ];
        }

        if ($profile->realname_status !== DeveloperProfile::REALNAME_APPROVED) {
            return [
                'ok'      => false,
                'code'    => self::ERR_OWNER_NOT_VERIFIED,
                'message' => '开发者主体实名未通过审核，不能上架；请等待平台审核。',
            ];
        }

        return ['ok' => true, 'code' => '', 'message' => '主体资格符合上架要求。'];
    }

    /**
     * 把该版本所属的商品置为**可售**（status=1 + visible_individually=1）。
     *
     * 走 Bagisto 既有的商品更新通道（`ProductRepository::update()`）+ 事件三步，
     * **不直写 `product_flat`** —— flat 是派生表，绕过事件会留下不一致；
     * 写法与 `DeveloperSkillService::createSkill()/updateSkill()` 一致。
     *
     * 必须在**版本迁移成功之后**调用（顺序见 publish）。商品不存在时抛异常、回滚整个发布事务，
     * 绝不留下一个"版本已发布、市场里却没有这个商品"的假发布。
     */
    protected function makeProductSellable(SkillVersion $version): void
    {
        $productId = (int) $version->product_id;
        $product = $this->products->find($productId);

        if (! $product) {
            // 现实中版本必挂在商品下；指到不存在的商品 = 数据被破坏。
            throw new \RuntimeException(
                "发布失败：版本 #{$version->id} 指向的商品 #{$productId} 不存在，无法置为可售。"
            );
        }

        // ⚠ 必须带上商品**当前**的分类：`AbstractType::update` 在 `categories` 缺失时会把商品
        //   从所有分类里 sync 掉（见 DeveloperSkillService::updateSkill 的注释）。技能一旦脱离
        //   「技能」分类，市场搜索（按该分类过滤，见 SkillCatalogController::search）就再也搜不到
        //   它 —— 那正是本次要修的病，不能在修它的时候又把它踩回去。
        $categories = $product->categories->pluck('id')->all();

        // 事件三步一步都不能省：`product_flat` 是 `catalog.product.update.after` 里
        // flatIndexer 刷出来的，不派发就只改了 EAV，搜索读的 flat 仍是旧值（status=0）。
        Event::dispatch('catalog.product.update.before', $productId);

        $this->products->update([
            'status'               => 1,
            'visible_individually' => 1,
            'channel'              => core()->getDefaultChannelCode(),
            'locale'               => (string) (optional(core()->getDefaultChannel()->default_locale)->code
                ?: core()->getDefaultChannelCode()),
            'categories'           => $categories,
        ], $productId);

        Event::dispatch('catalog.product.update.after', $product->fresh());
    }

    /**
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array}
     */
    protected function result(bool $ok, string $code, string $message, SkillVersion $version, ?array $gate = null): array
    {
        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $message,
            'version' => $version,
            'gate'    => $gate ?? ['ok' => $ok, 'layers' => [], 'unmet' => [], 'message' => ''],
        ];
    }
}
