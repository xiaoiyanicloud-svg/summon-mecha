# WBS 无外部硬件依赖工作包 · 第六批交付（2026-11-05）

> 范围：**A3 运行指标（Agent 侧 + 平台侧）**、**A4 崩溃重启自愈**。
> 环境：PHP 8.4.24（目标 8.3，本机未装，偏差沿用）/ Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）。

## 0. 结论

- **A3 完成**：9 个指标的**闭合键集**（设备侧 6 / 平台侧 3）落到 `deploy_metric_counters`，
  写入走 `INSERT … ON DUPLICATE KEY UPDATE`（原子累加，不是读改写）；
  设备增量上报按 `(did, batch_id)` **恰好一次**（`deploy_agent_metric_batches` 唯一键 + `insertOrIgnore`）；
  每个平台侧指标都只在**它真正发生的那一处**埋点；快照里 9 个键全是整数，键数恒为 9。
- **A3 附带修掉一个跨语言真实缺陷**：`agent-fact/v3` 的 `evidence` 在契约里是 **JSON 对象**，
  空值必须规范成 `{}`；PHP 侧原来把空 array 规范成 `[]`，与 Python 设备签名出的字节不同，
  真实 Agent 直连时验签必然失败（PHP 自签自验的用例反而全绿，掩盖了它）。
  现已 `(object) []` 规范化，并有 live HTTP 测试兜底。
- **A4 完成**：**真实独立进程**被 `kill()`（等价 `kill -9`）后重启，验证 5 条事实：
  启动计数 +1、`skills/*/.staging_*` 残骸被清理并计数、**执行记录不丢**且状态不被篡改、
  **未确认 outbox 事件仍未被标记已确认**（要补发）、重启进程退出码为 0。
- **诚实修正一处告警语义**：`agent_restart_total` 统计的是「进程启动次数」（首启 = 1）。
  最初把「发生过重启」写成 `> 0`，等于任何设备第一次上线就报"有过重启"。
  已改为 `累计启动次数 > 上报过指标的设备数`，并把分母 `agent_reporting_device_total` 放进快照自证（A3-12 覆盖）。
- 新增测试：**PHP 12 项 + Python 15 项**；回归 **37 个 PHP 文件 / 297 项 / 6424 断言全绿**，
  **Python 全套 91 项 OK**；`php -l` 10 文件 OK；`ecosystem:contract-check` / `data-preflight` 通过。
- **诚实缺口**：`package_signature_failed_total` **没有生产者**（Agent 侧不校验包签名），快照留 `null` 并写明原因；
  HTTP 层"多设备并发上报指标"压测未做；批次台账 30 天清理只做了代码实现，**未做时间旅行验证**；真机 / 生产 / KMS 仍 BLOCKED。

## 1. 改动清单

### A3 · 平台侧

| 文件 | 改动 |
|---|---|
| `Services/Deploy/DeployMetricCounter.php`（新增） | 闭合键集常量（`AGENT_KEYS` 6 / `PLATFORM_KEYS` 3 / `METRIC_KEYS` 9）；`increment()` 拒绝未知键与非正增量；`bump()` 用 MySQL `ON DUPLICATE KEY UPDATE`（其它驱动退化到行锁读改写）；`applyAgentBatch()` 按 `(did,batch_id)` 去重后累加，并清理 30 天前台账 |
| `Database/Migrations/2026_11_05_000003_create_deploy_metric_counters.php`（新增） | `deploy_metric_counters`（`uk_deploy_metric_key`）/ `deploy_agent_metric_batches`（`uk_deploy_agent_metric_batch`，索引 `idx_deploy_agent_metric_applied`） |
| `Services/Deploy/DeployInboxService.php` | 重复事件命中幂等分支 → `idempotency_hit_total +1`（在**确实命中的那一处**，不在通用 catch 里） |
| `Services/Deploy/DeployReconcileService.php` | 新对账报告首次落库且 `drift_count > 0` → `reconcile_drift_total += drift_count`（重投不重复累加） |
| `Http/Controllers/API/AgentController.php` | ① 事实验签被拒处 → `fact_auth_rejected_total +1`（拒绝真实发生时）；② 心跳新增 `metrics` / `metrics.batch_id` / `metrics.counters` 校验；③ 未知键 → `422 VALIDATION_ERROR`（让 Agent 丢弃该批而不是无限重试） |
| `Services/Deploy/GaOperationalMetrics.php` | 快照并入 9 个计数器；新增 `metric_key_total` / `metric_keys` / `metric_status` / `agent_reporting_device_total`；`package_signature_failed_total` 显式 `null` + `not_instrumented` 原因；告警 `agent_restart_observed` 判据修正 |
| `Services/Deploy/Contracts/AgentFactEnvelopeV3.php` | 空 `evidence` 规范为 `(object) []`（修跨语言 JCS 字节不一致） |

