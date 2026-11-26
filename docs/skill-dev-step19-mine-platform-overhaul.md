# Step 19 · 「我的」账户中心与 ToC/ToB 全栈平台化升级交付证据

- **日期**：2026-11-27
- **备份快照**：`d:\CodexDeploy\summon-mecha\.claude\backup\mine-platform-overhaul-20261126\`
- **架构与审计蓝图**：`mine_platform_audit_and_plan.md`

---

## 一、核心交付与架构升级总览

### 1. 全局视觉规范重构（工业科技蓝白 · 去 AI 化 · 人类友好）
- **色彩与排版体系**：在 `App.vue` 建立全局工业蓝白设计变量（`--sm-navy: #102F6E`、`--sm-blue: #165DFF`、`--sm-bg: #F4F7FB`、`--sm-border: #E5EAF2`）与等宽数字字体 `.sm-mono`，彻底清除全量「我的」模块中的淘宝橙（`#ff6b00`）、彩虹渐变徽章、全大写英文 AI 口号横幅及彩色系统 Emoji，全部统一为克制清晰的 SVG 线框语义图标（`components/SmIcon.vue`）。
- **导航与深链防卡死**：重构 `components/NavBar.vue`，补齐刘海屏/小程序胶囊状态栏安全距离（`statusBarPx` + `env(safe-area-inset-top)`）、右侧操作插槽（`#right`），以及刷新/深链直达（`getCurrentPages().length <= 1`）自动回退 `/pages/mine/index` 保护。

### 2. 「我的」主工作台拆分与独立路由架构（6,056 行大泥球解耦）
- 将原本单文件 6,056 行、把全部二级业务塞入弹窗的 `pages/mine/index.vue` 重构为 **772 行五层平台驾驶舱**：
  1. **身份与企业档案头部**：展示姓名、所属企业/职务、已激活的多重认证身份徽章（个人账户 / 企业客户 / 签约商家 / 技能开发者），右上角直达消息中心（未读红点）与系统设置。
  2. **四大核心数字与物理资产驾驶舱**：设备管理（`device_binding` 在线数/总数）、已购技能（买断授权与待指派提示）、定制需求（对接 `pages/scene/requests` 进行中单数）、发票与抬头（已开具/处理中）。
  3. **五态履约订单与售后工作栏**：待付款、待收货/履约、已完成、退换/售后、全部订单（含实时角标）。
  4. **角色自适应 ToB 工作台枢纽**：已认证商家/开发者直显经营指标与跨端工作台 SSO 入口；普通客户展示克制清晰的「供应链入驻 · 技能开发者 · 商务合作」入口。
  5. **2×4 常用工具九宫格**：找回此前遗失的「收货地址」独立入口，统一整合下发任务历史、赛事报名、我的收藏、平台客服、安全事故上报、商务合作与系统设置。
- 在 `pages.json` 新增 **7 个独立页面路由**：
  - `pages/mine/profile`（个人与企业档案、登录改密）
  - `pages/mine/address`（收货地址簿 CRUD 与默认地址切换）
  - `pages/mine/invoices`（可开票订单勾选、增值税普票/专票四要素校验、常用抬头簿 CRUD、开票记录与电子票下载）
  - `pages/mine/cooperate`（本体厂商 / RaaS 合伙人 / 达人创作者合作入驻、私密盘营业执照图片上传、跟进时间轴与待审撤回）
  - `pages/mine/supplier`（供应链商家专属邀请码实时核验入驻 & 无码资质申请获批签发全链路）
  - `pages/mine/portal-hub`（ToB 多后台注册中心：移动端实时经营快照 + 60 秒单次有效免登票据直跳 `/merchant/sso` 与 `/dev/sso`）
  - `pages/mine/settings`（合规协议、缓存清理、关于平台与《个保法》PIPL 账号注销前置核查申请）

