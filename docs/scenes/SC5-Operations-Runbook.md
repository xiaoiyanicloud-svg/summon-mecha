# SC5 场景运营与运行维护手册

更新日期：2026-10-22（Asia/Shanghai）。适用于当前 `SceneOperations` 命令、场景增量迁移和 Admin 路由。以下命令在 `my-bagisto-store` 应用根目录执行，数据库时间统一为 UTC。生产连接及登录凭证沿用部署配置。

## 1. 调度与只读检查

应用已在 `bootstrap/app.php` 注册每分钟 `scenes:operations --consume=100 --sweep`，带 `withoutOverlapping()`，并受 `scenes.operations_enabled` 控制。部署主机只需运行 Laravel Scheduler，避免再为同一场景命令配置一份重复 Cron。Linux 示例：

```cron
* * * * * cd /path/to/my-bagisto-store && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

核对实际命令定义、调度注册及表结构状态：

```bash
php artisan help scenes:operations
php artisan schedule:list
php artisan migrate:status
php artisan scenes:operations
php artisan scenes:operations --metrics
```

| 参数 | 当前语义 |
|---|---|
| `--consume=0` | 默认值为 0；不消费事件。仅此选项且没有 `--sweep` 时为只读报告。接受 0 至 1000 的整数，非法值在扫描前以退出码 1 拒绝。 |
| `--consume=100` | 最多尝试消费 100 次；遇到 `idle` 提前结束。不承诺处理所有积压。 |
| `--sweep` | 有写入：过期 held 预留、受理与报价待办、线下验收复核待办和联系 SLA 扫描。不会将设备直接恢复 ready，也不会清除 Outbox 租约。 |
| `--metrics` | 已注册的兼容参数。当前无此参数时也输出指标，两种调用均输出同一 JSON 报告。 |

当前没有 `--check-sla`、`--limit` 或 `--json` 参数。JSON 报告包含 `database_driver`、`missing_tables`、`features`、`delivery` 和 `metrics`；缺表或本次消费返回 `failed` 时退出码为 1。退出码 0 不能证明常驻进程或调度主机仍存活，须同时检查 Scheduler 日志与运行进程。

根因已核实后的一次受控消费：

```bash
php artisan scenes:operations --consume=100 --sweep
```

## 2. Outbox 查询与告警

实际表为 `business_outbox`，场景查询必须限定 `event_type LIKE 'scene.%'`。没有独立 `status` 列：`processed_at`、`attempts`、`available_at`、`lease_token`、`lease_until` 共同表达状态。

- 待处理：`processed_at IS NULL` 且 `attempts < 8`；还需满足可用时间和无有效租约才能领取。
- 领取时先递增 `attempts`，租约为 60 秒。进程中断后的到期租约由下一次 `consumeOne()` 接管，`--sweep` 不接管租约。
- 失败释放自己的租约，记录 `last_error_code`，并按 `min(3600, 2^attempts * 10)` 秒退避。
- 未完成且 `attempts >= 8` 为停止自动领取的记录，包括最后一次领取后进程中断的情况；核查后按第 3 节受控重放。

待处理积压（包含尚未到重试时间或正在租赁的事件）：

```sql
SELECT COUNT(*) AS pending_events
FROM business_outbox
WHERE event_type LIKE 'scene.%' AND processed_at IS NULL AND attempts < 8;
```

停止自动领取的事件：

```sql
SELECT COUNT(*) AS dead_events
FROM business_outbox
WHERE event_type LIKE 'scene.%' AND processed_at IS NULL AND attempts >= 8;
```

可被下一次消费接管的到期租约：

```sql
SELECT COUNT(*) AS expired_leases
FROM business_outbox
WHERE event_type LIKE 'scene.%' AND processed_at IS NULL AND attempts < 8
  AND lease_until IS NOT NULL AND lease_until <= UTC_TIMESTAMP();
```

未首次联系且超过联系截止时间的真实需求：

```sql
SELECT COUNT(*) AS overdue_contacts
FROM scene_service_clocks AS c
JOIN scene_requests AS r ON r.id = c.request_id
WHERE c.state = 'awaiting_contact' AND c.due_at < UTC_TIMESTAMP()
  AND r.is_test = 0 AND r.source <> 'legacy_custom'
  AND r.state NOT IN ('closed', 'cancelled', 'converted');
```

待办实际表为 `scene_operation_todos`。`due_at` 可为空，不能将其当成所有待办的统一 SLA；首次联系 SLA 以上述 `scene_service_clocks` 为准。告警可从积压持续增大、死信非零、到期租约持续不降和联系超时非零开始配置，阈值由实际处理容量与已批准服务日历确定。

## 3. 死信受控恢复

先只读核对对象、尝试次数与错误码，不在告警日志导出业务 payload：

```sql
SELECT id, event_id, aggregate_type, aggregate_id, event_type, attempts,
       last_error_code, available_at, lease_until, updated_at
