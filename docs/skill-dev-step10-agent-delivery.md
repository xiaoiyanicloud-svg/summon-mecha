# 召唤机甲 · 设备端 Agent 与平台必要对接交付报告（第一阶段）

> 实施日期：2026-11-03  
> 责任工程师：设备端与后端开发  
> 遵循基线：`AGENTS.md`、`CLAUDE.md`、`docs/adr/ADR-0001-device-agent-and-standard-transcoder.md`、`docs/agent-runtime-standard-transcoder-blueprint.md`  
> 门禁状态：`ecosystem:contract-check` 通过；Deploy 全量 123 项测试通过；Python 26 项测试通过。

---

## 一、交付范围与阶段边界

本轮为**第一阶段：设备端 Agent 独立代码包及其平台对接闭环**。
严格遵守业务与工程红线：
- **已交付**：
  1. 设备端 Agent 独立代码包 (`agent/zhjj_agent/`) 与统一 CLI 入口 (`agent/agent.py`)。
  2. 独立设备凭据认证、mTLS/签名头支持、凭据吊销阻断与共享密钥回退关闭。
  3. v2 签名契约（RFC 8785 JCS 规范串、全字段不可抵赖绑定、时效性检查）。
  4. 限量安全下载、流式 SHA-256 复算校验、Zip Slip 穿越拦截、符号链接拒绝、解压炸弹防御与 Staging 事务隔离安装。
  5. 技能执行（`OP_EXECUTE=7`）与取消（`OP_CANCEL_EXECUTION=8`）生命周期，按 `execution_id` 独立去重，执行事实与安装事实彻底物理隔离（执行成功绝不记为 `install_record`）。
  6. 本地 SQLite WAL 持久化 Journal、检查点、Outbox 待发事件与可靠补报对账。
  7. 首装回滚安全停用机制（无旧版本时安全置为 disabled，绝不伪造恢复成功）。
  8. 首个机型适配器：Unitree G1 EDU (`UnitreeG1Adapter`) 与全功能模拟适配器 (`MockAdapter`)。
  9. 平台侧增量迁移、模型、路由、控制器及端云双向 ACK/对账通道。
- **未展开（严格保留至第二阶段）**：
  - 标准行为 XML 语义编译器。
  - GMR 重定向、BVH/SMPL-X 转换及动作策略训练。
  - 实时运控步态仲裁、远程摇杆及厂商云接入。
  - 为第二阶段预留版本化接口 `agent-task/v2`，未实现能力明确拒绝。

---

## 二、代码清单与核心改动

### 1. 设备端代码包 (`agent/`)

| 文件路径 | 职责说明 |
| :--- | :--- |
| `agent/agent.py` | 生产与调试 CLI 启动入口，支持 `--config`、`--once`、`--adapter`、`--version`。 |
| `agent/zhjj_agent/__init__.py` | 包版本定义（`__version__ = "0.2.0"`）。 |
| `agent/zhjj_agent/constants.py` | 协议版本 (`agent-task/v2`)、操作码 1~8、任务状态机、执行状态及错误码字典。 |
| `agent/zhjj_agent/crypto.py` | RFC 8785 JCS 规范化编码器、v1/v2 规范串构造、RSA-SHA256 跨语言验签、流式文件 SHA-256。 |
| `agent/zhjj_agent/storage.py` | 本地 SQLite WAL 持久化层：任务 Journal、检查点、安装事实 (`installed_skills` + `state/installed.json` 原子替换)、Outbox 待发队列、撤销代次、执行资源互斥租约。 |
| `agent/zhjj_agent/client.py` | HTTP 传输层：支持独立设备凭据、Bearer Secret、X1 响应信封解析、端云 ACK 与对账快捷方法。 |
| `agent/zhjj_agent/package_manager.py` | 安全下载、大小超限保护、流式哈希复算、Zip Slip 拒绝、符号链接阻断、解压炸弹防护、Staging 事务隔离解压。 |
| `agent/zhjj_agent/adapters/base.py` | 机型适配器基类，抽象出安装预检、安装、卸载、启停、回滚、健康检查、执行与取消观测接口。 |
| `agent/zhjj_agent/adapters/mock.py` | 全功能模拟适配器，用于离线环境测试与端到端回归。 |
| `agent/zhjj_agent/adapters/unitree_g1.py` | 宇树 G1 EDU 机型插件，对接 `unitree_sdk2py`，诚实标记硬件未验证 (`is_hardware_verified = False`)。 |
| `agent/zhjj_agent/executor.py` | 技能执行监督器：执行并发资源互斥租约管理、安全取消、独立物理观测停止判定。 |
| `agent/zhjj_agent/task_manager.py` | 任务全生命周期编排：断电重启恢复、Outbox 异步重试、时效过滤、代次撤销、Staging 安装、OP 7/8 执行与取消。 |
| `agent/tests/test_crypto.py` | JCS 排序、类型编码、跨语言签名与验签单测（4 项）。 |
| `agent/tests/test_storage.py` | Journal 幂等、状态导出、Outbox 租约单测（3 项）。 |
| `agent/tests/test_package_manager.py` | Zip Slip、符号链接、解压炸弹、Staging 隔离单测（5 项）。 |
| `agent/tests/test_executor.py` | 执行去重、资源租约互斥冲突阻断、安全取消与物理观测单测（4 项）。 |
| `agent/tests/test_task_manager.py` | v2 验签、全字段篡改拦截、时效阻断、Outbox 补偿、首装回滚安全停用单测（9 项）。 |
| `agent/tests/test_e2e_simulator.py` | 端到端全链路闭环模拟器：拉取 -> 验签 -> 安装 -> ACK -> 执行(OP 7) -> 观测停止 -> 对账（1 项）。 |

