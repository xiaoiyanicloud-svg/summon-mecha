# G1-08 ~ G1-11 实施规划审计

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | G1-08A 提审/批准/拒绝；G1-08B 发布/排期/暂缓/恢复/撤稿；G1-09 outbox worker / 租约 / 恢复 / 扫描 / 公开读取 kill switch；G1-10 公开 Event/Content/Media DTO 与 API；G1-11 H5/小程序前端 |
| 依据 | `ADR-0001-trusted-publishing.md`、`Ecosystem-Engineering-Backlog.md`、`Ecosystem-Events-Content-Plan.md`、`Execution-Roadmap.md`、G1-02 ~ G1-06/07 evidence |
| 性质 | **只读规划审计**。本文不修改业务代码、不执行迁移、不写任何数据库、不打开任何开关。 |
| 结论 | G1-02 ~ G1-07 已完成“表 + 草稿服务 + 媒体/净化”，但**审核/发布状态机、管理端操作面、worker、公开 API、前端页面均不存在**。G1-08 开工前必须先完成一次 ADR 增量修订（2 个持久字段 + 2 个 outbox 事件类型 + 已发布对象的状态语义澄清），否则无法安全绑定“已批准版本”和“排期时间”。 |

---

## 0. 结论先行

1. **现有代码已经具备**：发布字段、双 revision 指针、内容指纹、媒体 manifest、富文本净化、来源/主办方校验、outbox 写入器。它们都是“草稿域 + 校验域”的能力。
2. **现有代码尚不具备**：
   - 任何 `draft → in_review → approved → published` 状态转移；
   - “已批准版本”的持久指针；
   - “排期时间”的持久事实；
   - 发布/暂缓/撤稿与 outbox 的同事务写入；
   - outbox 消费者与租约；
   - 公开内容 API、公开媒体路由、公开 DTO；
   - 前端活动/资讯独立页面。
3. **当前最危险的事实**：`EventController` 在 `public_enabled=true` 时会返回**全部** `events`（包括 draft），并输出未净化的旧 `detail`。因此 **G1-10 完成前绝不能把 `ECOSYSTEM_EVENT_PUBLIC_ENABLED` 改为 true**。这不是“页面没做完”，而是会直接泄露草稿与未净化 HTML。
4. **G1-08 的两个硬前提必须先报批**：
   - 新增 `approved_revision_id`（活动/文章）作为“当前已批准候选版本”的持久事实；
   - 新增 `scheduled_publish_at`（活动/文章）作为“排期发布”的持久事实。
   现有 ADR 字段字典没有这两个字段；不加就无法在已发布对象上安全地表达“旧版本仍公开、新版本已批准待切换”。
5. **本机可验证**：独立测试库 `summon_mecha_step10_test` 上的迁移、服务、命令单轮执行、HTTP Feature 测试、前端 Node 测试、H5 临时预览。
6. **本机不能证明**：root/生产迁移、常驻 scheduler/cron、真实多进程 worker、生产 MySQL DDL 锁影响、真实运营角色与法务签署、真实小程序分享。所有这些必须单列“未验证/待生产”。

---

## 1. 现状事实与关键缺口（按代码证据）

### 1.1 已落地的 schema 与服务

| 对象 | 现状 | 证据 |
|---|---|---|
| `events` | 已加 G1-02 发布字段与 G1-03 来源字段；`publish_status` 默认 `draft`、`disposition` 默认 `normal`、`row_version` 默认 0；双 revision 指针 FK 已建 | `Shop/src/Database/Migrations/2026_10_19_000001_add_g1_publishing_fields_to_events.php`、`000004_add_source_fields_to_events.php`、`000007_add_revision_pointers_fk_to_events.php` |
| `event_revisions` | 全字段、唯一键 `(event_id, revision_no)`、内容指纹、审核字段、媒体 manifest、sanitizer 版本 | `000005_create_event_revisions_table.php`、`Models/EventRevision.php` |
| `event_rule_versions` | 已建，规则文件 FK 已建 | `000006`、`000014` |
| `content_posts` / `content_revisions` / `content_sources` | 已建；slug 唯一；双指针 FK；来源权利依据 | `000008` ~ `000011`、`Models/ContentPost.php`、`Models/ContentRevision.php` |
| `ecosystem_media` / `ecosystem_media_refs` | 已建；私有盘、sha256 唯一、媒体/引用双审核轴 | `000012`、`000013`、`Services/Ecosystem/MediaService.php`、`MediaRefService.php` |
| outbox | 表已建；写入器已有事务、白名单、PII 守卫、dedupe 重放 | `000002_create_ecosystem_outbox_table.php`、`Services/Ecosystem/OutboxWriter.php`、`config/ecosystem_outbox.php` |
| 草稿服务 | 活动/内容 revision 草稿创建与编辑、指纹 CAS、不可变守卫 | `EventRevisionService.php`、`ContentRevisionService.php`、`ContentPostService.php` |
| 来源/主办方校验 | `assertDraftAssignment()` / `assertPublishable()`；B-01/B-03 默认只放行 `platform + organizer` | `Services/Ecosystem/EventSourceValidator.php:122-157`、`config/ecosystem_organizers.php:45-53` |
| 富文本 | HTMLPurifier 白名单；`img` 只保留 `data-media-id` | `Services/Ecosystem/RichTextSanitizer.php`、`config/ecosystem_rich_text.php` |
| 公开媒体门禁 | 服务层已具备（域开关 + 父对象 + 当前 published revision + ref/media 审核 + 文件存在） | `Services/Ecosystem/MediaAccessService.php:38-88` |

### 1.2 状态机缺口

| 缺口 | 代码事实 | 后果 |
|---|---|---|
| 没有任何提交/批准/拒绝服务 | `EventRevisionService` / `ContentRevisionService` 只写 `draft` | `review_state` 永远停在 `draft`；`submitted_*` / `reviewed_*` 无入口 |
| 已发布对象无法挂新的 working revision | `RevisionPointerValidator::assertAssignable()` 在 `published_revision_id !== null` 时**任何**写入都抛异常（`RevisionPointerValidator.php:24-31`） | 发布后想编辑会直接失败，无法“旧版本继续公开、新版本编辑中” |
| 内容已发布后无法新建工作修订 | `ContentPostService::updateDraft()` 只允许 `publish_status=draft`（`ContentPostService.php:117-123`） | 已发布文章无法进入工作修订流程 |
| `ContentPost` 模型级封死 published 指针 | `Models/ContentPost.php:54-99` 的 `creating/updating` 守卫 | G1-08 发布服务必须有一条受控的指针切换路径，否则无法发布 |
| 无“已批准候选版本”字段 | `events` / `content_posts` 只有 published / working 两个指针 | 批准后无法记住“哪一条 revision 被批准”；多次 revision 后只能猜 |
| 无“排期时间”字段 | 两张父表都没有 `scheduled_*` | `approved → scheduled` 无法持久化；worker 无法扫描 |
| `publish_status` 对已发布对象语义未澄清 | ADR §5.1 写了 `published` + 新 working revision，但没有说明父状态是否变化 | 若发布后提交新版本就改成 `in_review`，公开列表会突然看不到旧版本；这是错误实现 |

### 1.3 管理端缺口

| 缺口 | 代码事实 | 后果 |
|---|---|---|
| Admin 只有旧赛事 CRUD | `Admin/Http/Controllers/Catalog/EventController.php` 只处理 `name/type/status/date/...` 旧字段 | 无法编辑 revision、来源、时间、媒体、审核意见 |
| 没有主办方管理 UI | 只有 `OrganizerService`，无 Admin Controller/Route/View | 无法在界面上创建并核验 `ecosystem_organizers`；发布前置无法满足 |
| 没有媒体上传/审核 UI | 只有 `MediaService` / `MediaRefService` | 无法在界面上上传、批准媒体或审核媒体引用；提交前置无法满足 |
| 没有 G1 ACL / 菜单 / 路由 | `Admin/Config/acl.php` 只有 `catalog.events.*`；`catalog-routes.php` 只有 7 个旧动作 | 新增路由若未登记权限，按 ADR 必须默认拒绝并让测试失败 |
| 旧 `catalog.events` 权限仍只覆盖旧页 | `acl.php:213-241` | 不得把旧权限复用于 G1 提交/审核/发布 |

### 1.4 公开 API 与前端缺口

| 缺口 | 代码事实 | 后果 |
|---|---|---|
| `GET /api/events` 仍是旧 DTO | `Shop/src/Http/Controllers/API/EventController.php:23-32,117-152`；`Routes/api.php:127-130` | 打开开关会返回全部 events（含草稿）与未净化 `detail` |
| `GET /api/events/{id}` 仍是旧 DTO | 同上 | 打开开关后草稿 ID 可枚举 |
| 没有内容公开 API | 无 `ContentController`、无路由 | 资讯域不可读 |
| 没有公开媒体 HTTP 路由 | `MediaAccessService` 只有服务层 | 前端无法加载正文图片/封面 |
| 没有 Event/Content/Media Resource | 无 `Http/Resources/Ecosystem/`（项目也尚未建立该目录） | 无法保证公开 DTO 白名单 |
| 前端只有首页内嵌 tab | `components/EcoCenter.vue:50-152,407-460` | 没有独立列表/详情，没有分页筛选 |
| 资讯组件是空态 | `components/shouye/dongtai.vue:1-37` | 没有列表/详情/来源/分享 |
| 前端合同仍是旧字段 | `utils/ecosystem-events.js:1-40`、`utils/api.js:229-235` | 与新 DTO 不兼容 |
| 没有 event/content 页面 | `pages.json` 无相关 path | 无法直达、刷新、分享 |

### 1.5 运行态与开关缺口

| 缺口 | 代码事实 | 后果 |
|---|---|---|
| 没有 outbox worker | `Shop/src/Console/Commands/` 只有技能相关命令；`Shop/src/Jobs/` 只有 `RetrySkillDelivery` | outbox 只写不消费 |
| 没有 ecosystem 定时任务 | `bootstrap/app.php:53-135` 的 `withSchedule` 无 ecosystem 条目 | 排期发布、outbox 扫描、租约恢复都不会发生 |
| 没有公开读取 kill switch | 只有 `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled` | 紧急停读只能改域开关，且当前关闭时语义是“空列表/404”，不是 503 |
| 没有发布审计 | `Services/Ops/OperationLogger.php` 存在，但 ecosystem 服务未使用 | 谁提交/批准/发布/撤稿无强制留痕 |
| root 主库未迁移 | G1-02 ~ G1-07 evidence 均记录 root 无新表/新列 | G1-08+ 代码在 root 上无法运行；生产上线必须先做完整迁移演练 |
| `operation_log.target_id` 是 32 位 | `2026_10_14_000001_create_ops_safety_tables.php:32-40` | 与 `events.id` / `content_posts.id` 的 BIGINT 不一致；G1 若用它，需要明确边界 |

### 1.6 现有测试基线

- G1-06/07 验收：22 个指定测试文件 **258 passed / 2206 assertions**。
- 现有测试全部指向独立库 `summon_mecha_step10_test`，`beforeEach` 会拒绝其他库。
- 全量 pest 有 60 秒进程级超时问题，必须按文件分进程跑（见项目记忆与既有 evidence）。
- 与 G1-08+ 直接相关的现有测试文件：
  - `Shop/tests/Feature/Ecosystem/EcosystemEventRevisionServiceG1Test.php`
  - `EcosystemContentRevisionServiceG1Test.php`
  - `EcosystemRevisionPointerG1Test.php`
  - `EcosystemOutboxG1Test.php`
  - `EcosystemMediaAccessG1Test.php`（文件名以实际目录为准，G1-06/07 子组）
  - `Admin/tests/Feature/Catalog/EventAdminAclG0Test.php` / `EventFormG0Test.php` / `EventRegistrationPiiG0Test.php`

### 1.7 关键缺口清单（供后续批次逐条关闭）

