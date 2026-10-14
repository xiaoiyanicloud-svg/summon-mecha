# 项目导航地图 (Navigation Map)

> 作用：让 Claude 每次执行任务前快速定位代码，省 token。
> 用法：先读本文件，按 ✅/🔶/❌ 判断相关工作区，直接跳到对应文件，不要通读无关目录。

## 项目全貌（一图流）

```
d:\main
├── ================= 自己写的 =================
├── pages.json                    ★页面路由 + 底部 tabBar 定义
├── App.vue / main.js             uni-app 入口
├── manifest.json / uni.scss      app 配置
├── pages/          ✅ 页面(自己写)
│   ├── home/      首页·召唤机甲 + 场景方案 + 详情(jineng/rmfangan)
│   ├── robot/     机器人库(index 6万行) + 场景详情(fangan)
│   ├── ai/        AI召唤中心 index.vue
│   ├── order/     订单列表/订单详情/结算(checkout,最近在改)
│   ├── mine/      我的(index.vue 6万多行,最近在改)
│   └── tupian/    只有一张图 logo_PNG.png,不是代码
├── components/     ✅ 自定义组件
│   ├── CustomTabBar.vue、EcoCenter.vue
│   ├── jiqi/       changjing.vue 是「场景方案」唯一数据源(其余 jineng/peijian 机甲相关)
│   │                ⚠ goumai.vue 是 0 字节空文件
│   └── shouye/     dongtai.vue
├── utils/          ✅ 与后端对接的唯一入口(核心)
│   ├── config.js   BASE_URL(现为 http://127.0.0.1:8000)/超时/token key
│   ├── request.js  统一请求封装:Bearer token 注入、401 清登录态
│   ├── api.js      全部 API 清单:认证/商品/分类/购物车/结算/订单/地址/赛事(eventApi)/召唤(summonApi)/目录聚合(catalogApi)
│   └── address.js  地址行转换:后台接口行 ↔ 前端展示行(mine/checkout 共用,登录走接口游客走本地)
├── tools/          ✅ 运维脚本:bash tools/api-regression.sh 一键 API 体检(7项)
├── static/         ✅ 图片素材(png),不用读
├── unpackage/      ❌ 构建产物,别动
├── .claude/        Claude 配置(settings.local.json)
│
└── my-bagisto-store/   ============================================
    │   Bagisto (Laravel) 后端 = 本商城的 API 服务器
    │   ★ 前端 uni-app 只通过 REST API 访问它(见 utils/api.js)
    │   ⚠ 前台 Shop(Shopfront)已恢复启用(2026-08-27 还原,仅作演示,业务不走它)。历史记录见:
    │     my-bagisto-store/docs/CHANGES-shopfront-disabled.md
    │   ★ 后端一切代码都有用 —— 改字段、改接口、改后台管理都来这里。
    │   ✅ 最常改 —— 自定义 API 代码(uni-app 对接的数据流)
    │     packages/Webkul/Shop/src/Routes/api.php
    │     packages/Webkul/Shop/src/Http/Controllers/API/
    │                             Customer/Order/Product 控制器
    │     packages/Webkul/Shop/src/Http/Middleware/ResolveApiCustomer.php
    │     packages/Webkul/Shop/src/Providers/ShopServiceProvider.php
    │     app/Providers/AppServiceProvider.php
    │   ✅ 有用 —— 后台管理
    │     packages/Webkul/Admin  后台管理界面(需要时可改)
    │   🔶 有用但一般不动 —— 后端业务层/框架(报错或要改字段时才查)
    │     packages/Webkul/{Core,Customer,Checkout,Sales,Product,Category,Rule,Tax...}
    │     app/Http、config/、database/、routes/web.php、lang/、public/、tests/
    │   ❌ 唯一无关 —— 开源项目带的前端交互页面(给浏览器看的 HTML 网页)
    │     packages/Webkul/Shop(非 API 部分)网页商店 Shopfront(Blade 页面)
    │     packages/Webkul/Theme、resources/views、resources/themes、CMS 前台
    │   ❌ vendor/  composer 依赖,永远别动
```