FROM business_outbox
WHERE event_type LIKE 'scene.%' AND processed_at IS NULL AND attempts >= 8
ORDER BY id;
```

1. 按 `last_error_code` 处理事件契约、对象状态、原件或下游故障；核查第 8 次领取是否已产生投影。
2. 登录具有 `catalog.scene-workflow.operate` 权限的 Admin，在场景工作台点击该事件的重放操作。其路由名为 `admin.catalog.scene-workflow.replay`，默认路径为 `POST /admin/catalog/scene-workflow/outbox/{id}/replay`，保留会话与 CSRF 防护。
3. 该入口调用 `SceneOperationsService::replay()`：拒绝非场景事件、已完成事件和仍有有效租约的事件；强审计 `scene.outbox.replayed` 与重置在同一事务提交。审计失败整体回滚。不要用裸 SQL UPDATE 替代此入口。
4. 执行上一节的受控消费命令，再查询原事件的 `processed_at`。消费者的 `business_event_receipts` 幂等收据阻止重复业务投影；成功不等于收款或设备动作自动完成。

## 4. 指标与核账口径

`metrics` 包括漏斗、报价、交付、验收质量、财务、资源、售后和待办。需求相关财务与验收排除 `is_test=1`、`source=legacy_custom`；全局资源及运维队列按其真实运行范围统计。

- 初验率按 `project_id + item_key` 去重：初验通过项 / 已有验收事实的独立项目验收项。复验通过不计作初验通过；尚未产生验收事实的标准不在该分母内。
- `financial.total_payment_receipt_minor` 为已独立复核的报价及项目实际收款，金额为整数分，含押金，不能直接当收入。报价原始凭证与项目冻结快照只计一次，同一报价/项目内跨阶段复制的同类凭证去重；不同项目的同名凭证独立计入。
- `total_refund_due_minor` 只含已独立复核的应退责任；`pending_refund_due_minor` 为待复核建议；`total_refund_receipt_minor` 为已复核实际退款。`net_cash_received_minor` 只用收款减实退，不能把应退与实退再扣两次。
- `requires_reconciliation=true` 时核对 `conflicting_receipt_records`、`snapshot_payment_mismatch_count` 和 `legacy_snapshot_payment_minor`。只有冻结总额的老项目保留一次历史值，并明确标记核账；同一凭证异额不静默叠加。

## 5. 停写与恢复

| 配置 | 行为 |
|---|---|
| `SCENE_ORDER_CONVERSION_ENABLED=false` | 暂停报价转订单/项目，返回 503 `SCENE_WRITES_DISABLED`；不代表所有项目动作停写。 |
| `SCENE_WORKFLOW_ENABLED=false` | 场景业务服务写命令返回 503 `SCENE_WRITES_DISABLED`；有效授权下的历史读取保留原鉴权及数据校验。 |
| `SCENE_OPERATIONS_ENABLED=false` | 同时暂停场景 Scheduler 的条件执行及显式消费/扫描写入；只读运维报告仍可检查。 |

通过既有部署配置流程应用这些开关，清理该部署实例的配置缓存，再按部署方式重载长驻应用和 Worker。恢复时逐级恢复实际启用的开关，核对只读报告、告警、任务日志及一条经授权的写入验证，不使用不完整的匿名 POST 当作停写成功证明。

```bash
php artisan config:clear
```

`qa/scenes/preflight.php` 明确只允许 `APP_ENV=testing`、`DB_DATABASE=summon_mecha_scenes_test` 并核对数据库身份；它是隔离验收工具，不能作为生产巡检命令。生产缺表检查使用只读运维报告及 `migrate:status`，迁移由既有发布流程执行。

## 6. OCC、事故与技能售后

运营写入携带当前 `row_version`。收到 409 `SCENE_VERSION_CONFLICT` 后先刷新并审阅最新事实，再决定是否发起新命令；丢响应时保留原载荷和幂等键查询/重试。

事故通过路由 `admin.catalog.scene-workflow.incident`（默认 `POST /admin/catalog/scene-workflow/projects/{id}/incident`）提交 `row_version`、`incident_ref`、`reason`、`pause_project=1`，需要 `catalog.scene-workflow.deliver`。项目暂停并生成 `incident_review`，实物资源进入 maintenance。恢复路由 `admin.catalog.scene-workflow.resume` 仅恢复项目之前的状态，**不会恢复设备 ready、自动关闭事故待办或消除争议**；设备核验和待办处置须各自留证完成。

技能黑名单继续由既有熔断服务管理；`SCENE_SKILL_CIRCUIT_BROKEN` 会阻断相关任务推进。技能工单须关联当前客户、本项目技能子单及冻结版本的有效正式授权，场景工单仅关闭技术支持问题；技能退款仍走既有 D10 售后申请与独立审核，不能从场景工单直接创建退款责任。

已取消且实物未返库的项目保留占用并维持维护状态；可走正常留证返库入口。运输维护缓冲、损坏争议及财务复核分别处理。旧项目缺少客户接受的冻结取消政策时，不能借当前政策自动扣罚结算。

## 7. 手册回归验证

`tools/tests/scene-operations-runbook.mjs` 新建专属 MySQL 实例，复用完整 Laravel/Bagisto 迁移与隔离夹具，逐条执行上文只读 SQL、实际 Artisan 命令及带鉴权/强审计守卫的重放恢复检查。它不使用现有业务数据库，完成后核验运行标记并清理自己的测试库。

```text
node tools/tests/scene-operations-runbook.mjs --mysql-bin <mysqld绝对路径> --evidence-dir docs/scenes/sc45-p2-fix-evidence
```