### A3 · 设备侧（`agent/`）

| 文件 | 改动 |
|---|---|
| `zhjj_agent/metrics.py`（新增） | `AgentMetrics`：6 个设备键闭合；SQLite 表 `metric_counters(metric_key, cumulative, reported)` 与 `metric_batches(batch_id, payload_json, acked)`；`next_batch()` 复用未 ack 批次，`ack_batch()` / `drop_batch()` |
| `zhjj_agent/task_manager.py` | 注入 metrics（默认按 `storage.db_path` 建）；安装 `PackageManagerError(ERR_PKG_HASH_MISMATCH)` → `package_hash_mismatch_total`；`emit_event()` 在 `ERR_SIG_INVALID` 处 → `command_signature_failed_total`；outbox 发送失败 → `outbox_flush_failed_total` |
| `zhjj_agent/executor.py` | 执行租约冲突 → `task_lease_conflict_total` |
| `zhjj_agent/client.py` | `heartbeat(..., metrics=…)` 携带 `metrics{batch_id,counters}`；`send_event` 在持有私钥且未显式给信封时自动构造 v3 信封（补 `reported_at`） |
| `zhjj_agent/main.py` | `report_metrics()`：成功 ack / `VALIDATION_ERROR` 丢弃 / 其它保留重试；启动即上报一次，之后每轮循环上报 |

### A4 · 崩溃重启

| 文件 | 改动 |
|---|---|
| `zhjj_agent/task_manager.py` | `mark_agent_start()`：启动计数 +1、清理 `skills/*/.staging_*` 残骸并计数；`main.run()` 在 `resume_recovery()` **之前**调用 |
| `tests/test_crash_restart.py`（新增） | 真实子进程 crash → `kill()` → 重启子进程走真实启动路径；断言见 §4 |

### 测试

- `tests/Feature/Deploy/DeployMetricCounterTest.php`（新增，12 项 A3-01~A3-12）
- `agent/tests/test_metrics.py`（新增，14 项）
- `agent/tests/test_crash_restart.py`（新增，1 项）
- `tests/Feature/Deploy/SkillFullChainEndToEndTest.php`（**修夹具**：事件签名改走 `AgentFactEnvelopeV3` 自己的规范字节，不再手搓 `'evidence' => []`）
- `agent/tests/test_live_http_integration.py`（夹具补 `private_key_pem`；就绪探测从 1s/20s 放宽到 3s/40s —— 套件末尾 CPU 争用会让 PHP 冷启动超 1s，曾造成随机失败）

## 2. 指标契约（9 键，闭合）

