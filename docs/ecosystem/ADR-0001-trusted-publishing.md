# ADR-0001 可信发布（Trusted Publishing）

| 项 | 内容 |
|---|---|
| ADR 编号 | ADR-0001 |
| 标题 | 生态赛事活动与动态资讯的可信发布基础 |
| 状态 | **业务口径已冻结（2026-10-19）**；评审签署栏待填；阻塞项见 §9 |
| 适用阶段 | G1（G1-01 至 G1-11） |
| 适用分支 | `codex/ecosystem-g1-trusted-publishing` |
| 关联工作包 | G1-01（本文档）、G1-02 至 G1-11、G2-01、G2-12 |
| 上游文档 | [Ecosystem-Events-Content-Plan.md](./Ecosystem-Events-Content-Plan.md)、[Ecosystem-Engineering-Backlog.md](./Ecosystem-Engineering-Backlog.md)、[Ecosystem-Current-Audit.md](./Ecosystem-Current-Audit.md)、[Execution-Roadmap.md](./Execution-Roadmap.md) |
| 变更方式 | 已冻结口径的修改必须先报批并新增 ADR 或修订记录；不得在迁移、默认值或前端里悄悄改语义 |

> 本文只冻结业务与数据语义，不包含迁移代码、模型代码、接口实现。本文之外、又未列入 §9 的口径，不得被当作“以后再说”的默认值使用。

---

## 0. 结论摘要

1. **赛事来源与平台角色分离**：`source_type` 只取 `platform` / `external`；`platform_role` 只取 `organizer` / `co_organizer` / `authorized_operator` / `directory_only`。外部活动只做可信目录和官方外链，**永不产生站内报名成功**。
2. **商品 seller、活动主办方、平台角色三者互不推定**。商品归属、活动主办权、运营授权分别记录、分别核验。
3. **首期活动类型** `kind` 只取 `competition` / `salon` / `workshop` / `expo` / `open_day`。普通活动与机器人赛事共用 `events` 主表；赛务、队伍、成绩表留 G3。
4. **发布、举办、报名三轴分离**。公开可见性 = `publish_status=published` 且 `disposition=normal` 且存在 `published_revision_id`；举办状态不决定公开可见；报名可用性由服务端计算，不落前端状态。
5. **公开只读已发布 revision**。`working_revision` 与 `published_revision` 双指针；草稿和待审 ID 不可枚举；提交或批准后的 revision 不可变。
6. **时间统一 UTC 存储、IANA 时区、API 返回 ISO 8601**；容量 `null` / `>0` / `0` 三态语义冻结；首期每个活动一个 `event_registration_scope`，竞赛按队、其余按人，不混用。
7. **资讯是独立内容域**，`content_posts` / `content_revisions` 与 CMS 协议静态页彻底分开；来源、权利依据、修订、撤稿、slug 冲突策略冻结。
8. **outbox 与业务写同事务**；G1 只建立基础记录与消费接口，G2 再扩展报名通知扇出。
9. **公开 DTO 白名单**：不返回联系人、内部审核备注、私有证据、附件存储 key、工作 revision、审核人身份。
10. **G1 不开放站内报名、不开放 PII、不改 root 主库样例、不删除旧字段**。`events` 保留原 ID，新增字段只做向后兼容扩展。

---

## 1. 上下文

### 1.1 当前事实（2026-10-19）

- G0 已收口：旧站内报名默认停写；Admin ACL、PII/导出隔离、编辑回填完成。
- `ecosystem.events.public_enabled` 仍为 `false`：`GET /api/events` 返回 `{"data":[]}`，`GET /api/events/{id}` 返回 404 `EVENT_NOT_FOUND`，两者都不查库。
- `ecosystem.content.public_enabled` 为 `false`；独立资讯域尚未接入。
- root 库 `events` 有 8 条 Seeder 样例，`event_registrations` 有 1 条 RegTest 报名。二者都不是运营真实数据，**不得作为 G1 验收依据**。
- 现有 `events` 只有 `name/type/status/date/city/location/organizer/hot/teams/prize/schedule/history/detail/icon/bg_color/sort` 等展示字段；没有来源、修订、发布、举办状态或时区语义。
- `event_registrations.event_id` 是 `INT UNSIGNED`，`events.id` 是 `BIGINT UNSIGNED`，类型不一致；G1 不修，留 G2-02。

### 1.2 为什么需要本 ADR

G1 要新增的字段、枚举和状态机一旦被实现代码或迁移默认值悄悄决定，后续很难纠正：

- 把“外部活动”与“平台自办”混成一个布尔值，会让外部活动产生站内报名成功；
- 把发布状态、举办状态、报名可用性合成一个 `status`，取消活动会被误当成撤稿；
- 把 CMS 静态页当资讯，会绕过来源、修订和撤稿；
- 把 `capacity=0` 当不限，会制造超额报名；
- 把草稿 ID 与已发布 ID 用同一响应区分，会泄露未公开内容。

本 ADR 把这些语义在写第一行迁移之前冻结。

### 1.3 范围与非目标

**本文冻结**：赛事来源、平台角色、活动类型、三轴状态、时间与容量语义、主办方核验、内容域、outbox、公开 DTO 与错误码、权限与保留、阻塞项、G1-02 入口。

**本文不做**：

- 不做站内报名、候补、支付、票务、签到、通知扇出（G2 及以后）；
- 不做队伍、赛项、作品、赛程、成绩、申诉、奖项（G3）；
- 不替换或不改造 CMS 协议静态页；
- 不做前端页面、公开开关打开、数据迁移或 seed；
- 不接入或篡改任何厂商云。

---

## 2. 术语与命名纪律

### 2.1 冻结术语

| 术语 | 本文含义 | 禁止混用 |
|---|---|---|
| `source_type`（活动） | 活动与平台的关系：`platform` / `external` | 不得与内容域的 `source_type` 共用枚举校验 |
| `platform_role` | 平台在该活动中的角色：`organizer` / `co_organizer` / `authorized_operator` / `directory_only` | 不得用商品 seller、机构账号、来源核验结果推定 |
| `kind` | 活动类型：`competition` / `salon` / `workshop` / `expo` / `open_day` | 不得从旧 `type` 中文标签反推 |
| `publish_status` | 发布工作流：`draft` / `in_review` / `approved` / `scheduled` / `published` | 不包含举办状态和处置状态 |
| `disposition` | 处置状态：`normal` / `withheld` / `retracted` | `retracted` 为终态；不得用删除记录代替 |
| `hosting_status` | 举办状态：`scheduled` / `postponed` / `rescheduled` / `completed` / `canceled` | 不决定公开可见性 |
| `published_revision` | 当前公开的、已审且不可变的版本 | 公开读取不得回落到工作版本 |
| `working_revision` | 编辑中的版本 | 不可被公开列表或详情枚举 |
| `event_registration_scope` | 一个活动的报名范围与容量单位 | 首期每活动一个；不得人/队混算 |
| `ecosystem_organizers` | 主办/承办主体及核验记录 | 来源核验不等于机构账号认证或转载授权 |
| `content_posts` / `content_revisions` | 独立资讯对象与不可变版本 | 不等于 CMS 协议静态页 |
| 内容 `source_type` | `original` / `authorized_repost` / `link_summary` | 不得与活动 `source_type` 共用枚举 |
| `ecosystem_outbox` | 与业务写同事务的记录与消费基础 | 不是通用消息队列的替代品，不承载 PII |

### 2.2 域内同名不同义

`source_type` 在两个域内同名但枚举不同，必须分别校验：

| 域 | 字段 | 枚举 |
|---|---|---|
| 活动 | `events.source_type` / `event_revisions.source_type` | `platform`、`external` |
| 内容 | `content_revisions.source_type` | `original`、`authorized_repost`、`link_summary` |

任何共享校验器、共享常量或共享前端下拉，如果同时接受两套值，**视为缺陷**。

### 2.3 旧字段口径

- `events.status`（`registering/ongoing/upcoming/ended`）：**保留但不再作为发布、举办或报名可用性的真相源**。G1 之后只用于旧管理页兼容显示和迁移核对，不得据此打开公开读取或报名。
- `events.date`（自由文本）：**保留但不解析、不展示为已核验时间**。结构化时间以 `starts_at` / `ends_at` / `timezone` 为准。
- `events.type`（中文标签）：**保留为展示标签**，行为判断只认 `kind`。
- `events.organizer`（自由文本）：**保留为历史展示值**，主办权只认 `organizer_id` + revision 快照。
- `events.hot` / `events.teams`：样例来源的展示值，**公开 DTO 不返回**，不得作为热度或队伍数指标。

---

## 3. 决策

> 以下 G1-D01 至 G1-D09 均为 **2026-10-19 冻结**。需要业务后续确认的部分统一放 §9，不在正文里假设。

### G1-D01 赛事来源与平台角色

**冻结口径**

- `source_type`：
  - `platform`：平台自办，或平台持有明确书面授权/联合承办协议，可对活动事实、报名和现场组织负责。后续可按 G2 验收逐活动开放站内报名。
  - `external`：第三方活动。平台只做可信目录和官方外链，**永不产生站内报名成功**，也不承诺席位、审核结果或退款。
- `platform_role`：
  - `organizer`：平台是主办方；
  - `co_organizer`：平台是联合主办方，需书面协议；
  - `authorized_operator`：平台受主办方书面委托承办/运营；
  - `directory_only`：平台仅目录展示，不承担运营责任。
- 组合约束（应用层强制）：
  - `source_type=external` ⇒ `platform_role=directory_only`；
  - `source_type=platform` ⇒ `platform_role ∈ {organizer, co_organizer, authorized_operator}`；
  - `platform` + `directory_only` 为非法组合。
- 外部活动必须填写**已审核的官方外链**才可发布；站内只显示“前往官方报名”，点击离开平台，结果由外部主办方处理。
- 公开页面必须同时显示主办方主体名称与平台角色，不得只显示“合作赛事”这类模糊措辞。

**三方不推定**

| 关系 | 可否互相推定 | 说明 |
|---|---|---|
| 商品 `seller` → 活动主办方 | 否 | 卖机器人/技能不代表主办或承办活动 |
| 活动主办方 → 商品 seller | 否 | 主办活动不自动获得商品、店铺或技能授权 |
| 平台角色 → 商品/技能权利 | 否 | `authorized_operator` 只覆盖活动运营，不覆盖商品或技能分发 |
| 来源核验 → 机构账号认证 | 否 | 见到官网来源不等于该机构在平台开通官方账号 |
| 来源核验 → 全文/图片转载授权 | 否 | 目录外链、摘要、转载分别需要不同权利依据 |

**实现约束**

- 活动编辑权限不得复用商品 seller 权限。
- `events.organizer_id` 指向 `ecosystem_organizers`，不得指向 `customers` 或 `sellers`。
- 外部活动发布前必须通过来源与主办方核验；核验未通过时只能停留在 `draft`。

### G1-D02 首期活动类型与共用主表

**冻结口径**

- `kind` 只取：
  - `competition`（机器人赛事/竞赛）
  - `salon`（沙龙）
  - `workshop`（工作坊）
  - `expo`（展会）
  - `open_day`（开放日）
- 所有类型共用 `events` 主表。普通活动与机器人赛事共享发布、举办、来源和容量语义。
- 赛务专用表（`competition_tracks`、`teams`、`entries`、`submissions`、`stages`、`matches`、`score_revisions`、`appeals`、`awards`）**留 G3**，G1 不建占位表。
- 内容域单独建模（`content_posts` / `content_revisions`），**不把 CMS 静态页当资讯**，也不把资讯正文塞进 `events.detail`。
- **不得用商品订单表冒充报名**。`event_registrations` 是活动报名记录，不是订单；G1 不新增报名表，也不把订单状态映射成报名状态。
- `kind` 在首次发布后不可变。改变活动类型等于改变容量单位和运营责任，应新建活动并说明关系。

### G1-D03 发布、举办、报名三轴分离

#### 3.3.1 发布轴 `publish_status`

