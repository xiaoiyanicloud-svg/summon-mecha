<?php

namespace Webkul\Shop\Tests\Feature\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ecosystem\CompetitionRuleProfile;

/**
 * G3 赛务测试夹具。
 *
 * 事件复用 G1 已发布夹具；系列/届次/赛项/scope 直接写表，
 * 以精确构造 draft/active、规则已确认/未确认等状态。
 * 所有写入在 DatabaseTransactions 内回滚。
 */
final class G3CompetitionFixtures
{
    /**
     * @param  array<string, mixed>  $event
     * @return array{id: int, revision_id: int}
     */
    public static function event(array $event = []): array
    {
        return G1PublicApiFixtures::event(array_merge([
            'kind'               => 'competition',
            'participation_mode' => 'offline',
            'publish_status'     => 'published',
            'disposition'        => 'normal',
        ], $event));
    }

    public static function adminId(): int
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'name'            => 'G3 超管 '.bin2hex(random_bytes(3)),
            'permission_type' => 'all',
            'permissions'     => json_encode([]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return (int) DB::table('admins')->insertGetId([
            'name'       => 'G3 管理员',
            'email'      => 'g3-'.bin2hex(random_bytes(5)).'@example.test',
            'password'   => bcrypt('secret123'),
            'role_id'    => $roleId,
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function customer(string $name = '选手'): int
    {
        $suffix = bin2hex(random_bytes(5));

        return (int) DB::table('customers')->insertGetId([
            'first_name' => $name,
            'last_name'  => $suffix,
            'email'      => 'g3-'.$suffix.'@example.test',
            'phone'      => '13'.random_int(100000000, 999999999),
            'status'     => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 创建已批准规则版本。
     */
    public static function ruleVersion(int $eventId, int $adminId, array $overrides = []): EventRuleVersion
    {
        $id = (int) DB::table('event_rule_versions')->insertGetId(array_merge([
            'event_id'             => $eventId,
            'version_no'           => 1,
            'title'                => 'G3 测试赛规',
            'authority_language'   => 'zh',
            'body_html'            => '<p>规则正文</p>',
            'external_url'         => null,
            'file_media_id'        => null,
            'content_fingerprint'  => str_repeat('c', 64),
            'change_summary'       => '初始版本',
            'effective_from'       => null,
            'status'               => 'approved',
            'reviewed_by'          => $adminId,
            'reviewed_at'          => now(),
            'created_by'           => $adminId,
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));

        return EventRuleVersion::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesProfile(array $overrides = []): array
    {
        return array_replace_recursive([
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
            'appeal'  => [
                'window_minutes' => 120,
                'allowed_roles'  => ['captain'],
                'evidence_types' => ['pdf', 'image'],
            ],
            'safety' => [
                'owner_role'             => '现场安全负责人',
                'plan_ref'               => 'SAFETY-PLAN-001',
                'emergency_contact_ref'  => 'EMERGENCY-REF-001',
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function series(array $overrides = []): array
    {
        $suffix = bin2hex(random_bytes(4));

        $id = (int) DB::table('competition_series')->insertGetId(array_merge([
            'organizer_id'  => null,
            'organizer_name'=> '召唤机甲',
            'name'          => 'G3 系列 '.$suffix,
            'name_key'      => 'g3 series '.$suffix,
            'slug'          => 'g3-series-'.$suffix,
            'description'   => null,
            'status'        => 'active',
            'row_version'   => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));

        return ['id' => $id];
    }

    public static function edition(int $seriesId, int $eventId, array $overrides = []): int
    {
        return (int) DB::table('competition_editions')->insertGetId(array_merge([
            'series_id'   => $seriesId,
            'event_id'    => $eventId,
            'edition_no'  => 1,
            'name'        => '2026 届',
            'starts_at'   => null,
            'ends_at'     => null,
            'timezone'    => 'Asia/Shanghai',
            'status'      => 'published',
            'row_version' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $overrides));
    }

    public static function scope(int $eventId, string $scopeKey, array $overrides = []): int
    {
        $now = now('UTC');

        return (int) DB::table('event_registration_scopes')->insertGetId(array_merge([
            'event_id'               => $eventId,
            'scope_key'              => $scopeKey,
            'unit'                   => 'team',
            'capacity'               => 8,
            'capacity_confirmed_at'  => $now->format('Y-m-d H:i:s'),
            'confirmed_count'        => 0,
            'review_mode'            => 'auto',
            'registration_opens_at'  => $now->copy()->subHour()->format('Y-m-d H:i:s'),
            'registration_closes_at' => $now->copy()->addDay()->format('Y-m-d H:i:s'),
            'review_closes_at'       => null,
            'cancel_policy'          => 'before_close',
            'reapply_policy'         => 'allowed',
            'max_attempts'           => 3,
            'change_ack_required'    => false,
            'decline_releases_seat'  => false,
            'no_response_action'     => 'none',
            'form_version'           => 1,
            'form_schema'            => json_encode([
                'fields'   => ['name', 'phone', 'city', 'note'],
                'required' => ['name', 'phone'],
            ]),
            'privacy_version'        => '2026-10-19',
            'privacy_summary'        => '测试同意摘要',
            'rule_version_id'        => null,
            'status'                 => 'open',
            'policy_confirmed_at'    => $now->format('Y-m-d H:i:s'),
            'policy_confirmed_by'    => 1,
            'row_version'            => 0,
            'created_at'             => $now->format('Y-m-d H:i:s'),
            'updated_at'             => $now->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * 直接写入一个已确认规则的赛项（active）。
     */
    public static function track(
        int $editionId,
        int $scopeId,
        EventRuleVersion $ruleVersion,
        array $overrides = [],
        ?array $rulesProfile = null,
    ): int {
        $profile = $rulesProfile ?? self::rulesProfile();
        $fingerprint = app(CompetitionRuleProfile::class)->fingerprint($profile, $ruleVersion);

        // 赛项与 scope 必须绑定同一规则版本（团队报名会校验）。
        DB::table('event_registration_scopes')
            ->where('id', $scopeId)
            ->update(['rule_version_id' => (int) $ruleVersion->getKey()]);

        return (int) DB::table('competition_tracks')->insertGetId(array_merge([
            'edition_id'              => $editionId,
            'track_key'               => 'robowar',
            'name'                    => '机甲对战',
            'discipline'              => '对抗',
            'summary'                 => '测试赛项',
            'registration_scope_id'   => $scopeId,
            'rules_version_id'        => (int) $ruleVersion->getKey(),
            'status'                  => 'active',
            'rules_profile'           => json_encode($profile, JSON_UNESCAPED_UNICODE),
            'rules_fingerprint'       => $fingerprint,
            'rules_confirmed_at'      => now('UTC')->format('Y-m-d H:i:s'),
            'rules_confirmed_by'      => 1,
            'min_team_size'           => 2,
            'max_team_size'           => 4,
            'allow_multi_team'        => false,
            'submission_deadline_at'  => now('UTC')->addDays(5)->format('Y-m-d H:i:s'),
            'allow_correction'        => true,
            'row_version'             => 0,
            'created_at'              => now(),
            'updated_at'              => now(),
        ], $overrides));
    }

    public static function team(int $editionId, int $trackId, int $captainId, array $overrides = []): int
    {
        $suffix = bin2hex(random_bytes(3));

        $teamId = (int) DB::table('competition_teams')->insertGetId(array_merge([
            'edition_id'          => $editionId,
            'track_id'            => $trackId,
            'captain_customer_id' => $captainId,
            'name'                => '机甲队 '.$suffix,
            'name_key'            => 'team '.$suffix,
            'status'              => 'active',
            'row_version'         => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));

        DB::table('competition_team_members')->insert([
            'team_id'                => $teamId,
            'customer_id'            => $captainId,
            'role'                   => 'captain',
            'status'                 => 'active',
            'invited_by_customer_id' => $captainId,
            'invited_at'             => now(),
            'responded_at'           => now(),
            'joined_at'              => now(),
            'left_at'                => null,
            'row_version'            => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return $teamId;
    }

    public static function member(int $teamId, int $customerId, string $status = 'active', string $role = 'member'): int
    {
        return (int) DB::table('competition_team_members')->insertGetId([
            'team_id'                => $teamId,
            'customer_id'            => $customerId,
            'role'                   => $role,
            'status'                 => $status,
            'invited_by_customer_id' => null,
            'invited_at'             => now(),
            'responded_at'           => now(),
            'joined_at'              => $status === 'active' ? now() : null,
            'left_at'                => null,
            'row_version'            => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * 创建一条已确认 entry（用于作品/赛程测试）。
     */
    public static function entry(int $editionId, int $trackId, int $teamId, int $captainId, array $overrides = []): int
    {
        return (int) DB::table('competition_entries')->insertGetId(array_merge([
            'entry_no'       => 'EN'.strtoupper(bin2hex(random_bytes(6))),
            'edition_id'     => $editionId,
            'track_id'       => $trackId,
            'team_id'        => $teamId,
            'registration_id'=> null,
            'attempt_id'     => null,
            'captain_customer_id' => $captainId,
            'status'         => 'confirmed',
            'roster_snapshot'=> json_encode([
                ['customer_id' => $captainId, 'role' => 'captain', 'joined_at' => now()->toIso8601String()],
            ]),
            'eligibility_snapshot' => json_encode([
                'team_name'    => '测试队伍',
                'member_count' => 1,
                'min_members'  => 1,
                'max_members'  => 2,
            ]),
            'robot_declaration' => json_encode(['model' => 'M1', 'asset_no' => 'A1']),
            'rules_version_id' => null,
            'rules_fingerprint' => null,
            'frozen_at'      => now('UTC')->format('Y-m-d H:i:s'),
            'frozen_by_customer_id' => $captainId,
            'row_version'    => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $overrides));
    }

    /**
     * 生成一个可选上传文件（用于 CSV/附件测试）。
     */
    public static function uploadedCsv(string $contents, string $name = 'schedule.csv'): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'g3csv_');
        file_put_contents($path, $contents);

        return new \Illuminate\Http\UploadedFile($path, $name, 'text/csv', null, true);
    }
}
