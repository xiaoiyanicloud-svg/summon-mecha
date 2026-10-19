# G1 Admin 发布工作台 + 集成修正 —— 交付与实测记录

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | ① H5 分享基址修正；② 公开 DTO 受控 `external_registration_url`；③ outbox 注释修正；④ G1 Admin 发布工作台（活动 / 资讯 / 主办方）；⑤ ACL / 菜单 / 路由 / 逐路由授权；⑥ 并发、预览、表单错误与回归测试；⑦ **G1 Admin 真人可操作补齐**：活动新建、文章元数据编辑、媒体工作台上传/审核/隔离、revision 媒体引用、内容多来源、主办方驳回/暂停；⑧ **最后两个缺口**：草稿 revision 二次编辑（活动 / 资讯）、Admin 媒体预览流 |
| 依据 | `CLAUDE.md`；`docs/ecosystem/G1-08-11-implementation-plan.md` §3/§4/§6/§8；`G1-08B-publication-evidence.md`、`G1-09-worker-evidence.md`、`G1-10-public-api-evidence.md`、`G1-11-frontend-evidence.md` |
| 数据库 | 独立测试库 `summon_mecha_step10_test`；**未触碰 root 主库**、**本批未新增迁移** |
| 开关 | `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled` / kill switch / `event_publish_enabled` / `content_publish_enabled` **仓库默认值均未改动** |
| 结论 | 三个集成修正与 Admin 工作台继续有效；本批补齐后，运营可在后台完成新活动草稿、文章元数据、媒体上传/审核/隔离、revision 媒体引用与引用审核、多来源登记、主办方驳回/暂停，以及原有 revision 审核/排期/发布/处置全链路。**草稿 revision 可二次编辑（指纹 CAS、离开 draft 即拒绝、媒体引用保留），媒体工作台可从私有盘流式预览（仅后台会话，不生成公开 URL）**。媒体上传与审核权限分离，未审核媒体/引用不能提审；活动新建与媒体引用均有真实测试。未进入 G2。 |

---

## 1. 改动清单

### 1.1 集成修正

| 文件 | 改动 |
|---|---|
| `utils/ecosystem-public.js` | 新增 `resolveShareBase(location)`：H5 分享基址 = 当前站点 `origin + pathname`（去掉 `index.html` / 尾斜杠）；`buildShareUrl` 在基址为空时返回空串，不再生成 `/#/...` 伪链接 |
| `pages/ecosystem/event-detail.vue` | 复制链接改用 `h5ShareBase()`（H5 读 `window.location`；非 H5 返回空）；小程序分享仍用 `buildSharePath` |
| `pages/ecosystem/content-detail.vue` | 同上 |
| `tools/tests/ecosystem-public.test.mjs` | 新增 H5 分享基址、子目录 `/h5/index.html`、非 http(s)、空基址、非法外链等用例 |
| `Shop/src/Http/Resources/Ecosystem/EventPublicResource.php` | 新增受控 `external_registration_url`：仅当 revision 值非空且为合法 http/https 时输出；字段不改变发布前置 |
| `Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php` | 新增 DTO 用例：合法 URL 输出；`javascript:` / `ftp:` / 相对路径 / 空值不输出 |
| `config/ecosystem_outbox.php` | 注释中不存在的 `ecosystem:process-outbox --once` 改为真实用法 `--dry-run` / `--limit=N` |

### 1.2 Admin 发布工作台（新增）

