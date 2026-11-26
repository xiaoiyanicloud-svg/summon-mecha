# WBS 无外部硬件依赖工作包 · 第八/九批交付（2026-11-05）

> 范围：**批次 8 = A3 指标与 WBS 对齐 + A4 九边界故障注入/事务边界/证据归档**；
> **批次 9 = B1.2 登记型强类型入口 + B4.4 并发读取 + B5.5 工具链指纹**。
> 环境：PHP 8.4.24（目标 8.3 未装，偏差沿用）/ Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）/ Windows 11。

## 0. 结论

- **A3 与本 WBS 对齐**：闭合键集从 9 个计数器扩到 **12 个计数器 + 1 个 gauge**：
  补 `command_received_total`、`command_duplicate_total`（设备侧）与 `event_conflict_total`（平台侧），
  以及设备上报的 `outbox_pending_age_seconds`（**覆盖语义**，不累加）。
  A3.1 落地为**有界 label 契约**：`allowed = {did, model_code, operation, status, error_code}`、
  `cardinality` 实时基数、`rejected_unbounded = {event_id, task_id, skill_id, path, stack}`，
  并按 model_code / operation / status / error_code 做**查询时聚合**（不落 label 时间序列）。
  WBS 的 `event_signature_failed_total` 与已有 `fact_auth_rejected_total` 是**同一埋点**，文档按映射记录，
  不重复建一个没有独立生产者的键。
- **A4 九边界齐**：`before_intent / after_download / after_unpack / after_verify / after_activate /
  after_execution_start / before_outbox / after_send_before_ack / after_restart` 全部可注入，
  用 `os._exit(86)` 模拟真实崩溃（不跑 finally），再由独立进程重启恢复。
  **A4.5**：staging 残骸先写审计归档（路径/大小/sha256）再删除，归档失败则**保留现场**。
  **修掉一个真实缺陷**：恢复流程用 `exec_{task_id}` 猜 execution_id，导致真正的执行永远停在
  `ACCEPTED` —— 现改为从签名 payload / 本地执行表取回真实 id，未确认停止一律收敛 `UNKNOWN` 且**保留 lease**。
- **B1.2**：登记型构建不再裸调 `compile()`，统一走 `RegistrationCommand`
  （GA 机型闭集 + manifest 摘要 + 构建器解析），既有 D9 口径（"登记 ≠ 产出"）保持。
  版本存在性**刻意不加**：D9 夹具与历史调用方以版本 id 为键登记，新增前置会让老入口 422 —— 已在代码注释写明。
- **B4.4**：4 个**独立 PHP 进程**并发读取同一源包，结果字节级一致；一个进程读到坏包不影响其它进程。
- **B5.5**：每次构建 Run 落 `toolchain_json`（PHP 版本/SAPI/OS/构建器清单），
  镜像摘要显式为 `null`、状态 `NOT_RUN` —— 不伪造 digest。
- 测试：**PHP 15 + 4 + 2 + 2 项新增**；回归 **41 个文件 / 318 项 / 6544 断言全绿**，
  **Python 全套 122 项 OK**；`php -l` 15 文件 OK；契约门禁（45 路由 / 85 错误码）与数据 preflight 通过。
- **本批有 2 个迁移**（gauge 表、`skill_build_runs.toolchain_json`），均已在隔离库执行。

## 1. 改动清单

### A3 · 平台侧

| 文件 | 改动 |
|---|---|
| `Services/Deploy/DeployMetricCounter.php` | `AGENT_KEYS` 6→8（+command_received/command_duplicate）、`PLATFORM_KEYS` 3→4（+event_conflict）、`METRIC_KEYS` 9→12；新增 `GAUGE_KEYS`（outbox_pending_age_seconds）、`applyAgentGauges()`（**覆盖**语义）、`gaugeMax()` |
| `Database/Migrations/2026_11_05_000004_create_deploy_agent_metric_gauges.php`（新增） | `deploy_agent_metric_gauges`：`(did, metric_key)` 唯一，每设备每 gauge 只留最新值 |
| `Services/Deploy/GaOperationalMetrics.php` | 快照新增 `label_contract`（allowed/cardinality/rejected_unbounded）、`label_totals`（四维聚合）、`gauge_key_total/gauge_keys`；`outbox_pending_age_seconds` 改为**设备侧 gauge**（null=从未上报），平台派生口径改名 `platform_outbox_pending_age_seconds`；告警取两者较大值；新增 `event_conflict_observed` |
| `Services/Deploy/DeployInboxService.php` | 同 event_id 异载荷（409）→ `event_conflict_total +1` |
| `Http/Controllers/API/AgentController.php` | 心跳支持 `metrics.gauges`（未知 gauge → 422）；`counters` 与 `batch_id` 的耦合校验改为代码内判定（只有 counters 非空才要求 batch_id，纯 gauge 心跳合法） |

