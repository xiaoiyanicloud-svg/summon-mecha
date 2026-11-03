# 第一阶段设备端 Agent 与平台增量对接返工交付报告

- **日期**：2026-11-03
- **基线提交**：`44ca8ff83ba894450e757b7f5bcf8b0daf4f8804`
- **受测环境**：
  - 操作系统：Windows Server / Windows 10/11
  - PHP：8.3.4 (CLI)
  - Python：3.14.4 / 3.12 兼容导入
  - 隔离测试数据库：`summon_mecha_step10_test`（MySQL 5.7/8.0 兼容）
  - 隔离测试端口：动态随机端口（如 55359）
- **交付范围**：第一阶段设备端 Agent 独立代码包、必要的平台对接及隔离端云集成验证。

---

## 1. 对应闭环矩阵：工作包 A01–A08 与验收缺陷 R01–R10

| 工作包 | 对应缺陷 | 缺陷现象与成因 | 实施修复方案 | 验证用例 |
|---|---|---|---|---|
| **A01**<br>可运行基线与机型隔离 | **R01**<br>**R07** | Python 3.12 缺少 `Tuple`/`Any` 类型导入；`UnitreeG1Adapter` 在缺少 SDK 时盲目 sleep 0.05s 伪造成功。 | 1. 补齐 `executor.py` 与 `package_manager.py` 类型导入；<br>2. 重构 `UnitreeG1Adapter`，真实检查入口脚本，无 SDK 且无 stub 时拒绝执行，返回 `False`，记录 `UNKNOWN`，诚实标注 `HARDWARE_UNVERIFIED`。 | A-T01<br>A-T09 |
| **A02**<br>唯一可信任务对象与验签 | **R02** | 任务对象内外层字段（`operation`, `did`, `skill_id`, `version`, `package_sha256`）未校验一致性，允许外层篡改。 | 在 `TaskManager.process_command` 实施内外层字段强一致性校验与 `client.did` 身份核验，任何不符在副作用前拒绝。 | A-T02<br>A-T03<br>A-T04 |
| **A03**<br>RFC 8785 规范化 JCS 编码 | **R02** | PHP 与 Python JCS 编码在 UTF-16 键排序与浮点数指数格式上存在漂移风险。 | 1. PHP `SigningService.php` 采用 `utf16_strcmp` 码元排序与 ECMAScript 数字格式化；<br>2. Python `crypto.py` 采用 `encode("utf-16-be")` 与标准 ECMAScript 格式化；<br>3. 跨语言黄金测试向量双向字节比对完全一致。 | A-T25 |
| **A04**<br>安全下载、解包与 Staging 事务 | **R08** | 解包缺乏 Zip Slip 路径穿越、符号链接与解压炸弹防御；缺乏目录隔离。 | `PackageManager` 实施流式下载限额、SHA-256 实时复算、Zip Slip 校验、Symlink 拒绝、膨胀比预检、磁盘空间预检及 Staging 目录原子重命名。 | A-T21 |
| **A05**<br>技能执行与取消（OP 7/8） | **R05**<br>**R06** | 沿用设备/版本去重键导致第二次合法执行被吞；执行成功可能被误落为安装记录。 | 1. 执行任务按 `execution_id` 独立去重，支持同执行重投幂等与不同执行独立隔离；<br>2. 执行事实（`SkillExecution` / `execution_leases`）与安装事实（`InstallRecord` / `installed_skills`）彻底物理隔离。 | A-T07<br>A-T08<br>A-T23 |
| **A06**<br>本地持久化、Outbox 与崩溃恢复 | **R03**<br>**R04** | 迟到回报被拒时丢弃事实；下载阶段崩溃重启后将旧版误报为新版成功。 | 1. SQLite WAL 记录 Journal、Checkpoints 与 Event Outbox；<br>2. 收到有效 ACK 才移出 Outbox，服务端 409 错误保留事实供审计；<br>3. `resume_recovery` 校验当前版本，未完成下载判定为 `FAILED`，绝不虚报成功。 | A-T10<br>A-T11<br>A-T12<br>A-T13 |
| **A07**<br>生命周期与显式回滚安全停用 | **R04**<br>**R06** | 回滚缺乏对首装无旧版场景的安全停用判定；停用/卸载未清理正在运行的执行与租约。 | 1. 显式回滚优先取指定恢复版本或 `previous_version`；首装无旧版时安全停用（status置 `disabled`），不报假恢复；<br>2. 停用/卸载/回收时主动取消关联执行并释放租约，记录 `revocation_epoch`。 | A-T15<br>A-T17<br>A-T18<br>A-T19 |
| **A08**<br>平台增量对接与门禁加固 | **R09**<br>**R10** | 1. `SkillDeliveryAssetResolver` 在多机型构建存在时回退至单一文件，将 G1 产物错发给 A2；<br>2. `buildCommand` 刷新任务导致签名与有效期漂移；<br>3. 对账接口忽略端侧未知记录。 | 1. 产物解析器检测到存在目标机型构建时，机型不符强行返回 `null`；<br>2. `DeployTaskService::buildCommand` 首次构建签名后冻结在 `signed_payload`，刷新不改变过期时间；<br>3. `AgentController::reconcile` 实施全量双向差异比对。 | A-T06<br>A-T14<br>A-T20<br>A-T22<br>A-T24<br>A-T26 |

