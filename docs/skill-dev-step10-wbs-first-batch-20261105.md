# WBS 无外部硬件依赖工作包 · 第一批交付（2026-11-05）

> 依据：用户拍板「需要我拍板的你默认最优选择，开始执行第一批」。
> 范围：**B2 → B1 → B7 → B8 → A2（平台侧）**。A1 / A3~A7 / B3~B6 不在本批。
> 环境：Windows 11 + PowerShell；PHP 8.4.24（目标 8.3 未装，偏差已记录）；Python 3.12.14；MySQL **5.7.26 / REPEATABLE-READ**（本机隔离库 `summon_mecha_step10_test`）。

## 0. 结论先行

- 本批 **5 个工作包均已落地并通过回归**：B2（6 测试 + 2/8/16 真并发）、B1（9 单测 + 2 路由 + 双进程 claim）、B7（9 负例）、B8（3 测试 + 只读命令）、A2 平台侧（10 测试 + AgentV2 回归 9 测试）。
- 受影响的既有套件全绿：D9 8、GA 契约 11、Agent 错误契约 9、事件归属 4、编译器单测 5、**C-T 验收 28**、全链路 2。
- 构建门禁全绿：`route:list`、`migrate:status`、`ecosystem:contract-check`、`ecosystem:data-preflight`。
- **诚实缺口**：A1 事件正文签名、A2 硬件观察器、A2.4 Agent 侧 `now()` 伪造 stopped_at、B1 非便携登记路径、B3 Admin API/ACL、A3~A7、B4~B6 **未做**。等级见 §7。

## 1. 已采用的默认拍板

| 槽位 | 本批采用 | 备注 |
|---|---|---|
| Stop Observer | `source_type` 白名单 = `hardware_estop / motor_controller_feedback / safety_plc / independent_watchdog`；8 类非物理来源一律 422；`velocity≤0.05`、`current≤0.5`、`brake=true`、质量非 `bad`、`observed_at` 相对 `reported_at` 30s 内 | 阈值可配；**不是厂商限值**，真机 HIL 仍 BLOCKED |
| 事件签名 V3 | 本批未实现（A1 属第二批）；默认方案已记录在 AUDIT | 不宣称已签正文 |
| Build Worker | `database` 队列 + 显式 `skill:build-worker --once`；异步开关 `SKILL_BUILD_ASYNC_ENABLED` **默认 false**；`run_number` 用行锁 + 仅唯一键冲突有界重试 | 本机 MySQL 5.7.26；生产版本/隔离级别仍待补充 |

## 2. 改动清单

### B2 · run_number 单一分配
- 新增 `2026_11_05_000001_create_skill_build_sequences.php`（`build_key` 唯一 + `last_run_number`）。
- 新增 `Services/SkillCompile/RunNumberAllocator.php`：事务内 `SELECT ... FOR UPDATE` 自增；分配与 `skill_build_runs` 插入同事务；**只**对 `uk_build_sequences_key` 的 1062 做有界重试。
- `SkillBuildService::persist()`、`PortableSkillBuilder::reserveBuildRun()` 删除 `max+1`，改调分配器。
- 测试 `SkillBuildRunNumberTest.php`（6）。

### B1 · BuildCommand 生产化
- 新增 `2026_11_05_000002_create_skill_build_commands.php`：`(seller_id, idempotency_key)` 唯一、`request_hash`、状态机、`payload_json/result_json`、`started_at/finished_at`。
- 新增 `Models/SkillBuildCommand.php`、`Services/SkillCompile/BuildCommandService.php`、`BuildCommandConflictException.php`。
- 新增 `Console/Commands/SkillBuildWorker.php`（一轮有界批次，默认开关关闭）。
- `SkillSupplyController::compile()`：
  - **便携编译 `runtime.source_format=btcpp/xml4`** → 必须有源包字节 → 构造 `BuildCommand` → `BuildCommandService::submit()`；
  - **非便携登记型（motion/app）** → 保留既有 `SkillBuildService::compile()`（无源包字节可摘要，D-9 口径）。
  - 未显式给 `idempotency_key` 时按请求内容派生，重试同载荷命中同一条命令。
- `ApiErrorCode` 新增 `SKILL_COMPILE_SOURCE_NOT_FOUND`、`BUILD_COMMAND_CONFLICT`。
- 测试 `BuildCommandIdempotencyTest.php`（9）、`BuildCommandRouteTest.php`（2）；双进程 claim 探针。

### B7 · 交付解析 fail-closed
- `SkillDeliveryAssetResolver::isAssetApprovedForDelivery()` 增加：
  - `release.build_key === asset.build_key`；
  - `release.source_digest` 与 `asset.source_digest` 一致（旧 generation 拒绝）。