### 3. 二/三/四级子模块与跨端闭环补齐
- **账户与安全闭环（`CustomerController`）**：新增 `GET /api/customer/overview`、`PUT /api/customer/profile`、`PUT /api/customer/password`（改密自动吊销其它旧设备 Token）、`POST /api/customer/forgot-password` & `reset-password`（6 位防枚举邮箱验证码 + 5 次防爆破锁定 + Broker 兼容）、`POST /api/customer/deletion-request`（拦截未完结订单与在租履约单，无阻断时落审计日志与客服核销工单）。
- **ToB 增值税专票与常用抬头簿（`InvoiceController` + `CustomerInvoiceHeader`）**：补齐企业普票/专票（税号 + 开户行 + 账号 + 注册地址 + 注册电话四要素强校验）及抬头簿增删改查，开票时可选自动沉淀至常用抬头簿。
- **全品类售后维保与 D10 申诉闭环（`AfterSaleController`）**：打通买断技能退款（7 天 / 3 次激活防线 + D10 驳回后二次复核申诉）与整机租售/配件订单退换报修（`POST /api/after-sales/orders`）及待审撤回（`POST /api/after-sales/{id}/cancel`）。
- **跨端单次免登交换票据（`PortalBackendRegistryService` + `/merchant/sso` + `/dev/sso`）**：签发 SHA-256 哈希落库、60 秒有效、单次原子消费（防重放）的免登 Handoff Ticket，解决 App/H5 Sanctum Bearer Token 进入 Web SSR 商家/开发者后台需二次输入密码的断层；顺手修复 `CatalogBrandService.php` 在 PHP 8 下 `method_exists(array, 'getCollection')` 导致的商家发布商品 `TypeError` 崩溃。
- **设备真相源、下发审计、客服订单卡与合规诚实公示**：
  - 设备登记/编辑接入 `GET /api/device-models` 认证机型目录选择，严守 D1 红线如实展示「未核验」与「Agent 已配对 · 在线/离线」；
  - 新增 `GET /api/deploy-tasks` 跨设备下发任务历史审计页（`pages/mine/deploy.vue`），技能页高危权限下发前强弹 D6 物理安全责任确认书；
  - 客服工单支持携带本人交易订单卡（`order_id` 归属强校验，越权返 422）与设备卡；
  - 事故详情补齐本人设备序列号与技能版本回显，严守保密红线绝不泄露 `trace` 与 `handling_log`；
  - 新增 `GET /api/cms/compliance-filings`，关于页动态读取真实生效备案，未取得资质时如实披露诚实说明，零虚构备案号。

---

## 二、变更文件清单

### 后端（Laravel / Bagisto `my-bagisto-store/`）
- **加性数据库迁移**：
  - `packages/Webkul/Shop/src/Database/Migrations/2026_11_26_000001_add_mine_platform_overhaul_columns_and_tables.php`
- **模型与配置**：
  - `packages/Webkul/Shop/src/Models/CustomerInvoiceHeader.php`（新增）
  - `packages/Webkul/Shop/src/Models/Invoice.php`
  - `packages/Webkul/Shop/src/Models/AfterSale.php`
  - `packages/Webkul/Shop/src/Models/CooperationApplication.php`
  - `config/portal_backends.php`
- **服务与 Web 控制器**：
  - `packages/Webkul/Shop/src/Services/Merchant/PortalBackendRegistryService.php`
  - `packages/Webkul/Shop/src/Services/Catalog/CatalogBrandService.php`
  - `packages/Webkul/Shop/src/Http/Controllers/Merchant/MerchantPortalController.php`
  - `packages/Webkul/Shop/src/Http/Controllers/Dev/PortalController.php`
- **API 控制器与路由**：
  - `packages/Webkul/Shop/src/Http/Controllers/API/{CustomerController,InvoiceController,AfterSaleController,NotificationController,DeviceController,DeployController,OrderController,IncidentController,CmsPageController,PortalBackendController,CooperationController,TicketController}.php`
  - `packages/Webkul/Shop/src/Routes/{api,merchant,dev}.php`
- **自动化验收测试**：
  - `packages/Webkul/Shop/tests/Feature/API/MinePlatformOverhaulTest.php`（新增）

### 前端（uni-app 根目录）
- **全局基础与路由**：`App.vue`、`pages.json`、`components/NavBar.vue`、`utils/api.js`
- **「我的」主入口与 7 个新增独立页**：`pages/mine/{index,profile,address,invoices,cooperate,supplier,portal-hub,settings}.vue`
- **升级打磨的 12 个子级与关联页**：`pages/mine/{devices,agent-setup,skills,deploy,after-sales,notifications,incidents,incident-detail,legal,about,tickets,ticket-detail}.vue`、`pages/order/{index,order-detail}.vue`、`pages/ecosystem/{my-registrations,my-favorites}.vue`

---

## 三、实测验证证据

