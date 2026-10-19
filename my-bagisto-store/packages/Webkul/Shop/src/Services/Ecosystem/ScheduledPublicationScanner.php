<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 到期发布扫描（ADR-0001 §8.1 / G1-08B `publishScheduled` / G1-09）。
 *
 * 只处理**同时满足**：
 *   · `scheduled_publish_at` 非空且 <= 当前 UTC；
 *   · `disposition=normal`（暂缓 / 撤稿绝不自动复活）；
 *   · `publish_status` 为 `scheduled`（从未发布）或 `published`（已发布对象的新候选）。
 *
 * 失败分类：
 *   · 业务前置 / 状态机 / 版本冲突（Publication*Exception、*RevisionConflictException）
 *     → **不可重试**：在同一事务内安全回退，写 `schedule_aborted` 审计并告警；
 *   · QueryException（锁等待 / 连接等瞬时错误）→ 行保持 scheduled，下一轮重试；
 *   · 其他未预期异常 → 行保持 scheduled，记 error 日志并告警（不伪装成功、也不销毁排期）。
 *
 * 回退规则：
 *   · 从未发布过（`published_revision_id` 为空）的 scheduled 对象 → `approved`，
 *     清 `scheduled_publish_at`，保留 `approved_revision_id`；
 *   · 已发布对象 → 保持 `published` 与**旧公开指针**，清 `scheduled_publish_at`
 *     与 `approved_revision_id`（候选作废，旧版本继续公开）。
 *
 * 域开关：`PublicationState::publishEnabled()` 为 false 时**整域跳过**。运营关闭
 * 发布开关不是“这条排期业务失败”，不能因此回退排期。
 */