## ★ 场景方案 · 平台级整合（本阶段主线）

背景：原先前端写死「热门方案 + 场景」两套，现合并成**一套「场景方案」**——首页/机器页/详情页共用**同一个数据源**，后台可管理。每次改动记录在 `docs/scene-merge-log.md`（崩溃修复先读它）。

### 前端数据源（唯一入口）
- `components/jiqi/changjing.vue` ——**唯一数据源**，导出：
  - `sceneProducts`：本地兜底场景(28 项，无 is_hot、无 consultCount)；`loadBackendScenes()`：拉后端 `GET /api/scenes` 按 id 合并(is_hot/icon/bg/banner/consultCount/sort)。
  - `SCENE_SCHEMES`：每场景专属「方案定制」内容；`SCHEME_DB` / `DEFAULT_SCHEME`：旧带图方案与兜底。`featuredScenes` 是遗留(首页已不用)。
  - `normalizeImageUrl`：把后端 banner 里的 `http://localhost` 换回 `BASE_URL`(否则小程序/H5 加载 localhost 图失败)。
- 消费方：`pages/home/index.vue`(首页场景方案)、`pages/robot/index.vue`(场景 Tab)、`pages/robot/fangan.vue`(场景详情)。

### 首页 / 机器页 / 详情页
- `pages/home/index.vue`：场景方案卡 = **图片 + 标题 + 咨询人数**；`refreshScenes()` 按后端 `is_hot` 显示热门(无热门回退全部)；`data.schemes` 初始 `[...sceneProducts]`。
  - **⚠ 已踩的坑**：`onLoad`/`onShow` 必须和 `methods` **平级**，放进 `methods` 里框架不触发 → `refreshScenes` 永不执行、首页死卡/空卡。
- `pages/robot/fangan.vue`：场景详情。顶部大图 + **两个按钮**「场景租用 / 方案定制」；租用=场景说明+适用机器(来自目录「租用」分类产品)；定制=痛点/亮点/收益；`loadTobScene` 优先读后端 `scheme`，`loadRentProducts` 拉租用产品。
- `pages/robot/index.vue`：场景 Tab；`filteredSceneList` 按 `sort` 权重降序，依赖响应式 `sceneVersion`(merge 完成自增强制重算)。

### 后端 scenes 表 + 后台场景管理
- `my-bagisto-store/packages/Webkul/Shop/src/Models/Scene.php`：场景模型。fillable/casts 含 icon/bg_color/consult_count/sort/is_hot/robots/...；`getSchemeAttribute()` 组装完整方案(前端直接用)；`getBannerUrlAttribute()`。
- `packages/Webkul/Shop/src/Http/Controllers/API/SceneController.php`：`index()`/`show()` 返回 id/name/category/description/banner/icon/bg_color/is_hot/consult_count/sort/scheme。
- `packages/Webkul/Admin/src/Http/Controllers/Catalog/SceneController.php`：后台 CRUD + `updateStatus`(状态开关) + `toggleHot`(热门开关) + `updateConsultCount`(咨询人数内联编辑) + `uploadBanner`(原生 `storeAs` 上传，绕开 x-admin 组件 bug)。
- `packages/Webkul/Admin/src/DataGrids/Catalog/SceneDataGrid.php`：列表列(id/name/category/sort/consult_count/is_hot/status) + 批量 + 权限。**⚠ 列闭包读的字段必须进 `prepareQueryBuilder()->select(...)`**，否则列表 500。
- 表单 / 路由 / 校验 / 语言包：
  `Admin/src/Resources/views/catalog/scenes/{create,edit,index}.blade.php`
  `Admin/src/Routes/catalog-routes.php`(scenes 组，含 status/hot/consult-count/mass-*)
  `Admin/src/Http/Requests/SceneRequest.php`
  `Admin/src/Resources/lang/{zh_CN,en}/app.php`(catalog.scenes.*)
