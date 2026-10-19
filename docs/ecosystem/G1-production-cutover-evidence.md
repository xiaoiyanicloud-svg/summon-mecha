# G1 生产收口证据（本机运行环境）

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | root 备份/迁移、公开读取、worker/scheduler、H5 真实数据、双进程租约 |
| 结论 | 本机运行环境的 G1 技术收口已完成；root 已迁移，事件公开读取与调度已启用。真实运营内容尚未录入，因此公开列表当前为空，不宣称已有赛事/资讯运营数据。 |

## 1. root 备份与恢复演练

- 备份：`D:\main\backups\root-pre-g1-20261019-210256.sql`（1,530,144 bytes）
- 工具：MySQL 5.7.26 `mysqldump --single-transaction --quick --routines --triggers --events`
- 恢复演练：导入临时库 `root_restore_g1_20261019`
  - 186 张表
  - `events=8`
  - `event_registrations=1`
- 恢复校验通过后已删除临时库；备份文件继续保留。

## 2. root 迁移

执行：

```text
php artisan migrate --force
```

结果：`2026_10_19_000001` ~ `000016` 全部 DONE。

迁移后：

- 表数：186 → 195
- `events=8`、`event_registrations=1` 不变
- 旧字段快照 SHA-256（迁移前后一致）：
  `a997fe6c5310380ab146c0b605c45641c2f6642905fc2580ad6587c866435867`
- 8 条样例仍为 `publish_status=draft` / `disposition=normal` / `row_version=0`
- 8 条 `published_revision_id` / `approved_revision_id` / `scheduled_publish_at` 均为 NULL
- 未生成历史 revision，未改写/删除样例与报名

## 3. 本机运行开关

`.env` 已备份到 `.claude/backup/root-env-pre-g1-20261019-210929.env`，当前设置：

```text
ECOSYSTEM_EVENT_REVIEW_ENABLED=true
ECOSYSTEM_CONTENT_REVIEW_ENABLED=true
ECOSYSTEM_EVENT_PUBLISH_ENABLED=true
ECOSYSTEM_CONTENT_PUBLISH_ENABLED=false
ECOSYSTEM_SCHEDULER_ENABLED=true
ECOSYSTEM_OUTBOX_WORKER_ENABLED=true
ECOSYSTEM_EVENT_PUBLIC_ENABLED=true
ECOSYSTEM_CONTENT_PUBLIC_ENABLED=true
ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED=false
```

说明：

- 事件公开读取与事件发布已启用。
- 资讯公开读取已启用，但资讯发布仍关闭：B-04 / B-07 未完成业务/法务签署，不能由工程擅自放开。
- 报名双闸仍关闭；G2 未上线。
- kill switch 当前正常开放（false）；紧急时可改为 true，公开 API/媒体统一 503。
- `.env.example` 已同步新增全部 G1 环境变量模板，默认值仍为安全关闭。

## 4. 进程与调度

- 8000 后端：已重启加载新 `.env`，`GET /api/events`、`GET /api/content/posts` 返回 200 空列表（root 尚无公开运营数据）。
- scheduler：`php artisan schedule:work`
  - 进程 PID `25800`
  - 日志：`my-bagisto-store/storage/logs/schedule-work.out.log`
  - 日志实测 2026-10-19 21:11 执行：
    - `ecosystem:process-outbox`
    - `ecosystem:publish-scheduled`
- 手工 dry-run：
  - `worker_enabled=true`
  - `scheduler_enabled=true`
  - 当前 0 条待领取 outbox、0 条到期候选
- `schedule:list` 已显示两条每分钟任务。

## 5. 真实双进程租约竞争

独立测试库中只放 1 条 pending outbox，同时启动两个 PHP 进程调用 `OutboxLeaseService::claim(1)`：

```json
{"pid":27032,"claimed":1,"ids":[999]}
{"pid":29848,"claimed":0,"ids":[]}
```

数据库结果：

```json
{"status":"processing","lease_owner":"MICROSO-2GOS0BB:27032:...","attempts":1}
```

结论：同一 outbox 只会被一个真实进程领取。测试记录在 `.claude/backup/G1-PROD/`，测试行已清理。

## 6. H5 真实数据 E2E

