@extends('shop::merchant.layouts.app')

@section('title', '暂无商家后台访问权限')

@section('content')
<div style="max-width: 560px; margin: 56px auto;">
    <div class="card" style="padding: 40px 36px; text-align: center;">
        <div style="width: 56px; height: 56px; border-radius: 16px; background: var(--warning-bg); color: var(--warning); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 700; margin-bottom: 18px; border: 1px solid #FDE68A;">
            🔒
        </div>
        <div style="margin-bottom: 8px;">
            <span class="badge badge-amber">Access Restricted · 租户准入守卫</span>
        </div>
        <h1 style="font-size: 20px; margin: 0 0 10px; font-weight: 700; color: var(--text-primary);">暂无商家后台访问权限</h1>
        <p style="color: var(--text-secondary); font-size: 13.5px; margin: 0 0 24px; line-height: 1.6;">
            {{ $reason ?? '当前账号尚未开通商家权限，请先完成供应商资质审核或联系店铺管理员添加成员授权。' }}
        </p>

        <div style="text-align: left; padding: 14px 16px; border-radius: 10px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); margin-bottom: 24px; font-size: 12.5px; color: var(--text-secondary);">
            <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">如何获取商家后台权限？</div>
            <div style="margin-bottom: 4px;">1. <strong>企业入驻：</strong>在召唤机甲端内「我的 → 合作申请 / 供应商邀请」提交营业执照与供货方向；</div>
            <div>2. <strong>成员受邀：</strong>联系贵司已入驻店铺的管理员（Owner），在「设置与 RBAC → 店铺成员」中将您的邮箱加入团队。</div>
        </div>

        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="/dev" class="btn btn-secondary">前往开发者技能后台 ↗</a>
            <form method="POST" action="{{ route('shop.merchant.logout') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-primary">切换账号登录</button>
            </form>
        </div>
    </div>
</div>
@endsection