### 2. 平台端改动与数据库增量 (`my-bagisto-store/`)

| 文件路径 | 改动职责 |
| :--- | :--- |
| `Database/Migrations/2026_11_03_000001_add_v2_and_execution_to_deploy_tasks.php` | 纯 additive 增量迁移：为 `deploy_tasks` 添加 `command_seq`、`protocol_version`、`execution_id`、`task_expires_at`、`signed_payload`；新增 `skill_execution` 表。 |
| `Models/SkillExecution.php` | 技能执行独立事实模型，状态：`ACCEPTED`、`RUNNING`、`CANCEL_REQUESTED`、`SUCCEEDED`、`FAILED`、`CANCELED`、`UNKNOWN`。 |
| `Models/DeployTask.php` | 增加 `OP_EXECUTE = 7`、`OP_CANCEL_EXECUTION = 8`、`PROTOCOL_V2 = 'agent-task/v2'`；区分生命周期操作码 `OPERATIONS = [1..6]` 与执行操作码 `EXECUTION_OPERATIONS = [7..8]`。 |
| `Models/Device.php` | 增加 `binding()` 关联关系 (`belongsTo(DeviceBinding::class, 'binding_id')`)。 |
| `Services/Deploy/SigningService.php` | 增加 RFC 8785 递归 `jcsCanonicalize`、`canonicalV2`、`signV2`、`verifyV2`、`keyId` 方法，实现跨语言逐字节一致验签。 |
| `Services/Deploy/DeviceCredentialService.php` | 增加 `isRevoked(DeviceBinding $binding)` 方法。 |
| `Services/Deploy/DeployTaskService.php` | `findOpenExecutionTask` 独立去重，执行成功不落 `install_record`，`buildCommand` 构建 v2 签名与参数。 |
| `Http/Controllers/API/AgentController.php` | 彻底封堵已吊销独立凭据通过省略 `X-Agent-Did` 使用共享密钥绕过的漏洞；`commands` 轮询门禁（旧 Agent 自动过滤 OP 7/8）；新增 `ack` 与 `reconcile` 控制器端点。 |
| `Http/Controllers/API/SkillController.php` | 新增客户侧触发执行 `POST /api/skills/entitlements/{id}/executions`、查询执行状态 `GET /api/skills/executions/{id}`、取消执行 `POST /api/skills/executions/{id}/cancel`。 |
| `Routes/api.php` | 注册 Agent 侧 `agent/ack`、`agent/reconcile` 及客户侧 `executions` 路由。 |
| `tests/Feature/API/AgentV2ExecutionTest.php` | 新增 Feature 测试：旧 Agent 协议隔离、执行不污染安装事实、ACK、对账、客户侧执行与取消全链路（5 项）。 |

