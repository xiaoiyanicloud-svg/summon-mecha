<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-03 · `events` 来源三字段 + 主办方引用（ADR-0001 §4.2 / G1-D01 / G1-D05）。
 *
 * 只做 additive：
 *   · `source_type` / `platform_role` / `organizer_id` 全部可空；
 *   · **不回填**旧 `events.organizer` 自由文本 —— 旧文本只是历史展示值，
 *     不能据此推定主办主体、来源类型或平台角色（ADR §2.3「三方不推定」）；
 *   · 存量三字段保持 NULL，代表“历史未核定”，在其被显式补齐前不得发布。
 *
 * 外键指向 `ecosystem_organizers.id`（迁移顺序保证 000003 先建表），
 * ON DELETE / ON UPDATE 均为 RESTRICT：主体一旦被活动引用就不允许物理删除或改号，
 * 避免历史活动的主办权指向漂移。
 *
 * down() 先删 FK、再删索引、最后删列 —— MySQL 不允许删除仍被外键使用的索引。
 */
return new class extends Migration
{
    private const TABLE = 'events';

    private const FOREIGN_KEY = 'fk_events_organizer_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $definitions = $this->columnDefinitions();

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

        foreach ($this->indexDefinitions() as $name => $columns) {
            if (! Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->index($columns, $name));
            }
        }

        // 本迁移依赖 000003 已建好 ecosystem_organizers；缺表说明迁移顺序被破坏，
        // 必须显式失败，不能“跳过 FK 但迁移记成功”留下一个没有外键的半成品结构。
        if (! Schema::hasTable('ecosystem_organizers')) {
            throw new RuntimeException(
                'ecosystem_organizers 不存在，无法为 events.organizer_id 建立外键；请先执行 2026_10_19_000003。'
            );
        }

        if (! $this->hasForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('organizer_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('ecosystem_organizers')
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

        if ($this->hasForeignKey()) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
        }

        foreach (array_keys($this->indexDefinitions()) as $name) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($name));
            }
        }

        $columns = array_reverse(array_keys($this->columnDefinitions()));

        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($existingColumns !== []) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($existingColumns));
        }
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            // NULL = 历史未核定；三字段必须同时为空或同时齐全（EventSourceValidator）。
            'source_type'   => fn (Blueprint $table) => $table->string('source_type', 20)->nullable(),
            'platform_role' => fn (Blueprint $table) => $table->string('platform_role', 20)->nullable(),

            // 指向 ecosystem_organizers.id；不得指向 customers / sellers（ADR G1-D01）。
            'organizer_id'  => fn (Blueprint $table) => $table->unsignedBigInteger('organizer_id')->nullable(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function indexDefinitions(): array
    {
        return [
            'idx_events_source_role' => ['source_type', 'platform_role'],
            'idx_events_organizer_id' => ['organizer_id'],
        ];
    }

    private function hasForeignKey(): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', self::FOREIGN_KEY)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
