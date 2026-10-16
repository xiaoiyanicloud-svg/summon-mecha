<?php

use Webkul\Shop\Models\ComplianceFiling;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SupportTicket;
use Webkul\Shop\Services\Ops\AdminAlertService;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * 平台告警链路：定时任务 → 告警服务（带去重）→ 管理员收件箱。
 *
 * 这三段缺一段整条链就没意义：
 *   有任务没收件箱 = 往没人看的信箱投信
 *   有收件箱没去重   = 三天内被刷屏，然后被无视（比没有更糟）
 *   有去重没任务     = 没人发现
 */

function oaTicket(array $overrides = []): SupportTicket
{
    return SupportTicket::create(array_merge([
        'ticket_no'  => testUniqueNo('T'),
        'channel'    => 'app',
        'category'   => 'install_failed',
        'priority'   => 'normal',
        'status'     => SupportTicket::STATUS_OPEN,
        'sla_due_at' => now()->subHours(3),   // 默认就是超时的
    ], $overrides));
}

/**
 * 造一起事故。
 *
 * ⚠ `created_at` 不在模型的 fillable 里（正常情况不该让业务代码改创建时间），
 *   所以测试里直接写库来把它挪到过去 —— 命令判定的正是"上报后过了多久还没结案"。
 */
function oaIncident(array $overrides = []): Incident
{
    $createdAt = $overrides['created_at'] ?? now()->subHours(60);
    unset($overrides['created_at'], $overrides['updated_at']);

    $incident = Incident::create(array_merge([
        'incident_no'    => testUniqueNo('I'),
        'severity'       => Incident::SEVERITY_MODERATE,
        'description'    => 'x',
        'responsibility' => Incident::RESPONSIBILITY_UNKNOWN,
        'status'         => Incident::STATUS_REPORTED,
    ], $overrides));

    DB::table('incident')->where('id', $incident->id)->update([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $incident->fresh();
}

// ==================== 1 · 去重（最核心的机制）====================

it('去重：未读的告警不会重复发（否则三天内就被无视）', function () {
    $admin = $this->loginAsAdmin();

    $alerts = app(AdminAlertService::class);

    $alerts->alert('t', '标题', '内容', 'k:1');
    $alerts->alert('t', '标题', '内容', 'k:1');

    // ⚠ 断言按「我」这个收件人计数 —— alert() 是发给**所有在职管理员**的，
    //   拿返回条数当断言会把库里其他管理员也算进来。
    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 't')
        ->count())->toBe(1);
});

it('去重：不同的 dedup_key 互不影响', function () {
    $admin = $this->loginAsAdmin();

    $alerts = app(AdminAlertService::class);

    $alerts->alert('t', 'A', '', 'k:1');
    $alerts->alert('t', 'B', '', 'k:2');

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 't')
        ->count())->toBe(2);
});

it('去重：读过之后仍在静默期内，不重复发', function () {
    $admin = $this->loginAsAdmin();

    $alerts = app(AdminAlertService::class);
    $alerts->alert('t', '标题', '', 'k:1');

    // 标记已读
    DB::table('notification')->where('recipient_id', $admin->id)->update(['read_at' => now()]);

    $alerts->alert('t', '标题', '', 'k:1');

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 't')
        ->count())->toBe(1);
});

it('去重：已读且过了静默期 → 会再提醒一次（问题还没解决）', function () {
    $admin = $this->loginAsAdmin();

    $alerts = app(AdminAlertService::class);
    $alerts->alert('t', '标题', '', 'k:1');

    // 读过了，而且把创建时间挪到静默期之外
    DB::table('notification')->where('recipient_id', $admin->id)->update([
        'read_at'    => now()->subDays(10),
        'created_at' => now()->subDays(AdminAlertService::QUIET_DAYS + 1),
    ]);

    $alerts->alert('t', '标题', '', 'k:1');

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 't')
        ->count())->toBe(2);
});

// ==================== 2 · 工单 SLA ====================

it('工单超时：升级优先级 + 发告警', function () {
    $admin = $this->loginAsAdmin();
    $ticket = oaTicket();

    artisan('ops:escalate-overdue-tickets')->assertSuccessful();

    expect($ticket->fresh()->priority)->toBe('high');

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 'ticket_overdue')
        ->exists())->toBeTrue();
});

it('工单超时：已结单的不算超时（超时只对"还欠着用户"的单有意义）', function () {
    $this->loginAsAdmin();
    $ticket = oaTicket(['status' => SupportTicket::STATUS_RESOLVED]);

    artisan('ops:escalate-overdue-tickets')->assertSuccessful();

    expect($ticket->fresh()->priority)->toBe('normal')
        ->and(DB::table('notification')->where('type', 'ticket_overdue')->count())->toBe(0);
});

it('工单超时：还在 SLA 内的不打扰', function () {
    $this->loginAsAdmin();
    oaTicket(['sla_due_at' => now()->addHours(5)]);

    artisan('ops:escalate-overdue-tickets')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'ticket_overdue')->count())->toBe(0);
});

it('工单超时：不自动结单（把用户的问题从队列里抹掉是最不负责任的做法）', function () {
    $this->loginAsAdmin();
    $ticket = oaTicket();

    artisan('ops:escalate-overdue-tickets')->assertSuccessful();

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_OPEN);
});

it('工单超时：命令支持 --dry-run，且不写库', function () {
    $this->loginAsAdmin();
    $ticket = oaTicket();

    artisan('ops:escalate-overdue-tickets --dry-run')->assertSuccessful();

    expect($ticket->fresh()->priority)->toBe('normal')
        ->and(DB::table('notification')->where('type', 'ticket_overdue')->count())->toBe(0);
});

// ==================== 3 · 备案到期 ====================

