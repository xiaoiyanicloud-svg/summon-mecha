<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-04 · `event_revisions` / `event_rule_versions` schema 守卫（ADR-0001 §4.3 / §4.5）。
 *
 * 测的是库里的真实结构，不是迁移代码“看起来写了什么”：
 *   · 两张表的全列类型 / NULL / 默认值逐字段比对；
 *   · 唯一约束与索引按名字、列序、唯一性比对；
 *   · FK 指向、删除/更新规则；
 *   · `event_rule_versions.file_media_id` 的外键由 G1-06（000014）补上；
 *   · `created_by` / `submitted_by` / `reviewed_by` 无外键，但列必须存在；
 *   · 模型守卫：非 draft 内容冻结、身份列冻结、禁止删除；
 *   · 唯一约束真实阻止重复版本号。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / seed。
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-04 revision schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

/**
 * 读真实列定义（别名避开 information_schema 在不同 MySQL 版本里的大小写差异）。
 */
function g4RevColumnMeta(string $table, string $column): ?object
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
function g4RevIndexMeta(string $table, string $index): array
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
function g4RevNormalizeColumnType(string $type): string
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
function g4RevForeignKeysOn(string $table): array
{
    return DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('COLUMN_NAME')
        ->all();
}

function g4RevForeignKey(string $table, string $constraint): ?object
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

