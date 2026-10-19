# G1-11 前端（H5 / 小程序）交付证据

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | 活动 / 资讯列表与详情页、首页 EcoCenter / Dongtai 预览、公开 DTO 前端归一化、分享、富文本安全渲染 |
| 依据 | `docs/ecosystem/G1-08-11-implementation-plan.md` §7、§8.7；`G1-10-public-api-evidence.md` |
| 数据库 | 2026-10-19 追加的真实数据 E2E **仅在独立测试库 `summon_mecha_step10_test`** 通过真实服务写入；root 主库只读探针确认种子数据 0 条 |
| 开关 | 仓库 `config/` 与 `.env` 默认值**未改**；仅在 8009/8010 测试进程的环境变量中临时开启，进程已停止 |
| 结论 | 4 个独立页面 + 首页预览改造完成；新增 18 个 Node 用例，全部 Node 测试 35/35 通过。2026-10-19 追加真实数据 H5 E2E：活动/资讯列表与详情、410、503、首页预览、H5 剪贴板均已实测并截图；**小程序真机分享仍未验证**（见 §6） |

---

## 1. 结论先行

1. 新增 `pages/ecosystem/events.vue`、`event-detail.vue`、`content.vue`、`content-detail.vue`，并在 `pages.json` 注册（共 26 个页面，原 22 + 4）。
2. 首页 `EcoCenter` 赛事 tab 改为 G1 公开 API 的 compact 预览：点击进入独立详情，提供「查看全部」；**inline 旧详情、报名弹窗、`hot/teams/date/status/detail` 依赖与假报名交互全部删除**。
3. `Dongtai` 改为真实资讯预览：加载 / 错误 / 空三态分开，保留诚实空态，点击进入资讯详情，提供「查看全部」。
4. 新增 `utils/ecosystem-public.js`：纯函数、零依赖，负责归一化、分页、状态/日期、错误映射、富文本白名单、报名只读展示与分享。
5. `utils/api.js`：`eventApi.getEvents(params)` 支持筛选/分页；新增 `contentApi.getPosts(params)` / `getPost(slug)`；**移除前端 `eventApi.register`**。
6. 页面四态（loading / error / empty / data）分离；404 / 410 / 503 分别显示独立文案与图标，不伪装空态。
7. 报名区只读：只认服务端 `registration.can_register` / `allowed_actions`；G1 不出现 `waitlist`、不伪造报名成功。

---

## 2. 改动清单

### 2.1 新增

| 文件 | 作用 |
|---|---|
| `pages/ecosystem/events.vue` | 活动列表：kind / hosting_status 筛选 chips、稳定分页、加载更多、下拉刷新、四态 |
| `pages/ecosystem/event-detail.vue` | 活动详情：ID 直达 / 刷新 / 返回、404/410/503、时间（按 event timezone）、媒体、正文、只读报名区、H5 复制链接、小程序分享 |
| `pages/ecosystem/content.vue` | 资讯列表：type 筛选、稳定分页、加载更多、四态 |
| `pages/ecosystem/content-detail.vue` | 资讯详情：slug 直达 / 刷新、来源 / 首发与公开时间 / 更正说明 / 正文、分享 |
| `utils/ecosystem-public.js` | 归一化、分页、状态/日期、错误映射、富文本白名单、报名展示、分享（纯函数） |
| `tools/tests/ecosystem-public.test.mjs` | 18 个 Node 用例 |

### 2.2 修改

| 文件 | 改动 |
|---|---|
| `pages.json` | 注册 4 个页面；详情页 `enableShareAppMessage`；列表/详情 `enablePullDownRefresh`；导航栏标题 |
| `utils/api.js` | `eventApi` 支持 params；新增 `contentApi`；移除 `eventApi.register` |
| `components/EcoCenter.vue` | 赛事 tab 改 compact 预览；删除 inline 详情 / 报名弹窗 / 旧字段依赖；查看全部 |
| `components/shouye/dongtai.vue` | 真实资讯预览 + 四态 + 查看全部 + 点击详情 |
| `components/StateView.vue` | 新增 `errorIcon` prop（默认 `📡`，向后兼容）；按钮补 `role="button"` / `tabindex="0"` |

