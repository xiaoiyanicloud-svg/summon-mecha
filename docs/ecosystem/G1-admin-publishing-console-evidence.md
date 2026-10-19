# G1 Admin 发布工作台 + 集成修正 —— 交付与实测记录

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | ① H5 分享基址修正；② 公开 DTO 受控 `external_registration_url`；③ outbox 注释修正；④ G1 Admin 发布工作台（活动 / 资讯 / 主办方）；⑤ ACL / 菜单 / 路由 / 逐路由授权；⑥ 并发、预览、表单错误与回归测试 |
| 依据 | `CLAUDE.md`；`docs/ecosystem/G1-08-11-implementation-plan.md` §3/§4/§6/§8；`G1-08B-publication-evidence.md`、`G1-09-worker-evidence.md`、`G1-10-public-api-evidence.md`、`G1-11-frontend-evidence.md` |
| 数据库 | 独立测试库 `summon_mecha_step10_test`；**未触碰 root 主库**、**本批未新增迁移** |
| 开关 | `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled` / kill switch / `event_publish_enabled` / `content_publish_enabled` **仓库默认值均未改动** |
| 结论 | 三个集成修正已落地；Admin 工作台可完成活动/资讯的 revision、审核、排期/发布、暂缓/恢复/撤稿，以及主办方创建/核验；逐路由授权与 stale 并发提示有真实测试；未进入 G2。 |

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
| `Admin/src/Http/Controllers/Catalog/Ecosystem/PublishingController.php` | 活动 / 资讯 / 主办方工作台；所有写动作走真实审核、发布、主办方服务 |
| `Admin/src/Exceptions/AdminPublishingConflictException.php` | Admin 层 revision 目标并发冲突（与 row_version 冲突统一为“已被他人修改，请刷新”） |
| `config/ecosystem_admin.php` | **路由 → 权限**登记表（31 条路由 / 21 个权限 key）；控制器入口读取，漏登记直接 500 |
| `Admin/src/Routes/catalog-routes.php` | 新增 `admin/catalog/ecosystem-publishing` 路由组，路由名 `admin.catalog.ecosystem.*` |
| `Admin/src/Config/acl.php` | 新增 `ecosystem.*` 权限树：events 9 项、content 9 项、organizers 3 项 + 分组父节点 |
| `Admin/src/Config/menu.php` | 新增“生态发布”菜单入口（key=`ecosystem`） |
| `User/src/Http/Middleware/Bouncer.php` | 对 `ecosystem.*` 权限**跳过统一 401**，把授权交给控制器；其余权限行为不变 |
| `Admin/src/Resources/views/catalog/ecosystem-publishing/index.blade.php` | 工作台列表：活动 + 文章 + 主办方；publish_status / disposition / row_version / 公开 revision / 候选 revision |
| `.../content-index.blade.php` | 文章列表 + 新建文章壳 |
| `.../organizer-index.blade.php` | 主办方列表 + 新建 + 核验 |
| `.../events/show.blade.php` | 活动元数据、创建 revision、revision 列表与审核/发布动作、处置动作 |
| `.../content/show.blade.php` | 资讯 revision、来源、审核/发布动作、处置动作；页面标注“当前一条来源，多来源留后续” |
| `.../_preview.blade.php` | 独立安全预览页（不套 Admin 布局、不生成公开 token、不输出 storage_key / 私有证据 / 审核备注） |
| `Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php` | 逐路由登记、custom 403、super admin 200/302、未登录重定向 |
| `Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php` | 活动/资讯全链路、并发、预览、B-01 外部活动拒绝、默认关闭时 publish 拒绝 |

### 1.3 备份

改动前文件已复制到 `.claude/backup/G1-ADMIN/`（保持 `my-bagisto-store` / `utils` / `pages` / `tools` 相对路径）：

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

---

## 3. 实测证据

### 3.1 语法 / 视图 / 路由 / 迁移