```
draft -> in_review -> approved -> scheduled -> published
                         |            |
                         |            +-> published（立即发布）
                         +-> draft（审核拒绝）
```

- `draft → in_review`：编辑提交指定 revision；提交后该 revision 不可变。
- `in_review → approved`：审核通过，审批绑定该 revision 的内容指纹与媒体集合。
- `in_review → draft`：审核拒绝，必须填写内部原因；拒绝不重新打开原 revision，编辑创建新 revision。
- `approved → scheduled`：计划发布时间在未来；计划只绑定已批准的 revision。
- `approved → published`：立即发布。
- `scheduled → published`：发布 worker 必须重新核验审批版本、内容指纹、处置状态、来源权利、媒体可用性和发布权限；任一不符则中止并告警。
- `scheduled → approved`：取消排期，回到已批准但未发布。
- 已发布对象再次编辑：创建新的 `working_revision`，**公开页继续读旧 `published_revision`**；新 revision 提交/批准前不得替换公开内容。
- 已批准但未发布的对象若创建新 working revision，原批准失效，`publish_status` 回到 `draft`，原排期任务必须重检并中止。
- 发布是**切换指针**：在同一活动/内容锁内同时更新 `published_revision_id`、公开快照字段和 `published_at`；不复制一份“公开表”。

#### 3.3.2 处置轴 `disposition`

| 值 | 含义 | 公开读取 | 可恢复 |
|---|---|---|---|
| `normal` | 正常 | 按发布轴判断 | — |
| `withheld` | 暂缓公开（待复核） | 不可见；返回与不存在相同的 404 | 可，复核通过后恢复为 `normal`，但必须重检发布版本与外部条件 |
| `retracted` | 撤稿/撤回，终态 | 不可见；返回 410 中性说明 | **不可** |

- `retracted` 是终态：不得通过改 ID、换 slug、恢复旧 revision、重跑定时任务或普通编辑重新公开。
- 撤稿后需要发布纠正内容时，必须新建独立对象、独立核实、独立审核；原对象保持终态，可在新对象中关联中性说明。
- 撤稿不删除数据库记录。公开路径、旧 slug、旧 revision、缓存、分享摘要和搜索元数据都必须停止输出旧正文。
- `withheld` 不得被展示成“已删除”；恢复必须经过复核，不能由定时任务自动恢复。

#### 3.3.3 举办轴 `hosting_status`

| 值 | 含义 | 终态 |
|---|---|---|
| `scheduled` | 已排期，按已公布时间举办 | 否 |
| `postponed` | 延期，日期待定 | 否 |
| `rescheduled` | 已改期，新日期已确定 | 否 |
| `completed` | 已结束 | 是 |
| `canceled` | 已取消 | 是 |

- 取消是终态，不得恢复；已取消活动保留活动身份和变更说明，不得靠删除活动或报名来“取消”。
- `postponed` 且新日期未定时，**不得编造新日期**；公开字段 `starts_at` / `ends_at` 置空，原时间只保留在历史 revision 中。
- 待定活动在公开列表中的排序必须有确定规则（如按 `published_at` 或 `id`），不得依赖数据库对 NULL 的默认排序造成跨页漂移。
- `rescheduled` 必须有新的 `starts_at` / `ends_at`，并在 revision 变更说明中保留原安排。
- 举办状态不决定公开可见性：已取消活动仍可公开查看取消状态；`withheld`/`retracted` 才停止公开。
- 详见 §9 B-06：G1 的 `postponed` / `rescheduled` / `canceled` 写能力在政策签署前禁用。

#### 3.3.4 公开与工作双指针

- `events.published_revision_id`：当前公开版本，只读。
- `events.working_revision_id`：当前编辑版本，仅后台可见。
- `content_posts` 同样使用双指针。
- 公开列表和详情**只读已发布快照**；不得回落到工作版本，也不得把工作版本字段拼进公开响应。
- 草稿、待审、拒绝、暂缓对象的 ID/slug 在公开接口中**不可枚举**：所有未公开状态返回与不存在相同的 404，不通过文案、耗时或字段差异泄露存在性。
- 已撤稿对象返回 410 中性说明，不回显旧标题、旧正文、旧摘要或旧元数据。

#### 3.3.5 报名可用性由服务端计算

`can_register` 不落库、不由前端保存，由服务端按下式实时计算：

```
visible = publish_status == published
       && disposition == normal
       && published_revision_id is not null

can_register = visible
  && source_type == platform
  && platform_role in (organizer, co_organizer, authorized_operator)
  && hosting_status in (scheduled, rescheduled)
  && ecosystem.events.registration_enabled == true
  && ecosystem.events.registration_write_verified == true
  && scope exists && scope.status == open
  && now within registration window
  && (capacity is null || confirmed_count < capacity)
  && identity policy satisfied
  && no active registration
```

- 返回 `can_register` + 稳定 `registration_reason` + `allowed_actions`。
- `registration_reason` 沿用现有公开合同字段名，不改名；`allowed_actions` 为 G1 新增数组。
- `allowed_actions` 只是提示，提交时仍必须重检；不得因为前端拿到 `register` 就跳过服务端守卫。
- **G1 阶段站内报名写入口保持关闭**：`registration_enabled` 与 `registration_write_verified` 任一为 `false`，所有平台活动的 `can_register` 都为 `false`，reason 为 `registration_disabled`。

#### 3.3.6 报名 reason 与 allowed_actions 词表

reason 按以下优先级返回第一条命中项（保证同一状态稳定）：

| 顺序 | reason | 条件 | G1 是否出现 |
|---|---|---|---|
| 1 | `external_only` | `source_type=external` 或 `platform_role=directory_only` | 是 |
| 2 | `canceled` | `hosting_status=canceled` | 是（只读展示） |
| 3 | `ended` | `hosting_status=completed` 或已过结束时间 | 是（只读展示） |
| 4 | `registration_disabled` | 双重开关任一为 false | 是 |
| 5 | `not_open` | 当前时间早于报名开放时间 | 否（G2） |
| 6 | `closed` | 当前时间晚于报名截止时间 | 否（G2） |
| 7 | `requires_login` | 匿名用户且其余条件满足 | 否（G2） |
| 8 | `already_registered` | 当前身份已有有效轮次 | 否（G2） |
| 9 | `full` | 容量有限且 `confirmed_count >= capacity` | 否（G2） |
| 10 | `ok` | 以上均不命中且 `can_register=true` | 否（G2） |

`allowed_actions` 稳定词表：

| 值 | 含义 | G1 |
|---|---|---|
| `view_detail` | 查看公开详情 | 是 |
| `open_external_registration` | 打开已审核的官方外链 | 是（仅外部活动） |
| `login` | 登录后继续 | 否（G2） |
| `register` | 站内报名 | 否（G2） |
| `view_my_registration` | 查看本人报名 | 否（G2） |

- 已撤稿/暂缓/未发布不返回业务动作，只返回 404/410。
- 外部活动即使 `can_register=false`，只要有已审核外链，就返回 `open_external_registration`。
- 不得返回 `waitlist`；候补未立项。

### G1-D04 时间与容量

**时间**

- `starts_at` / `ends_at` / `published_at` / `first_published_at` / 审核与核验时间统一以 **UTC** 存储，字段类型 `DATETIME`。MySQL 5.7 的 `DATETIME` 不带时区，转换由应用层完成。
- `timezone` 使用 **IANA** 名称（如 `Asia/Shanghai`、`Europe/Berlin`），不得存 `+08:00` 这类偏移。
- API 返回 **ISO 8601**，带 `timezone` 对应的偏移；显示按活动 `timezone`。
- 起止时间规则：
  - 两者都为 `null` 表示待定；
  - 两者都有值时必须 `ends_at >= starts_at`；
  - 不允许只有 `starts_at` 没有 `ends_at`；
  - `source_type=platform` 的活动没有确定起止时间时**不得发布**（可 `postponed` 待定，但见 B-06 的写能力限制）；
  - `source_type=external` 的目录条目允许待定展示，但**永远不因此开启站内报名**。
- 现有应用默认时区 `Asia/Shanghai` 不改；新域读写显式转换，迁移不依赖数据库隐式时区。

**容量**

- 首期一个活动一个 `event_registration_scope`；`scope_key` 默认 `default`。
- 计量单位 `unit`：
  - `competition` ⇒ `team`；
  - `salon` / `workshop` / `expo` / `open_day` ⇒ `person`；
  - 同一活动不得同时使用 `team` 与 `person`。
- `capacity` 三态：
  - `null`：不限；
  - `>0`：有限容量；
  - `0`：不开放名额/已关闭，**不得当成不限**；
  - 未知容量不得自动当不限，应按未配置处理并拒绝报名。
- `confirmed_count`：
  - 只统计当前轮次 `confirmed` 与 `checked_in`；
  - 人工审核的 `submitted` 不占确认席位；
  - 取消后只释放一次；降低容量不得低于已占名额；
  - 统计口径与 `event_registration_attempts` 的事实对账，不能只靠前端或手填 `teams`。
- 报名、取消、重报、候补、支付、票务属于 G2 及后续，**G1 不开放**。G1 只冻结语义，不创建 scope 行，不开放写接口。

### G1-D05 主办方与来源

**`ecosystem_organizers` 口径**

每个主办/承办主体至少记录：

| 信息 | 说明 |
|---|---|
| 主体名称 | 对外展示名称；不得与商品 seller 名称自动合并 |
| `platform_role` | 该主体经核验可承担的平台角色；G1 一期一个主体一个角色 |
| 来源 URL | 官方活动页/机构页；只允许 `http`/`https` |
| 公开资料 | 可对外展示的官网、简介、活动页 |
| 私有依据 | 授权书、协议、核验截图等；存储 key 不得进入公开 DTO |
| 核验人 | 后台用户 ID |
| 核验时间 | UTC |
| 状态 | `pending` / `verified` / `rejected` / `suspended` |

**核验边界**

- 来源核验只产生“官方来源/官方原文”说明。
- 来源核验**不等于**机构账号认证，**不等于**全文/图片转载授权，**不等于**平台与机构存在合作徽章。
- 机构账号、合作徽章、认证标识在 G1 不显示；确有需要时另行立项。
- 外部活动只允许已审核外链；服务器**不得**自动抓取任意来源 URL，不得把外部正文、图片或赛规文件复制进平台。
- 禁止 `javascript:`、`data:`、`file:` 等协议；只允许审核过的 `http`/`https` 目标。
- 活动主办方、商品 seller、平台角色三者的关系见 G1-D01，不得互相推定。

**发布前置**

- 发布前必须满足：主办方状态 `verified`、来源字段完整、授权依据存在、外链已审核（外部活动）。
- `co_organizer` / `authorized_operator` 必须有书面授权或协议依据；仅有口头说明或网页截图不足以发布。
- 平台自办（`platform` + `organizer`）仍需记录公司主体和内部责任人，但不需要外部机构认证。

### G1-D06 资讯内容域

**独立建模**

- `content_posts` / `content_revisions` 独立于 CMS 协议静态页、翻译页和营销活动。
- 内容类型 `type`：`news` / `announcement` / `case` / `competition_report`。
- CMS 默认静态页不是资讯；不得把 CMS 页数量宣称成文章数。

**来源与权利**

- 内容 `source_type`：
  - `original`：平台原创。权利依据 `platform_original`；必须记录内部事实核对时间；原文 URL 填平台 canonical URL（草稿阶段可空，提交审核前必填）。
  - `authorized_repost`：获准转载。必须有原文 URL、书面授权依据、授权范围（正文/图片/改编）。
  - `link_summary`：链接摘要。必须有原文 URL；只做摘要和必要引用，不复制全文、不盗链图片。
- 每条来源记录必须具备：来源名称、原文 URL（草稿阶段可空，`in_review` 起必填）、核验时间、权利依据。
- `source_type` 与权利依据不匹配时禁止提交/批准。缺来源、缺授权、仅有外链许可而试图复制全文，均阻断。
- 外部链接审核策略与活动外链一致：只允许 `http`/`https`，不自动抓取，不内网访问。