---

## 2. A-T01 ~ A-T26 黑盒验收测试结果清单

测试运行指令：`cd agent && py -m unittest tests/test_acceptance_at01_at26.py`

| 用例编号 | 测试目标与输入 | 预期结果 | 实际执行结果 | 判定 |
|---|---|---|---|---|
| **A-T01** | Python 3.12/3.14 规范导入与 CLI 入口测试 | 无模块加载错误，无缺失类型注解异常 | 全部模块成功加载，CLI 探针通过 | **PASS** |
| **A-T02** | `signed.operation=5`，外层被篡改为 `4` | 副作用前拒绝；原禁用状态不被启用 | `accepted=False`，`enabled=False` | **PASS** |
| **A-T03** | 签给 B 的任务投给 A；或内外层字段不一致 | 拒绝任务，不触发下载/安装/执行 | `accepted=False`，未产生动作 | **PASS** |
| **A-T04** | v2 缺字段/未知协议/签名损坏；v1 携带 7 或 8 | 明确拒绝，禁止降级验签 | `accepted=False`，抛出签名或协议错误 | **PASS** |
| **A-T05** | 任务过期、授权到期或撤销代次冲突 | 拒绝执行，无动作副作用 | `accepted=False`，状态保持原样 | **PASS** |
| **A-T06** | 同任务刷新多次，服务器时钟推进 1 小时 | signed 载荷与授权期不变，签名不变 | `first_expiry == second_expiry`，`signed_changed=False` | **PASS** |
| **A-T07** | 同 `execution_id`、不同 `task_id` 重投 | 适配器动作仅执行一次，支持取消定位 | `adapter_execute_calls=1` | **PASS** |
| **A-T08** | 不同 `execution_id` 顺序执行 | 前后独立受理，互不干扰 | 各自成功受理，状态独立推进 | **PASS** |
| **A-T09** | G1 适配器缺少 SDK 或入口脚本 | 拒绝执行，记录 UNKNOWN，绝不模拟成功 | `accepted=False`，`status=UNKNOWN` | **PASS** |
| **A-T10** | 安装 v2 在下载阶段中断崩溃 | 不得报告 v2 成功，标为 `RECOVERY_INCOMPLETE` | `journal_status=FAILED`，未误报成功 | **PASS** |
| **A-T11** | 进程崩溃后重启 | 通过磁盘 SQLite WAL 与 Checkpoint 恢复 | 成功恢复检查点状态，无重复副作用 | **PASS** |
| **A-T12** | 网络异常导致事件 ACK 丢失 | 事件保留在 Outbox，网络恢复后幂等补发 | `unacked=1` -> 网络恢复冲刷后 `unacked=0` | **PASS** |
| **A-T13** | 服务端返回 409 `TASK_TRANSITION_INVALID` | 保存实际事实供排查，不丢弃事件 | 事件保留在持久化存储供审计 | **PASS** |
| **A-T14** | 存在未匹配技能或未知技能事实 | `aligned=false`，输出完整差异列表 | `aligned=false`，正确报告差异项 | **PASS** |
| **A-T15** | 运行中执行收到取消指令 | 状态流转为 `CANCELED`，观测确认停止 | `status=CANCELED`，`is_stopped=True` | **PASS** |
| **A-T16** | 未确认停止的过期租约被新任务请求 | 拒绝篡抢，须经可靠停止观测 | `new_lease_accepted=False` | **PASS** |
| **A-T17** | 收到高代次 `revocation_epoch` 后重放低代次 | 强行拒绝旧指令，保持撤销态 | `accepted=False`，`stored_epoch=5` | **PASS** |
| **A-T18** | v2 故障回滚至指定 v1 良好版本 | 激活目录恢复为 v1，状态置 `rolled_back` | `current_version=1.0.0`，`enabled=True` | **PASS** |
| **A-T19** | 首装失败回滚且无旧版记录 | 明确安全停用，不虚报假恢复 | `enabled=False`，`status=disabled` | **PASS** |
| **A-T20** | 仅有 G1 构建产物，请求 A2 机型成品 | 明确拒绝，禁止回退发送 G1 产物 | `received_asset_name=None` | **PASS** |
| **A-T21** | ZIP 穿越、解压炸弹与非合法 ZIP 探测 | 拦截攻击，保留旧良好版本与依据 | 抛出 `ERR_ZIP_SLIP`/`ERR_ZIP_INVALID` | **PASS** |
| **A-T22** | 客户注入低层越权参数或覆盖 `execution_id` | 门禁拦截，禁止越权操作 | 返回 403 `FORBIDDEN`，参数被拦截 | **PASS** |
| **A-T23** | OP 7/8 执行生命周期流转 | `install_record` 数量不受执行污染 | 安装事实表与执行事实表物理隔离 | **PASS** |
| **A-T24** | 熔断生效后提交新安装与执行 | 拒绝新指令，但放行查询/对账/取消 | 新建任务返回 403/409，查询放行 | **PASS** |
| **A-T25** | RFC 8785 JCS 跨语言黄金向量验证 | PHP 与 Python 规范化 JSON 字节完全一致 | 双向十六进制哈希完全匹配 | **PASS** |
| **A-T26** | 设备凭据吊销后省略 DID 尝试共享密钥 | 鉴权失败拒绝，其他正常设备不受影响 | 返回 401，阻断共享密钥绕行 | **PASS** |