| 键 | 侧 | 生产者（唯一埋点位置） | 语义 |
|---|---|---|---|
| `agent_restart_total` | 设备 | `TaskManager.mark_agent_start()` | 累计进程启动次数（首启 = 1；含"重启"） |
| `staging_orphan_total` | 设备 | `mark_agent_start()` 扫到的 `.staging_*` | 本次启动清理的残骸条数 |
| `command_signature_failed_total` | 设备 | `emit_event()` 且 `error_code == ERR_SIG_INVALID` | 下行指令验签失败 |
| `package_hash_mismatch_total` | 设备 | 安装分支 `PackageManagerError(ERR_PKG_HASH_MISMATCH)` | 包 SHA-256 与下发值不符 |
| `task_lease_conflict_total` | 设备 | `SkillExecutor.execute()` 租约冲突 | 同设备并行动作被执行租约挡住 |
| `outbox_flush_failed_total` | 设备 | `flush_outbox()` 发送失败 | 事件补发失败 |
| `idempotency_hit_total` | 平台 | `DeployInboxService::duplicateResult()` | 重复事件命中 Inbox 幂等 |
| `reconcile_drift_total` | 平台 | `DeployReconcileService` 新报告落库 | 对账偏差**条数**（不是报告数） |
| `fact_auth_rejected_total` | 平台 | `AgentController::event` 验签拒绝处 | 事件正文伪造/密钥配错 |

**基数有界靠代码约束，不靠自律**：写第 10 个键会抛 `InvalidArgumentException`（A3-02），
设备端上报平台侧键同样被拒（A3-06），心跳里带未注册键直接 422（A3-08）。

## 3. 幂等与一致性语义

1. **平台累加是原子的**：`INSERT … ON DUPLICATE KEY UPDATE metric_value = metric_value + VALUES(metric_value)`。
   不用"查出来 +1 再写回"——并发下会静默丢计数。
2. **设备增量恰好一次**：`(did, batch_id)` 唯一键；`insertOrIgnore` 返回 0 即重复，直接 `false`（调用方仍回 200，让 Agent 正常 ack）。
3. **设备端最少一次**：未 ack 的批次**复用同一 `batch_id`** 重发；只有平台明确回 `VALIDATION_ERROR` 才 `drop_batch()`（否则该批会永远重试）。
4. **对账偏差不重复计**：只有"新报告落库"（`wasRecentlyCreated`）才累加；重投不变。
5. **测试必须按增量断言**：计数器是**全局累计值**，`agent/tests/test_live_http_integration.py` 会拉起真实 PHP 服务并**真实提交**，
   历史运行会给某些键留下基线。A3-04 / A3-09 / A3-11 首轮就是因为按绝对值断言而红（见 §6），现全部改为 `$before + N`。

## 4. A4：崩溃重启（真实进程，不是同进程 mock）

`agent/tests/test_crash_restart.py` 的流程：

1. **子进程 A**（真实 `python -c`）写入现场：一个 `.staging_*` 残骸目录、一条 `RUNNING` 执行记录、
   一条未确认 outbox 事件；写就绪标记后挂起。
2. **父进程** `child.kill()` —— 等价 `kill -9`，**没有任何 finally 会执行**；并断言残骸确实还在（这才是崩溃的样子）。
3. **子进程 B** 重新启动，调用真实的 `TaskManager.mark_agent_start()`，打印本地状态。

断言（全部通过）：

| 事实 | 断言 |
|---|---|
| 启动被计数 | `agent_restart_total == 2`（A 一次 + B 一次） |
| 残骸被清理并计数 | `recovery.staging_orphans_cleaned == 1`、`staging_orphan_total == 1`、目录已消失 |
| 执行记录没丢、状态没被篡改 | `execution_id == exec_crash`、`status == RUNNING`（仍待人工/恢复流程处理，不是"假装成功"） |
| 未确认事件仍待补发 | `unacked_event_ids == ["evt_crash_1"]`（崩溃不能让它变成"已送达"） |

**为什么必须用独立进程**：同进程里 `try/finally` 或 mock 一个"崩溃"，证明不了任何事——
真实崩溃是进程被内核直接抹掉。这一条也是本批对 WBS "不得用同进程 mock 冒充双进程"红线的直接回应。

## 5. 证据

