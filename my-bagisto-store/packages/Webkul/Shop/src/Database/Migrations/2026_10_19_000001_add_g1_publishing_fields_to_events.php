<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-02 · `events` 可信发布最小扩展（ADR-0001 §4.2 / §11.1）。
 *
 * 只做 additive：加列 + 加索引，不删旧列、不改旧列语义、不建外键。
 *
 * 为什么先不建 FK：
 *   `event_revisions` 要到 G1-04 才建；此时 `published_revision_id` /
 *   `working_revision_id` 只能是普通 BIGINT 指针。贸然指向一张不存在的表，
 *   迁移在干净库上跑不起来。G1-04 建表后再补 FK（ADR §4.2 已写明）。
 *
 * 存量行回填：
 *   `publish_status` / `disposition` / `row_version` 是 NOT NULL + 默认值，
 *   MySQL 加列时会用默认值填充既有行（draft / normal / 0），
 *   **不需要、也不允许**按旧 `status` / `date` / `type` 去猜 kind、hosting_status
 *   或时间（ADR §5.4 / §11.1 第 4 条）。
 *
 * 锁表提示：MySQL 5.7 DDL 不在事务里，失败不会整体回滚。迁移前按 §11.2 备份。
 * 本迁移按“缺什么补什么”写，便于部分失败后的重跑，但**不代表**可以跳过备份。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        $definitions = $this->columnDefinitions();

        $missingColumns = array_values(array_filter(
            array_keys($definitions),
            fn (string $column): bool => ! Schema::hasColumn('events', $column)
        ));

        if ($missingColumns !== []) {
            Schema::table('events', function (Blueprint $table) use ($definitions, $missingColumns): void {
                foreach ($missingColumns as $column) {
                    $definitions[$column]($table);
                }
            });
        }

        foreach ($this->indexDefinitions() as $name => $columns) {
            if (! Schema::hasIndex('events', $name)) {
                Schema::table('events', fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        // 先删索引，再逆序删列 —— 与 up() 的添加顺序相反。
        foreach (array_keys($this->indexDefinitions()) as $name) {
            if (Schema::hasIndex('events', $name)) {
                Schema::table('events', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }

        $columns = array_reverse(array_keys($this->columnDefinitions()));

        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('events', $column)
        ));

        if ($existingColumns !== []) {
            Schema::table('events', fn (Blueprint $table) => $table->dropColumn($existingColumns));
        }
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            // NULL = 历史未核定，不可发布（ADR §4.2 约束）。
            'kind'                  => fn (Blueprint $table) => $table->string('kind', 20)->nullable(),
            'participation_mode'    => fn (Blueprint $table) => $table->string('participation_mode', 20)->nullable(),

            // 结论型默认值刻意保持保守：新对象是草稿、正常处置，不是“已发布”。
            'publish_status'        => fn (Blueprint $table) => $table->string('publish_status', 20)->default('draft'),
            'disposition'           => fn (Blueprint $table) => $table->string('disposition', 20)->default('normal'),
            'hosting_status'        => fn (Blueprint $table) => $table->string('hosting_status', 20)->nullable(),

            // UTC 存储 + IANA 时区名；NULL = 待定（ADR G1-D04）。
            'timezone'              => fn (Blueprint $table) => $table->string('timezone', 64)->nullable(),
            'starts_at'             => fn (Blueprint $table) => $table->dateTime('starts_at')->nullable(),
            'ends_at'               => fn (Blueprint $table) => $table->dateTime('ends_at')->nullable(),

            // 公开/工作双指针；G1-02 只建列，G1-04 建 event_revisions 后再补 FK。
            'published_revision_id' => fn (Blueprint $table) => $table->unsignedBigInteger('published_revision_id')->nullable(),
            'working_revision_id'   => fn (Blueprint $table) => $table->unsignedBigInteger('working_revision_id')->nullable(),

            'first_published_at'    => fn (Blueprint $table) => $table->dateTime('first_published_at')->nullable(),
            'published_at'          => fn (Blueprint $table) => $table->dateTime('published_at')->nullable(),

            // 乐观并发：每次**真实字段变更** +1；no-op 保存不递增（见 EventWriteService）。
            'row_version'           => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function indexDefinitions(): array
    {
        return [
            'idx_events_publish_disposition_starts' => ['publish_status', 'disposition', 'starts_at'],
            'idx_events_hosting_status'             => ['hosting_status'],
            'idx_events_kind'                       => ['kind'],
            'idx_events_published_revision'         => ['published_revision_id'],
            'idx_events_working_revision'           => ['working_revision_id'],
        ];
    }
};