### 2.3 备份

改动前的原文件已备份到 `.claude/backup/G1-11/`：

```
utils/api.js
components/EcoCenter.vue
components/shouye/dongtai.vue
components/StateView.vue
pages.json
```

---

## 3. 关键实现口径

### 3.1 只消费 G1-10 DTO

- 活动字段：`id / kind / name / summary / organizer{name,platform_role} / source{name,url} / participation_mode / city / location_text / timezone / starts_at / ends_at / published_at / revision_no / hosting_status / body_html / registration{can_register,registration_reason,allowed_actions} / media[]`。
- 资讯字段：`slug / type / title / summary / author{name,org} / sources[] / published_at / first_published_at / revision_no / correction_note / body_html / media[]`。
- 归一化后**不保留** `hot`、`teams`、`date`、`status`、`detail` 等旧字段；新公开页面中没有这些字段的读取点。

### 3.2 状态规则

活动展示状态（`eventStatus`）：

| 条件（按优先级） | 展示 |
|---|---|
| `hosting_status=canceled` | 已取消 |
| `hosting_status=completed` | 已结束 |
| `hosting_status=postponed/rescheduled` | 时间待定 |
| `ends_at < now` | 已结束 |
| `starts_at <= now` | 进行中 |
| `starts_at > now` | 即将开始 |
| 无有效 `starts_at` | 时间待定 |

日期格式化**不做浏览器时区二次换算**：G1-10 已按活动 `timezone` 输出带偏移的 ISO 时间（如 `2026-11-01T09:00:00+08:00`），前端只格式化该本地时间；时区名称单独展示（如「北京时间」）。

### 3.3 错误映射

| 输入 | kind | 重试 |
|---|---|---|
| HTTP 404 | `not_found`（不存在或未公开） | 否 |
| HTTP 410 | `gone`（内容已撤稿） | 否 |
| HTTP 503 | `unavailable`（服务暂不可用） | 是 |
| 网络失败 | `network` | 是 |
| 超时 | `timeout` | 是 |
| 2xx 但结构非法 | `format` | 是 |
| 5xx | `server` | 是 |
| 其他 4xx | `request` | 否 |

404/410/503 各有独立标题与图标；列表空态只在「请求成功且 `data=[]`」时出现。

### 3.4 富文本安全渲染

- 只接受服务端已净化的 `body_html`，通过 `<rich-text :nodes>` 渲染，不使用 `v-html`。
- 只保留 DTO `media[]` 中的受控相对 URL（必须以 `/api/` 开头、单斜杠）：
  - 按 `data-media-id` 精确匹配；
  - 或 `src` 与 DTO 相对 URL 完全一致；
  - 其余 `<img>` 整条删除。
- 输出 `src` 只能是 `BASE_URL + /api/...`；远程 URL、协议相对 URL、`/uploads/...` 一律拒绝。
- 额外兜底移除 `script/style/iframe/object/embed/form/video/audio/source`、事件属性与 `javascript:/vbscript:/data:` 链接。
- 图片固定追加 `style="max-width:100%;height:auto"`，375px 下不横向溢出。

### 3.5 报名区（G1 只读）

- `can_register === true`（字符串 `'true'` 不算）才显示 register 按钮；G1 后端恒为 false。
- 外部报名按钮需**同时**满足：`allowed_actions` 含 `open_external_registration` **且** DTO 提供合法的 `http(s)` 外部链接。
- 其余情况只显示稳定原因文案（`registration_disabled` / `external_only` / `canceled` / `ended`）。
- 不存在 `waitlist`、不存在假报名成功提示；`eventApi.register` 已从前端 API 模块移除。

### 3.6 分页

- 列表使用服务端 `meta.current_page / last_page / per_page / total`。
- 域开关关闭时后端返回 `{data: []}`（无 meta）：按「单页空列表」处理，不报格式异常。
- `meta` 存在但字段非法 → `format` 错误（不静默当空页）。
- 加载更多按 `id` / `slug` 去重，避免翻页期间数据变动导致重复。
- 到达最后一页显示「没有更多了」。

