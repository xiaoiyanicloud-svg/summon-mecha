<?php

/**
 * G2/G3/G4 浏览器端到端演练 · 状态变更辅助（仅独立测试库）。
 *
 * 浏览器无法代替后台完成的操作，用这里调用真实服务执行：
 *   · retract-event      撤稿，验证公开 API/SSR/sitemap/收藏同步降级；
 *   · create-showcase    基于浏览器刚提交的作品版本创建展示草稿；
 *   · publish-showcase   后台审核 + 发布展示（队长授权后）。
 *
 * 用法：
 *   php mutate.php --action=retract-event --id=12 --actor=3 --reason=撤稿演练
 *   php mutate.php --action=create-showcase --track=5 --entry=9 --admin=3 --title=回顾
 *   php mutate.php --action=publish-showcase --id=4 --admin=3
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionShowcase;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/i', $argument, $matches)) {
        $options[$matches[1]] = $matches[2];
    }
}

$action = (string) ($options['action'] ?? '');
$adminId = (int) ($options['admin'] ?? $options['actor'] ?? 0);

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

try {
    switch ($action) {
        case 'retract-event':
            $eventId = (int) ($options['id'] ?? 0);

            if ($eventId <= 0 || $adminId <= 0) {
                $fail('retract-event requires --id and --actor');
            }

            $event = Event::query()->findOrFail($eventId);
            $service = app(EventPublicationService::class);
            $updated = $service->retract(
                $event,
                $adminId,
                (int) $event->row_version,
                (string) ($options['reason'] ?? '端到端撤稿演练'),
            );

            qa_json([
                'action'      => $action,
                'event_id'    => (int) $updated->getKey(),
                'disposition' => (string) $updated->disposition,
                'row_version' => (int) $updated->row_version,
            ]);
            break;

        case 'create-showcase':
            $trackId = (int) ($options['track'] ?? 0);
            $entryId = (int) ($options['entry'] ?? 0);

            if ($trackId <= 0 || $entryId <= 0 || $adminId <= 0) {
                $fail('create-showcase requires --track, --entry and --admin');
            }

            $submission = DB::table('competition_submissions')->where('entry_id', $entryId)->first();

            if ($submission === null) {
                $fail('no submission for entry '.$entryId);
            }

            $version = DB::table('competition_submission_versions')
                ->where('submission_id', (int) $submission->id)
                ->where('status', 'submitted')
                ->orderByDesc('version_no')
                ->first();

            if ($version === null) {
                $fail('no submitted version for entry '.$entryId);
            }

            $track = CompetitionTrack::query()->findOrFail($trackId);
            $showcase = app(CompetitionShowcaseService::class)->createDraft($track, [
                'showcase_type'         => CompetitionShowcase::TYPE_RECAP,
                'title'                 => (string) ($options['title'] ?? '端到端作品回顾'),
                'summary'               => (string) ($options['summary'] ?? '浏览器演练提交的作品与复盘内容。'),
                'entry_id'              => $entryId,
                'submission_version_id' => (int) $version->id,
            ], $adminId);

            qa_json([
                'action'           => $action,
                'showcase_id'      => (int) $showcase->getKey(),
                'version_id'       => (int) $version->id,
                'status'           => (string) $showcase->status,
                'row_version'      => (int) $showcase->row_version,
            ]);
            break;

        case 'publish-showcase':
            $showcaseId = (int) ($options['id'] ?? 0);

            if ($showcaseId <= 0 || $adminId <= 0) {
                $fail('publish-showcase requires --id and --admin');
            }

            $service = app(CompetitionShowcaseService::class);
            $showcase = CompetitionShowcase::query()->findOrFail($showcaseId);

            if ((string) $showcase->status === CompetitionShowcase::STATUS_DRAFT) {
                $showcase = $service->approve($showcase, (int) $showcase->row_version, $adminId);
            }

            if ((string) $showcase->status === CompetitionShowcase::STATUS_APPROVED) {
                $showcase = $service->publish($showcase, (int) $showcase->row_version, $adminId);
            }

            qa_json([
                'action'      => $action,
                'showcase_id' => (int) $showcase->getKey(),
                'status'      => (string) $showcase->status,
                'row_version' => (int) $showcase->row_version,
            ]);
            break;

        default:
            $fail('unknown action: '.$action);
    }
} catch (Throwable $exception) {
    $fail($exception::class.': '.$exception->getMessage());
}
