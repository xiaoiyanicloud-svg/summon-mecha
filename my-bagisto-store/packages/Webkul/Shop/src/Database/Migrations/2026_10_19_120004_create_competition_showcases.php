<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-11 · 作品展示 / 授权 / 技能提审交接。
 *
 *   · 展示引用已提交的作品版本，不引用草稿；
 *   · 必须由队长对具体版本显式授权（consent_fingerprint 钉住版本内容）；
 *   · 获奖不自动上架、不发 license、不下发真机；技能提审是队长显式动作，
 *     调用既有 DeveloperSkillService 闸门；
 *   · 公开字段最小化：标题/摘要/队伍/奖项，不含附件/repo/video。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_submission_versions')) {
            throw new RuntimeException('G3-11 迁移缺少 competition_submission_versions 前置表。');
        }

        if (! Schema::hasTable('competition_showcases')) {
            Schema::create('competition_showcases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('submission_version_id');
                $table->unsignedBigInteger('award_id')->nullable();
                $table->string('showcase_type', 16)->default('recap');
                $table->string('title', 160);
                $table->text('summary');
                $table->string('status', 24)->default('draft');

                // 队长授权：具体版本 + 内容指纹 + 授权范围。
                $table->dateTime('consent_at')->nullable();
                $table->unsignedInteger('consent_by_customer_id')->nullable();
                $table->char('consent_fingerprint', 64)->nullable();
                $table->json('consent_scope')->nullable();

                $table->unsignedInteger('reviewed_by_admin_id')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->unsignedInteger('published_by_admin_id')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->text('rejected_reason')->nullable();

                // 技能提审交接（显式动作；不自动上架/发 license/下发）。
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->dateTime('developer_handoff_at')->nullable();
                $table->json('handoff_result')->nullable();

                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_showcases', [
            'uk_competition_showcases_version' => ['columns' => ['submission_version_id'], 'unique' => true],
            'idx_competition_showcases_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
            'idx_competition_showcases_entry' => ['columns' => ['entry_id'], 'unique' => false],
            'idx_competition_showcases_award' => ['columns' => ['award_id'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_showcases', 'edition_id', 'fk_competition_showcases_edition', 'competition_editions');
        $this->addForeignKey('competition_showcases', 'track_id', 'fk_competition_showcases_track', 'competition_tracks');
        $this->addForeignKey('competition_showcases', 'entry_id', 'fk_competition_showcases_entry', 'competition_entries');
        $this->addForeignKey('competition_showcases', 'submission_version_id', 'fk_competition_showcases_submission_version', 'competition_submission_versions');
        $this->addForeignKey('competition_showcases', 'award_id', 'fk_competition_showcases_award', 'competition_awards');
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_showcases');
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
