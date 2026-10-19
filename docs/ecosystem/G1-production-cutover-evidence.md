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

- root 没有真实已发布赛事/资讯，因此公开页面为空是正确状态。
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
