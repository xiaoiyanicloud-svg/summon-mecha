# 五机型技能交付 GA · P0 公共底座交付报告

- 日期：2026-11-04
- 范围：P0 公共底座 + P1/P2/P3 Adapter 契约接入；不声称真机 GA
- 锁定目标：`unitree_g1`、`unitree_r1`、`agibot_x2`、`agibot_a3`、`engineai_t800`
- A2 处置：运行 Profile 已删除；数据库/种子仅保留 `display_only` 历史记录；A2 Adapter 注册返回拒绝
- 结论：**公共软件底座与模拟/隔离测试通过；五机型生产可交付全部 BLOCKED**

## 1. 结论摘要

1. 已建立五机型独立 Target Profile、独立 Adapter 类与注册表，并删除 `agibot_a2.json` 运行 Profile。
2. 已固化 `TargetProfile`、`BuildCommand`、`AgentTaskEnvelopeV2`、`ExecutionFact`、`ReconcileReport` 强类型契约。
3. `deploy_task` 继续作为持久 Outbox，新增原子领取、投递租约、`delivery_attempt`；新增 `deploy_inbox_event` 以 `(did,event_id)` 唯一键幂等处理 ACK。
4. 目标审核新增产物、build_key、Profile 摘要三重快照校验和只追加审计事件。
5. 设备端新增 nonce 防重放、v2 envelope 机型/Profile/key_id/时间窗口校验；缺桥时 Broker fail-closed。
6. 平台 Deploy 全量 162 项、Agent 62 项、编译器 31 项均通过。
7. 控制面 red-team 本机 p95 为 **1012.640ms、p99 为 1190.035ms，超过 300ms 门槛**；30 分钟 soak 已执行并通过（软件层），但性能门禁仍失败。
8. 五款机型均无真机 HIL，统一为 `HARDWARE_UNVERIFIED` / `Production Ready = BLOCKED`。

## 2. Gap Matrix

| 能力 | 本轮前 | 本轮实现 | 仍存缺口/风险 |
|---|---|---|---|
| 五机型 Profile | G1/A2/Mock | 五机型 v2 Profile；A2 运行 Profile 删除 | R1/X2/A3/T800 关节、力矩、温度与固件范围等待厂商原始数据 |
| 五机型 Adapter | 仅 G1 | R1/X2/A3/T800 独立 Adapter 类 + 注册表 | 四机型真实 SDK 调用未取得机载验证 |
| SDK/协议边界 | 仅 G1 部分与 A2 字符串分支 | 每机型 SDK 名称、版本范围、传输、ABI 字段 | AimDK/Native SDK 具体版本与固件兼容矩阵未获批准 |
| 源包/IR | XML XXE、节点预算、JCS 摘要 | 新增路径规范化、文件数、64MiB 总读取预算 | 源包读取仍使用 ZipArchive 到内存；上限内可接受，需生产压测 |
| 多目标编译 | 测试仅 G1/A2 | 同一源分别构建五目标，5 个产物 SHA 唯一 | 四目标物理轨迹映射未完成 |
| 不可变构建 | build run 历史 | 唯一键并发分配、同一 build_key 不重置已审核状态 | 仍需生产并发压测 |
| 目标审核/发布 | 表存在但无服务 | approve/reject/revoke + Profile 摘要失效 + 审计事件 | 管理端 UI/API 权限入口未完成 |
| 签名/nonce/吊销 | HMAC、v2 签名、授权窗口 | did/model/profile/nonce/expires/key_id 纳入 envelope；Agent nonce 表 | 生产 KMS/HSM、多公钥轮换未实现 |
| 下载/解压 | 流式哈希、ZIP Slip、软链、炸弹检查 | 500MiB 实测内存增量 3.621MiB | 30 分钟软件 soak 已执行；生产存储/磁盘满未验证 |
| 安装/执行/取消/回滚 | G1 较完整 | 四机型独立类与 fail-closed 生命周期 | 四机型真实 SDK 停止观测与回滚未验证 |
| 幂等/并发/租约 | 条件更新、SQLite 租约 | Outbox 领取租约、Inbox 唯一键、skill_id 纳入任务幂等键 | 取消与完成的高并发仍需生产故障注入 |
| Outbox/Inbox/ACK | deploy_task + JSON ACK 数组 | 显式 Inbox 表、重复 ACK 返回原 ack_id、同键异载荷冲突 | Inbox 重复命中计数尚未作为指标持久化 |
| 多租户 | 多数路径有限定 | 新增路径不新增无范围 ID 查询 | 目标审核管理入口尚未做四角色越权验收 |
| 迁移/开关 | additive 迁移 | 新增 additive 迁移；`config/skill_ga.php` 全部默认关闭 | 尚未在 root 做部署迁移；管理端开关编排未完成 |
| 日志/指标/告警 | 零散 Log | 结构化 PHP/Python 日志；`skill-ga:metrics` | 告警平台、指标导出器和部分指标未埋点 |
| SIL/HIL | G1 仅历史 artifact cross-check | 当前五机型 SIL replay 均 NOT_RUN；HIL 均 UNVERIFIED | 五机型均无真机 HIL |

