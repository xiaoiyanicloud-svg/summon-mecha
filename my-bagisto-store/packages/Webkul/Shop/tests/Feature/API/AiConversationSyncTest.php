<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AiConversation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function aiSyncCustomer(string $suffix): Customer
{
    return Customer::create([
        'first_name'  => 'AI',
        'last_name'   => $suffix,
        'email'       => 'ai-sync-'.$suffix.'-'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function aiConversationPayload(int $updatedAt, string $text = '测试需求', bool $deleted = false): array
{
    return [
        'client_id'         => 'conv-test-1',
        'title'             => '测试对话',
        'messages'          => $deleted ? [] : [
            ['role' => 'user', 'text' => $text],
            ['role' => 'ai', 'text' => '测试回答', 'machines' => [['id' => 1, 'name' => '示例机器人']]],
        ],
        'client_updated_at' => $updatedAt,
        'deleted'           => $deleted,
    ];
}

it('AI-SYNC · 登录用户可创建并取回跨设备会话', function () {
    $customer = aiSyncCustomer('create');
    actingAs($customer, 'customer');

    $res = postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(1000)],
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.conversations.0.client_id', 'conv-test-1')
        ->assertJsonPath('data.conversations.0.messages.1.text', '测试回答');

    expect($res->json('data.conversations.0.deleted_at'))->toBeNull();
    expect(AiConversation::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('AI-SYNC · 较新的客户端版本覆盖旧版本', function () {
    $customer = aiSyncCustomer('newer');
    actingAs($customer, 'customer');

    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(1000, '旧需求')],
    ])->assertOk();

    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(2000, '新需求')],
    ])->assertOk()
        ->assertJsonPath('data.conversations.0.messages.0.text', '新需求');
});

it('AI-SYNC · 删除墓碑阻止旧设备复活会话', function () {
    $customer = aiSyncCustomer('tombstone');
    actingAs($customer, 'customer');

    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(1000)],
    ])->assertOk();

    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(3000, '', true)],
    ])->assertOk()
        ->assertJsonPath('data.conversations.0.messages', []);

    $res = postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(2000, '旧设备内容')],
    ])->assertOk();

    expect($res->json('data.conversations.0.deleted_at'))->not->toBeNull();
    expect(AiConversation::withTrashed()->first()->trashed())->toBeTrue();
});

it('AI-SYNC · 不同客户之间严格隔离', function () {
    $me = aiSyncCustomer('isolate-me');
    $other = aiSyncCustomer('isolate-other');

    actingAs($other, 'customer');
    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(1000)],
    ])->assertOk();

    actingAs($me, 'customer');
    postJson(route('shop.api.ai.conversations.sync'), ['conversations' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.conversations');
});

it('AI-SYNC · 未登录不能读取或写入同步表', function () {
    postJson(route('shop.api.ai.conversations.sync'), [
        'conversations' => [aiConversationPayload(1000)],
    ])->assertStatus(401);

    expect(AiConversation::withTrashed()->count())->toBe(0);
});
