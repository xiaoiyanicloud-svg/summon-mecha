<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Services\Ecosystem\PublicContentQuery;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;

/**
 * G4-08 · 独立测试库基准（不是生产压测）。
 *
 * 用途：在正式压测前，用真实 MySQL + 真实查询服务给出量级与退化点。
 *   · 只允许在库名含 `_test` / `_testing` 的库执行；
 *   · 全程事务内造数，结束回滚，不留测试数据；
 *   · 输出 JSON（中位数/p95/max），不包含任何 PII；
 *   · `php -S` 的单进程结果不作为并发结论。
 */
class EcosystemG4Benchmark extends Command
{
    protected $signature = 'ecosystem:g4-benchmark
        {--events=2000 : 造多少条已发布活动}
        {--queries=30 : 每个场景采样次数}';

    protected $description = 'G4 公开列表/搜索/游标/sitemap 的独立测试库基准';

    public function handle(PublicEventQuery $events, PublicContentQuery $contents): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! preg_match('/_(test|testing)$/', $database)) {
            $this->error('拒绝执行：基准命令只允许在 *_test / *_testing 独立库运行，当前库：'.$database);

            return self::FAILURE;
        }

        $count = max(10, (int) $this->option('events'));
        $samples = max(3, (int) $this->option('queries'));

        DB::beginTransaction();

        try {
            $this->seed($count);
            $results = [
                'database'     => $database,
                'seeded'       => $count,
                'samples'      => $samples,
                'initial_page' => $this->bench(fn () => $events->paginate([], 1, 20), $samples),
                'cursor_first_page' => $this->bench(
                    fn () => $events->cursorPaginate([], null, 20),
                    $samples,
                ),
                'keyword'      => $this->bench(
                    fn () => $events->paginate(['q' => '性能测试'], 1, 20),
                    $samples,
                ),
                'registration_open' => $this->bench(
                    fn () => $events->paginate(['registration_state' => 'open'], 1, 20),
                    $samples,
                ),
                'sitemap'      => $this->bench(
                    fn () => $events->sitemapRows(5000)->count(),
                    $samples,
                ),
                'content_initial_page' => $this->bench(
                    fn () => $contents->paginate([], 1, 20),
                    $samples,
                ),
            ];

            // 游标连续翻 10 页的总耗时（每页 20 条），验证跨页扫描没有退化。
            $results['cursor_10_pages'] = $this->bench(function () use ($events): int {
                $cursor = null;
                $seen = 0;

                for ($page = 0; $page < 10; $page++) {
                    $result = $events->cursorPaginate([], $cursor, 20);
                    $seen += $result['items']->count();
                    $cursor = $result['next_cursor'];

                    if ($cursor === null) {
                        break;
                    }
                }

                return $seen;
            }, max(3, intdiv($samples, 3)));

            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            DB::rollBack();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('基准失败并已回滚：'.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, int>
     */
    private function seed(int $count): void
    {
        $now = now('UTC');

        for ($offset = 0; $offset < $count; $offset += 200) {
            $rows = [];
            $revisions = [];

            for ($index = $offset; $index < min($count, $offset + 200); $index++) {
                $publishedAt = $now->copy()->subMinutes($index)->format('Y-m-d H:i:s');

                $rows[] = [
                    'name'            => 'G4 性能测试活动 '.$index,
                    'type'            => 'salon',
                    'status'          => 'registering',
                    'icon'            => '?',
                    'bg_color'        => '#E8F0FE',
                    'sort'            => 0,
                    'kind'            => 'salon',
                    'participation_mode' => 'offline',
                    'publish_status'  => 'published',
                    'disposition'     => 'normal',
                    'hosting_status'  => 'scheduled',
                    'timezone'        => 'Asia/Shanghai',
                    'starts_at'       => $publishedAt,
                    'ends_at'         => $publishedAt,
                    'first_published_at' => $publishedAt,
                    'published_at'    => $publishedAt,
                    'row_version'     => 1,
                    'source_type'     => 'platform',
                    'platform_role'   => 'organizer',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            $before = (int) DB::table('events')->max('id');
            DB::table('events')->insert($rows);

            $inserted = DB::table('events')
                ->where('id', '>', $before)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($inserted as $position => $eventId) {
                $index = $offset + $position;
                $revisions[] = [
                    'event_id'                => $eventId,
                    'revision_no'             => 1,
                    'name'                    => 'G4 性能测试活动 '.$index,
                    'summary'                 => '性能测试摘要',
                    'detail_html'             => null,
                    'city'                    => '上海',
                    'location_text'           => '测试场地',
                    'participation_mode'      => 'offline',
                    'timezone'                => 'Asia/Shanghai',
                    'starts_at'               => $now->copy()->subMinutes($index)->format('Y-m-d H:i:s'),
                    'ends_at'                 => $now->copy()->subMinutes($index)->format('Y-m-d H:i:s'),
                    'source_type'             => 'platform',
                    'platform_role'           => 'organizer',
                    'organizer_name_snapshot' => '召唤机甲',
                    'source_name'             => '性能测试',
                    'content_fingerprint'     => str_repeat('c', 64),
                    'sanitizer_version'       => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
                    'review_state'            => 'approved',
                    'change_summary'          => '性能测试',
                    'created_by'              => 1,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];
            }

            DB::table('event_revisions')->insert($revisions);

            // 每 10 条给一个开放 scope，供 registration_state=open 过滤使用。
            $pairs = DB::table('event_revisions')
                ->whereIn('event_id', $inserted)
                ->pluck('id', 'event_id')
                ->all();

            foreach ($inserted as $position => $eventId) {
                if (! isset($pairs[$eventId])) {
                    continue;
                }

                $revisionId = (int) $pairs[$eventId];

                DB::table('events')->where('id', $eventId)->update([
                    'published_revision_id' => $revisionId,
                ]);

                if (((int) $eventId % 10) === 0) {
                    DB::table('event_registration_scopes')->insert([
                        'event_id'               => $eventId,
                        'scope_key'              => 'default',
                        'unit'                   => 'person',
                        'capacity'               => 100,
                        'capacity_confirmed_at'  => $now->format('Y-m-d H:i:s'),
                        'confirmed_count'        => 1,
                        'review_mode'            => 'auto',
                        'registration_opens_at'  => $now->copy()->subDay()->format('Y-m-d H:i:s'),
                        'registration_closes_at' => $now->copy()->addYear()->format('Y-m-d H:i:s'),
                        'status'                 => 'open',
                        'policy_confirmed_at'    => $now->format('Y-m-d H:i:s'),
                        'row_version'            => 0,
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ]);
                }
            }
        }

        // 确保 registration_state=open 过滤在基准里能命中。
        config([
            'ecosystem.events.registration_enabled'        => true,
            'ecosystem.events.registration_write_verified' => true,
        ]);

    }

    /**
     * @return array{samples: int, p50_ms: float, p95_ms: float, max_ms: float}
     */
    private function bench(callable $callback, int $samples): array
    {
        $timings = [];

        for ($i = 0; $i < $samples; $i++) {
            $start = hrtime(true);
            $callback();
            $timings[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($timings);

        return [
            'samples' => $samples,
            'p50_ms'  => round($timings[(int) floor((count($timings) - 1) * 0.5)], 2),
            'p95_ms'  => round($timings[(int) floor((count($timings) - 1) * 0.95)], 2),
            'max_ms'  => round(max($timings), 2),
        ];
    }
}
