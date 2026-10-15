# 第 9 步 · 开发者端闭环与平台化 开发计划（v2 · 2026-10-16）

> **基线**：`72b67a9`（工作区干净）。
> **依据**：两名工程师独立勘察 + 交叉验证（我 + Claude Code 只读架构评审），全部结论**回代码逐条复核**，
> 不采信文档口径，也**不采信彼此的自述**。
> Claude 评审原件：`C:\Users\Administrator\.claude\plans\d-main-my-bagisto-store-bagisto-laravel-cuddly-rivest.md`。
>
> **闭环定义（验收唯一标准）**：开发者**自助**走完
> 入驻 → 签协议 → 建技能 → **改技能** → 建版本 → 传包 → 提审 → **看到逐层结论** →
> 驳回后改完**再提审** → 上架 → **下架**，全程不需要在群里问人。

---

## 0. 一句话结论（先看这个）

开发者端真正的洞**不在"缺几个功能"**，而在**三条地基裂缝**：

1. **审核状态真相分裂** —— 同一件事存了两份（`audit_record` 权威而细、`skill_versions.status` 粗且提审时不写），下游各自解读。
2. **发布闸门不是唯一入口** —— `Admin` 的发布动作过闸门，`internal.key` 的 API 发布口**不过闸门**。
3. **编译层是桩，且不在交付链路上** —— "传一份、多机型可下"目前**没有实现**：交付走的始终是开发者上传的那个文件。

第 1 层（"缺的那些按钮"）是**装门**；这三条是**门后面有没有墙**。**先修墙，再装门。**

---

## 1. 已闭环（不重复做；但改动前必须先读这些位置）

| 能力 | 代码位置 |
|---|---|
| 独立门户（D7，不塞 Bagisto Admin） | `Routes/dev.php` + `Resources/views/dev/*`（10 视图）+ `config/developer_portal.php` |
| 开发者身份（独立 guard + 4 个中间件） | `config/auth.php` + `Http/Middleware/{AuthenticateDeveloper,RequireDeveloperLogin,ResolveDeveloper,ResolveDeveloperLogin}.php` |
| 入驻 / 协议签署（含正文 sha256 留痕） | `Services/Developer/DeveloperOnboardingService.php` |
| 建技能 / 建版本 / 传包 / 提审（L0 机审） | `Services/Developer/DeveloperSkillService.php` |
| 审核状态 + 驳回理由可见 | `Dev/SkillController::audits` + `views/dev/audits/index.blade.php` |
| 数据卡（并进总览页，口径诚实） | `views/dev/dashboard.blade.php` + `OpsMetricsService::forSeller()` |
| `/api/dev/*` 合同层（Bearer token） | `Http/Controllers/API/Dev/*`（5 个控制器） |
| 归属隔离（查询层过滤 → 一律 404） | `DeveloperSkillService::findOwned*` |
| 管理员入驻审核台（实名批准/驳回、账号停启用） | `Admin/Customers/DeveloperController.php` |

---

## 2. 深度勘察结论（本轮新发现 + 对上一版判断的修正）

### 2.1 【新 · 最硬】发布有第二条旁路，绕过发布闸门

- **证据**：`Admin\Catalog\SkillController::publish()`（`:252-260`）**过** `ReviewGate::evaluate()`；
  而 `API\SkillAdminController::publish()`（`:163-195`，路由 `shop.api.skill-admin.versions.publish`，internal.key 通道）
  只检查三件事：版本存在、非 `archived`、`assets` 非空 —— **完全没有闸门判定**。
- **后果**：一条未过审的技能，可以由 internal.key 通道**直接上架**。这与 M2「可上架 · 垃圾被拒」的判据、
  与审核承诺**直接冲突**，而且它是**静默**的。
- **处置**：收敛成一处 —— 两条路径都过闸门，发布动作复用同一实现。

### 2.2 【修正】审核状态是"两份真相"，不是"少两个枚举值"

- `submitForReview()`（`DeveloperSkillService.php:277-339`）**全程不写 status**，也**没有** `$version->status` 前置守卫
  → 已 `published` 的版本**可以重复提审**。
