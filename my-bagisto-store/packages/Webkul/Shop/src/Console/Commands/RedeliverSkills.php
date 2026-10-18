<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;

class RedeliverSkills extends Command
{
    protected $signature = 'skills:redeliver {orderId : 订单 ID} {--execute : 通过安全门槛后执行，默认仅检查}';

    protected $description = '检查既有 app 授权的异常补偿；不自动补建缺失授权，不执行真机安装';

    public function handle(SkillDeliveryCompensator $compensator): int
    {
        $orderId = filter_var($this->argument('orderId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $orderId || ! OrderProxy::modelClass()::find($orderId)) {
            $this->error('订单不存在或 ID 无效。');

            return self::FAILURE;
        }
        if ($this->option('execute') && ! $compensator->executionAllowed()) {
            $this->error('EXECUTION_NOT_VERIFIED：执行开关未启用或安全校验未通过，自动与 CLI 执行均关闭。');

            return self::FAILURE;
        }
        $entitlements = SkillEntitlement::where('order_id', $orderId)->orderBy('id')->get();
        $rows = [];
        foreach ($entitlements as $entitlement) {
            $result = $this->option('execute') ? $compensator->execute((int) $entitlement->id) : $compensator->inspect((int) $entitlement->id);
            $rows[] = [$result['entitlement_id'], $result['state'], $result['reason_code'], $result['attempts'].'/'.$result['max_attempts']];
        }
        $missing = DB::table('order_items')->where('order_id', $orderId)
            ->whereNotIn('id', $entitlements->pluck('order_item_id')->filter())->count();
        $this->table(['授权', '状态', '原因', '补偿次数'], $rows);
        $this->line(($this->option('execute') ? '执行结果' : '只读检查').'；没有对应授权的订单条目 '.$missing.' 项（包含非技能商品），需要巡检分类后人工确认。');
        $this->line('此命令仅处理应用内授权，不代表机器人已安装技能。');

        return self::SUCCESS;
    }
}
