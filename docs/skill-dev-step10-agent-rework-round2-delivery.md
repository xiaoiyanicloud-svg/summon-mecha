# 第一阶段设备端 Agent 与平台增量对接第二轮返工交付报告

- **报告日期**：2026-11-03
- **返工基线提交**：`478a036c97d5e7b93b86bf4a358da60212489c5d`
- **执行范围界定**：**仅限第一阶段设备端 Agent 与平台必要对接返工（F01–F08 及 RF01–RF08）**。严守红线，未推进、未执行、未合并第二阶段（编译器、动作转码、BT.CPP Runner、机型 Profile）任何工作包。
- **受测环境与工具链**：
  - 操作系统：Windows (PowerShell)
  - PHP：8.3.4 (CLI) (`D:\java\php\php.exe`)
  - Python：3.12.14 (`C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe`)
  - 隔离测试数据库：`summon_mecha_step10_test`（MySQL，严禁对生产/开发主库写入）
  - 测试端口：随机受控端口（如 56641、56877 等），真实 Laravel HTTP 端口隔离
- **证据存储路径**：
  - 只读历史基线：`qa/agent-acceptance/20261103-round2/baseline-478a036-readonly/`
  - 本轮修复证据：`qa/agent-acceptance/20261103-round2/01-fixed-evidence/`
  - 统一哈希清单：`qa/agent-acceptance/20261103-round2/01-fixed-evidence/fixed-evidence-manifest.json`

---

## 1. 执行摘要与范围说明

在接手最新复验基线 `478a036` 后，针对独立复验报告 `docs/skill-dev-step10-agent-independent-reacceptance-20261103.md` 中指出的软件验收不通过项（F01–F08 反例失败、探针退出码为 0 但断言语义失败、A-T 部分用例存在空断言或未验证真实业务路径、RFC 8785 浮点数科学计数法格式偏差等），我们进行了严密的语义回归与工程重构：

1. **坚持反向反例先行（Fail-First）**：
   编写并固化了独立语义验证器 `qa/agent-acceptance/20261103-round2/pre-fix-check/validate_rf_gates.py`。在旧基线 `478a036` 下，该验证器捕获全部 9 项门槛失败（RF01–RF08 及 JCS_1e6），进程以非零退出码 1 严正报错，杜绝以“探针脚本退出码为 0”掩盖业务语义失败。
2. **彻底消灭空断言与模拟桩**：
   重构 `agent/tests/test_acceptance_at01_at26.py`，彻底删除原有 `assertTrue(True)`、无操作占位符与死循环空等待；全面接入真实 G1 适配器拒绝、撤销代次过滤、真实长执行线程并发取消、真实签名验签与 RFC 8785 跨语言黄金向量。
3. **闭环 F01–F08 核心缺陷**：
   所有下载路径接入设备级 HMAC 凭证与源站重定向脱敏、释放租约前严格核验外部真实停止确认、SQLite 引入持久化 `executions` 事实表阻断跨进程重启重跑、启动与轮询真实挂接崩溃恢复补偿、全面防护外层参数越权篡改与稀疏载荷注入、封堵 G1 适配器目录穿越与如实返回失败态、回滚严格校验有效期与磁盘 SHA256 镜像比对、引入 v2 可靠 ACK 投递协议与超时任务回执幂等。
4. **全套门禁一次性全绿**：
   - 语义门槛验证器 `validate_rf_gates.py`：**ALL 9 RF GATES PASSED (Exit Code: 0)**
   - Agent 核心验收套件 `test_acceptance_at01_at26.py`：**26/26 全部通过**（3.74s）
   - Python 全量离线与集成套件：**57/57 全部通过**（13.64s，含 4 项端口隔离真实 Laravel HTTP 测试）
   - 平台端 Deploy 特性套件：**152/152 全部通过**（5,714 个断言，24.53s）
   - 平台端 AgentV2 特性套件：**5/5 全部通过**（30 个断言，0.99s）
   - 生态契约检查 `ecosystem:contract-check`：**通过**（契约版本：2026-10-20-q1）
5. **严守工作包边界**：
   本次返工严格限制在第一阶段设备端 Agent 与平台必要 API，第二阶段编译器与转码器相关源码无任何改动，Git 状态与提交范围完全干净。

---

## 2. 缺陷 F01–F08 与关闭门槛 RF01–RF08 逐项闭环矩阵

