<?php

namespace Webkul\Shop\Console\Commands;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Q-05 · 生态域数据 preflight（**只读**）。
 *
 *   php artisan ecosystem:data-preflight
 *   php artisan ecosystem:data-preflight --json=build/data-preflight.json --strict
 *
 * 目的：在任何 expand / backfill / 切换 / 恢复之前，先回答“存量数据能不能碰”：
 *   · 重复的活跃轮次、scope 计数与事实不一致、容量小于已占；
 *   · 公开指针指向不存在 / 别的父对象的 revision；
 *   · 状态 / 单位 / 时区 / 起止时间不合法；
 *   · outbox 过期租约、dead/failed 积压；
 *   · 媒体 / 关联引用孤儿、文件缺失。
 *
 * 输出只含表名、数量与 ID 样本，**不输出姓名 / 手机号 / 密文 / request_key**。
 * 本命令不写库；`--strict` 让 warning 也返回非零（发布前用它）。
 *
 * 退出码：0 无阻断；1 有 blocker（或 --strict 下的 warning）。
 */
class EcosystemDataPreflight extends Command
{
    protected $signature = 'ecosystem:data-preflight
        {--json= : 将结果写入 JSON 文件}
        {--strict : warning 也视为失败}
        {--sample=5 : 每个问题输出的 ID 样本上限}';

    protected $description = '只读检查生态域存量数据的重复、孤儿、计数、时区、指针与 outbox 风险';

    /** @var array<int, array<string, mixed>> */
    private array $findings = [];

    private int $sampleLimit = 5;

    public function handle(): int
    {
        $this->sampleLimit = max(0, (int) $this->option('sample'));

        $this->checkSchema();
        $this->checkEvents();
        $this->checkRegistrations();
        $this->checkScopes();
        $this->checkOutbox();
        $this->checkMedia();
        $this->checkContent();

        $blockers = 0;
        $warnings = 0;

        foreach ($this->findings as $finding) {
            $finding['severity'] === 'blocker' ? $blockers++ : $warnings++;
        }

        $result = [
            'generated_at' => now('UTC')->toIso8601String(),
            'database'     => DB::connection()->getDatabaseName(),
            'read_only'    => true,
            'strict'       => (bool) $this->option('strict'),
            'blockers'     => $blockers,
            'warnings'     => $warnings,
            'findings'     => $this->findings,
        ];

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            $path = (string) $this->option('json');
            $directory = dirname($path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $this->line('结果已写入：'.$path);
        }

        if ($this->findings === []) {
            $this->info('数据 preflight 通过：没有发现重复、孤儿、计数或状态问题。');

            return self::SUCCESS;
        }

        $this->table(
            ['级别', '代码', '数量', '说明'],
            array_map(fn ($item) => [
                $item['severity'],
                $item['code'],
                (string) $item['count'],
                $item['detail'],
            ], $this->findings),
        );

        $this->line(sprintf('blocker %d；warning %d。', $blockers, $warnings));

        if ($blockers > 0) {
            $this->error('存在阻断项：先修复 / 分流，再执行迁移或开放写入口。');

            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && $warnings > 0) {
            $this->warn('--strict：warning 视为失败。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function checkSchema(): void
    {
        $tables = [
            'events', 'event_revisions', 'event_registration_scopes', 'event_registrations',
            'event_registration_attempts', 'event_registration_requests', 'ecosystem_outbox',
            'ecosystem_media', 'ecosystem_media_refs', 'content_posts', 'content_revisions',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->add('blocker', 'schema_missing_table', $table, 1, '必需表不存在：'.$table);
            }
        }

        $columns = [
            'event_registrations'        => ['scope_id', 'subject_key', 'public_no', 'identity_kind', 'current_attempt_id', 'row_version'],
            'event_registration_attempts'=> ['registration_id', 'event_id', 'scope_id', 'status', 'attempt_no', 'row_version'],
            'event_registration_scopes'  => ['event_id', 'scope_key', 'unit', 'capacity', 'confirmed_count', 'status'],
            'events'                     => ['publish_status', 'disposition', 'published_revision_id', 'row_version'],
        ];

        foreach ($columns as $table => $required) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($required as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $this->add('blocker', 'schema_missing_column', $table.'.'.$column, 1, '必需列不存在：'.$table.'.'.$column);
                }
            }
        }
    }