### 1. 独立测试库（`summon_mecha_step10_test`）15 套件全量回归
执行命令：
```powershell
$env:DB_DATABASE="summon_mecha_step10_test"; $env:APP_ENV="testing"
cd d:\CodexDeploy\summon-mecha\my-bagisto-store
php vendor/bin/pest `
  packages/Webkul/Shop/tests/Feature/API/MinePlatformOverhaulTest.php `
  packages/Webkul/Shop/tests/Feature/PortalAndMerchantBackendTest.php `
  packages/Webkul/Shop/tests/Feature/Dev/DeveloperPortalTest.php `
  packages/Webkul/Shop/tests/Feature/API/CooperationApplicationTest.php `
  packages/Webkul/Shop/tests/Feature/API/CooperationSupplierInviteTest.php `
  packages/Webkul/Shop/tests/Feature/API/CustomerServiceCenterTest.php `
  packages/Webkul/Shop/tests/Feature/API/InvoiceApplyB15Test.php `
  packages/Webkul/Shop/tests/Feature/Billing/InvoiceIssueTest.php `
  packages/Webkul/Shop/tests/Feature/API/AfterSaleValidationA9Test.php `
  packages/Webkul/Shop/tests/Feature/API/DeviceNationalCodeB19Test.php `
  packages/Webkul/Shop/tests/Feature/API/DeviceUnbindBindingB4Test.php `
  packages/Webkul/Shop/tests/Feature/API/IncidentReportTest.php `
  packages/Webkul/Shop/tests/Feature/API/IncidentHandlingLogTest.php `
  packages/Webkul/Shop/tests/Feature/Merchant/MerchantOrderFulfillmentDeepTest.php `
  packages/Webkul/Shop/tests/Feature/Notification/LifecycleNotificationB2Test.php
```
实测结果：**92 passed, 0 failed (675 assertions, 10.58s)**。

### 2. 平台契约门禁、数据预检与 Blade 模板编译
执行命令：
```powershell
cd d:\CodexDeploy\summon-mecha\my-bagisto-store
php artisan ecosystem:contract-check
php artisan ecosystem:data-preflight
php artisan view:cache; php artisan view:clear
```
实测结果：
- `ecosystem:contract-check`：`admin_routes=113, public_routes=45, contracted_routes=45, error_codes=85, state_inventories=11` —— **契约检查通过**。
- `ecosystem:data-preflight`：**数据 preflight 通过：没有发现重复、孤儿、计数或状态问题**。
- `view:cache`：**Blade templates cached successfully**。

### 3. 前端 Vue SFC 语法编译、路由唯一性、去 AI 化红线扫描与无头浏览器 E2E
- **Vue SFC + Babel 全量编译与视觉红线审计**：编译 27 个 `.vue` 文件（含 `pages/mine/` 全部 20 页），校验 `pages.json` 全部 45 条路由与磁盘组件一一对应，扫描确认 **0 淘宝橙色值、0 AI 英文口号、0 模板彩色 Emoji**。
- **Node 契约与配置一致性单测**：`node --test tools/tests/config-consistency.test.mjs tools/tests/ecosystem-g4.test.mjs tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs` —— **41/41 passed**。
- **Playwright 无头 Chrome 渲染回归**：`node tools/tests/scene-workflow-ui.mjs` —— **`compiledSfc: 17, checks: 26, pageErrors: []`**。

---

## 四、诚实披露：外部依赖与未接入能力边界

根据 `CLAUDE.md` 诚实标定纪律，以下外部第三方能力在本轮**未伪造、未硬编码假数据**，保持清晰边界：
1. **微信支付真实商户收银台与原路退回网关**：订单待支付与退款仍按平台真实网关配置状态运行，未接入微信商户号证书前不伪造真实微信扣款。
2. **微信小程序手机号一键快捷登录**：需微信认证小程序 AppID/Secret 线上解密权限，当前提供邮箱密码登录、注册及 6 位邮箱验证码找回密码闭环。
3. **税局电子发票自动签章直连（航信/百望云）**：客户端提交普票/专票申请并落库后状态如实标为「待财务开具」，由后台上传真实税局 PDF 后开放下载，绝不前端合成假发票图片。
4. **厂商云端设备序列号（SN）出厂防伪核验**：无厂商私有云接口授权前，纯手填 SN 设备如实标定「未核验」，仅在真实设备端 Agent 完成配对心跳后显示「Agent 已配对 · 在线」。
5. **安全事故图片/视频直传对象存储**：需 COS/OSS 凭证与内容安全扫描（`msgSecCheck`）上线后方可放开，当前事故上报仅接收文字描述与外部取证链接。

---

## 五、回滚指引

如需回滚本批次改动：
1. **数据库回滚**：
   ```powershell
   cd d:\CodexDeploy\summon-mecha\my-bagisto-store
   php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_11_26_000001_add_mine_platform_overhaul_columns_and_tables.php
   ```
2. **代码快照恢复**：从备份目录 `d:\CodexDeploy\summon-mecha\.claude\backup\mine-platform-overhaul-20261126\` 按路径覆盖恢复修改前的控制器、路由与前端视图，并移除本轮新增的独立页与迁移文件（切勿执行 `git add -A` 或破坏性重置）。
