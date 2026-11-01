@extends('shop::merchant.layouts.app')

@section('title', '开通商家店铺')

@section('content')
<div style="max-width: 760px; margin: 28px auto;">
    <!-- 顶部入驻步骤条 -->
    <div class="stepper" style="margin-bottom: 20px;">
        <div class="step-item is-done">
            <span class="step-dot">✓</span>
            <span>1. 供应商资质核验通过</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item is-active">
            <span class="step-dot">2</span>
            <span>2. 配置商户主体与首家店铺</span>
        </div>
        <div class="step-divider"></div>
        <div class="step-item">
            <span class="step-dot">3</span>
            <span>3. 分配独立租户并进入工作台</span>
        </div>
    </div>

    <div class="card" style="padding: 32px 36px;">
        <div style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--border-subtle);">
            <span class="badge badge-blue" style="margin-bottom: 8px;">Merchant Onboarding</span>
            <h1 style="font-size: 21px; margin: 0 0 6px; font-weight: 700;">开通商家主体与首家店铺</h1>
            <p style="margin: 0; font-size: 13px; color: var(--text-secondary);">
                完成入驻后系统将自动分配多租户供给节点（<code>seller_id</code>）与默认主仓，商品、库存、订单与结算严格按租户隔离。
            </p>
        </div>

        <form method="POST" action="{{ route('shop.merchant.onboarding.store') }}">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="company_name">企业/商户主体全称</label>
                    <input id="company_name" type="text" name="company_name" value="{{ old('company_name', $supplierApp->company_name ?? '') }}" required placeholder="例如：深圳智元机甲科技有限公司">
                </div>
                <div class="field">
                    <label for="shop_name">首家经营店铺名称</label>
                    <input id="shop_name" type="text" name="shop_name" value="{{ old('shop_name', $supplierApp->company_name ?? '') }}" required placeholder="例如：智元机器人官方旗舰店">
                </div>
                <div class="field">
                    <label for="credit_code">统一社会信用代码（选填）</label>
                    <input id="credit_code" type="text" name="credit_code" value="{{ old('credit_code') }}" class="mono" placeholder="91440300XXXXXXXXXX">
                </div>
                <div class="field">
                    <label for="timezone">店铺核算与展示时区</label>
                    <select id="timezone" name="timezone">
                        <option value="Asia/Shanghai" selected>Asia/Shanghai (UTC+8 中国标准时间)</option>
                        <option value="UTC">UTC (标准世界时)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="contact_name">主理人 / 业务联系人</label>
                    <input id="contact_name" type="text" name="contact_name" value="{{ old('contact_name', $supplierApp->contact_name ?? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))) }}" required>
                </div>
                <div class="field">
                    <label for="contact_phone">联系电话</label>
                    <input id="contact_phone" type="text" name="contact_phone" value="{{ old('contact_phone', $supplierApp->contact_phone ?? ($customer->phone ?? '')) }}" class="mono" required>
                </div>
            </div>

            <div class="field" style="margin-top: 8px;">
                <label>主营业务范围（可多选）</label>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 4px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 10px; margin: 0; cursor: pointer;">
                        <input type="checkbox" name="business_types[]" value="sale_robot" checked style="margin-top: 3px;">
                        <div>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">机器人整机售卖</div>
                            <div style="font-size: 11.5px; color: var(--text-secondary); font-weight: 400;">人形/四足整机买断与渠道供货</div>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 10px; margin: 0; cursor: pointer;">
                        <input type="checkbox" name="business_types[]" value="rental_robot" checked style="margin-top: 3px;">
                        <div>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">机器人整机租赁</div>
                            <div style="font-size: 11.5px; color: var(--text-secondary); font-weight: 400;">日租/周租/月租、押金与验机定损</div>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 10px; margin: 0; cursor: pointer;">
                        <input type="checkbox" name="business_types[]" value="accessory" checked style="margin-top: 3px;">
                        <div>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">机甲配件/外设售卖</div>
                            <div style="font-size: 11.5px; color: var(--text-secondary); font-weight: 400;">关节模组、传感器与适配皮肤</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="field" style="margin-top: 16px; padding: 12px 14px; background: #F8FAFC; border: 1px solid var(--border-subtle); border-radius: 10px;">
                <label style="display: flex; align-items: flex-start; gap: 10px; font-weight: 500; margin: 0; cursor: pointer;">
                    <input type="checkbox" name="accept_agreement" value="1" required style="margin-top: 3px;">
                    <span style="font-size: 12.5px; color: var(--text-secondary); line-height: 1.55;">
                        我已阅读并同意《召唤机甲平台商家入驻及商品合规协议》与《机器人设备租赁履约与押金结算规范》，承诺所有上架商品与适配机型真实合规。
                    </span>
                </label>
            </div>

            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="submit" class="btn btn-primary" style="padding: 10px 26px;">
                    立即开通并进入商家后台 →
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
