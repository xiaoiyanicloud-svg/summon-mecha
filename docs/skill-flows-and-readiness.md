# 技能功能 · 开发前准备检查 + 用户端/开发者端交互路径

> 日期：2026-10-13 ｜ 依据：[skill-supply-plan.md](./skill-supply-plan.md) v3.3、[skill-p0-board.md](./skill-p0-board.md)、[skill-plan-verification.md](./skill-plan-verification.md)
> 范围：**只含技能供需板块**
> 图例：✅已有 ／ 🆕新建 ／ ♻️复用平台能力 ／ ⏸️条件触发 ／ 🔴阻塞
>
> ⚠ **2026-10-14 更新（只此一处，其余行未重新核验）**：**P1「异步底座」已不是 🔴** ——
> `.env` 已切 `QUEUE_CONNECTION=database` + `CACHE_STORE=database`，`withSchedule()` 已排 4 条运维任务。
> 剩下的只是**部署时起常驻进程**（`queue:work` / `schedule:work`），不是代码阻塞。
> P2（术语表）与 P3（设备真相源=binding）**均已落地**。

---

# 第一部分 · 开发前准备检查

## 1.1 已就绪（可立即用）

| 项 | 证据 | 状态 |
|---|---|---|
| v1 交易底座：授权/下载/绑定/自报安装 | `SkillController.php`（mine/assets/download/bindDevice/confirmInstall） | ✅ |
| 下单幂等发授权（挂事件、吞异常、防重试重复） | `Listeners/SkillDelivery.php` | ✅ |
| 技能版本与交付物表 | `SkillVersion`/`SkillAsset`（carrier_type=`file/cloud_config/remote_service/api_bundle`） | ✅ |
| 上传通道（internal.key） | `SkillAdminController.php` + `VerifyInternalKey.php`，key 已设 | ✅ |
| 交付通道注册表 | `config/skill_delivery.php`（默认 `app`=人工） | ✅ |
| 后台技能菜单/ACL/路由 | `Admin` menu/acl/catalog-routes | ✅ |
| 前端"我的技能/我的设备" | `pages/mine/skills.vue`、`devices.vue`（已接后端） | ✅ |
| 平台可复用：评价/优惠券/CMS/活动/注销/社交登录框架 | Bagisto 原生（**注意：均未接技能、前端零入口**） | ♻️ |

## 1.2 开发前**必须补齐的前置**（不做会在阶段三返工）

| # | 前置 | 为什么 | 负责 | 阻塞 |
|---|---|---|---|---|
| P1 | **异步底座**：Redis + queue worker + scheduler 常驻（现 `sync`、无 cron） | 试用回收/结算/通知/灰度**全依赖** | 后端 | 🔴 |
| P2 | **冻结交付术语表**（channel/release_channel/carrier_type/pricing_mode·license_mode） | 否则 37 张表各写各的词 | 架构 | 🔴 |
| P3 | **设备唯一真相源=binding**，改 v1 三接口 | 否则换机越权读付费包 | 后端 | 🔴 |
| P4 | **对象存储接入**（现技能包在本地 private 盘） | 付费资产要独立 bucket + 凭证 | 后端 | 中 |
| P5 | **机型库种子**（宇树/智元/众擎 P0，其余 L3） | 兼容性校验的输入 | 数据 | 中 |
| P6 | **外部审批**（见下） | 没有就不能卖 | 合规/运营 | 🔴 |

## 1.3 外部审批清单（阶段 0 并行，**在关键路径上**）

| 区域 | 事项 | 阻断 |
|---|---|---|
| 中国 | 微信支付商户+虚拟商品类目、**电商收付通+二级商户分账**（二清）、ICP+小程序备案、**EDI 许可**、**等保2.0**、算法/大模型/拟人化三层备案、内容标识、隐私·协议律师定稿 | 🔴 任一卡住不能开卖 |
| 美国 | 本地实体（收 Stripe）、税务 | 🔴 |
| 通用 | 用户协议、开发者协议、**操作者责任条款** | 🔴 |

## 1.4 检查结论

**"能开工"的清单**：W0（术语冻结/异步底座/设备真相源/机型数据/对象存储）→ 可开工；**外部审批必须今天启动**（月级周期，越晚越卡）。
**"不能开工就上"的**：W4 下发链路（等 Agent 侧就绪）；开发者门户/结算/看板（P1）。
**P0 对外可交付形态**：**manual 通道**（v1 已跑通的下载+人工装），不是 Agent 自动下发。

---

# 第二部分 · 用户端（买家）详细交互路径

> 每一步：**用户动作 → 系统行为（接口/表）→ 结果**。标注实现状态。

## 路径 U1 · 发现与判断技能

