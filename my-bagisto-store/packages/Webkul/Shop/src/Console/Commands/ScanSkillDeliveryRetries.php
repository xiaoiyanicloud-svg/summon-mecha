<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;

class ScanSkillDeliveryRetries extends Command
{
    protected $signature = 'skills:scan-delivery-retries {--limit= : 本轮最多检查条数}';

    protected $description = '持久初始化已有异常授权的补偿进度，并在安全开关允许时提交后派发任务';

    public function handle(SkillDeliveryCompensator $compensator): int
    {
        $this->line(json_encode($compensator->dispatchDue((int) ($this->option('limit') ?: config('skill_delivery.retry.scan_limit', 100))), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