**revision 不可变**

- revision 一旦 `in_review` 即不可变；修改正文、来源、附件、作者或摘要必须创建新 revision 并重新审核。
- 批准绑定 `content_fingerprint` 与媒体集合指纹；指纹不符不得发布。
- 公开与工作双指针；公开页只读 `published_revision`。
- 拒绝不重新打开原 revision，编辑创建新 revision。
- 更正必须有可见说明、原因、责任编辑和时间；普通修改不得刷新首次发布时间。
- 暂缓可复核恢复；撤稿终态，旧 slug/旧 ID/旧 revision 不复活。

**slug 冲突策略（冻结部分）**

- `content_posts.slug` 全局唯一。
- slug 只使用小写 ASCII、数字和连字符；长度 3–120；不得使用保留前缀（至少包含 `api`、`admin`、`cms`、`content`、`media`）。
- slug 在首次发布后不可变；首次发布前可变，但必须留审计。
- 已撤稿对象的 slug **永久保留，不得复用**；同 slug 再次发布视为冲突，返回 409。
- 中文标题不自动转拼音；由编辑显式填写。具体字符集、保留词全集和转写规则见 §9 B-04。
- 冲突时返回 `CONTENT_SLUG_TAKEN`，不得静默加后缀或覆盖。

**富文本与媒体**

- 富文本允许列表（G1）：`p`、`br`、`strong`、`em`、`u`、`s`、`blockquote`、`ul`、`ol`、`li`、`a`、`img`、`figure`、`figcaption`、`table`、`thead`、`tbody`、`tr`、`th`、`td`、`code`、`pre`、`hr`。
- 禁止：`script`、`style`、事件属性（`on*`）、`iframe`、`form`、`object`、`embed`、`svg`、`math`，以及 `javascript:` / `data:` / `file:` 链接。
- `a` 只允许 `http`/`https`；输出时加 `rel="noopener noreferrer"`。
- `img` 只引用 `ecosystem_media` 中已审核的媒体，不允许任意远程 URL 直链。
- 私有不走 public 存储；公开读取经过受控代理，且同时核验父对象发布状态、当前公开 revision 的真实引用和媒体自身状态。
- 媒体文件/哈希不可原位替换；换文件必须生成新媒体并重新审核。
- 净化版本 `sanitizer_version` 必须随 revision 记录；渲染端不得重新引入危险 HTML。

### G1-D07 Outbox

- `ecosystem_outbox` 与发布/撤回/举办状态变更等业务写**同一事务**记录；业务回滚则 outbox 不留下可消费记录。
- 字段至少覆盖：`aggregate_type`、`aggregate_id`、`event_type`、`payload`、`status`、`attempts`、`available_at`、`lease_owner`、`lease_until`、`last_error`、`created_at`、`updated_at`。
- 增加 `dedupe_key`（可空，唯一）用于业务幂等；同一业务事实不得重复入队。
- 状态：`pending` → `processing` → `sent`；可重试失败回 `pending` 并更新 `available_at`；超过上限为 `dead`。
- worker 用短事务领取租约并提交释放锁；执行时按聚合 → 版本 → outbox 的顺序核验，禁止持 outbox 锁反向等待聚合。
- 过期租约不得结算新租约；旧 worker 的完成/失败回报必须被拒绝。
- `payload` 只放必要 ID、版本和原因码；**不含手机号、证件、令牌、完整联系人**。
- G1 只建立基础与消费接口；报名通知扇出、分页游标、人工补偿入口留 G2-12。
- G1 允许的 `event_type`（示例，落地时以注册表为准）：`event.published`、`event.withheld`、`event.retracted`、`content.published`、`content.withheld`、`content.retracted`。G1 不发送报名相关事件。

### G1-D08 API、错误码与 DTO

**公开 DTO 白名单**

活动公开对象至少包含：

| 字段 | 说明 |
|---|---|
| `id` | 公开活动 ID；保留原 ID |
| `kind` | 活动类型 |
| `name` / `summary` | 来自 published revision |
| `organizer` | `{name, platform_role}`；不含私有证据 |
| `source` | `{name, url}`；外部活动显示官方来源 |
| `participation_mode` | `offline` / `online` / `hybrid` |
| `city` / `location_text` | 来自 published revision |
| `timezone` | IANA |
| `starts_at` / `ends_at` | ISO 8601；待定为 `null` |
| `published_at` / `revision_no` | 公开版本信息 |
| `registration` | `{can_register, registration_reason, allowed_actions}` |
| `hosting_status` | 公开举办状态（取消/延期/改期） |

资讯公开对象至少包含：

| 字段 | 说明 |
|---|---|
| `slug` / `type` / `title` / `summary` | 来自 published revision |
| `author` | `{name, org}` |
| `sources` | 来源名称、URL、核验时间、公开权利说明；不含私有授权文件 |
| `published_at` / `first_published_at` / `revision_no` | 时间分开 |
| `correction_note` | 更正说明；无则 `null` |
| `body_html` | 已净化正文；按渲染策略输出 |

**公开 DTO 永不返回**

- 联系人、手机号、证件、报名资料；
- 内部审核备注、审核人 ID、拒绝原因；
- 工作 revision、`working_revision_id`；
- 私有证据、授权书存储 key、附件存储 key、媒体在磁盘上的路径；
- 商品 seller、订单、支付、技能授权等无关对象；
- 样例 `hot` / `teams` 等无真实来源的指标。

**HTTP 语义**

- 未发布、草稿、待审、暂缓、不存在：统一 404，文案和结构一致，不泄露存在性。
- 已撤稿：410 中性说明；不回显旧标题、旧正文、旧摘要。
- 公开列表只返回已发布且 `normal` 的对象；分页稳定排序，不以随机或热度字段制造顺序漂移。
- 公开读取不得回落到 CMS 静态页、样例或旧缓存正文。

**错误码**

只列 G1 需要的码；全部必须登记到 `ApiErrorCode` 单一事实源。错误码值属于线上契约，改名可以，改值须报批。

| 错误码 | HTTP | 语义 | 客户端处置 | 工作包 |
|---|---|---|---|---|
| `EVENT_NOT_FOUND` | 404 | 活动不存在，或未发布/暂缓（不区分） | 展示不存在/未公开空态 | G1-10 |
| `EVENT_UNAVAILABLE` | 410 | 活动已撤稿，终态 | 展示中性撤稿说明，不再重试 | G1-10 |
| `EVENT_REGISTRATION_DISABLED` | 403 | 站内报名双重开关未开 | 展示停写原因，不重试创建 | G1/G2 |
| `EVENT_REGISTRATION_NOT_OPEN` | 409 | 不在报名时间窗或活动未开放报名 | 展示开放时间或不可报名 | G2 |
| `EVENT_REVISION_CONFLICT` | 409 | `expected_row_version` / revision 不匹配 | 重取最新版本后人工合并 | G1-08 |
| `EVENT_TRANSITION_INVALID` | 409 | 发布/处置/举办状态不允许该动作 | 刷新状态，不重放 | G1-08 |
| `EVENT_SOURCE_REQUIRED` | 422 | 缺主办方、来源、授权依据或外部报名链接 | 补齐资料后重新提交 | G1-03/04 |
| `EVENT_ORGANIZER_UNVERIFIED` | 422 | 主办方未通过核验 | 先完成核验 | G1-03 |
| `EVENT_TIME_INVALID` | 422 | 时区非法、结束早于开始、起止不完整或平台活动缺时间 | 修正时间或改为待定 | G1-02/04 |
| `CONTENT_NOT_FOUND` | 404 | 文章不存在，或未发布/暂缓（不区分） | 展示不存在/未公开空态 | G1-10 |
| `CONTENT_UNAVAILABLE` | 410 | 文章已撤稿，终态 | 展示中性撤稿说明 | G1-10 |
| `CONTENT_REVISION_CONFLICT` | 409 | 期望版本或 revision 不匹配 | 重取后人工合并 | G1-08 |
| `CONTENT_TRANSITION_INVALID` | 409 | 内容状态不允许该动作 | 刷新状态 | G1-08 |
| `CONTENT_SLUG_TAKEN` | 409 | slug 已存在或已撤稿但保留 | 换 slug，不覆盖 | G1-05 |
| `CONTENT_SOURCE_REQUIRED` | 422 | 缺来源名、原文 URL、核验时间或权利依据 | 补齐来源后重新提交 | G1-05 |
| `MEDIA_INVALID` | 422 | 类型/解码/MIME/大小/数量/协议/路径非法 | 更换文件或压缩后重试 | G1-06 |
| `MEDIA_NOT_FOUND` | 404 | 媒体不存在、父对象未公开或无权读取 | 展示空态，不探测 | G1-06 |

补充约定：

- 旧 `EVENT_NOT_REGISTERING` / `EVENT_ENDED` 只保留给旧报名入口兼容期；新公开合同和 G2 写入口使用 `EVENT_REGISTRATION_NOT_OPEN` 等新码，不得把旧码静默改成新语义。
- 乐观并发必须使用 `expected_row_version`（对象级）和 `expected_revision_id`（版本级）；冲突响应可返回当前版本号给有权限的后台用户，公开端不返回。
- 409 不得用于“服务故障”；503 单独返回 `SERVICE_UNAVAILABLE`，且不伪装成功。

### G1-D09 权限与数据保留

**权限**

- G1 新增权限与 G0 的 `catalog.events` **分开**：`catalog.events` 只保留旧管理页兼容，不得授权 G1 的提交、审核、发布、暂缓、撤稿。
- 建议权限键（落地时以 Admin ACL 配置为准）：

| 域 | 权限键 | 允许 | 不允许 |
|---|---|---|---|
| 活动 | `ecosystem.events.view` | 查看草稿/工作 revision | 发布、撤稿、看 PII |
| 活动 | `ecosystem.events.create` | 建草稿 | 审核、发布 |
| 活动 | `ecosystem.events.edit` | 编辑工作 revision | 审核、发布 |
| 活动 | `ecosystem.events.submit` | 提交审核 | 批准 |
| 活动 | `ecosystem.events.review` | 批准/拒绝指定 revision | 发布、撤稿 |
| 活动 | `ecosystem.events.schedule` | 排期/取消排期 | 立即发布 |
| 活动 | `ecosystem.events.publish` | 立即发布 | 编辑、审核 |
| 活动 | `ecosystem.events.withhold` | 暂缓公开 | 普通编辑恢复 |
| 活动 | `ecosystem.events.retract` | 撤稿终态 | 恢复 |
| 主办方 | `ecosystem.organizers.view` | 查看主办方与公开资料 | 查看私有证据、核验 |
| 主办方 | `ecosystem.organizers.manage` | 新建/编辑主办方 | 核验 |
| 主办方 | `ecosystem.organizers.verify` | 核验/拒绝/停用 | 发布活动 |
| 内容 | `ecosystem.content.view` | 查看草稿/工作 revision | 发布、撤稿 |
| 内容 | `ecosystem.content.create` / `edit` / `submit` | 建稿/编辑/提交 | 审核、发布 |
| 内容 | `ecosystem.content.review` | 批准/拒绝 revision | 发布 |
| 内容 | `ecosystem.content.schedule` / `publish` | 排期/发布 | 编辑 |
| 内容 | `ecosystem.content.withhold` / `retract` | 暂缓/撤稿 | 恢复撤稿 |
| 媒体 | `ecosystem.media.upload` | 上传待审媒体 | 批准公开 |
| 媒体 | `ecosystem.media.review` | 审核/拒绝/隔离 | 编辑正文 |

- 新增后台路由必须在同一版本登记权限映射；未登记的路由默认拒绝，并让测试失败。不得全局放宽其他 Bagisto 模块。
- 提交、审核、发布是三个独立能力；生产运营建议至少两人分担，但系统是否强制“提交人不得批准”由运营政策确认（不列为 G1 阻塞）。
- G1 **不开放任何 PII 权限**：不新增报名资料查看、解密、导出、签到权限。G0 的 PII 遮罩与导出封锁继续有效。
- 公开 DTO、后台 DTO、导出 DTO 分开构造；禁止直接序列化 Eloquent 整模型。

