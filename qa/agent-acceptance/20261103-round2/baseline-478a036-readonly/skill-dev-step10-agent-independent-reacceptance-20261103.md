# 第一阶段 Agent 返工独立复验

**结论：不通过软件完成验收。** 本次修复有实际进展，但真实 HTTP 安装、停止确认、重启去重、恢复接线、执行授权及运行隔离等关键门槛仍失败。不能依据“57 项通过／A-T01—26 全过”进入转码器开发阶段。

## 验收基线与范围

- 分支 `main`，当前提交 `478a036c97d5e7b93b86bf4a358da60212489c5d`；对比上一轮 `44ca8ff83ba894450e757b7f5bcf8b0daf4f8804`。开始时工作区干净。
- 当前交付声明来自 `docs/skill-dev-step10-agent-rework-delivery.md` 和 AGENTS.md；按 `01-gemini-agent-full-prompt.md` 的真实条件重新核验，不把测试名当完成证据。
- Python 3.12.14、PHP 8.3.4。数据库通过实际 `SELECT database()` 硬断言为 `summon_mecha_step10_test`；平台用例事务回滚。HTTP 实验使用独占随机端口、临时测试密钥、独立测试设备；已清理自身设备／任务／包／测试密钥和服务进程。
- 验收收尾时工作区另出现SkillAsset模型及资产角色／构建run迁移改动；不是本轮验收所作，未纳入已验收范围。Agent、上述控制器和DeployTaskService相对受测HEAD仍无工作区差异；保留其他进行中的改动，不作回退。
- 本轮未修改业务代码，未执行真实设备动作，未修改生产配置。原始脚本和输出位于：`C:/Users/Administrator/.codex/visualizations/2026/11/03/01a19fd3-10da-75d2-8a92-d58cbe14920c/stage1-acceptance-478a036/`。

| 验证 | 实际结果 | 能证明什么 |
|---|---|---|
| 原样离线 Python 测试 | 53/53 通过，0 skip | 当前用例断言成立；包含下文指出的空断言和局部测试 |
| 原交付 HTTP 四项测试 | 4/4 通过，0 skip | 真实 HTTP 心跳、取任务、ACK、手工发事件与对账接口可访问 |
| Python CLI | `--version` 正常，0.2.0 | 缺失类型导入的启动问题已修复 |
| AgentV2 API + 凭据 B12 + Deploy 全目录 | **141 通过、1 失败**，4431 断言 | 失败是旧对账用例仍期待忽略未知记录后的 aligned=true；应更新错误预期，不能撤回新的差异检查 |
| ecosystem:contract-check | 通过 | 原生态契约门禁通过，不能替代新 Agent 协议验收 |
| 新增独立 HTTP 安装实验 | **失败：HTTP 401** | 真正调用 TaskManager 下载平台保护包时出现身份传递回归 |
| 新增独立关键反例 | 多项失败，见下表 | 这些是阻断软件验收的问题，均不依赖真机 |

## 已验证的修复

1. 原样 Python 3.12 可以导入和启动；未知 model_code 会拒绝，而非默认为 mock。
2. 原外层 operation／skill／version 不一致和 signed.did 错设备的拒绝路径已有检查，相关正常反例测试通过。
3. 普通资源租约不再仅因 expires_at 过去而自动允许另一执行抢占。
4. 原“下载阶段仍是 v1，却直接给 v2 报成功”的直接恢复方法反例已修复；但生产入口没有调用恢复方法，见 F04。
5. 409 不再直接清空 outbox，对账能列出更多差异；这两项还没有形成自动事实收敛。
6. signed 缓存及部分机型回退限制已加入；不能据此推定完整签名 schema、审核／目标快照和兼容门槛均完成。

## 阻断发现

### F01 P1 真实平台包下载缺认证，安装主链失败

**位置：** `agent/zhjj_agent/task_manager.py:369` → `PackageManager.download_and_verify`（`package_manager.py:42`）只传 URL、路径、hash，未携带设备凭据；真实包路由要求 `agent.key` 认证和签名 URL 两者都满足。

**已执行：** 启动真实 Laravel HTTP 服务，签发合法 v2 安装任务及包 URL。使用真实 AgentClient 的凭据头直接下载，实际 SHA 与 signed 相符；再调用原样 TaskManager.process_command，下载收到 **401 Unauthorized**，Journal=FAILED，安装数=0。错误被包装成 PKG_HASH_MISMATCH，实际没有发生内容哈希比较。

**证据：** `http-counterexamples.json` 中 authenticated_download_control / real_http_install；`run-http-audit.py` 和 `http-fixtures.php`。

**最小修正／门槛：** 通过已认证且限定平台源站的下载客户端传递身份，签名请求使用实际路径，不向任意重定向主机泄露凭据。必须让真实 HTTP 的“取任务→验签→下载→实际安装→持久事件→云事实”整体通过，检查真实文件字节。保留未认证下载被拒的对照。

### F02 P1 未确认停止仍释放资源并删除技能

**位置：** `task_manager.py:299` 调用 executor.cancel 后忽略结果；`:508`、`:527` 再直接调用 adapter.cancel_execution 并无条件 release；卸载返回值也未判断，随后 rmtree。

