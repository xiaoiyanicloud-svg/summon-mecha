# 客服中心会话化改造

日期：2026-10-26

## 定位

本次改造不新增第二套客服数据模型，而是在既有 `support_ticket` / `ticket_message` 上把“异步工单”升级为可持续沟通的客服中心。工单仍是唯一真相源，SLA、事故升级、后台受理和操作留痕均沿用现有链路。

> **阅读提示（2026-10-26 更新）**：下面「已完成」记录的是上一版“会话化 + 在线客服”的实现。用户端界面已在同日后半段完成体验重构（去掉营销位、通道路由、在线人数与实现细节），以文末「用户端体验重构」为准；后端接口与后台工作台未变。

## 已完成

### 用户端

- `pages/mine/tickets.vue`：客服中心首页
  - 深色服务头图、问题分类、新会话输入、会话列表、状态标签、最近消息摘要、本地未读点。
  - 会话按 `updated_at` 排序，最后一条消息由后端返回。
- `pages/mine/ticket-detail.vue`：会话详情
  - 对话气泡、消息时间、日期分隔、快捷回复、固定输入区；在线会话 2 秒增量同步，留言工单 5 秒同步。
  - 已解决会话继续发送后自动重新打开；已关闭会话禁止继续发送。
  - 客服回复后用户返回列表可看到未读点。
- 机器人详情页“客服”入口不再只弹 Toast，登录后进入真实客服中心。
- 登录前明确提示，不伪造“在线人工”状态。

### 后端

新增用户追加消息接口：

```text
POST /api/tickets/{id}/messages
```

行为：

- 仅允许工单归属客户访问；跨用户请求返回 404。
- 内容必填，最长 2000 字；附件仍只接收链接，最多 5 个。
- `resolved` 会话收到用户消息后回到 `processing`，并续 24 小时 SLA。
- `closed` 会话返回 409，要求新建会话。
- 写入消息与状态变更处于同一事务。

列表接口补充 `last_message`、`status_label`、`category_label`、`updated_at`；详情补充 `can_reply`、`sla_due_at` 与消息时间。

### 后台

`Admin customers/tickets/show` 升级为客服工作台：

- 左侧对话流、消息计数、快捷回复、固定回复入口。
- 右侧客户信息、SLA、状态/优先级处置、事故定性。
- 原有路由、表单字段、状态机、操作日志与事故互链保持不变。

### 在线客服 MVP

本次新增真正的“在线客服”模式，未引入 WebSocket 或第三方客服平台，采用 uni-app 三端兼容的增量轮询。

数据层：

- `support_ticket.service_mode`：`async | online`，存量工单默认 `async`。
- `support_ticket` 新增 `queued_at / accepted_at / last_customer_message_at / last_staff_message_at / ended_at`。
- `ticket_message` 新增 `read_at`。
- 新增 `support_agent_state`：客服主动上线、最大并发、20 秒心跳，60 秒无心跳自动离线。

用户端：

- 检测到新鲜客服心跳时默认进入在线客服；客服离线时也允许提交在线咨询并进入队列，客服上线后接入。
- 在线咨询进入队列后显示“排队中”；客服认领后显示“已接入”和客服姓名。
- 新增 2 秒增量接口，只拉取 `after_message_id` 之后的新消息。
- 支持客服/系统消息已读、用户主动结束会话、离线同步失败提示。

后台：

- 客服工作台新增“上线接待/在线接待”状态、20 秒心跳和 60 秒失联保护。
- 在线会话采用事务锁认领，防止两名客服抢同一会话。
- 支持认领容量限制、释放回队列、后台 2 秒增量同步、用户消息已读。
- 在线回复使用 AJAX，不整页刷新；原表单路径保留为降级方案。
- 工作台脚本位于 `my-bagisto-store/packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js`，通过受后台鉴权的 `admin/customers/support/workbench.js` 路由加载。

在线接口：

```text
GET  /api/tickets/online-status
POST /api/tickets/online
GET  /api/tickets/{id}/updates
POST /api/tickets/{id}/read
POST /api/tickets/{id}/end
POST /admin/customers/support/presence
POST /admin/customers/tickets/{id}/claim
POST /admin/customers/tickets/{id}/release
GET  /admin/customers/tickets/{id}/updates
POST /admin/customers/tickets/{id}/read
```

### 数据修正

`ticket_message.created_at` 此前不在 `$fillable` 中，导致新消息时间落库为空。现已：

- 将 `created_at` 加入可填字段并做 datetime cast。
- 历史空时间在 API 与后台展示时回退到工单创建时间，避免时间轴空白。

## 验证

- 在线客服用户侧专项：`8 passed / 32 assertions`。
- 后台在线接待专项：`9 passed / 33 assertions`。
- 在线客服 + 既有售后 API 联合回归：`32 passed / 109 assertions`。
- `php artisan ecosystem:contract-check`：通过。
- `php artisan route:list --name=shop.api.tickets`：9 条路由符合预期。
- `php artisan view:cache`：Blade 编译通过。
- HBuilderX 5.07 / Vue 3 H5：编译成功。
- 本机 H5 实测：客服中心、会话页、已解决状态、追加消息、后台客服工作台均正常；浏览器控制台无 error/warning。
- 测试与视觉演练只使用独立库 `summon_mecha_step10_test`；演练数据已清理。

## 当前边界

- 在线同步采用 2 秒增量轮询，不是 WebSocket。
- 未接入微信客服、Intercom、Zendesk 等外部客服平台。
- 在线客服目前是单客服认领会话；转接 / 挂起 / 内部备注已在 2026-10-27 补齐（见文末第三轮），排班、质检仍未做。
- 客服心跳超过 60 秒会自动离线；重新打开工作台后需再次点击“上线接待”。
- 附件只支持链接，尚未开放对象存储文件上传。
- 客服回复后的系统推送/订阅消息仍未接入，用户通过轮询和会话未读点感知。
## 环境迁移记录

- 2026-10-26：root 主库已只执行本功能 migration `2026_10_26_000001_add_online_customer_service`（Batch 51）。
- 迁移前备份：`.claude/backup/online-support-20261026/root-support-ticket-before-online-migration.sql`。
- 迁移后只读验证：`support_ticket.service_mode / queued_at`、`ticket_message.read_at`、`support_agent_state` 均存在，DataGrid 同款查询可返回数据。

## 用户端体验重构（2026-10-26，当前版本）

### 目标与边界

把“带设计感的工单表单”改成“客服中心”：首屏直接给提问入口，再给会话记录与自助问题；不再让用户理解内部通道。