    private function checkEvents(): void
    {
        if (! Schema::hasTable('events')
            || ! Schema::hasTable('event_revisions')
            || ! Schema::hasTable('event_registrations')
            || ! Schema::hasTable('event_registration_attempts')) {
            return;
        }

        $this->countRows(
            'blocker',
            'event_publish_status_unknown',
            'events',
            "SELECT id FROM events WHERE publish_status IS NULL OR publish_status NOT IN ('draft','in_review','approved','scheduled','published')",
            '事件的 publish_status 不在已声明状态表',
        );

        $this->countRows(
            'blocker',
            'event_disposition_unknown',
            'events',
            "SELECT id FROM events WHERE disposition IS NULL OR disposition NOT IN ('normal','withheld','retracted')",
            '事件的 disposition 不在已声明处置表',
        );

        $this->countRows(
            'blocker',
            'event_pointer_missing',
            'events',
            "SELECT e.id
               FROM events e
               LEFT JOIN event_revisions r ON r.id = e.published_revision_id
              WHERE e.publish_status = 'published'
                AND e.disposition = 'normal'
                AND (e.published_revision_id IS NULL OR r.id IS NULL)",
            '已发布活动缺少有效的 published revision',
        );

        $this->countRows(
            'blocker',
            'event_pointer_wrong_parent',
            'events',
            "SELECT e.id
               FROM events e
               JOIN event_revisions r ON r.id IN (e.published_revision_id, e.working_revision_id, e.approved_revision_id)
              WHERE r.event_id <> e.id",
            '活动 revision 指针指向了别的活动的 revision',
        );

        $this->countRows(
            'warning',
            'event_time_invalid',
            'events',
            "SELECT id FROM events WHERE starts_at IS NOT NULL AND ends_at IS NOT NULL AND starts_at > ends_at",
            '活动 starts_at 晚于 ends_at',
        );

        $this->countRows(
            'warning',
            'event_schedule_stale',
            'events',
            "SELECT id FROM events WHERE publish_status = 'scheduled' AND scheduled_publish_at IS NOT NULL AND scheduled_publish_at < (UTC_TIMESTAMP() - INTERVAL 1 HOUR)",
            '排期时间已过仍未发布',
        );

        $this->countRows(
            'warning',
            'revision_time_invalid',
            'event_revisions',
            "SELECT id FROM event_revisions WHERE starts_at IS NOT NULL AND ends_at IS NOT NULL AND starts_at > ends_at",
            '活动 revision 起止时间颠倒',
        );

        $zones = DB::table('events')->whereNotNull('timezone')->distinct()->pluck('timezone')
            ->merge(DB::table('event_revisions')->whereNotNull('timezone')->distinct()->pluck('timezone'))
            ->unique();

        $invalidZones = [];

        foreach ($zones as $zone) {
            try {
                new DateTimeZone((string) $zone);
            } catch (Throwable) {
                $invalidZones[] = (string) $zone;
            }
        }

        if ($invalidZones !== []) {
            $this->add('blocker', 'event_timezone_invalid', 'events.timezone', count($invalidZones), '未知时区：'.implode(', ', array_slice($invalidZones, 0, 10)));
        }

        $this->countRows(
            'warning',
            'registration_field_overlong',
            'event_registrations',
            'SELECT id FROM event_registrations WHERE CHAR_LENGTH(COALESCE(name, "")) > 50 OR CHAR_LENGTH(COALESCE(phone, "")) > 20 OR CHAR_LENGTH(COALESCE(city, "")) > 100',
            '历史报名姓名 / 手机 / 城市超过当前表单上限',
        );

        $this->countRows(
            'warning',
            'registration_identity_incomplete',
            'event_registrations',
            "SELECT id FROM event_registrations WHERE COALESCE(identity_kind, 'legacy') <> 'legacy' AND (scope_id IS NULL OR subject_key IS NULL OR public_no IS NULL)",
            '非 legacy 报名缺少 scope / subject / public_no',
        );

        $this->countRows(
            'warning',
            'registration_current_attempt_missing',
            'event_registrations',
            "SELECT r.id
               FROM event_registrations r
               LEFT JOIN event_registration_attempts a ON a.id = r.current_attempt_id AND a.registration_id = r.id
              WHERE r.current_attempt_id IS NOT NULL AND a.id IS NULL",
            'current_attempt_id 指向不存在或不属于本人的轮次',
        );

        $this->countRows(
            'warning',
            'registration_legacy_orphan_event',
            'event_registrations',
            "SELECT r.id FROM event_registrations r LEFT JOIN events e ON e.id = r.event_id WHERE e.id IS NULL",
            '报名指向不存在的活动',
        );
    }