**数据保留**

| 对象 | G1 处理 | 公开 | 删除/清理 |
|---|---|---|---|
| `events` 与 `event_revisions` | 保留原 ID；历史 revision 不删除 | 只读已发布且 `normal` | G1 不提供删除；归档政策待 §9 B-05 |
| `content_posts` / `content_revisions` | 提交/批准后不可变；撤稿保留 | 只读已发布且 `normal` | G1 不提供删除；保留期限待 B-05 |
| `ecosystem_media` | 文件/哈希不可替换；拒绝文件隔离 | 仅经父对象与媒体状态双重核验 | G1 不清理；待 B-02/B-05 |
| 主办方私有依据 | 仅授权后台可见 | 否 | 保留至核验失效后按政策处理 |
| `ecosystem_outbox` | 保留消费与重放记录 | 否 | G1 不清洗；保留期限待 B-05 |
| root 8 条 Seeder 样例 + 1 条 RegTest 报名 | **保留、隐藏，不删除、不改写** | 否 | 后续按归档/清理政策处理，G1 不动 |
| `event_registrations` | G1 不读取、不导出、不新增权限 | 否 | G2-11/G2-13 处理 |
| 日志/告警 | 只含必要 ID、版本、原因码、指纹 | 否 | 不含 PII、令牌、证件 |

- 未批准的清理任务只能 dry-run，不得自动批量删除历史记录。
- 撤稿/暂缓对象停止公开，但内部审计证据在保留期内受限保存；不得把“停止公开”做成物理删除。

---

## 4. 字段字典

> 类型按 MySQL 5.7 设计。`NULL=是` 且说明为“历史未核定”的字段，只用于迁移存量，不属于业务枚举值，不得对外展示为任何角色/类型。

### 4.1 `events` 既有字段（保留，不删除、不改语义）

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| events | `id` | BIGINT UNSIGNED | 否 | auto | PK | 保留原 ID；公开 ID 不变 | 既有 |
| events | `name` | VARCHAR(255) | 否 | — | — | 旧展示字段；公开以 revision 为准 | 既有 |
| events | `type` | VARCHAR(255) | 否 | `格斗竞技` | — | 旧中文标签，仅展示；行为判断只认 `kind` | 既有 |
| events | `status` | VARCHAR(20) | 否 | `registering` | — | 旧状态；G1 后不再作为真相源 | 既有 |
| events | `date` | VARCHAR(255) | 是 | NULL | — | 旧自由文本；不解析、不作为已核验时间 | 既有 |
| events | `city` | VARCHAR(255) | 是 | NULL | — | 旧字段；结构化值进 revision | 既有 |
| events | `location` | VARCHAR(255) | 是 | NULL | — | 旧字段；结构化值进 revision | 既有 |
| events | `organizer` | VARCHAR(255) | 是 | NULL | — | 旧自由文本；主办权只认 `organizer_id` | 既有 |
| events | `hot` | INT UNSIGNED | 否 | 60 | — | 样例热度；公开 DTO 不返回 | 既有 |
| events | `teams` | INT UNSIGNED | 否 | 0 | — | 手填/样例队伍数；不得作为指标 | 既有 |
| events | `prize` | TEXT | 是 | NULL | — | 旧字段；发布前由 revision 承接 | 既有 |
| events | `schedule` | TEXT | 是 | NULL | — | 旧字段；不得当结构化赛程 | 既有 |
| events | `history` | TEXT | 是 | NULL | — | 旧字段 | 既有 |
| events | `detail` | TEXT | 是 | NULL | — | 旧正文；新公开正文用 revision 的净化 HTML | 既有 |
| events | `icon` | VARCHAR(255) | 否 | `🏆` | — | 旧展示字段 | 既有 |
| events | `bg_color` | VARCHAR(20) | 否 | `#E8F0FE` | — | 旧展示字段 | 既有 |
| events | `sort` | INT | 否 | 0 | 索引（现状） | 旧排序；不得用于真实热度 | 既有 |
| events | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | 既有时间戳 | 既有 |

### 4.2 `events` 新增字段

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| events | `kind` | VARCHAR(20) | 是 | NULL | `idx(kind)` | `competition/salon/workshop/expo/open_day`；NULL=历史未核定，不可发布 | G1-02 |
| events | `participation_mode` | VARCHAR(20) | 是 | NULL | — | `offline/online/hybrid`；NULL=未核定 | G1-02 |
| events | `publish_status` | VARCHAR(20) | 否 | `draft` | `idx(publish_status, disposition, starts_at)` | `draft/in_review/approved/scheduled/published` | G1-02 |
| events | `disposition` | VARCHAR(20) | 否 | `normal` | 同上 | `normal/withheld/retracted`；`retracted` 终态 | G1-02 |
| events | `hosting_status` | VARCHAR(20) | 是 | NULL | `idx(hosting_status)` | `scheduled/postponed/rescheduled/completed/canceled`；NULL=历史未核定 | G1-02 |
| events | `timezone` | VARCHAR(64) | 是 | NULL | — | IANA 名称；平台活动发布前必填 | G1-02 |
| events | `starts_at` | DATETIME | 是 | NULL | 组合索引见上 | UTC；NULL=待定 | G1-02 |
| events | `ends_at` | DATETIME | 是 | NULL | 组合索引见上 | UTC；不得早于 `starts_at` | G1-02 |
| events | `published_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx` | 公开指针；G1-02 建列，G1-04 建 FK | G1-02 |
| events | `working_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx` | 工作指针；仅后台 | G1-02 |
| events | `first_published_at` | DATETIME | 是 | NULL | — | UTC；首次发布后不刷新 | G1-02 |
| events | `published_at` | DATETIME | 是 | NULL | — | UTC；当前公开版本的发布时间 | G1-02 |
| events | `row_version` | INT UNSIGNED | 否 | 0 | — | 乐观并发；每次可变字段更新 +1 | G1-02 |
| events | `source_type` | VARCHAR(20) | 是 | NULL | `idx(source_type, platform_role)` | `platform/external`；NULL=历史未核定 | G1-03 |
| events | `platform_role` | VARCHAR(20) | 是 | NULL | 同上 | `organizer/co_organizer/authorized_operator/directory_only`；NULL=未核定 | G1-03 |
| events | `organizer_id` | BIGINT UNSIGNED | 是 | NULL | `idx`，G1-03 建 FK | 指向 `ecosystem_organizers.id`；不得指向 customer/seller | G1-03 |
| events | `approved_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx` + FK → `event_revisions.id` | 当前已批准候选版本；公开读取仍只认 `published_revision_id` | G1-08A |
| events | `scheduled_publish_at` | DATETIME | 是 | NULL | `idx(publish_status, scheduled_publish_at)` | UTC；G1-08A 只建列，写入属于 G1-08B | G1-08A/B |

约束（应用层，不依赖 MySQL 5.7 CHECK）：

- `source_type=external` ⇒ `platform_role=directory_only`；
- `source_type=platform` ⇒ `platform_role` 不得为 `directory_only`；
- `publish_status=published` ⇒ `published_revision_id` 非空、`published_at` 非空；
- `disposition=retracted` ⇒ 禁止任何发布/恢复动作；
- `kind`/`source_type`/`platform_role`/`hosting_status`/`timezone`/`starts_at`/`ends_at` 中任一为 NULL 时，不得进入 `published`（外部待定目录例外：`starts_at/ends_at` 可空，但不得开放报名）。

### 4.3 `event_revisions`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| event_revisions | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-04 |
| event_revisions | `event_id` | BIGINT UNSIGNED | 否 | — | `uk(event_id, revision_no)`，FK events.id | 活动归属 | G1-04 |
| event_revisions | `revision_no` | INT UNSIGNED | 否 | — | 同上 | 从 1 递增；不复用 | G1-04 |
| event_revisions | `name` | VARCHAR(255) | 否 | — | — | 公开名称快照 | G1-04 |
| event_revisions | `summary` | VARCHAR(500) | 是 | NULL | — | 摘要 | G1-04 |
| event_revisions | `detail_html` | MEDIUMTEXT | 是 | NULL | — | 已净化正文；记录 `sanitizer_version` | G1-04/07 |
| event_revisions | `city` | VARCHAR(100) | 是 | NULL | — | 城市展示 | G1-04 |
| event_revisions | `location_text` | VARCHAR(255) | 是 | NULL | — | 场馆/线上方式；不含联系人 | G1-04 |
| event_revisions | `participation_mode` | VARCHAR(20) | 是 | NULL | — | `offline/online/hybrid` | G1-04 |
| event_revisions | `timezone` | VARCHAR(64) | 否 | — | — | IANA | G1-04 |
| event_revisions | `starts_at` | DATETIME | 是 | NULL | — | UTC；待定为 NULL | G1-04 |
| event_revisions | `ends_at` | DATETIME | 是 | NULL | — | UTC | G1-04 |
| event_revisions | `source_type` | VARCHAR(20) | 否 | — | — | 快照 `platform/external` | G1-04 |
| event_revisions | `platform_role` | VARCHAR(20) | 否 | — | — | 快照角色 | G1-04 |
| event_revisions | `organizer_id` | BIGINT UNSIGNED | 是 | NULL | `idx`，FK | 主体引用 | G1-04 |
| event_revisions | `organizer_name_snapshot` | VARCHAR(191) | 否 | — | — | 发布时的主体名称；避免主体改名改变历史 | G1-04 |
| event_revisions | `source_name` | VARCHAR(191) | 否 | — | — | 来源名称 | G1-04 |
| event_revisions | `source_url` | VARCHAR(2048) | 是 | NULL | — | 官方来源；不建索引 | G1-04 |
| event_revisions | `source_verified_at` | DATETIME | 是 | NULL | — | UTC | G1-04 |
| event_revisions | `authorization_basis` | VARCHAR(50) | 是 | NULL | — | `self_organized/written_authorization/co_organization_agreement/official_link_only` | G1-04 |
| event_revisions | `authorization_evidence_ref` | VARCHAR(255) | 是 | NULL | — | 私有依据引用；永不公开 | G1-04 |
| event_revisions | `external_registration_url` | VARCHAR(2048) | 是 | NULL | — | 外部活动已审核外链；不建索引 | G1-04 |
| event_revisions | `rule_summary` | TEXT | 是 | NULL | — | 规则摘要；详细规则见 `event_rule_versions` | G1-04 |
| event_revisions | `content_fingerprint` | CHAR(64) | 否 | — | — | 内容 sha256；提交/批准/发布核验 | G1-04 |
| event_revisions | `media_manifest_hash` | CHAR(64) | 是 | NULL | — | 媒体集合指纹 | G1-04/06 |
| event_revisions | `sanitizer_version` | VARCHAR(20) | 是 | NULL | — | 富文本净化版本 | G1-07 |
| event_revisions | `review_state` | VARCHAR(20) | 否 | `draft` | `idx(event_id, review_state)` | `draft/in_review/approved/rejected` | G1-04 |
| event_revisions | `submitted_by` | BIGINT UNSIGNED | 是 | NULL | — | 后台用户 ID | G1-04 |
| event_revisions | `submitted_at` | DATETIME | 是 | NULL | — | UTC | G1-04 |
| event_revisions | `reviewed_by` | BIGINT UNSIGNED | 是 | NULL | — | 后台用户 ID；不公开 | G1-04 |
| event_revisions | `reviewed_at` | DATETIME | 是 | NULL | — | UTC | G1-04 |
| event_revisions | `review_note` | TEXT | 是 | NULL | — | 内部审核意见；永不公开 | G1-04 |
| event_revisions | `change_summary` | VARCHAR(500) | 否 | — | — | 本版本变更说明；公开可显示 | G1-04 |
| event_revisions | `published_at` | DATETIME | 是 | NULL | — | 该版本首次公开时间 | G1-04 |
| event_revisions | `created_by` | BIGINT UNSIGNED | 否 | — | — | 创建人 | G1-04 |
| event_revisions | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | `updated_at` 仅用于审核状态流转，不改正文 | G1-04 |