- 只改用户端页面，**未动数据库、未动 API、未改后台客服工作台**。
- 在线能力保留：有客服在线时静默走在线会话，否则走留言工单；用户端不再出现“在线客服 / 留言工单”二选一。
- 服务时间：每日 9:00–21:00；非服务时间承诺 24 小时内回复。
- **视觉与 AI 召唤页统一**：同一套蓝湖渐变背景、玻璃卡片、`#165DFF / #4291F6` 渐变主色、同款气泡与胶囊输入条；AI 召唤页新增人工客服入口。

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `pages/mine/tickets.vue` | 重写客服中心首屏：直接提问 → 我的会话 → 常见问题。去掉营销 hero、客服身份卡（首屏信息过多，按反馈移除）、通道选择、在线人数/排队数、英文装饰。新增问题类型选择、常见问题预填、未读数字、最后回复人摘要。视觉改为 AI 召唤页同款渐变 + 玻璃卡片 + 圆形渐变发送键。 |
| `pages/mine/ticket-detail.vue` | 重写会话页：顶部客服身份 + 问题分类 + 回复承诺；去掉 `SVC`、`2s 同步`、`0 位在线`、假“正在输入”动画；快捷话术按分类下发，客服回复后自动隐藏；气泡、输入条与 AI 召唤页保持一致。 |
| `pages/ai/index.vue` | 新增人工客服入口：空态卡片内“需要人工客服？”整行入口 + 输入区常驻“🎧 人工”圆形按钮，均跳转 `/pages/mine/tickets`；新增 `goHumanService` 与埋点 `ai_human_service_click`。 |
| `utils/customer-service.js` | 新增。列表页/会话页共用服务名称、服务时间、分类、状态、常见问题、快捷话术，避免两边说法不一致。 |
| `docs/customer-service-center-implementation.md` | 本节及截图证据。 |

### 实测证据（本机 H5，登录态）

- 客服中心首屏：`docs/customer-service-center-screenshots/tickets-home.png`
- 会话页（新建会话、快捷话术）：`docs/customer-service-center-screenshots/ticket-detail.png`
- 常见问题点击预填（分类同步切换为“设备问题”）：`docs/customer-service-center-screenshots/faq-prefill.png`
- 客服回复后的会话（客服名称、快捷话术隐藏）：`docs/customer-service-center-screenshots/ticket-staff-reply.png`
- AI 召唤页人工客服入口（卡片入口 + 输入区常驻入口）：`docs/customer-service-center-screenshots/ai-page-human-entry.png`

交互实测：

- 点击常见问题 → 输入框填入问题、分类自动切换，截图 `faq-prefill.png`。
- 点击发送 → 创建工单并跳转会话页：`#/pages/mine/ticket-detail?id=7`。
- 会话内追加消息 → 消息立即出现在对话流中。
- 客服回复 → 显示客服名称与白色气泡；快捷话术自动隐藏。
- AI 召唤页点击输入区“人工”按钮 → 跳转 `#/pages/mine/tickets`（未登录时显示登录引导）。
- 浏览器控制台无 error/warning（CDP 采集为空）。

测试数据已清理：探针工单 `6/7`、对应消息、探针客户 `14` 均已删除，复查计数为 0。

## 会话折叠（2026-10-27）

### 目标与边界

「我的会话」默认只露出**最新一条**（后端 `orderByDesc('updated_at')` 保证第一条即最新），其余折叠在「展开其余 N 条会话」胶囊后面，点一下才展开，再点收起。只改列表页展示，**未动 API、未动工单数据、未改会话页**。

- 折叠时若被折叠的会话里有未回复消息，胶囊上带未读数字，避免“藏起来的会话没人管”。
- 展开状态不持久化（离开页面再回来重新折叠），避免用户下次进来自以为只有一条会话。
- 只有 1 条会话时不渲染展开胶囊。

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `pages/mine/tickets.vue` | 新增 `expanded` 状态与 `visibleList / restItems / restUnread` 计算属性、`toggleExpanded` 方法；会话列表改为单个 `v-for` 包在 `<block>` 内，首条之后插入折叠/展开胶囊；新增 `.conv-toggle / .conv-toggle-text / .conv-toggle-caret(.open)` 样式。 |

### 实测证据（本机 H5，登录态，客户 id=6，4 条会话）

- 默认折叠：`docs/customer-service-center-screenshots/tickets-home-collapsed.png`（仅最新一条 + 「展开其余 3 条会话」）
- 点击展开：`docs/customer-service-center-screenshots/tickets-home-expanded.png`（「收起历史会话」+ 其余 3 条全部展示）

实测方式：`tools/shot.mjs` 同款 CDP 截图（临时脚本，已删除），注入临时 Sanctum token 后打开 `#/pages/mine/tickets`，用 `dispatchEvent(new MouseEvent('click'))` 点折叠胶囊再截第二张。临时 token 已删除，未留测试数据。

### 回滚

```bash
git checkout -- pages/mine/tickets.vue
```

### 已知观察（本次未改）

最新一条会话在「最后一条消息是客服发的、且本地没有已读记录」时会显示未读数 `1`（`unreadCount` 的本地兜底逻辑，服务端 `unread_count` 为 0 时生效）。这是折叠改动之前就有的行为，与本次无关；如需统一口径（例如服务端已读就以服务端为准），单独立项再改。

## 会话页顶部精简 + 输入条抬升（2026-10-27）

### 改动

用户反馈两点：① 会话页底部的输入条贴着屏幕底边；② 顶部那条「召 / 召唤机甲 / 处理中 / 分类 · 留言工单 · 24 小时内回复」信息条太占地方，只保留「人工服务」和「服务时间」。

| 文件 | 改动 |
| --- | --- |
| `pages/mine/ticket-detail.vue` | 顶部 `agent-bar`（头像 + 客服名 + 状态胶囊 + 分类/服务提示）换成一行 `service-bar`：左「人工服务 · 每日 9:00–21:00」，右侧仅保留在线会话的「结束」入口；删除随之无用的 `agentName / agentShort / serviceHint` 计算属性与 `statusLabel` 引用；`composer-panel` 底部内边距 `20rpx → 56rpx`（仍叠加 `env(safe-area-inset-bottom)`，真机刘海屏不受影响）。 |

取舍说明：

- 状态胶囊（待受理 / 处理中 / 已解决 / 已关闭）随信息条一起移除。已关闭与已解决仍分别由底部「本次会话已结束」面板、顶部欢迎语说明；进行中状态不再单独展示。如需保留，可加回 `service-bar` 右侧，一行即可。
- 分类仍显示在顶部标题下方的小胶囊里（`其他 / 支付·退款 / …`），未丢失。

### 实测证据

- 改后会话页：`docs/customer-service-center-screenshots/ticket-detail-slim-header.png`（H5 390×844，工单 76，`service-bar` 高 30px）
- 输入条位置来自 CDP `getBoundingClientRect` 实测：改后 `.input-area.bottom = 815`，视口高 844 → 距底 29px；改前 padding-bottom 为 `20rpx`（10px），改后 `56rpx`（28px）+ `env(safe-area-inset-bottom)`。

### 回滚

```bash
git checkout -- pages/mine/ticket-detail.vue
```

### 未做

- 微信小程序端未实测（仅 H5 实测）；样式改动为纯 CSS + 模板删减，未改数据与接口。



### 回滚

```bash
# 注意：tickets.vue / ticket-detail.vue 在本轮开始前就已有未提交改动，
# git checkout 会一并回退到 HEAD（连上一版“在线客服”前端一起丢失）。
# pages/ai/index.vue 本轮改动前是干净的，可单独回滚。
git checkout -- pages/mine/tickets.vue pages/mine/ticket-detail.vue pages/ai/index.vue
rm utils/customer-service.js
```

本轮完成后的文件已备份到 `.claude/backup/customer-service-ai-style-20261026/`（含 `user-side-after.patch`）；第一次重构的中间版本仍保留在 `.claude/backup/customer-service-redesign-20261026/`。

### 本轮未做（不得在对外表述中说已具备）

