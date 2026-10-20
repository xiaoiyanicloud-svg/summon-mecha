<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionAppealAttachment;
use Webkul\Shop\Models\CompetitionAppealEvent;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-09 · 申诉提交、受理、调查与裁决。
 *
 * 边界：
 *   · 只有规则允许的主体（本批 captain）可提交；队员/路人拒绝；
 *   · 窗口以“当次公布版本的 published_at + window_minutes”为准；
 *   · 申诉绑定具体 result_version，裁决必须记录适用版本；
 *   · 证据按规则声明的类型映射真实 MIME，私有存储，不返回 storage_key；
 *   · 事件时间线只追加，不删除历史。
 */
class CompetitionAppealService
{
    private const EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'video/mp4'       => ['mp4'],
        'text/plain'      => ['txt'],
    ];

    public function __construct(
        private readonly CompetitionRuleProfile $rules,
        private readonly OperationLogger $logger,
        private readonly OutboxWriter $outbox,
    ) {}

    public function submit(int $resultId, int $resultVersionId, Customer $customer, string $reason): CompetitionAppeal
    {
        $this->rules->assertWriteEnabled();

        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 4000) {
            throw CompetitionException::invalid('申诉理由必须填写 10~4000 字。');
        }

        return DB::transaction(function () use ($resultId, $resultVersionId, $customer, $reason): CompetitionAppeal {
            // 锁序与成绩服务一致：track → result。
            $probe = CompetitionResult::query()->find($resultId);

            if ($probe === null) {
                throw CompetitionException::resultNotFound();
            }

            $track = CompetitionTrack::query()->whereKey($probe->track_id)->lockForUpdate()->firstOrFail();
            $this->rules->assertTrackReady($track);

            $result = CompetitionResult::query()->whereKey($resultId)->lockForUpdate()->first();

            if ($result === null) {
                throw CompetitionException::resultNotFound();
            }

            if ((string) $track->status !== 'active' && (string) $track->status !== 'closed') {
                throw CompetitionException::trackNotActive();
            }

            if ($result->published_version_id === null || (int) $result->published_version_id !== $resultVersionId) {
                throw CompetitionException::appealResultChanged('申诉必须绑定当前公布的成绩版本。');
            }

            $version = CompetitionResultVersion::query()->find($resultVersionId);

            if ($version === null || $version->published_at === null) {
                throw CompetitionException::appealClosed('成绩尚未公布，不能申诉。');
            }

            $entry = CompetitionEntry::query()->find($result->entry_id);

            if ($entry === null) {
                throw CompetitionException::appealForbidden();
            }

            $team = CompetitionTeam::query()->find($entry->team_id);

            if ($team === null || (string) $team->status !== CompetitionTeam::STATUS_ACTIVE) {
                throw CompetitionException::appealForbidden('队伍已解散，不能提交申诉。');
            }

            $isCaptain = CompetitionTeamMember::query()
                ->where('team_id', $team->getKey())
                ->where('customer_id', (int) $customer->getKey())
                ->where('role', CompetitionTeamMember::ROLE_CAPTAIN)
                ->where('status', CompetitionTeamMember::STATUS_ACTIVE)
                ->exists();

            $profile = (array) $track->rules_profile;
            $appealRules = (array) ($profile['appeal'] ?? []);
            $allowedRoles = (array) ($appealRules['allowed_roles'] ?? []);

            if (! $isCaptain || ! in_array('captain', $allowedRoles, true)) {
                throw CompetitionException::appealForbidden('当前规则只允许队长提交申诉。');
            }

            $windowMinutes = (int) ($appealRules['window_minutes'] ?? 0);

            if ($windowMinutes <= 0) {
                throw CompetitionException::rulesUnconfirmed('申诉窗口未在规则中明确。');
            }

            $openedAt = $version->published_at;
            $deadline = $openedAt->copy()->addMinutes($windowMinutes);
            $now = now('UTC');

            if ($now->lessThan($openedAt)) {
                throw CompetitionException::appealClosed('申诉窗口尚未开始。');
            }

            if ($now->greaterThan($deadline)) {
                throw CompetitionException::appealClosed(sprintf(
                    '申诉窗口已于 %s 截止。',
                    $deadline->toIso8601String(),
                ));
            }

            $duplicate = CompetitionAppeal::query()
                ->where('result_version_id', $resultVersionId)
                ->where('submitted_by_customer_id', (int) $customer->getKey())
                ->whereNotIn('status', CompetitionAppeal::TERMINAL)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw CompetitionException::appealClosed('同一成绩版本已有进行中的申诉。');
            }

            $appeal = new CompetitionAppeal();
            $appeal->fill([
                'appeal_no'               => 'AP'.strtoupper(bin2hex(random_bytes(8))),
                'edition_id'              => (int) $result->edition_id,
                'track_id'                => (int) $track->getKey(),
                'entry_id'                => (int) $entry->getKey(),
                'result_id'               => (int) $result->getKey(),
                'result_version_id'       => (int) $version->getKey(),
                'submitted_by_customer_id'=> (int) $customer->getKey(),
                'status'                  => CompetitionAppeal::STATUS_SUBMITTED,
                'reason'                  => $reason,
                'rule_version_id'         => $track->rules_version_id,
                'rules_fingerprint'       => $track->rules_fingerprint,
                'window_opens_at'         => $openedAt,
                'deadline_at'             => $deadline,
                'submitted_at'            => $now,
                'row_version'             => 0,
            ]);
            $appeal->save();

            $this->appendEvent($appeal, CompetitionAppealEvent::TYPE_SUBMITTED, 'customer', (int) $customer->getKey(), $reason);

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.appeal.submitted',
                'competition_appeal',
                (int) $appeal->getKey(),
                [
                    'result_id'         => (int) $result->getKey(),
                    'result_version_id' => (int) $version->getKey(),
                    'track_id'          => (int) $track->getKey(),
                ],
            );

            return $appeal->refresh();
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, CompetitionAppeal>
     */
    public function listForCustomer(Customer $customer)
    {
        return CompetitionAppeal::query()
            ->with(['events', 'attachments'])
            ->where('submitted_by_customer_id', (int) $customer->getKey())
            ->orderByDesc('id')
            ->get();
    }

    public function findForCustomer(Customer $customer, int $appealId): CompetitionAppeal
    {
        $appeal = CompetitionAppeal::query()
            ->with(['events', 'attachments'])
            ->whereKey($appealId)
            ->where('submitted_by_customer_id', (int) $customer->getKey())
            ->first();

        if ($appeal === null) {
            throw CompetitionException::appealNotFound();
        }

        return $appeal;
    }

    public function withdraw(CompetitionAppeal $appeal, Customer $customer, int $expectedRowVersion, string $reason = ''): CompetitionAppeal
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($appeal, $customer, $expectedRowVersion, $reason): CompetitionAppeal {
            $locked = CompetitionAppeal::query()->whereKey($appeal->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->submitted_by_customer_id !== (int) $customer->getKey()) {
                throw CompetitionException::appealForbidden();
            }

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ($locked->isTerminal()) {
                throw CompetitionException::appealClosed('申诉已结束，不能撤回。');
            }

            $locked->status = CompetitionAppeal::STATUS_WITHDRAWN;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->appendEvent($locked, CompetitionAppealEvent::TYPE_WITHDRAWN, 'customer', (int) $customer->getKey(), $reason);

            $this->queueAppealEvent($locked, 'competition_appeal.withdrawn');

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                'competition.appeal.withdrawn',
                'competition_appeal',
                (int) $locked->getKey(),
                ['reason' => $reason],
            );

            return $locked->refresh();
        });
    }

    public function accept(CompetitionAppeal $appeal, int $adminId, int $expectedRowVersion, string $note = ''): CompetitionAppeal
    {
        $this->rules->assertWriteEnabled();

        return DB::transaction(function () use ($appeal, $adminId, $expectedRowVersion, $note): CompetitionAppeal {
            $locked = CompetitionAppeal::query()->whereKey($appeal->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if ((string) $locked->status !== CompetitionAppeal::STATUS_SUBMITTED) {
                throw CompetitionException::appealClosed('只有待受理的申诉可以受理。');
            }

            $locked->status = CompetitionAppeal::STATUS_ACCEPTED;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->appendEvent($locked, CompetitionAppealEvent::TYPE_ACCEPTED, 'admin', $adminId, $note);
            $this->queueAppealEvent($locked, 'competition_appeal.accepted');

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.appeal.accepted',
                'competition_appeal',
                (int) $locked->getKey(),
                ['note' => $note],
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array{decision_result:string, decision_reason:string, decision_result_version_id:int}  $data
     */
    public function decide(CompetitionAppeal $appeal, int $adminId, int $expectedRowVersion, array $data): CompetitionAppeal
    {
        $this->rules->assertWriteEnabled();

        $decision = (string) ($data['decision_result'] ?? '');
        $reason = trim((string) ($data['decision_reason'] ?? ''));
        $resultVersionId = (int) ($data['decision_result_version_id'] ?? 0);
        $allowed = (array) config('ecosystem_competition.statuses.appeal_decision', []);

        if (! in_array($decision, $allowed, true)) {
            throw CompetitionException::invalid('裁决结果不合法。');
        }

        if (mb_strlen($reason) < 4 || mb_strlen($reason) > 4000) {
            throw CompetitionException::invalid('裁决理由必须填写 4~4000 字。');
        }

        return DB::transaction(function () use ($appeal, $adminId, $expectedRowVersion, $decision, $reason, $resultVersionId): CompetitionAppeal {
            $locked = CompetitionAppeal::query()->whereKey($appeal->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->row_version !== $expectedRowVersion) {
                throw CompetitionException::versionStale();
            }

            if (! in_array((string) $locked->status, [
                CompetitionAppeal::STATUS_ACCEPTED,
                CompetitionAppeal::STATUS_INVESTIGATING,
            ], true)) {
                throw CompetitionException::appealClosed('只有已受理/调查中的申诉可以裁决。');
            }

            $result = CompetitionResult::query()->whereKey($locked->result_id)->lockForUpdate()->firstOrFail();

            if ($result->published_version_id === null
                || (int) $result->published_version_id !== $resultVersionId) {
                throw CompetitionException::appealResultChanged();
            }

            $locked->status = in_array($decision, ['rejected', 'inadmissible'], true)
                ? CompetitionAppeal::STATUS_REJECTED
                : CompetitionAppeal::STATUS_DECIDED;
            $locked->decision_result = $decision;
            $locked->decision_reason = $reason;
            $locked->decision_result_version_id = $resultVersionId;
            $locked->decided_at = now('UTC');
            $locked->decided_by_admin_id = $adminId;
            $locked->row_version = (int) $locked->row_version + 1;
            $locked->save();

            $this->appendEvent(
                $locked,
                CompetitionAppealEvent::TYPE_DECISION,
                'admin',
                $adminId,
                $reason,
                [
                    'decision_result'           => $decision,
                    'decision_result_version_id'=> $resultVersionId,
                    'status'                    => (string) $locked->status,
                ],
            );

            $this->queueAppealEvent($locked, 'competition_appeal.decided');

            $this->logger->logOrFail(
                'admin',
                $adminId,
                'competition.appeal.decided',
                'competition_appeal',
                (int) $locked->getKey(),
                [
                    'decision_result'            => $decision,
                    'decision_result_version_id' => $resultVersionId,
                ],
            );

            return $locked->refresh();
        });
    }

    public function addEvidence(CompetitionAppeal $appeal, Customer $customer, UploadedFile $file): CompetitionAppealAttachment
    {
        $this->rules->assertWriteEnabled();

        $locked = CompetitionAppeal::query()->whereKey($appeal->getKey())->firstOrFail();

        if ((int) $locked->submitted_by_customer_id !== (int) $customer->getKey()) {
            throw CompetitionException::appealForbidden();
        }

        if ($locked->isTerminal()) {
            throw CompetitionException::appealClosed('申诉已结束，不能再补充证据。');
        }

        $track = CompetitionTrack::query()->findOrFail($locked->track_id);
        $this->rules->assertTrackReady($track);

        $types = (array) (($track->rules_profile['appeal']['evidence_types'] ?? []));
        $mimeMap = (array) config('ecosystem_competition.appeal.evidence_mimes', []);
        $allowedMimes = [];

        foreach ($types as $type) {
            foreach ((array) ($mimeMap[$type] ?? []) as $mime) {
                $allowedMimes[$mime] = self::EXTENSIONS[$mime] ?? [];
            }
        }

        if ($allowedMimes === []) {
            throw CompetitionException::appealEvidenceInvalid('规则没有配置可接受的证据类型。');
        }

        if (! $file->isValid()) {
            throw CompetitionException::appealEvidenceInvalid('上传文件无效或上传失败。');
        }

        $size = (int) $file->getSize();
        $maxBytes = (int) config('ecosystem_competition.appeal.max_bytes', 10485760);

        if ($size <= 0 || $size > $maxBytes) {
            throw CompetitionException::appealEvidenceInvalid(sprintf('证据大小必须在 1~%d 字节之间。', $maxBytes));
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw CompetitionException::appealEvidenceInvalid('证据文件无法读取。');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = mb_strtolower((string) $finfo->file($realPath));

        if (! array_key_exists($mime, $allowedMimes)) {
            throw CompetitionException::appealEvidenceInvalid(sprintf('证据类型「%s」不在本赛事允许范围内。', $mime));
        }

        $originalName = $this->sanitizeName($file->getClientOriginalName());
        $extension = mb_strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, $allowedMimes[$mime], true)) {
            throw CompetitionException::appealEvidenceInvalid(sprintf(
                '扩展名「%s」与真实 MIME「%s」不匹配。',
                $extension,
                $mime,
            ));
        }

        $sha256 = hash_file('sha256', $realPath);

        if ($sha256 === false) {
            throw CompetitionException::appealEvidenceInvalid('无法计算证据指纹。');
        }

        $count = CompetitionAppealAttachment::query()
            ->where('appeal_id', $locked->getKey())
            ->where('status', CompetitionAppealAttachment::STATUS_ACTIVE)
            ->count();

        if ($count >= (int) config('ecosystem_competition.appeal.max_files', 6)) {
            throw CompetitionException::appealEvidenceInvalid('证据数量已达上限。');
        }

        $existing = CompetitionAppealAttachment::query()
            ->where('appeal_id', $locked->getKey())
            ->where('sha256', $sha256)
            ->where('status', CompetitionAppealAttachment::STATUS_ACTIVE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $disk = (string) config('ecosystem_competition.appeal.disk', 'local');
        $directory = trim((string) config('ecosystem_competition.appeal.directory', 'ecosystem/competition-appeals'), '/');
        $storageKey = sprintf('%s/%d/%s.%s', $directory, (int) $locked->getKey(), $sha256, $extension);

        $stream = fopen($realPath, 'rb');

        if ($stream === false) {
            throw CompetitionException::appealEvidenceInvalid('证据文件无法读取。');
        }

        try {
            $stored = Storage::disk($disk)->put($storageKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw CompetitionException::appealEvidenceInvalid('证据写入私有存储失败。');
        }

        try {
            return DB::transaction(function () use ($locked, $customer, $originalName, $storageKey, $mime, $size, $sha256): CompetitionAppealAttachment {
                $attachment = new CompetitionAppealAttachment();
                $attachment->fill([
                    'appeal_id'    => (int) $locked->getKey(),
                    'customer_id'  => (int) $customer->getKey(),
                    'original_name'=> $originalName,
                    'storage_key'  => $storageKey,
                    'mime'         => $mime,
                    'byte_size'    => $size,
                    'sha256'       => $sha256,
                    'status'       => CompetitionAppealAttachment::STATUS_ACTIVE,
                ]);
                $attachment->save();

                $this->logger->logOrFail(
                    'customer',
                    (int) $customer->getKey(),
                    'competition.appeal.evidence_uploaded',
                    'competition_appeal_attachment',
                    (int) $attachment->getKey(),
                    [
                        'appeal_id' => (int) $locked->getKey(),
                        'mime'      => $mime,
                        'byte_size' => $size,
                        'sha256'    => $sha256,
                    ],
                );

                return $attachment->refresh();
            });
        } catch (\Throwable $exception) {
            if (! CompetitionAppealAttachment::query()->where('storage_key', $storageKey)->exists()) {
                Storage::disk($disk)->delete($storageKey);
            }

            throw $exception;
        }
    }

    /**
     * @return array{stream: resource, mime: string, name: string, size: int}
     */
    public function openEvidenceForCustomer(Customer $customer, int $attachmentId): array
    {
        $attachment = CompetitionAppealAttachment::query()
            ->whereKey($attachmentId)
            ->where('status', CompetitionAppealAttachment::STATUS_ACTIVE)
            ->first();

        if ($attachment === null) {
            throw CompetitionException::attachmentNotFound('证据不存在。');
        }

        $appeal = CompetitionAppeal::query()->find($attachment->appeal_id);

        if ($appeal === null || (int) $appeal->submitted_by_customer_id !== (int) $customer->getKey()) {
            throw CompetitionException::attachmentNotFound('证据不存在。');
        }

        return $this->openEvidence($attachment);
    }

    /**
     * @return array{stream: resource, mime: string, name: string, size: int}
     */
    public function openEvidenceForAdmin(int $attachmentId): array
    {
        $attachment = CompetitionAppealAttachment::query()
            ->whereKey($attachmentId)
            ->where('status', CompetitionAppealAttachment::STATUS_ACTIVE)
            ->first();

        if ($attachment === null) {
            throw CompetitionException::attachmentNotFound('证据不存在。');
        }

        return $this->openEvidence($attachment);
    }

    public function adminFind(int $appealId): CompetitionAppeal
    {
        $appeal = CompetitionAppeal::query()
            ->with(['events', 'attachments', 'entry.team', 'result'])
            ->find($appealId);

        if ($appeal === null) {
            throw CompetitionException::appealNotFound();
        }

        return $appeal;
    }

    /**
     * @return \Illuminate\Support\Collection<int, CompetitionAppeal>
     */
    public function adminList(int $trackId)
    {
        return CompetitionAppeal::query()
            ->with(['entry.team', 'attachments', 'events'])
            ->where('track_id', $trackId)
            ->orderByDesc('id')
            ->get();
    }

    public function toCustomerDto(CompetitionAppeal $appeal): array
    {
        $appeal->loadMissing(['events', 'attachments']);

        return [
            'id'                 => (int) $appeal->getKey(),
            'appeal_no'          => (string) $appeal->appeal_no,
            'status'             => (string) $appeal->status,
            'row_version'        => (int) $appeal->row_version,
            'reason'             => (string) $appeal->reason,
            'track_id'           => (int) $appeal->track_id,
            'result_id'          => (int) $appeal->result_id,
            'result_version_id'  => (int) $appeal->result_version_id,
            'window_opens_at'    => $appeal->window_opens_at?->toIso8601String(),
            'deadline_at'        => $appeal->deadline_at?->toIso8601String(),
            'submitted_at'       => $appeal->submitted_at?->toIso8601String(),
            'decided_at'         => $appeal->decided_at?->toIso8601String(),
            'decision_result'    => $appeal->decision_result,
            'decision_reason'    => $appeal->decision_reason,
            'decision_result_version_id' => $appeal->decision_result_version_id,
            'events'             => $appeal->events->sortBy('id')->map(fn (CompetitionAppealEvent $event): array => [
                'type'       => (string) $event->event_type,
                'actor_type' => (string) $event->actor_type,
                'reason'     => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
            'attachments'        => $appeal->attachments
                ->where('status', CompetitionAppealAttachment::STATUS_ACTIVE)
                ->map(fn (CompetitionAppealAttachment $attachment): array => [
                    'id'            => (int) $attachment->getKey(),
                    'original_name' => (string) $attachment->original_name,
                    'mime'          => (string) $attachment->mime,
                    'byte_size'     => (int) $attachment->byte_size,
                    'download_url'  => '/api/customer/competition/appeal-attachments/'.(int) $attachment->getKey(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array{stream: resource, mime: string, name: string, size: int}
     */
    private function openEvidence(CompetitionAppealAttachment $attachment): array
    {
        $disk = (string) config('ecosystem_competition.appeal.disk', 'local');
        $storage = Storage::disk($disk);

        if (! $storage->exists($attachment->storage_key)) {
            throw CompetitionException::attachmentNotFound('证据文件缺失，请联系运营。');
        }

        $stream = $storage->readStream($attachment->storage_key);

        if (! is_resource($stream)) {
            throw CompetitionException::attachmentNotFound('证据读取失败。');
        }

        return [
            'stream' => $stream,
            'mime'   => (string) $attachment->mime,
            'name'   => (string) $attachment->original_name,
            'size'   => (int) $attachment->byte_size,
        ];
    }

    private function appendEvent(
        CompetitionAppeal $appeal,
        string $type,
        string $actorType,
        ?int $actorId,
        ?string $reason = null,
        array $payload = [],
    ): CompetitionAppealEvent {
        return CompetitionAppealEvent::create([
            'appeal_id'  => (int) $appeal->getKey(),
            'event_type' => $type,
            'actor_type' => $actorType,
            'actor_id'   => $actorId,
            'reason'     => $reason,
            'payload'    => $payload ?: null,
        ]);
    }

    private function queueAppealEvent(CompetitionAppeal $appeal, string $eventType): void
    {
        $this->outbox->write(
            'competition_appeal',
            (int) $appeal->getKey(),
            $eventType,
            [
                'appeal_id'  => (int) $appeal->getKey(),
                'status'     => (string) $appeal->status,
                'row_version'=> (int) $appeal->row_version,
                'track_id'   => (int) $appeal->track_id,
            ],
            sprintf('competition-appeal:%d:%d:%s', (int) $appeal->getKey(), (int) $appeal->row_version, $eventType),
        );
    }

    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $name) ?? 'evidence';
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '' || mb_strlen($name) > 160) {
            $name = mb_substr($name !== '' ? $name : 'evidence', 0, 160);
        }

        return $name;
    }
}
