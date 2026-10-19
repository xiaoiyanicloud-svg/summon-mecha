<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G1-05 · 内容域三表 schema 守卫（ADR-0001 §4.6 / §4.7 / §4.8 / G1-D06）。
 *
 * 测的是库里的真实结构，不是迁移代码“看起来写了什么”：
 *   · `content_posts` / `content_revisions` / `content_sources` 全列类型 / NULL / 默认值；
 *   · 唯一键与索引按名字、列序、唯一性比对；
 *   · FK 指向、删除/更新规则（全部 RESTRICT）；
 *   · `content_posts` 双 revision 指针外键真实阻止删除被引用的 revision；
 *   · 内容域与 CMS 静态页彻底分开：`cms_pages` 仍是 10 页、`content_posts` 初始 0、
 *     三表都没有 `cms_page_id`；
 *   · `ecosystem_media` 仍不存在（G1-06 才建）；
 *   · 内容公开开关仍为 false。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / seed。
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-05 content schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

/**
 * 读真实列定义（别名避开 information_schema 在不同 MySQL 版本里的大小写差异）。
 */
function g5SchemaColumnMeta(string $table, string $column): ?object
{
    return DB::selectOne(
        'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
                EXTRA AS extra, COLUMN_KEY AS column_key
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
}

/**
 * @return array{columns: array<int, string>, unique: bool}|array{}
 */
function g5SchemaIndexMeta(string $table, string $index): array
{
    $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]);

    if (! $rows) {
        return [];
    }

    return [
        'columns' => array_map(fn ($row) => $row->Column_name, $rows),
        'unique'  => ((int) $rows[0]->Non_unique) === 0,
    ];
}

/**
 * MySQL 5.7 的 COLUMN_TYPE 带整数显示宽度（bigint(20) / int(10) unsigned），
 * MySQL 8 已去掉。ADR 冻结的是语义类型，比较前归一化显示宽度。
 */
function g5SchemaNormalizeColumnType(string $type): string
{
    return preg_replace(
        '/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/',
        '$1',
        strtolower($type)
    ) ?? $type;
}

/**
 * @return array<int, string>
 */
function g5SchemaForeignKeysOn(string $table): array
{
    return DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('COLUMN_NAME')
        ->all();
}

