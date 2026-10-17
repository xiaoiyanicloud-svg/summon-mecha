<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\Notification;
use Webkul\Shop\Models\Seller;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * S-12c 修 · 门户收件箱可见类型放宽（realname.%）—— 读侧回归。
 *
 * 背景：S-12c 落地后运营逐层驳回开发者实名会发 `realname.rejected`（收件人=开发者本人），
 * 但门户收件箱 scope `Notification::scopeDeveloperInbox` 此前只收 `skill.%`，这条**看不到**。
 * 本卡的最小修法：在 scope 里**新增**一个可见类型 `realname.%`（OR 组用闭包包起来，
 * 别让 orWhere 短路掉 `recipient_id` 归属过滤 —— 那会把别人的通知放进来）。
 *
 * 锁四件事：
 *   a) 开发者自己的 realname.rejected 出现在其门户收件箱（且能标记已读）；
 *   b) **越权隔离**：看不到别人的任何通知 —— 同类型（realname.rejected）也不行；
 *   c) 既有 `skill.%` 可见类型不受影响（放宽没有挤掉技能类通知）；
 *   d) 买家事务类型（refund./after_sale./delivery./trial.）仍被挡在门户之外。
 */

function riCustomer(string $prefix = 'ri'): Customer
{
    return Customer::create([
        'first_name'  => 'Ri',
        'last_name'   => 'Fix',
        'email'       => $prefix.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/** 一个可进门户的开发者（customer + seller 归属锚点 + active profile）。返回 [customer, profile]。 */
function riDeveloper(string $prefix = 'ri'): array
{
    $customer = riCustomer($prefix);

    $seller = Seller::create([
        'code'              => 'ri_'.uniqid(),
        'name'              => '收件箱放宽测试开发者',
        'status'            => 'active',
        'owner_customer_id' => $customer->id,
    ]);

    $profile = DeveloperProfile::create([
        'customer_id'     => $customer->id,
        'seller_id'       => $seller->id,
        'dev_type'        => DeveloperProfile::DEV_TYPE_COMPANY,
        'realname_status' => DeveloperProfile::REALNAME_PENDING,
        'status'          => DeveloperProfile::STATUS_ACTIVE,
    ]);

    return [$customer, $profile];
}

// ==================== a) 开发者的 realname.rejected 在门户看得到 ====================

it('a) 开发者的 realname.rejected 出现在其门户收件箱，且可被标记已读', function () {
    [$customerA] = riDeveloper('ri_a_');

    $noteA = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customerA->id,
        'type'           => 'realname.rejected',
        'title'          => '实名审核未通过',
        'content'        => '你的实名资料「企业层（公司认证）」未通过审核。理由：营业执照照片不清晰。',
        'payload'        => ['layer' => 'company', 'reason' => '营业执照照片不清晰'],
    ]);

    // scope 是读侧三处的**唯一**尺子：直接查它，可见
    expect(Notification::query()->developerInbox($customerA->id)->whereKey($noteA->id)->count())->toBe(1);

    actingAs($customerA, 'developer');

    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('实名审核未通过')
        ->assertSeeText('营业执照照片不清晰');

    // read 侧也走同一把尺子 → 这条能被 A 标记已读
    post(route('shop.dev.notifications.read', $noteA->id))->assertRedirect();
    expect(Notification::find($noteA->id)->read_at)->not->toBeNull();
});

// ==================== b) 越权隔离：看不到别人的（同类型也不行） ====================

it('b) 越权隔离：A 的门户收件箱看不到 B 的任何通知 —— 同类型 realname.rejected 也不行', function () {
    [$custA] = riDeveloper('ri_b_a_');
    [$custB] = riDeveloper('ri_b_b_');

    // B 也有一条 realname.rejected —— 正是新增可见类型，最容易被 orWhere 短路漏进来
    $noteB = Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $custB->id,
        'type'           => 'realname.rejected',
        'title'          => 'B 的实名驳回',
        'content'        => 'B 专属的驳回内容，A 绝不该看到',
    ]);

    // 给 A 放一条可见的，证明页面确实渲染了（不是空页导致的假阴性）
    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $custA->id,
        'type'           => 'skill.published',
        'title'          => 'A 的技能已上架',
        'content'        => '版本 1.0.0',
    ]);

    actingAs($custA, 'developer');

    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('A 的技能已上架')
        ->assertDontSeeText('B 的实名驳回')
        ->assertDontSeeText('B 专属的驳回内容');

    // 直接查 scope：A 的收件箱里一行都不是 B 的（含 realname.% 分支）
    expect(Notification::query()->developerInbox($custA->id)->where('recipient_id', $custB->id)->count())->toBe(0);

    // A 拿 B 的通知 id 去标记已读 → 404（越权无副作用）
    post(route('shop.dev.notifications.read', $noteB->id))->assertNotFound();
    expect(Notification::find($noteB->id)->read_at)->toBeNull();
});

