# G1-09 outbox worker / 到期扫描 / 公开读取 kill switch —— 交付与实测记录

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | outbox 领取 / 租约 / 结算 / 重试 / dead / 过期恢复；到期发布扫描；独立公开读取 kill switch；调度注册 |
| 依据 | `docs/ecosystem/ADR-0001-trusted-publishing.md` G1-D07、附录 C；`docs/ecosystem/G1-08-11-implementation-plan.md` §5、§8.6~§8.9 |
| 数据库 | 独立测试库 `summon_mecha_step10_test`（MySQL 5.7.26）；**未新增迁移**、未触碰 root 主库 |
| 性质 | 服务层 + 命令 + 调度注册；**不含** G1-10 公开 API / G1-11 前端 |

---

## 1. 改动清单

### 新增

| 文件 | 作用 |
|---|---|
| `my-bagisto-store/config/ecosystem_public_read.php` | 独立 kill switch 配置；`emergency_disabled` 默认 false |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/OutboxLeaseService.php` | 领取 / 结算 / 失败 / 过期恢复 / drip-dry 统计 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/OutboxLeaseLostException.php` | 旧 worker 回报被拒的显式异常 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/EcosystemOutboxProcessor.php` | 事实核验、superseded、dead、重试分类 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/ScheduledPublicationScanner.php` | 到期扫描、安全回退、`schedule_aborted` 审计与告警 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicReadGate.php` | 只读配置的 kill switch，不查业务表 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicReadUnavailableException.php` | 503 / `SERVICE_UNAVAILABLE` 语义 |
| `my-bagisto-store/packages/Webkul/Shop/src/Console/Commands/ProcessEcosystemOutbox.php` | `ecosystem:process-outbox` |
| `my-bagisto-store/packages/Webkul/Shop/src/Console/Commands/PublishScheduledEcosystem.php` | `ecosystem:publish-scheduled` |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemOutboxLeaseG1Test.php` | 9 tests / 43 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemOutboxProcessorG1Test.php` | 8 tests / 32 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemScheduledPublishG1Test.php` | 8 tests / 45 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemPublicReadKillSwitchG1Test.php` | 4 tests / 11 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemSchedulerRegistrationG1Test.php` | 3 tests / 11 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemCommandsG1Test.php` | 3 tests / 11 assertions |
| `docs/ecosystem/G1-09-worker-evidence.md` | 本文件 |

### 修改

| 文件 | 改动 |
|---|---|
| `my-bagisto-store/config/ecosystem_outbox.php` | 新增 `worker_enabled` / `lease_seconds` / `batch_size` / `max_attempts` / `retry_backoff_seconds` |
| `my-bagisto-store/config/ecosystem_publishing.php` | 新增 `schedule_aborted` 审计动作与原因码 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationState.php` | 新增 `publishEnabled()`（与 `assertPublishEnabled()` 同源，供扫描器整域跳过） |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/MediaAccessService.php` | 构造注入 `PublicReadGate`；`resolvePublic()` 第一步检查 kill switch |
| `my-bagisto-store/packages/Webkul/Shop/src/Providers/ShopServiceProvider.php` | 注册两个 artisan 命令 |
| `my-bagisto-store/bootstrap/app.php` | 注册两条每分钟调度（默认开关 false） |
| `docs/ecosystem/ADR-0001-trusted-publishing.md` | 追加附录 C 落地记录 |
| `docs/ecosystem/G1-08-11-implementation-plan.md` | 追加 §5.9 落地记录与差异说明 |

### 备份

原始文件已复制到 `.claude/backup/G1-09/`（保持 `docs` / `my-bagisto-store` 相对路径）。

---

## 2. 关键行为

### 2.1 领取与租约

- MySQL 5.7.26，无 `SKIP LOCKED`：每批在短事务内 `SELECT ... FOR UPDATE LIMIT <=20`，逐行更新后立即提交；handler 在事务外执行。
- `lease_owner = host:pid:uuid`，**每次领取唯一**。
- `attempts` 在领取时 +1。
- 结算 / 失败 SQL 必须匹配 `id + status=processing + lease_owner + lease_until >= now`；影响 0 行 → `OutboxLeaseLostException`，旧 worker 不写任何状态。
- 过期恢复：`processing` 且 `lease_until < now`（或 NULL）→ `pending`，清租约，`last_error=lease_expired`，不重复加 attempts。
- 重试：瞬时 DB 错误回 `pending`，`available_at = now + backoff[attempts-1]`；达到 `max_attempts` → `dead`。业务 / 状态 / payload 错误直接 `dead`。

