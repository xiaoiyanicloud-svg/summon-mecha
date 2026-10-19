# G1-10 公开 Event / Content / Media API 交付证据

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | G1-10 公开活动 / 资讯 / 媒体 DTO 与 HTTP API；不进入 G1-11 前端 |
| 依据 | `ADR-0001-trusted-publishing.md` G1-D08；`G1-08-11-implementation-plan.md` §6、§8.7~§8.9；G1-09 `PublicReadGate` |
| 数据库 | 独立测试库 `summon_mecha_step10_test`；**root 主库未迁移、未写入** |
| 开关 | `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled` 未改（默认 false）；kill switch 未打开 |
| 结论 | 冻结合同 1~12 全部落地；新增 32 个 G1-10 用例全部通过；33 个既有 G1 回归文件、G0 报名闸用例全部通过。 |

---

## 1. 结论先行

1. `/api/events`、`/api/events/{id}` 已切换为 G1 公开合同；旧 `format()` 与 `hot/teams/date/status/detail` 输出已删除。
2. 新增 `/api/content/posts`、`/api/content/posts/{slug}`，只读 `published_revision_id`。
3. 新增 `/api/events/{id}/media/{mediaId}`、`/api/content/posts/{slug}/media/{mediaId}`，私有盘流式输出，URL 不含 revision / storage key。
4. 公开 DTO 全部显式构造，未序列化任何 Eloquent 模型。
5. `public_enabled` 与 kill switch **保持默认关闭**；公开读取在关闭时不查业务表。

---

## 2. 改动清单

### 2.1 新增

| 文件 | 作用 |
|---|---|
| `Shop/src/Services/Ecosystem/PublicEventQuery.php` | 可见性查询、筛选、`published_at DESC, id DESC` 稳定分页 |
| `Shop/src/Services/Ecosystem/PublicContentQuery.php` | 同上；`first_published_at DESC, id DESC` |
| `Shop/src/Services/Ecosystem/PublicRegistrationState.php` | G1 恒 `can_register=false`；reason 优先级与 `allowed_actions` |
| `Shop/src/Services/Ecosystem/PublicBodyRenderer.php` | `data-media-id` → 受控媒体 URL；未批准 / 未知 ID 删除 |
| `Shop/src/Services/Ecosystem/PublicMediaResolver.php` | 当前 revision 的 approved ref + approved media 批量解析 |
| `Shop/src/Http/Resources/Ecosystem/EventPublicResource.php` | 活动 DTO 白名单 |
| `Shop/src/Http/Resources/Ecosystem/ContentPublicResource.php` | 资讯 DTO 白名单与公开来源 |
| `Shop/src/Http/Resources/Ecosystem/MediaPublicResource.php` | `id/role/alt_text/url` |
| `Shop/src/Http/Controllers/API/ContentController.php` | 资讯列表 / 详情 |
| `Shop/src/Http/Controllers/API/EcosystemMediaController.php` | 活动 / 资讯媒体流式输出 |
| `tests/Feature/Ecosystem/G1PublicApiFixtures.php` | 精确构造发布 / 暂缓 / 撤稿 / 过期净化版本等状态 |
| `tests/Feature/Ecosystem/PublicEventApiG1Test.php` | 活动 API 合同 |
| `tests/Feature/Ecosystem/PublicContentApiG1Test.php` | 资讯 API 合同 |
| `tests/Feature/Ecosystem/PublicMediaApiG1Test.php` | 媒体门禁与响应头 |
| `tests/Feature/Ecosystem/PublicDtoLeakG1Test.php` | DTO 字段与真实值泄漏守卫 |
| `tests/Feature/Ecosystem/PublicVisibilityG1Test.php` | 列表可见性、分页、筛选、正文媒体白名单 |

### 2.2 修改

| 文件 | 改动 |
|---|---|
| `Shop/src/Http/Controllers/API/EventController.php` | 重写 `index/show` 为 G1 合同；`register` 保留 G0 停写语义 |
| `Shop/src/Routes/api.php` | 注册 content 与 media 路由 |
| `Shop/src/Http/Controllers/API/ApiErrorCode.php` | 新增 `EVENT_UNAVAILABLE` / `CONTENT_NOT_FOUND` / `CONTENT_UNAVAILABLE` |
| `Shop/src/Services/Ecosystem/MediaAccessService.php` | 私有盘缺件时记录 `ecosystem.public_media.file_missing` error（仍返回 404） |
| `tests/Feature/API/EventRegistrationGateG0Test.php` | 两个旧合同断言升级为 G1 DTO（`registration.*`、reason 码、恒 false） |

