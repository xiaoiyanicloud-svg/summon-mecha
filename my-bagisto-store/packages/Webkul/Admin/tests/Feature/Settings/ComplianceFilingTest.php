<?php

use Webkul\Shop\Models\ComplianceFiling;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * 合规备案台账（计划 §4.17）。
 *
 * 四个设计断言值得单独锁住：
 *   ① **没有删除入口** —— 能被抹掉的台账不是台账
 *   ② 「即将到期」只对**已备案**的记录报 —— 否则提醒永远响，就没人看了
 *   ③ 附件只收文字/链接，不做文件上传（证件类材料需要访问控制方案）
 *   ④ **没有备案号的记录不许标成「已备案」** —— 状态是结论，结论要先过"号存在"这道门。
 *      这条此前是缺的：一次 PUT 就能造出"已备案但不知道备案号"的记录，
 *      而到期提醒、合规判断全都读这个字段。
 */

function cfFiling(array $overrides = []): ComplianceFiling
{
    return ComplianceFiling::create(array_merge([
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => '测试备案',
        'status'      => ComplianceFiling::STATUS_PENDING,
    ], $overrides));
}

it('备案台账列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.settings.compliance.index'))
        ->assertOk()
        ->assertSeeText('备案台账');
});

it('登记备案：附件按「一行一条」入库', function () {
    $this->loginAsAdmin();

    post(route('admin.settings.compliance.store'), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => '门户站点备案',
        'filing_no'   => '粤ICP备12345678号-1',
        'authority'   => '广东省通信管理局',
        'status'      => ComplianceFiling::STATUS_FILED,
        'attachments' => "回执见共享盘 /legal/icp.pdf\n内部工单 T-1001",
    ])->assertRedirect(route('admin.settings.compliance.index'));

    $filing = ComplianceFiling::where('name', '门户站点备案')->first();

    expect($filing)->not->toBeNull()
        ->and($filing->attachments)->toBe(['回执见共享盘 /legal/icp.pdf', '内部工单 T-1001'])
        ->and($filing->filing_no)->toBe('粤ICP备12345678号-1');
});

it('登记备案：备案号留空是合法的（拿到号之前本来就没有）', function () {
    $this->loginAsAdmin();

    post(route('admin.settings.compliance.store'), [
        'filing_type' => ComplianceFiling::TYPE_EDI,
        'name'        => 'EDI 许可（办理中）',
        'status'      => ComplianceFiling::STATUS_SUBMITTED,
    ])->assertRedirect();

    expect(ComplianceFiling::where('name', 'EDI 许可（办理中）')->value('filing_no'))->toBeNull();
});

it('登记备案：类型与状态都受白名单限制', function () {
    $this->loginAsAdmin();

    post(route('admin.settings.compliance.store'), [
        'filing_type' => 'made_up_type',
        'name'        => 'x',
        'status'      => ComplianceFiling::STATUS_FILED,
    ])->assertSessionHasErrors('filing_type');

    post(route('admin.settings.compliance.store'), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => 'x',
        'status'      => 'made_up_status',
    ])->assertSessionHasErrors('status');
});

it('登记备案：状态改成「已备案」却不给备案号 → 拒绝（没有号的"已备案"不是已备案）', function () {
    $this->loginAsAdmin();

    post(route('admin.settings.compliance.store'), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => '没有号却说已备案',
        'status'      => ComplianceFiling::STATUS_FILED,
    ])->assertSessionHasErrors('filing_no');

    expect(ComplianceFiling::where('name', '没有号却说已备案')->exists())->toBeFalse();
});

