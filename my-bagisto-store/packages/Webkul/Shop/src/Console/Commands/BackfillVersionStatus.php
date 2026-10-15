<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;

/**
 * 历史数据回填：把"已提审过、但状态仍是 draft"的版本修正为 in_review。
 *
 * 为什么需要它：
 *   2026-10-16 起审核台队列只取 `in_review` / `rejected`（见
 *   `Admin\Catalog\SkillReviewController::index`）。而**改动上线前**提审的版本，
 *   当时提审并不写 status，库里仍是 draft —— 它们会从审核台**静默消失**（漏单）。
 *   本命令把这类版本补成 in_review，让它们重新进入审核台。
 *
 * ⚠ 关联键陷阱（见 `AuditRecord::scopeForVersion` 的注释）：
 *   `audit_record.skill_id` 存的是 **products.id**，`audit_record.version` 存的是
 *   **skill_versions.id** —— 列名与内容不一致。所以判"是否提审过"必须走
 *   `forVersion($product_id, $version_id)`，不能按列名想当然。
 *
 * 幂等：只处理 `status=draft`，回填后即为 `in_review`；再跑一次不会再动它们。
 */
class BackfillVersionStatus extends Command
{
    /**
     * --dry-run：只打印将要改动/跳过的版本，不写库。
     */
    protected $signature = 'skill:backfill-version-status {--dry-run : 只打印不写库}';

    protected $description = '把"已提审过(status=draft 但存在 audit_record)"的历史版本回填为 in_review';

    public function __construct(protected SkillVersionState $states)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $changed = 0;
        $failed = 0;

        // chunkById 边翻页边改：每批处理后下一批从更大的 id 取，已改过的行不会再被取到，
        // 不会因为"改了状态导致结果集变化"而漏行或空转。
        SkillVersion::query()
            ->where('status', SkillVersion::STATUS_DRAFT)
            ->orderBy('id')
            ->chunkById(200, function ($versions) use ($dryRun, &$scanned, &$changed, &$failed) {
                foreach ($versions as $version) {
                    $scanned++;

                    $hasRecord = AuditRecord::query()
                        ->forVersion($version->product_id, $version->id)
                        ->exists();

                    // 从没提审过的 draft 不动 —— 那是还没做完，不是漏单。
                    if (! $hasRecord) {
                        continue;
                    }

                    $line = "#{$version->id}（商品 #{$version->product_id}，v{$version->version}）：draft → in_review";

                    if ($dryRun) {
                        $this->line('[dry-run] '.$line);

                        $changed++;

                        continue;
                    }

                    // 走唯一入口写状态（与 AuditService / SkillPublisher 同一条路径）
                    $result = $this->states->transition($version, SkillVersion::STATUS_IN_REVIEW);

                    if (! $result['ok']) {
                        $failed++;
                        $this->error("迁移失败：{$line}（{$result['code']} {$result['message']}）");

                        continue;
                    }

                    $changed++;
                    $this->line($line);
                }
            });

        $suffix = $dryRun ? '（dry-run，未写库）' : '';
        $this->info("扫描 draft 版本 {$scanned} 个，回填 in_review {$changed} 个，失败 {$failed} 个{$suffix}。");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