| 文件 | 作用 |
|---|---|
| `Admin/src/Http/Controllers/Catalog/Ecosystem/PublishingController.php` | 活动 / 资讯 / 主办方工作台；所有写动作走真实审核、发布、主办方服务；草稿二次编辑直接调用 `EventRevisionService::updateDraft` / `ContentRevisionService::updateDraft`；媒体预览从私有盘流式输出 |
| `Admin/src/Exceptions/AdminPublishingConflictException.php` | Admin 层 revision 目标并发冲突（与 row_version 冲突统一为“已被他人修改，请刷新”） |
| `config/ecosystem_admin.php` | **路由 → 权限**登记表（48 条路由 / 24 个权限 key）；控制器入口读取，漏登记直接 500 |
| `Admin/src/Routes/catalog-routes.php` | 新增 `admin/catalog/ecosystem-publishing` 路由组，路由名 `admin.catalog.ecosystem.*` |
| `Admin/src/Config/acl.php` | 新增 `ecosystem.*` 权限树：events 9 项、content 9 项、organizers 3 项 + 分组父节点 |
| `Admin/src/Config/menu.php` | 新增“生态发布”菜单入口（key=`ecosystem`） |
| `User/src/Http/Middleware/Bouncer.php` | 对 `ecosystem.*` 权限**跳过统一 401**，把授权交给控制器；其余权限行为不变 |
| `Admin/src/Resources/views/catalog/ecosystem-publishing/index.blade.php` | 工作台列表：活动 + 文章 + 主办方；publish_status / disposition / row_version / 公开 revision / 候选 revision |
| `.../content-index.blade.php` | 文章列表 + 新建文章壳 |
| `.../organizer-index.blade.php` | 主办方列表 + 新建 + 核验 |
| `.../events/show.blade.php` | 活动元数据、创建 revision、**最新 draft 二次编辑表单（预填 + 隐藏指纹）**、revision 列表与审核/发布动作、处置动作 |
| `.../content/show.blade.php` | 资讯 revision、**最新 draft 二次编辑表单（预填 + 1~N 来源整组替换）**、来源、审核/发布动作、处置动作 |
| `.../media/index.blade.php` | 媒体列表增加“预览”入口（新窗口，仅 `ecosystem.media.view`） |
| `.../_preview.blade.php` | 独立安全预览页（不套 Admin 布局、不生成公开 token、不输出 storage_key / 私有证据 / 审核备注） |
| `Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php` | 逐路由登记（48 条）、custom 403、super admin 200/302、未登录重定向 |
| `Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php` | 活动/资讯全链路、并发、预览、B-01 外部活动拒绝、默认关闭时 publish 拒绝、**草稿二次编辑（指纹重算 / stale / 非 draft 拒绝 / 多来源整组替换）**、**媒体预览流** |

### 1.3 首批备份（历史）

首批改动前文件已复制到 `.claude/backup/G1-ADMIN/`（保持 `my-bagisto-store` / `utils` / `pages` / `tools` 相对路径）：

```
my-bagisto-store/config/ecosystem_outbox.php
my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php
my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php
my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php
my-bagisto-store/packages/Webkul/Shop/src/Http/Resources/Ecosystem/EventPublicResource.php
my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php
my-bagisto-store/packages/Webkul/User/src/Http/Middleware/Bouncer.php
utils/ecosystem-public.js
tools/tests/ecosystem-public.test.mjs
pages/ecosystem/event-detail.vue
pages/ecosystem/content-detail.vue
```

### 1.4 G1 Admin 真人可操作补齐（本批）

| 文件 | 改动 |
|---|---|
| `Shop/src/Services/Ecosystem/EventDraftService.php` | **新增**。活动草稿壳唯一写入口：必填/枚举/来源三字段校验、`publish_status=draft`、`disposition=normal`、`row_version=0`、三个 revision 指针与发布时间置 NULL、旧展示列安全默认值、创建与 `ecosystem.event.create` 审计同事务 |
| `Admin/.../PublishingController.php` | 新增 `createEvent/storeEvent`、`updateContentMetadata`、`mediaIndex/storeMedia/approveMedia/rejectMedia/quarantineMedia`、活动/内容 revision `media_refs.store` 与 `media_refs.review`、`rejectOrganizer/suspendOrganizer`；多来源解析；`runAction` 纳入媒体异常；修复 `InvalidArgumentException` 未导入导致领域错误未被转回表单的问题；**最后两项**：`updateEventRevisionDraft/updateContentRevisionDraft`（`expected_fingerprint` CAS）与 `previewMedia`（私有盘流式输出） |
| `Admin/.../Routes/catalog-routes.php` | 补齐本批 14 条路由（活动新建 2、文章元数据 1、媒体 5、revision 媒体引用 4、主办方驳回/暂停 2）；**最后两项**再新增 3 条（活动/资讯草稿 `update`、媒体 `preview`）；`route:list` 现为 **48** 条 |
| `config/ecosystem_admin.php` | 48 条路由 → **24** 个权限 key；新增 `ecosystem.media.view/upload/review`；草稿编辑复用 `ecosystem.events.edit` / `ecosystem.content.edit`，媒体预览复用 `ecosystem.media.view` |
| `Admin/src/Config/acl.php` | 新增媒体权限树（`ecosystem.media` + 3 个子权限）；更新活动/文章/主办方权限文案 |
| `Admin/src/Config/menu.php` | 新增“生态媒体”菜单入口（key=`ecosystem.media`） |
| `Admin/.../events/create.blade.php` | **新增**活动新建表单：name / kind / participation_mode / hosting_status / timezone / starts_at / ends_at / source_type / platform_role / organizer_id；创建后跳工作页 |
| `Admin/.../media/index.blade.php` | **新增**媒体工作台：真实文件上传、MIME/大小/尺寸/状态、批准/拒绝/隔离；不输出 `storage_key`；**最后一项**增加“预览”入口 |
| `Admin/.../events/show.blade.php` | 新增 revision 媒体引用区：当前 refs（role/alt/review_status/媒体状态）、整组替换、引用批准/驳回；**最后一项**增加“最新 draft 编辑草稿表单”（预填 + 隐藏 `expected_fingerprint`） |
| `Admin/.../content/show.blade.php` | 新增文章元数据编辑（type/slug/category + `expected_row_version`）；来源改为 5 行、1~N 条；新增媒体引用区；**最后一项**增加“最新 draft 编辑草稿表单”（预填正文与 1~N 来源，整组替换） |
| `Admin/.../organizer-index.blade.php` | 新增驳回 / 暂停动作（`expected_status` + 必填原因） |
| `Shop/src/Models/EventRevision.php` / `ContentRevision.php` | 新增 `mediaRefs()` 关系（按 `sort_order`），供工作页安全展示引用元数据 |
| `Shop/tests/.../EventDraftServiceG1Test.php` | **新增**服务测试：默认值 / 来源 / UTC / 审计 / 越权字段 / 枚举 / 时区 / 三字段与角色一致 |
| `Admin/tests/.../EcosystemPublishingAclG1Test.php` | 路由夹具扩展到 48 条；新增媒体上传与审核权限分离、引用审核归属 `ecosystem.media.review` 的逐路由断言 |
| `Admin/tests/.../EcosystemPublishingWorkflowG1Test.php` | 新增活动新建、文章元数据 CAS/slug 冻结、媒体上传/审核/拒绝/隔离、revision 引用未审核不可提审、媒体变化后旧批准失效、多来源、主办方驳回/暂停；**最后两项**新增活动/资讯草稿二次编辑与媒体预览流用例 |