| 编号 | 缺口 | 关闭批次 |
|---|---|---|
| GAP-01 | 无 `approved_revision_id`，批准版本无法持久绑定 | G1-08A（ADR 修订） |
| GAP-02 | 无 `scheduled_publish_at`，排期无持久事实 | G1-08B（ADR 修订） |
| GAP-03 | 已发布活动无法挂新 working revision | G1-08B |
| GAP-04 | 已发布文章无法开始新 revision；模型封死 published 指针 | G1-08B |
| GAP-05 | 媒体引用只能在 draft 审核；提交前置与审核流程未定义 | G1-08A |
| GAP-06 | 无 Admin 修订/审核/媒体/主办方 UI、路由、ACL、菜单 | G1-08A |
| GAP-07 | 无提交/批准/拒绝服务 | G1-08A |
| GAP-08 | 无发布/排期/暂缓/恢复/撤稿服务与 outbox 写入 | G1-08B |
| GAP-09 | 无 worker、租约、恢复扫描、排期扫描、kill switch | G1-09 |
| GAP-10 | 无公开 Event/Content/Media Resource 与路由 | G1-10 |
| GAP-11 | 无公开读取的 404/410 统一语义 | G1-10 |
| GAP-12 | 无前端列表/详情/筛选/分页/分享/富文本渲染 | G1-11 |
| GAP-13 | B-02 媒体权利没有字段承载 | 记录风险；G1 用运营政策，后续 ADR 修订 |
| GAP-14 | 提审前置不完整（来源核验时间、授权依据、来源条数、sanitizer 版本） | G1-08A/08B |
| GAP-15 | `operation_log.target_id` 32 位与 BIGINT 不一致 | G1-08A 记录；G1 用 payload 承载 revision，必要时单独报批扩列 |

---

## 2. 批次拆分总览

| 批次 | 目标 | 主要依赖 | 不包含 |
|---|---|---|---|
| **G1-08A** | 草稿 → 提审 → 批准/拒绝；媒体/主办方最小管理面；审批绑定指纹与媒体 manifest | G1-04 ~ G1-07 | 发布指针切换、排期、公开 API、前端 |
| **G1-08B** | 已批准版本 → 立即发布/排期/暂缓/恢复/撤稿；事务 outbox；已发布对象继续编辑 | G1-08A | worker 消费、公开 API、前端 |
| **G1-09** | outbox worker、租约、恢复扫描、排期发布扫描、公开读取 kill switch | G1-08B | 通知扇出、报名事件、公开 DTO |
| **G1-10** | 公开 Event/Content/Media DTO、列表/详情、分页筛选、404/410、媒体流式输出 | G1-08B、G1-09（kill switch） | SSR/OG/sitemap、搜索、收藏、报名 |
| **G1-11** | H5/小程序列表/详情、分页筛选、分享、富文本安全渲染 | G1-10 | SSR/OG、搜索、收藏、报名 |

建议把 **G1-08A 与 G1-08B 分两次提交**；G1-09 与 G1-10 的契约可以并行设计，但 G1-10 的公开读取必须复用 G1-09 的 kill switch，不能各自实现一套。

---

## 3. G1-08A：提审 / 批准 / 拒绝

### 3.1 目标

把现有“只写草稿”的服务接成最小审核闭环：

```
draft --submit--> in_review --approve--> approved
                         \--reject-----> rejected（父对象回 draft；本 revision 不重开）
```

并在批准时把 `content_fingerprint`、`media_manifest_hash`、来源快照、sanitizer 版本固定为审批事实。

### 3.2 写作用域（文件清单）

> 所有路径相对 `my-bagisto-store/`。

**新增迁移**

| 文件 | 内容 |
|---|---|
| `packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000015_add_approved_revision_pointers.php` | `events.approved_revision_id`、`content_posts.approved_revision_id`：`BIGINT UNSIGNED NULL`、索引、FK → revisions（RESTRICT/RESTRICT） |
| `packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000016_add_scheduled_publish_at.php`（可放 08B，但建议与 08A 一起评审） | `events.scheduled_publish_at`、`content_posts.scheduled_publish_at`：`DATETIME NULL` + `idx(publish_status, scheduled_publish_at)` |

**新增服务**

| 文件 | 内容 |
|---|---|
| `Shop/src/Services/Ecosystem/PublicationPreconditions.php` | 提交/批准/发布共用的前置校验：revision 完整、来源、时间、sanitizer、媒体 refs、内容/媒体指纹、slug（内容） |
| `Shop/src/Services/Ecosystem/EventReviewService.php` | `submit()` / `approve()` / `reject()`；父对象行锁 + revision 行锁；`expected_row_version` CAS |
| `Shop/src/Services/Ecosystem/ContentReviewService.php` | 同上，内容域 |
| `Shop/src/Services/Ecosystem/PublicationAudit.php` | 用 `OperationLogger::logOrFail()` 写 `ecosystem.event.submit/approve/reject`、`ecosystem.content.*`；payload 含 `revision_id`、`revision_no`、`from`、`to`、`fingerprint_version`、`reason_code` |
| `Shop/src/Services/Ecosystem/PublicationState.php` | 状态转移表与合法性判断的单一入口（配置驱动） |

**修改**

| 文件 | 改动 |
|---|---|
| `Models/Event.php` | `approved_revision_id` fillable/cast/关系 |
| `Models/ContentPost.php` | `approved_revision_id` fillable/cast/关系；**保留** published 指针守卫，G1-08B 再开受控入口 |
| `Services/Ecosystem/EventWriteService.php` | 白名单加入 `approved_revision_id`；校验必须同活动且 `review_state=approved`；`scheduled_publish_at` 不在本服务 |
| `Services/Ecosystem/ContentPostService.php` | 同上；或新增 `assignApprovedRevision()` 方法 |
| `Services/Ecosystem/RevisionPointerValidator.php` | 新增 `assertApprovedAssignable()`；**本批不改 published 指针规则** |
| `Services/Ecosystem/ContentFingerprint.php` | 若新增字段参与指纹，必须递增 `fingerprint_version`；建议 `approved_revision_id` **不参与** revision 指纹 |
| `config/ecosystem_revisions.php` | 新增 `publishing` 状态转移/审核前置配置（或新建 `config/ecosystem_publishing.php`） |
| `Http/Controllers/API/ApiErrorCode.php` | 如缺 `CONTENT_REVISION_CONFLICT` 等，确认已登记；不新增裸字面量 |

**Admin 新增**

| 文件 | 内容 |
|---|---|
| `Admin/src/Http/Controllers/Catalog/Ecosystem/EventWorkflowController.php` | 草稿编辑、revision 列表、提交、审核队列、批准/拒绝、预览 |
| `Admin/src/Http/Controllers/Catalog/Ecosystem/ContentWorkflowController.php` | 同上 |
| `Admin/src/Http/Controllers/Catalog/Ecosystem/OrganizerController.php` | 主办方列表/新建/编辑/核验/停用（最小可用） |
| `Admin/src/Http/Controllers/Catalog/Ecosystem/MediaController.php` | 上传、列表、批准/拒绝/隔离、引用审核 |
| `Admin/src/Resources/views/catalog/ecosystem/events/*.blade.php` | 草稿表单、revision 列表、审核页、预览页 |
| `Admin/src/Resources/views/catalog/ecosystem/content/*.blade.php` | 同上 |
| `Admin/src/Resources/views/catalog/ecosystem/organizers/*.blade.php` | 主办方 |
| `Admin/src/Resources/views/catalog/ecosystem/media/*.blade.php` | 媒体 |
| `Admin/src/Routes/catalog-routes.php` | 在 `catalog` 前缀下新增 `ecosystem/...` 路由；路由名 `admin.catalog.ecosystem.*` |
| `Admin/src/Config/acl.php` | 新增 `ecosystem.events.*`、`ecosystem.content.*`、`ecosystem.media.*`、`ecosystem.organizers.*` 映射 |
| `Admin/src/Config/menu.php` | 新增“生态发布”菜单（或挂在赛事管理下），菜单 key 与 ACL 一致 |

**测试新增**

| 文件 | 覆盖 |
|---|---|
| `Shop/tests/Feature/Ecosystem/EcosystemEventReviewG1Test.php` | 提交/批准/拒绝、父状态、revision 状态、并发 CAS |
| `Shop/tests/Feature/Ecosystem/EcosystemContentReviewG1Test.php` | 同上 + slug/来源 |
| `Shop/tests/Feature/Ecosystem/EcosystemPublicationPreconditionsG1Test.php` | 指纹/manifest/媒体 refs/sanitizer/来源/时间 |
| `Shop/tests/Feature/Ecosystem/EcosystemPublicationAuditG1Test.php` | 审计同事务、失败回滚、无 PII |
| `Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php` | 每个新路由逐动作权限；未映射路由默认拒绝 |
| `Admin/tests/Feature/Catalog/EcosystemOrganizerConsoleG1Test.php` | 主办方核验 UI/路由 |
| `Admin/tests/Feature/Catalog/EcosystemMediaConsoleG1Test.php` | 上传/批准/引用审核 UI/路由 |

### 3.3 依赖顺序

1. ADR 增量修订批准（`approved_revision_id`）。
2. 迁移 000015；在 `summon_mecha_step10_test` 上回滚/重跑。
3. `PublicationPreconditions` → `EventReviewService` / `ContentReviewService`。
4. Admin 主办方 → 媒体 → 审核工作台。
5. ACL/菜单/路由登记 + 默认拒绝测试。
6. 全量回归（G1-02 ~ G1-07 测试不能退化）。

### 3.4 不可做边界

- 不写 `published_revision_id`（发布属于 G1-08B）。
- 不创建 `scheduled_publish_at` 写入（排期属于 G1-08B）。
- 不写 outbox 发布事件（提交/批准不是公开事实；ADR 只要求发布/暂缓/撤稿写 outbox）。
- 不改公开 API；不打开任何开关。
- 不允许在 `in_review` 之后修改 revision 内容或媒体引用。若审核要改，必须拒绝并新建 revision。
- 不允许用 `catalog.events` 旧权限授权 G1 动作。
- 不新增报名/PII/导出权限。

### 3.5 验收测试清单

**服务层**

- [ ] `draft → in_review` 只允许提交**指定 revision**；提交后 `review_state=in_review`，内容列不可改。
- [ ] 提交前置：缺 `source_name` / `source_verified_at` / `authorization_basis` / `organizer_name_snapshot` 时拒绝（活动）。
- [ ] 提交前置：内容 revision 少于 1 条来源、`original` 缺 canonical URL、`authorized_repost` 缺 `rights_scope`/`evidence_ref`、`link_summary` 正文非空时拒绝。
- [ ] 提交前置：媒体引用全部 `review_status=approved` 且媒体 `status=approved`；否则拒绝。
- [ ] 提交前置：`sanitizer_version` 必须等于当前配置版本；否则拒绝（fail-closed）。
- [ ] 提交前置：活动 `kind` / `timezone` / `source_type` / `platform_role` / `organizer_id` / `hosting_status` 完整；平台活动必须有起止时间。
- [ ] 提交前置：B-01/B-03 未签署时，非 `platform + organizer` 组合在提交阶段即拒绝（`EVENT_ORGANIZER_UNVERIFIED`），而不是等到发布。
- [ ] 批准时重算 `content_fingerprint` 与 `media_manifest_hash`，任一与库内不一致 → `EVENT_REVISION_CONFLICT` / `CONTENT_REVISION_CONFLICT`。
- [ ] 批准绑定 `reviewed_by` / `reviewed_at` / `review_note`；拒绝必须有非空内部原因。
- [ ] 拒绝后原 revision `review_state=rejected`，不可重开；父对象回到 `draft`（从未发布对象）。
- [ ] 已发布对象提交/批准新 revision 时，父 `publish_status` **保持 `published`**；公开指针不变。
- [ ] 已发布对象存在 `review_state=in_review` 的 revision（`working_revision_id` 为空）时，拒绝开始新 draft。
- [ ] 已发布对象开始新 draft：`publish_status` / `published_revision_id` 不变，`approved_revision_id` / `scheduled_publish_at` 清空。
- [ ] 已发布对象拒绝新 revision：`published_revision_id` 不变，`approved_revision_id` / `scheduled_publish_at` 清空。
- [ ] 已批准/已排期对象创建新 working revision 时，原批准失效：清空 `approved_revision_id`、`scheduled_publish_at`，父回 `draft`。
- [ ] 两人并发提交/批准：`expected_row_version` 不符返回 409，不静默覆盖。
- [ ] 审计与状态写同事务：强制审计失败时整个动作回滚（`logOrFail`）。
- [ ] 审计 payload 不含 PII；只含 ID/版本/原因码/指纹版本。

