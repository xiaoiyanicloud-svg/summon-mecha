<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-09 · 申诉主体 / 追加事件 / 私有证据。
 *
 *   · 申诉必须绑定当次公布的成绩版本；裁决必须记录适用结果版本；
 *   · 事件表只追加，不更新/删除，形成可回查时间线；
 *   · 证据只存服务端路径与哈希，下载重新校验提交者/裁决权限。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_results') || ! Schema::hasTable('competition_result_versions')) {
            throw new RuntimeException('G3-09 迁移缺少成绩前置表。');
        }

        if (! Schema::hasTable('competition_appeals')) {
            Schema::create('competition_appeals', function (Blueprint $table): void {
                $table->id();
                $table->string('appeal_no', 32);
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('entry_id');
                $table->unsignedBigInteger('result_id');
                $table->unsignedBigInteger('result_version_id');
                $table->unsignedInteger('submitted_by_customer_id');
                $table->string('status', 24)->default('submitted');
                $table->text('reason');
                $table->unsignedBigInteger('rule_version_id')->nullable();
                $table->char('rules_fingerprint', 64)->nullable();
                $table->dateTime('window_opens_at')->nullable();
                $table->dateTime('deadline_at')->nullable();
                $table->dateTime('submitted_at');
                $table->dateTime('decided_at')->nullable();
                $table->unsignedInteger('decided_by_admin_id')->nullable();
                $table->string('decision_result', 24)->nullable();
                $table->text('decision_reason')->nullable();
                $table->unsignedBigInteger('decision_result_version_id')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_appeal_events')) {
            Schema::create('competition_appeal_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('appeal_id');
                $table->string('event_type', 24);
                $table->string('actor_type', 16);
                $table->unsignedInteger('actor_id')->nullable();
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('competition_appeal_attachments')) {
            Schema::create('competition_appeal_attachments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('appeal_id');
                $table->unsignedInteger('customer_id');
                $table->string('original_name', 191);
                $table->string('storage_key', 191);
                $table->string('mime', 100);
                $table->unsignedInteger('byte_size');
                $table->char('sha256', 64);
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_appeals', [
            'uk_competition_appeals_no' => ['columns' => ['appeal_no'], 'unique' => true],
            'idx_competition_appeals_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
            'idx_competition_appeals_result' => ['columns' => ['result_id'], 'unique' => false],
            'idx_competition_appeals_customer' => ['columns' => ['submitted_by_customer_id'], 'unique' => false],
            'idx_competition_appeals_deadline' => ['columns' => ['deadline_at'], 'unique' => false],
        ]);

        $this->addIndexes('competition_appeal_events', [
            'idx_competition_appeal_events_appeal' => ['columns' => ['appeal_id', 'id'], 'unique' => false],
        ]);

        $this->addIndexes('competition_appeal_attachments', [
            'uk_competition_appeal_attachments_storage' => ['columns' => ['storage_key'], 'unique' => true],
            'idx_competition_appeal_attachments_appeal' => ['columns' => ['appeal_id'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_appeals', 'edition_id', 'fk_competition_appeals_edition', 'competition_editions');
        $this->addForeignKey('competition_appeals', 'track_id', 'fk_competition_appeals_track', 'competition_tracks');
        $this->addForeignKey('competition_appeals', 'entry_id', 'fk_competition_appeals_entry', 'competition_entries');
        $this->addForeignKey('competition_appeals', 'result_id', 'fk_competition_appeals_result', 'competition_results');
        $this->addForeignKey('competition_appeal_events', 'appeal_id', 'fk_competition_appeal_events_appeal', 'competition_appeals');
        $this->addForeignKey('competition_appeal_attachments', 'appeal_id', 'fk_competition_appeal_attachments_appeal', 'competition_appeals');
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_appeal_attachments');
        Schema::dropIfExists('competition_appeal_events');
        Schema::dropIfExists('competition_appeals');
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