### 3.7 分享

| 平台 | 行为 |
|---|---|
| H5 | 复制当前 H5 站点基址下的 `/#/pages/ecosystem/event-detail?id=...` 或 `.../content-detail?slug=...`；`uni.setClipboardData` 成功后才提示「链接已复制」 |
| 小程序 | `onShareAppMessage` 返回真实详情 `path` 与标题；未加载 / 404 / 410 时退回列表页，不分享未公开内容 |
| 非法 ID / slug | 不生成分享路径，提示内容尚未公开 |

---

## 4. 静态检查

```bash
cd /d/main
node -e "JSON.parse(require('fs').readFileSync('pages.json','utf8'))"
# pages: 26
# pages/ecosystem/events=true
# pages/ecosystem/event-detail=true
# pages/ecosystem/content=true
# pages/ecosystem/content-detail=true

git diff --check          # 无输出
```

SFC `<script>` 语法（node --check，7 个文件全部 `syntax OK`）：

```
pages/ecosystem/events.vue: syntax OK
pages/ecosystem/event-detail.vue: syntax OK
pages/ecosystem/content.vue: syntax OK
pages/ecosystem/content-detail.vue: syntax OK
components/EcoCenter.vue: syntax OK
components/shouye/dongtai.vue: syntax OK
components/StateView.vue: syntax OK
```

Vite H5 编译（HBuilderX uni-app dev server，5173）：

```
GET /pages/ecosystem/events.vue            200
GET /pages/ecosystem/event-detail.vue      200
```

旧字段 / 假报名残留检查：

```bash
grep -rn "hot\|teams\|eventApi.register\|waitlist\|假报名" \
  pages/ecosystem components/EcoCenter.vue components/shouye/dongtai.vue \
  utils/ecosystem-public.js utils/api.js
# 仅命中注释中声明「不读取/不存在」的说明文字，无代码读取点
```

---

## 5. Node 测试

命令：

```bash
node --test tools/tests/*.test.mjs
```

真实结果：

```
tests 35
pass 35
fail 0
duration_ms 219.7861
```

其中 `tools/tests/ecosystem-public.test.mjs` 新增 18 个用例，覆盖：

- G1 活动/资讯 DTO 归一化、必需字段、旧字段不进入模型；
- 列表空结果 vs 格式异常；meta 严格解析；跨页去重；
- 状态（取消 / 结束 / 进行中 / 即将开始 / 待定）与按服务端偏移的日期格式化；
- 404 / 410 / 503 / 网络 / 超时 / 格式 / 5xx / 4xx 错误映射；
- 媒体 URL 白名单、富文本 img 白名单、远程 URL 不复活、script/事件属性/危险协议移除；
- 报名只读：`can_register` 严格布尔、外部报名需 URL、无 waitlist；
- 分享路径编码、H5 URL、复制成功/失败判定。

既有 17 个 Node 用例（`ecosystem-events` / `skill-market`）保持通过。

---

## 6. H5 证据

### 6.1 真实数据 E2E（2026-10-19 追加）

**环境**

| 项 | 值 |
|---|---|
| H5 dev server | `http://127.0.0.1:5173`（HBuilderX / Vite，未停止） |
| 独立后端 8009 | `DB_DATABASE=summon_mecha_step10_test APP_ENV=testing ECOSYSTEM_EVENT_PUBLIC_ENABLED=true ECOSYSTEM_CONTENT_PUBLIC_ENABLED=true php artisan serve --host=127.0.0.1 --port=8009` |
| 独立后端 8010 | 同 8009，另加 `ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED=true`（仅用于 503 截图） |
| 既有后端 8000 | **未停止、未重启、未写入**（root 库 + 公开开关 false） |
| `utils/config.js` | 临时改为 8009 / 8010 截图，结束后按备份**逐字节恢复**（md5 `4c637db145ec4da1dbfa76abab7fd20b`，与备份一致；用户既有的 `BASE_URL='http://127.0.0.1:8000'` 改动保留） |