**HTTP/Admin**

- [ ] 每个新路由都有 ACL 映射；未映射路由测试失败。
- [ ] 无权限 custom 角色直接 URL/Ajax 被拒（不是只隐藏菜单）。
- [ ] 旧 `catalog.events` 权限不能提交/批准/拒绝。
- [ ] 预览只允许有 `ecosystem.events.view` / `ecosystem.content.view` 的后台用户；响应 `Cache-Control: no-store`、`X-Robots-Tag: noindex`。
- [ ] 预览媒体只来自目标 revision，未审核/隔离媒体拒绝；不出现 `storage_key`。

### 3.6 回滚方式

1. 先关管理端配置/路由（或回滚代码），确保没有新的提交/批准。
2. 回滚 Admin/Service 代码（显式文件清单，禁止 `git add -A`）。
3. 在独立库执行：
   ```bash
   cd D:/main/my-bagisto-store
   DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
     --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000015_add_approved_revision_pointers.php --force
   ```
4. 生产回滚前必须导出 `approved_revision_id` 非空的记录与 `operation_log` 审计；MySQL 5.7 DDL 不能事务回滚，必须先备份。
5. 不删除 `event_revisions` / `content_revisions` / 审计记录。

---

## 4. G1-08B：发布 / 排期 / 暂缓 / 恢复 / 撤稿与事务 outbox

### 4.1 目标

把“已批准版本”切换为公开版本，并把所有公开事实写入 outbox：

```
approved --publish--> published
approved --schedule--> scheduled --worker--> published
scheduled --unschedule--> approved
published --withhold--> published + withheld
withheld --restore--> published + normal
any --retract--> disposition=retracted（终态）
```

### 4.2 写作用域（文件清单）

**新增迁移**

| 文件 | 内容 |
|---|---|
| `.../2026_10_19_000016_add_scheduled_publish_at.php` | 若 08A 未建；`scheduled_publish_at` + 索引 |
| 可选 `.../2026_10_19_000017_add_publication_audit_indexes.php` | 仅在确认需要按 revision 查询审计时新增；默认不建第二张审计表 |

**新增配置**

| 文件 | 内容 |
|---|---|
| `config/ecosystem_publishing.php` | 状态转移表、发布前置、B-01/B-03/B-04/B-06 开关、`event_publish_enabled` / `content_publish_enabled`、`scheduler_enabled`、原因码词表 |
| `config/ecosystem_outbox.php`（修改） | 增加 `event.restored`、`content.restored`；增加 `worker_enabled`、`batch_size`、`lease_seconds`、`max_attempts`、`backoff_seconds`、`handler` 映射 |

**新增服务**

| 文件 | 内容 |
|---|---|
| `Shop/src/Services/Ecosystem/EventPublicationService.php` | `publishNow()`、`schedule()`、`unschedule()`、`withhold()`、`restore()`、`retract()`、`publishDue()`（系统） |
| `Shop/src/Services/Ecosystem/ContentPublicationService.php` | 同上 |
| `Shop/src/Services/Ecosystem/PublicationPointerSwitcher.php` | 受控切换 `published_revision_id` / `approved_revision_id` / `scheduled_publish_at`；唯一允许绕过模型/指针守卫的入口 |
| `Shop/src/Services/Ecosystem/PublicationOutbox.php` | 计算 dedupe key、构造白名单 payload、调用 `OutboxWriter`（不自开事务） |
| `Shop/src/Services/Ecosystem/PublishedRevisionGuard.php` | 发布前重检：当前 `approved_revision_id`、`review_state`、指纹、manifest、来源、媒体、sanitizer、B 开关 |
| `Shop/src/Services/Ecosystem/BeginRevisionService.php` | 已发布/已批准对象开始新 working revision 的协调器：创建 revision + 挂 working 指针 + 失效旧批准（同事务） |

**修改**

| 文件 | 改动 |
|---|---|
| `Services/Ecosystem/RevisionPointerValidator.php` | 拆分：published 指针只在 `PublicationPointerSwitcher` 上下文允许；已发布对象允许改 working 指针 |
| `Models/ContentPost.php` | published 指针守卫改为“普通 save 仍拒绝；仅 `PublicationPointerSwitcher` 受控写入” |
| `Models/Event.php` | 校验 `published_revision_id` / `approved_revision_id` 同活动；普通入口不直接写 |
| `Services/Ecosystem/EventWriteService.php` | 不再允许通过普通入口改 published 指针；working 指针在已发布对象上允许 |
| `Services/Ecosystem/ContentPostService.php` | 新增 `beginWorkingRevision()` / `assignApprovedRevision()`；已发布对象不再被 `draft-only` 一刀切拒绝 |
| `Services/Ecosystem/EventRevisionService.php` | `createDraft()` 可被 `BeginRevisionService` 协调；保持“内容不可变”不变 |
| `Services/Ecosystem/ContentRevisionService.php` | 同上 |
| `config/ecosystem_content.php` | 增加 `publishing_enabled` / `publish_allowed` / 原因码；不把 `public_enabled` 与发布开关混用 |

**Admin 修改/新增**

| 文件 | 内容 |
|---|---|
| `Admin/.../EventWorkflowController.php` | 发布、排期、取消排期、暂缓、恢复、撤稿动作 |
| `Admin/.../ContentWorkflowController.php` | 同上 |
| `Admin/.../views/.../publish*.blade.php` | 发布/排期/处置表单；原因必填、二次确认 |
| `Admin/src/Routes/catalog-routes.php` | 新增动作路由 |
| `Admin/src/Config/acl.php` | `ecosystem.events.schedule/publish/withhold/retract`；`ecosystem.content.*` 同 |
| `Admin/src/Config/menu.php` | 如需要，发布台/处置台入口 |

**测试新增**

| 文件 | 覆盖 |
|---|---|
| `EcosystemEventPublicationG1Test.php` | 立即发布、指针切换、父状态、outbox 同事务 |
| `EcosystemContentPublicationG1Test.php` | 同上 + 首发时间、slug |
| `EcosystemPublicationScheduleG1Test.php` | 排期、取消排期、已发布对象排期不隐藏旧版本 |
| `EcosystemPublicationDispositionG1Test.php` | 暂缓/恢复/撤稿终态、410 语义前置（服务层） |
| `EcosystemBeginRevisionG1Test.php` | 已发布/已批准对象开始新 revision；旧批准失效 |
| `EcosystemPublicationRollbackG1Test.php` | outbox 与业务写回滚；无悬挂记录 |
| `Admin/tests/Feature/Catalog/EcosystemPublishAclG1Test.php` | 发布/排期/处置逐动作授权 |

### 4.3 依赖顺序

1. ADR 增量修订批准（`scheduled_publish_at`、`restored` 事件、已发布对象状态语义）。
2. 迁移 000016；回滚/重跑。
3. `PublicationPointerSwitcher` + `PublishedRevisionGuard`。
4. `EventPublicationService` / `ContentPublicationService` + `PublicationOutbox`。
5. `BeginRevisionService` 接管“发布后编辑”。
6. Admin 动作/表单/ACL。
7. 回归 + 回滚演练。

### 4.4 不可做边界

- 不实现 worker：排期只写 `scheduled` 事实，由 G1-09 扫描。
- 不实现公开 API；不打开公开开关。
- 不发送通知；不写报名事件。
- 不允许 `retracted` 被普通编辑、重试、定时任务、数据修复脚本恢复。
- 不允许 `withhold` 自动恢复；恢复必须重新走复核。
- 不允许删除 revision / 父对象；撤稿只改 disposition。
- B-06 未签署时，`postponed` / `rescheduled` / `canceled` 写能力保持关闭；只允许 `scheduled → completed`。
- B-01/B-03 未签署时，只允许 `platform + organizer` 进入发布；外部/联合主办/授权承办在提交阶段已拒绝。
- B-04/B-07 未签署时，内容 `publish` / `schedule` 保持关闭；可到 `approved` 演练。

### 4.5 状态机与指针切换（本批核心）

**父对象 `publish_status` 语义（需写入 ADR 修订）**

| 父对象当前 | 动作 | 父 `publish_status` | 指针/时间 | revision 状态 |
|---|---|---|---|---|
| 从未发布 `draft` | submit | `in_review` | `working_revision_id` 保持 | `in_review` |
| 从未发布 `in_review` | approve | `approved` | `approved_revision_id=rev` | `approved` |
| 从未发布 `in_review` | reject | `draft` | `approved_revision_id=NULL` | `rejected` |
| 从未发布 `approved` | schedule | `scheduled` | `approved_revision_id=rev`，`scheduled_publish_at=future` | `approved` |
| 从未发布 `scheduled` | unschedule | `approved` | 清 `scheduled_publish_at` | `approved` |
| 从未发布 `approved/scheduled` | publish | `published` | `published_revision_id=rev`，`published_at=now`，`first_published_at ??= now` | `approved` |
| 已发布 `published` | submit 新 revision | **保持 `published`** | `working_revision_id=new` | `in_review` |
| 已发布 `published` | approve 新 revision | **保持 `published`** | `approved_revision_id=new` | `approved` |
| 已发布 `published` | schedule 新 revision | **保持 `published`** | `approved_revision_id=new`，`scheduled_publish_at=future` | `approved` |
| 已发布 `published` | publish 新 revision | **保持 `published`** | `published_revision_id=new`，旧版本指针被替换；旧 revision 保留 | `approved` |
| 任意已发布 | withhold | 保持原 `publish_status` | 不改指针 | 不变 |
| `withheld` | restore | 保持原 `publish_status` | 不改指针 | 不变 |
| 任意 | retract | 保持原 `publish_status` | 清 `scheduled_publish_at`；指针保留作审计 | 不变 |

> 关键：**已发布对象的新版本审核/排期不能把父 `publish_status` 改成 `in_review`/`scheduled`**，否则公开列表会突然看不到旧版本。候选版本由 `approved_revision_id` + `scheduled_publish_at` 表达。

**原子切换顺序（同一 DB 事务）**

1. `SELECT ... FOR UPDATE` 父对象（按 `id`）。
2. 可选 `expected_row_version` CAS；不符 → `*_REVISION_CONFLICT`。
3. `SELECT ... FOR UPDATE` 目标 revision。
4. 校验：`review_state=approved`、`approved_revision_id` 指向它、指纹/manifest 重算一致、来源/媒体/sanitizer 合格、B 开关通过、`disposition=normal`。
5. 若发布：更新父对象 `published_revision_id`、`published_at`、`first_published_at`（仅首次）、`publish_status=published`、`scheduled_publish_at=NULL`、`row_version+1`；更新 revision `published_at`（仅首次）。
6. 写 outbox（同一事务，`OutboxWriter` 不自开事务）。
7. 写 `operation_log`（`logOrFail`，同一事务）。
8. 提交。

**已发布对象编辑（保留旧公开版本）**

1. `BeginRevisionService::start()` 锁父对象。
2. `retracted` → 拒绝。
3. `in_review` → 拒绝（先拒绝或等待审核完成）。
4. 创建新 draft revision（复用 `EventRevisionService::createDraft` / `ContentRevisionService::createDraft`）。
5. 设置 `working_revision_id=new`。
6. 若父对象 `approved`/`scheduled`：清 `approved_revision_id`、`scheduled_publish_at`，父回 `draft`。
7. 若父对象 `published`：**保持** `publish_status` / `published_revision_id` 不变。
8. 同一事务提交；公开读取继续读旧 `published_revision_id`。

