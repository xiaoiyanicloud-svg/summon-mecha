# 技能供需平台 · 计划与技术逐项验证矩阵

> 日期：2026-10-12 ｜ 对象：[skill-supply-plan.md](./skill-supply-plan.md) v3.3
> 方法：**逐项拿到真实代码底座里核对**（Bagisto@`my-bagisto-store` + uni-app@`d:\main`），每项给文件:行号证据；全球能力另做行业/法规对标。**"底座具备(未启用)" ≠ "缺失"**。
> 结论口径：✅已实现 ／ ♻️具备未启用 ／ 🟠部分 ／ ❌不存在 ／ ⚠️**计划假设不成立**
>
> ⚠ **2026-10-14 更新（只此一处，本表其余行未重新核验）**：本文件写于 10-12，其中
> **第 15 项「队列 / 调度」已不再成立** —— `.env` 已切 `QUEUE_CONNECTION=database` + `CACHE_STORE=database`；
> `bootstrap/app.php` 的 `withSchedule()` 已排 4 条运维任务；`app/Console/Commands` 已存在（4 个命令，均支持 `--dry-run`）；
> `/up` 已挂 DB/Cache/Queue 深检。**仍未解决的**：常驻 `queue:work` / `schedule:work` 是**部署动作**，本机没起
> —— 实测 19 条 job 积压 25 小时，这正是"配好了但没人跑"的样子。
> 引用本表任何一行前请自己跑一遍；现状见 [skill-remaining-closure.md](./skill-remaining-closure.md)。

---

## A. 技能功能逐项验证（代码实测）

| # | 项 | 结论 | 证据（文件:行） | 对计划的影响 |
|---|---|---|---|---|
| 1 | 技能市场列表/详情 + 分类 | 🟠 部分 | `skill` 分类真实存在（DB category_id=5）；后台按分类取商品 `Admin/.../Catalog/SkillController.php:34`；首页生态中心接真实商品 `components/EcoCenter.vue:467` | 列表可复用；**技能专属类目/筛选体系不存在**（见 C-5） |
| 2 | 详情页 `home/jineng`+浮层、`mine/skills`、`mine/devices` | 🟠 部分 | `pages/mine/skills.vue`（全接后端）、`devices.vue`（接 deviceApi/skillApi）**可复用**；机器页详情浮层 `pages/robot/index.vue:280` 用真实商品；**但 `pages/home/jineng.vue` 是纯 mock**（`:91` getMockData，picsum 图 + 写死评论） | `home/jineng` 详情页**需重接真实数据**，不是"已有" |
| 3 | 授权/下载/绑定/自报安装 | ✅ | `SkillController.php:28/58/86/130/187`；路由 `Routes/api.php:199`；模型状态机 `SkillEntitlement.php:126` | 直接复用，§1.1 属实 |
| 4 | 版本与交付物（carrier_type 真实值） | ✅ | 白名单真实值 = **`file / cloud_config / remote_service / api_bundle`**：`SkillAsset.php:30-43`、`SkillAdminController.php:99` | **v3.3 对 carrier_type 的修正正确**，原 `*_package` 确会被 422 |
| 5 | 上传通道 SkillAdminController + internal.key | ✅ 已启用 | `SkillAdminController.php`；中间件 `VerifyInternalKey.php`；key 在 `.env:154` 已设 | 直接可用 |
| 6 | 评价 `ProductReview` + API | ♻️ 后端有·**未接技能/前端** | 表/模型/仓储齐；API `API/ReviewController.php`（`Routes/api.php:56`）。**DB product_reviews 0 行**，前端零调用（`home/jineng` 用 mock 评论） | "可复用"仅指服务端；**技能评价页要从零建** |
| 7 | 优惠券 `CartRule` + coupon API | ♻️ 有·**未配置·对技能无效** | `CartRule/` 包；`CartController.php:201`；`Routes/api.php:127`。**DB cart_rules 0 行**；**技能走 tob-order 不经购物车**，券无路径 | 复用可行，但**技能单要新建券逻辑** |
| 8 | 收藏/对比 | ♻️ 后端有·前端零入口 | `WishlistController.php`、`CompareController.php`；`Routes/api.php:104/234`；**`utils/api.js` 无封装** | 后端可复用，前端要建 |
| 9 | 搜索 | 🟠 DNS 搜索有·技能无·ES 未启用 | 服务端 `ProductController.php:31`（`searchEngine='database'`）；ES `ElasticSearchRepository.php` 存在但被 `engine=='elastic'` 门控未开；**无 Scout**（composer 未 require）；前端机器人库搜索是**本地过滤** `pages/robot/index.vue:816`，**技能 tab 无搜索框** | §4.18"升级为服务端搜索"成立；"不做 ES"与现状一致，但**别以为已有 ES** |
| 10 | 多语言 | ♻️ 后端支持·**前端单语言** | locales 表 21 种、`product_flat.locale` 逐语言；**前端无 i18n**（无 vue-i18n/locale 目录） | 后端可复用；前端多语言零 |
| 11 | 多币种 | ⚠️ **框架支持·实际只 CNY** | currencies 表 65 行、channel_currencies 全启用、base=CNY；**`currency_exchange_rates` 0 行** → 无法换算 | ⚠ **"多币种支撑全球"不成立**（见 C-2） |
| 12 | 支付方式 | 🟠 4 种（COD/转账/PayPal×2）·**无 Stripe·无微信** | `Payment/.../paymentmethods.php`、`Paypal/.../paymentmethods.php`（均已 active）；**全仓无 stripe/wechat**（grep 空） | §4.2 微信支付**必须新建**；PayPal 现成可海外用 |
| 13 | 微信登录 | ⚠️ **包在·无微信 provider** | `SocialLogin/` 已注册；providers 仅 facebook/twitter/google/linkedin/github（`config/services.php:53`），**无 wechat** | ⚠ **"复用 SocialLogin 包"不成立**（见 C-1）；须自研 code2session |
| 14 | 订单/结算/退款 | 🟠 状态机在·**C 端退款 API 无** | `Sales/Models/Order.php:37`；退款仅 Admin `RefundController`，**Shop/C 端无 refund 路由** | 退款 API + 售后表族**全新建** |
| 15 | 队列/调度 | ⚠️ **sync·无 scheduler·无 cron** | `.env:42` `QUEUE_CONNECTION=sync`；`app/Console` 目录不存在（Laravel 11 无 Kernel）；`bootstrap/app.php:53` `withSchedule()` 空 | ⚠ §7 阶段 0"接 Redis+queue+scheduler"**属实且必需**（见 C-9） |
| 16 | 对象存储 | ⚠️ **落本地 private 盘·非对象存储** | `SkillAsset::DISK='private'` → `filesystems.php:40` = `storage/app/private`；s3 盘已定义但 AWS_* 全空；coze MinIO **无连接** | ⚠ **"已上 MinIO"不成立**（见 C-3）；存储改造是真活 |

