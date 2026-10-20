<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-10 · 奖项主体 / 奖项版本。
 *
 *   · 奖项版本显式引用一个成绩版本；成绩修订后旧奖项版本转 pending_review，
 *     不再冒充最终结论；
 *   · 新版本发布时旧 published 版本标 superseded，历史保留；
 *   · 公开只认“奖项 published + 版本 published + 结果版本仍是当前终审版本”。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_result_versions')) {
            throw new RuntimeException('G3-10 迁移缺少 competition_result_versions 前置表。');
        }

        if (! Schema::hasTable('competition_awards')) {
            Schema::create('competition_awards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->string('award_key', 64);
                $table->string('name', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 24)->default('draft');
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_award_versions')) {
            Schema::create('competition_award_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('award_id');
                $table->unsignedInteger('version_no');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('result_version_id');
                $table->unsignedInteger('rank_no')->nullable();
                $table->string('status', 24)->default('draft');
                $table->text('reason')->nullable();
                $table->json('snapshot')->nullable();
                $table->unsignedInteger('created_by_admin_id');
                $table->unsignedInteger('published_by_admin_id')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->unsignedInteger('revoked_by_admin_id')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->text('revocation_reason')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_awards', [
            'uk_competition_awards_track_key' => ['columns' => ['track_id', 'award_key'], 'unique' => true],
            'idx_competition_awards_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_award_versions', [
            'uk_competition_award_versions_no' => ['columns' => ['award_id', 'version_no'], 'unique' => true],
            'idx_competition_award_versions_result' => ['columns' => ['result_version_id', 'status'], 'unique' => false],
            'idx_competition_award_versions_status' => ['columns' => ['award_id', 'status'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_awards', 'edition_id', 'fk_competition_awards_edition', 'competition_editions');
        $this->addForeignKey('competition_awards', 'track_id', 'fk_competition_awards_track', 'competition_tracks');
        $this->addForeignKey('competition_award_versions', 'award_id', 'fk_competition_award_versions_award', 'competition_awards');
        $this->addForeignKey('competition_award_versions', 'entry_id', 'fk_competition_award_versions_entry', 'competition_entries');
        $this->addForeignKey('competition_award_versions', 'result_version_id', 'fk_competition_award_versions_result', 'competition_result_versions');
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_award_versions');
        Schema::dropIfExists('competition_awards');
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