// ==================== b2) 反证：OR 不分组就会越权 ====================

it('b2) 反证：若 OR 组不分组（朴素写法），B 的通知会漏进 A —— 证明闭包是承重的', function () {
    [$custA] = riDeveloper('ri_b2_a_');
    [$custB] = riDeveloper('ri_b2_b_');

    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $custB->id,
        'type'           => 'realname.rejected',
        'title'          => 'B 的实名驳回',
        'content'        => 'B 专属内容',
    ]);

    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $custA->id,
        'type'           => 'skill.published',
        'title'          => 'A 的技能已上架',
        'content'        => '版本 1.0.0',
    ]);

    // 朴素写法（OR 未分组）——正是 scope 里刻意避免的那半截：AND 与 OR 优先级下，
    // recipient_id 条件被 OR 短路，任何人的 realname.% 都会漏进来。
    $naive = Notification::query()
        ->where('recipient_type', 'customer')
        ->where('recipient_id', $custA->id)
        ->where('type', 'like', 'skill.%')
        ->orWhere('type', 'like', 'realname.%')
        ->get();

    expect($naive->contains(fn ($n) => (int) $n->recipient_id === $custB->id))->toBeTrue();  // 越权

    // 而 scope（闭包分组）不含 B 的任何行
    $safe = Notification::query()->developerInbox($custA->id)->get();
    expect($safe->contains(fn ($n) => (int) $n->recipient_id === $custB->id))->toBeFalse();
});

// ==================== c) 既有 skill.% 不受影响 ====================

it('c) 放宽过滤不影响既有 skill.% 可见类型（技能类通知照旧可见）', function () {
    [$customer] = riDeveloper('ri_c_');

    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.review.rejected',
        'title'          => '技能审核未通过',
        'content'        => '技能包缺少安装说明文件',
    ]);

    actingAs($customer, 'developer');

    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('技能审核未通过')
        ->assertSeeText('技能包缺少安装说明文件');

    expect(Notification::query()->developerInbox($customer->id)->where('type', 'skill.review.rejected')->count())->toBe(1);
});

// ==================== d) 买家事务类型仍被挡在外面 ====================

it('d) 买家事务类型（refund./after_sale./delivery./trial.）仍被挡在门户收件箱之外', function () {
    [$customer] = riDeveloper('ri_d_');

    $buyer = [
        'refund.settled'      => '退款已到账',
        'after_sale.approved' => '退款申请已通过',
        'delivery.completed'  => '技能已下发',
        'trial.expired'       => '试用已到期',
    ];

    foreach ($buyer as $type => $title) {
        Notification::create([
            'recipient_type' => 'customer',
            'recipient_id'   => $customer->id,
            'type'           => $type,
            'title'          => $title,
            'content'        => '买家侧内容：'.$type,
        ]);
    }

    // 放一条技能类通知，证明页面确实渲染了（不是空页导致的假阴性）
    Notification::create([
        'recipient_type' => 'customer',
        'recipient_id'   => $customer->id,
        'type'           => 'skill.published',
        'title'          => '技能已上架',
        'content'        => '版本 1.0.0 已上架',
    ]);

    actingAs($customer, 'developer');

    get(route('shop.dev.notifications.index'))
        ->assertOk()
        ->assertSeeText('技能已上架')
        ->assertDontSeeText('退款已到账')
        ->assertDontSeeText('退款申请已通过')
        ->assertDontSeeText('技能已下发')
        ->assertDontSeeText('试用已到期');

    // 直接查 scope：买家类型一条都进不来
    expect(Notification::query()
        ->developerInbox($customer->id)
        ->whereIn('type', array_keys($buyer))
        ->count())->toBe(0);
});