不变式：

- `review_state ∈ {in_review, approved, rejected}` 后，内容列（名称、摘要、正文、时间、来源、附件、指纹）不可更新。
- 审核拒绝不重新打开本 revision；编辑创建 `revision_no + 1`。
- `approved` 绑定 `content_fingerprint` 与 `media_manifest_hash`；发布前重算并比对。
- 提交审核前，`source_name`、`source_verified_at`、`authorization_basis`、`organizer_name_snapshot` 必填；外部活动发布前 `external_registration_url` 必填。草稿阶段允许暂空，但 `in_review` 起不得缺失。

### 4.4 `ecosystem_organizers`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| ecosystem_organizers | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-03 |
| ecosystem_organizers | `name` | VARCHAR(191) | 否 | — | `idx(name(64))` | 主体名称；不自动合并 seller | G1-03 |
| ecosystem_organizers | `platform_role` | VARCHAR(20) | 否 | — | `idx(status, platform_role)` | 经核验可承担的角色；G1 一期一个 | G1-03 |
| ecosystem_organizers | `source_url` | VARCHAR(2048) | 是 | NULL | — | 官方页；`http/https` | G1-03 |
| ecosystem_organizers | `public_materials` | TEXT | 是 | NULL | — | 可公开资料 | G1-03 |
| ecosystem_organizers | `private_evidence_ref` | VARCHAR(255) | 是 | NULL | — | 私有依据引用；永不公开 | G1-03 |
| ecosystem_organizers | `verified_by` | BIGINT UNSIGNED | 是 | NULL | — | 核验人 | G1-03 |
| ecosystem_organizers | `verified_at` | DATETIME | 是 | NULL | — | UTC | G1-03 |
| ecosystem_organizers | `status` | VARCHAR(20) | 否 | `pending` | `idx(status)` | `pending/verified/rejected/suspended` | G1-03 |
| ecosystem_organizers | `verification_note` | TEXT | 是 | NULL | — | 内部核验说明；永不公开 | G1-03 |
| ecosystem_organizers | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G1-03 |

不变式：

- `status=verified` 必须有 `verified_by`、`verified_at` 和至少一项依据（公开资料或私有依据）。
- 活动发布时 `events.source_type` / `platform_role` 必须与所引用 organizer 的记录一致；不一致需新建 organizer 并单独核验。
- 来源核验不产生机构账号认证，不产生全文/图片转载授权。

### 4.5 `event_rule_versions`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| event_rule_versions | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-04 |
| event_rule_versions | `event_id` | BIGINT UNSIGNED | 否 | — | `uk(event_id, version_no)`，FK | 活动归属 | G1-04 |
| event_rule_versions | `version_no` | INT UNSIGNED | 否 | — | 同上 | 从 1 递增 | G1-04 |
| event_rule_versions | `title` | VARCHAR(255) | 否 | — | — | 规则名称 | G1-04 |
| event_rule_versions | `authority_language` | VARCHAR(10) | 否 | — | — | 权威语言，如 `zh-CN` | G1-04 |
| event_rule_versions | `body_html` | MEDIUMTEXT | 是 | NULL | — | 平台获准存储的正文；仅外链许可不得复制 | G1-04 |
| event_rule_versions | `external_url` | VARCHAR(2048) | 是 | NULL | — | 官方规则链接；不建索引 | G1-04 |
| event_rule_versions | `file_media_id` | BIGINT UNSIGNED | 是 | NULL | `idx` | 获准存储的规则文件 | G1-04/06 |
| event_rule_versions | `content_fingerprint` | CHAR(64) | 否 | — | — | 正文/文件指纹 | G1-04 |
| event_rule_versions | `change_summary` | VARCHAR(500) | 否 | — | — | 变更摘要 | G1-04 |
| event_rule_versions | `effective_from` | DATETIME | 是 | NULL | — | UTC | G1-04 |
| event_rule_versions | `status` | VARCHAR(20) | 否 | `draft` | `idx(event_id, status)` | `draft/approved/superseded/withdrawn` | G1-04 |
| event_rule_versions | `reviewed_by` / `reviewed_at` | BIGINT UNSIGNED / DATETIME | 是 | NULL | — | 审核记录 | G1-04 |
| event_rule_versions | `created_by` | BIGINT UNSIGNED | 否 | — | — | — | G1-04 |
| event_rule_versions | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G1-04 |

不变式：

- `body_html` 与 `external_url` 至少一项；仅有外链许可时只能存 URL，不得复制全文。
- 已批准版本不可改正文/文件；新版本重新审核。
- 报名若绑定规则（G2），必须绑定具体 `version_no` 与指纹，不得只绑活动 ID。

### 4.6 `content_posts`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| content_posts | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-05 |
| content_posts | `type` | VARCHAR(20) | 否 | — | `idx(type, first_published_at)` | `news/announcement/case/competition_report` | G1-05 |
| content_posts | `slug` | VARCHAR(120) | 否 | — | `uk(slug)` | 全局唯一；小写 ASCII/数字/连字符；撤稿后不复用 | G1-05 |
| content_posts | `category` | VARCHAR(64) | 是 | NULL | `idx(category)` | 分类标签 | G1-05 |
| content_posts | `publish_status` | VARCHAR(20) | 否 | `draft` | `idx(publish_status, disposition, first_published_at)` | 同活动发布轴 | G1-05 |
| content_posts | `disposition` | VARCHAR(20) | 否 | `normal` | 同上 | `normal/withheld/retracted` | G1-05 |
| content_posts | `published_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx`，G1-05 后建 FK | 公开指针 | G1-05 |
| content_posts | `working_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx` | 工作指针 | G1-05 |
| content_posts | `first_published_at` | DATETIME | 是 | NULL | 组合索引见上 | UTC；首次发布后不刷新 | G1-05 |
| content_posts | `published_at` | DATETIME | 是 | NULL | — | UTC；当前公开版本时间 | G1-05 |
| content_posts | `row_version` | INT UNSIGNED | 否 | 0 | — | 乐观并发 | G1-05 |
| content_posts | `created_by` | BIGINT UNSIGNED | 否 | — | — | 创建人 | G1-05 |
| content_posts | `updated_by` | BIGINT UNSIGNED | 是 | NULL | — | 最近操作人 | G1-05 |
| content_posts | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G1-05 |
| content_posts | `approved_revision_id` | BIGINT UNSIGNED | 是 | NULL | `idx` + FK → `content_revisions.id` | 当前已批准候选版本；公开读取仍只认 `published_revision_id` | G1-08A |
| content_posts | `scheduled_publish_at` | DATETIME | 是 | NULL | `idx(publish_status, scheduled_publish_at)` | UTC；G1-08A 只建列，写入属于 G1-08B | G1-08A/B |

### 4.7 `content_revisions`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| content_revisions | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-05 |
| content_revisions | `post_id` | BIGINT UNSIGNED | 否 | — | `uk(post_id, revision_no)`，FK | 文章归属 | G1-05 |
| content_revisions | `revision_no` | INT UNSIGNED | 否 | — | 同上 | 从 1 递增 | G1-05 |
| content_revisions | `title` | VARCHAR(255) | 否 | — | — | 标题 | G1-05 |
| content_revisions | `summary` | VARCHAR(500) | 是 | NULL | — | 摘要 | G1-05 |
| content_revisions | `body_html` | MEDIUMTEXT | 是 | NULL | — | 已净化正文 | G1-05/07 |
| content_revisions | `author_name` | VARCHAR(191) | 否 | — | — | 作者展示名 | G1-05 |
| content_revisions | `author_org` | VARCHAR(191) | 是 | NULL | — | 作者机构；不等于机构认证 | G1-05 |
| content_revisions | `source_type` | VARCHAR(20) | 否 | — | — | `original/authorized_repost/link_summary` | G1-05 |
| content_revisions | `original_published_at` | DATETIME | 是 | NULL | — | 原文时间；与平台首发分开 | G1-05 |
| content_revisions | `correction_note` | TEXT | 是 | NULL | — | 公开更正说明 | G1-05 |
| content_revisions | `change_summary` | VARCHAR(500) | 否 | — | — | 本版本变更说明 | G1-05 |
| content_revisions | `content_fingerprint` | CHAR(64) | 否 | — | — | 内容 sha256 | G1-05 |
| content_revisions | `media_manifest_hash` | CHAR(64) | 是 | NULL | — | 媒体集合指纹 | G1-06 |
| content_revisions | `sanitizer_version` | VARCHAR(20) | 否 | — | — | 净化版本 | G1-07 |
| content_revisions | `review_state` | VARCHAR(20) | 否 | `draft` | `idx(post_id, review_state)` | `draft/in_review/approved/rejected` | G1-05 |
| content_revisions | `submitted_by` / `submitted_at` | BIGINT UNSIGNED / DATETIME | 是 | NULL | — | 提交记录 | G1-05 |
| content_revisions | `reviewed_by` / `reviewed_at` | BIGINT UNSIGNED / DATETIME | 是 | NULL | — | 审核记录；不公开 | G1-05 |
| content_revisions | `review_note` | TEXT | 是 | NULL | — | 内部意见；永不公开 | G1-05 |
| content_revisions | `published_at` | DATETIME | 是 | NULL | — | 该版本公开时间 | G1-05 |
| content_revisions | `created_by` | BIGINT UNSIGNED | 否 | — | — | — | G1-05 |
| content_revisions | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | `updated_at` 不用于正文变更 | G1-05 |

### 4.8 `content_sources`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| content_sources | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-05 |
| content_sources | `revision_id` | BIGINT UNSIGNED | 否 | — | `idx(revision_id)`，FK | 所属 revision | G1-05 |
| content_sources | `source_name` | VARCHAR(191) | 否 | — | — | 来源名称 | G1-05 |
| content_sources | `source_url` | VARCHAR(2048) | 是 | NULL | — | 原文 URL；草稿可空，`in_review` 起必填；原创填平台 canonical URL | G1-05 |
| content_sources | `source_verified_at` | DATETIME | 否 | — | — | 核验时间（UTC）；原创为事实核对时间 | G1-05 |
| content_sources | `rights_basis` | VARCHAR(50) | 否 | — | — | `platform_original/written_repost_permission/official_link_only/public_document_summary` | G1-05 |
| content_sources | `rights_scope` | VARCHAR(255) | 是 | NULL | — | 正文/图片/改编范围 | G1-05 |
| content_sources | `evidence_ref` | VARCHAR(255) | 是 | NULL | — | 私有授权依据引用；永不公开 | G1-05 |
| content_sources | `sort_order` | INT UNSIGNED | 否 | 0 | `uk(revision_id, sort_order)` | 展示顺序 | G1-05 |
| content_sources | `created_at` | TIMESTAMP | 是 | NULL | — | — | G1-05 |

不变式：

- 每个进入 `in_review` 的 revision 至少一条来源；`source_type=original` 要求 `rights_basis=platform_original` 且 `source_url` 为平台 canonical URL。
- `authorized_repost` 要求 `source_url`、书面授权依据和 `rights_scope`；`link_summary` 要求 `source_url` 且不得复制全文。
- 来源记录随 revision 不可变；修改来源创建新 revision。