---

## B. 全球交易能力逐项验证

| # | 能力 | 底座现状 | 现实结论 |
|---|---|---|---|
| 1 | 多语言 i18n | **后端具备·仅启用 zh_CN**（per-channel×locale 内容、RTL 支持；注意 **Bagisto 英文码是 `en` 非 `en_US`**）；**前端 uni-app 零 i18n**（字符串硬编码） | 后端近零开发；**前端要从 0 建 i18n 基建** |
| 2 | 多币种/汇率 | **具备·仅启用 CNY**；`currency_exchange_rates` **0 行**；有 `exchange-rate:update` 命令 + Fixer/ExchangeRates 两 provider，**未配 key 未调度** | 配 key + 挂调度即可；**不灌汇率则 convertPrice 原样返回=不换算** |
| 3 | 多区域/多渠道 | **具备·仅 1 个 default channel**；`channels` 承载 theme/hostname/locale/币种/root_category；按 hostname 路由；**per-channel 定价**（`product_price_indices.channel_id`） | channels 正是为分区设计；**税/合规差异不在 Bagisto**，需外挂 |
| 4 | 海外支付/分账 | PayPal 现成（Smart Button + Standard，含 Capture/Refund）；**无 Stripe/微信/支付宝**；多商户分账需 **Stripe Connect**（Bagisto 无，需自建）；**Stripe 不收中国大陆主体**（须 HK/US 实体） | PayPal 可行；**Stripe Connect 有难度且是组织/牌照问题**；"国内微信 + 海外 Stripe"天然两个主体 |
| 5 | 全球存储/CDN | s3 盘已定义但**未装驱动**（无 aws-sdk）；`FILESYSTEM_DISK=public` 本地 | S3/R2/OSS + CDN 是配置级；**中国大陆节点需 ICP 备案域名 + 持牌 CDN** |
| 6 | 数据出境（中国） | 无合规模块（法律+流程问题） | 见 C-合规要点 |
| 7 | 时区/跨区结算 | 全局 `Asia/Shanghai`；`channels.timezone` 列**有但实际 NULL**（无 Admin 表单）；无 per-user tz | 存 UTC + 启用 channel tz 可行；**跨区结算 Bagisto 很弱，须自建** |
| 8 | 海外厂商/可比市场 | 平台侧无任何厂商适配器；本地 40+ 品牌映射 | **可比市场存在但全早期**：OpenMind（2026-01，合作含智元/优必选/云深处/傅利叶/越疆等，x402 支付）、宇树 UniStore（全免费）、智元（无付费市场）。**没有一家"付费技能市场+平台抽成"** |

