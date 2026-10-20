<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\RegistrationScopeService;

/**
 * G2-02 / G2-15 · confirmed_count 对账。
 *
 * 默认只读；`--apply` 才按 attempts 事实修正 scope 计数。
 * 修正会递增 row_version，不删除报名事实。
 */
class ReconcileRegistrationScopes extends Command
{
    protected $signature = 'ecosystem:reconcile-registration-scopes
        {--apply : 实际写回修正值（默认只读）}
        {--event= : 只检查指定活动 ID}';

    protected $description = '从 attempts 重算报名 scope 的 confirmed_count（默认 dry-run）';

    public function handle(RegistrationScopeService $scopes): int
    {
        $query = EventRegistrationScope::query()->orderBy('id');

        if ($this->option('event')) {
            $query->where('event_id', (int) $this->option('event'));
        }

        $apply = (bool) $this->option('apply');
        $checked = 0;
        $changed = 0;

        foreach ($query->cursor() as $scope) {
            $result = $scopes->reconcileConfirmedCount($scope, $apply);
            $checked++;

            if ($result['changed']) {
                $changed++;
                $this->line(sprintf(
                    'scope #%d event #%d：stored=%d computed=%d %s',
                    $result['scope_id'],
                    (int) $scope->event_id,
                    $result['stored'],
                    $result['computed'],
                    $apply ? '已修正' : '需修正（未写）',
                ));
            }
        }

        $this->info(sprintf('检查 %d 个 scope；不一致 %d 个。%s', $checked, $changed, $apply ? '' : '未写库。'));

        return self::SUCCESS;
    }
}
