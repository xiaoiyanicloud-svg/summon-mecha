<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Resources\Ecosystem\CompetitionResource;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\CompetitionAttachmentService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G3CompetitionFixtures as Fixtures;

/**
 * G3-05 · 作品草稿/版本/截止/跨队/私有附件。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G3 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_competition.write_enabled' => true,
        'ecosystem_competition.attachment.disk' => 'local',
    ]);

    Storage::fake('local');
});

function g3SubmissionContext(): array
{
    $admin = Fixtures::adminId();
    $event = Fixtures::event();
    $version = Fixtures::ruleVersion($event['id'], $admin);
    $series = Fixtures::series();
    $edition = Fixtures::edition($series['id'], $event['id']);
    $scope = Fixtures::scope($event['id'], 'robowar');
    $trackId = Fixtures::track($edition, $scope, $version);

    $captainId = Fixtures::customer('队长');
    $memberId = Fixtures::customer('队员');
    $teamId = Fixtures::team($edition, $trackId, $captainId);
    Fixtures::member($teamId, $memberId);

    $entryId = Fixtures::entry($edition, $trackId, $teamId, $captainId, [
        'rules_version_id' => (int) $version->getKey(),
        'rules_fingerprint' => DB::table('competition_tracks')->where('id', $trackId)->value('rules_fingerprint'),
    ]);

    return [
        'captain' => Customer::query()->findOrFail($captainId),
        'member'  => Customer::query()->findOrFail($memberId),
        'team'    => CompetitionTeam::query()->findOrFail($teamId),
        'entry'   => CompetitionEntry::query()->findOrFail($entryId),
        'track'   => CompetitionTrack::query()->findOrFail($trackId),
    ];
}

it('G3-05 · 草稿→提交→补正：旧版本保留且不再可改', function () {
    $ctx = g3SubmissionContext();
    $service = app(CompetitionSubmissionService::class);

    $workspace = $service->workspace($ctx['team'], $ctx['entry'], $ctx['captain']);
    expect($workspace['submission']->status)->toBe('draft');

    $v1 = $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['captain'], [
        'title'       => '第一版作品',
        'summary'     => '摘要',
        'description' => '描述',
        'repo_url'    => 'https://example.com/repo',
    ]);

    expect($v1->version_no)->toBe(1);
    expect($v1->status)->toBe('draft');
    expect($v1->content_fingerprint)->toHaveLength(64);

    $submitted = $service->submit($ctx['team'], $ctx['entry'], $ctx['captain']);
    expect($submitted->status)->toBe('submitted');

    expect(fn () => $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['captain'], [
        'title' => '直接改已提交版本',
    ]))->not->toThrow(CompetitionException::class); // 应新建补正版本，而不是改旧版

    $v2 = CompetitionSubmissionVersion::query()->where('version_no', 2)->first();
    expect($v2)->not->toBeNull();
    expect((int) $v2->submission_id)->toBe((int) $v1->submission_id);
    expect(CompetitionSubmissionVersion::query()->find($v1->id)->status)->toBe('submitted');
});

it('G3-05 · 取得锁后截止时间重检，过期提交拒绝', function () {
    $ctx = g3SubmissionContext();
    $service = app(CompetitionSubmissionService::class);

    $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['captain'], [
        'title' => '截止前草稿',
    ]);

    DB::table('competition_tracks')->where('id', $ctx['track']->id)->update([
        'submission_deadline_at' => now('UTC')->subHour()->format('Y-m-d H:i:s'),
    ]);

    expect(fn () => $service->submit($ctx['team'], $ctx['entry'], $ctx['captain']))
        ->toThrow(CompetitionException::class);
});

it('G3-05 · 非队长不能编辑，跨队不能写', function () {
    $ctx = g3SubmissionContext();
    $service = app(CompetitionSubmissionService::class);

    expect(fn () => $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['member'], [
        'title' => '队员越权',
    ]))->toThrow(CompetitionException::class);

    $otherCaptain = Customer::query()->findOrFail(Fixtures::customer('别的队长'));
    $otherTeam = CompetitionTeam::query()->findOrFail(
        Fixtures::team($ctx['track']->edition_id, $ctx['track']->id, (int) $otherCaptain->id),
    );

    expect(fn () => $service->saveDraft($otherTeam, $ctx['entry'], $otherCaptain, [
        'title' => '跨队写入',
    ]))->toThrow(CompetitionException::class);
});

it('G3-05 · 只允许 http/https 链接', function () {
    $ctx = g3SubmissionContext();

    expect(fn () => app(CompetitionSubmissionService::class)->saveDraft(
        $ctx['team'],
        $ctx['entry'],
        $ctx['captain'],
        ['title' => '危险链接', 'repo_url' => 'javascript:alert(1)'],
    ))->toThrow(CompetitionException::class);
});

it('G3-05 · 附件私有存储、跨队读取拒绝、DTO 不泄露存储路径', function () {
    $ctx = g3SubmissionContext();
    $service = app(CompetitionSubmissionService::class);
    $attachments = app(CompetitionAttachmentService::class);

    $version = $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['captain'], [
        'title' => '带附件作品',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'g3att_');
    file_put_contents($path, "hello submission\n");

    $attachment = $attachments->upload(
        $ctx['team'],
        $ctx['entry'],
        $version,
        $ctx['captain'],
        new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
    );

    expect($attachment->original_name)->toBe('notes.txt');
    expect(Storage::disk('local')->exists($attachment->storage_key))->toBeTrue();

    $dto = json_encode(CompetitionResource::submission($version->submission->fresh()), JSON_UNESCAPED_UNICODE);
    expect($dto)->not->toContain('storage_key');
    expect($dto)->not->toContain($attachment->storage_key);

    // 队员可读、跨队不可读。
    $file = $attachments->openForMember($ctx['member'], (int) $attachment->id);
    expect(is_resource($file['stream']))->toBeTrue();
    fclose($file['stream']);

    $outsider = Customer::query()->findOrFail(Fixtures::customer('外部'));
    expect(fn () => $attachments->openForMember($outsider, (int) $attachment->id))
        ->toThrow(CompetitionException::class);

    // 队员不能上传。
    expect(fn () => $attachments->upload(
        $ctx['team'],
        $ctx['entry'],
        $version,
        $ctx['member'],
        new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
    ))->toThrow(CompetitionException::class);
});

it('G3-05 · 已提交版本不能再上传附件', function () {
    $ctx = g3SubmissionContext();
    $service = app(CompetitionSubmissionService::class);
    $attachments = app(CompetitionAttachmentService::class);

    $version = $service->saveDraft($ctx['team'], $ctx['entry'], $ctx['captain'], ['title' => '作品']);
    $submitted = $service->submit($ctx['team'], $ctx['entry'], $ctx['captain']);

    $path = tempnam(sys_get_temp_dir(), 'g3att_');
    file_put_contents($path, "late\n");

    expect(fn () => $attachments->upload(
        $ctx['team'],
        $ctx['entry'],
        $submitted,
        $ctx['captain'],
        new UploadedFile($path, 'late.txt', 'text/plain', null, true),
    ))->toThrow(CompetitionException::class);
});