## 3. 核心契约

### TargetProfile

代码：`Webkul\Shop\Services\SkillCompile\Portable\TargetProfile`

必填字段：vendor、model_code、model_name、sdk_name、sdk_version_range、firmware_range、compatibility_level、capabilities、joint_limits、velocity_limits、torque_limits、temperature_limits、artifact_abi、profile_digest、hardware_verification_status。

约束：

- compatibility_level 只允许 `L1/L2/L3`。
- `L1` 必须同时为 `HARDWARE_VERIFIED`。
- 当前五机型均为 `L2 + HARDWARE_UNVERIFIED`。
- 摘要为 Profile 原始对象的 RFC 8785 JCS SHA-256。

### BuildCommand / BuildResult

- `BuildCommand`：skill_version_id、source_asset_id、source_digest、target_model_code、target_profile_digest、compiler_version、idempotency_key、requested_by、seller_id。
- 幂等键作用域：`seller:{sellerId}:build:{idempotencyKey}`。
- `BuildResult`：build_key、run_number、target_model_code、status、artifact_asset_id、package_sha256、target_profile_digest、error_code。

### AgentTaskEnvelopeV2

协议固定为 `agent-task/v2`。签名域包含：

`protocol, task_id, command_seq, did, operation, model_code, package_sha256, target_profile_digest, nonce, issued_at, expires_at, payload`

外层同时返回 `signature`、`key_id`。Agent 验签后校验 key_id、设备、机型、Profile 摘要、时间窗口和 nonce；同一 nonce 绑定其他 task 即拒绝。

### ExecutionFact / ReconcileReport

- `CANCEL_REQUESTED` 不得携带 `actual_stopped=true`。
- `SUCCEEDED` 必须 `actual_started=true` 且 `actual_stopped=true`。
- ReconcileReport 固化 did、model_code、observed_generation、installed_skills、active_executions、outbox_cursor、reported_at。

## 4. 状态机与事务边界

- Build：`pending -> running -> succeeded | failed | canceled`；数据库历史词表沿用 `success/failed`。
- DeployTask：`pending -> dispatching -> downloading -> installing -> success | failed | timeout`，离线为 `pending_online`。
- SkillExecution：`ACCEPTED -> RUNNING -> SUCCEEDED | FAILED | CANCELED | UNKNOWN`；取消先进入 `CANCEL_REQUESTED`。
- Installation：`absent -> downloading -> staged -> verified -> installed -> enabled`。

事务边界：

1. 任务状态与 Outbox 行（`deploy_task`）同事务。
2. 命令领取仅做 MySQL 锁和状态更新，不调用 HTTP/SDK/DDS。
3. Inbox ACK 与 `delivered_at` 在同一个 MySQL 事务提交。
4. Agent 任务 Journal、执行事实和事件 Outbox 在 SQLite 事务中提交。
5. 物理停止只在 Adapter `observe_execution()` 返回 `is_stopped=true` 后确认。

## 5. 五机型支持矩阵