| 步 | 用户动作 | 系统（接口/表） | 状态 |
|---|---|---|---|
| 1 | 打开小程序 → 进技能市场 | `EcoCenter.vue` / `robot/index.vue` 技能 tab 拉 `catalogApi` skill | ✅（`EcoCenter` 已接后端；技能 tab 无搜索框） |
| 2 | 搜索/筛选（机型/动作类型/风险） | 🆕 `GET /api/skills/search` + `skill_search_index` | 🆕 |
| 3 | 点开技能详情 | 详情浮层 `robot/index.vue:280`（真实商品）；⚠ `home/jineng` 是 mock 需重接 | 🟠 |
| 4 | 看适配机型/版本历史/风险标 | 联 `device_model` + `skill_versions` | 🆕 |

## 路径 U2 · 兼容性校验 + 试用

| 步 | 用户动作 | 系统 | 状态 |
|---|---|---|---|
| 5 | 选"我要装到哪台机" | 选本人登记/绑定设备 | ✅ 设备列表 |
| 6 | 点"检查兼容" | 🆕 `GET /api/skills/{id}/compatibility?deviceId=` → `{compatible,reason,channels[]}`；**遇"未知"固件→降级提示** | 🆕 |
| 7 | （可选）点"试用" | 🆕 `POST /api/skills/{id}/trial` → 发 20min 授权（`expires_at=+20min`, `meta.trial`）→ 直接下发 | 🆕 |
| 8 | 试用倒计时 | "我的技能"展示剩余时长 | 🆕 |

## 路径 U3 · 购买（买断）

| 步 | 用户动作 | 系统 | 状态 |
|---|---|---|---|
| 9 | 点"购买" | 🆕 **分级风险告知弹窗**（按 `risk_level`），high 风险逐条勾选"空旷无人环境" → 落 `risk_acknowledgment` | 🆕（§4.14 L3） |
| 10 | 选交付通道 | 由 §4.3 校验结果给出可选通道（agent/vendor/manual ∩ 设备） | 🆕 |
| 11 | 微信支付 | 🆕 `checkout` → 微信支付单 → 回调 `/api/callback/payment` → 订单 paid | 🆕 |
| 12 | 授权自动发放 | ✅ **复用** `SkillDelivery` 监听器（幂等，`skill_entitlements` 一行） | ✅ 零改动 |

## 路径 U4 · 下发与使用

| 步 | 用户动作 | 系统 | 状态 |
|---|---|---|---|
| 13 | 绑定设备 | ✅ `PUT /api/skills/entitlements/{id}/bind` | ✅ |
| 14a | **manual 通道**：下载包 + 看图文安装 | ✅ `GET /api/skills/entitlements/{id}/assets` + `download`（鉴权流式） | ✅ |
| 14b | manual：装完自报 | ✅ `POST .../confirm-install` → `status=active`（标记 unverified） | ✅ |
| 15a | **agent 通道**：自动下发 | ⏸️ `POST /api/deploy/tasks` → `deploy_task` 状态机 → 进度页（3s 轮询） | ⏸️ 等 Agent |
| 15b | agent：看进度/结果 | ⏸️ `GET /api/deploy/tasks/{taskNo}` | ⏸️ |
| 16 | 管理：启停/更新/卸载/回退 | ⏸️ 下发指令（manual 通道下仅能"重新下载/自报"） | ⏸️/🆕 |

## 路径 U5 · 售后与信任

| 步 | 用户动作 | 系统 | 状态 |
|---|---|---|---|
| 17 | 评价/晒单 | ♻️ 后端 `ProductReview` API → 🆕 技能评价页 | ♻️+🆕 |
| 18 | 申请退款/售后 | 🆕 `POST /api/after-sale`（虚拟商品政策：试用不退、未下发可退、已激活申诉）→ 联动 revoke | 🆕 |
| 19 | 提工单/求助 | 🆕 `POST /api/tickets`（用户↔平台↔开发者） | 🆕 |
| 20 | 收通知 | 🆕 `notification` + 微信订阅消息（审核/下发/试用到期/退款） | 🆕 |
| 21 | 举报违规技能 | 🆕 `POST /api/skills/{id}/report` | 🆕 |

**用户端预期达成度**：**核心购买-下发链路 P0 可达（manual 通道）**；自动下发(agent)、管理(启停/更新) **等 Agent**。

---

# 第三部分 · 开发者端（卖家）详细交互路径

> 入口：**独立 Web 门户**（桌面优先）；小程序/App 通过 `web-view` 打开**轻量视图**（审核状态/数据/消息）。

## 路径 D1 · 入驻

| 步 | 开发者动作 | 系统 | 状态 |
|---|---|---|---|
| 1 | 从 Web（或小程序 web-view）进开发者门户 | ✅ 现状是 **Blade SSR**（`Resources/views/dev/*` + `Routes/dev.php`）；独立域名备案属 E2；小程序用**一次性 code 换 token**（不把 token 拼 URL） | ✅ 已可用 |
| 2 | 注册/登录 | 🆕 与 C 端同账号（微信扫码/手机号） | 🆕 |
| 3 | 主体认证（个人/企业） | 🆕 `developer_profile` | 🆕 |
| 4 | 签《开发者协议》+《技能安全责任承诺》 | 🆕 `developer_agreement` 电子签留档（§4.14 L1） | 🆕 |