已使用独立测试库 + 8009/8010 临时后端完成：

- 活动/资讯列表和详情真实数据
- 媒体图片真实流式输出
- 410 撤稿
- 503 kill switch
- 首页活动/资讯 tab
- H5 复制链接真实读回

截图位于 `docs/ecosystem/screenshots/`，详见 `G1-11-frontend-evidence.md` §6。

## 7. 当前真实运营边界

- root 没有真实运营赛事/资讯；2026-10-19 起 root 存在 3 条测试活动 + 3 条测试资讯（名称/标题均带 `【测试】`，见 §9），它们只是试效果用的测试数据，不是运营内容。
- 不应把 8 条 Seeder 样例当作运营内容发布。
- 资讯发布待 B-04/B-07 签署后开启 `ECOSYSTEM_CONTENT_PUBLISH_ENABLED=true`。
- Admin 媒体文件保留/清理策略仍依赖 B-05，不做自动物理清理。
- 小程序真机分享、Safari/微信内置浏览器剪贴板仍需目标设备验证。
- 正式托管环境需按同一备份/迁移/`.env`/常驻进程清单再执行一次；本机结果不等于外部生产主机已完成部署。

## 8. 回滚

1. 先将 `ECOSYSTEM_EVENT_PUBLIC_ENABLED` / `ECOSYSTEM_CONTENT_PUBLIC_ENABLED` 改为 false。
2. 将 `ECOSYSTEM_EVENT_PUBLISH_ENABLED` / `ECOSYSTEM_SCHEDULER_ENABLED` / `ECOSYSTEM_OUTBOX_WORKER_ENABLED` 改为 false。
3. 停止 `schedule:work`。
4. 代码回滚到 G1 前的提交。
5. 数据库恢复使用备份 `root-pre-g1-20261019-210256.sql`；必须先演练到临时库再切换。
6. 不清空 outbox / revision / 审计记录；按恢复演练结果整体回切数据库快照。

## 9. root 测试数据（2026-10-19，试效果用，非运营内容）

目标：在本机 root 库验证活动/资讯公开链路的真实效果。全部数据通过 G1 服务创建，未用 SQL 伪造发布状态。

### 9.1 创建/复用结果

| 对象 | ID | 说明 |
|---|---|---|
| 主办方 `召唤机甲（上海）科技有限公司` | `1` | `OrganizerService` 创建并 `verify`，`platform_role=organizer`，来源为测试保留域名 `https://test.ecosystem.invalid/root-testdata/...` |
| 活动媒体（第一轮 events 占位图，已退出公开引用） | `1` | sha256 `adef7dd6...08c12`，`MediaService` 上传 + `approve`；旧版占位图含历史样例文字 |
| 资讯媒体（第一轮 content 占位图，已退出公开引用） | `2` | sha256 `a431d79a...ba806`，`MediaService` 上传 + `approve`；旧版占位图含历史样例文字 |
| 活动媒体（第二轮干净测试横幅） | `3` | `image/png`，1200×800，sha256 `444f6415...fea583`，`test-event-banner.png` |
| 资讯媒体（第二轮干净测试横幅） | `4` | `image/png`，1200×800，sha256 `2ddbf25f...635d94`，`test-content-banner.png` |
| 【测试】具身智能开放日 · 张江 | event `9` / revision `1`（旧）→ revision `4`（当前公开） | `open_day` / `offline` / `scheduled`，2026-11-15 09:30–17:30 |
| 【测试】机器人格斗邀请赛 · 上海站 | event `10` / revision `2`（旧）→ revision `5`（当前公开） | `competition` / `offline` / `scheduled`，2026-12-05 10:00–18:00 |
| 【测试】机器人技能工作坊 · Agent 入门 | event `11` / revision `3`（旧）→ revision `6`（当前公开） | `workshop` / `hybrid` / `scheduled`，2026-12-12 14:00–17:00 |
| 【测试】赛事与动态资讯频道上线说明 | content post `1` / revision `1`（旧）→ revision `4`（当前公开） | `announcement`，slug `test-ecosystem-channel-launch` |
| 【测试】机器人赛事报名将在 G2 阶段开放 | content post `2` / revision `2`（旧）→ revision `5`（当前公开） | `news`，slug `test-event-registration-g2` |
| 【测试】开发者入驻与技能生态进展 | content post `3` / revision `3`（旧）→ revision `6`（当前公开） | `news`，slug `test-developer-ecosystem-progress` |