- **上下文卡片未做**：`/api/tickets/{id}` 目前不返回 `skill_id`/设备/订单信息；要做需先补只读字段。
- **图片/截图上传未做**：仍是链接附件，需要对象存储与内容安全方案。
- **FAQ 自助与 AI 首答未做**：常见问题只是前端预填，不是知识库，也不会自动回答。
- **满意度/结单回访未做**：没有评分与回访问卷。
- **订阅消息推送未做**：用户仍靠页面轮询与未读点感知回复。
- **后台客服工作台未改**：本次只改用户端。
- 服务时间写死在 `utils/customer-service.js`，如业务时间调整需同步修改。
## 后台客服工作台（2026-10-27）

### 目标

把“打开一张工单、在表单里回一句”的详情页，改成客服真正能连续接待的对话式工作台：

- 左侧同时看到 **排队中 / 我的接待 / 待处理留言**，带未读数和等待时长。
- 中间是当前会话，全高对话流，回复用 Enter 发送、Shift+Enter 换行。
- 右侧客户信息、在线接待、处置状态、事故定性不丢。
- 回复、状态、认领、释放全部 AJAX，不再整页刷新。

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php` | 新增 `workbench()`（自动跳到最该处理的会话）、`conversations()`（左侧三组会话 HTML）、`conversationSections()` / 摘要辅助方法；`show()` 传入会话分组、客户名、受理人姓名；`claim/release/updateStatus` 增加 JSON 分支（原有表单跳转保留）。 |
| `packages/Webkul/Admin/src/Routes/customers-routes.php` | 新增 `GET support/workbench`、`GET support/conversations`。 |
| `.../views/customers/tickets/show.blade.php` | 重写为三栏全高工作台：左会话队列、中对话流 + 固定输入区、右客户与处置；新增页面级自定义 CSS（后台 Tailwind 产物缺少颜色/按钮类）。 |
| `.../views/customers/tickets/partials/conversation-list.blade.php` | 新增。左侧会话列表片段，服务端渲染 + 轮询替换复用同一份 HTML。 |
| `.../views/customers/tickets/index.blade.php` | 新增“进入客服工作台”按钮，更新说明文案。 |
| `.../assets/js/online-support-workbench.js` | 重写：会话列表 3 秒轮询、未读标题、Enter 发送、输入框自动增高、自动滚底/新消息提示、状态/认领/释放 AJAX；常驻 20 秒在线心跳（页面加载后再点“上线接待”也不会 60 秒掉线）；**启动时机改为 `window.load` + 150ms**（Bagisto 在 load 时挂载 Vue 会整体替换节点）。 |
| `packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` | 新增 3 条用例：会话分组与未读数、认领/释放 JSON、处置状态 JSON。 |

### 实测证据

- 工作台截图：`docs/customer-service-center-screenshots/admin-workbench.png`
- 后台测试：`12 passed (50 assertions)`（`D:/php.exe vendor/bin/pest packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php`）
- `php -l` 控制器与路由通过；`node --check` 工作台脚本通过；`artisan view:cache` 编译通过；`artisan route:list --name=admin.customers.support` 返回 4 条路由。
- 浏览器实测（登录后台）：
  - 上线接待 → `在线接待`、`1 位在线`。
  - 快捷回复填入 → Enter 发送 → 消息立即追加，输入框清空，消息带当前客服名。
  - 处置状态改为“已解决” → 顶部徽标即时更新；改回“处理中”恢复“已接入”。
  - 排队会话点击“接入在线会话” → 工单 assignee_id 落到当前客服、状态 processing；释放后回到 open/unassigned。
  - `GET /admin/customers/support/workbench` 自动跳到最该处理的会话。
  - 左侧列表 3 秒轮询生效（改动 `document.title` 后会被未读数重新覆盖）。
- 已知无关报错：登录跳转过程中出现过一次 Bagisto 自带 `/admin/dashboard` 的 `app.component is not a function`，与本工作台页面无关，未处理。

测试数据已清理：探针工单 `59/60/61`、探针客户 `66`、探针客服 `55`、客服在线状态与相关操作日志均已删除，复查计数为 0。

### 两个必须写下来的坑

1. **后台 Tailwind 是预编译的**：`bg-gray-900`、`bg-emerald-600`、`bg-rose-500`、`text-[11px]`、`ring-gray-200` 等类不在产物里，直接写会出现“按钮透明、徽标掉色”。工作台的颜色/按钮/气泡已收敛到 `show.blade.php` 的页面级 CSS；后续改样式请沿用这些 `support-*` 类，不要假设任意 Tailwind 类可用。
2. **不能绑 DOMContentLoaded**：Bagisto Admin 在 `window.load` 执行 `app.mount('#app')`，Vue 会替换服务端渲染节点；在 DOMContentLoaded 缓存的按钮/输入框会变成脱离文档的旧节点（点了没反应）。工作台脚本已改为 `load` + 150ms 启动。以后给后台页面加交互脚本，务必注意这一点。

### 回滚

```bash
# 这些文件在本轮开始前已有未提交改动，git checkout 会一并回退到 HEAD，
# 建议先提交当前改动再回滚。
git checkout -- \
  packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  packages/Webkul/Admin/src/Routes/customers-routes.php \
  packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  packages/Webkul/Admin/src/Resources/views/customers/tickets/index.blade.php \
  packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js
