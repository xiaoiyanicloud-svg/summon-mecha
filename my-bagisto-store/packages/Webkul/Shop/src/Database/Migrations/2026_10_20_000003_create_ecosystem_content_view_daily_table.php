<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G4-06 · 内容访问日聚合。
 *
 * 只存“日期 + 对象 + 次数”，**不存 IP / UA / 用户 ID / 访客哈希**：
 *   · 去重键只在 cache 中短时存在（见 ContentViewRecorder）；
 *   · 指标页只读聚合值，无法反查到个人。
 *
 * 唯一键保证同一对象同一天只有一行，写入走原子 upsert/increment。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_content_view_daily';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();

                $table->date('view_date');
                $table->string('target_type', 20);
                $table->string('target_key', 191);
                $table->unsignedBigInteger('views')->default(0);

                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(
                    ['view_date', 'target_type', 'target_key'],
                    'uk_ecosystem_content_view_daily_day_target'
                );
                $table->index(['target_type', 'target_key', 'view_date'], 'idx_ecosystem_content_view_daily_target');
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

        if (! Schema::hasIndex(self::TABLE, 'uk_ecosystem_content_view_daily_day_target')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['view_date', 'target_type', 'target_key'],
                    'uk_ecosystem_content_view_daily_day_target'
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
            'view_date'   => fn (Blueprint $table) => $table->date('view_date'),
            'target_type' => fn (Blueprint $table) => $table->string('target_type', 20),
            'target_key'  => fn (Blueprint $table) => $table->string('target_key', 191),
            'views'       => fn (Blueprint $table) => $table->unsignedBigInteger('views')->default(0),
            'created_at'  => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'  => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