**真实数据（全部经服务层写入，不是裸 SQL 伪造发布轴）**

| 对象 | ID | 状态 / 关键字段 |
|---|---|---|
| 后台账号 | admin `4029` | `g1-h5-e2e@example.test` |
| 主办方 | organizer `2054` | `verified`，`platform_role=organizer`（`OrganizerService::create` + `verify`） |
| 活动 | event `4436` | `publish_status=published`，`disposition=normal`，`published_revision_id=2233`，`published_at=first_published_at=2026-10-19 13:04:03 UTC`（21:04 +08）；时间 `2026-11-15 09:30~17:30 Asia/Shanghai`，city=上海，participation_mode=offline |
| 活动媒体 | media `1150` | 真实 PNG 1200×800，`approved`；`event_revision 2233` 引用 `role=cover`、ref `approved`；sha256 `6ae9aaf0…2b6e` |
| 资讯 | post `1450` | slug `h5-e2e-embodied-ai-open-day`，`publish_status=published`，`published_revision_id=1445`，`published_at=2026-10-19 13:04:03 UTC`；含 1 条来源与 `body_html` |
| 资讯媒体 | media `1151` | 真实 PNG 1200×800，`approved`；`content_revision 1445` 引用 `role=cover`、ref `approved`；sha256 `7e9eb976…9f53` |
| 撤稿活动 | event `4437` | 曾发布（`published_at` 非空）、`publish_status=published`、`disposition=retracted`（`EventPublicationService::retract`） |

服务链路：`EventDraftService` → `EventRevisionService` → `MediaService` + `MediaRefService` → `EventReviewService`（submit/approve）→ `EventPublicationService::publishNow`；资讯同构（`ContentPostService` / `ContentRevisionService` / `ContentReviewService` / `ContentPublicationService`）。发布开关与 B-04/B-07 **只在种子进程内** `config()->set`，仓库默认值未改。

**真实 API 证据**（原始响应保存在 `.claude/backup/G1-H5-E2E/`）

| 请求 | 结果 | 证据文件 |
|---|---|---|
| `GET http://127.0.0.1:8009/api/events` | `200`，列表含 event 4436 | `api-events-list.json` / `http-events-list.headers` |
| `GET /api/events/4436` | `200`，DTO 含 organizer/source/city/timezone/body_html/media | `api-event-detail.json` |
| `GET /api/events/4436/media/1150` | `200 image/png`，33040 字节，md5 与种子 PNG 一致 | `api-event-media.png` |
| `GET /api/content/posts` | `200`，列表含 slug | `api-content-list.json` |
| `GET /api/content/posts/h5-e2e-embodied-ai-open-day` | `200`，含 sources 与 body_html | `api-content-detail.json` |
| `GET /api/events/4437` | `410 Gone`，`EVENT_UNAVAILABLE`，中性文案 | `api-event-410.json` |
| `GET http://127.0.0.1:8010/api/events` | `503`，`SERVICE_UNAVAILABLE` | `api-events-503.json` / `public-read-503-390.png` |

**截图**（`node tools/shot.mjs`，375/390 宽，DSF=2，均来自 8009 真实数据；逐张用 view_image 检查过，无空白/404/错误态）

| 截图 | 视口（CSS） | 实际像素 | 内容 |
|---|---|---|---|
| `docs/ecosystem/screenshots/events-data-375.png` | 375×812 | 750×1624 | 活动列表真实卡片（唯一 E2E 活动） |
| `docs/ecosystem/screenshots/events-data-390.png` | 390×844 | 780×1688 | 同上 |
| `docs/ecosystem/screenshots/event-detail-data-390.png` | 390×844 | 780×1688 | 活动详情：标题/时间/地点/参与方式/主办方/报名只读/正文 |
| `docs/ecosystem/screenshots/content-data-390.png` | 390×844 | 780×1688 | 资讯列表真实卡片 |
| `docs/ecosystem/screenshots/content-detail-data-390.png` | 390×844 | 780×1688 | 资讯详情：标题/作者/来源/正文 + 正文内真实图片 |
| `docs/ecosystem/screenshots/event-detail-410-390.png` | 390×844 | 780×1688 | 撤稿活动：410「内容已撤稿」 |
| `docs/ecosystem/screenshots/public-read-503-390.png` | 390×844 | 780×1688 | kill switch：503「服务暂不可用」+ 重试 |
| `docs/ecosystem/screenshots/home-event-feed-data-390.png` | 390×844 | 780×1688 | 首页 EcoCenter 赛事 tab 的真实活动预览 |

