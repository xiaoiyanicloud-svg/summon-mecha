# 机器人技能供需平台 · 开发计划（融合修订版 v3.4）

> 版本：v3.4 ｜ 日期：2026-10-13
> 前身：v3.3 / v3.2 / v3.1 / v3.0（2026-10-10）+ v2.0《召唤机甲·机器人技能分发平台开发计划》
> 本版性质：**基于现平台已上线的技能供需 v1（skill_delivery 底座）的演进式开发计划**，不推倒重建。
>
> ⚠ **本文是计划，不是现状**。计划里描述"现在是 X"的句子会随时间失效 —— 引用前请对照代码。
> 已核实失效的一处：§7 阶段 0 的「异步底座」原文（已就地更正）。
> 现状以 [skill-remaining-closure.md](./skill-remaining-closure.md) 与 [skill-dev-steps.md](./skill-dev-steps.md) 的进度快照为准。
>
> ⚠ **2026-10-17 口径校准（S-7）**：本文以下说法已作废，引用前以代码为准：
> - 「独立 Vue3 SPA 开发者门户」→ 现状是 **Blade SSR**（`Resources/views/dev/*` + `Routes/dev.php`），不是 SPA；独立域名属 E2。
> - 「`manifest.yaml`」→ `symfony/yaml` 未安装，**实际只有 `manifest.json` 可用**（`ManifestParser` 对 `.yaml` 会回落报错）。
> - 「传一次 → 平台转各机型 → 多机型可下」→ 编译层已如实降级为**登记**（D-9）：`skill_build` 不在交付链路上、`BuildResult::success()` 零调用点，产物需外部/人工产出。
> - 「第三方开发者为 0、不为其设计功能」→ **2026-10-16 已拍板真实引入（邀请入驻）**，开发者侧链路（S-11a–e）已落地。
> - 熔断「一键禁用所有设备」→ 实际：对新下发即时、对已装设备**下次上线**生效、**人工通道安装的当前无法自动卸载**。

> **v3.4 · 细节统一版（把散在专项文档里的设计并入主计划）**
> 1. **§4.20 技能转换层（技能编译器）**＋`skill_build` 表——补上"开发者传一次、平台转成各机型载体"的关键中间层（依据 [skill-standardization-chain.md](./skill-standardization-chain.md)）。
> 2. **§4.21 技能质量体系与过审门槛**＋`skill_quality` 表——"好/一般并存是正常市场，垃圾不过审"（依据 [skill-quality-and-review.md](./skill-quality-and-review.md)）。
> 3. **§4.22 Agent 落地（判定 + 功能明细）**——三厂可自主部署的判定表 + Agent F1–F5 功能（依据 [agent-adaptation-plan.md](./agent-adaptation-plan.md)）。
> 4. **§4.8 审核** 升级为**三层准入 + 拒绝清单**（机审 / **仿真预跑** / 人审）。
> 5. **§4.17 合规** 落地为**中国/美国分栏清单**（依据 [skill-plan-boss-response.md](./skill-plan-boss-response.md)）。
> 6. **§12 专项文档索引**——主计划给"决策与契约"，专项文档给"深度细节"。

> **v3.3 · 技能功能修正版（保留备查）**：修正文档与 v1 代码不符的硬错（`carrier_type` 词表 / `license_mode` / `app≡manual` / 设备真相源 / 搜索物化表 / 异步底座前置）。
> **v3.2 · 三端补全版（保留备查）**：开发者端独立门户、合规、用户端补全、熔断、事故闭环；缺口按"四栏"重列；排期 4 周→8–10 周。
> **v3.1 · 行业对齐版（保留备查）**：技能包规范重写（中立 manifest + 导入器）、供给冷启动、物理安全三层责任、行业对标。

> **v3.2 相对 v3.1 的修订（保留备查，依据三端深度体检）**
> 体检结论：用户端 ~60%、平台端 ~55%、**开发者端 ~20%**、合规安全 ~10%。v3.2 把缺口一次补齐。
> 1. **§0 新增 D7–D11**：独立开发者门户（Web 为主，小程序/App 可打开）、三端分离、合规三层备案、技能熔断、虚拟商品售后。
> 2. **§1.2 缺口盘点重列**：按"用户端/开发者端/平台端/合规"四栏重报。
> 3. **§2 架构拆第三端**：新增**开发者门户**。
> 4. **§3.3 新增数据表**：开发者/凭证/评价/工单/通知/类目/内容安全/熔断/售后/对账/备案/事故。
> 5. **§4.15–§4.19 新增五节**：开发者端全模块、平台端补全、合规与备案、用户端补全、技能熔断与事故闭环。
> 6. **§5/§6/§7/§8/§9 同步扩容**：接口、页面、排期（4 周 → 8–10 周）、风险、决策重审。
> 7. **§9 决策反转**：v3.1"砍独立双后台"作废——**新增独立开发者门户**。

---

## 0. 已拍板的决策记录

| # | 决策项 | 结论 | 设计影响 |
|---|---|---|---|
| D1 | 设备归属模型 | **登记制 + 绑定表分层**：`devices` 保留"用户登记视角"（customer_id+sn 唯一，允许同一 SN 被多人登记，防抢注）；新建 `device_binding` 承载"真实设备全局视角"（DID 全局唯一、归属、在线态） | v1 数据不迁移；下发链路面向 device_binding |
| D2 | 下发通道 | **三通道可选**：① MQTT+设备 Agent（开放机型主路）② 厂商云 API 适配（封闭机型，P1 按厂商接入）③ 下载+人工安装（兜底，继承 v1）。技能声明支持通道 ∩ 设备可用通道 → 用户可选 | Agent 协议内预留厂商适配插件位；`skill_assets.carrier_type` 天然支持一版本多通道资产 |
| D3 | 授权模式 | **买断制**：购买后当前版本技能永久可用，无订阅。后续小版本免费更新（V1 简化结算），大版本开发者可选按新商品定价（V2） | ⚠ **v3.3 修正**：买断用新建的 `pricing_mode='buyout'` 表达；**删掉 v3.1/v3.2 误写的 `license_mode='perpetual'`**——v1 的 `license_mode` 是**授权范围**语义（合法值仅 `per_device/per_order/per_account/unlimited`，见 `SkillAdminController`），与"买断"正交，不改。买断型不填 `expires_at` |
| D4 | 试用功能 | **保留，20 分钟自动回收，限在线通道**（可行性判定见 §4.7：可行）。人工安装通道技能无试用 | 试用 = 带 expires_at 的 entitlement + 每分钟调度回收 + uninstall 指令 |
| D5 | 技能包格式 | **不自创格式**：定义平台中立 `manifest.yaml` 超集（概念对齐宇树 `metadata.yaml`），**载体与动作数据分离**，并为宇树/智元/通用动捕各留一个**导入适配器**——他平台技能可导入本平台 | `.skill` 内含 `manifest.yaml + motion/ + app/ + assets/ + vendor/`；`skill_versions` 增 `manifest_schema / source_format / imported_meta`；新增 `skill_import` 表 |
| D6 | 物理安全 | **三层责任模型**：开发者责任（准入签承诺+权限声明）/ 沙盒（静态扫描 P0 + 设备端受控执行+仿真预跑）/ 用户知晓（分级风险告知+留痕） | `manifest.safety` 必填；`security_scan` 扩类；`risk_acknowledgment` 分级；沙盒执行提前到 P0 |
| D7 | 开发者门户 | **独立开发者门户，Web 为主**（Vue3 SPA，桌面优先，对标智元控制台/App Store Connect）；**小程序 / App 通过 web-view 打开同一站点**（轻量视图：审核状态/数据概览/消息；传包等重操作引导到 Web）。**不塞进 Bagisto Admin** | 新增第三端；§9"砍双后台"决策作废；`developer_*` 表族；开发者 OAuth + API Key |
| D8 | 合规 | **三层 AI 备案**（算法备案 + 大模型备案 + **拟人化互动服务安全评估**）＋ 内容安全检测 ＋ 隐私政策/个保法 ＋ 机器人安全责任边界（对齐杭州条例/人形机器人安全国标） | 新增 `compliance_filing`、`content_security_record` 表；**备案为外部审批，阶段 0 前置并行**（同微信支付） |
| D9 | 技能熔断 | **kill switch**：安全事件时一键对**已安装设备**远程禁用 + 回滚，并全网紧急下架 | 新增 `skill_blocklist` 表；复用 deploy_task 的 uninstall/rollback |
| D10 | 售后政策 | **虚拟商品售后规则单列**（技能=虚拟交付，不照搬实物）：试用不退款、买断在"未下发/下发失败"可退、已激活原则上不退但可申诉 | 新增 `after_sale` / `refund_request` 表；退款联动 revoke entitlements |
| D11 | 事故闭环 | **事故上报 + 责任追溯 + 责任保险**（物理安全的下游） | 新增 `incident` 表；`operation_log` 贯穿追溯链 |

---

## 0.5 平台定位与边界（v3.3 新增 · 防跑偏准绳）

> 任何后续设计先对照本节；对不上就砍。

**一句话定位**：面向**全球**的**机器人技能（动作 / 能力包）供需交易与下发平台**——开发者上架技能，用户购买并下发到**自有**机器人；平台负责**交易 / 审核 / 授权 / 交付 / 结算 / 安全责任**六件事。

**边界（不是这些，禁止跑偏）**
- ❌ 不是机器人整机 / 配件销售平台（那是现有商城业务）
- ❌ 不是训练 / 算力平台（不自建训练集群、不做数据采集外包）
- ❌ 不是硬件厂，不做固件、不做整机
- ❌ 不制造"所有品牌开箱即用"的幻觉

**兼容性承诺分级（把"现实"写进承诺里）**

| 级别 | 定义 | 承诺 |
|---|---|---|
| **L1 可交付** | 该机型已有可用通道（人工 / Agent / 厂商 API） | 承诺**下发可用** |
| **L2 可适配** | 有通用动作数据 + 重定向配置 | 承诺"**可转换**"，不承诺开箱即用（跨本体重定向行业普遍不成熟） |
| **L3 仅展示** | 机型在库但无任何通道 | **只展示、不发售**（诚实，不硬撑） |

**"全球"的现实解**：多语言（zh/en 起步）、多币种、多支付（微信 / 支付宝 / Stripe / PayPal）、区域化合规、全球存储加速。**顺序是先跑通设备与客户所在区域，架构预留全球，不追求"一步全球"**（详见 §11）。

**现实性分级（对全计划的一个总开关）**：每个功能标注 **现实 P0**（有底座、能落地）／**条件触发**（等某个外部前提，如真机 Agent、第三方开发者）／**暂不现实**（列为观察，不排期）。**禁止把"条件触发/暂不现实"当 P0 排期。**

---

## 1. 现状底座盘点（复用清单，防止重复建设）

### 1.1 已上线的 v1 能力（2026-09-30 skill_delivery 迁移）

| 层 | 已有资产 | 复用方式 |
|---|---|---|
| 数据表 | `sellers`（含 commission_rate 分成预留）、`skill_versions`（version/channel/status/license_mode/max_activations/valid_days/min_robot_version/changelog/install_guide）、`skill_assets`（carrier_type/file_path/sha256/entrypoint，唯一键 version+carrier+sort → **天然支持一版本多通道资产**）、`devices`（customer_id+sn 唯一、model_code、firmware_version、verify_status）、`skill_entitlements`（**order_item 唯一键幂等发授权**、license_key、activations、expires_at、revoked_at）、`products.seller_id` | 全部保留，按 §3 扩展列 |
| C 端接口 | `/api/devices` CRUD、`/api/skills/mine`、entitlements 指派设备/资产列表/鉴权下载/confirm-install 自报安装 | 保留；新增下发/兼容性/试用接口 |
| B 端 | Bagisto Admin 已配 `catalog.skills` 菜单 + ACL；`SkillAdminController`（internal.key 内部建版通道） | 升级为审核队列与版本管理模块载体 |
| 前端页面 | 技能市场（首页生态中心 tab + 机器页技能 tab）、技能详情（pages/home/jineng + 详情浮层）、`pages/mine/skills`、`pages/mine/devices`、订单页 | 全部保留，新增 4 块（§7） |
| 基建 | coze docker compose 内已有 **MinIO、Redis**（未暴露端口） | 暴露端口/共网复用，不新起 |
| 种子数据 | `components/jiqi/skill-data.js`：40+ 品牌 × 机型映射 | 直接迁移为 `device_model` 机型库初始数据 |

### 1.2 净缺口总览（v3.2 按"四栏"重列）