### 2.3 备份

原文件已备份到 `.claude/backup/G1-10/`：

```
my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/ApiErrorCode.php
my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EventController.php
my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php
my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/MediaAccessService.php
```

---

## 3. 冻结合同逐条落地

| # | 合同 | 落地位置 | 证据 |
|---|---|---|---|
| 1 | 列表只返回 published + normal + 有效已批准公开版本；域关 `{data:[]}` 且不查库；kill switch 503 优先 | `PublicEventQuery::visibleBase()`、`EventController::index()` | `PublicEventApiG1Test`、`PublicVisibilityG1Test` |
| 2 | 详情统一 404；retracted 且曾发布 410；中性文案 | `EventController::show()`、`PublicEventQuery::findRetracted()` | `PublicEventApiG1Test` |
| 3 | 资讯列表同构；`first_published_at DESC,id DESC`；分页 | `PublicContentQuery`、`ContentController::index()` | `PublicContentApiG1Test` |
| 4 | 资讯详情 404 `CONTENT_NOT_FOUND` / 410 `CONTENT_UNAVAILABLE` | `ContentController::show()` | `PublicContentApiG1Test` |
| 5 | 媒体先 gate，再解析当前 published revision，调用 `MediaAccessService`；不暴露 revision / storage | `EcosystemMediaController` | `PublicMediaApiG1Test` |
| 6 | `page` / `per_page<=50`；events 6 个筛选、contents 3 个筛选；非法 422；不做搜索 | 两个 Query 的 `applyFilters()`、Controller `indexRules()` | `PublicVisibilityG1Test`、`PublicContentApiG1Test` |
| 7 | DTO 显式构造；字段白名单与永不返回清单 | 三个 Resource | `PublicDtoLeakG1Test`（顶层字段精确比对 + 递归键 + 真实值） |
| 8 | G1 `can_register=false`；reason 优先级；`allowed_actions` 只含 G1 词表 | `PublicRegistrationState` | `PublicEventApiG1Test` |
| 9 | `body_html` 必须当前 sanitizer；过期 fail-closed；媒体列表只来自当前 revision approved | `bodyIsRenderable()` + `countIncompatibleSanitizer()` + `PublicMediaResolver` | `PublicContentApiG1Test`、`PublicVisibilityG1Test` |
| 10 | 真实 MIME / Length / inline / nosniff / no-store；缺文件 404 + 日志；撤稿暂缓媒体 404 | `EcosystemMediaController::headers()`、`MediaAccessService` | `PublicMediaApiG1Test` |
| 11 | 新增三个错误码；统一 `APIController::fail` 信封 | `ApiErrorCode` | 全部测试的 `assertJsonPath('code')` |
| 12 | `PublicReadGate` 503 映射；kill switch 早于 DB | 三个 Controller 的 `publicReadUnavailable()` | `PublicEventApiG1Test`、`PublicVisibilityG1Test` |

### 3.1 可见性 SQL（实际实现）

```sql
JOIN event_revisions AS public_revision
  ON public_revision.id = events.published_revision_id
 AND public_revision.event_id = events.id
WHERE events.publish_status = 'published'
  AND events.disposition = 'normal'
  AND public_revision.review_state = 'approved'
  AND (public_revision.detail_html IS NULL
       OR public_revision.sanitizer_version = 'g1-07-v1')
ORDER BY events.published_at DESC, events.id DESC
```

资讯把 `event_revisions` 换成 `content_revisions`，正文列换成 `body_html`，排序换成
`first_published_at DESC, id DESC`。

---

## 4. 路由证据

命令：

```bash
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan route:list --path=api/events --json
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan route:list --path=api/content --json
```

真实输出（节选）：