```bash
/d/php.exe -l <12 个新增/修改 PHP 文件>
# 全部 No syntax errors detected

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan view:cache
# INFO  Blade templates cached successfully.

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan route:list --path=admin/catalog/ecosystem-publishing
# Showing [31] routes

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:status
# G1 迁移 000001 ~ 000016 均为 Ran；本批未新增迁移

cd /d/main && git diff --check
# 无输出（exit 0）
```

路由 → 权限覆盖（tinker）：

```
routes=31 map=31 missing=[] stale=[]
acl_keys=226 need=21 missing=[]
acl_items=10
```

### 3.2 Admin 工作台（新增）

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingWorkflowG1Test.php \
  --no-coverage
```

结果：**12 passed / 278 assertions**（两个文件合并）。

覆盖：

- 31 条路由全部登记；权限 key 全部进入 ACL；无 stale 映射；
- custom 角色（只有 `catalog`、无 `ecosystem.*`）对**每个 GET / POST 路由**均 403，且活动 / revision / 文章 / 主办方行不变；
- super admin 打开工作台、活动/文章/主办方页、两个预览页均 200；空表单写动作 302（校验回表单），不出现 403/500；
- 未登录访问重定向登录；
- 活动：元数据 → 创建 revision → 提交 → 批准 → 排期 → 取消排期 → 发布 → 暂缓 → 恢复 → 撤稿；
- 撤稿后 publish / restore 均返回表单错误，disposition 保持 `retracted`；
- 资讯：创建文章壳 → revision → 提交 → 批准 → 排期 → 取消排期 → 发布 → 暂缓 → 恢复 → 撤稿；
- 资讯默认关闭时 publish 被拒绝，`published_revision_id` 仍为 NULL，row_version 不变；
- stale `row_version`：提示“已被他人修改”，kind 不变；错误 `expected_revision_id`：revision 仍为 draft；
- 预览：`no-store` / `noindex`，不出现 `PRIVATE-AUTH-EVIDENCE-REF`、`PRIVATE-CONTENT-EVIDENCE-REF`、`g1-admin/private-secret.png`、审核备注；
- B-01/B-03 未签署：外部活动 revision 可以建草稿，但提交被拒，父对象仍为 draft。

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

### 3.5 G1 后端回归（分进程）

| 组 | 文件数 | 结果 |
|---|---|---|
| 审核 / 发布 / 处置 | 8 | **71 passed / 459 assertions** |
| outbox / 租约 / 扫描 / kill switch | 7 | **46 passed / 204 assertions** |
| 草稿 / schema / pointer / organizer / rule | 12 | **161 passed / 1376 assertions** |
| 公开 API / 媒体 / 富文本 / G0 报名闸 | 10 | **88 passed / 678 assertions** |

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

| 项 | 状态 | 说明 |
|---|---|---|
| 媒体上传 / 审核 UI | **未做** | 工作台不提供上传；“媒体 ID 列表”预留，未做假上传 |
| revision 媒体 ID 选择 | **未做** | 正文中 `data-media-id` 会被净化器按“未提供允许集合”移除；页面已标注 |
| 多来源 | **未做** | 资讯每条 revision 当前只登记一条来源；页面已标注 |
| 活动新建入口 | **未做** | 本批按任务只做已有活动的工作台；新活动仍需旧赛事管理/数据初始化后再补 G1 元数据 |
| 资讯元数据编辑（type/slug/category） | **未做** | 只有创建文章壳；修改仍走 `ContentPostService::updateDraft`，本批未接 UI |
| 主办方停用 / 驳回 UI | **未做** | 只做新建 + 核验通过；服务已有 reject/suspend |
| 媒体预览流 | **未做** | 预览只显示媒体元数据（media_id / role / review_status / alt），不输出文件 |
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

---

## 6. 回滚

无数据库结构变更，回滚只涉及代码与配置：

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