### 4.6 验收测试清单

- [ ] 未批准 revision 直接发布 → `EVENT_TRANSITION_INVALID` / `CONTENT_TRANSITION_INVALID`。
- [ ] 批准后内容/来源/媒体被改动 → 发布重检失败，返回冲突，不切换指针。
- [ ] `approved_revision_id` 与目标 revision 不一致 → 拒绝。
- [ ] 平台活动缺 `kind` / `timezone` / 起止时间 / `hosting_status` → `EVENT_TIME_INVALID` 或 `EVENT_TRANSITION_INVALID`。
- [ ] 主办方未核验 → `EVENT_ORGANIZER_UNVERIFIED`。
- [ ] 非 `platform + organizer` 且 B-01/B-03 未签署 → 拒绝。
- [ ] `disposition=retracted` 时任何发布/恢复 → 拒绝。
- [ ] 发布切换后：`published_revision_id`、`published_at`、`first_published_at` 正确；重复发布同一 revision 是 no-op（不重复写 outbox）。
- [ ] 发布后创建新 revision：公开指针不变；旧内容仍可被公开读取（在 G1-10 测试）。
- [ ] 已批准/已排期对象创建新 revision：批准失效、排期清空、父回 draft。
- [ ] 排期时间必须在未来；`scheduled` 状态只绑定已批准 revision。
- [ ] 取消排期回到 `approved`；不删除 revision。
- [ ] 暂缓：`disposition=withheld`；重复暂缓是 no-op；原因必填。
- [ ] 恢复：重检版本/来源/媒体/指纹/B 开关；不得自动恢复；恢复写 outbox。
- [ ] 撤稿：`disposition=retracted` 终态；任何恢复/发布/编辑（除新建独立对象）被拒绝；写 outbox。
- [ ] outbox 与业务写在同事务：业务回滚后无 outbox；外部连接在提交前不可见。
- [ ] 审计与业务同事务；审计失败整个发布回滚。
- [ ] 并发两个发布者：只有一人切换成功；另一人 409。
- [ ] `row_version` 每次真实状态变更 +1；no-op 不递增。

### 4.7 回滚方式

1. 先把 `event_publish_enabled` / `content_publish_enabled` / `scheduler_enabled` 设为 false，停止新的发布/排期。
2. 代码回滚（显式文件清单）。
3. 独立库回滚 000016：
   ```bash
   DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
     --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000016_add_scheduled_publish_at.php --force
   ```
4. **不要清空 outbox**：已写记录是审计事实；回滚 worker 时只关开关。
5. 生产回滚前导出 `events` / `content_posts` 的指针与 disposition；不要删除 revision。

### 4.8 G1-08B 落地记录（2026-10-19）

> 本节记录实际落地与 §4.2 规划的差异，避免后来者按规划清单误判“没做”。

**已落地（核心层）**

| 文件 | 说明 |
|---|---|
| `Services/Ecosystem/EventPublicationService.php` | `schedule()` / `unschedule()` / `publishNow()` / `publishScheduled()` / `withhold()` / `restore()` / `retract()` |
| `Services/Ecosystem/ContentPublicationService.php` | 同构；publish/schedule/restore 额外要求 `content_publish_enabled + B-04 + B-07` |
| `Services/Ecosystem/PublicationPointerSwitcher.php` | `published_revision_id` 的唯一生产写入口；Query Builder 原子更新 + `row_version` CAS |
| `Services/Ecosystem/PublicationOutbox.php` | 构造白名单 payload 与稳定 `dedupe_key`，不自开事务 |
| `Services/Ecosystem/PublicationPreconditions.php` | 新增 `assertEventPublishable()` / `assertContentPublishable()`；发布/排期/恢复统一重检 |
| `Services/Ecosystem/PublicationState.php` | 新增 `assertPublishEnabled()` / `assertDispositionNormal()` / 词表校验 |
| `Services/Ecosystem/PublicationAudit.php` | 新增 `recordSystem()`（actor_type=system，供 G1-09 使用） |
| `config/ecosystem_publishing.php` | 发布转移表、动作/处置/原因码词表、outbox 事件映射、发布开关 |
| `config/ecosystem_outbox.php` | 登记 `event.restored` / `content.restored` |
| `tests/Feature/Ecosystem/EcosystemEventPublicationG1Test.php` | 20 tests / 125 assertions |
| `tests/Feature/Ecosystem/EcosystemContentPublicationG1Test.php` | 10 tests / 62 assertions |
| `tests/Feature/Ecosystem/EcosystemPublicationDispositionG1Test.php` | 6 tests / 61 assertions |

**与 §4.2 规划的差异**

- `PublishedRevisionGuard` 没有单独建类：发布/排期/恢复的重检复用 `PublicationPreconditions` 的发布向方法，避免两套校验漂移。
- `BeginRevisionService` 在 G1-08A 已落地，本批直接复用，不重复实现。
- `publishDue()` 在规划中命名，本批按任务冻结命名为 `publishScheduled()`；G1-09 的扫描器调用后者。
- 排期失败回退 `approved`、告警、worker 租约仍属 G1-09，本批**未做**。

**明确未做**

- 没有 outbox worker / 租约 / 恢复扫描 / 排期扫描常驻进程；
- 没有 Admin 发布台 / 处置台 / ACL / 菜单；
  > 2026-10-19 更新：Admin 发布工作台已在 `G1-admin-publishing-console-evidence.md` 落地；
  > 本节记录的是 G1-08B 当时的批次口径，不要据此判断“管理端仍不存在”。
- 没有公开 API、公开 DTO、前端页面；
- 没有打开 `public_enabled`、`event_publish_enabled`、`content_publish_enabled` 或任何 B 项签署开关；
- 没有修改 root 主库，没有执行 `migrate:fresh` / `truncate` / `seed`。

**关键语义**

- `publish_status` 对已发布对象保持 `published`；候选由 `approved_revision_id + scheduled_publish_at` 表达；
- 发布切换在同一事务内完成：父行锁 → `expected_row_version` CAS → revision 行锁 → 重检 → 指针/时间/`row_version` → outbox → 审计；
- 重复发布同一 revision、重复暂缓、重复撤稿是 no-op（不写 outbox、不推 `row_version`）；
- `withhold` / `retract` 的调用方原因只写内部日志与稳定审计原因码，不进入 outbox payload，更不进入公开字段。

---

## 5. G1-09：outbox worker / 租约 / 恢复 / 扫描 / 公开读取 kill switch

### 5.1 目标

1. 消费 `ecosystem_outbox`：领取租约、执行 handler、结算、失败重试、超过上限 `dead`。
2. 恢复过期 `processing` 租约；旧 worker 不能结算新租约。
3. 扫描 `publish_status=scheduled` 且 `scheduled_publish_at <= now` 的活动/文章，调用发布服务；失败回退 `approved` 并告警。
4. 提供独立于业务 DB 的公开读取 kill switch，覆盖 API / 媒体；命中时 503，不伪装空数据。

### 5.2 写作用域（文件清单）

| 文件 | 内容 |
|---|---|
| `config/ecosystem_outbox.php`（修改） | `worker_enabled=false`、`scheduler_enabled=false`、`batch_size=20`、`lease_seconds=60`、`max_attempts=5`、`backoff`、`handler` 映射 |
| `config/ecosystem.php`（修改） | `public_read.disabled`（env `ECOSYSTEM_PUBLIC_READ_DISABLED=false`）；保留 `events.public_enabled` / `content.public_enabled` |
| `Shop/src/Services/Ecosystem/Outbox/OutboxWorker.php` | `claim()`、`settle()`、`fail()`、`recoverExpired()`；MySQL 5.7 不支持 `SKIP LOCKED`，用短事务 `FOR UPDATE` |
| `Shop/src/Services/Ecosystem/Outbox/OutboxHandlerRegistry.php` | 按 `event_type` 分发；未知类型 → dead |
| `Shop/src/Services/Ecosystem/Outbox/Handlers/*.php` | `event.published/withheld/restored/retracted`、`content.*` 的核验型 handler |
| `Shop/src/Services/Ecosystem/PublicReadGate.php` | kill switch + 域开关；返回稳定结果给 controller |
| `Shop/src/Console/Commands/ProcessEcosystemOutbox.php` | `ecosystem:process-outbox --limit=N --dry-run` |
| `Shop/src/Console/Commands/RecoverEcosystemOutbox.php` | `ecosystem:recover-outbox --dry-run` |
| `Shop/src/Console/Commands/PublishScheduledEcosystem.php` | `ecosystem:publish-scheduled --limit=N --dry-run` |
| `bootstrap/app.php`（修改） | 注册 3 条 schedule，全部 `withoutOverlapping()`，并用 `when(fn () => config('ecosystem_outbox.worker_enabled'))` 等开关控制 |
| `Shop/src/Models/EcosystemOutbox.php`（修改） | 增加 scope/状态常量（如果能保持模型简单，也可只放服务层） |
| 测试 | `EcosystemOutboxWorkerG1Test.php`、`EcosystemOutboxLeaseG1Test.php`、`EcosystemScheduledPublishG1Test.php`、`EcosystemPublicReadKillSwitchG1Test.php` |

### 5.3 worker 设计（MySQL 5.7 约束）

**领取（短事务，立即提交）**

```
BEGIN
SELECT id FROM ecosystem_outbox
 WHERE status='pending' AND available_at <= UTC_NOW()
 ORDER BY id
 LIMIT :batch
 FOR UPDATE;
-- 逐行 UPDATE status='processing', lease_owner=:token, lease_until=UTC_NOW()+TTL, attempts=attempts+1
COMMIT
```

- `lease_owner` 使用 `host:pid:uuid` 作为一次领取的唯一 token；**不要**只用 host。
- MySQL 5.7 没有 `SKIP LOCKED`，不要照抄 MySQL 8 写法；用小 batch（20）缩短锁持有时间。
- 领取事务**不执行 handler**；handler 在事务外执行，避免持 outbox 锁反向等待聚合锁。

**结算**

```
UPDATE ecosystem_outbox
SET status='sent', lease_owner=NULL, lease_until=NULL, last_error=NULL
WHERE id=? AND status='processing'
  AND lease_owner=? AND lease_until >= UTC_NOW()
```

- 影响行数 0 → `LeaseLost`：旧 worker 的完成/失败回报一律丢弃，不写 `sent`/`dead`。
- 失败：同样带 `lease_owner` + 未过期守卫；`attempts >= max_attempts` → `dead`，否则回 `pending`，`available_at = now + backoff(attempts)`。
- 过期租约：`recoverExpired()` 将 `status=processing AND lease_until < now` 重置为 `pending`，清 lease，`available_at=now`；不增加 attempts（attempts 在 claim 时已加）。

**handler 语义**

- 按 `aggregate_type` + `aggregate_id` 重新读取父对象与 revision。
- `event.published`：父 `publish_status=published`、`published_revision_id = payload.revision_id`、`disposition=normal`；否则 `superseded`。
- `event.withheld`：`disposition=withheld`；`event.retracted`：`disposition=retracted`；`restored`：`disposition=normal`。
- 内容域同理，并核对 `content_posts.slug`。
- G1 的 handler **不发送通知**，只核验并把结果记为 `sent`；`superseded` 也记 `sent` 并在 `last_error` 写稳定原因（`superseded:...`），避免把旧事实投递给 G2 消费者。
- 未知 `event_type` → `dead` + 告警。

**排期扫描**