### 2.2 handler 语义

| event_type | 核验 |
|---|---|
| `*.published` | 父 `publish_status=published`、`disposition=normal`、`published_revision_id=payload.revision_id` |
| `*.withheld` | `disposition=withheld`，公开指针与 payload 一致 |
| `*.restored` | `published + normal`，公开指针与 payload 一致 |
| `*.retracted` | `disposition=retracted`，公开指针与 payload 一致（从未发布时 revision_id=0） |

- 所有类型都要求父 `row_version == payload.row_version`；否则 `dead`，`last_error` 前缀 `superseded:`。
- 未知 event_type / aggregate_type / 类型不匹配 / payload 非法 / 父对象不存在 → `dead`。
- **不发送通知**；`sent` 只表示消费方确认了事实，不代表用户已收到任何消息。

### 2.3 到期扫描

- 查询 `scheduled_publish_at <= UTC_NOW()` + `disposition=normal` + `publish_status ∈ {scheduled,published}`，按 `scheduled_publish_at, id` 稳定排序。
- 成功：调用 G1-08B `publishScheduled()`，切换公开指针并写 outbox / 系统审计。
- 不可重试失败：写 `ecosystem.{domain}.schedule_aborted`（`actor_type=system`）+ 告警；
  - 从未发布 → `approved`，清 `scheduled_publish_at`，保留 `approved_revision_id`；
  - 已发布 → 保持 `published` 与旧公开指针，清 `scheduled_publish_at`、`approved_revision_id`。
- `withheld` / `retracted` 不进入候选；回退事务再次校验 `disposition=normal`。
- 域发布开关关闭 → 整域跳过，不回退排期。
- 瞬时 / 未预期错误 → 行保持 `scheduled`，下一轮重试，命令返回非零退出码。

### 2.4 kill switch

- `PublicReadGate::assertAvailable()` 只读 `config('ecosystem_public_read.emergency_disabled')`，不查业务表。
- 命中抛 503 语义异常；媒体门禁在域开关之前调用 gate（503 优先于 404）。
- G1-10 的 controller 负责把异常映射为 `{code: SERVICE_UNAVAILABLE}`；本批不改公开 API。

### 2.5 调度

- `bootstrap/app.php` 注册 `ecosystem:process-outbox` 与 `ecosystem:publish-scheduled`，均 `everyMinute + withoutOverlapping`。
- 默认 `worker_enabled=false`、`scheduler_enabled=false`。
- **注册不等于已运行**：本仓库未启动 `schedule:work` / crontab，本批不声称常驻进程已运行。

---

## 3. 实测证据

### 3.1 语法与差异检查

```bash
/d/php.exe -l <20 changed PHP files>   # 全部 No syntax errors detected
git diff --check                       # 无输出（DIFF_CHECK_OK）
```

### 3.2 G1-09 新增测试（6 文件合并执行）

```bash
DB_DATABASE=summon_mecha_step10_test /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemOutboxLeaseG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemOutboxProcessorG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemScheduledPublishG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemPublicReadKillSwitchG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemSchedulerRegistrationG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemCommandsG1Test.php
```

结果：**35 passed / 153 assertions**（4.59s）。

分文件结果：

| 文件 | 结果 |
|---|---|
| `EcosystemOutboxLeaseG1Test` | 9 passed / 43 assertions |
| `EcosystemOutboxProcessorG1Test` | 8 passed / 32 assertions |
| `EcosystemScheduledPublishG1Test` | 8 passed / 45 assertions |
| `EcosystemPublicReadKillSwitchG1Test` | 4 passed / 11 assertions |
| `EcosystemSchedulerRegistrationG1Test` | 3 passed / 11 assertions |
| `EcosystemCommandsG1Test` | 3 passed / 11 assertions |

### 3.3 G1-02 ~ G1-08B 回归（分进程执行）