### 1.5 备份

本批改动前文件已复制到 `.claude/backup/G1-ADMIN-FULL/`（保持仓库相对路径），共 17 个文件；新增文件不在备份中。

最后两项改动前文件已复制到 `.claude/backup/G1-ADMIN-EDIT/`（10 个文件）：控制器、路由、`config/ecosystem_admin.php`、3 个视图、2 个测试文件、2 个证据文档。

---

## 2. 关键实现口径

### 2.1 分享基址

- H5：`window.location.origin + pathname`，去掉 `/index.html` 与尾斜杠；
  - 根部署 → `https://host`
  - 子目录 `/h5/index.html` → `https://host/h5`
  - 生成 `https://host/#/pages/ecosystem/event-detail?id=12`
- 非 H5：`h5ShareBase()` 返回空串；`buildShareUrl` 不再拼出 `/#/...`。
- API 的 `BASE_URL` 仍只用于媒体 / 富文本图片，不再进入分享链接。

### 2.2 `external_registration_url` 的边界

- 仅当 revision 中的值非空、字面为 `http(s)` 且有 host 时输出；空值 / 相对路径 / `javascript:` / `ftp:` / 含空白控制字符一律不输出该字段。
- 该字段只是“已批准版本的展示字段”，**不改变发布前置**：
  - B-01/B-03 未签署时，`source_type=external` 仍在提交阶段被 `PublicationPreconditions` 拒绝；
  - 测试 `B-01/B-03 未签署：外部活动仍不能提交审核` 已覆盖。

### 2.3 授权与 403

Bagisto 的 Bouncer 中间件对“已登记 route 映射”的 custom 角色统一返回 401，无法满足本批“直接 URL / Ajax 一律 403”的要求。处理方式：

1. `config/ecosystem_admin.php` 逐路由登记权限；
2. `Bouncer::checkIfAuthorized()` 对 `ecosystem.*` 跳过统一 401；
3. `PublishingController::authorizeRoute()` 在每个动作入口读取登记表并 `abort(403)`；
4. 漏登记的路由在运行时 500；测试逐路由断言登记表与真实路由完全一致。

`config/acl.php` 仍登记同一批权限 key，供角色分配树与菜单使用；**没有复用 `catalog.events`**。

### 2.4 并发与表单错误

- 所有对已有对象的写动作都带 hidden `expected_row_version`；revision 动作还带 hidden `expected_revision_id`。
- 状态机服务自己做父行锁 + row_version CAS；控制器先做 revision 目标 CAS。
- `EventRevisionConflictException` / `ContentRevisionConflictException` / `AdminPublishingConflictException` 统一转回：
  - 302 回工作页；
  - `errors.action = 已被他人修改，请刷新后再试。`；
  - 不改变库内状态。
- 领域异常（发布前置 / 状态转移 / 来源 / 时间 / slug / 内容不可变）同样转回 `errors.action`，不 500。未知异常继续向上抛。