- `audit_record` 才是细粒度真相：`layer`(1=L0/2=L1/3=L2)、`result`(0待审/1通过/2驳回/3待补充)、
  `reason_category/detail`、`suggestion`、`auditor_id`、`submitted_at/audited_at`；且 `submitLayer0()` **过与不过都写记录**。
  `ReviewGate::hasSubmitted()/layerStates()` 本就能把"没提审 / 待审 / 驳回 / 待补充"逐层分开。
- ⚠ **上一版我判断错的地方**：不是"分不清 L2 驳回与从未提审"—— 用 `audit_record` 分得清。
  真实缺陷是**同一事实存了两份且不一致**；且 `skill_versions.status` 在 DB 层是
  `string(16) default 'draft'`、**无 CHECK 约束**（迁移 `2026_09_30_000001`）—— 写入面无约束。
- **连带黑洞**：Admin 审核台队列**只捞 `STATUS_DRAFT`**（`Admin/Catalog/SkillReviewController::index:51`）。
  于是"已发布版本被重复提审"会**追加一条 `audit_record`，却永远不出现在待审队列里** —— 谁都看不到。

### 2.3 【修正 · 低估】审核结果通知：读侧也把开发者挡住了

- 生产侧：`LifecycleNotifier` 只有 `toCustomer` 系（售后/退款/下发/试用），无 `toDeveloper`；
  `recipient_type` 注释含 `developer`（迁移 `2026_10_13_000005`）但**零生产者**。
- **读侧**：`API/NotificationController` **硬过滤 `recipient_type='customer'`**（`:21`、`:50`）。
  即：即便明天产出一条 `developer` 通知，**开发者也读不到**。
- 而且**开发者门户里根本没有收件箱页面**（`views/dev/layouts/app.blade.php` 顶栏只有 总览/我的技能/审核状态/退出）。
- ⚠ 由此引出一个必须拍板的设计问题：门户复用 customer 账号，
  那**开发者通知该落在 App 通知中心（收件人 = customer）还是门户新页（收件人 = developer）**？
  两条路成本与语义不同（见 §5-Q4）。

### 2.4 【确认】编译层是桩，而且不在交付链路上

- `MotionCarrierBuilder::build()` **恒返回 `BuildResult::pending()`**（`:42`）—— 从不产出真实产物。
- `SkillBuildService::compile()` 的**唯一入口**是 `API/SkillSupplyController::compile()`（internal.key，`:81-92`）；
  `Admin::publish()` 也**不触发编译**。
- `skill_build` 表**唯一的读点**是 `SkillSupplyController::builds()` 的回读接口（`:173`）—— **无消费者**。
- 交付实际路径是：`SkillAsset`（开发者上传的原始文件）→ 下单 → `skill_entitlement` → `API/SkillController::download()` 流式下载。
  **`skill_build` 根本不在这条链路上。**
- **结论**：「传一份中立定义 → 转各机型载体 → 多机型可下」今天是**纸面承诺**：
  平台下发的**就是开发者上传的那个文件**，只是带了 `carrier_type` 标签。

### 2.5 【确认】合规与安全层"声明"多于"强制"

- `manifest.permissions` 只在 `ManifestValidator` 做白名单校验，**运行时零消费者**（下发/安装/执行都不读）
  → D6「沙盒受控执行」目前**没有技术支点**，只有开发者承诺（D6 第一层）。
- `ManifestValidator` 把 schema 硬绑 `!== 'zhaohuan.skill/v2'`，**无版本兼容策略**。
- `security_scan` 表**零模型、零引用**（孤表）。

### 2.6 【修正范围】不能对"已建未用表"一概而论

- 确为死表：`settlement`、`withdrawal`、`reconciliation`、`developer_app`、`api_credential`、`security_scan`。
- **但**：`developer_profile`、`developer_agreement`、`developer_team_member`、`skill_review`、`skill_report`、
  `skill_blocklist`、`gray_release`、`rollback_record`、`compliance_filing`、`incident` 等**有模型且有真实调用**。

### 2.7 【新发现 2026-10-16 · 复核 D-2 时抓到】发布动作没让商品可售 —— "上架"是空动作

- 市场搜索接口 `SkillCatalogController::search`（`:163-168`）第一条约束就是
  `product_flat.status = 1` AND `visible_individually = 1`；前端 `utils/api.js:160-175` 用的正是 `/api/skill-catalog/search`。