| CLAIM_ID | 内容 | 命令 / 退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| A3-T-01 | A3 12 项全过（A3-01~A3-12） | `pest DeployMetricCounterTest` / 0 | [a3a4-php-regression.txt](qa/wbs-20261105/a3a4-php-regression.txt)（`DeployMetricCounterTest.php  Tests: 12 passed (66 assertions)`） | `F331679B…` |
| A3-SNAP-01 | 真实快照：9 键全整数、`metric_key_total=9`、`package_signature_failed_total=null` 且带 `not_instrumented` 原因、`agent_reporting_device_total` 可自证 | `php probes/a3_snapshot_probe.php` / 0 | [a3-metrics-snapshot.json](qa/wbs-20261105/a3-metrics-snapshot.json) | `7C20DB5D…` |
| A3A4-REG-01 | Deploy 30 文件 + API 7 文件 = **37 文件 / 297 项 / 6424 断言全过**（按文件分进程） | 循环 `pest <file>` / 全 0 | [a3a4-php-regression.txt](qa/wbs-20261105/a3a4-php-regression.txt) | `F331679B…` |
| A3A4-PY-01 | Python 全套 **91 项 OK**（含 live HTTP 4 项） | `python -m unittest discover -s tests` / 0 | [a3a4-python-regression.txt](qa/wbs-20261105/a3a4-python-regression.txt) | `39CD4504…` |
| A4-T-01 | 真实 `kill()` → 重启自愈；A4 1 项 + A3 设备侧 14 项全过 | `python -m unittest tests.test_crash_restart tests.test_metrics` / 0 | [a4-crash-restart.txt](qa/wbs-20261105/a4-crash-restart.txt) | `742FF1A0…` |
| A3A4-GATE-01 | `php -l` 10 文件 OK + `ecosystem:contract-check` 通过（契约 `2026-10-20-q1`）+ `data-preflight` 通过 + 迁移 `[6] Ran` | `/0` | [php-lint-a3a4.txt](qa/wbs-20261105/php-lint-a3a4.txt) / [contract-check-a3a4.txt](qa/wbs-20261105/contract-check-a3a4.txt) | `780AC8AC…` / `B5DFE11E…` |
| A3-RG-01 | 关掉批次去重 → **A3-05 / A3-07 两红**；还原 → 12 绿 | `pest --filter="A3-0[57]"` / 1→0 | [red-green-a3a4.txt](qa/wbs-20261105/red-green-a3a4.txt) | `4407FC75…` |
| A4-RG-01 | 关掉启动自愈的 staging 清理 → **A4-01 红**（`staging_orphans_cleaned: 0 != 1`）；还原 → 15 绿 | `python -m unittest tests.test_crash_restart` / 1→0 | 同上 | 同上 |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)。

> 该 manifest 是**累积清单**：本批后共 **64 条**、文件本身 SHA256 `D4D4531A…`。
> 第五批文档里写的"57 条 / `06DB0FE2…`"是**当时**的快照，不是笔误；以本清单为准。

## 6. red → green（本批真实发生过的那一部分）

| 变异 / 缺陷 | 观察 | 说明 |
|---|---|---|
| 关闭 `(did,batch_id)` 去重（`if (false && $inserted === 0)`） | A3-05、A3-07 **两红** | 证明"重复上报不刷高计数"是被断言住的；关掉后计数确实从 1 变 2 |
| 关闭启动时 `.staging_*` 清理 | A4 **红**（`staging_orphans_cleaned: 0 != 1`） | 证明崩溃测试真的在验证自愈，而不是碰巧目录不存在 |
| A3-04 / A3-09 / A3-11 首轮按绝对值断言 | **两红**（期望 5 实得 7；期望 2 实得 10） | 根因：计数器全局累计 + live 测试真实提交；已改为按增量断言，并在用例里写明原因 |
| `SkillFullChainEndToEndTest` 首轮 401 | **红** | 夹具手搓 `'evidence' => []` 签名，与契约 `{}` 不一致；改为走 `AgentFactEnvelopeV3` 自身规范字节后绿 |
| 全套件跑 `test_live_http_integration` 首轮 `setUpClass` 失败 | **红**（偶发） | 就绪探测 1s 超时 + 20s 期限，套件末尾 CPU 争用下 PHP 冷启动超时；放宽到 3s/40s 后全绿 |