| 机型 | 兼容等级 | 软件完成 | SDK 接入 | SIL | HIL | 生产可交付 |
|---|---|---|---|---|---|---|
| Unitree G1 `unitree_g1` | L2 | PASS | PARTIAL | NOT_RUN（仅历史 artifact cross-check） | HARDWARE_UNVERIFIED | BLOCKED |
| Unitree R1 `unitree_r1` | L2 | PARTIAL | PARTIAL（Adapter 契约） | FAIL（未运行） | HARDWARE_UNVERIFIED | BLOCKED |
| Agibot X2 `agibot_x2` | L2 | PARTIAL | PARTIAL（Adapter 契约） | FAIL（未运行） | HARDWARE_UNVERIFIED | BLOCKED |
| Agibot A3 `agibot_a3` | L2 | PARTIAL | PARTIAL（Adapter 契约） | FAIL（未运行） | HARDWARE_UNVERIFIED | BLOCKED |
| EngineAI T800 `engineai_t800` | L2 | PARTIAL | PARTIAL（Adapter 契约） | FAIL（未运行） | HARDWARE_UNVERIFIED | BLOCKED |

整体五机型 GA：**未完成**。

## 6. 改动文件

平台核心：

- `my-bagisto-store/resources/skill-target-profiles/{unitree_g1,unitree_r1,agibot_x2,agibot_a3,engineai_t800}.json`
- `my-bagisto-store/packages/Webkul/Shop/src/Services/SkillCompile/Portable/TargetProfile.php`
- `TargetProfileService.php`
- `CapabilityResolver.php` 既有接口复用
- `SemanticLowerer.php`
- `Builders/PortableSkillBuilder.php`
- `BuildCommand.php`
- `BuildResult.php`
- `SkillTargetReleaseService.php`
- `Services/Deploy/Contracts/*`
- `Services/Deploy/DeployInboxService.php`
- `Services/Deploy/GaOperationalMetrics.php`
- `Services/Deploy/DeployTaskService.php`
- `Services/SkillDelivery/SkillDeliveryAssetResolver.php`
- `Models/{DeployInboxEvent,DeployTask,SkillTargetRelease,SkillTargetReleaseEvent}.php`
- `Database/Migrations/2026_11_04_000001_create_deploy_inbox_and_outbox_leases.php`
- `Http/Controllers/API/{AgentController,RobotSupportMatrixController}.php`
- `Console/Commands/GaOperationalMetricsCommand.php`
- `config/{deploy_gateways,skill_ga}.php`
- `database/seeders/DeviceModelSeeder.php`
- `Routes/api.php`

Agent：

- `agent/zhjj_agent/adapters/{unitree_r1,agibot_x2,agibot_a3,engineai_t800,vendor_plan_adapter,registry}.py`
- `agent/zhjj_agent/{main,storage,task_manager,telemetry}.py`
- `agent/tests/{test_five_model_adapters,test_live_http_integration}.py`

测试/基准：

- `packages/Webkul/Shop/tests/Feature/Deploy/GaFiveModelContractTest.php`
- `PortableSkillCompilerAcceptanceTest.php`（新增 C-T25/C-T26）
- `KillSwitchSaleStopTest.php`（消除对人工 seed 分类的依赖）
- `qa/ga/benchmark_p0.php`
- `qa/ga/benchmark_claim_concurrency.php`
- `qa/ga/benchmark_control_plane_p95.py`
- `qa/ga/benchmark_stream_500mb.py`

## 7. 极端失败测试与基准实测

| 组 | 实际结果 |
|---|---|
| 幂等/重试 | 1,000 次同键同参数命令 → 1 条任务；p95 4.159ms |
| ACK 丢失/重复 | 1,000 次同 event_id ACK → 1 条 Inbox 事实；p95 2.284ms |
| 双 Worker 并发领取 | 2 个独立 PHP Worker → 1 个 claim，delivery_attempt=1 |
| 五目标编译 | 同一源构建五个目标成功，5 个产物 SHA-256 互异；A2 构建失败 |
| 安全/解压 | ZIP Slip、软链、炸弹、哈希不符、nonce 重放均有真实断言通过 |
| 500MiB 流式下载 | 524,288,000 bytes，耗时 7.166s，内存增量 3.621MiB，PASS |
| 控制面 p95/p99 | 100 请求：p50 777.492ms、p95 1012.640ms、p99 1190.035ms、max 1195.747ms；并发 2 饱和，**FAIL（阈值 300ms）** |
| 30 分钟 soak | **PASS（软件层）**：1,800.969s、899 cycles、2,697 commands、0 errors；内存 +8.324MiB、句柄 +11、线程峰值 2、临时文件 2→3 |

