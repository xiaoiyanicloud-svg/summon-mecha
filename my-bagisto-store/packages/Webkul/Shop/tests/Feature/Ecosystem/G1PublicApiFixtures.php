<?php

namespace Webkul\Shop\Tests\Feature\Ecosystem;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * G1-10 公开 API 测试夹具。
 *
 * 直接写表而不走服务层，是为了精确构造“已发布 / 候选 / 暂缓 / 撤稿 /
 * 过期净化版本 / 非当前 revision”等状态；模型守卫会阻止这些状态组合。
 *
 * 所有写入都在 ShopTestCase 的 DatabaseTransactions 内回滚。
 */
final class G1PublicApiFixtures
{
    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $revision
     * @return array{id: int, revision_id: int}
     */
    public static function event(array $event = [], array $revision = []): array
    {
        $now = '2026-10-19 04:00:00';

        $eventId = (int) DB::table('events')->insertGetId(array_merge([
            'name'               => '旧活动名称',
            'type'               => '旧类型',
            'status'             => 'ended',
            'date'               => '旧日期文本',
            'city'               => '旧城市',
            'location'           => '旧地点',
            'organizer'          => '旧主办文本',
            'hot'                => 999,
            'teams'              => 42,
            'detail'             => '<p>旧正文-不得公开</p>',
            'kind'               => 'salon',
            'participation_mode' => 'offline',
            'publish_status'     => 'published',
            'disposition'        => 'normal',
            'hosting_status'     => 'scheduled',
            'timezone'           => 'Asia/Shanghai',
            'starts_at'          => '2026-11-01 01:00:00',
            'ends_at'            => '2026-11-01 10:00:00',
            'source_type'        => 'platform',
            'platform_role'      => 'organizer',
            'first_published_at' => $now,
            'published_at'       => $now,
            'row_version'        => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], $event));

        $revisionId = (int) DB::table('event_revisions')->insertGetId(array_merge([
            'event_id'                      => $eventId,
            'revision_no'                   => 1,
            'name'                          => '公开活动名称',
            'summary'                       => '公开活动摘要',
            'detail_html'                   => '<p>公开正文</p>',
            'city'                          => '上海',
            'location_text'                 => '张江科学会堂',
            'participation_mode'            => 'offline',
            'timezone'                      => 'Asia/Shanghai',
            'starts_at'                     => '2026-11-01 01:00:00',
            'ends_at'                       => '2026-11-01 10:00:00',
            'source_type'                   => 'platform',
            'platform_role'                 => 'organizer',
            'organizer_name_snapshot'       => '召唤机甲',
            'source_name'                   => '召唤机甲官方',
            'source_url'                    => 'https://example.com/events/1',
            'source_verified_at'            => '2026-10-18 04:00:00',
            'authorization_basis'           => 'self_organized',
            'authorization_evidence_ref'    => 'PRIVATE-AUTH-EVIDENCE-REF',
            'external_registration_url'     => null,
            'content_fingerprint'           => str_repeat('a', 64),
            'media_manifest_hash'           => null,
            'sanitizer_version'             => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
            'review_state'                  => 'approved',
            'review_note'                   => '内部审核备注不得公开',
            'reviewed_by'                   => 77,
            'reviewed_at'                   => '2026-10-18 05:00:00',
            'change_summary'                => '初始版本',
            'published_at'                  => '2026-10-19 04:00:00',
            'created_by'                    => 1,
            'created_at'                    => $now,
            'updated_at'                    => $now,
        ], $revision));

        DB::table('events')->where('id', $eventId)->update([
            'published_revision_id' => $revisionId,
        ]);

        return ['id' => $eventId, 'revision_id' => $revisionId];
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $revision
     * @param  array<string, mixed>  $source
     * @return array{id: int, revision_id: int}
     */
    public static function content(array $post = [], array $revision = [], array $source = []): array
    {
        $now = '2026-10-19 04:00:00';

        $postId = (int) DB::table('content_posts')->insertGetId(array_merge([
            'type'               => 'news',
            'slug'               => 'g1-10-content-'.bin2hex(random_bytes(4)),
            'category'           => 'robotics',
            'publish_status'     => 'published',
            'disposition'        => 'normal',
            'first_published_at' => $now,
            'published_at'       => $now,
            'row_version'        => 1,
            'created_by'         => 1,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], $post));

        $revisionId = (int) DB::table('content_revisions')->insertGetId(array_merge([
            'post_id'             => $postId,
            'revision_no'         => 1,
            'title'               => '公开文章标题',
            'summary'             => '公开文章摘要',
            'body_html'           => '<p>公开文章正文</p>',
            'author_name'         => '张三',
            'author_org'          => '召唤机甲编辑部',
            'source_type'         => 'original',
            'original_published_at' => null,
            'correction_note'     => null,
            'change_summary'      => '初始版本',
            'content_fingerprint' => str_repeat('b', 64),
            'media_manifest_hash' => null,
            'sanitizer_version'   => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
            'review_state'        => 'approved',
            'review_note'         => '内部内容审核备注不得公开',
            'reviewed_by'         => 88,
            'reviewed_at'         => '2026-10-18 05:00:00',
            'published_at'        => '2026-10-19 04:00:00',
            'created_by'          => 1,
            'created_at'          => $now,
            'updated_at'          => $now,
        ], $revision));

        DB::table('content_sources')->insert(array_merge([
            'revision_id'        => $revisionId,
            'source_name'        => '官方原文',
            'source_url'         => 'https://example.com/news/1',
            'source_verified_at' => '2026-10-18 04:00:00',
            'rights_basis'       => 'platform_original',
            'rights_scope'       => '全文',
            'evidence_ref'       => 'PRIVATE-CONTENT-EVIDENCE-REF',
            'sort_order'         => 0,
            'created_at'         => $now,
        ], $source));

        DB::table('content_posts')->where('id', $postId)->update([
            'published_revision_id' => $revisionId,
        ]);

        return ['id' => $postId, 'revision_id' => $revisionId];
    }

    /**
     * 写入一份真实存在于 fake 私有盘上的媒体。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{id: int, storage_key: string, contents: string}
     */
    public static function media(string $contents = 'image-bytes', string $mime = 'image/png', array $overrides = []): array
    {
        $storageKey = 'g1-10/'.bin2hex(random_bytes(8)).'.bin';

        Storage::disk('local')->put($storageKey, $contents);

        $id = (int) DB::table('ecosystem_media')->insertGetId(array_merge([
            'disk'          => 'local',
            'storage_key'   => $storageKey,
            'original_name' => 'private-original.png',
            'mime'          => $mime,
            'byte_size'     => strlen($contents),
            'width'         => str_starts_with($mime, 'image/') ? 100 : null,
            'height'        => str_starts_with($mime, 'image/') ? 100 : null,
            'sha256'        => hash('sha256', $contents.uniqid('', true)),
            'status'        => 'approved',
            'uploaded_by'   => 1,
            'created_at'    => '2026-10-19 04:00:00',
            'updated_at'    => '2026-10-19 04:00:00',
        ], $overrides));

        return ['id' => $id, 'storage_key' => $storageKey, 'contents' => $contents];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function mediaRef(
        string $ownerType,
        int $ownerId,
        int $mediaId,
        string $role = 'cover',
        array $overrides = [],
    ): int {
        return (int) DB::table('ecosystem_media_refs')->insertGetId(array_merge([
            'media_id'      => $mediaId,
            'owner_type'    => $ownerType,
            'owner_id'      => $ownerId,
            'role'          => $role,
            'sort_order'    => 0,
            'alt_text'      => '公开替代文本',
            'review_status' => 'approved',
            'created_at'    => '2026-10-19 04:00:00',
        ], $overrides));
    }

    /**
     * @return array<int, string>
     */
    public static function forbiddenKeys(): array
    {
        return [
            'hot',
            'teams',
            'date',
            'status',
            'detail',
            'working_revision_id',
            'approved_revision_id',
            'review_note',
            'reviewed_by',
            'evidence_ref',
            'authorization_evidence_ref',
            'storage_key',
            'disk',
            'contacts',
            'contact',
            'phone',
        ];
    }
}