**诚实标注**：A3-01/02/03/06/08/10/12 与设备侧 14 项、A4 断言细节是**先实现后补测**，首跑即绿，**没有**单次 red 记录。

## 7. 明确未做 / NOT_PROVEN

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| `package_signature_failed_total` 的真实生产者 | **NOT_INSTRUMENTED（有意的）** | Agent 侧只校验包 SHA-256 与指令信封签名，**不存在包签名校验**。快照里留 `null` + `not_instrumented` 原因；若将来加包签名，必须同时在 `DeployMetricCounter::AGENT_KEYS` 与 `metrics.py` 开键 |
| 多设备并发上报指标的 HTTP 压测 | **NOT_PROVEN** | 当前只验证了"重复批次不刷高"与"未知键 422"；未做 N 设备并发打心跳的端到端压力测试 |
| 批次台账 30 天清理 | **PROVEN-BY-CODE（未实测）** | 清理 SQL 已实现；无法在不改系统时间的前提下验证真实过期删除 |
| `reconcile_drift_total` 的对外 API 暴露 | **NOT_DONE** | 只在快照/告警里可观测；未新增路由 |
| 设备端指标上报的"平台侧真实回 422 → drop"整链 | **PARTIAL** | 单元级有（`test_metrics.py::test_drop_batch_discards_without_acking` + live 测试心跳）；未用真实 Agent 进程打真实服务触发 422 |
| 真机 HIL / 生产 MySQL·FPM / KMS·HSM | **BLOCKED** | 外部前提 |
| A5 / A6 / A7 | **NOT_RUN** | 不在本批（A7 因单密钥模型 + KMS 未定，预计 BLOCKED） |
| 全量 Pest 一次跑完 | **NOT_RUN** | 项目既有约束（进程级 `set_time_limit` 60s），一律按文件分进程 |

## 8. 等级（本批结束时）

- 软件公共底座：**PARTIAL**（A1/A2.4/A3/A4 + B1~B8 有实现与证据；A5/A6 未做，A7 BLOCKED）。
- Agent：**PARTIAL**（A1 事件签名 / A2.4 停止观察 / A3 指标 / A4 崩溃重启完成；真机观察器仍 BLOCKED）。
- Compiler：**PARTIAL**（B1~B8 齐；剩 A 系）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 9. 环境级风险（沿用第五批，未变化）

1. **`.env` 的 `DB_DATABASE` 仍是 `root`**：本批**所有** pest / artisan / 探针都显式注入
   `DB_DATABASE=summon_mecha_step10_test`。新增一条硬约束：**A3 的计数器断言必须按增量写**，
   因为 live HTTP 测试会真实提交到该库。
2. 隔离库仍是"裸迁移"状态（`categories=1 / locales=1 / currencies=1`），
   3 个 `SkillAudit` 用例的环境性失败结论不变（`PRE_EXISTING_SUSPECTED（未证明）`）。

## 10. 回滚

本批**有迁移**（两个新表）。回滚顺序：

1. 代码：删除 `DeployMetricCounter.php`、`metrics.py`；
   还原 `DeployInboxService` / `DeployReconcileService` / `GaOperationalMetrics` / `AgentController` 的指标埋点与校验；
   还原 `AgentFactEnvelopeV3` 的 `evidence` 规范化（**不建议**单独回滚这一条：它是跨语言签名缺陷的修复）；
   还原 `task_manager.py` 的 `mark_agent_start` / 计数器埋点，删除 `main.py` 的 `report_metrics` 调用。
2. 迁移：`drop table deploy_agent_metric_batches; drop table deploy_metric_counters;`
   （**仅限隔离库**；生产未执行过本迁移）。
3. 测试：删除 `DeployMetricCounterTest.php`、`test_metrics.py`、`test_crash_restart.py`；
   `SkillFullChainEndToEndTest` / `test_live_http_integration` 的夹具修改可保留（它们只修了夹具）。

回滚后重跑 §5 的命令。未执行任何 git 写操作，未触碰 `root` 库。
