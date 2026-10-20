<?php

/**
 * Q-04 · 浏览器演练夹具（仅独立测试库）。
 *
 * 创建：
 *   · 一个已发布活动 + 个人报名 scope（容量 20，自动确认）；
 *   · 一个已确认规则的赛项（系列 / 届次 / track / 团队 scope）；
 *   · 三名客户：报名者、队长、队员（已知邮箱与密码）；
 *   · 两名后台账号：超管（all）、裁判（custom：只看赛务，无报名 PII/导出）。
 *
 * 输出 JSON 供 q04/run.mjs 驱动浏览器。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ecosystem\CompetitionRuleProfile;

$marker = 'QA-Q04-'.strtoupper(bin2hex(random_bytes(4)));
$now = qa_now();
$opens = now('UTC')->subDay()->format('Y-m-d H:i:s');
$closes = now('UTC')->addDays(2)->format('Y-m-d H:i:s');
$starts = now('UTC')->addDay()->format('Y-m-d H:i:s');
$ends = now('UTC')->addDays(2)->format('Y-m-d H:i:s');

// ── 活动 ─────────────────────────────────────────────────────────────
$eventId = (int) DB::table('events')->insertGetId([
    'name'               => $marker,
    'type'               => 'Q-04 browser drill',
    'status'             => 'registering',
    'kind'               => 'competition',
    'participation_mode' => 'offline',
    'publish_status'     => 'published',
    'disposition'        => 'normal',
    'hosting_status'     => 'scheduled',
    'timezone'           => 'Asia/Shanghai',
    'starts_at'          => $starts,
    'ends_at'            => $ends,
    'source_type'        => 'platform',
    'platform_role'      => 'organizer',
    'first_published_at' => $now,
    'published_at'       => $now,
    'row_version'        => 1,
    'created_at'         => $now,
    'updated_at'         => $now,
]);

$revisionId = (int) DB::table('event_revisions')->insertGetId([
    'event_id'                   => $eventId,
    'revision_no'                => 1,
    'name'                       => $marker,
    'summary'                    => 'Q-04 浏览器演练活动',
    'detail_html'                => '<p>Q-04 浏览器演练</p>',
    'city'                       => '上海',
    'location_text'              => '张江科学会堂',
    'participation_mode'         => 'offline',
    'timezone'                   => 'Asia/Shanghai',
    'starts_at'                  => $starts,
    'ends_at'                    => $ends,
    'source_type'                => 'platform',
    'platform_role'              => 'organizer',
    'organizer_name_snapshot'    => '召唤机甲（上海）科技有限公司',
    'source_name'                => '召唤机甲官方',
    'source_url'                 => 'https://test.invalid/'.$marker,
    'source_verified_at'         => $now,
    'authorization_basis'        => 'self_organized',
    'content_fingerprint'        => hash('sha256', $marker),
    'sanitizer_version'          => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
    'review_state'               => 'approved',
    'change_summary'             => 'Q-04 drill',
    'published_at'               => $now,
    'created_by'                 => 1,
    'created_at'                 => $now,
    'updated_at'                 => $now,
]);

DB::table('events')->where('id', $eventId)->update(['published_revision_id' => $revisionId]);

$personScopeId = (int) DB::table('event_registration_scopes')->insertGetId([
    'event_id'               => $eventId,
    'scope_key'              => 'default',
    'unit'                   => 'person',
    'capacity'               => 20,
    'capacity_confirmed_at'  => $now,
    'confirmed_count'        => 0,
    'review_mode'            => 'auto',
    'registration_opens_at'  => $opens,
    'registration_closes_at' => $closes,
    'review_closes_at'       => null,
    'cancel_policy'          => 'before_close',
    'reapply_policy'         => 'allowed',
    'max_attempts'           => 3,
    'change_ack_required'    => 0,
    'decline_releases_seat'  => 0,
    'no_response_action'     => 'none',
    'form_version'           => 1,
    'form_schema'            => json_encode(['fields' => ['name', 'phone', 'city', 'note'], 'required' => ['name', 'phone']]),
    'privacy_version'        => '2026-10-19',
    'privacy_summary'        => '报名信息仅用于本次活动联络与签到。',
    'rule_version_id'        => null,
    'status'                 => 'open',
    'policy_confirmed_at'    => $now,
    'policy_confirmed_by'    => 1,
    'row_version'            => 0,
    'created_at'             => $now,
    'updated_at'             => $now,
]);

// ── 赛务 ─────────────────────────────────────────────────────────────
$ruleVersionId = (int) DB::table('event_rule_versions')->insertGetId([
    'event_id'            => $eventId,
    'version_no'          => 1,
    'title'               => 'Q-04 测试赛规',
    'authority_language'  => 'zh',
    'body_html'           => '<p>规则正文</p>',
    'external_url'        => null,
    'file_media_id'       => null,
    'content_fingerprint' => str_repeat('d', 64),
    'change_summary'      => '初始版本',
    'effective_from'      => null,
    'status'              => 'approved',
    'reviewed_by'         => 1,
    'reviewed_at'         => $now,
    'created_by'          => 1,
    'created_at'          => $now,
    'updated_at'          => $now,
]);

$profile = [
    'owners' => [
        'rules'   => '赛事规则负责人',
        'scoring' => '评分负责人',
        'appeal'  => '裁决负责人',
        'safety'  => '安全负责人',
    ],
    'eligibility' => [
        'min_members'                => 2,
        'max_members'                => 4,
        'robot_declaration_required' => true,
        'robot_declaration_fields'   => ['model', 'asset_no'],
    ],
    'scoring' => ['policy' => 'manual'],
    'tie'     => ['policy' => 'manual'],
    'appeal'  => ['window_minutes' => 120, 'allowed_roles' => ['captain'], 'evidence_types' => ['pdf', 'image']],
    'safety'  => ['owner_role' => '现场安全负责人', 'plan_ref' => 'SAFETY-Q04', 'emergency_contact_ref' => 'EMERGENCY-Q04'],
];
$fingerprint = app(CompetitionRuleProfile::class)->fingerprint(
    $profile,
    EventRuleVersion::query()->findOrFail($ruleVersionId),
);

$seriesId = (int) DB::table('competition_series')->insertGetId([
    'organizer_id'   => null,
    'organizer_name' => '召唤机甲',
    'name'           => 'Q-04 系列',
    'name_key'       => strtolower($marker).' series',
    'slug'           => 'q04-series-'.strtolower(bin2hex(random_bytes(4))),
    'description'    => null,
    'status'         => 'active',
    'row_version'    => 0,
    'created_at'     => $now,
    'updated_at'     => $now,
]);

$editionId = (int) DB::table('competition_editions')->insertGetId([
    'series_id'   => $seriesId,
    'event_id'    => $eventId,
    'edition_no'  => 1,
    'name'        => '2026 届',
    'starts_at'   => null,
    'ends_at'     => null,
    'timezone'    => 'Asia/Shanghai',
    'status'      => 'published',
    'row_version' => 0,
    'created_at'  => $now,
    'updated_at'  => $now,
]);

$teamScopeId = (int) DB::table('event_registration_scopes')->insertGetId([
    'event_id'               => $eventId,
    'scope_key'              => 'robowar',
    'unit'                   => 'team',
    'capacity'               => 8,
    'capacity_confirmed_at'  => $now,
    'confirmed_count'        => 0,
    'review_mode'            => 'auto',
    'registration_opens_at'  => $opens,
    'registration_closes_at' => $closes,
    'review_closes_at'       => null,
    'cancel_policy'          => 'before_close',
    'reapply_policy'         => 'allowed',
    'max_attempts'           => 3,
    'change_ack_required'    => 0,
    'decline_releases_seat'  => 0,
    'no_response_action'     => 'none',
    'form_version'           => 1,
    'form_schema'            => json_encode(['fields' => ['name', 'phone', 'city', 'note'], 'required' => ['name', 'phone']]),
    'privacy_version'        => '2026-10-19',
    'privacy_summary'        => '队伍报名信息仅用于赛事联络与资格核验。',
    'rule_version_id'        => $ruleVersionId,
    'status'                 => 'open',
    'policy_confirmed_at'    => $now,
    'policy_confirmed_by'    => 1,
    'row_version'            => 0,
    'created_at'             => $now,
    'updated_at'             => $now,
]);

$trackId = (int) DB::table('competition_tracks')->insertGetId([
    'edition_id'            => $editionId,
    'track_key'             => 'robowar',
    'name'                  => '机甲对战',
    'discipline'            => '对抗',
    'summary'               => 'Q-04 测试赛项',
    'registration_scope_id' => $teamScopeId,
    'rules_version_id'      => $ruleVersionId,
    'status'                => 'active',
    'rules_profile'         => json_encode($profile, JSON_UNESCAPED_UNICODE),
    'rules_fingerprint'     => $fingerprint,
    'rules_confirmed_at'    => $now,
    'rules_confirmed_by'    => 1,
    'min_team_size'         => 2,
    'max_team_size'         => 4,
    'allow_multi_team'      => false,
    'submission_deadline_at'=> now('UTC')->addDays(5)->format('Y-m-d H:i:s'),
    'allow_correction'      => true,
    'row_version'           => 0,
    'created_at'            => $now,
    'updated_at'            => $now,
]);

// ── 客户 ─────────────────────────────────────────────────────────────
$makeCustomer = static function (string $label) use ($marker): array {
    $email = strtolower($marker).'-'.$label.'@example.test';

    $id = (int) DB::table('customers')->insertGetId([
        'first_name'  => 'Q04',
        'last_name'   => ucfirst($label),
        'email'       => $email,
        'phone'       => '136'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'status'      => 1,
        'is_verified' => 1,
        'password'    => bcrypt('secret123'),
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => 'secret123'];
};

$registrant = $makeCustomer('registrant');
$captain = $makeCustomer('captain');
$member = $makeCustomer('member');

$teamId = (int) DB::table('competition_teams')->insertGetId([
    'edition_id'          => $editionId,
    'track_id'            => $trackId,
    'captain_customer_id' => $captain['id'],
    'name'                => 'Q04 机甲队',
    'name_key'            => strtolower($marker).' team',
    'status'              => 'active',
    'row_version'         => 0,
    'created_at'          => $now,
    'updated_at'          => $now,
]);

foreach ([[$captain['id'], 'captain'], [$member['id'], 'member']] as [$customerId, $role]) {
    DB::table('competition_team_members')->insert([
        'team_id'                => $teamId,
        'customer_id'            => $customerId,
        'role'                   => $role,
        'status'                 => 'active',
        'invited_by_customer_id' => $captain['id'],
        'invited_at'             => now(),
        'responded_at'           => now(),
        'joined_at'              => now(),
        'left_at'                => null,
        'row_version'            => 0,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

// ── 后台账号 ─────────────────────────────────────────────────────────
$makeAdmin = static function (string $label, string $permissionType, array $permissions) use ($marker): array {
    $roleId = (int) DB::table('roles')->insertGetId([
        'name'            => $marker.' '.$label,
        'permission_type' => $permissionType,
        'permissions'     => json_encode($permissions),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $email = strtolower($marker).'-'.$label.'@example.test';

    DB::table('admins')->insert([
        'name'       => 'Q04 '.$label,
        'email'      => $email,
        'password'   => bcrypt('secret123'),
        'role_id'    => $roleId,
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['email' => $email, 'password' => 'secret123', 'role_id' => $roleId];
};

$superAdmin = $makeAdmin('super', 'all', []);
$refereeAdmin = $makeAdmin('referee', 'custom', [
    'ecosystem.competition.view',
    'ecosystem.competition.scoring',
    'ecosystem.competition.results',
]);

qa_json([
    'marker'         => $marker,
    'event_id'       => $eventId,
    'revision_id'    => $revisionId,
    'person_scope_id'=> $personScopeId,
    'team_scope_id'  => $teamScopeId,
    'track_id'       => $trackId,
    'edition_id'     => $editionId,
    'series_id'      => $seriesId,
    'rule_version_id'=> $ruleVersionId,
    'team_id'        => $teamId,
    'registrant'     => $registrant,
    'captain'        => $captain,
    'member'         => $member,
    'admin_super'    => $superAdmin,
    'admin_referee'  => $refereeAdmin,
]);