### 2.1 F01 / RF01：真实平台包下载缺设备认证与错误分类

- **基线问题与成因**：
  在 `478a036` 中，`TaskManager._handle_install` 调用 `PackageManager.download_and_verify` 时未传入凭证头，请求受控平台下载端点 `GET /api/agent/packages/{package_name}` 收到 HTTP 401 Unauthorized。`PackageManager` 错误地将所有网络/HTTP 异常一律包装为 `ERR_PKG_HASH_MISMATCH`，导致真实下载失败被伪装成包哈希错误；同时缺乏跨域重定向过滤，存在密钥泄露风险。
- **修改代码位置**：
  - [`agent/zhjj_agent/client.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/client.py)：新增 `get_download_headers(package_url)`，校验 URL 必须匹配 `self.server_url` 的 Origin，自动生成包含 `X-Agent-Did`、`X-Agent-Timestamp` 与 `X-Agent-Signature` 的设备级认证头。
  - [`agent/zhjj_agent/package_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/package_manager.py)：引入 `SafeRedirectHandler`，当发生跨源站重定向时自动剔除 `X-Agent-*` 与 `Authorization` 等敏感头；`download_and_verify` 接收 `headers` 参数并使用自定义 `OpenerDirector`；明确区分 HTTP 401/403 抛出 `AUTH_FAILED`、网络中断抛出 `ERR_DOWNLOAD_FAILED`、真正 SHA256 校验不匹配抛出 `ERR_PKG_HASH_MISMATCH`。
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：在 `_handle_install` 中调用 `self.client.get_download_headers(package_url)` 并透传给包管理器，捕获 `AUTH_FAILED` 时准确记录 `phase="failed"` 且错误码为 `AUTH_FAILED`。
- **基线失败现象（反例）**：
  `http_counterexamples.json` 中 `real_http_install.accepted == false`，错误日志为 `HTTP Error 401: Unauthorized`，`installed_count == 0`，`journal_status == 'FAILED'`。
- **修复后通过证据（RF01）**：
  - `rf-gates-fixed-result.json` -> `RF01`: `accepted=True, journal_status=COMMITTED, installed_count=1, error=None`。
  - 启动真实 Laravel 测试端口服务，以合法设备凭据向平台请求任务并下载，HMAC 验签通过，成功流式拉取、解包并落盘，SHA256 完全吻合，Journal 正式提交为 `COMMITTED`，Outbox 成功记录回执。
  - 跨源重定向测试证明敏感头在跨站跳转时被安全剥除。

---

### 2.2 F02 / RF02：外部执行尚未停止即释放租约与删除技能

- **基线问题与成因**：
  在 `478a036` 中，当外部 Stub 正在长周期运行（`is_stopped=False`），平台发来回收或卸载指令时，`TaskManager._handle_uninstall_or_reclaim` 与 `_handle_toggle` 盲目调用 `storage.release_lease()` 并通过 `shutil.rmtree()` 删除了 `skills/{id}/{version}` 目录，最后将任务标为 `done`。造成硬件动作仍在物理执行，端侧却宣称已卸载且磁盘文件已被销毁的严重事故。
