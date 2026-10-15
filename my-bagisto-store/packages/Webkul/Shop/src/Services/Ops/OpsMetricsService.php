<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Shop\Models\DeviceBinding;

/**
 * 平台运营指标 —— **只读聚合**。
 *
 * 这个类最重要的不是查询，而是**每个指标都声明口径与数据源就绪状态**。
 * 一个运营看板最容易犯的错，是把"没有数据"显示成"0" ——
 * 前者是"这个维度还没接入"，后者是"这个维度接入了但是零"。
 * 两者对决策的含义完全相反，长得却一模一样。
 *
 * 所以每条指标必须回三样东西：
 *   value      —— 数值（或 null，表示算不出来）
 *   basis      —— 口径（这个数是怎么算的）
 *   ready      —— 数据源是否已接入。false 时界面显示「未接入」而**不是 0**
 *
 * ⚠ 已被核验否定、**故意不做**的维度（做了只会永远是 0，属于假指标）：
 *   - 曝光量 / 浏览量：`visits` 依赖未开启的访客统计，且队列无 worker
 *   - 净收入 / 已收款、结算、提现、对账：真实收款依赖微信支付资质（E1），
 *     当前支付/退款流水来自 mock 网关（`payment_transaction` 有记录，但没有真实资金进出）
 *   这些在页面上以「未接入」显式列出，**不是**显示 0。
 *
 * ⚠ 「发票」曾经也可以列在上面（表建了、没人写）—— **M9 起不能了**：
 *   支付成功会真的落一条开票登记记录（`Services/Billing/InvoiceService`），
 *   所以它现在是 `trade()` 里的真实指标。**"统计了的东西不许说成没统计"** ——
 *   这与下面「下载量」那段是同一枚硬币的两面。
 *
 * ⚠ 反过来的错也犯过一次：**「下载量」曾被列在上面**，理由是"`skill_search_index`
 *   是孤儿物化表，做出来永远是 0"。但下载另有计数（`entitlements.meta.download_count`，
 *   见 `downloadCount()`）—— 平台在说"我没统计下载"，而它统计了。
 *   **"把统计了的说成没统计"和"把没统计的显示成 0"一样会给决策者假事实**，
 *   只是前者更隐蔽：没人会去怀疑一句"我们没统计"。现已改成真实指标。
 */