> ⚠ **P0 现实**：开发者门户是 **P1**。**P0 阶段自营技能走 Admin**（internal.key 自助上架）；等有外部开发者意愿再开门户。

## 路径 D2 · 建技能与上架

| 步 | 开发者动作 | 系统 | 状态 |
|---|---|---|---|
| 5 | 建技能、填元数据（多语言 zh/en、图标/截图/预览） | 🆕 技能 CRUD | 🆕 |
| 6 | 上传 `.skill` 包 | ✅ `SkillAdminController` 通道 + 🆕 `manifest.yaml` schema 校验 | ✅+🆕 |
| 7 | 或**导入他平台包**（宇树 `metadata.yaml`/智元资源/BVH） | 🆕 导入器 + `skill_import` | 🆕 |
| 8 | 版本管理（历史/下架/回滚） | ✅ 表在 + 🆕 UI | ✅+🆕 |
| 9 | 定价（买断/免费） | ✅ `products.price` + 🆕 UI | ✅+🆕 |
| 10 | 提交审核 | 🆕 触发 §4.8 两阶段 | 🆕 |
| 11 | （P1）Beta 预发布测试轨 | 🆕 分发到指定设备/测试者 | 🆕 P1 |

## 路径 D3 · 审核与反馈

| 步 | 开发者动作 | 系统 | 状态 |
|---|---|---|---|
| 12 | 看审核状态 | 🆕 `audit_record` 对开发者可见 | 🆕 |
| 13 | 收到驳回理由 | 🆕 结构化 `reason_category` + 建议 | 🆕 |
| 14 | 申诉 | 🆕（对标 Apple ARB） | 🆕 |
| 15 | 通过 → 上架 | 🆕 | 🆕 |

## 路径 D4 · 运营与变现（P1）

| 步 | 开发者动作 | 系统 | 状态 |
|---|---|---|---|
| 16 | 看数据看板（下载/安装成功率/机型/错误码/评分/卸载率） | 🆕 `api_call_log` + 聚合 | 🆕 P1 |
| 17 | 应用与密钥（AppID/Secret/轮换/调用日志） | 🆕 `developer_app`/`api_credential` | 🆕 P1 |
| 18 | 收入/结算/提现 | 🆕 `settlement`/`withdrawal`（**受二清约束**：须接微信收付通） | 🆕 P1 |
| 19 | 工具链（SDK/仿真/真机调试/重定向） | 🆕 | 🆕 P1 |
| 20 | 迭代新版本 | ✅ 版本机制 + 🆕 免费更新流程 | ✅+🆕 |

**开发者端预期达成度**：**P0 只有"Admin 自助上架"**；完整门户（入驻/凭证/测试轨/看板/结算）**在 P1**。

---

# 第四部分 · 是否达到预期？——逐维度对照

| 维度 | 预期 | P0 能否达到 | 说明 |
|---|---|---|---|
| 用户能**买到并装上**技能 | 是 | ✅（manual 通道） | v1 链路 + 支付 + 售后 |
| 用户能**自动下发**到机器人 | 是 | ⏸️（等 Agent） | agent 通道独立轨 |
| 用户能**试用** | 是 | ⏸️（限在线通道） | manual 不支持，agent 就绪后可用 |
| 用户能**评价/售后/求助** | 是 | ✅ | 新建但简单 |
| 开发者能**上架**技能 | 是 | ✅（Admin） | 完整门户 P1 |
| 开发者能**导入他平台**技能 | 是 | ✅ | 导入器 v1 |
| 开发者能**测试/看数据/结算** | 是 | ⏸️ P1 | 门户整体 P1 |
| 平台能**审核/风控/熔断** | 是 | ✅ 审核 / ⏸️ 熔断(等 Agent) | |
| **全球** | 是 | ⏸️ 架构预留 | 短期中国+美国 |

## 结论

**按此计划，P0 能达到"用户买到并装到机器人 + 开发者上架技能 + 平台审核"的闭环**——但**"自动下发/试用/熔断"这条最像"技能平台"的能力，全部押在 Agent 就绪上**；**开发者完整门户在 P1**。

**换句话说**：P0 交付的是一个**"技能交易 + 人工交付"平台**，与"技能自动下发"平台之间**隔着 Agent 这一个里程碑**。这个落差**不是计划缺陷，是外部依赖**（Agent 在开发中）。Boss 需要知道：**在 Agent 就绪前，对外只能说"技能可买可下载可人工安装"，不能说"一键下发到机器人"。**