**数据出境要点（CAC 令第16号，2024-03-22）**：第 3 条（国际贸易不含 PI/重要数据 → 全免）、第 5 条（**为订立/履行合同**，原文列举"跨境购物、跨境支付" → **全免**；年累计 **<10 万人** PI → 全免）。**红线**：机器人采集的**人脸/生物特征/健康=敏感 PI，年累计 1 万人即触发**；内容侧触发**拟人化互动服务安全评估（2026-07-15 施行）**。GDPR 侧中欧无充分性认定，需 SCC + DPIA（条款需律师确认）。

---

## C. ⚠️ 计划中"假设不成立"的项（必须修正）

| # | 计划原文假设 | 实测真相 | 处置 |
|---|---|---|---|
| C-1 | 微信登录复用 `SocialLogin` 包 | 包无 wechat provider | 改为**自研 code2session** |
| C-2 | 多币种已就绪、支撑全球交易 | `currency_exchange_rates` 0 行，实际只 CNY | 改为"**具备但未启用**"，配 key+调度+灌汇率才算 |
| C-3 | 技能包已在 MinIO / 复用 coze | 落**本地 private 盘**，S3/MinIO 未接入 | 存储改造是**真活**，非"复用" |
| C-4 | 技能详情页 `home/jineng` 用真实数据 | 该页**纯 mock** | 列为"需重接后端" |
| C-5 | 技能类目/属性（动作类型/场景/难度/风险）体系 | **不存在**（仅 Bagisto `skill` 分类 id=5） | 明确为**新建** |
| C-6 | 评价/优惠券"已接入技能+前端页" | 后端有 API 但**未接技能、前端零入口**，且技能不经购物车 | 改为"**后端可复用，技能侧全新建**" |
| C-7 | C 端退款 API 存在 | **不存在**（仅 Admin） | 明确为**新建** |
| C-8 | 商品搜索对技能可用 | 是**前端本地过滤**，技能 tab 无搜索框 | 明确"技能搜索需新建" |
| C-9 | 队列/调度已具备 | `sync`、无 scheduler、无 cron | §7 阶段 0 前置**属实且必需** |

**另有 2 处路径/说法需校准**
- 路由前缀：v1 实际是 `/api/skills/entitlements/{id}/bind`、`/api/skills/entitlements/{id}/confirm-install`（带 `/api/skills/` 前缀），计划 §5.1 写作 `/api/entitlements/...` → 订正。
- `skill_entitlements (status,expires_at)` 索引：v1 **确实没有** → v3.3 该项属实。
- v1 默认交付通道 `app` = 计划 `manual`，仅 `app` 实现 → v3.3 命名定案属实。

---

## D. 结论：计划哪些可照做、哪些要改

**可以照做（有实证底座）**：授权/下载/绑定/自报安装（§4.5）、上传通道（§4.10）、版本与交付物（§4.4 主体）、Bagisto 多语言/多币种/多渠道（**前提是去启用**）、PayPal 海外收款。

**必须改（假设不成立）**：C-1～C-9 全部。

**"全球"的现实边界（2026）**：**技术底座不是瓶颈**（Bagisto 的多语言/多币种/渠道是开箱能力，只是关着），真瓶颈在三层——① **前端**（uni-app 零 i18n，从 0 建）；② **支付与资金**（无 Stripe/微信，Stripe 不收大陆主体 → **两个法务主体**，是组织/牌照问题）；③ **合规**（合同履约类业务数据出境**很宽松**，但**机器人采集的敏感生物特征 1 万人/年即触发**、内容侧触发拟人化备案）。→ **建议把"全球"降级为架构承诺**：先在国内+单区域把"设备下发闭环"跑通，再按设备与客户所在区域逐区开通；支付主体与合规作为**阶段 0 前置并行**（同微信支付资质）。
