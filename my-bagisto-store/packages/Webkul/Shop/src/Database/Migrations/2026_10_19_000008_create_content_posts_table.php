<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-05 · `content_posts`（ADR-0001 §4.6 / G1-D06）。
 *
 * 资讯是**独立内容域**：与 CMS 协议静态页、翻译页彻底分开，不借用 `cms_pages`，
 * 也不建 `cms_page_id`。本表只保存文章的发布轴 / 处置轴与双 revision 指针；
 * 标题、正文、作者、来源等公开内容都属于 `content_revisions`。
 *
 * 边界：
 *   · `slug` 全局唯一（`uk(slug)`）；撤稿后**永久保留**，不复用 —— 唯一键是这条规则的数据库兜底；
 *   · `published_revision_id` / `working_revision_id` 本迁移只建列与索引，
 *     外键在 000009 建好 `content_revisions` 之后由 000011 补上
 *     （此时 `content_revisions` 尚不存在，提前建 FK 会让干净库迁移失败）；
 *   · `created_by` / `updated_by` **刻意不建外键**：后台账号可能归档，审计事实必须保留。
 *
 * 前置表缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'content_posts';

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
            // 部分失败后的重跑：表在但列不齐时“缺什么补什么”。
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
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // down 只回收本迁移自己的对象。000011 的指针外键必须先由它自己撤销；
        // 若这里仍有外键指向 content_revisions，删表会失败 —— 让它失败，
        // 而不是静默删掉别处迁移的约束。
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            // news / announcement / case / competition_report。
            'type'                   => fn (Blueprint $table) => $table->string('type', 20),
            // 全局唯一；小写 ASCII/数字/连字符；撤稿后不复用。
            'slug'                   => fn (Blueprint $table) => $table->string('slug', 120),
            'category'               => fn (Blueprint $table) => $table->string('category', 64)->nullable(),

            // 发布轴 / 处置轴（与活动同词表，但互不影响）。
            'publish_status'         => fn (Blueprint $table) => $table->string('publish_status', 20)->default('draft'),
            'disposition'            => fn (Blueprint $table) => $table->string('disposition', 20)->default('normal'),

            // 公开 / 工作双指针；FK 由 000011 补上。
            'published_revision_id'  => fn (Blueprint $table) => $table->unsignedBigInteger('published_revision_id')->nullable(),
            'working_revision_id'    => fn (Blueprint $table) => $table->unsignedBigInteger('working_revision_id')->nullable(),

            // UTC 存储；首次发布时间之后不再刷新（ADR G1-D06）。
            'first_published_at'     => fn (Blueprint $table) => $table->dateTime('first_published_at')->nullable(),
            'published_at'           => fn (Blueprint $table) => $table->dateTime('published_at')->nullable(),

            'row_version'            => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),

            // 后台用户 ID；不建外键（见类注释）。
            'created_by'             => fn (Blueprint $table) => $table->unsignedBigInteger('created_by'),
            'updated_by'             => fn (Blueprint $table) => $table->unsignedBigInteger('updated_by')->nullable(),

            'created_at'             => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'             => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'uk_content_posts_slug' => [
                'columns' => ['slug'],
                'unique'  => true,
            ],
            'idx_content_posts_type_first_published_at' => [
                'columns' => ['type', 'first_published_at'],
                'unique'  => false,
            ],
            'idx_content_posts_category' => [
                'columns' => ['category'],
                'unique'  => false,
            ],
            'idx_content_posts_publish_status_disposition_first_published_at' => [
                'columns' => ['publish_status', 'disposition', 'first_published_at'],
                'unique'  => false,
            ],
            'idx_content_posts_published_revision_id' => [
                'columns' => ['published_revision_id'],
                'unique'  => false,
            ],
            'idx_content_posts_working_revision_id' => [
                'columns' => ['working_revision_id'],
                'unique'  => false,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('admins')) {
            throw new RuntimeException(
                'admins 不存在，无法创建 content_posts；请先完成 Bagisto 基础迁移。'
            );
        }
    }
};
