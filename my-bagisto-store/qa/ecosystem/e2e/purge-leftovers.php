<?php

/**
 * e2e 演练中断后的孤儿清理（仅独立测试库）。
 *
 *   php qa/ecosystem/e2e/purge-leftovers.php
 *
 * 只处理 **引用对象已不存在** 的行和带 QA-E2E / E2E-SKILL 标记的夹具：
 *   · 活动已删但报名/scope/规则/赛务/outbox 仍残留；
 *   · 关联指向已删活动/内容/机型/技能/场景；
 *   · QA 客户的报名、收藏、通知；
 *   · 带 QA-E2E 标记的内容、机型、场景、技能。
 *
 * 不删除任何仍被现有活动引用的数据。bootstrap.php 会拒绝非 _test 库。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$deleted = [];

$eventIds = DB::table('events')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
$contentSlugs = DB::table('content_posts')->pluck('slug')->map(static fn ($slug): string => (string) $slug)->all();

// 夹具活动的主名称在 revision 里（events.name 是旧列），必须按 revision 找。
$qaEventIds = DB::table('event_revisions')->where('name', 'like', 'QA-E2E-%')
    ->pluck('event_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

$orphanEventIds = collect([
    DB::table('event_registration_scopes')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    DB::table('event_rule_versions')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    DB::table('event_registrations')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    DB::table('event_registration_attempts')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    DB::table('event_registration_requests')->where('target_type', 'event')->whereNotIn('target_id', $eventIds)->pluck('target_id'),
    DB::table('competition_editions')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    DB::table('event_revisions')->whereNotIn('event_id', $eventIds)->pluck('event_id'),
    collect($qaEventIds),
])->flatten()->map(static fn ($id): int => (int) $id)->unique()->values()->all();

$orphanEditionIds = DB::table('competition_editions')->whereIn('event_id', $orphanEventIds)->pluck('id')->all();
$orphanTrackIds = DB::table('competition_tracks')->whereIn('edition_id', $orphanEditionIds)->pluck('id')->all();
$orphanTeamIds = DB::table('competition_teams')->whereIn('track_id', $orphanTrackIds)->pluck('id')->all();
$orphanEntryIds = DB::table('competition_entries')->whereIn('track_id', $orphanTrackIds)->pluck('id')->all();
$orphanResultIds = DB::table('competition_results')->whereIn('track_id', $orphanTrackIds)->pluck('id')->all();

// ── 赛务 ─────────────────────────────────────────────────────────────
// 展示引用 submission_version / award / result，必须先删。
if ($orphanTrackIds !== []) {
    DB::table('competition_showcases')->whereIn('track_id', $orphanTrackIds)->delete();
    $awardIds = DB::table('competition_awards')->whereIn('track_id', $orphanTrackIds)->pluck('id')->all();

    if ($awardIds !== []) {
        DB::table('competition_award_versions')->whereIn('award_id', $awardIds)->delete();
        DB::table('competition_awards')->whereIn('id', $awardIds)->delete();
    }
}

if ($orphanEntryIds !== []) {
    $submissionIds = DB::table('competition_submissions')->whereIn('entry_id', $orphanEntryIds)->pluck('id')->all();
    $versionIds = $submissionIds === [] ? [] : DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->pluck('id')->all();
    $appealIds = DB::table('competition_appeals')->whereIn('entry_id', $orphanEntryIds)->pluck('id')->all();

    if ($versionIds !== []) {
        DB::table('competition_submission_attachments')->whereIn('version_id', $versionIds)->delete();
        DB::table('competition_submission_versions')->whereIn('submission_id', $submissionIds)->delete();
    }

    if ($submissionIds !== []) {
        DB::table('competition_submissions')->whereIn('id', $submissionIds)->delete();
    }

    if ($appealIds !== []) {
        DB::table('competition_appeal_attachments')->whereIn('appeal_id', $appealIds)->delete();
        DB::table('competition_appeal_events')->whereIn('appeal_id', $appealIds)->delete();
        DB::table('competition_appeals')->whereIn('id', $appealIds)->delete();
    }
}

if ($orphanResultIds !== []) {
    DB::table('competition_result_versions')->whereIn('result_id', $orphanResultIds)->delete();
    DB::table('competition_results')->whereIn('id', $orphanResultIds)->delete();
}

if ($orphanTrackIds !== []) {
    DB::table('competition_matches')->whereIn('track_id', $orphanTrackIds)->delete();
    DB::table('competition_stages')->whereIn('track_id', $orphanTrackIds)->delete();
    DB::table('competition_entries')->whereIn('track_id', $orphanTrackIds)->delete();
}

if ($orphanTeamIds !== []) {
    DB::table('competition_team_invites')->whereIn('team_id', $orphanTeamIds)->delete();
    DB::table('competition_team_members')->whereIn('team_id', $orphanTeamIds)->delete();
    DB::table('competition_teams')->whereIn('id', $orphanTeamIds)->delete();
}

if ($orphanTrackIds !== []) {
    DB::table('competition_tracks')->whereIn('id', $orphanTrackIds)->delete();
}

if ($orphanEditionIds !== []) {
    $seriesIds = DB::table('competition_editions')->whereIn('id', $orphanEditionIds)->pluck('series_id')->unique()->all();
    DB::table('competition_editions')->whereIn('id', $orphanEditionIds)->delete();
    DB::table('competition_series')->whereIn('id', $seriesIds)->delete();
}

// 没有届次的空系列（夹具中断）；只清 G3 测试系列，不碰其它系列。
DB::table('competition_series')
    ->where('name', 'like', 'G3 系列%')
    ->whereNotIn('id', DB::table('competition_editions')->pluck('series_id'))
    ->delete();

// ── 报名 ─────────────────────────────────────────────────────────────
if ($orphanEventIds !== []) {
    // 先断开 revision 指针，否则删 revision 会被 FK 拦住。
    DB::table('events')->whereIn('id', $orphanEventIds)->update([
        'published_revision_id' => null,
        'working_revision_id'   => null,
        'approved_revision_id'  => null,
    ]);

    $registrationIds = DB::table('event_registrations')->whereIn('event_id', $orphanEventIds)->pluck('id')->all();
    $attemptIds = DB::table('event_registration_attempts')->whereIn('event_id', $orphanEventIds)->pluck('id')->all();

    if ($attemptIds !== []) {
        DB::table('event_checkin_tokens')->whereIn('attempt_id', $attemptIds)->delete();
        DB::table('event_change_acknowledgments')->whereIn('attempt_id', $attemptIds)->delete();
    }

    if ($registrationIds !== []) {
        DB::table('ecosystem_outbox')->where('aggregate_type', 'registration')->whereIn('aggregate_id', $registrationIds)->delete();
    }

    DB::table('event_registration_requests')->where('target_type', 'event')->whereIn('target_id', $orphanEventIds)->delete();
    DB::table('event_registration_attempts')->whereIn('event_id', $orphanEventIds)->delete();
    DB::table('event_registrations')->whereIn('event_id', $orphanEventIds)->delete();
    DB::table('event_changes')->whereIn('event_id', $orphanEventIds)->delete();
    DB::table('event_registration_scopes')->whereIn('event_id', $orphanEventIds)->delete();
    DB::table('event_rule_versions')->whereIn('event_id', $orphanEventIds)->delete();
    DB::table('event_revisions')->whereIn('event_id', $orphanEventIds)->delete();
}

// 夹具活动本身：先断开 revision 指针，再删行。
if ($qaEventIds !== []) {
    DB::table('events')->whereIn('id', $qaEventIds)->update([
        'published_revision_id' => null,
        'working_revision_id'   => null,
        'approved_revision_id'  => null,
    ]);
    DB::table('events')->whereIn('id', $qaEventIds)->delete();
}

$liveEventIds = DB::table('events')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

// ── outbox 孤儿 ──────────────────────────────────────────────────────
$deleted['outbox'] = 0;
$deleted['outbox'] += DB::table('ecosystem_outbox')->where('aggregate_type', 'registration')
    ->whereNotIn('aggregate_id', DB::table('event_registrations')->pluck('id'))->delete();
$deleted['outbox'] += DB::table('ecosystem_outbox')->where('aggregate_type', 'competition_result')
    ->whereNotIn('aggregate_id', DB::table('competition_results')->pluck('id'))->delete();
$deleted['outbox'] += DB::table('ecosystem_outbox')->where('aggregate_type', 'event')
    ->whereNotIn('aggregate_id', DB::table('events')->pluck('id'))->delete();

// ── 关联孤儿 ─────────────────────────────────────────────────────────
$deleted['relations'] = 0;
$deleted['relations'] += DB::table('ecosystem_relations')->where('source_type', 'event')->whereNotIn('source_key', array_map('strval', $liveEventIds))->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('source_type', 'content')->whereNotIn('source_key', $contentSlugs)->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('target_type', 'event')->whereNotIn('target_key', array_map('strval', $liveEventIds))->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('target_type', 'content')->whereNotIn('target_key', $contentSlugs)->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('target_type', 'device_model')->whereNotIn('target_key', DB::table('device_model')->pluck('id')->map(static fn ($id): string => (string) $id)->all())->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('target_type', 'skill')->whereNotIn('target_key', DB::table('products')->pluck('id')->map(static fn ($id): string => (string) $id)->all())->delete();
$deleted['relations'] += DB::table('ecosystem_relations')->where('target_type', 'scene')->whereNotIn('target_key', DB::table('scenes')->pluck('id')->map(static fn ($id): string => (string) $id)->all())->delete();

// ── 带 QA-E2E 标记的内容 / 目标 ──────────────────────────────────────
$postIds = DB::table('content_posts')->whereIn(
    'id',
    DB::table('content_revisions')->where('title', 'like', 'QA-E2E-%')->pluck('post_id')
)->pluck('id')->all();

if ($postIds !== []) {
    $revisionIds = DB::table('content_revisions')->whereIn('post_id', $postIds)->pluck('id')->all();
    DB::table('content_posts')->whereIn('id', $postIds)->update([
        'published_revision_id' => null,
        'working_revision_id'   => null,
        'approved_revision_id'  => null,
    ]);

    if ($revisionIds !== []) {
        DB::table('content_sources')->whereIn('revision_id', $revisionIds)->delete();
        DB::table('content_revisions')->whereIn('id', $revisionIds)->delete();
    }

    DB::table('content_posts')->whereIn('id', $postIds)->delete();
}

$deleted['device_models'] = DB::table('device_model')->where('model_name', 'like', 'QA-E2E-%')->delete();
$deleted['scenes'] = DB::table('scenes')->where('name', 'like', 'QA-E2E-%')->delete();

$skillProductIds = DB::table('products')->where('sku', 'like', 'E2E-SKILL-%')->pluck('id')->all();

if ($skillProductIds !== []) {
    DB::table('skill_versions')->whereIn('product_id', $skillProductIds)->delete();
    DB::table('product_flat')->whereIn('product_id', $skillProductIds)->delete();
    $deleted['skills'] = DB::table('products')->whereIn('id', $skillProductIds)->delete();
} else {
    $deleted['skills'] = 0;
}

// ── QA 客户 / 通知 / 收藏 / 访问 ─────────────────────────────────────
$customerIds = DB::table('customers')->where('email', 'like', 'qa-e2e-%')->pluck('id')->all();

if ($customerIds !== []) {
    DB::table('notification')->where('recipient_type', 'customer')->whereIn('recipient_id', $customerIds)->delete();
    DB::table('ecosystem_favorites')->whereIn('customer_id', $customerIds)->delete();
    $deleted['customers'] = DB::table('customers')->whereIn('id', $customerIds)->delete();
} else {
    $deleted['customers'] = 0;
}

// G3 夹具在中断演练里留下的后台账号（tests 内的同名账号会随事务回滚）。
$g3AdminIds = DB::table('admins')->where('email', 'like', 'g3-%@example.test')->pluck('id')->all();

if ($g3AdminIds !== []) {
    $g3RoleIds = DB::table('admins')->whereIn('id', $g3AdminIds)->pluck('role_id')->unique()->all();
    $deleted['admins'] = DB::table('admins')->whereIn('id', $g3AdminIds)->delete();
    $deleted['roles'] = $g3RoleIds === [] ? 0 : DB::table('roles')->whereIn('id', $g3RoleIds)->delete();
} else {
    $deleted['admins'] = 0;
    $deleted['roles'] = 0;
}

$deleted['content_views'] = DB::table('ecosystem_content_view_daily')
    ->where('target_type', 'content')
    ->whereNotIn('target_key', $contentSlugs)
    ->delete();
$deleted['content_views'] += DB::table('ecosystem_content_view_daily')
    ->where('target_type', 'event')
    ->whereNotIn('target_key', array_map('strval', $liveEventIds))
    ->delete();

qa_json([
    'purged'             => true,
    'orphan_event_ids'   => $orphanEventIds,
    'orphan_tracks'      => count($orphanTrackIds),
    'orphan_entries'     => count($orphanEntryIds),
    'deleted'            => $deleted,
]);