- 而 `DeveloperSkillService::createSkill()` 建商品写 **`status => 0`**；
  发布动作 `SkillPublisher::publish()` **只改版本状态**，全仓**没有任何地方**把技能商品置为 1（已 grep 三处相关目录确认）。
- **后果**：开发者技能走完"提审 → L2 通过 → 发布"，版本是 published，**但商品仍禁用 → 市场搜不到**。
  也就是说 **⑥「上架/下架」此前既没有上、也没有下的落点**。
- **处置**（已进 D-3 任务卡）：发布动作追加"商品置为可售"（走 `ProductRepository::update()` + 事件三步，
  不直接写 `product_flat`）；下架 = 商品 `status = 0`；两者共用同一条商品更新通道。
- **验收方式的一条教训**：这类问题**字段断言证明不了** —— 必须用**接口级**断言
  （发布后 `GET /api/skill-catalog/search` 能搜到该技能、发布前搜不到）。

---

## 3. 分层路线图（判据：这层修完，上层会不会推翻它）

### 第 1 层 · 近期闭环「开发者不掉链子」

| # | 条目 | 依赖 |
|---|---|---|
| ① | **发布闸门唯一化**：`internal.key` 发布口也过 `ReviewGate`，发布动作收敛成一处 | §5-Q1（旁路是否疏漏） |
| ② | **收敛审核状态真相源**：定 `audit_record` 为唯一事实，`skill_versions.status` 降为派生 / 同步写 | §5-Q2 |
| ③ | **门户展示逐层闸门**：复用已存在的 `ReviewGate::layerStates()/evaluate()`，逐层显示 通过 / 待审 / 驳回 / 待补充 / 未接入 + 原因 | 无 |
| ④ | **技能信息可编辑**（改名 / 改价 / 改简介）+ 改价留痕（遵 D3 `pricing_mode='buyout'`） | 无 |
| ⑤ | **提审前置收紧**：仅 `draft` / `rejected` 可提，堵住"已发布可重复提审" | ② |
| ⑥ | **撤回提审**（`in_review → draft`）+ **开发者下架**（写理由留痕） | §5-Q3（下架语义） |
| ⑦ | **审核结果通知开发者**（生产 + 读侧同开） | §5-Q4（收件箱落点） |

### 第 2 层 · 平台化「结构正确，不挡住未来」

| # | 条目 | 为什么现在做 |
|---|---|---|
| ⑧ | **schema 版本策略**：引入版本解析 / 兼容分支，替代 `!==` 硬比 | 现在零真实外部上传者，是唯一能"零痛"引入兼容层的窗口；等有了存量包再改 = 全废 |
| ⑨ | **包完整性**：上传即解包、固定 neutral manifest 抽取时机、`file_hash` 与审核快照绑定 | 编译输入若不稳定，任何"编译"都无从谈起 |
| ⑩ | **permissions 进运行时**：至少在下发前校验"声明权限 ⊆ 设备能力"，越界拒绝并留痕 | D6 责任模型需要技术支点；后补要改下发协议 |
| ⑪ | **编译层定性**：**如实降级**（见 §4）—— 不做半吊子 | 含糊着最坏：表在、注册表在、产物永远 `null` |

### 第 3 层 · 远期生态「第三方开发者」——**当前明确不做**

供给方为 0，以下属 `CLAUDE.md` 红线「不要为不存在的供给方设计功能」：

- ❌ 团队 RBAC ｜ ❌ Beta 测试轨 ｜ ❌ 开发者申诉入口 ｜ ❌ SDK·CLI
- ❌ 凭证密钥 `api_credential` / `developer_app`（已裁决永久不做）
- ❌ 财务结算 / 提现 / 对账（卡外部前提，见 §4）
- ❌ 邀请制 / 白名单 / 实名门槛（**经营决策**，非工程）
- ❌ 独立 Vue3 SPA / 独立备案域名（现有 Blade SSR 已可交付；域名属 E2）

---

## 4. 前置声明（**先说做不到的，避免最后一步才翻车**）

> 项目铁律：不许把"看代码觉得对"当证据；不许把没跑通的步骤标 ✅。
> 下列条目**在外部前提到位前无法验证、甚至可能最终不可实现**，因此**不进近期排期**。