## 8. 实际验证命令与结果

```powershell
cd D:\main\my-bagisto-store
$env:APP_ENV='testing'; $env:DB_DATABASE='summon_mecha_step10_test'; $env:LOG_CHANNEL='stderr'
php artisan test packages/Webkul/Shop/tests/Feature/Deploy
# 162 passed / 5,778 assertions

php artisan ecosystem:contract-check
# PASS

php artisan ecosystem:data-preflight
# PASS

php artisan migrate:status
# 2026_11_04_000001 ... [2] Ran
```

```powershell
cd D:\main\agent
$python='C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
& $python -m unittest discover -s tests -p 'test_*.py'
# 62 tests OK
```

语法与路由：

- 37 个改动 PHP 文件全部 `php -l` 通过。
- Python `compileall` 通过。
- `GET /api/robot-support-matrix` 路由存在。
- 环境偏差：本机 PHP 为 **8.4.24**；未找到可用的 PHP 8.3。Python 为 **3.12.14**。

## 9. 监控与日志

PHP 结构化事件：

- `deploy.outbox.claimed`
- `deploy.inbox.ack_processed`
- `skill_target_release.approved|rejected|revoked`

Agent JSON 日志：

- `agent.command_received`
- `agent.execution_fact`

只读指标入口：

```powershell
php artisan skill-ga:metrics
```

已实现：Outbox 最老积压、队列滞后、活跃执行租约、执行状态计数、UNKNOWN 数、Inbox ACK 总数和告警布尔值。

尚未接入指标平台：签名失败、哈希不符、幂等命中、租约冲突、reconcile drift、Agent restart、staging orphan；命令中以 `null + not_instrumented` 诚实返回。

## 10. 灰度、回滚与运行手册

### 灰度

1. 保持 `config/skill_ga.php` 全关闭。
2. 按机型逐台打开 build → publish → install → execute。
3. 任何阶段只允许目标审核 approved 且 Profile 摘要匹配的产物。
4. L2 目标不得在客户页面展示为“开箱即用”。
5. 无 HIL 的机型不得进入生产 `READY`。

### 回滚

1. 关闭 `SKILL_GA_*` 对应阶段/机型开关。
2. 停止新构建与新安装；保留 ACK、对账、取消、卸载、回滚入口。
3. 目标发布执行 `revoke`，写入撤销原因和 actor。
4. 已安装设备下发签名回滚/禁用任务；离线设备上线后处理。
5. 不由旧成功构建覆盖失败构建；修复后生成新 build run。
6. 迁移回滚只允许在确认无新数据依赖后执行 `php artisan migrate:rollback --step=1`；生产优先前滚修复。

### 值守

- `execution_unknown_total > 0`：立即冻结相关资源租约并人工核实物理停止。
- `outbox_pending_age_seconds > 300`：检查 worker、设备在线、ACK Inbox 与网络。
- 签名/哈希失败突增：停止目标发布，检查密钥、产物与 CDN。
- 频繁重启/租约冲突：隔离设备，检查 Agent 进程与 SQLite WAL。
- E-Stop/看门狗/`ESTOP_ACTIVE_LOCKED`：按现场安全流程处置，不通过平台强制复位。

## 11. 未完成项与外部阻塞

