# G1-08 ~ G1-11 可信发布闭环 · 最终交付证据

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | codex/ecosystem-g1-trusted-publishing |
| 范围 | G1-08A/08B、G1-09、G1-10、G1-11、Admin 发布工作台、集成修正、Admin 真人可操作补齐（活动新建 / 元数据 / 媒体 / 引用 / 多来源 / 主办方复核）、草稿 revision 二次编辑与 Admin 媒体预览流 |
| 数据库 | 独立测试库完成全量验证；root 已于 2026-10-19 完成备份、恢复演练和 additive 迁移，详见 [G1-production-cutover-evidence.md](./G1-production-cutover-evidence.md) |
| 公开状态 | 本机 `.env` 已开启活动/资讯公开读取与事件发布；资讯发布仍因 B-04/B-07 未签署保持关闭；kill switch=false。root 尚无真实运营内容，公开列表当前为空。 |
| 运行态 | outbox worker 与到期发布 scheduler 已在本机启用；`schedule:work` PID 25800 常驻并已实测每分钟执行两条 G1 任务 |
| 结论 | 代码、迁移、服务、HTTP、Admin、Node 测试均已在独立库通过。2026-10-19 追加真实数据 H5 E2E：活动/资讯列表与详情、410、503、首页预览、H5 剪贴板均已实测截图，root 主库零写入。root 迁移、恢复演练、本机开关、常驻调度和真实双进程租约竞争已完成；小程序真机分享、Safari/微信内置剪贴板和正式托管主机部署仍未验证。 |

## 1. 已完成范围

- G1-08A：revision 提交/批准/拒绝、审批绑定指纹/媒体 manifest/sanitizer、并发 CAS、事务审计、新草稿失效旧批准。
- G1-08B：发布/排期/取消排期/暂缓/恢复/撤稿、公开指针原子切换、事务 outbox。
- G1-09：outbox 租约/重试/dead/过期恢复、旧 worker 回报拒绝、到期发布扫描、公开读取 kill switch。
- G1-10：活动/资讯/媒体公开 API、显式 DTO、404/410/503、分页筛选、私有盘流式媒体。
- G1-11：独立活动/资讯列表与详情、首页真实预览、分页筛选、分享、受控富文本渲染。
- Admin：活动/资讯 revision 工作台、审核、排期、发布、处置、主办方核验、安全预览、逐路由 ACL。
- Admin 真人可操作补齐：活动 G1 新建入口（`EventDraftService`）、文章元数据编辑、媒体上传/审核/拒绝/隔离工作台、revision 媒体引用与引用审核、内容多来源（1~N）、主办方驳回/暂停。
- Admin 最后两个缺口：草稿 revision 二次编辑（活动 / 资讯，`expected_fingerprint` CAS、draft-only、多来源整组替换）与媒体预览流（`ecosystem.media.view`、私有盘流式输出、nosniff / private,no-store / PDF attachment）。

## 2. 最终验证

### 2.1 Shop G1/G0

本轮（Admin 补齐后）按文件分组的关键回归：

```text
Tests: 190 passed (1114 assertions)
Duration: ~27s
```

覆盖媒体 / 引用 / 内容草稿 / 活动草稿 / 来源 / 主办方 / 审核前置 / 发布 / 公开 DTO/API。上一轮全量 G1-02 ~ G1-11 结果为 **378 passed / 2849 assertions**；本轮未改 outbox / 租约 / 到期扫描 / kill switch / 富文本净化，沿用该结果。

### 2.2 Admin G1/G0

```text
Tests: 23 passed (590 assertions)   # G1 Admin ACL + 工作流
Tests: 23 passed (329 assertions)   # G0 赛事 ACL / 表单 / PII 回归
```

覆盖 G1 Admin 48 条路由逐路由 ACL、媒体上传/审核权限分离、活动新建、文章元数据、媒体工作台、revision 引用、多来源、主办方驳回/暂停、预览、并发（含 stale 引用审核）、草稿二次编辑（stale / 非 draft 拒绝 / 多来源整组替换）、媒体预览流，以及 G0 回归。

### 2.3 前端纯函数

```text
node --test tools/tests/*.test.mjs
tests 36 / pass 36 / fail 0
```

### 2.4 静态与路由

- 本轮 12 个新增/修改 PHP 文件 php -l：0 failures。
- git diff --check：通过。
- `route:list --path=admin/catalog/ecosystem-publishing`：**48 条**（48 条路由 / 24 个权限 key）。
- 公开 API：/api/events 4 条、/api/content/posts 3 条。
- migrate:status：测试库 000001 ~ 000016 均 Ran。
- H5 dev server 375/390 截图：既有活动/资讯空态、活动/资讯 404、首页两个 tab；2026-10-19 追加真实数据 E2E 截图（见 §2.5）。

### 2.5 H5 真实数据 E2E（2026-10-19 追加）

在独立测试库、独立后端进程上使用真实服务写入并截图，未使用 mock / 静态假数据：

| 对象 | ID | 状态 |
|---|---|---|
| 主办方 | `2054` | verified（platform + organizer） |
| 已发布活动 | `4436` | published / normal，revision `2233`，published_at + first_published_at 均非空 |
| 活动媒体 | `1150` | 真实 PNG 1200×800，approved，revision 引用 approved |
| 已发布资讯 | post `1450` / revision `1445` | published，slug `h5-e2e-embodied-ai-open-day`，含来源与 body_html |
| 资讯媒体 | `1151` | 真实 PNG 1200×800，approved，引用 approved |
| 已撤稿活动 | `4437` | published + retracted（曾发布 → 410） |