> v3.1 只列了 5 项、且漏了整个开发者端与合规 → 排期系统性低估。按头部水平重列：

| 栏 | 已有/可复用 | 净缺口（按优先级） |
|---|---|---|
| **用户端** | 交易/设备/技能使用主链路；评价(`ProductReview`)/优惠券(`CartRule`)/CMS/活动/注销 **后端原生但有坑**：评价/优惠券 API 在、`product_reviews`/`cart_rules` 均 **0 行**、**未接技能、前端零入口**（详见 [verification C-6](./skill-plan-verification.md)） | ① 微信支付 ② **虚拟商品售后/退款政策** ③ **客服工单** ④ **消息通知中心** ⑤ **技能专属搜索/筛选** ⑥ 评价/优惠券**接入技能+前端页** ⑦ 设备解绑/转移+一机一码 ⑧ 发票 |
| **开发者端** | （几乎为零）`sellers` 表 + `SkillAdminController`(internal.key) | ① **独立开发者门户** ② 入驻/协议/责任承诺 ③ **凭证与 Key 生命周期** ④ **预发布测试轨** ⑤ **开发者数据看板** ⑥ 开发者结算面板 ⑦ 审核状态/驳回/申诉 ⑧ 文档/SDK/CLI ⑨ 工具链入口（仿真/真机调试/重定向） |
| **平台端** | 审核/机型/结算/看板/灰度/导入 | ① **内容安全** ② **技能熔断 kill switch** ③ **工单/纠纷仲裁** ④ 开发者/商户管理后台 ⑤ 技能类目属性 ⑥ 对账 ⑦ 风控（刷单/薅试用/支付） ⑧ ACL 细粒度分权 ⑨ 事故上报闭环 |
| **合规与安全** | 物理安全三层责任模型（§4.14） | ① **AI 三层备案** ② 隐私政策/个保法 ③ 责任边界与国标对齐 ④ 违规内容处置 ⑤ 责任保险 |
| **底座（v1 已上线）** | `sellers / skill_versions / skill_assets / devices / skill_entitlements`、下单幂等发授权、通道注册表 | 全部保留 |

---

## 2. 总体架构

```
┌────────────────────────────────────────────────────────────┐
│  用户端 uniapp(微信小程序)        开发者/平台端             │
│  市场/详情/我的技能/设备          Bagisto Admin(审核/运营/  │
│  +新增: 下发进度/结果页           技能版本/结算/开发者)     │
└──────────────┬──────────────────────┬──────────────────────┘
               │ /api/*(customers token)│ admin panel + internal.key
┌──────────────▼──────────────────────▼──────────────────────┐
│  Laravel/Bagisto 交易底座（现有，扩展）                     │
│  账号/商品/订单/支付/授权(entitlements)/审核记录/结算       │
│  兼容性校验 ｜ 试用回收调度(每分钟) ｜ operation_log         │
└──────┬───────────────────────────────┬─────────────────────┘
       │ internal.key HTTP             │ 复用 coze: MinIO(技能包)
┌──────▼───────────────┐    ┌─────────▼─────────┐  ┌─────────▼────────┐
│ 下发调度服务(Go, 新建)│    │ EMQX MQTT Broker  │  │ Redis(复用coze)   │
│ deploy_task 状态机    │◄──►│ 设备长连接/在线态  │  │ 锁/队列/热数据    │
│ 三通道路由/重试/签名  │    │ (设备端 Agent)     │  └──────────────────┘
│ 厂商API适配器(P1)     │    └───────────────────┘
└──────────────────────┘
```

**技术选型与原则**

| 项 | 选型 | 理由 |
|---|---|---|
| 交易/审核/授权 | Laravel（现有 Bagisto） | v1 幂等设计已验证；沿用 events/event_registrations 扩展模式加表 |
| 下发调度/Agent 协议 | Go 独立服务 | 状态机+长连接并发；通过 internal.key（已有先例）与 Laravel 通信 |
| MQTT Broker | EMQX（docker） | 自带设备在线态、规则引擎、监控看板（P0 监控白嫖） |
| 对象存储 | 复用 coze 的 MinIO | 暴露端口或共网；后续 CDN 挂前面 |
| 缓存 | 复用 coze 的 Redis | 同上 |
| 数据库 | 现有 MySQL 5.7（phpstudy）不动 | 新表全部进 Bagisto 库；**不新起 MySQL 8 / ES** |
> **⚠ v3.2 注（架构改为三端，上图待重绘）**：v3.1 把"用户端 / 开发者+平台端"并成两栏，**与"头部水平"冲突**。v3.2 拆为**三端**：
> - **用户端**：uniapp 微信小程序 + H5（不变）。
> - **开发者端**：**独立 Web 门户**（Vue3 SPA，桌面优先）；**小程序 / App 通过 `web-view` 打开同一站点**的轻量视图（审核状态/数据概览/消息），传包等重操作引导到 Web（D7）。需**独立备案域名**。
> - **平台端**：保留 Bagisto Admin（运营/审核/财务/客服）。
> 三者共用 Laravel 底座 + v1 鉴权；开发者端走 **开发者 OAuth + API Key**，不再用运营的 `internal.key`。

| 开发者门户 | 独立 Vue3 SPA（Web 为主，小程序/App web-view 打开） | 头部标配独立门户（D7）；与 uniapp 同技术栈可复用组件；重操作留 Web，轻操作多端可达 |
| 前端多后端 | Laravel 做网关代理 `/api/go/*` → Go 服务 | 前端 BASE_URL 零改动 |

> ⚠ **v3.1 注（对象存储）**：技能包是**付费私有资产**，与 coze（AI 开发/演示栈）共享实例有 blast radius 风险。**至少要独立 bucket + 独立凭证**，绝不共用实例级密钥；能起独立 MinIO 更好。v3.0 一句"复用 coze"带过太轻。
> ⚠ **v3.3 实测订正**：现状技能包**落本地 `private` 盘**（`SkillAsset::DISK='private'` → `filesystems.php:40` = `storage/app/private`）；s3 盘已定义但**未装驱动**、`AWS_*` 全空；coze MinIO **与本 app 无连接配置**。→ **"上传 MinIO"不是"复用"，是一次真正的存储改造**（装驱动 + 配 bucket + 迁移存量）。

---

## 3. 数据模型设计

### 3.1 复用表与扩展列（9 张）

| 表 | 新增列（migration） | 说明 |
|---|---|---|
| `skill_versions` | `delivery_channels` JSON、`risk_level` varchar(10)、`pricing_mode` varchar(16)、`manifest_schema` varchar(32)、`source_format` varchar(32)、`imported_meta` JSON | 通道声明如 `["agent","vendor:unitree","manual"]`；`manifest_schema` 如 `zhaohuan.skill/v2`；`source_format` 记录来源（`native` / `unitree_unistore` / `agibot_aimdk` / `bvh` / `smpler` / `lerobot`）；`imported_meta` 存导入时的原始元数据 |
| `skill_assets` | ⚠ **需一条值域迁移** | carrier_type 词表**必须对齐 v1 实际枚举**：v1 是 `file / cloud_config / remote_service / api_bundle`（`SkillAsset.php` + `SkillAdminController` 白名单硬校验）。计划扩为 `file(=人工下载包) / cloud_config / remote_service / api_bundle / motion_data / app_package / vendor_package`，**同步改白名单**；唯一键 version+carrier+sort → 一版本多载体 |
| `devices` | `binding_id`（nullable index） | 关联全局绑定表 |
| `skill_entitlements` | 无（试用用 meta JSON + expires_at 表达） | 买断型不填 expires_at；试用型必填且 meta.trial=true |
| `sellers` | `realname_status`、`realname_payload` JSON、`settlement_account` JSON | 实名认证 + 提现账户 |
| `customers` | `wechat_openid`（unique nullable）、`phone` | 微信登录绑定 |
| `products` / `orders` / `order_payment` / Admin `roles` | 无 | 原样复用 |

> **⚠ 通道命名对账（v3.1 修正，务必按此理解，否则必踩坑）**
> - `skill_versions.channel`（v1 已有，默认 `stable`）= **发布通道**（stable/beta）；本计划新增的 `delivery_channels` = **交付通道**（agent/vendor/manual）。同表两个"channel"，以列名区分，注释里钉死。
> - v1 已启用的交付通道是 **`app`**（`config/skill_delivery.php` 默认值；`AppChannel` = 授权下载 + 自报安装），**它 ≡ 本计划的 `manual` 通道**。⚠ **v3.3 定案：保留 `app` 作为内部 key、展示层/文档标注"人工安装通道"，不做数据迁移**（改 `AppChannel::key()` 会牵动存量 `skill_entitlements.delivery_channel='app'`、`SkillDeliveryManager::label()` 三处）。**禁止两套词混用**：代码内 `app`，人读文档写 `manual`。
> - 交付通道枚举固定为：`manual`（=v1 `app`，下载+人工）/ `agent`（MQTT+设备 Agent）/ `vendor:{code}`（厂商云 API，**四张表统一用 `vendor:{code}` 写法，不要四种编码**）。

### 3.2 新建表（v3.1 设计，字段级）

**device_model 机型库**
```
id, brand, model_code(unique), model_name, vendor_code(nullable→厂商API通道用),
degrees_of_freedom int, compute_platform varchar, comm_channels JSON(["agent","vendor","manual"]),
firmware_versions JSON, min_agent_version, icon, status, sort, timestamps
```
> **种子数据已采集**：[device-model-seed.md](./device-model-seed.md)（宇树/智元/众擎/乐聚/傅利叶/优必选/云深处/BD Spot/1X 等）。
> ⚠ **数据可用性（实测）**：**通信/接口字段可采到八成以上**；**算力芯片型号 / 固件-OTA 版本号只能采到一半**（多数厂商不公开）→ **`compute_platform` 与 `firmware_versions` 必须允许"未知"**，兼容性校验逻辑要能处理"未知"（未知默认降级为人工通道 + 提示）。

**device_binding 全局设备绑定（D1 分层）**
```
id, did varchar(64) unique,        -- Agent首联生成 did_{hash}；厂商同步 vendor_{code}_{sn}；人工通道 sn_{sn}
source varchar(16),                -- agent / vendor / manual
vendor_code nullable, sn, model_id FK device_model,
owner_customer_id,                 -- 当前归属（可转移）
devices_id nullable,               -- 关联用户登记记录(可空: 未见登记直接绑定)
bind_status varchar(16),           -- bound/unbound/transferring
online_status tinyint, online_channel, last_online_at,
bound_at, unbound_at, meta JSON, timestamps
```
> 语义：`devices` = 用户自报登记（弱验证，verify_status 标记）；`device_binding` = 平台确权设备（强验证：Agent 握手/厂商 API 校验）。下发只认 binding。
>
> ⚠ **v3.3 定案（设备唯一真相源）**：**以 `device_binding` 为准**，`devices` 退化为"登记视图"。执行要点：① v1 三个接口（`skills/mine` 归属校验、`bindDevice`、`confirm-install`）现全部按 `devices.customer_id` 过滤，**须改读 binding**；② 去掉 `device_binding.devices_id` 反向列，**只在 `devices` 侧保留 `binding_id` 单向引用**，避免双向可空循环；③ 归属一致性用事务保证（`devices.customer_id ↔ device_binding.owner_customer_id` 同步）；④ 换机/转移走统一入口。**不改则 transfer 后 v1 接口与下发链路会看到两个归属，最坏越权读到别人付费包。**

**deploy_task 下发任务（核心）**
```
id, task_no unique, did, channel varchar(16),      -- agent/vendor:xx/manual
skill_id, skill_version_id, entitlement_id,
operation tinyint,        -- 1安装 2卸载 3回滚 4启用 5停用 6回收(试用到期)
status varchar(20),       -- pending/dispatching/downloading/installing/success/
                          -- failed/timeout/cancelled/pending_online(离线挂起)
retry_count int default 0, next_retry_at,
package_url, package_hash, signature text,
error_code varchar(32), error_msg varchar(512),
created_by, created_at, completed_at, timestamps
索引: (did,status), (entitlement_id,operation,status), (status,next_retry_at)
```

**install_record 安装事实**
```
id, did, skill_version_id, entitlement_id, deploy_task_id,
status varchar(16),        -- installed/updated/rolled_back/uninstalled/expired
current_version, installed_at, removed_at, timestamps
unique(did, skill_version_id)   -- 每设备每技能一条，重复安装转 update
```

**audit_record 两阶段审核**
```
id, skill_id, version nullable(基础信息审核为空), audit_type tinyint(1基础/2版本),
auditor_id, result tinyint(0待审 1通过 2驳回 3待补充),
reason_category, reason_detail text, suggestion text,
submitted_at, audited_at, timestamps
```

