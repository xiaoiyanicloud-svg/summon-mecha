<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAudit;

class AuditSkillDelivery extends Command
{
    protected $signature = 'skills:audit-delivery {--limit=100} {--notify : 向现有管理员站内告警，默认只读}';

    protected $description = '巡检缺授权、缺技能分类和交付失败；不修改购买权益';

    public function handle(SkillDeliveryAudit $audit): int
    {
        $issues = $audit->inspect((int) $this->option('limit'), (bool) $this->option('notify'));
        foreach ($issues as $issue) {
            $this->line(json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $this->info('问题数 '.count($issues).'；告警 '.($this->option('notify') ? $audit->notify($issues) : '未发送（只读）'));

        return self::SUCCESS;
    }
}
