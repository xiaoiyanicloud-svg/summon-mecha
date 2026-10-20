<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-06 · 阶段 / 场次（G3 实施计划 §7）。
 *
 *   · 阶段属于赛项；同赛项内 stage_key 唯一；
 *   · 场次属于阶段/赛项/届次，但 edition_id 冗余保存用于跨届隔离与查询；
 *   · unique(edition_id, match_key)：同一届内场次键唯一，重试导入不重复；
 *   · 时间字段 UTC；home/away entry 可为空（待定），一旦引用必须是同赛项 entry。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_tracks') || ! Schema::hasTable('competition_entries')) {
            throw new RuntimeException('G3-06 迁移缺少 competition_tracks / competition_entries 前置表。');
        }

        if (! Schema::hasTable('competition_stages')) {
            Schema::create('competition_stages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->string('stage_key', 50);
                $table->string('name', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 20)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_matches')) {
            Schema::create('competition_matches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('stage_id');
                $table->string('match_key', 64);
                $table->unsignedInteger('round_no')->default(1);
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('venue', 191)->nullable();
                $table->unsignedBigInteger('home_entry_id')->nullable();
                $table->unsignedBigInteger('away_entry_id')->nullable();
                $table->string('status', 20)->default('draft');
                $table->dateTime('published_at')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_stages', [
            'uk_competition_stages_track_key' => ['columns' => ['track_id', 'stage_key'], 'unique' => true],
            'idx_competition_stages_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_matches', [
            'uk_competition_matches_edition_key' => ['columns' => ['edition_id', 'match_key'], 'unique' => true],
            'idx_competition_matches_stage_status' => ['columns' => ['stage_id', 'status'], 'unique' => false],
            'idx_competition_matches_track_time' => ['columns' => ['track_id', 'starts_at'], 'unique' => false],
            'idx_competition_matches_venue_time' => ['columns' => ['venue', 'starts_at'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_stages', 'edition_id', 'fk_competition_stages_edition', 'competition_editions');
        $this->addForeignKey('competition_stages', 'track_id', 'fk_competition_stages_track', 'competition_tracks');
        $this->addForeignKey('competition_matches', 'edition_id', 'fk_competition_matches_edition', 'competition_editions');
        $this->addForeignKey('competition_matches', 'track_id', 'fk_competition_matches_track', 'competition_tracks');
        $this->addForeignKey('competition_matches', 'stage_id', 'fk_competition_matches_stage', 'competition_stages');
        $this->addForeignKey('competition_matches', 'home_entry_id', 'fk_competition_matches_home_entry', 'competition_entries');
        $this->addForeignKey('competition_matches', 'away_entry_id', 'fk_competition_matches_away_entry', 'competition_entries');
    }

    public function down(): void
    {
        foreach (['competition_matches', 'competition_stages'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  array<string, array{columns: array<int, string>, unique: bool}>  $indexes
     */
    private function addIndexes(string $tableName, array $indexes): void
    {
        foreach ($indexes as $name => $definition) {
            if (Schema::hasIndex($tableName, $name)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }
    }

    private function addForeignKey(string $tableName, string $column, string $name, string $references): void
    {
        if ($this->hasForeignKey($tableName, $name)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $name, $references): void {
            $table->foreign($column, $name)
                ->references('id')->on($references)
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    private function hasForeignKey(string $tableName, string $name): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
