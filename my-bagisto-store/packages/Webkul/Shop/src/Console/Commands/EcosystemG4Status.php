<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G4-09 · 只读上线状态。
 *
 * 运维/值守在不发版、不改配置的前提下，先看这张表判断“哪些能力开着、
 * 哪些数据已经存在、失败通知有多少”。命令只读，不触发任何写动作。
 */
class EcosystemG4Status extends Command
{
    protected $signature = 'ecosystem:g4-status';

    protected $description = '输出 G4 开关、关键表数据量与失败通知数（只读）';

    public function handle(): int
    {
        $switches = [
            'search.enabled'                => config('ecosystem_g4.search.enabled'),
            'favorites.enabled'             => config('ecosystem_g4.favorites.enabled'),
            'relations.enabled'             => config('ecosystem_g4.relations.enabled'),
            'ssr.enabled'                   => config('ecosystem_g4.ssr.enabled'),
            'sitemap.enabled'               => config('ecosystem_g4.sitemap.enabled'),
            'metrics.enabled'               => config('ecosystem_g4.metrics.enabled'),
            'metrics.content_views_enabled' => config('ecosystem_g4.metrics.content_views_enabled'),
            'ops.enabled'                   => config('ecosystem_g4.ops.enabled'),
            'public_read.emergency_disabled' => config('ecosystem_public_read.emergency_disabled'),
            'events.public_enabled'         => config('ecosystem.events.public_enabled'),
            'content.public_enabled'        => config('ecosystem.content.public_enabled'),
        ];

        $this->info('G4 switches');
        foreach ($switches as $key => $value) {
            $this->line(sprintf('  %-36s %s', $key, $value ? 'true' : 'false'));
        }

        $this->newLine();
        $this->info('Facts');

        $facts = [
            'events.published'   => ['events', fn () => DB::table('events')->where('publish_status', 'published')->where('disposition', 'normal')->count()],
            'content.published'  => ['content_posts', fn () => DB::table('content_posts')->where('publish_status', 'published')->where('disposition', 'normal')->count()],
            'favorites'          => ['ecosystem_favorites', fn () => DB::table('ecosystem_favorites')->count()],
            'relations.active'   => ['ecosystem_relations', fn () => DB::table('ecosystem_relations')->where('status', 'active')->count()],
            'content_views.today'=> ['ecosystem_content_view_daily', fn () => (int) DB::table('ecosystem_content_view_daily')->where('view_date', now('UTC')->toDateString())->sum('views')],
            'outbox.failed'      => ['ecosystem_outbox', fn () => DB::table('ecosystem_outbox')->where('status', 'failed')->whereNull('lease_owner')->count()],
            'registration.submitted' => ['event_registration_attempts', fn () => DB::table('event_registration_attempts')->where('status', 'submitted')->count()],
        ];

        foreach ($facts as $label => [$table, $callback]) {
            $value = Schema::hasTable($table) ? (int) $callback() : null;
            $this->line(sprintf('  %-28s %s', $label, $value === null ? 'table_missing' : (string) $value));
        }

        $this->newLine();
        $this->line('database='.DB::connection()->getDatabaseName());

        return self::SUCCESS;
    }
}
