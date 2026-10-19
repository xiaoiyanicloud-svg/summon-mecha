<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\BeginRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;

/**
 * G1-08B · 暂缓 / 恢复 / 撤稿全链（ADR-0001 §4.5 / §8.4，附录 B A-03/A-04）。
 *
 * 覆盖：
 *   · withhold 只作用于已发布对象、保留公开指针、清候选与排期、原因必填；
 *   · 重复暂缓 / 重复撤稿是 no-op；
 *   · restore 只允许 withheld，且必须重新通过全部前置，不能自动恢复；
 *   · retract 终态：publish / schedule / restore / 新建 revision 全部拒绝；
 *   · 两类 outbox 事件 `*.restored` 已登记并随事务写入；
 *   · 内容域同构全链。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08B disposition tests require summon_mecha_step10_test.');
    }
});

function g8bDspAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8B Disposition Operator',
        'email'      => 'g8b-dsp-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g8bDspOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'             => 'G8B DSP 主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g8bDspEvent(EcosystemOrganizer $organizer, array $overrides = []): Event
{
    return Event::create(array_merge([
        'name'           => 'G8B DSP 活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ], $overrides))->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8bDspEventPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                    => 'G8B DSP 活动公开名称',
        'summary'                 => '活动摘要',
        'detail_html'             => '<p>活动正文</p>',
        'city'                    => '上海',
        'location_text'           => '张江科学会堂',
        'participation_mode'      => 'offline',
        'timezone'                => 'Asia/Shanghai',
        'starts_at'               => '2026-11-01 10:00:00',
        'ends_at'                 => '2026-11-01 18:00:00',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网活动页',
        'source_url'              => 'https://example.com/events',
        'source_verified_at'      => '2026-10-01 10:00:00',
        'authorization_basis'     => 'self_organized',
        'rule_summary'            => '规则摘要',
        'change_summary'          => '初始版本',
    ], $overrides);
}

/**
 * approved + published 的活动（row_version 3）。
 *
 * @return array{0: Event, 1: EventRevision, 2: int, 3: EcosystemOrganizer}
 */
function g8bDspPublishedEvent(): array
{
    config(['ecosystem_publishing.event_publish_enabled' => true]);

    $adminId = g8bDspAdminId();
    $organizer = g8bDspOrganizer();
    $event = g8bDspEvent($organizer);
    $revision = app(EventRevisionService::class)->createDraft(
        $event,
        $adminId,
        g8bDspEventPayload($organizer),
    );

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $event = app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);
    $event = app(EventPublicationService::class)->publishNow($event, $revision->fresh(), $adminId, 2);

    return [$event->fresh(), $revision->fresh(), $adminId, $organizer];
}

function g8bDspEnableContent(): void
{
    config([
        'ecosystem_publishing.content_publish_enabled' => true,
        'ecosystem_publishing.b04_content_slug_signed' => true,
        'ecosystem_publishing.b07_content_legal_signed' => true,
    ]);
}

/**
 * approved + published 的文章（row_version 3）。
 *
 * @return array{0: ContentPost, 1: ContentRevision, 2: int}
 */
function g8bDspPublishedContent(): array
{
    g8bDspEnableContent();

    $adminId = g8bDspAdminId();
    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8b-dsp-'.bin2hex(random_bytes(5)),
    ]);

    $revision = app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        [
            'title'          => 'G8B DSP 资讯标题',
            'summary'        => '摘要',
            'body_html'      => '<p>正文</p>',
            'author_name'    => '张三',
            'author_org'     => '召唤机甲',
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

    $post = app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);
    $post = app(ContentReviewService::class)->approve($post, $revision->fresh(), $adminId, 1);
    $post = app(ContentPublicationService::class)->publishNow($post, $revision->fresh(), $adminId, 2);

    return [$post->fresh(), $revision->fresh(), $adminId];
}