- 查询 `publish_status=scheduled AND scheduled_publish_at <= UTC_NOW()`（活动/文章各一批）。
- 逐条调用 `publishDue()`（系统 actor）；成功即切换；确定性校验失败 →
  - 回退 `publish_status=approved`，清 `scheduled_publish_at`；
  - 写 `operation_log`（actor=system，action=`ecosystem.event.schedule_aborted`，reason）；
  - `AdminAlertService::alert()`，dedupe key 含聚合 ID + 日期，避免刷屏。
- 瞬时 DB/锁错误 → 让事务失败，行保持 `scheduled`，下一轮重试；不要误判为“确定性失败”。
- 没有 scheduler 常驻时，命令本身可以手工单轮运行；**不得**宣称定时任务已在跑。

### 5.4 公开读取 kill switch

| 状态 | 公开列表 | 公开详情 | 公开媒体 |
|---|---|---|---|
| `public_read.disabled=true` | 503 `SERVICE_UNAVAILABLE` | 503 | 503 |
| 域 `public_enabled=false`（G1 验收前） | 保持现有 `{"data":[]}` | 404 `EVENT_NOT_FOUND` / `CONTENT_NOT_FOUND` | 404 `MEDIA_NOT_FOUND` |
| 域 `public_enabled=true` + 可见 | 正常 | 正常 | 正常 |
| 域 `public_enabled=true` + 撤稿 | 不出现 | 410 `*_UNAVAILABLE` | 404 `MEDIA_NOT_FOUND` |
| 域 `public_enabled=true` + 暂缓/未发布 | 不出现 | 404 | 404 |

- kill switch 必须在任何 DB 查询之前检查；503 不得伪装成空列表。
- kill switch 是独立配置，不依赖数据库状态；变更后需 `config:cache`（或部署流程）实际生效，必须在 Runbook 里写清。
- G1-10 的 controller 媒体门禁统一调用 `PublicReadGate`，不要各自判断。

### 5.5 依赖顺序

1. G1-08B 的发布服务与 outbox 写入契约冻结。
2. `OutboxWorker` + handler registry（先用假 handler 做租约测试）。
3. 3 个命令 + scheduler 注册 + `worker_enabled=false` 默认。
4. kill switch + `PublicReadGate`。
5. 真实两连接/多进程租约测试；手工单轮演练。

### 5.6 不可做边界

- 不实现报名通知扇出（G2-12）。
- 不发送短信/微信/邮件；不把 `sent` 解释为“用户已收到”。
- 不引入 Redis/消息队列/微服务；仍用 database driver。
- 不使用 MySQL 8 的 `SKIP LOCKED`。
- 不在每个 GET 上探测数据库写权限。
- 不自动恢复 `withheld`；不自动复活 `retracted`。
- 不删除 outbox 历史；`dead` 只标记，不物理删除。

### 5.7 验收测试清单

- [ ] 领取后 `status=processing`、`lease_owner` 唯一、`lease_until` 正确、`attempts+1`。
- [ ] 两个 worker 并发领取同一批：同一行只被一个 worker 拿到。
- [ ] 旧 worker 在租约过期且被新 worker 领取后回报成功 → 拒绝，行状态不被旧 worker 改写。
- [ ] 租约过期未被领取 → 恢复扫描回 `pending`。
- [ ] 失败重试：`available_at` 按 backoff 推后；达到上限 → `dead`。
- [ ] handler 幂等：同一事实重复执行不产生第二事实。
- [ ] 未知 event_type → `dead` + 告警。
- [ ] 排期成功：到点后切换指针、写 outbox/audit。
- [ ] 排期失败：回退 `approved`、清排期、写 audit、发告警；不保留“假 scheduled”。
- [ ] kill switch：列表/详情/媒体全部 503；不查库；恢复后正常。
- [ ] 域开关关闭：保持 G1 前的空列表/404 语义，不泄露草稿。
- [ ] 配置缓存下开关实际生效（部署演练记录，不只用测试进程）。
- [ ] 真实两连接/多进程证据：租约与排期至少各一条。
- [ ] 命令 `--dry-run` 不写库。

### 5.8 回滚方式

1. `worker_enabled=false`、`scheduler_enabled=false`，停止 cron/schedule:work（若已启）。
2. 回滚代码；outbox 表与记录保留。
3. 不需要回滚数据库结构（G1-09 原则上不新增表；若新增索引/列，单独写 down）。
4. 生产回滚先确认没有正在执行的发布事务；记录当前 outbox 状态。

---

### 5.9 G1-09 落地记录（2026-10-19）

| 项 | 落地结果 |
|---|---|
| 状态 | **已完成（服务层 + 命令 + 调度注册 + 测试）**；未进入 G1-10/11 |
| 数据库 | 独立测试库 `summon_mecha_step10_test`；**未新增迁移**，复用 `ecosystem_outbox` |
| outbox 开关 | `worker_enabled=false` / `lease_seconds=300` / `batch_size=20`（硬上限）/ `max_attempts=5` / backoff 30→3600 |
| 领取 | 短事务 `FOR UPDATE` + 每批 ≤20；每次唯一 `lease_owner=host:pid:uuid`；attempts 在领取时 +1 |
| 结算 | 条件更新 `status=processing + lease_owner + lease_until>=now`；0 行 → `OutboxLeaseLostException`，旧 worker 不写状态 |
| 恢复 | `processing` 且租约过期/为空 → `pending`，清租约，不重复加 attempts；内置于 `ecosystem:process-outbox` 每轮 |
| handler | 只核验事实并记账；`sent` ≠ 已通知用户；`row_version` 被取代 → `dead(superseded)`；未知类型 → `dead` |
| 扫描 | 只处理 due + `disposition=normal` + `scheduled/published`；稳定排序；域开关关闭整域跳过 |
| 失败回退 | 从未发布 → `approved`；已发布 → 保留旧公开指针；写 `schedule_aborted` 审计 + 告警；不复活 withheld/retracted |
| kill switch | 新增 `config/ecosystem_public_read.php`；`PublicReadGate` 只读配置；媒体门禁优先返回 503 |
| 调度 | 两条 `everyMinute + withoutOverlapping`，默认开关 false；**不声称常驻进程已运行** |

**与 §5.2 规划的差异（已按任务口径调整）**

1. 文件命名：实际为 `OutboxLeaseService` / `EcosystemOutboxProcessor` / `ScheduledPublicationScanner`，不建 `Outbox/` 子目录与独立 handler 类；handler 语义收敛在 processor 的核验方法中。
2. `lease_seconds` 采用任务指定的 300（规划表曾写 60），给单批 handler 留出更大余量。
3. 规划 §5.3 曾提议 superseded 记为 `sent` + `last_error`；任务要求标记 `dead`，以任务为准。
4. 不单设 `ecosystem:recover-outbox` 命令：恢复扫描在 `ecosystem:process-outbox` 每轮开头执行，避免多一个“忘了跑”的入口。
5. 域发布开关关闭时整域跳过（不产生 `schedule_aborted`），避免运营停开关导致排期被批量回退。

**测试**：新增 6 个文件、35 tests / 153 assertions；G1-02 ~ G1-08B 回归 303 tests / 2344 assertions 全部通过（分进程执行）。

---

## 6. G1-10：公开 Event / Content / Media DTO 与 API

### 6.1 目标

- `GET /api/events` / `GET /api/events/{id}` 改为 G1 公开合同。
- 新增 `GET /api/content/posts` / `GET /api/content/posts/{slug}`。
- 新增活动/内容媒体读取路由。
- 统一 404/410/503；只读 `published_revision_id`；公开 DTO 白名单。
- 列表分页、基础筛选、稳定排序。

### 6.2 写作用域（文件清单）

| 文件 | 内容 |
|---|---|
| `Shop/src/Http/Resources/Ecosystem/EventPublicResource.php` | 活动 DTO 白名单 |
| `Shop/src/Http/Resources/Ecosystem/ContentPublicResource.php` | 资讯 DTO 白名单 |
| `Shop/src/Http/Resources/Ecosystem/MediaPublicResource.php` | `id/role/alt_text/url`；不含 storage_key |
| `Shop/src/Services/Ecosystem/PublicEventQuery.php` | 可见性查询、筛选、稳定排序、分页 |
| `Shop/src/Services/Ecosystem/PublicContentQuery.php` | 同上 |
| `Shop/src/Services/Ecosystem/PublicBodyRenderer.php` | 把已净化 HTML 中的 `data-media-id` 替换为受控媒体 URL；未知/未批准 ID 删除 |
| `Shop/src/Services/Ecosystem/PublicRegistrationState.php` | G1 的 `can_register` / `registration_reason` / `allowed_actions` |
| `Shop/src/Http/Controllers/API/EventController.php`（重写 index/show/format） | 只读已发布；404/410；不再返回旧字段 |
| `Shop/src/Http/Controllers/API/ContentController.php`（新增） | 列表/详情 |
| `Shop/src/Http/Controllers/API/EcosystemMediaController.php`（新增） | 活动/内容媒体流式输出 |
| `Shop/src/Routes/api.php`（修改） | 新增 content/media 路由；event 路由保留但 controller 重写 |
| `Shop/src/Services/Ecosystem/PublicReadGate.php` | 复用 G1-09 的 kill switch + 域开关 |
| 测试 | `EcosystemPublicEventApiG1Test.php`、`EcosystemPublicContentApiG1Test.php`、`EcosystemPublicMediaApiG1Test.php`、`EcosystemPublicDtoLeakG1Test.php` |

### 6.3 DTO 口径

**活动**

```json
{
  "id": 12,
  "kind": "competition",
  "name": "…",
  "summary": "…",
  "organizer": { "name": "…", "platform_role": "organizer" },
  "source": { "name": "…", "url": "https://…" },
  "participation_mode": "offline",
  "city": "上海",
  "location_text": "…",
  "timezone": "Asia/Shanghai",
  "starts_at": "2026-11-01T09:00:00+08:00",
  "ends_at": "2026-11-01T18:00:00+08:00",
  "published_at": "2026-10-20T12:00:00+08:00",
  "revision_no": 3,
  "hosting_status": "scheduled",
  "registration": {
    "can_register": false,
    "registration_reason": "registration_disabled",
    "allowed_actions": ["view_detail"]
  },
  "media": [
    { "id": 7, "role": "cover", "alt_text": "…", "url": "/api/events/12/media/7" }
  ]
}
```

- 不返回：`hot`、`teams`、`date`、`status`、`detail`、联系人、内部审核备注、审核人、工作 revision、`storage_key`、私有证据。
- `published_at` / `revision_no` 来自当前 published revision。
- `registration_reason` 保持现有公开合同字段名；`allowed_actions` 为 G1 新增数组。
- G1 的 `can_register` 恒为 false；平台活动 reason=`registration_disabled`，外部活动（当前不可发布）reason=`external_only`，`allowed_actions` 含 `open_external_registration`。

**资讯**

```json
{
  "slug": "robot-expo-2026",
  "type": "news",
  "title": "…",
  "summary": "…",
  "author": { "name": "…", "org": "…" },
  "sources": [
    { "name": "…", "url": "https://…", "verified_at": "2026-10-19T…", "rights_basis": "platform_original" }
  ],
  "published_at": "…",
  "first_published_at": "…",
  "revision_no": 2,
  "correction_note": null,
  "body_html": "<p>…<img data-media-id=\"7\"></p>",
  "media": [ … ]
}
```

- `body_html` 必须经过 `PublicBodyRenderer`；如果 `sanitizer_version` 不是当前版本，**fail-closed**（404/503，并记录日志），不得直接输出旧净化结果。
- `sources` 不返回 `evidence_ref`；`rights_scope` 可作为公开说明返回。
- 不返回：内部审核意见、审核人、工作 revision、私有授权文件、附件存储 key。

### 6.4 查询与可见性

**活动列表**

```
WHERE publish_status='published'
  AND disposition='normal'
  AND published_revision_id IS NOT NULL
  [AND kind=?]
  [AND city=?]
  [AND starts_at >= ?]
  [AND starts_at <= ?]
ORDER BY published_at DESC, id DESC
LIMIT/OFFSET (page, per_page <= 50)
```

