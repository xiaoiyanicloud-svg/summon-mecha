<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AiAnalyticsEvent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function aiAnalyticsCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'AI',
        'last_name'   => 'Analytics',
        'email'       => 'ai-analytics-'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

it('AI-EVENT · 游客可提交允许事件且设备键只存哈希', function () {
    postJson(route('shop.api.ai.events.store'), [
        'events' => [[
            'event_id'    => 'evt-guest-1',
            'event_name'  => 'ai_page_view',
            'occurred_at' => now()->toIso8601String(),
            'properties'  => ['source' => 'tabbar', 'viewport' => 375, 'raw' => ['prompt' => '不能入库']],
        ], [
            'event_id'    => 'evt-guest-2',
            'event_name'  => 'ai_prompt_submit',
            'occurred_at' => now()->toIso8601String(),
            'properties'  => ['query_length' => 24],
        ]],
    ], ['X-Guest-Key' => 'guest-device-key-123456'])->assertOk()
        ->assertJsonPath('accepted', 2);

    $rows = AiAnalyticsEvent::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->first()->device_hash)->toHaveLength(64)
        ->and($rows->first()->device_hash)->not->toBe('guest-device-key-123456')
        ->and($rows->first()->properties)->toMatchArray(['source' => 'tabbar', 'viewport' => 375])
        ->and($rows->first()->properties)->not->toHaveKey('raw');
});

it('AI-EVENT · 登录事件归属当前客户', function () {
    $customer = aiAnalyticsCustomer();
    actingAs($customer, 'customer');

    postJson(route('shop.api.ai.events.store'), [
        'events' => [[
            'event_id'    => 'evt-customer-1',
            'event_name'  => 'ai_reply_done',
            'occurred_at' => now()->toIso8601String(),
            'properties'  => ['latency_ms' => 1800],
        ]],
    ])->assertOk();

    expect(AiAnalyticsEvent::query()->first()->customer_id)->toBe($customer->id);
});

it('AI-EVENT · event_id 去重，重复上报不会重复计数', function () {
    $event = [
        'event_id'    => 'evt-dedupe-1',
        'event_name'  => 'ai_first_token',
        'occurred_at' => now()->toIso8601String(),
        'properties'  => ['latency_ms' => 600],
    ];

    postJson(route('shop.api.ai.events.store'), ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 1);
    postJson(route('shop.api.ai.events.store'), ['events' => [$event]])->assertOk()->assertJsonPath('accepted', 0);

    expect(AiAnalyticsEvent::query()->count())->toBe(1);
});

it('AI-EVENT · 未登记事件名被拒绝', function () {
    postJson(route('shop.api.ai.events.store'), [
        'events' => [[
            'event_id'    => 'evt-invalid-1',
            'event_name'  => 'raw_prompt_capture',
            'occurred_at' => now()->toIso8601String(),
        ]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    expect(DB::table('ai_analytics_events')->count())->toBe(0);
});

it('AI-EVENT · 报表输出计数与核心漏斗', function () {
    foreach (['ai_page_view', 'ai_prompt_submit', 'ai_first_token', 'ai_reply_done'] as $index => $eventName) {
        AiAnalyticsEvent::query()->insert([
            'event_id'    => 'report-'.$index,
            'customer_id' => null,
            'device_hash' => null,
            'event_name'  => $eventName,
            'properties'  => null,
            'occurred_at' => now(),
            'created_at'  => now(),
        ]);
    }

    $this->artisan('ai:analytics-report', ['--days' => 7])
        ->expectsOutputToContain('ai_page_view')
        ->assertSuccessful();

    $report = app(\Webkul\Shop\Services\Ai\AiAnalyticsIngestService::class)->report(7);
    expect($report['counts']['ai_reply_done'])->toBe(1)
        ->and($report['funnel'])->toMatchArray([
            'ai_page_view'    => 1,
            'ai_prompt_submit' => 1,
            'ai_first_token'  => 1,
            'ai_reply_done'   => 1,
        ]);
});