1. R1/X2/A3/T800 的官方 SDK 版本、固件范围、关节/速度/力矩/温度限值未取得可审批原始资料。
2. 四机型 SIL 未运行；五机型真机 HIL 全部未验证。
3. 四机型真实动作桥、停止观测、回滚与资源权限未做机载验证。
4. 生产密钥托管仍无 KMS/HSM；多公钥轮换与撤销未完成。
5. 管理端目标审核 UI/API、四角色权限和越权验收未完成。
6. 控制面本地 p95 未达 300ms，需生产级运行容器/OPcache/FPM 或架构优化后复测。
7. 30 分钟软件 soak 已完成；生产规模 1,000 并发、真实网络抖动和 Outbox 积压恢复仍未完成。
8. 告警平台接入、指标导出器、部分指标埋点未完成。
9. 本机无 PHP 8.3，最终 PHP 验证在 8.4.24 完成。
10. 未执行 Git add/commit/push；未触碰 root 库。
---

## 12. 2026-11-04 独立审计修订

本轮独立复核在 P0 基础上补上了设备事实链的关键缺口，并以新文件为准：

- `/api/agent/evt` 现要求 `event_id`，所有任务/执行事实以 `(did,event_id)` 原子 Inbox 幂等入账；同键异载荷 409，迟到事实只追加 `late_report`，不回退服务端终态；
- `SUCCEEDED/FAILED/CANCELED` 执行终态必须携带独立停止观察；`CANCEL_REQUESTED` 不释放资源租约；无法确认停止进入 `UNKNOWN`；
- 新增 `device_reconcile_report`、执行证据/实际启停列、Inbox 应用结果列（迁移 `2026_11_04_000002`）；
- 新增 `InboxEvent`、`OutboxLease`、`TargetReleaseEvent` 强类型契约；
- 未知厂商物理限值的运动技能在编译期 fail-closed：`C-T27` 证明仅 G1 ground-truth 可构建，R1/X2/A3/T800 明确拒绝；
- 安装下载与 Staging 在成功/失败/异常路径均清理；
- 当前编译器专项为 **33 passed / 5,238 assertions**；平台 Deploy 为 **167 passed / 5,839 assertions**；Agent Python 为 **68 tests OK**；Agent API 为 **22 passed / 112 assertions**；HIL 证据硬门禁为 **9 tests OK**。

完整第一性原理推导、SOTA 证据、红队、Runbook 与最终等级见
[`skill-dev-step10-five-model-ga-research-delivery.md`](./skill-dev-step10-five-model-ga-research-delivery.md)。
---

## 13. 2026-11-05 完成度复核更正

后续独立 claim audit 对旧证据作了降级，必须以本节为准：

- `qa/hil-verification/hil_safety_report.json` 为 `INVALID_NON_HIL`：实现是 `MockHardwareSafetyInterlock`，12.5ms 为硬编码，没有独立传感器日志、硬件序列号、固件、操作者或可重放物理观察。
- `qa/physics-verification/dynamics_verification_report.json` 被降级：源码没有 Pinocchio/RNEA 调用，间隙与惯量为约化模型/硬编码值。
- `qa/dds-verification/dds_conformance_report.json` 被降级：仅为静态字节布局与本地计时，不是 live DDS 互操作、真实 QoS 或 DDS Security。
- 当前 Python 3.12 环境没有 `mujoco` 和 `pinocchio`，因此 G1 当前 SIL replay **NOT_RUN**；旧 telemetry 仅能做 artifact cross-check。
- 当前五机型 Profile 均为 L2、HARDWARE_UNVERIFIED、Production BLOCKED；G1 `software_driver=PASS`，但 `sdk_contract=PARTIAL`。
- 当前 Deploy 全量 **167 passed / 5,839 assertions**；Agent Python **68 tests OK**；Agent API **22 passed / 112 assertions**；编译器 **33 passed / 5,238 assertions**；HIL validator **9 tests OK**。
- Composer locked audit：**76 个 advisory，3 critical、24 high、38 medium、8 low、3 unknown**，供应链门禁 FAIL。
- 高信号 secret 正则扫描有 6 个复核项；gitleaks/trivy/trufflehog/pip-audit/safety 未安装，不能宣称密钥历史无泄漏。
- 当前 PHP 8.4.24；PHP 8.3/FPM 未验证。

证据入口：`qa/ga/final_evidence_ledger.json`、`qa/ga/claim_audit_result.json`、`qa/ga/blocked_items.json`、`qa/hil-verification/EVIDENCE_STATUS.json`。