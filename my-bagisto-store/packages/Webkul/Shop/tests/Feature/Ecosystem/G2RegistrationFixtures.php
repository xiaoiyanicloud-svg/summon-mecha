<?php

namespace Webkul\Shop\Tests\Feature\Ecosystem;

use Illuminate\Support\Facades\DB;

/**
 * G2 报名测试夹具。
 *
 * 事件本身复用 G1 的已发布夹具；scope 直接写表以精确构造 draft/open 状态。
 * 所有写入在 DatabaseTransactions 内回滚。
 */
final class G2RegistrationFixtures
{
    /**
     * @param  array<string, mixed>  $event
     * @return array{id: int, revision_id: int}
     */
    public static function event(array $event = []): array
    {
        return G1PublicApiFixtures::event($event);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function scope(int $eventId, array $overrides = []): int
    {
        $now = now('UTC');

        return (int) DB::table('event_registration_scopes')->insertGetId(array_merge([
            'event_id'               => $eventId,
            'scope_key'              => 'default',
            'unit'                   => 'person',
            'capacity'               => 10,
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
            'status'                 => 'draft',
            'policy_confirmed_at'    => null,
            'policy_confirmed_by'    => null,
            'row_version'            => 0,
            'created_at'             => $now->format('Y-m-d H:i:s'),
            'updated_at'             => $now->format('Y-m-d H:i:s'),
        ], $overrides));
    }
}
