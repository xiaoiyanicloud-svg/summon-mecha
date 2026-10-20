<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\CompetitionEdition;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionMatch;
use Webkul\Shop\Models\CompetitionStage;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-06 · 阶段 / 场次与 CSV 预览导入。
 *
 * 安全边界：
 *   · 预览只读，不写库；导入必须带同一 `preview_hash`，防止“看的是一份、
 *     导入的是另一份”；
 *   · 时间必须带时区偏移，服务端不做“默认时区猜测”；
 *   · entry 必须已确认且属于本赛项；跨赛项/不存在直接定位到行；
 *   · 同文件重复 match_key、同 entry/同场地时间重叠均在预览阶段报错；
 *   · 已发布阶段/场次不允许被导入覆盖；
 *   · 导入只写 draft，公开与否由显式发布动作决定。
 */
class CompetitionScheduleService
{
    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @return array{preview_hash: string, rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function preview(CompetitionTrack $track, UploadedFile $file): array
    {
        $this->rules->assertWriteEnabled();
        $this->assertTrackUsable($track);

        $contents = $this->readContents($file);

        return $this->analyze($track, $contents);
    }

    /**
     * @return array{preview_hash: string, summary: array<string, int>}
     */
    public function import(CompetitionTrack $track, UploadedFile $file, string $previewHash, int $adminId): array
    {
        $this->rules->assertWriteEnabled();
        $this->assertTrackUsable($track);

        $contents = $this->readContents($file);
        $hash = hash('sha256', $contents);

        if (! hash_equals($hash, trim($previewHash))) {
            throw CompetitionException::scheduleInvalid('导入文件与预览文件不一致，请重新预览。');
        }

        return DB::transaction(function () use ($track, $contents, $hash, $adminId): array {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            // 同一届次内跨赛项的场地/时间冲突需要在同一把届次锁下判定，
            // 否则两个赛项并发导入可能各自通过预览后同时落库。
            $lockedEdition = CompetitionEdition::query()->whereKey($lockedTrack->edition_id)->lockForUpdate()->firstOrFail();

            $analysis = $this->analyze($lockedTrack, $contents);
            $errors = array_values(array_filter(
                $analysis['rows'],
                static fn (array $row): bool => $row['status'] === 'error',
            ));

            if ($errors !== []) {
                throw CompetitionException::scheduleInvalid('CSV 存在错误，未导入任何数据。', $errors);
            }

            $edition = CompetitionEdition::query()->findOrFail($lockedTrack->edition_id);
            $stages = [];

            foreach ($analysis['rows'] as $row) {
                if ($row['status'] !== 'valid') {
                    continue;
                }

                $stageKey = (string) $row['data']['stage_key'];

                if (! isset($stages[$stageKey])) {
                    $stage = CompetitionStage::query()
                        ->where('track_id', $lockedTrack->getKey())
                        ->where('stage_key', $stageKey)
                        ->lockForUpdate()
                        ->first();

                    if ($stage === null) {
                        $stage = new CompetitionStage();
                        $stage->fill([
                            'edition_id' => (int) $edition->getKey(),
                            'track_id'   => (int) $lockedTrack->getKey(),
                            'stage_key'  => $stageKey,
                            'name'       => (string) $row['data']['stage_name'],
                            'sort_order' => (int) $row['data']['stage_order'],
                            'status'     => CompetitionStage::STATUS_DRAFT,
                            'row_version'=> 0,
                        ]);
                        $stage->save();
                    } else {
                        if ((string) $stage->status === CompetitionStage::STATUS_PUBLISHED) {
                            throw CompetitionException::schedulePublished();
                        }

                        // 重试同一文件时，阶段内容不变则不递增版本。
                        if ((string) $stage->name !== (string) $row['data']['stage_name']
                            || (int) $stage->sort_order !== (int) $row['data']['stage_order']) {
                            $stage->name = (string) $row['data']['stage_name'];
                            $stage->sort_order = (int) $row['data']['stage_order'];
                            $stage->row_version = (int) $stage->row_version + 1;
                            $stage->save();
                        }
                    }

                    $stages[$stageKey] = $stage;
                }

                $stage = $stages[$stageKey];
                $match = CompetitionMatch::query()
                    ->where('edition_id', $edition->getKey())
                    ->where('match_key', (string) $row['data']['match_key'])
                    ->lockForUpdate()
                    ->first();

                if ($match === null) {
                    $match = new CompetitionMatch();
                    $match->fill([
                        'edition_id' => (int) $edition->getKey(),
                        'track_id'   => (int) $lockedTrack->getKey(),
                        'stage_id'   => (int) $stage->getKey(),
                        'match_key'  => (string) $row['data']['match_key'],
                        'round_no'   => (int) $row['data']['round_no'],
                        'starts_at'  => Carbon::parse((string) $row['data']['starts_at'], 'UTC'),
                        'ends_at'    => Carbon::parse((string) $row['data']['ends_at'], 'UTC'),
                        'venue'      => $row['data']['venue'],
                        'home_entry_id' => $row['data']['home_entry_id'],
                        'away_entry_id' => $row['data']['away_entry_id'],
                        'status'     => CompetitionMatch::STATUS_DRAFT,
                        'row_version'=> 0,
                    ]);
                    $match->save();
                } elseif ($row['action'] !== 'skip') {
                    if ((string) $match->status === CompetitionMatch::STATUS_PUBLISHED) {
                        throw CompetitionException::schedulePublished();
                    }

                    $match->fill([
                        'stage_id'   => (int) $stage->getKey(),
                        'round_no'   => (int) $row['data']['round_no'],
                        'starts_at'  => Carbon::parse((string) $row['data']['starts_at'], 'UTC'),
                        'ends_at'    => Carbon::parse((string) $row['data']['ends_at'], 'UTC'),
                        'venue'      => $row['data']['venue'],
                        'home_entry_id' => $row['data']['home_entry_id'],
                        'away_entry_id' => $row['data']['away_entry_id'],
                    ]);
                    $match->row_version = (int) $match->row_version + 1;
                    $match->save();
                }
            }

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.schedule.imported',
                'competition_track',
                (int) $lockedTrack->getKey(),
                array_merge(['preview_hash' => $hash], $analysis['summary']),
            );

            return ['preview_hash' => $hash, 'summary' => $analysis['summary']];
        });
    }