it('备案到期：已备案且临近 → 发告警', function () {
    $admin = $this->loginAsAdmin();

    ComplianceFiling::create([
        'filing_type'      => ComplianceFiling::TYPE_ICP,
        'name'             => '门户备案',
        'status'           => ComplianceFiling::STATUS_FILED,
        'annual_review_at' => now()->addDays(20),
    ]);

    artisan('ops:alert-compliance-due')->assertSuccessful();

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 'compliance_due')
        ->exists())->toBeTrue();
});

it('备案到期：还没备案的不提醒（还没到期一说，报了会让提醒永远响）', function () {
    $this->loginAsAdmin();

    ComplianceFiling::create([
        'filing_type'      => ComplianceFiling::TYPE_ICP,
        'name'             => '办理中',
        'status'           => ComplianceFiling::STATUS_PENDING,
        'annual_review_at' => now()->subDay(),
    ]);

    artisan('ops:alert-compliance-due')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'compliance_due')->count())->toBe(0);
});

// ==================== 4 · 事故未结案 ====================

it('事故：严重事故超过 4 小时就提醒（时限比一般事故短得多）', function () {
    $admin = $this->loginAsAdmin();
    oaIncident(['severity' => Incident::SEVERITY_CRITICAL, 'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5)]);

    artisan('ops:alert-open-incidents')->assertSuccessful();

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 'incident_open')
        ->exists())->toBeTrue();
});

it('事故：严重事故才 1 小时 → 不打扰（提醒过早会让运营对告警脱敏）', function () {
    $this->loginAsAdmin();
    oaIncident(['severity' => Incident::SEVERITY_CRITICAL, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    artisan('ops:alert-open-incidents')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'incident_open')->count())->toBe(0);
});

it('事故：一般事故 5 小时 → 还在 48 小时时限内，不提醒', function () {
    $this->loginAsAdmin();
    oaIncident(['severity' => Incident::SEVERITY_MODERATE, 'created_at' => now()->subHours(5), 'updated_at' => now()->subHours(5)]);

    artisan('ops:alert-open-incidents')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'incident_open')->count())->toBe(0);
});

it('事故：已结案的不再提醒', function () {
    $this->loginAsAdmin();
    oaIncident([
        'severity'   => Incident::SEVERITY_CRITICAL,
        'status'     => Incident::STATUS_RESOLVED,
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    artisan('ops:alert-open-incidents')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'incident_open')->count())->toBe(0);
});

// ==================== 5 · 管理员收件箱 ====================

it('收件箱：列表页可以渲染并显示未读数', function () {
    $admin = $this->loginAsAdmin();

    app(AdminAlertService::class)->alert('t', '有一条待办', '内容', 'k:1');

    get(route('admin.alerts.index'))
        ->assertOk()
        ->assertSeeText('平台告警')
        ->assertSeeText('未读 1');
});

it('收件箱：标记已读', function () {
    $admin = $this->loginAsAdmin();

    app(AdminAlertService::class)->alert('t', '待办', '内容', 'k:1');

    $id = DB::table('notification')->where('recipient_id', $admin->id)->value('id');

    post(route('admin.alerts.read', $id))->assertOk();

    expect(DB::table('notification')->where('id', $id)->value('read_at'))->not->toBeNull();
});

it('收件箱：只能标记发给自己的那条（否则可让问题从别人视野里消失）', function () {
    $me = $this->loginAsAdmin();

    $other = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    app(AdminAlertService::class)->alert('t', '别人的待办', '内容', 'k:1');

    $otherId = DB::table('notification')->where('recipient_id', $other->id)->value('id');

    // 我尝试标记别人的
    post(route('admin.alerts.read', $otherId))->assertStatus(404);

    expect(DB::table('notification')->where('id', $otherId)->value('read_at'))->toBeNull();
});

it('收件箱：全部标记已读', function () {
    $admin = $this->loginAsAdmin();

    $alerts = app(AdminAlertService::class);
    $alerts->alert('t', 'A', '', 'k:1');
    $alerts->alert('t', 'B', '', 'k:2');

    post(route('admin.alerts.read-all'))->assertRedirect();

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->whereNull('read_at')
        ->count())->toBe(0);
});

it('收件箱：列表只显示自己的告警', function () {
    $me = $this->loginAsAdmin();

    $other = \Webkul\User\Models\Admin::factory()->create(['role_id' => 1]);

    DB::table('notification')->insert([
        'recipient_type' => 'admin',
        'recipient_id'   => $other->id,
        'type'           => 't',
        'title'          => '这是别人的告警标题',
        'content'        => 'x',
        'channel'        => 'inapp',
        'sent_at'        => now(),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    get(route('admin.alerts.index'))
        ->assertOk()
        ->assertDontSeeText('这是别人的告警标题');
});

it('结构断言：告警没有删除路由（系统产出的事实不该被抹掉）', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->methods()[0].' '.$r->uri())
        ->filter(fn ($r) => str_contains($r, 'admin/alerts'));

    expect($routes)->not->toBeEmpty()
        ->and($routes->filter(fn ($r) => str_contains($r, 'DELETE')))->toBeEmpty();
});

it('调度：三个 ops 任务已注册进 schedule', function () {
    // ⚠ 不用 app(Schedule::class) 断言 —— 测试里解析到的 Schedule 未必是
    //   bootstrap 时 withSchedule 配过的那一份。直接看 schedule:list 的真实输出，
    //   那才是运维实际会看到的东西。
    artisan('schedule:list')
        ->expectsOutputToContain('ops:alert-compliance-due')
        ->expectsOutputToContain('ops:escalate-overdue-tickets')
        ->expectsOutputToContain('ops:alert-open-incidents')
        ->assertSuccessful();
});