### A3 · 设备端

| 文件 | 改动 |
|---|---|
| `zhjj_agent/metrics.py` | `AGENT_METRIC_KEYS` 6→8、`PLATFORM_METRIC_KEYS` 3→4；新增 `AGENT_GAUGE_KEYS`、`outbox_pending_age_seconds()`（读同一 SQLite 的 outbox 表）、`gauges()` |
| `zhjj_agent/task_manager.py` | `process_command` 入口 `command_received_total +1`；重复任务分支 `command_duplicate_total +1` |
| `zhjj_agent/main.py` | 心跳每轮携带 `metrics.gauges`（覆盖语义），增量计数仍按批 |

### A4

| 文件 | 改动 |
|---|---|
| `zhjj_agent/failpoints.py`（新增） | 9 个具名边界；`os._exit(86)` 真实崩溃；`ZHJJ_FAILPOINT_MODE=exception` 供进程内观察 |
| `zhjj_agent/task_manager.py` | 9 处 `failpoint()` 埋点；staging 清理改为**先归档后删除**（`state/audit/staging_orphans.jsonl`，单条最多 500 文件并标 `truncated`，归档失败保留现场）；恢复流程修正 execution_id 来源 |
| `zhjj_agent/storage.py` | 新增 `get_executions_for_task()` —— 恢复必须能从本地记录找回签名里的 execution_id |

### B1.2 / B4.4 / B5.5

| 文件 | 改动 |
|---|---|
| `Services/SkillCompile/RegistrationCommand.php`（新增） | 登记型命令：版本 id + 目标机型 + manifest 摘要 + seller；`scopedKey()` 只由这三者构成 |
| `Services/SkillCompile/SkillBuildService.php` | 新增 `registerWithCommand()`：GA 机型闭集、manifest 摘要一致性、构建器解析；`persist()` 写入 `toolchain_json` |
| `Http/Controllers/API/SkillSupplyController.php` | 登记型分支改走 `RegistrationCommand` + `registerWithCommand()` |
| `Services/SkillCompile/ToolchainFingerprint.php`（新增） | PHP 版本/SAPI/OS/构建器清单；镜像摘要 `null` + `NOT_RUN` |
| `Database/Migrations/2026_11_05_000005_add_toolchain_to_skill_build_runs.php`（新增） | `skill_build_runs.toolchain_json`（nullable text） |
| `Models/SkillBuildRun.php` | fillable + `toolchain_json => array` cast |

### 测试

- PHP：`DeployMetricCounterTest` 12→**15**（A3-13 gauge 覆盖 / A3-14 未知 gauge 422 / A3-15 409 冲突计数）；
  新增 `RegistrationCommandTest`（4）、`SourcePackageConcurrencyTest`（2）、`ToolchainFingerprintTest`（2）
- Python：`test_metrics` 14→**17**（命令计数、gauge）；新增 `test_failpoints`（9 边界 × 崩溃+恢复，2 个用例含 9 个子测试）

## 2. A3 指标契约（12 + 1）

| 键 | 侧 | 生产者 | 语义 |
|---|---|---|---|
| `command_received_total` | 设备 | `TaskManager.process_command` 入口 | 收到指令即 +1（含被拒） |
| `command_duplicate_total` | 设备 | 重复任务分支 | 同 task_id 重复投递被幂等挡住 |
| `agent_restart_total` | 设备 | `mark_agent_start` | 进程启动次数（首启=1） |
| `staging_orphan_total` | 设备 | 启动清理 | 本次清理的残骸数 |
| `command_signature_failed_total` | 设备 | `emit_event(ERR_SIG_INVALID)` | 下行指令验签失败 |
| `package_hash_mismatch_total` | 设备 | 安装下载校验 | 包 SHA-256 不符 |
| `task_lease_conflict_total` | 设备 | 执行租约冲突 | 并行动作被挡住 |
| `outbox_flush_failed_total` | 设备 | 补发失败 | 事件送不出去 |
| `idempotency_hit_total` | 平台 | Inbox 幂等命中 | 重复事件 |
| `reconcile_drift_total` | 平台 | 新对账报告 | 偏差**条数** |
| `fact_auth_rejected_total` | 平台 | 事实验签拒绝 | 伪造/配错密钥（≡ WBS `event_signature_failed_total`） |
| `event_conflict_total` | 平台 | Inbox 409 冲突 | 同 event_id 异载荷 |
| `outbox_pending_age_seconds`（gauge） | 设备 | 心跳每轮 | 最老未确认事件年龄（覆盖） |

