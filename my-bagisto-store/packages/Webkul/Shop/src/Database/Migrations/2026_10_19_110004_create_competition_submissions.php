<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-05 · 作品提交 / 版本 / 私有附件（G3 实施计划 §6）。
 *
 *   · 一个 entry 一个 submission 主体；版本号递增、提交后不可变；
 *   · 截止时间以 UTC 存储，服务层在取得锁后重新核验；
 *   · 附件独立私有盘（storage_key 由服务端生成），不进入公开 DTO；
 *     `entry_id` 冗余保存，用于下载时按队伍重新授权。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_entries')) {
            throw new RuntimeException('G3-05 迁移缺少 competition_entries 前置表。');
        }

        if (! Schema::hasTable('competition_submissions')) {
            Schema::create('competition_submissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('track_id');
                $table->string('status', 20)->default('draft');
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->dateTime('deadline_at')->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_submission_versions')) {
            Schema::create('competition_submission_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('submission_id');
                $table->unsignedInteger('version_no');
                $table->string('title', 160);
                $table->string('summary', 500)->nullable();
                $table->text('description')->nullable();
                $table->string('repo_url', 500)->nullable();
                $table->string('video_url', 500)->nullable();
                $table->char('content_fingerprint', 64);
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('created_by_customer_id');
                $table->dateTime('submitted_at')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_submission_attachments')) {
            Schema::create('competition_submission_attachments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('version_id');
                $table->unsignedBigInteger('entry_id');
                $table->string('original_name', 191);
                $table->string('storage_key', 191);
                $table->string('mime', 100);
                $table->unsignedInteger('byte_size');
                $table->char('sha256', 64);
                $table->unsignedInteger('created_by_customer_id');
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_submissions', [
            'uk_competition_submissions_entry' => ['columns' => ['entry_id'], 'unique' => true],
            'idx_competition_submissions_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_submission_versions', [
            'uk_competition_submission_versions_no' => ['columns' => ['submission_id', 'version_no'], 'unique' => true],
            'idx_competition_submission_versions_status' => ['columns' => ['submission_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_submission_attachments', [
            'uk_competition_submission_attachments_storage' => ['columns' => ['storage_key'], 'unique' => true],
            'idx_competition_submission_attachments_version' => ['columns' => ['version_id'], 'unique' => false],
            'idx_competition_submission_attachments_entry' => ['columns' => ['entry_id'], 'unique' => false],
            'idx_competition_submission_attachments_sha' => ['columns' => ['sha256'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_submissions', 'entry_id', 'fk_competition_submissions_entry', 'competition_entries');
        $this->addForeignKey('competition_submissions', 'track_id', 'fk_competition_submissions_track', 'competition_tracks');
        $this->addForeignKey('competition_submission_versions', 'submission_id', 'fk_competition_submission_versions_submission', 'competition_submissions');
        $this->addForeignKey('competition_submission_attachments', 'version_id', 'fk_competition_submission_attachments_version', 'competition_submission_versions');
        $this->addForeignKey('competition_submission_attachments', 'entry_id', 'fk_competition_submission_attachments_entry', 'competition_entries');
    }

    public function down(): void
    {
        foreach (['competition_submission_attachments', 'competition_submission_versions', 'competition_submissions'] as $table) {
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