---

## 三、关键机制与工程红线落地证明

### 1. 独立设备身份与凭据吊销守护（关闭共享密钥回退）
- **漏洞根因与修复**：此前若请求未带 `X-Agent-Did`，中间件将 `agent.did` 设为 `null`，部分校验跳过。修复后，`AgentController::rejectIdentityMismatch` 显式判定设备是否处于吊销状态；凡已吊销凭据的设备，一律返回 401 `AGENT_CREDENTIAL_INVALID`，彻底杜绝冒用共享密钥回退。
- **验证**：`AgentDeviceCredentialB12Test.php` 9 项全过。

### 2. v2 签名全字段绑定（RFC 8785 JCS）
- 签名算法：`RSA-SHA256`。
- 规范化串：采用 RFC 8785 JSON Canonicalization Scheme (JCS)。PHP 端以 `uksort($arr, 'strcmp')` 配合无空格 `json_encode`，与 Python `json.dumps(..., separators=(',', ':'), sort_keys=True)` 经实测逐字节完全一致。
- 绑定对象：`protocol_version`、`task_id`、`command_seq`、`did`、`operation`、`skill_id`、`version`、`package_sha256`、`execution_id`、`task_expires_at`。
- 防篡改：篡改任意字段（操作码、设备 DID、文件哈希、有效期延长）均直接触发 `ERR_SIG_INVALID`，在产生任何物理副作用前拦截。

### 3. 解包安全防御与 Staging 事务隔离
- 限量下载：下载流式计数，超过 200MB 立即终止并抛出 `ERR_PKG_TOO_LARGE`。
- 哈希核验：下载过程中流式复算 SHA-256，与指令内签名保护的 `package_sha256` 比对，不符即拒装并上报 `ERR_PKG_HASH_MISMATCH`。
- Zip Slip 拦截：解压前检查每个 entry 的规范绝对路径，凡逃逸目标目录一律抛出 `ERR_ZIP_SLIP`。
- 符号链接阻断：拒绝含有 POSIX symlink 属性（`0o120000`）的条目，抛出 `ERR_ZIP_SYMLINK`。
- 解压炸弹防御：单个文件解压膨胀比 > 100 倍或文件总数 > 5000 触发 `ERR_ZIP_BOMB`。
- Staging 事务：解压至 `.staging_<uuid>`，全部核验无误后原子重命名至最终版本目录；中途出错彻底清理 staging，旧版本目录毫发无损。

### 4. 安装事实与执行事实严格隔离
- 严禁把技能执行事实写进 `install_record` 表。`DeployTaskService::onEvent` 对 `OP_EXECUTE` 与 `OP_CANCEL_EXECUTION` 只更新 `skill_execution` 表，绝不创建或修改 `InstallRecord`。
- 去重隔离：`OP_INSTALL` 按 `(did, version, operation)` 去重；而 `OP_EXECUTE` 严格按 `execution_id` 去重，绝不因为同技能重复执行而吞掉第二次合法动作。

### 5. 独立物理观测与安全停止判定
- SDK 返回 accepted 或 BT halt 不等于物理停止。适配器必须提供 `observe_execution` 判定物理速度为 0 或电机停止。
- 取消流程：下发适配器取消指令后，必须轮询观测确认 `is_stopped` 后才释放互斥租约；若超时仍未确认停止，状态标为 `UNKNOWN`，保持资源锁定，严防启动冲突动作。

### 6. 本地 SQLite 持久化 Journal、检查点与 Outbox 补偿
- 任务到达后先写本地 SQLite WAL `tasks` 表并置 `RECEIVED_DURABLE`，再处理业务。
- 发生断网或进程被 kill，所有事件保存在 `outbox` 表中。
- 重启后由 `TaskManager::resume_recovery()` 探测未完成任务与未确认事件，恢复后通过 `flush_outbox()` 补发并取得云端 ACK，实现脱机自愈。

### 7. 首装回滚安全停用机制
- 若技能为首次安装（无前序版本）即发起 `OP_ROLLBACK`，Agent 将技能安全置为 `disabled` 并如实上报 `NO_PREVIOUS_VERSION`，绝不虚报 `rolled_back`。

---

## 四、测试与验收实测结果

