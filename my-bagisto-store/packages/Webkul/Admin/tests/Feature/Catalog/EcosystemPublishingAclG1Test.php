<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\Event;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * G1 Admin 发布工作台 · 逐路由授权。
 *
 * 覆盖：
 *   · `config/ecosystem_admin.php` 必须覆盖全部 `admin.catalog.ecosystem.*` 路由；
 *   · 每个权限 key 都必须在 `config/acl.php` 登记（角色分配树可见）；
 *   · custom 角色（有无关权限、无 ecosystem.*）直接 URL / Ajax 一律 403，
 *     且不产生任何副作用；
 *   · 超级管理员可访问全部页面，动作请求不会被授权层拦截；
 *   · 未登录重定向到后台登录。
 *
 * 运行必须指向独立测试库（APP_ENV=testing，库名以 _test/_testing 结尾）。
 */

beforeEach(function () {
    expect(app()->environment())->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toMatch('/(?:_test|_testing)$/')
        ->not->toBe('root');
});

function ecoAclAdmin(array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function ecoAclSuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

/**
 * @return array{event: int, event_revision: int, post: int, content_revision: int, organizer: int, media_approved: int, media_pending: int, event_ref: int, content_ref: int}
 */
function ecoAclFixtures(): array
{
    $now = '2026-10-19 04:00:00';

    // 媒体预览流要求私有盘文件真实存在；授权用例只验证 ACL，
    // 用 fake 盘放一份占位内容即可（不涉及真实文件）。
    Storage::fake('local');

    $organizerId = (int) DB::table('ecosystem_organizers')->insertGetId([
        'name'                 => '授权测试主办方',
        'platform_role'        => 'organizer',
        'source_url'           => 'https://organizer.example/about',
        'public_materials'     => '公开资料',
        'private_evidence_ref' => 'PRIVATE-ORG-EVIDENCE-REF',
        'status'               => 'verified',
        'verified_by'          => 1,
        'verified_at'          => $now,
        'created_at'           => $now,
        'updated_at'           => $now,
    ]);

    $eventId = (int) DB::table('events')->insertGetId([
        'name'               => 'G1 授权测试活动',
        'type'               => '旧类型',
        'status'             => 'registering',
        'city'               => '上海',
        'hot'                => 0,
        'teams'              => 0,
        'kind'               => 'salon',
        'participation_mode' => 'offline',
        'publish_status'     => 'draft',
        'disposition'        => 'normal',
        'hosting_status'     => 'scheduled',
        'timezone'           => 'Asia/Shanghai',
        'source_type'        => 'platform',
        'platform_role'      => 'organizer',
        'organizer_id'       => $organizerId,
        'row_version'        => 0,
        'created_at'         => $now,
        'updated_at'         => $now,
    ]);

    $eventRevisionId = (int) DB::table('event_revisions')->insertGetId([
        'event_id'                      => $eventId,
        'revision_no'                   => 1,
        'name'                          => 'G1 授权测试活动',
        'summary'                       => '摘要',
        'detail_html'                   => '<p>正文</p>',
        'city'                          => '上海',
        'location_text'                 => '张江',
        'participation_mode'            => 'offline',
        'timezone'                      => 'Asia/Shanghai',
        'source_type'                   => 'platform',
        'platform_role'                 => 'organizer',
        'organizer_id'                  => $organizerId,
        'organizer_name_snapshot'       => '授权测试主办方',
        'source_name'                   => '官方来源',
        'source_url'                    => 'https://example.com/events/1',
        'source_verified_at'            => '2026-10-18 04:00:00',
        'authorization_basis'           => 'self_organized',
        'authorization_evidence_ref'    => 'PRIVATE-AUTH-EVIDENCE-REF',
        'external_registration_url'     => null,
        'content_fingerprint'           => str_repeat('a', 64),
        'media_manifest_hash'           => null,
        'sanitizer_version'             => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
        'review_state'                  => 'draft',
        'change_summary'                => '初始版本',
        'created_by'                    => 1,
        'created_at'                    => $now,
        'updated_at'                    => $now,
    ]);

    DB::table('events')->where('id', $eventId)->update(['working_revision_id' => $eventRevisionId]);

    $postId = (int) DB::table('content_posts')->insertGetId([
        'type'           => 'news',
        'slug'           => 'g1-admin-acl-post',
        'category'       => 'robotics',
        'publish_status' => 'draft',
        'disposition'    => 'normal',
        'row_version'    => 0,
        'created_by'     => 1,
        'created_at'     => $now,
        'updated_at'     => $now,
    ]);

    $contentRevisionId = (int) DB::table('content_revisions')->insertGetId([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => '授权测试文章',
        'summary'             => '摘要',
        'body_html'           => '<p>正文</p>',
        'author_name'         => '作者',
        'author_org'          => '机构',
        'source_type'         => 'original',
        'change_summary'      => '初始版本',
        'content_fingerprint' => str_repeat('b', 64),
        'media_manifest_hash' => null,
        'sanitizer_version'   => (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1'),
        'review_state'        => 'draft',
        'created_by'          => 1,
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);

    DB::table('content_sources')->insert([
        'revision_id'        => $contentRevisionId,
        'source_name'        => '官方原文',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => '2026-10-18 04:00:00',
        'rights_basis'       => 'platform_original',
        'rights_scope'       => '全文',
        'evidence_ref'       => 'PRIVATE-CONTENT-EVIDENCE-REF',
        'sort_order'         => 0,
        'created_at'         => $now,
    ]);

    DB::table('content_posts')->where('id', $postId)->update(['working_revision_id' => $contentRevisionId]);

    $mediaApproved = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => 'ecosystem/media/aa/'.str_repeat('a', 64).'.png',
        'original_name' => 'approved.png',
        'mime'          => 'image/png',
        'byte_size'     => 128,
        'width'         => 16,
        'height'        => 16,
        'sha256'        => str_repeat('a', 64),
        'status'        => 'approved',
        'uploaded_by'   => 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    $mediaPending = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => 'ecosystem/media/bb/'.str_repeat('b', 64).'.png',
        'original_name' => 'pending.png',
        'mime'          => 'image/png',
        'byte_size'     => 64,
        'width'         => 8,
        'height'        => 8,
        'sha256'        => str_repeat('b', 64),
        'status'        => 'pending',
        'uploaded_by'   => 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    Storage::disk('local')->put('ecosystem/media/aa/'.str_repeat('a', 64).'.png', 'approved-media-bytes');

    $eventRef = (int) DB::table('ecosystem_media_refs')->insertGetId([
        'media_id'      => $mediaApproved,
        'owner_type'    => 'event_revision',
        'owner_id'      => $eventRevisionId,
        'role'          => 'cover',
        'sort_order'    => 0,
        'alt_text'      => '活动封面',
        'review_status' => 'pending',
        'created_at'    => $now,
    ]);

    $contentRef = (int) DB::table('ecosystem_media_refs')->insertGetId([
        'media_id'      => $mediaApproved,
        'owner_type'    => 'content_revision',
        'owner_id'      => $contentRevisionId,
        'role'          => 'inline',
        'sort_order'    => 0,
        'alt_text'      => '文章配图',
        'review_status' => 'pending',
        'created_at'    => $now,
    ]);

    return [
        'event'            => $eventId,
        'event_revision'   => $eventRevisionId,
        'post'             => $postId,
        'content_revision' => $contentRevisionId,
        'organizer'        => $organizerId,
        'media_approved'   => $mediaApproved,
        'media_pending'    => $mediaPending,
        'event_ref'        => $eventRef,
        'content_ref'      => $contentRef,
    ];
}

/**
 * @param  array<string, int>  $ids
 * @return array<string, array<string, int>>
 */
function ecoAclRouteParams(array $ids): array
{
    return [
        'admin.catalog.ecosystem.index'                     => [],
        'admin.catalog.ecosystem.events.create'             => [],
        'admin.catalog.ecosystem.events.store'              => [],
        'admin.catalog.ecosystem.events.show'               => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.metadata'           => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.revisions.store'    => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.revisions.preview'  => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.update'   => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.media_refs.store' => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.media_refs.review' => ['id' => $ids['event'], 'revision' => $ids['event_revision'], 'ref' => $ids['event_ref']],
        'admin.catalog.ecosystem.events.revisions.submit'   => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.approve'  => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.reject'   => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.schedule' => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.revisions.publish'  => ['id' => $ids['event'], 'revision' => $ids['event_revision']],
        'admin.catalog.ecosystem.events.unschedule'         => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.withhold'           => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.restore'            => ['id' => $ids['event']],
        'admin.catalog.ecosystem.events.retract'            => ['id' => $ids['event']],

        'admin.catalog.ecosystem.content.index'             => [],
        'admin.catalog.ecosystem.content.store'             => [],
        'admin.catalog.ecosystem.content.show'              => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.metadata'          => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.revisions.store'   => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.revisions.preview' => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.update'  => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.media_refs.store' => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.media_refs.review' => ['id' => $ids['post'], 'revision' => $ids['content_revision'], 'ref' => $ids['content_ref']],
        'admin.catalog.ecosystem.content.revisions.submit'  => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.approve' => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.reject'  => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.schedule' => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.revisions.publish' => ['id' => $ids['post'], 'revision' => $ids['content_revision']],
        'admin.catalog.ecosystem.content.unschedule'        => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.withhold'          => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.restore'           => ['id' => $ids['post']],
        'admin.catalog.ecosystem.content.retract'           => ['id' => $ids['post']],

        'admin.catalog.ecosystem.organizers.index'          => [],
        'admin.catalog.ecosystem.organizers.store'          => [],
        'admin.catalog.ecosystem.organizers.verify'         => ['id' => $ids['organizer']],
        'admin.catalog.ecosystem.organizers.reject'         => ['id' => $ids['organizer']],
        'admin.catalog.ecosystem.organizers.suspend'        => ['id' => $ids['organizer']],

        'admin.catalog.ecosystem.media.index'               => [],
        'admin.catalog.ecosystem.media.preview'             => ['id' => $ids['media_approved']],
        'admin.catalog.ecosystem.media.store'               => [],
        'admin.catalog.ecosystem.media.approve'             => ['id' => $ids['media_pending']],
        'admin.catalog.ecosystem.media.reject'              => ['id' => $ids['media_pending']],
        'admin.catalog.ecosystem.media.quarantine'          => ['id' => $ids['media_approved']],
    ];
}

/**
 * @return array<int, Illuminate\Routing\Route>
 */
function ecoPublishingRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.catalog.ecosystem.'))
        ->values()
        ->all();
}

it('每一条生态发布路由都登记了权限，且权限 key 已进入 ACL', function () {
    $routes = collect(ecoPublishingRoutes())->map(fn ($route): string => (string) $route->getName());
    $map = (array) config('ecosystem_admin.route_permissions');

    expect($routes)->not->toBeEmpty();
    expect(array_values(array_diff($routes->all(), array_keys($map))))->toBe([]);
    expect(array_values(array_diff(array_keys($map), $routes->all())))->toBe([]);

    $aclKeys = collect(config('acl'))->pluck('key')->all();

    foreach (array_unique(array_values($map)) as $permission) {
        expect($aclKeys)->toContain($permission);
    }
});

it('custom 角色无 ecosystem 权限时：所有直接 URL / Ajax 一律 403 且无副作用', function () {
    $ids = ecoAclFixtures();
    $params = ecoAclRouteParams($ids);

    $this->actingAs(ecoAclAdmin(['catalog']), 'admin');

    $before = [
        'event'      => (array) DB::table('events')->where('id', $ids['event'])->first(),
        'revision'   => (array) DB::table('event_revisions')->where('id', $ids['event_revision'])->first(),
        'post'       => (array) DB::table('content_posts')->where('id', $ids['post'])->first(),
        'organizer'  => (array) DB::table('ecosystem_organizers')->where('id', $ids['organizer'])->first(),
    ];

    foreach (ecoPublishingRoutes() as $route) {
        $name = (string) $route->getName();
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        expect($params)->toHaveKey($name);

        foreach ($methods as $method) {
            $response = $this->call($method, route($name, $params[$name]));

            expect($response->getStatusCode())
                ->toBe(403, sprintf('%s %s 未返回 403', $method, $name));
        }
    }

    expect((array) DB::table('events')->where('id', $ids['event'])->first())->toBe($before['event']);
    expect((array) DB::table('event_revisions')->where('id', $ids['event_revision'])->first())->toBe($before['revision']);
    expect((array) DB::table('content_posts')->where('id', $ids['post'])->first())->toBe($before['post']);
    expect((array) DB::table('ecosystem_organizers')->where('id', $ids['organizer'])->first())->toBe($before['organizer']);
});

it('中间件对 ecosystem.* 路由先做 403 防线，绕过控制器也无法访问', function () {
    $map = (array) config('ecosystem_admin.route_permissions');
    $map['admin.catalog.ecosystem.probe'] = 'ecosystem.events.view';
    config(['ecosystem_admin.route_permissions' => $map]);

    // 探针路由只经过 admin 中间件，内部没有任何授权调用：能访问即说明
    // 中间件防线失效（控制器即使忘记 authorizeRoute 也不能成为缺口）。
    Route::middleware(['admin'])->get(config('app.admin_url').'/ecosystem-acl-probe', [
        'as'   => 'admin.catalog.ecosystem.probe',
        'uses' => fn (): string => 'probe-ok',
    ]);

    // 未登记权限的 ecosystem 路由：中间件拿不到权限，非超管一律 403。
    Route::middleware(['admin'])->get(config('app.admin_url').'/ecosystem-acl-unmapped', [
        'as'   => 'admin.catalog.ecosystem.unmapped-probe',
        'uses' => fn (): string => 'unmapped-ok',
    ]);

    $this->actingAs(ecoAclAdmin(['catalog']), 'admin');
    get(route('admin.catalog.ecosystem.probe'))
        ->assertForbidden()
        ->assertSeeText('无权执行该生态发布操作。');

    // 权限精确到 key：只有 content 权限的用户同样不能访问要求 events.view 的探针。
    $this->actingAs(ecoAclAdmin(['ecosystem.content.publish']), 'admin');
    get(route('admin.catalog.ecosystem.probe'))->assertForbidden();

    $this->actingAs(ecoAclAdmin(['catalog']), 'admin');
    get(route('admin.catalog.ecosystem.unmapped-probe'))->assertForbidden();

    // 拥有对应权限的 custom 角色与超级管理员可以通过中间件。
    $this->actingAs(ecoAclAdmin(['ecosystem.events.view']), 'admin');
    get(route('admin.catalog.ecosystem.probe'))
        ->assertOk()
        ->assertSeeText('probe-ok');

    $this->actingAs(ecoAclSuperAdmin(), 'admin');
    get(route('admin.catalog.ecosystem.probe'))
        ->assertOk()
        ->assertSeeText('probe-ok');
});

it('超级管理员可以打开工作台并看到生态发布菜单', function () {
    ecoAclFixtures();

    $this->actingAs(ecoAclSuperAdmin(), 'admin');

    get(route('admin.catalog.ecosystem.index'))
        ->assertOk()
        ->assertSeeText('生态发布工作台')
        ->assertSeeText('生态发布');
});

it('超级管理员访问全部页面与预览不会触发授权层拦截', function () {
    $ids = ecoAclFixtures();
    $params = ecoAclRouteParams($ids);

    $this->actingAs(ecoAclSuperAdmin(), 'admin');

    foreach (ecoPublishingRoutes() as $route) {
        $name = (string) $route->getName();
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        foreach ($methods as $method) {
            $response = $this->call($method, route($name, $params[$name]));

            // GET 页面必须是 200；写动作在空表单下是 302（校验失败回表单），
            // 但绝不能是 403 / 500。
            if ($method === 'GET') {
                expect($response->getStatusCode())->toBe(200, sprintf('GET %s 不是 200', $name));
            } else {
                expect($response->getStatusCode())->toBe(302, sprintf('%s %s 不是 302', $method, $name));
            }
        }
    }
});

it('未登录访问生态发布路由会重定向到后台登录', function () {
    $ids = ecoAclFixtures();

    get(route('admin.catalog.ecosystem.index'))
        ->assertRedirect(route('admin.session.create'));

    post(route('admin.catalog.ecosystem.events.metadata', $ids['event']))
        ->assertRedirect(route('admin.session.create'));
});

it('媒体上传与审核权限分离，引用审核归 ecosystem.media.review', function () {
    $ids = ecoAclFixtures();

    // 只有上传权（含查看）：能打开列表、能提交上传表单，但不能审核。
    $this->actingAs(ecoAclAdmin(['ecosystem.media.view', 'ecosystem.media.upload']), 'admin');

    get(route('admin.catalog.ecosystem.media.index'))->assertOk();

    post(route('admin.catalog.ecosystem.media.store'))->assertRedirect();

    post(route('admin.catalog.ecosystem.media.approve', $ids['media_pending']), [
        'expected_status' => 'pending',
    ])->assertForbidden();

    post(route('admin.catalog.ecosystem.events.revisions.media_refs.review', [
        $ids['event'],
        $ids['event_revision'],
        $ids['event_ref'],
    ]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertForbidden();

    expect((string) DB::table('ecosystem_media')->where('id', $ids['media_pending'])->value('status'))->toBe('pending');

    // 只有审核权（含查看）：能审核媒体与引用，但不能上传。
    $this->actingAs(ecoAclAdmin(['ecosystem.media.view', 'ecosystem.media.review']), 'admin');

    post(route('admin.catalog.ecosystem.media.store'))->assertForbidden();

    post(route('admin.catalog.ecosystem.media.approve', $ids['media_pending']), [
        'expected_status' => 'pending',
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    expect((string) DB::table('ecosystem_media')->where('id', $ids['media_pending'])->value('status'))->toBe('approved');

    post(route('admin.catalog.ecosystem.events.revisions.media_refs.review', [
        $ids['event'],
        $ids['event_revision'],
        $ids['event_ref'],
    ]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $ids['event']));

    expect((string) DB::table('ecosystem_media_refs')->where('id', $ids['event_ref'])->value('review_status'))->toBe('approved');

    // 活动编辑权不等于媒体审核权：仍不能审核引用。
    $this->actingAs(ecoAclAdmin(['ecosystem.events.view', 'ecosystem.events.edit']), 'admin');

    post(route('admin.catalog.ecosystem.content.revisions.media_refs.review', [
        $ids['post'],
        $ids['content_revision'],
        $ids['content_ref'],
    ]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertForbidden();
});