- **修改代码位置**：
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：重构 `_handle_uninstall_or_reclaim` 与 `_handle_toggle`：
    1. 首先触发 `executor.cancel_execution(active_execution_id)`；
    2. 主动调用 `executor.observe_execution(active_execution_id)` 获得外部观察事实；
    3. 若 `obs.get("is_stopped")` 不为 `True`，或者适配器 `adapter.uninstall()` 返回 `False`，**绝对禁止调用 release_lease 与 rmtree**；保留 ACTIVE 租约与本地文件，记录错误码 `STOP_UNCONFIRMED`，上报 `PHASE_FAILED`，返回 `False`；
    4. 仅在适配器与执行器外部双重确认 `is_stopped == True` 且 `uninstall() == True` 后，方允许流转至 `storage.release_lease` 与目录清理。
  - [`agent/zhjj_agent/executor.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/executor.py)：在 `observe_execution` 观察到终止时，只有当 `obs.get("is_stopped") is True` 时才执行租约回收。
- **基线失败现象（反例）**：
  `agent_counterexamples.json` 中 `reclaim_before_stop_confirmation` 显示：`observed.is_stopped == False`，但 `lease == RELEASED`，`payload_directory_exists == False`，`last_event.phase == "done"`。
- **修复后通过证据（RF02）**：
  - `rf-gates-fixed-result.json` -> `RF02`: `observed_stopped=False, lease=ACTIVE, payload_exists=True, last_phase=failed`。
  - 当外部进程持续处于 RUNNING 状态且 `is_stopped=False` 时，租约牢固保持 `ACTIVE`，本地技能目录完整保留，状态机如实报告 `failed`，拒绝伪造 `done`。

---

### 2.3 F03 / RF03：同一 execution_id 跨进程重启再次重复启动

- **基线问题与成因**：
  在 `478a036` 中，端侧仅依赖运行时字典或内存锁判断执行去重。当 Agent 进程发生非正常退出并重启，或者适配器重新初始化后，服务端重新下发相同 `execution_id` 但不同 `task_id` 的指令时，由于内存状态丢失，重新调用了 `adapter.execute_skill`，导致外部机器人物理动作被重复执行。
- **修改代码位置**：
  - [`agent/zhjj_agent/storage.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/storage.py)：在 SQLite 中新建物理级独立事实表 `executions`（含 `execution_id` 主键、`task_id`、`skill_id`、`version`、`plan_digest`、`params_json`、`status`、`error_code`、`started_at`、`completed_at` 等字段），实现执行事实与安装事实、资源租约的彻底解耦。新增 `record_execution`、`get_execution`、`update_execution_status` 等原子方法。
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：在 `_handle_execute` 入口处首先查询 SQLite `storage.get_execution(execution_id)`：
    1. 若已存在且处于终态（`COMPLETED` / `FAILED`），直接幂等返回已记录的事实，**绝不重复调用适配器 execute_skill**；
    2. 若已存在且处于 `RUNNING`，仅恢复观察器监听，阻断外部启动调用；
    3. 校验请求载荷（`skill_id`, `version`, `execution_params`）与历史记录的一致性，若被篡改则报错拒绝。
- **基线失败现象（反例）**：
  `agent_counterexamples.json` 中 `same_execution_after_restart` 显示：在新 Agent 进程中重投相同 `execution_id`，适配器被调用次数 `new_adapter_start_calls == 1`（重复执行）。
- **修复后通过证据（RF03）**：
  - `rf-gates-fixed-result.json` -> `RF03`: `new_adapter_start_calls=0, second_accepted=True`。
  - 在完全新建的 Agent 进程与适配器中，重投已有 `execution_id` 命令被本地持久化事实精确拦截，外部适配器调用次数为 0，且安全返回已落库事实。

---

### 2.4 F04 / RF04：崩溃恢复与对账未接入正常运行入口

- **基线问题与成因**：
  在 `478a036` 中，`resume_recovery()` 方法虽然存在，但未被编排进 CLI 入口与 `poll_once()` 主循环。若 Agent 在安装下载途中（`DOWNLOADING`）崩溃退出，重启后正常轮询时，该未决任务永远停滞在 `DOWNLOADING`，恢复逻辑从未被自动触发，导致本地状态与服务端永久失步。
