<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-04 · 为 `events` 的公开/工作双指针补外键（ADR-0001 §4.2）。
 *
 * G1-02 只建了 BIGINT 列（`published_revision_id` / `working_revision_id`），
 * 因为当时 `event_revisions` 还不存在。000005 建好表后，本迁移把两个指针钉到
 * `event_revisions.id`，DELETE / UPDATE 均 RESTRICT：
 *   · 被任一活动引用的 revision 不可物理删除；
 *   · revision 主键不可改号（避免指针悄悄指向另一条历史）。
 *
 * 迁移顺序强约束：必须先执行 000005。缺表 / 缺列时显式 throw，
 * 不静默跳过 —— 否则会留下“有指针列但没有外键”的半成品结构。
 *
 * down() **只删这两个外键**，不删列（列归 G1-02 的迁移所有）。
 */
return new class extends Migration
{
    private const TABLE = 'events';

    /**
     * @var array<string, string> column => constraint name
     */
    private const FOREIGN_KEYS = [
        'published_revision_id' => 'fk_events_published_revision_id',
        'working_revision_id'   => 'fk_events_working_revision_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(
                'events 不存在，无法为 revision 指针建立外键；请先执行 G1-02 的 2026_10_19_000001。'
            );
        }

        if (! Schema::hasTable('event_revisions')) {
            throw new RuntimeException(
                'event_revisions 不存在，无法为 events 的 revision 指针建立外键；请先执行 G1-04 的 2026_10_19_000005。'
            );
        }

        foreach (array_keys(self::FOREIGN_KEYS) as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException(sprintf(
                    'events.%s 不存在，无法建立 revision 指针外键；请先执行 G1-02 的 2026_10_19_000001。',
                    $column,
                ));
            }
        }

        foreach (self::FOREIGN_KEYS as $column => $constraint) {
            if ($this->hasForeignKey($constraint)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $constraint): void {
                $table->foreign($column, $constraint)
                    ->references('id')
                    ->on('event_revisions')
                    ->onDelete('restrict')
                    ->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // 先删两个外键；本迁移不负责列与索引。
        foreach (self::FOREIGN_KEYS as $constraint) {
            if ($this->hasForeignKey($constraint)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign($constraint));
            }
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