```
GET|HEAD  api/events                       shop.api.events.index       EventController@index
GET|HEAD  api/events/{id}                  shop.api.events.show        EventController@show
GET|HEAD  api/events/{id}/media/{mediaId}  shop.api.events.media       EcosystemMediaController@event
POST      api/events/{id}/register         shop.api.events.register    EventController@register

GET|HEAD  api/content/posts                      shop.api.content.posts.index  ContentController@index
GET|HEAD  api/content/posts/{slug}               shop.api.content.posts.show   ContentController@show
GET|HEAD  api/content/posts/{slug}/media/{mediaId} shop.api.content.posts.media EcosystemMediaController@content
```

---

## 5. 真实响应摘要

探针：`storage/app/g1-10-verify/probe.php`（仅在 `summon_mecha_step10_test`
事务内插入夹具，`finally` 回滚；已复核探针数据 0 残留）。

命令：

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe storage/app/g1-10-verify/probe.php
```

### 5.1 活动列表 `200`

```json
{
  "data": [{
    "id": 3423,
    "kind": "salon",
    "name": "公开活动名称",
    "summary": "公开活动摘要",
    "organizer": {"name": "召唤机甲", "platform_role": "organizer"},
    "source": {"name": "召唤机甲官方", "url": "https://example.com/events/1"},
    "participation_mode": "offline",
    "city": "上海",
    "location_text": "张江科学会堂",
    "timezone": "Asia/Shanghai",
    "starts_at": "2026-11-01T09:00:00+08:00",
    "ends_at": "2026-11-01T18:00:00+08:00",
    "published_at": "2026-10-19T12:00:00+08:00",
    "first_published_at": "2026-10-19T12:00:00+08:00",
    "revision_no": 1,
    "hosting_status": "scheduled",
    "body_html": "<p>公开正文</p>",
    "registration": {
      "can_register": false,
      "registration_reason": "registration_disabled",
      "allowed_actions": ["view_detail"]
    },
    "media": []
  }],
  "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 1}
}
```

### 5.2 资讯详情 `200`

```json
{
  "data": {
    "slug": "g1-10-probe-post",
    "type": "news",
    "title": "公开文章标题",
    "summary": "公开文章摘要",
    "author": {"name": "张三", "org": "召唤机甲编辑部"},
    "sources": [{
      "name": "官方原文",
      "url": "https://example.com/news/1",
      "verified_at": "2026-10-18T12:00:00+08:00",
      "rights_basis": "platform_original",
      "rights_scope": "全文"
    }],
    "published_at": "2026-10-19T12:00:00+08:00",
    "first_published_at": "2026-10-19T12:00:00+08:00",
    "revision_no": 1,
    "correction_note": null,
    "body_html": "<p>公开文章正文</p>",
    "media": []
  }
}
```

来源中的 `evidence_ref=PRIVATE-EVIDENCE-REF` 未出现在响应中。

### 5.3 kill switch `503`

```json
{"success":false,"code":"SERVICE_UNAVAILABLE","message":"公开内容暂时不可用，请稍后重试。"}
```

---

## 6. 测试证据

### 6.1 G1-10 新增

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicEventApiG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicContentApiG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicMediaApiG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicDtoLeakG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/PublicVisibilityG1Test.php \
  --no-coverage
```

真实结果：

```
Tests:    32 passed (294 assertions)
Duration: 7.47s
```