- migrations：`..._create_scenes_table`、`..._add_scheme_columns_to_scenes_table`(icon/bg_color/consult_count/tags/stats/pains/benefits/scheme_scenes/robots)、`..._add_sort_to_scenes_table`、`..._add_is_hot_to_scenes_table`。

### 场景数据流
后端 `scenes` 表 → `GET /api/scenes`(index) → `changjing.loadBackendScenes()` → `sceneProducts` 合并 → 首页 `filter(is_hot)` 显示热门 / 机器页按 sort 排 / 详情 `GET /api/scenes/{id}` 读 scheme。

### 踩坑记录（重要）
- 首页生命周期钩子别放 `methods` 内(见上)。
- `SceneDataGrid` 加列必须同步 `select` 该字段，否则后台列表 500。
- 后端 `APP_URL=http://localhost` → banner 拼 localhost → 前端必须 `normalizeImageUrl`。
- 后端跑 `http://127.0.0.1:8000`，CORS 已允许 `*`；前端带 token GET 会触发 OPTIONS 预检，Laravel `HandleCors` 已放行。
- 改后端文件：控制器/路由/DataGrid 改动需**重启后端 PHP 服务**；blade/语言包改动 `php artisan view:clear` + 刷新后台即可。

## ★ 夜班新增功能架构（2026-09-07，均已闭环可用）

### 1. 订单分类体系（order_type）
- orders 表 `order_type` 列：rent/buy/plan/custom/skill/accessory（迁移含存量回填）。
- 后台订单页顶部**类型页签**（全部/租用/购买/方案/定制/技能/配件）+ 过滤面板"类型"下拉。
- 归类规则：tob-order 落类型；**场景租用(from=scene)→plan**；技能/配件从 checkout 白名单透传（不再被吞成 buy）。
- 前端订单列表/详情的类型显示走 `/api/customer/orders` 返回的 `order_type`（OrderController::formatOrder 已补字段）。

### 2. 场景租用多机合单
- fangan 场景租用页：机器卡片 ⊕ 多选 → 底部结算栏（1/3/7/30 天全场统一租期）→ checkout 多机清单模式 → tob-order `items[]` **一单多条目**。

### 3. 赛事活动平台级闭环
- 数据：`events` / `event_registrations` 表（⚠ Bagisto 自带 marketing_events 是另一张表，勿混淆）。
- 后台：目录 → 赛事活动（CRUD + 报名列表）。
- 前台：EcoCenter 赛事 tab 拉 `/api/events`（失败回退 mock）；报名弹窗 POST `/api/events/{id}/register` 真实落库（同手机号同赛事防重复）。

### 4. 小召·召唤引擎（AI 页灵魂入口）
- `POST /api/summon`：需求文本 →（拆词+15组同义词表）→ 场景打分 → rent 分类机型打分 → 返回最佳场景(含方案)/推荐机型/备选；`engine: rule-v1` 标记，后续可平滑换 LLM。
- AI 页 onSendMsg 真实调用，结果流卡片：场景→查看方案、机型→立即租用、备选切换。

### 5. 信任与自助层
- 订单自助取消：`POST /api/customer/orders/{id}/cancel`（仅本人+仅 pending，状态机校验），详情页按钮真实化。
- 诚实化扫尾：登录/注册无假登录态；我的页开票/合伙人/会员等未接后端的交互统一"开发中"提示；手机号显示真实数据。

### 6. 地址统一（T3）
- 登录：mine 地址簿 + checkout 结算均走 `addressApi`（含新增 destroy 接口）；游客：本地存储双模式。张三李四假数据已清。

### 7. 其他
- 机器人库：租/购页搜索框（本地过滤）；品牌/类别筛选 v1 按商品名关键词推断。
- 订单详情接真接口 getOrderDetail；列表 6 条假种子已删。
- 业务表备份：`cd my-bagisto-store && D:/php.exe tools_backup_db.php`（11 表 JSON 按日期归档到 .claude/backup/db/）。

## ★ 技能供需平台（skill supply / demand）— 2026-10 开发中