**security_scan 安全扫描**
```
id, skill_version_id, scan_type tinyint(1静态 2注入检测 3沙盒),
result varchar(16) SAFE/SUSPICIOUS/DANGEROUS/PASS/WARN/BLOCKED,
report JSON, scanned_at, timestamps
```

**skill_import 导入任务（v3.1 新增，D5 落地载体）**
```
id, seller_id, source_format varchar(32),     -- unitree_unistore/agibot_aimdk/bvh/smpler/lerobot/native
source_ref varchar(255),                      -- 源地址/上传包名/外部技能 ID
status varchar(16),                           -- pending/parsing/mapped/failed/imported
raw_meta JSON,                                -- 从源 manifest 解析出的原始元数据(如宇树 metadata.yaml 全文)
mapped_manifest JSON,                         -- 映射后的平台中立 manifest.yaml 结果
warnings JSON,                                -- 无法自动映射的字段(如宇树 arm64 容器仅限宇树设备)
skill_id nullable, skill_version_id nullable, -- 导入成功后指向产物
error_msg varchar(512), created_at, imported_at, timestamps
索引: (seller_id,status), (source_format,source_ref)
```
> 语义：导入器把**他平台的技能包**解析→映射成 `manifest.yaml`→走正常审核流；无法映射的部分（如宇树容器依赖 G1 的 DDS 运控接口）写进 `warnings` 并**自动收紧 `compatibility.models`/`delivery_channels`**，不允许"导入后能下发到不兼容设备"。

**risk_acknowledgment 风险告知确认**
```
id, customer_id, entitlement_id nullable, skill_version_id,
channel, ack_payload JSON, ip, created_at
```

**settlement / withdrawal 结算与提现（P1）**
```
settlement: id, seller_id, period_start, period_end, order_item 统计快照 JSON,
            gross, commission_rate, commission, net, status, timestamps
withdrawal: id, seller_id, settlement_ids JSON, amount, account JSON,
            status(applied/approved/paid/rejected), reviewed_by, reviewed_at, timestamps
```

**gray_release / rollback_record（P1）**
```
gray_release:  id, skill_version_id, strategy JSON(whitelist/percent/model),
               batch_no, status(paused/releasing/done/aborted), metrics JSON, timestamps
rollback_record: id, gray_release_id nullable, deploy_task_id, trigger(auto/manual),
               reason, affected_devices int, created_at
```

**operation_log 操作审计（轻量）**
```
id, actor_type(customer/admin/seller/system), actor_id, action,
target_type, target_id, payload JSON, ip, created_at
索引: (actor_type,actor_id,created_at), (target_type,target_id)
```

**vendor_config 厂商 API 通道配置（P1，按厂商接入时建）**
```
id, vendor_code unique, vendor_name, auth_type(oauth2/hmac), api_base_url,
app_id, app_secret(加密), callback_url, status(active/inactive/mock), timestamps
```

### 3.3 新建表（v3.2 三端补全，字段级）

**developer_profile / developer_team_member 开发者主体与团队**
```
developer_profile: id, customer_id(unique), seller_id, dev_type(personal/company),
  realname_status, cert_payload JSON,              -- 资质
  agreement_signed_at, api_quota JSON, status, timestamps
developer_team_member: id, developer_id FK, customer_id, role(owner/admin/dev/finance/support),
  app_scope JSON(可访问的应用), status, timestamps   -- 对齐 ASC 8 类角色
```

**developer_agreement 协议与责任承诺留档**
```
id, developer_id, doc_type(dev_agreement/safety_commitment), doc_version,
signed_at, sign_payload JSON(电子签/勾选+IP), timestamps
索引: (developer_id, doc_type)
```

**developer_app / api_credential 应用与密钥**
```
developer_app: id, developer_id, name, platform, status, timestamps
api_credential: id, developer_app_id, app_id(unique), secret_hash, scopes JSON,
  status, last_used_at, rotated_at, expires_at, created_at, timestamps
调用日志: api_call_log: id, credential_id, endpoint, status, latency_ms, ip, created_at
索引: (credential_id,created_at)
```

**skill_review 技能评价（可复用 `ProductReview` 则本表仅存技能特有字段）**
```
id, skill_id, skill_version_id, customer_id, order_id, rating tinyint(1-5),
content text, media JSON(晒单图/视频), is_anonymous, status(visible/hidden/reported),
reply JSON(开发者回复), replied_at, timestamps
unique(customer_id, skill_id, order_id)   -- 一单一评
```

**skill_report 举报**
```
id, reporter_customer_id, target_type(skill/review/developer), target_id,
reason_category, detail text, evidence JSON, status(pending/handled/rejected),
handler_id, handled_at, created_at, timestamps
```

**support_ticket / ticket_message 工单（用户↔平台↔开发者）**
```
support_ticket: id, ticket_no unique, customer_id nullable, developer_id nullable,
  skill_id nullable, channel, category, priority, status(open/pending/processing/resolved/closed),
  assignee_id, related_deploy_task_id, sla_due_at, timestamps
ticket_message: id, ticket_id, actor_type(customer/developer/admin/system), actor_id,
  content text, attachments JSON, created_at
索引: (status,sla_due_at), (customer_id), (developer_id)
```

**notification / notify_template 通知中心**
```
notification: id, recipient_type(customer/developer/admin), recipient_id, type,
  title, content, payload JSON, channel(inapp/subscribe_msg/sms),
  read_at, sent_at, created_at
notify_template: id, code unique, scene, title_tpl, content_tpl, channel, status, timestamps
索引: (recipient_type,recipient_id,read_at)
```

**skill_category / skill_tag 技能类目与标签**
```
skill_category: id, parent_id, code, name JSON(多语言), sort, status, timestamps
skill_tag: id, name, type(motion_type/scene/difficulty), sort
skill_tag_pivot: skill_id, tag_id
```

**content_security_record 内容安全检测**
```
id, target_type(skill_meta/icon/preview/review), target_id, provider(wechat/tencent_yms/other),
  scan_type(text/image/video), result(pass/review/block), labels JSON, raw JSON, scanned_at, timestamps
```

**skill_blocklist 熔断 / 紧急下架**
```
id, skill_id, skill_version_id nullable(空=整技能), reason, severity,
  action(block_new/block_all/force_uninstall/force_rollback), status(active/lifted),
  affected_devices int, created_by, created_at, lifted_at, timestamps
```

**after_sale / refund_request 售后与退款（虚拟商品政策 D10）**
```
after_sale: id, order_id, order_item_id, entitlement_id, customer_id, type(refund/complaint),
  reason_category, detail, policy_basis, status(applied/reviewing/approved/rejected/refunded),
  reviewer_id, reviewed_at, timestamps
refund_request: id, after_sale_id, wechat_refund_no, amount, status, callback_at, timestamps
```

**reconciliation 三方对账**
```
id, period_start, period_end, channel(wechat), platform_amount, wechat_amount, diff,
  status(matched/mismatched/resolved), detail JSON, created_at, timestamps
```

**invoice 发票**
```
id, customer_id, order_ids JSON, type(personal/company), title, tax_no, amount,
  status(applied/issued/rejected), file_path, issued_at, timestamps
```

**compliance_filing 备案台账（D8）**
```
id, filing_type(algorithm/llm/affective_interaction/icp), name, filing_no,
  authority, scope, filed_at, expires_at, annual_review_at, status, attachments JSON, timestamps
```

**incident 事故上报与追溯（D11）**
```
id, incident_no unique, skill_id nullable, skill_version_id nullable, device_binding_id nullable,
  customer_id nullable, severity, occurred_at, description, evidence JSON,
  responsibility(developer/platform/vendor/user/unknown), trace JSON(追溯链: 日志/下发/授权/告知),
  status(reported/investigating/resolved/closed), insurance_claim JSON, created_at, resolved_at, timestamps
```

**skill_search_index 搜索物化表（v3.3 新增，必建）**
```
id, skill_id, version_id, name, brand, model_codes JSON, delivery_channels JSON,
  resource_type, risk_level, tags JSON, price, rating_avg, download_count, updated_at
索引: (resource_type), (risk_level), (brand), 复合(name/model)
```
> **为什么必建**：MySQL 5.7 **不能给 JSON 列直接建索引**，而 §4.18 要求"按机型/通道/动作类型/风险等级筛选"。靠现查 `delivery_channels` 这种 JSON 列做不到。→ 落一张**拍平成列 + 复合索引**的物化表，技能上架/更新时同步写。

**v3.3 索引/幂等补丁（架构师发现）**
- `skill_entitlements` 加 **`(status, expires_at)` 索引**——§4.7 试用每分钟回收若靠 `meta.trial`（JSON）过滤会**全表扫**；回收扫描走 `(status, expires_at)`，或落物理列 `trial_expires_at`。
- `deploy_task` 加**幂等键** `(entitlement_id, operation)` 的部分唯一——否则每分钟回收会对同一到期授权**重复生成** operation=6 任务。
- `device_binding` 补 `(owner_customer_id)`、`(did)`、`(model_id)` 索引；`skill_blocklist` 补 `(skill_id,status)` 索引。

**skill_build 技能编译记录（v3.4 新增，§4.20 落地载体）**
```
id, skill_version_id, target_model_code, target_agent_type(unitree/agibot/engineai),
status(pending/building/success/failed), carrier_type, artifact_asset_id,
retarget_conf JSON, build_log, error varchar(512), built_at, timestamps
索引: (skill_version_id, target_model_code), (status)
```
> 语义：把"中立技能定义"编译成"某机型载体"的过程留痕（§4.20）。一个技能版本 → N 条 build（每机型一条）。

**skill_quality 技能质量分（v3.4 新增，§4.21 落地载体）**
```
id, skill_version_id, sim_score, install_success_rate, rating_avg, uninstall_rate,
grade(qualified/good/featured), graded_at, timestamps
索引: (skill_version_id), (grade)
```
> 语义：过审后的质量分级（好/一般）；**不合格不在此表——它已被审核拒绝**（§4.21）。

### 3.4 决策落库示意

- **D1 分层**：用户 A、B 都登记了 SN=X（devices 两行，合法）→ Agent 首联确权后 device_binding 一行 owner=A；B 的登记记录保留但下发校验会提示"该设备归属他人"。
- **D2 三通道**：`skill_versions.delivery_channels` ∩ `device_model.comm_channels` ∩ 设备实际在线通道 = 可选列表；空集 → 仅展示+人工通道兜底。
- **D3 买断**：entitlements.status='active'，无 expires_at；版本更新免费 → install_record 转 updated。
- **D4 试用**：entitlements 一行，expires_at=+20min，meta.trial=true。

---

## 4. 功能模块设计

### 4.1 账号与权限

- C 端：customers + **微信登录**（小程序 code2session，openid 绑定列已规划）；手机号注册保留。
  > ⚠ **验证订正**：`SocialLogin` 包**无 wechat provider**（仅 facebook/twitter/google/linkedin/github）→ **微信登录须自研 code2session**，不能"复用 SocialLogin 包"。
- 开发者 = customers × sellers（一人可既是买家又是开发者）；提现前置**实名认证**（sellers.realname_status）。
- B 端：Bagisto Admin roles/ACL 原生 RBAC，新增角色：审核员（audit 队列）、运营（推荐位/活动/看板）、管理员（全局）。菜单沿 `catalog.skills` 先例扩展。
- operation_log 中间件：Admin 全操作 + C 端关键操作（购买/下发/绑定）落审计。

### 4.2 在线支付（关键路径，资质先行）

- **微信支付**（小程序 JSAPI）：商户号申请 + 类目审核 1-2 周 → **立即启动，与开发并行**。
- 支付流：checkout 生成微信支付单 → 支付回调（/api/callback/payment）→ 订单 paid → **既有 entitlements 幂等发授权监听器自动触发**（v1 已有，零改动）。
- 退款：走微信退款接口，同时 revoke 对应 entitlements。
- 分成（P1）：**微信支付分账**（90/10 或阶梯），settlement 周期生成结算单，避免平台代收代付合规风险。

### 4.3 设备与兼容性校验

- 机型库 Admin 维护（种子：skill-data.js 40+ 品牌）。
- 绑定流程（用户端）：
  1. 登记 SN（v1 已有）→ 提示安装 Agent / 走厂商授权 / 仅人工；
  2. Agent 首联：MQTT 注册 → 生成 DID → device_binding 确权 → devices.binding_id 回填；
  3. 厂商通道：跳厂商 OAuth 授权 → 平台拉设备列表确权（P1）。
