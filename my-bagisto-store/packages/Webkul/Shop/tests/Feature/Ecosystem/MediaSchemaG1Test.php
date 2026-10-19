<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G1-06 · 媒体域 schema 守卫（ADR-0001 §4.9 / §4.10 / G1-D06）。
 *
 * 测的是库里的真实结构，不是迁移代码“看起来写了什么”：
 *   · `ecosystem_media` / `ecosystem_media_refs` 全列类型 / NULL / 默认值；
 *   · 唯一键与索引按名字、列序、唯一性比对；
 *   · `ecosystem_media_refs.media_id` 外键指向媒体表且 RESTRICT；
 *   · `owner_type` **没有**多态外键（归属对象是否存在由下一批服务校验）；
 *   · `event_rule_versions.file_media_id` 的 G1-06 外键与既有索引；
 *   · 唯一约束 / 外键真实阻止重复与删除。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / seed。
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-06 media schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g6SchemaColumnMeta(string $table, string $column): ?object
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
function g6SchemaIndexMeta(string $table, string $index): array
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

function g6SchemaNormalizeColumnType(string $type): string
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
function g6SchemaForeignKeysOn(string $table): array
{
    return DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('COLUMN_NAME')
        ->all();
}

function g6SchemaForeignKey(string $table, string $constraint): ?object
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
function g6SchemaMedia(array $overrides = []): int
{
    $sha = str_pad(bin2hex(random_bytes(32)), 64, '0');

    return (int) DB::table('ecosystem_media')->insertGetId(array_merge([
        'disk'          => 'local',
        'storage_key'   => 'ecosystem/media/'.substr($sha, 0, 2).'/'.$sha.'.png',
        'original_name' => 'cover.png',
        'mime'          => 'image/png',
        'byte_size'     => 1234,
        'width'         => 320,
        'height'        => 200,
        'sha256'        => $sha,
        'status'        => 'pending',
        'uploaded_by'   => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6SchemaRef(int $mediaId, array $overrides = []): int
{
    return (int) DB::table('ecosystem_media_refs')->insertGetId(array_merge([
        'media_id'      => $mediaId,
        'owner_type'    => 'event_revision',
        'owner_id'      => 1,
        'role'          => 'cover',
        'sort_order'    => 0,
        'alt_text'      => null,
        'review_status' => 'pending',
        'created_at'    => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6SchemaEvent(array $overrides = []): int
{
    return (int) DB::table('events')->insertGetId(array_merge([
        'name'       => 'G6 媒体活动',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6SchemaRule(int $eventId, array $overrides = []): int
{
    return (int) DB::table('event_rule_versions')->insertGetId(array_merge([
        'event_id'            => $eventId,
        'version_no'          => 1,
        'title'               => 'G6 规则',
        'authority_language'  => 'zh-CN',
        'content_fingerprint' => str_repeat('c', 64),
        'change_summary'      => '初始规则',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

it('G1-06 · ecosystem_media 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'id'            => ['bigint unsigned', 'NO', null],
        'disk'          => ['varchar(50)', 'NO', null],
        'storage_key'   => ['varchar(255)', 'NO', null],
        'original_name' => ['varchar(255)', 'YES', null],
        'mime'          => ['varchar(100)', 'NO', null],
        'byte_size'     => ['bigint unsigned', 'NO', null],
        'width'         => ['int unsigned', 'YES', null],
        'height'        => ['int unsigned', 'YES', null],
        'sha256'        => ['char(64)', 'NO', null],
        'status'        => ['varchar(20)', 'NO', 'pending'],
        'uploaded_by'   => ['bigint unsigned', 'NO', null],
        'created_at'    => ['timestamp', 'YES', null],
        'updated_at'    => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g6SchemaColumnMeta('ecosystem_media', $column);

        expect($meta)->not->toBeNull();
        expect(g6SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    $id = g6SchemaColumnMeta('ecosystem_media', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-06 · ecosystem_media 唯一键与 status 索引按名字和列序存在', function () {
    expect(g6SchemaIndexMeta('ecosystem_media', 'uk_ecosystem_media_storage_key'))
        ->toBe(['columns' => ['storage_key'], 'unique' => true])
        ->and(g6SchemaIndexMeta('ecosystem_media', 'uk_ecosystem_media_sha256'))
        ->toBe(['columns' => ['sha256'], 'unique' => true])
        ->and(g6SchemaIndexMeta('ecosystem_media', 'idx_ecosystem_media_status'))
        ->toBe(['columns' => ['status'], 'unique' => false]);
});

it('G1-06 · ecosystem_media 无外键，uploaded_by 只存后台账号 ID', function () {
    expect(g6SchemaForeignKeysOn('ecosystem_media'))->toBe([])
        ->and(Schema::hasColumn('ecosystem_media', 'uploaded_by'))->toBeTrue();
});

it('G1-06 · ecosystem_media_refs 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        'id'            => ['bigint unsigned', 'NO', null],
        'media_id'      => ['bigint unsigned', 'NO', null],
        'owner_type'    => ['varchar(30)', 'NO', null],
        'owner_id'      => ['bigint unsigned', 'NO', null],
        'role'          => ['varchar(20)', 'NO', null],
        'sort_order'    => ['int unsigned', 'NO', '0'],
        'alt_text'      => ['varchar(255)', 'YES', null],
        'review_status' => ['varchar(20)', 'NO', 'pending'],
        'created_at'    => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g6SchemaColumnMeta('ecosystem_media_refs', $column);

        expect($meta)->not->toBeNull();
        expect(g6SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    // ADR §4.10 没有 updated_at：引用是审核快照。
    expect(Schema::hasColumn('ecosystem_media_refs', 'updated_at'))->toBeFalse();
});

it('G1-06 · ecosystem_media_refs 索引 / 唯一键 / 外键符合 ADR', function () {
    expect(g6SchemaIndexMeta('ecosystem_media_refs', 'idx_ecosystem_media_refs_media_id'))
        ->toBe(['columns' => ['media_id'], 'unique' => false])
        ->and(g6SchemaIndexMeta('ecosystem_media_refs', 'uk_ecosystem_media_refs_owner_sort'))
        ->toBe(['columns' => ['owner_type', 'owner_id', 'sort_order'], 'unique' => true]);

    $fk = g6SchemaForeignKey('ecosystem_media_refs', 'fk_ecosystem_media_refs_media_id');

    expect($fk)->not->toBeNull()
        ->and($fk->column_name)->toBe('media_id')
        ->and($fk->referenced_table_name)->toBe('ecosystem_media')
        ->and($fk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $fk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $fk->update_rule))->toBe('RESTRICT');

    // owner_type 是多态语义列，**不得**建外键。
    expect(g6SchemaForeignKeysOn('ecosystem_media_refs'))->toBe(['media_id']);
});

it('G1-06 · 唯一约束真实阻止重复 storage_key / sha256 / owner 排序', function () {
    $mediaId = g6SchemaMedia(['storage_key' => 'ecosystem/media/aa/fixed.png', 'sha256' => str_repeat('a', 64)]);

    expect(fn () => g6SchemaMedia(['storage_key' => 'ecosystem/media/aa/fixed.png']))
        ->toThrow(QueryException::class);

    expect(fn () => g6SchemaMedia(['sha256' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);

    g6SchemaRef($mediaId, ['owner_type' => 'event_revision', 'owner_id' => 7, 'sort_order' => 0]);

    expect(fn () => g6SchemaRef($mediaId, ['owner_type' => 'event_revision', 'owner_id' => 7, 'sort_order' => 0]))
        ->toThrow(QueryException::class);

    // 不同 sort_order / owner_id / owner_type 均可共存。
    g6SchemaRef($mediaId, ['owner_type' => 'event_revision', 'owner_id' => 7, 'sort_order' => 1]);
    g6SchemaRef($mediaId, ['owner_type' => 'content_revision', 'owner_id' => 7, 'sort_order' => 0]);
    g6SchemaRef($mediaId, ['owner_type' => 'event_revision', 'owner_id' => 8, 'sort_order' => 0]);

    expect(DB::table('ecosystem_media_refs')->count())->toBe(4);
});

it('G1-06 · owner_type 无多态外键：不存在的 owner_id 仍可按约定写入', function () {
    $mediaId = g6SchemaMedia();

    // 归属对象是否存在由 MediaRefService（下一批）校验，数据库刻意不建多态 FK。
    g6SchemaRef($mediaId, ['owner_type' => 'content_revision', 'owner_id' => 999999999]);

    expect(DB::table('ecosystem_media_refs')->where('owner_id', 999999999)->exists())->toBeTrue();
});

it('G1-06 · media 外键真实阻止删除被引用媒体，未被引用的媒体仍可删除', function () {
    $referenced = g6SchemaMedia();
    $orphan = g6SchemaMedia();
    g6SchemaRef($referenced);

    expect(fn () => DB::table('ecosystem_media')->where('id', $referenced)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('ecosystem_media')->where('id', $orphan)->delete())->toBe(1);
});

it('G1-06 · event_rule_versions.file_media_id 外键指向媒体表且既有索引保留', function () {
    $fk = g6SchemaForeignKey('event_rule_versions', 'fk_event_rule_versions_file_media_id');

    expect($fk)->not->toBeNull()
        ->and($fk->column_name)->toBe('file_media_id')
        ->and($fk->referenced_table_name)->toBe('ecosystem_media')
        ->and($fk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $fk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $fk->update_rule))->toBe('RESTRICT');

    expect(g6SchemaIndexMeta('event_rule_versions', 'idx_event_rule_versions_file_media_id'))
        ->toBe(['columns' => ['file_media_id'], 'unique' => false]);

    // 外键真实阻止删除被规则版本引用的媒体。
    $mediaId = g6SchemaMedia();
    $eventId = g6SchemaEvent();
    g6SchemaRule($eventId, ['file_media_id' => $mediaId]);

    expect(fn () => DB::table('ecosystem_media')->where('id', $mediaId)->delete())
        ->toThrow(QueryException::class);
});
