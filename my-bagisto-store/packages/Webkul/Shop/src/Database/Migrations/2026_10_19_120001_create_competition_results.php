<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-08 · 成绩主体 / 不可变版本。
 *
 *   · 一个 (track, entry, scope_key) 一行成绩主体；scope_key 为 `track`
 *     或 `match:{id}`，用字符串唯一键绕开 MySQL “NULL 不参与唯一约束”；
 *   · 版本提交后不可变；修订新建版本，旧版本标 superseded，永不删除；
 *   · 分数是定点字符串 + 策略快照，不存浮点；排名/并列组随版本冻结。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_entries') || ! Schema::hasTable('competition_matches')) {
            throw new RuntimeException('G3-08 迁移缺少 competition_entries / competition_matches 前置表。');
        }

        if (! Schema::hasTable('competition_results')) {
            Schema::create('competition_results', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('match_id')->nullable();
                $table->string('scope_key', 64)->default('track');
                $table->string('status', 24)->default('draft');
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->unsignedBigInteger('published_version_id')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_result_versions')) {
            Schema::create('competition_result_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('result_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('match_id')->nullable();
                $table->unsignedInteger('version_no');
                $table->string('status', 24)->default('draft');
                // 修订后旧版本变为 superseded；这里保留它被替换前的公布状态，
                // 公开页才能诚实显示“旧结论为暂定/终审，当前复核中”。
                $table->string('superseded_status', 24)->nullable();
                $table->unsignedBigInteger('supersedes_version_id')->nullable();

                // 分数：字段 key => {value, scaled, status}；全程定点字符串。
                $table->json('score_values')->nullable();
                $table->string('total_scaled', 32)->nullable();
                $table->unsignedInteger('rank_no')->nullable();
                $table->string('tie_group', 32)->nullable();
                $table->string('forfeit_status', 16)->default('none');
                $table->boolean('excluded')->default(false);

                $table->string('scoring_policy', 32);
                $table->json('scoring_snapshot');
                $table->char('scoring_fingerprint', 64);

                $table->unsignedBigInteger('rules_version_id')->nullable();
                $table->char('rules_fingerprint', 64)->nullable();

                $table->text('reason')->nullable();
                $table->unsignedInteger('created_by_admin_id');
                $table->dateTime('submitted_at')->nullable();
                $table->unsignedInteger('reviewed_by_admin_id')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->unsignedInteger('finalized_by_admin_id')->nullable();
                $table->dateTime('finalized_at')->nullable();
                $table->text('revision_reason')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_results', [
            'uk_competition_results_scope' => ['columns' => ['track_id', 'entry_id', 'scope_key'], 'unique' => true],
            'idx_competition_results_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
            'idx_competition_results_edition' => ['columns' => ['edition_id', 'status'], 'unique' => false],
            'idx_competition_results_entry' => ['columns' => ['entry_id'], 'unique' => false],
            'idx_competition_results_match' => ['columns' => ['match_id'], 'unique' => false],
        ]);

        $this->addIndexes('competition_result_versions', [
            'uk_competition_result_versions_no' => ['columns' => ['result_id', 'version_no'], 'unique' => true],
            'idx_competition_result_versions_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
            'idx_competition_result_versions_entry' => ['columns' => ['entry_id'], 'unique' => false],
            'idx_competition_result_versions_fingerprint' => ['columns' => ['scoring_fingerprint'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_results', 'edition_id', 'fk_competition_results_edition', 'competition_editions');
        $this->addForeignKey('competition_results', 'track_id', 'fk_competition_results_track', 'competition_tracks');
        $this->addForeignKey('competition_results', 'entry_id', 'fk_competition_results_entry', 'competition_entries');
        $this->addForeignKey('competition_results', 'match_id', 'fk_competition_results_match', 'competition_matches');
        $this->addForeignKey('competition_result_versions', 'result_id', 'fk_competition_result_versions_result', 'competition_results');
        $this->addForeignKey('competition_result_versions', 'track_id', 'fk_competition_result_versions_track', 'competition_tracks');
        $this->addForeignKey('competition_result_versions', 'entry_id', 'fk_competition_result_versions_entry', 'competition_entries');
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_result_versions');
        Schema::dropIfExists('competition_results');
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