### 4.9 `ecosystem_media`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| ecosystem_media | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-06 |
| ecosystem_media | `disk` | VARCHAR(50) | 否 | — | — | 存储盘标识；不对外 | G1-06 |
| ecosystem_media | `storage_key` | VARCHAR(255) | 否 | — | `uk(storage_key)` | 私有路径；永不进入公开 DTO | G1-06 |
| ecosystem_media | `original_name` | VARCHAR(255) | 是 | NULL | — | 原始文件名；展示前需净化 | G1-06 |
| ecosystem_media | `mime` | VARCHAR(100) | 否 | — | — | 真实解码后的 MIME | G1-06 |
| ecosystem_media | `byte_size` | BIGINT UNSIGNED | 否 | — | — | 字节数 | G1-06 |
| ecosystem_media | `width` / `height` | INT UNSIGNED | 是 | NULL | — | 图片尺寸 | G1-06 |
| ecosystem_media | `sha256` | CHAR(64) | 否 | — | `uk(sha256)` | 文件不可原位替换 | G1-06 |
| ecosystem_media | `status` | VARCHAR(20) | 否 | `pending` | `idx(status)` | `pending/approved/rejected/quarantined` | G1-06 |
| ecosystem_media | `uploaded_by` | BIGINT UNSIGNED | 否 | — | — | 上传人 | G1-06 |
| ecosystem_media | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G1-06 |

### 4.10 `ecosystem_media_refs`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| ecosystem_media_refs | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-06 |
| ecosystem_media_refs | `media_id` | BIGINT UNSIGNED | 否 | — | `idx(media_id)`，FK | 媒体对象 | G1-06 |
| ecosystem_media_refs | `owner_type` | VARCHAR(30) | 否 | — | `uk(owner_type, owner_id, sort_order)` | `event_revision/content_revision` | G1-06 |
| ecosystem_media_refs | `owner_id` | BIGINT UNSIGNED | 否 | — | 同上 | 所属 revision | G1-06 |
| ecosystem_media_refs | `role` | VARCHAR(20) | 否 | — | — | `cover/inline/attachment/rule_document` | G1-06 |
| ecosystem_media_refs | `sort_order` | INT UNSIGNED | 否 | 0 | 同上 | 展示顺序 | G1-06 |
| ecosystem_media_refs | `alt_text` | VARCHAR(255) | 是 | NULL | — | 无障碍替代文本 | G1-06 |
| ecosystem_media_refs | `review_status` | VARCHAR(20) | 否 | `pending` | — | `pending/approved/rejected` | G1-06 |
| ecosystem_media_refs | `created_at` | TIMESTAMP | 是 | NULL | — | — | G1-06 |

公开读取必须同时满足：父对象 `published` + `normal`、父 revision 为当前 `published_revision`、引用存在、`ecosystem_media.status=approved`、`review_status=approved`。任一不满足即 404。

### 4.11 `ecosystem_outbox`

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| ecosystem_outbox | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G1-02 |
| ecosystem_outbox | `aggregate_type` | VARCHAR(50) | 否 | — | `idx(aggregate_type, aggregate_id)` | `event/content` 等 | G1-02 |
| ecosystem_outbox | `aggregate_id` | BIGINT UNSIGNED | 否 | — | 同上 | 聚合 ID | G1-02 |
| ecosystem_outbox | `event_type` | VARCHAR(100) | 否 | — | `idx(event_type)` | 如 `event.published` | G1-02 |
| ecosystem_outbox | `payload` | JSON | 否 | — | — | 只含 ID/版本/原因码；无 PII | G1-02 |
| ecosystem_outbox | `dedupe_key` | VARCHAR(191) | 是 | NULL | `uk(dedupe_key)` | 业务幂等键；可空 | G1-02 |
| ecosystem_outbox | `status` | VARCHAR(20) | 否 | `pending` | `idx(status, available_at)` | `pending/processing/sent/dead` | G1-02 |
| ecosystem_outbox | `attempts` | SMALLINT UNSIGNED | 否 | 0 | — | 已尝试次数 | G1-02 |
| ecosystem_outbox | `available_at` | DATETIME | 否 | — | 组合索引见上 | UTC；下次可领取时间 | G1-02 |
| ecosystem_outbox | `lease_owner` | VARCHAR(100) | 是 | NULL | — | worker 标识 | G1-09 |
| ecosystem_outbox | `lease_until` | DATETIME | 是 | NULL | — | UTC；过期可被重新领取 | G1-09 |
| ecosystem_outbox | `last_error` | TEXT | 是 | NULL | — | 最近错误；不得含 PII/令牌 | G1-09 |
| ecosystem_outbox | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G1-02 |

### 4.12 G2 预留：`event_registration_scopes`

G1 **不创建**此表，仅冻结语义；G2-01 落地。

| 对象 | 字段 | 类型 | NULL | 默认 | 索引/唯一 | 说明 | 所属工作包 |
|---|---|---|---|---|---|---|---|
| event_registration_scopes | `id` | BIGINT UNSIGNED | 否 | auto | PK | — | G2-01 |
| event_registration_scopes | `event_id` | BIGINT UNSIGNED | 否 | — | `uk(event_id, scope_key)`，FK | 活动 | G2-01 |
| event_registration_scopes | `scope_key` | VARCHAR(50) | 否 | `default` | 同上 | 首期固定 `default` | G2-01 |
| event_registration_scopes | `unit` | VARCHAR(10) | 否 | — | — | `team/person`；由 `kind` 决定 | G2-01 |
| event_registration_scopes | `capacity` | INT UNSIGNED | 是 | NULL | — | NULL 不限；0 关闭；>0 有限 | G2-01 |
| event_registration_scopes | `confirmed_count` | INT UNSIGNED | 否 | 0 | — | 只计当前 confirmed/checked_in | G2-01 |
| event_registration_scopes | `review_mode` | VARCHAR(10) | 否 | — | — | `auto/manual` | G2-01 |
| event_registration_scopes | `registration_opens_at` | DATETIME | 是 | NULL | — | UTC | G2-01 |
| event_registration_scopes | `registration_closes_at` | DATETIME | 是 | NULL | — | UTC | G2-01 |
| event_registration_scopes | `review_closes_at` | DATETIME | 是 | NULL | — | UTC；与报名截止分开 | G2-01 |
| event_registration_scopes | `status` | VARCHAR(20) | 否 | `draft` | `idx(event_id, status)` | `draft/open/closed` | G2-01 |
| event_registration_scopes | `row_version` | INT UNSIGNED | 否 | 0 | — | 并发控制 | G2-01 |
| event_registration_scopes | `created_at` / `updated_at` | TIMESTAMP | 是 | NULL | — | — | G2-01 |

`event_registrations` 既有字段（`id`、`event_id` INT UNSIGNED、`customer_id`、`name`、`phone`、`city`、`note`、时间戳）G1 **不改、不读、不导出**；类型统一与外键修复留 G2-02。

---

## 5. 状态转移矩阵

### 5.1 发布轴 + 处置轴

| 当前 | 动作 | 结果 | 权限 | 前置条件 | 副作用 |
|---|---|---|---|---|---|
| 无 | create | `draft` | `create` | kind/来源主体可后补 | 创建 working revision |
| `draft` | submit | `in_review` | `submit` | revision 完整、来源齐全、媒体已审 | revision 置 `in_review`，不可变 |
| `in_review` | approve | `approved` | `review` | 指纹未变、无利益冲突配置 | revision 置 `approved`，记录审核人/时间 |
| `in_review` | reject | `draft` | `review` | 填写内部原因 | revision 置 `rejected`；新建 revision 才能再提 |
| `approved` | schedule | `scheduled` | `schedule` | 计划时间在未来、revision 仍批准 | 创建排期任务，绑定 revision 指纹 |
| `approved` | publish | `published` | `publish` | 公开前置全部满足 | 切换 `published_revision_id`，写 outbox |
| `scheduled` | worker publish | `published` | system | 重检版本/指纹/处置/来源/媒体/权限 | 切换指针，写 outbox；失败告警 |
| `scheduled` | unschedule | `approved` | `schedule` | — | 取消任务 |
| `published` | edit | `published`（公开不变） | `edit` | 创建新 working revision | 公开页继续读旧 revision |
| `published` | withhold | `published` + `withheld` | `withhold` | 填写原因 | 公开立即不可见；写 outbox |
| `withheld` | restore | `published` + `normal` | `review` 或 `publish` | 复核通过、版本/来源/媒体重检 | 恢复同一合格 revision；写 outbox |
| `draft/in_review/approved/scheduled/published/withheld` | retract | 对应状态 + `retracted` | `retract` | 填写原因 | 终态；公开 410；写 outbox |
| `retracted` | 任意 | 拒绝 | — | — | 返回 `*_TRANSITION_INVALID` |

补充：

- `approved`/`scheduled` 对象创建新 working revision 时，原批准失效，`publish_status` 回 `draft`；排期任务必须重检并中止。
- `published` 对象创建新 working revision 不改变公开指针；新版本批准并发布前，公开页仍是旧版本。
- `retracted` 不得被普通编辑、重试、定时任务、数据修复脚本打开。
- 发布 worker 只处理 `scheduled`；不得用“文章/活动 ID + 当前时间”恢复公开而不核对 revision。

### 5.2 举办轴

| 当前 | 动作 | 结果 | 前置条件 | 说明 |
|---|---|---|---|---|
| 无 | 初始化 | `scheduled` | 有确定起止时间 | 平台活动发布前必须 |
| `scheduled` | postpone | `postponed` | 新日期未定 | 公开时间置空；原时间留历史 revision |
| `scheduled` / `postponed` | reschedule | `rescheduled` | 新起止时间已确定 | 保留原安排 |
| `postponed` / `rescheduled` | restore schedule | `scheduled` | 时间确定 | 允许 |
| `scheduled` / `postponed` / `rescheduled` | complete | `completed` | 活动已结束 | 终态 |
| `scheduled` / `postponed` / `rescheduled` | cancel | `canceled` | 取消政策已确认 | 终态；活动仍可公开查看取消状态 |
| `completed` / `canceled` | 任意 | 拒绝 | — | 返回 `EVENT_TRANSITION_INVALID` |

G1 限制：`postpone` / `reschedule` / `cancel` 在 §9 B-06 签署前**禁用**；运营用 `withhold` 或 `retract` 停止展示。`scheduled → completed` 允许。

### 5.3 公开可见性矩阵

| publish_status | disposition | hosting_status | 公开列表 | 公开详情 | 报名 reason |
|---|---|---|---|---|---|
| `published` | `normal` | 任意（含 canceled） | 是 | 是 | 按 §3.3.6 |
| `published` | `withheld` | 任意 | 否 | 404 | — |
| `published` | `retracted` | 任意 | 否 | 410 | — |
| `draft/in_review/approved/scheduled` | 任意 | 任意 | 否 | 404 | — |
| 任意 | `retracted` | 任意 | 否 | 410 | — |

### 5.4 旧 `status` 映射（仅兼容显示，不驱动行为）

| 旧 `status` | 含义 | G1 处理 |
|---|---|---|
| `registering` | 旧“报名中” | 不据此开放报名；`can_register` 仍由 §3.3.5 计算 |
| `upcoming` | 旧“即将开始” | 不据此设置 `hosting_status` |
| `ongoing` | 旧“进行中” | 不据此设置 `hosting_status` |
| `ended` | 旧“已结束” | 不据此设置 `completed`；需运营确认 |
| 其它/空 | 未知 | 按未核定处理，不可发布 |

现有 8 条 Seeder 样例统一回填 `publish_status=draft`、`disposition=normal`、`row_version=0`，其余新增字段保持 NULL；不根据旧 `status`/`date` 猜测新状态。

---

## 6. 错误码

见 §3 G1-D08 表格。落地要求：

- 所有码必须加入 `ApiErrorCode`，并以 `ApiErrorCode::all()` 的可测试集合约束控制器；禁止在控制器里写裸字面量。
- 码值属于线上契约；新增码可以，改已有码的值须报批。
- 公开端错误响应不得包含堆栈、SQL、内部 ID、审核备注、联系人。
- 404 与 410 的区分只用于“曾经公开且已撤稿”的对象；草稿、待审、暂缓、不存在统一 404。

---

## 7. 权限矩阵

### 7.1 角色与能力（G1）

