<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-04 · `event_rule_versions`（ADR-0001 §4.5）。
 *
 * 活动规则的版本化记录：规则随活动演进，已批准版本不可改正文/文件，
 * 新规则只能发新 `version_no`（G2 报名绑定具体版本与指纹时依赖这一点）。
 *
 * 边界：
 *   · `event_id` 外键指向 `events.id`，RESTRICT/RESTRICT —— 有规则版本的活动不可物理删除；
 *   · `uk(event_id, version_no)` 保证版本号在活动内唯一、不复用；
 *   · `file_media_id` **本批不建外键**：`ecosystem_media` 要到 G1-06 才建，
 *     现在指向不存在的表会让干净库迁移失败；G1-06 建表后再补 FK。
 *
 * 前置表缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'event_rule_versions';

    private const FK_EVENT = 'fk_event_rule_versions_event_id';

    public function up(): void
    {
        $this->assertPrerequisites();

        $definitions = $this->columnDefinitions();

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($definitions): void {
                $table->id();

                foreach ($definitions as $definition) {
                    $definition($table);
                }
            });
        } else {
            $missingColumns = array_values(array_filter(
                array_keys($definitions),
                fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
            ));

            if ($missingColumns !== []) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($definitions, $missingColumns): void {
                    foreach ($missingColumns as $column) {
                        $definitions[$column]($table);
                    }
                });
            }
        }

        foreach ($this->indexDefinitions() as $name => $definition) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }

        if (! $this->hasForeignKey(self::FK_EVENT)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('event_id', self::FK_EVENT)
                    ->references('id')
                    ->on('events')
                    ->onDelete('restrict')
                    ->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasForeignKey(self::FK_EVENT)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FK_EVENT));
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'event_id'            => fn (Blueprint $table) => $table->unsignedBigInteger('event_id'),
            'version_no'          => fn (Blueprint $table) => $table->unsignedInteger('version_no'),

            'title'               => fn (Blueprint $table) => $table->string('title', 255),
            'authority_language'  => fn (Blueprint $table) => $table->string('authority_language', 10),

            // 平台获准存储的正文；仅有外链许可时只能存 URL，不得复制全文（ADR §4.5 不变式）。
            'body_html'           => fn (Blueprint $table) => $table->mediumText('body_html')->nullable(),
            // 官方规则链接；不建索引。
            'external_url'        => fn (Blueprint $table) => $table->string('external_url', 2048)->nullable(),

            // 获准存储的规则文件；G1-06 建 ecosystem_media 后才补外键。
            'file_media_id'       => fn (Blueprint $table) => $table->unsignedBigInteger('file_media_id')->nullable(),

            'content_fingerprint' => fn (Blueprint $table) => $table->char('content_fingerprint', 64),
            'change_summary'      => fn (Blueprint $table) => $table->string('change_summary', 500),

            // UTC 存储（ADR G1-D04）；NULL = 尚未确定生效时间。
            'effective_from'      => fn (Blueprint $table) => $table->dateTime('effective_from')->nullable(),

            // draft -> approved -> superseded / withdrawn。
            'status'              => fn (Blueprint $table) => $table->string('status', 20)->default('draft'),

            // 审核记录；后台用户 ID 不建外键，理由同 event_revisions。
            'reviewed_by'         => fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at'         => fn (Blueprint $table) => $table->dateTime('reviewed_at')->nullable(),

            'created_by'          => fn (Blueprint $table) => $table->unsignedBigInteger('created_by'),

            'created_at'          => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'          => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'uk_event_rule_versions_event_version' => [
                'columns' => ['event_id', 'version_no'],
                'unique'  => true,
            ],
            'idx_event_rule_versions_event_status' => [
                'columns' => ['event_id', 'status'],
                'unique'  => false,
            ],
            'idx_event_rule_versions_file_media_id' => [
                'columns' => ['file_media_id'],
                'unique'  => false,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('events')) {
            throw new RuntimeException(
                'events 不存在，无法创建 event_rule_versions；请先执行 G1-02 的 2026_10_19_000001。'
            );
        }
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
