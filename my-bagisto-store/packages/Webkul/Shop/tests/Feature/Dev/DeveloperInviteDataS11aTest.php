<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\DeveloperInviteUsage;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

/**
 * S-11a · 开发者邀请码（数据层）。
 *
 * 只测数据层四件事：**码形态** / **失效原因分四种** / **claim 的原子性** / **过期拦截**。
 * 不涉及 Admin 路由（S-11b）、门户 dev/join（S-11c）、register() 衔接（S-11d）。
 *
 * 原子性用例**真打到数据库**：claim() 的成败由条件 UPDATE 的 affected 行数决定，
 * 断言一律重新 `DB::table(...)` 回查（不读内存里的模型对象）。
 */

function s11aService(): DeveloperInviteService
{
    return new DeveloperInviteService();
}

it('生成的码：10 位、字符集不含 0/O/1/I/L、两次不重复', function () {
    $svc = s11aService();

    $codes = [];
    $forbidden = ['0', 'O', '1', 'I', 'L'];

    for ($i = 0; $i < 20; $i++) {
        $invite = $svc->generate(null, 'test');
        $codes[] = $invite->code;

        expect($invite->code)->toHaveLength(10);

        foreach ($forbidden as $ch) {
            expect(str_contains($invite->code, $ch))->toBeFalse();
        }

        // 只应取自去混淆字符集
        foreach (str_split($invite->code) as $ch) {
            expect(str_contains(DeveloperInviteService::CODE_ALPHABET, $ch))->toBeTrue();
        }
    }

    // 20 个码两两不重复
    expect(array_unique($codes))->toHaveCount(20);
});

it('explainUnredeemable：四种失效给四种不同的人话文案', function () {
    $svc = s11aService();

    // 1) 不存在
    $msgMissing = $svc->explainUnredeemable('NOSUCHCODE');

    // 2) 已作废
    $revoked = $svc->generate(null, 'revoked');
    DB::table('developer_invite')->where('id', $revoked->id)->update(['status' => DeveloperInvite::STATUS_REVOKED]);
    $msgRevoked = $svc->explainUnredeemable($revoked->code);

    // 3) 已过期
    $expired = $svc->generate(null, 'expired', 1, now()->subDay());
    $msgExpired = $svc->explainUnredeemable($expired->code);

    // 4) 已用完
    $exhausted = $svc->generate(null, 'exhausted', 1);
    $svc->claim($exhausted, 999101, '10.0.0.1', 'UA');
    $msgExhausted = $svc->explainUnredeemable($exhausted->code);

    // 四条都非空
    foreach ([$msgMissing, $msgRevoked, $msgExpired, $msgExhausted] as $m) {
        expect($m)->not->toBe('');
    }

    // 四种原因 → 四种**不同**文案（不许笼统说"无效"）
    expect(array_unique([$msgMissing, $msgRevoked, $msgExpired, $msgExhausted]))->toHaveCount(4);

    // 且不该出现全靠"无效"两个字的笼统说法
    expect($msgMissing)->toContain('不存在');
    expect($msgRevoked)->toContain('作废');
    expect($msgExpired)->toContain('过期');
    expect($msgExhausted)->toContain('用完');
});

it('claim 原子性：同一码连续两次 claim → 第一次 true / 第二次 false，times_used 只 +1、流水只有一行', function () {
    $svc = s11aService();
    $invite = $svc->generate(null, 'atomic', 1);
    $customerId = 999201;

    $first = $svc->claim($invite, $customerId, '10.0.0.2', 'UA-1');
    $second = $svc->claim($invite, $customerId, '10.0.0.2', 'UA-1');

    expect($first)->toBeTrue();
    expect($second)->toBeFalse();

    // 回查数据库（不是内存对象）
    $timesUsed = (int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used');
    $usageRows = DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count();

    expect($timesUsed)->toBe(1);
    expect($usageRows)->toBe(1);
});

it('max_uses=2：可领两次（两位不同开发者），第三次 false', function () {
    $svc = s11aService();
    $invite = $svc->generate(null, 'multi', 2);

    $r1 = $svc->claim($invite, 999301, '10.0.0.3', 'UA-A');
    $r2 = $svc->claim($invite, 999302, '10.0.0.4', 'UA-B');
    $r3 = $svc->claim($invite, 999303, '10.0.0.5', 'UA-C');

    expect($r1)->toBeTrue();
    expect($r2)->toBeTrue();
    expect($r3)->toBeFalse();

    expect((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(2);
    expect(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(2);

    // 领满后再看 findRedeemable → 不再可领
    expect($svc->findRedeemable($invite->code))->toBeNull();
});

it('已过期的码：findRedeemable 返回 null 且 claim 失败', function () {
    $svc = s11aService();
    $invite = $svc->generate(null, 'expired-claim', 1, now()->subMinute());

    expect($svc->findRedeemable($invite->code))->toBeNull();

    $claimed = $svc->claim($invite, 999401, '10.0.0.6', 'UA-X');
    expect($claimed)->toBeFalse();

    // 失败时不留任何副作用
    expect((int) DB::table('developer_invite')->where('id', $invite->id)->value('times_used'))->toBe(0);
    expect(DB::table('developer_invite_usage')->where('invite_id', $invite->id)->count())->toBe(0);
});

it('可领的码：findRedeemable 命中、explainUnredeemable 返回空串', function () {
    $svc = s11aService();
    $invite = $svc->generate(null, 'ok', 1, now()->addDay());

    $found = $svc->findRedeemable($invite->code);
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($invite->id);

    expect($svc->explainUnredeemable($invite->code))->toBe('');
});

it('模型与表映射正确（developer_invite / developer_invite_usage）', function () {
    expect((new DeveloperInvite)->getTable())->toBe('developer_invite');
    expect((new DeveloperInviteUsage)->getTable())->toBe('developer_invite_usage');

    $svc = s11aService();
    $invite = $svc->generate(null, 'rel', 1);
    $svc->claim($invite, 999501, '10.0.0.9', 'UA-R');

    expect($invite->fresh()->usages()->count())->toBe(1);
});