it('编辑备案：不许把一条无号记录改成「已备案」', function () {
    // 先建一条"审批中、还没拿到号"的记录 —— 这是正常状态
    $filing = cfFiling([
        'name'   => 'EDI 许可（办理中）',
        'status' => ComplianceFiling::STATUS_SUBMITTED,
    ]);

    $this->loginAsAdmin();

    // 直接改状态为已备案，但不填号 —— 这正是此前那道缺口
    put(route('admin.settings.compliance.update', $filing->id), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => $filing->name,
        'status'      => ComplianceFiling::STATUS_FILED,
    ])->assertSessionHasErrors('filing_no');

    // 状态不许被改过去
    expect($filing->fresh()->status)->toBe(ComplianceFiling::STATUS_SUBMITTED)
        ->and($filing->fresh()->filing_no)->toBeNull();
});

it('编辑备案：可改状态与备案号', function () {
    $filing = cfFiling();

    $this->loginAsAdmin();

    put(route('admin.settings.compliance.update', $filing->id), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => $filing->name,
        'filing_no'   => '粤ICP备99999999号-1',
        'status'      => ComplianceFiling::STATUS_FILED,
    ])->assertRedirect(route('admin.settings.compliance.index'));

    expect($filing->fresh()->status)->toBe(ComplianceFiling::STATUS_FILED)
        ->and($filing->fresh()->filing_no)->toBe('粤ICP备99999999号-1');
});

it('编辑动作留痕到 operation_log', function () {
    $filing = cfFiling();

    $admin = $this->loginAsAdmin();

    put(route('admin.settings.compliance.update', $filing->id), [
        'filing_type' => ComplianceFiling::TYPE_ICP,
        'name'        => $filing->name,
        'filing_no'   => '粤ICP备88888888号-1',
        'status'      => ComplianceFiling::STATUS_FILED,
    ])->assertRedirect();

    expect(DB::table('operation_log')
        ->where('action', 'compliance.filing.update')
        ->where('target_id', $filing->id)
        ->where('actor_id', $admin->id)
        ->exists())->toBeTrue();
});

it('结构断言：台账没有删除路由', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($r) => $r->methods()[0].' '.$r->uri())
        ->filter(fn ($r) => str_contains($r, 'settings/compliance'));

    expect($routes)->not->toBeEmpty()
        ->and($routes->filter(fn ($r) => str_contains($r, 'DELETE')))->toBeEmpty();
});

it('到期判定：只有「已备案」且临近的记录才算即将到期', function () {
    // 已备案 + 30 天后年检 → 即将到期
    $soon = cfFiling([
        'status'           => ComplianceFiling::STATUS_FILED,
        'annual_review_at' => now()->addDays(30),
    ]);

    // 已备案 + 一年后到期 → 不急
    $far = cfFiling([
        'status'     => ComplianceFiling::STATUS_FILED,
        'expires_at' => now()->addYear(),
    ]);

    // 已备案 + 已过期 → 也要报（过期同样是"需要动作"）
    $overdue = cfFiling([
        'status'     => ComplianceFiling::STATUS_FILED,
        'expires_at' => now()->subDays(5),
    ]);

    // 还没提交的 → 不报（还没到期一说，报了会让提醒永远响）
    $pending = cfFiling([
        'status'           => ComplianceFiling::STATUS_PENDING,
        'annual_review_at' => now()->addDays(10),
    ]);

    expect($soon->isExpiringSoon())->toBeTrue()
        ->and($far->isExpiringSoon())->toBeFalse()
        ->and($overdue->isExpiringSoon())->toBeTrue()
        ->and($pending->isExpiringSoon())->toBeFalse();
});

it('到期判定：年检优先于到期（年检没做，到期前就可能失效）', function () {
    $filing = cfFiling([
        'status'           => ComplianceFiling::STATUS_FILED,
        'annual_review_at' => now()->addDays(20),
        'expires_at'       => now()->addYear(),
    ]);

    expect($filing->nextDueAt()->toDateString())->toBe(now()->addDays(20)->toDateString());
});

it('没有日期的备案不会被评为即将到期', function () {
    $filing = cfFiling(['status' => ComplianceFiling::STATUS_FILED]);

    expect($filing->nextDueAt())->toBeNull()
        ->and($filing->isExpiringSoon())->toBeFalse();
});