> **现状（2026-10-14）**：v1 底座已上线（2026-09-30 skill_delivery 迁移）；**v3.4 计划已按步骤开发到第 8 步** ——
> 第 7 步（用户端前端）**已完成**，第 8 步（Agent 下发）**平台侧已完成**；第 2/3/4/5/6 步为"主体完成、各留缺口"。
> **先看 [`docs/skill-dev-steps.md`](./docs/skill-dev-steps.md) 的【进度快照】**——那里是唯一权威的进度口径（按代码实测核验，非仅凭文档）。
> ⚠ **改这块之前，先读计划**（`docs/skill-supply-plan.md` + `docs/skill-dev-steps.md`），别凭现状改。

### 要读的文档（**完整清单见 `skill-supply-plan.md` §12**；先读第一个）
| 文档 | 内容 |
|---|---|
| `docs/skill-supply-plan.md` | **主开发计划 v3.4**（§0.5 定位边界 / §3 数据模型 / §4 功能 / §11 全球 / §12 文档索引） |
| `docs/skill-dev-steps.md` | **施工顺序 + 【进度快照】**（第 1→10 步 / 每步缺口 / 里程碑状态）← **想知道做到哪了看这个** |
| `docs/skill-dev-step8.md` | 第 8 步交付说明（Agent 下发：签名/状态机/一键下发/灰度回滚/参考 Agent） |
| `docs/skill-p0-board.md` | **P0 可执行看板**（任务 / 人日 / 依赖 / 里程碑） |
| `docs/agent-adaptation-plan.md` | **Agent 落地判定 + F1–F5 功能 + 协议** |
| `docs/skill-standardization-chain.md` | **技能转换层**（上传↔编译↔下发 三端标准化） |
| `docs/skill-quality-and-review.md` | **质量体系 + 过审门槛 + 拒绝清单** |
| `docs/skill-user-tutorial.md` | **用户手册**（零基础装 Agent） |
| `docs/device-model-seed.md` | 机型库种子数据（宇树/智元/众擎…） |
| `docs/skill-plan-verification.md` | 逐项验证矩阵（已有/缺失/假设不成立，带代码行号） |
| `docs/skill-flows-and-readiness.md` | 开发前准备 + 三端交互路径 |
| `docs/skill-plan-team-review-2026-10.md`、`skill-plan-audit-2026-10.md`、`skill-plan-boss-response.md` | 团队评审 / 三端体检 / Boss 决议回应 |

### 代码在哪（v1 已上线部分）
- **表结构**：`my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_09_30_000001_create_skill_delivery_tables.php`（`sellers`/`skill_versions`/`skill_assets`/`devices`/`skill_entitlements`）+ `..._000004_add_verify_status_to_devices.php`
- **模型**：`Shop/src/Models/{SkillVersion,SkillAsset,SkillEntitlement,Device}.php`
- **C 端接口**：`Shop/src/Http/Controllers/API/SkillController.php`（mine/assets/download/bindDevice/confirmInstall）、`DeviceController.php`；路由 `Shop/src/Routes/api.php`（前缀 **`/api/skills/*`**、`/api/devices/*`）
- **上传通道**：`Shop/src/Http/Controllers/API/SkillAdminController.php` + 中间件 `VerifyInternalKey.php`（X-Internal-Key，key=`SKILL_UPLOAD_KEY`）
- **交付通道**：`config/skill_delivery.php`（默认 `app` = 人工通道）+ `Services/SkillDelivery/AppChannel.php`
- **发授权监听**：`Listeners/SkillDelivery.php`（挂 `checkout.order.save.after`，**幂等、吞异常**——为建单重试兜底）
- **后台**：`Admin/.../Catalog/SkillController.php` + `Admin/src/Routes/catalog-routes.php`
- **前端**：`pages/mine/skills.vue`、`pages/mine/devices.vue`、`utils/api.js` 的 `skillApi`/`deviceApi`；技能市场在 `components/EcoCenter.vue`(首页生态中心) + `pages/robot/index.vue`(机器页技能 tab)。⚠ `pages/home/jineng.vue` 是 **mock**（未接后端）

