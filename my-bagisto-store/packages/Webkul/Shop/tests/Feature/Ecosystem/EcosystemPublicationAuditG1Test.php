<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G1-08A · 审核审计同事务与 payload 边界（ADR 附录 B A-05）。
 *
 * 覆盖：
 *   · submit / approve / reject 写 operation_log（actor=admin，target=父对象）；
 *   · payload 只含 revision_id / revision_no / from / to / fingerprint_version / reason_code；
 *   · 拒绝原因自由文本不进入 payload（只落 revision.review_note）；
 *   · OperationLogger::logOrFail 失败时整个业务动作回滚。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A audit tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8AudAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Audit Admin',
        'email'      => 'g8-audit-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8AudOrganizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G8 审计主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

function g8AudEvent(EcosystemOrganizer $organizer): Event
{
    return Event::create([
        'name'           => 'G8 审计活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ])->fresh();
}

function g8AudEventDraft(Event $event, EcosystemOrganizer $organizer, int $adminId): \Webkul\Shop\Models\EventRevision
{
    return app(EventRevisionService::class)->createDraft($event, $adminId, [
        'name'                    => 'G8 审计活动名称',
        'detail_html'             => '<p>正文</p>',
        'timezone'                => 'Asia/Shanghai',
        'starts_at'               => '2026-11-01 10:00:00',
        'ends_at'                 => '2026-11-01 18:00:00',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网',
        'source_url'              => 'https://example.com/events',
        'source_verified_at'      => '2026-10-01 10:00:00',
        'authorization_basis'     => 'self_organized',
        'change_summary'          => '初始版本',
    ]);
}

function g8AudContentDraft(ContentPost $post, int $adminId): \Webkul\Shop\Models\ContentRevision
{
    return app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        [
            'title'          => 'G8 审计资讯',
            'body_html'      => '<p>正文</p>',
            'author_name'    => '张三',
            'source_type'    => 'original',
            'change_summary' => '初始版本',
        ],
        [[
            'source_name'        => '平台官网',
            'source_url'         => 'https://example.com/news/1',
            'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
            'rights_basis'       => 'platform_original',
        ]],
    );
}

function g8AudLog(string $action): ?object
{
    return DB::table('operation_log')
        ->where('action', $action)
        ->orderByDesc('id')
        ->first();
}

it('G1-08A · 活动提交 / 批准写审计，payload 只含白名单字段', function () {
    $adminId = g8AudAdminId();
    $organizer = g8AudOrganizer();
    $event = g8AudEvent($organizer);
    $revision = g8AudEventDraft($event, $organizer, $adminId);

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $submitLog = g8AudLog('ecosystem.event.submit');

    expect($submitLog)->not->toBeNull()
        ->and((string) $submitLog->actor_type)->toBe('admin')
        ->and((int) $submitLog->actor_id)->toBe($adminId)
        ->and((string) $submitLog->target_type)->toBe('event')
        ->and((int) $submitLog->target_id)->toBe((int) $event->id);

    $payload = json_decode((string) $submitLog->payload, true);
    $keys = array_keys($payload);
    sort($keys);

    expect($keys)->toBe([
        'fingerprint_version',
        'from',
        'reason_code',
        'revision_id',
        'revision_no',
        'to',
    ])
        ->and($payload['revision_id'])->toBe((int) $revision->id)
        ->and($payload['revision_no'])->toBe((int) $revision->revision_no)
        ->and($payload['from'])->toBe('draft')
        ->and($payload['to'])->toBe('in_review')
        ->and($payload['reason_code'])->toBe('submitted')
        ->and($payload['fingerprint_version'])->toBe((int) config('ecosystem_revisions.fingerprint_version', 1));

    // 不含自由文本 / PII。
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($encoded)->not->toContain('@')
        ->and($encoded)->not->toContain('G8 审计活动名称')
        ->and($encoded)->not->toContain('手机');

    app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1, '审核通过');

    $approveLog = g8AudLog('ecosystem.event.approve');

    expect($approveLog)->not->toBeNull()
        ->and(json_decode((string) $approveLog->payload, true)['to'])->toBe('approved')
        ->and(json_decode((string) $approveLog->payload, true)['reason_code'])->toBe('approved');
});

it('G1-08A · 拒绝原因自由文本不进入审计 payload', function () {
    $adminId = g8AudAdminId();
    $organizer = g8AudOrganizer();
    $event = g8AudEvent($organizer);
    $revision = g8AudEventDraft($event, $organizer, $adminId);

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $secretReason = '内部原因：联系人手机 13800000000';
    app(EventReviewService::class)->reject($event, $revision->fresh(), $adminId, 1, $secretReason);

    $log = g8AudLog('ecosystem.event.reject');
    $payload = json_decode((string) $log->payload, true);

    expect($payload['reason_code'])->toBe('review_rejected')
        ->and(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('13800000000')
        ->and($revision->fresh()->review_note)->toBe($secretReason);
});

it('G1-08A · 内容提交写审计（target_type=content_post）', function () {
    $adminId = g8AudAdminId();
    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-audit-'.bin2hex(random_bytes(5)),
    ]);
    $revision = g8AudContentDraft($post, $adminId);

    app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);

    $log = g8AudLog('ecosystem.content.submit');

    expect($log)->not->toBeNull()
        ->and((string) $log->target_type)->toBe('content_post')
        ->and((int) $log->target_id)->toBe((int) $post->id)
        ->and(json_decode((string) $log->payload, true)['revision_id'])->toBe((int) $revision->id);
});

it('G1-08A · 审计 logOrFail 失败时提交整体回滚', function () {
    $adminId = g8AudAdminId();
    $organizer = g8AudOrganizer();
    $event = g8AudEvent($organizer);
    $revision = g8AudEventDraft($event, $organizer, $adminId);

    app()->instance(OperationLogger::class, new class extends OperationLogger
    {
        public function logOrFail(
            string $actorType,
            ?int $actorId,
            string $action,
            ?string $targetType = null,
            ?int $targetId = null,
            array $payload = [],
            ?string $ip = null,
        ): OperationLog {
            throw new RuntimeException('audit storage down');
        }
    });

    expect(fn () => app(EventReviewService::class)->submit($event, $revision, $adminId, 0))
        ->toThrow(RuntimeException::class, 'audit storage down');

    expect((string) $event->fresh()->publish_status)->toBe('draft')
        ->and((int) $event->fresh()->row_version)->toBe(0)
        ->and($event->fresh()->working_revision_id)->toBeNull()
        ->and((string) $revision->fresh()->review_state)->toBe('draft')
        ->and($revision->fresh()->submitted_at)->toBeNull()
        ->and(g8AudLog('ecosystem.event.submit'))->toBeNull();
});