**基数有界靠代码**：写第 13 个计数器 / 第 2 个 gauge 直接抛异常；心跳带未注册键 422。

## 3. A4 九边界（真实进程）

| 边界 | 断言（崩溃现场 → 重启恢复） |
|---|---|
| `before_intent` | 无任务记录 → 恢复后仍无 |
| `after_download` | 任务 DOWNLOADING、无版本目录 → 不谎报安装 |
| `after_unpack` | staging 残骸在 → 启动**归档 + 清理**，审计文件含 manifest.json 与 sha256 |
| `after_verify` | 同上 |
| `after_activate` | 激活指针在、安装事实无 → 恢复不得当成"安装成功" |
| `after_execution_start` | 执行 ACCEPTED/RUNNING、lease ACTIVE → 恢复收敛 UNKNOWN/RUNNING，**lease 不释放** |
| `before_outbox` | 无 outbox 记录（该边界事实确实丢失，是已知代价）→ 不伪造事件 |
| `after_send_before_ack` | 一条未确认事件 → 重启 flush 后补发并 ack |
| `after_restart` | 自愈完成后再崩 → 下一轮仍收敛、清理数为 0 |

## 4. 证据

| CLAIM_ID | 内容 | 命令 / 退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| A3-T-02 | A3 15 项全过（12 键 + gauge + label 契约 + 冲突计数） | `pest DeployMetricCounterTest` / 0 | [a8a9-php-regression.txt](qa/wbs-20261105/a8a9-php-regression.txt)（`Tests: 15 passed`） | `213BB477…` |
| A3-SNAP-02 | 真实快照：`metric_key_total=12`、`gauge_keys`、`label_contract`、`label_totals`、设备 outbox age 为 null（从未上报） | `php probes/a3_snapshot_probe.php` / 0 | [a3-metrics-snapshot-v2.json](qa/wbs-20261105/a3-metrics-snapshot-v2.json) | `2434170A…` |
| A3-PY-02 | 设备侧指标 17 项全过（含命令计数与 gauge） | `python -m unittest tests.test_metrics` / 0 | [a3-metrics-tests-v2.txt](qa/wbs-20261105/a3-metrics-tests-v2.txt) | `F4BD7E06…` |
| A4-T-02 | 9 个 failpoint 全部真实崩溃并恢复（含 9 个子测试） | `python -m unittest tests.test_failpoints` / 0 | [a4-failpoints-tests.txt](qa/wbs-20261105/a4-failpoints-tests.txt)（`Ran 2 tests ... OK`） | `873CC756…` |
| A4-T-03 | SQLite `journal_mode=wal`（崩溃恢复事务边界） | 同上 | 同上 | 同上 |
| B9-T-01 | B1.2 4 项 + B4.4 2 项 + B5.5 2 项全过 | `pest <3 files>` / 0 | [b-batch9-tests.txt](qa/wbs-20261105/b-batch9-tests.txt) | `3797BB6D…` |
| REG-02 | **41 文件 / 318 项 / 6544 断言全绿** | 循环 `pest <file>` / 全 0 | [a8a9-php-regression.txt](qa/wbs-20261105/a8a9-php-regression.txt) | `213BB477…` |
| PY-02 | Python 全套 **122 项 OK** | `python -m unittest discover -s tests` / 0 | [a8a9-python-regression.txt](qa/wbs-20261105/a8a9-python-regression.txt) | `AC1FC580…` |
| GATE-02 | `php -l` 15 文件 OK + 契约 `2026-10-20-q1` + preflight 通过 + 两个迁移 `[7]/[8] Ran` | `/0` | [php-lint-a8a9.txt](qa/wbs-20261105/php-lint-a8a9.txt) / [contract-check-a8a9.txt](qa/wbs-20261105/contract-check-a8a9.txt) / [migrate-status-a8a9.txt](qa/wbs-20261105/migrate-status-a8a9.txt) | `0C747376…` / `1EBF70E2…` / `5916B8FD…` |
| RG-02 | 4 处变异 red→green | `/1→0` | [red-green-a8a9.txt](qa/wbs-20261105/red-green-a8a9.txt) | `3DAEA71E…` |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)：**86 条**，
文件自身 SHA256 `B281AD21…`（由 `regen-manifest.py` 生成，可复现）。

