{{--
    实名引导弹窗（S-12d）—— **纯 CSS `:target`**，不引 JS 框架（门户现状零 JS，为弹窗引 JS 不划算）。

    用法（两处复用：skills/index 与 dashboard）：
      · 未实名时，把「创建技能」按钮的 href 指向 `#realname-modal` —— 点击即打开本弹窗、**不跳创建页**；
      · 「去实名」按钮指向 `shop.dev.realname`（实名提交页）；
      · 关闭：href 指向 `#_`（清掉 fragment，`#_` 不匹配任何 id）。

    ⚠ **这只是引导，不是闸门**：服务端拦截在 `DeveloperSkillService::canCreateSkill()`，
      绕开前端直接 POST 建技能照样被拒（有测试锁着）。
--}}
<div id="realname-modal" class="rn-modal">
    <a href="#_" class="rn-modal-backdrop" aria-label="关闭"></a>
    <div class="rn-modal-box" role="dialog" aria-modal="true" aria-labelledby="realname-modal-title">
        <h2 id="realname-modal-title">需要先完成实名认证</h2>
        <p>
            创建技能前必须完成<strong>实名认证</strong>，它分两层：
        </p>
        <ul>
            <li><strong>公司认证</strong>：公司主体名 + 统一社会信用代码 + 营业执照附件</li>
            <li><strong>操作人认证</strong>：操作人姓名 + 证件号</li>
        </ul>
        <p>
            两层都由<strong>平台人工审核</strong>，通过后才能创建技能。
        </p>
        <p class="muted">弹窗只是引导；服务端同样会拦截未实名的建技能请求。</p>
        <div style="margin-top:16px;display:flex;gap:10px">
            <a class="btn" href="{{ route('shop.dev.realname') }}">去实名</a>
            <a class="btn ghost" href="#_">我知道了</a>
        </div>
    </div>
</div>
<style>
    /* :target 命中（URL fragment = #realname-modal）才显示 —— 零 JS */
    .rn-modal { display: none; }
    .rn-modal:target { display: block; }
    .rn-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .5); display: block; z-index: 40; }
    .rn-modal-box {
        position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
        width: min(540px, 92vw); z-index: 41;
        background: #fff; border-radius: 12px; padding: 22px 24px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, .3);
    }
    .rn-modal-box h2 { margin: 0 0 10px; font-size: 18px; }
    .rn-modal-box p { font-size: 14px; line-height: 1.8; margin: 0 0 8px; }
    .rn-modal-box ul { margin: 0 0 8px; padding-left: 20px; font-size: 14px; line-height: 1.9; }
</style>