| 条目 | 卡在哪 | 什么时候才可能做 |
|---|---|---|
| **编译层"真做"**（中立定义 → 各机型载体自动产出） | 产物格式由**厂商规范**决定（宇树 docker 镜像布局 / 智元资源包 / 众擎 rsync 目录），平台**没有权威来源**；真机不存在（E7），产物**无法验证** | 拿到厂商载体规范 + 真机（E7）之后 |
| **permissions 的端侧强制** | 设备端 Agent 不存在 → 只能做"平台侧下发前拦截"，**端侧执行强制无法验证** | E7 之后 |
| **财务结算 / 提现 / 对账** | 无已收款事实源（订单全 COD）、无分账主体、无佣金费率、"可结算"口径未定义；`settlement.seller_id` 还是 NOT NULL（连自营都装不下） | 真支付资质（E1）+ 分账方案确定后 |
| **微信支付 / msgSecCheck / 小程序 `web-view` 业务域名** | 外部资质（E1 / E2） | 资质到位后 |

**本轮对 ⑪ 编译层的建议：如实降级，不装样子。**
即：明确把「多机型载体当前由**人工产出 / 登记**」写进对外口径与界面文案，
把 `skill_build` 的定位改成"登记表"而不是"编译产物表"；**自动编译**等 E7 之后单独立项。
理由：现在做，做出来的是**无法验证的空壳**；而"传一次多机型可下"若继续含糊，
等于平台宣称一件它做不到的事 —— 这是本项目最在意的一类问题。
（若你判断该技术卖点必须现在就立住，则本条转为"真做"，但需要先拿到厂商载体规范 —— 见 §5-Q5。）

---

## 5. 需要你拍板的问题（工程无法替你决定）

**Q1 · 发布旁路（§2.1）是疏漏还是有意？**
`internal.key` 的 API 发布口不过闸门，是否是留给平台自营技能的"快速通道"？
若是**有意**，需要在计划里显式记录该例外并加白名单 / 留痕；若是**疏漏**，本轮立刻收敛成一处。
（我倾向：疏漏，且必须消灭 —— 因为 Admin 连自营都过闸门。）

**Q2 · 审核状态的真相源定谁？**
建议：`audit_record` 为唯一事实，`skill_versions.status` 降为派生缓存（或提审 / 判决时同步写）。
认不认这个方向？它决定第 1 层能否"一处收口"，否则每条改动都要各修一份。

**Q3 · 开发者下架语义？** 下架**是否回收已售授权**？
我的建议：**不回收** —— 已买断的设备继续可用，只停新增销售；
对高危技能走 D9 熔断（那是另一条路径，有留痕与责任追溯）。

**Q4 · 开发者通知落在哪？** 门户复用 customer 账号，两条路：
(a) 写 `recipient_type='customer'` → 出现在 App 通知中心，成本低但开发者得去 App 看；
(b) 门户新增收件箱 + 读侧支持 `developer`，成本中，但符合"开发者中心"的心智。
我倾向 (b) + (a) 双写，但 (b) 需要你确认是否要独立收件箱。

**Q5 · 编译层：如实降级，还是现在真做？** 见 §4。
我建议**降级 + 明确口径**；若要求现在真做，请先提供**厂商载体规范**，否则做不出且会前功尽弃。

**Q6 · 开发者准入：开放注册还是邀请制？**
现状是任何 C 端买家都能自助入驻，`realname_status` **不拦提审** → 未实名主体可产生真实交易。
这是**有意接受**，还是**待改**？

**Q7 · 死表口径统一？**
`api_credential` 已裁决"永久不做"，但 `developer_app`、`security_scan` 以及
`settlement` / `withdrawal` / `reconciliation` 是否一并写进 `CLAUDE.md` 级红线，避免后来者再翻出来重做？

---

## 6. 执行方式（控制 Claude Code，逐卡推进）

1. **备份**：改动前备份到 `.claude/backup/step9-<卡号>/`
2. **任务卡拆分**：一卡 = 一个可独立验收的最小闭环，**不并行改同一文件**
3. **驱动**：`claude -p <任务卡> --append-system-prompt-file CLAUDE.md --permission-mode acceptEdits`
   - 只读评审用 `--permission-mode plan`
   - **每完成一卡压缩上下文 / 开新会话**（省 token，也避免错误前提被继承）