| 角色 | 活动 | 内容 | 主办方 | 媒体 | PII/导出 |
|---|---|---|---|---|---|
| 访客 | 只读已发布 | 只读已发布 | 只读公开资料 | 只读已审公开媒体 | 无 |
| 登录客户 | 只读已发布；G1 无报名 | 只读已发布 | 只读公开资料 | 只读 | 无 |
| 内容编辑 | 无 | create/edit/submit | 只读 | upload | 无 |
| 内容审核 | 无 | view/review | 只读 | view/review | 无 |
| 内容发布 | 无 | view/schedule/publish/withhold/retract | 只读 | view | 无 |
| 活动编辑 | create/edit/submit | 无 | view/manage | upload | 无 |
| 活动审核 | view/review | 无 | view/verify | view | 无 |
| 活动发布 | view/schedule/publish/withhold/retract | 无 | view | view | 无 |
| 平台管理员 | 配置权限与紧急处置 | 同左 | 同左 | 同左 | G1 不新增 |

### 7.2 硬约束

- 新增后台路由未登记权限映射时默认拒绝，并让本模块测试失败。
- `catalog.events` 不得授权 G1 的提交、审核、发布、暂缓、撤稿；旧管理页只保留兼容读取。
- 菜单、按钮、Ajax、DataGrid 导出使用同一权限；隐藏菜单不等于授权。
- G1 不新增报名 PII 查看、解密、导出、签到权限。
- 公开 DTO、后台 DTO、导出 DTO 分开；禁止直接序列化模型。
- 审核人、发布人、撤稿人必须写入审计；日志不含 PII。

---

## 8. 数据保留与治理

见 §3 G1-D09 表格。补充：

- G1 不提供活动/内容的物理删除接口；撤稿是终态处置，不是删除。
- root 库 8 条 Seeder 样例和 1 条 RegTest 报名**保持原样、保持隐藏**；不得改写、不得删除、不得作为验收数据。
- 未批准的清理任务只能 dry-run；不得自动批量删除历史记录。
- 历史 revision 是审计证据；即使对象撤稿，内部仍按保留期受限保存。
- 保留期限、账号注销、材料删除与审计保留的最终口径见 §9 B-05；签署前不执行任何自动清理。

---

## 9. 阻塞项与待确认

> 以下各项**未确认前，对应写能力保持关闭**。不得用默认值、示例数据或“先上线再说”绕过。

| 编号 | 阻塞项 | 未确认的影响 | 未确认前禁用的能力 | 确认人/日期 |
|---|---|---|---|---|
| B-01 | 外部活动外链许可与展示边界 | 无法判断哪些外部活动可公开目录展示、外链是否需逐条许可 | `source_type=external` 的活动发布；外链公开展示 | 待填 |
| B-02 | 媒体权利（图片、规则文件、转载图） | 无法确认第三方图片/文件的公开与存储许可 | 含第三方媒体的内容/活动发布；第三方媒体审核通过 | 待填 |
| B-03 | 主办方核验标准（证据等级、授权书、有效期） | 无法确认 `co_organizer` / `authorized_operator` 的授权强度 | `co_organizer`、`authorized_operator`、所有外部活动发布 | 待填 |
| B-04 | slug 字符集、保留词全集、中文转写规则 | 冲突策略已冻结，但最终格式未定 | 内容公开发布（草稿可用临时 slug，发布前必须通过最终校验） | 待填 |
| B-05 | 数据保留期限、注销/删除/审计保留 | 无法执行清理，也无法承诺删除时限 | 任何自动清理/物理删除任务；G1 保持不删除 | 待填 |
| B-06 | 活动取消/改期/延期政策（含对报名的影响） | 取消/改期的展示、告知和后续责任未定 | `postponed`、`rescheduled`、`canceled` 写能力；运营改用暂缓/撤稿 | 待填 |
| B-07 | 内容法律责任与资质适用范围（新闻信息服务、生态治理规定等） | 无法确认资讯公开发布的业务资质与责任边界 | `ECOSYSTEM_CONTENT_PUBLIC_ENABLED=true`；内容公开发布 | 待填 |

补充说明：

- B-01/B-03 未签署前，**只有平台自办**（`platform` + `organizer`）活动可进入发布流程；外部、联合主办、授权承办一律保持 `draft`。
- B-02 未签署前，只允许平台自有或已明确书面授权的媒体；其余媒体不得进入公开 revision。
- B-04 不阻塞 G1-02/G1-05 建表与草稿；阻塞内容公开开关。
- B-05 不阻塞发布；阻塞一切清理/删除动作。G1 没有清理任务。
- B-06 未签署前，`scheduled → completed` 允许，其余举办状态变更禁用。
- B-07 未签署前，G1 可以建草稿、审核、演练，但内容公开开关保持 `false`，不得对外展示为资讯。
- 所有确认必须留书面记录（会议纪要、邮件、审批单），并回填本表；口头确认不算。

---

## 10. G1-01 验收标准

### 10.1 完成判据

| 判据 | 状态 | 证据 |
|---|---|---|
| 主体、来源、平台角色三者明确且不可互相推定 | 已完成 | §3 G1-D01、§4.4 |
| `source_type` / `platform_role` 枚举冻结 | 已完成 | §3 G1-D01 |
| 首期 `kind` 与容量单位冻结 | 已完成 | §3 G1-D02、G1-D04 |
| 发布、举办、报名三轴分离 | 已完成 | §3 G1-D03、§5 |
| 时间 UTC/IANA/ISO 8601 口径 | 已完成 | §3 G1-D04 |
| `capacity` 三态与 `confirmed_count` 口径 | 已完成 | §3 G1-D04、§4.12 |
| 主办方核验与来源权利边界 | 已完成 | §3 G1-D05、§4.4 |
| 内容独立域、来源、修订、slug、富文本安全 | 已完成 | §3 G1-D06、§4.6–4.10 |
| outbox 字段与事务边界 | 已完成 | §3 G1-D07、§4.11 |
| 公开 DTO 白名单与错误码 | 已完成 | §3 G1-D08、§6 |
| 权限与保留策略 | 已完成 | §3 G1-D09、§7、§8 |
| 不完整决策标记为阻塞并映射到禁用能力 | 已完成 | §9 |
| G1-02 最小 schema 与迁移顺序 | 已完成 | §11 |
| 评审人/日期/负责人签署 | **待签** | 下表 |

### 10.2 签署栏（留空待签）

| 角色 | 姓名 | 日期 | 结论/备注 |
|---|---|---|---|
| 产品负责人 |  |  |  |
| 架构负责人 |  |  |  |
| 业务/活动负责人 |  |  |  |
| 内容负责人 |  |  |  |
| 法务/合规 |  |  |  |
| QA 负责人 |  |  |  |

> 签署完成前，本 ADR 只能作为实现输入，不得据此打开 `ecosystem.events.public_enabled` 或 `ecosystem.content.public_enabled`。

---

## 11. 下一阶段 G1-02 入口

### 11.1 G1-02 最小 schema 清单（只列，不写迁移）

G1-02 只做 `events` 扩展与 `ecosystem_outbox`：

1. `ALTER TABLE events` 新增：
   - `kind`、`participation_mode`
   - `publish_status`、`disposition`、`hosting_status`
   - `timezone`、`starts_at`、`ends_at`
   - `published_revision_id`、`working_revision_id`
   - `first_published_at`、`published_at`
   - `row_version`
2. 新增索引：
   - `idx(publish_status, disposition, starts_at)`
   - `idx(hosting_status)`
   - `idx(kind)`
   - `idx(published_revision_id)`、`idx(working_revision_id)`
3. 新建 `ecosystem_outbox` 及 §4.11 索引。
4. 存量回填：
   - 现有全部 `events` 行：`publish_status='draft'`、`disposition='normal'`、`row_version=0`；
   - 其余新增字段保持 NULL；
   - 不根据 `status`/`date`/`type` 猜测 `kind`、`hosting_status` 或时间。
5. **不创建** FK（`event_revisions` 尚未建）；`published_revision_id` / `working_revision_id` 先为普通列，G1-04 建表后再加 FK。
6. **不 drop** `date` / `status` / `type` / `organizer` / `hot` / `teams`。
7. **不改** `event_registrations` 结构与数据。
8. 迁移后 `ecosystem.events.public_enabled` 仍为 `false`。

### 11.2 迁移顺序与 preflight

建议顺序（expand → 回填 → 校验 → 后续收缩，G1 不做收缩）：

1. 只读 preflight：
   - 确认 MySQL 5.7 版本与 `innodb` 配置；
   - 统计 `events` / `event_registrations` 行数，记录现有 ID；
   - 记录 `events.id`（BIGINT）与 `event_registrations.event_id`（INT）类型差异；
   - 统计 `date` 非空但无法解析的行；
   - 确认字符集/排序规则；
   - 备份目标库。
2. 执行 additive migration（只加列/加表/加索引）。
3. 回填 `publish_status` / `disposition` / `row_version`。
4. 逐字段对比旧列未变化。
5. 在独立测试库 `summon_mecha_step10_test` 跑迁移与回归；禁止对 root 主库 `migrate:fresh` / `truncate` / `seed`。

MySQL 5.7 DDL 不能假定事务回滚；迁移前必须有可恢复备份。新增列/索引的锁表影响在演练库评估。

### 11.3 G1-02 明确不做

- `event_revisions`（G1-04）
- `ecosystem_organizers` 与 `organizer_id`（G1-03）
- `event_rule_versions`（G1-04）
- `content_posts` / `content_revisions` / `content_sources`（G1-05）
- `ecosystem_media` / `ecosystem_media_refs`（G1-06）
- 富文本净化（G1-07）
- 发布/审核/撤稿服务与 worker（G1-08/G1-09）
- 公开 Resource 与前端（G1-10/G1-11）
- `event_registration_scopes`、报名、PII、导出（G2）
- `event_id` 类型统一与外键修复（G2-02）

### 11.4 G1-02 必须新增的测试

- 迁移前后旧列逐字段一致；8 条样例仍隐藏。
- 新列默认值正确；新增对象默认 `draft/normal/0`。
- `row_version` 在更新时递增；并发保存返回 `EVENT_REVISION_CONFLICT`（服务层可先用桩）。
- outbox 与业务写同事务：提交前其他连接不可见，回滚后无记录。
- outbox `dedupe_key` 唯一冲突可重放。
- `GET /api/events` 与 `GET /api/events/{id}` 行为不变（空列表 / 404），不因迁移泄露数据。
- `php -l`、`artisan migrate:status`、独立测试库回归。

### 11.5 G1-02 入口门槛

- 本 ADR 已评审并签署（至少产品、架构、QA）。
- B-01/B-03/B-06 的禁用能力已在配置或服务层有明确开关，不靠“记得别点”。
- 独立测试库可用；备份完成。
- 公开开关保持 `false`；不新增任何公开写入口。

---

## 12. 参考

- [Ecosystem-Events-Content-Plan.md](./Ecosystem-Events-Content-Plan.md)：状态模型、数据模型、接口与内容治理的原始方案。
- [Ecosystem-Engineering-Backlog.md](./Ecosystem-Engineering-Backlog.md)：G1-01 至 G1-11 工作包与 T04/T13–T30 验收矩阵。
- [Ecosystem-Current-Audit.md](./Ecosystem-Current-Audit.md)：当前空数据、样例、ACL 与 PII 风险。
- [Execution-Roadmap.md](./Execution-Roadmap.md)：G0 收口事实、G1/G2 健康门槛。
- 现有代码：`my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EventController.php`、`ApiErrorCode.php`、`my-bagisto-store/config/ecosystem.php`。

---

## 附录 A：G1-01 决策清单（供评审逐条勾选）

| 编号 | 决策 | 冻结日期 | 评审 |
|---|---|---|---|
| G1-D01 | 赛事来源与平台角色，三方不推定 | 2026-10-19 | 待签 |
| G1-D02 | 首期活动类型与共用主表 | 2026-10-19 | 待签 |
| G1-D03 | 发布、举办、报名三轴分离 | 2026-10-19 | 待签 |
| G1-D04 | 时间与容量语义 | 2026-10-19 | 待签 |
| G1-D05 | 主办方与来源核验边界 | 2026-10-19 | 待签 |
| G1-D06 | 独立资讯内容域与 slug/富文本 | 2026-10-19 | 待签 |
| G1-D07 | outbox 事务与消费基础 | 2026-10-19 | 待签 |
| G1-D08 | 公开 DTO、HTTP 语义与错误码 | 2026-10-19 | 待签 |
| G1-D09 | 权限与数据保留 | 2026-10-19 | 待签 |
| B-01 至 B-07 | 阻塞项（见 §9） | 未冻结 | 待确认 |