function g4RevEvent(array $overrides = []): int
{
    return (int) DB::table('events')->insertGetId(array_merge([
        'name'       => 'G4 修订活动',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g4RevRevision(int $eventId, array $overrides = []): int
{
    return (int) DB::table('event_revisions')->insertGetId(array_merge([
        'event_id'                 => $eventId,
        'revision_no'              => 1,
        'name'                     => 'G4 修订快照',
        'timezone'                 => 'Asia/Shanghai',
        'source_type'              => 'platform',
        'platform_role'            => 'organizer',
        'organizer_name_snapshot'  => 'G4 主办方',
        'source_name'              => 'G4 来源',
        'content_fingerprint'      => str_repeat('a', 64),
        'change_summary'           => '初始版本',
        'created_by'               => 1,
        'created_at'               => now(),
        'updated_at'               => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g4RevRule(int $eventId, array $overrides = []): int
{
    return (int) DB::table('event_rule_versions')->insertGetId(array_merge([
        'event_id'            => $eventId,
        'version_no'          => 1,
        'title'               => 'G4 规则',
        'authority_language'  => 'zh-CN',
        'content_fingerprint' => str_repeat('b', 64),
        'change_summary'      => '初始规则',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

it('G1-04 · event_revisions 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'id'                         => ['bigint unsigned', 'NO', null],
        'event_id'                   => ['bigint unsigned', 'NO', null],
        'revision_no'                => ['int unsigned', 'NO', null],
        'name'                       => ['varchar(255)', 'NO', null],
        'summary'                    => ['varchar(500)', 'YES', null],
        'detail_html'                => ['mediumtext', 'YES', null],
        'city'                       => ['varchar(100)', 'YES', null],
        'location_text'              => ['varchar(255)', 'YES', null],
        'participation_mode'         => ['varchar(20)', 'YES', null],
        'timezone'                   => ['varchar(64)', 'NO', null],
        'starts_at'                  => ['datetime', 'YES', null],
        'ends_at'                    => ['datetime', 'YES', null],
        'source_type'                => ['varchar(20)', 'NO', null],
        'platform_role'              => ['varchar(20)', 'NO', null],
        'organizer_id'               => ['bigint unsigned', 'YES', null],
        'organizer_name_snapshot'    => ['varchar(191)', 'NO', null],
        'source_name'                => ['varchar(191)', 'NO', null],
        'source_url'                 => ['varchar(2048)', 'YES', null],
        'source_verified_at'         => ['datetime', 'YES', null],
        'authorization_basis'        => ['varchar(50)', 'YES', null],
        'authorization_evidence_ref' => ['varchar(255)', 'YES', null],
        'external_registration_url'  => ['varchar(2048)', 'YES', null],
        'rule_summary'               => ['text', 'YES', null],
        'content_fingerprint'        => ['char(64)', 'NO', null],
        'media_manifest_hash'        => ['char(64)', 'YES', null],
        'sanitizer_version'          => ['varchar(20)', 'YES', null],
        'review_state'               => ['varchar(20)', 'NO', 'draft'],
        'submitted_by'               => ['bigint unsigned', 'YES', null],
        'submitted_at'               => ['datetime', 'YES', null],
        'reviewed_by'                => ['bigint unsigned', 'YES', null],
        'reviewed_at'                => ['datetime', 'YES', null],
        'review_note'                => ['text', 'YES', null],
        'change_summary'             => ['varchar(500)', 'NO', null],
        'published_at'               => ['datetime', 'YES', null],
        'created_by'                 => ['bigint unsigned', 'NO', null],
        'created_at'                 => ['timestamp', 'YES', null],
        'updated_at'                 => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g4RevColumnMeta('event_revisions', $column);

        expect($meta)->not->toBeNull();
        expect(g4RevNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    $id = g4RevColumnMeta('event_revisions', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-04 · event_revisions 唯一约束与索引按名字和列序存在', function () {
    expect(g4RevIndexMeta('event_revisions', 'uk_event_revisions_event_revision'))
        ->toBe(['columns' => ['event_id', 'revision_no'], 'unique' => true])
        ->and(g4RevIndexMeta('event_revisions', 'idx_event_revisions_event_review_state'))
        ->toBe(['columns' => ['event_id', 'review_state'], 'unique' => false])
        ->and(g4RevIndexMeta('event_revisions', 'idx_event_revisions_organizer_id'))
        ->toBe(['columns' => ['organizer_id'], 'unique' => false]);
});

it('G1-04 · event_revisions 两个外键指向正确且为 RESTRICT / RESTRICT', function () {
    $eventFk = g4RevForeignKey('event_revisions', 'fk_event_revisions_event_id');

    expect($eventFk)->not->toBeNull()
        ->and($eventFk->column_name)->toBe('event_id')
        ->and($eventFk->referenced_table_name)->toBe('events')
        ->and($eventFk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $eventFk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $eventFk->update_rule))->toBe('RESTRICT');

    $organizerFk = g4RevForeignKey('event_revisions', 'fk_event_revisions_organizer_id');

    expect($organizerFk)->not->toBeNull()
        ->and($organizerFk->column_name)->toBe('organizer_id')
        ->and($organizerFk->referenced_table_name)->toBe('ecosystem_organizers')
        ->and($organizerFk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $organizerFk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $organizerFk->update_rule))->toBe('RESTRICT');

    // created_by / submitted_by / reviewed_by 必须存在，但不得有外键。
    $foreignKeys = g4RevForeignKeysOn('event_revisions');
    sort($foreignKeys);

    expect($foreignKeys)->toBe(['event_id', 'organizer_id'])
        ->and(Schema::hasColumn('event_revisions', 'created_by'))->toBeTrue()
        ->and(Schema::hasColumn('event_revisions', 'submitted_by'))->toBeTrue()
        ->and(Schema::hasColumn('event_revisions', 'reviewed_by'))->toBeTrue();
});

it('G1-04 · 新 revision 省略审核字段时落库为 draft，可选指纹/净化版本为 NULL', function () {
    $eventId = g4RevEvent();
    $revisionId = g4RevRevision($eventId);

    $row = DB::table('event_revisions')->where('id', $revisionId)->first();

    expect($row->review_state)->toBe('draft')
        ->and($row->media_manifest_hash)->toBeNull()
        ->and($row->sanitizer_version)->toBeNull()
        ->and($row->submitted_by)->toBeNull()
        ->and($row->submitted_at)->toBeNull()
        ->and($row->reviewed_by)->toBeNull()
        ->and($row->reviewed_at)->toBeNull()
        ->and($row->review_note)->toBeNull()
        ->and($row->published_at)->toBeNull()
        ->and($row->organizer_id)->toBeNull();
});

it('G1-04 · event_revisions 唯一约束真实阻止同活动重复 revision_no', function () {
    $eventId = g4RevEvent();
    g4RevRevision($eventId, ['revision_no' => 1]);

    expect(fn () => g4RevRevision($eventId, ['revision_no' => 1]))
        ->toThrow(QueryException::class);
});

it('G1-04 · event_rule_versions 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'id'                  => ['bigint unsigned', 'NO', null],
        'event_id'            => ['bigint unsigned', 'NO', null],
        'version_no'          => ['int unsigned', 'NO', null],
        'title'               => ['varchar(255)', 'NO', null],
        'authority_language'  => ['varchar(10)', 'NO', null],
        'body_html'           => ['mediumtext', 'YES', null],
        'external_url'        => ['varchar(2048)', 'YES', null],
        'file_media_id'       => ['bigint unsigned', 'YES', null],
        'content_fingerprint' => ['char(64)', 'NO', null],
        'change_summary'      => ['varchar(500)', 'NO', null],
        'effective_from'      => ['datetime', 'YES', null],
        'status'              => ['varchar(20)', 'NO', 'draft'],
        'reviewed_by'         => ['bigint unsigned', 'YES', null],
        'reviewed_at'         => ['datetime', 'YES', null],
        'created_by'          => ['bigint unsigned', 'NO', null],
        'created_at'          => ['timestamp', 'YES', null],
        'updated_at'          => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g4RevColumnMeta('event_rule_versions', $column);

        expect($meta)->not->toBeNull();
        expect(g4RevNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    $id = g4RevColumnMeta('event_rule_versions', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-04 · event_rule_versions 唯一约束与索引按名字和列序存在', function () {
    expect(g4RevIndexMeta('event_rule_versions', 'uk_event_rule_versions_event_version'))
        ->toBe(['columns' => ['event_id', 'version_no'], 'unique' => true])
        ->and(g4RevIndexMeta('event_rule_versions', 'idx_event_rule_versions_event_status'))
        ->toBe(['columns' => ['event_id', 'status'], 'unique' => false])
        ->and(g4RevIndexMeta('event_rule_versions', 'idx_event_rule_versions_file_media_id'))
        ->toBe(['columns' => ['file_media_id'], 'unique' => false]);
});

it('G1-04 · event_rule_versions 有 event 与 file_media 两个外键，后者指向 G1-06 媒体表', function () {
    $eventFk = g4RevForeignKey('event_rule_versions', 'fk_event_rule_versions_event_id');

    expect($eventFk)->not->toBeNull()
        ->and($eventFk->column_name)->toBe('event_id')
        ->and($eventFk->referenced_table_name)->toBe('events')
        ->and($eventFk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $eventFk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $eventFk->update_rule))->toBe('RESTRICT');

    $fileMediaFk = g4RevForeignKey('event_rule_versions', 'fk_event_rule_versions_file_media_id');

    expect($fileMediaFk)->not->toBeNull()
        ->and($fileMediaFk->column_name)->toBe('file_media_id')
        ->and($fileMediaFk->referenced_table_name)->toBe('ecosystem_media')
        ->and($fileMediaFk->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $fileMediaFk->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $fileMediaFk->update_rule))->toBe('RESTRICT');

    $foreignKeys = g4RevForeignKeysOn('event_rule_versions');
    sort($foreignKeys);

    expect($foreignKeys)->toBe(['event_id', 'file_media_id']);
});

it('G1-04 · event_rule_versions 唯一约束真实阻止同活动重复 version_no', function () {
    $eventId = g4RevEvent();
    g4RevRule($eventId, ['version_no' => 1]);

    expect(fn () => g4RevRule($eventId, ['version_no' => 1]))
        ->toThrow(QueryException::class);
});

it('G1-04 · 新规则版本省略 status 时落库为 draft，可选列均为 NULL', function () {
    $eventId = g4RevEvent();
    $ruleId = g4RevRule($eventId);

    $row = DB::table('event_rule_versions')->where('id', $ruleId)->first();

    expect($row->status)->toBe('draft')
        ->and($row->body_html)->toBeNull()
        ->and($row->external_url)->toBeNull()
        ->and($row->file_media_id)->toBeNull()
        ->and($row->effective_from)->toBeNull()
        ->and($row->reviewed_by)->toBeNull()
        ->and($row->reviewed_at)->toBeNull();
});

it('G1-04 · EventRevision 模型：非 draft 后内容冻结、身份列冻结、禁止删除', function () {
    $eventId = g4RevEvent();
    $revisionId = g4RevRevision($eventId);

    $revision = EventRevision::query()->findOrFail($revisionId);
    $revision->name = '草稿阶段可改';
    $revision->save();

    // draft -> in_review 本身是合法流转。
    $revision->review_state = 'in_review';
    $revision->submitted_by = 1;
    $revision->submitted_at = now();
    $revision->save();

    // 提审后内容冻结。
    $revision->name = '提审后不应可改';
    expect(fn () => $revision->save())->toThrow(RevisionImmutableException::class);

    $revision->refresh();
    expect($revision->name)->toBe('草稿阶段可改');

    // 身份列永远冻结。
    $revision->revision_no = 2;
    expect(fn () => $revision->save())->toThrow(RevisionImmutableException::class);

    $revision->refresh();
    $revision->event_id = $eventId + 1;
    expect(fn () => $revision->save())->toThrow(RevisionImmutableException::class);

    // 审计证据禁止删除。
    expect(fn () => $revision->delete())->toThrow(RevisionImmutableException::class);
});

it('G1-04 · EventRuleVersion 模型：非 draft 后规则内容冻结、身份列冻结、禁止删除', function () {
    $eventId = g4RevEvent();
    $ruleId = g4RevRule($eventId);

    $rule = EventRuleVersion::query()->findOrFail($ruleId);
    $rule->title = '草稿阶段可改';
    $rule->save();

    $rule->status = 'approved';
    $rule->reviewed_by = 1;
    $rule->reviewed_at = now();
    $rule->save();

    $rule->body_html = '<p>批准后不应可改</p>';
    expect(fn () => $rule->save())->toThrow(RevisionImmutableException::class);

    $rule->refresh();
    expect($rule->body_html)->toBeNull();

    $rule->version_no = 2;
    expect(fn () => $rule->save())->toThrow(RevisionImmutableException::class);

    $rule->refresh();
    $rule->created_by = 99;
    expect(fn () => $rule->save())->toThrow(RevisionImmutableException::class);

    expect(fn () => $rule->delete())->toThrow(RevisionImmutableException::class);
});