4. **验收（由我执行，不采信自述）**：
   - `php artisan test --filter=Dev`（现有 4 个 Dev 测试 + 本卡新增）
   - `php -l` / `php artisan route:list --path=dev`
   - **行为级证据**（tinker 探针或 H5 截图）—— 至少一处
5. **留档**：`docs/skill-dev-step9-<卡号>.md`（改动清单 / 实测证据 / 回滚命令 / **未做部分**）
6. **提交**：一卡一 commit。⚠ 动 git 前先处理 `my-bagisto-store2 备份/` 这条红线
   （`git add -A` 会把它认成 2739 个删除）

**偏差处置（硬规则）**：任何一卡开工前若发现"前提与代码不符"，**立即停下**，
不再往上叠补丁，回写本计划并重新对齐后再继续。

### 建议的下轮批次（按风险 / 成本比排序）

- **批次 A（不需拍板，立刻可做）**：① 发布闸门唯一化 → ③ 门户闸门展示 → ④ 技能编辑 → ⑧ schema 版本策略
- **批次 B（等你拍板后）**：② 状态源收敛 → ⑤ 提审前置 → ⑥ 撤回 / 下架 → ⑦ 通知
- **批次 C（取决于 Q5）**：⑪ 编译层定性 → ⑨ 包完整性 → ⑩ permissions 运行时

---

## 7. 总验收判据（端到端一条链，缺一环不算闭环）

入驻 → 签协议 → 建技能 → **改技能** → 建版本 → 传包 → 提审 →
**门户看到逐层闸门状态** → L2 驳回 → **收到开发者通知** → 改包**再提审** →
L2 通过 → **两条发布路径都过闸门** → 上架 → 客户端可见 / 可买 →
**下架**（语义见 Q3） → 已授权设备不被回收 → **未过审技能无法通过任何路径上架**

---

## 8. 负责人裁决（2026-10-16 · 不再回抛给需求方）

> 依据：本轮代码实测 + Claude Code 只读架构评审交叉验证 + Claude Code **历史会话检索**
> （`~/.claude/projects/d--main/*.jsonl`，约 50MB，逐条查证"是否属预留"）。
> **检索结论**：`api.php` 的 team/apps/analytics/beta 留位、L1 仿真层未接入、`device_pull` / `device_push` 通道留位、
> 智元导入 P1、`config/skill_builders.php` 的 `AppCarrierBuilder` 留位、§4.20 转换层"未加固未验证" —— **都是显式预留，不是缺陷，不重做**。
> 而**发布旁路不是预留**：它随 `aff6083`（基线批量入库，294 文件）带进来，无注释、无细粒度提交说明其有意。

| # | 裁决 | 理由（一句话） |
|---|---|---|
| Q1 | **发布旁路 = 疏漏，已修** | 无任何证据支持"有意"；且 Admin 控制器的类注释自称"两者…不存在两套真相" —— 断言与代码不符，属疏漏 |
| Q2 | **`audit_record` = 审核判决唯一事实；`skill_versions.status` = 版本生命周期** | 两者**正交**而非互为副本：一个版本可以"在审"且"被驳回"。状态只允许由**统一入口**写 |
| Q3 | **下架不回收已售授权** | 已买断的设备继续可用，只停新增销售；高危走 D9 熔断（独立路径、有留痕与责任追溯）。回收已售授权会把"买断制"（D3）变成租 |
| Q4 | **通知写 `developer` 收件人 + 门户新增收件箱；不与 customer 双写** | 审核结果是**开发者身份**的事，落开发者中心；App 通知中心继续只服务买家语义。双写会造成"已读"状态分裂 |
| Q5 | **编译层如实降级**（详见 §4） | 产物格式由厂商规范决定、平台无权威来源、真机不存在（E7）→ 现在做只能做出**无法验证的空壳**；而含糊着等于平台宣称一件做不到的事 |
| Q6 | **准入保持开放注册，但"实名未通过不得上架"** | 提审不拦（鼓励供给），**发布前**必须 `realname_status='approved'`；平台自营 `seller_id` 为 NULL，不受限。未实名主体不应产生真实交易 |
| Q7 | **死表裁决记录在本计划，暂不并入 `CLAUDE.md`** | `api_credential`/`developer_app`（无门可开的锁）、`security_scan`（零消费者）、`settlement`/`withdrawal`/`reconciliation`（卡外部前提）。CLAUDE.md 是宪法级文档，并入与否留给需求方一句话 |

