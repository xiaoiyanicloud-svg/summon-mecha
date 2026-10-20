<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2-02 · 报名身份、轮次与类型统一（G2 实施计划 §3.1）。
 *
 * 只做 expand：保留旧 `name/phone/city/note`，不删除、不改写历史行。
 * 历史行统一标记 `identity_kind=legacy`，`scope_id/subject_key` 保持 NULL，
 * 因此不会被新流程按手机号自动认领，也不会撞 `uk(scope_id, subject_key)`。
 *
 * 类型统一：既有 `event_registrations.event_id` 是 INT UNSIGNED，而 `events.id`
 * 是 BIGINT UNSIGNED。建外键前必须改成 BIGINT，否则 MySQL 拒绝建约束。
 *
 * 迁移前 preflight（缺表/缺列/孤儿行/越界）显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'event_registrations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('event_registrations 不存在，无法执行 G2-02 增量迁移。');
        }

        if (! Schema::hasTable('event_registration_scopes')) {
            throw new RuntimeException('event_registration_scopes 不存在；请先执行 2026_10_19_100001。');
        }

        // ---- preflight ----
        $orphan = (int) DB::table(self::TABLE)
            ->leftJoin('events', 'events.id', '=', 'event_registrations.event_id')
            ->whereNull('events.id')
            ->count();

        if ($orphan > 0) {
            throw new RuntimeException(sprintf(
                'event_registrations 存在 %d 条找不到 events 的孤儿行；请先生成核查报告并人工处置，迁移不得静默删除或改号。',
                $orphan
            ));
        }

        // 1) event_id -> BIGINT UNSIGNED（保留索引）。
        $currentType = (string) (DB::selectOne(
            "SHOW COLUMNS FROM `event_registrations` LIKE 'event_id'"
        )?->Type ?? '');

        if (stripos($currentType, 'bigint') === false) {
            DB::statement(
                'ALTER TABLE `event_registrations` '
                .'MODIFY `event_id` BIGINT UNSIGNED NOT NULL'
            );
        }

        // 2) 增量列。
        $definitions = $this->columnDefinitions();
        $missing = array_values(array_filter(
            array_keys($definitions),
            fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
        ));

        if ($missing !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($definitions, $missing): void {
                foreach ($missing as $column) {
                    $definitions[$column]($table);
                }
            });
        }

        // 3) 历史行 identity_kind=legacy（新列为 default legacy，但存量行需要回填确认）。
        DB::table(self::TABLE)
            ->whereNull('scope_id')
            ->where(function ($query): void {
                $query->whereNull('identity_kind')->orWhere('identity_kind', '');
            })
            ->update(['identity_kind' => 'legacy']);

        // 4) 索引与唯一约束。
        if (! Schema::hasIndex(self::TABLE, 'uk_event_registrations_scope_subject')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                // 两列都可空：历史行不参与唯一性；新行由服务层保证 NOT NULL。
                $table->unique(['scope_id', 'subject_key'], 'uk_event_registrations_scope_subject');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'uk_event_registrations_public_no')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('public_no', 'uk_event_registrations_public_no');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'idx_event_registrations_scope_attempt')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scope_id', 'current_attempt_id'], 'idx_event_registrations_scope_attempt');
            });
        }

        // 5) 外键：event_id -> events.id；scope_id -> scopes.id。
        if (! $this->hasForeignKey('fk_event_registrations_event')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('event_id', 'fk_event_registrations_event')
                    ->references('id')->on('events')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }

        if (! $this->hasForeignKey('fk_event_registrations_scope')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('scope_id', 'fk_event_registrations_scope')
                    ->references('id')->on('event_registration_scopes')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['fk_event_registrations_scope', 'fk_event_registrations_event'] as $constraint) {
            if ($this->hasForeignKey($constraint)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign($constraint));
            }
        }

        // 只删本迁移新增的列；旧业务列（name/phone/...）永远保留。
        $drop = array_values(array_filter(
            ['scope_id', 'subject_key', 'public_no', 'current_attempt_id', 'identity_kind', 'row_version'],
            fn (string $column): bool => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($drop !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }

        // event_id 类型不回退：回退 BIGINT -> INT 可能截断新数据，且旧代码仍可读 BIGINT。
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'scope_id'           => fn (Blueprint $table) => $table->unsignedBigInteger('scope_id')->nullable(),
            // 服务端生成的身份键 `c:{customer_id}`；历史匿名行保持 NULL。
            'subject_key'        => fn (Blueprint $table) => $table->string('subject_key', 100)->nullable(),
            // 公开编号不是授权凭证；随机生成，避免顺序枚举。
            'public_no'          => fn (Blueprint $table) => $table->string('public_no', 32)->nullable(),
            'current_attempt_id' => fn (Blueprint $table) => $table->unsignedBigInteger('current_attempt_id')->nullable(),
            'identity_kind'      => fn (Blueprint $table) => $table->string('identity_kind', 20)->default('legacy'),
            'row_version'        => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
        ];
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
