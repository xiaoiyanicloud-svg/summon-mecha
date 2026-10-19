<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Event;

use function Pest\Laravel\getJson;

/**
 * G1-02 · `events` 增量 schema 与 `ecosystem_outbox` 结构守卫（ADR-0001 §4.2 / §4.11 / §11）。
 *
 * 这里测的是**库里的真实结构**，不是迁移代码“看起来写了什么”：
 *   · 新列的类型 / NULL / 默认值逐字段比对；
 *   · 索引按名字与列序比对（迁移列名写错时 Schema 会静默建在别的列上）；
 *   · 旧列、旧表 `event_registrations` 一个都不能变；
 *   · 新增指针列**没有** FK（G1-04 建 event_revisions 后才补）；
 *   · 迁移后公开读取三开关仍为 false，且接口行为不变。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / truncate / seed。
 * 运行必须指向独立库 summon_mecha_step10_test，禁止落到 root 主库。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-02 schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

/**
 * 读真实列定义（用别名避开 MySQL 8 information_schema 的大写列名）。
 */
function g1ColumnMeta(string $table, string $column): ?object
{
    return DB::selectOne(
        'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
}

/**
 * @return array{columns: array<int, string>, unique: bool}|array{}
 */
function g1IndexMeta(string $table, string $index): array
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
 * MySQL 5.7 的 information_schema.COLUMN_TYPE 会带整数显示宽度
 * （`bigint(20) unsigned` / `int(10) unsigned`），MySQL 8 已去掉显示宽度。
 * ADR 冻结的是语义类型（`bigint unsigned` / `int unsigned`），不是显示宽度，
 * 因此比较前先把整数类型的 `(N)` 归一化掉 —— 不能把 20/10 写死进断言。
 */
function g1NormalizeColumnType(string $type): string
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
function g1ForeignKeysOn(string $table): array
{
    return DB::table('information_schema.KEY_COLUMN_USAGE')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', $table)
        ->whereNotNull('REFERENCED_TABLE_NAME')
        ->pluck('COLUMN_NAME')
        ->all();
}

it('G1-02 · events 新列的类型 / NULL / 默认值符合 ADR 字段字典', function () {
    $expectations = [
        // column => [type, nullable, default]
        'kind'                  => ['varchar(20)', 'YES', null],
        'participation_mode'    => ['varchar(20)', 'YES', null],
        'publish_status'        => ['varchar(20)', 'NO', 'draft'],
        'disposition'           => ['varchar(20)', 'NO', 'normal'],
        'hosting_status'        => ['varchar(20)', 'YES', null],
        'timezone'              => ['varchar(64)', 'YES', null],
        'starts_at'             => ['datetime', 'YES', null],
        'ends_at'               => ['datetime', 'YES', null],
        'published_revision_id' => ['bigint unsigned', 'YES', null],
        'working_revision_id'   => ['bigint unsigned', 'YES', null],
        'first_published_at'    => ['datetime', 'YES', null],
        'published_at'          => ['datetime', 'YES', null],
        'row_version'           => ['int unsigned', 'NO', '0'],
    ];

    foreach ($expectations as $column => [$type, $nullable, $default]) {
        $meta = g1ColumnMeta('events', $column);

        expect($meta)->not->toBeNull();
        expect(g1NormalizeColumnType($meta->column_type))->toBe($type);
        expect($meta->is_nullable)->toBe($nullable);
        expect($meta->column_default === null ? null : (string) $meta->column_default)->toBe($default);
    }
});

it('G1-02 · events 五个新索引按名字和列序存在', function () {
    $indexes = [
        'idx_events_publish_disposition_starts' => ['publish_status', 'disposition', 'starts_at'],
        'idx_events_hosting_status'             => ['hosting_status'],
        'idx_events_kind'                       => ['kind'],
        'idx_events_published_revision'         => ['published_revision_id'],
        'idx_events_working_revision'           => ['working_revision_id'],
    ];

    foreach ($indexes as $name => $columns) {
        expect(g1IndexMeta('events', $name))
            ->toBe(['columns' => $columns, 'unique' => false]);
    }
});

it('G1-02 · 新增的 revision 指针列没有外键（G1-04 才建 event_revisions）', function () {
    expect(g1ForeignKeysOn('events'))->toBe([]);
});

it('G1-02 · events 旧列一个都不少，旧默认值不被改写', function () {
    $legacyColumns = [
        'id', 'name', 'type', 'status', 'date', 'city', 'location', 'organizer',
        'hot', 'teams', 'prize', 'schedule', 'history', 'detail', 'icon',
        'bg_color', 'sort', 'created_at', 'updated_at',
    ];

    foreach ($legacyColumns as $column) {
        expect(Schema::hasColumn('events', $column))->toBeTrue();
    }

    // 旧语义保持原样：type 默认中文标签、status 默认 registering。
    expect((string) g1ColumnMeta('events', 'type')->column_default)->toBe('格斗竞技')
        ->and((string) g1ColumnMeta('events', 'status')->column_default)->toBe('registering');
});

it('G1-02 · event_registrations 结构与 G0 收口时完全一致', function () {
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
    ];

    $actual = Schema::getColumnListing('event_registrations');
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('G1-02 · 新建活动不给新字段时落库为 draft / normal / 0，其余为 NULL', function () {
    $event = Event::create(['name' => 'G1-02 默认值守卫']);
    $event->refresh();

    expect($event->publish_status)->toBe('draft')
        ->and($event->disposition)->toBe('normal')
        ->and((int) $event->row_version)->toBe(0)
        ->and($event->kind)->toBeNull()
        ->and($event->participation_mode)->toBeNull()
        ->and($event->hosting_status)->toBeNull()
        ->and($event->timezone)->toBeNull()
        ->and($event->starts_at)->toBeNull()
        ->and($event->ends_at)->toBeNull()
        ->and($event->published_revision_id)->toBeNull()
        ->and($event->working_revision_id)->toBeNull()
        ->and($event->first_published_at)->toBeNull()
        ->and($event->published_at)->toBeNull();
});

it('G1-02 · ecosystem_outbox 列、索引与唯一约束完整，且没有外键', function () {
    $expectedColumns = [
        'id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'dedupe_key',
        'status',
        'attempts',
        'available_at',
        'lease_owner',
        'lease_until',
        'last_error',
        'created_at',
        'updated_at',
    ];

    $actualColumns = Schema::getColumnListing('ecosystem_outbox');
    sort($actualColumns);

    $expectedSorted = $expectedColumns;
    sort($expectedSorted);

    expect($actualColumns)->toBe($expectedSorted);

    expect(g1IndexMeta('ecosystem_outbox', 'uk_ecosystem_outbox_dedupe_key'))
        ->toBe(['columns' => ['dedupe_key'], 'unique' => true])
        ->and(g1IndexMeta('ecosystem_outbox', 'idx_ecosystem_outbox_aggregate'))
        ->toBe(['columns' => ['aggregate_type', 'aggregate_id'], 'unique' => false])
        ->and(g1IndexMeta('ecosystem_outbox', 'idx_ecosystem_outbox_event_type'))
        ->toBe(['columns' => ['event_type'], 'unique' => false])
        ->and(g1IndexMeta('ecosystem_outbox', 'idx_ecosystem_outbox_status_available'))
        ->toBe(['columns' => ['status', 'available_at'], 'unique' => false]);

    expect(g1ForeignKeysOn('ecosystem_outbox'))->toBe([]);
});

it('G1-02 · ecosystem_outbox 不给 status/attempts 时落库为 pending / 0', function () {
    $id = DB::table('ecosystem_outbox')->insertGetId([
        'aggregate_type' => 'event',
        'aggregate_id'   => 1,
        'event_type'     => 'event.published',
        'payload'        => json_encode([]),
        'available_at'   => now()->utc(),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $row = DB::table('ecosystem_outbox')->where('id', $id)->first();

    expect($row->status)->toBe('pending')
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->dedupe_key)->toBeNull()
        ->and($row->lease_owner)->toBeNull()
        ->and($row->lease_until)->toBeNull()
        ->and($row->last_error)->toBeNull();
});

it('G1-02 · 迁移后三个公开/报名开关仍为 false，接口不泄露库内记录', function () {
    // 这三个开关是 G0/G1 的对外口径：迁移只加列，不得顺带打开任何门。
    expect(config('ecosystem.events.public_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_write_verified'))->toBeFalse()
        ->and(config('ecosystem.content.public_enabled'))->toBeFalse();

    Event::create(['name' => '迁移后仍应隐藏的事件']);

    getJson('/api/events')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    getJson('/api/events/1')
        ->assertNotFound()
        ->assertJson([
            'success' => false,
            'code'    => ApiErrorCode::EVENT_NOT_FOUND,
        ]);
});