- 测试 `SkillDeliveryRevocationTest.php`（9）：撤销、build_key、source_digest、profile 过期、source 直发、跨机型、无 release、旧/新 generation。

### B8 · 构建指标
- 新增 `Services/SkillCompile/BuildMetricsService.php`：从命令表派生 `build_accepted/started/succeeded/failed/canceled/queued/running`、`build_duration_ms`、`status_breakdown`、`result_status_breakdown`；`build_key` 为唯一关联过滤。
- 新增 `Console/Commands/SkillBuildMetrics.php`（只读）。
- 测试 `BuildMetricsTest.php`（3）：状态/时长、build_key 过滤、同键重试不重复计数。

### A2 · StopObservation（平台侧）
- 新增 `Services/Deploy/Contracts/StopObservation.php` + `StopObservationSourceForbiddenException.php`：14 字段强类型；8 类非物理来源抛专用异常；`isAcceptable()` / `isFresh()`。
- 新增 `config/stop_observation.php`（保守阈值，可配）。
- `ExecutionFact`：终态必须携带 `StopObservation`；`toArray()` 包含它。
- `AgentController::event()`：解析 `stop_observation`；禁用来源 → 422；形状/质量/过期 → 降级 UNKNOWN；观察与事实矛盾 → 422。
- `DeployTaskService::onEvent()`：`assertFactMatchesPhase` 允许终态 phase 携带 UNKNOWN；fact=UNKNOWN 时任务安全收敛到 `timeout`，**绝不进 success**；`persistExecutionFact` 落 `stop_observation` 审计。
- 测试 `StopObservationContractTest.php`（10）；`AgentV2ExecutionTest` 终态夹具补 synthetic 观察（9 全过）。

## 3. 原始证据（命令 / 退出码 / SHA256）

所有文件位于 `qa/wbs-20261105/`，完整清单见 `evidence-manifest.json`（SHA256 `E59277A3BBB399BBA1D204FDDA6C722B27C750080BF99951078066992D175D5F`）。

| WBS | 证据文件 | 退出码 | 结果 | SHA256 |
|---|---|---|---|---|
| B2 | `b2-run-number.txt` | 0 | 6 passed / 16 assertions | `9F7FD90C…C75B5` |
| B2 | `b2-concurrency-evidence.json` | 0 | 2→1,2；8→1..8；16→1..16；行数/去重/极值一致 | `8843FC0D…A83AB` |
| B1 | `b1-idempotency.txt` | 0 | 9 passed / 32 assertions | `A054A466…C1912` |
| B1 | `b1-route.txt` | 0 | 2 passed / 11 assertions（生产路由落命令表） | `680115E1…DD5AD` |
| B1 | `b1-claim-evidence.json` | 0 | 双进程仅 worker-A claimed=true | `C9FE50D6…57EB` |
| B7 | `b7-delivery.txt` | 0 | 9 passed / 10 assertions | `031ED29D…1372` |
| B8 | `b8-metrics.txt` | 0 | 3 passed / 23 assertions | `2824553E…D5D3D` |
| B8 | `build-metrics.txt` / `build-worker-once.txt` | 0 / 0 | 只读快照、worker 0 claimed | `B70994EF…D2C3` / `9906CA31…D938` |
| A2 | `a2-stop-observation.txt` | 0 | 10 passed / 127 assertions | `1A065571…BD3E` |
| A2 回归 | `agent-v2-regression.txt` | 0 | 9 passed / 71 assertions | `3B7752B0…8EBB` |
| 回归 | `d9-compile-honesty.txt` | 0 | 8 passed / 55 assertions | `3555D450…C9BC` |
| 回归 | `ga-five-model.txt` | 0 | 11 passed / 90 assertions | `62564AF9…3DD6` |
| 回归 | `agent-error-p9.txt` / `agent-ownership.txt` | 0 / 0 | 9 / 4 passed | `B47DBD5F…8BDA` / `F4760108…8147` |
| 回归 | `portable-compiler-unit.txt` | 0 | 5 passed / 5116 assertions | `B739CAE7…894E` |
| 回归 | `compiler-acceptance.txt` | 0 | **28 passed / 122 assertions** | `229CE6AD…2A36` |
| 回归 | `full-chain.txt` | 0 | 2 passed / 75 assertions | `E56C6A78…FAF1` |
| 门禁 | `contract-check.txt` / `data-preflight.txt` | 0 / 0 | 契约通过 / 无重复孤儿计数问题 | `C20FB58D…26A5` / `776CA42D…D8DB` |
| 门禁 | `route-list-skill-supply.txt` / `migrate-status.txt` | 0 / 0 | 14 路由；两条新迁移 [4]/[5] Ran | `12D86F02…2C25` / `76E9242A…E6F` |
| Lint | `php-lint-round1.txt` | 0 | 31 文件全 OK | `B50B97E2…E892` |