### 2.5 预览

- 路由：`GET /admin/catalog/ecosystem-publishing/{events|content}/{id}/revisions/{revision}/preview`
- 只允许 `ecosystem.events.view` / `ecosystem.content.view`；
- 响应头：`Cache-Control: no-store...`（Admin 的 `NoCacheMiddleware` 会覆盖为同含 `no-store` 的值）、`X-Robots-Tag: noindex, nofollow`；
- 正文只在 `sanitizer_version` 与当前配置一致时渲染；过期版本 fail-closed，只显示提示；
- 不输出 `storage_key`、`authorization_evidence_ref`、`evidence_ref`、`review_note`、`working_revision_id`；
- 不生成公开预览 token / 公开 URL。

### 2.6 B-06 未签署时的 hosting_status

Admin 元数据的 `hosting_status` 只允许 `review_allowed_hosting_statuses`（当前 `scheduled` / `completed`）。页面明确标注 B-06 未签署；`postponed` / `rescheduled` / `canceled` 不能通过工作台写入。

### 2.7 活动新建入口

- 路由：`GET/POST /admin/catalog/ecosystem-publishing/events`（`events.create` / `events.store`），权限 `ecosystem.events.create`。
- 控制器不直接 `Event::create()`，统一走 `EventDraftService::createDraft()`：
  - 只接受 `name / kind / participation_mode / hosting_status / timezone / starts_at / ends_at / source_type / platform_role / organizer_id`；
  - 发布轴、处置轴、三个 revision 指针、`row_version`、旧展示列与审计字段一律拒绝透传；
  - 初始结论固定 `publish_status=draft`、`disposition=normal`、`row_version=0`，没有任何 revision，因此**不能直接发布**；
  - 旧展示列写入安全默认值（`type=生态活动`、`status=upcoming`、`hot/teams/sort=0` 等），只用于兼容旧后台列表，不进入公开 DTO；
  - 来源三字段必须同时齐全，复用 `EventSourceValidator::assertDraftValues()`（组合、主体存在性、角色一致、非 rejected/suspended）；
  - 时间按活动 IANA 时区解释后以 UTC 落库；
  - 创建与 `ecosystem.event.create` 审计（`logOrFail`）同事务。
- 创建成功后跳活动工作页；工作页没有 revision 时不存在可发布对象。

### 2.8 文章元数据与 slug 冻结

- 工作页新增 `type / slug / category` 表单，走 `ContentPostService::updateDraft()` + hidden `expected_row_version`。
- 只有 `publish_status=draft` 的文章可编辑；stale row_version 回到 `errors.action`。
- 首次发布后 slug 冻结：控制器在调用服务前对“已首次发布 + slug 变化”给出明确错误（含“slug 不可修改”），避免被“非 draft 不可编辑”泛化。

### 2.9 媒体工作台与权限分离

- 路由：`GET media`、`POST media`、`POST media/{id}/approve|reject|quarantine`。
- 权限：`ecosystem.media.view`（列表）、`ecosystem.media.upload`（上传）、`ecosystem.media.review`（批准/拒绝/隔离与引用审核）。上传与审核**不共用**权限。
- 上传走 `MediaService::upload()`：真实文件表单；服务端 finfo 解码真实 MIME、校验扩展名 / `getimagesize` / PDF 头 / 大小 / sha256；落私有盘，无公开 URL。
- 页面只展示 `original_name / mime / byte_size / width / height / status / uploaded_by / created_at`，**不查询也不输出** `disk / storage_key / sha256`。
- 审核动作带 hidden `expected_status` 做页面状态 CAS；stale 时提示“已被他人修改”，不覆盖别人刚做的审核结论。
- 已 rejected / quarantined 的媒体不能原地恢复；换文件必须新建媒体。

### 2.10 revision 媒体引用

- 活动/内容工作页对每条 `draft` revision 展示当前 refs（`media_id / role / alt_text / review_status / 媒体状态`），不输出私有路径。
- 引用写入走 `MediaRefService::replaceForRevision()`：
  - 只有 draft revision 可写（服务 + `EcosystemMediaRef` 模型守卫双重拒绝）；
  - 只允许 `status=approved` 的媒体；
  - `sort_order` 由服务端按表单行顺序重排 0..n-1；
  - 替换后重算 `media_manifest_hash` 与 `content_fingerprint`。
- 引用审核走 `MediaRefService::reviewRef()`，权限 `ecosystem.media.review`；带 hidden `expected_review_status` CAS。
- `PublicationPreconditions` 在提交/批准/发布时要求**每条 ref 与媒体均 approved**，未审核引用不能提审。
- 任何媒体/ref 变化都发生在 draft 上，提交后 revision 冻结；旧批准不会继承，必须重新走 revision 审核。已批准媒体被隔离后，发布前置会重新校验并拒绝。