    private function checkRegistrations(): void
    {
        if (! Schema::hasTable('event_registration_attempts') || ! Schema::hasTable('event_registrations')) {
            return;
        }

        $this->countRows(
            'blocker',
            'attempt_status_unknown',
            'event_registration_attempts',
            "SELECT id FROM event_registration_attempts WHERE status NOT IN ('submitted','confirmed','rejected','canceled','checked_in')",
            '报名轮次状态不在已声明状态表',
        );

        $this->countRows(
            'blocker',
            'attempt_orphan_registration',
            'event_registration_attempts',
            "SELECT a.id FROM event_registration_attempts a LEFT JOIN event_registrations r ON r.id = a.registration_id WHERE r.id IS NULL",
            '轮次指向不存在的报名主体',
        );

        $this->countRows(
            'blocker',
            'attempt_event_mismatch',
            'event_registration_attempts',
            "SELECT a.id
               FROM event_registration_attempts a
               JOIN event_registrations r ON r.id = a.registration_id
               JOIN event_registration_scopes s ON s.id = a.scope_id
              WHERE a.event_id <> r.event_id OR s.event_id <> a.event_id",
            '轮次 / 报名 / scope 的活动不一致',
        );

        $rows = DB::table('event_registration_attempts')
            ->select('registration_id', DB::raw('COUNT(*) as total'))
            ->whereIn('status', ['submitted', 'confirmed', 'checked_in'])
            ->groupBy('registration_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(50)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->add('blocker', 'duplicate_active_attempts', 'event_registration_attempts', $rows->count(), '同一报名存在多个活跃轮次', $rows->pluck('registration_id')->take($this->sampleLimit)->all());
        }

        $this->countRows(
            'warning',
            'attempt_checked_in_without_time',
            'event_registration_attempts',
            "SELECT id FROM event_registration_attempts WHERE status = 'checked_in' AND checked_in_at IS NULL",
            '已签到轮次缺少 checked_in_at',
        );

        $this->countRows(
            'warning',
            'attempt_contact_cipher_missing',
            'event_registration_attempts',
            "SELECT id FROM event_registration_attempts WHERE status IN ('confirmed','checked_in') AND (contact_name_enc IS NULL OR contact_phone_enc IS NULL)",
            '已确认轮次缺少联系人密文',
        );

        $this->countRows(
            'warning',
            'attempt_contact_city_overlong',
            'event_registration_attempts',
            'SELECT id FROM event_registration_attempts WHERE CHAR_LENGTH(COALESCE(contact_city, "")) > 100',
            '联系城市超过当前上限',
        );
    }

    private function checkScopes(): void
    {
        if (! Schema::hasTable('event_registration_scopes') || ! Schema::hasTable('event_registration_attempts')) {
            return;
        }

        $this->countRows(
            'blocker',
            'scope_unit_unknown',
            'event_registration_scopes',
            "SELECT id FROM event_registration_scopes WHERE unit NOT IN ('person','team')",
            'scope 计量单位未知',
        );

        $this->countRows(
            'blocker',
            'scope_status_unknown',
            'event_registration_scopes',
            "SELECT id FROM event_registration_scopes WHERE status NOT IN ('draft','open','closed')",
            'scope 状态未知',
        );

        $this->countRows(
            'blocker',
            'scope_policy_missing',
            'event_registration_scopes',
            "SELECT id FROM event_registration_scopes WHERE status = 'open' AND (policy_confirmed_at IS NULL OR policy_confirmed_by IS NULL)",
            'open 的 scope 没有政策确认记录',
        );

        $this->countRows(
            'blocker',
            'scope_counter_negative',
            'event_registration_scopes',
            'SELECT id FROM event_registration_scopes WHERE confirmed_count < 0',
            'confirmed_count 为负数',
        );

        $counterFacts = DB::table('event_registration_attempts')
            ->select('scope_id', DB::raw('COUNT(*) AS total'))
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->groupBy('scope_id');

        $rows = DB::table('event_registration_scopes as s')
            ->leftJoinSub($counterFacts, 'x', 'x.scope_id', '=', 's.id')
            ->select('s.id', 's.confirmed_count', DB::raw('COALESCE(x.total, 0) AS computed'))
            ->whereRaw('s.confirmed_count <> COALESCE(x.total, 0)')
            ->limit(50)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->add('blocker', 'scope_counter_mismatch', 'event_registration_scopes', $rows->count(), 'confirmed_count 与轮次事实不一致', $rows->pluck('id')->take($this->sampleLimit)->all());
        }

        $this->countRows(
            'blocker',
            'scope_counter_exceeds_capacity',
            'event_registration_scopes',
            'SELECT id FROM event_registration_scopes WHERE capacity IS NOT NULL AND confirmed_count > capacity',
            '已占名额超过容量',
        );