### 本轮进度（2026-10-16）

| 项 | 状态 | 证据 |
|---|---|---|
| ① 发布闸门唯一化 | ✅ **完成** | 新增 `Services/SkillAudit/SkillPublisher`（发布动作唯一入口）；`Admin\Catalog\SkillController::publish` 与 `API\SkillAdminController::publish` 均改为调用它；`ApiErrorCode` 增 `GATE_NOT_PASSED`。新增 `SkillPublishGateTest` **7 例通过**；`SkillReviewGateTest`(20) + `SkillAdminAssetTest`(10) 回归**全绿** |
| ③ 门户展示逐层闸门 | ✅ **完成** | `Dev\SkillController::show` 注入 `ReviewGate` 并传 `evaluate()` 结果（**门户不重算判定**）；`views/dev/skills/show.blade.php` 增"发布闸门"卡。新增 `DeveloperGateVisibilityTest` **3 例通过**；`Feature/Dev` 全目录 **29 例通过** |
| ④ 技能信息可编辑 | ✅ **完成** | Claude Code 执行（任务卡 D-1）+ 我验收：`Feature/Dev` **34 例通过**（29 旧 + 5 新）；Admin Catalog **38 例通过**。边界覆盖：跨租户改他人技能 404、未登录跳登录、负价被拦、请求体夹带 `status` 被丢弃、`product_flat` 同步刷新（证明事件三步真的派发了） |
| ② 状态真相源收敛 + ⑤ 提审前置收紧 | ✅ **完成** | `5c96f8f`。新增 `SkillVersionState`（显式迁移白名单，无 `draft→published`）；状态写入**下沉到 `AuditService`**（门户 / internal.key / 运营后台三条通道的唯一漏斗，故平台自营路径也自动落 `in_review`，队列口径自然统一）；审核台队列由 `draft` 改为 `in_review|rejected`；新增 `skill:backfill-version-status` 回填历史。实测 `SkillAudit`+`Dev` 79 例全绿，失败集与基线完全一致 |
| ⑥ 撤回提审 + 开发者下架 | ✅ **完成** | `00f2f7c`。**含一处空动作修复**：见 §2.7 —— "发布"原先不改商品可售性，市场根本搜不到；现由 `SkillPublisher` 在同事务内置商品可售（走事件三步 + 显式带分类）。下架 = 商品置不可售、理由必填留痕、**已售授权不动**（Q3）。接口级验收：发布前搜不到 / 发布后能搜到 |
| ⑦ 开发者通知 | ✅ **完成** | `a563ef2`。生产端挂 `AuditService::submitLayer0/recordLayer2` 与 `SkillPublisher::publish`（事务提交后），全包 `safe()`；读侧门户收件箱 + 未读角标，可见范围收敛成 `Notification::scopeDeveloperInbox()` 一处。实测 Dev 48 + SkillAudit 49 = 97 例全绿 |
| 实名发布前置（Q6） | ✅ **完成** | `a104fbd`。`SkillPublisher::ownerQualification()` 置于**闸门之前**（"谁在卖"比"东西过没过审"更靠前）；自营 `seller_id` NULL 不受限；`seller_id` 有值查不到主体 → **fail-closed**；协议未签全 / 实名未过各自独立错误码。连带修：API 侧 `default` 兜底会把新码误标成 `GATE_NOT_PASSED`（已逐个显式映射）；开发者总览页那句"实名不影响你建技能与提审"落地后已成假话（已改）。实测 SkillAudit 55 + Dev 48 = 103 例全绿 |
| 待清理（两行） | ✅ 完成 | `99e14f1` 顺带修掉（`onboarding` 文案 + `DeveloperProfile` 类注释；后者还牵出一句同样已腐坏的"唯一闸门是协议已签"） |

### 第 2 层 · 平台化

