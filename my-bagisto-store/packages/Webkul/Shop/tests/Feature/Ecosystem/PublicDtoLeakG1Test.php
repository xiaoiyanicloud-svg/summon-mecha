<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G1-10 · 公开 DTO 泄漏守卫（ADR-0001 G1-D08「公开 DTO 永不返回」）。
 *
 * 不只看字段名：同时检查内部证据引用、存储 key、原始文件名、旧正文与
 * 审核备注的真实值没有出现在响应里。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-10 DTO leak tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem.content.public_enabled'         => true,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);
});

/**
 * @return array<int, string>
 */
function g10CollectKeys(mixed $value): array
{
    $keys = [];

    if (! is_array($value)) {
        return $keys;
    }

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($child)) {
            $keys = array_merge($keys, g10CollectKeys($child));
        }
    }

    return array_values(array_unique($keys));
}

it('G1-10 · 活动 DTO 顶层字段精确等于冻结白名单', function () {
    $event = Fixtures::event();

    $data = getJson("/api/events/{$event['id']}")->assertOk()->json('data');

    $expected = [
        'id', 'kind', 'name', 'summary', 'organizer', 'source', 'participation_mode',
        'city', 'location_text', 'timezone', 'starts_at', 'ends_at', 'published_at',
        'first_published_at', 'revision_no', 'hosting_status', 'body_html',
        'registration', 'media', 'favorited', 'related',
    ];

    expect(array_keys($data))->toEqualCanonicalizing($expected);
    expect(array_keys($data['organizer']))->toEqualCanonicalizing(['name', 'platform_role']);
    expect(array_keys($data['source']))->toEqualCanonicalizing(['name', 'url']);
    expect(array_keys($data['registration']))->toEqualCanonicalizing([
        'can_register', 'registration_reason', 'allowed_actions',
        'form_version', 'privacy_version', 'privacy_summary', 'rules_version_id',
    ]);
});

it('G1-10 · 资讯 DTO 顶层字段精确等于冻结白名单', function () {
    $post = Fixtures::content();
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $data = getJson("/api/content/posts/{$slug}")->assertOk()->json('data');

    $expected = [
        'slug', 'type', 'title', 'summary', 'author', 'sources', 'published_at',
        'first_published_at', 'revision_no', 'correction_note', 'body_html', 'media',
        'favorited', 'related',
    ];

    expect(array_keys($data))->toEqualCanonicalizing($expected);
    expect(array_keys($data['author']))->toEqualCanonicalizing(['name', 'org']);
    expect(array_keys($data['sources'][0]))->toEqualCanonicalizing([
        'name', 'url', 'verified_at', 'rights_basis', 'rights_scope',
    ]);
});

it('G1-10 · 活动与资讯响应递归不含内部字段名', function () {
    $eventMedia = Fixtures::media('event-bytes', 'image/png');
    $event = Fixtures::event();
    Fixtures::mediaRef('event_revision', $event['revision_id'], $eventMedia['id']);

    $contentMedia = Fixtures::media('content-bytes', 'image/png');
    $post = Fixtures::content();
    Fixtures::mediaRef('content_revision', $post['revision_id'], $contentMedia['id']);

    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $keys = array_merge(
        g10CollectKeys(getJson("/api/events/{$event['id']}")->assertOk()->json('data')),
        g10CollectKeys(getJson("/api/content/posts/{$slug}")->assertOk()->json('data')),
    );

    foreach (Fixtures::forbiddenKeys() as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('G1-10 · 响应不包含内部证据 / 存储路径 / 审核备注 / 旧字段值', function () {
    $media = Fixtures::media('secret-bytes', 'image/png');
    $event = Fixtures::event([], [
        'detail_html' => '<p>公开正文</p><img data-media-id="'.$media['id'].'">',
    ]);
    Fixtures::mediaRef('event_revision', $event['revision_id'], $media['id']);

    $post = Fixtures::content();
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $raw = (string) getJson("/api/events/{$event['id']}")->assertOk()->getContent()
        .(string) getJson("/api/content/posts/{$slug}")->assertOk()->getContent();

    foreach ([
        'PRIVATE-AUTH-EVIDENCE-REF',
        'PRIVATE-CONTENT-EVIDENCE-REF',
        'private-original.png',
        $media['storage_key'],
        '内部审核备注不得公开',
        '内部内容审核备注不得公开',
        '旧正文-不得公开',
        '旧活动名称',
        '旧日期文本',
        '旧主办文本',
    ] as $secret) {
        expect($raw)->not->toContain($secret);
    }

    // 媒体 URL 不得携带 revision / 磁盘信息。
    $eventBody = (string) getJson("/api/events/{$event['id']}")->json('data.body_html');
    expect($eventBody)->not->toContain('revision');
    expect($eventBody)->not->toContain('storage');
});

it('G1-10 · external_registration_url 仅在合法 http/https 时输出', function () {
    $valid = Fixtures::event([], [
        'external_registration_url' => 'https://organizer.example/signup',
    ]);

    $data = getJson("/api/events/{$valid['id']}")->assertOk()->json('data');

    expect($data)->toHaveKey('external_registration_url');
    expect($data['external_registration_url'])->toBe('https://organizer.example/signup');

    foreach ([
        'javascript:alert(1)',
        'ftp://organizer.example/signup',
        '/relative/signup',
        'https://',
        'https://organizer.example/a b',
    ] as $invalid) {
        $event = Fixtures::event([], ['external_registration_url' => $invalid]);
        $payload = getJson("/api/events/{$event['id']}")->assertOk()->json('data');

        expect($payload)->not->toHaveKey('external_registration_url');
    }

    // 为空时不输出；不会因为字段存在就伪造一个空串。
    $empty = Fixtures::event([], ['external_registration_url' => null]);
    expect(getJson("/api/events/{$empty['id']}")->assertOk()->json('data'))
        ->not->toHaveKey('external_registration_url');
});
