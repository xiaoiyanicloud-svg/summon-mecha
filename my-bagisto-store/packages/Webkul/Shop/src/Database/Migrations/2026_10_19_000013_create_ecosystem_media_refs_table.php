<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-06 · `ecosystem_media_refs`（ADR-0001 §4.10 / G1-D06）。
 *
 * 媒体与 revision 的引用关系。边界：
 *   · `media_id` 外键指向 `ecosystem_media.id`，DELETE / UPDATE 均 RESTRICT ——
 *     被引用的媒体不可物理删除；
 *   · `owner_type` 只有 `event_revision` / `content_revision` 两个值，
 *     **刻意不做多态外键**；归属对象是否存在由 MediaRefService（下一批）校验；
 *   · `uk(owner_type, owner_id, sort_order)` 保证同一 revision 内展示顺序唯一；
 *   · 本表只有 `created_at`（引用是审核快照，不需要 updated_at）；
 *   · `review_status` 默认 `pending`，公开读取要求 `approved`。
 *
 * 前置表缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_media_refs';

    private const FK_MEDIA = 'fk_ecosystem_media_refs_media_id';

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

        if (! $this->hasForeignKey(self::FK_MEDIA)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('media_id', self::FK_MEDIA)
                    ->references('id')
                    ->on('ecosystem_media')
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

        if ($this->hasForeignKey(self::FK_MEDIA)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FK_MEDIA));
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'media_id'      => fn (Blueprint $table) => $table->unsignedBigInteger('media_id'),
            // event_revision / content_revision；不做多态外键。
            'owner_type'    => fn (Blueprint $table) => $table->string('owner_type', 30),
            'owner_id'      => fn (Blueprint $table) => $table->unsignedBigInteger('owner_id'),
            // cover / inline / attachment / rule_document。
            'role'          => fn (Blueprint $table) => $table->string('role', 20),
            'sort_order'    => fn (Blueprint $table) => $table->unsignedInteger('sort_order')->default(0),
            'alt_text'      => fn (Blueprint $table) => $table->string('alt_text', 255)->nullable(),
            // pending / approved / rejected。
            'review_status' => fn (Blueprint $table) => $table->string('review_status', 20)->default('pending'),

            'created_at'    => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'idx_ecosystem_media_refs_media_id' => [
                'columns' => ['media_id'],
                'unique'  => false,
            ],
            'uk_ecosystem_media_refs_owner_sort' => [
                'columns' => ['owner_type', 'owner_id', 'sort_order'],
                'unique'  => true,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('ecosystem_media')) {
            throw new RuntimeException(
                'ecosystem_media 不存在，无法创建 ecosystem_media_refs；请先执行 G1-06 的 2026_10_19_000012。'
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