rm packages/Webkul/Admin/src/Resources/views/customers/tickets/partials/conversation-list.blade.php
```

本轮完成后的文件已备份到 `.claude/backup/admin-support-console-20261027/`（含 `admin-console-after.patch`）。

### 本轮未做

- **切换会话仍走整页跳转**：左侧列表是 `<a>` 链接，点击会重新加载工作台；没有做单页无刷新切换。
- **没有新消息声音/桌面通知**：只做了未读数、标题提示和新消息浮标。
- **没有会话转接、客服排班、质检、多人协作**。
- **事故定性的表单仍是整页提交**（低频动作），没有改成 AJAX。
- 仅验证 1440×900 桌面端；1024 以下的自适应栅格未做浏览器实拍。

## 客服中心渠道选择：在线客服 / 工单留言（2026-10-27）

### 背景与边界

用户反馈「描述你遇到的问题」区域应当让用户自己选**在线客服**还是**工单留言**。此前是页面替用户决定：有客服在线就静默走在线会话，否则静默走留言 —— 用户看不到自己排进哪条队。现在恢复为显式二选一，**仍然保留自动默认**。

| 文件 | 改动 |
| --- | --- |
| `pages/mine/tickets.vue` | 新增 `channel`/`channelTouched` 状态与 `selectChannel()`；卡片内加两个渠道选项（标题 + 状态点 + 一行说明）；提交按所选渠道分别走 `ticketApi.createOnline()` / `ticketApi.create()`；`refreshOnline()` 在用户未手动选择前，按在线情况给默认项（在线→在线客服，离线→工单留言）。 |

规则：

- 在线客服：`isOnline` 时显示绿点与「客服在线，实时对话」；无客服在线仍可选，提交后进入排队（接口本来就允许），文案变成「已进入排队，客服上线后会回复」。
- 工单留言：始终可用，`24 小时内回复`。
- 用户手动点过之后，后台状态刷新不再改写他的选择（`channelTouched`）。
- 默认项由在线状态决定，避免离线时段把用户塞进一条没人接待的队。

### 实测证据

- 无客服在线（默认选中「工单留言」）：`docs/customer-service-center-screenshots/tickets-channel-offline.png`
- 有客服在线（默认选中「在线客服」+ 绿点）：`docs/customer-service-center-screenshots/tickets-channel-online.png`
- 点击切换：两选项 `class` 从 `channel-option` / `channel-option active` 正确互换（CDP 断言）。
- 提交落库（探针工单，已删除）：
  - 选工单留言 → `support_ticket.id=77, service_mode=async`
  - 选在线客服 → `support_ticket.id=78, service_mode=online`
  - 清理后复查：两条工单与消息均已删除。

### 回滚

```bash
git checkout -- pages/mine/tickets.vue
```

## 后台工作台第二轮：减法 + 吞吐（2026-10-27）

### 先说实话：100 单/天意味着什么

100 单/天 ≈ 平均每 5.8 分钟一单，峰值（按 3 倍）也就 12–15 单/小时。这个量级**不是数据库或机器扛不住**，瓶颈在「客服要在多少次点击/跳转里处理一单」。因此本轮没有做微服务、队列扩容之类的重活，只做两件有实际收益的事：**去掉互相重复的信息**、**减少每单的操作成本与无效轮询**。

### 改动清单

| 文件 | 改动 | 类型 |
| --- | --- | --- |
| `.../customers/tickets/index.blade.php` | 首屏的 3 段蓝色说明块压成 1 行摘要 + 1 行操作提示 | 减法 |
| `.../tickets/partials/conversation-list.blade.php` | 每行 5 个彩色徽标 → 1 个状态 + 未读 +（紧急/超时）；「在线/留言 · 分类」降级为灰色小字；工单号收进 `data-ticket-no` 与鼠标悬停 `title`，不再占一行 | 减法 |
| `.../views/customers/tickets/show.blade.php` | ① 左栏统计补「留言」计数；② 会话头把 `gap-x-3`（后台 Tailwind 产物里**不存在**）换成 `gap-x-2`，信息不再粘成一串；③ 会话头新增「下一条待处理 →」；④ 受理人改为可被 JS 更新的节点；⑤ 「事故定性」卡并入「处置状态」卡底部，右栏从 4 卡降到 3 卡；⑥ 英文小标题 Customer/INCIDENT 改中文 | 减法 + 吞吐 |
| `.../Http/Controllers/Customers/SupportTicketController.php` | ① `workbench()` 支持 `?exclude={id}`，让「下一条」不会跳回自己；② 留言池改为**最久没人动优先**（原为最新优先，SLA 容易烂在池底）；③ `waitingLabel` 分钟数人性化（1310 分钟 → 21 小时）；④ 会话行数据补 `priority` 字段 | 吞吐 |
| `.../assets/js/online-support-workbench.js` | ① 「接入会话」不再整页刷新（用返回的 JSON 就地更新状态/受理人/输入框）；② 标签页在后台（`document.hidden`）时不轮询会话与列表，切回前台立即补一次；③ 「留言」计数跟随刷新；④ 已接入后心跳不再把提示文案改回去 | 吞吐 |

### 实测证据

- 工单列表页（说明块压缩后）：`docs/customer-service-center-screenshots/admin-ticket-index.png`
- 工作台（右栏 3 卡、列表降噪、`等待 21 小时`、头部信息不再粘连）：`docs/customer-service-center-screenshots/admin-workbench-clarified.png`
- 「下一条待处理」：在工单 2 上点击 → 跳到 `/admin/customers/tickets/5`（`?exclude=2` 生效，未回到自己）。
- 「接入会话不刷新」CDP 实测：

  ```
  URL BEFORE : /admin/customers/tickets/2
  PRESENCE   : 在线接待
  URL AFTER  : /admin/customers/tickets/2   ← 没有整页跳转
  CLAIM HIDDEN: true
  CLAIM HINT : 已接入，当前由你接待。
  ASSIGNEE   : 截图临时账号
  INPUT DISABLED: false
  STATUS BADGE: 已接入
  ```

- `php -l` 控制器语法通过；`php artisan view:cache` 全部 Blade 编译通过；`node --check` 工作台 JS 通过。
- 后台在线客服测试全绿：`pest packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` → **12 passed / 50 assertions**。
- 测试数据已清理：临时管理员（`shot-temp@zhaohuan.local`）、客服在线状态、工单 2 的接入状态与系统消息均已还原/删除，复查 `support_agent_state=0`、`admins=1`、工单 2 为 `open / 未指派`。

### 回滚

```bash
git checkout -- \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/index.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js
D:/php.exe artisan view:cache   # 回滚后重编译 Blade
```

> 注意：这些文件在本轮开始前就有未提交改动（上一轮工作台），`git checkout` 会退到 HEAD 的旧版本，建议先提交再回滚。

### 本轮未做（需要你拍板）

1. **多会话并行**：目前一次只能在一个会话里；切换仍走整页跳转（左侧列表是 `<a>`）。要做「无刷新切换 + 多标签」是另一档工作量。
2. **接入后释放按钮缺失**：无刷新接入之后右栏不会长出「释放回队列」，要释放得刷新一次页面。可接受就先这样。
3. **自动分配 / 转接 / 排班 / 质检**：都没做，也没立项。
4. **索引**：`support_ticket` 已有 `(service_mode,status,queued_at)` 与 `(status,sla_due_at)`；缺 `assignee_id` 索引，但 100 单/天的数据量下没有收益，**故意不加**。
5. 仅实测 1440×900 桌面端与 390×844 移动端 H5；1024 以下后台自适应未实拍。

## 客服中心第二轮：按「头部平台六件套」补齐（2026-10-27）

### 判断框架：客服中心不是聊天框，而是六件事

| # | 支柱 | 头部平台的标准动作 | 本轮之前 | 现在 |
| --- | --- | --- | --- | --- |
| 1 | 自助分流 | 先自助/AI，人工托底 | 只有 FAQ 预填文案 | 客服中心新增「先问问机甲 AI」入口，直达 AI 召唤 |
| 2 | 排队透明 | 告诉用户在等谁、等多久 | 有在线/排队数据但界面不显示 | 渠道卡显示「客服在线 N 位 · 排队 X 人」 |
| 3 | 质量闭环 | 服务后评价（CSAT） | **完全没有** | 会话结单后可评 1–5 星 + 标签 + 可选留言，一次会话一次评价 |
| 4 | 用户可控 | 未读、折叠、断线提示 | 未读/折叠已有 | 断线/同步中断提示已有（详情页） |
| 5 | 客服效率 | 队列、下一条、无刷新接入 | 上一轮已做队列/下一条/无刷新接入 | 保留 |
| 6 | 可度量 | 首响、结单、超时、满意度 | **完全没有** | 工作台顶部今日指标条：新会话/已结单/待回复/超时/平均首响/满意度 |

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_27_000001_add_rating_to_support_ticket.php` | 新增 `rating` / `rating_tags` / `rating_comment` / `rated_at` 四列 + `idx_ticket_rated_at` |
| `.../Shop/src/Models/SupportTicket.php` | 新增 `RATING_TAG_LABELS` 标签枚举、`isRateable()`（已解决/已关闭且未评）、fillable/casts |
| `.../Shop/src/Http/Controllers/API/TicketController.php` | 新增 `rate()`：校验 1–5 分与标签白名单；非本人 404、未结单 422、重复评价 409；会话状态返回体补 `rating/rating_tags/rating_comment/rated_at/can_rate` |
| `.../Shop/src/Routes/api.php` | 新增 `POST /api/tickets/{id}/rate`（写限流） |
| `pages/mine/ticket-detail.vue` | 新增评价卡：5 星 + 6 个标签 + 选填留言；已评后显示「已评价 ★★★★★」 |
| `pages/mine/tickets.vue` | 渠道卡显示在线人数与排队数；新增「先问问机甲 AI」入口 |
| `utils/api.js` / `utils/customer-service.js` | 新增 `ticketApi.rate` 与 `RATING_TAGS`（与服务端枚举同源） |
| `.../Admin/.../SupportTicketController.php` | 新增 `dashboardStats()`（今日新会话/结单/待回复/超时/平均首响/满意度），`conversations` 接口一并下发；会话行数据补 `rating` |
| `.../Admin/.../tickets/show.blade.php` | 顶部今日指标条；会话头显示「评价 N★」；客户卡显示星级 + 标签 + 留言 |
| `.../tickets/partials/conversation-list.blade.php` | 已评价会话加 `★N` 徽标 |
| `.../Admin/.../online-support-workbench.js` | 指标条随 3 秒轮询实时刷新 |