> 8000 后端保持公开开关关闭，页面只会得到空列表；截图里出现的 E2E 活动/资讯只能来自 8009 的独立测试库，可排除静态假数据。

### 6.2 H5 剪贴板实测

通过 CDP 在**真实详情页**点击「复制链接」，再用 `navigator.clipboard.readText()` 读回：

| 页面 | 读回内容 | 结果 |
|---|---|---|
| 活动详情 `?id=4436` | `http://127.0.0.1:5173/#/pages/ecosystem/event-detail?id=4436` | ✅ 与预期完全一致；不含 `8000` / `8009` |
| 资讯详情 `?slug=h5-e2e-embodied-ai-open-day` | `http://127.0.0.1:5173/#/pages/ecosystem/content-detail?slug=h5-e2e-embodied-ai-open-day` | ✅ 与预期完全一致；不含 `8000` / `8009` |

**真实失败记录（未隐藏）**：首次在 headless Chrome 中直接 `readText()` 时返回
`NotAllowedError: Failed to execute 'readText' on 'Clipboard': Document is not focused.`。
此时页面 toast 已是「链接已复制」，但**读回被浏览器拒绝，不能据此判成功**。
补上 `Emulation.setFocusEmulationEnabled(true)` + `Page.bringToFront` 后重测才读回上述 URL。
原始成功记录：`.claude/backup/G1-H5-E2E/clipboard-result-event.json`、`clipboard-result-content.json`。首次失败只保留在当次 CDP 终端输出中（该次 JSON 后来被成功结果覆盖），此处按原文如实记录，未把它算作成功。

### 6.3 环境清理与无副作用证明

| 检查 | 证据 |
|---|---|
| 8009 / 8010 已停止 | `taskkill /T /F` 后 `netstat` 无 `:8009` / `:8010` LISTEN |
| `utils/config.js` 逐字节恢复 | md5 `4c637db145ec4da1dbfa76abab7fd20b`，与 `.claude/backup/G1-H5-E2E/config.js.orig` 相同；`git diff` 只剩用户原有的 8008→8000 改动 |
| root 主库无写入 | 只读探针输出 `database=root`，两个 E2E 活动名、资讯 slug、主办方名、后台邮箱、两个媒体 sha256 计数均为 **0**（`.claude/backup/G1-H5-E2E/root-db-readonly-probe.json`） |
| 仓库开关默认值未改 | `config/ecosystem_publishing.php`、`config/ecosystem.php`、`config/ecosystem_public_read.php`、`.env` 本轮均未被写入；仅在测试进程环境变量中临时开启 |
| 未执行 git add / commit | 无 |
| 临时脚本已备份 | `.claude/backup/G1-H5-E2E/`：种子脚本、PNG 生成脚本、CDP 剪贴板脚本、API 原始响应、种子结果 JSON |

### 6.4 仍未完成 / 未验证

| 项 | 状态 | 说明 |
|---|---|---|
| 小程序真机 / 开发者工具分享 | **未验证** | 本机只有 H5 dev server，没有小程序运行环境 |
| 小程序端有数据视图 | **未验证** | 同上；页面代码与 H5 共用，但未在真机运行 |
| 多浏览器剪贴板 | **部分验证** | 仅验证 headless Chrome；Safari / 微信内置浏览器未测，且无用户手势时可能仍被拒绝 |
| 长正文滚动、极端字号、键盘遮挡 | **未专门验证** | 已截到正文首屏与正文内图片；未做长文滚动全量截图 |
| root / 生产迁移与开关发布 | **未做** | 不在本批范围；kill switch 只在 8010 进程环境变量中验证 |
| SSR / OG / sitemap / 搜索 / 收藏 / 报名写 | **未做** | G1-11 明确排除 |

