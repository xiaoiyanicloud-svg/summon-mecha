<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G4-03 · 生态关联（活动 / 资讯 → 活动 / 资讯 / 机型 / 技能 / 场景）。
 *
 * 多态引用刻意不建外键：目标可能来自不同域（活动、内容、商品、机型、场景），
 * 且目标下架不应删除运营配置。**目标是否仍可展示由 EcosystemRelationService
 * 在读取时逐项复核**，失效项整项省略。
 *
 * 不存外链 URL / 目标标题快照：避免配置里出现第二个真相源，也避免目标改名后
 * 展示旧标题。唯一键保证同一来源对同一目标只有一条关系。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_relations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();

                $table->string('source_type', 20);
                $table->string('source_key', 191);

                $table->string('target_type', 20);
                $table->string('target_key', 191);

                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('status', 20)->default('active');

                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(
                    ['source_type', 'source_key', 'target_type', 'target_key'],
                    'uk_ecosystem_relations_source_target'
                );
                $table->index(
                    ['source_type', 'source_key', 'status', 'sort_order'],
                    'idx_ecosystem_relations_source'
                );
                $table->index(['target_type', 'target_key'], 'idx_ecosystem_relations_target');
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

        if (! Schema::hasIndex(self::TABLE, 'uk_ecosystem_relations_source_target')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['source_type', 'source_key', 'target_type', 'target_key'],
                    'uk_ecosystem_relations_source_target'
                );
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
            'source_type' => fn (Blueprint $table) => $table->string('source_type', 20),
            'source_key'  => fn (Blueprint $table) => $table->string('source_key', 191),
            'target_type' => fn (Blueprint $table) => $table->string('target_type', 20),
            'target_key'  => fn (Blueprint $table) => $table->string('target_key', 191),
            'sort_order'  => fn (Blueprint $table) => $table->unsignedSmallInteger('sort_order')->default(0),
            'status'      => fn (Blueprint $table) => $table->string('status', 20)->default('active'),
            'created_by'  => fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable(),
            'created_at'  => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'  => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
