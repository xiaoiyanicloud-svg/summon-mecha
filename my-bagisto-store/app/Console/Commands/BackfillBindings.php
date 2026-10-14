<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\SkillDelivery\DeviceBindingService;

/**
 * 给历史设备补建 binding（v3.4 步骤 2「设备唯一真相源=binding」的存量数据收口）。
 *
 *   php artisan skill:backfill-bindings --dry-run
 *   php artisan skill:backfill-bindings
 *
 * 为什么需要它：`device_binding` 是**后加**的（v3.4 步骤 2.3），
 * 之前登记的 devices 行没有对应 binding。而第 8 步的下发链路**只认 binding** ——
 * 这些设备在"授权指派"时会自愈（bindDevice/confirmInstall 里 heal=true），
 * 但**没人点过它们的授权**就一直缺着。这条命令一次性补齐，别等用户踩。
 *
 * 幂等：已有 binding（含 devices.binding_id 指向的那条）的直接跳过。
 * 只处理 `bind_status = bound` 的设备 —— 已解绑（revoked）的**不该**给它确权。
 */
class BackfillBindings extends Command
{
    protected $signature = 'skill:backfill-bindings {--dry-run : 只报告将要做什么}';

    protected $description = '给历史 devices 行补建 device_binding（下发链路只认 binding）';

    public function handle(DeviceBindingService $bindings): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $devices = Device::query()
            ->where('bind_status', Device::STATUS_BOUND)
            ->orderBy('id')
            ->get();

        $this->line(sprintf(
            '%s检查 %d 台在绑设备…',
            $dryRun ? '[DRY-RUN] ' : '',
            $devices->count()
        ));

        $created = $skipped = $broken = $failed = 0;

        foreach ($devices as $device) {
            try {
                // 已有 binding（含 dangling binding_id 但行不存在的情况，下面单独算）
                if ($device->binding_id && DeviceBinding::find($device->binding_id)) {
                    $skipped++;

                    continue;
                }

                // binding_id 有值但行没了 —— 数据坏了，值得报出来
                if ($device->binding_id) {
                    $broken++;
                    $this->warn("  ! device#{$device->id} 的 binding_id={$device->binding_id} 指向不存在的行，将重建");
                }

                if ($dryRun) {
                    $this->line("  → 将补建：device#{$device->id} sn={$device->sn} customer={$device->customer_id}");
                    $created++;

                    continue;
                }

                $binding = $bindings->ensureForDevice($device);

                if ($binding) {
                    $created++;
                    $this->line("  ✓ 已补建：device#{$device->id} sn={$device->sn} → did={$binding->did}");
                } else {
                    $failed++;
                    $this->error("  ✗ 补建失败：device#{$device->id}");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ device#{$device->id} 出错：{$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s补建 %d ／ 已有跳过 %d ／ 断链修复 %d ／ 失败 %d',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $skipped,
            $broken,
            $failed
        ));

        if ($failed > 0) {
            $this->error('有失败项，排查后重跑（命令幂等）。');

            return self::FAILURE;
        }

        if (! $dryRun && $created > 0) {
            $this->info('完成。可用 `skill:backfill-bindings --dry-run` 复核（应全部为"已有跳过"）。');
        }

        return self::SUCCESS;
    }
}