class ScheduledPublicationScanner
{
    public function __construct(
        private readonly EventPublicationService $events,
        private readonly ContentPublicationService $contents,
        private readonly PublicationState $state,
        private readonly PublicationAudit $audit,
        private readonly AdminAlertService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(int $limit = 50, bool $dryRun = false): array
    {
        $limit = max(1, $limit);

        $summary = [
            'dry_run'   => $dryRun,
            'candidates'=> 0,
            'published' => 0,
            'aborted'   => 0,
            'skipped'   => 0,
            'transient' => 0,
            'errors'    => 0,
            'domains'   => [],
        ];

        $remaining = $limit;

        foreach (['event', 'content'] as $domain) {
            $summary['domains'][$domain] = [
                'enabled'    => false,
                'candidates' => 0,
                'ids'        => [],
            ];

            if (! $this->state->publishEnabled($domain)) {
                $summary['domains'][$domain]['skipped_disabled'] = true;

                continue;
            }

            if ($remaining <= 0) {
                break;
            }

            $summary['domains'][$domain]['enabled'] = true;

            $ids = $this->dueIds($domain, $remaining);
            $summary['domains'][$domain]['candidates'] = count($ids);
            $summary['domains'][$domain]['ids'] = $ids;
            $summary['candidates'] += count($ids);
            $remaining -= count($ids);

            if ($dryRun) {
                continue;
            }

            foreach ($ids as $id) {
                $this->processOne($domain, $id, $summary);
            }
        }

        return $summary;
    }

    /**
     * @return array<int, int>
     */
    private function dueIds(string $domain, int $limit): array
    {
        $now = now('UTC')->format('Y-m-d H:i:s');

        if ($domain === 'event') {
            return Event::query()
                ->whereNotNull('scheduled_publish_at')
                ->where('scheduled_publish_at', '<=', $now)
                ->where('disposition', 'normal')
                ->whereIn('publish_status', ['scheduled', 'published'])
                ->orderBy('scheduled_publish_at')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return ContentPost::query()
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', $now)
            ->where('disposition', 'normal')
            ->whereIn('publish_status', ['scheduled', 'published'])
            ->orderBy('scheduled_publish_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function processOne(string $domain, int $id, array &$summary): void
    {
        try {
            if ($domain === 'event') {
                $event = Event::query()->find($id);

                if ($event === null) {
                    $summary['skipped']++;

                    return;
                }

                $this->events->publishScheduled($event);
            } else {
                $post = ContentPost::query()->find($id);

                if ($post === null) {
                    $summary['skipped']++;

                    return;
                }

                $this->contents->publishScheduled($post);
            }

            $summary['published']++;
        } catch (PublicationTransitionException|PublicationPreconditionException|EventRevisionConflictException|ContentRevisionConflictException $exception) {
            try {
                $outcome = $this->abort($domain, $id, $exception);
            } catch (Throwable $abortFailure) {
                // 回退本身失败（例如 DB 不可用）：事务已回滚，行仍保持 scheduled；
                // 不把它算作“已安全回退”，如实计为 error 并继续处理其它候选。
                $summary['errors']++;
                Log::error('ecosystem.schedule.abort_failed', [
                    'domain'       => $domain,
                    'aggregate_id' => $id,
                    'error'        => $abortFailure::class,
                ]);
                $this->alertError($domain, $id, 'abort_failed', $abortFailure);

                return;
            }

            if ($outcome === 'aborted') {
                $summary['aborted']++;
                $this->alertAborted($domain, $id, $exception);
            } else {
                // 失败后另一个操作者已改变状态（例如取消排期、暂缓、撤稿）：不覆盖它。
                $summary['skipped']++;
            }
        } catch (QueryException $exception) {
            $summary['transient']++;
            Log::warning('ecosystem.schedule.transient_failure', [
                'domain'       => $domain,
                'aggregate_id' => $id,
                'error'        => $exception::class,
            ]);
            $this->alertError($domain, $id, 'transient_db_error', $exception);
        } catch (Throwable $exception) {
            $summary['errors']++;
            Log::error('ecosystem.schedule.unexpected_failure', [
                'domain'       => $domain,
                'aggregate_id' => $id,
                'error'        => $exception::class,
                'message'      => $exception->getMessage(),
            ]);
            $this->alertError($domain, $id, 'unexpected_error', $exception);
        }
    }

    /**
     * 安全回退：只在行仍处于“可回退”状态时执行；不复活 withheld / retracted。
     *
     * @return 'aborted'|'skipped'
     */
    private function abort(string $domain, int $id, Throwable $cause): string
    {
        return DB::transaction(function () use ($domain, $id, $cause): string {
            $now = now('UTC');

            if ($domain === 'event') {
                $locked = Event::query()->whereKey($id)->lockForUpdate()->first();
            } else {
                $locked = ContentPost::query()->whereKey($id)->lockForUpdate()->first();
            }

            if ($locked === null
                || (string) $locked->disposition !== 'normal'
                || ! in_array((string) $locked->publish_status, ['scheduled', 'published'], true)
                || $locked->scheduled_publish_at === null
                || $locked->scheduled_publish_at->greaterThan($now)) {
                return 'skipped';
            }

            $expectedRowVersion = (int) $locked->row_version;
            $fromStatus = (string) $locked->publish_status;
            $neverPublished = $locked->published_revision_id === null;
            $toStatus = $neverPublished ? 'approved' : 'published';

            $attributes = [
                'publish_status'       => $toStatus,
                'scheduled_publish_at' => null,
                'row_version'          => $expectedRowVersion + 1,
                'updated_at'           => now(),
            ];

            if (! $neverPublished) {
                // 已发布对象：旧公开指针保持不变，只作废候选。
                $attributes['approved_revision_id'] = null;
            }

            $affected = DB::table($domain === 'event' ? 'events' : 'content_posts')
                ->where('id', $id)
                ->where('row_version', $expectedRowVersion)
                ->where('disposition', 'normal')
                ->whereIn('publish_status', ['scheduled', 'published'])
                ->update($attributes);

            if ($affected !== 1) {
                return 'skipped';
            }

            $revisionId = (int) ($locked->approved_revision_id ?? $locked->published_revision_id ?? 0);
            $revisionNo = $this->revisionNo($domain, $revisionId);

            $this->audit->recordSystem(
                $domain,
                (string) config('ecosystem_publishing.audit_actions.'.$domain.'.schedule_aborted'),
                $id,
                [
                    'revision_id'         => $revisionId,
                    'revision_no'         => $revisionNo,
                    'from'                => $fromStatus,
                    'to'                  => $toStatus,
                    'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
                    'reason_code'         => (string) config('ecosystem_publishing.reason_codes.schedule_aborted'),
                ],
            );

            Log::warning('ecosystem.schedule.aborted', [
                'domain'       => $domain,
                'aggregate_id' => $id,
                'from'         => $fromStatus,
                'to'           => $toStatus,
                'failure'      => $this->failureReason($cause),
            ]);

            return 'aborted';
        });
    }

    private function revisionNo(string $domain, int $revisionId): int
    {
        if ($revisionId <= 0) {
            return 0;
        }

        $revisionNo = $domain === 'event'
            ? EventRevision::query()->whereKey($revisionId)->value('revision_no')
            : ContentRevision::query()->whereKey($revisionId)->value('revision_no');

        return (int) ($revisionNo ?? 0);
    }

    /**
     * 稳定失败分类（不含自由文本 / PII），用于日志与告警正文。
     */
    private function failureReason(Throwable $cause): string
    {
        return match (true) {
            $cause instanceof PublicationTransitionException     => 'transition_invalid',
            $cause instanceof PublicationPreconditionException   => 'precondition_failed',
            $cause instanceof EventRevisionConflictException     => 'revision_conflict',
            $cause instanceof ContentRevisionConflictException   => 'revision_conflict',
            default                                              => 'business_failure',
        };
    }

    private function alertAborted(string $domain, int $id, Throwable $cause): void
    {
        $this->safeAlert(
            'ecosystem.schedule_aborted',
            sprintf('%s #%d 到期发布失败，已安全回退', $domain === 'event' ? '活动' : '文章', $id),
            sprintf(
                '排期时间已到，但发布前置不满足（%s）。系统未切换公开指针，并按规则回退排期。',
                $this->failureReason($cause),
            ),
            sprintf('ecosystem.schedule_aborted:%s:%d:%s', $domain, $id, now('UTC')->format('Y-m-d')),
            AdminAlertService::LEVEL_WARN,
        );
    }

    private function alertError(string $domain, int $id, string $reason, Throwable $cause): void
    {
        $this->safeAlert(
            'ecosystem.schedule_error',
            sprintf('%s #%d 到期发布遇到%s', $domain === 'event' ? '活动' : '文章', $id, $reason === 'transient_db_error' ? '瞬时数据库错误' : '未预期错误'),
            sprintf('行保持 scheduled，下一轮重试（%s）。', $reason),
            sprintf('ecosystem.schedule_error:%s:%d:%s:%s', $domain, $id, $reason, now('UTC')->format('Y-m-d')),
            AdminAlertService::LEVEL_WARN,
        );
    }

    private function safeAlert(string $type, string $title, string $content, string $dedupKey, string $level): void
    {
        try {
            $this->alerts->alert($type, $title, $content, $dedupKey, [], $level);
        } catch (Throwable $exception) {
            // 告警失败不能反过来破坏已完成的回退 / 审计。
            Log::error('ecosystem.schedule.alert_failed', [
                'type'  => $type,
                'error' => $exception::class,
            ]);
        }
    }
}
