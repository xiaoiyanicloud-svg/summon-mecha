<?php

use Illuminate\Http\UploadedFile;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Models\TicketMessage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * A9 · 售后域其余校验缺口。
 *
 * 覆盖四项:
 *   ① 工单 category 收敛枚举 + 接附件
 *   ② 事故 occurred_at 不允许未来时间
 *   ③ 商品评价 title/comment 长度上限 + 附件大小上限
 *   ④ 事故受理台 oplog 并入 note 摘要(见 Admin 侧测试)
 *
 * (售后端点限流 throttle 已在 X3 批次接上,本文件不再重复。)
 */

function a9Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'A9',
        'last_name'   => 'User',
        'email'       => 'a9'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

// ───────────────────────── 工单 ─────────────────────────

it('A9 · 工单 category 必须是枚举内的值', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.tickets.store'), [
        'content'  => '装不上',
        'category' => '这不是一个合法类别',
    ])->assertStatus(422);

    expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(0);
});

it('A9 · 工单 category 合法值(delivery)与省略时默认 other 都通过', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.tickets.store'), [
        'content'  => '下发失败',
        'category' => SupportTicket::CATEGORY_DELIVERY,
    ])->assertStatus(201);

    postJson(route('shop.api.tickets.store'), ['content' => '随便说说'])->assertStatus(201);

    expect(SupportTicket::where('customer_id', $customer->id)->orderBy('id')->pluck('category')->all())
        ->toBe([SupportTicket::CATEGORY_DELIVERY, SupportTicket::CATEGORY_OTHER]);
});

it('A9 · 工单接附件:首条留言带上附件链接,详情接口回读', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.tickets.store'), [
        'content'     => '截图在链接里',
        'attachments' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ])->assertStatus(201);

    $message = TicketMessage::where('ticket_id', $res->json('data.id'))->first();

    expect($message->attachments)->toBe([
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]);

    // 详情接口把附件原样回给当事人
    getJson(route('shop.api.tickets.show', $res->json('data.id')))
        ->assertOk()
        ->assertJsonPath('data.messages.0.attachments', [
            'https://example.com/a.png',
            'https://example.com/b.png',
        ]);
});

it('A9 · 工单附件:超过 5 个、单个超过 500 字都被拒', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.tickets.store'), [
        'content'     => 'x',
        'attachments' => array_fill(0, 6, 'https://example.com/a.png'),
    ])->assertStatus(422);

    postJson(route('shop.api.tickets.store'), [
        'content'     => 'x',
        'attachments' => [str_repeat('h', 501)],
    ])->assertStatus(422);

    expect(SupportTicket::where('customer_id', $customer->id)->count())->toBe(0);
});

// ───────────────────────── 事故 ─────────────────────────

it('A9 · 事故 occurred_at 不允许未来时间(否则 SLA 计时错乱)', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MINOR,
        'description' => 'x',
        'occurred_at' => now()->addDay()->toDateTimeString(),
    ])->assertStatus(422);

    expect(Incident::where('customer_id', $customer->id)->count())->toBe(0);
});

it('A9 · 事故 occurred_at 是过去/现在的时间 → 正常受理', function () {
    $customer = a9Customer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.incidents.store'), [
        'severity'    => Incident::SEVERITY_MINOR,
        'description' => 'x',
        'occurred_at' => now()->subHours(3)->toDateTimeString(),
    ])->assertStatus(201);
});

// ───────────────────────── 商品评价 ─────────────────────────

it('A9 · 商品评价 title / comment 超长被拒', function () {
    postJson(route('shop.api.products.reviews.store', ['id' => 1]), [
        'title'   => str_repeat('标', 256),
        'comment' => '好',
        'rating'  => 5,
    ])->assertStatus(422);

    postJson(route('shop.api.products.reviews.store', ['id' => 1]), [
        'title'   => '标题',
        'comment' => str_repeat('评', 2001),
        'rating'  => 5,
    ])->assertStatus(422);
});

it('A9 · 商品评价附件超过大小上限被拒', function () {
    $tooBig = UploadedFile::fake()->create('big.mp4', 25000, 'video/mp4'); // 25MB > 20MB

    postJson(route('shop.api.products.reviews.store', ['id' => 1]), [
        'title'       => '标题',
        'comment'     => '很满意',
        'rating'      => 5,
        'attachments' => [$tooBig],
    ])->assertStatus(422);
});