- **兼容性校验接口**：`GET /api/skills/{productId}/compatibility?deviceId=` →
  输入用户登记/绑定的设备 → 比对 device_model × skill_versions(min_robot_version/自由度/通道) →
  输出 `{compatible, reason, channels[]}`，reason 枚举：机型不匹配/固件过低/无可用通道/设备未确权/设备离线(仅提示)。
  > ⚠ **数据现实（见 [device-model-seed.md](./device-model-seed.md)）**：`compute_platform`/`firmware_versions` 大量"未知"（厂商不公开）→ **校验遇"未知"须降级**：不判定"固件过低"，改提示"请确认固件版本"并只放行人工通道。P0 校验范围**限宇树/智元/众擎**，其余 L3 仅展示。
- 失败原因分类对齐需求：不在线、已被绑定（归属他人）、型号不兼容、网络超时。

### 4.4 技能包规范（v3.1 重写：对齐行业标准 + 他平台技能可导入）

**为什么重写 v3.0**：v3.0 自创了 `manifest.json / skill.md / script / motion` 格式，与行业两家头部都对不上——
- **宇树 UniStore**（官方 `unitreerobotics/unitree-app-templates` 仓库实证）：技能包是 **Docker 化的 `tar.gz`**，声明文件是 **`metadata.yaml`**（`base_image / permissions / build_env / startup_commands / change_log` + `app/` 目录须 80 端口提供 HTTP），内含 arm64 二进制 + ONNX→MNN 策略 + BVH→CSV 动作数据，运行时通过 DDS 话题 `rt/user_lowcmd` **短时接管官方运控**。
- **智元 AIMDK**（官方文档实证）：技能是**本体本地资源**，用 `resource_type` 枚举六分类（`MOTION=1 / EMOTICON=2 / AUDIO=3 / SKILL=6（全身舞蹈）/ MAP=7 / OFFRING_WORK=8`），动作文件是 `.macp`，迁移打包成 `.gz`。

自创格式的**直接商业损失**是：**别人平台已有的技能导不进来，你的技能也导不出去**。v3.1 改为三层：

#### 4.4.1 包结构（`.skill`，ZIP 或 tar.gz，二者兼容）
```
skill/
  manifest.yaml          # ① 平台中立声明（超集，概念对齐宇树 metadata.yaml）
  skill.md               # 人类可读说明（用途/适用场景/注意事项）
  icon.png / preview.mp4
  motion/                # ② 动作数据（kind=motion|composite 时）
    source.bvh | source.smplx | motion.macp   # 保留原始格式，不转码（可回溯）
    retarget/                                 # 各本体重定向配置 → 跨本体关键
      unitree_g1.yaml
      agibot_a2.yaml
  app/                   # ② 运行载体（kind=app 时，对齐宇树 app/）
    entrypoint / startup_commands
  assets/                # 音频/表情/贴图（对齐智元 AUDIO/EMOTICON）
  vendor/                # 厂商专有资产（宇树 policy.mnn、智元资源包原样存放）
  licenses/              # 授权与第三方许可
```

**核心分离原则**：**动作数据（`motion/`，可跨本体重定向）与运行载体（`app/`，绑定特定本体）分开存**。同一技能因此可以有多种载体：宇树容器载体、智元资源载体、通用动作载体——`skill_assets` 的一版本多 carrier 唯一键天然支持。

#### 4.4.2 manifest.yaml（平台中立超集，字段对齐宇树+智元）
```yaml
schema: zhaohuan.skill/v2
kind: motion | app | composite          # 纯动作 / 容器应用 / 编排
name: { zh_CN: "打招呼", en_US: "Wave" } # 对齐智元 display_name 多语言
version: 1.0.0
description: ...
pricing_mode: buyout | free              # 对齐 D3 买断制
delivery_channels: [agent, vendor:unitree, manual]
permissions: [motion_control, audio_play, camera]   # 对齐宇树 permissions 声明式
runtime:                                 # kind=app 时（对齐宇树 metadata.yaml）
  base_image: ...
  build_env: [...]
  startup_commands: [...]
  entrypoint: ...
  port: 80
motion:
  format: bvh | smplx | macp
  fps: 60
  retarget: [unitree_g1, agibot_a2]      # 支持重定向的本体
resource_type: skill | motion | emoticon | audio   # 对齐智元 AIMDK 六分类
compatibility:
  models:
    - { vendor: unitree, model: G1, min_firmware: "1.4.8", min_app: "1.9.0", dof: 23 }
    - { vendor: agibot,   model: A2, min_firmware: "1.3.0" }
safety:                                   # 见 §4.14，必填，不填直接驳回
  risk_level: low | medium | high
  requires_open_area: true
  estop_required: false
  max_duration_s: 120
```

#### 4.4.3 导入适配器（"他平台技能能上传"的落地机制，D5）
每个源格式一个 importer，产出 `manifest.yaml`，写 `skill_import`（§3.2）留痕：
| 源格式 | 识别方式 | 映射 | 自动收紧 |
|---|---|---|---|
| `unitree_unistore` | 含 `metadata.yaml` + `app/` | `base_image/permissions/startup_commands/entrypoint` → `runtime.*`；BVH/CSV → `motion/`；`vendor/` 存政策模型 | 模型锁 `unitree:G1`，通道锁 `agent/vendor:unitree`（arm64 容器离了 G1 跑不了） |
| `agibot_aimdk` | `.gz` 资源包 + `resource_type` | `MOTION→motion/`、`EMOTICON/AUDIO→assets/`、`SKILL→kind=motion` | 模型锁 `agibot:{model}`，通道锁 `vendor:agibot` |
| `bvh` / `smplx` / `fbx` | 动捕文件 | → `motion/source.*` + 生成 `retarget/*.yaml`（对齐众擎 GMR 重定向） | 由用户选择目标本体 |
| `lerobot` | LeRobot 数据集结构 | → `motion/`（对齐智元/乐聚采用的格式） | — |
| `native` | 本平台新建 | 直接编辑 | — |

> **诚实边界**：宇树容器包**物理上只能跑在宇树设备**（依赖 G1 DDS），所以"跨平台上传"的真实价值是——**同一技能在你平台上有多种载体，跨本体运行靠 `motion/` 层重定向，而不是靠容器**。别对外承诺"宇树技能能直接跑在智元机器人上"。

#### 4.4.4 校验与存储（继承 v3.0）
- 格式校验：必须文件、白名单扩展名、黑名单（`.sh/.exe/.so` 等**仅在 `motion/`、`assets/`、`vendor/` 生效**；`app/` 内允许受控可执行文件，但须过 §4.14 沙盒与权限核对）、≤500MB、`manifest.yaml` schema 校验。
- 存储：`skill_assets` 上传 **MinIO**（⚠ 见 §2 注：生产技能包与 coze 开发栈**独立 bucket + 独立凭证**，不共用实例级密钥），路径 `skills/{skill_id}/{version}/{carrier_type}/`；下载仍走鉴权接口（私有读）。

### 4.5 市场与交易

- 列表/筛选/搜索复用现有市场（Bagisto products + 分类/属性）；详情页新增：适配机型（device_model 联表）、通道标识、版本历史（skill_versions changelog）。
- **购买（买断）**：兼容校验通过 → 风险告知弹窗（记录 risk_acknowledgment）→ 通道选择 → 微信支付 → 授权发放（幂等）。
- **试用（D4）**：仅当技能含 agent/vendor 通道；每用户每技能 7 天内 1 次；点击即发 20 分钟试用授权并直接走下发；倒计时展示在"我的技能"。
- 价格体系：products.price 一次买断；V1 全版本免费更新。

### 4.6 下发调度与设备通信（三通道，D2）

**状态机**（deploy_task.status，Go 服务驱动）：
```
pending → dispatching → downloading → installing → success
                │            │
                │            └→ download_failed → 重试(5s/15s/45s, 最多3次)
                ├→ timeout(60s) → 重试1次 → failed
                ├→ pending_online（设备离线：Agent 上线拉取 / 厂商通道跳过）
                └→ cancelled
```

**Agent 通道（MQTT，P0）**
- Topic 设计：
  - 下行指令：`zhjj/{did}/cmd`（QoS1）：`{task_id, operation, skill_id, version, package_url, package_hash, signature, ts}`
  - 上行回报：`zhjj/{did}/evt`：`{task_id, phase: downloading|installing|done|failed, error_code, progress, ts}`
  - 在线心跳：MQTT keepalive +遗嘱消息 → EMQX 系统主题 → Go 服务更新 device_binding.online_status
- 签名：`RSA_Sign(平台私钥, task_id+skill_id+version+package_hash+ts)`，Agent 内置公钥验签，失败拒绝安装。
- Agent 协议 v1 仅定义安装/卸载/启停/回报四类指令；厂商差异做成 Agent 内插件（vendor/ 目录与 .skill 包 vendor/ 对应）。

**⚠ 三家最新主流机型的"真实下发模型"完全不同（2026-10 一手调研，详见 §4.6.1）**
| 厂家 | 下发模型 | Agent 要做成 | 关键约束 |
|---|---|---|---|
| **宇树**（G1/H1/H2/Go2/B2） | **容器应用级**：Docker(ARM64) 包 + `metadata.yaml` → 传 UniStore，机器上 80 端口跑 HTTP，用 DDS 抢控制权 | 打成 ARM64 容器应用 | 必须遵守 FSM（Passive→执行→回落）交还官方运控 |
| **智元**（远征A2/A3、灵犀X2） | **资源级**：技能=数据资源(mcap/动作)，放 `tmp` → RPC 同步/播放 | 只需发 RPC，**不强制把 Agent 装机** | **禁跑 x86、禁 apt、轮询≤0.2Hz**（违反不保修） |
| **众擎**（PM01/T800） | **插件级**：技能=控制算法注册为 Native SDK 的 **Runner 插件** → `install.sh` 部署 | 做成 Runner 插件 | ROS2 Humble + CycloneDDS(`ROS_DOMAIN_ID=69`) |

**→ 结论（v3.3 定案）**
1. **Agent 必须一厂一插件**，不是一套通吃；三家下发载体 = 宇树 Docker 应用 / 智元 mcap 资源 / 众擎 Runner。
2. **先做宇树 G1**——唯一有"官方 App Store + 完整打包模板 + 现成 DDS 控制流"的，起步最快。
3. 通吃的抽象层 = 统一 ① **ARM64 构建产物** ② **ROS2 话题**（三家都支持，但宇树主通道是 DDS、智元高层在 HTTP RPC，ROS2 只覆盖部分）③ 技能的三种表达形态。
4. `device_model` P0 **只收宇树/智元/众擎最新主流机型**，其余一律 **L3 仅展示**（§0.5）。

**厂商 API 通道（P1，按厂商）**
- 继承 v2.0 VendorAdapter 接口（bindDevice/deploySkill/getStatus/rollback/uninstall）；宇树 OAuth2、智元 HMAC；vendor_config 配置驱动；无回调能力的厂商轮询状态。

**人工通道（兜底，升级 v1）**
- 鉴权下载 manual_package + 图文安装引导（skill_versions.install_guide 已有列）→ confirm-install 自报（保留）→ install_record 落库（状态自报，标记 unverified）。

**错误码体系**：BIND_001~007（绑定）沿用 v2.0；DEP_001~009（下发）沿用；新增 `TRIAL_001 试用通道不支持`、`TRIAL_002 试用次数用尽`。

### 4.7 试用 20 分钟自动回收（D4 可行性判定：**可行**）

| 判定点 | 结论 |
|---|---|
| 授权表达 | entitlements.expires_at=+20min + meta.trial，零改表 |
| 定时回收 | Laravel scheduler 每分钟：到期试用 → status='expiring' → 生成 operation=6(回收) 的 deploy_task → 三通道路由（人工通道在发放时已被拦截，不会出现） |
| 在线设备 | Agent 立即执行卸载回报；厂商通道调 uninstall |
| 离线设备 | 任务转 pending_online，设备上线即执行（MQTT 遗言/上线事件触发） |
| 已知局限 | 设备断电拔线可本地保留技能包（无硬件级防篡改）；动作类技能风险可接受，记录在案；后续可加"技能启动时 Agent 校验授权"缓解 |
| 结论 | **保留试用**，前端在"我的技能"展示剩余时长；人工通道技能隐藏试用按钮 |

### 4.8 审核与安全扫描（v3.4：三层准入 + 拒绝清单）

