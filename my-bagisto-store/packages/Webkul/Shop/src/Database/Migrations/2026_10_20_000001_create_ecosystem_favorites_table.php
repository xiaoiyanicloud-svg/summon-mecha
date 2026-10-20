<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G4-02 · 用户收藏（活动 / 资讯）。
 *
 * 设计约束：
 *   · 目标用 `target_type + target_key` 多态引用，不建外键：撤稿/下架后关系保留，
 *     读取时由公开查询统一过滤；删除用户时级联清理（收藏是用户偏好，不是审计事实）。
 *   · 唯一键保证“同一用户、同一目标”只有一条，重复点击幂等。
 *   · 资讯用 slug 作为 key（发布后不可变），活动用 ID 字符串。
 *
 * 本迁移只建表，不写数据、不改既有表。MySQL 5.7 DDL 不在事务里，
 * 失败时按“缺什么补什么”重跑；表结构异常（缺主键）不自动修补。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_favorites';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();

                $table->unsignedInteger('customer_id');
                $table->string('target_type', 20);
                $table->string('target_key', 191);

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(
                    ['customer_id', 'target_type', 'target_key'],
                    'uk_ecosystem_favorites_customer_target'
                );
                $table->index(['target_type', 'target_key'], 'idx_ecosystem_favorites_target');
                $table->index(['customer_id', 'created_at'], 'idx_ecosystem_favorites_customer_created');

                $table->foreign('customer_id', 'fk_ecosystem_favorites_customer')
                    ->references('id')->on('customers')
                    ->onDelete('cascade')->onUpdate('restrict');
            });

            return;
        }

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

        if (! Schema::hasIndex(self::TABLE, 'uk_ecosystem_favorites_customer_target')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['customer_id', 'target_type', 'target_key'],
                    'uk_ecosystem_favorites_customer_target'
                );
            });
        }

        $foreignKeys = array_map(
            static fn (array $definition): string => (string) ($definition['name'] ?? ''),
            Schema::getForeignKeys(self::TABLE)
        );

        if (! in_array('fk_ecosystem_favorites_customer', $foreignKeys, true)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('customer_id', 'fk_ecosystem_favorites_customer')
                    ->references('id')->on('customers')
                    ->onDelete('cascade')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'customer_id' => fn (Blueprint $table) => $table->unsignedBigInteger('customer_id'),
            'target_type' => fn (Blueprint $table) => $table->string('target_type', 20),
            'target_key'  => fn (Blueprint $table) => $table->string('target_key', 191),
            'created_at'  => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'  => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