## 4. 失败测试优先（诚实说明）

- 本批测试与实现**交替编写**；未对每一项单独保存「旧代码上失败 → 新代码通过」的 red→green 日志。此项在 §6 标 `RED_GREEN_LOG = NOT_CAPTURED`。
- 缺陷存在的代码证据（改动前）：
  - B2：`SkillBuildService.php:132`、`PortableSkillBuilder.php:204` 两处 `max(run_number)+1`；后者 `catch (QueryException)` **吞掉全部**异常。
  - B1：`SkillSupplyController.php:102` 直接 `$this->builder->compile(...)`；`compileWithCommand` 在 `src` 内零调用方（`rg` 证据）；无 `skill_build_commands` 表。
  - B7：`SkillDeliveryAssetResolver` 未比较 `release.build_key` 与 `asset.source_digest`。
  - A2：`ExecutionFact` 允许 `SUCCEEDED` 仅凭 `actual_stopped=true`，无独立观察对象。
- 说明：以上为**代码事实 + 新测试通过**的组合证据；不宣称已保存旧代码失败日志。

## 5. 回滚

仅在隔离库/受控环境执行；**禁止**对 root 库执行 `migrate:fresh` / `truncate` / `seed`。

```powershell
# 1) 先排空异步命令（若开过 SKILL_BUILD_ASYNC_ENABLED）
php artisan skill:build-worker --once

# 2) 回滚两条加性迁移（隔离库）
php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_11_05_000002_create_skill_build_commands.php
php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_11_05_000001_create_skill_build_sequences.php
```

代码回滚：按 §2 文件清单逐文件还原（本批未执行任何 git add/reset/clean/stash/checkout）。新增文件直接删除；修改文件恢复原实现。回滚后重跑 §3 的回归与门禁。

## 6. NOT_PROVEN / BLOCKED

| 项 | 状态 | 原因 / 最小解除条件 | 当前证据等级 |
|---|---|---|---|
| B2 生产 MySQL 版本/隔离级别 | NOT_PROVEN | 本机仅 MySQL 5.7.26 / REPEATABLE-READ；生产事实待槽位 3 | CODE + TEST(本机) |
| B1 非便携登记路径 | PARTIAL | `motion/app` 无源包字节，仍走 `SkillBuildService::compile`；需定义 `RegistrationCommand` 或要求源包 | CODE + TEST |
| B1 取消后物理副作用 | NOT_PROVEN | `cancel()` 只阻止迟到结果覆盖；已开始的 builder 无法回滚 | CODE |
| B2/B1/B7/B8/A2 red→green 日志 | NOT_CAPTURED | 未保存旧代码失败输出；缺陷以代码位置证明 | CODE |
| A2 真机停止观察器 | BLOCKED | 无真实传感器/采样/断线语义；需厂商接口与 HIL | 平台契约 TEST；硬件 NOT_RUN |
| A2.4 Agent 侧 `stopped_at=now()` | NOT_FIXED | `agent/zhjj_agent/task_manager.py:320-324` 仍用 `now()`；本批只做平台侧 | CODE |
| A1 事件正文签名 / V3 / 重放 | NOT_RUN | 属第二批；本批未改请求签名 | — |
| A3/A4/A5/A6/A7 | NOT_RUN | 未在本批范围 | — |
| B3 Admin API/ACL/四角色 | NOT_RUN | 本批只保留服务层状态机 | — |
| B4/B5/B6 | NOT_RUN | 未在本批范围 | — |
| KMS/HSM、生产 FPM/MySQL、真机 HIL | BLOCKED | 外部前提 | — |

## 7. 等级（本批结束时）

- 软件公共底座：**PARTIAL**（本批 5 项完成；A1/A3~A7/B3~B6 未做）。
- 当前 SIL：**NOT_RUN**（本批未跑物理仿真）。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 8. 本批未触碰的红线

- 未对 root 库执行 `migrate:fresh` / `truncate` / `seed`；所有迁移与测试均指向 `summon_mecha_step10_test`。
- 未执行 `git add -A` / `reset` / `clean` / `stash` / `checkout`。
- 未引入 Redis / Kafka / EMQX / 微服务。
- 未把 synthetic / mock / SIL 标记为 HIL；A2 测试观察器均标注 `synthetic-test-only`。