it('G1-08B · 活动暂缓保留公开指针、清候选与排期；重复暂缓是 no-op', function () {
    [$event, $rev1, $adminId, $organizer] = g8bDspPublishedEvent();

    // 为“清候选与排期”准备一个新候选并排期。
    $rev2 = app(EventRevisionService::class)->createDraft(
        $event,
        $adminId,
        g8bDspEventPayload($organizer, ['name' => '待暂缓的第二版']),
    );
    $event = app(EventReviewService::class)->submit($event, $rev2, $adminId, 3);
    $event = app(EventReviewService::class)->approve($event, $rev2->fresh(), $adminId, 4);
    $event = app(EventPublicationService::class)->schedule(
        $event,
        $rev2->fresh(),
        $adminId,
        5,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    );

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->getKey())
        ->and((int) $event->approved_revision_id)->toBe((int) $rev2->getKey())
        ->and($event->scheduled_publish_at)->not->toBeNull();

    $withheld = app(EventPublicationService::class)->withhold($event, $adminId, 6, '版权争议');

    expect((string) $withheld->publish_status)->toBe('published')
        ->and((string) $withheld->disposition)->toBe('withheld')
        ->and((int) $withheld->published_revision_id)->toBe((int) $rev1->getKey())
        ->and($withheld->approved_revision_id)->toBeNull()
        ->and($withheld->scheduled_publish_at)->toBeNull()
        ->and((int) $withheld->row_version)->toBe(7);

    $outbox = EcosystemOutbox::query()->where('event_type', 'event.withheld')->sole();

    expect((int) $outbox->aggregate_id)->toBe((int) $event->id)
        ->and($outbox->payload['revision_id'])->toBe((int) $rev1->getKey())
        ->and($outbox->payload['reason_code'])->toBe('withheld')
        // 原因只进内部日志，不进 payload，更不进公开字段。
        ->and($outbox->payload)->not->toHaveKey('reason');

    // 重复暂缓：no-op，不推版本、不重复 outbox。
    $again = app(EventPublicationService::class)->withhold($withheld, $adminId, 7, '再次暂缓');

    expect((int) $again->row_version)->toBe(7)
        ->and(EcosystemOutbox::where('event_type', 'event.withheld')->count())->toBe(1);
});