### 1. 设备端 Python 自动化测试（26/26 全部通过）
运行命令：
```powershell
$env:PYTHONPATH="agent"; py -m unittest discover -s agent/tests -p "test_*.py" -v
```
实测输出：
```text
test_canonical_v2_prefix (test_crypto.TestCrypto.test_canonical_v2_prefix) ... ok
test_jcs_canonicalize_order (test_crypto.TestCrypto.test_jcs_canonicalize_order) ... ok
test_jcs_types (test_crypto.TestCrypto.test_jcs_types) ... ok
test_rsa_signature_and_verify (test_crypto.TestCrypto.test_rsa_signature_and_verify) ... ok
test_full_agent_lifecycle_end_to_end (test_e2e_simulator.TestE2ESimulator.test_full_agent_lifecycle_end_to_end) ... ok
test_disabled_skill_cannot_execute (test_executor.TestExecutor.test_disabled_skill_cannot_execute) ... ok
test_execute_and_observe_succeeded (test_executor.TestExecutor.test_execute_and_observe_succeeded) ... ok
test_resource_mutex_conflict_blocked (test_executor.TestExecutor.test_resource_mutex_conflict_blocked) ... ok
test_safe_cancel_execution (test_executor.TestExecutor.test_safe_cancel_execution) ... ok
test_staging_install_success_and_failure_isolation (test_package_manager.TestPackageManager.test_staging_install_success_and_failure_isolation) ... ok
test_valid_unpack_and_manifest (test_package_manager.TestPackageManager.test_valid_unpack_and_manifest) ... ok
test_zip_bomb_file_count_rejected (test_package_manager.TestPackageManager.test_zip_bomb_file_count_rejected) ... ok
test_zip_slip_attack_rejected (test_package_manager.TestPackageManager.test_zip_slip_attack_rejected) ... ok
test_zip_symlink_rejected (test_package_manager.TestPackageManager.test_zip_symlink_rejected) ... ok
test_storage_installed_skills_and_json_sync (test_storage.TestStorage.test_storage_installed_skills_and_json_sync) ... ok
test_storage_journal_and_idempotency (test_storage.TestStorage.test_storage_journal_and_idempotency) ... ok
test_storage_outbox_and_leases (test_storage.TestStorage.test_storage_outbox_and_leases) ... ok
test_distinct_execution_ids_not_deduped (test_task_manager.TestTaskManager.test_distinct_execution_ids_not_deduped) ... ok
test_first_install_rollback_safely_disables (test_task_manager.TestTaskManager.test_first_install_rollback_safely_disables) ... ok
test_interrupted_install_recovery_on_startup (test_task_manager.TestTaskManager.test_interrupted_install_recovery_on_startup) ... ok
test_op_execute_and_cancel_lifecycle (test_task_manager.TestTaskManager.test_op_execute_and_cancel_lifecycle) ... ok
test_outbox_offline_retry_and_flush (test_task_manager.TestTaskManager.test_outbox_offline_retry_and_flush) ... ok
test_replay_duplicate_task_idempotency (test_task_manager.TestTaskManager.test_replay_duplicate_task_idempotency) ... ok
test_task_expired_rejected_before_side_effects (test_task_manager.TestTaskManager.test_task_expired_rejected_before_side_effects) ... ok
test_v2_signature_tamper_all_fields_rejected (test_task_manager.TestTaskManager.test_v2_signature_tamper_all_fields_rejected) ... ok
test_v2_signature_tamper_rejected (test_task_manager.TestTaskManager.test_v2_signature_tamper_rejected) ... ok

----------------------------------------------------------------------
Ran 26 tests in 3.776s

OK
```

### 2. 平台端 API 专项测试
运行命令：
```powershell
cd my-bagisto-store; $env:DB_DATABASE="summon_mecha_step10_test"; $env:APP_ENV="testing"; php artisan test packages/Webkul/Shop/tests/Feature/API/AgentV2ExecutionTest.php
```
实测输出：
```text
   PASS  Packages\Webkul\Shop\tests\Feature\API\AgentV2ExecutionTest
  ✓ it 旧 Agent 轮询时过滤掉 OP_EXECUTE(7) 与 OP_CANCEL_EXECUTION(8)                                                     0.45s  
  ✓ it OP_EXECUTE 执行成功仅更新 SkillExecution 状态，绝不污染 InstallRecord                                                   0.07s  
  ✓ it POST api/agent/ack 支持端云双向事件确认                                                                             0.13s  
  ✓ it POST api/agent/reconcile 端侧安装事实与云端记录准确对账                                                                  0.08s  
  ✓ it 客户侧能够发起技能执行与取消，流转为 OP 7/8 任务                                                                              0.12s  

  Tests:    5 passed (28 assertions)
  Duration: 1.05s
```