执行链路（每项均走真实服务）：

- 活动：`EventDraftService` → 媒体上传/复用 + `approve` → `EventRevisionService`（`detail_html` 含 `data-media-id`）→ `MediaRefService::replaceForRevision` + `reviewRef(approved)` → `EventReviewService::submit/approve` → `EventPublicationService::publishNow`
- 资讯：`ContentPostService` → 媒体上传/复用 + `approve` → `ContentRevisionService`（`body_html` 含 `data-media-id`、`source_type=original` / `rights_basis=platform_original`）→ refs `approved` → `ContentReviewService::submit/approve` → `ContentPublicationService::publishNow`
- 第二轮（见 §9.6）改为在已发布对象上 `BeginRevisionService::startEventRevision` / `startContentRevision` 开 draft，再走同一审核/发布链路。
- 资讯 B-04 / B-07：只在种子脚本进程内 `config()->set(..., true)`；`.env` 未改，`ECOSYSTEM_CONTENT_PUBLISH_ENABLED=false` 与仓库默认保持关闭。

种子脚本与结果：`.claude/backup/G1-ROOT-TESTDATA/seed.php`、`seed-result-first-run.json`、`seed-result.json`（第一轮）；`.claude/backup/G1-ROOT-TESTDATA/seed-clean-banners.php`、`seed-clean-banners-result-first-run.json`、`seed-clean-banners-result.json`（第二轮，脚本均未提交）。

### 9.2 公开 API 验证

| 校验 | 结果 |
|---|---|
| `GET /api/events?per_page=20` | 200；`meta.total=3`；返回 event `11/10/9`，均带 `【测试】` 前缀；封面为新媒体 `3` |
| `GET /api/events/{9,10,11}` | 全部 200；名称/城市/地点/参与方式/起止时间与定义一致；`body_html` 含 `data-media-id="3"` 与 `/api/events/{id}/media/3` |
| `GET /api/content/posts?per_page=20` | 200；`meta.total=3`；返回 3 个测试 slug；封面为新媒体 `4` |
| `GET /api/content/posts/{slug}` | 全部 200；标题/摘要/来源正确；`body_html` 含 `data-media-id="4"` 与受控媒体 URL |
| `GET /api/events/{9,10,11}/media/3` | 全部 200，`image/png`，233,358 bytes |
| `GET /api/content/posts/{slug}/media/4` | 全部 200，`image/png`，235,032 bytes |
| 旧媒体 `1` / `2` | 记录与文件仍保留，但已不在任何当前公开 revision 中；对应 URL 不再由公开接口渲染 |

### 9.3 原有数据未变

- 原 8 条 Seeder 活动旧字段快照 SHA-256：`a997fe6c5310380ab146c0b605c45641c2f6642905fc2580ad6587c866435867`，第一轮与第二轮执行前后均一致（与 §2 迁移前记录相同）。
- `event_registrations` 仍为 `1` 条。
- 未删除/改写原 8 条活动和 1 条报名；未删除旧媒体 `1` / `2` 与旧 revision `1`–`3`。
- 当前计数：`events=11`（8 原有 + 3 测试）、`event_revisions=6`（每活动 1 旧 + 1 新）、`content_posts=3`、`content_revisions=6`、`ecosystem_media=4`、`ecosystem_media_refs=12`。

### 9.4 截图（8000 + 5173 真实数据）

| 文件 | 页面 |
|---|---|
| `docs/ecosystem/screenshots/root-test-events-390.png` | `/#/pages/ecosystem/events` |
| `docs/ecosystem/screenshots/root-test-event-detail-390.png` | `/#/pages/ecosystem/event-detail?id=9` |
| `docs/ecosystem/screenshots/root-test-content-390.png` | `/#/pages/ecosystem/content` |
| `docs/ecosystem/screenshots/root-test-content-detail-390.png` | `/#/pages/ecosystem/content-detail?slug=test-ecosystem-channel-launch` |