### 6.2 G0 报名闸（已升级为 G1 DTO）

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/API/EventRegistrationGateG0Test.php --no-coverage
```

```
Tests:    7 passed (43 assertions)
```

G0 的两个旧断言改为：

- `data.*.registration.can_register === false`（G1 恒 false；G2 未接，即使双闸打开也不得 true）；
- `registration_reason === 'registration_disabled'`（G1 稳定原因码，不再回显停写文案）。

停写入口（`POST register` 在双闸关闭时 403、检查早于查询）保持原样并通过。

### 6.3 既有 G1 回归（逐文件运行，避开本机 60s 进程时限）

33 个既有 `tests/Feature/Ecosystem/*G1Test.php` 全部通过：

```
Tests:    338 passed (2497 assertions)
```

> 按文件逐个启动 Pest 是本机限制（控制器 `set_time_limit` 是进程级），
> 与 G1-10 改动无关；所有文件均指向 `summon_mecha_step10_test`。

### 6.4 静态检查

```bash
/d/php.exe -l <每个新增/修改的 PHP 文件>   # 全部 No syntax errors detected
cd /d/main && git diff --check              # 无输出
```

---

## 7. 未做项（明确未做，不是“已完成”）

| 项 | 状态 |
|---|---|
| G1-11 H5 / 小程序前端（活动/资讯页面、媒体展示、路由跳转） | **未做** |
| 打开 `ecosystem.events.public_enabled` / `ecosystem.content.public_enabled` | **未做**（保持默认 false） |
| 打开 kill switch | **未做**（保持 false） |
| Admin 预览路由 / 预览媒体 | **未做**（G1-11/后续批次） |
| 报名写入口接入 G2、`register` / `waitlist` 动作 | **未做**（G1 恒 `can_register=false`） |
| 全文搜索 / ES / 收藏 / 评论 | **未做**（搜索留 G4-01） |
| SSR / canonical / OG / sitemap | **未做**（G4-04） |
| 数据库迁移 / root 主库写入 / `migrate:fresh` / truncate / seed | **未做** |
| OpenAPI / 字段字典文件更新（Q-01） | **未做**；DTO 白名单以 Resource 与测试为准 |

---

## 8. 风险与后续

1. **旧前端仍是旧合同**：`utils/ecosystem-events.js` 读取扁平
   `registration_reason` / `can_register`。因两个域开关默认 false，线上不会
   因此返回错误数据；G1-11 必须在打开开关前改为 `registration.*`。
2. **列表外正文过期只记录聚合计数**：列表 SQL 直接排除
   `sanitizer_version` 不匹配的行，并用一次 count 查询记录
   `ecosystem.public_event/content.sanitizer_version_mismatch`（`scope=list`）。
   详情会在日志中记录对象与 revision ID。这里刻意不把旧 HTML 作为降级输出。
3. **media[] 不预检文件存在性**：DTO 按合同只列 approved ref + approved media；
   若私有盘缺件，图片请求会得到 404 并记录 error。公开页可能出现单张破图，
   但不会 500，也不会泄露路径。
4. **PDF 使用 `attachment`**：规划 §6.5 允许“PDF attachment（或按产品确认）”。
   本批按更安全的 attachment 落地；若产品要求浏览器内联，需单独确认后改一行。
5. **每页一次额外 count 查询**：仅用于过期净化版本的 error 观测；可接受，
   后续如列表 QPS 上升可改为采样日志。
6. **媒体缓存**：G1 固定 `private, no-store`，不接 CDN；G4 接 CDN 时必须补
   撤回 / purge / 签名有效期。
7. **外部活动**：B-01/B-03 未签署，发布前置仍拒绝 external；公开 DTO 的
   `external_only` + `open_external_registration` 只为未来放行准备，当前
   正常数据不会出现。

---

## 9. 回滚

无数据库结构变更，回滚只涉及代码：

```bash
cd /d/main

# 1) 恢复被改文件
cp .claude/backup/G1-10/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EventController.php \
   my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EventController.php
cp .claude/backup/G1-10/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/ApiErrorCode.php \
   my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/ApiErrorCode.php
cp .claude/backup/G1-10/my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php \
   my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php
cp .claude/backup/G1-10/my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/MediaAccessService.php \
   my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/MediaAccessService.php

# 2) 删除新增文件（示例）
rm my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/ContentController.php
rm my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EcosystemMediaController.php
rm -r my-bagisto-store/packages/Webkul/Shop/src/Http/Resources/Ecosystem
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicEventQuery.php
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicContentQuery.php
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicRegistrationState.php
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicBodyRenderer.php
rm my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicMediaResolver.php
rm my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/Public*G1Test.php
rm my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/G1PublicApiFixtures.php
```

回滚前确认两个域开关仍为 false；媒体路由回滚后已公开页面会暂时无法加载图片，
必须先关域开关再回滚（与规划 §6.9 一致）。

---

## 10. 闸门复核

- `php -l`：全部新增/修改 PHP 文件通过。
- `artisan route:list`：7 条路由存在且 action / name 正确。
- `git diff --check`：通过。
- 既有 G1 回归：33 文件 / 338 用例通过。
- G0 报名闸：7 用例通过。
- G1-10：32 用例 / 294 断言通过。
- 未执行 `git add` / `commit` / `push`。
