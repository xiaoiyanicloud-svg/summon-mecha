<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-08A · 为活动 / 文章预留排期发布时间（ADR-0001 附录 B A-02）。
 *
 * 本批**只建列与索引，不写任何值**：
 *   · 排期写入、取消排期、到点发布与 worker 扫描属于 G1-08B / G1-09；
 *   · outbox 的 `available_at` 是消费者重试时间，不是排期存储；
 *   · 组合索引 `(publish_status, scheduled_publish_at)` 供后续扫描
 *     “到点且仍可发布”的行使用。
 *
 * down() 只回收本迁移自己的列与索引。
 */
return new class extends Migration
{
    /**
     * @var array<string, string> table => index name
     */
    private const TARGETS = [
        'events'        => 'idx_events_publish_status_scheduled_at',
        'content_posts' => 'idx_content_posts_publish_status_scheduled_at',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $index) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(sprintf(
                    '%s 不存在，无法增加 scheduled_publish_at；请先执行对应建表迁移。',
                    $table,
                ));
            }

            if (! Schema::hasColumn($table, 'scheduled_publish_at')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    // UTC 存储（ADR G1-D04）；NULL 表示未排期。
                    $blueprint->dateTime('scheduled_publish_at')->nullable();
                });
            }

            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                    $blueprint->index(['publish_status', 'scheduled_publish_at'], $index);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $index) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }

            if (Schema::hasColumn($table, 'scheduled_publish_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('scheduled_publish_at'));
            }
        }
    }
};
