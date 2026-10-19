<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-05 · `content_sources`（ADR-0001 §4.8 / G1-D06）。
 *
 * 每条 revision 的来源与权利依据：来源名、原文 URL、核验时间、权利依据与范围。
 * 来源记录属于 revision 的审计证据，随 revision 不可变；修改来源必须创建新 revision。
 *
 * 边界：
 *   · `revision_id` 外键指向 `content_revisions.id`，RESTRICT/RESTRICT ——
 *     被引用的 revision 不可物理删除；
 *   · `idx(revision_id)` 与 `uk(revision_id, sort_order)`：同一 revision 内顺序唯一；
 *   · `evidence_ref` 只存私有依据引用（存储 key / 文档编号），不存文件本体，永不进入公开 DTO；
 *   · 本表只有 `created_at`，没有 `updated_at` —— 来源记录不提供“原地更新”语义。
 *
 * 前置表缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'content_sources';

    private const FK_REVISION = 'fk_content_sources_revision_id';

    public function up(): void
    {
        $this->assertPrerequisites();

        $definitions = $this->columnDefinitions();

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($definitions): void {
                $table->id();

                foreach ($definitions as $definition) {
                    $definition($table);
                }
            });
        } else {
            $missingColumns = array_values(array_filter(
                array_keys($definitions),
                fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
            ));

            if ($missingColumns !== []) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($definitions, $missingColumns): void {
                    foreach ($missingColumns as $column) {
                        $definitions[$column]($table);
                    }
                });
            }
        }

        foreach ($this->indexDefinitions() as $name => $definition) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }

        if (! $this->hasForeignKey(self::FK_REVISION)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('revision_id', self::FK_REVISION)
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

        if ($this->hasForeignKey(self::FK_REVISION)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FK_REVISION));
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'revision_id'         => fn (Blueprint $table) => $table->unsignedBigInteger('revision_id'),

            'source_name'         => fn (Blueprint $table) => $table->string('source_name', 191),
            // 草稿可空，in_review 起必填；原创填平台 canonical URL。
            'source_url'          => fn (Blueprint $table) => $table->string('source_url', 2048)->nullable(),
            // UTC 存储；原创为内部事实核对时间。
            'source_verified_at'  => fn (Blueprint $table) => $table->dateTime('source_verified_at'),

            // platform_original / written_repost_permission / official_link_only / public_document_summary。
            'rights_basis'        => fn (Blueprint $table) => $table->string('rights_basis', 50),
            // 正文 / 图片 / 改编范围。
            'rights_scope'        => fn (Blueprint $table) => $table->string('rights_scope', 255)->nullable(),
            // 私有授权依据引用；永不公开。
            'evidence_ref'        => fn (Blueprint $table) => $table->string('evidence_ref', 255)->nullable(),

            'sort_order'          => fn (Blueprint $table) => $table->unsignedInteger('sort_order')->default(0),

            'created_at'          => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'idx_content_sources_revision_id' => [
                'columns' => ['revision_id'],
                'unique'  => false,
            ],
            'uk_content_sources_revision_sort' => [
                'columns' => ['revision_id', 'sort_order'],
                'unique'  => true,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('content_revisions')) {
            throw new RuntimeException(
                'content_revisions 不存在，无法创建 content_sources；请先执行 G1-05 的 2026_10_19_000009。'
            );
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