### v3.4 新增代码在哪（第 2–8 步，均在 `my-bagisto-store/packages/Webkul/Shop/src/`）
- **下发链路**：`Services/Deploy/`（`DeployTaskService` 状态机 / `SigningService` RSA 签名 / `AgentGateway` + `HttpPollAgentGateway` HTTP 轮询真传输 / `KillSwitchService` 熔断 / `GrayReleaseService` 灰度自动回滚）；接口 `Http/Controllers/API/{AgentController,DeployController,GrayReleaseController}.php`
- **Agent 安装脚本**：`Http/Controllers/AgentSetupController.php`（输出 `agent/setup.sh`、公钥、参考 `agent.py`）——路径**故意不叫 install.sh**（含 `/install` 会被 Bagisto Installer 中间件重定向到 `/admin`）
- **技能供给侧**：`Services/{SkillPackage,SkillAudit,SkillCompile,SkillImport,SkillQuality}/` + `Http/Controllers/API/SkillSupplyController.php`
- **设备/机型**：`Services/SkillDelivery/{DeviceBindingService,CompatibilityService}.php`、`database/seeders/DeviceModelSeeder.php`
- **C 端页面**：`pages/mine/{deploy,agent-setup}.vue`（下发进度、一键配对）
- **测试**：`packages/Webkul/Shop/tests/Feature/API/AgentDeployTest.php`

### 约定 / 坑（改前必看）
- 交付通道：代码内 **`app` ≡ 计划 `manual`**；`skill_versions.channel` 是**发布通道**（stable/beta），**≠** `delivery_channels`（交付通道）
- `skill_assets.carrier_type` 白名单 = **`file/cloud_config/remote_service/api_bundle` + v3.4 扩的 `motion_data/app_package/vendor_package`**（以 `SkillAsset::CARRIER_TYPES` 为准）
- **设备唯一真相源 = `device_binding`**（下发链路只认它）；`devices` 是用户自报登记。⚠ v1 的 `mine/bind/confirm-install` **尚未**改读 binding（只做了加性回填）
- `.env`：`QUEUE_CONNECTION=database`、`CACHE_STORE=database`（原 sync/file；**改用 database 驱动而非 Redis**，将来切 Redis 改 2 行 env）。**仍无 scheduler**，故灰度自动回滚靠"设备回报后自驱"，不靠定时任务

### 问法定位
- "技能计划 / 改技能" → `docs/skill-supply-plan.md` + `docs/skill-p0-board.md`
- "**做到哪了 / 进度 / 还剩什么**" → `docs/skill-dev-steps.md`【进度快照】
- "技能表 / 字段" → 上面 migration + `Shop/src/Models/Skill*.php`
- "技能接口 / 下载 / 绑定 / 自报安装" → `SkillController.php` + `Routes/api.php`
- "技能上传 / 建版本" → `SkillAdminController.php`
- "技能下发通道" → `config/skill_delivery.php` + `Services/SkillDelivery/`
- "Agent / 下发 / 装机 / 灰度 / 回滚 / 熔断" → `Services/Deploy/` + `AgentSetupController.php` + `docs/skill-dev-step8.md`
- "机型库 / 兼容性校验" → `docs/device-model-seed.md`
- "技能前端页" → `pages/mine/skills.vue`、`components/EcoCenter.vue`、`pages/robot/index.vue`

## 关键联结点（数据流）

前端页面 → `utils/api.js` 里某个 `xxxApi` → `utils/request.js`(带 Bearer token、超时、401 处理)
→ 后端 `packages/Webkul/Shop/src/Http/Controllers/API/*` → Bagisto 业务层。

- 后端地址在 `utils/config.js` 的 `BASE_URL`(默认本机 `http://127.0.0.1:8000`;真机/小程序要改局域网 IP)
- 后端 API 路由在 `packages/Webkul/Shop/src/Routes/api.php`

## 约定 / 注意事项

- 页面/组件均为 vue3 + `<script setup>` 风格的单文件组件，`navigationStyle: custom` 自定义导航。
- 最近活跃文件(改代码优先看这些)：components/jiqi/changjing.vue(场景数据源)、home/index.vue、robot/index.vue、
  robot/fangan.vue、utils/api.js、docs/scene-merge-log.md；以及后端 Admin/Shop 的 Scene 相关文件。