服务链路：`EventDraftService` → `EventRevisionService` → `MediaService`/`MediaRefService` → `EventReviewService` → `EventPublicationService`；资讯同构。发布开关与 B-04/B-07 只在种子进程 `config()->set`，仓库默认值未改。

真实 API：`/api/events`、`/api/events/4436`、`/api/events/4436/media/1150`（`image/png`，md5 与种子一致）、`/api/content/posts`、`/api/content/posts/{slug}` 均 200；`/api/events/4437` 为 410 `EVENT_UNAVAILABLE`；8010 kill switch 为 503 `SERVICE_UNAVAILABLE`。

截图（`docs/ecosystem/screenshots/`，全部经 view_image 检查）：

- `events-data-375.png` / `events-data-390.png`
- `event-detail-data-390.png`
- `content-data-390.png`
- `content-detail-data-390.png`
- `event-detail-410-390.png`
- `public-read-503-390.png`
- `home-event-feed-data-390.png`

剪贴板（CDP 点击「复制链接」+ `navigator.clipboard.readText()`）：

- 活动详情读回 `http://127.0.0.1:5173/#/pages/ecosystem/event-detail?id=4436`
- 资讯详情读回 `http://127.0.0.1:5173/#/pages/ecosystem/content-detail?slug=h5-e2e-embodied-ai-open-day`
- 两者均不含 `8000` / `8009`。首次 headless 未聚焦时 `readText()` 真实报错 `NotAllowedError: Document is not focused`，补 `Emulation.setFocusEmulationEnabled` + `Page.bringToFront` 后成功；成功原始记录见 `.claude/backup/G1-H5-E2E/clipboard-result-*.json`。

清理与无副作用：8009/8010 进程已停止且端口无 LISTEN；`utils/config.js` 按备份逐字节恢复（md5 `4c637db145ec4da1dbfa76abab7fd20b`）；root 主库只读探针确认 E2E 活动名 / 资讯 slug / 主办方 / 邮箱 / 媒体 sha256 计数全部为 0；未执行 git add/commit。详细证据见 `G1-11-frontend-evidence.md` §6。

## 3. 关键质量修正

1. 已发布对象存在 in_review revision 时禁止再开新 draft，避免并行审核。
2. 已发布对象开新 draft 时清空旧 `approved_revision_id` / `scheduled_publish_at`，防止被取代候选复活。
3. 拒绝后清理失效批准与排期，公开指针不变。
4. H5 分享不再使用 API BASE_URL，改用当前站点 origin/path。
5. `external_registration_url` 受控加入公开 DTO，但 external 活动仍受 B-01/B-03 发布前置约束。
6. Admin `ecosystem.*` 授权改为中间件先 403、控制器再按路由细化，避免新增路由漏授权时静默放行。
7. 新建活动统一走 `EventDraftService`：初始 draft/normal/row_version=0、无 revision、创建与审计同事务，控制器不能直接填发布轴。
8. 媒体上传与审核拆成 `ecosystem.media.upload` / `ecosystem.media.review`；列表另设 `ecosystem.media.view`，避免审核角色看不到队列。
9. revision 媒体引用只允许 draft + approved 媒体；替换后重算 manifest/指纹，未审核引用不能提审，提交后不能再改。
10. 资讯来源表单改为 1~N 行，服务端按输入顺序重排；主办方驳回/暂停使用 `expected_status` CAS + 必填原因。
11. 草稿 revision 支持二次编辑：`expected_fingerprint` CAS + 行锁内 draft-only；stale 明确提示且不改库；资讯来源整组替换并重算指纹；媒体引用与 `media_manifest_hash` 保留。
12. Admin 媒体预览从私有盘流式输出，只认 `ecosystem.media.view`；真实 MIME + nosniff + private/no-store；PDF attachment；不暴露 disk/storage_key，缺件 404。

## 4. 仍未完成 / 未验证

- 正式托管主机上的部署、备份、DDL 锁窗口、配置发布与回滚演练；本机 root 收口不等于外网生产主机已完成。
- root 尚无真实运营赛事/资讯；不能把 8 条 Seeder 样例当作真实内容发布。
- 资讯发布仍需 B-04/B-07 业务/法务签署；工程侧保持 `ECOSYSTEM_CONTENT_PUBLISH_ENABLED=false`。
- 小程序真机运行与分享；Safari / 微信内置浏览器剪贴板；长正文滚动与极端字号。
- 长时间稳定性、故障注入与跨机多进程压测；本次只做了本机真实双进程租约竞争。
- 媒体/引用列表分页与搜索、媒体文件保留/物理清理策略（依赖 B-05）。
- CDN/缓存撤回、报名 G2、搜索/收藏/SSR/OG/sitemap。
- `sent` 只代表 outbox 事实被消费，不代表用户已收到通知；G1 未接通知渠道。

## 5. 证据索引

- [G1-08-11-implementation-plan.md](./G1-08-11-implementation-plan.md)
- [G1-08B-publication-evidence.md](./G1-08B-publication-evidence.md)
- [G1-09-worker-evidence.md](./G1-09-worker-evidence.md)
- [G1-10-public-api-evidence.md](./G1-10-public-api-evidence.md)
- [G1-11-frontend-evidence.md](./G1-11-frontend-evidence.md)
- [G1-admin-publishing-console-evidence.md](./G1-admin-publishing-console-evidence.md)
- [Execution-Roadmap.md](./Execution-Roadmap.md)