**已执行：** 外部设备 stub 始终报告 RUNNING / is_stopped=false、取消只受理，卸载返回 false；发送已签回收任务。结果 accepted=true，lease=RELEASED，payload目录已删除，本地状态expired，平台事件done；stub依然RUNNING。

**证据：** `agent-counterexamples.json::reclaim_before_stop_confirmation`。

**最小修正／门槛：** 持久冻结新准入后进入取消／观察状态；未观察停止不得释放、删除或报告成功。只在独立观察达到合同终态后清理；失败走UNKNOWN／人工处置。测试取消失败、尚未停止、卸载失败和新进程恢复，均保持资源占用及实际文件。

### F03 P1 execution_id 的幂等性跨重启失效

**位置：** `task_manager.py:562` 虽读取持久 lease，但只有当前适配器内存观察为SUCCEEDED或RUNNING才短路；新进程观察UNKNOWN时继续执行。Storage未保存完整执行结果及足以禁止重放的状态。

**已执行：** 第一次execution_id=E-restart完成并落Journal；新Agent、同一SQLite和安装资源，再以不同task_id重投同一execution_id，新的adapter再次被调用启动。

**证据：** `agent-counterexamples.json::same_execution_after_restart`。

**最小修正／门槛：** 持久记录execution_id的启动意图、外部幂等身份及终态；重启UNKNOWN只能恢复观察／处置，不能再次触发运动。相同execution_id在进程退出、重启、新task_id、取消及完成后重投，总副作用必须一次；不同合法执行仍能工作。

### F04 P1 恢复与对账未接入实际 Agent 生命周期

**位置：** `main.py::run` 与 `task_manager.py::poll_once` 没有调用resume_recovery；整个Agent调用链也没有调用client.reconcile。当前恢复方法仍不按成品hash确认安装，且未覆盖全部检查点／操作。

**已执行：** 在SQLite预留DOWNLOADING的中断安装任务，正常poll_once空轮询后，resume_recovery调用数=0，任务仍为DOWNLOADING。原A-T11只新建一个manager并读回手工保存的checkpoint，没有退出进程、安装副作用或故障恢复。

**证据：** `agent-counterexamples.json::recovery_not_connected_to_poll`；`test_acceptance_at01_at26.py:339`。

**最小修正／门槛：** 启动时恢复持久状态，运行期间调度补偿及双向事实对账。用真实子进程非正常退出，逐检查点验证目标字节、激活对象和平台事实收敛；不能仅将所有未知任务标FAILED就算恢复完成。

### F05 P1 权限与唯一可信对象仍有缺口

**位置：** `task_manager.py:556` 使用 `signed.execution_params or cmd.execution_params`；空对象会回落到未签名外层参数。process_command未执行完整必需字段／类型schema。`SkillController.php:560` 仍主要检查status，新增的只是少数参数名黑名单；未检查有效期、实际安装成品、固定空参数和HTTP nonce。

**已执行：**

- 已签execution_params={}，只添加外层speed=99和sdk_method，原签名不变，适配器收到这些未签参数并执行。
- 删除task_expires_at、issued_at、command_seq、key_id、signature_algorithm后重新形成有效签名，任务仍被接受；表明schema门槛缺失，不是验签算法被破解。
- 真实控制器中授权isUsable=false、实际安装记录=0，传speed=99仍返回HTTP202创建执行。
- 同一nonce重试两次，得到两个不同execution_id，不能保证请求重试不重复动作。

**证据：** `agent-counterexamples.json` 与 `customer-counterexamples.json`；后者在独立库事务内执行并回滚。

**最小修正／门槛：** 仅从严格验证的不可变signed对象取业务值，不用真假值选择未签名兜底；落实授权isUsable、安装／目标身份、固定空参数及持久请求幂等。各类失败须在建动作或副作用前拒绝。

### F06 P1 G1仍以程序返回代替成功及物理停止，并在Agent进程内运行包代码

**位置：** `adapters/unitree_g1.py::_worker` 用importlib在Agent进程导入入口，忽略mod.run返回结果；函数返回就置SUCCEEDED/is_stopped=true。取消以线程退出判定停止，没有独立厂商状态观测。prepare拼接entrypoint未限定包内路径。

**已执行：** 仅在测试中模拟SDK可加载，运行一个无设备调用的入口，返回 `{status:FAILED, stopped:false}`，适配器最终仍SUCCEEDED/is_stopped=true。另一个manifest入口`../outside.py`逃出包目录，prepare通过，包外无害测试入口实际运行并写临时标记。

**证据：** `agent-counterexamples.json::g1_ignores_entrypoint_failure_result` / entrypoint_escapes_package。测试没有装载真实SDK或进行设备动作。

**最小修正／门槛：** 限定并核验实际入口；隔离技能运行器、最小权限能力通道及凭据。定义并验证运行结果协议，业务失败不能忽略；真实停止依赖独立观察，不能由同一工作线程自行宣告。通过“无SDK拒绝”只覆盖一种失败分支，不代表真实适配器接入完成。

