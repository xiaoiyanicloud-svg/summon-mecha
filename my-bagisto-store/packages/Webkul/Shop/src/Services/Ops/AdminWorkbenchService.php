<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\ContentSecurityRecord;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\Notification as PlatformNotification;
use Webkul\Shop\Models\SkillReport;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Models\SupportTicket;

/**
 * 召唤机甲运营工作台聚合服务。
 *
 * 将散落在多菜单下的高优待办（待审技能、合作申请、平台告警、售后退款、
 * 工单与 SLA 超时、故障事故、开发者实名、内容安全与举报）和真实生态开关状态
 * 聚合到后台首页「运营工作台」。
 *
 * 所有计数与开关均基于真实库表与配置，严格按当前管理员 Bouncer 权限过滤：
 * 无某模块权限的角色看不到该模块待办卡片与入口，绝不泄露越权业务摘要。
 */
class AdminWorkbenchService
{
    public function summary(?int $adminId = null): array
    {
        $queues = [];

        if ($this->hasPermission('catalog.skill-reviews')) {
            $pendingVersions = SkillVersion::query()
                ->where('status', SkillVersion::STATUS_IN_REVIEW)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('audit_record')
                        ->whereColumn('audit_record.skill_id', 'skill_versions.product_id')
                        ->whereColumn('audit_record.version', 'skill_versions.id');
                })
                ->count();

            $rejectedVersions = SkillVersion::query()
                ->where('status', SkillVersion::STATUS_REJECTED)
                ->count();