**原则**：**平台守住"及格线"（能用+安全+合规，客观可判），把垃圾挡在门外；及格线以上的好/一般交给质量分级和市场（§4.21）。**

**三层准入**（不过 → 拒绝）：
```
① L0 机器审 → ② L1 仿真预跑 → ③ L2 人工审 → 过审 / 拒绝
 格式/权限/内容   能不能跑、稳不稳   完整性/一致性/侵权
```
- **L0 机审（P0）**：包格式 schema、文件黑白名单、内容关键字（命令执行/提权/固件/越权路径）、`manifest.permissions` × 实际调用比对、**内容安全（`msgSecCheck`+多媒体）**、**提示注入检测（LLM，辅助非唯一闸门）**。恶意/越权/违规 → **拒绝**。
- **L1 仿真预跑（P0，垃圾过滤器）**：上架前在仿真里跑动作——**跑不起来/姿态失稳/超时 → 拒绝**；能跑但分低 → 只**降级**（不放精选）。对齐智元 Genie Sim / 宇树 mujoco 做法。**这是"垃圾不过审"的主力，客观、可批量、不靠人工审美。**
- **L2 人审（P0）**：功能完整性（半成品拒）、**描述与内容一致（虚假宣传拒）**、**侵权**（无授权拒）、人工通道 `install_guide` 为空拒。

**静态扫描细节（L0 内）**：文件黑白名单 → 内容关键字正则 → 权限声明比对。
**沙盒动态执行（L0 内，P0）**：`kind=app` 包**上架前必过** `docker --network=none --read-only`（限 CPU/内存/PIDs，沿用 v2.0）。

**过审之后**：进 `skill_quality` 分级（§4.21），**不拒绝，只分级排序**。
审核队列落 Bagisto Admin（`audit_record` + 审核详情页 + 驳回理由库），驳回支持带建议重提。

### 4.9 分成结算与提现（P1）

- 结算周期（默认半月）：按 order_item × sellers.commission_rate 生成 settlement；
- 提现：实名 + 结算单勾选 → withdrawal → 管理员审核 → 微信商家转账到零钱/银行卡；
- 全程 operation_log 审计。

### 4.10 灰度发布与版本回退（P1）

- 策略：白名单 DID / 比例 5%→20%→50%→100%（每批 ≥2h 观察）；
- 自动回滚阈值：安装成功率<90% 暂停、崩溃率>2% 回滚（向已更新设备推旧版本 uninstall+install）；
- 用户手动回退：install_record 历史 → 发 rollback 任务。

### 4.11 监控运维（P0 用现成，P1 加Prometheus）

- P0：EMQX Dashboard（在线设备数/连接/消息）+ Laravel 日志 + deploy_task 聚合接口（成功率按技能/机型/错误码）；
- P1：Prometheus+Grafana（Go 服务指标、MQTT、MySQL）、告警（下发失败率、异常离线）。

### 4.12 数据与推荐

- 技能数据看板：下载/收入/评分/机型分布/卸载率（install_record + orders 聚合）；**同一套指标对开发者开放（§4.15 E，v3.2）**；
- 推荐：**AI 召唤页（Coze）推荐池加入技能商品**（现有商品推荐链路一行扩展）；设备机型 → 兼容技能优先；
- 运营：Bagisto CMS/Banner + 首页推荐位配置。

### 4.13 供给策略与冷启动（v3.1 新增，补 v3.0 整块缺失）

**问题**：本平台是**双边市场**——买家要"有好技能"才来，开发者要"有买家"才来，一开始两边都是零 → **没人来**。这叫冷启动。v3.0 通篇是交易/下发**管道**，却没说"第一个技能从哪来"。行业一手证据表明**没有一家靠自然增长做起来**：
- 宇树 UniStore 首批 24 个动作**几乎全是官方出品，第三方只有 1 个**；
- 智元干脆不做付费市场，改用「**元苼**计划 5 年 20 亿 + 灵创大赛现金 68 万」往里砸人；
- 乐聚（挑战赛 ~50 万 / ~9 万美元）、众擎（引擎计划开放数据/模型）、傅利叶均靠**赛事奖金 + 开源**。

**结论：供给必须先由平台自己撑起来，分三步。**

| 步 | 动作 | 依赖 | 目标 |
|---|---|---|---|
| **① 自营填货架（P0）** | 用 `sellers.id=NULL` 自营位，把 `skill-data.js` 的 40+ 品牌机型映射转成「机型适配技能 + 官方动作包」；从宇树/智元公开的**免费动作**中，按各自许可（注意 CC BY-NC-SA 等 **非商用**限制）合规搬运 | §4.4 导入器 | 上架即有 **N 个可下发技能**，货架不空 |
| **② 赛事/基金引流（P0→P1）** | 办「技能创意大赛」，现金奖 + **获奖作品自动上架**。**直接复用现有 `events` / `event_registrations` 赛事闭环**（前端 EcoCenter 赛事 tab 已通） | 现有赛事模块 | 用钱换第一批第三方开发者 |
| **③ 搬运存量供给（P1）** | 用 §4.4.3 导入器把宇树/智元/HuggingFace Reachy 等**已存在的公开技能**批量导入 | 导入器 + 许可审查 | 用他平台的存量，快速扩充货架 |

**北极星指标（不是"注册开发者数"）**：`可下发的技能数 × 覆盖机型数`。这两个数上不去，市场就是空壳。

### 4.14 物理安全责任模型（v3.1 新增，三层）

技能会**物理执行**（撞击、伤人、损坏财产）——这是机器人市场区别于手机 App Store 的**最大成本项与责任项**。行业共识是"**Docker 沙盒 + 权限声明 + 平台审核**"（宇树仅做到这三点，也只放了 1 个第三方动作）。本平台按三层落实，**每层都有落库载体**：

**L1 · 开发者责任（准入 + 声明 + 承诺）**
- 上架前强制签署《技能安全责任承诺》（存 `sellers`/`skill_import` 旁的责任记录），约定"因技能缺陷致损，责任回溯到开发者"。
- `manifest.safety` **必填**（`risk_level / requires_open_area / estop_required / max_duration_s`），缺项**直接驳回**。
- `manifest.permissions` 越权（声明了但未用、或用了未声明）→ 审核驳回。

**L2 · 沙盒（技术隔离）**
- 包层面：静态三层扫描（§4.8 已 P0）+ `kind=app` 包**必过 docker 沙盒**（`--network=none --read-only`，限 CPU/内存/PIDs）。
- 设备端：Agent **受控执行**——限幅、限速、`max_duration_s` 超时**自动交还官方运控**（对齐宇树"接管→执行→交还运控 + 姿态安全回退"）、急停可用；`high` 风险动作默认在安全姿态下执行。
- 仿真预跑（P0 后可选）：对齐智元 Genie Sim / 宇树 mujoco，`app`/`motion` 上架前先在仿真里跑通再放行。

**L3 · 用户知晓责任（强制告知 + 留痕）**
- 下发前**分级风险告知弹窗**：按 `manifest.safety.risk_level` 出文案；`high` 风险要求用户逐条勾选"我已在空旷、无人的安全环境使用"。落 `risk_acknowledgment`（存 `ack_payload` + IP + 时间）。
- 首次执行时**设备端二次提示**（语音/App："请确保周围无人"）。
- 试用同理——试用不是免责，告知与留痕一样不能少。

| 层 | 责任主体 | 落库载体 | 等级 |
|---|---|---|---|
| L1 开发者责任 | 开发者/seller | 责任承诺记录 + `manifest.safety` + `audit_record` | P0 |
| L2 沙盒 | 平台 | `security_scan`（含沙盒类） + Agent 受控执行 + 仿真 | P0 |
| L3 用户知晓 | 用户 | `risk_acknowledgment`（分级） | P0 |

### 4.15 开发者端 · 独立开发者门户（v3.2 新增，D7，最大缺口补全）

**形态**：**独立 Web 门户**（Vue3 SPA，桌面优先），对标智元开发者控制台 / Apple App Store Connect；**小程序 / App 通过 `web-view` 打开同一站点**的轻量视图（审核状态、数据概览、消息、工单），传包/大屏看数据等重操作引导到 Web。需**独立备案域名**。与用户端 `customers` 同账号体系（开发者 = customers × sellers）。

**门户模块（9 组）**：

| 组 | 模块 | 内容 | 优先级 |
|---|---|---|---|
| A 入驻 | 注册/登录 | 微信扫码 / 手机号；与 C 端同账号 | P0 |
| | 主体认证 | 个人/企业资质；`developer_profile` | P0 |
| | 协议签署 | 《开发者协议》+**《技能安全责任承诺》**电子签留档（`developer_agreement`，§4.14 L1） | P0 |
| | 团队与角色 | owner/admin/dev/finance/support + 按应用授权（对齐 ASC 8 类） | P1 |
| B 技能 | 技能 CRUD | 建技能、填元数据、**多语言（zh/en）**、图标/截图/预览视频 | P0 |
| | 包上传 | 上传 `.skill`（§4.4）或**导入他平台包**（§4.4.3） | P0 |
| | 版本管理 | 版本历史、多版本并存、下架、回滚 | P0 |
| | 定价配置 | 买断定价（D3）、免费/试用开关 | P0 |
| | 发布通道 | stable / beta（复用 `skill_versions.channel`） | P1 |
| C 审核 | 提交审核 | 触发 §4.8 两阶段；同技能同版本仅一版在审 | P0 |
| | 状态跟踪 | 待审/通过/驳回/待补充，**对开发者可见** | P0 |
| | 驳回理由 + 申诉 | 结构化 `reason_category` + 开发者申诉入口（对标 Apple ARB） | P0 |
| D 测试 | **预发布测试轨** | **Beta 包分发到指定设备/测试者**（对标 TestFlight / 微信体验版）；技能会物理执行，上架前必须真机跑通 | **P0** |
| | 沙盒/仿真自测 | 开发者侧发起仿真预跑（对接 §4.14 L2 仿真），非只平台侧 | P1 |
| E 数据 | **开发者数据看板** | 下载量/安装**成功率**/机型分布/**错误码分布**/评分/卸载率（按版本），对标 App Analytics + vitals | **P0** |
| | 质量监控 | 端侧崩溃/失败率、下发失败聚类 | P1 |
| F 财务 | 收入报表 | 销售/分成明细，可导出 | P1 |
| | 结算/提现 | 结算单、提现申请、银行/税务/协议信息 | P1 |
| G 凭证 | **应用与密钥** | AppID/Secret、Scope、**轮换**、`api_call_log` 调用日志与配额 | P1 |
| H 工具链 | SDK/CLI 下载 | 平台 SDK/CLI（发布/鉴权/上报） | P1 |
| | 仿真接入 | Isaac Sim / MuJoCo 接入说明与配置 | P1 |
| | 真机调试/远程诊断 | 指定设备下发测试包、拉日志 | P1 |
| | 跨本体重定向工具 | 生成 `motion/retarget/*.yaml`（对齐众擎 GMR） | P1 |
| | 数据集上传 | 动作/数采数据（P2） | P2 |
| I 支持 | 文档/工单/社区/赛事 | 接入文档、工单（复用 §4.16）、社区、**赛事入口（复用现有 events，§4.13 ②）** | P0/P1 |

> **为什么这块是本计划的命门**：开发者端缺 → **§4.13 供给冷启动直接失败**（没有门面，赛事奖金只换一次性作品）。D7 不改，开发者端永远是 20%。

### 4.16 平台端 · 运营后台补全（v3.2 新增）

| 模块 | 内容 | 优先级 |
|---|---|---|
| **内容安全** | 技能名/描述/图标/预览视频/评价：接微信 `msgSecCheck` + 多媒体检测（涉政涉黄违规），落 `content_security_record`；违规阻断上架 | **P0** |
| **技能类目/属性** | ⚠ **不存在，须全新建**：现仅有 Bagisto `skill` 分类（id=5），无 `skill_category`/`skill_tag` 表；前端左侧分类是 mock tag 且对真实商品不生效。新建：动作类型/场景/难度/风险等级，驱动市场筛选 | **P0** |
| **开发者/商户管理** | 入驻审核、资质分级、封禁、责任承诺管理（`developer_profile`） | **P0** |
| **工单/纠纷仲裁** | `support_ticket` 全流程 + 用户投诉技能致损的仲裁视图（联动 §4.19 事故） | **P0** |
| 审核运营 | 驳回理由库（结构化）、复审/申诉队列、**审核 SLA 监控** | P1 |
| 对账 | `reconciliation`：平台-微信-开发者三方对账 | P1 |
| 风控 | 刷单、**薅试用**（每用户1次可被多账号绕过 → 设备指纹/手机号去重）、支付风控 | P1 |
| ACL 细化 | 审核员/运营/财务/客服分离，操作级权限（对齐 ASC 8 类） | P1 |
| 机器人专属看板 | 安装成功率 / 机型分布 / 错误码聚合（做成看板，非仅接口） | P1 |
| 发票管理 | `invoice` 开具与审核 | P1 |