评价口径（刻意约束）：

- **只有结单后能评**（已解决/已关闭）。处理中评价只会把「等待的怨气」打成分数。
- **一张会话一次**，不做改评/追评 —— 运营要的是当时那次服务的真实感受。
- 标签固定枚举（问题已解决/回复及时/态度好/说明清楚/仍未解决/等待太久），不回落成自由文本。

### 实测证据

- 用户侧评价卡（5 星 + 标签 + 选填）：`docs/customer-service-center-screenshots/tickets-rating-card.png`
- 提交后：`docs/customer-service-center-screenshots/tickets-rating-done.png`（★★★★★ 已评价）
- 渠道卡在线人数/排队数：`docs/customer-service-center-screenshots/tickets-channel-online.png`（客服在线 1 位 · 排队 1 人）
- AI 分流入口：`docs/customer-service-center-screenshots/tickets-ai-entry.png`
- 后台今日指标条（真实数据渲染）：`docs/customer-service-center-screenshots/admin-today-stats.png`（今日新会话 2 · 已结单 1 · 待回复 3 · 超时 1 · 平均首响 1 分钟 · 满意度 5（1 条评价））
- 后台评价展示：`docs/customer-service-center-screenshots/admin-rating-view.png`（会话头「评价 5★」+ 客户卡星级/标签）
- 自动化测试：
  - `pest packages/Webkul/Shop/tests/Feature/API/CustomerServiceCenterTest.php` → **11 passed / 41 assertions**（新增 5 条评价用例：写入、重复 409、未结单 422、越权 404、评分/标签校验 422）
  - `pest packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` → **12 passed / 50 assertions**
  - `pest packages/Webkul/Shop/tests/Feature/API/OnlineCustomerServiceTest.php` → **8 passed / 35 assertions**
  - 其中「在线人数/队列长度」用例原先写死 `queue_size=1`，在开发库存在历史排队会话时必然变红；已改成「基线 +1」的断言（**这是测试脆弱，不是功能回归**）。
- `php -l` / `node --check` / `view:cache` 全通过；迁移已执行。
- 探针数据已清理：评价探针工单 118 及消息、临时管理员、临时 token、在线状态均已删除；工单 76 被误改的状态已还原为 `processing`。

### 回滚

```bash
# 代码
git checkout -- \
  my-bagisto-store/packages/Webkul/Shop/src/Models/SupportTicket.php \
  my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/TicketController.php \
  my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/partials/conversation-list.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js \
  pages/mine/ticket-detail.vue pages/mine/tickets.vue utils/api.js utils/customer-service.js
rm my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_27_000001_add_rating_to_support_ticket.php
# 数据库
D:/php.exe artisan migrate:rollback --step=1
```

### 仍未做（按头部平台标准排的下三步）

1. **会话转接 / 挂起 / 备注**：客服把会话转给同事、挂起等客户补充，目前只能「接入 / 释放」。
2. **知识库化快捷回复**：现在仍是 3 条硬编码话术；下一步应做成配置注册表（按类别/场景），并能统计话术使用率。
3. **消息级能力**：图片/附件上传（等对象存储 + 内容安全扫描就绪）、客服「正在输入」、消息已读回执展示。
4. **客服绩效报表**：今日条只是当天的数；按人/按类目的周报、SLA 达成率、评价分布（差评回访）还没做。
5. **多渠道接入**：目前只有 H5/小程序；公众号、企微、电话工单没有接。

## 客服中心第三轮：转接 / 挂起 / 内部备注 / 话术知识库 / 消息级能力（2026-10-27）

### 拍板口径（本轮开工前确认）

| 问题 | 结论 |
| --- | --- |
| 挂起怎么实现 | **独立挂起标记**（`support_ticket.held_at`），不改 open/processing/resolved/closed 状态机；后台状态徽标仍显示真实状态，另用琥珀色「挂起」徽标表达挂起 |
| 转接允许转给谁 | **在线会话只转给在线且未满负荷的客服**；留言工单可转给任意启用中的后台账号 |
| 消息级能力做到哪 | **已读回执 + 双方「正在输入」**；图片/附件上传继续等对象存储 + 内容安全 |
| 快捷话术统计口径 | 记录 **insert（点击插入）+ send（实际发出）** 两类事件，可计算采纳率 |

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `Shop/.../Database/Migrations/2026_10_27_000002_add_support_collaboration.php` | 新增。`support_ticket.held_at / held_reason`；新表 `support_quick_reply_event`（reply_key / event / admin_id / ticket_id / category / created_at，含时间与 key 两组索引） |
| `Shop/.../Models/SupportTicket.php` | `isHeld()`、`agentStatusLabel()`（后台状态文案不受挂起影响）、`serviceStatusLabel()`（用户端挂起显示「等待补充」）、`isOverdue()` 挂起不报超时、`typingCacheKey()` / `TYPING_TTL_SECONDS`、fillable/casts |
| `Shop/.../Models/TicketMessage.php` | actor_type 常量（含 `ACTOR_NOTE`）与 `STAFF_ACTOR_TYPES`；备注不是客服回复 |
| `Shop/.../Http/Controllers/API/TicketController.php` | ① 列表/详情/增量/已读全部过滤 `actor_type=note`；② 列表摘要单独按可见消息取最后一条；③ 用户回复自动解除挂起；④ 新增 `typing()` 与 `staff_typing`；⑤ 增量下发 `receipts`（用户消息已读状态） |
| `Shop/.../Routes/api.php` | 新增 `POST /api/tickets/{id}/typing`（`throttle:typing`） |
| `Shop/.../Providers/ShopServiceProvider.php` | 新增 `typing` 限流档：20/分（前端约 4 秒一次，30/分会被正常打字限死） |
| `Admin/.../Http/Controllers/Customers/SupportTicketController.php` | 新增 `transfer()` / `hold()` / `resume()` / `note()` / `typing()` / `quickReplyEvent()`，以及 `assignableAgents()` / `quickReplyGroups()` / `quickReplyStats()` / `recordQuickReplyEvent()`；会话分组新增「已挂起」；待回复/超时口径排除挂起；回复自动解除挂起并记 `send` 事件；增量下发 `held` / `customer_typing` / `receipts` |
| `Admin/.../Routes/customers-routes.php` | 新增 transfer / hold / resume / notes / typing / support/quick-replies/event 六条路由 |
| `config/support_quick_replies.php` | **新增配置注册表**。按 `common / delivery / skill / device / payment / other` 分组，每条 `key / label / content`；共 22 条话术 |
| `Admin/.../DataGrids/Customers/SupportTicketDataGrid.php` | 状态列追加「挂起」徽标；挂起中的单不再显示已超时 |
| `Admin/.../views/customers/tickets/show.blade.php` | 快捷话术改为配置下发（带 `data-reply-key`）；新增「回复用户 / 内部备注」模式切换；新增转接下拉与按钮、挂起/恢复卡；内部备注琥珀气泡；客服消息「已读/未读」回执；用户输入中提示；近 7 天话术效果折叠面板 |
| `Admin/.../views/customers/tickets/partials/conversation-list.blade.php` | 会话行显示「挂起」徽标（悬停看内部原因） |
| `Admin/.../assets/js/online-support-workbench.js` | 话术点击记 insert、发送带 `quick_reply_key` 记 send；备注模式；转接/挂起/恢复；已读回执增量刷新（`receipts`）；双向输入中心跳；用户输入中提示；挂起状态同步 |
| `pages/mine/ticket-detail.vue` | 用户消息显示「已送达 / 已读」；客服「正在输入…」提示；挂起时欢迎语改为「客服正在等待你的补充信息」；输入 4 秒心跳；增量 `receipts` 更新既有气泡 |
| `pages/mine/tickets.vue` | 状态文案改用服务端 `status_label`（能看到「等待补充」），挂起态使用琥珀色样式 |
| `utils/api.js` | 新增 `ticketApi.typing` |
| 测试 | 新增 `Shop/.../SupportCollaborationTest.php`（6 条）；`OnlineCustomerServiceAdminTest.php` 扩到 23 条 |

