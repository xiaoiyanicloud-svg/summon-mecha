# WBS 无外部硬件依赖工作包 · 第三批交付（2026-11-05）

> 范围：**A2.4 端侧观察时间替换（禁止 now() 伪造 stopped_at）** + **A1.9 独立进程重启探针**。
> 本批**未做** B3 Admin 审核 API/ACL（见 §5）。
> 环境：PHP 8.4.24 / Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）。

## 0. 结论

- A2.4 已闭环：端侧执行事实的 `stopped_at` **只来自独立观察器的 `observed_at`**；缺失/伪造来源/坏质量一律降级 `UNKNOWN`，不再用 `now()` 伪造。
- A1.9 已闭环：outbox 落盘后由**另一个没有设备私钥的进程**重投，`event_id`、签名字节、`key_id` 完全一致，且 ACK 后队列清零。
- 本批新增/回归测试 **63 项全过**（端侧 8 个模块）。
- **诚实缺口**：B3 Admin API/ACL、A3~A7/B4~B6、真机 HIL 仍未做。

## 1. 改动清单

### A2.4 · 端侧观察时间
- 新增 `agent/zhjj_agent/stop_observation.py`：
  - `validate_observation()`：白名单 `source_type`、必需字段、`actual_stopped=true`、质量非 `bad`、64 位十六进制证据哈希、`observed_at>0`；
  - `synthetic_observation()`：仅测试/模拟器使用，`collector_version='synthetic-test-only'`。
- `adapters/unitree_g1.py`、`adapters/vendor_plan_adapter.py`：
  - `_observe_independent_stop()` 改为返回 `(是否已停, 错误, 结构化观察)`；
  - 只给 `is_stopped=true` 而无合法 `observation` → `STOP_OBSERVATION_MISSING`（按未停止处理）；
  - `observe_execution()` 返回 `observation`（仅在 `is_stopped=true` 时）。
- `adapters/mock.py`：模拟停止时生成 synthetic observation（明确标注 test-only）。
- `task_manager.py::_execution_fact()`：
  - 需要 `actual_stopped=true` 时从适配器取结构化观察；
  - `stopped_at = observation["observed_at"]`；**不再 `stopped_at = now()`**；
  - 无合法观察 → `actual_stopped=False`、终态降级 `UNKNOWN`、`error_code=STOP_OBSERVATION_MISSING`；
  - fact 顶层新增 `stop_observation`（平台 A2 契约直接读取），evidence 内同时保留。
- 测试：
  - 新增 `agent/tests/test_stop_observation_time.py`（4）：观察时间生效、缺观察降级、伪造 `mock` 来源拒绝、坏质量拒绝；
  - 更新 `test_e2e_simulator.py` / `test_acceptance_at01_at26.py` / `test_five_model_adapters.py` 的测试观察器为 synthetic；
  - 更新 `test_fact_envelope_v3.py`：真实执行路径生成 fact，断言顶层 `stop_observation` 且 `stopped_at == observed_at`。

### A1.9 · 跨进程重启
- 新增 `qa/wbs-20261105/probes/outbox_restart_setup.py`：进程 1 产生事件、断网落 outbox；
- 新增 `qa/wbs-20261105/probes/outbox_restart_flush.py`：进程 2 **不带设备私钥**打开同一 outbox 重投；
- 证据：`a1-restart-evidence.json`（event_id/签名/key_id 一致，unacked=0）。

## 2. 证据

| CLAIM_ID | 内容 | 命令/退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| A2.4-UNIT-01 | 端侧 8 模块 63 项全过 | `unittest`（crypto/storage/five-model/task-manager/fact-envelope/stop-time/e2e/acceptance）/ 0 | [a2-agent-unittests.txt](qa/wbs-20261105/a2-agent-unittests.txt) | 见 manifest |
| A2.4-TIME-01 | `stopped_at` 来自观察、缺观察/伪造来源/坏质量降级 | `unittest tests.test_stop_observation_time` / 0 | [a2-agent-unittests.txt](qa/wbs-20261105/a2-agent-unittests.txt) | 见 manifest |
| A1.9-RESTART-01 | 跨进程重投同 event_id/同签名，ACK 后 unacked=0 | 两个独立 Python 进程 / 0 | [a1-restart-evidence.json](qa/wbs-20261105/a1-restart-evidence.json) | `DF4EC019…` |
| A1-PY-01 | 信封/outbox/crypto 8 项全过 | `unittest tests.test_fact_envelope_v3 tests.test_crypto` / 0 | [a1-python.txt](qa/wbs-20261105/a1-python.txt) | 见 manifest |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)。

## 3. 攻击面与安全语义

- **伪造 observed_at**：`stopped_at` 只取观察器的 `observed_at`；测试用固定 `1700000000` 证明不随 wall clock 变化。
- **布尔冒充物理停止**：观察器只返回 `is_stopped=true`、无结构化 `observation` → 按未停止处理，终态 UNKNOWN。
- **非物理来源**：`mock / sdk_return / bt_halt / cancel_requested / cloud_ack / script_assertion / sil` 一律 `validate_observation` 拒绝。
- **坏质量**：`quality=bad` 拒绝。
- **重启重投**：签名信封随 outbox 持久化；新进程不重签、不改 event_id。

## 4. 证据等级

- A2.4：**CODE + TEST**（端侧契约与严格降级已测）；真机观察器仍 **BLOCKED**。
- A1.9：**CODE + TEST（独立进程）**；跨机器/断电场景未测。

## 5. 明确未做 / NOT_PROVEN

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| B3 Admin 审核/撤销 API + 四角色 ACL | **NOT_RUN** | 服务层状态机已有；Admin 路由/页面/ACL/角色矩阵未接 |
| A3~A7 / B4~B6 | **NOT_RUN** | 不在本批 |
| 真机 Stop Observer | **BLOCKED** | 无真实传感器/采样/断线语义；当前适配器观察器均为 synthetic-test-only |
| KMS/HSM、生产 FPM/MySQL、HIL | **BLOCKED** | 外部前提 |
| 跨机器 / 断电重启 | **NOT_PROVEN** | 只验证了同机独立进程与 SQLite 持久化 |

## 6. 等级（本批结束时）

- 软件公共底座：**PARTIAL**。
- Agent：**PARTIAL**（A1 + A2.4 平台/端侧完成；真机观察器 BLOCKED）。
- Compiler：**PARTIAL**（B1/B2/B7/B8 完成；B3~B6 未做）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 7. 回滚

本批无迁移。回滚 = 删除 `agent/zhjj_agent/stop_observation.py`、恢复 `adapters/{unitree_g1,vendor_plan_adapter,mock}.py` 与 `task_manager.py` 的原观察/时间逻辑、移除新增测试与探针。

回滚后重跑 §2 的端侧套件。未执行任何 git 写操作，未触碰 root 库。