已按第二轮新 revision 重拍并逐张用图片查看工具检查：列表/详情均为 root API 返回的 `【测试】` 数据，封面与正文图是干净测试横幅（`test-event-banner.png` / `test-content-banner.png`），画面中不再出现「机甲格斗联盟 / 宇树科技」等旧样例文字。

### 9.5 边界与风险

- 这些是**测试数据**，不是运营真实内容，也不是对真实合作方的冒称。
- 来源 URL 统一使用测试保留域名 `https://test.ecosystem.invalid/root-testdata/...`，不解析、不冒充真实合作。
- 第一轮占位图 `events-current-placeholder.jpg` / `content-current-placeholder.jpg` 本身是旧版样例页面的截图，画面中带有「机甲格斗联盟 / 宇树科技」等历史示例文字。第二轮已把当前公开 revision 的正文图与封面全部切到干净测试横幅；旧媒体 `1` / `2` 与旧 revision 按「不删除」要求保留在库内，但公开接口不再引用。
- 脚本幂等：第一轮第二次执行时 6 项均为 `reused+already-published`；第二轮第二次执行时 6 项均为 `skipped-already-published`，媒体为 `reused`，计数不变，未重复创建 revision。
- 未改代码、开关、worker、scheduler；未 `git add/commit`。

### 9.6 第二轮 clean-banner revision（2026-10-19，替换旧占位图）

第一轮截图不合格的原因不是正文数据，而是媒体文件本身含旧样例文字。第二轮只替换内容/媒体版本，不改标题、slug、时间、来源、主办方。

流程（每个对象）：

1. 用 `MediaService::upload` 上传 1200×800 PNG（服务端 finfo 判定真实 MIME 为 `image/png`）并 `approve`。
2. 在**已发布**对象上调用 `BeginRevisionService::startEventRevision` / `startContentRevision` 开新 draft；此时 `published_revision_id` 仍指向旧 revision。
3. 新 revision 的 `detail_html` / `body_html` 引用新横幅 `data-media-id`；`MediaRefService::replaceForRevision` 挂 `cover`，再 `reviewRef(approved)`。
4. `submit` → `approve` → `publishNow`；公开指针切到新 revision，旧 revision 保留。
5. B-04 / B-07 仍只在脚本进程内 `config()->set`，`.env` 未改。

| 对象 | 新 revision | 当前公开指针 | 正文/封面媒体 | 旧 revision 保留 |
|---|---|---|---|---|
| event `9` | `4`（revision_no 2） | `4` | `3` | `1` |
| event `10` | `5`（revision_no 2） | `5` | `3` | `2` |
| event `11` | `6`（revision_no 2） | `6` | `3` | `3` |
| post `1` | `4`（revision_no 2） | `4` | `4` | `1` |
| post `2` | `5`（revision_no 2） | `5` | `4` | `2` |
| post `3` | `6`（revision_no 2） | `6` | `4` | `3` |

新 media：

| 媒体 ID | 文件 | MIME | 尺寸 | sha256 | 状态 |
|---|---|---|---|---|---|
| `3` | `.claude/backup/G1-ROOT-TESTDATA/banners/test-event-banner.png` | `image/png` | 1200×800 | `444f6415c00057406aa0a9b2f6d0ab44a8108af7ce292ec12055ddb6b8fea583` | approved |
| `4` | `.claude/backup/G1-ROOT-TESTDATA/banners/test-content-banner.png` | `image/png` | 1200×800 | `2ddbf25f1851bc092b469e6ba4cec9068d17566ac98e34c8d41d4b3cc6355d94` | approved |

复核：

- 6 个对象 `publish_status=published`、`working_revision_id=NULL`、`row_version=7`，`published_revision_id` 指向新 revision。
- 旧/新 revision 除 `detail_html` / `body_html`、`media_manifest_hash`、`content_fingerprint`、`change_summary` 与审计字段外，标题、slug、起止时间、来源快照逐字段一致。
- 原 8 条 Seeder 旧字段哈希仍为 `a997fe6c5310380ab146c0b605c45641c2f6642905fc2580ad6587c866435867`；报名仍 `1`。
- 计数：`event_revisions=6`、`content_revisions=6`、`ecosystem_media=4`、`ecosystem_media_refs=12`。
- 公开 API 与 4 张 390 宽截图均复验通过。