- **修改代码位置**：
  - [`agent/zhjj_agent/main.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/main.py)：在 Agent 启动入口 `run()` 开始循环前，显式调用 `task_manager.resume_recovery()`。
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：在 `poll_once()` 方法开始处，显式调用 `self.resume_recovery()`，确保无论是常驻守护进程还是单次 poll（`--once`），都能在拉取新指令前完成崩溃状态检查点核对与出站队列重放；将未完成下载的残留任务安全收敛为 `FAILED` 并记录 Journal。
- **基线失败现象（反例）**：
  `agent_counterexamples.json` 中 `recovery_not_connected_to_poll` 显示：预置 `DOWNLOADING` 任务后调用 `poll_once()`，`recovery_calls == 0`，任务状态依然保持 `DOWNLOADING`。
- **修复后通过证据（RF04）**：
  - `rf-gates-fixed-result.json` -> `RF04`: `recovery_calls=1, journal_status=FAILED`。
  - 在普通 `poll_once()` 触发时，自动激活崩溃自愈检查点，残缺任务正确收敛并记录为 `FAILED`，恢复计数如实累加。

---

### 2.5 F05 / RF05：参数越权篡改、稀疏载荷注入与用户端非幂等

- **基线问题与成因**：
  1. `TaskManager` 读取执行参数时优先读取外层明文 `cmd.get("execution_params")`，未比对内层被签名的 `signed["execution_params"]`，使得中间人可通过伪造外层参数越权篡改机器人动作；
  2. 允许缺少 `protocol_version`、`task_id`、`command_seq`、`did`、`operation`、`task_expires_at` 等元数据的稀疏载荷绕过校验；
  3. 服务端 `SkillController.php` 在用户端发起技能执行时，未校验授权（Entitlement）是否有效，且未支持客户端 `nonce` 幂等去重。
- **修改代码位置**：
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：
    1. 在 `process_command` 中强制执行内层 `signed` 稀疏性与必填字段校验，若缺少 `protocol_version`、`task_id`、`command_seq`、`did`、`operation`、`task_expires_at` 立即拒绝（`ERR_SIG_INVALID`）；
    2. 执行强一致性校验：若外层提供了 `execution_params`，必须与内层 `signed["execution_params"]` 逐字逐键一致，否则以 `ERR_SIG_INVALID` 阻断执行；
    3. `_handle_execute` 仅从不可篡改的 `signed["execution_params"]` 获取参数。
  - [`my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/SkillController.php`](file:///d:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/SkillController.php)：
    1. `triggerExecution` 入口处严格检查 `$entitlement->isUsable()`，过期或无效授权直接返回 HTTP 403 `ERR_SKILL_EXPIRED`；
    2. 引入 `nonce` 字段校验，根据 `(user_id, skill_id, nonce)` 查询既有未超时记录，若命中则幂等返回该记录（HTTP 200），阻断重复计费与重复指令下发。
- **基线失败现象（反例）**：
  `customer_counterexamples.json` 显示：外层参数篡改未被拦截；过期授权仍可发起执行；缺少 `nonce` 去重。
- **修复后通过证据（RF05）**：
  - `rf-gates-fixed-result.json` -> `RF05`: `params_protected=True, sparse_rejected=True, cust_rejected=True, nonce_deduped=True`。
  - 外层参数篡改 100% 被捕获并拒绝；稀疏不完整载荷被拒绝；服务端对过期权益返回 403；同 nonce 重复调用返回 200 且返回既有 execution_id。

---

### 2.6 F06 / RF06：Unitree G1 适配器路径穿越逃逸与失败结果被吞

- **基线问题与成因**：
  1. `UnitreeG1Adapter.prepare` 与 `execute_skill` 未对 `entrypoint` 进行目录界限校验，允许 `../outside.py` 等穿越路径在技能安装根目录外执行任意代码；
  2. 适配器工作线程调用入口模块的 `mod.run()` 时，未检查其返回值字典；当模块返回 `{"status": "FAILED", "stopped": False}` 时，适配器依旧盲目记录 `status="SUCCEEDED"` 并伪造 `is_stopped=True`。
- **修改代码位置**：
  - [`agent/zhjj_agent/adapters/unitree_g1.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/adapters/unitree_g1.py)：
    1. 增加规范化绝对路径检查：`real_entry = os.path.realpath(entry_path)`，要求必须以 `real_install + os.sep` 开头；对试图跳出安装目录的非法路径直接返回 `False`，记录 `FAILED` 执行状态，绝不调用 Python `load_module` 执行恶意代码；
    2. 工作线程在执行 `res = mod.run(context)` 后，严格解构其返回字典：若 `res.get("status") == "FAILED"` 或 `res.get("stopped") is False`，如实更新记录为 `status = "FAILED"` 且 `is_stopped = False`，绝不虚构成功。
- **基线失败现象（反例）**：
  `agent_counterexamples.json` 中 `g1_escape_and_failure` 显示：`g1_handles_fail=False (observed SUCCEEDED)`，`escape_blocked=False (executed=True)`。
- **修复后通过证据（RF06）**：
  - `rf-gates-fixed-result.json` -> `RF06`: `g1_handles_fail=True (observed FAILED), escape_blocked=True (executed=False)`。
  - 路径逃逸直接被拦截，未发生越界脚本执行；返回 FAILED 的业务脚本如实体现为端侧 FAILED 与未停止状态。

---

### 2.7 F07 / RF07：回滚未校验 task_expires_at 与磁盘内容哈希

- **基线问题与成因**：
  `TaskManager._handle_rollback` 在执行回滚操作时，仅检查本地是否存在历史备份目录，未校验指令的 `task_expires_at` 过期时间；同时未将磁盘上的快照内容与签名中的 `restore_sha256` 进行 SHA256 完整性复核，若备份目录已被本地篡改，端侧依然会执行回滚并上报成功。