### 新增接口

```text
POST /api/tickets/{id}/typing                         用户输入中心跳
POST /admin/customers/tickets/{id}/transfer           转接给同事
POST /admin/customers/tickets/{id}/hold               挂起等待客户补充
POST /admin/customers/tickets/{id}/resume             恢复处理
POST /admin/customers/tickets/{id}/notes              内部备注（仅后台可见）
POST /admin/customers/tickets/{id}/typing             客服输入中心跳
POST /admin/customers/support/quick-replies/event     话术 insert / send 事件
```

### 关键边界（实现时特意守住的）

- **备注永不进入用户接口**：用户侧列表、详情、增量、未读计数、最后一条摘要全部排除 `note`；备注也不更新 `last_staff_message_at`，否则「待回复」指标会把备注当成客服已回复。
- **挂起不改状态机**：`held_at` 与状态正交；挂起单移出排队/我的接待/留言池，单独进「已挂起」；不再计入超时与待回复；客户回复、客服回复、恢复按钮都会解除挂起。
- **在线转接防悬空**：目标客服必须在线且接待数未满；转接写系统消息（用户可见）与 `operation_log`。
- **输入中不落库**：`cache` 8 秒 TTL，两端共用同一 key 规则；发出消息立即清除；备注输入不触发「客服正在输入」。
- **已读回执单独下发**：消息不会再出现一次，靠 `receipts` 把已存在气泡的「未读/已送达」更新为「已读」。

### 实测证据

**自动化**

- `pest packages/Webkul/Shop/tests/Feature/API/SupportCollaborationTest.php` → **6 passed / 35 assertions**
- `pest packages/Webkul/Shop/tests/Feature/API/CustomerServiceCenterTest.php` → **11 passed / 41 assertions**
- `pest packages/Webkul/Shop/tests/Feature/API/OnlineCustomerServiceTest.php` → **8 passed / 35 assertions**
- `pest packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` → **23 passed / 101 assertions**
- `pest packages/Webkul/Admin/tests/Feature/Customers/SupportTicketTest.php` → **9 passed / 21 assertions**
- `pest packages/Webkul/Admin/tests/Feature/Customers/TicketIncidentEscalateB10Test.php` → **4 passed / 18 assertions**
  （其中「详情页显示已定性事故互链」在本轮开始时是红的：上一轮未提交改动把「本工单已定性为事故」文案删掉了。本轮补回文案，**不是新功能**。）
- `php -l` 全部改动 PHP 文件通过；`node --check` 工作台 JS 通过；`artisan view:cache` 通过。
- `artisan route:list`：`shop.api.tickets` 11 条、`admin.customers.tickets` 14 条、`admin.customers.support` 5 条。
- `artisan migrate:status`：`2026_10_27_000002_add_support_collaboration` 已执行（Batch 53）。
- `artisan ecosystem:contract-check`：契约检查通过。

**浏览器实测（本机 H5 + 后台）**

- 用户会话页：`docs/customer-service-center-screenshots/ticket-collab-held-typing.png`
  - 顶部欢迎语「客服正在等待你的补充信息，直接回复即可。」
  - 用户消息右侧「已读」（蓝）
  - 底部「客服正在输入…」
  - 内部备注（服务端确实存在一条 note）**没有出现**在用户会话里
- 后台工作台：`docs/customer-service-center-screenshots/admin-collaboration.png`
  - 今日指标条出现「挂起 1」
  - 左栏独立「已挂起」分组，行内状态「已接入」+ 琥珀「挂起」
  - 中间消息流出现琥珀色「内部备注 · 仅客服可见」气泡
  - 客服消息时间后显示绿色「已读」
  - 快捷回复按「设备问题 / 通用」分组下发
  - 右栏出现「转接给同事」下拉（在线客服 · 接待 0/5）与「挂起与恢复」卡
- 浏览器交互（CDP 实测，不是看代码推断）：
  - 点击 `data-reply-key="common.received"` → 输入框值变为「已收到，我们正在为你排查。」，`support_quick_reply_event` 落库 `insert`（admin 191 / ticket 284 / key common.received）。
  - 切到「内部备注」→ 提示「备注只在后台可见，不会发给用户」，提交后备注气泡即时出现在消息流；同一时刻用用户 token 请求 `/api/tickets/284/updates`，`grep` 备注内容 **0 次命中**。
- 临时数据已清理：探针工单/客户/管理员/客服在线状态/话术事件/消息/操作日志复查均为 **0**；临时 CDP 脚本与截图已删除，仅保留上面两张证据图。

### 数据迁移与备份

- 迁移前备份：`.claude/backup/customer-service-collab-20261027/root-support-tables-before-collab.json`（`support_ticket` + `ticket_message` 全量行）
- 本轮改动文件留档：`.claude/backup/customer-service-collab-20261027/files/`（18 个文件，保留原相对路径）
- 迁移只做加法（两列 + 一张新表），回滚不丢既有工单与消息。

### 回滚

```bash
# 数据库
D:/php.exe artisan migrate:rollback --step=1

# 代码（注意：这些文件在本轮开始前就已有未提交改动，git checkout 会退到 HEAD）
git checkout -- \
  my-bagisto-store/packages/Webkul/Shop/src/Models/SupportTicket.php \
  my-bagisto-store/packages/Webkul/Shop/src/Models/TicketMessage.php \
  my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/TicketController.php \
  my-bagisto-store/packages/Webkul/Shop/src/Providers/ShopServiceProvider.php \
  my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Routes/customers-routes.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/DataGrids/Customers/SupportTicketDataGrid.php \
  pages/mine/ticket-detail.vue pages/mine/tickets.vue utils/api.js
rm my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_27_000002_add_support_collaboration.php
rm my-bagisto-store/config/support_quick_replies.php
rm my-bagisto-store/packages/Webkul/Shop/tests/Feature/API/SupportCollaborationTest.php
D:/php.exe artisan view:cache   # 回滚后重编译 Blade
```