        $this->countRows(
            'warning',
            'scope_time_invalid',
            'event_registration_scopes',
            'SELECT id FROM event_registration_scopes WHERE registration_opens_at IS NOT NULL AND registration_closes_at IS NOT NULL AND registration_opens_at > registration_closes_at',
            '报名开始时间晚于截止时间',
        );
    }

    private function checkOutbox(): void
    {
        if (! Schema::hasTable('ecosystem_outbox')) {
            return;
        }

        $this->countRows(
            'blocker',
            'outbox_status_unknown',
            'ecosystem_outbox',
            "SELECT id FROM ecosystem_outbox WHERE status NOT IN ('pending','processing','sent','failed','dead')",
            'outbox 状态未知',
        );

        $this->countRows(
            'warning',
            'outbox_expired_lease',
            'ecosystem_outbox',
            "SELECT id FROM ecosystem_outbox WHERE status = 'processing' AND (lease_until IS NULL OR lease_until < UTC_TIMESTAMP())",
            'outbox 存在过期 / 空租约（worker 崩溃或进程卡住）',
        );

        $this->countRows(
            'warning',
            'outbox_backlog',
            'ecosystem_outbox',
            "SELECT id FROM ecosystem_outbox WHERE status = 'pending' AND available_at < (UTC_TIMESTAMP() - INTERVAL 1 HOUR)",
            'pending 超过 1 小时未消费',
        );

        $failed = DB::table('ecosystem_outbox')->whereIn('status', ['failed', 'dead'])->count();

        if ($failed > 0) {
            $this->add('warning', 'outbox_failed_or_dead', 'ecosystem_outbox', $failed, '存在 failed/dead 记录：failed 可人工补偿，dead 为终态');
        }
    }

    private function checkMedia(): void
    {
        if (! Schema::hasTable('ecosystem_media')) {
            return;
        }

        $this->countRows(
            'blocker',
            'media_status_unknown',
            'ecosystem_media',
            "SELECT id FROM ecosystem_media WHERE status NOT IN ('pending','approved','rejected','quarantined')",
            '媒体状态未知',
        );

        $this->countRows(
            'blocker',
            'media_ref_orphan',
            'ecosystem_media_refs',
            'SELECT ref.id FROM ecosystem_media_refs ref LEFT JOIN ecosystem_media m ON m.id = ref.media_id WHERE m.id IS NULL',
            '媒体引用指向不存在的媒体',
        );

        $missing = 0;

        DB::table('ecosystem_media')
            ->where('status', 'approved')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'disk', 'storage_key'])
            ->each(function ($media) use (&$missing): void {
                try {
                    if (! Storage::disk((string) $media->disk)->exists((string) $media->storage_key)) {
                        $missing++;
                    }
                } catch (Throwable) {
                    $missing++;
                }
            });

        if ($missing > 0) {
            $this->add('warning', 'media_file_missing', 'ecosystem_media', $missing, '已批准媒体的物理文件缺失（抽样 200 条）');
        }
    }

    private function checkContent(): void
    {
        if (! Schema::hasTable('content_posts')) {
            return;
        }

        $this->countRows(
            'blocker',
            'content_publish_status_unknown',
            'content_posts',
            "SELECT id FROM content_posts WHERE publish_status NOT IN ('draft','in_review','approved','scheduled','published')",
            '资讯 publish_status 未知',
        );

        $this->countRows(
            'blocker',
            'content_pointer_missing',
            'content_posts',
            "SELECT p.id
               FROM content_posts p
               LEFT JOIN content_revisions r ON r.id = p.published_revision_id
              WHERE p.publish_status = 'published' AND p.disposition = 'normal'
                AND (p.published_revision_id IS NULL OR r.id IS NULL)",
            '已发布资讯缺少有效的 published revision',
        );

        $this->countRows(
            'blocker',
            'content_pointer_wrong_parent',
            'content_posts',
            "SELECT p.id
               FROM content_posts p
               JOIN content_revisions r ON r.id IN (p.published_revision_id, p.working_revision_id, p.approved_revision_id)
              WHERE r.post_id <> p.id",
            '资讯 revision 指针指向了别的文章',
        );
    }

    private function countRows(string $severity, string $code, string $table, string $sql, string $detail): void
    {
        try {
            $count = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM ('.$sql.') q')->c ?? 0);

            if ($count === 0) {
                return;
            }

            $rows = DB::select('SELECT * FROM ('.$sql.') q LIMIT '.max(1, $this->sampleLimit));
        } catch (Throwable $exception) {
            $this->add('warning', 'check_unavailable', $code, 1, '检查无法执行：'.$exception->getMessage());

            return;
        }

        $ids = array_map(fn ($row) => (int) ($row->id ?? 0), $rows);

        $this->add($severity, $code, $table, $count, $detail, $ids);
    }

    /**
     * @param  array<int, int>  $samples
     */
    private function add(string $severity, string $code, string $table, int $count, string $detail, array $samples = []): void
    {
        $this->findings[] = [
            'severity'  => $severity,
            'code'      => $code,
            'table'     => $table,
            'count'     => $count,
            'detail'    => $detail,
            'sample_ids'=> $samples,
        ];
    }
}
