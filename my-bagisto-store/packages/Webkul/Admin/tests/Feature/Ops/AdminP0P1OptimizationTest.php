<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\Product;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Notification as PlatformNotification;
use Webkul\Shop\Models\SkillVersion;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

function createLimitedAdmin(array $permissions): Admin
{
    $role = Role::create([
        'name'            => 'LimitedRole_'.uniqid(),
        'description'     => 'Limited test role',
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create([
        'role_id' => $role->id,
        'status'  => 1,
    ]);
}

it('P0 fail-closed ACL blocks unauthorized custom Admin actions and search endpoints with 403', function () {
    $limited = createLimitedAdmin(['dashboard']);
    actingAs($limited, 'admin');

    post(route('admin.after-sales.approve', 999))->assertStatus(403);
    post(route('admin.after-sales.reject', 999))->assertStatus(403);
    post(route('admin.customers.cooperations.invites.store'))->assertStatus(403);
    post(route('admin.customers.cooperations.issue_invite', 999))->assertStatus(403);
    post(route('admin.customers.developers.realname.approve', 999))->assertStatus(403);
    post(route('admin.catalog.skills.versions.publish', 999))->assertStatus(403);
    post(route('admin.catalog.skill-reviews.judge', 999))->assertStatus(403);
    post(route('admin.catalog.content-security.dispose', 999))->assertStatus(403);
    post(route('admin.catalog.skill-reports.dispose', 999))->assertStatus(403);
    post(route('admin.customers.tickets.reply', 999))->assertStatus(403);
    post(route('admin.customers.incidents.handle', 999))->assertStatus(403);

    getJson(route('admin.search.skills', ['query' => 'walk']))->assertStatus(403);
    getJson(route('admin.search.developers', ['query' => 'dev']))->assertStatus(403);
    getJson(route('admin.search.cooperations', ['query' => 'coop']))->assertStatus(403);
});

it('P0 cooperation reviewer without developer-invites.manage sees list without invite box and gets 403 on invite creation', function () {
    $coop = CooperationApplication::create([
        'application_no' => testUniqueNo('COOP'),
        'coop_type'      => CooperationApplication::TYPE_MANUFACTURER,
        'org_name'       => '宇树合作测试厂',
        'contact_name'   => '张工',
        'contact_phone'  => '13800001111',
        'status'         => CooperationApplication::STATUS_PENDING,
    ]);

    $reviewer = createLimitedAdmin([
        'dashboard',
        'customers',
        'customers.cooperations',
        'customers.cooperations.view',
        'customers.cooperations.review',
    ]);
    actingAs($reviewer, 'admin');

    $indexResp = get(route('admin.customers.cooperations.index'));
    $indexResp->assertStatus(200);
    expect($indexResp->getContent())->not->toContain(route('admin.customers.cooperations.invites.store'));

    $showResp = get(route('admin.customers.cooperations.show', $coop->id));
    $showResp->assertStatus(200);
    expect($showResp->getContent())->not->toContain(route('admin.customers.cooperations.issue_invite', $coop->id));

    post(route('admin.customers.cooperations.invites.store'))->assertStatus(403);
    post(route('admin.customers.cooperations.issue_invite', $coop->id))->assertStatus(403);
});

it('P0 top-level Admin menu has no duplicate sort keys and ecosystem menu has icon and name', function () {
    $this->loginAsAdmin();

    $items = collect(menu()->getItems('admin'));
    $sorts = $items->map(fn ($item) => $item->sort)->values()->all();

    expect($sorts)->toHaveCount(count(array_unique($sorts)));

    $ecosystem = $items->first(fn ($item) => $item->getKey() === 'ecosystem');
    expect($ecosystem)->not->toBeNull();
    expect($ecosystem->getName())->toBe('生态运营');
    expect($ecosystem->getIcon())->not->toBeEmpty();
});

it('P1 removes blocking Google Fonts and external Bagisto/Webkul branding from login and dashboard', function () {
    $loginResp = get(route('admin.session.create'));
    $loginResp->assertStatus(200);
    $loginHtml = $loginResp->getContent();

    expect($loginHtml)
        ->toContain('召唤机甲')
        ->toContain('sm-admin-brand.css')
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('bagisto.com')
        ->not->toContain('webkul.com');

    $this->loginAsAdmin();
    $dashResp = get(route('admin.dashboard.index'));
    $dashResp->assertStatus(200);
    $dashHtml = $dashResp->getContent();

    expect($dashHtml)
        ->toContain('召唤机甲')
        ->toContain('sm-admin-brand.css')
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('bagisto.com')
        ->not->toContain('webkul.com')
        ->not->toContain('cache/logo/bagisto.png');
});

it('P1 dashboard renders aggregated Summon Mecha workbench hub while preserving Bagisto report disclaimer and ACL isolation', function () {
    CooperationApplication::create([
        'application_no' => testUniqueNo('COOPWB'),
        'coop_type'      => CooperationApplication::TYPE_RAAS,
        'org_name'       => '星海机甲运维网络',
        'contact_name'   => '李经理',
        'contact_phone'  => '13900002222',
        'status'         => CooperationApplication::STATUS_PENDING,
    ]);

    $superAdmin = $this->loginAsAdmin();
    PlatformNotification::create([
        'recipient_type' => 'admin',
        'recipient_id'   => $superAdmin->id,
        'type'           => 'ops_alert',
        'title'          => '巡检存在待办工单',
        'content'        => '工单池有超时待回复项',
        'channel'        => PlatformNotification::CHANNEL_INAPP,
        'sent_at'        => now(),
    ]);

    $superResp = get(route('admin.dashboard.index'));
    $superResp->assertStatus(200);
    $superHtml = $superResp->getContent();

    expect($superHtml)
        ->toContain('data-testid="summon-mecha-workbench"')
        ->toContain('今日运营工作台')
        ->toContain('技能准入待审')
        ->toContain('合作与供应链入驻')
        ->toContain('平台未读告警')
        ->toContain('本页数字按 Bagisto 原生报表口径计算');

    $noticePos = strpos($superHtml, 'Bagisto 原生报表口径');
    $statsPos = strpos($superHtml, 'v-dashboard-overall-details');
    expect($noticePos)->not->toBeFalse()
        ->and($statsPos)->not->toBeFalse()
        ->and($noticePos)->toBeLessThan($statsPos);

    // Role with only dashboard permission should NOT see privileged queue cards
    $minimalAdmin = createLimitedAdmin(['dashboard']);
    actingAs($minimalAdmin, 'admin');

    $minResp = get(route('admin.dashboard.index'));
    $minResp->assertStatus(200);
    $minHtml = $minResp->getContent();

    expect($minHtml)
        ->toContain('data-testid="summon-mecha-workbench"')
        ->not->toContain('合作与供应链入驻')
        ->not->toContain('售后退款待审')
        ->not->toContain('技能准入待审');
});

it('P1 notification bell endpoint unifies unread platform alerts with order notifications and supports search endpoints', function () {
    $admin = $this->loginAsAdmin();

    PlatformNotification::create([
        'recipient_type' => 'admin',
        'recipient_id'   => $admin->id,
        'type'           => 'ops_alert',
        'title'          => 'P1BellTestAlert',
        'content'        => '检测到高优告警',
        'channel'        => PlatformNotification::CHANNEL_INAPP,
        'sent_at'        => now(),
    ]);

    $notifResp = getJson(route('admin.notification.get_notification', ['limit' => 5, 'read' => 0]));
    $notifResp->assertStatus(200)
        ->assertJsonPath('alerts_unread', 1);

    $alerts = $notifResp->json('admin_alerts');
    expect($alerts)->toBeArray()->not->toBeEmpty();
    expect($alerts[0]['title'])->toBe('P1BellTestAlert');

    // Seed searchable Skill, Developer, CooperationApplication
    $product = Product::factory()->create([
        'sku' => 'SM-P1-SEARCH-'.uniqid(),
    ]);

    $skillCategoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');
    if ($skillCategoryId) {
        DB::table('product_categories')->insertOrIgnore([
            'product_id'  => $product->id,
            'category_id' => $skillCategoryId,
        ]);
    }

    SkillVersion::create([
        'product_id'   => $product->id,
        'version'      => '1.0.0',
        'channel'      => SkillVersion::CHANNEL_STABLE,
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => SkillVersion::LICENSE_PER_DEVICE,
        'pricing_mode' => SkillVersion::PRICING_BUYOUT,
    ]);

    $customer = Customer::factory()->create(['email' => 'devsearch_'.uniqid().'@summonmecha.test']);
    $dev = DeveloperProfile::create([
        'customer_id'     => $customer->id,
        'dev_type'        => DeveloperProfile::DEV_TYPE_COMPANY,
        'realname_status' => DeveloperProfile::REALNAME_PENDING,
        'company_name'    => '深蓝具身实验室',
        'status'          => DeveloperProfile::STATUS_ACTIVE,
    ]);

    CooperationApplication::create([
        'application_no'  => 'COOPSEARCH'.uniqid(),
        'coop_type'       => CooperationApplication::TYPE_INFLUENCER,
        'org_name'        => '机甲测评先锋',
        'contact_name'    => '陈先生',
        'contact_phone'   => '13700003333',
        'followup_person' => '运营小刘',
        'status'          => CooperationApplication::STATUS_CONTACTING,
    ]);

    getJson(route('admin.search.skills', ['query' => $product->sku]))
        ->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.sku', $product->sku);

    getJson(route('admin.search.developers', ['query' => '深蓝具身']))
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $dev->id);

    getJson(route('admin.search.cooperations', ['query' => '机甲测评先锋']))
        ->assertStatus(200)
        ->assertJsonPath('data.0.org_name', '机甲测评先锋');
});
