<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-06 · 为 `event_rule_versions.file_media_id` 补外键（ADR-0001 §4.5）。
 *
 * 000006 建规则版本表时 `ecosystem_media` 还不存在，只能先建 BIGINT 列与
 * `idx_event_rule_versions_file_media_id` 索引。000012 建好媒体表后，本迁移
 * 把该列钉到 `ecosystem_media.id`，DELETE / UPDATE 均 RESTRICT：
 *   · 被规则版本引用的媒体不可物理删除；
 *   · 媒体主键不可改号（避免引用悄悄指向另一份字节）。
 *
 * 迁移顺序强约束：必须先执行 000006 / 000012。缺表 / 缺列时显式 throw，
 * 不静默跳过 —— 否则会留下“有引用列但没有外键”的半成品结构。
 *
 * 本迁移**不触碰**既有索引：`idx_event_rule_versions_file_media_id` 归 000006 所有，
 * 加外键不得顺手删除或重建它。
 *
 * down() 只删本迁移的外键，不删列、不删索引。
 */
return new class extends Migration
{
    private const TABLE = 'event_rule_versions';

    private const FK_FILE_MEDIA = 'fk_event_rule_versions_file_media_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(
                'event_rule_versions 不存在，无法为 file_media_id 建立外键；请先执行 G1-04 的 2026_10_19_000006。'
            );
        }

        if (! Schema::hasTable('ecosystem_media')) {
            throw new RuntimeException(
                'ecosystem_media 不存在，无法为 file_media_id 建立外键；请先执行 G1-06 的 2026_10_19_000012。'
            );
        }

        if (! Schema::hasColumn(self::TABLE, 'file_media_id')) {
            throw new RuntimeException(
                'event_rule_versions.file_media_id 不存在，无法建立外键；请先执行 G1-04 的 2026_10_19_000006。'
            );
        }

        if ($this->hasForeignKey(self::FK_FILE_MEDIA)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreign('file_media_id', self::FK_FILE_MEDIA)
                ->references('id')
                ->on('ecosystem_media')
                ->onDelete('restrict')
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasForeignKey(self::FK_FILE_MEDIA)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FK_FILE_MEDIA));
        }
    }

    private function hasForeignKey(string $constraint): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