| 项 | 状态 | 证据 |
|---|---|---|
| ⑧ schema 版本策略 | ✅ **完成** | `99e14f1`。`ManifestValidator` 由"一句话拒绝"改为四类可区分提示（缺失 / 支持 / **太新** / **太旧已停用** / 命名空间不认识）—— 关键在让开发者分得清"太新"与"写错了"，否则只能靠猜。**顺带补了一个真缺口**：`skill_versions.manifest_schema` 此前只有 Admin 导入路径写，开发者通道恒为 NULL，而 `IncidentService` 把它当事故证据快照读 → 开发者技能出事故时证据链最该有的那条是空的；落库点选在 `AuditService::submitLayer0`（三条通道唯一漏斗）。实测 SkillAudit 65 + Dev 48 = 113 例全绿 |
| ⑨ 包完整性 / manifest 抽取时机 | ⏸ 下一卡 | 目标：同一版本重复上传后，审核快照与落盘文件仍一致（`file_hash` 与审核绑定） |
| ⑩ permissions 进运行时 | ⏸ | 目标：声明权限 ⊄ 设备能力 → 下发前拒绝并留痕（D6 责任模型的技术支点） |
| ⑪ 编译层口径降级 | ⏸ | 目标：界面/口径如实说"载体由人工产出/登记"，不再假装能自动编译 |

> **第 1 层（近期闭环）①–⑦ 已全部完成。** 端到端验收链已打通：
> 入驻 → 签协议 → 建技能 → 改技能 → 建版本 → 传包 → 提审 → **门户看到逐层闸门** → 驳回 → **收到通知** →
> 改完再提 → 通过 → **实名合格才可发布** → **市场搜得到** → 下架（已售授权不动）。

### 已验证的"零新增失败"口径（可复用的验收方法）

两轮 D-2 / D-3 都把四条 pest 的**失败类名**逐条与 `docs/skill-test-baseline.md` 比对，而不是只看总数：
`Admin/Feature/Catalog` 恒定失败 4 个原生类（Attribute / AttributeFamily / Category / Products\ProductTest），
`Shop/Feature/API` 恒定失败 `CategoryProductTest` 2 条。**不在清单上的才叫新问题。**

---

## 9. 颗粒度对齐与卡片拆解（2026-10-16 晚）

### 对齐规则（一卡的边界怎么定）

**一卡 = 一条能端到端验收的行为链**，不是"一块代码"。

- ❌ 反面：只加通知生产者、不加读侧 —— 项目自己踩过：`notification` 库读写端都在，但零生产者 → 页面永远空白，
  等于"功能看着有、实际没有"（见 `LifecycleNotifier` 类注释）。只做一半**不算完成**。
- ⚠ 已发生的偏差：D-2 / D-3 各含 5 件事、涉及 13-14 个文件，**偏粗**——评审成本高、一次翻车面大。
  后续按上面这条规则收细。
- 一条推论：**验收必须用接口级断言**，不是字段断言。"版本状态是 published"证明不了"用户能看见"——
  发布是空动作那件事（§2.7）就是字段断言永远发现不了的。

### 剩余卡片（按此颗粒度拆）

| 卡 | 内容 | 验收判据（端到端） |
|---|---|---|
| D-4 | 开发者通知：生产端 + 门户收件箱 + 已读 | 运营驳回后，**开发者在门户看得到那条通知且带驳回理由**；买家类通知不混入；自营不发 |
| D-5 | 实名发布前置（Q6） | 未实名主体的技能发布被拒；自营（`seller_id` NULL）不受限 |
| D-6 | schema 版本策略（⑧） | v2 包仍收；未知版本给出**明确拒绝**而非静默失败 |
| D-7 | 包完整性 / manifest 抽取时机（⑨） | 同一版本重复上传后，审核快照与落盘文件仍一致 |
| D-8 | permissions 进运行时（⑩） | 声明权限 ⊄ 设备能力 → 下发前拒绝并留痕 |
| D-9 | 编译层口径降级（⑪） | 界面/口径如实说"载体由人工产出/登记"，不再假装能自动编译 |

### 一处裁决细化（Q4 改判，记录理由）

原裁决：通知写 `recipient_type='developer'` + 门户新增收件箱，不与 customer 双写。
**改判为：写 `recipient_type='customer'` + 开发者本人的 `customer_id`；门户收件箱按 `type` 前缀 `skill.` 过滤。**