### 2.11 内容多来源与主办方复核

- 资讯 revision 表单提供 5 行来源，支持 1~N 条；全空行丢弃，至少保留一条；每行 `source_name / source_url / source_verified_at / rights_basis / rights_scope / evidence_ref`。
- 控制器只把整理后的 `sources` 数组交给 `ContentRevisionService`（经 `BeginRevisionService`）；必填组合、`authorized_repost` 的 scope/evidence、URL 与核验时间仍由服务层校验。
- 工作页不再出现“当前每条 revision 只登记一条来源”的提示。
- 主办方新增驳回 / 暂停：`POST organizers/{id}/reject|suspend`，权限 `ecosystem.organizers.verify`，`expected_status` CAS + 必填原因，统一走 `OrganizerService::reject/suspend`。

### 2.12 草稿 revision 二次编辑

- 路由（权限与创建/元数据同口径）：
  - `POST /admin/catalog/ecosystem-publishing/events/{id}/revisions/{revision}/update` → `ecosystem.events.edit`
  - `POST /admin/catalog/ecosystem-publishing/content/{id}/revisions/{revision}/update` → `ecosystem.content.edit`
- 控制器直接调用 `EventRevisionService::updateDraft()` / `ContentRevisionService::updateDraft()`，**不绕过领域服务**：
  - 表单带 hidden `expected_fingerprint`，服务在 revision 行锁内做指纹 CAS；
  - 只有 `review_state=draft` 可编辑；`in_review` / `approved` / `rejected` 即使直接 POST 也被拒绝；
  - 指纹过期统一回 `errors.action = 内容已被他人修改，请刷新后重试；本次编辑未保存。`，**不改库、不静默覆盖**；
  - 指纹按合并后的完整内容重算（活动含来源快照与 `media_manifest_hash`；资讯含有序来源）。
- 活动表单字段：`name / summary / detail_html / city / location_text / participation_mode / timezone / starts_at / ends_at / source_name / source_url / source_verified_at / authorization_basis / change_summary`。
- 资讯表单字段：`title / summary / body_html / author_name / author_org / source_type / original_published_at / correction_note / change_summary` + **1~N 行来源**。
- 资讯来源编辑是**整组替换**：服务层在 draft 守卫下删除旧来源、按输入顺序重排 `sort_order=0..n-1`，并把来源纳入指纹；旧来源不会残留。
- 未进入表单的字段（活动 `authorization_evidence_ref` / `external_registration_url` / 来源三字段快照）保留原值；媒体引用由 `MediaRefService` 维护，编辑正文不会清空引用。
- 页面只在**最新 draft** 上展示“编辑草稿”表单并预填；没有 draft 时提示先创建草稿；非 draft revision 不出现编辑入口。

### 2.13 Admin 媒体预览流

- 路由：`GET /admin/catalog/ecosystem-publishing/media/{id}/preview`，权限 `ecosystem.media.view`。
- 只服务后台已登录且有权限的用户，从私有盘流式输出；不生成公开 URL / 预览 token。
- 响应头：
  - `Content-Type` 取数据库里经 `MediaService` 解码校验的真实 MIME；
  - 图片 `inline`，PDF（及其他非图片）`attachment; filename*=UTF-8''...`；
  - `X-Content-Type-Options: nosniff`；
  - `Cache-Control` 含 `private` + `no-store`（Laravel 会话中间件会再叠加 `max-age=0, must-revalidate, no-cache`）；
  - `X-Robots-Tag: noindex, nofollow`。
- 响应头 / 响应体都不出现 `disk` / `storage_key` / 磁盘路径。
- 记录不存在与私有盘缺件统一 404。

---

## 3. 实测证据

### 3.1 语法 / 视图 / 路由 / 迁移

```bash
/d/php.exe -l <12 个新增/修改 PHP 文件>
# 全部 No syntax errors detected

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe artisan view:cache
# INFO  Blade templates cached successfully.

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe artisan route:list --path=admin/catalog/ecosystem-publishing
# Showing [48] routes

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:status
# G1 迁移 000001 ~ 000016 均为 Ran；本批未新增迁移

cd /d/main && git diff --check
# 无输出（exit 0）
```

路由 → 权限覆盖：

```
routes=48 map=48 (逐路由登记)
unique_permissions=24
ecosystem ACL entries=29（含 5 个分组父节点）
missing=[] stale=[]
```

### 3.2 Admin 工作台（含本批补齐）

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php \
  --no-coverage
