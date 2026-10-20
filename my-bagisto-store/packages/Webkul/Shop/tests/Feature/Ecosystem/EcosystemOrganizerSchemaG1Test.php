<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;

/**
 * G1-03 · 主办方与来源 schema 守卫（ADR-0001 §4.2 / §4.4 / §4.3）。
 *
 * 测的是库里的真实结构：
 *   · `ecosystem_organizers` 全列类型 / NULL / 默认值；
 *   · `name(64)` 前缀索引（SUB_PART=64），不是普通全列索引；
 *   · `events` 三列、两个索引、一个 RESTRICT 外键；
 *   · 旧 `events.organizer` 自由文本**不会**自动填成来源（三方不推定）；
 *   · `event_registrations` 保持不变；G1-04 的 revision 表已建，G1-05/06 的表仍不得提前出现；
 *   · 迁移后四个公开/报名开关仍为 false。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / seed。
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-03 schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

/**
 * 读真实列定义（别名避开 information_schema 在不同 MySQL 版本里的大小写差异）。
 */
function g3SchemaColumnMeta(string $table, string $column): ?object
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
 * @return array{columns: array<int, string>, unique: bool, sub_parts: array<int, int|null>}|array{}
 */
function g3SchemaIndexMeta(string $table, string $index): array
{
    $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]);

    if (! $rows) {
        return [];
    }

    return [
        'columns'   => array_map(fn ($row) => $row->Column_name, $rows),
        'unique'    => ((int) $rows[0]->Non_unique) === 0,
        'sub_parts' => array_map(
            fn ($row) => $row->Sub_part === null ? null : (int) $row->Sub_part,
            $rows
        ),
    ];
}

/**
 * MySQL 5.7 的 COLUMN_TYPE 带整数显示宽度（bigint(20)），MySQL 8 已去掉。
 * ADR 冻结的是语义类型，比较前归一化显示宽度。
 */
function g3SchemaNormalizeColumnType(string $type): string
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
function g3SchemaForeignKeysOn(string $table): array
{
    return DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('COLUMN_NAME')
        ->all();
}

function g3SchemaForeignKey(string $table, string $constraint): ?object
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

it('G1-03 · ecosystem_organizers 全列类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'id'                   => ['bigint unsigned', 'NO', null],
        'name'                 => ['varchar(191)', 'NO', null],
        'platform_role'        => ['varchar(20)', 'NO', null],
        'source_url'           => ['varchar(2048)', 'YES', null],
        'public_materials'     => ['text', 'YES', null],
        'private_evidence_ref' => ['varchar(255)', 'YES', null],
        'verified_by'          => ['bigint unsigned', 'YES', null],
        'verified_at'          => ['datetime', 'YES', null],
        'status'               => ['varchar(20)', 'NO', 'pending'],
        'verification_note'    => ['text', 'YES', null],
        'created_at'           => ['timestamp', 'YES', null],
        'updated_at'           => ['timestamp', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g3SchemaColumnMeta('ecosystem_organizers', $column);

        expect($meta)->not->toBeNull();
        expect(g3SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }

    // id 必须是 BIGINT UNSIGNED 自增主键。
    $id = g3SchemaColumnMeta('ecosystem_organizers', 'id');

    expect(strtolower((string) $id->extra))->toBe('auto_increment')
        ->and((string) $id->column_key)->toBe('PRI');
});

it('G1-03 · name 是 64 前缀索引，status_role / status 索引按名字与列序存在', function () {
    expect(g3SchemaIndexMeta('ecosystem_organizers', 'idx_ecosystem_organizers_name'))
        ->toBe([
            'columns'   => ['name'],
            'unique'    => false,
            'sub_parts' => [64],
        ])
        ->and(g3SchemaIndexMeta('ecosystem_organizers', 'idx_ecosystem_organizers_status_role'))
        ->toBe([
            'columns'   => ['status', 'platform_role'],
            'unique'    => false,
            'sub_parts' => [null, null],
        ])
        ->and(g3SchemaIndexMeta('ecosystem_organizers', 'idx_ecosystem_organizers_status'))
        ->toBe([
            'columns'   => ['status'],
            'unique'    => false,
            'sub_parts' => [null],
        ]);
});

it('G1-03 · verified_by 不建外键', function () {
    expect(g3SchemaForeignKeysOn('ecosystem_organizers'))->toBe([]);
});

