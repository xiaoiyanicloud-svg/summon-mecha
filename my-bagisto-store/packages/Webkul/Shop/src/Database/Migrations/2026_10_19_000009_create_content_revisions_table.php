<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-05 · `content_revisions`（ADR-0001 §4.7 / G1-D06）。
 *
 * 一篇文章的公开内容快照：标题、摘要、正文、作者、来源类型、指纹与审核轴。
 * revision 一旦离开 draft 即不可变，编辑只能创建 `revision_no + 1`。
 *
 * 边界：
 *   · `uk(post_id, revision_no)` 保证版本号在文章内唯一、不复用；
 *   · `post_id` 外键指向 `content_posts.id`，RESTRICT/RESTRICT ——
 *     有 revision 的文章不可物理删除；
 *   · `created_by` / `submitted_by` / `reviewed_by` **刻意不建外键**，理由同 G1-04；
 *   · `media_manifest_hash` 本批保持可空，媒体表/FK 留 G1-06；
 *   · `sanitizer_version` 非空：G1-05 写入占位版本 `unsanitized-g1-05`，
 *     净化本身留 G1-07，渲染端必须能看到“尚未净化”这一事实。
 *
 * 前置表缺失时显式 throw，不静默跳过。
 */
return new class extends Migration
{
    private const TABLE = 'content_revisions';

    private const FK_POST = 'fk_content_revisions_post_id';

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

        if (! $this->hasForeignKey(self::FK_POST)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('post_id', self::FK_POST)
                    ->references('id')
                    ->on('content_posts')
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

        // 必须先撤销本表自己的外键；000011 指向本表的外键归 000011 撤销。
        if ($this->hasForeignKey(self::FK_POST)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(self::FK_POST));
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'post_id'                => fn (Blueprint $table) => $table->unsignedBigInteger('post_id'),
            'revision_no'            => fn (Blueprint $table) => $table->unsignedInteger('revision_no'),

            // 公开内容快照；非 draft 后由模型守卫禁止更新。
            'title'                  => fn (Blueprint $table) => $table->string('title', 255),
            'summary'                => fn (Blueprint $table) => $table->string('summary', 500)->nullable(),
            'body_html'              => fn (Blueprint $table) => $table->mediumText('body_html')->nullable(),
            'author_name'            => fn (Blueprint $table) => $table->string('author_name', 191),
            'author_org'             => fn (Blueprint $table) => $table->string('author_org', 191)->nullable(),

            // original / authorized_repost / link_summary。
            'source_type'            => fn (Blueprint $table) => $table->string('source_type', 20),
            // 原文时间；与平台首次发布时间分开。
            'original_published_at'  => fn (Blueprint $table) => $table->dateTime('original_published_at')->nullable(),

            // 公开更正说明；普通修改不得静默刷新首次发布时间。
            'correction_note'        => fn (Blueprint $table) => $table->text('correction_note')->nullable(),
            'change_summary'         => fn (Blueprint $table) => $table->string('change_summary', 500),

            // 内容 sha256 / 媒体集合指纹 / 富文本净化版本。
            'content_fingerprint'    => fn (Blueprint $table) => $table->char('content_fingerprint', 64),
            'media_manifest_hash'    => fn (Blueprint $table) => $table->char('media_manifest_hash', 64)->nullable(),
            'sanitizer_version'      => fn (Blueprint $table) => $table->string('sanitizer_version', 20),

            // 审核轴：draft -> in_review -> approved / rejected。
            'review_state'           => fn (Blueprint $table) => $table->string('review_state', 20)->default('draft'),

            // 后台用户 ID；不建外键（见类注释），也永不公开。
            'submitted_by'           => fn (Blueprint $table) => $table->unsignedBigInteger('submitted_by')->nullable(),
            'submitted_at'           => fn (Blueprint $table) => $table->dateTime('submitted_at')->nullable(),
            'reviewed_by'            => fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at'            => fn (Blueprint $table) => $table->dateTime('reviewed_at')->nullable(),
            'review_note'            => fn (Blueprint $table) => $table->text('review_note')->nullable(),

            // 该版本首次公开时间；NULL = 尚未发布过。
            'published_at'           => fn (Blueprint $table) => $table->dateTime('published_at')->nullable(),

            'created_by'             => fn (Blueprint $table) => $table->unsignedBigInteger('created_by'),

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
            'uk_content_revisions_post_revision' => [
                'columns' => ['post_id', 'revision_no'],
                'unique'  => true,
            ],
            'idx_content_revisions_post_review_state' => [
                'columns' => ['post_id', 'review_state'],
                'unique'  => false,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('content_posts')) {
            throw new RuntimeException(
                'content_posts 不存在，无法创建 content_revisions；请先执行 G1-05 的 2026_10_19_000008。'
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