- 改后端或 API 契约时，前端和后端两侧都要对齐(字段名、路由、方法)。
- 前台 Shop 已恢复启用(2026-08-27 还原,仅作演示,业务不走它)；历史见 `my-bagisto-store/docs/CHANGES-shopfront-disabled.md`。
- 图片都在 static/，新页面用图直接从那里引用。
- my-bagisto-store 的网页交互页面(Shopfront/Theme/CMS 前台)非核心业务；后端 API 与 Admin 后台才是本项目所用。

## 快速定位常用问法

- "首页/机器人/AI/订单/我的" → `pages/{home,robot,ai,order,mine}/index.vue`
- "场景方案/热门/首页场景卡" → `components/jiqi/changjing.vue`(数据源) + `pages/home/index.vue` + 后端 `Scene*`
- "后台场景管理/咨询人数/热门开关" → `Admin/.../Catalog/{SceneController,SceneDataGrid}.php` + `scenes/*.blade.php`
- "首页场景卡不改/显示死卡" → 先查 `home/index.vue` 的 `onLoad`/`onShow` 是否误放 `methods` 里(必须在顶级)
- "改场景/崩溃修复参考" → `docs/scene-merge-log.md`
- "接口问题" → `utils/api.js` + `packages/Webkul/Shop/src/Routes/api.php`
- "请求没带 token / 401" → `utils/request.js`
- "改后端地址" → `utils/config.js`
- "订单分类/类型页签" → `OrderDataGrid.php` + `orders/index.blade.php`(页签+localStorage清理脚本) + `TobOrderController`(order_type 归类)
- "赛事/报名" → 后端 `Shop/.../API/EventController.php` + 后台 `Admin/.../Catalog/EventController.php` + 前端 `components/EcoCenter.vue`
- "小召/召唤/AI 匹配" → `Shop/.../API/SummonController.php`(同义词表在此) + `pages/ai/index.vue`
- "多机合单/场景租用" → `pages/robot/fangan.vue`(⊕选择+结算栏) + `TobOrderController`(items[])
- "取消订单" → `OrderController::cancel` + `pages/order/order-detail.vue`
- "地址" → `utils/address.js` + `AddressController`(destroy) + mine/checkout 双模式
- "接口体检" → `bash tools/api-regression.sh`；"备份业务表" → `tools_backup_db.php`
- "技能 / 技能市场 / 技能下发 / 技能供需" → 见上「★ 技能供需平台」段（`docs/skill-supply-plan.md` + `docs/skill-p0-board.md`）

## 工作区备忘（2026-09-07 更新）

- **my-bagisto-store2/** = 用户手动备份（2026-08-28 快照：含 tob-order、无 scenes/order_type 功能）。**保留勿删**；勿从它启动服务或部署——主后端永远是 `my-bagisto-store`，唯一端口 `127.0.0.1:8000`（8011 的多余 serve 进程已于 9/7 清除，若再出现请查启动脚本）。
- **_prevai_log.txt** = 历次 AI 协作修改的会话日志（商品图片修复等来龙去脉），历史资料勿删。
- **bagisto/（根目录）** = 空目录，2026-09-07 已删除。
- **docs/**：ai-changelog.md（AI 改动流水 + 恢复方法）/ scene-merge-log.md（场景整合记录）/ **UPGRADE-NOTES.md（Bagisto 核心改动 16 处清单，升级版本前必读）**。
- **后台账号** admin@zhaohuan.com：密码 2026-09-07 已更换（向项目负责人索取）。
- **测试订单**：9/7 已清理 18 条测试单（备份 JSON 在 `.claude/backup/T4-ordertype/`），剩余 41 条为真实流程单；后台订单页顶部有**类型页签**（全部/租用/购买/方案/定制/技能/配件）。
- **计算机使用纪律**：AI 不主动控制用户屏幕，仅在用户明确要求时使用。