> 既有空态 / 404 截图仍保留在 `.claude/backup/G1-11/screenshots/`，证明域开关关闭时的诚实空态与 404 态；本次新增截图证明**有数据、410、503** 三种此前缺失的状态。

---

## 7. 风险与后续

1. **外部报名链接受发布前置约束**：G1-10 已受控输出 `external_registration_url`，前端仍要求
   `allowed_actions` 含 `open_external_registration` 且链接为合法 http(s) 才显示。B-01/B-03
   未签署时 external 活动仍不能发布，因此当前正常数据不会出现外部报名按钮。
2. **列表严格归一化**：一页里任一行缺 `id/name`（资讯缺 `slug/title`）会让整页进入 `format` 错误态。
   这是刻意 fail-closed（避免半页假数据），但后端字段回归会直接表现为错误态。
3. **媒体可能单张破图**：G1-10 的 `media[]` 不预检私有盘文件存在性；文件缺失时图片 404，页面不会 500。
4. **`rich-text` 样式受限**：正文内部样式由节点属性决定；已在 `<img>` 上固定 `max-width:100%;height:auto`，其余排版依赖 `rich-text` 容器继承。
5. **H5 剪贴板**：部分浏览器/无用户手势场景会拒绝写入；`copyShareLink` 只在写入 resolve 后提示成功，失败提示手动复制。
6. **小程序分享路径**：`path` 使用真实详情路由；上线前需在开发者工具/真机确认 `pages/ecosystem/*` 已随新版本发布，否则旧版本用户打开分享会 404。
7. **筛选值依赖后端枚举**：kind / hosting_status / type 与 `config/ecosystem_publishing.php`、`config/ecosystem_content.php` 对齐；枚举变更需同步前端 chips。
8. **旧文件 `utils/ecosystem-events.js`** 已不再被页面引用，但仍保留（其测试仍通过）。是否删除需在后续清理批次统一决定，避免本批误删引用。

---

## 8. 回滚

无数据库结构变更，回滚只涉及前端文件：

```bash
cd /d/main

# 1) 恢复被修改文件
cp .claude/backup/G1-11/utils/api.js utils/api.js
cp .claude/backup/G1-11/components/EcoCenter.vue components/EcoCenter.vue
cp .claude/backup/G1-11/components/shouye/dongtai.vue components/shouye/dongtai.vue
cp .claude/backup/G1-11/components/StateView.vue components/StateView.vue
cp .claude/backup/G1-11/pages.json pages.json

# 2) 删除本批新增文件
rm -r pages/ecosystem
rm utils/ecosystem-public.js
rm tools/tests/ecosystem-public.test.mjs

# 3) 截图证据按需保留或删除
rm -r .claude/backup/G1-11/screenshots
```

回滚后旧 `EcoCenter` / `Dongtai` 仍可运行；两个公开域开关保持关闭，不会因回滚暴露草稿。
若小程序已发布新页面，需保留后端路由兼容期，避免已分享链接直接 404 无提示（规划 §7.7）。

---

## 9. 闸门复核

- 仓库 `config/` 与 `.env` 中的 `public_enabled` / kill switch 默认值**未改**；2026-10-19 的 H5 E2E 只在 8009/8010 测试进程环境变量中临时开启，进程已停止。
- 未改后端 API、Admin、报名写入口。
- 未执行数据库迁移、`migrate:fresh` / `truncate` / `seed`；E2E 数据仅写入独立测试库 `summon_mecha_step10_test`，root 主库只读探针 0 条。
- 未实现 SSR / OG / sitemap / 搜索 / 收藏 / 评论。
- 未执行 `git add -A` / `git commit` / `git push`。
- `node --test tools/tests/*.test.mjs`：35 passed / 0 failed。
- `pages.json` JSON 合法、4 个页面注册成功。
- `git diff --check`：通过。