> 若只想撤掉某个能力：删 `support_quick_replies.php` 会退回硬编码话术前需先改 Blade/JS；删备注过滤会让内部备注泄漏给用户，**不要单独撤这一条**。

### 本轮未做（不得对外说已具备）

- **图片/截图/附件上传**：仍是链接附件，等对象存储 + 内容安全扫描；本轮未开放客服附件输入。
- **输入中不是 WebSocket**：仍靠 2 秒/5 秒轮询读缓存，停手 8 秒后消失；弱网下会有延迟。
- **话术管理界面**：话术在 `config/support_quick_replies.php`，改话术要发版；没有后台增删改、没有按客服个人的话术库。
- **绩效报表**：只有今日指标与近 7 天话术效果；按人/按类目的周报、SLA 达成率、差评回访仍未做。
- **转给开发者**：仍然只能转给后台客服；`support_ticket.developer_id` 的开发者门户回复链路未做。
- **多渠道**：公众号、企微、电话工单未接。
- 仅浏览器实测 1440×900 后台与 390×844 H5；小程序端未实测（样式改动为纯 CSS + 模板，未改接口契约）。

## 客户收件箱 + 一个客户一张进行中会话（2026-10-27）

### 数据诊断

本机库里客户 6 同时挂着 **4 张 open/processing 工单**（在线 1 + 留言 3，类别分散在支付/其他）。这不是页面样式问题，是两个结构性问题叠加：

1. `POST /api/tickets` 与 `/api/tickets/online` **每调用一次就新建一张单**，没有「已有进行中会话就续写」的判断；
2. 后台左栏是**按工单**组织的，一个客户 4 张单就占 4 行；加上四个分组、每段 30 条上限、无搜索/无统一等待排序，几十人咨询时客服只能反复扫列表和整页跳转。

### 拍板口径

| 问题 | 结论 |
| --- | --- |
| 「一个客户一个对话历史」 | **按客户聚合 + 同一客户只允许一张 open/processing**；工单仍是真相源，SLA/事故/评价/类别不丢 |
| 存量重复进行中 | 保留最近更新的一张，其余置为 closed 并写系统消息「已合并至会话 #X」；消息、事故链、评价都不删 |
| 本轮排队改造 | 客户收件箱 + 未读/待回复/排队/留言/挂起筛选 + 搜索 + 客户历史；无刷新切换/通知/自动分配留到下一轮 |

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `Shop/.../Http/Controllers/API/TicketController.php` | `store()` 改为 `createOrResumeTicket()`：锁客户行 → 找 open/processing → 有则续写、无则新建；响应加 `resumed`（200）与 `data.id`。未接待的留言单在用户改选在线客服时升级为 online 并重新排队；`reply()` 遇到「已解决单 + 另一张进行中会话」时把消息转投当前会话并返回 `redirect_ticket_id` |
| `pages/mine/tickets.vue` | 输入卡新增「你有进行中的会话，本次发送会续接到该会话」提示 + 查看入口；会话列表副标题改为「进行中 N · 历史 M」；`resumed` 时提示「已续接到当前会话」 |
| `pages/mine/ticket-detail.vue` | 收到 `redirect_ticket_id` 时提示并自动切到进行中的会话 |
| `Shop/.../Console/Commands/MergeDuplicateActiveTickets.php` | 新增 `support:merge-duplicate-active`：默认 dry-run，`--execute` 才写库；保留最近更新的一张，其余关闭 + 系统消息 + operation_log；**已关联事故的重复单跳过并报告**，不碰事故链 |
| `Shop/.../Providers/ShopServiceProvider.php` | 注册上述命令 |
| `Admin/.../SupportTicketController.php` | `conversationSections()` 换成 `customerInbox()`：按客户分组、未读跨会话求和、等待时长取最早未回复、按最久未回复排序；`show()` 下发收件箱与「客户历史」；`conversations()` 返回新的 counts（total/mine/waiting/unread/queue/async/held） |
| `Admin/.../views/customers/tickets/partials/conversation-list.blade.php` | 由「分组 + 工单行」改为「客户行」：客户名、合并未读、等待时长、最后消息、状态/我负责/排队/挂起/超时/优先级/评价/受理人、多会话数量；带 `data-inbox-row` 与筛选/搜索所需 data 属性 |
| `Admin/.../views/customers/tickets/show.blade.php` | 左栏静态筛选条（全部/我负责/待回复/未读 + 排队/留言/挂起，两行）+ 搜索框；右栏新增「客户历史」卡；修复 `.support-tone` 覆盖 `.hidden` 导致「挂起」徽标常显的问题 |
| `Admin/.../assets/js/online-support-workbench.js` | 筛选/搜索在每次 3 秒重绘后重新应用；counts 改为新口径；移除旧「我的」计数 |
| 测试 | `SupportCollaborationTest` 增到 9 条（续接、通道升级、resolved 转投）；Admin 收件箱/客户历史测试重写；`OnlineCustomerServiceTest` 调整一条被新规则取代的断言 |

### 存量整理执行记录

```text
dry-run：
客户6 | 5 友 | 保留 T2610271344266-6GB69J | 关闭 T2610262321246-CTEHVX / T2610262340016-NOWRJJ / T2610262018156-RAWPZD | 计划 3，跳过 0
--execute：
已关闭 3 张重复进行中会话（计划 3，跳过 0）
复查：multi_active=[]，客户6 = 1 张 processing + 3 张 closed，合并系统消息 3 条，operation_log 3 条
```

迁移前备份：`.claude/backup/customer-inbox-20261027/root-support-tables-before-merge.json`（工单 + 消息全量行）。

### 实测证据

**自动化**

- `pest packages/Webkul/Shop/tests/Feature/API/SupportCollaborationTest.php` → **9 passed / 55 assertions**
- `pest packages/Webkul/Shop/tests/Feature/Console/MergeDuplicateActiveTicketsTest.php` → **2 passed / 10 assertions**
- 上述 4 个 Shop 工单相关文件合并跑 → **30 passed / 141 assertions**
- `pest .../CustomerServiceCenterTest.php` → **11 passed / 41 assertions**
- `pest .../OnlineCustomerServiceTest.php` → **8 passed / 35 assertions**
- `pest packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` → **24 passed / 113 assertions**
- `pest .../SupportTicketTest.php` → **9 passed / 21 assertions**
- `php -l` 改动 PHP 通过；`node --check` 工作台 JS 通过；`artisan view:cache` 通过；`artisan ecosystem:contract-check` 通过；两个改动的 `.vue` 用 HBuilderX 自带 `@vue/compiler-sfc` 编译校验通过。

**浏览器实测（后台，1440×900）**

- 客户收件箱：`docs/customer-service-center-screenshots/admin-customer-inbox.png`
  - 左栏一行一个客户：王小明（等待 35+ 分钟、未读 2）、李思（等待 9+ 分钟、排队）、赵敏（我负责 + 挂起）、5 友（处理中）。
  - 筛选条：全部 4 · 我负责 1 · 待回复 2 · 未读 4 · 排队 1 · 留言 3 · 挂起 1。
- 客户历史：`docs/customer-service-center-screenshots/admin-customer-history.png`
  - 5 友 当前 1 张进行中，右栏「客户历史」列出合并后的 3 张已关闭会话（含单号/类别/状态）。