```

结果：**23 passed / 590 assertions**（两个文件合并：ACL 7 + 工作流 16）。

覆盖：

- 48 条路由全部登记；24 个权限 key 全部进入 ACL；无 stale 映射；
- custom 角色（只有 `catalog`、无 `ecosystem.*`）对**每个 GET / POST 路由**均 403，且活动 / revision / 文章 / 主办方 / 媒体行不变；
- 媒体上传与审核权限分离：`ecosystem.media.upload` 不能审核/审核引用；`ecosystem.media.review` 不能上传；活动编辑权也不能审核引用；
- super admin 打开工作台、活动/文章/主办方/媒体/新建活动页、两个预览页均 200；空表单写动作 302（校验回表单），不出现 403/500；
- 未登录访问重定向登录；
- 活动新建：草稿默认值、来源三字段、UTC 时间、旧展示列默认值、`ecosystem.event.create` 审计、无 revision；
- 文章元数据：type/slug/category 更新 + row_version 递增；stale 不覆盖；首次发布后 slug 冻结错误含“slug 不可修改”；
- 媒体：真实 PNG 上传（pending）→ 批准 / 拒绝 / 隔离；stale `expected_status` 不改库；伪装成 PNG 的 PHP 被真实 MIME 校验拒绝；页面不出现 `storage_key`；
- revision 媒体引用：写引用后 `media_manifest_hash` / 指纹更新；引用未批准时提交被拒；引用批准后可提交；提交后再替换引用被拒；
- 媒体变化不继承旧批准：已批准媒体被隔离后，发布前置拒绝，`published_revision_id` 仍为 NULL；
- 多来源：资讯 revision 写入 3 条来源并按 sort_order 保持顺序；页面不再提示“只登记一条来源”；
- 主办方：驳回 / 暂停 / 重新核验；`expected_status` stale 不覆盖；原因必填；
- 活动：元数据 → 创建 revision → 提交 → 批准 → 排期 → 取消排期 → 发布 → 暂缓 → 恢复 → 撤稿；
- 资讯：创建文章壳 → revision → 提交 → 批准 → 排期 → 取消排期 → 发布 → 暂缓 → 恢复 → 撤稿；
- 资讯默认关闭时 publish 被拒绝，`published_revision_id` 仍为 NULL，row_version 不变；
- stale `row_version`：提示“已被他人修改”，kind 不变；错误 `expected_revision_id`：revision 仍为 draft；
- 预览：`no-store` / `noindex`，不出现 `PRIVATE-AUTH-EVIDENCE-REF`、`PRIVATE-CONTENT-EVIDENCE-REF`、`g1-admin/private-secret.png`、审核备注；
- **活动草稿二次编辑**：成功保存后指纹变化、`media_manifest_hash` 与媒体引用保留；stale 指纹回 `errors.action`（含“内容已被他人修改”）且库不变；`in_review` / `approved` / `rejected` 直接 POST 均被拒绝且不改库；页面只在 draft 上展示编辑入口；
- **资讯草稿二次编辑**：3 条新来源整组替换旧来源（`sort_order=0..2`、旧来源不残留）、正文重新净化、指纹变化；stale 指纹不改库；`in_review` 后直接 POST 被拒绝；
- **媒体预览流**：PNG 200 + `image/png` + `nosniff` + `private`/`no-store` + `inline`；PDF `application/pdf` + `attachment` + 正确 `Content-Length`；响应头不含 `storage_key` / `ecosystem/media/`；无权限 custom 角色 403；文件缺失与记录不存在 404；媒体列表存在预览入口；
- B-01/B-03 未签署：外部活动 revision 可以建草稿，但提交被拒，父对象仍为 draft。

### 3.2b 新建活动服务

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EventDraftServiceG1Test.php \
  --no-coverage
```

结果：**4 passed / 59 assertions**。覆盖默认值 / 来源 / UTC / 审计 / 越权字段 / 枚举 / 时区 / 三字段与角色一致。

### 3.3 公开 DTO / API

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicEventApiG1Test.php \
  --no-coverage
```

结果：**12 passed / 132 assertions**。

### 3.4 G0 Admin 回归

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Admin/tests/Feature/Catalog/EventAdminAclG0Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/EventFormG0Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/EventRegistrationPiiG0Test.php \
  --no-coverage
```

结果：**23 passed / 329 assertions**（401/403 语义、DataGrid 按钮、PII 遮罩均未退化）。

相邻 Admin 冒烟（菜单 / ACL 加载 / 页面渲染）：

```bash
... SkillReviewConsoleTest.php ContentSecurityTest.php DashboardTest.php SkillCatalogAdminTest.php ...
```

结果：**34 passed / 197 assertions**。