理由：开发者**就是**那个客户（同一账号，门户登录复用 customer 身份），同一个人该在两处都看得到；
读侧（`API/NotificationController` 硬过滤 `customer`）**一行都不用改**，风险最小；
按 `type` 前缀过滤即可保证买家事务不混进开发者中心。原方案的"独占 developer 类型"没有带来实际收益，
却要多开一条读路径。

---

## 10. 供需深度优化（2026-10-16 · 依据 A-1 审计）

> 审计报告：`C:\Users\Administrator\.claude\plans\a-1-iterative-whale.md`（26KB，只读审计）。
> 最硬的几条已由我逐行复核（`DeployController:82-90` 不传 packageUrl/Hash、`SigningService:68` 用 `?? ''`、
> 开发者通道 manifest 字段落 NULL、MQTT 零代码、CLAUDE.md 与 bootstrap/app.php 的 scheduler 口径冲突）。

### 排序理由（为什么不是先做"包分发"）

审计把"包分发端点"列为最高杠杆，我同意它**是**最高杠杆，但排在第二张卡：
分发要用 `package_hash`，而该哈希**必须取自一个可交付、且已验证存在的产物**。
顺序反了，就会把"根本给不出去的包"签进签名里 —— 签名覆盖了一个不可交付的东西，比不签更坏。

### 卡片

| 卡 | 内容 | 状态 |
|---|---|---|
| S-2 | **供给侧字段与闸门的正确性**：开发者 manifest 的 `delivery_channels`/`risk_level`/`pricing_mode` 落库（原先恒 NULL → 试用恒被拒、兼容性恒降级人工、风险告知"未分级"）；发布前置补"可交付性"（文件类交付物必须真的在盘上，否则用户付钱后下载才暴露） | ✅ `1893fb3` |
| S-1 | **面向 Agent 的签名限时下载 + 真实 `package_hash`**：让 `deploy_task` 不再是空的，Agent 通道不再"按构造干跑"；签名覆盖真实哈希，防"审核后换包"在平台侧成立（端侧复算待 E7） | ⏸ 下一卡（最高杠杆） |
| S-3 | **上架交接**：开发者链封顶在 `in_review` —— L2 通过不等于上架，而门户没有入口也没有信号，技能可能无限期停在半路 | ⏸ |
| S-4 | 常量收口：`license_mode`/`pricing_mode`/`channel` 字面量散落多个控制器，与"单一真相"纪律相悖（`SkillAsset::CARRIER_TYPES` 是既有正面样板） | ⏸ 卫生项，不急 |

### 两条要顺手校准的"口径腐坏"（审计抓到，我复核成立）

1. **`CLAUDE.md:79` 写着"没有常驻 worker、没有 scheduler"**，而 `bootstrap/app.php:53` 已注册 6 条定时任务
   （每天 09:10/09:15、每小时 25/40 分、每分钟）。准确说法是"**scheduler 代码有了、常驻进程没起**"。
   现在这句话会让任何接手的 AI 得出"这里不存在定时任务"的**错误结论** —— 包括我自己。
2. **`manifest.yaml` 在本机根本解析不了**（`symfony/yaml` 未安装，`ManifestParser` 回落报错），
   而文档钦定的中立清单格式写的是 `.yaml`。实际只有 `manifest.json` 可用。

### 分工与验收方式（2026-10-16 起）

- **Claude Code = 执行**（`claude -p` headless，任务卡式；`--permission-mode acceptEdits`，
  显式禁 `Bash(git *)`，并禁改 `.md`）。
- **Codex = 决策 + 验收**：不采信 Claude 的自述，逐条回代码复核 + 自跑测试。
- **上下文纪律**：一卡一会话，卡与卡之间不继承上下文 —— 既省 token，也避免上一卡的错误前提被带进下一卡。
  任务卡必须自带背景与证据（新会话不熟项目），这是卡的首要成本，不是浪费。

**本轮遗留的两个小裁决（我已拍板，记录备查）**
1. **改技能名不重算 `url_key`**：改名是"把填错的字改对"，不该顺带给已发布商品换 URL、断掉外部链接。缺省即保留原值。
2. **留痕范围 = 每次编辑都写一条**（不只价格变化时）：操作留痕宁可多不可少，且实现更简单、不会漏。