- CDP 交互实测（不是看代码推断）：
  - 点「待回复」→ 可见客户 `77,78`；点「挂起」→ `79`；搜索「李」→ `78`；清空后恢复 `77,78,79,6`。
  - 非挂起会话头部「挂起」徽标 `display: none`（修复了 `.support-tone` 覆盖 `.hidden` 的问题）。

**真实库接口实测**

```text
POST /api/tickets（客户 77，已有进行中会话）
→ 200 {"message":"已续接到你当前的会话。","data":{"id":141,"resumed":true}}
复查：该客户工单数仍为 1，ticket 141 消息数 +1
```

**未做截图的部分（如实标注）**

- 用户端新增的「进行中会话提示」没能在本轮截到图：H5 dev server 中途停止（5173 拒绝连接），只做了 `@vue/compiler-sfc` 编译校验 + 真实接口续接实测。页面结构与文案可在 dev server 恢复后补一张。

### 回滚

```bash
# 代码
git checkout -- \
  my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/TicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/partials/conversation-list.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js \
  pages/mine/tickets.vue pages/mine/ticket-detail.vue
rm my-bagisto-store/packages/Webkul/Shop/src/Console/Commands/MergeDuplicateActiveTickets.php
# Provider 里的注册行需手工删除
```

存量合并是**数据变更**，代码回滚不会自动恢复那 3 张已关闭工单。需要恢复时按备份 JSON 还原对应行，并删除合并系统消息与 operation_log：

```php
$b = json_decode(file_get_contents('.claude/backup/customer-inbox-20261027/root-support-tables-before-merge.json'), true);
foreach ([1, 2, 5] as $id) {
    $row = collect($b['support_ticket'])->firstWhere('id', $id);
    if ($row) {
        DB::table('support_ticket')->where('id', $id)->update([
            'status' => $row['status'], 'ended_at' => $row['ended_at'],
            'held_at' => $row['held_at'], 'held_reason' => $row['held_reason'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
DB::table('ticket_message')->where('content', 'like', '%已合并至你当前的会话%')->delete();
DB::table('operation_log')->where('action', 'ticket.merge_duplicate')->delete();
```

### 本轮未做

- **多标签并行**：单次切换已无刷新（见下一节），但还不能同时开多个会话标签。
- **新消息声音 / 桌面通知**：已补齐（见下一节）；未做自定义铃声与免打扰时段。
- **自动分配 / 排班 / 客服负载看板**：未做。
- **后台手动重开历史单仍可能产生同客户两张 active**：收件箱会聚合成一行并标「N 个会话」，但不在服务端强制阻止（运营可能需要重开）。用户侧的新建/回复路径已守住单进行中。
- **收件箱未做分页**：当前一次性加载全部 active 工单（几十到几百可接受；上千需要游标/分页）。

## 无刷新切换 + 新消息提示音/桌面通知（2026-10-28）

### 目标

几十人同时咨询时，客服一天要在十几张会话之间来回点。整页刷新会重跑三栏查询、丢搜索/筛选、打断输入；新消息也只有左栏未读数和标签页标题。本轮做两件事：

1. **点会话不整页刷新**：只换工作台主体，保留筛选/搜索/滚动位置，URL 与浏览器前进后退同步；
2. **新消息提醒**：提示音默认开启、可一键关闭；桌面通知按需授权；后台标签页在开启通知后继续轮询（否则通知永远不会触发）。

### 改动清单

| 文件 | 改动 |
| --- | --- |
| `.../views/customers/tickets/partials/workspace.blade.php` | 新增。把 `#support-workspace` 主体（统计条 + 三栏）从 `show.blade.php` 抽出，供整页与 AJAX 切换共用；`data-support-config` 增加 `ticketNo`；客户历史链接加 `data-workspace-link`；左栏头部加「提示音 / 桌面通知」开关 |
| `.../views/customers/tickets/show.blade.php` | 改为 `@include` 工作台片段；保留 layout/toast/页面级 CSS/脚本；脚本版本 `20261028-1`；新增 `.support-toggle` 样式 |
| `.../Http/Controllers/Customers/SupportTicketController.php` | `show()` 在 `?embed=1` 时只返回工作台片段（不套后台 layout），供无刷新切换 |
| `.../assets/js/online-support-workbench.js` | ① 启动改为可重入：每次 hydrate 先清理上一实例的定时器与 document 监听；② 左栏点击走事件委托 → `fetch(...?embed=1)` → 替换 `#support-workspace` → `pushState` → 重新 hydrate；③ `popstate` 支持浏览器前进/后退；④ 保留筛选、搜索词、列表滚动位置；⑤ 标签页标题跟随新工单；⑥ 提示音/桌面通知开关与 localStorage；⑦ 后台标签页开启通知时继续轮询，且不替客服标记已读 |
| `.../tests/Feature/Customers/OnlineCustomerServiceAdminTest.php` | 新增 embed 测试：只返回主体、不套 layout |

### 实测证据

- `pest .../OnlineCustomerServiceAdminTest.php` → **25 passed / 118 assertions**
- `php -l`、`node --check`、`artisan view:cache` 通过。
- 浏览器 CDP 实测（1440×900，临时客服账号/工单，已清理）：
  - **无刷新切换**：在 `/admin/customers/tickets/483` 记录 `window.__noReloadMarker` → 点客户 391 → URL 变为 `/484`，marker 仍在（证明没有整页重载）；新 `data-support-config.ticketId=484`；标题变为「(4) 客服工作台 SW…B」；筛选「待回复」保持，活动行变为 391。
  - **筛选/滚动保持**：切换前激活「待回复」，切换后仍是 `waiting`。
  - **提示音开关持久化**：关闭后 `localStorage.support_sound_enabled=0`，切换会话后按钮仍显示「提示音 关」。
  - **客户历史无刷新**：从工单 76 点客户历史里的已关闭工单 → URL 变为 `/5`，marker 保留。
  - **浏览器后退**：从 484 后退 → 回到 483，marker 保留，配置 `ticketId=483`。
  - 截图：`docs/customer-service-center-screenshots/admin-inbox-switch.png`（切到李思后的新主体、筛选保持、提示音关闭）。
- `docs/customer-service-center-screenshots/admin-customer-inbox.png` 与 `admin-customer-history.png` 已按最终 UI 重拍。

### 如实标注

- 提示音的实际发声、桌面通知的系统弹窗没有做“听到/看到”的客观验证（headless 浏览器无法听声、系统通知需要人工授权）；已验证开关、localStorage 持久化、通知权限分支与后台轮询逻辑。
- 浏览器会在后台标签页对定时器降频，桌面通知可能延迟数秒到一分钟，不保证秒级。
- 无刷新切换只覆盖左栏客户行与「客户历史」；「下一条待处理」、返回工单列表仍是整页跳转。

### 回滚

```bash
git checkout -- \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Customers/SupportTicketController.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/assets/js/online-support-workbench.js \
  my-bagisto-store/packages/Webkul/Admin/tests/Feature/Customers/OnlineCustomerServiceAdminTest.php
rm my-bagisto-store/packages/Webkul/Admin/src/Resources/views/customers/tickets/partials/workspace.blade.php
D:/php.exe artisan view:cache
```

> `show.blade.php` 与工作台脚本在本轮前已有未提交改动，`git checkout` 会一并回退到 HEAD；需要保留本轮改动时按 `.claude/backup/customer-inbox-20261027/files/` 下的副本恢复。