### 3.5 Shop G1 关键回归（分进程）

本批未改审核/发布状态机，但媒体、revision、来源与工作台接线都依赖这些服务，按文件分组实跑：

| 组 | 文件 | 结果 |
|---|---|---|
| 媒体 / 引用 / 内容草稿 / 新建活动服务 | MediaService、MediaRefService、EcosystemContentRevisionService、EcosystemContentPostService、EventDraftService | **69 passed / 407 assertions** |
| 活动草稿 / 来源 / 主办方 / 新 draft | EcosystemEventRevisionService、EcosystemEventSource、EcosystemOrganizerService、EcosystemBeginRevision | **59 passed / 292 assertions** |
| 审核 / 提交前置 | EcosystemEventReview、EcosystemContentReview、EcosystemPublicationPreconditions | **20 passed / 96 assertions** |
| 发布 / 公开 DTO / 公开 API | EcosystemEventPublication、EcosystemContentPublication、PublicDtoLeak、PublicEventApi | **42 passed / 319 assertions** |

四组合计：**190 passed / 1114 assertions**。outbox / 租约 / 到期扫描 / kill switch / 富文本净化本轮未改，沿用 `G1-08-11-completion-evidence.md` 的全量结果（378 passed / 2849 assertions）。

本轮补跑草稿服务（`EcosystemEventRevisionService`、`EcosystemContentRevisionService`、`EventDraftService`、`EcosystemBeginRevision`）：**61 passed / 332 assertions**；两个 `updateDraft` 的 draft 守卫、指纹 CAS 与来源整组替换均未退化。

### 3.6 Node

```bash
cd /d/main && node --test tools/tests/*.test.mjs
```

结果：**36 passed / 0 failed**。

新增（本次或本批之前）覆盖：

- H5 分享基址：根部署 / 子目录 `index.html` / 尾斜杠 / 非 http(s) / 空基址 → 空链接；
- 外部报名：合法 URL 才显示按钮；`javascript:` / 相对路径不显示；
- 旧 DTO 示例字段不进入前端模型。

---

## 4. 未做 / 未验证（明确未做）

本批已补齐：活动新建入口、资讯元数据编辑、媒体上传/审核/拒绝/隔离、revision 媒体引用与引用审核、多来源、主办方驳回/暂停、**草稿 revision 二次编辑、Admin 媒体预览流**。以下仍未做：

| 项 | 状态 | 说明 |
|---|---|---|
| 媒体 / 引用列表分页与搜索 | **未做** | 媒体列表最近 100 条；revision 引用逐条 revision 展示，数据量大后需要分页 |
| 媒体物理清理 / 保留策略 | **未做** | rejected / quarantined 媒体不物理删除；私有盘留存与清理策略待运营/CDN 方案确定 |
| 公开开关 / kill switch / B 项签署开关 | **未改动** | 全部保持默认 false |
| G2 报名写 / 通知 / 报名 DTO | **未做** | 明确不进入 G2 |
| root / 生产迁移 | **未做** | 本批无迁移；`migrate:status` 只在独立测试库执行 |
| 常驻 scheduler / worker | **未启动** | 仅 Admin 手工排期；到期发布仍需 worker/手工命令 |
| 真实多进程并发压测 | **未做** | 并发测试为单进程串行 CAS 模拟 |
| 小程序真机分享 / H5 剪贴板实写 | **未验证** | Node 测试覆盖纯函数；真机/浏览器未操作 |

---

## 5. 风险与后续

