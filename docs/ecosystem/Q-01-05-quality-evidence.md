# Q-01 ~ Q-05 · 横向质量工作包交付证据

日期：2026-10-20。范围：[Ecosystem-Engineering-Backlog.md](./Ecosystem-Engineering-Backlog.md) §7 全部 5 个横向工作包。
环境：Windows 11 + PHP 8.4.24 + MySQL 5.7.26 + 独立测试库 `summon_mecha_step10_test`。
结论：**5 个包全部实现并跑通**；过程中发现并修复了 2 个产品代码缺陷（见 §2、§3）。

> 本文只记录**实际执行过**的检查。未做项在 §6 明示，不标 ✅。

## 0. 交付物

| 包 | 代码 / 工具 | 证据 |
|---|---|---|
| Q-01 | `config/ecosystem_contract.php`、`ContractCoverageChecker`、`ecosystem:contract-check`、`docs/ecosystem/openapi.json`、`EcosystemContractGateQ1Test` | §1 |
| Q-02 | `qa/ecosystem/bootstrap.php`（专用库硬校验）、`qa/ecosystem/q02/*` | `q02-result.json` |
| Q-03 | `qa/ecosystem/q03/*`；`OutboxLeaseService` failed/dead 语义修正 | `q03-result.json` |
| Q-04 | `qa/ecosystem/q04/*`（CDP 驱动 + 四角色夹具） | `q04-result.json`、`q04-screenshots/` |
| Q-05 | `ecosystem:data-preflight`、`qa/ecosystem/q05/*`、`EcosystemDataPreflightQ5Test` | `q05-result.json` |

运行说明见 [qa/ecosystem/README.md](../../my-bagisto-store/qa/ecosystem/README.md)。

### 回归基线（2026-10-20，独立测试库）

```text
Shop Ecosystem 全量：522 passed / 3,703 assertions（含 Q-01 9 项、Q-05 2 项）
Admin ACL + G4 + G3 权限：17 passed / 520 assertions
前端 Node：37 passed
契约门禁：113 后台路由 / 45 公开路由 / 85 错误码 / 11 状态快照 / 0 违规
```

> 注意：两套 PHPUnit 套件**不能并发**跑同一个测试库，否则会互相看到对方的事务数据；
> 本次曾在并发跑时出现 1 个 ACL 假失败，串行复跑 17/17 通过。

## 1. Q-01 契约门禁

### 交付

- `config/ecosystem_contract.php`：45 条公开路由的 URI / 方法 / 鉴权 / 幂等策略 / 限流 / 稳定错误码；
  85 个生态域错误码；11 个模型状态快照；4 组词表；5 个公开 DTO → OpenAPI schema 映射。
- `ContractCoverageChecker`：双向核对
  - 后台 113 条 `admin.catalog.ecosystem.*` 路由 ↔ `config/ecosystem_admin.php` ↔ `acl.php`；
  - 公开路由 ↔ 契约（URI/方法/handler/鉴权/幂等/错误码）；
  - 源码使用的 `ApiErrorCode` / 域异常字面量 ↔ 已登记错误码；
  - 发布转移表 / outbox 映射 / 模型状态常量 ↔ 冻结快照；
  - OpenAPI operationId / 方法 / `x-error-codes` / `$ref` / `x-resource-class` ↔ 契约。
- 构建入口：`composer ecosystem:contract-check`（非零退出即阻断）。

### 门禁抓到的真实缺陷

`CompetitionTeamController` 两处引用 `ApiErrorCode::COMPETITION_TRACK_NOT_FOUND`，但该常量**从未定义**；
一旦走到该分支会抛 `Undefined constant` → 500。已补常量并登记错误码。

### 结果

```text
后台路由 113；公开路由 45；契约登记 45；错误码 85；状态快照 11；违规 0。
Q-01 专项测试：9 passed / 15 assertions
```

测试同时验证门禁不是“永远绿”：未登记公开写路由、后台缺权限、写路由缺鉴权/幂等、
未登记错误码、状态常量漂移、转移表未知状态、OpenAPI 缺 operation / 错误码不一致，均会被拒绝。

## 2. Q-02 真实并发与隔离

### 交付

- `qa/ecosystem/bootstrap.php`：`APP_ENV=testing` 且库名以 `_test` 结尾才执行；否则 `exit(2)`。
- 两进程容量竞争、两进程行锁等待、REPEATABLE READ 快照 vs `FOR UPDATE` 当前读、清理。

### 实测（`q02-result.json`，7/7 通过）

```text
guard_rejects_root                 PASS  （真实子进程 + DB_DATABASE=root → exit 2）
guard_accepts_test_db              PASS
capacity_race_one_confirmed        PASS  （两个独立 PHP 进程，容量 1，仅 1 confirmed）
capacity_race_loser_rejected       PASS  （另一方 REGISTRATION_FULL，无残留 attempt）
real_lock_wait                     PASS  （另一进程持有 event+scope 锁，waiter 阻塞约 2.5s）
repeatable_read_snapshot_vs_current PASS （普通 SELECT 旧快照；FOR UPDATE 读到新提交）
fixture_cleanup                    PASS
```

## 3. Q-03 Outbox / worker / 补偿

### 交付

- 真实 worker 子进程（`php artisan ecosystem:process-outbox`）、afterCommit 第二连接观察、
  重复投递、崩溃租约、25 条跨批积压、failed 人工补偿与 dead 终态。

### 修复的产品缺陷