- 不使用旧 `sort` / `hot` / `teams`。
- 待定时间（`starts_at IS NULL`）不得造成跨页漂移；排序主键必须包含 `id`。
- 筛选参数白名单校验；不允许把任意列名拼进 SQL。

**资讯列表**

```
WHERE publish_status='published'
  AND disposition='normal'
  AND published_revision_id IS NOT NULL
  [AND type=?]
  [AND category=?]
ORDER BY first_published_at DESC, id DESC
```

- 搜索 (`q`) 留 G4-01；G1 不做 LIKE 搜索，避免转义和性能问题。
- 分页返回 `data` + `meta`（current_page/last_page/per_page/total）；不要用随机/伪热度排序。

**详情**

- 可见：返回 DTO。
- 不存在 / 草稿 / 待审 / 已批准未发布 / 已排期 / 暂缓：404，统一文案与结构。
- 已撤稿：410，中性文案；不回显旧标题/正文/摘要/来源/媒体。
- kill switch：503，先于任何查询。
- 域开关关闭：保持现有行为（列表 `{data:[]}`、详情 404），不查库。

### 6.5 媒体输出

**路由**

```
GET /api/events/{id}/media/{mediaId}
GET /api/content/posts/{slug}/media/{mediaId}
```

- 控制器先解析父对象的当前 `published_revision_id`，再调用 `MediaAccessService::resolvePublic('event_revision'|'content_revision', $revisionId, $mediaId)`。
- 不在 URL 暴露 revision ID；不提供“按 media_id 直接读取任意媒体”的通用入口。
- DTO 中的 `media[].url` 使用上述路由。

**响应头**

| 头 | 值 |
|---|---|
| `Content-Type` | 数据库真实 `mime` |
| `Content-Length` | 文件字节数 |
| `Content-Disposition` | 图片 `inline`；PDF `attachment`（或按产品确认） |
| `X-Content-Type-Options` | `nosniff` |
| `Cache-Control` | `private, no-store`（G1 不做长缓存/CDN） |
| `X-Robots-Tag` | 可选 `noindex`（媒体不期望被搜索） |

- 不返回 `storage_key` / `disk` / 磁盘路径。
- 文件缺失 → 404 `MEDIA_NOT_FOUND` + error 日志。
- 媒体未批准 / 引用未批准 / 父 revision 非当前 published / 父对象 withheld/retracted / 域关闭 → 404。
- kill switch → 503。
- 不承诺 CDN/第三方副本清除（G4 另议）。

### 6.6 依赖顺序

1. G1-08B 的公开指针与 revision 数据可用。
2. G1-09 的 `PublicReadGate`/kill switch 契约冻结。
3. Query 服务 + Resource + DTO 泄漏测试。
4. Controller/路由。
5. 媒体流式输出 + 门禁测试。
6. OpenAPI/字段字典更新（Q-01）。

### 6.7 不可做边界

- 不实现 SSR / canonical / OG / sitemap（G4-04）。
- 不实现搜索/ES/收藏/评论/报名写入口。
- 不返回旧 `hot/teams/date/status`；不把旧 CMS 页当资讯。
- 不打开 `public_enabled`；不在本批执行生产开关变更。
- 不允许 `revision` 查询参数、`preview=1` 或任何绕过可见性的入口。
- 不允许直接序列化 Eloquent 模型；所有公开 DTO 显式构造。
- 不允许公开媒体走 public 磁盘或签名长 URL。

### 6.8 验收测试清单

- [ ] 草稿 / 待审 / 已批准 / 已排期 / 暂缓 / 不存在：详情统一 404，响应体结构与文案一致。
- [ ] 已撤稿：410，响应体不含旧标题、正文、摘要、来源、媒体。
- [ ] 列表只返回 published + normal；不出现草稿/暂缓/撤稿。
- [ ] 分页稳定：同一 `published_at` 多行按 `id` 决胜；跨页无重复/遗漏。
- [ ] 筛选参数非法 → 422；合法筛选生效；`per_page` 上限生效。
- [ ] DTO 泄漏测试：不含 `working_revision_id`、`review_note`、`reviewed_by`、`evidence_ref`、`storage_key`、联系人、`hot/teams`。
- [ ] `can_register=false`；`registration_reason` 稳定；`allowed_actions` 只含 G1 词表。
- [ ] 外部活动（若未来放行）返回 `external_only` + `open_external_registration`，但 G1 默认不可发布。
- [ ] `sanitizer_version` 不是当前版本 → fail-closed，不输出正文。
- [ ] `data-media-id` 替换只使用当前 revision 已批准媒体；未知 ID 删除；不重新引入远程 URL。
- [ ] 媒体：未批准/隔离/非当前 revision/撤稿父对象 → 404；合法 → 200 + 正确 MIME；文件缺失 → 404。
- [ ] kill switch → 列表/详情/媒体全部 503，不查库；关闭后恢复。
- [ ] 域开关关闭 → 保持 `{data:[]}` / 404 旧语义，不泄露草稿。
- [ ] `artisan route:list` 确认路由；`php -l`；OpenAPI/字段字典与 Resource 一致。

### 6.9 回滚方式

1. 保持 `public_enabled=false` / kill switch 开启。
2. 回滚 controller/resource/query/routes 代码；无数据库结构变更。
3. 旧前端若尚未升级，回滚到旧 EventController 前必须确认旧 controller 不会在开关打开时泄露草稿（见 §1.4 风险）。
4. 媒体路由回滚后，已公开页面可能暂时无法加载图片；先关域开关再回滚。

---

## 7. G1-11：H5 / 小程序前端

### 7.1 目标

- 活动列表/详情、资讯列表/详情独立页面。
- 基础筛选、分页/加载更多、返回/刷新/直达。
- H5 复制真实详情 URL；小程序 `onShareAppMessage` 使用真实详情路由。
- 富文本安全渲染；加载/异常/空/404/410 状态。
- 首页 EcoCenter / Dongtai 作为精选预览，带“查看全部”。

### 7.2 写作用域（文件清单）

| 文件 | 内容 |
|---|---|
| `pages/events/index.vue` | 活动列表：筛选、分页、StateView |
| `pages/events/detail.vue` | 活动详情：来源/主办方/时间/时区/处置状态/媒体/CTA |
| `pages/content/index.vue` | 资讯列表：类型/分类、分页 |
| `pages/content/detail.vue` | 资讯详情：来源、首发/更新时间、更正说明、正文 |
| `pages.json`（修改） | 注册 4 个页面 |
| `utils/api.js`（修改） | `eventApi.getEvents(params)`、`eventApi.getEvent(id)`、`contentApi.getPosts(params)`、`contentApi.getPost(slug)`；媒体 URL 直接来自 DTO |
| `utils/ecosystem-events.js`（重写） | 新 DTO 归一化；404/410/503 分类；不再从 `status` 猜报名 |
| `utils/ecosystem-content.js`（新增） | 资讯 DTO 归一化、日期/时区显示 |
| `components/EcoCenter.vue`（修改） | 活动 tab 使用新 DTO；加载更多/查看全部；不再显示 `hot/teams/date` |
| `components/shouye/dongtai.vue`（修改） | 资讯预览；真实空/错态；“查看全部” |
| `components/RichContent.vue`（新增，可选） | `rich-text` 渲染封装；只接受服务端已渲染的 `body_html` |
| `tools/tests/ecosystem-events.test.mjs`（修改） | 新 DTO 归一化/状态测试 |
| `tools/tests/ecosystem-content.test.mjs`（新增） | 资讯归一化/日期/状态测试 |

### 7.3 页面状态与交互

| 场景 | 行为 |
|---|---|
| 加载中 | StateView loading |
| 网络/500/格式异常 | StateView error + 重试；不显示空态 |
| 成功但无数据 | StateView empty；不显示样例 |
| 详情 404 | “不存在或未公开”空态；不显示旧缓存内容 |
| 详情 410 | “该内容已撤稿”中性说明；不显示旧标题/正文 |
| 503 kill switch | 服务暂不可用 + 重试；不伪装空列表 |
| 分页 | “加载更多”或页码；到底显示“没有更多”；不重复/不漏 |
| 筛选 | 类型/城市/日期（活动）、类型/分类（资讯）；清空筛选可恢复 |
| 分享 H5 | 复制当前 H5 站点基址 + `/#/pages/ecosystem/event-detail?id=...`；`uni.setClipboardData` 成功才提示成功；失败提示失败 |
| 分享小程序 | `onShareAppMessage` 返回 `{title, path: '/pages/events/detail?id=' + id}`；不分享草稿/未公开 |
| 富文本 | 只渲染服务端 `body_html`；不拼接未净化内容；图片 URL 来自 DTO |

### 7.4 依赖顺序

1. G1-10 公开 DTO/路由冻结。
2. `utils/api.js` + 归一化 + Node 测试。
3. 列表页 → 详情页 → 首页预览改造。
4. H5 截图（375/390px）、小程序分享冒烟。
5. 全量 Node 测试 + `api-regression.sh`。

### 7.5 不可做边界

- 不做 SSR / OG / sitemap（G4）。
- 不做搜索、收藏、评论、报名。
- 不在前端内置样例赛事/资讯；不在接口失败时回填样例。
- 不在前端猜 `can_register`；只认服务端 `registration.can_register` / `allowed_actions`。
- 不直接 `v-html` 任意后端 HTML；富文本只接受 G1-10 已渲染的 `body_html`。
- 不打开公开开关；本地预览只能用隔离库 + 临时配置覆盖，并留下回滚记录。

### 7.6 验收测试清单

- [ ] Node：新 DTO 归一化、缺字段、格式异常、404/410/503 分类。
- [ ] 列表：首屏、加载更多、筛选、无结果、错误重试、到底提示。
- [ ] 详情：ID 直达、刷新、返回列表、404、410、503。
- [ ] 首页 EcoCenter 活动 tab 用真实数据；空/错态不显示样例；查看全部可跳转。
- [ ] Dongtai 资讯预览用真实数据；空/错态不显示样例；查看全部可跳转。
- [ ] 富文本：图片来自 DTO 媒体 URL；script/事件属性/危险协议不出现。
- [ ] H5 复制链接：成功才提示成功；失败不伪装。
- [ ] 小程序分享路径与标题正确；草稿/未公开不可分享。
- [ ] 375/390px、长标题、大字号、键盘不遮挡关键操作。
- [ ] `node --test tools/tests/*.test.mjs` 通过。
- [ ] H5 截图证据（使用 `tools/shot.mjs`），注明使用的隔离库与临时开关。

### 7.7 回滚方式

1. 保持 `public_enabled=false` / kill switch。
2. 回滚前端页面与 `pages.json`；旧 EcoCenter/Dongtai 仍可运行。
3. 若已发布小程序版本，保留旧 API 兼容期或回滚后端路由；不得删除已发布页面导致分享链接 404 无提示。
4. 不涉及数据库。

---

## 8. 关键设计决策

### 8.1 排期用什么持久事实

**决策**：在 `events` 与 `content_posts` 增加 `scheduled_publish_at DATETIME NULL`（UTC），并增加 `idx(publish_status, scheduled_publish_at)`。

**理由**：

- outbox 是“已发生事实”的消费流，不是排期存储；不能把未来时间塞进 `available_at` 当作排期。
- 只有父对象行能提供“当前待发布版本 + 时间”的原子读写。
- worker 扫描 `publish_status=scheduled AND scheduled_publish_at <= now` 即可，无需额外表。
- 取消排期 = 清空该字段并回 `approved`，不删除 revision。

**首次发布与已发布对象**：

- 从未发布对象：`publish_status=scheduled` + `approved_revision_id` + `scheduled_publish_at`。
- 已发布对象：`publish_status` 保持 `published`，只设置 `approved_revision_id` + `scheduled_publish_at`；到点切换 `published_revision_id`，旧版本继续公开到切换前。

### 8.2 状态机与 revision 指针如何原子切换