            $queues[] = [
                'key'     => 'skill_reviews',
                'title'   => '技能准入待审',
                'count'   => $pendingVersions,
                'meta'    => $rejectedVersions > 0 ? "另有 {$rejectedVersions} 个驳回/待补充版本" : '含 L0 机审与 L2 人工终审',
                'tone'    => $pendingVersions > 0 ? 'primary' : 'neutral',
                'route'   => route('admin.catalog.skill-reviews.index'),
                'cta'     => '进入审核台',
            ];
        }

        if ($this->hasPermission('customers.cooperations')) {
            $coopPending = CooperationApplication::query()
                ->where('status', CooperationApplication::STATUS_PENDING)
                ->count();
            $coopContacting = CooperationApplication::query()
                ->where('status', CooperationApplication::STATUS_CONTACTING)
                ->count();

            $queues[] = [
                'key'     => 'cooperations',
                'title'   => '合作与供应链入驻',
                'count'   => $coopPending + $coopContacting,
                'meta'    => "待初审 {$coopPending} · 跟进中 {$coopContacting}",
                'tone'    => $coopPending > 0 ? 'warning' : 'neutral',
                'route'   => route('admin.customers.cooperations.index'),
                'cta'     => '跟进申请',
            ];
        }

        if ($this->hasPermission('dashboard.alerts')) {
            $alertsUnread = $adminId
                ? PlatformNotification::query()
                    ->where('recipient_type', 'admin')
                    ->where('recipient_id', $adminId)
                    ->whereNull('read_at')
                    ->count()
                : 0;

            $queues[] = [
                'key'     => 'alerts',
                'title'   => '平台未读告警',
                'count'   => $alertsUnread,
                'meta'    => $alertsUnread > 0 ? '存在需确认的系统扫描告警' : '当前无未读巡检告警',
                'tone'    => $alertsUnread > 0 ? 'danger' : 'neutral',
                'route'   => route('admin.alerts.index'),
                'cta'     => '查看告警箱',
            ];
        }

        if ($this->hasPermission('dashboard.after-sales')) {
            $afterSalesPending = AfterSale::query()
                ->whereIn('status', [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING])
                ->count();

            $queues[] = [
                'key'     => 'after_sales',
                'title'   => '售后退款待审',
                'count'   => $afterSalesPending,
                'meta'    => '涉及退款网关审批动作',
                'tone'    => $afterSalesPending > 0 ? 'warning' : 'neutral',
                'route'   => route('admin.after-sales.index'),
                'cta'     => '处理售后',
            ];
        }

        if ($this->hasPermission('customers.tickets')) {
            $ticketsOpen = SupportTicket::query()
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
                ->count();

            $ticketsOverdue = SupportTicket::query()
                ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PROCESSING])
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', now())
                ->count();

            $queues[] = [
                'key'     => 'tickets',
                'title'   => '客服工单池',
                'count'   => $ticketsOpen,
                'meta'    => $ticketsOverdue > 0 ? "其中 {$ticketsOverdue} 单已超 SLA" : '全部未结单均在 SLA 内',
                'tone'    => $ticketsOverdue > 0 ? 'danger' : ($ticketsOpen > 0 ? 'primary' : 'neutral'),
                'route'   => route('admin.customers.tickets.index'),
                'cta'     => '处理工单',
            ];
        }

        if ($this->hasPermission('customers.incidents')) {
            $incidentsOpen = Incident::query()
                ->whereNotIn('status', [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED])
                ->count();

            $incidentsSevere = Incident::query()
                ->whereNotIn('status', [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED])
                ->whereIn('severity', [Incident::SEVERITY_SEVERE, Incident::SEVERITY_CRITICAL])
                ->count();

            $queues[] = [
                'key'     => 'incidents',
                'title'   => '机甲故障与事故',
                'count'   => $incidentsOpen,
                'meta'    => $incidentsSevere > 0 ? "严重/重大等级 {$incidentsSevere} 起" : '暂无严重事故堆积',
                'tone'    => $incidentsSevere > 0 ? 'danger' : ($incidentsOpen > 0 ? 'warning' : 'neutral'),
                'route'   => route('admin.customers.incidents.index'),
                'cta'     => '溯源处置',
            ];
        }

        if ($this->hasPermission('customers.developers')) {
            $devsPending = DeveloperProfile::query()
                ->where('realname_status', DeveloperProfile::REALNAME_PENDING)
                ->count();

            $queues[] = [
                'key'     => 'developers',
                'title'   => '开发者实名待审',
                'count'   => $devsPending,
                'meta'    => '双层实名与营业执照核验',
                'tone'    => $devsPending > 0 ? 'primary' : 'neutral',
                'route'   => route('admin.customers.developers.index'),
                'cta'     => '核验主体',
            ];
        }

        if ($this->hasPermission('catalog.content-security')) {
            $securityPending = ContentSecurityRecord::query()
                ->where('result', '!=', ContentSecurityRecord::RESULT_PASS)
                ->where('disposition', ContentSecurityRecord::DISPOSITION_PENDING)
                ->count();

            $queues[] = [
                'key'     => 'content_security',
                'title'   => '内容安全待复核',
                'count'   => $securityPending,
                'meta'    => '机扫命中或未完成复判',
                'tone'    => $securityPending > 0 ? 'danger' : 'neutral',
                'route'   => route('admin.catalog.content-security.index'),
                'cta'     => '复核拦截',
            ];
        }

        if ($this->hasPermission('catalog.skill-reports')) {
            $reportsPending = SkillReport::query()
                ->where('status', SkillReport::STATUS_PENDING)
                ->count();

            $queues[] = [
                'key'     => 'skill_reports',
                'title'   => '技能投诉与举报',
                'count'   => $reportsPending,
                'meta'    => '含安全风险 / 侵权 / 违规举报',
                'tone'    => $reportsPending > 0 ? 'warning' : 'neutral',
                'route'   => route('admin.catalog.skill-reports.index'),
                'cta'     => '核查举报',
            ];
        }

        $totalPending = array_sum(array_column($queues, 'count'));

        $readiness = [
            [
                'label'   => '赛事公开展示',
                'enabled' => (bool) config('ecosystem.events.public_enabled', false),
                'text'    => config('ecosystem.events.public_enabled', false) ? '已开放读取' : '默认关闭（安全闸）',
            ],
            [
                'label'   => '站内报名写库',
                'enabled' => (bool) (config('ecosystem.events.registration_enabled', false) && config('ecosystem.events.registration_write_verified', false)),
                'text'    => (config('ecosystem.events.registration_enabled', false) && config('ecosystem.events.registration_write_verified', false))
                    ? '已放行写库'
                    : '默认关闭（待首场赛规签署）',
            ],
            [
                'label'   => '动态资讯公开',
                'enabled' => (bool) config('ecosystem.content.public_enabled', false),
                'text'    => config('ecosystem.content.public_enabled', false) ? '已开放读取' : '默认关闭',
            ],
            [
                'label'   => '生态 G4 工作台 / 指标',
                'enabled' => (bool) config('ecosystem_g4.ops.enabled', false),
                'text'    => config('ecosystem_g4.ops.enabled', false) ? '已启用' : '默认关闭（可按需配置）',
            ],
            [
                'label'   => '技能默认交付通道',
                'enabled' => true,
                'text'    => strtoupper((string) config('skill_delivery.default', 'app')).' · 人工/标准交付就绪',
            ],
        ];

        $shortcuts = [];

        if ($this->hasPermission('dashboard.overview')) {
            $shortcuts[] = ['label' => '技能运营总览', 'route' => route('admin.dashboard.ops')];
        }

        if ($this->hasPermission('catalog.skills')) {
            $shortcuts[] = ['label' => '技能版本目录', 'route' => route('admin.catalog.skills.index')];
        }

        if ($this->hasPermission('catalog.skill-import')) {
            $shortcuts[] = ['label' => '技能清单导入', 'route' => route('admin.catalog.skills.import')];
        }

        if ($this->hasPermission('catalog.ecosystem.ops')) {
            $shortcuts[] = ['label' => '生态运营台', 'route' => route('admin.catalog.ecosystem.ops.index')];
        }

        if ($this->hasPermission('customers.developer-invites')) {
            $shortcuts[] = ['label' => '开发者邀请码', 'route' => route('admin.customers.developer-invites.index')];
        }

        return [
            'total_pending' => $totalPending,
            'queues'        => $queues,
            'readiness'     => $readiness,
            'shortcuts'     => $shortcuts,
            'generated_at'  => now()->format('Y-m-d H:i'),
        ];
    }

    protected function hasPermission(string $permission): bool
    {
        return function_exists('bouncer') && bouncer()->hasPermission($permission);
    }
}