---

## 附录 B：G1-08A / G1-08B ADR 增量修订（A-01 ~ A-06）

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 授权 | **2026-10-19 用户授权按 G1 真实闭环推进**；本附录为增量留档，不改变 G1-D01 ~ G1-D09 已冻结口径 |
| 范围 | A-01/A-05/A-06 支撑 G1-08A（提交 / 批准 / 拒绝）；A-02/A-03/A-04 的发布语义在 G1-08B（发布 / 排期 / 暂缓 / 恢复 / 撤稿）落地。worker / 公开 API / 前端仍属 G1-09 ~ G1-11 |
| 性质 | 增量修订；与正文冲突时以本附录的**新增字段与已发布对象语义**为准 |

### A-01 `approved_revision_id`

- `events` / `content_posts` 新增 `approved_revision_id BIGINT UNSIGNED NULL`，索引 + 外键指向对应 revision 表，`RESTRICT/RESTRICT`。
- 语义：**当前已批准、尚未成为公开版本的候选 revision**。它不是公开指针，公开读取仍只认 `published_revision_id`。
- `working_revision_id` 继续只指 `draft`；`RevisionPointerValidator` 的 draft 约束不放宽。
- 普通写入口（`EventWriteService` / `ContentPostService`）不得透传；只有审核服务的受控校验可以写入。

### A-02 `scheduled_publish_at`

- `events` / `content_posts` 新增 `scheduled_publish_at DATETIME NULL`（UTC），并增加 `idx(publish_status, scheduled_publish_at)`。
- G1-08A 只建列与索引；G1-08B 起由 `EventPublicationService` / `ContentPublicationService` 写入 UTC 值，`PublicationState` 负责合法状态判断。
- 排期不是 outbox 的 `available_at`；outbox 只记录已发生事实。`schedule` / `unschedule` 不写 outbox，G1-09 扫描 `publish_status=scheduled AND scheduled_publish_at <= UTC_NOW()`。

### A-03 outbox 恢复事件

- `event.restored` / `content.restored` 已在 `config/ecosystem_outbox.php` 登记为正式事件类型；G1-08A 不写任何 outbox 发布/恢复事件。
- 本条已于 G1-08B 落地：`withhold -> restore` 必须写独立的 `*.restored`，不得复用 `*.published`。
- 只有**已发生**的公开事实写 outbox（publish / withhold / restore / retract）；`schedule` / `unschedule` 不写 outbox，未来时间只存父对象 `scheduled_publish_at`。
- payload 只含 `revision_id` / `revision_no` / `row_version` / `fingerprint_version` / `reason_code`；`dedupe_key` 由事件类型 + 聚合 + revision + 变更后的 `row_version` 组成，同一事实重放不新增记录。

### A-04 已发布对象的新版本语义

- 已发布对象（`publish_status=published`）提交/批准新 revision 时，父 `publish_status` **保持 `published`**，`published_revision_id` 不变；候选版本由 `approved_revision_id` 表达。
- 从未发布对象按 `draft → in_review → approved/rejected`。
- 已 `approved` / `scheduled` 对象创建新 working draft 时，批准失效：清 `approved_revision_id`、清 `scheduled_publish_at`，父回 `draft`。
- 已发布对象创建新 working draft 时，`publish_status` 与 `published_revision_id` 不变；但**清空 `approved_revision_id` 与 `scheduled_publish_at`** —— 任何未公开候选被新 draft 取代即失效。
- 已发布对象即使 `working_revision_id` 为空，只要同对象存在 `review_state=in_review` 的 revision，也必须拒绝开始新 draft（不得并行审核）；该探测在父行锁事务内完成（已发布对象提交后 working 指针为空、父状态仍是 `published`，仅凭父行无法发现）。
- 拒绝（reject）不区分父对象是否 `published`：一律清空 `approved_revision_id`（被拒绝的候选不得残留可发布指针），`scheduled_publish_at` 存在也清空，避免旧排期复活；`published_revision_id` 仍不变。

**G1-08B 补充（发布 / 排期 / 处置）**

- 发布只允许 `approved_revision_id` 指向的 `review_state=approved` revision；发布 / 排期 / 恢复必须重新执行全部前置（来源、指纹、媒体 manifest、sanitizer、主办方核验、B 开关），不得复用审批结论。
- 已发布对象发布新候选时，`publish_status` 保持 `published`；切换前旧 `published_revision_id` 继续公开，切换后旧 revision 保留（不删除）但其媒体不再可通过公开门禁读取。
- 排期：从未发布对象 `approved → scheduled`；已发布对象保持 `published`，只写 `approved_revision_id + scheduled_publish_at`。取消排期：`scheduled → approved`，已发布对象保持 `published`；只清时间，不删 revision。
- 暂缓（withhold）：仅当前已发布对象；`disposition=withheld`，公开指针保留，清 `approved_revision_id` 与 `scheduled_publish_at`；原因必填但只进内部日志与稳定审计原因码。
- 恢复（restore）：仅 `withheld → normal`，必须重新通过前置；不得自动恢复。
- 撤稿（retract）：`disposition=retracted` 为终态，清候选与排期、公开指针保留作审计；任何后续 publish / restore / schedule / 新 revision 均拒绝。
- 重复发布同一 revision、重复暂缓、重复撤稿是 no-op：不写 outbox、不推 `row_version`。
- `published_revision_id` 的唯一生产写入口是 `PublicationPointerSwitcher`（Query Builder 原子更新 + `where row_version = ?` CAS）；普通写入口与模型守卫继续拒绝非 NULL 写入。

### A-05 审核 / 发布开关

- 新增 `config/ecosystem_publishing.php`：状态转移表、B-01/B-03/B-04/B-07 开关、审核前置开关、原因码词表。
- `event_publish_enabled` / `content_publish_enabled` / `scheduler_enabled` 默认 **false**；G1-08A 不提供发布/排期能力。
- 审核开关默认允许提交/批准/拒绝演练；任何公开开关保持 false。
- 所有 `in_review` / `approved` / `rejected` 后内容列不可变；审核要改必须拒绝并新建 revision。

### A-06 媒体引用审核时序

- 媒体引用审核（`MediaRefService::reviewRef()`）**只在 draft revision 上执行**，不得为审核方便放开到 `in_review`。
- 工作流固定为：上传媒体 → 媒体批准 → 引用审核（draft 期）→ 提交 revision → 审核 revision → 批准 → 发布。
- 提交 / 批准前置统一校验：每条 ref `review_status=approved`、媒体 `status=approved`、私有盘文件存在；同时重算 `content_fingerprint` 与 `media_manifest_hash`，并校验 `sanitizer_version` 等于当前配置版本。任一不一致 fail-closed。
- 前置校验不得生成公开 URL，不得读取公开盘。

---

## 附录 C：G1-09 落地记录（2026-10-19）

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | outbox worker（领取 / 租约 / 结算 / 重试 / dead / 过期恢复）、到期发布扫描、公开读取 kill switch、调度注册 |
| 性质 | 落地记录；**不改变** G1-D01 ~ G1-D09 与附录 B A-01 ~ A-06 的冻结口径 |
| 数据库 | 独立测试库 `summon_mecha_step10_test`；未新增迁移、未触碰 root 主库 |

### C-01 outbox worker

- `config/ecosystem_outbox.php` 新增：`worker_enabled=false`、`lease_seconds=300`、`batch_size=20`（代码硬上限 20）、`max_attempts=5`、`retry_backoff_seconds=[30,120,600,1800,3600]`。
- 领取：短事务 `FOR UPDATE` 选中 `status=pending AND available_at <= UTC_NOW()` 的批次，逐行更新为 `processing`，写入每次唯一的 `lease_owner=host:pid:uuid`、`lease_until=now+TTL`、`attempts+1`，事务提交后才执行 handler。**不使用** MySQL 8 的 `SKIP LOCKED`。
- 结算 / 失败回报：条件更新必须同时匹配 `id + status=processing + lease_owner + lease_until >= now`；影响 0 行抛 `OutboxLeaseLostException`，旧 worker 静默丢弃结果（只记 warning），不写 `sent` / `dead` / `pending`。
- 过期恢复：`processing AND (lease_until < now OR NULL)` → `pending`，清租约，`last_error=lease_expired`，**不**重复增加 attempts。
- 重试：`QueryException` 视为瞬时错误，回 `pending` 并按 attempts 退避；达到 `max_attempts` → `dead`。未知 event_type / aggregate_type、payload 非法、父对象不存在、状态不符、`row_version` 被取代一律 `dead`，不无限重试。
- handler 只做事实核验与消费记账：`sent` **不代表**已向用户发送任何通知。G1 未接通知渠道。

### C-02 superseded 语义

- 父对象 `row_version` 与 outbox payload 不一致时标记 `dead`，`last_error` 以 `superseded:` 开头。
- 旧事实不得投递给 G2 消费者；不产生第二条公开事实。

### C-03 到期发布扫描

- 只处理 `scheduled_publish_at <= UTC_NOW()`、`disposition=normal`、`publish_status ∈ {scheduled,published}` 的父对象，稳定按 `scheduled_publish_at, id` 排序。
- 域发布开关为 false（内容域还要求 B-04 / B-07）时**整域跳过**：运营关开关不是“该排期失败”，不得回退。
- 业务前置 / 状态机 / 版本冲突 → 不可重试：在事务内安全回退：
  - 从未发布（`published_revision_id IS NULL`）→ `approved`，清 `scheduled_publish_at`，保留 `approved_revision_id`；
  - 已发布 → 保持 `published` 与旧公开指针，清 `scheduled_publish_at` 与 `approved_revision_id`；
  - 写 `ecosystem.{event|content}.schedule_aborted` 审计（`actor_type=system`，`reason_code=schedule_aborted`），并发 `AdminAlertService` 告警（dedupe key 含域 + ID + UTC 日期）。
- `withheld` / `retracted` 永不自动复活：查询先按 `disposition=normal` 过滤，回退事务再校验一次。
- 瞬时 `QueryException` → 行保持 `scheduled`，下一轮重试；未预期异常同样保持 `scheduled` 并告警，不伪装成功。

### C-04 公开读取 kill switch

- 新增 `config/ecosystem_public_read.php`：`emergency_disabled=false`（env `ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED`）。
- `PublicReadGate::assertAvailable()` 只读配置、不查业务表；命中抛 `PublicReadUnavailableException`（503 / `SERVICE_UNAVAILABLE`）。
- `MediaAccessService::resolvePublic()` 在域开关与任何查询之前调用 gate：kill switch 命中时为 503，而不是 404 / 空列表。
- 公开列表 / 详情 / 媒体的 controller 映射属 G1-10；本批只提供服务层语义与异常。

### C-05 调度注册

- `bootstrap/app.php` 注册两条 `everyMinute + withoutOverlapping`：
  - `ecosystem:process-outbox` 受 `ecosystem_outbox.worker_enabled` 控制（默认 false）；
  - `ecosystem:publish-scheduled` 受 `ecosystem_publishing.scheduler_enabled` 控制（默认 false）。
- **仅注册不等于已运行**：没有 `schedule:work` / crontab 时两条都不会执行；本批不声称常驻进程已启动。

### C-06 命令

- `php artisan ecosystem:process-outbox [--limit=] [--dry-run]`：一轮有界批次；dry-run 只读统计。
- `php artisan ecosystem:publish-scheduled [--limit=50] [--dry-run]`：一轮有界扫描；瞬时/未预期错误返回非零退出码。
- 过期租约恢复内置于 `ecosystem:process-outbox` 每轮开头，不单设 `ecosystem:recover-outbox`。
