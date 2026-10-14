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
 *   - 下载量：`skill_search_index` 是孤儿物化表，全仓无写入方
 *   - 浏览量：`visits` 依赖未开启的访客统计，且队列无 worker
 *   - 净收入 / 已收款：真实收款依赖微信支付资质（E1），invoices 恒 0
 *   这些在页面上以「未接入」显式列出，**不是**显示 0。
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
                '技能订单金额（**下单口径**，非已收款 —— 本项目无真实支付）',
                true
            ),
            'paid_amount'    => $this->metric(null, '已收款金额（依赖微信支付资质 E1；invoices 恒 0，无支付流水）', false),
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
            'installs' => $this->metric(DB::table('install_record')->count(), '安装记录（人工通道由用户自报）', true),
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
     * @return array<int, array{name: string, reason: string}>
     */
    public function notConnected(): array
    {
        return [
            ['name' => '净收入 / 已收款', 'reason' => '真实收款依赖微信支付资质（E1）；invoices 恒 0、无支付流水'],
            ['name' => '下载量', 'reason' => 'skill_search_index 是孤儿物化表，全仓无写入方 —— 做出来永远是 0'],
            ['name' => '浏览量 / 访客', 'reason' => 'visits 依赖未开启的访客统计，且队列无 worker 消费'],
            ['name' => '对账（平台账 vs 微信账）', 'reason' => '两侧都无数据源，且支付交易号 out_trade_no 从未持久化'],
            ['name' => '真机下发成功率', 'reason' => '真机 Agent 未接入，现有数据来自模拟设备'],
        ];
    }

    // ==================== 内部 ====================

    /**
     * 已过 L0、尚未做 L2 人审的版本数（审核队列的深度）。
     *
     * ⚠ 列名陷阱：`audit_record.skill_id` 存的是 **products.id**，`version` 存的是
     *   **skill_versions.id**（见 AuditRecord::scopeForVersion 的注释）。
     */
    protected function countPendingHumanReview(): int
    {
        return DB::table('skill_versions as v')
            ->where('v.status', 'draft')
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