### 4.17 合规与备案（v3.2 新增，D8；v3.4 落地为清单）

- **三层 AI 备案**：① 算法备案（推荐，`/api/summon`）② 大模型备案（生成式 AI）③ **拟人化互动服务安全评估**（2026-07-15 施行；**涉情感陪伴/拟人的机器人技能会触发**）。落 `compliance_filing`。**外部审批，阶段 0 前置并行。**
- **内容安全**：见 §4.16（微信内容安全 API + 人工编制 + 未成年人保护）。
- **隐私与数据**：隐私政策、个保法/《APP 个人信息收集使用规定》、账号注销（复用 Bagisto `GDPR`）；**`permissions:[camera]` 采集音视频=敏感个人信息，需单独同意+PIA**；**机器人采集的人脸/生物特征出境年累计 1 万人即触发**（敏感 PI）。
- **机器人安全责任**：对齐**《人形机器人安全要求 第4部分：公共服务领域》**（立项 20262891-T-604）与**杭州《促进具身智能机器人产业发展条例》**责任边界（制造商/算法软件方/集成商/运营方）；平台明确自身"网络交易平台服务提供者"角色。
- **责任保险**：`high` 风险技能**强制投保方可上架**；平台提供投保入口；接入 `incident.insurance_claim`。

**合规清单（阶段 0 并行，任一"阻断"项卡住不能开卖）**
| 区域 | 事项 | 阻断 |
|---|---|---|
| 中国 | 微信支付商户+虚拟商品类目 / **电商收付通+二级商户分账（二清）** / ICP+小程序备案 / **EDI 许可** / **等保2.0** / 算法+大模型+拟人化备案 / 生成合成内容标识 / 隐私·协议律师定稿 | 🔴 |
| 美国 | 本地实体（收 Stripe）/ 税务（W-8/W-9）/ CCPA / 州级机器人·AI 法规 | 🔴 |
| 通用 | 用户协议 / 开发者协议 / **机器人操作者责任条款**（第三人由用户承担 + 平台卖保险） | 🔴 |

> ⚠ **V1 只做自营/免费可绕"二清"**；第三方分成必须先接微信收付通。**等保/备案绕不过。**

### 4.18 用户端 · 补全（v3.2 新增）

| 模块 | 内容 | 优先级 |
|---|---|---|
| **虚拟商品售后/退款政策** | `after_sale`/`refund_request`（D10）：试用不退；买断在"未下发/下发失败"可退；已激活原则上不退但可申诉；退款联动 revoke entitlements（§4.2 已有接口） | **P0** |
| **客服工单** | 用户提工单（装不上/下发失败/退款）→ 平台/开发者处理（复用 `support_ticket`） | **P0** |
| **消息通知中心** | 审核结果/下发完成/试用到期/退款到账 → 站内信 + **微信订阅消息**（`notification`） | **P0** |
| **技能搜索/筛选** | 按机型/通道/动作类型/风险等级筛选（`skill_category`+索引）；现有"本地过滤"升级为服务端搜索 | **P0** |
| **评价接入** | ⚠ 后端 `ProductReview` 表/模型/API 齐（`/api/product/{id}/reviews`），但**表 0 行、未接技能、前端零入口**（`home/jineng` 现为 mock 评论）→ **服务端可复用，技能评价页全新建** | P0 |
| 优惠券接入 | `CartRule` 包 + coupon API 在，但 **0 行、未配置**，且**技能走 tob-order 不经购物车 → 券对技能单无路径，须新建券逻辑** | P1 |
| 设备解绑/转移 | `device_binding` 的 unbound/transferring 落接口（换机/二手） | P1 |
| 一机一码 | `device_binding.national_code`（工信部 29 位） | P1 |
| 发票 | `invoice` 申请入口 | P1 |
| 举报 | 技能/评价举报入口（`skill_report`，Apple UGC 四件套之二） | P1 |
| 分享 | 技能分享到微信/朋友圈 | P2 |

### 4.19 技能熔断与事故闭环（v3.2 新增，D9/D11）

- **熔断 kill switch**（`skill_blocklist`）：安全事件时一键 → **① 阻断新下发 ② 全网紧急下架 ③ 对已安装设备远程 `uninstall` 或 `rollback`**（复用 §4.6 deploy_task 的三通道能力）。这是机器人商店区别于手机商店的关键风控，v3.1 缺失。
- **事故闭环**（`incident`）：上报 → 定责（`responsibility`）→ **追溯链**（`trace`：关联授权/下发/风险告知留痕/操作日志）→ 处置 → 保险理赔。**责任追溯依赖 §4.14 L1 的承诺签署 + L3 的告知留痕 + `operation_log`**，三者缺一不可。
- 与平台端工单（§4.16）打通：用户投诉 → 工单 → 若定性事故 → `incident`。

### 4.20 技能转换层（技能编译器）—— 三端标准化链路（v3.4 新增）

**问题**：之前开发者上传 / 平台 Agent / 厂家机型三端**分开设计**，缺了中间的"转换机器"。补上它，才能实现 **"开发者传一次、平台转成各机型载体、统一下发"**。

**全链路（一次上传 → 多机型 fan-out）**
```
开发者上传（中立定义）
  .skill = manifest.yaml + motion/(通用动作) + logic/(平台SDK) + 声明
        │ ①校验 → ②编译（★转换层）→ ③审核 → ④下发
        ▼
平台转换层：中立定义 ──► 宇树载体(Docker ARM64+metadata.yaml)
                     ├─► 智元载体(资源包 mcap)
                     └─► 众擎载体(Runner 插件/ROS2)
        动作重定向：通用动作 → 各机型关节空间（按 device_model 自由度）
        ▼ 落 skill_assets(多载体) + skill_build(编译记录)
平台下发端：统一协议（deploy_task + 签名 + 三通道）——所有机型同一套指令/回报
        ▼ 按机型路由载体
Agent 插件：宇树=docker load｜智元=拷 tmp+RPC｜众擎=rsync（**插件只做载体落地**）
```

**三端标准化点**
| 端 | 标准化为 |
|---|---|
| 开发者上传 | **一份中立定义**（调**平台 SDK**，不直接调厂商 API） |
| 平台转换层 | **编译成 N 个机型载体**（`skill_build`） |
| 平台下发 | **统一协议**（不管什么机型） |
| Agent 插件 | **只做载体落地**（薄，不含转换） |

**分层标准化的现实边界（不夸大）**
| 技能类型 | 能否"传一次、多机型全自动" |
|---|---|
| **纯动作 `kind=motion`** | ✅ 基本可以（动作重定向，非 100% 无损） |
| 带逻辑 `kind=app` | 🟠 半自动（逻辑用平台 SDK 可打包；机型特有硬件无法自动跨） |
| 厂商包导入 | ❌ 不能跨（宇树容器依赖 G1 DDS，锁死机型） |

> **P0 只做"纯动作"标准化；平台 SDK 化放 P1**（建议）。对外只承诺 motion 跨机型。转换是**保真**不是**提质**（见 §4.21）。

### 4.21 技能质量体系与过审门槛（v3.4 新增）

**原则**：**好技能与一般技能并存 = 正常市场；垃圾技能不是"质量差"，是"不合格"，必须挡在门外。**

```
   不合格（垃圾）│ 及格线 │     合格（好/一般并存）
  ──────────────┼───────┼──────────────────────────►
   ❌ 审核拒绝    │能用+安全+合规│ ★合格 ★★优质 ★★★精选
   （§4.8 三层准入）│（客观可判） │（评分+数据，市场检验）
```

- **及格线（过审）= 能用 + 安全 + 合规**：客观可测，**L1 仿真跑分把关**（§4.8）。
- **及格线以上**：进 `skill_quality` 分级——**不拒绝，只分级排序**。
- **转换 ≠ 提质**：平台是"翻译官"不是"编辑"，垃圾进垃圾出，只保证"合法"不保证"好看"。

**质量分级**
| 等级 | 判据（示例） | 权益 |
|---|---|---|
| ★★★ 精选/官方认证 | 仿真高分 + 评分≥4.5 + 成功率≥95% | 首页推荐、认证标 |
| ★★ 优质 | 评分≥4.0 + 成功率≥90% | 正常展示 |
| ★ 合格 | 过审即可 | 正常展示 |
| （不合格） | 未过审 | **不上架** |

**质量分来源（客观）**：仿真跑分 / 安装成功率 / 用户评分 / 卸载率 / 开发者等级 → `skill_quality`。
**降级规则**：上架后成功率骤降 / 大量差评 / 出事故 → 自动降级 + 进复审（联动 §4.19 熔断）。

### 4.22 设备 Agent 落地（v3.4 新增，判定 + 功能明细）

**落地判定（2026-10 一手调研）：三厂都能"不依赖厂商商店"自主部署**（官方文档/仓库即此路径：SSH + 容器/脚本）。

| 维度 | 宇树（EDU） | 智元 | 众擎 |
|---|---|---|---|
| 自主部署 | ✅ | ✅ | ✅ |
| SSH | `unitree@192.168.123.164`(默认123) | `agi@`+sudo | `ubuntu@192.168.0.162`(ubuntu) |
| 需过厂商云？ | 不需 | **不需**（资源 RPC 是本地 HTTP） | 本就无云 |
| 执行绕得开厂商？ | ❌ 必用 SDK2/DDS | ❌ 必用 AimDK | ❌ 必用 ROS2 |
| 实证 | dimOS/WendyOS/LeRobot | 官方部署文档 | install.sh |

**Agent 功能（F1–F5）**
- **F1 核心（三厂通用）**：引导装机（SSH 一条命令）→ **出站连接**（机器人不需公网 IP）→ 一机一证 mTLS → 声明式目标状态 → 验签+幂等 → 授权校验 → 健康上报 → **Agent 自更新**。
- **F2 载体部署（插件）**：宇树 docker load / 智元 拷 tmp+RPC / 众擎 rsync。
- **F3 签名回滚**：平台私钥签、设备验签；**Uptane/TUF** 防降级；失败自动回滚；高危技能**单次授权**（正好做试用）。
- **F4 安全加固**：**三家默认弱口令 → Agent 首次运行强制改密**；**不接入/篡改厂商云**（宇树 EULA 红线）。
- **F5 通道选择**：厂家接口 → 平台 agent → 纯手动。

**用户侧引导装机（决定普通用户能否自己装）**：平台必须提供**一键配对小工具**（Windows/Mac：插网线→点"连接并安装"，自动设 IP/SSH/装 Agent，**全程图形化不碰终端**）+ 每厂家 90 秒视频 + 付费远程代装。**终端/静态 IP/SSH 是真门槛，不是用户该承受的。**

---

## 5. 接口清单

### 5.1 C 端（uniapp，customers token）