## 5. red → green

| 变异 | 观察 |
|---|---|
| gauge 改成累加 | **A3-13 红**（120 再报 45 存成 165） |
| 清理前不归档 | **A4 failpoint 红**（缺 `staging_orphans.jsonl`） |
| 跳过 manifest 摘要校验 | **B1-2 红** |
| 不写 toolchain_json | **B5-5 红** |

诚实标注：A3-14/A3-15、B4.4、A4 的九个边界值是首跑即绿，无单次 red。

## 6. 明确未做 / NOT_PROVEN / BLOCKED

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| 真实磁盘写满、真实 ACL 权限拒绝 | **INJECTED_ONLY** | 仍为注入式验证（前批结论沿用） |
| HTTP 层多设备指标并发压测 | **NOT_PROVEN** | 未对心跳路由做 N 设备压力测试 |
| B3 HTTP 层并发竞态 | **NOT_PROVEN** | 仅服务层双进程探针 |
| 真实厂商适配器上的安装/回滚 | **NOT_PROVEN** | 需真机 |
| 沙箱接入执行路径 | **NOT_DONE** | 需拍板适配器契约 |
| 并发轮换/吊销压测 | **NOT_PROVEN** | 行锁已实现，未压测 |
| 独立构建镜像/容器摘要 | **NOT_RUN** | 无镜像；`toolchain_json` 明确写 null + NOT_RUN |
| KMS/HSM、真机 HIL、生产 FPM·MySQL | **BLOCKED** | 外部前提 |
| 全量 Pest 一次跑完 | **NOT_RUN** | 进程级 `set_time_limit` 约束 |

> **口径更正（自我校验）**：上一轮报告说"8 个部分项"。本批后它们在**代码/测试层面**都已补齐
> （A3.1/A3.2/A4.1/A4.2/A4.5/B1.2/B4.4/B5.5），但"代码补齐"不等于"外部环境已验证"——
> 上表的 NOT_PROVEN/BLOCKED 仍然成立，不得据此宣称生产就绪。

## 7. 等级（本批结束时）

- 软件公共底座：**PARTIAL** —— WBS 84 个子项在代码/测试层面均有对应实现与证据；
  仍差真实环境验证（生产 MySQL/FPM、OS 级沙箱、KMS/HSM）。
- Agent：**PARTIAL**（A1–A6 代码与测试齐；真机观察器/OS 级隔离 BLOCKED/NOT_RUN）。
- Compiler：**PARTIAL**（B1–B8 代码与测试齐；独立工具链镜像 NOT_RUN）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 8. 环境级风险（沿用）

1. `.env` 的 `DB_DATABASE` 仍指向 `root`；本批所有命令显式注入 `DB_DATABASE=summon_mecha_step10_test`。
2. 隔离库播种稀薄；3 个 `SkillAudit` 环境性失败结论不变（`PRE_EXISTING_SUSPECTED（未证明）`）。
3. **本批新增两个迁移**，只在本机隔离库执行过；生产未执行。

## 9. 回滚

1. 迁移（**仅隔离库**）：
   `php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_11_05_000005_add_toolchain_to_skill_build_runs.php`
   `php artisan migrate:rollback --path=packages/Webkul/Shop/src/Database/Migrations/2026_11_05_000004_create_deploy_agent_metric_gauges.php`
2. 代码：还原 `DeployMetricCounter` / `GaOperationalMetrics` / `DeployInboxService` / `AgentController` /
   `SkillBuildService` / `SkillSupplyController` / `SkillBuildRun`；删除 `RegistrationCommand`、
   `ToolchainFingerprint`、`failpoints.py` 与三个新测试文件；还原 `metrics.py` / `task_manager.py` / `main.py` / `storage.py`。
3. 数据：`deploy_agent_metric_gauges` 直接 drop；`skill_build_runs.toolchain_json` 随列回滚删除。

回滚后重跑 §4 的命令。全程未执行任何 git 写操作，未触碰 `root` 库。
