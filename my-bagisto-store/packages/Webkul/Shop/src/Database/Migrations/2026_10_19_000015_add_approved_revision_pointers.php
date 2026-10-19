<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-08A · 为活动 / 文章增加「当前已批准候选版本」指针（ADR-0001 附录 B A-01）。
 *
 * 为什么需要第三条指针：
 *   · `published_revision_id` 表示**当前公开版本**；
 *   · `working_revision_id` 只表示**编辑中的 draft**；
 *   · 批准后、发布前（或已发布对象的新版本）需要一个持久事实回答
 *     “哪一条 revision 已被批准”。只靠 review_state=approved 无法在
 *     同一对象有多条 approved 历史时确定当前候选。
 *
 * 边界：
 *   · 列 BIGINT UNSIGNED NULL；`down()` 只回收本迁移自己的列 / 索引 / 外键；
 *   · 外键 RESTRICT/RESTRICT：被指针引用的 revision 不可物理删除或改号；
 *   · 普通写入口（EventWriteService / ContentPostService）不得透传；
 *     只有审核服务经 RevisionPointerValidator 的受控校验写入；
 *   · 本迁移不写任何数据，不改变存量行的公开状态。
 *
 * 前置表 / 列缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    /**
     * @var array<string, array{table: string, fk: string, index: string}>
     */
    private const TARGETS = [
        'events' => [
            'revision_table' => 'event_revisions',
            'fk'             => 'fk_events_approved_revision_id',
            'index'          => 'idx_events_approved_revision_id',
        ],
        'content_posts' => [
            'revision_table' => 'content_revisions',
            'fk'             => 'fk_content_posts_approved_revision_id',
            'index'          => 'idx_content_posts_approved_revision_id',
        ],
    ];

    public function up(): void
    {
        $this->assertPrerequisites();

        foreach (self::TARGETS as $table => $meta) {
            if (! Schema::hasColumn($table, 'approved_revision_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('approved_revision_id')->nullable();
                });
            }

            if (! Schema::hasIndex($table, $meta['index'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($meta): void {
                    $blueprint->index('approved_revision_id', $meta['index']);
                });
            }

            if (! $this->hasForeignKey($table, $meta['fk'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($meta): void {
                    $blueprint->foreign('approved_revision_id', $meta['fk'])
                        ->references('id')
                        ->on($meta['revision_table'])
                        ->onDelete('restrict')
                        ->onUpdate('restrict');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $meta) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->hasForeignKey($table, $meta['fk'])) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($meta['fk']));
            }

            if (Schema::hasIndex($table, $meta['index'])) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($meta['index']));
            }

            if (Schema::hasColumn($table, 'approved_revision_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('approved_revision_id'));
            }
        }
    }

    private function assertPrerequisites(): void
    {
        foreach (self::TARGETS as $table => $meta) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(sprintf(
                    '%s 不存在，无法增加 approved_revision_id；请先执行 G1-02 / G1-05 的建表迁移。',
                    $table,
                ));
            }

            if (! Schema::hasTable($meta['revision_table'])) {
                throw new RuntimeException(sprintf(
                    '%s 不存在，无法为 %s.approved_revision_id 建立外键；请先执行对应 revision 建表迁移。',
                    $meta['revision_table'],
                    $table,
                ));
            }
        }
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