| 接口 | 方法 | 状态 | 说明 |
|---|---|---|---|
| /api/auth/wechat | POST | 新增 | 微信登录 code 换 token 绑定 |
| /api/devices（CRUD） | GET/POST/PUT/DELETE | **已有** | 设备登记 |
| /api/device-bindings | GET/POST | 新增 | 确权绑定列表/发起绑定(Agent配对码/厂商授权) |
| /api/skills/mine | GET | **已有** | 含试用剩余时长 |
| /api/skills/{id}/compatibility | GET | 新增 | 兼容性+可用通道 |
| /api/skills/{id}/trial | POST | 新增 | 发起试用（20min） |
| /api/skills/import | POST | 新增 | **导入他平台技能包**（§4.4.3；上传源包或填源地址 → 解析 → 生成 `manifest.yaml` + `skill_import` 留痕） |
| /api/skills/entitlements/{id}/bind | PUT | **已有** | 授权指派设备（⚠ 实际路径带 `/api/skills/` 前缀，见 `Routes/api.php:199`） |
| /api/deploy/tasks | POST | 新增 | 创建下发（安装/卸载/回滚/启停） |
| /api/deploy/tasks/{taskNo} | GET | 新增 | 状态轮询（3s） |
| /api/deploy/tasks/{taskNo}/cancel | POST | 新增 | 取消 |
| /api/skills/entitlements/{id}/confirm-install | POST | **已有** | 人工通道自报 |
| /api/skills/entitlements/{id}/refund 或 /api/after-sale | POST | 新增 | **C 端退款/售后**（⚠ 现状退款仅 Admin，C 端无路由） |
| /api/risk/acknowledge | POST | 新增 | 风险告知留痕 |
| /api/skills/search | GET | 新增 | 技能搜索/筛选（机型/通道/动作类型/风险等级） |
| /api/skills/{id}/reviews | GET/POST | **可复用** | 技能评价（Bagisto `ProductReview` API 已有，接入技能场景） |
| /api/skills/{id}/report | POST | 新增 | 举报技能/评价 |
| /api/after-sale | POST/GET | 新增 | 售后/退款申请与列表（D10） |
| /api/tickets | POST/GET | 新增 | 客服工单 + 消息 |
| /api/notifications | GET/PUT | 新增 | 通知中心（站内信 + 订阅消息回执） |
| /api/device-bindings/{id}/unbind、/transfer | POST | 新增 | 设备解绑/转移 |
| /api/invoices | POST/GET | 新增 | 发票申请 |
| /api/checkout/*、/api/tob-orders | — | **已有** | 交易（支付方式升级为微信）；优惠券 API 已有可复用 |

### 5.2 设备/Agent（MQTT + HTTP）

- MQTT：`zhjj/{did}/cmd`、`zhjj/{did}/evt`（§4.6）；
- HTTP：`POST /go/agent/register`（首联注册换 DID+MQTT 凭证）、`POST /go/agent/heartbeat`（HTTP 兜底心跳）。

### 5.3 内部与回调

- Laravel → Go：internal.key（先例已有）：创建任务/查询状态/取消；
- 回调：`/api/callback/payment`（微信）、`/api/callback/vendor/{code}/deploy|bind`（P1 厂商）。

### 5.4 Admin 端

- 审核队列/详情/通过/驳回、机型库 CRUD、技能版本管理（升级现有 SkillAdminController）、**导入任务管理（`skill_import` 列表/映射预览/警告处置）**、设备绑定列表、结算/提现审核、operation_log 查询、看板聚合接口。
- **v3.2 新增**：内容安全检测与处置、技能类目/属性管理、开发者入驻审核与封禁、工单/纠纷仲裁、**技能熔断 kill switch**、三方对账、发票管理、审核 SLA 监控、事故上报管理、备案台账。

### 5.5 开发者端（独立门户，开发者 OAuth + API Key）

| 接口 | 方法 | 说明 |
|---|---|---|
| /dev/auth/* | — | 开发者登录（微信扫码/手机号）、与 C 端同账号体系 |
| /api/dev/profile、/agreements | GET/POST | 主体认证、协议与责任承诺签署 |
| /api/dev/team/* | CRUD | 团队成员与角色（RBAC） |
| /api/dev/skills/* | CRUD | 技能记录、元数据（多语言/截图/预览） |
| /api/dev/skills/{id}/versions/* | CRUD | 版本管理、包上传、下架、回滚 |
| /api/dev/skills/import | POST | 导入他平台技能包（§4.4.3） |
| /api/dev/skills/{id}/submit | POST | 提交审核 |
| /api/dev/audits/* | GET/POST | 审核状态、驳回理由、申诉 |
| /api/dev/skills/{id}/beta | POST | **预发布测试轨**：Beta 分发到指定设备/测试者 |
| /api/dev/analytics/* | GET | **开发者数据看板**（安装成功率/机型/错误码/评分/卸载率） |
| /api/dev/finance/* | GET/POST | 收入报表、结算单、提现申请 |
| /api/dev/apps、/credentials | CRUD | 应用与密钥（AppID/Secret/轮换/Scope）、`api_call_log` |
| /api/dev/tools/* | GET | SDK/CLI 下载、仿真接入、真机调试、重定向工具 |

---

## 6. 前端页面规划（uniapp）

| 页面/组件 | 状态 | 内容 |
|---|---|---|
| 技能市场（生态中心+机器页） | **已有** | 加"可用通道/试用"角标 |
| 技能详情（home/jineng+浮层） | **浮层已有·jineng 页为 mock** | ⚠ `pages/home/jineng.vue` 现为纯 mock（picsum 图+写死评论），**需重接后端**；真实详情在机器页浮层 `robot/index.vue:280`。加适配机型实数据、版本历史、试用按钮 |
| 兼容性校验组件 | 新增 | 详情页自动校验 → 灰按钮+原因 |
| 风险告知+通道选择弹窗 | 新增 | 双通道风险说明/勾选/通道选择 |
| 下发进度页 | 新增 | 状态机可视化+实时日志（3s 轮询） |
| 下发结果页 | 新增 | 成功/失败原因/重试 |
| 我的技能 | **已有** | 扩展：启停/更新/卸载/回退入口、试用倒计时 |
| 我的设备 | **已有** | 扩展：确权状态、Agent 安装引导、在线状态 |
| 微信登录 | 新增 | 全局替换/并存 |
| 技能搜索/筛选页 | 新增 | 机型/通道/动作类型/风险等级（§4.18） |
| 技能评价/晒单 | 新增 | 复用 `ProductReview` 接入技能（§4.18） |
| 售后/退款申请 | 新增 | 虚拟商品政策（D10） |
| 客服工单 | 新增 | 用户↔平台↔开发者（§4.18） |
| 通知中心 | 新增 | 站内信 + 微信订阅消息 |
| 设备解绑/转移 | 新增 | 换机/二手场景 |
| 举报 | 新增 | 技能/评价举报（Apple UGC 四件套） |

**开发者门户页面（v3.2 新增，独立 Web，小程序/App web-view 可打开轻量视图）**

| 页面 | 说明 |
|---|---|
| 入驻/登录/协议签署 | 主体认证 + 责任承诺电子签（§4.15 A） |
| 技能列表 / 技能编辑 | 元数据、多语言、图标/截图/预览（B） |
| 包上传 / 版本管理 | `.skill` 上传或导入；版本历史/下架/回滚（B） |
| 提交审核 / 审核状态 / 申诉 | 状态跟踪 + 驳回理由 + 申诉（C） |
| Beta 测试分发 | 指定设备/测试者（D，对标 TestFlight） |
| 数据看板 | 安装成功率/机型/错误码/评分/卸载率（E） |
| 财务/结算/提现 | 收入报表、结算单（F） |
| 应用与密钥 | AppID/Secret/轮换/调用日志（G） |
| 工具链 | SDK/CLI、仿真接入、真机调试、重定向（H） |
| 文档/工单/社区/赛事 | 支持与增长（I） |

> **多端可达**：小程序/App 内 `web-view` 打开 **轻量视图**（审核状态、数据概览、消息、工单）；传包、密钥、大屏看数据等**重操作引导到 Web**。Web 站点需独立备案域名。

---

## 7. 排期（v3.2 扩容：**P0 ≈ 8–10 周**，P1 ≈ 3–4 周）

> v3.1 的"4 周 P0"在补入开发者端 + 平台端 + 合规后**装不下**。且**微信支付、AI 三层备案、开发者域名备案都是外部审批，必须像支付一样前置并行**，不能计入开发工时。

**阶段 0 · 外部审批与并行准备（立即启动，不占开发工时）**
微信支付商户申请/类目审核；**AI 三层备案（算法/大模型/拟人化互动）**；**开发者门户独立域名备案**；EMQX + MinIO/Redis 端口暴露（**MinIO 独立 bucket + 独立凭证**）；Go 服务脚手架；自营技能素材准备（§4.13 ①）+ 赛事窗口报批（②）。
**＋ v3.3 技术前置三件（不先做，阶段三会整体返工）**：
1. **异步底座接入**〔**2026-10-14 更新：已落地，此处原文已过时**〕：原文写"现 `.env` 为 `QUEUE_CONNECTION=sync`、无 scheduler、Windows 无 cron"。
   实际落地方案不是 Redis，而是 **`database` 驱动**（`QUEUE_CONNECTION=database` + `CACHE_STORE=database`，队列/缓存/原子锁/worker 消费均已实测），
   `bootstrap/app.php` 已排 4 条运维任务。**剩下的只是部署动作**：`queue:work` / `schedule:work` 常驻进程本机没起
   —— 实测 19 条 job 积压 25 小时（§4.7 试用回收、§4.9 结算、§4.10 灰度、§4.16 通知确实全依赖它，这一点原文的因果判断是对的）。
2. **冻结「技能交付术语表」**：channel=交付通道 / release_channel=发布通道 / carrier_type 白名单 / pricing_mode vs license_mode（§3.1）。✅ 已落地。
3. **选定「设备唯一真相源 = binding」**并改 v1 三个接口（§3.2）。✅ 已落地。

**阶段一 · 交易与用户端补全（W1–W3）**
- D1：微信登录；operation_log；sellers 实名列
- D2：微信支付下单/回调/退款；entitlements 幂等联调
- D3：device_model 机型库（种子迁移）+ Admin CRUD
- D4：device_binding 表 + Agent 注册换 DID 接口（Go）
- D5：兼容性校验接口 + 详情页灰按钮；联调
- **D5.5（v3.2）**：用户端补全——**虚拟商品售后/退款政策 + 工单 + 通知中心 + 技能搜索/筛选 + 评价接入技能**

**阶段二 · 供给侧（W4–W6）**
- D6：`.skill` 格式（`manifest.yaml` 超集）校验 + **导入适配器 v1**（宇树/智元/BVH）+ MinIO 存储改造
- D7：静态扫描三层 + security_scan 落库
- D8：提示注入检测（LLM 通道）+ **内容安全检测（§4.16）**
- D9：审核两阶段队列 + 驳回重提 + **驳回理由库/申诉**
- D10：**技能类目/属性体系**；自营技能填充 + 赛事引流落地（§4.13）→ 验收货架 ≥N 个可下发技能

**阶段三 · 下发链路（W7–W10）**
- D11–D17 同 v3.1（EMQX/Agent 协议/状态机/受控执行/internal API/风险告知/install_record/试用回收/三通道联调）
- **D17.5（v3.2）**：**技能熔断 kill switch + 事故上报闭环（§4.19）**

**阶段四 · 开发者端补全（W4–W10，独立人力并行）**  ← v3.2 新增，最大工作量
- D18：开发者门户工程脚手架 + 入驻/认证/协议签署（§4.15 A）
- D19：技能 CRUD / 包上传 / 版本管理 / 定价（B）
- D20：提交审核 + 状态跟踪 + 驳回 + 申诉（C）
- D21：**预发布测试轨 Beta 分发**（D）
- D22：**开发者数据看板**（E）
- D23：凭证与 Key 生命周期（G）；财务/结算（F）
- D24：工具链入口（SDK/仿真/真机调试/重定向）+ 文档（H/I）；小程序/App web-view 轻量视图

**阶段五 · 平台端与合规收口（W6–W10）**
开发者入驻审核/封禁、工单仲裁、对账、风控、ACL 细化、机器人专属看板、发票、备案台账落库、隐私政策与责任边界上线。

**阶段六 · P1（W11+）**：灰度/自动回滚深化 → 结算提现（微信分账/商家转账）→ Prometheus 告警 → 厂商 API 适配器（宇树或智元试点）→ 导入器 v2（LeRobot/更多厂商）→ 数据集/训练工具链 → AI 召唤推技能。

**验收**：
- 一：真实微信支付买到授权；售后/工单/通知/搜索/评价可用
- 二：开发者上传 `.skill` **或导入他平台包** → 扫描 → 两阶段审核 → 上架；货架 ≥N 个可下发技能
- 三（=P0 总验收）：Agent 模拟设备上，购买→绑定→**分级风险告知留痕**→下发→受控执行→生效→卸载→试用回收全链路；**技能熔断一键禁用已装设备**
- 四：**开发者自助走通 入驻→建技能→传包→Beta 测试→提审→看数据→结算**；小程序/App 可打开开发者后台轻量视图
- 五：备案台账齐备、隐私政策上线、事故上报闭环可用
- 六：灰度回滚可用；结算提现走通；告警触达

---

## 8. 风险与应对

| 风险 | 等级 | 应对 |
|---|---|---|
| 微信支付类目审核受阻（虚拟商品类目） | 高 | 阶段 0 立即申请；备选：先收款码/线下对公过渡，回调记账后补 |
| 封闭机型装不了 Agent | 高 | 三通道设计已兜底（D2）；厂商 API 通道 P1 试点 |
| 试用回收被拔线绕过 | 中 | 已知局限记录；P1 加"技能启动时授权校验" |
| 静态扫描误报 | 中 | WARN 不拦截进人工复审；规则热更新 |
| EMQX/Go 新组件运维生疏 | 中 | docker compose 编排进现有 coze 栈统一管理 |
| 微信分账资质门槛（P1） | 中 | 达不到则改为月度人工打款+settlement 记录 |
| **供给冷启动失败（§4.13）** | **高** | 平台自营先填货架 + 赛事/基金引流 + 导入器搬运存量；北极星指标盯"可下发技能数×覆盖机型数"，不盯注册数 |
| **物理安全责任（§4.14）** | **高** | 三层责任模型：开发者签承诺+权限声明、沙盒受控执行、用户分级告知留痕；`high` 风险技能人工复审+仿真预跑 |
| **厂商远程砖化 / 单点依赖** | 中 | 行业先例：Astro for Business 远程停用+退款、Pepper 硬件退市→商店殉葬。**不要把用户已购技能单点绑在某厂商云上**；`motion/` 层保留可迁移载体 |
| **跨本体重定向不达标** | 中 | 先只对已开放 SDK 品牌（宇树/智元/众擎/乐聚/傅利叶）承诺跨本体；其余品牌明确"仅人工通道+展示"，不夸大互通性 |
| 导入包许可风险 | 中 | 导入器只做技术搬运；上架前人工核许可（如 AgiBot World 为 CC BY-NC-SA **非商用**），商用技能须作者授权 |
| **开发者端不到位 → 冷启动失败** | **高** | D7 独立开发者门户是补给侧的"门面"；不做则赛事奖金只换一次性作品（见 §4.15 末注） |
| **合规审批受阻（AI 三层备案/域名备案）** | **高** | 与微信支付同列**阶段 0 前置并行**；拟人化互动办法 2026-07-15 已施行，涉情感陪伴技能尤其触发 |
| **内容安全漏审** | 中 | 接微信 `msgSecCheck` + 多媒体检测，违规阻断上架；评价/UGC 亦需过检（Apple UGC 强制） |
| **物理事故与责任** | **高** | §4.19 事故闭环 + 责任追溯链（承诺签署+告知留痕+操作日志）+ 责任保险；高风险技能人工复审 |
| **小程序 web-view 域名限制** | 中 | 开发者门户需独立**备案**域名方可被小程序 web-view 打开；未备案前小程序端先只放"消息/概览"轻视图 |

---

## 9. 与 v2.0 文档的差异对照（为什么砍/留）

| v2.0 内容 | 本版处置 |
|---|---|
| 独立 user/order/payment/skill 四表 + Go 全栈 | 砍：复用 customers/orders/entitlements/products（幂等设计已验证），Go 只做下发 |
| 30 张表 | 9 复用 + 13 新建 |
| 独立 Vue3 双后台 | 砍（V1）：全部落 Bagisto Admin（menu/acl 定制先例现成）；V2 视开发者规模再独立 |
| VendorAdapter 厂商适配为核心 | 降级为三通道之一（P1 按厂商） |
| MySQL8/Redis/RabbitMQ/ES 新起 | 砍：MySQL 5.7 不动；Redis/MinIO 复用 coze；MQ 用 EMQX+Go 原生；ES 不做 |
| Docker 沙盒 V1.5 | 保留，**v3.1 提到 P0**（会物理执行的技能，沙盒是准入门槛，见 §4.14 L2） |
| 订阅/续费 | 砍（D3 买断制）；时长授权字段保留以备未来 |
| 21 天排期 | v3.1 修正为 4 周 P0 + 2 周 P1；**v3.2 再扩容为 8–10 周 P0**（补入开发者端 + 平台端 + 合规），外部审批前置并行 |
| **（v3.2 反悔）独立 Vue3 双后台** | **v3.1 曾"砍"，v3.2 作废此决策**：保留 Bagisto Admin 作平台运营端，**新增独立开发者门户**（D7）——头部标配，砍了开发者端永远是 20% |
| **（v3.3 修正）文档与 v1 代码不符** | 架构师逐行对码发现：`carrier_type` 词表、`license_mode='perpetual'`、`app/manual` 命名、设备双真相源——均已按 §3.1/§3.2 修正，避免"照文档写即报错" |

---

## 10. 行业对标摘要（v3.1 新增，2026-10-10 调研沉淀）

> 来源可信度：宇树、智元为**官方一手**（`unitreerobotics/unitree-app-templates` 仓库、智元开放平台 AIMDK 文档原文）；应用商店先例为**媒体/搜索层**（页面正文受网络策略限制未能逐页复核，标 ⚠）。

| 平台 | 技能商店 | 技能包形态 | 设备身份 | 审核 | 分成/定价 |
|---|---|---|---|---|---|
| **宇树 UniStore**（2025-12 首发→2026-05 全开）【高】 | 有（用户广场/动作库/数据集/开发者中心） | Docker 化 `tar.gz` + `metadata.yaml`（`base_image/permissions/startup_commands`），DDS 接管官方运控 | **SN / 工信部 29 位一机一码** | 1–5 工作日；G1 App≥1.9.0、OTA≥1.4.8 | 首批 24 动作**全限免**；称有分成但**比例未公开** |
| **智元 AIMA**（2026-06-09）【高】 | 无付费市场（创作+共享+竞赛+基金） | 本体本地资源；`resource_type` 六分类；`.macp` 动作、`.gz` 迁移 | **SN**（`/agibot/data/info/sn`） | 开发者认证+发凭证（运营方审核） | "元苼"5 年 20 亿、大赛 68 万；**无付费分成** |
| 众擎/乐聚/傅利叶 | 无 | 全开源 SDK + 数据集 + 赛事 | — | 开源 | 赛事奖金（乐聚 ~50 万 / ~9 万美元） |
| 达闼 CloudMinds | 概念性云端应用商店 | 云端下发驱动本体 | — | — | ⚠ 无分成实证 |
| 大疆 DJI | **非软件商店** | PSDK 认证硬件载荷插机体 | 机型绑定 | 兼容认证 | — |
| SoftBank Pepper | 有（应用需**物理装到机器人**） | App | — | — | 硬件停产→商店殉葬 |
| OpenMind ⚠ | 2026-01 机器人应用商店 | OM1 HAL 抽象层 | — | — | x402/USDC 链上支付 |
| 手机对照：Apple / Google / 微信 | — | — | — | — | 30%（小企业 15%）/ 15%→30% / **微信小程序≈0 佣金（仅 0.6% 通道费）** |

**五条影响本计划的结论**
1. **没有一家在做"付费技能市场+平台抽成"** → 本计划 D3 买断+分成是**押注而非对标**，P0 宜"自营+免费引流"，分成后置。
2. **没有一家用 DID**，全锚定 **SN**，工信部已推 **29 位一机一码** → §2.2 建议 `device_binding` 增 `national_code` 与行业对齐。
3. **技能包的行业真实形态 ≠ v3.0 自创格式** → 触发本次 §4.4 重写（对齐宇树 `metadata.yaml` + 导入器）。
4. **行业最大死结是供给冷启动**（宇树第三方仅 1 个、智元靠砸钱）→ 触发新增 §4.13。
5. **物理安全责任是行业最高成本项**（宇树做到"沙盒+权限+审核"也只放 1 个第三方）→ 触发新增 §4.14 三层责任模型。

---

## 11. 全球技能交易要求与现实路径（v3.3 新增，依据 [skill-plan-verification.md](./skill-plan-verification.md)）

> 本节替代 §0.5 的"详见 §11"悬空引用。

### 11.1 能力验证结论（实测）

| 能力 | 底座现状 | 现实结论 |
|---|---|---|
| 多语言 | **具备·仅启用 zh_CN**（per-channel×locale、RTL 支持；注意 Bagisto 英文码是 **`en` 非 `en_US`**）；**前端 uni-app 零 i18n** | 后端近零开发；**前端从 0 建 i18n** |
| 多币种 | **具备·仅 CNY**；`currency_exchange_rates` **0 行**；有汇率命令+两 provider，**未配 key 未调度** | 配 key+挂调度+**灌汇率**才算可用 |
| 多区域 | **具备·仅 1 个 channel**；按 hostname 路由；有 per-channel 定价 | channels 可用；**税/合规差异需外挂** |
| 海外支付 | PayPal 现成；**无 Stripe/微信/支付宝**；分账需 Stripe Connect（Bagisto 无） | PayPal 可行；**Stripe 不收大陆主体**→ 两个法务主体 |
| 全球存储 | s3 盘已定义但**未装驱动**；技能包现落**本地 private 盘** | S3/R2+CDN 是配置级；大陆节点需备案域名+持牌 CDN |

### 11.2 现实边界（2026）

**技术不是瓶颈**——Bagisto 的多语言/多币种/多渠道本就是开箱能力（本项目只是全关着）。真瓶颈在三层：
1. **前端**：uni-app 是彻底的"中文本地应用"（零 i18n、`¥` 硬编码、单一后端），多语言/多币种要**从零建**。
2. **支付与资金**：**Stripe 不收中国大陆主体**，"国内收微信 + 海外收 Stripe"天然是**两个法务主体**——这是**组织/牌照问题，不是代码问题**。
3. **合规**：合同履约类业务数据出境**很宽松**（CAC 令第16号第 3/5 条：合同确需 + 年累计 <10 万人全免）；**红线是机器人采集的人脸/生物特征（敏感 PI，年累计 1 万人即触发）**与**拟人化互动备案（2026-07-15 施行）**。

### 11.3 落地策略：**架构预留全球，区域逐个开通**

- **架构上**：技能 manifest 从一开始就多语言（`name:{zh/en}`，**用 `en` 对齐 Bagisto**）；金额存 UTC+基准币；channel 承载区域。
- **不追求"一步全球"**：**先在国内 + 设备/客户所在区域把"下发闭环"跑通**，再按区域逐开 channel。
- **阶段 0 前置并行**（同微信支付资质）：海外支付主体（HK/US 实体可选）、数据出境路径判定（避免敏感 PI 出境）、内容/拟人化备案。
- **敏感数据硬约束**：技能包**不得携带**可识别的生物特征数据出境；机器人摄像头数据默认**端侧处理、不出境**。

### 11.4 全球招募与合规分级（技能侧）

| 区域 | 可交易 | 门槛 |
|---|---|---|
| 中国大陆 | 可 | 微信/支付宝 · 内容备案 · 实名 |
| 海外（欧美等） | 可（同主体可承载者） | Stripe/PayPal · GDPR（SCC+DPIA）· 敏感数据不出境 |

> **诚实结论**：一个"面向全球、先跑通单区域、付费技能市场"的平台在 2026 年**技术上可行**；但"付费技能市场+平台抽成"这一**商业模型**行业尚无成功先例——**最大风险不是"能不能全球"，而是"有没有人买单"**（见团队评审 §4）。

---

## 12. 专项文档索引（v3.4 新增）

> 主计划给**决策与契约**；下列专项文档给**深度细节**。改对应模块前先读它。

| 文档 | 内容 | 对应主计划 |
|---|---|---|
| [skill-changes-summary.md](./skill-changes-summary.md) | **近期改动白话汇总**（含未做/待决策；§12 与本文 §4.1–§4.22 逐条对应） | §4 全篇 |
| [skill-p0-board.md](./skill-p0-board.md) | **P0 可执行看板**（任务/人日/依赖/里程碑） | §7 排期 |
| [agent-adaptation-plan.md](./agent-adaptation-plan.md) | **Agent 判定 + F1–F5 功能明细 + 协议** | §4.22 |
| [skill-standardization-chain.md](./skill-standardization-chain.md) | **转换层全链路**（上传↔编译↔下发） | §4.20 |
| [skill-quality-and-review.md](./skill-quality-and-review.md) | **质量体系 + 过审门槛 + 拒绝清单** | §4.8 / §4.21 |
| [device-model-seed.md](./device-model-seed.md) | **机型库种子数据**（含数据缺口） | §3.2 |
| [skill-user-tutorial.md](./skill-user-tutorial.md) | **用户手册**（零基础装 Agent） | §4.22 |
| [skill-plan-verification.md](./skill-plan-verification.md) | **逐项验证矩阵**（已有/缺失/假设不成立，带代码行号） | 全篇 |
| [skill-plan-team-review-2026-10.md](./skill-plan-team-review-2026-10.md) | 团队评审（架构/市场/合规/产品） | 风险 |
| [skill-plan-audit-2026-10.md](./skill-plan-audit-2026-10.md) / [skill-plan-boss-response.md](./skill-plan-boss-response.md) | 三端体检 / Boss 决议回应 | 背景 |
| [skill-flows-and-readiness.md](./skill-flows-and-readiness.md) | 开发前准备 + 三端交互路径 | §4 / §7 |
