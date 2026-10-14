<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webkul\Shop\Models\SkillAsset;

/**
 * 把技能包在存储盘之间搬家（v3.4 步骤 3 · 对象存储接入）。
 *
 *   php artisan skill:move-assets --from=private --to=cos --dry-run
 *   php artisan skill:move-assets --from=private --to=cos                 # 复制
 *   php artisan skill:move-assets --from=private --to=cos --delete-source # 复制后删源
 *
 * 设计要点：**不改数据库**。
 *   `skill_assets.file_path` 存的是**与盘无关的相对路径**（`skills/{product_id}/{version}/{name}`），
 *   落在哪个盘由 `config('skill_delivery.asset_disk')` 决定。所以"切盘"就是搬字节 +
 *   改一行配置 —— 这也是为什么要在切之前先把路径统一成相对路径。
 *
 * 幂等：目标盘已有同路径文件时跳过复制；加了 --delete-source 也可以安全重复执行。
 * 建议顺序：先 --dry-run 看量 → 再不加 --delete-source 复制一遍 → 校验 → 才删源 → 最后改配置。
 */
class MoveSkillAssets extends Command
{
    protected $signature = 'skill:move-assets
        {--from=private : 源盘名}
        {--to= : 目标盘名（必填）}
        {--delete-source : 复制成功且校验通过后删除源文件}
        {--dry-run : 只报告将要做什么，不做任何写入}';

    protected $description = '把技能包在存储盘之间搬家（切对象存储用）';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');

        if ($to === '') {
            $this->error('必须指定 --to=<目标盘>。');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('源盘与目标盘相同，无需搬运。');

            return self::FAILURE;
        }

        try {
            $fromDisk = Storage::disk($from);
            $toDisk = Storage::disk($to);
        } catch (Throwable $e) {
            $this->error("存储盘不可用：{$e->getMessage()}");

            return self::FAILURE;
        }

        $assets = SkillAsset::query()->whereNotNull('file_path')->get();

        if ($assets->isEmpty()) {
            $this->info('没有带文件的交付物，无需搬运。');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s从 [%s] 搬到 [%s]，共 %d 个交付物%s',
            $dryRun ? '[DRY-RUN] ' : '',
            $from,
            $to,
            $assets->count(),
            $deleteSource ? '（复制后删源）' : ''
        ));

        $copied = $skipped = $missing = $failed = 0;

        foreach ($assets as $asset) {
            $path = $asset->file_path;

            try {
                $onSource = $fromDisk->exists($path);
                $onTarget = $toDisk->exists($path);

                // 已经在目标盘（上次跑过一半）→ 跳过，保持幂等
                if (! $onSource && $onTarget) {
                    $skipped++;
                    $this->line("  · 已在目标盘，跳过：{$path}");

                    continue;
                }

                if (! $onSource) {
                    $missing++;
                    $this->warn("  ! 源盘找不到文件（资产 #{$asset->id}）：{$path}");

                    continue;
                }

                if ($onTarget) {
                    $skipped++;
                    $this->line("  · 目标盘已有，跳过：{$path}");
                } else {
                    if ($dryRun) {
                        $this->line("  → 将复制：{$path}");

                        // dry-run 不真做，但删源检查也一并预告
                        if ($deleteSource) {
                            $this->line("    并删除源文件：{$path}");
                        }

                        $copied++;

                        continue;
                    }

                    $stream = $fromDisk->readStream($path);

                    if (! $stream) {
                        $failed++;
                        $this->error("  ✗ 读不出源文件：{$path}");

                        continue;
                    }

                    $toDisk->writeStream($path, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    // 校验：目标盘存在且字节数一致，才敢删源
                    $sourceSize = $fromDisk->size($path);
                    $targetSize = $toDisk->exists($path) ? $toDisk->size($path) : -1;

                    if ($sourceSize !== $targetSize) {
                        $failed++;
                        $this->error("  ✗ 校验失败（源 {$sourceSize}B / 目标 {$targetSize}B）：{$path}");

                        continue;
                    }

                    $copied++;
                    $this->line("  ✓ 已复制：{$path}");
                }

                // 删源只在不 dry-run 且校验通过后执行
                if ($deleteSource && ! $dryRun) {
                    $fromDisk->delete($path);
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ 处理失败（资产 #{$asset->id}）：{$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s复制 %d ／ 跳过 %d ／ 源缺失 %d ／ 失败 %d',
            $dryRun ? '[DRY-RUN] ' : '',
            $copied,
            $skipped,
            $missing,
            $failed
        ));

        if ($failed > 0) {
            $this->error('存在失败项，不要删源、也不要切配置 —— 先排查。');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY-RUN 结束，未做任何改动。');

            return self::SUCCESS;
        }

        if (! $deleteSource) {
            $this->info('复制完成（源文件保留）。校验无误后再跑一次加 --delete-source，然后：');
        } else {
            $this->info('搬运完成。现在可以切配置：');
        }

        $this->line('  .env:  SKILL_ASSET_DISK='.$to);

        return self::SUCCESS;
    }
}