    public function publishStage(
        CompetitionTrack $track,
        CompetitionStage $stage,
        int $expectedRowVersion,
        int $adminId,
    ): CompetitionStage {
        $this->rules->assertWriteEnabled();
        $this->assertTrackUsable($track);

        return DB::transaction(function () use ($track, $stage, $expectedRowVersion, $adminId): CompetitionStage {
            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTrackUsable($lockedTrack);

            $lockedStage = CompetitionStage::query()->whereKey($stage->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $lockedStage->track_id !== (int) $track->getKey()) {
                throw CompetitionException::notFound('阶段不属于该赛项。');
            }

            if ((int) $lockedStage->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $lockedStage->status === CompetitionStage::STATUS_PUBLISHED) {
                throw CompetitionException::invalid('阶段已发布，无需重复操作。');
            }

            $matches = CompetitionMatch::query()
                ->where('stage_id', $lockedStage->getKey())
                ->lockForUpdate()
                ->get();

            if ($matches->isEmpty()) {
                throw CompetitionException::scheduleInvalid('阶段没有任何场次，不能发布。');
            }

            foreach ($matches as $match) {
                if ((string) $match->status === CompetitionMatch::STATUS_CANCELED) {
                    continue;
                }

                if ($match->starts_at === null || $match->ends_at === null
                    || $match->starts_at->greaterThanOrEqualTo($match->ends_at)) {
                    throw CompetitionException::scheduleInvalid(sprintf(
                        '场次 %s 时间不合法，不能发布。',
                        (string) $match->match_key,
                    ));
                }

                foreach (['home_entry_id', 'away_entry_id'] as $field) {
                    if ($match->{$field} === null) {
                        continue;
                    }

                    $entry = CompetitionEntry::query()->find($match->{$field});

                    if ($entry === null
                        || (int) $entry->track_id !== (int) $track->getKey()
                        || (string) $entry->status !== CompetitionEntry::STATUS_CONFIRMED) {
                        throw CompetitionException::scheduleInvalid(sprintf(
                            '场次 %s 引用的参赛条目未确认或跨赛项，不能发布。',
                            (string) $match->match_key,
                        ));
                    }
                }

                $match->status = CompetitionMatch::STATUS_PUBLISHED;
                $match->published_at = now('UTC');
                $match->row_version = (int) $match->row_version + 1;
                $match->save();
            }

            $lockedStage->status = CompetitionStage::STATUS_PUBLISHED;
            $lockedStage->published_at = now('UTC');
            $lockedStage->row_version = (int) $lockedStage->row_version + 1;
            $lockedStage->save();

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.schedule.stage_published',
                'competition_stage',
                (int) $lockedStage->getKey(),
                [
                    'track_id'    => (int) $track->getKey(),
                    'match_count' => $matches->count(),
                ],
            );

            return $lockedStage->refresh();
        });
    }

    /**
     * 公开赛程：只返回已发布阶段 + 已发布场次；按阶段顺序、开始时间排序。
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicSchedule(int $eventId): array
    {
        $edition = CompetitionEdition::query()->where('event_id', $eventId)->first();

        if ($edition === null) {
            return [];
        }

        $tracks = CompetitionTrack::query()
            ->where('edition_id', $edition->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($tracks as $track) {
            $stages = CompetitionStage::query()
                ->where('track_id', $track->getKey())
                ->where('status', CompetitionStage::STATUS_PUBLISHED)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $stageData = [];

            foreach ($stages as $stage) {
                $matches = CompetitionMatch::query()
                    ->where('stage_id', $stage->getKey())
                    ->where('status', CompetitionMatch::STATUS_PUBLISHED)
                    ->orderBy('starts_at')
                    ->orderBy('id')
                    ->get();

                $matchData = [];

                foreach ($matches as $match) {
                    $matchData[] = [
                        'match_key' => (string) $match->match_key,
                        'round_no'  => (int) $match->round_no,
                        'starts_at' => $match->starts_at?->toIso8601String(),
                        'ends_at'   => $match->ends_at?->toIso8601String(),
                        'venue'     => $match->venue,
                        'home'      => $this->publicEntry($match->home_entry_id),
                        'away'      => $this->publicEntry($match->away_entry_id),
                    ];
                }

                $stageData[] = [
                    'stage_key'  => (string) $stage->stage_key,
                    'name'       => (string) $stage->name,
                    'sort_order' => (int) $stage->sort_order,
                    'matches'    => $matchData,
                ];
            }

            $result[] = [
                'track_key'   => (string) $track->track_key,
                'name'        => (string) $track->name,
                'discipline'  => $track->discipline,
                'summary'     => $track->summary,
                'min_team_size' => $track->min_team_size,
                'max_team_size' => $track->max_team_size,
                'allow_multi_team' => (bool) $track->allow_multi_team,
                'submission_deadline_at' => $track->submission_deadline_at?->toIso8601String(),
                'stages'      => $stageData,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicEntry(?int $entryId): ?array
    {
        if ($entryId === null) {
            return null;
        }

        $entry = CompetitionEntry::query()->find($entryId);

        if ($entry === null || (string) $entry->status !== CompetitionEntry::STATUS_CONFIRMED) {
            return null;
        }

        $snapshot = (array) $entry->eligibility_snapshot;
        $roster = (array) $entry->roster_snapshot;

        return [
            'team_name'    => (string) ($snapshot['team_name'] ?? ''),
            'member_count' => count($roster),
        ];
    }

    /**
     * @return array{preview_hash: string, rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    private function analyze(CompetitionTrack $track, string $contents): array
    {
        $hash = hash('sha256', $contents);
        $lines = $this->parseCsv($contents);
        $edition = CompetitionEdition::query()->findOrFail($track->edition_id);

        $existingStages = CompetitionStage::query()
            ->where('track_id', $track->getKey())
            ->get()
            ->keyBy('stage_key');

        $existingMatches = CompetitionMatch::query()
            ->where('edition_id', $edition->getKey())
            ->get()
            ->keyBy('match_key');

        $entries = CompetitionEntry::query()
            ->where('track_id', $track->getKey())
            ->where('status', CompetitionEntry::STATUS_CONFIRMED)
            ->get()
            ->keyBy('entry_no');

        $rows = [];
        $seenMatchKeys = [];
        $stageMeta = [];
        $fileIntervals = [];
        $summary = ['total' => 0, 'valid' => 0, 'errors' => 0, 'creates' => 0, 'updates' => 0, 'skips' => 0];

        foreach ($lines as $line) {
            $rowNo = (int) $line['row_no'];
            $raw = $line['data'];
            $errors = [];
            $data = null;

            $stageKey = trim((string) ($raw['stage_key'] ?? ''));
            $stageName = trim((string) ($raw['stage_name'] ?? ''));
            $stageOrderRaw = trim((string) ($raw['stage_order'] ?? ''));
            $matchKey = trim((string) ($raw['match_key'] ?? ''));
            $roundRaw = trim((string) ($raw['round_no'] ?? ''));
            $startsRaw = trim((string) ($raw['starts_at'] ?? ''));
            $endsRaw = trim((string) ($raw['ends_at'] ?? ''));
            $venue = trim((string) ($raw['venue'] ?? ''));
            $homeNo = trim((string) ($raw['home_entry_no'] ?? ''));
            $awayNo = trim((string) ($raw['away_entry_no'] ?? ''));

            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,49}$/', $stageKey)) {
                $errors[] = 'stage_key 只允许字母、数字、下划线、连字符，长度 1~50';
            }

            if ($stageName === '' || mb_strlen($stageName) > 120) {
                $errors[] = 'stage_name 必填且不得超过 120 字符';
            }

            $stageOrder = $stageOrderRaw === '' ? 0 : (int) $stageOrderRaw;

            if ($stageOrderRaw !== '' && ! ctype_digit($stageOrderRaw)) {
                $errors[] = 'stage_order 必须是非负整数';
            }

            if ($stageKey !== '' && isset($stageMeta[$stageKey])
                && ($stageMeta[$stageKey]['name'] !== $stageName || $stageMeta[$stageKey]['order'] !== $stageOrder)) {
                $errors[] = '同一 stage_key 的名称/顺序不一致';
            }

            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,63}$/', $matchKey)) {
                $errors[] = 'match_key 只允许字母、数字、冒号、下划线、连字符，长度 1~64';
            }

            if (isset($seenMatchKeys[$matchKey])) {
                $errors[] = 'match_key 在文件内重复';
            }

            $roundNo = $roundRaw === '' ? 1 : (int) $roundRaw;

            if ($roundRaw !== '' && (! ctype_digit($roundRaw) || $roundNo < 1)) {
                $errors[] = 'round_no 必须是正整数';
            }

            $startsAt = $this->parseOffsetTime($startsRaw, 'starts_at', $errors);
            $endsAt = $this->parseOffsetTime($endsRaw, 'ends_at', $errors);

            if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
                $errors[] = 'starts_at 必须早于 ends_at';
            }

            if (mb_strlen($venue) > 191) {
                $errors[] = 'venue 不得超过 191 字符';
            }

            $home = $this->resolveEntry($homeNo, $entries, 'home_entry_no', $errors);
            $away = $this->resolveEntry($awayNo, $entries, 'away_entry_no', $errors);

            if ($home !== null && $away !== null && $homeNo !== '' && $homeNo === $awayNo) {
                $errors[] = '同一场次不能引用同一参赛条目两次';
            }

            $stage = $existingStages[$stageKey] ?? null;

            if ($stage !== null && (string) $stage->status === CompetitionStage::STATUS_PUBLISHED) {
                $errors[] = '目标阶段已发布，不能通过导入覆盖';
            }

            $action = 'create';
            $existingMatch = null;

            if (isset($existingMatches[$matchKey])) {
                $existingMatch = $existingMatches[$matchKey];

                if ((string) $existingMatch->status === CompetitionMatch::STATUS_PUBLISHED) {
                    $errors[] = '目标场次已发布，不能通过导入覆盖';
                }

                $probe = [
                    'stage_key' => $stageKey,
                    'round_no'  => $roundNo,
                    'starts_at' => $startsAt,
                    'ends_at'   => $endsAt,
                    'venue'     => $venue !== '' ? $venue : null,
                    'home'      => $home,
                    'away'      => $away,
                ];

                $same = $this->sameExistingPayload($existingMatch, $existingStages, $probe);
                $action = $same ? 'skip' : 'update';
            }

            // 文件内时间冲突：同 entry 或同场地重叠。
            if ($startsAt !== null && $endsAt !== null) {
                foreach ([$homeNo, $awayNo] as $entryNo) {
                    if ($entryNo === '') {
                        continue;
                    }

                    foreach ($fileIntervals['entry'][$entryNo] ?? [] as $interval) {
                        if ($interval['match_key'] !== $matchKey
                            && $startsAt < $interval['end'] && $endsAt > $interval['start']) {
                            $errors[] = sprintf('与文件内场次 %s 的参赛条目时间冲突', $interval['match_key']);
                        }
                    }
                }

                if ($venue !== '') {
                    foreach ($fileIntervals['venue'][$venue] ?? [] as $interval) {
                        if ($interval['match_key'] !== $matchKey
                            && $startsAt < $interval['end'] && $endsAt > $interval['start']) {
                            $errors[] = sprintf('与文件内场次 %s 的场地时间冲突', $interval['match_key']);
                        }
                    }
                }

                // 与库内其它场次冲突（排除当前 match_key）。
                foreach ($existingMatches as $key => $other) {
                    if ((string) $key === $matchKey || $other->starts_at === null || $other->ends_at === null) {
                        continue;
                    }

                    $otherStart = $other->starts_at->format('Y-m-d H:i:s');
                    $otherEnd = $other->ends_at->format('Y-m-d H:i:s');

                    if (! ($startsAt < $otherEnd && $endsAt > $otherStart)) {
                        continue;
                    }

                    $sameEntry = ($home !== null && in_array($home, [(int) $other->home_entry_id, (int) $other->away_entry_id], true))
                        || ($away !== null && in_array($away, [(int) $other->home_entry_id, (int) $other->away_entry_id], true));
                    $sameVenue = $venue !== '' && $venue === (string) $other->venue;

                    if ($sameEntry) {
                        $errors[] = sprintf('与已存在场次 %s 的参赛条目时间冲突', (string) $key);
                    } elseif ($sameVenue) {
                        $errors[] = sprintf('与已存在场次 %s 的场地时间冲突', (string) $key);
                    }
                }
            }

            if ($stageKey !== '' && ! isset($stageMeta[$stageKey])) {
                $stageMeta[$stageKey] = ['name' => $stageName, 'order' => $stageOrder];
            }

            if ($matchKey !== '') {
                $seenMatchKeys[$matchKey] = true;
            }

            if ($startsAt !== null && $endsAt !== null) {
                foreach ([$homeNo, $awayNo] as $entryNo) {
                    if ($entryNo !== '') {
                        $fileIntervals['entry'][$entryNo][] = [
                            'match_key' => $matchKey,
                            'start'     => $startsAt,
                            'end'       => $endsAt,
                        ];
                    }
                }

                if ($venue !== '') {
                    $fileIntervals['venue'][$venue][] = [
                        'match_key' => $matchKey,
                        'start'     => $startsAt,
                        'end'       => $endsAt,
                    ];
                }
            }

            $data = [
                'stage_key'   => $stageKey,
                'stage_name'  => $stageName,
                'stage_order' => $stageOrder,
                'match_key'   => $matchKey,
                'round_no'    => $roundNo,
                'starts_at'   => $startsAt,
                'ends_at'     => $endsAt,
                'venue'       => $venue !== '' ? $venue : null,
                'home_entry_no' => $homeNo !== '' ? $homeNo : null,
                'away_entry_no' => $awayNo !== '' ? $awayNo : null,
                'home_entry_id' => $home,
                'away_entry_id' => $away,
            ];

            $status = $errors === [] ? 'valid' : 'error';
            $summary['total']++;

            if ($status === 'valid') {
                $summary['valid']++;
                $summary[$action === 'create' ? 'creates' : ($action === 'update' ? 'updates' : 'skips')]++;
            } else {
                $summary['errors']++;
            }

            $rows[] = [
                'row_no'  => $rowNo,
                'status'  => $status,
                'action'  => $action,
                'errors'  => array_values(array_unique($errors)),
                'data'    => $data,
            ];
        }

        return ['preview_hash' => $hash, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  key-of<array<string, CompetitionEntry>>|null  $entries
     */
    private function resolveEntry(string $entryNo, $entries, string $field, array &$errors): ?int
    {
        if ($entryNo === '') {
            return null;
        }

        if (mb_strlen($entryNo) > 32) {
            $errors[] = $field.' 长度不得超过 32';

            return null;
        }

        $entry = $entries[$entryNo] ?? null;

        if ($entry === null) {
            $errors[] = $field.' 指向的参赛条目不存在、未确认或属于其它赛项';

            return null;
        }

        return (int) $entry->getKey();
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  \Illuminate\Support\Collection<string, CompetitionStage>  $stages
     */
    private function sameExistingPayload(CompetitionMatch $match, $stages, array $probe): bool
    {
        $stage = $stages[$probe['stage_key']] ?? null;

        if ($stage === null || (int) $match->stage_id !== (int) $stage->getKey()) {
            return false;
        }

        return (int) $match->round_no === (int) $probe['round_no']
            && (string) $match->starts_at?->format('Y-m-d H:i:s') === (string) $probe['starts_at']
            && (string) $match->ends_at?->format('Y-m-d H:i:s') === (string) $probe['ends_at']
            && (string) ($match->venue ?? '') === (string) ($probe['venue'] ?? '')
            && (int) ($match->home_entry_id ?? 0) === (int) ($probe['home'] ?? 0)
            && (int) ($match->away_entry_id ?? 0) === (int) ($probe['away'] ?? 0);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseOffsetTime(string $value, string $field, array &$errors): ?string
    {
        if ($value === '') {
            $errors[] = $field.' 必填';

            return null;
        }

        // 必须带偏移：不做“按服务器时区猜”的隐式解释。
        if (! preg_match('/(Z|[+-]\d{2}:\d{2})$/', $value)) {
            $errors[] = $field.' 必须带时区偏移（如 2026-11-01T09:00:00+08:00）';

            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $errors[] = $field.' 时间格式不合法';

            return null;
        }
    }

    /**
     * @return array<int, array{row_no: int, data: array<string, string>}>
     */
    private function parseCsv(string $contents): array
    {
        $maxBytes = (int) config('ecosystem_competition.schedule_import.max_bytes', 2097152);
        $maxRows = (int) config('ecosystem_competition.schedule_import.max_rows', 2000);
        $columns = (array) config('ecosystem_competition.schedule_import.columns', []);

        if (strlen($contents) > $maxBytes) {
            throw CompetitionException::scheduleInvalid('CSV 文件超过大小上限。');
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');

        if (! is_array($header)) {
            fclose($stream);

            throw CompetitionException::scheduleInvalid('CSV 缺少表头。');
        }

        $header = array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            $header,
        );

        $missingColumns = array_values(array_diff($columns, $header));
        $unknownColumns = array_values(array_diff($header, $columns));

        if (count(array_unique($header)) !== count($header)) {
            fclose($stream);

            throw CompetitionException::scheduleInvalid('CSV 表头存在重复列。');
        }

        if ($missingColumns !== [] || $unknownColumns !== []) {
            fclose($stream);

            throw CompetitionException::scheduleInvalid('CSV 表头不匹配。', [[
                'row_no' => 1,
                'errors' => array_merge(
                    $missingColumns !== [] ? ['缺少列：'.implode(', ', $missingColumns)] : [],
                    $unknownColumns !== [] ? ['未知列：'.implode(', ', $unknownColumns)] : [],
                ),
            ]]);
        }

        $rows = [];
        $rowNo = 1;

        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rowNo++;

            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }

            if (count($values) !== count($header)) {
                fclose($stream);

                throw CompetitionException::scheduleInvalid('CSV 行列数与表头不一致。', [[
                    'row_no' => $rowNo,
                    'errors' => [sprintf('期望 %d 列，实际 %d 列', count($header), count($values))],
                ]]);
            }

            if (count($rows) >= $maxRows) {
                fclose($stream);

                throw CompetitionException::scheduleInvalid('CSV 行数超过上限 '.$maxRows.'。');
            }

            $rows[] = [
                'row_no' => $rowNo,
                'data'   => array_combine($header, array_map(static fn (mixed $v): string => (string) $v, $values)),
            ];
        }

        fclose($stream);

        if ($rows === []) {
            throw CompetitionException::scheduleInvalid('CSV 没有数据行。');
        }

        return $rows;
    }

    private function readContents(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw CompetitionException::scheduleInvalid('上传文件无效或上传失败。');
        }

        $path = $file->getRealPath();
        $contents = $path !== false ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw CompetitionException::scheduleInvalid('无法读取 CSV 文件。');
        }

        return $contents;
    }

    private function assertTrackUsable(CompetitionTrack $track): void
    {
        if ((string) $track->status !== 'active') {
            throw CompetitionException::trackNotActive();
        }

        $this->rules->assertTrackReady($track);
    }
}