function g5SchemaForeignKey(string $table, string $constraint): ?object
{
    return DB::selectOne(
        'SELECT kcu.COLUMN_NAME AS column_name,
                kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
                kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
                rc.DELETE_RULE AS delete_rule,
                rc.UPDATE_RULE AS update_rule
           FROM information_schema.REFERENTIAL_CONSTRAINTS rc
           JOIN information_schema.KEY_COLUMN_USAGE kcu
             ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.TABLE_NAME = rc.TABLE_NAME
          WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
            AND rc.TABLE_NAME = ?
            AND rc.CONSTRAINT_NAME = ?',
        [$table, $constraint]
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g5SchemaPost(array $overrides = []): int
{
    return (int) DB::table('content_posts')->insertGetId(array_merge([
        'type'           => 'news',
        'slug'           => 'g5-schema-post-'.bin2hex(random_bytes(4)),
        'publish_status' => 'draft',
        'disposition'    => 'normal',
        'row_version'    => 0,
        'created_by'     => 1,
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g5SchemaRevision(int $postId, array $overrides = []): int
{
    return (int) DB::table('content_revisions')->insertGetId(array_merge([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => 'G5 内容修订',
        'author_name'         => 'G5 作者',
        'source_type'         => 'original',
        'change_summary'      => '初始版本',
        'content_fingerprint' => str_repeat('a', 64),
        'sanitizer_version'   => 'unsanitized-g1-05',
        'review_state'        => 'draft',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g5SchemaSource(int $revisionId, array $overrides = []): int
{
    return (int) DB::table('content_sources')->insertGetId(array_merge([
        'revision_id'        => $revisionId,
        'source_name'        => 'G5 来源',
        'source_verified_at' => now(),
        'rights_basis'       => 'platform_original',
        'sort_order'         => 0,
        'created_at'         => now(),
    ], $overrides));
}

it('G1-05 · content_posts 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'id'                    => ['bigint unsigned', 'NO', null],
        'type'                  => ['varchar(20)', 'NO', null],
        'slug'                  => ['varchar(120)', 'NO', null],
        'category'              => ['varchar(64)', 'YES', null],
        'publish_status'        => ['varchar(20)', 'NO', 'draft'],
        'disposition'           => ['varchar(20)', 'NO', 'normal'],
        'published_revision_id' => ['bigint unsigned', 'YES', null],
        'working_revision_id'   => ['bigint unsigned', 'YES', null],
        'first_published_at'    => ['datetime', 'YES', null],
        'published_at'          => ['datetime', 'YES', null],
        'row_version'           => ['int unsigned', 'NO', '0'],
        'created_by'            => ['bigint unsigned', 'NO', null],
        'updated_by'            => ['bigint unsigned', 'YES', null],
        'created_at'            => ['timestamp', 'YES', null],
        'updated_at'            => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g5SchemaColumnMeta('content_posts', $column);

        expect($meta)->not->toBeNull();
        expect(g5SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    $id = g5SchemaColumnMeta('content_posts', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-05 · content_posts 唯一约束与索引按名字和列序存在', function () {
    expect(g5SchemaIndexMeta('content_posts', 'uk_content_posts_slug'))
        ->toBe(['columns' => ['slug'], 'unique' => true])
        ->and(g5SchemaIndexMeta('content_posts', 'idx_content_posts_type_first_published_at'))
        ->toBe(['columns' => ['type', 'first_published_at'], 'unique' => false])
        ->and(g5SchemaIndexMeta('content_posts', 'idx_content_posts_category'))
        ->toBe(['columns' => ['category'], 'unique' => false])
        ->and(g5SchemaIndexMeta('content_posts', 'idx_content_posts_publish_status_disposition_first_published_at'))
        ->toBe(['columns' => ['publish_status', 'disposition', 'first_published_at'], 'unique' => false])
        ->and(g5SchemaIndexMeta('content_posts', 'idx_content_posts_published_revision_id'))
        ->toBe(['columns' => ['published_revision_id'], 'unique' => false])
        ->and(g5SchemaIndexMeta('content_posts', 'idx_content_posts_working_revision_id'))
        ->toBe(['columns' => ['working_revision_id'], 'unique' => false]);
});

it('G1-05 · content_posts 双 revision 指针外键指向 content_revisions 且为 RESTRICT', function () {
    foreach ([
        'fk_content_posts_published_revision_id' => 'published_revision_id',
        'fk_content_posts_working_revision_id'   => 'working_revision_id',
    ] as $constraint => $column) {
        $fk = g5SchemaForeignKey('content_posts', $constraint);

        expect($fk)->not->toBeNull()
            ->and($fk->column_name)->toBe($column)
            ->and($fk->referenced_table_name)->toBe('content_revisions')
            ->and($fk->referenced_column_name)->toBe('id')
            ->and(strtoupper((string) $fk->delete_rule))->toBe('RESTRICT')
            ->and(strtoupper((string) $fk->update_rule))->toBe('RESTRICT');
    }

    $foreignKeys = g5SchemaForeignKeysOn('content_posts');
    sort($foreignKeys);

    // created_by / updated_by 必须存在，但不得有外键。
    expect($foreignKeys)->toBe(['published_revision_id', 'working_revision_id'])
        ->and(Schema::hasColumn('content_posts', 'created_by'))->toBeTrue()
        ->and(Schema::hasColumn('content_posts', 'updated_by'))->toBeTrue();
});

it('G1-05 · content_revisions 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        'id'                    => ['bigint unsigned', 'NO', null],
        'post_id'               => ['bigint unsigned', 'NO', null],
        'revision_no'           => ['int unsigned', 'NO', null],
        'title'                 => ['varchar(255)', 'NO', null],
        'summary'               => ['varchar(500)', 'YES', null],
        'body_html'             => ['mediumtext', 'YES', null],
        'author_name'           => ['varchar(191)', 'NO', null],
        'author_org'            => ['varchar(191)', 'YES', null],
        'source_type'           => ['varchar(20)', 'NO', null],
        'original_published_at' => ['datetime', 'YES', null],
        'correction_note'       => ['text', 'YES', null],
        'change_summary'        => ['varchar(500)', 'NO', null],
        'content_fingerprint'   => ['char(64)', 'NO', null],
        'media_manifest_hash'   => ['char(64)', 'YES', null],
        'sanitizer_version'     => ['varchar(20)', 'NO', null],
        'review_state'          => ['varchar(20)', 'NO', 'draft'],
        'submitted_by'          => ['bigint unsigned', 'YES', null],
        'submitted_at'          => ['datetime', 'YES', null],
        'reviewed_by'           => ['bigint unsigned', 'YES', null],
        'reviewed_at'           => ['datetime', 'YES', null],
        'review_note'           => ['text', 'YES', null],
        'published_at'          => ['datetime', 'YES', null],
        'created_by'            => ['bigint unsigned', 'NO', null],
        'created_at'            => ['timestamp', 'YES', null],
        'updated_at'            => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g5SchemaColumnMeta('content_revisions', $column);

        expect($meta)->not->toBeNull();
        expect(g5SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    $id = g5SchemaColumnMeta('content_revisions', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-05 · content_revisions 唯一约束与索引按名字和列序存在', function () {
    expect(g5SchemaIndexMeta('content_revisions', 'uk_content_revisions_post_revision'))
        ->toBe(['columns' => ['post_id', 'revision_no'], 'unique' => true])
        ->and(g5SchemaIndexMeta('content_revisions', 'idx_content_revisions_post_review_state'))
        ->toBe(['columns' => ['post_id', 'review_state'], 'unique' => false]);
});

it('G1-05 · content_revisions 只有 post_id 外键，指向 content_posts 且为 RESTRICT', function () {
    $fk = g5SchemaForeignKey('content_revisions', 'fk_content_revisions_post_id');

    expect($fk)->not->toBeNull()
        ->and($fk->column_name)->toBe('post_id')
        ->and($fk->referenced_table_name)->toBe('content_posts')
        ->and($fk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $fk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $fk->update_rule))->toBe('RESTRICT');

    expect(g5SchemaForeignKeysOn('content_revisions'))->toBe(['post_id'])
        ->and(Schema::hasColumn('content_revisions', 'created_by'))->toBeTrue()
        ->and(Schema::hasColumn('content_revisions', 'submitted_by'))->toBeTrue()
        ->and(Schema::hasColumn('content_revisions', 'reviewed_by'))->toBeTrue();
});

it('G1-05 · content_sources 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        'id'                 => ['bigint unsigned', 'NO', null],
        'revision_id'        => ['bigint unsigned', 'NO', null],
        'source_name'        => ['varchar(191)', 'NO', null],
        'source_url'         => ['varchar(2048)', 'YES', null],
        'source_verified_at' => ['datetime', 'NO', null],
        'rights_basis'       => ['varchar(50)', 'NO', null],
        'rights_scope'       => ['varchar(255)', 'YES', null],
        'evidence_ref'       => ['varchar(255)', 'YES', null],
        'sort_order'         => ['int unsigned', 'NO', '0'],
        'created_at'         => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g5SchemaColumnMeta('content_sources', $column);

        expect($meta)->not->toBeNull();
        expect(g5SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    // ADR §4.8 没有 updated_at。
    expect(Schema::hasColumn('content_sources', 'updated_at'))->toBeFalse();
});

it('G1-05 · content_sources 索引 / 唯一键 / 外键符合 ADR', function () {
    expect(g5SchemaIndexMeta('content_sources', 'idx_content_sources_revision_id'))
        ->toBe(['columns' => ['revision_id'], 'unique' => false])
        ->and(g5SchemaIndexMeta('content_sources', 'uk_content_sources_revision_sort'))
        ->toBe(['columns' => ['revision_id', 'sort_order'], 'unique' => true]);

    $fk = g5SchemaForeignKey('content_sources', 'fk_content_sources_revision_id');

    expect($fk)->not->toBeNull()
        ->and($fk->column_name)->toBe('revision_id')
        ->and($fk->referenced_table_name)->toBe('content_revisions')
        ->and($fk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $fk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $fk->update_rule))->toBe('RESTRICT');

    expect(g5SchemaForeignKeysOn('content_sources'))->toBe(['revision_id']);
});

it('G1-05 · 三表省略可空列 / 默认列时落库默认值正确', function () {
    $postId = g5SchemaPost(['category' => null, 'updated_by' => null]);
    $revisionId = g5SchemaRevision($postId);
    $sourceId = g5SchemaSource($revisionId);

    $post = DB::table('content_posts')->where('id', $postId)->first();

    expect($post->publish_status)->toBe('draft')
        ->and($post->disposition)->toBe('normal')
        ->and((int) $post->row_version)->toBe(0)
        ->and($post->published_revision_id)->toBeNull()
        ->and($post->working_revision_id)->toBeNull()
        ->and($post->first_published_at)->toBeNull()
        ->and($post->published_at)->toBeNull()
        ->and($post->category)->toBeNull()
        ->and($post->updated_by)->toBeNull();

    $revision = DB::table('content_revisions')->where('id', $revisionId)->first();

    expect($revision->review_state)->toBe('draft')
        ->and($revision->summary)->toBeNull()
        ->and($revision->body_html)->toBeNull()
        ->and($revision->author_org)->toBeNull()
        ->and($revision->original_published_at)->toBeNull()
        ->and($revision->correction_note)->toBeNull()
        ->and($revision->media_manifest_hash)->toBeNull()
        ->and($revision->submitted_by)->toBeNull()
        ->and($revision->reviewed_by)->toBeNull()
        ->and($revision->review_note)->toBeNull()
        ->and($revision->published_at)->toBeNull();

    $source = DB::table('content_sources')->where('id', $sourceId)->first();

    expect((int) $source->sort_order)->toBe(0)
        ->and($source->source_url)->toBeNull()
        ->and($source->rights_scope)->toBeNull()
        ->and($source->evidence_ref)->toBeNull();
});

it('G1-05 · 唯一键真实阻止重复 slug / revision_no / 来源排序', function () {
    $postId = g5SchemaPost(['slug' => 'g5-unique-slug']);

    expect(fn () => g5SchemaPost(['slug' => 'g5-unique-slug']))
        ->toThrow(QueryException::class);

    g5SchemaRevision($postId, ['revision_no' => 1]);

    expect(fn () => g5SchemaRevision($postId, ['revision_no' => 1]))
        ->toThrow(QueryException::class);

    $revisionId = g5SchemaRevision($postId, ['revision_no' => 2]);
    g5SchemaSource($revisionId, ['sort_order' => 0]);

    expect(fn () => g5SchemaSource($revisionId, ['sort_order' => 0]))
        ->toThrow(QueryException::class);
});

it('G1-05 · 外键真实阻止删除被引用的文章 / revision / 来源父版本', function () {
    $postId = g5SchemaPost(['slug' => 'g5-fk-post']);
    $revisionId = g5SchemaRevision($postId);
    $otherRevisionId = g5SchemaRevision($postId, ['revision_no' => 2]);
    g5SchemaSource($revisionId);

    // 指针外键：被文章引用的 revision 不可删除。
    DB::table('content_posts')->where('id', $postId)->update(['working_revision_id' => $revisionId]);

    expect(fn () => DB::table('content_revisions')->where('id', $revisionId)->delete())
        ->toThrow(QueryException::class);

    // revision 外键：有 revision 的文章不可物理删除。
    expect(fn () => DB::table('content_posts')->where('id', $postId)->delete())
        ->toThrow(QueryException::class);

    // 来源外键：被来源引用的 revision 不可删除。
    DB::table('content_posts')->where('id', $postId)->update(['working_revision_id' => null]);

    expect(fn () => DB::table('content_revisions')->where('id', $revisionId)->delete())
        ->toThrow(QueryException::class);

    // 未被引用的 revision 仍可删（隔离验证，证明失败来自外键而不是别处）。
    expect(DB::table('content_revisions')->where('id', $otherRevisionId)->delete())->toBe(1);
});

it('G1-05 · 内容域与 CMS 静态页彻底分开：10 页不变、初始 0 篇、无 cms_page_id、无媒体表', function () {
    // ADR G1-D06：CMS 默认静态页不是资讯，不得把 CMS 页数量宣称成文章数。
    expect(DB::table('cms_pages')->count())->toBe(10)
        ->and(DB::table('content_posts')->count())->toBe(0);

    foreach (['content_posts', 'content_revisions', 'content_sources'] as $table) {
        expect(Schema::hasColumn($table, 'cms_page_id'))->toBeFalse();
    }

    expect(Schema::hasTable('ecosystem_media'))->toBeFalse()
        ->and(Schema::hasTable('ecosystem_media_refs'))->toBeFalse()
        ->and(config('ecosystem.content.public_enabled'))->toBeFalse();
});