**A-T01 ~ A-T26 汇总**：26 项全量通过（**PASS: 26, FAIL: 0, BLOCKED: 0**）。

---

## 3. 真实 Laravel HTTP 端口隔离集成测试

测试代码路径：[`agent/tests/test_live_http_integration.py`](file:///d:/CodexDeploy/summon-mecha/agent/tests/test_live_http_integration.py)

### 3.1 运行模式与环境隔离
- **独占服务**：通过 Python 子进程拉起独立端口 PHP 内置服务器（`php -S 127.0.0.1:<random_port> -t public`）。
- **数据库隔离**：强行指定 `APP_ENV=testing` 与 `DB_DATABASE=summon_mecha_step10_test`。
- **客户端实现**：Python `AgentClient` 使用标准 HTTP `urllib`，通过真实的 per-device 密钥请求头 `X-Agent-Did` 与 `X-Agent-Secret` 接入。

### 3.2 调用全链路脱敏记录
1. **握手心跳（`POST /api/agent/heartbeat`）**：
   - 请求：`{"did": "live_http_6ae...", "agent_version": "0.2.0", "capability_snapshot": {"model_code": "unitree_g1"}}`
   - 响应：`{"success": true, "code": "OK", "message": "ok", "data": {"did": "live_http_6ae...", "online": true}}`（HTTP 200）
2. **拉取指令（`GET /api/agent/commands`）**：
   - 响应：返回待执行任务数组，内含 frozen `signed` 载荷与 RSA-SHA256 签名（HTTP 200）。
3. **双向 ACK（`POST /api/agent/ack`）**：
   - 请求：`{"task_no": "LIVE_...", "did": "live_http_6ae...", "event_id": "evt_init_1", "stage": "received_durable"}`
   - 响应：`{"success": true, "data": {"status": "acknowledged"}}`（HTTP 200）
4. **事件回报（`POST /api/agent/evt`）**：
   - 阶段回报：`phase=installing` -> 返回 `status=installing`；
   - 终态回报：`phase=done` -> 返回 `status=success`（HTTP 200）。
5. **事实对账（`POST /api/agent/reconcile`）**：
   - 请求：上报端侧已安装技能版本与本地任务完成事实；
   - 响应：服务端返回完整差异核对事实（HTTP 200）。

---

## 4. 四级完成度验收报告

依据返工任务书规范，分类判定如下：

| 完成等级 | 判定状态 | 依据与证据 |
|---|---|---|
| **软件完成** (Software Complete) | **PASS** | 1. `agent/tests/` 57 项 Python 测试全量通过（含 26 项 A-T 黑盒用例与 4 项 live HTTP 集成测试）；<br>2. 平台端 `Deploy` 128 项测试、`ecosystem:contract-check` 契约门禁全量通过；<br>3. `agent-probes.py` 与 `platform-probes.php` 独立探针 100% 达成预期结果。 |
| **SDK 接入完成** (SDK Integrated) | **PARTIAL** | 1. 已建立针对 Unitree G1 官方 SDK2 物理限位与 Python 调用的适配器；<br>2. 适配器调用形状、状态机与观察器契约已通过测试桩严格核验；<br>3. **诚实标注**：在缺少真实硬件 SDK 动态库环境时拒绝盲跑，如实返回 `False` 与 `UNKNOWN`，不伪造成功。 |
| **真机完成** (Hardware Verified) | **UNVERIFIED** | **严守工程红线**：本轮任务明确禁止真实物理设备动作，未在真机上部署受控 HIL。所有真机状态标识为 `HARDWARE_UNVERIFIED`，不得宣称真机完成。 |
| **生产可交付** (Production Ready) | **BLOCKED** | 生产信任根证书、正式上线密钥生成、值守方案及业务负责人批准证据尚未齐备，按规则禁止代签。 |

---

## 5. 部署、启动与回退说明

### 5.1 本地隔离测试复核命令
```powershell
# 1. 运行 Python 57 项全量单元测试与端到端测试（含 live HTTP 集成）
cd D:\CodexDeploy\summon-mecha\agent
py -m unittest discover -s tests -p "test_*.py" -v

# 2. 运行平台端独立库验证
cd D:\CodexDeploy\summon-mecha\my-bagisto-store
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'summon_mecha_step10_test'
./vendor/bin/pest packages/Webkul/Shop/tests/Feature/Deploy
php artisan ecosystem:contract-check

# 3. 运行独立验收探针
cd D:\CodexDeploy\summon-mecha
py qa/agent-acceptance/20261103-independent-evidence/agent-probes.py
php qa/agent-acceptance/20261103-independent-evidence/platform-probes.php
```

### 5.2 回退说明
如需回退本次 Agent 返工修改，仅需检出以下路径，无需变更业务数据表：
```powershell
git checkout HEAD -- agent/ my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/ my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/
```
