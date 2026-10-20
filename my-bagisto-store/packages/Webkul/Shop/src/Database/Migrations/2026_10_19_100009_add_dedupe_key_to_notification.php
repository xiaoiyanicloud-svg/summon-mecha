<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-12 · `notification.dedupe_key`。
 *
 * outbox 至少一次投递；通知必须按业务事实去重：
 *   · 同一 `registration.<action>` + attempt 只产生一条站内信；
 *   · worker 崩溃重投、租约恢复、人工补偿都复用同一个 key；
 *   · 列可空，不影响既有通知；唯一索引只约束非空 key。
 */
return new class extends Migration
{
    private const TABLE = 'notification';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('notification 表不存在，无法执行 G2-12 增量迁移。');
        }

        if (! Schema::hasColumn(self::TABLE, 'dedupe_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('dedupe_key', 191)->nullable()->after('payload');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'uk_notification_dedupe_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('dedupe_key', 'uk_notification_dedupe_key');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, 'uk_notification_dedupe_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('uk_notification_dedupe_key');
            });
        }

        if (Schema::hasColumn(self::TABLE, 'dedupe_key')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('dedupe_key');
            });
        }
    }
};
