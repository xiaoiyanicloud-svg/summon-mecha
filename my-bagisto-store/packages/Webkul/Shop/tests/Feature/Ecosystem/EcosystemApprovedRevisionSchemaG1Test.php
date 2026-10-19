<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-08A · approved_revision_id / scheduled_publish_at schema 守卫（ADR 附录 B A-01/A-02）。
 *
 * 这里测真实库结构，不测迁移“看起来写了什么”：
 *   · 两张父表列类型 / NULL / 默认值；
 *   · approved 指针索引与外键 RESTRICT/RESTRICT；
 *   · scheduled_publish_at 与 (publish_status, scheduled_publish_at) 索引；
 *   · 普通写入口仍不透传 approved_revision_id；
 *   · published_revision_id 仍保持 G1-04/05 的拒绝语义。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A schema tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8SchColumnMeta(string $table, string $column): ?object
{
    return DB::selectOne(
        'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
                DATETIME_PRECISION AS datetime_precision
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
}

/**
 * @return array{columns: array<int, string>, unique: bool}|array{}
 */
function g8SchIndexMeta(string $table, string $index): array
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

function g8SchForeignKey(string $table, string $column): ?object
{
    return DB::table('information_schema.KEY_COLUMN_USAGE as k')
        ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join): void {
            $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
        })
        ->where('k.TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->where('k.TABLE_NAME', $table)
        ->where('k.COLUMN_NAME', $column)
        ->select([
            'k.REFERENCED_TABLE_NAME as ref_table',
            'k.REFERENCED_COLUMN_NAME as ref_column',
            'r.DELETE_RULE as delete_rule',
            'r.UPDATE_RULE as update_rule',
        ])
        ->first();
}

function g8SchAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Schema Admin',
        'email'      => 'g8-schema-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8SchOrganizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G8 Schema 主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

function g8SchEvent(EcosystemOrganizer $organizer): Event
{
    return Event::create([
        'name'           => 'G8 Schema 活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'salon',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ])->fresh();
}

it('G1-08A · approved_revision_id 两表列 / 索引 / 外键符合 A-01', function () {
    foreach ([
        'events'        => ['event_revisions', 'idx_events_approved_revision_id'],
        'content_posts' => ['content_revisions', 'idx_content_posts_approved_revision_id'],
    ] as $table => [$revisionTable, $index]) {
        $column = g8SchColumnMeta($table, 'approved_revision_id');

        expect($column)->not->toBeNull()
            // MySQL 5.7 的 COLUMN_TYPE 带显示宽度（bigint(20) unsigned）；冻结的是语义类型。
            ->and(preg_replace('/^bigint\(\d+\)/', 'bigint', strtolower((string) $column->column_type)))
            ->toBe('bigint unsigned')
            ->and((string) $column->is_nullable)->toBe('YES')
            ->and($column->column_default)->toBeNull();

        $indexMeta = g8SchIndexMeta($table, $index);

        expect($indexMeta)->not->toBe([])
            ->and($indexMeta['columns'])->toBe(['approved_revision_id'])
            ->and($indexMeta['unique'])->toBeFalse();

        $fk = g8SchForeignKey($table, 'approved_revision_id');

        expect($fk)->not->toBeNull()
            ->and((string) $fk->ref_table)->toBe($revisionTable)
            ->and((string) $fk->ref_column)->toBe('id')
            ->and((string) $fk->delete_rule)->toBe('RESTRICT')
            ->and((string) $fk->update_rule)->toBe('RESTRICT');
    }
});

it('G1-08A · scheduled_publish_at 两表列 / 索引符合 A-02，且新建对象不写值', function () {
    foreach ([
        'events'        => 'idx_events_publish_status_scheduled_at',
        'content_posts' => 'idx_content_posts_publish_status_scheduled_at',
    ] as $table => $index) {
        $column = g8SchColumnMeta($table, 'scheduled_publish_at');

        expect($column)->not->toBeNull()
            ->and(strtolower((string) $column->column_type))->toBe('datetime')
            ->and((string) $column->is_nullable)->toBe('YES')
            ->and($column->column_default)->toBeNull();

        $indexMeta = g8SchIndexMeta($table, $index);

        expect($indexMeta)->not->toBe([])
            ->and($indexMeta['columns'])->toBe(['publish_status', 'scheduled_publish_at'])
            ->and($indexMeta['unique'])->toBeFalse();
    }

    $organizer = g8SchOrganizer();
    $event = g8SchEvent($organizer);

    expect($event->scheduled_publish_at)->toBeNull()
        ->and($event->approved_revision_id)->toBeNull();

    $adminId = g8SchAdminId();
    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-schema-'.bin2hex(random_bytes(5)),
    ]);

    expect($post->scheduled_publish_at)->toBeNull()
        ->and($post->approved_revision_id)->toBeNull();
});

it('G1-08A · approved_revision_id 外键阻止删除被引用的 revision', function () {
    $adminId = g8SchAdminId();
    $organizer = g8SchOrganizer();
    $event = g8SchEvent($organizer);

    $revision = app(EventRevisionService::class)->createDraft($event, $adminId, [
        'name'                    => 'G8 Schema 快照',
        'timezone'                => 'Asia/Shanghai',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网',
        'authorization_basis'     => 'self_organized',
        'change_summary'          => '快照',
    ]);

    DB::table('event_revisions')->where('id', $revision->id)->update(['review_state' => 'approved']);
    DB::table('events')->where('id', $event->id)->update(['approved_revision_id' => (int) $revision->id]);

    expect(fn () => DB::table('event_revisions')->where('id', $revision->id)->delete())
        ->toThrow(QueryException::class);
});

it('G1-08A · 普通写入口不透传 approved_revision_id；published 指针仍拒绝', function () {
    $adminId = g8SchAdminId();
    $organizer = g8SchOrganizer();
    $event = g8SchEvent($organizer);

    expect(in_array('approved_revision_id', (new ReflectionClass(EventWriteService::class))->getConstant('WRITABLE_FIELDS'), true))
        ->toBeFalse()
        ->and(in_array('approved_revision_id', ContentPostService::EDITABLE_FIELDS, true))
        ->toBeFalse();

    $revision = app(EventRevisionService::class)->createDraft($event, $adminId, [
        'name'                    => 'G8 Schema 快照',
        'timezone'                => 'Asia/Shanghai',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网',
        'authorization_basis'     => 'self_organized',
        'change_summary'          => '快照',
    ]);

    expect(fn () => app(EventWriteService::class)->update($event, 0, [
        'published_revision_id' => (int) $revision->id,
    ]))->toThrow(RevisionImmutableException::class);

    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-schema-'.bin2hex(random_bytes(5)),
    ]);

    $post->published_revision_id = 1;

    expect(fn () => $post->save())->toThrow(ContentTransitionException::class);
});