**决策**：

- 所有发布/暂缓/恢复/撤稿动作走 `EventPublicationService` / `ContentPublicationService`。
- 唯一允许写 `published_revision_id` 的入口是 `PublicationPointerSwitcher`；普通 `save()` / `EventWriteService` 继续拒绝。
- 顺序：父行锁 → 可选 `expected_row_version` CAS → revision 行锁 → 重检 → 更新指针/时间/`row_version` → outbox → audit → 提交。
- `publish_status` 对已发布对象保持 `published`；候选版本由 `approved_revision_id` / `scheduled_publish_at` 表达。

**为什么不能把“已批准版本”塞进 `working_revision_id`**：working 指针的既有语义是“仅后台可见的 draft”；让它指向 approved 会破坏 `RevisionPointerValidator` 和所有“编辑中”判断。

**为什么不能只用 `published_revision_id` 当排期绑定**：它表示“当前公开版本”；排期期间旧版本仍公开，提前写入会造成公开内容错误。

### 8.3 审批如何绑定 fingerprint + media manifest

**提交时**：

1. 锁父对象 + revision。
2. 重算 `content_fingerprint` 与 `media_manifest_hash`（从库内 revision 内容 + 有序来源 + 媒体 refs）。
3. 与库内值比较；不一致 → `*_REVISION_CONFLICT`。
4. 校验媒体：每条 ref `review_status=approved`、媒体 `status=approved`、文件存在。
5. 校验来源：活动 `source_name/source_verified_at/authorization_basis/organizer_name_snapshot`；内容至少 1 条来源，权利依据匹配。
6. 校验 sanitizer 版本 = 当前配置。
7. 写 `review_state=in_review`、`submitted_by/at`；父状态按 §8.2。

**批准时**：

1. 重做上述 2–6。
2. 校验 `review_state=in_review`、审核人有权限、非终态。
3. 写 `reviewed_by/at/review_note`、`review_state=approved`；父 `approved_revision_id=rev`。

**发布时**：

1. 重做上述 2–6。
2. 校验 `approved_revision_id=rev`、`review_state=approved`、B 开关、来源/媒体/时间。
3. 切换指针。
4. 任何一步不符 → 不切换，返回稳定错误码。

**媒体引用审核必须在提交前完成**：`MediaRefService::reviewRef()` 只允许父 revision 为 `draft`（`MediaRefService.php:353-377`）。这是既有不可变语义，**不要**为了审核方便放开到 `in_review`；否则批准后的 manifest 会在审核期变化，审批绑定失去意义。工作流固定为：

```
上传媒体 → 媒体批准 → 引用审核（draft 期）→ 提交 revision → 审核 revision → 批准 → 发布
```

### 8.4 修改已发布对象如何保留旧公开版本

**决策**：

- 已发布对象编辑必须创建新的 `working_revision`；公开读取始终只读 `published_revision_id`。
- 新 revision 提交/批准/排期期间，父 `publish_status` 保持 `published`，公开指针不变。
- 已发布对象创建新 working draft 时，`publish_status` 与 `published_revision_id` 不变，但必须清空 `approved_revision_id` 与 `scheduled_publish_at`：任何未公开候选被新 draft 取代即失效，避免旧批准/旧排期复活。
- 已发布对象即使 `working_revision_id` 为空，只要同对象存在 `review_state=in_review` 的 revision，就拒绝开始新 draft（不得并行审核）。已发布对象提交后 working 指针为空、父状态仍是 `published`，必须显式查询同对象 revision；探测在父行锁事务内完成。
- 拒绝（reject）不区分父对象是否 `published`：一律清空 `approved_revision_id`，`scheduled_publish_at` 存在也清空；`published_revision_id` 仍不变。
- 新 revision 发布时，在同一事务内切换 `published_revision_id`；旧 revision 保留（不删除），公开页立即读新版本。
- 旧版本媒体：公开媒体门禁要求“父 revision = 当前 published revision”，所以切换后旧媒体自动不可通过公开路由读取；旧 revision 的审计证据保留。
- 若新 revision 在提交前被放弃，父对象仍公开旧版本；`working_revision_id` 可指向新 draft 或清空。

### 8.5 预览如何不泄露

**决策**：

- 预览只走后端 Admin 会话路由，**不生成公开预览 token/链接**。
- 路由示例：
  - `GET /admin/catalog/ecosystem/events/{id}/preview?revision_id=N`
  - `GET /admin/catalog/ecosystem/content/{id}/preview?revision_id=N`
  - `GET /admin/catalog/ecosystem/{type}/{id}/preview/media/{mediaId}?revision_id=N`
- 权限：`ecosystem.events.view` / `ecosystem.content.view` / `ecosystem.media.view`；并校验 revision 属于目标对象。
- 响应头：`Cache-Control: no-store, private`、`X-Robots-Tag: noindex, nofollow`。
- 预览媒体使用独立的 `MediaAccessService::resolvePreview()`：只校验 revision 归属 + 媒体/引用存在 + 管理员权限，不要求 published；仍然不返回 `storage_key`，仍然流式输出私有盘。
- 预览 HTML 由同一个 `PublicBodyRenderer` 渲染，但媒体 URL 指向 Admin 预览路由；不在响应中输出公开 URL、内部备注、审核人、`working_revision_id`。
- 候选 revision 因新 draft 或拒绝而失效（`approved_revision_id` / `scheduled_publish_at` 被清空）时，revision 记录仍保留；预览仍只按 Admin 权限读取指定 revision，不因候选失效而删除或暴露内容。公开页始终只读 `published_revision_id`。
- 禁止：`?preview=1` 作用在公开 API 上；禁止把预览 URL 写入 sitemap/OG/分享。

### 8.6 outbox worker 如何防旧租约结算

**决策**：

- `lease_owner` 是**每次领取生成的唯一 token**（`host:pid:uuid`），不是固定的 worker 名。
- 结算 SQL 必须同时匹配 `id`、`status='processing'`、`lease_owner`、未过期 `lease_until`。
- 影响行数 0 → `LeaseLost`，旧 worker 静默丢弃结果（记 warning 日志，不写状态）。
- 恢复扫描只重置 `lease_until < now` 的 `processing` 行；重置后旧 token 永久失效。
- handler 必须幂等；重复执行不会产生第二事实。
- 禁止持 outbox 行锁调用会反向等待父对象锁的逻辑；领取事务先提交，handler 在事务外执行。

### 8.7 公开 404 / 410

**决策**：

- 404：不存在、draft、in_review、approved、scheduled、withheld、域开关关闭、媒体不可读。
- 410：`disposition=retracted`，且该对象**曾经发布过**（`first_published_at` 非 null / `published_at` 非 null / 曾公开）。中性文案，不回显旧内容。
- 503：kill switch 或数据库不可用；不伪装成功/空数据。
- 响应体结构统一 `{code, message}`；404 的文案与耗时不得暴露对象是否存在。
- 媒体对撤稿父对象统一 404（不是 410），避免通过媒体探测撤稿对象。

### 8.8 媒体输出

- 只从私有盘流式读取；不生成 public URL；不落 CDN。
- 公开门禁必须同时校验：域开关 + 父对象 published/normal + 父 revision 是当前 published + ref approved + media approved + 文件存在。
- 预览门禁单独实现，只给 Admin 权限。
- 缓存：G1 `no-store`；G4 再接 CDN 时必须补撤回/purge/签名 URL 有效期。
- 文件缺失：404 + 日志，不 500、不返回路径。
- 输出头：真实 MIME、长度、nosniff、inline/attachment、no-store。

### 8.9 B-01 / B-03 / B-04 / B-05 / B-06 未签署时的关闭能力

| 阻塞项 | 未签署时保持关闭 | 落地位置 |
|---|---|---|
| B-01 外部活动外链许可 | `source_type=external` 活动**提交/批准/发布**全部拒绝；不公开展示外链 | `EventSourceValidator::assertPublishable()` + `PublicationPreconditions` + `config/ecosystem_organizers.php` |
| B-03 主办方核验标准 | `co_organizer` / `authorized_operator` 及所有外部活动提交/批准/发布拒绝；只放行 `platform + organizer` | 同上 |
| B-04 slug 最终规则 | 内容 `publish` / `schedule` 关闭；可建草稿、提交、批准演练；`public_enabled` 保持 false | `config/ecosystem_content.php` 的 `publish_allowed=false`；`PublicationPreconditions` |
| B-05 保留/删除 | 不提供删除/清理/物理删除；媒体/outbox/revision 全部保留；清理任务只允许 dry-run | 模型删除守卫 + 不新增清理命令 |
| B-06 取消/改期/延期政策 | `postponed` / `rescheduled` / `canceled` 写能力关闭；只允许 `scheduled → completed` | `config/ecosystem_publishing.php` 的 hosting 转移表 |
| B-07 内容法律责任 | `ECOSYSTEM_CONTENT_PUBLIC_ENABLED=false`；内容发布/排期关闭 | `config/ecosystem.php` + `config/ecosystem_content.php` |
| B-02 媒体权利 | 数据模型没有权利字段，无法代码化；G1 只允许平台自有/已明确书面授权媒体，由运营政策控制；不宣称已解决 | 记录为风险；后续 ADR 修订补字段 |

> 这些开关必须是**配置/服务层**开关，不能只靠“记得别点”。测试必须覆盖“开关关闭时动作被拒绝”。

### 8.10 ADR 增量修订请求（开工前报批）

| 编号 | 修订 | 原因 | 影响 |
|---|---|---|---|
| A-01 | `events` / `content_posts` 新增 `approved_revision_id BIGINT UNSIGNED NULL` + FK | 批准版本需要持久绑定；published/working 双指针无法表达 | 字段字典 §4.2 / §4.6 追加；G1-08A |
| A-02 | `events` / `content_posts` 新增 `scheduled_publish_at DATETIME NULL` + 索引 | 排期需要持久事实；outbox 不是排期存储 | 字段字典追加；G1-08B |
| A-03 | outbox 增加 `event.restored` / `content.restored` | 恢复需要可区分事件；ADR 示例允许注册表落地时扩展 | `config/ecosystem_outbox.php`；G1-08B |
| A-04 | 澄清已发布对象的 `publish_status` 语义：新版本审核/排期期间保持 `published` | 否则公开列表会隐藏旧版本 | ADR §5.1 补充；G1-08B |
| A-05 | 新增发布/排期/worker/kill switch 配置键及默认 false | 失败关闭、可运营停写 | `config/ecosystem_publishing.php`、`ecosystem_outbox.php`、`ecosystem.php` |
| A-06 | 明确媒体引用审核必须在提交前完成（reviewRef 仅 draft） | 现有服务语义；避免审批后 manifest 变化 | ADR §4.3 / §4.10 补充说明 |

> 在 A-01/A-02 获批前，不应写 G1-08 的迁移；可以用服务桩和测试先行，但不得把未批准字段写进生产 schema。

---

## 9. 本机可验证 vs root/生产

### 9.1 可在本机独立测试库验证

| 项 | 方式 | 证据要求 |
|---|---|---|
| G1-08/09 迁移 | `DB_DATABASE=summon_mecha_step10_test artisan migrate` / `rollback` / 重跑 | `migrate:status`、回滚前后表/列/FK 快照 |
| 审核/发布服务 | Pest Feature，`DatabaseTransactions` | 状态、指针、指纹、媒体、outbox、audit 断言 |
| outbox 租约 | 同进程两连接/独立 PDO 模拟并发；能做的部分 | claim/resettle/expire 测试；真实多进程另记“未验证” |
| 排期扫描 | `artisan ecosystem:publish-scheduled --dry-run/--limit=N` | 前后状态、outbox、audit、告警 |
| 公开 API | `config(['ecosystem.events.public_enabled'=>true])` 的测试进程（不提交 env） | 404/410/503、DTO 泄漏、分页筛选 |
| 媒体流式输出 | Feature 测试 + 私有盘 fixture | MIME/header/404，不出现 storage_key |
| 前端归一化 | `node --test tools/tests/*.test.mjs` | 通过输出 |
| H5 预览 | `node tools/shot.mjs` + 隔离库 + 临时开关 | 截图；记录临时 env 与回滚命令 |
| 静态检查 | `php -l`、`artisan route:list`、`git diff --check` | 命令输出 |
| 接口回归 | `bash tools/api-regression.sh` | 通过/失败计数 |