class OpsMetricsService
{
    /**
     * 技能类商品 id 集合（与 Admin「技能管理」取分类的方式一致）。
     */
    protected function skillCategoryId(): ?int
    {
        return Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'skill'))
            ->value('id');
    }

    /**
     * @return int[]
     */
    protected function skillProductIds(): array
    {
        $categoryId = $this->skillCategoryId();

        if (! $categoryId) {
            return [];
        }

        return DB::table('product_categories')
            ->where('category_id', $categoryId)
            ->pluck('product_id')
            ->all();
    }

    /**
     * 交易（**下单口径**）。
     *
     * ⚠ 口径是"已下单"，不是"已收款"。本项目没有真实支付（WechatGateway 是占位），
     *   把下单金额说成收入是**虚假陈述**。界面上必须写清这一点。
     */
    public function trade(): array
    {
        $skillProductIds = $this->skillProductIds();

        $allItems = DB::table('order_items')->count();

        $skillItems = $skillProductIds === [] ? collect() : DB::table('order_items')
            ->whereIn('product_id', $skillProductIds)
            ->get();

        return [
            'orders_total'   => $this->metric(DB::table('orders')->count(), '全部订单数（含非技能商品）', true),
            'order_items'    => $this->metric($allItems, '全部订单行数', true),
            'skill_items'    => $this->metric($skillItems->count(), '技能类商品的订单行数', true),
            'skill_amount'   => $this->metric(
                round((float) $skillItems->sum('total'), 2),
                '技能订单金额（「下单口径」，非已收款 —— 本项目无真实支付）',
                true
            ),
            'paid_amount'    => $this->metric(null, '已收款金额（真实收款依赖微信支付资质 E1；当前支付流水来自 mock 网关，不是真实资金）', false),
            'invoices'       => $this->metric(
                DB::table('invoice')->count(),
                '开票登记条数（支付成功后自动登记；「真实开具」未接入开票服务商，见发票台账）',
                true
            ),
        ];
    }

    /**
     * 技能供给。
     */
    public function supply(): array
    {
        $versions = DB::table('skill_versions')
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'products'   => $this->metric(DB::table('products')->whereIn('id', $this->skillProductIds() ?: [0])->count(), '技能类商品数', true),
            'versions'   => $this->metric(DB::table('skill_versions')->count(), '技能版本总数', true),
            'draft'      => $this->metric((int) ($versions['draft'] ?? 0), '草稿版本（未发布）', true),
            'published'  => $this->metric((int) ($versions['published'] ?? 0), '已发布版本', true),
            'assets'     => $this->metric(DB::table('skill_assets')->count(), '交付物数量', true),
            'categories' => $this->metric(DB::table('skill_category')->count(), '技能类目', true),
            'tags'       => $this->metric(DB::table('skill_tag')->count(), '技能标签', true),
        ];
    }

    /**
     * 审核与内容安全。
     */
    public function review(): array
    {
        $pendingL2 = $this->countPendingHumanReview();

        return [
            'audit_records' => $this->metric(DB::table('audit_record')->count(), '三层审核记录总数', true),
            'pending_l2'    => $this->metric($pendingL2, '已过 L0、尚未做 L2 人审的版本', true),
            'content_block' => $this->metric(
                DB::table('content_security_record')->where('result', 'block')->count(),
                '内容安全命中拦截', true
            ),
            'content_todo'  => $this->metric(
                DB::table('content_security_record')
                    ->where('result', '!=', 'pass')
                    ->where('disposition', 'pending')
                    ->count(),
                '内容安全待人工处置', true
            ),
        ];
    }

    /**
     * 设备与机型。
     */
    public function devices(): array
    {
        return [
            'devices'  => $this->metric(DB::table('devices')->count(), '用户登记的设备数', true),
            'bindings' => $this->metric(DB::table('device_binding')->count(), '设备绑定（真相源）条数', true),
            'models'   => $this->metric(DB::table('device_model')->count(), '机型库条目', true),
            'agent_online' => $this->metric(
                // ⚠ 在线是**读时判断**（最近心跳在 DeviceBinding::ONLINE_WINDOW_SECONDS 内），
                //   不是读 online_status 粘滞列 —— 那一列只写过 true，从没写过 false，
                //   拿它统计会得到"曾经连上过"的设备总数，并且在页面上被读成"在线"。
                //   见 DeviceBinding 的类注释。
                DeviceBinding::query()->onlineNow()->count(),
                'agent 在线设备数（最近 '.DeviceBinding::ONLINE_WINDOW_SECONDS.' 秒内有心跳；真机 Agent 未接入，本机数据来自模拟）',
                true
            ),
        ];
    }

    /**
     * 授权。
     */
    public function entitlements(): array
    {
        $byStatus = DB::table('skill_entitlements')
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total'  => $this->metric(DB::table('skill_entitlements')->count(), '授权总数', true),
            'active' => $this->metric((int) ($byStatus['active'] ?? 0), '有效授权', true),
            'revoked' => $this->metric((int) ($byStatus['revoked'] ?? 0), '已撤销授权', true),
            'installs' => $this->metric(DB::table('install_record')->count(), '安装记录（「人工通道由用户自报」，真机 Agent 未接入）', true),
            'downloads' => $this->metric(
                $this->downloadCount(),
                '已下载次数（App 内鉴权下载计数；「仅覆盖 App/API 通道」，人工送达不计数）',
                true
            ),
        ];
    }

    /**
     * 累计下载次数。
     *
     * ⚠ 这里曾经是错的 —— 而且错的方向和本项目一直警惕的那一类**正好相反**：
     *   运营总览的「未接入」清单里写着"下载量：skill_search_index 是孤儿物化表，全仓无写入方
     *   —— 做出来永远是 0"。前半句是真的（那张物化表确实没人写），
     *   **后半句是假的**：`SkillController::download()` 每完成一次鉴权下载，都会把
     *   `skill_entitlements.meta.download_count` 加一。
     *
     *   也就是说平台在说"我没统计下载"，而它统计了。后果和"没统计显示成 0"一样坏，
     *   只是方向相反：有人会为此立项去补一个根本不需要补的埋点，
     *   而开发者问"我的技能被下载过几次"时，得到的回答是"平台没统计"。
     *
     *   **真正的边界**（这才是该写进口径的那句话）：只覆盖 App/API 的鉴权下载，
     *   人工通道送达没有计数。
     */
    protected function downloadCount(?int $sellerId = null): int
    {
        $query = DB::table('skill_entitlements')->whereNotNull('meta');

        if ($sellerId !== null) {
            $query->where('seller_id', $sellerId);
        }

        return (int) $query
            ->selectRaw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.download_count')) AS UNSIGNED)), 0) AS c")
            ->value('c');
    }

    /**
     * 单个开发者视角的指标 —— 给开发者门户用。
     *
     * ⚠ **参数是非空 `int`，这是刻意的，不要"顺手"改成 `?int $sellerId = null`。**
     *   本仓库已经踩过同型退化两次：
     *     - `DeveloperSkillService` 的注释：「`where('seller_id', null)` 在 Laravel 里会退化成
     *       `is null`，那就正好匹配到**平台自营**商品 —— 是越权，不是空结果」
     *     - `Admin\Customers\DeveloperController` 统计技能数时按 `$profile->seller_id` 过滤，
     *       而该列可空 —— seller 为 null 时统计的是全平台自营商品数
     *   若把"平台口径"也用 null 表达，"这个开发者没有归属主体"就会被**静默解释成
     *   "给他看平台全站数字"**。用非空 int 让那个状态在类型层面不可表达。
     *
     * ⚠ **这里只有"有直挂 seller 列"的指标。**没有直挂列的维度（设备、内容安全、工单、事故）
     *   不在这个列表里 —— 不是标成"不适用"，而是**根本不出现在返回结果中**。
     *   列表里出现的每一条，都是"对这个读者真的算得出来、且算的是他自己的"。
     *   要展示"哪些暂时给不了"，用 `notAvailableForSeller()`（开发者语域）。
     *
     * @return array<string, array{value: int|float|null, basis: string, ready: bool}>
     */
    public function forSeller(int $sellerId): array
    {
        $versions = DB::table('skill_versions')
            ->where('seller_id', $sellerId)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // 审核记录挂在 audit_record.skill_id 上，而它存的是 **products.id**
        // （列名与内容不一致，见 AuditRecord::scopeForVersion 的注释）
        $productIds = DB::table('products')->where('seller_id', $sellerId)->pluck('id')->all();

        $entByStatus = DB::table('skill_entitlements')
            ->where('seller_id', $sellerId)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'products' => $this->metric(
                DB::table('products')->where('seller_id', $sellerId)->count(),
                '你名下的技能商品数', true
            ),
            'versions' => $this->metric(
                DB::table('skill_versions')->where('seller_id', $sellerId)->count(),
                '你名下的技能版本数', true
            ),
            'published' => $this->metric(
                (int) ($versions['published'] ?? 0),
                '已发布的版本数（用户能拿到的只有这些）', true
            ),
            'assets' => $this->metric(
                DB::table('skill_assets')->where('seller_id', $sellerId)->count(),
                '你上传的交付物文件数', true
            ),
            'entitlements' => $this->metric(
                DB::table('skill_entitlements')->where('seller_id', $sellerId)->count(),
                '你的技能被授予的授权数（用户下单后自动发放）', true
            ),
            'entitlements_active' => $this->metric(
                (int) ($entByStatus['active'] ?? 0),
                '其中还有效的授权数', true
            ),
            'downloads' => $this->metric(
                $this->downloadCount($sellerId),
                '你的技能被下载的次数（「只统计 App/API 的鉴权下载」，人工送达不计）', true
            ),
            'audits' => $this->metric(
                $productIds === [] ? 0 : DB::table('audit_record')->whereIn('skill_id', $productIds)->count(),
                '你的技能累计产生的审核记录条数', true
            ),
            'pending_review' => $this->metric(
                $this->countPendingHumanReview($sellerId),
                '已提交、还在等人审的版本数（平台运营在审）', true
            ),
        ];
    }

    /**
     * 开发者门户里「暂时给不了的数据」—— **用开发者能懂的话说，不是平台运维行话**。
     *
     * 与 `notConnected()` 分开写成两份，是因为读者不同：那边写给运营（"E1""invoices 恒 0"
     * 是行话，运营看得懂），这边写给技能作者（他只关心"我能不能知道有多少人在用"）。
     * 同一份文案两边复用，等于对其中一边没说人话。
     *
     * ⚠ 顺序即渲染顺序：**这一块要排在页面上任何数字之前**。
     *   一个开发者打开看板，第一眼看到一串 0，会得出"我的技能没人用"；
     *   而真相可能是"平台压根没在统计这一项"。两者结论相反，长得一样。
     *
     * @return array<int, array{name: string, reason: string}>
     */
    public function notAvailableForSeller(): array
    {
        return [
            [
                'name'   => '安装量 / 真机下发情况',
                'reason' => '平台还没有接入真机 Agent，现在的安装记录只能由用户手工自报，也没有可靠的失败原因回传。所以这一项我们没有数字可给 —— 不是"安装为 0"。',
            ],
            [
                'name'   => '收入 / 结算',
                'reason' => '平台尚未开通收款通道，也没有结算与分成规则。你的技能被下单不等于你会收到款，具体结算方式要等支付资质到位后才能定。',
            ],
            [
                'name'   => '曝光量 / 浏览量',
                'reason' => '平台没有开启访客统计，无法告诉你"多少人看过但没买"。',
            ],
            [
                'name'   => '用户评价 / 口碑',
                'reason' => '评价功能「还没有对外开放」 —— 平台目前没有让用户提交评价的入口。所以这一项不是"还没有人评价"，而是"还收不到评价"，两者不是一回事。',
            ],
        ];
    }

    /**
     * 客服与安全。
     */
    public function service(): array
    {
        $openTickets = DB::table('support_ticket')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $overdueTickets = DB::table('support_ticket')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->count();

        $openIncidents = DB::table('incident')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $severeIncidents = DB::table('incident')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereIn('severity', ['severe', 'critical'])
            ->count();

        return [
            'tickets_open'    => $this->metric($openTickets, '未结工单', true),
            'tickets_overdue' => $this->metric($overdueTickets, '超 SLA 且未结的工单', true),
            'incidents_open'  => $this->metric($openIncidents, '未结案事故', true),
            'incidents_severe' => $this->metric($severeIncidents, '未结案的严重/重大事故（应急响应触发线）', true),
            'alerts_unread'   => $this->metric(
                DB::table('notification')->where('recipient_type', 'admin')->whereNull('read_at')->count(),
                '平台告警未读数（全员合计）', true
            ),
        ];
    }

    /**
     * 开发者。
     */
    public function developers(): array
    {
        return [
            'total'   => $this->metric(DB::table('developer_profile')->count(), '开发者主体数', true),
            'pending' => $this->metric(
                DB::table('developer_profile')->where('realname_status', 'pending')->count(),
                '待平台审核的开发者', true
            ),
            'skills'  => $this->metric(
                DB::table('products')->whereNotNull('seller_id')->count(),
                '归属于开发者的技能商品数（seller_id 非空）', true
            ),
        ];
    }

    /**
     * 合规。
     */
    public function compliance(): array
    {
        return [
            'filings'  => $this->metric(DB::table('compliance_filing')->count(), '备案台账条目', true),
            'filed'    => $this->metric(
                DB::table('compliance_filing')->where('status', 'filed')->count(),
                '已备案条目', true
            ),
            'filings_ready' => $this->metric(
                null,
                '备案审批结果（依赖外部审批，见「剩余闭环清单」E1–E3）', false
            ),
        ];
    }

    /**
     * 本页**显式标注为未接入**的维度。
     *
     * 列出来而不是藏起来：藏起来的话，看板上"没有这一项"与"这一项是 0"分不清；
     * 而运营需要知道"下载量根本没在统计"，才不会以为"下载量为 0 说明没人用"。
     *
     * ⚠ 这份清单里**不允许出现与事实相反的理由**。曾经有过一条：
     *   「下载量：skill_search_index 是孤儿物化表，全仓无写入方 —— 做出来永远是 0」。
     *   前半句是真的（那张物化表确实没人写），后半句是假的 —— 下载另有计数
     *   （见 `downloadCount()`）。**把"统计了"说成"没统计"，与把"没统计"显示成 0
     *   一样会给决策者假事实**，而且更隐蔽：没人会去怀疑一句"我们没统计"。
     *   现在下载量是 `entitlements()` 里的一个真实指标，理由写在它自己的口径里。
     *
     * @return array<int, array{name: string, reason: string}>
     */
    public function notConnected(): array
    {
        return [
            ['name' => '净收入 / 已收款', 'reason' => '真实收款依赖微信支付资质（E1）。当前支付/退款流水来自 mock 网关：payment_transaction 有记录，但没有真实资金进出 —— 净收入＝已收款−已退款，两侧都不是真钱，算出来是假账'],
            ['name' => '结算（平台 ↔ 开发者分成）', 'reason' => '平台尚无结算与分成规则，也没有真实收款通道（E1）—— 结算金额无从算起。settlement 表已建，代码未做（M9 只做了发票）'],
            ['name' => '提现（开发者/商户提现）', 'reason' => '提现依赖结算结果与真实资金通道（E1），平台当前也没有资金托管能力。withdrawal 表已建，代码未做（M9 只做了发票）'],
            ['name' => '对账（平台账 vs 微信账）', 'reason' => '微信侧账单要等支付资质（E1）：拿不到渠道账单，就没有可比的另一侧。平台侧已具备对账锚点 —— 支付流水 payment_transaction.out_trade_no 是唯一键（M6）'],
            ['name' => '曝光量 / 浏览量', 'reason' => 'visits 依赖未开启的访客统计，且队列无 worker 消费'],
            ['name' => '真机下发成功率', 'reason' => '真机 Agent 未接入，现有数据来自模拟设备'],
            ['name' => '人工送达的下载次数', 'reason' => '下载计数只在 App/API 的鉴权下载里做；走安装指引人工送达的那部分没有回执'],
        ];
    }

    // ==================== 内部 ====================

    /**
     * 已过 L0、尚未做 L2 人审的版本数（审核队列的深度）。
     *
     * ⚠ 列名陷阱：`audit_record.skill_id` 存的是 **products.id**，`version` 存的是
     *   **skill_versions.id**（见 AuditRecord::scopeForVersion 的注释）。
     *
     * seller 过滤**只加在主表 `v` 上**就行：两个子查询都是 `whereColumn` 挂在 v 上的，
     * 收敛到一处 —— 不必去碰 audit_record 那两列内容不一致的列名。
     */
    protected function countPendingHumanReview(?int $sellerId = null): int
    {
        return DB::table('skill_versions as v')
            ->where('v.status', 'draft')
            ->when($sellerId !== null, fn ($q) => $q->where('v.seller_id', $sellerId))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('audit_record as a')
                ->whereColumn('a.skill_id', 'v.product_id')
                ->whereColumn('a.version', 'v.id'))
            // 还没有 L2（layer=3）通过记录的
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('audit_record as a')
                ->whereColumn('a.skill_id', 'v.product_id')
                ->whereColumn('a.version', 'v.id')
                ->where('a.layer', 3)
                ->where('a.result', 1))
            ->count();
    }

    /**
     * @return array{value: int|float|null, basis: string, ready: bool}
     */
    protected function metric(int|float|null $value, string $basis, bool $ready): array
    {
        return ['value' => $value, 'basis' => $basis, 'ready' => $ready];
    }
}
