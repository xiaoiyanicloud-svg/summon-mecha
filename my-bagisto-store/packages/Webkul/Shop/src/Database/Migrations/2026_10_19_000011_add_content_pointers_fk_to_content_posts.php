<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-05 · 为 `content_posts` 的公开/工作双指针补外键（ADR-0001 §4.6）。
 *
 * 000008 只建了 BIGINT 列与索引，因为当时 `content_revisions` 还不存在。
 * 000009 建好表后，本迁移把两个指针钉到 `content_revisions.id`，
 * DELETE / UPDATE 均 RESTRICT：
 *   · 被文章引用的 revision 不可物理删除；
 *   · revision 主键不可改号（避免指针悄悄指向另一条历史）。
 *
 * 迁移顺序强约束：必须先执行 000008 / 000009。缺表 / 缺列时显式 throw，
 * 不静默跳过 —— 否则会留下“有指针列但没有外键”的半成品结构。
 *
 * down() **只删这两个外键**，不删列（列归 000008 的迁移所有）。
 */
return new class extends Migration
{
    private const TABLE = 'content_posts';

    /**
     * @var array<string, string> column => constraint name
     */
    private const FOREIGN_KEYS = [
        'published_revision_id' => 'fk_content_posts_published_revision_id',
        'working_revision_id'   => 'fk_content_posts_working_revision_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(
                'content_posts 不存在，无法为 revision 指针建立外键；请先执行 G1-05 的 2026_10_19_000008。'
            );
        }

        if (! Schema::hasTable('content_revisions')) {
            throw new RuntimeException(
                'content_revisions 不存在，无法为 content_posts 的 revision 指针建立外键；请先执行 G1-05 的 2026_10_19_000009。'
            );
        }

        foreach (array_keys(self::FOREIGN_KEYS) as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException(sprintf(
                    'content_posts.%s 不存在，无法建立 revision 指针外键；请先执行 G1-05 的 2026_10_19_000008。',
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
                    ->on('content_revisions')
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

        // 先删两个指针外键；本迁移不负责列与索引。
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