| 组 | 文件数 | 结果 |
|---|---|---|
| 组 1 | 9 | 108 passed / 884 assertions（22.64s） |
| 组 2 | 9 | 96 passed / 597 assertions（15.82s） |
| 组 3 | 9 | 99 passed / 863 assertions（22.63s） |
| **合计** | **27** | **303 passed / 2344 assertions** |

> 分进程是为了避开控制器 `set_time_limit(60)` 在单进程全量跑时触发的误解超时（见项目记忆 `pest-suite-time-limit`）。

### 3.4 命令实测

```bash
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan list | grep ecosystem:
  ecosystem:process-outbox       领取并消费生态 outbox 的一轮有界批次（租约 / 重试 / dead）
  ecosystem:publish-scheduled    扫描到期的活动 / 文章排期并执行系统发布（失败安全回退）

$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan ecosystem:process-outbox --dry-run
worker_enabled=false（false 时调度器不会自动触发；本命令为手工执行）
[DRY-RUN] 到期待领取 0 条；过期租约 0 条。未写库。

$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan ecosystem:publish-scheduled --dry-run
scheduler_enabled=false（false 时调度器不会自动触发；本命令为手工执行）
活动域发布开关关闭，已整域跳过。
内容域发布开关关闭，已整域跳过。
[DRY-RUN] 到期候选 0 条（活动 0 / 文章 0）。未发布、未回退。
```

### 3.5 数据库版本

```sql
SELECT VERSION(); -- 5.7.26
```

---

## 4. 未做 / 未验证

| 项 | 状态 | 说明 |
|---|---|---|
| 真实双进程 / 双连接阻塞领取 | **未做** | 测试用两个服务实例顺序领取，证明“同一行只会被一个 worker 拿到”和旧租约拒绝；未制造两个真实连接同时 `FOR UPDATE` 的锁竞争证据 |
| 常驻 scheduler / cron | **未启动** | 仅完成注册与默认开关；`schedule:work` / crontab 需部署侧启动 |
| G1-10 公开 API / DTO / 503 响应体 | **未做** | 本批只提供 `PublicReadGate` 与 503 语义异常，controller 映射属 G1-10 |
| G1-11 H5 / 小程序 | **未做** | 未进入前端 |
| 通知渠道 | **未接** | 不发送短信 / 微信 / 邮件；`sent` 不代表用户已收到 |
| Admin 管理界面 | **未做** | 无 outbox / 排期运维页面 |
| 人工补偿入口 / 分页游标 | **未做** | 按 ADR G1-D07 留 G2-12 |
| 生产 MySQL DDL / 锁影响 | **未验证** | 本批无迁移；生产行为未测 |
| 配置缓存生效演练 | **未做** | kill switch / worker 开关变更后需 `config:cache` + reload，需部署流程验证 |

---

## 5. 回滚

1. 配置：`worker_enabled=false`、`scheduler_enabled=false`；确认没有正在执行的批次。
2. 代码：回滚本批新增文件与以下修改：
   - `config/ecosystem_outbox.php`
   - `config/ecosystem_publishing.php`
   - `config/ecosystem_public_read.php`（删除）
   - `PublicationState.php`
   - `MediaAccessService.php`
   - `ShopServiceProvider.php`
   - `bootstrap/app.php`
3. 数据库：**无需回滚结构**（本批无迁移）。`ecosystem_outbox` 记录保留；已 `sent` / `dead` 的历史不删除。
4. 备份：`.claude/backup/G1-09/` 下有修改前原文。

---

## 6. 风险与注意事项

1. **锁竞争**：`FOR UPDATE` 在 MySQL 5.7 会持有行锁到事务提交；batch 上限 20 是防锁等待放大的硬约束，不要调大。
2. **租约长度**：单批 handler 若超过 `lease_seconds=300`，会被恢复扫描重新放回 pending，旧 worker 的结算被拒绝；表现为 `lease_lost` warning。当前 handler 只读核验，远低于 300s。
3. **域开关**：扫描器在域发布开关关闭时整域跳过，不会回退排期；运营重新打开后下一轮继续。
4. **告警去重**：`schedule_aborted` / `schedule_error` 的 dedupe key 含 UTC 日期；同一对象同一天只提醒一次。
5. **kill switch 生效**：配置变更必须经部署流程（`config:cache` + reload）；只改进程内配置不会影响其他 worker。
6. **`dead` 只标记不删除**：需要人工排查 `last_error`；本批不提供自动重放入口。