**本机验证的硬约束**：

- 所有 artisan 命令显式带 `DB_DATABASE=summon_mecha_step10_test`。
- 禁止对 root 执行 `migrate:fresh` / `truncate` / `seed`。
- 全量 pest 按文件分进程跑，避免 60 秒超时。
- H5 预览若临时打开 `ECOSYSTEM_EVENT_PUBLIC_ENABLED=true`，必须使用隔离库、仅本地进程，验证后立即恢复，并在 evidence 中写明；不得提交到 `.env` / `.env.example`。

### 9.2 必须 root/生产迁移或生产验证

| 项 | 为什么本机不能证明 | 前置 |
|---|---|---|
| root 主库迁移 G1-02 ~ G1-08+ | root 当前无任何 G1 列/表；8 条 Seeder 样例 + 1 条 RegTest 报名仍存在 | 备份、preflight、维护窗口、逐迁移执行、旧字段比对 |
| MySQL 5.7 DDL 锁表影响 | 本机测试库数据量小，不能代表生产 | 生产相近数据量演练 |
| 常驻 scheduler / cron | 本机没有常驻进程；没起等于不存在 | 部署 `schedule:work` 或 crontab；监控 |
| queue/outbox 真实多进程 | 本机可模拟，但不是生产负载/故障 | 真实 worker 进程、积压/崩溃/租约演练 |
| 真实运营角色与 ACL | 本机测试角色不等于生产角色配置 | 生产角色配置、逐动作验收 |
| 法务/业务签署 B-01/B-03/B-04/B-06/B-07 | 外部审批 | 书面记录；未签署保持关闭 |
| 真实小程序分享/域名 | 本机 H5 不等于小程序 | 真机/开发者工具验收 |
| CDN/第三方副本清除 | G1 明确不做 | G4 另议 |
| 8 条 Seeder 样例不作为公开数据 | 必须保持隐藏/不删除/不改写 | 迁移后核对 `publish_status` 仍为 draft |

### 9.3 诚实标注要求

- 每个批次交付文档必须列出：已完成 / 部分完成 / 未做 / 未验证。
- 不得把“本机测试通过”写成“生产可用”。
- 不得把“命令已注册”写成“定时任务在跑”。
- 不得把“公开开关代码支持”写成“公开已上线”。
- 不得把“媒体门禁服务存在”写成“公开媒体路由已开放”。

---

## 10. 推荐实施顺序

| 顺序 | 批次 | 可并行 | 出口条件 |
|---|---|---|---|
| 0 | ADR 增量修订 A-01 ~ A-06 + B 项签署状态确认 | — | 产品/架构/QA 书面确认；未签署项保持关闭 |
| 1 | G1-08A schema + 审核服务 + 主办方/媒体 Admin 最小面 | — | 提交/批准/拒绝测试通过；Admin ACL 默认拒绝 |
| 2 | G1-08B 发布/排期/暂缓/恢复/撤稿 + outbox 写入 | — | 指针原子切换、outbox 同事务、旧版本保留 |
| 3 | G1-09 worker/租约/恢复/排期扫描/kill switch | 与 G1-10 契约设计并行 | 租约/恢复/排期/503 测试通过；开关默认 false |
| 4 | G1-10 公开 DTO/API/媒体 | 与 G1-09 后半段并行 | 404/410/503、DTO 泄漏、媒体门禁通过 |
| 5 | G1-11 前端 | 依赖 G1-10 契约 | 列表/详情/分页筛选/分享/H5 截图通过 |
| 6 | 全量回归 + 生产迁移演练（不执行） | — | 基线 258+ 不退化；root 迁移手册/备份/回滚演练完成 |

**关键路径**：A-01/A-02 → G1-08A → G1-08B → G1-09 → G1-10 → G1-11。G1-09 的 handler 契约可以与 G1-10 并行，但 kill switch 必须先于公开路由。

---

## 11. 各批 Claude 会话提示要点（省 token 版）

> 每批开新会话时，只贴对应一段 + 当前分支 + “先读 ADR §3/§4/§5、G1-08-11-implementation-plan.md 对应章节”。不要重新全文读所有文档。

### 11.1 G1-08A 会话提示

```
工作区 D:/main，分支 codex/ecosystem-g1-trusted-publishing。只做 G1-08A：
提审/批准/拒绝 + Admin 主办方/媒体/审核最小面。
先读 docs/ecosystem/G1-08-11-implementation-plan.md §3、§8.3、§8.10。
关键约束：
- 先确认 ADR 增量 A-01 已批准；未批准不写迁移。
- 新增 approved_revision_id（events/content_posts），不加 scheduled_publish_at（08B）。
- 媒体引用必须在提交前 approved；不要放开 reviewRef 到 in_review。
- 不写 published_revision_id；不写 outbox 发布事件；不打开任何开关。
- Admin 每个新路由必须登记 ACL；旧 catalog.events 不得复用。
- 所有写操作同一事务 + OperationLogger::logOrFail。
测试：独立库 summon_mecha_step10_test，按文件分进程跑；禁止 root。
交付：改动清单、测试输出、回滚命令、未做项。禁止 git add -A。
```

### 11.2 G1-08B 会话提示

```
工作区 D:/main，分支 codex/ecosystem-g1-trusted-publishing。只做 G1-08B：
发布/排期/暂缓/恢复/撤稿 + 事务 outbox + 已发布对象继续编辑。
先读 docs/ecosystem/G1-08-11-implementation-plan.md §4、§8.1-§8.4、§8.9。
关键约束：
- 先确认 ADR 增量 A-02/A-03/A-04 已批准。
- 新增 scheduled_publish_at + 索引；新增 approved_revision_id（若 08A 未建）。
- 已发布对象 publish_status 保持 published；候选版本用 approved_revision_id/scheduled_publish_at。
- 唯一允许写 published_revision_id 的入口是 PublicationPointerSwitcher。
- outbox 与业务写同事务；dedupe key 含 next_row_version。
- 不实现 worker；不实现公开 API；不打开开关。
- B-01/B-03/B-04/B-06 未签署项保持关闭。
测试：指针原子性、旧版本保留、批准失效、暂缓/撤稿终态、outbox 回滚。
交付：改动清单、测试输出、回滚命令、未做项。禁止 git add -A。
```

### 11.3 G1-09 会话提示

```
工作区 D:/main，分支 codex/ecosystem-g1-trusted-publishing。只做 G1-09：
outbox worker / 租约 / 恢复扫描 / 排期发布扫描 / 公开读取 kill switch。
先读 docs/ecosystem/G1-08-11-implementation-plan.md §5、§8.6。
关键约束：
- MySQL 5.7，无 SKIP LOCKED；短事务 FOR UPDATE，batch<=20。
- lease_owner=host:pid:uuid；结算必须匹配 status/lease_owner/未过期 lease_until。
- 旧 worker 回报一律拒绝；恢复扫描重置过期 processing。
- handler 只核验、不通知；unknown type -> dead + alert。
- 排期失败回退 approved + 清 scheduled_publish_at + audit + alert。
- 所有命令默认开关 false；不声称 scheduler 已在跑。
- kill switch 先于 DB 查询，503 不伪装空。
测试：两连接租约、过期恢复、重试上限、幂等、排期成功/失败、503。
交付：改动清单、测试输出、命令用法、回滚命令、未做项。禁止 git add -A。
```

### 11.4 G1-10 会话提示

```
工作区 D:/main，分支 codex/ecosystem-g1-trusted-publishing。只做 G1-10：
公开 Event/Content/Media DTO 与 API。
先读 docs/ecosystem/G1-08-11-implementation-plan.md §6、§8.7、§8.8。
关键约束：
- 只读当前 published_revision_id；不回落到 working revision。
- 404/410/503 语义严格；撤稿 410 不回显旧内容；媒体对撤稿 404。
- DTO 白名单：不返回 hot/teams/date/status/working_revision_id/review_note/evidence_ref/storage_key/联系人。
- 列表稳定排序 published_at DESC,id DESC；per_page<=50；不做搜索。
- 媒体从私有盘流式输出；no-store；不生成 public URL。
- sanitizer_version 不是当前版本 -> fail-closed。
- 不打开 public_enabled；不实现 SSR/OG/收藏/报名。
测试：404/410/503、DTO 泄漏、分页筛选、媒体门禁、旧开关语义。
交付：改动清单、测试输出、回滚命令、未做项。禁止 git add -A。
```

### 11.5 G1-11 会话提示

```
工作区 D:/main，分支 codex/ecosystem-g1-trusted-publishing。只做 G1-11：
H5/小程序活动/资讯列表与详情、分页筛选、分享、富文本安全渲染。
先读 docs/ecosystem/G1-08-11-implementation-plan.md §7。
关键约束：
- 只消费 G1-10 DTO；不在前端猜报名状态/拼样例。
- 富文本只渲染服务端 body_html；图片 URL 来自 DTO media。
- 加载/错误/空/404/410/503 分开；空态不显示样例。
- H5 复制真实 URL 成功才提示成功；小程序分享真实详情路径。
- 不实现 SSR/OG/搜索/收藏/报名。
- 不打开公开开关；本地预览仅隔离库 + 临时配置，留回滚记录。
测试：node --test tools/tests/*.test.mjs；H5 截图 tools/shot.mjs；375/390px。
交付：改动清单、测试输出、截图、回滚命令、未做项。禁止 git add -A。
```

---

## 12. 验收与停止条件

出现以下任一情况，**停止推进并报告**，不得标记完成：

1. `public_enabled=true` 时仍能返回草稿、暂缓、撤稿内容，或返回未净化 HTML。
2. 发布指针切换与 outbox 不在同一事务；业务回滚后仍有可消费 outbox。
3. 旧 worker 能结算新租约，或过期租约能覆盖新状态。
4. 审批后修改正文/来源/媒体仍能沿用旧批准。
5. `retracted` 能被普通编辑、重试、定时任务恢复。
6. 公开 DTO 返回联系人、内部审核备注、`working_revision_id`、`storage_key`、私有证据。
7. 媒体公开读取只校验 media.active，不校验父对象/当前 revision/ref 状态。
8. B-01/B-03/B-04/B-06/B-07 未签署却开放对应写能力。
9. root 主库被 `migrate:fresh` / `truncate` / `seed`，或 8 条 Seeder 样例被发布/删除/改写。
10. 把“本机测试通过”“命令已注册”“代码已合并”写成“生产可用/定时任务在跑/公开已上线”。

---

## 13. 参考

- `docs/ecosystem/ADR-0001-trusted-publishing.md`
- `docs/ecosystem/Ecosystem-Engineering-Backlog.md`
- `docs/ecosystem/Ecosystem-Events-Content-Plan.md`
- `docs/ecosystem/Execution-Roadmap.md`
- `docs/ecosystem/G1-02-migration-evidence.md`
- `docs/ecosystem/G1-03-organizer-evidence.md`
- `docs/ecosystem/G1-04-revision-evidence.md`
- `docs/ecosystem/G1-05-content-evidence.md`
- `docs/ecosystem/G1-06-07-media-rich-text-evidence.md`
- 代码：`my-bagisto-store/packages/Webkul/Shop/src/{Models,Services/Ecosystem,Http/Controllers/API,Database/Migrations}`、`packages/Webkul/Admin/src/{Http/Controllers/Catalog,Config,Routes,Resources/views/catalog/events}`、`components/{EcoCenter.vue,shouye/dongtai.vue}`、`utils/{api.js,ecosystem-events.js}`、`pages.json`