it('G1-08B · 未发布对象不能暂缓；暂缓原因必填', function () {
    config(['ecosystem_publishing.event_publish_enabled' => true]);

    $adminId = g8bDspAdminId();
    $organizer = g8bDspOrganizer();
    $event = g8bDspEvent($organizer);
    $revision = app(EventRevisionService::class)->createDraft(
        $event,
        $adminId,
        g8bDspEventPayload($organizer),
    );
    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $event = app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);

    expect(fn () => app(EventPublicationService::class)->withhold($event, $adminId, 2, '未发布'))
        ->toThrow(PublicationTransitionException::class);

    expect((string) $event->fresh()->disposition)->toBe('normal');

    [$published] = g8bDspPublishedEvent();

    expect(fn () => app(EventPublicationService::class)->withhold($published, $adminId, 3, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect((string) $published->fresh()->disposition)->toBe('normal')
        ->and(EcosystemOutbox::where('event_type', 'event.withheld')->count())->toBe(0);
});

it('G1-08B · 恢复必须重新通过前置；不满足时保持 withheld 且不写 restored', function () {
    [$event, $revision, $adminId] = g8bDspPublishedEvent();
    $publisher = app(EventPublicationService::class);

    $withheld = $publisher->withhold($event, $adminId, 3, '等待复核');

    // 暂缓后正文被改动：审批绑定失效，恢复必须拒绝。
    DB::table('event_revisions')->where('id', $revision->id)->update([
        'detail_html' => '<p>暂缓后被改掉的正文</p>',
    ]);

    try {
        $publisher->restore($withheld, $adminId, 4);
        $this->fail('前置不满足时不应恢复。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe('EVENT_REVISION_CONFLICT');
    }

    expect((string) $withheld->fresh()->disposition)->toBe('withheld')
        ->and((int) $withheld->fresh()->row_version)->toBe(4)
        ->and(EcosystemOutbox::where('event_type', 'event.restored')->count())->toBe(0);

    // 不调用 restore 就不会自动恢复：再次读取仍是 withheld。
    expect((string) Event::query()->findOrFail($event->id)->disposition)->toBe('withheld');
});

it('G1-08B · 活动暂缓 -> 恢复写 restored；撤稿终态拒绝一切后续动作', function () {
    [$event, $rev1, $adminId, $organizer] = g8bDspPublishedEvent();
    $publisher = app(EventPublicationService::class);

    $withheld = $publisher->withhold($event, $adminId, 3, '合规复核');
    $restored = $publisher->restore($withheld, $adminId, 4);

    expect((string) $restored->disposition)->toBe('normal')
        ->and((int) $restored->published_revision_id)->toBe((int) $rev1->getKey())
        ->and((int) $restored->row_version)->toBe(5)
        ->and(EcosystemOutbox::where('event_type', 'event.restored')->count())->toBe(1);

    // 新候选 + 排期后再撤稿：候选与排期都必须清空。
    $rev2 = app(EventRevisionService::class)->createDraft(
        $restored,
        $adminId,
        g8bDspEventPayload($organizer, ['name' => '撤稿前候选']),
    );
    $event = app(EventReviewService::class)->submit($restored, $rev2, $adminId, 5);
    $event = app(EventReviewService::class)->approve($event, $rev2->fresh(), $adminId, 6);
    $event = $publisher->schedule(
        $event,
        $rev2->fresh(),
        $adminId,
        7,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    );

    $retracted = $publisher->retract($event, $adminId, 8, '经法务确认撤稿');

    expect((string) $retracted->disposition)->toBe('retracted')
        ->and((string) $retracted->publish_status)->toBe('published')
        ->and((int) $retracted->published_revision_id)->toBe((int) $rev1->getKey())
        ->and($retracted->approved_revision_id)->toBeNull()
        ->and($retracted->scheduled_publish_at)->toBeNull()
        ->and((int) $retracted->row_version)->toBe(9)
        ->and(EcosystemOutbox::where('event_type', 'event.retracted')->count())->toBe(1);

    // 终态：发布 / 排期 / 恢复 / 新建 revision 全部拒绝。
    expect(fn () => $publisher->publishNow($retracted, $rev2->fresh(), $adminId, 9))
        ->toThrow(PublicationTransitionException::class);

    expect(fn () => $publisher->schedule(
        $retracted,
        $rev2->fresh(),
        $adminId,
        9,
        new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
    ))->toThrow(PublicationTransitionException::class);

    expect(fn () => $publisher->restore($retracted, $adminId, 9))
        ->toThrow(PublicationTransitionException::class);

    expect(fn () => app(BeginRevisionService::class)->startEventRevision(
        $retracted,
        $adminId,
        9,
        g8bDspEventPayload($organizer, ['name' => '撤稿后新草稿']),
    ))->toThrow(PublicationTransitionException::class);

    // 重复撤稿 no-op。
    $again = $publisher->retract($retracted, $adminId, 9, '重复撤稿');
    expect((int) $again->row_version)->toBe(9)
        ->and(EcosystemOutbox::where('event_type', 'event.retracted')->count())->toBe(1);
});

it('G1-08B · 暂缓写 outbox 失败时整体回滚', function () {
    [$event, , $adminId] = g8bDspPublishedEvent();

    config(['ecosystem_publishing.outbox_event_types.event.withhold' => 'event.not_registered']);

    expect(fn () => app(EventPublicationService::class)->withhold($event, $adminId, 3, '回滚验证'))
        ->toThrow(InvalidArgumentException::class);

    $fresh = $event->fresh();

    expect((string) $fresh->disposition)->toBe('normal')
        ->and((int) $fresh->row_version)->toBe(3)
        ->and(EcosystemOutbox::where('event_type', 'event.withheld')->count())->toBe(0);
});

it('G1-08B · 内容暂缓 / 恢复 / 撤稿全链与活动同构', function () {
    [$post, $revision, $adminId] = g8bDspPublishedContent();
    $publisher = app(ContentPublicationService::class);

    $withheld = $publisher->withhold($post, $adminId, 3, '内容合规复核');

    expect((string) $withheld->disposition)->toBe('withheld')
        ->and((string) $withheld->publish_status)->toBe('published')
        ->and((int) $withheld->published_revision_id)->toBe((int) $revision->getKey())
        ->and((int) $withheld->row_version)->toBe(4)
        ->and(EcosystemOutbox::where('event_type', 'content.withheld')->count())->toBe(1);

    $restored = $publisher->restore($withheld, $adminId, 4);

    expect((string) $restored->disposition)->toBe('normal')
        ->and((int) $restored->row_version)->toBe(5)
        ->and(EcosystemOutbox::where('event_type', 'content.restored')->count())->toBe(1);

    $retracted = $publisher->retract($restored, $adminId, 5, '收到权利人撤稿通知');

    expect((string) $retracted->disposition)->toBe('retracted')
        ->and((int) $retracted->published_revision_id)->toBe((int) $revision->getKey())
        ->and((int) $retracted->row_version)->toBe(6)
        ->and(EcosystemOutbox::where('event_type', 'content.retracted')->count())->toBe(1);

    // 终态拒绝发布与恢复。
    expect(fn () => $publisher->publishNow($retracted, $revision, $adminId, 6))
        ->toThrow(PublicationTransitionException::class);

    expect(fn () => $publisher->restore($retracted, $adminId, 6))
        ->toThrow(PublicationTransitionException::class);
});
