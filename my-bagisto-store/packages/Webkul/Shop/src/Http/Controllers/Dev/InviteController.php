<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Services\Developer\DeveloperInviteService;

/**
 * 开发者门户 · 被邀请者落地页（S-11c）。
 *
 * 定位（拍板）：入驻是双通道（自助申请 + 邀请）；**邀请码不是准入闸门**，
 * 只提供「落地路由 + 归属 + 来源留痕」。准入闸门是实名 + 协议。
 *
 * ⚠ 本控制器**只读码 + 写 session**，绝不在这里 claim：
 *   领取（times_used +1、落 usage 流水）必须发生在建 developer_profile 的那一刻，
 *   否则被邀请人每刷新一次落地页就烧掉一个码。衔接逻辑属 S-11d。
 *
 * ⚠ 失效原因四种分开说（不存在 / 作废 / 过期 / 用完），文案唯一来源是
 *   DeveloperInviteService::explainUnredeemable()，本控制器不自己拼。
 */
class InviteController extends Controller
{
    /**
     * 邀请码在会话里的键名。S-11d 注册/入驻衔接与 S-11e 进度卡都读它。
     */
    public const SESSION_KEY = 'dev_invite_code';

    public function __construct(protected DeveloperInviteService $invites) {}

    public function join(Request $request)
    {
        $code = trim((string) $request->query('code', ''));

        // 空码不是错误页：链接可能被聊天软件截断，这里说清缺什么、下一步找谁。
        if ($code === '') {
            return response()->view('shop::dev.invite.landing', [
                'state'   => 'missing',
                'code'    => null,
                'message' => '邀请链接里缺少邀请码，请向邀请人重新索取。',
            ]);
        }

        $invite = $this->invites->findRedeemable($code);

        if (! $invite) {
            // 只读解释，不改任何状态；已有会话里的有效码**不清除**
            //（他可能刚打开过另一个有效链接，死链接不该把它弄丢）。
            return response()->view('shop::dev.invite.landing', [
                'state'   => 'invalid',
                'code'    => $code,
                'message' => $this->invites->explainUnredeemable($code),
            ]);
        }

        // 只把码带进会话 —— 领取留给建 profile 那一刻（S-11d）。
        $request->session()->put(self::SESSION_KEY, $invite->code);

        // 已登录开发者：码已带住，直接去入驻页（已入驻的会由入驻页自行放行到技能页）。
        if (auth('developer')->check()) {
            return redirect()->route('shop.dev.onboarding');
        }

        return response()->view('shop::dev.invite.landing', [
            'state'   => 'ok',
            'code'    => $invite->code,
            'message' => null,
        ]);
    }
}