G4-07 运营台的“通知失败重排”只接受 `status='failed'`，但 worker 从未写过 `failed`：
可重试失败耗尽后直接写 `dead`，导致运营台**永远看不到可补偿失败**，重排入口形同虚设；
而把所有 `dead` 都开放重排又会复活 `superseded` / 事实不符等终态旧事实。

修正（`OutboxLeaseService::fail`）：

```text
可重试且未达上限 → pending（指数退避）
可重试但达上限   → failed（运营确认后可重排）
不可重试         → dead（终态，绝不复活）
```

同步更新处理器 summary（`failed` 计数与告警）和 G1 租约测试。

### 实测（`q03-result.json`，10/10 通过）

```text
after_commit_visibility                 PASS  未提交不可见 / 提交可见 / 回滚无残留
worker_dry_run                          PASS
real_worker_consumes_seed               PASS  submitted + confirmed 全部 sent
seed_notifications_created              PASS
duplicate_delivery_no_duplicate_notification PASS 同一事实重放不新增站内信
lease_recovery_and_stale_rejection      PASS 过期租约恢复；旧 owner settle 抛 LeaseLost
backlog_drained_across_batches          PASS  25 条跨两个批次清空
compensation_failed_retry_and_dead_terminal PASS failed 重排后送达；dead 被拒绝重排
```

回归：`EcosystemOutboxLeaseG1Test` + `EcosystemOutboxProcessorG1Test` = 17 passed / 75 assertions。

## 4. Q-04 真实浏览器四角色演练

### 交付

- `qa/ecosystem/q04/driver.mjs`：无 Playwright 的 CDP 驱动（视口、Tab/Enter、截图、异常收集）。
- `run.mjs`：自动创建夹具、启动测试库服务（8010）与 H5 静态站点（8099）、四角色演练、清理。
- 9 张截图：`q04-screenshots/`（按 HANDOFF Git 红线，本机截图不入库；可用 `q04/run.mjs` 复现）。

### 实测（`q04-result.json`，18/18 通过，其中 1 项为 observed）

| 角色 | 检查 |
|---|---|
| 客户 | 错误密码停留登录区；键盘 Tab 顺序 4 个可聚焦元素；空表单字段错误；提交成功回执含 `public_no`；我的报名可查回；375px 无横向溢出 |
| 队长 | 队伍详情显示“我是队长”；生成一次性邀请码；无效邀请码走错误分支；375px 无横向溢出 |
| 裁判 | 有赛务只读权可看成绩页；直接访问报名列表 **403**，页面不泄漏报名邮箱；390px 无横向溢出（observed） |
| 超管 | 报名列表 200 且列出活动 |

关键指标：

```text
customer 375px: innerWidth=375 scrollWidth=375 overflowX=false
captain  375px: innerWidth=375 scrollWidth=375 overflowX=false
admin    390px: innerWidth=390 scrollWidth=390 overflowX=false（observed）
referee  registration list: status=403, leaks_email=false
```

## 5. Q-05 数据 preflight 与迁移 / 生命周期演练

### 交付

- `php artisan ecosystem:data-preflight [--json=] [--strict] [--sample=]`：只读；
  覆盖 schema、事件/资讯公开指针、状态/时区/起止时间、报名身份、重复活跃轮次、
  scope 计数/容量、outbox 租约与积压、媒体孤儿与文件缺失。
- `qa/ecosystem/q05/*`：风险数据夹具、preflight 断言、计数对账、留存 dry-run、
  expand / backfill / switch / recovery 演练。

### 实测（`q05-result.json`，9/9 通过）

```text
preflight_detects_risk_codes            PASS
  scope_counter_mismatch / scope_counter_exceeds_capacity
  duplicate_active_attempts / attempt_status_unknown
  event_timezone_invalid / revision_time_invalid / outbox_expired_lease
preflight_is_read_only                  PASS  （前后行数与计数完全一致）
reconcile_dry_run_reports_without_write PASS  （stored=5 保持不变）
reconcile_apply_fixes_counter           PASS  （stored 修正为 2）
retention_dry_run_no_delete             PASS  （未执行任何删除）
migration_drill                         PASS
  backfill 幂等（跑两次不重复）
  回填后失败撤掉探针 → 源数据哈希不变
  switch 视图行数/内容与源一致
  切换后新写入 → 破坏性回退被拒绝
  前向恢复再次回填 → 新行进入目标表
  探针清理干净
```

另外在 root 主库跑过一次只读 preflight：**0 blocker / 0 warning**（未写库）。
Q-05 专项测试：2 passed / 9 assertions。

## 6. 未做 / 边界

| 项 | 说明 |
|---|---|
| 生产规模并发 | Q-02/Q-03 在独立测试库执行，不是生产压测；生产并发仍需 Q-02/Q-03 在约定环境复跑 |
| CI 流水线 | 只提供 `composer ecosystem:contract-check` / `ecosystem:data-preflight` 入口，未接 GitHub Actions / Jenkins |
| OpenAPI 覆盖范围 | 只覆盖 Shop 公开 API；后台由 ACL 映射 + 逐路由测试覆盖，不重复生成 OpenAPI |
| Q-04 真机 | Chrome 桌面 headless；小程序真机、微信内置浏览器、Safari 未验证 |
| Q-04 H5 构建 | 需先 `D:/HBuilderX/cli.exe publish web --project D:/main`；构建产物不入库 |
| Q-05 迁移演练 | 是测试库上的流程演练（`qa_q05_*` 探针），不等于生产迁移已批准；生产迁移仍需 runbook、备份与值守 |
| Q-05 数据源 | preflight 只检查库内事实，不扫描外部 CSV / 历史 dump |
