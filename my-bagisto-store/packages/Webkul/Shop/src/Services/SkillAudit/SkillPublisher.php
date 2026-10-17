<?php

namespace Webkul\Shop\Services\SkillAudit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\LifecycleNotifier;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;

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
     * 声明为**需落盘载体**（file / motion_data / app_package / vendor_package）的交付物
     * 在盘上取不到（2026-10-16；D-9 起判据从"只认 file"放宽到全部需落盘载体）。
     *
     * 与 `ERR_VERSION_HAS_NO_ASSET` 是**两回事**：那条是"一件交付物都没有"，
     * 这条是"有交付物记录，但东西给不出去" —— 落盘失败 / 对象存储没切好 / 文件被清掉，
     * 或声明了需落盘载体却根本没登记文件路径。
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

    /**
     * 审核通过之后，交付物又变了（任务卡 D-7 · ⑨）—— 拒绝发布。
     *
     * "审的那个包"与"要发的这个包"不是同一份字节流。与 GATE_NOT_PASSED 分开成码，
     * 是因为处置路径完全不同：闸门没过要去**审核台**把审核做掉，而这条要去**商品编辑页**
     * 重新上传 + 重新提审。混成一个码会让运营在审核台里空转 —— 那边显示的是"已通过"，
     * 他找不到任何可做的动作。
     */
    public const ERR_PACKAGE_CHANGED_AFTER_REVIEW = 'PACKAGE_CHANGED_AFTER_REVIEW';

    /**
     * 版本已经是 published —— 旧路径（publish）收到这个请求时的**明确结果**（S-3b）。
     *
     * 病：下架是**商品级**停售（`product.status=0` / `visible_individually=0`），
     * 版本仍是 published。再点「发布」会走到 `SkillVersionState::transition(published → published)`，
     * 被状态机拒绝后在事务里抛 RuntimeException —— 两条通道都是 500。500 既没告诉运营
     * "版本其实没丢"，也没告诉他该走哪个动作。
     *
     * 修法**不是**给状态机加 `published → published`（那会把"重复发布"伪装成一次合法迁移），
     * 而是把这条路径变成可读的结果码，并指到正确的商品级动作：{@see self::relist()}。
     */
    public const ERR_VERSION_ALREADY_PUBLISHED = 'VERSION_ALREADY_PUBLISHED';

    /** 版本尚未发布（草稿/在审/驳回）—— 没有"重新上架"可谈，应该走 publish。 */
    public const ERR_VERSION_NOT_PUBLISHED = 'VERSION_NOT_PUBLISHED';

    /** relist 成功：版本状态保持 published，只把商品重新置为可售。 */
    public const OK_RELISTED = 'RELISTED';

    /** 商品当前已可售 —— relist 的幂等提示（**不是**失败）。 */
    public const ALREADY_SELLABLE = 'ALREADY_SELLABLE';

    /** 熔断生效中 —— 平台强制下架期间禁止复上架（码值与 API 信封的 SKILL_BLOCKED 一致）。 */
    public const ERR_SKILL_BLOCKED = 'SKILL_BLOCKED';

    public function __construct(
        protected ReviewGate $gate,
        protected SkillVersionState $states,
        protected ProductRepository $products,
        protected LifecycleNotifier $notifier,
        protected PackageFingerprint $fingerprint,
        /**
         * 熔断判据的**唯一读取入口**（S-8 收敛在 `KillSwitchService::isBlockedFor`）。
         * 复上架必须问同一处，不能自己查 `skill_blocklist` —— 否则强度阶梯会漂移。
         */
        protected KillSwitchService $killSwitch,
    ) {}

    /**
     * 发布一个版本。
     *
     * 顺序刻意如此：**先闸门，再改状态**。反过来的话，一旦闸门没过，
     * 库里就已经留下一个"看起来发布过"的痕迹。
     *
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array, package_integrity_verified: bool|null, package_integrity_note: string|null}
     */
    public function publish(SkillVersion $version): array
    {
        if ($version->status === SkillVersion::STATUS_ARCHIVED) {
            return $this->result(false, self::ERR_VERSION_ARCHIVED, '已归档的版本不能发布。', $version);
        }

        // S-3b · 已发布版本再走 publish —— 这是"商品被下架后想重新上架"的常见误操作。
        //
        // ⚠ 不允许继续往下走：状态机没有（也**不该有**）`published → published`，
        //   继续走会在 DB::transaction 里抛 RuntimeException → 两条 HTTP 通道都 500。
        //   这里提前返回明确结果，文案直接指向正确的动作（商品级 relist）。
        if ($version->status === SkillVersion::STATUS_PUBLISHED) {
            return $this->result(
                false,
                self::ERR_VERSION_ALREADY_PUBLISHED,
                '该版本已发布（下架只停售商品，不会回退版本状态）。若商品已下架，请走「重新上架」。',
                $version
            );
        }

        $version->loadMissing('assets');

        if ($version->assets->isEmpty()) {
            return $this->result(false, self::ERR_VERSION_HAS_NO_ASSET, '该版本还没有任何交付物,不能发布。', $version);
        }

        // 可交付性 —— "有交付物" ≠ "交付物拿得到"（2026-10-16）。
        //   一个声明为**需落盘**载体、盘上却没有的版本此前照样能发布上架：用户付钱后走鉴权下载
        //   （`API\SkillController::download`）拿到"文件缺失" —— 钱收了、东西给不出去，
        //   是交易事故，不是展示问题。
        //   2026-10-16（D-9）把判据从"只认 file"放宽到"全部需落盘载体"（见 missingAssetFiles）：
        //   声明为 app_package / vendor_package / motion_data 却拿不出文件的版本同样要拦。
        //   位置刻意在"有没有东西"之后、"谁在卖"之前：先看**有没有**，再看**给不给得出**，
        //   最后才看**谁在卖**。反过来的话，未实名 + 文件缺失会报成"实名问题"，运营照那个方向
        //   把实名审过了，再点发布才发现真正的原因是文件没了 —— 白跑一趟。
        // ⚠ 判空必须用 isNotEmpty()：`if ($collection)` 恒为真（PHP 里**任何对象**都 truthy），
        //   用错会把每一个版本都拦下来 —— 本卡第一版就踩了这一下。
        $missing = $this->missingAssetFiles($version);

        if ($missing->isNotEmpty()) {
            // 报错要说清**是哪个交付物的哪种载体、缺的是什么**（"没登记路径" vs "盘上没文件"），
            // 否则运营只会看到"文件缺失"却不知道该补哪一步。
            $detail = $missing
                ->map(function (SkillAsset $asset) {
                    $label = SkillAsset::CARRIER_LABELS[$asset->carrier_type] ?? $asset->carrier_type;
                    $what = $asset->file_path
                        ? '路径 '.$asset->file_path.' 在存储上不存在'
                        : '未登记文件路径（该载体按设计必须落盘）';

                    return '交付物 #'.$asset->id.'（载体 '.$asset->carrier_type.'/'.$label
                        .'，文件 '.($asset->file_name ?: '—').'，'.$what.'）';
                })
                ->implode('；');

            return $this->result(
                false,
                self::ERR_ASSET_FILE_MISSING,
                "该版本声明为需落盘载体的交付物在存储上取不到：{$detail}。请上传/重传对应载体的文件后再发布。",
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

        // 包完整性 —— "**审的那个包，就是发的这个包**"（任务卡 D-7 · ⑨）。
        //
        // 位置刻意在**闸门之后、迁移之前**：
        //   ① 必须在 ReviewGate 之后 —— 先确认"审过了"，再确认"审的和现在的是同一份"。
        //      顺序反了，一个**压根没审过**的版本会先报"包变了"，把运营引去查一个并不存在的
        //      换包问题（真正的原因是他没做审核）。
        //   ② 必须在状态迁移之前 —— 这是一条拒绝理由，拒绝了就不该留下任何"看起来发布过"的痕迹。
        //
        // 为什么这条闸门必要：交付物是**幂等 upsert**（`AssetUploadService::store`），
        // 同一个 `(version, carrier_type, sort_order)` 再传一次就把旧文件覆盖掉了。
        // 于是"提审之后、发布之前"再传一次包，就能让**审核记录看起来仍然有效**，
        // 而实际要交付出去的已经是另一份字节流 —— 此前没有任何记录能发现。
        $integrity = $this->packageIntegrity($version);

        // ⚠ 判据方向见 {@see self::packageIntegrity()}：只有"审过的那份"有值时才比对。
        if ($integrity['changed']) {
            return $this->result(
                false,
                self::ERR_PACKAGE_CHANGED_AFTER_REVIEW,
                '该版本在审核通过之后，交付物发生了变化；请重新提交审核。',
                $version,
                $gate,
                $integrity
            );
        }

        // 过渡口径（任务卡 D-7 · ⑨）：存量已发布/在审版本的历史 audit_record **没有**指纹，
        // 非文件类交付物也没有字节（指纹天然为 NULL）。此时：
        //   · **不**因为"变了"而拒绝 —— 缺失不等于变化，拿"没记"当"换了包"会把存量全打死；
        //   · 也**不**静默放行 —— 返回值里如实带上 `package_integrity_verified = false`
        //     与一句说明，调用方（接口/后台）能看见"这次发布没能校验包完整性"。
        // 这条口径是**过渡**的：等存量版本走完一轮"重新提审→发布"（每条新审核记录都带指纹）
        // 之后，`$reviewed === null` 会退化成"真的一件文件类交付物都没有"，那时才能收紧成一律拒绝。

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

        return $this->result(true, self::OK, "版本 {$version->version} 已发布。", $version->fresh('assets'), $gate, $integrity);
    }

    /**
     * 商品级「重新上架」（S-3b）。
     *
     * 背景：下架是**商品级停售**（`product.status=0` / `visible_individually=0`），
     * 而 `skill_versions.status` 仍是 published。此时对版本再调 publish 无意义
     * （见 {@see self::ERR_VERSION_ALREADY_PUBLISHED}），正确的动作是把**商品**重新置为可售。
     *
     * 三条不可谈判的语义：
     *   · **不碰版本状态机** —— 本方法绝不调 `SkillVersionState::transition()`；
     *     版本保持 published，下架/复售都只影响商品的可售性。
     *   · **要不要重新过审由闸门 + 包指纹自动判定** —— 审核记录仍在、包指纹未变 → 复售；
     *     包已换 → PACKAGE_CHANGED_AFTER_REVIEW，逼回重新提审。没有"跳过校验"的开关。
     *   · **熔断生效中禁止复上架** —— 平台强制下架期间，运营不能凭一次点击把它抬回来。
     *
     * 下架只停新增销售，**不回收已售授权**：本方法不读写 `skill_entitlements`。
     *
     * @return array{ok: bool, code: string, message: string, version: SkillVersion, gate: array, package_integrity_verified: bool|null, package_integrity_note: string|null}
     */
    public function relist(SkillVersion $version): array
    {
        // ① 归档版本没有"重新上架"可说 —— 与 publish 同一个码，处置也一样（先发布可用版本）。
        if ($version->status === SkillVersion::STATUS_ARCHIVED) {
            return $this->result(false, self::ERR_VERSION_ARCHIVED, '已归档的版本不能重新上架；请先发布该版本。', $version);
        }

        // 只有已发布版本才存在"商品被下架后复售"这回事。草稿/在审/驳回应该走「发布」；
        // 若在这里把商品置为可售，会造出"市场里能买、却没有已发布版本可交付"的坏状态。
        if ($version->status !== SkillVersion::STATUS_PUBLISHED) {
            return $this->result(
                false,
                self::ERR_VERSION_NOT_PUBLISHED,
                '该版本尚未发布，不能执行「重新上架」；请先发布该版本。',
                $version
            );
        }

        // ② 幂等：商品已经"市场能搜到"，就没有可做的事 —— 不报错、不改任何状态。
        if ($this->isProductSellable((int) $version->product_id)) {
            return $this->result(true, self::ALREADY_SELLABLE, '该商品当前已在售，无需重复上架。', $version);
        }

        // ③ 平台强制下架（熔断生效中）期间，运营不得把商品重新挂回市场。
        //   判据走 KillSwitchService 的**唯一读入口**（'sale' 门 = 任何生效熔断都拦）。
        if ($this->killSwitch->isBlockedFor((int) $version->product_id, 'sale')) {
            return $this->result(
                false,
                self::ERR_SKILL_BLOCKED,
                '该技能处于平台强制下架（熔断生效）期间，不能重新上架；请等待平台解除熔断。',
                $version
            );
        }

        // ④ 发布闸门 —— 与 publish 是**同一份实现**（ReviewGate::evaluate）。
        //    审核记录仍在且仍通过才放行；否则去审核台把审核做掉。
        $gate = $this->gate->evaluate((int) $version->product_id, (int) $version->id);

        if (! $gate['ok']) {
            return $this->result(
                false,
                self::ERR_GATE_NOT_PASSED,
                '未通过发布闸门：'.$gate['message'],
                $version,
                $gate
            );
        }

        // ⑤ 包指纹 —— 与 publish 是**同一份实现**（packageIntegrity）。
        //    位置在闸门之后：先确认"审过了"，再确认"审的就是现在这份"。
        //    若包已换，不能复售，必须重新提审（审核台此刻显示的是"已通过"，但那对应的是旧包）。
        $integrity = $this->packageIntegrity($version);

        if ($integrity['changed']) {
            return $this->result(
                false,
                self::ERR_PACKAGE_CHANGED_AFTER_REVIEW,
                '该版本在审核通过之后，交付物发生了变化；请重新提交审核后再重新上架。',
                $version,
                $gate,
                $integrity
            );
        }

        // ⑥ 只动商品，**不动版本状态**。makeProductSellable 与 publish 共用同一实现
        //    （ProductRepository::update + 事件三步刷新 product_flat）。
        //    事务包住单品更新：任一步失败都不留"看起来已上架"的半状态。
        DB::transaction(function () use ($version) {
            $this->makeProductSellable($version);
        });

        return $this->result(
            true,
            self::OK_RELISTED,
            "版本 {$version->version} 已重新上架。",
            $version->fresh('assets'),
            $gate,
            $integrity
        );
    }

    /**
     * D-7 · ⑨ 包完整性比对 —— publish 与 relist 共用的**同一份判定**。
     *
     * "审的那个包"取最近一条通过记录上的指纹（{@see self::reviewedFingerprint()}）；
     * "要发的这个包"由 {@see PackageFingerprint::forVersion()} 现算。
     *
     * ⚠ 判据方向：**只有"审过的那份"有值时才比对**。
     *   `$current === null`（现在一个文件类交付物都没有）也是"变了" —— 审的时候有包，
     *   现在没有了，同样是"审的不是发的"，不能因为另一侧为空就放行。
     *   `$reviewed === null` 是过渡口径（历史审核记录没记指纹 / 只有非文件类交付物）：
     *   缺失不等于变化，不拒绝，但 verified=false + note 如实说明"本次没能校验"。
     *
     * @return array{changed: bool, verified: bool, note: string|null, reviewed: string|null, current: string|null}
     */
    protected function packageIntegrity(SkillVersion $version): array
    {
        $reviewed = $this->reviewedFingerprint($version);
        $current = $this->fingerprint->forVersion((int) $version->id);

        $changed = $reviewed !== null && ($current === null || ! hash_equals($reviewed, $current));

        return [
            'changed'  => $changed,
            'verified' => $reviewed !== null && ! $changed,
            'note'     => $reviewed === null
                ? '本次发布未能校验包完整性：该版本没有可比的包指纹'
                    .'（历史审核记录未记录指纹，或该版本只有非文件类交付物）。'
                : null,
            'reviewed' => $reviewed,
            'current'  => $current,
        ];
    }

    /**
     * "审核通过时看到的那份包"的指纹 —— 取**最近一条 L2（人审）通过记录**上的值。
     *
     * 为什么以人审为准：L2 是人工对"这一份包"下的结论，是判据的权威来源。
     *   （`required_layers` 是策略，默认 [1, 3]；若配置把 L2 拿掉，退一步取闸门内
     *   其它层最近一条通过记录 —— 有记录总比没有强。两层都拿不到才走"无指纹"过渡口径。）
     *
     * ⚠ 返回 `null` 有两种含义，调用方**必须**都按"无指纹可比"处理、不得当成"变了"：
     *   · 没有通过记录（配置未把任何带指纹的层纳入闸门）；
     *   · 有记录但当时没有指纹（存量历史数据 / 该版本只有非文件类交付物）。
     */
    protected function reviewedFingerprint(SkillVersion $version): ?string
    {
        $passed = fn () => AuditRecord::query()
            ->forVersion((int) $version->product_id, (int) $version->id)
            ->where('result', AuditRecord::RESULT_PASS);

        $record = $passed()->where('layer', AuditRecord::LAYER_HUMAN)->orderByDesc('id')->first();

        if (! $record) {
            $record = $passed()->whereIn('layer', $this->gate->requiredLayers())->orderByDesc('id')->first();
        }

        return $record?->package_fingerprint;
    }

    /**
     * 声明为**需落盘**载体、但存储上取不到的那些交付物（发布前置用）。
     *
     * ⚠ 判据用 `SkillAsset::isFileBacked()` / `fileExists()` —— D-9（2026-10-16）从
     *   `isFile()` 放宽：原先只认 `carrier_type === 'file'`，于是声明为
     *   `app_package` / `vendor_package` / `motion_data` 的交付物**完全不查在盘性** ——
     *   一个"只声明 app_package 且盘上没文件"的版本能过"有交付物"+"可交付性"两关被上架，
     *   买家付钱后下载拿不到东西（钱收了、货给不出）。
     *   放宽后这三类同样必须落盘；`file` 类语义**一字未变**（`isFileBacked()` 对 file 恒真）。
     *
     * 不落盘载体（cloud_config / remote_service / api_bundle）**不参与**本判定：
     *   它们按设计就不落盘，只有 payload（见 `AssetUploadService` 的非文件分支）——
     *   把它们也算进来会把本来正常的版本全部拦死。
     *
     * ⚠ 与包指纹/下载端点的分工：指纹（`PackageFingerprint`）与 C 端鉴权下载仍用
     *   `SkillAsset::isFile()`（只认 file）—— 那是"审的就是发的"这条判据，不能掺进
     *   没有字节的载体。本方法管的是另一件事："声明了要落盘的东西，盘上到底有没有"。
     *
     * @return Collection<int, SkillAsset>
     */
    protected function missingAssetFiles(SkillVersion $version): Collection
    {
        return $version->assets
            ->filter(fn (SkillAsset $asset) => $asset->isFileBacked() && ! $asset->fileExists())
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
     * 商品当前是否**可售**（市场能搜到）—— relist 的幂等判据。
     *
     * 判据与市场搜索同源：`product_flat` 里同一 channel/locale 行
     * `status=1 && visible_individually=1`（见 `SkillCatalogController::search`）。
     * 用 flat 而不是 EAV：flat 才是搜索真正读的表 —— 拿 EAV 判断可能出现
     * "库里说可售、市场里搜不到"的自相矛盾（flat 索引还没刷出来）。
     *
     * 与 `makeProductSellable()` 写入的是**同一组** channel/locale（见 defaultLocaleCode）。
     */
    public function isProductSellable(int $productId): bool
    {
        $row = DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('channel', core()->getDefaultChannelCode())
            ->where('locale', $this->defaultLocaleCode())
            ->first(['status', 'visible_individually']);

        return $row && (int) $row->status === 1 && (int) $row->visible_individually === 1;
    }

    /** 商品更新/可售判据统一使用的 locale（默认渠道的默认语言）。 */
    protected function defaultLocaleCode(): string
    {
        return (string) (optional(core()->getDefaultChannel()->default_locale)->code
            ?: core()->getDefaultChannelCode());
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
            'locale'               => $this->defaultLocaleCode(),
            'categories'           => $categories,
        ], $productId);

        Event::dispatch('catalog.product.update.after', $product->fresh());
    }

    /**
     * @param  array{verified: bool, note: string|null}|null  $integrity  包完整性校验结论（D-7 · ⑨）
     * @return array{
     *     ok: bool, code: string, message: string, version: SkillVersion, gate: array,
     *     package_integrity_verified: bool|null, package_integrity_note: string|null
     * }
     */
    protected function result(
        bool $ok,
        string $code,
        string $message,
        SkillVersion $version,
        ?array $gate = null,
        ?array $integrity = null,
    ): array {
        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $message,
            'version' => $version,
            'gate'    => $gate ?? ['ok' => $ok, 'layers' => [], 'unmet' => [], 'message' => ''],
            // 契约**只加不改**：既有键一个都不动，这里补两个新键。
            //   verified: true  = 已用指纹校验且"审的就是发的"
            //   verified: false = 走到这一步了但这版没有可比指纹（**过渡口径**，见 publish 注释）
            //   verified: null  = 本次根本没走到校验那一步（如闸门没过）
            'package_integrity_verified' => $integrity['verified'] ?? null,
            'package_integrity_note'     => $integrity['note'] ?? null,
        ];
    }
}
