# AI 召唤 · 剩余项收口证据（2026-10-20）

## 1. 结论

| 剩余项 | 状态 | 说明 |
|---|---|---|
| 跨设备历史同步 | 完成 | 登录客户按 `client_updated_at` 合并；删除墓碑保留 90 天；前端按客户 ID 隔离本地存储 |
| 正式埋点与报表 | 完成 | 游客/客户均可上报；服务端白名单、去重、属性裁剪；含 CLI 漏斗报表 |
| 读屏与焦点闭环 | 完成 | 抽屉为临时 dialog、打开聚焦搜索、Esc 关闭、ARIA live 播报同步结果 |
| 真实小召上游回归 | 完成 | Docker/Coze 已修复并运行；真实流式、非流式和页面级浏览器演练全部通过 |

本轮剩余项已经全部收口。

## 2. 数据模型

新增 additive 迁移：

`my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_20_100001_create_ai_conversation_and_analytics_tables.php`

### `ai_conversations`

- 私有客户数据，`customer_id + client_id` 唯一。
- `messages` 存服务端裁剪后的持久会话字段，不存生成态、播放态或原始推理文本。
- `client_updated_at` 为冲突裁决版本；较新的客户端版本覆盖较旧版本。
- `deleted_at` 作为墓碑保留 90 天，旧设备不能复活已删除会话。

### `ai_analytics_events`

- `event_id` 全局唯一并 `insertOrIgnore` 去重。
- `device_hash` 只存 `X-Guest-Key` 的 SHA-256，不存原设备键。
- `properties` 只接收标量值；服务端最多保留 20 个属性、字符串最多 200 字。
- 不存提示词正文、回答正文、姓名、手机号或自由文本需求。

## 3. API

| 方法 | 路径 | 鉴权 | 用途 |
|---|---|---|---|
| POST | `/api/ai/conversations/sync` | customer + write throttle | 上传 active/tombstone 并返回云端快照 |
| POST | `/api/ai/events` |可选 customer + write throttle | 批量埋点，最多 20 条/批 |

错误信封继续使用 `APIController` 与 `ApiErrorCode`，未新增错误码字面量。

## 4. 前端

- `pages/ai/index.vue`
  - 本地历史、墓碑和草稿按客户作用域分开：`user_<customer_id>`。
  - 首次登录可接管登录前的游客历史；后续切号绝不复制上一账号状态。
  - 离线优先：同步失败时本机数据保留，并显示“云端同步失败 · 本机 N 段”。
  - 抽屉改为 `v-if` dialog；打开后聚焦搜索框，Esc 关闭；同步结果通过 `aria-live` 播报。
  - 埋点覆盖页面、提交、首包、完成、错误、停止、重试、推荐点击、方案定制、复制、历史打开/搜索和语音结果。
- `utils/ai-analytics.js`
  - 本地队列最多 100 条，20 条/批，失败保留并延后重试。
- `utils/api.js`
  - 新增 `aiConversationApi.sync` 与 `aiAnalyticsApi.ingest`。

## 5. 验证证据

### 后端

```text
AiConversationSyncTest + AiAnalyticsTest + HardeningRound4Test
22 passed / 125 assertions
```

覆盖：版本覆盖、墓碑防复活、跨客户隔离、未登录拒绝、去重、白名单、属性裁剪、漏斗报表、write 限流。

### 仓库门禁

```text
php artisan ecosystem:contract-check      通过
php artisan ecosystem:data-preflight      通过
```

### 浏览器

```text
真实 Chrome + 本地 mock 云端/埋点/SSE：13/13 通过
```

覆盖：云端会话拉取、本地 active/tombstone 上传、账号切换隔离、焦点进入搜索、Esc 关闭、读屏播报、埋点不含正文、320px 无横向溢出、0 页面异常。

截图：`.claude/backup/ai-full-closeout-20261020/evidence/ai-ui-round3-shots/`（按仓库约定不入库）。

### 真实上游

真实 `/api/summon-stream`：

```json
{
  "ok": true,
  "first_event_ms": 1425,
  "first_delta_ms": 3142,
  "total_ms": 5693,
  "events": 184,
  "answer_length": 75,
  "follow_ups": 3,
  "error": null
}
```

真实 `/api/summon`：

```json
{
  "success": true,
  "engine": "coze-v1",
  "answer_length": 293,
  "elapsed_ms": 10794
}
```

真实浏览器（H5 + Laravel + Coze，无 mock）：

- 输入协作机器人部署问题后成功拿到完整回答；
- 回答长度 83；
- 无页面异常、无控制台异常；
- 截图：`.claude/backup/ai-full-closeout-20261020/evidence/real-ai-browser/real-ai-answer-375.png`。

## 6. 数据库状态

- 独立测试库：迁移已执行，专项测试通过。
- root：同一 additive 迁移已执行，编号 `47`。
- root 现有业务表未被改写。
- 收口时实测：`ai_conversations = 0`，`ai_analytics_events = 32`；事件为 page_view / prompt_submit / first_token / done / retry / stop，属性只含长度、延迟、来源、版本等元数据，不含提示词或回答正文。

## 7. 运营命令

```bash
cd D:/main/my-bagisto-store
php artisan ai:analytics-report --days=7
php artisan ai:analytics-report --days=30 --json=D:/main/docs/ai-analytics-30d.json
```

## 8. 回滚

1. 回滚前端/路由/文档：
   使用 `.claude/backup/ai-full-closeout-20261020/` 下的原文件覆盖对应路径。
2. 回滚迁移：

```bash
cd D:/main/my-bagisto-store
php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_20_100001_create_ai_conversation_and_analytics_tables.php --force
```

该回滚只删除本次新增的 `ai_conversations`、`ai_analytics_events` 两张表。

## 9. Docker / Coze 修复记录

根因：Docker Desktop 的 `%LOCALAPPDATA%\Docker\run` 下遗留了不可访问的 Unix socket/reparse point，启动时在 `dockerInference` 初始化阶段崩溃。

修复：通过 `docker-desktop` WSL 发行版清理以下陈旧运行时文件，再重启 Docker Desktop：

```text
dockerInference
dockerEthernetVfkit
userAnalyticsOtlpHttp.sock
```

启动顺序：

```bash
cd D:/main/coze-studio/docker
docker compose -f docker-compose.full.yml up -d
```

Milvus 健康后如 `coze-server` 仍处于重启退避，执行一次：

```bash
docker restart coze-server
```

当前 `coze-server`、`coze-web` 与全部依赖容器均已正常运行，`127.0.0.1:8888` 有监听。