it('G1-03 · 新主体省略 status / verified_by / verified_at 时落库为 pending / NULL', function () {
    $id = DB::table('ecosystem_organizers')->insertGetId([
        'name'          => 'G3 默认值主办方',
        'platform_role' => 'organizer',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $row = DB::table('ecosystem_organizers')->where('id', $id)->first();

    expect($row->status)->toBe('pending')
        ->and($row->verified_by)->toBeNull()
        ->and($row->verified_at)->toBeNull()
        ->and($row->source_url)->toBeNull()
        ->and($row->public_materials)->toBeNull()
        ->and($row->private_evidence_ref)->toBeNull()
        ->and($row->verification_note)->toBeNull();
});

it('G1-03 · events 三个来源列类型 / NULL / 默认值符合 ADR', function () {
    $expectations = [
        'source_type'   => ['varchar(20)', 'YES', null],
        'platform_role' => ['varchar(20)', 'YES', null],
        'organizer_id'  => ['bigint unsigned', 'YES', null],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g3SchemaColumnMeta('events', $column);

        expect($meta)->not->toBeNull();
        expect(g3SchemaNormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }
});

it('G1-03 · events 来源索引与 organizer 外键（RESTRICT / RESTRICT）', function () {
    expect(g3SchemaIndexMeta('events', 'idx_events_source_role'))
        ->toBe([
            'columns'   => ['source_type', 'platform_role'],
            'unique'    => false,
            'sub_parts' => [null, null],
        ])
        ->and(g3SchemaIndexMeta('events', 'idx_events_organizer_id'))
        ->toBe([
            'columns'   => ['organizer_id'],
            'unique'    => false,
            'sub_parts' => [null],
        ]);

    $foreignKey = g3SchemaForeignKey('events', 'fk_events_organizer_id');

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey->column_name)->toBe('organizer_id')
        ->and($foreignKey->referenced_table_name)->toBe('ecosystem_organizers')
        ->and($foreignKey->referenced_column_name)->toBe('id')
        ->and(strtoupper((string) $foreignKey->delete_rule))->toBe('RESTRICT')
        ->and(strtoupper((string) $foreignKey->update_rule))->toBe('RESTRICT');
});

it('G1-03 · 被活动引用的 organizer 物理删除被 FK RESTRICT 拒绝', function () {
    $organizer = EcosystemOrganizer::create([
        'name'          => 'G3 RESTRICT 主办方',
        'platform_role' => 'organizer',
    ]);

    $event = Event::create([
        'name'          => 'G3 RESTRICT 活动',
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $organizer->id,
    ]);

    // 直接走 Eloquent delete，不经过 OrganizerService（服务层本来就不提供 delete）。
    expect(fn () => $organizer->delete())->toThrow(QueryException::class);

    expect(EcosystemOrganizer::query()->find($organizer->id))->not->toBeNull()
        ->and(Event::query()->find($event->getKey()))->not->toBeNull()
        ->and(Event::query()->find($event->getKey())->organizer_id)->toBe((int) $organizer->id);
});

it('G1-03 · 旧 organizer 自由文本不会自动填成来源三字段', function () {
    $event = Event::create([
        'name'      => 'G3 旧文本主办方守卫',
        'organizer' => '某历史主办机构（自由文本）',
    ]);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect($fresh->organizer)->toBe('某历史主办机构（自由文本）')
        ->and($fresh->source_type)->toBeNull()
        ->and($fresh->platform_role)->toBeNull()
        ->and($fresh->organizer_id)->toBeNull();
});

it('G1-03 · event_registrations 保留 G0 旧列，并追加 G2 身份/轮次列', function () {
    $expected = [
        'id',
        'event_id',
        'customer_id',
        'name',
        'phone',
        'city',
        'note',
        'created_at',
        'updated_at',
        // G2-02 expand：旧列不删，只追加。
        'scope_id',
        // G3-03：队伍报名归属；历史个人报名为 NULL。
        'team_id',
        'subject_key',
        'public_no',
        'current_attempt_id',
        'identity_kind',
        'row_version',
    ];

    $actual = Schema::getColumnListing('event_registrations');
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('G1-05 · revision、内容域与媒体域已建', function () {
    // G1-04 新增 revision 与规则版本；G1-05 新增内容域三表；G1-06 新增媒体域。
    expect(Schema::hasTable('event_revisions'))->toBeTrue()
        ->and(Schema::hasTable('event_rule_versions'))->toBeTrue()
        ->and(Schema::hasTable('content_posts'))->toBeTrue()
        ->and(Schema::hasTable('content_revisions'))->toBeTrue()
        ->and(Schema::hasTable('content_sources'))->toBeTrue()
        ->and(Schema::hasTable('ecosystem_media'))->toBeTrue()
        ->and(Schema::hasTable('ecosystem_media_refs'))->toBeTrue();
});

it('G1-03 · 迁移后四个公开/报名开关仍为 false', function () {
    expect(config('ecosystem.events.public_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_write_verified'))->toBeFalse()
        ->and(config('ecosystem.content.public_enabled'))->toBeFalse();
});