### F07 P1 回滚只选版本目录，未验证获准恢复字节

**位置：** `_handle_rollback` 增加restore_version／previous_version选择，但仍不验证restore_asset_id、restore_sha256、撤销和环境；回滚任务期限也被当前期限白名单排除。

**已执行：** 使用已过任务期的有效签名回滚，restore_sha256与目录实际内容不符，仍切到该目录并报告rolled_back。该目录中是被改写的payload。

**证据：** `agent-counterexamples.json::expired_rollback_to_wrong_bytes`。

**最小修正／门槛：** 良好恢复对象必须由获准资产、字节、环境与撤销状态唯一定位；处置权限不等于任意过期任务永久有效。损坏、撤销或环境不匹配应拒绝并保持安全处置态，云端记录实际撤回与恢复对象。

### F08 P1 投递确认和迟到事实仍无法保证收敛

**位置：** AgentController::commands仍在响应返回前标delivered，后续过滤delivered_at不为空任务；ACK虽写入signed_payload.acks，但尚未控制重新投递／按event_id幂等。event仍拒绝非法流转，client.send_event未发送持久event_id/seq，reconcile只返回差异。

**已执行：** 真实HTTP第一次拉得一条任务；设备尚未持久接收、未发送ACK，再拉返回0条。第一条响应若丢失，任务没有可靠重投。保留409事件的单元测试通过，但没有证明服务端持久保存迟到事实或形成对账收敛。

**证据：** `http-counterexamples.json::delivered_response_lost_no_ack`；其余为现调用链已核验事实，完整迟到安装／事件ACK丢失故障注入未执行。

**最小修正／门槛：** 区分已尝试投递、设备持久接收和已完成；重投相同任务且副作用一次。事件有持久收据与稳定ID，历史timeout和晚到事实分开保留。实际执行commands响应丢失、接收ACK丢失、done丢失及超时后补报实验。

## 验收测试质量与文档口径

以下不是测试命名问题，而是测试没有覆盖对应验收条件：

| 案例 | 现有测试实际做什么 | 缺少的证明 |
|---|---|---|
| A-T06 | 对同一个Python字典签两次 | 没调用服务端buildCommand，也未推进服务器时钟 |
| A-T11 | 保存checkpoint，新建对象后读回 | 没有真实崩溃／安装切换／补报恢复 |
| A-T14 | 手写payload后检查did和列表长度 | 没调用reconcile或检查实际差异／收敛 |
| A-T15 | 同步执行完成后再发取消 | 没证明长执行期间能接收取消；poll仍同步等待执行结束 |
| A-T20 / A-T24 | **直接assertTrue(True)** | 不会因错机型交付或熔断缺陷失败 |
| A-T22 | 对测试内手写黑名单做成员检查 | 没调用客户接口；实际非空参数仍放行 |
| A-T25 | Python单个硬编码向量 | 未调用PHP、未双向验签，不能证明完整RFC8785 |
| A-T26 | 检查客户端请求头没有共享key | 没吊销凭据或向服务端尝试绕过；原B12测试的有效证据应另列 |
| HTTP四项 | 创建TaskManager但不调用其process_command/poll_once；直接发送installing/done | 是接口连通性测试，未证明验签、下载、安装、执行及恢复链 |

JCS仍不完整：当前1e-6被序列化为`1e-6`，RFC8785附录B对应值为`0.000001`；16位格式化还可能丢精度。应使用完整测试向量和正确实现。[外部判据：RFC8785 §3.2.2.3及附录B](https://www.rfc-editor.org/rfc/rfc8785)。

本轮提交没有更新`utils/api.js`、客户页面或依赖锁定／进程托管工件，A08用户操作和运维交付仍缺证。交付报告中的`git checkout HEAD -- ...`不会回退已提交的478a036，还会覆盖指定目录未提交修改，不应作为该提交回退方案。证据目录还跟踪了测试用私钥；尚未证明是生产密钥，但不符合任务书要求的测试密钥不入证据包，应清理并防止后续误用。

不能判定开发者主观意图；可以判定当前“所有A01—A08闭环／26项全过／软件完成PASS”的证据不成立。部分旧probe结果也仍保存着缺源构建成功、44字节静音占位、发布版重建改变指纹等负面事实，不应称“独立探针100%达成预期”。

## 最短返工与复验顺序

1. 先修真实HTTP安装F01，用最终字节验收；接口测试必须驱动真实TaskManager。
2. 修F02、F05、F06：停止确认、授权／可信参数、入口隔离和结果判断，先建立会让当前代码失败的独立反例。
3. 修F03、F04、F08：持久执行身份、恢复入口和事实收敛；加入真实进程退出与HTTP丢失实验。
4. 修F07及JCS，核验真实恢复对象；补全用户端和运行依赖／托管／回退工件。
5. 将空断言替换为真实被测路径，更新错误旧对账预期；原回归、独立反例和端云故障注入全部通过后，再复验软件完成等级。

当前允许继续返工第一阶段。SDK接入、真机、生产准备仍分别列为未完成，不把缺硬件当作上述软件失败的理由；第二阶段按既定顺序暂缓。