- **修改代码位置**：
  - [`agent/zhjj_agent/task_manager.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/task_manager.py)：
    1. 在 `process_command` 的 `task_expires_at` 校验中，将 `OP_ROLLBACK` 与 `OP_INSTALL` 等同视作敏感指令，过期一律抛出 `ERR_COMMAND_EXPIRED`；
    2. 在 `_handle_rollback` 中，遍历将要恢复的磁盘文件并计算其实际 SHA-256 摘要，与 `signed.get("restore_sha256")` 进行恒等核对；若哈希不符，拒绝执行恢复，保留被篡改现场，记录 `ERR_RESTORE_HASH_MISMATCH` 并上报 `failed`。
- **基线失败现象（反例）**：
  `agent_counterexamples.json` 中 `tampered_rollback_accepted` 显示：篡改后的备份目录仍被回滚执行，`accepted == True`。
- **修复后通过证据（RF07）**：
  - `rf-gates-fixed-result.json` -> `RF07`: `accepted=False, payload=tampered-not-good`。
  - 注入篡改文件后，回滚指令被彻底拒绝，篡改内容未生效，端侧上报失败。

---

### 2.8 F08 / RF08：指令交付可靠性（v1/v2 协议分流）与超时任务回执幂等

- **基线问题与成因**：
  1. 服务端 `AgentController.php::commands` 在 Agent 轮询拉取时便立即写入 `delivered_at = now()`。在网络抖动场景下，若 HTTP 响应在传输途中丢包，Agent 并未持久化该指令，而服务端却认为已投递且不再重发，导致任务永久悬挂丢失；
  2. 当任务在服务端已流转为 `timeout` 终态时，若迟到的 Agent 再次上报事件，服务端抛出 HTTP 409 异常，引发 Agent 端 Outbox 无限死循环重试。
- **修改代码位置**：
  - [`my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/AgentController.php`](file:///d:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/AgentController.php)：
    1. 实施协议版本分流：检查请求头 `X-Agent-Protocol`。对于 `agent-task/v2` 协议，`commands()` 拉取时不提前打标 `delivered_at`，保持可重试状态；仅当端侧持久化完成后调用 `POST /api/agent/ack` 时，才将 `delivered_at` 更新为当前时间；对于遗留 v1 协议，保持原有即时打标逻辑以兼容旧契约；
    2. 在 `event()` 接口中，若任务状态已处于 `timeout` 终态，将迟到事实记录至 `$task->signed_payload['late_report']` 并保存，接口返回 HTTP 200（`status=ignored_timeout`），消除 409 冲突死循环。
- **基线失败现象（反例）**：
  `http_counterexamples.json` 中 `v2_lost_on_network_drop` 显示：无 ACK 确认的情况下，第二次轮询无法再次获得未送达任务，`second_without_ack == 0`。
- **修复后通过证据（RF08）**：
  - `rf-gates-fixed-result.json` -> `RF08`: `first_count=1, second_without_ack=1`。
  - v2 协议下，未收到端侧 ACK 前任务支持可靠重新拉取；收到 ACK 后标记交付；迟到事件上报平稳收敛为 200 成功响应。

---

### 2.9 JCS 规范串标准序列化（RFC 8785 §3.2.2.3 / ECMAScript Number）

- **基线问题与成因**：
  PHP 与 Python 在对小浮点数（如 `1e-6`、`1e-7`）与大数（如 `1e20`、`1e21`）进行 JCS 序列化时，依赖系统底层 `json_encode` 或 `str(float)`。Python `str(1e-6)` 输出为 `"1e-06"`，而 ECMAScript 规范定义 `1e-6` 必须序列化为定点数字符串 `"0.000001"`，只有绝对值 $< 1e-6$ 时才允许使用科学计数法。导致双向 JCS 跨语言摘要出现差异。
- **修改代码位置**：
  - [`agent/zhjj_agent/crypto.py`](file:///d:/CodexDeploy/summon-mecha/agent/zhjj_agent/crypto.py)：编写符合 ECMAScript 规范的 `format_es_number(val)`。对于浮点数，当 $-0.0$ 时输出 `"0"`；当处于 $[1e-6, 1e21)$ 区间时，以精准定点字符串输出；其他区间采用小写且无多余前导零的指数格式（如 `1e-7`、`1e+21`）。
  - [`my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/SigningService.php`](file:///d:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/SigningService.php)：同步落地对齐的 `formatEsNumber` 实现。
- **修复后通过证据**：
  - `rf-gates-fixed-result.json` -> `JCS_1e6`: `jcs_1e6={"x":0.000001}, expected={"x":0.000001}`。
  - `test_acceptance_at01_at26.py::test_at25_rfc8785_cross_language_vectors` 双向黄金向量测试 100% 通过。

---

## 3. 验收套件 A-T01 至 A-T26 逐项全覆盖与消灭空断言记录

测试代码路径：[`agent/tests/test_acceptance_at01_at26.py`](file:///d:/CodexDeploy/summon-mecha/agent/tests/test_acceptance_at01_at26.py)

| 用例编号 | 验收项描述 | 旧基线缺陷 / 空断言状态 | 本轮实装真实业务断言与验证逻辑 | 结果 |
|---|---|---|---|---|
| **A-T01** | Python 3.12 兼容性 | 正常通过 | 验证全部模块与类型导入无弃用/缺失报错 | **PASS** |
| **A-T02** | 签名篡改阻断 | 正常通过 | 篡改 `operation` 签名失败且不产生安装副作用 | **PASS** |
| **A-T03** | 跨设备重放阻断 | 正常通过 | 错配 `did` 被 `DID_MISMATCH` 拦截 | **PASS** |
| **A-T04** | 任务过期阻断 | 正常通过 | `task_expires_at` 过期被 `ERR_COMMAND_EXPIRED` 拦截 | **PASS** |
| **A-T05** | 降级重包阻断 | 正常通过 | 低版本包被 `ERR_DOWNGRADE_REJECTED` 拦截 | **PASS** |
| **A-T06** | 摘要篡改阻断 | 正常通过 | payload SHA 篡改被 `ERR_PKG_HASH_MISMATCH` 拦截 | **PASS** |
| **A-T07** | 执行幂等去重 | 内存去重不足 | 依赖 SQLite `executions` 持久表，同 execution_id 不重跑 | **PASS** |
| **A-T08** | 执行与安装隔离 | 正常通过 | `SkillExecution` 成功绝不污染 `InstallRecord` | **PASS** |
| **A-T09** | 硬件未验证标定 | 正常通过 | G1 无真实 SDK 且无 stub 时如实返回 `HARDWARE_UNVERIFIED` | **PASS** |
| **A-T10** | WAL 持久事实 | 正常通过 | Journal、Checkpoints 与 Outbox 事务落盘且 WAL 模式开启 | **PASS** |
| **A-T11** | 崩溃恢复补偿 | 仅手动断言，未触发状态流转 | **消除占位符**：构造真实 DOWNLOADING 任务，调用恢复逻辑验证其安全收敛为 FAILED | **PASS** |
| **A-T12** | 409 事实保留 | 正常通过 | 服务端返回 409 时保留本地 Outbox 事实供对账 | **PASS** |
| **A-T13** | 真实 ACK 移除 | 正常通过 | 仅在收到服务端有效 ACK 后才移出 Outbox | **PASS** |
| **A-T14** | 事实差异对账 | 弱断言未比对差异字段 | **强化真实断言**：上报端侧真实版本，与云端对账并断言差异字段 `aligned==False` | **PASS** |
| **A-T15** | 长执行安全取消 | 外部无阻塞，0.05s 自杀式假测试 | **消除虚假完成**：启动长执行工作线程，并发触发取消，断言线程收到取消且状态置为 `CANCELLED` | **PASS** |
| **A-T16** | 故障超时上报 | 正常通过 | 执行超时准确上报 `phase=failed` 并记录 `EXECUTION_TIMEOUT` | **PASS** |
| **A-T17** | 首装回滚安全停用 | 正常通过 | 无历史版本回滚时标记为 `disabled`，绝不虚构恢复 | **PASS** |
| **A-T18** | 有历史版本安全回滚 | 正常通过 | 准确恢复上一版本 payload 并重建软链与状态 | **PASS** |
| **A-T19** | 停用卸载取消执行 | 正常通过 | 卸载指令主动取消关联执行并注销租约 | **PASS** |
| **A-T20** | 机型回退阻断 | **旧基线存在 `assertTrue(True)` 空断言** | **彻底消除**：真实构造 `zhiyuan_a2` 与 `unitree_g1` 机型包，G1 适配器对 A2 明确拒绝（`prepare=False`），对 G1 合法接受 | **PASS** |
| **A-T21** | 解包解压安全防范 | 正常通过 | 拦截 Zip Slip、Symlink 穿越与 Zip Bomb 膨胀攻击 | **PASS** |
| **A-T22** | 越权执行参数防御 | **旧基线存在 `assertTrue(True)` 空断言** | **彻底消除**：向 `TaskManager.process_command` 注入外层篡改的 `execution_params`，断言被 `ERR_SIG_INVALID` 严厉拒绝 | **PASS** |
| **A-T23** | 跨执行生命周期隔离 | 正常通过 | 不同 `execution_id` 的执行互不干扰，租约独立管理 | **PASS** |
| **A-T24** | 撤销代次阻断重投 | **旧基线存在 `assertTrue(True)` 空断言** | **彻底消除**：真实设置 `revocation_epoch`，验证旧指令在租约与任务管理器层被拒（`ERR_REVOKED`） | **PASS** |
| **A-T25** | JCS 跨语言黄金向量 | 浮点数未覆盖 1e-6 临界点 | **升级强化**：覆盖 RFC 8785 全部规范，针对 `1e-6`、`1e20`、`-0.0` 实施跨语言黄金向量双向断言 | **PASS** |
| **A-T26** | 单机双设备密钥隔离 | 正常通过 | 设备 A 密钥无法解密或操作设备 B 的任务与凭证 | **PASS** |

**A-T01 ~ A-T26 汇总**：全部 26 项真实业务用例 100% 通过（**PASS: 26, FAIL: 0, BLOCKED: 0**），无任何空断言，无任何假成功。

---

## 4. 全量验证与端云集成测试汇总

### 4.1 测试执行矩阵与证据对应表

| 测试套件 | 执行范围与命令 | 执行结果 | 证据文件 |
|---|---|---|---|
| **RF 语义门槛验证器** | `python validate_rf_gates.py` | **ALL 9 GATES PASSED** (Exit 0) | [`rf-gates-fixed-result.json`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/rf-gates-fixed-result.json) |
| **A-T01~26 黑盒验收** | `python -m unittest tests/test_acceptance_at01_at26.py` | **26 passed in 3.74s** | [`python-test-output.txt`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/python-test-output.txt) |
| **Python 全量测试** | `python -m unittest discover -s tests -p "test_*.py"` | **57 passed in 13.64s** (含 4 项 Live HTTP) | [`python-test-output.txt`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/python-test-output.txt) |
| **平台 Deploy 特性套件** | `pest packages/Webkul/Shop/tests/Feature/Deploy/` | **152 passed (5714 assertions)** | [`deploy-pest-output.txt`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/deploy-pest-output.txt) |
| **平台 AgentV2 API** | `pest tests/Feature/API/AgentV2ExecutionTest.php` | **5 passed (30 assertions)** | [`api-agent-v2-pest-output.txt`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/api-agent-v2-pest-output.txt) |
| **生态契约门禁** | `php artisan ecosystem:contract-check` | **PASSED** (113 admin, 45 public, 85 err) | [`contract-check.txt`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/contract-check.txt) |

### 4.2 真实 HTTP 端口隔离集成测试（`test_live_http_integration.py`）

- **网络隔离**：Python 子进程随机拉起独立端口（如 56641），环境变量注入 `APP_ENV=testing` 与 `DB_DATABASE=summon_mecha_step10_test`。
- **全链路测试事实**：
  1. `test_01_handshake_and_heartbeat`：Agent 端发送真实 HMAC 签名心跳，服务端返回 200，设备在线状态更新；
  2. `test_02_poll_commands_and_ack`：通过真实 HTTP 轮询拉取指令，并通过 `POST /api/agent/ack` 完成双向可靠确认；
  3. `test_03_report_install_events_and_reconcile`：Agent 上报安装过程事件与终态，并调用 `/api/agent/reconcile` 完成端云版本对账；
  4. `test_04_unauthorized_access_rejected`：错配凭据与伪造签名直接被平台返回 401 拦截。

---

## 5. 证据文件与代码指纹清单

详见清单文件：[`qa/agent-acceptance/20261103-round2/01-fixed-evidence/fixed-evidence-manifest.json`](file:///d:/CodexDeploy/summon-mecha/qa/agent-acceptance/20261103-round2/01-fixed-evidence/fixed-evidence-manifest.json)

| 证据文件 | SHA-256 摘要 | 作用说明 |
|---|---|---|
| `rf-gates-fixed-result.json` | `298e6d74728dd75bc4ffdd7e09ed011fdef8756bbc17ce72bb9acc53ee27fcca` | 9 项 RF 门槛全部通过的判定 JSON 事实 |
| `agent-counterexamples.json` | `99cd1cbb5eb637886c4644c0f7bb4ef5bfc1b06138a19d703b764f6e9320a63f` | Agent 端反例修复后的实际输出事实 |
| `http-counterexamples.json` | `3ead1122101251e57df384e5e5b9b89b9ec0dfc0e60f488010996ae2333a328b` | HTTP 鉴权与重定向反例修复后的输出事实 |
| `customer-counterexamples.json` | `13119b0f01102a9779b9b4a19eb95fddde33b7d8889ef8f0dee1942276c11ed4` | 用户端参数防护与 nonce 去重输出事实 |
| `python-test-output.txt` | `84723d941caed851d442f14bb4547e3423a38aa1d70dfddf761aa4769562f6d9` | Python 57 项全量单元与集成测试执行全日志 |
| `deploy-pest-output.txt` | `a7d6f8b7cc8cc26cd6738207f972731f9f87c27271d1f60a74f82b0a82b6b76d` | 平台端 Deploy 152 项 Pest 特性测试执行日志 |
| `api-agent-v2-pest-output.txt` | `3eb48cfc6da0cfe3bb76a5bc0aaf1e1b46ac6dfc3715beac798d308a4d60f3f7` | 平台端 AgentV2 5 项 Pest API 测试执行日志 |
| `contract-check.txt` | `15912a22ff566c3fa3fa11a28f81c04c0e5628bcb49b0b4bd7833ce19cbeaf7f` | `artisan ecosystem:contract-check` 门禁检查日志 |
| `source-sha256-after.txt` | `e3efc08c3c31b52c3729c04e638e8d04e4986192797de81673d26e4ced7b0f8a` | 本轮全部修改源码文件的 SHA-256 清单 |
| `git-diff.patch` | `ce8c3c376f59e523331ffd7c856be0c4aaead2809ee16e4a909d4e46a3cbb6a1` | 本轮返工代码全量差异补丁 |

---

## 6. 四级完成度诚实验收判定

依据工程纪律规范与独立复验要求，分类判定如下：

| 完成等级 | 判定状态 | 判定依据与事实 |
|---|---|---|
| **软件完成** (Software Complete) | **PASS** | 1. 独立语义验证器 `validate_rf_gates.py` 判定 RF01–RF08 及 JCS 门槛全部达成，退出码为 0；<br>2. `agent/tests/` 57 项 Python 测试全绿（含 26 项 A-T 黑盒与 4 项 live HTTP 隔离集成）；<br>3. 平台端 Deploy 152 项与 AgentV2 5 项测试全绿，契约门禁全绿；<br>4. 彻底消灭空断言，端云交互业务全链路打通。 |
| **SDK 接入完成** (SDK Integrated) | **PARTIAL** | 1. 宇树 Unitree G1 适配器已严密实现入参校验、路径穿越拦截、状态观察与执行生命周期管理；<br>2. **诚实标定**：当运行环境缺少真实真机 SDK 动态链接库时，拒绝盲跑或伪造成功，如实返回 `False` 与 `UNKNOWN`，标定为 `HARDWARE_UNVERIFIED`。 |
| **真机完成** (Hardware Verified) | **HARDWARE_UNVERIFIED** | **严守工程红线**：第一阶段返工严格禁止在无物理保护环境下触发实体机器人动作。未在物理机器人上部署受控 HIL。所有真机状态标识为 `HARDWARE_UNVERIFIED`，严禁宣称真机完成。 |
| **生产可交付** (Production Ready) | **BLOCKED** | 生产环境根证书链、物理机上架密钥分发、双人值守方案及业务负责人签字审计证据尚待第二阶段及上线演练齐备，按纪律红线维持 `BLOCKED` 状态。 |

---

## 7. 结论与后续操作

第一阶段第二轮返工要求之 F01–F08 缺陷与 RF01–RF08 门槛已全部闭环，所有反例已全部转化为严密回归断言并通过验证。

**本轮返工严格限定在第一阶段范围，未启动第二阶段任务。目前处于停止等待状态，等待用户后续指令。**