1. **Bouncer 的定向例外**：`ecosystem.*` 权限跳过中间件 401，授权完全依赖控制器入口。任何新增路由若忘记在 `config/ecosystem_admin.php` 登记，会在运行时 500、并在 ACL 测试中失败；这是刻意的 fail-closed，但评审时必须保留这条测试。
2. **旧赛事管理仍可写旧字段**：旧 `Catalog\EventController` 仍可创建/编辑 `events` 的旧列；G1 工作台只负责 G1 字段。旧控制器删除有 revision 的活动会触发 FK RESTRICT（500），本批未改旧删除路径。
3. **主办方没有 row_version**：`ecosystem_organizers` 表没有版本列，核验动作使用 hidden `expected_status` 做状态 CAS；与事件/内容的 row_version 语义不完全一致，但同样能阻止“页面过期后静默覆盖”。
4. **预览正文依赖净化版本**：与当前 `sanitizer_version` 不一致时只显示提示，不渲染旧 HTML；运营需要新建 revision 才能预览正文。
5. **B-06 关闭口径**：工作台只允许 `scheduled` / `completed`；已有 `postponed/rescheduled/canceled` 历史对象若保存元数据，必须改选允许值（页面已提示）。
6. **`operation_log.target_id` 仍是 32 位**：与 G1 BIGINT 主键的边界风险继续存在（GAP-15），本批未扩列。
7. **排期只是事实**：Admin 的“排期”只写 `scheduled_publish_at`，不会自动到点发布；必须有 G1-09 worker / 手工 `ecosystem:publish-scheduled`。
8. **列表上限**：工作台列表各取最近 50 / 100 条，没有分页/搜索；数据量上升后需要补分页（本批按“最小但真人可用”）。
9. **工作台入口权限**：`/` 工作台按 `ecosystem.events.view` 授权；只有资讯或主办方权限的角色可直接访问 `/content` / `/organizers`。菜单项 key 为 `ecosystem`，角色分配时需同时授予该父节点（或在 ACL 界面勾选子项时确认父节点被带上）。
10. **媒体权限多了一个 view key**：任务要求的是 `ecosystem.media.upload` / `ecosystem.media.review`；为了让“只有审核权”的角色也能看到待审队列，额外登记了 `ecosystem.media.view`。角色分配时三个 key 与父节点 `ecosystem.media` 都要按需授予。
11. **媒体文件只增不减**：`sha256` 唯一阻止重复上传，但 rejected / quarantined 文件仍留在私有盘；没有物理清理任务。上线前需确定保留期、备份与删除审批。
12. **活动来源/角色必须匹配**：新建活动表单的来源三字段要求 `organizer_id` 对应主体的 `platform_role` 与表单一致；不一致会在服务层被拒（错误可读）。运营需先建对主体，不能靠活动表单“推定”角色。
13. **活动新建只写草稿壳**：`name` 同时写入旧 `events.name`（兼容旧列表）；旧 `type/status` 等展示列使用服务端默认值，不进入公开 DTO。旧赛事管理仍可编辑旧列。

---

## 6. 回滚

无数据库结构变更，回滚只涉及代码与配置。

### 6.1 本批补齐回滚（G1-ADMIN-FULL）

```bash
cd /d/main

# 1) 恢复本批修改文件（回到首批完成后的状态）
for f in \
  my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Catalog/Ecosystem/PublishingController.php \
  my-bagisto-store/config/ecosystem_admin.php \
  my-bagisto-store/config/ecosystem_publishing.php \
  my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php \
  my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php \
  my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/index.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/content-index.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/organizer-index.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/events/show.blade.php \
  my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/content/show.blade.php \
  my-bagisto-store/packages/Webkul/Shop/src/Models/EventRevision.php \
  my-bagisto-store/packages/Webkul/Shop/src/Models/ContentRevision.php \
  my-bagisto-store/packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  my-bagisto-store/packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php; do
  cp ".claude/backup/G1-ADMIN-FULL/$f" "$f"
done

# 2) 删除本批新增
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/EventDraftService.php
rm my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EventDraftServiceG1Test.php
rm my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/events/create.blade.php
rm -r my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing/media
```

### 6.2 首批回滚（G1-ADMIN）

```bash
cd /d/main

# 1) 恢复被修改文件
cp .claude/backup/G1-ADMIN/my-bagisto-store/config/ecosystem_outbox.php \
   my-bagisto-store/config/ecosystem_outbox.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php \
   my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php \
   my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php \
   my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/User/src/Http/Middleware/Bouncer.php \
   my-bagisto-store/packages/Webkul/User/src/Http/Middleware/Bouncer.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/Shop/src/Http/Resources/Ecosystem/EventPublicResource.php \
   my-bagisto-store/packages/Webkul/Shop/src/Http/Resources/Ecosystem/EventPublicResource.php
cp .claude/backup/G1-ADMIN/my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php \
   my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php
cp .claude/backup/G1-ADMIN/utils/ecosystem-public.js utils/ecosystem-public.js
cp .claude/backup/G1-ADMIN/tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-public.test.mjs
cp .claude/backup/G1-ADMIN/pages/ecosystem/event-detail.vue pages/ecosystem/event-detail.vue
cp .claude/backup/G1-ADMIN/pages/ecosystem/content-detail.vue pages/ecosystem/content-detail.vue

# 2) 删除本批新增
rm my-bagisto-store/config/ecosystem_admin.php
rm my-bagisto-store/packages/Webkul/Admin/src/Exceptions/AdminPublishingConflictException.php
rm my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Catalog/Ecosystem/PublishingController.php
rm -r my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/ecosystem-publishing
rm my-bagisto-store/packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php
rm my-bagisto-store/packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php
```

回滚后仍保持所有公开/发布开关默认 false；已存在的 revision / outbox / 审计记录不删除。