### 3. Deploy 模块全量回归测试（123/123 全部通过）
运行命令：
```powershell
cd my-bagisto-store; $env:DB_DATABASE="summon_mecha_step10_test"; $env:APP_ENV="testing"; php artisan test packages/Webkul/Shop/tests/Feature/Deploy
```
实测输出：
```text
  Tests:    123 passed (524 assertions)
  Duration: 20.87s
```

### 4. 平台全局契约门禁
运行命令：
```powershell
cd my-bagisto-store; php artisan ecosystem:contract-check
```
实测输出：
```text
契约版本：2026-10-20-q1
+-------------------+------+
| 项目              | 数量 |
+-------------------+------+
| admin_routes      | 113  |
| public_routes     | 45   |
| contracted_routes | 45   |
| error_codes       | 85   |
| state_inventories | 11   |
+-------------------+------+
契约检查通过。
```

---

## 五、机型适配与硬件状态诚实声明

| 机型名称 | 适配器实现类 | 依赖 SDK | 目标机型标识 | 状态与验证程度 |
| :--- | :--- | :--- | :--- | :--- |
| **模拟适配器** | `zhjj_agent.adapters.mock.MockAdapter` | 标准库 | `mock` | **自动化全链路通过**。完全用于单元测试与端到端模拟演练。 |
| **宇树 G1 EDU** | `zhjj_agent.adapters.unitree_g1.UnitreeG1Adapter` | `unitree_sdk2py` (DDS) | `unitree_g1` | **已完成规范对接代码，标注为 HARDWARE_UNVERIFIED（真机未验证）**。严格声明属性 `is_hardware_verified = False`，在未接入真实 G1 算力单元硬件时绝不谎称真机验收。 |

---

## 六、部署、操作与回退指引

### 1. 设备端部署命令
```bash
# 1. 确保安装 Python 3.10+ 及依赖
pip install cryptography

# 2. 检查版本
python agent/agent.py --version
# 输出: agent.py 0.2.0

# 3. 单次调试运行
python agent/agent.py --config /path/to/config.json --once

# 4. 常驻守护运行
python agent/agent.py --config /path/to/config.json
```

`config.json` 样例：
```json
{
  "server_url": "https://mecha.example.com",
  "did": "did_unitree_g1_001",
  "device_secret": "sec_xxxxxxxxxxxxxx",
  "public_key": "platform_public.pem",
  "model_code": "unitree_g1",
  "poll_interval": 5
}
```

### 2. 回退方案（Rollback Plan）
- **Agent 端回退**：若新 Agent 进程异常，可直接切换回上一版 `agent.py`；由于云端下发接口已实现协议门禁（若请求未声明 `agent-task/v2`，自动屏蔽 OP 7/8 任务），旧 Agent 仍能稳定执行基础生命周期安装与卸载。
- **服务端回退**：
  - 增量迁移 `2026_11_03_000001_add_v2_and_execution_to_deploy_tasks.php` 均为加性列与独立新表，无需回滚数据库即可保持向下兼容；
  - 若需完全撤回：`php artisan migrate:rollback --step=1`（仅在测试环境执行）。

---

## 七、第二阶段（转码器与编译器）接口预留

后续标准行为 XML 转码器完成生成后，将作为合规成品包导入平台并打上目标机型标签。
本阶段已锁定的承接契约：
1. **任务分发**：转码产物作为独立 `skill_assets` 登记，任务下发时通过 `build_key`、`target_model_code` 和 `package_sha256` 绑定目标机型。
2. **执行启动**：`OP_EXECUTE(7)` 携带 `plan_digest` 与 `execution_params`，Agent 端解包后调用适配器 `execute_skill` 调起机器底层动作，并通过 `observe_execution` 闭环。
