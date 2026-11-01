@extends('shop::merchant.layouts.app')

@section('title', '商家登录')

@section('content')
<div style="max-width: 880px; margin: 40px auto;">
    <div class="card card-flush" style="display: grid; grid-template-columns: 1.05fr 1fr; overflow: hidden;">
        <!-- 左侧品牌与能力背书面板 -->
        <div style="background: linear-gradient(155deg, #0B1528 0%, #0F296B 60%, #165DFF 100%); color: #FFFFFF; padding: 40px 36px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: inline-flex; align-items: center; gap: 8px; padding: 5px 12px; border-radius: 999px; background: rgba(255, 255, 255, 0.12); font-size: 11.5px; font-weight: 600; letter-spacing: 0.03em; margin-bottom: 18px;">
                    <span>SUMMON MECHA · MERCHANT SAAS</span>
                </div>
                <h2 style="font-size: 24px; font-weight: 700; margin: 0 0 12px; line-height: 1.3; letter-spacing: -0.02em;">
                    多租户机器人商业供给与租赁履约中台
                </h2>
                <p style="font-size: 13.5px; color: rgba(255, 255, 255, 0.78); margin: 0 0 28px; line-height: 1.6;">
                    面向本体厂商、区域租赁运营商与机甲配件供应商的统一经营工作台。
                </p>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,0.16); display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; margin-top: 2px;">✓</div>
                        <div>
                            <div style="font-size: 13.5px; font-weight: 600;">SPU + 多规格 SKU 与租期阶梯方案</div>
                            <div style="font-size: 12px; color: rgba(255,255,255,0.68);">支持整机售卖、整机租赁、配件适配机型矩阵</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,0.16); display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; margin-top: 2px;">✓</div>
                        <div>
                            <div style="font-size: 13.5px; font-weight: 600;">设备 SN 出库、归还验机定损与维保解封</div>
                            <div style="font-size: 12px; color: rgba(255,255,255,0.68);">售卖库存与可租库存双轨隔离，乐观锁防超卖</div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,0.16); display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; margin-top: 2px;">✓</div>
                        <div>
                            <div style="font-size: 13.5px; font-weight: 600;">T+N 结算对账单、增值税开票与 RBAC 审计</div>
                            <div style="font-size: 12px; color: rgba(255,255,255,0.68);">全链路租户级隔离（<code>merchant_id</code>）与操作留痕</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="padding-top: 24px; border-top: 1px solid rgba(255, 255, 255, 0.14); display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: rgba(255, 255, 255, 0.75);">
                <span>需要发布机甲技能包？</span>
                <a href="/dev/login" style="color: #FFFFFF; font-weight: 600; text-decoration: underline;">切换至开发者技能后台 ↗</a>
            </div>
        </div>

        <!-- 右侧登录表单 -->
        <div style="padding: 44px 40px; display: flex; flex-direction: column; justify-content: center; background: #FFFFFF;">
            <div style="margin-bottom: 26px;">
                <span class="badge badge-blue" style="margin-bottom: 10px;">Merchant Portal</span>
                <h1 style="font-size: 22px; margin: 0 0 6px; font-weight: 700; color: var(--text-primary);">登录商家后台</h1>
                <p style="margin: 0; font-size: 13px; color: var(--text-secondary);">
                    请使用已通过供应商资质核验或店铺成员授权的账号登录。
                </p>
            </div>

            <form method="POST" action="{{ route('shop.merchant.login.store') }}">
                @csrf
                <div class="field">
                    <label for="email">商户账号邮箱</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="merchant@summon-mecha.com">
                </div>

                <div class="field" style="margin-top: 16px;">
                    <label for="password">登录密码</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="请输入登录密码">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 22px; padding: 11px 16px; font-size: 14px;">
                    进入商家工作台 →
                </button>
            </form>

            <div style="margin-top: 24px; padding: 12px 14px; border-radius: 10px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); font-size: 12px; color: var(--text-secondary); line-height: 1.55;">
                <strong style="color: var(--text-primary);">准入说明：</strong>尚未开通商家权限的账号，可在移动端「我的 → 合作申请 / 供应商邀请」提交资质或核验受邀码后自动开通。
            </div>
        </div>
    </div>
</div>
@endsection
