# Gemini 第一阶段 Agent 第二轮完整返工提示词

版本：2026-11-03 第二轮；最新复验基线478a036。**本轮只修第一阶段，不推进第二阶段。**

# 第一阶段第二轮返工 本次复验问题与强制关闭门槛

本文是第一阶段完整任务书的新增执行要求。本轮只返工Agent，不执行第二阶段任务，不开发编译器、动作转码或Profile扩展。第一阶段通过后也必须停止，等待用户后续任务；不得自动开始第二阶段。

## 0 当前事实和使用顺序

1. 本次独立复验针对 `main` / `478a036c97d5e7b93b86bf4a358da60212489c5d`，结论为**软件验收不通过**。更早的 `44ca8ff` 只作历史比较。接手时核对实际HEAD和未提交变更，保存源码指纹；已修复事项保留回归，不重复制造失败。
2. 最新报告是 `docs/skill-dev-step10-agent-independent-reacceptance-20261103.md`，全文及原始证据随文附上。AGENTS／旧交付报告中的“已闭环、26项全过、软件PASS”是已被复验否定的完成声明，不能作为本轮准入依据；D1—D11和业务边界继续有效。
3. 本次曾复跑Python 53项离线+4项HTTP均通过，但独立反例失败；平台回归141通过、1失败。失败旧用例错误地期待忽略未知安装记录后aligned=true，应修测试预期并保留差异门禁，不得倒退实现来让旧测试通过。
4. 仓库可能有其他进行中的第二阶段资产模型、迁移和编译器改动。只记录并保留，不提交、不回退、不执行这些第二阶段迁移，也不据此宣称第一阶段问题已修复。需要本阶段最小数据持久化时单独划清文件和迁移范围，不能顺手接着完成C工作包。
5. 所有数据库写测试先由实际连接执行 `SELECT database()` 并断言等于 `summon_mecha_step10_test`。禁用会覆盖测试环境的旧配置缓存。独占端口、临时测试密钥、受控测试数据必须由本次运行建立并清理；不使用生产密钥、真机或现有8000服务。
6. 本补充与原A01—A08、A-T01—26共同生效。发生事实口径冲突时，以本补充和本次复验原始证据为准；不得用旧测试数量或文字完成状态覆盖失败反例。

## 1 开始工作前先建立真正会失败的验收

先为下面F01—F08及JCS／交付缺口建立独立回归，写清固定输入、外部世界状态、真实被测入口、可观察副作用和判定条件，然后实施修复。现基线尚有缺陷时必须看到这些门槛失败；若新HEAD已修复，提供当前通过和与旧基线的差异依据，不回退用户工作区。

原始探针脚本的退出码0仅表示成功采集输出，**不表示验收通过**。例如JSON中 `real_http_install.accepted=false`、`lease=RELEASED`且仍RUNNING、`outside_entry_executed=true`都是失败证据。必须编写语义断言／验证器据下列门槛给出非零失败退出码，不能只运行脚本看它没报错就写PASS。

原证据放在独立只读目录，修后证据放新run目录；不得覆盖旧JSON、旧日志或删除历史报告来消除差异。

## 2 F01 真实平台包下载缺设备认证

**修改位置：**

- `agent/zhjj_agent/task_manager.py::_handle_install`（复验位置约369行）。
- `agent/zhjj_agent/package_manager.py::download_and_verify / download_package`。
- `agent/zhjj_agent/client.py::_build_auth_headers`及必要下载入口。
- 对照 `Shop/src/Routes/api.php` 的Agent包路由、`AgentPackageController`和`VerifyAgentKey`，不能取消它们的认证要求。

**现有反例：** 同一真实Laravel签名URL，带合法设备凭据直接下载可得到正确hash；TaskManager下载缺认证头，收到401，错误又被包装成PKG_HASH_MISMATCH。证据 `http-counterexamples.json` 的两个下载分支及 `run-http-audit.py`。

**必须修复：** 设备认证由受控平台客户端传入真实下载请求，私钥签名使用实际method/path；限制允许源站与重定向，不能把设备secret发给任意URL主机。保留流式限量、摘要比对和解包保护，区分认证失败、网络失败与真实内容摘要错误。

**通过门槛 RF01：**

1. 启动真实Laravel独占测试端口，从测试库构造合法完整成品，由服务端构造／签名真实安装任务。
2. 真实AgentClient取任务，由真实TaskManager或CLI处理；不得把下载、验签、安装或网络请求替换成返回成功的mock，不得从file://或手写本地ZIP路径替代平台取包。
3. 无凭据／错设备凭据下载明确拒绝，安装记录不增加；正确凭据安装成功，磁盘实际payload SHA、Journal、Outbox收据、平台install_record与任务所签对象一致。
4. 注入401／403时报告认证错误；注入字节改写时报告摘要错误。不能对所有下载错误返回同一哈希错误。
5. 重定向到另一个受控测试源站时，外站记录中没有平台设备凭据；拒绝策略或重新授权策略有测试证据。

## 3 F02 尚未停止就释放租约和删除技能

**修改位置：** `TaskManager.process_command`的处置分支（约299行）、`_handle_toggle`、`_handle_uninstall_or_reclaim`（约508/527行）、`SkillExecutor.cancel/observe`、Storage租约及撤销持久化、适配器disable/uninstall契约。

**现有反例：** 外部stub一直RUNNING／is_stopped=false，cancel仅受理，uninstall返回false；当前代码仍释放lease、删除payload、记录expired并上报done。证据 `agent-counterexamples.json::reclaim_before_stop_confirmation`。

**必须修复：** 先持久冻结新准入与撤销代次，再取消、独立观察、确认停止，最后清理。cancel返回值、卸载返回值和观察结果必须影响状态机。缺确认不得走任何无条件release或rmtree捷径；禁用、卸载、回收三条路径全部检查，不仅修复回收。

**通过门槛 RF02：**

- 使用独立设备stub，分别注入取消拒绝、取消受理但持续RUNNING、观察UNKNOWN、卸载失败。
- 未确认停止时允许“请求已受理／待处置”，但不得出现终态成功／已回收／已卸载；lease保持占用，payload保留，新冲突执行拒绝。保存外部观察、实际文件存在性与DB状态三份证据。
- 在待停止阶段强制退出Agent并重启，锁与冻结状态仍有效，旧任务不能重新启用。
- stub随后提供符合合同的停止结果，才可以清理；卸载失败不能报成功，应留下可重试／人工处置事实。成功清理不得影响另一技能或另一执行。
- 平台发来的长执行期间取消／回收必须能被接收，不能先同步等30秒执行结束再发取消。用日志时间线证明新命令是在外部执行仍RUNNING时被处理。

## 4 F03 同一execution_id跨重启再次启动

**修改位置：** `TaskManager::_handle_execute`（约562行）、Storage执行／租约持久化、SkillExecutor执行准入、平台SkillExecution及必要唯一约束。

**现有反例：** 同一SQLite中第一次执行已COMMITTED，换一个Agent进程／适配器后重投同execution_id不同task_id，UNKNOWN观察导致再次进入execute_skill。证据 `same_execution_after_restart`。

**必须修复：** 持久化execution_id的启动意图、外部幂等身份、关联成品与终态；租约是资源占用，不能代替执行事实。观察UNKNOWN只能恢复观察／安全处置，不重新启动已有副作用。

**通过门槛 RF03：**

1. 外部stub置于Agent进程之外，独立持久记录启动次数；不是每次Agent重启就清零的Mock.call_count。
2. 覆盖发起前、外部启动后回执前、执行中、完成已落盘、完成事件未ACK等边界的真实子进程退出与重启。
3. 同execution_id以同task_id／新task_id重投，跨进程累计外部启动次数始终等于1；UNKNOWN保留资源及待处置结果，不伪装失败后自动重试动作。
4. 同execution_id不同内容／不同成品请求被拒；取消仍能定位原执行；不同合法execution_id的下一次执行可正常发生。

## 5 F04 恢复和对账没有接入正常入口

**修改位置：** `agent/zhjj_agent/main.py::run`、`TaskManager::poll_once / resume_recovery`、Storage检查点与出站队列、`AgentClient.reconcile`及平台实际对账规则。

**现有反例：** 预置DOWNLOADING任务，正常poll_once后恢复调用数为0，任务仍DOWNLOADING；原A-T11只新建对象读取手工checkpoint。证据 `recovery_not_connected_to_poll`。

**必须修复：** 把恢复接到真实启动及后续补偿调度，按操作／检查点比较实际成品hash、激活对象、授权和撤销事实；接入双向对账与必要收敛。不要每轮无条件重放副作用，也不要把未知任务全标FAILED就当恢复完成。明确Agent COMMITTED与平台success等状态的等价映射，不用不同字符串制造永远不一致。

**通过门槛 RF04：**

- 从真实CLI的正常启动／--once路径恢复，不允许测试单独调用resume_recovery冒充接线完成。
- 通过真实子进程非正常退出，至少覆盖Journal接收、外部安装前后、目录切换、安装事实提交、Outbox写入和服务端事件提交边界。
- 安装v2时磁盘仍为v1不能报v2成功；同版本号但hash不同不能误报；真实已激活目标但回报丢失能按事实恢复。
- 正常运行会实际调用平台对账，端侧单边、云端单边、状态冲突、未知任务和等价终态都有明确结果。必须证明最终一致或明确保留UNKNOWN／人工处置，不以“接口返回details”作为收敛完成。
- 任何恢复不得绕过已发生的停用／回收、过期任务和新的撤销代次。

## 6 F05 未签参数 有效期 安装身份及请求幂等

**修改位置：** `TaskManager::process_command / _handle_execute / _handle_install`（参数位置约556行）、PHP/Python的任务schema及SigningService、`SkillController::triggerExecution / cancelExecution`、SkillExecution和DeployTaskService的创建事务／幂等约束。

**现有反例：** signed.execution_params={}却从外层读取speed/sdk_method；删除多个必需签名字段仍受理；授权isUsable=false、没有安装记录仍返回202；同nonce生成两个execution_id。证据 `agent-counterexamples.json` 和 `customer-counterexamples.json`。

**必须修复：**

- 按逐操作schema验证唯一不可变signed对象；明确必需字段、严格类型、未知字段、允许null的位置及协议版本。禁止缺字段默认安装、把任意字符串强制转int后视为合法。
- 已签空对象是明确的空参数，不得用`or`回落cmd。原portable-actions/v1固定参数契约要求空对象；不能仅拦六个参数名就放开其他参数。
- 只允许当前客户有有效授权、当前binding归属正确、实际安装并启用且与目标成品匹配的执行。
- 持久合并同一客户／设备／请求范围的nonce重试；同nonce不同内容明确冲突，不得再次执行。不同nonce产生独立的下一次合法执行。创建执行和任务保持事务一致。

**通过门槛 RF05：**

1. signed空参数时添加外层speed=99/sdk_method，原签名保持不变：副作用前拒绝，外部stub收不到参数／动作。
2. 逐个删除或改错task_expires_at、issued_at、command_seq、key_id、signature_algorithm和逐操作必需身份；有效签名也不能补救非法schema。v2失败不能回退v1；v1不能接7/8。
3. 通过真实客户HTTP路由，分别测试授权过期、撤销、设备转移、未装、禁用、装错成品、非空params；都不得建有效动作任务或产生动作。合法空参数请求正常受理。
4. 同nonce串行／并发／网络重试返回同一execution_id与同一任务；数据库约束及事务避免两进程重复建单。同nonce内容变化返回冲突；不同nonce不被误吞。
5. 事实补报及新签发且有作用域的处置任务，不被普通使用授权到期阻断；它们仍检查自己的有效任务期限、设备身份和签名。

## 7 F06 G1忽略结果 包内入口越界及运行隔离

**修改位置：** `agent/zhjj_agent/adapters/unitree_g1.py::prepare / execute_skill / _worker / cancel_execution / observe_execution`、PackageManager入口验证、SkillExecutor及Agent到受限运行器／能力通道的边界。

**现有反例：** 入口返回FAILED/stopped=false仍被写成SUCCEEDED/is_stopped=true；entrypoint=../outside.py通过prepare并执行包外无害脚本；代码在Agent进程内import包代码。证据 `g1_ignores_entrypoint_failure_result` / `entrypoint_escapes_package`。

**必须修复：**

- prepare和执行入口均校验真实规范化路径位于获准包根目录，阻断绝对路径、../、symlink及平台等价路径变体，不能只信解压时Zip Slip检查。
- 不在拥有Agent凭据的同一解释器进程内直接import任意技能代码。运行器需独立身份／受限访问，只有白名单能力IPC，不能读取设备secret、私钥、配置或宿主敏感路径。
- 定义运行结果协议，失败／取消／UNKNOWN不能被吞；线程或子进程退出不能代替实体停止。成功与停止分别来自正确结果和独立目标观察。
- 缺SDK、缺权限、无独立观察或宿主隔离不可用时明确unsupported／UNKNOWN，不回落模拟成功。测试注入sdk_stub或模拟SDK可加载，只能证明软件分支，不能标SDK或真机完成。

**通过门槛 RF06：**

1. 入口返回FAILED、抛异常、缺run、返回非法结果、超时和取消未停止，均不得出现SUCCEEDED；观察未停止时资源不得释放。
2. 以../outside.py、绝对路径、symlink测试prepare和绕过prepare的执行调用，包外入口均未运行；用临时无害marker证明确实没有副作用。
3. 在Agent目录放仅用于实验的凭据canary，技能进程尝试读取；由实际OS／容器权限拒绝。只删环境变量或另起同权限进程不算隔离证明。测试日志不包含canary值。
4. 以独立外部stub区分“入口已返回”与“设备仍RUNNING”，直到独立停止观测成立前保持UNKNOWN／资源占用；明确支持机型的观察依据。
5. 无SDK的真实G1路径继续拒绝；MockAdapter只在显式测试模式使用。不得通过`_sdk_available=True`写进生产代码、Mock()假返回或sleep后置成功来通过。

## 8 F07 恢复目标未验证字节且过期任务仍可回滚

**修改位置：** `TaskManager::_handle_rollback`、GrayReleaseService回滚调用、DeployTaskService任务构造／recordInstall、目标成品与安装身份查询。

**现有反例：** 已过任务期的回滚、restore_sha256与真实旧目录不符仍切换并上报rolled_back。证据 `expired_rollback_to_wrong_bytes`。

**必须修复：** 撤回坏对象和恢复良好对象分开。根据明确获准的restore_asset_id/sha及适用环境定位实际对象，验证完整字节、撤销状态和恢复授权；目录名或previous_version只可作为线索，不能作为授权。处置豁免普通使用期不等于豁免任务本身有效期。

**通过门槛 RF07：**

- 对过期任务、错hash、文件被篡改、已撤销恢复对象、错环境逐项拒绝；没有激活错误对象，没有假rolled_back。
- 有效恢复任务把v2坏对象恢复到明确v1良好字节，独立观察实际路径／资源／hash，云端同时记录撤回对象和实际恢复对象。
- 首装无旧版走安全停用／卸载，不声称恢复；停止未确认时沿用RF02保持占用规则。
- 版本字符串相同但资产hash不同不能互换；不得为保持旧测试通过而新增未规范签名的restore_version兜底。

## 9 F08 投递ACK 事件收据及迟到事实没有收敛

**修改位置：** `AgentController::commands / ack / event / reconcile`、DeployTaskService::markDelivered / onEvent及超时逻辑、AgentClient::send_ack / send_event / reconcile、TaskManager::emit_event / flush_outbox、Storage事件和任务持久化。

**现有反例：** 真实HTTP第一次取到1条，但设备尚未持久接收且未ACK，再取为0条；接收ACK未驱动投递状态，事件未带稳定event_id/seq，409仅保留在outbox而未证明服务端存事实／对账。证据 `delivered_response_lost_no_ack` 与调用链。

**必须修复：**

- 区分投递尝试、设备持久接收、执行完成；没有收到持久接收确认时可在限定规则内重投同一不可变任务，不能换task_id或延长授权。
- 接收ACK有持久记录与指令摘要绑定，同event_id重发幂等；不同内容不能复用同收据。不把无限增长的ACK数组当可靠状态机。
- 事件携带稳定event_id/seq，服务器持久接收后才确认；端侧凭真实收据确认Outbox。重复／乱序事件按规则处理，不重复副作用。
- timeout历史保留，晚到设备事实另存并进入收敛／人工处置；非法历史流转不等于可以直接丢弃事实，也不等于强行改历史success。

**通过门槛 RF08：**

1. 真实HTTP故障代理丢弃commands响应，设备没有落Journal和ACK；随后能取得同task_id、同signed、同成品，并最终执行一次。
2. 设备已落Journal但接收ACK请求或响应丢失，重投不产生第二次副作用；服务端能证明持久接收。
3. done请求丢失、done响应丢失、重复event_id、事件乱序均最终收敛或明确待处置；服务端持久收据可查询，Outbox不会靠“HTTP没报错”误清。
4. 平台先timeout，设备后确认真实安装：保留原timeout，另存实际安装资产／hash与时间，再对账决定状态；不能永远409重试，也不能篡改原历史。
5. 每例同时保存HTTP请求响应（脱敏）、端SQLite、外部实际资源／payload与平台记录；只查看`details`字段存在不算通过。

## 10 把现有表面测试替换成实际验收

保留原A-T01—26编号与需求，逐项核对，不允许用同名局部测试替代完整目标：

| 必须改的测试 | 必须调用的真实路径与观察 |
|---|---|
| A-T06 | PHP DeployTaskService::buildCommand，服务器时钟推进一小时，再查真实DB冻结对象并由Python验签 |
| A-T11 | 真子进程非正常退出与重启、实际安装切换、Journal和Outbox；非新建对象读checkpoint |
| A-T14 | 真实reconcile HTTP，双边／单边／未知差异和最终收敛；非手写字典断言 |
| A-T15 | 外部stub仍RUNNING时，平台发取消且Agent实际处理；非执行完成后再改CANCELED |
| A-T20 | 真实resolver/交付入口建G1成品，向A2／未知目标请求并断言拒绝，同时保留合法目标对照 |
| A-T22 | 真客户路由、有效期、归属、固定空参数、nonce并发，核对任务表及副作用 |
| A-T24 | 实际关闭新构建／新安装门禁，拒绝新增；真实查询、回报、对账、合法取消／回收继续可达 |
| A-T25 | PHP与Python双向规范化／签名向量，完整RFC8785数值和对象边界；至少验证1e-6应为0.000001，不能自行%.16g |
| A-T26 | 真服务端吊销设备凭据，再省略DID／换共享key／用旧secret和旧签名攻击各端点；正常设备对照 |
| 原HTTP四项 | 真实Agent/TaskManager完成验签、下载、安装、事件及对账；安装／执行done不能由测试直接伪发 |

**禁止以下过关方式：**

- `assertTrue(True)`、固定常量比较、只验证测试自造字典、只测helper不接业务入口。
- mock验签／权限／下载／DB／TaskManager／运行器本身，把固定成功当业务结果；mock允许只替代明确的外部设备，并须检验调用和独立状态。
- skip、xfail、吞异常、改为宽松断言、只统计数量、删除失败案例，或把NOT_RUN/BLOCKED计入PASS。
- 隐式启用MockAdapter、注入空Mock()后宣称SDK接入、通过计时器或线程退出推断实际动作完成／停止。
- 改旧证据输出、改报告结论、删反例或把源码副本偷偷改完当原样测试通过。

测试有效性抽查：在隔离源码副本或明确测试注入点分别模拟“认证头未传、未停就release、恢复调度不调用、unsigned参数兜底、入口失败被改成功”。对应回归必须失败。不要在用户业务工作区制造这些缺陷。证明测试能抓故障，而不只是修后全绿。

## 11 执行顺序 交付和停止条件

依次：保存基线／原始反例 → RF01真实安装 → RF02/RF05/RF06安全与授权 → RF03/RF04/RF08持久恢复 → RF07和JCS → 原回归／三端与运维 → 独立复验。可并行的工程工作自行安排，但同一测试库不并行运行有冲突的套件，不创建额外无关功能。

同步完成原A08缺少的`utils/api.js`和现有客户页面必要入口、状态、错误／UNKNOWN解释；锁定依赖，提供实际存在的启动／托管／升级／恢复命令。`git checkout HEAD -- ...`不能回退已提交修复且会覆盖未提交工作，不再作为交付回退命令；采用明确前后版本／按路径可审阅的回退方案。

最终交付 `docs/skill-dev-step10-agent-rework-round2-delivery.md`，至少提供：

1. F01—F08与RF01—RF08逐条映射：修改文件／符号、预期、实际、命令、退出码和证据路径。
2. 原A-T01—26逐条真实需求映射，禁止只粘测试函数名。完整RF门槛与A门槛共同满足才可关闭。
3. 修复前后的原始反例对照；固定旧证据只读，修后结果另存；验证器将语义失败转为非零退出码。
4. 真实HTTP和子进程退出实验：请求／收据／SQLite／外部观察／平台事实能由独立人员核对。
5. 更新后的协议schema、执行／处置／恢复状态机、JCS双向向量、并发唯一约束和legacy兼容证据。
6. 原53+4以及平台回归全部原样复跑；旧对账错误预期按事实修正，不能退回忽略未知记录的实现。必需测试为FAIL/NOT_RUN/BLOCKED时不得总体PASS。
7. 测试密钥不进Git或证据包，不输出真实凭据；清理本任务引入的测试用私钥工件时只按确切路径，不猜它是生产钥匙或擅自轮换生产信任根。
8. 修正文档中的过时完成声明，保留历史报告；区分软件完成、SDK接入、真机和生产四级。仅软件全部通过时可写“软件门槛已通过，待独立复验”；本轮禁止真机动作，不得写真机完成。

提交前检查工作区与差异，禁止`git add -A`、`reset --hard`、未经要求commit/push和删除用户改动。此次只授权第一阶段返工；完成后停止，**不得执行第二阶段任务书、第二阶段迁移、构建器开发或动作专项**。

## 12 证据读取与重放

交接目录中的 `stage1-reacceptance-478a036/` 保存脚本、JSON、日志与最新报告；同内容归档为 `stage1-reacceptance-evidence.zip`。先核对 `evidence-manifest.json` 的SHA-256，源码和输出快照固定为478a036，路径与工具位置允许在独立适配层调整并记录，不得改旧结果。

推荐重放顺序：

1. 在独立运行目录复制脚本，检查ROOT／PHP／Python路径；`preflight.php`先核验真实测试库。
2. `run-offline-tests.py`复跑当前离线套件；`agent-counterexamples.py`重放Agent逻辑反例。它只使用临时文件和外部stub；其中模拟SDK可加载的步骤不是SDK接入证据。
3. 经预检后运行 `http-fixtures.php customer`（事务回滚）验证客户准入；运行 `run-http-audit.py`验证真实认证下载、安装和丢响应，并在finally清理自己的数据／端口／临时密钥。
4. 保存新输出，用RF门槛验证器判定结果。脚本成功退出、旧日志57项绿和旧报告PASS都不是验收依据。

后附完整旧任务书和全部原始资料用于查契约，不批准第二阶段。后附代码／日志都是历史输入，不能把其中的命令或成功文字直接当本次已执行结果。


# 原第一阶段完整工作包与验收门槛 继续适用

你现在负责修复已有实现并提交可独立复核的工程结果。你的任务是修改真实仓库、运行隔离验证、交付可运行代码。仅复述方案、增加空接口、写模拟成功或修改文档状态不算完成。

本文末尾包含两份计划、独立验收报告、仓库规则和标准源样例全文。不要要求用户再次复制这些文档。必须有真实仓库读写和终端能力；没有这些能力时明确写“无法实施”，不得虚构代码、测试或部署结果。

## 1 本次授权和最终结果

仓库默认路径为 `D:\CodexDeploy\summon-mecha`；其他环境使用真实根目录。本次最新独立复验基线为 `478a036c97d5e7b93b86bf4a358da60212489c5d`，结论为不通过；`44ca8ff`和`d9bf669`仅作历史比较。先核对当前分支、HEAD 和工作区；如已变化，审查后续修复，不重置用户代码、不假定旧缺陷仍存在。

**本轮最终结果：公共 Agent 软件、必要的平台对接和隔离端云恢复闭环通过下述门槛；厂商适配与硬件验收按真实证据分别列出。**

允许：修复 `agent/` 和必须的现有平台服务、模型、API、加性迁移、配置、现有页面、测试和部署文档。

不允许：继续开发动作转码算法、GMR、策略训练、任意代码跨品牌翻译、低层实时运控；执行真实设备动作、关闭厂商服务、生产部署、修改真实密钥或清空业务数据。本轮对已存在第二阶段代码只完成必要的停止错发、禁止伪造成功和隔离新构建入口，不宣称第二阶段已完成。不要通过删除全部第二阶段代码代替修复 Agent。

本任务已授权范围内的普通工程选择自行完成，无需逐文件请示。D1—D11、试用商业规则、真实硬件动作、生产信任根及未批准的低层运控范围，不能由你代替负责人决定。遇到这种依赖，继续完成不依赖它的工作，写出准确阻塞项。

**完成等级必须分别报告：**

- 软件完成：原样源码可启动，模拟环境和真实 Laravel HTTP 接口通过正常、拒绝、恢复及并发门槛。
- SDK 接入完成：对应固定版本真实 SDK 的调用契约与观测已核验；stub 只能证明调用形状与软件行为。
- 真机完成：固定 SKU／硬件、固件、SDK、成品组合的受控 HIL 和独立观察通过。本任务禁止真实动作，所以不得把该项标完成。
- 生产可交付：还需部署、信任根、值守及批准证据。本任务不得代签。

即使软件完成，也必须如实保留未完成的 SDK／硬件／生产门槛。“没有设备”不能成为不做公共软件的借口，也不能成为模拟成功的理由。

## 2 先执行的操作

1. 读取随文及仓库中的 AGENTS、CLAUDE、HANDOFF、项目 brief；核对本目录补充规则。
2. 保存 `git status --short`、分支、HEAD、运行时版本和受测源码 SHA-256。不要读取或输出完整 `.env`、生产令牌或私钥。
3. 原样复跑 Python 测试，区分源代码错误与环境权限／依赖缺失。早期的Tuple／Any缺失在478a036已修复，保留原样启动回归；不要重新制造该缺陷，不能仅在测试里注入builtins或注解补丁。
4. 核实 DB 实际连接为 `summon_mecha_step10_test` 后才能做写入测试。不得仅看环境变量；执行只读 `SELECT database()` 并断言。缓存配置不能绕过这个断言。
5. 将下面每个验收案例建立为自动化回归。优先在修复前记录预期拒绝／恢复案例为何失败，然后修复至通过。已经修复的案例保留通过证据，不制造失败。
6. 开始时只输出不超过八项的行动清单，然后立即动手。持续工作直至本次范围完成或只剩明确外部阻塞；不要停在“建议接下来开发”。

证据放 `qa/agent-acceptance/<日期或run-id>/` 或仓库既有适合的 QA 目录；真实临时密钥只在隔离运行目录，不能打进证据归档。交付文档放 `docs/skill-dev-step10-agent-rework-delivery.md`。

## 3 按顺序落实的工作包

### A01 可运行基线与真实模式隔离

定位：`agent/zhjj_agent/executor.py`、`package_manager.py`、`main.py`、`adapters/unitree_g1.py`。

- 修复缺失导入，明确支持的 Python 版本和可复现依赖。提供依赖锁定及实际测试安装命令，不写不存在的安装脚本。
- 真实机型适配器缺 SDK、能力或可靠观察时，返回明确 unsupported／UNKNOWN，不返回模拟成功。
- MockAdapter 必须由显式测试配置选择。未知 model_code 不得静默回落 MockAdapter；生产配置误拼机型应启动失败或明确拒绝。
- G1 的 install/observe 不能仅写入再读取同一内存字典作为安装证明；执行必须证明真实入口／受控运行器被启动。真实 SDK 不可得时保持能力拒绝，同时以注入 stub 验证公共软件契约，不能把 stub 挂在生产成功路径。

验收：原样 CLI 能启动；没有 SDK／入口时 G1 拒绝执行；让入口抛错，不能仍在 0.05 秒后成功；未知执行不能返回“已确认停止”。

### A02 唯一可信任务对象与完整权限门禁

定位：`task_manager.py::process_command`、`crypto.py`、平台 SigningService、DeployTaskService、AgentController、VerifyAgentKey。

- 采用附录蓝图的 v2 签名字段表及签名域。先做严格 schema／类型／操作校验，再验签与建立不可变任务对象；所有业务分支只能读取该对象。
- 外层只保留签名、规范允许的传输字段和可刷新的包 URL。保留重复业务字段兼容时必须与 signed 完全一致，冲突即拒绝；不要只签内层却执行外层。
- 不能把缺操作码默认成安装。未知协议、未知操作、缺必需字段、非法 ID／时间类型、未知安全字段均明确拒绝。
- 核对 signed.did 与当前设备身份；绑定操作、技能版本、最终成品、目标发布、环境、授权和撤销代次。新安装／启用／执行均检查各自授权窗口；不同操作不能共享一套“只要没过任务期就放行”的规则。
- 处置任务使用独立有效的处置权限，不能因普通使用授权到期误阻断；仍需认证、验签、有效任务期限、作用域和防重放。已发生事实的补报不要求重新取得普通使用授权。
- v2 校验失败不回退 v1。旧协议只用于明确配置允许的旧生命周期路径，绝不接收 OP 7/8；为新 Agent 安装任务落实明确的 v2 能力协商，不以响应中附带 signed 冒充迁移完成。
- 使用符合 RFC 8785 的实现／正式测试向量。PHP 的 strcmp+浮点强转与 Python json.dumps 不是充分的 JCS 实现。覆盖 1e-7、负零、Unicode UTF-16 排序、空对象／数组、NaN/Inf、重复键等边界，验证跨语言字节和签名一致。
- 设备独立凭据被吊销后，省略 DID、共享 key、旧 secret、旧签名等路径全部拒绝；缺身份不得继续读取他人任务。

冻结要求：同一个 task_id 的 signed 首次冻结；重新获取只能刷新定位同一成品的 URL，不改变操作、字节、issued_at、task_expires_at 或授权期。成品不可取得时明确失败，不降为成功 dry install。

### A03 客户执行准入与执行身份

定位：SkillController 的 triggerExecution / executionStatus / cancelExecution、SkillExecution、DeployTaskService。

- 只允许当前客户持有、未到期且未撤销的授权，使用 device_binding 真相源校验当前归属。
- 执行前确认目标成品已经实际安装且启用，版本／资产／摘要与获准目标发布相符。不能只看 entitlement.status=active。
- portable-actions/v1 固定参数技能的 execution_params 必须是空对象。明确拒绝 speed、cycles、路径、SDK 方法或任意脚本注入；不要把“接收任意 JSON 并签名”当参数安全。
- 执行不下载包，包 URL 等字段按协议置 null；使用 installed_asset_id／sha 等证明实际执行对象。
- HTTP 重试 nonce 仅合并同一次请求；不同 nonce 可以创建下一次合法执行。设备端按 execution_id 持久保证副作用不重复，不仅按 task_id 去重。
- 取消按被取消的 execution_id 定位与去重；execute 和 cancel 使用同一 execution_id 不意味着取消被当成重复执行吞掉。
- 执行事实不写 install_record。创建 execution 与任务必须有一致的事务／失败补偿，失败不能遗留假 ACCEPTED。

### A04 安全安装和产物身份

定位：PackageManager、TaskManager 安装分支、成品解析服务及适配器 prepare/install/observe。

- 只允许目标匹配且准入有效的最终成品进入新交付路径；源文件不作为成品下载或执行。
- 修复解析器“只有一个 file 就发”的新机型成品错发问题。对新目标成品缺少对应目标构建／发布身份时拒绝；旧 legacy 路径单独保持兼容，不能全面破坏旧业务。
- 统一生成 manifest、目标 Profile、signed 和适配器预检字段。G1 不能以 vendor=unitree 代替精确机型与环境匹配。
- 下载体积、摘要、包内路径／符号链接／压缩膨胀／磁盘预算均检查。skill_id/version 构成的安装路径也必须限定在正确根目录。
- 明确 manifest、实际入口、声明权限和合法载体规则；未声明或缺失入口不能仅凭解压成功安装成功。非 ZIP 旧载体仅由对应已支持插件接受，不能“任意裸文件一律成功”。
- 安装日志记录不可变成品身份；外部副作用前记意图，后记独立观察；切换失败保留旧良好成品。fsync／SQLite 同步策略和磁盘错误处理要有明确定义。
- 不把平台长期设备凭据发送给任意包 URL 主机；验证下载认证边界和重定向策略。

### A05 操作感知的崩溃恢复与双向事实对账

定位：Storage、TaskManager::resume_recovery／emit_event／flush_outbox、AgentController 的 commands/ack/event/reconcile、DeployTaskService::onEvent。

- 每个操作有明确状态机和恢复规则。安装 v2 的恢复必须观察 v2 的实际成品身份；发现旧 v1 active 不得提交新安装成功。
- 持久化收到任务与事件事实；server ACK 必须有持久接收记录或可核验的等价机制，不能仅随机生成 ack_id。
- 事件使用 event_id／seq 去重；同事件重发返回已持久接收的结果。端侧只有取得可靠确认才清待发标记。
- TASK_TRANSITION_INVALID 和 TASK_NOT_FOUND 不等于“事实已保存”。应转入可审计的对账／人工处置，不无条件丢弃事件。
- 平台 timeout 后的实际安装事实需要独立保存和对账，保留原 timeout 历史，不粗暴改成历史 success。
- reconcile 必须处理端侧单边、云端单边、双方冲突、未知对象和任务事实；有差异时 aligned=false，列出差异和处置状态。不得忽略未知安装后返回 aligned=true。
- 取任务响应在到达设备前丢失时能重投同一任务；接收 ACK 丢失时只补确认不重复副作用。投递标记不等于收到。
- 新进程从真实资源／文件／适配器可核验状态重建视图，不依赖上次进程内存。恢复时复核撤销与期限；不能重放已经被后续处置覆盖的旧安装／执行。

### A06 非阻塞执行监督和安全处置

定位：SkillExecutor、TaskManager::_handle_execute/_handle_toggle/_handle_uninstall_or_reclaim、资源租约及撤销表。

- 长任务执行不能阻塞接收取消／停用／回收。采用符合现有架构的监督方式，不另造平台消息系统。
- deadline 是协议明确的绝对期限；duration 用单独字段或派生剩余预算。使用单调时钟测持续时间，处理时钟异常；不能把 Unix 时间戳当等待秒数。
- 停用／卸载／回收先原子持久冻结准入并提升撤销代次，再取消在途执行、独立观察停止，最后释放资源与清文件。
- 调用取消 accepted、runner halt、进程结束，均不自动证明实体停止。不能确认则 UNKNOWN／人工处置，保留冲突资源占用。
- 租约超时只触发核查，不能仅因时间过去允许新冲突动作。重启后未知执行也保留占用，直到可靠观察或明确获准处置。
- 删除失败、SDK 拒绝或仍运行时不能写卸载／回收成功；不能先 rmtree 再证明动作停止。
- 资源管理只协调本平台任务，不宣称完成全机器人运控仲裁。

### A07 明确恢复目标的回滚

定位：TaskManager::_handle_rollback、GrayReleaseService、DeployTaskService::recordInstall。

- revoked_version_id 是坏对象；restore_asset_id／restore_sha256 及对应环境是良好恢复对象，两者不能混用。
- 回滚不能继续拿外层坏版本号寻找旧包。即使回滚豁免普通防降级，仍需校验恢复授权、哈希、环境和撤销状态。
- 实际切换后独立观察良好目标，平台同时记录撤回对象和实际恢复对象。
- 首装无良好旧版可安全停用／卸载，明确报告没有恢复旧版；升级明明有 v1 时不能误报 NO_PREVIOUS_VERSION。
- 取消、回收及已发生事实补报不因“关闭新安装”而不可达。

### A08 用户可操作和运维交付

- 同步 `utils/api.js` 和现有“我的技能／设备”页面需要的执行、取消及状态入口，不新建无关运营系统。
- 展示 ACCEPTED、RUNNING、CANCEL_REQUESTED、UNKNOWN／待对账、成功和失败的区别；unsupported 与未接入真机要可解释。不得出现假成功按钮。
- 提供有效配置样例、依赖、启动、托管、重启、升级与回退方法；示例不含真实凭据，命令必须实际存在。
- 暂停新构建／新安装后，心跳、查询、事实补报、对账和合法安全处置仍可工作。
- 修正 AGENTS 和交付报告的完成口径；保留原验收报告及历史证据，不删除负面结论来制造全绿。

## 4 必须逐项通过的黑盒验收

每条必须有 case_id、输入、执行命令、预期、实际、证据路径。预期在修实现前冻结，不允许通过放宽断言、skip/xfail、mock 被验对象或删除反例来“通过”。测试适配器可以模拟外部世界；不可模拟掉被验的签名、任务处理、数据库、对账或持久化逻辑。

| case_id | 具体输入／故障 | 必须观察到的结果 |
|---|---|---|
| A-T01 | 原样支持版本启动 CLI 与发现全部 Python 测试 | 无模块加载错误；不是仅 compileall 成功 |
| A-T02 | signed.operation=5，保留签名，外层 operation 改成4 | 副作用前拒绝；原禁用状态不被启用 |
| A-T03 | 有效签给 B 的任务放到 A；或内外层 DID/skill/version/hash 不一致 | 拒绝；不下载、不安装、不执行 |
| A-T04 | v2 缺字段／未知协议／签名损坏／operation 缺失；v1 携带7或8 | 明确拒绝，不默认安装、不降级验签 |
| A-T05 | 使用授权到期、任务到期、被撤销、设备解绑，分别触发新执行 | 无动作副作用；合法处置及历史事实补报仍可达 |
| A-T06 | 同 task_id 刷新两次，服务器时钟推进一小时 | signed 及授权期不变；URL 可更新且仍定位同字节 |
| A-T07 | 同 execution_id、不同 task_id 重投；重启后再投 | 适配器启动副作用总计一次；取消仍能定位该执行 |
| A-T08 | 同 HTTP nonce 重试与两个不同 nonce 顺序执行 | 前者合并，后者各有执行身份，不能吞下一次合法执行 |
| A-T09 | SDK／入口不存在，或入口立即抛错，使用 G1 适配器 | unsupported／失败／UNKNOWN，绝不计时模拟成功 |
| A-T10 | 安装v2在下载阶段崩溃，设备真实仍为v1 | 不得把v2报done；明确旧事实与未完成任务 |
| A-T11 | 逐个在落journal、外部安装前后、目录切换、落安装事实、入outbox、服务端落事件前后退出进程 | 新进程读取相同持久目录恢复，最终设备字节和平台事实一致；不重复副作用 |
| A-T12 | commands响应丢失、接收ACK丢失、done请求丢失、done响应丢失 | 同任务可恢复投递／补报，副作用一次；事件有可靠持久确认 |
| A-T13 | 平台已timeout后设备补报真实成功；或返回409/404 | 保存实际事实／待处置，不丢事件，不篡改历史timeout |
| A-T14 | 云installed、端uninstalled；端独有安装；云独有安装；未知任务 | aligned=false，差异完整，不忽略未知记录 |
| A-T15 | 真实长执行stub保持RUNNING，同时平台发取消／回收 | 轮询仍接收处置；停止未观察前不报已停止、不释放资源 |
| A-T16 | UNKNOWN占用的租约时间已过，或Agent重启 | 新冲突执行被拒；需要可靠停止观测才能释放 |
| A-T17 | 收到epoch=5停用／回收，再投epoch=4旧安装、启用、执行及旧journal | 状态保持撤销／停用，不能重新激活 |
| A-T18 | v2坏、v1良好且文件存在，回滚指定v1资产摘要 | 实际激活字节变成v1；云端记录实际恢复v1 |
| A-T19 | 首装无旧版；恢复目标已撤销／损坏／不兼容 | 首装明确安全停用；非法目标拒绝，不能虚报恢复 |
| A-T20 | 唯一G1目标成品，请求A2／未知型号／错误SKU | 新目标路径拒绝，不能回退发送G1；旧manual仍正常 |
| A-T21 | ZIP穿越、symlink、解压预算超限、磁盘不足、缺manifest／入口 | 安装失败且旧良好成品、状态和恢复依据保留 |
| A-T22 | 客户传speed/cycles/SDK方法／越权execution_id | 固定参数或归属门禁拒绝；无新动作任务或副作用 |
| A-T23 | 任意OP7/8成功／失败／取消 | install_record数量与安装事实不受执行污染 |
| A-T24 | 关闭新构建、新安装；再提交新安装和合法取消、对账、补报 | 新安装拒绝；修复／处置通路仍可用 |
| A-T25 | PHP产生并签名、Python消费JCS边界向量；反向验证 | 规范字节及验签一致；不能仅双方各自roundtrip |
| A-T26 | 已吊销设备省略DID，用共享key／旧凭据尝试所有Agent端点 | 全部拒绝；其他正常设备不受误伤 |

崩溃测试必须至少包含真实子进程非正常退出后重启，不能全用会执行 finally 的可捕获异常代替。观察器应从磁盘上的实际 payload／激活指针或外部 stub 资源读取，不从“刚写的成功状态”反推成功。模拟进程退出不等于真实断电验证，报告中区分。

端云集成测试至少一组启动真实 Laravel HTTP 服务连接独立测试库，由 Python Agent 使用真实 AgentClient 完成流程；不能全部用 MockHttpClient 或直接调用服务类代替 HTTP 集成。测试服务器独占端口，配置与进程只属于隔离测试，不影响现有8000服务。

## 5 必须复跑的既有验证

按真实环境选择等价命令，保存运行时版本、完整命令、退出码及输出。先确认隔离库，不能直接复制到生产：

```powershell
$env:PYTHONPATH = 'agent'
python -m unittest discover -s agent/tests -p 'test_*.py' -v
```

```powershell
# 在 my-bagisto-store 下；必须先断言实际DB为独立测试库，并禁用过期配置缓存。
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'summon_mecha_step10_test'
php artisan test packages/Webkul/Shop/tests/Feature/API/AgentV2ExecutionTest.php packages/Webkul/Shop/tests/Feature/API/AgentDeviceCredentialB12Test.php packages/Webkul/Shop/tests/Feature/Deploy
php artisan ecosystem:contract-check
php artisan route:list --path=api/agent --except-vendor
```

另外执行受影响 PHP 文件语法检查、增量迁移在独立库的状态／前后兼容、客户端 API 一致性和页面浏览器验证。原生态门禁通过不能替代 A-T01—26。若修复了明确错误的旧测试，保留变更原因与替代反例，不能以测试数量下降来藏失败。

## 6 不可伪造的交付清单

交付 `docs/skill-dev-step10-agent-rework-delivery.md`，至少包含：

1. 受测 commit／工作区指纹、改动文件及每个A工作包与验收发现R编号的对应关系。
2. 本任务 A-T01—26 的逐项结果；只能写 PASS / FAIL / NOT_RUN / BLOCKED，后两种不能计通过。每项链接原始输出与设备／数据库观察证据。
3. 冻结协议schema、PHP/Python签名黄金向量、逐操作授权表、安装／执行／恢复状态机和兼容策略。
4. 至少一组真实HTTP集成的请求／响应脱敏记录、持久事件收据、设备实际payload摘要与平台记录对照。
5. 真实运行过的部署／启动／升级／恢复命令；不能包含生产凭据或真实设备动作。
6. SDK与HIL缺口，写到具体机型、版本、接口及所需材料；不能用“有待优化”代替未实现功能。
7. 前端验证截图和错误／未知态说明；没有浏览器验证就写NOT_RUN。
8. 更新后的AGENTS和交付状态，不把本轮代码提交等同于独立验收通过。

最后执行 `git diff --check`、检查工作区与密钥泄露、只按明确路径管理改动。禁止 git add -A、reset --hard、未授权提交／push、清理用户文件、对业务库fresh/truncate/seed。不得改动独立验收报告来消除负面证据。

**停点：完成第一阶段交付后停止扩大功能。第二阶段任务书本轮禁止执行；第一阶段结束后停止，等待用户后续任务。任一必需软件案例失败就写“软件验收未通过”，继续修复；只有外部硬件未取得时可交付“软件通过，真机未验收”，不得写整个Agent安全可交付。**

## 7 随文资料的使用规则

下面全部是原文快照，并非“另找文件阅读”的占位链接。本文前述任务授权、验收门槛及用户最新要求适用于本轮；仓库已拍板业务边界持续有效。附录中 Draft/Proposed 的推荐不自动变成生产／硬件批准。旧基线、时间、计划状态按源码与证据核验，不可照抄为当前完成事实。

不要把文档本身写着的“通过”当作通过；只有当前源码原样复跑及可独立观察的结果才能关闭相应验收项。


# 完整原始资料附录

以下为原文快照，旧完成声明不构成通过证据；后续阶段内容不构成本轮实施授权。


## 附录 1 仓库协作入口全文 状态声明须按最新复验纠正

原路径：`AGENTS.md`；SHA-256：`85698a4934653b7cd065c0a4abdd5af0e66ac4e59829b93d4e975430d1b1919e`。以下全文不删节。

<!-- BEGIN ORIGINAL AGENTS.md -->
# AGENTS.md · 本仓库 AI 协作入口

任何 AI/Agent 接手本仓库，先按顺序读：

1. [CLAUDE.md](CLAUDE.md) —— 不可谈判的定位、决策红线、工程纪律（对所有 AI 生效，不限 Claude）。
2. [docs/ecosystem/HANDOFF.md](docs/ecosystem/HANDOFF.md) —— 生态赛事与资讯当前进度、开关状态、未完成项、验证命令。
3. [docs/ai-project-brief.md](docs/ai-project-brief.md) —— 项目理解手册。
4. 当前任务相关批次证据：`docs/skill-dev-step10-agent-delivery.md`、`docs/ecosystem/*-evidence.md`、`*-implementation-plan.md`。

## 当前状态（2026-11-03）

- **标准技能编译器与动作转码器（第二阶段）已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-stage2-compiler-delivery.md` 及 `docs/portable-skill-spec/validation.json`。
  - **中立标准源解析与校验**：严格校验 `zhaohuan.skill/v2` 与 BTCPP XML4，白名单过滤与 XXE 防御；
  - **机型 Profile 与能力仲裁**：宇树 G1 EDU（23/29 DoF，官方 SDK2 物理限位与 DDS）、智元 A2、离线模拟机型 Profile 及 JCS SHA-256 摘要（`target_profile_digest`）；
  - **动作转码与目标代码降阶**：余弦平滑轨迹插值生成、`action.py` 脚本与成品 `.skill` 归档生成，写入 `skill_assets` 并遵守 `uk_skill_assets_version_carrier` 约束；
  - **全链路交付打通**：通过 `SkillDeliveryAssetResolver` 为目标设备唯一解析对应构建产物，并在设备端 Agent（Unitree G1 适配器）完成安装与 `OP_EXECUTE(7)` 执行验证；
  - **验证**：新增 `PortableSkillCompilerTest.php` 5 项全过、Deploy 全量 128 项测试全过、Python 27 项测试全过。
- **设备端 Agent 与平台必要对接（第一阶段返工）已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-agent-rework-delivery.md` 及 `qa/agent-acceptance/20261103-independent-evidence/`。
  - 针对独立验收报告提出的 R01~R10 缺陷及 A01~A08 工作包全部闭环：
    - **A01/R01/R07**：修复 Python 3.12 类型导入问题；重构 `UnitreeG1Adapter`，真实检查入口脚本，无 SDK 且无 stub 时拒绝执行，诚实标注 `HARDWARE_UNVERIFIED`；
    - **A02/A03/R02**：落实 RFC 8785 JCS 规范串与双向跨语言黄金向量验证；严格内外层一致性校验与凭据验签；
    - **A04/R08**：强化解包安全（Zip Slip、Symlink、Zip Bomb）与 Staging 事务隔离；
    - **A05/R05/R06**：执行生命周期按 `execution_id` 独立去重，执行事实与安装事实彻底物理隔离；
    - **A06/R03/R04**：SQLite WAL 本地持久化与 Outbox 补偿重试，修复下载崩溃误报成功漏洞与迟到事实保留；
    - **A07/R04/R06**：首装无旧版安全停用与停用/卸载时主动取消关联执行；
    - **A08/R09/R10**：修复下发指令过期时间漂移与跨机型构建错发漏洞，实现全量端云事实双向对账；
    - **验证**：交付黑盒测试套件 `test_acceptance_at01_at26.py`（26 项用例全过）、真实 Laravel HTTP 端口隔离端到端集成测试 `test_live_http_integration.py`（4 项全过）、Python 全量 57 项测试全过、Deploy 全量 128 项测试全过、契约门禁全过。
- **技能全链路缺失清单专项**：
  - 计划基准文档见 `docs/skill-supply-demand-audit-and-blueprint.md`。
  - **节点 1（开发者端 DEV-01~06）已闭环**：
    - `DEV-01`：`PackageInspector` 补齐 `class_exists(\ZipArchive::class)` 守卫，杜绝环境缺少扩展导致的 500 未捕获崩溃；
    - `DEV-02`：驳回平台代客打包（严守 D6 责任边界），落地上传即安检（Fail-Fast，落盘前验证 ZIP + `manifest.json` 结构合规并自动回填 `entrypoint`）；
    - `DEV-03`：补齐 `install_guide`（人工通道）及 `entrypoint`、`payload_json` 字段与 JSON 解析；
    - `DEV-04`~`DEV-06`：依据平台定位、安全基线红线（不引入有历史漏洞的 symfony/yaml）及既有已落地功能驳回关闭；
    - 验证：新增 `DeveloperAssetUploadFailFastTest.php` 专项测试，通过 Blade 缓存编译、`ecosystem:contract-check` 门禁与 86 项全量测试。
- G0 / G1 / G2 / G3-01~12 / G4-01~09 / **Q-01~Q-05** 代码与本机验证完成。
- **Q 横向质量包**：契约门禁、真实并发、真实 worker、四角色浏览器演练、数据 preflight 与迁移/生命周期演练；证据见 `docs/ecosystem/Q-01-05-quality-evidence.md`，入口见 `my-bagisto-store/qa/ecosystem/README.md`。
- **G2/G3/G4 浏览器 e2e（2026-10-20）**：T31/T47、G3 客户侧全链路、G4 搜索/收藏/关联/SSR/sitemap/撤稿/停读/375px，39/39 通过；证据见 `docs/ecosystem/G3-G4-browser-e2e-evidence.md`。
- **G3 Admin 录分/发布（2026-10-20）**：录分草稿→提交复核→写名次→暂定发布→终审，12/12 通过；证据见 `docs/ecosystem/admin-g3-result.json`。
- 构建门禁：`php artisan ecosystem:contract-check`（路由/ACL/错误码/状态/OpenAPI）与只读 `php artisan ecosystem:data-preflight`；一键入口 `bash tools/ecosystem-gate.sh`（`--with-admin-browser` 加 Admin 演练，`--with-browser` 再加 e2e）。
- **所有 G4 新开关默认关闭**：搜索、收藏、关联、SSR、sitemap、指标、内容访问、运营台。
- 报名写闸、赛务写闸、公开赛程/成绩/展示仍关闭；首场赛规未签署。
- root 已做 additive 迁移；备份见 HANDOFF §4。
- 验证必须指向独立库 `summon_mecha_step10_test`；禁止对 root 执行 `migrate:fresh` / `truncate` / `seed`。
- **Git 状态**：G2~Q 与收尾改动已提交（`b36798c`、`a09d8b6`、`a634c2c`）；分支未 push。
- **Git 红线**：`my-bagisto-store2 备份/` 会让 git 认为 2739 个已跟踪文件被删除。禁止 `git add -A`，只按路径 `git add`。

<!-- END ORIGINAL AGENTS.md -->


## 附录 2 工程与业务红线全文

原路径：`CLAUDE.md`；SHA-256：`23734a12808b5722e9935263fb859361574d998d533245ad321c591866b5109a`。以下全文不删节。

<!-- BEGIN ORIGINAL CLAUDE.md -->
# 召唤机甲 · AI 开发合伙人须知

> 细节见 [docs/ai-project-brief.md](docs/ai-project-brief.md)(项目理解手册)。本文件只放**不可谈判的部分**——系统提示是每一轮都要付的成本。
> 本文件亦可直接作为其他 AI 工具的系统提示词使用。

---

## 【身份与核心行为准则】

你是召唤机甲(SUMMON MECHA)的核心开发合伙人,而非指令执行器。你必须站在项目负责人视角思考:既懂业务目标,也懂代码落地,主动补位、提前预判风险。

**铁则**:认知上主动 ≠ 决策上越权。已拍板的方案绝不推翻;有疑虑先报告风险与方案,人保留最终决定权;所有变更必须先报批、留档,再动手。

## 【项目核心定位(强制记忆,仅此一版)】

1. **主体**:召唤机甲(上海)科技有限公司 —— 全球机器人生态服务运营商(非硬件制造商、非纯商城)。
2. **平台本质**:近处做「场景服务 + 交易交付(租赁/购买/配件/技能) + 技能生态」;最远处是「覆盖机器人领域多业务 + 赋能每一台机器人 + 让普通人也能手搓机器人」——**终局是方向锚,不是当下排期表**。
3. **两个核心支点**:AI 召唤(需求入口)与 Agent(设备端)。
4. **技术底座**:
   - 前端:uni-app(微信小程序 + H5),深蓝科技风
   - 后端:Bagisto/Laravel 模块化单体,扩展走配置注册表机制
   - Go 服务:仅负责下发调度,**不做微服务架构改造**
5. **三个绝对不能搞错的定位(犯错即方向事故)**:
   - 我们**不是**机器人操作系统,不是固件/硬件厂商。宇树 SDK2/DDS、智元 AimDK、众擎 ROS2 等厂商 SDK 绕不开,**绝不接入或篡改厂商云**,不碰厂商 EULA 红线。
   - 我们**不承诺跨品牌开箱即用**。兼容性分三级:**L1 可交付 / L2 可适配(只承诺"可转换")/ L3 仅展示不发售**。
- 生态是「自营供给 + 第三方开发者 + 客户需求」三方。**2026-10-16 拍板:第三方开发者要真实引入(邀请入驻),开发者侧链路必须做到"真人可用"** —— 不要再拿"供给方为 0"当不做开发者功能的理由。但**仍然只为已拍板的环节设计**:第三方结算/提现/对账、团队 RBAC、Beta 轨、申诉入口、SDK·CLI 等**仍未立项**(见 `docs/skill-dev-step9-closure-plan.md` §3)。

## 【排期与优先级原则】

1. 终局愿景只用来判断「这个设计会不会挡住未来」,**绝对不是**「现在就去做它」。
2. 排期只看当前真实基线 + 最近里程碑(**M1 可售 / M2 可上架 / M3 人工通道闭环**)。
3. 依赖外部前提的(微信支付资质、真机 Agent、EMQX 等)**一律不排期**,不做 P0 优先级。(第三方开发者**不再**属于此类:2026-10-16 已拍板邀请入驻,开发者侧链路纳入排期。)

## 【已拍板决策红线 D1–D11(变更必须先报批)】

- **D1** 设备真相源 = `device_binding`;`devices` 表只是登记视图,下发逻辑只认 binding
- **D2** 下发三通道:MQTT+Agent / 厂商云 API / 下载+人工安装
- **D3** 买断制无订阅;定价字段写 `pricing_mode='buyout'`,**禁止**写 `license_mode='perpetual'`
- **D4** 试用 20 分钟自动回收,仅限在线通道
- **D5** 不自创技能包格式:中立 manifest + 各厂商导入适配器
- **D6** 物理安全三层责任:开发者承诺 / 沙盒受控执行 / 用户分级告知留痕
- **D7** 开发者门户独立于 Bagisto Admin
- **D8** AI 三层备案等合规是外部审批,阶段 0 并行前置
- **D9** 熔断 kill switch 可一键禁用已安装设备（⚠ **生效范围**见下方「现状口径」：对**离线设备**要等其下次上线；**人工通道**安装的当前无法自动卸载）
- **D10** 虚拟商品售后规则:试用不退 / 未下发可退 / 已激活可申诉
- **D11** 事故处理机制:上报 + 责任追溯 + 责任保险

## 【术语纪律(混用即 Bug)】

- `skill_versions.channel` = 发布通道(stable/beta);`delivery_channels` = 交付通道(manual / agent / vendor:{code})
- 代码内 `app` ≡ 计划的 `manual`,二者等价,**禁止改名**
- `license_mode` = 授权范围;`pricing_mode` = 定价模式,二者正交,不可混淆
- `carrier_type` 枚举以 `SkillAsset::CARRIER_TYPES` 常量为准,**禁止相信文档里的枚举描述**

## 【工程纪律铁则】

1. 修改前先 `rg` 确认唯一定义与所有引用点;有风险的改动先备份到 `.claude/backup/<任务号>/`
2. **必须实测**,不许"看代码觉得对":`php -l` 语法检查 / `artisan route:list` 路由校验 / `migrate:status` 迁移状态 / `tinker` 探针 / H5 截图 / `bash tools/api-regression.sh` 接口回归
3. **验收三问**:功能健康吗?这步真完成了吗?符合战略吗?——任一不过就**停下报告**,不要继续推进
4. **诚实标注**:没做写"未做",部分做写"部分完成 + 缺口明细"。**严禁把未达标步骤标成 ✅(本项目真实发生过)**
5. 交付文档统一放 `docs/skill-dev-stepN.md`,必须包含:改动清单、实测证据、回滚命令、未做的部分
6. **Git 红线**:`my-bagisto-store2 备份/` 是由 `my-bagisto-store2/` 改名而来,git 认为 **2739 个已跟踪文件被删除** —— 执行 `git add -A` 会毁掉整个备份。**动 git 前必须先处理它。**

## 【禁止清单(绝对禁区)】

- ❌ 把"条件触发 / 暂不现实"当 P0 排期
- ❌ 接入或篡改厂商云(触碰厂商 EULA 红线)
- ❌ 让厂商专有技能跨机型流通
- ❌ 把 L2/L3 机型宣传成"开箱即用"
- ❌ 境外模型对境内用户提供生成式服务;敏感生物特征 / 摄像头数据出境
- ❌ 未经批准变更已拍板架构与决策
- ❌ 引用不存在的《设计规范》《架构规范》——**按现有代码的实际写法来**

## 【代码地图(找路用,别满仓库乱翻)】

- **前端** uni-app(Options API):`pages/`、`components/`、`utils/api.js`(唯一 API 入口)、`utils/request.js`
- **前端 API 地址唯一配置点**:`utils/config.js`,默认必须与 `php artisan serve` 的 `8000` 端口一致;个人/部署环境只通过 `.env` 的 `VITE_API_BASE_URL`、`VITE_SSR_BASE_URL` 覆盖,禁止直接改源码端口;提交前跑 `node --test tools/tests/config-consistency.test.mjs`
- **后端** Bagisto:`my-bagisto-store/packages/Webkul/Shop/src/`(`Routes/api.php`、`Http/Controllers/API/`、`Services/<业务域>/`、`Database/Migrations/`)
- **扩展优先走配置注册表**:`config/{skill_delivery,deploy_gateways,payment_gateways,skill_builders,skill_importers,developer_portal}.php`
- **现状提醒**:队列/缓存是 `database` 驱动。**scheduler 代码已注册 6 条定时任务**(`bootstrap/app.php` 的 `withSchedule`),但**常驻进程未起**;`queue:work` 同理 —— **没起就等于不存在**,别假设定时任务在跑。
  (2026-10-16 更正:此前本文件写"没有 scheduler",那句话会让接手的人以为定时任务不存在,已按事实改写。)
- **现状口径（对外表述必须与代码一致）**:当前**唯一真能跑通的交付通道是「下载 + 人工安装」(`manual`)**;Agent 通道**平台侧已就绪**(签名限时下载 + 包指纹,见 `Services/Deploy/`),但**设备端 Agent 未接真机(E7)**。对外**不要**说"一键下发已打通""覆盖所有机型""跨品牌开箱即用"。
- **中立清单格式**:文档写的是 `manifest.yaml`,但 `symfony/yaml` **未安装**,实际**只有 `manifest.json` 可用**(`ManifestParser` 对 `.yaml` 会回落报错)。改格式前先看这条。
- **进度**以 [docs/skill-dev-steps.md](docs/skill-dev-steps.md) 的【进度快照】为准;`NAVIGATION.md` 有过期描述,以代码为准
- **生态赛事与资讯（G0/G1/G2/G3/G4 + Q-01~Q-05）** 接手先读 [docs/ecosystem/HANDOFF.md](docs/ecosystem/HANDOFF.md):当前到哪、开关状态、未完成项、下一步;细节再进同目录 `*-evidence.md`。G4 分发与运营代码已完成但**所有 G4 开关默认关闭**，上线顺序见 `G4-runbook.md`；首场赛规未签署，G3 写闸保持关闭。Q 横向质量包证据见 `docs/ecosystem/Q-01-05-quality-evidence.md`；浏览器 e2e 见 `docs/ecosystem/G3-G4-browser-e2e-evidence.md`；构建门禁为 `php artisan ecosystem:contract-check`、只读 `php artisan ecosystem:data-preflight`，一键入口 `bash tools/ecosystem-gate.sh`。
- **改动总览**以 [docs/skill-changes-summary.md](docs/skill-changes-summary.md) 为准(白话+表格;含未做/待决策,让后来者一眼看懂这几天改了什么)

## 【输出与交付标准】

1. 代码可直接落地:参数校验、错误码、权限、日志齐备;沿用项目既有模式(配置注册表 + 服务类),不新造架子
2. 前端沿用现有页面配色与组件(深蓝科技风 + 白,主色 `#102F6E` / `#165DFF`),所有页面带加载 / 异常 / 空状态处理
3. 结论先行;主动发现问题、提出方案,但不擅自改已拍板的东西
4. **开始动手前,先读 [docs/ai-project-brief.md](docs/ai-project-brief.md)(项目理解手册)**

---

现在,基于以上认知处理你的任务。遇到模糊需求先对齐定位,遇到红线先提示风险,遇到可以优化的地方主动给出方案。

<!-- END ORIGINAL CLAUDE.md -->


## 附录 3 生态交接全文

原路径：`docs/ecosystem/HANDOFF.md`；SHA-256：`68eaef3b7e9c7799d0e45f82fdcb25683115a7901688c5131c134ac6911910c5`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/ecosystem/HANDOFF.md -->
# 生态赛事与资讯 · 接手须知（HANDOFF）

> **任何 AI / 新会话先读这一页。** 它只回答三件事：现在到哪了、下一步做什么、什么不能碰。
> 实现细节看各批 `*-evidence.md`，本页不重复，也不替代证据。

| 项 | 内容 |
|---|---|
| 最后更新 | 2026-10-20（G3 Admin 录分/发布浏览器演练收尾后） |
| 分支 | `codex/ecosystem-g2-free-registration` |
| 范围 | G0（停写去伪）→ G1（可信发布）→ G2（免费活动报名）→ G3（人工赛务）→ G4（分发与运营）→ Q-01~Q-05（横向质量）→ **浏览器 e2e + Admin 赛务演练收尾** |
| 结论 | 代码、独立测试库验证、root additive 迁移完成；**G4 新开关全部关闭**；Q、e2e 与 Admin 演练证据已落地；无真实运营数据 |
| Git | G2~Q 与收尾改动均已提交（`b36798c`、`a09d8b6`、`a634c2c`）；分支**未配置 upstream，未 push** |

## 1. 一句话状态

G0/G1/G2/G3 与 **G4-01 ~ G4-09** 的代码、独立测试库验证、root additive 迁移均已完成。
**Q-01 ~ Q-05 横向质量包**已完成：契约门禁、真实并发、真实 worker、四角色浏览器演练、
数据 preflight 与迁移/生命周期演练均可复跑，证据见
[Q-01-05-quality-evidence.md](./Q-01-05-quality-evidence.md)。

**G4 最重要的边界**：搜索、收藏、关联、SSR、sitemap、指标、运营台全部默认关闭；
没有打开任何新的生产读/写入口。root 三张新表为空，旧活动/报名未改写。

**Q-03 带来的产品修正**：outbox 可重试失败耗尽后写 `failed`（运营可重排），
不可重试事实仍写 `dead`（终态，绝不复活）。此前 worker 从不写 `failed`，
G4-07 的“通知失败重排”因此永远为空；修正后语义与运营台一致。

**G3 的边界仍有效**：首场赛规未签署，不得写生产 `rules_confirmed_at`、不得创建真实赛项。

**2026-10-20 浏览器 e2e 收尾**：G2 T31 切号 / T47 跨设备、G3 客户侧全链路
（作品提交→展示授权→公开成绩→申诉）、G4 搜索/收藏/关联/SSR/sitemap/撤稿/停读/375px，
已在真实 Chrome + 独立测试库跑通 **39/39**，并修复 H5 收藏 `key` 类型缺陷
（修复前后端 422，收藏按钮实际不可用）。证据见
[G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md)。

**2026-10-20 Admin 赛务收尾**：G3 Admin 录分/发布真实浏览器演练 **12/12** 通过
（录分员保存草稿→提交复核→写名次；发布员暂定发布→终审，两个后台账号与独立
Chrome profile）。证据见 [admin-g3-result.json](./admin-g3-result.json) 与
[G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md) §2。

## 2. 工作区状态（交接前必看）

- **G2 + G3 + G4 + Q 改动已 commit**（2026-10-20）：
  - `b36798c` 代码 / 测试 / QA 脚本；
  - `a09d8b6` 文档 / 证据 / OpenAPI / 结果 JSON；
  - `a634c2c` 收尾：G3 Admin 录分/发布演练、G2/G3/G4 e2e、门禁与 CI workflow、文档更新。
  分支 `codex/ecosystem-g2-free-registration` **未配置 upstream，也未 push**；
  其他机器要看到，需要先 push 或手工带走这三个提交。
- **本次收尾改动已提交**（`a634c2c`），包含 `qa/ecosystem/e2e/**`、
  `qa/ecosystem/admin-g3/**`、`tools/ecosystem-gate.sh`、
  `.github/workflows/ecosystem-gate.yml`、`utils/api.js`、`q04/driver.mjs`、
  `G3-G4-browser-e2e-evidence.md`、`g3g4-e2e-result.json`、`admin-g3-result.json`
  及本次更新的 HANDOFF/Roadmap/Backlog/G4-evidence。
- 截图目录按 Git 红线**故意不提交**：`docs/ecosystem/e2e-screenshots/`、
  `docs/ecosystem/admin-g3-screenshots/`、`docs/ecosystem/q04-screenshots/`
  与 `docs/ecosystem/screenshots/e2e-*.png`（均可用脚本复现）。
- 仍留在工作区、按 Git 红线**故意不提交**的：`.claude/*.log`、`.claude/tasks/`、
  `docs/ecosystem/screenshots/`、`docs/ecosystem/q04-screenshots/`（截图可用脚本复现）。
- G4 新增/更新文档：
  - `G4-implementation-plan.md`（范围、数据模型、接口、风险、验收映射）
  - `G4-runbook.md`（开关矩阵、分阶段上线、停写/回退、值守）
  - `G4-evidence.md`（交付、测试、root 迁移、基准、未做项）
  - `G4-08-drill.md`（T27/T28/T70/T71/T73/T76/T78/T79/T80 演练对照）
  - `g4-benchmark-result.json`（独立测试库基准输出）
- Q-01 ~ Q-05 新增：
  - 产品代码：`config/ecosystem_contract.php`、`ContractCoverageChecker`、
    `ecosystem:contract-check`、`ecosystem:data-preflight`、
    `OutboxLeaseService` 的 failed/dead 语义修正。
  - QA 工具：`my-bagisto-store/qa/ecosystem/**`（专用库硬校验 + 演练脚本）。
  - 文档/证据：`Q-01-05-quality-evidence.md`、`openapi.json`、
    `q02-result.json`、`q03-result.json`、`q04-result.json`、`q05-result.json`、
    `q04-screenshots/`。
- G3 证据/计划仍在：`G3-01-06-*`、`G3-07-12-*`、`G3-12-drill.md`、`G3-01-competition-rule-freeze.md`。
- **Git 红线**：`CLAUDE.md` 已写明 `my-bagisto-store2 备份/` 会让 git 认为 2739 个
  已跟踪文件被删除。**禁止 `git add -A`**；只按路径 `git add`。
- 不要把 `.claude/backup/`、本机截图、测试库 dump 提交进仓库。

## 3. 文档阅读顺序

1. [Execution-Roadmap.md](./Execution-Roadmap.md) —— 批次边界与健康门槛（含 G4 / Q / 收尾进度）
2. [Q-01-05-quality-evidence.md](./Q-01-05-quality-evidence.md) —— 五个横向质量包的实测结果与未做项
3. [G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md) —— G2 T31/T47、G3 客户侧全链路、G4 浏览器 e2e 与收藏缺陷
4. [G4-runbook.md](./G4-runbook.md) —— 上线顺序、开关、停写/回退、值守
5. [G4-implementation-plan.md](./G4-implementation-plan.md) —— 数据模型、筛选/游标、SSR、指标口径
6. [G4-evidence.md](./G4-evidence.md) —— 实测结果与未做项
7. [HANDOFF.md](./HANDOFF.md) —— 本页
8. [G3-12-drill.md](./G3-12-drill.md)、[G3-07-12-evidence.md](./G3-07-12-evidence.md)
9. [G2-implementation-plan.md](./G2-implementation-plan.md)
10. [ADR-0001-trusted-publishing.md](./ADR-0001-trusted-publishing.md) —— 仍为 **Proposed**

## 4. 当前开关与 root 事实

```text
# G1/G2/G3（沿用）
ECOSYSTEM_EVENT_REGISTRATION_ENABLED=false
ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED=false
ECOSYSTEM_REGISTRATION_ENABLED=false
ECOSYSTEM_REGISTRATION_LIFECYCLE_ENABLED=false
ECOSYSTEM_CONTENT_PUBLISH_ENABLED=false
ECOSYSTEM_COMPETITION_WRITE_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED=false

# G4（本批新增，全部 false）
ECOSYSTEM_SEARCH_ENABLED=false
ECOSYSTEM_FAVORITES_ENABLED=false
ECOSYSTEM_RELATIONS_ENABLED=false
ECOSYSTEM_SSR_ENABLED=false
ECOSYSTEM_SITEMAP_ENABLED=false
ECOSYSTEM_METRICS_ENABLED=false
ECOSYSTEM_CONTENT_VIEWS_ENABLED=false
ECOSYSTEM_OPS_WORKBENCH_ENABLED=false
```

- root：G1/G2/G3 迁移均已执行；G4 新增 3 个迁移（favorites / relations / view daily）。
- root 数据：`events=11`（8 条 Seeder + 3 条既有【测试】数据）、
  `event_registrations=1`（legacy）、G4 三张新表均为 0。
- 备份：`backups/root-before-g4-20261020.sql`（1,696,955 bytes）。
- 生产部署侧**未启动** `schedule:work` / cron；G4 没有新增队列消费器。

## 5. 已完成 / 未完成

### G4-01 ~ G4-09（前批）

| 项 | 结论 |
|---|---|
| G4-01 搜索/筛选/分页 | 完成；LIKE 转义、8 种报名状态、游标 + filters hash、page 上限 |
| G4-02 收藏 | 完成；唯一键幂等、跨账号隔离、撤稿隐藏、上限 500、私有 no-store |
| G4-03 关联 | 完成；失效目标整项省略、Admin 维护、公开 DTO 最小化 |
| G4-04 SSR/sitemap | 完成；canonical/OG/JSON-LD、410 中性页、草稿/撤稿排除、kill switch 503 |
| G4-05 H5 SEO | 完成；纯函数 + Node 测试；canonical 指向 SSR，分享不含私有状态 |
| G4-06 指标 | 完成；口径与事实同页；无源返回 unavailable；访问只存日聚合 |
| G4-07 运营台 | 完成；待审/截止/通知失败/来源复核/撤稿；PII 遮罩与活动范围 |
| G4-08 演练 | 完成；撤稿/取消/停读同步降级；独立测试库基准 |
| G4-09 上线 | 完成；开关矩阵、状态命令、runbook、root 备份与 additive 迁移 |

### Q-01 ~ Q-05（横向质量）

| 项 | 结论 |
|---|---|
| Q-01 契约门禁 | 完成；113 条后台路由 + 45 条公开 API + 85 错误码 + 11 状态快照 + OpenAPI 双向核对；抓到并修复 `COMPETITION_TRACK_NOT_FOUND` 未定义常量 |
| Q-02 并发/隔离 | 完成；专用库硬校验、容量 1 两进程竞争、真实行锁等待、RR 快照 vs 当前读 |
| Q-03 worker/补偿 | 完成；afterCommit/回滚、真实 worker、重复投递、崩溃租约、25 条积压、failed/dead 补偿语义修正 |
| Q-04 浏览器演练 | 完成；客户/队长/裁判/超管四角色，375/390px，键盘 Tab，错误分支，403 无 PII 泄漏，9 张截图 |
| Q-05 数据/迁移 | 完成；只读 preflight、计数对账 dry-run/apply、留存 dry-run、expand/backfill/switch/recovery 演练 |

### 浏览器 e2e 收尾（2026-10-20）

| 项 | 结论 |
|---|---|
| G2 T31/T47 | 完成；切号后私有报名不残留；第二设备同账号看到同一报名 |
| G3 客户侧全链路 | 完成；作品草稿→提交→展示授权→公开成绩→申诉，真实页面通过 |
| G4 搜索/关联 | 完成；关键词筛选；五类关联；撤稿目标整项省略 |
| G4 收藏 | 完成；点击/刷新/我的收藏/资讯 tab/跨账号/撤稿 stale；修复 `key` 类型缺陷 |
| G4 SSR/sitemap | 完成；canonical/OG/JSON-LD、收录/排除、撤稿 410、停读 503 |
| 布局 | 完成；375px 详情/成绩页无横向溢出 |
| G3 Admin 录分/发布 | 完成；录分草稿→提交复核→写名次；暂定发布→终审；12/12，两个后台账号分权 |
| 本地门禁 | `tools/ecosystem-gate.sh`；契约 + preflight + 后端/Admin + Node；`--with-admin-browser` 加 Admin 演练，`--with-browser` 再加 e2e |

### G1/G2/G3（前序批次）

**已完成（本机可复现）**：G1 可信发布、公开 API/DTO、媒体/富文本、worker、H5 E2E；
G2 报名主体；G3 人工赛务全链路。

### 未完成

| 项 | 原因 | 谁来做 |
|---|---|---|
| 首场赛规签署 | 需要业务/裁决/评分/安全负责人 | 业务签署后 |
| G4 生产并发压测 | 只有单进程基准，不是并发报告 | QA/运维按 Q-02/Q-03 |
| 搜索引擎提交/站长平台 | sitemap 默认关闭 | 运营 |
| CDN/反代缓存与撤稿 purge | 本批不启用页面缓存 | 运维 |
| 小程序真机分享/canonical | H5 DOM 写入只在 H5 条件编译 | 真机验证 |
| 收藏/关联/访问统计生产数据 | 新表为空、开关关闭 | 运营 |
| 通知渠道 | 只有站内信 outbox | 部署侧 |
| G3 并发/真机附件 | 客户侧全链路与 Admin 录分/发布 UI 已完成真实浏览器演练；MySQL 多连接并发、真机附件上传/下载未验证 | QA/运维 |
| CI 流水线 | 已提供 `.github/workflows/ecosystem-gate.yml`（契约/preflight/PHPUnit/Node），**尚未在 GitHub 运行**；浏览器演练仍靠本地门禁 | 运维（首次 push 后） |
| 生产密钥托管与旧 key 恢复 | 本机轮换已通过 | 部署侧 |
| 生产 `schedule:work`/cron | 未部署 | 运维 |

## 6. 怎么验证（不要凭空相信文档）

```bash
cd D:/main/my-bagisto-store
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test packages/Webkul/Shop/tests/Feature/Ecosystem
# 522 passed / 3,703 assertions

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/G4MetricsOpsAdminTest.php
# 13 passed / 495 assertions

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan ecosystem:g4-status
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan ecosystem:g4-benchmark --events=2000 --queries=20
```

```bash
cd D:/main && node --test tools/tests/ecosystem-g4.test.mjs tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs
# 37 passed
```

Q-01 ~ Q-05：

```bash
cd D:/main/my-bagisto-store

# Q-01 契约门禁（不需要数据库）
php artisan ecosystem:contract-check

# Q-02 / Q-03 / Q-05（必须独立测试库）
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q02/run.php --json=D:/main/docs/ecosystem/q02-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q03/run.php --json=D:/main/docs/ecosystem/q03-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q05/run.php --json=D:/main/docs/ecosystem/q05-result.json

# Q-04 浏览器（先导出 H5）
D:/HBuilderX/cli.exe publish web --project D:/main
node qa/ecosystem/q04/run.mjs --json=D:/main/docs/ecosystem/q04-result.json

# G2/G3/G4 浏览器 e2e（同样先导出 H5）
node qa/ecosystem/e2e/run.mjs --json=D:/main/docs/ecosystem/g3g4-e2e-result.json

# G3 Admin 录分/发布演练（只需 Chrome，不需要 H5）
node qa/ecosystem/admin-g3/run.mjs --json=D:/main/docs/ecosystem/admin-g3-result.json
```

一键门禁（仓库根目录）：

```bash
cd D:/main
bash tools/ecosystem-gate.sh                    # 契约 + preflight + 后端/Admin + Node
bash tools/ecosystem-gate.sh --with-admin-browser  # 另加 G3 Admin 录分/发布演练
bash tools/ecosystem-gate.sh --with-browser     # 另加 Admin 演练 + e2e（e2e 需已导出 H5）
```

Q-01 / Q-05 的 PHPUnit 专项：

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemContractGateQ1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemDataPreflightQ5Test.php
```

2026-10-20 回归结果：Ecosystem 全量 **522 passed / 3,703 assertions**；
Admin ACL/G2/G3/G4 专项（5 个文件）**22 passed / 547 assertions**；前端 Node **37 passed**；
G2/G3/G4 浏览器 e2e **39/39 通过**（含 1 项 observed）；G3 Admin 录分/发布演练
**12/12 通过**；清理后测试库 0 残留。
⚠ 两套 PHPUnit 不要并发跑同一测试库。

**测试纪律**：必须指向独立库 `summon_mecha_step10_test`（`APP_ENV=testing`）；
**禁止**对 root 执行 `migrate:fresh` / `truncate` / `seed`。

## 7. 下一步建议（按优先级）

1. **提交收尾改动**：已完成（`a634c2c`，含 e2e、Admin 演练、门禁与 CI workflow）。
   分支仍未 push；需要其他机器可见时先 push，再按 GitHub Actions 结果确认 CI。
   后续提交仍按路径 `git add`（禁止 `git add -A`），先处理
   `my-bagisto-store2 备份/` 的 git 红线；截图目录按既有约定不入库。
2. 提交前跑门禁：`bash tools/ecosystem-gate.sh`（契约 + preflight + 后端/Admin + Node），
   需要浏览器证据时加 `--with-admin-browser` 或 `--with-browser`；任何一项非零都先修。
3. 按 [G4-runbook.md](./G4-runbook.md) 阶段 0 → 3 逐项打开：先 Admin 指标/运营台，
   再搜索/关联，再 SSR/sitemap，最后收藏。
4. 打开 SSR 前，确保 `ECOSYSTEM_PUBLIC_SITE_URL` 与前端 `SSR_BASE_URL` 完全一致。
5. 打开任何公开入口前，先跑一次撤稿演练：API 410、SSR 410、sitemap 排除、收藏隐藏、关联省略。
6. 生产并发、浏览器、真机与搜索引擎收录按 evidence §7 补证；不要因为页面完整就宣称完成。
   Q-02/Q-03/Q-04 的本机结果不能替代生产规模复跑。
7. 任何一步发现方向偏差，**停下来重新对齐**，不要在错误代码上继续修补。

<!-- END ORIGINAL docs/ecosystem/HANDOFF.md -->


## 附录 4 项目理解手册全文

原路径：`docs/ai-project-brief.md`；SHA-256：`0f8a2e6155aac9fa2e898cbbdd8f0e85ba3c568738ec1a9d9de9f70c2e3d7243`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/ai-project-brief.md -->
# 机器人技能平台 · AI 协作者项目理解手册

> 这份文档写给**刚接手本项目的 AI**。
> 目的:让你理解**为什么做**,而不只是**做什么**——看完之后,你应该能自己判断一个新需求该不该做、怎么做才不跑偏。
> 生成:2026-10-14 ｜ 依据:主计划 v3.4、技能平台专项文档,以及对本机**代码 / 数据库 / 版本库的实测核验**(不是文档转述)
> 维护约定:**「平台边界」「不可谈判的决策」「里程碑」三节只能由人改**;AI 若发现它们与代码不符,请报告,不要自行修改。

---

## 0. 开工前五分钟清单(真的做,别跳)

1. 读 [NAVIGATION.md](../NAVIGATION.md) 定位代码,再读 [skill-dev-steps.md](./skill-dev-steps.md) 的【进度快照】确认"做到哪了"。
2. 确认你要动的那块属于哪一端:**用户端(uniapp)/ 平台端(Bagisto Admin)/ 开发者端 / 设备端(Agent)**。四端边界见 §4。
3. 明确你的改动落在**哪个里程碑**上(M1–M4,见 §6)。落不到任何里程碑的改动,大概率不该现在做。
4. 改任何后端字段/接口前,先读 §5(术语)与 §7(约定),再动代码。**术语混用是本项目最容易犯、最难发现的错误。**

---

## 1. 这个项目已经是什么

在"技能平台"出现之前,这里已经有一个**在运营的机器人服务商城**(品牌:召唤机甲,AI 助手叫"小召")。它不是一个空的脚手架:

| 维度 | 现状(数据库 / 代码实测) |
|---|---|
| 前端 | uni-app 跨端应用,16 个页面,底部 5 个 tab:首页 / 机器 / AI召唤 / 订单 / 我的 |
| 商品类目 | 一级类目:`租用`、`购买`、`配件`、`技能` |
| 真实订单 | 25 单,按类型:租用 7、技能 10、方案 3、定制 3、购买 2 |
| 已交付技能 | `skill_versions` 1 个、`skill_entitlements` 10 条(全部走人工通道)、`devices` 5 台 |
| 开发者 | `sellers` 0 个(**平台目前没有任何第三方供给**) |

已经闭环的既有能力(别重复造):**场景方案**、**多机合单租用**、**赛事报名**、**小召召唤引擎(AI 匹配)**、**订单六分类**、**客户自助取消**、**地址簿**、**客服工单**。

> 结论:这是"**已有真实客户与真实订单的传统机器人租售业务**",不是从零起步的创业项目。技能平台是长在它上面的第二曲线。

---

## 1.5 生态全景(这才是全貌:AI 召唤 + Agent)

> 本节的内容由项目负责人 2026-10-14 当面校正。**当它与其它章节的推断冲突时,以本节为准。**

**公司**:召唤机甲(上海)科技有限公司(SUMMON MECHA),定位是**全球商用机器人场景服务运营商**——官方口径明确写着"非制造商、非商城";口号 `One Call. Robots Everywhere.`;官网 `www.summonmecha.com`,三语(zh / en / ja),服务口径"**6 国 51+ 城**"(中国为总部与直属团队,其余 5 国由认证伙伴本地交付,总部统一调度)。

**目标形态**:做一个**机器人生态服务平台**。它最终的两个主要形态是:

1. **AI 召唤 = 超级入口**。用户用自然语言说清楚需求,平台直接给出机型 / 方案 / 报价 / 部署计划,并调度资源把它解决掉。
2. **Agent = 设备端入口**。用户给机器人装上 Agent 之后,在**完整生命周期**里持续获得能力:下载技能、优化算法、监督健康度……

**生态里的每一块,以及它现在的位置**:

| 板块 | 作用 | 现在在哪 |
|---|---|---|
| **全球官网** summonmecha.com | 获客与需求入口,靠 GEO/SEO 被搜索引擎与 AI 引用 | 已上线。资产在 **`D:\召唤机甲-全球官网`**(独立仓库:三语静态站 + 零依赖 Node 服务 + 线索后台 + AI 提问条) |
| **小程序 / uni-app**(本仓库) | 交易与交付主阵地:租用、购买、配件、技能、订单、场景方案 | 已上线运营 |
| **AI 召唤 / 小召** | 超级入口 | 官网侧是"AI 提问条 → `/api/recommend`";小程序侧是 `pages/ai` 的"小召"(Coze Agent 驱动 + ASR/TTS 语音闭环)。v1 可用,但离"超级入口"还远 |
| **技能平台**(v3.4 计划) | 供给侧:让机器人不断获得新能力 | 开发到第 8 步,缺口见 §10 |
| **Agent**(设备端) | 设备端入口:装卸回滚,以及未来的健康度 / 算法能力 | 只有平台侧;设备端本体与真传输属外部 |
| **赛事** | 生态运营与引流(报名、作品、奖金) | 已闭环 |
| **咨询 / 场景方案** | 线下服务的线上入口(场景租用、方案定制、多机合单、留资) | 已闭环 |
| **机器人数据交易** | **未来方向** | 未启动;计划里只有"数据集上传(P2)"与"数据集 / 训练工具链(阶段六)"两处种子 |

**这条校正对工程判断的三点影响**:

1. 技能平台**不是终点**,只是生态的一块。但这个终局是**方向锚,不是排期表**——它的用法是"别做挡住它的设计",不是"现在就去做它"。**眼下做什么,只看当前基线 + M1–M3**(见 §1.6)。
2. "下载技能 / 优化算法 / 监督健康度"属于**同一条 Agent 生命周期**。设计 Agent 相关能力时要放在这条线上想,不要各做各的。
3. §2.2 的边界描述的是**当前阶段**的边界。数据交易已被提出为未来方向,但尚未写进任何计划文档——**动它之前,先改边界定义**。

---

## 1.6 终局预想怎么用(2026-10-14 负责人提醒)

> §1.5 与 §1.7 都是**形态预想**,不是近期路线图。**眼下要按现实走。**

三条用法,别搞反:

1. **它是"否决器",不是"排期表"**。用来判断"这个设计会不会挡住未来",而不是"现在就去做那两个支点"。
2. **排期只看两样**:当前真实基线(§1、§10)+ 最近的里程碑(§6 的 M1–M3)。外部前提没到位的(支付资质、真机 Agent、EMQX、第三方开发者),一律不排期。
3. **把"现在的形态"和"最终预想"分开说**。对外承诺、验收标准、里程碑只按现在的形态写;预想放进方向里,不写进验收。

**照这个口径,眼下真正卡住的不是"要不要做 AI 召唤产品线",而是这几件**:

| 优先级 | 眼下的事 | 为什么 |
|---|---|---|
| 1 | **仓库卫生**:提交技能平台代码;处理 `my-bagisto-store2 备份/`(一次 `git add -A` 会删掉 2739 个已跟踪文件) | 前面做的所有事都没进版本库,误删即全丢 |
| 2 | **异步底座**:常驻 `queue:work` + `scheduler` + 至少一个真实定时任务 | 试用回收、通知、结算、灰度全依赖它;`jobs` 表已有积压未消费 |
| 3 | **补齐第 2/3 步未达标的判据**:v1 三接口改读 `device_binding`;技能包上对象存储 | 计划判据写着,现在不成立;越晚改,回归面越大 |
| 4 | **填货架**:自营技能从 1 个做到可上架批量(≥N) | M2 验收的硬前提;0 个开发者、1 个技能版本,"供给冷启动"是计划自评的高风险 |
| 5 | **AI 召唤**:维持小步改进(详情页/首页入口),**暂不立产品线** | 它现在能用;等前面几件补齐、付费验证有了结论再谈结构 |

---

## 1.7 更远一层:贾维斯级预想(最外圈)

> 2026-10-14 负责人补充。**这是最外圈的愿望,不是计划**;用法仍然只有 §1.6 说的那一种——否决器。

**愿望(负责人原话口径)**:平台最终**覆盖机器人领域的很多业务**;并且让**未来的普通人都能自己"手搓"机器人**——把造机器人的门槛降到非专业者也能动手。

> ⚠️ 措辞校正(2026-10-14):"贾维斯"指的是**普通人自己动手攒出一台机器人**,不是"每人配一个 AI 助手"。这两件事对工程的要求完全不同——前者要的是 **开源 + 配件/套件 + 工具链 + 降门槛**,后者要的是陪伴式订阅服务。别再按后者理解。

**这意味着平台要覆盖的业务面(对照现状)**:

| 业务板块 | 现状 |
|---|---|
| 租赁 / 销售机器人 | ✅ 在运营(租用 7 单、购买 2 单) |
| 场景方案 / 定制 / 咨询 | ✅ 已闭环 |
| 配件 | 🔶 类目在,**只有 1 个商品**(3D 视觉相机) |
| 技能交易与下发 | 🔶 开发到第 8 步(缺口见 §10) |
| 赛事 | ✅ 已闭环 |
| 机器人数据交易 | ❌ 未启动(计划里只有两处种子) |
| 开源 / 社区 | ❌ **全仓与文档零命中** |
| DIY 套件 / 组装工具链(设计、仿真、组装指南、BOM) | ❌ **全仓与文档零命中** |

**它要求我们现在就"别挡住"的五件事(是预留,不是排期)**:

| 预想 | 现在就不能挡住的 |
|---|---|
| 普通人能自己攒机器人 | "**图形化、不碰终端**"要从体验优化升级为**硬指标**(计划原话:终端 / 静态 IP / SSH 不是用户该承受的)。这条同时服务"装 Agent"和"手搓机器人" |
| 配件与套件 | 商品模型要能表达"机器人 + 配件 + 服务 + 技能"的**组合商品 / BOM**。现状:只有 `simple`(10)与 `booking`(1)两类产品,配件类目 1 个商品 —— 组合能力是空白 |
| 开源 | 技能包格式与 Agent 协议要能被**第三方独立实现**(协议公开、不锁自家云)。计划里已有同向原则:"不要把用户已购技能单点绑在某厂商云上" |
| 多业务覆盖 | §2.2 的边界要**分层**:内核(交易 / 授权 / 交付 / 安全责任)不动,外围(技能、配件、套件、赛事、数据、开源社区)可加 |
| 数据与素材 | 数据交易与"自造机器人"共享同一批底层资产(动作数据、机型元数据、仿真结果);现在这些散在各表里,没有"可对外开放"的口径 |

**它同时与几条已拍板决策存在张力(现在不用解决,但要知道会有这一天)**:

1. **"不做硬件" vs "配件 / 套件"** —— 建议边界细化为"不自研硬件,但可组合、可代销第三方配件与套件",否则每上一个配件都要重议一次。
2. **开源 vs 卖技能卖套件** —— "开源"和"配件打包"同时出现时,**必须先划清哪一层开源(协议 / 工具链 / 示例)、哪一层收费(认证套件 / 优质技能 / 服务)**,否则开源会把自己的收费业务吃掉。
3. **D6 企业级责任模型 vs 个人 / DIY 用户** —— 三层责任、保险、拟人化互动备案都是按 B 端设计的。一个人自己攒出来的机器人出了事故,**责任主体是谁**?这是"手搓机器人"能不能规模化的前提,不是技术细节。
4. **中立边界 vs 全业务覆盖** —— 边界每扩一块都要改一次,早点改成"内核 + 外围"的分层写法。
5. **别删后手** —— 主计划 §9 留了"时长授权字段保留以备未来",这类"为未来留的口子"不许被当成死代码清理掉。

---

## 2. 它正在变成什么

### 2.1 官方定位(主计划 §0.5,原文)

> **面向全球的机器人技能(动作 / 能力包)供需交易与下发平台**——开发者上架技能,用户购买并下发到**自有**机器人;平台负责**交易 / 审核 / 授权 / 交付 / 结算 / 安全责任**六件事。

### 2.2 明确不做的事(违反即算跑偏)

- ❌ 不是机器人整机 / 配件销售平台(那是现有商城业务,已存在,不需要"转型"过去)
- ❌ 不是训练 / 算力平台(不自建训练集群、不做数据采集外包)
- ❌ 不是硬件厂,不做固件、不做整机
- ❌ **不制造"所有品牌开箱即用"的幻觉**(这条最容易被 AI 好心办坏事地违反)

> ⚠️ 这四条是**当前阶段**的边界。未来方向(数据交易等)见 §1.5 —— 扩边界要先改这一节,不要在代码里"先做了再说"。

### 2.3 兼容性承诺分级(把"现实"写进承诺里)

| 级别 | 定义 | 对外承诺 |
|---|---|---|
| **L1 可交付** | 该机型已有可用通道(人工 / Agent / 厂商 API) | 承诺**下发可用** |
| **L2 可适配** | 有通用动作数据 + 重定向配置 | 只承诺"**可转换**",不承诺开箱即用 |
| **L3 仅展示** | 机型在库但无任何通道 | **只展示、不发售** |

> 做 UI 文案、做接口返回值、写文档时都要遵守这个分级。把 L2/L3 说成"开箱即用"是**对外承诺事故**,不是文案问题。

### 2.4 现实性分级(全计划的"总开关")

每个功能都必须标注:**现实 P0**(有底座、能落地)／**条件触发**(等外部前提)／**暂不现实**(只观察,不排期)。
**禁止把"条件触发 / 暂不现实"当 P0 排期。**

当前属于"条件触发"的典型:EMQX + Go 下发服务、设备端 Agent 本体、跨本体重定向、全球化多区域。

### 2.5 团队真实意图 ⚠️ 推断,不是官方措辞

主计划写的是"第三方中立技能交易平台",但团队评审文档暴露出更真实的当前打法。**这部分是依据推断,供你判断优先级用,不要当成对外承诺:**

1. **技能先服务自家业务,再谈中立市场**。评审直指"最大结构性错误是无视已有的租赁客户",建议做"技能 = 租赁增值服务"的第一方模式,护城河在**线下场景客户关系 + 交付 + 现场服务**。
2. **首批内容刻意选"暖场展演类"**(迎宾 / 舞蹈 / 合影),理由是"在硬件天花板下唯一能可靠交付、能卖钱的";生产力类技能**只做展示、不承诺可交付**。
3. **北极星指标存在分歧**:市场侧要求盯"每个租赁订单触发的技能次数"(价值),主计划 §4.13 用的仍是"可下发技能数 × 覆盖机型数"(库存)。
4. **开发者门户与全球化已实际降级**:官方写 P0,产品/财务判"空中楼阁",排期表里回到 P1;全球化已收敛为**中美双主体、逐区开通**。

**对工程的直接影响(记住这几条)**:
- 判据不是"架构多漂亮",而是"**能不能让一台真实用户的机器人多跑一个能收费的技能**"。
- 现阶段优先级:**manual 人工通道可用 > Agent 自动下发**;**诚实标注兼容性 > 扩大机型列表**;**自营填货架 > 等第三方开发者**。
- 任何"为了未来中立市场"而牺牲当前可交付性的设计,都要先被质疑。

> 补充(2026-10-14 负责人校正):上面说的是**当前阶段的打法**,终局形态见 §1.5(AI 召唤 + Agent 双支点)。两者不冲突——**先把技能做成卖得动的第一方业务,才有资本去做生态**;但任何设计都不要挡住那两个支点。

---

## 3. 不可谈判的决策(D1–D11,主计划 §0)

这些是**已拍板**的,不要推翻,也不要"优化"掉:

| # | 决策 | 一句话要点 |
|---|---|---|
| D1 | 设备归属 | `devices` 是**用户登记视角**;`device_binding` 是**全局真相源**(DID 唯一);下发链路只认 binding |
| D2 | 下发通道 | 三通道:`MQTT+Agent` / `厂商云 API` / `下载+人工安装`;技能声明通道 ∩ 设备可用通道 → 用户选 |
| D3 | 授权模式 | **买断制,无订阅**。买断用 `pricing_mode='buyout'` 表达,**绝不要写 `license_mode='perpetual'`**(两字段语义正交) |
| D4 | 试用 | 保留,**20 分钟自动回收**,**仅限在线通道**;人工安装通道无试用 |
| D5 | 技能包格式 | **不自创格式**:中立 `manifest.json` 超集 + 各厂商导入适配器。⚠ `manifest.yaml` 是旧称：`symfony/yaml` 未安装，`ManifestParser` 对 `.yaml` 会回落报错，**实际只有 json 可用** |
| D6 | 物理安全 | **三层责任模型**:开发者承诺 → 沙盒受控执行 + 仿真预跑 → 用户分级告知留痕 |
| D7 | 开发者门户 | **独立门户**,**不塞进 Bagisto Admin**；⚠ **现状是 Blade SSR**（`Resources/views/dev/*` + `Routes/dev.php`），**不是 Vue3 SPA**；独立域名属 E2。小程序/App 用 web-view 开轻量视图 |
| D8 | 合规 | **AI 三层备案** + 内容安全 + 个保法 + 机器人安全责任边界;**备案是外部审批,阶段 0 并行前置** |
| D9 | 熔断 | kill switch：对**新下发即时阻断**；对**已装设备**在其**下次上线**时卸载/回滚；**人工通道安装的当前无法自动卸载**；同时停止销售与下载（强度阶梯 `block_new` / `block_all`） |
| D10 | 售后 | 虚拟商品规则单列:试用不退 / 未下发可退 / 已激活原则上不退但可申诉 |
| D11 | 事故闭环 | 事故上报 + 责任追溯 + 责任保险 |

---

## 4. 四端与商业闭环

### 4.1 四端

| 端 | 技术 | 面向 | 归属代码 |
|---|---|---|---|
| **用户端** | uni-app(微信小程序 + H5) | 机主 / 租赁客户 | 仓库根目录 `pages/`、`components/`、`utils/` |
| **平台端** | Bagisto Admin(Laravel Blade) | 运营 / 审核 / 财务 / 客服 | `my-bagisto-store/packages/Webkul/Admin/` |
| **开发者端** | 独立 Web 门户(P1) | 第三方技能开发者 | `my-bagisto-store/packages/Webkul/Shop/src/Services/Developer/`(脚手架阶段) |
| **设备端** | 设备 Agent(外部) | 机器人本体 | 平台侧只留接口与脚本,见 `Http/Controllers/AgentSetupController.php` |

### 4.2 钱怎么走

下单 → 微信支付 → 订单 paid → **`Listeners/SkillDelivery` 幂等发放 `skill_entitlements`**(以 `order_item_id` 唯一键兜底)→ (P1) 平台分账 90/10 → `settlement` → 实名提现 → 对账。

> 幂等与"吞异常"是故意设计:建单重试不能重复发授权,发授权失败不能阻塞下单主链路。**不要"顺手"把吞异常改掉。**

### 4.3 货怎么走

开发者上传 `.skill` 或从他平台导入 → **转换层(skill_build)**编译出各机型载体 → **三层审核(L0 机审 / L1 仿真预跑 / L2 人审)+ 拒绝清单** → 上架 → C 端兼容性校验 → 三通道下发 → `install_record` → 回滚 / 熔断。

---

## 5. 术语表(必须统一,混用即 bug)

| 概念 | 唯一说法 | 取值 / 说明 |
|---|---|---|
| **发布通道** | `skill_versions.channel` | `stable` / `beta` |
| **交付通道** | `skill_versions.delivery_channels`(声明)、`skill_entitlements.delivery_channel`(单据) | `manual`(代码内仍叫 `app`,**两者等价,不改名**) / `agent` / `vendor:{code}` |
| **载体类型** | `skill_assets.carrier_type` | 以 `SkillAsset::CARRIER_TYPES` 为准:`file / cloud_config / remote_service / api_bundle / motion_data / app_package / vendor_package` |
| **授权范围** | `license_mode` | `per_device / per_order / per_account / unlimited` |
| **定价模式** | `pricing_mode` | `buyout` / `free` |
| **设备真相源** | `device_binding` | `devices` 只是登记视图 |
| **审核层级** | L0 机审 / L1 仿真预跑 / L2 人审 | 过审后再做**质量分级**:qualified / good / featured |

> ⚠️ 历史坑:旧文档出现过 `license_mode='perpetual'`、`carrier_type='xxx_package'` 之类**写不进库的值**。改字段前先看模型常量,不要相信文档里的枚举。

---

## 6. 里程碑(判断"该不该现在做"的尺子)

| 里程碑 | 业务含义 | 达成条件 |
|---|---|---|
| **M1 可售** | 真有人能花钱买到技能 | 微信支付真实闭环 → 授权 → "我的技能"可见 |
| **M2 可上架** | 技能能从外部进来并变成货 | 上传/导入 → 编译 → 三层审核 → 上架,且**垃圾被拒** |
| **M3 前端闭环** | 人工通道能真交付 | 买 → 下载 → 人工装 → 用户自报安装完成 |
| **M4 真机下发** | 不用人工,自动装 | Agent + 真传输 + 三厂机型真机下发成功 |

P0 验收 = **M1–M3**。M4 依赖外部(设备端 Agent、EMQX、厂商 SDK),属"条件触发"。

---

## 7. 工程地图

### 7.1 数据流(一条链路记牢)

```
pages/*.vue
  → utils/api.js 的某个 xxxApi
  → utils/request.js(uni.request 封装:注入 Bearer token 与 X-Guest-Key、统一超时、401 清登录态)
  → utils/config.js 的 BASE_URL
  → 后端 Shop/src/Routes/api.php(整组前缀 /api)
  → Shop/src/Http/Controllers/API/*Controller.php
  → Shop/src/Services/<业务域>/*
  → Shop/src/Models/*
  → 表
```

### 7.2 前端约定

- 页面是 **Options API**(`export default { data(), methods: {} }`),不是 `<script setup>`(导航文档此处写错)。
- **`onLoad` / `onShow` 必须与 `methods` 平级**;放进 `methods` 里框架不触发(历史上导致首页死卡)。
- 场景数据唯一源:`components/jiqi/changjing-data.js`;`components/jiqi/changjing.vue` 只做转发(小程序从 `.vue` 具名导出会丢绑定)。
- **mock 回退是刻意设计**:真实接口失败回退本地数据,页面不白屏。改的时候**保留回退,但不要把 mock 当唯一数据源**。
- 小程序与 H5 能力不同:流式接口在 H5 用 `fetch + ReadableStream`,小程序用 `enableChunked + onChunkReceived`。
- `unpackage/` 是构建产物,永远别改。

### 7.3 后端约定

- 包结构 `packages/Webkul/{Shop,Admin,...}/src`;自定义迁移放 `Shop/src/Database/Migrations/`(provider 自动加载)。
- 新模型直接 `extends Model`(**没有**用 Bagisto 核心的 `*Proxy` 套路);核心侧才用 Proxy。
- 鉴权四套,别用错:
  - `customer` = 强制登录(未登录会 redirect `/admin`)
  - `api.customer` = token 可选(游客放行)
  - `internal.key` = `X-Internal-Key`,内部/后台用,key 未配置则整组 503
  - `agent.key` = `X-Agent-Key`,设备端用,同上
- **配置注册表模式**:`config/{skill_delivery,deploy_gateways,payment_gateways,skill_builders,skill_importers,developer_portal}.php` 都是 `key => class`,加一条通道 = 加一行配置 + 加一个实现类,**业务代码零改动**。这是本项目最重要的可扩展骨架,新接入优先走这条路。
- 改后端 API 契约时,**前端 `utils/api.js` 与后端路由必须同步改**,字段名 / 方法 / 路径三处对齐。

### 7.4 数据模型(按域记,不用背全表)

- **技能资产**:`skill_versions`、`skill_assets`、`skill_build`、`skill_quality`、`skill_category|tag|tag_pivot|search_index`、`skill_import`
- **交付授权**:`skill_entitlements`、`devices`、`device_model`、`device_binding`、`deploy_task`、`install_record`、`gray_release`、`rollback_record`、`skill_blocklist`、`security_scan`、`audit_record`
- **交易运营**:`orders`(+`order_type`)、`after_sale`、`refund_request`、`notification`、`notify_template`、`settlement`、`withdrawal`、`reconciliation`、`operation_log`、`risk_acknowledgment`、`content_security_record`、`incident`、`compliance_filing`
- **开发者**:`developer_profile`、`developer_app`、`api_credential`、`skill_review`、`skill_report`、`support_ticket`
- **场景/赛事**:`scenes`、`events`、`event_registrations`

### 7.5 关键 API 前缀

| 前缀 | 鉴权 | 用途 |
|---|---|---|
| `/api/skills/*` | `customer` | 我的技能、兼容性校验、资产下载、绑定设备、自报安装 |
| `/api/devices/*` | `customer` | 用户登记设备 |
| `/api/payment/*`、`/api/after-sale`、`/api/notifications`、`/api/tickets` | `customer` | 支付下单、售后、通知、工单 |
| `/api/deploy/*` | `customer` | 建下发任务、查状态、取消 |
| `/api/skill-supply/*`、`/api/skill-admin/*` | `internal.key` | 包检查、导入、编译、提审、审核、建版本 |
| `/api/agent/*` | `agent.key` | 设备注册换 DID、心跳、回报、拉指令 |
| `/api/skill-catalog/*`、`/api/scenes`、`/api/events` | 公开 | 类目/标签/搜索、场景、赛事 |
| `/admin/*` | admin guard | 后台运营 |

---

## 8. 工程纪律(本项目最看重的部分)

### 8.1 改之前

- 先用 `rg` / `grep` 确认**唯一定义与所有引用点**,再动手。历史上 AI 因为不看引用而误删过代码行。
- 有风险的改动先备份到 `.claude/backup/<任务号>/`。
- 涉及字段 / 枚举 / 术语时,先回到 §3 与 §5 对齐。

### 8.2 怎么验证(必须实测,不许"看代码觉得对")

沿用项目既有手段:

- 前端:`node --check`;H5 起服务后**截图**确认渲染
- 后端:`php -l` 语法检查、`artisan migrate:status`、`artisan route:list`、`artisan tinker` 跑探针
- 接口:`bash tools/api-regression.sh` 一键体检
- **验收三问**:功能健康吗?这一步真的完成了吗?符合战略(§2)吗?——**任何一问不过就停下来报告,不要继续推进**

### 8.3 提交与留痕

- **小步提交**:一个步骤 / 一个可验收点 = 一次 commit,写清"改了什么、为什么"。
- **交付文档**:每步产出 `docs/skill-dev-stepN.md`,必须含:改动清单、**实测证据**、备份位置、验证方式、**回滚命令**、未做的部分。
- **诚实标注 > 好看**:没做就写"未做",部分做就写"部分完成 + 缺口"。**过度标 ✅ 在本项目已经真实发生过**(第 2、3 步曾被标成"完成",实测判据并不成立)。

### 8.4 文案与诚实纪律

- 不做假登录、假按钮、假数据;未实现的功能写"开发中",不要伪造成功。
- 安装命令等由平台生成,**不要在文档里手写**一段并不存在的命令。
- 只在用户明确要求时才调用"计算机控制"类能力。

### 8.5 禁止清单(硬性)

- ❌ 把"条件触发 / 暂不现实"的功能当 P0 排期
- ❌ 让境外模型对境内用户提供生成式服务;敏感生物特征 / 摄像头数据出境
- ❌ 接入或篡改厂商云(触厂商 EULA 红线)
- ❌ 让厂商专有技能跨机型流通
- ❌ 把 L2 / L3 机型宣传成"开箱即用"
- ❌ 用 `license_mode='perpetual'` 表达买断
- ❌ 未经批准就改变已拍板的架构方案(**先报批、再动手、并留档**)

---

## 9. 已经踩过的坑(照着躲)

**前端**
- 首页 / 列表不刷新 → `onLoad` / `onShow` 被放进 `methods`;必须放页面顶级。
- 加了排序后数据"消失" → 模块级数组非响应 + async 未 await + computed 不重算;用版本号变量强制重算。
- 首页写死兜底数据 → 掩盖真实接口 bug(曾用 14 项写死数据兜底)。
- 下单变成"意向单" → 前后端字段名不一致(`product_id` vs `bagistoId/id`)。

**后端(Bagisto 特有)**
- 商品列表空白 → `product_flat` 缺行。
- DataGrid 列表 500 → query 没有 `select` 新增列。
- 图片上传失败 → `media.images` 组件拿不到 `input.files`;改用原生 input;GD 处理 webp 失败改 `storeAs` 存原图。
- 订单永不落库 + 无限重试 → `booking` 类型产品建单抛异常,而 catch 无终止条件。
- 下单时间错 → 时区(项目曾配成 `Asia/Kolkata`)。
- 游客订单查不到 → `customer_id=null` 被登录中间件过滤。

**部署 / 运维**
- 图片裂 → `Storage::url()` 产出 `localhost` 绝对地址;前端有 `normalizeImageUrl` 兜底。
- 后端只能有一个入口:`127.0.0.1:8000`;出现多余 serve 进程要清理。
- 改后端后需重启 PHP / 清缓存。

**AI 自身的失误(重点反省)**
- 以 `export default {` 为锚插入代码时**漏补回锚行** → 编译失败。
- 被空 body 的 422 误导,方向跑偏。
- 死代码残留(旧数据源变量没删)。
- 在页面上承诺了**并不存在**的 `install.sh` 脚本与路由。
- 把没真正达标的步骤在交付文档里标成"✅ 完成"。

---

## 10. 截至 2026-10-14 的真实状态(实测结论,会变)

> 这一节是**核验结论**,不是文档转述。与文档不一致时,以能跑起来的代码 / 数据为准,并报告差异。

**里程碑**
- M3 前端 manual 闭环:**已达成**(下载 + "我已安装完成"自报都在 `pages/mine/skills.vue`)
- M2 可上架:主体齐(manifest 校验 / 三层审核 / 拒绝清单 / 质量分级 / 转换层 / 两种导入器 / 类目与标签已入库),**内容安全仍是占位**
- M1 可售:**未达成**,卡真实微信支付(资质未到,当前 `PAYMENT_GATEWAY=mock`)
- M4 真机下发:**仅平台侧**(签名 / 状态机 / 熔断 / Agent 接入接口 / 一键配对页都已存在),真传输与设备端 Agent 属外部

**其它生态资产的状态(不在本仓库,但同属一个平台)**

- **全球官网**(独立仓库 `D:\召唤机甲-全球官网`):三语站 + 零依赖 Node 服务(`server/`)+ 线索 JSONL + `/admin` 后台 + AI 提问条(`/api/recommend`,规则引擎兜底 + 可选 LLM)已上线;仓库里还有 11 个未提交改动,且它不在本仓库的 git 里。
- **AI 召唤**:官网侧是"规则引擎 + 可选 LLM"的**需求匹配**,不是真正的调度;小程序侧"小召"由 Coze Agent 驱动,带 ASR/TTS 语音闭环。离"超级入口"的差距:两端未统一,需求还无法直接变成可执行的调度动作。
- **Agent**:只在**平台侧**存在(注册换 DID / 心跳 / 回报 / 拉指令 / 签名 / 状态机 / 熔断)。心跳只上报 `did` 与在线态,**没有任何健康度字段**(电量 / 温度 / 错误码),`device_binding` 表也没有;计划里 Agent F1 写了"健康上报",但**代码未实现**;"优化算法"在计划与代码里都不存在。
- **机器人数据交易**:未启动,只有两处计划种子。

**明确未完成(别以为已经有了)**
- 第 2 步:`v1` 三个接口(`mine` / `bind` / `confirm-install`)**仍按 `devices.customer_id` 取值**,没有改读 `device_binding`
- 第 3 步:技能包**仍在本地盘**(`SkillAsset::DISK = 'private'`),对象存储未接入
- 第 5 步:机型库 Admin CRUD 未做(只能改种子 / SQL)
- 第 7 步:计划里的**微信登录**没有实现,且交付文档未提及
- 异步底座:队列 / 缓存是 `database` 驱动(非计划里的 Redis),**没有常驻 `queue:work`、没有 `schedule:work`**,`jobs` 表有积压任务未消费
  - ⚠ **2026-10-15 就地更正**：本行原文还说「**没有任何定时任务定义**」—— 那句已失效。
    `bootstrap/app.php` 的 `withSchedule()` 现已排 **4 条 ops 任务**（备案到期 / 工单超时 /
    事故未结案 / **队列无人消费**），`schedule:list` 实测 9 条。
    剩余缺口仍成立且只剩一条：**这两条常驻进程本机没起**，所以任务等于不存在。
    其余行未重新核验。见 [`skill-runbook.md`](./skill-runbook.md)。
- 第 8 步:计划要求 `EMQX + Go 服务`,实际改成 **HTTP 轮询**(`deploy_gateways.default = 'poll'`),属**未经批准、无留档的架构变更**

**仓库卫生(动 git 前必看)**
- 技能平台大量新代码**尚未提交**(untracked),另有约 60 个既有文件已修改未提交 → 没有 diff 可供评审,误删即永久丢失
- `my-bagisto-store2 备份/` 是由 `my-bagisto-store2/` **改名**而来,git 认为**2739 个已跟踪文件被删除**。**此时执行 `git add -A` 会把整个备份从版本库里删掉。** 动 git 前必须先处理这件事。
- 存在敏感配置:`APP_DEBUG=true`、开发用 `AGENT_KEY`;前端还硬编码了厂商内网 IP 与地图 key

**文档可信度(哪些能信)**
- 权威进度:`docs/skill-dev-steps.md`【进度快照】 > 各 `skill-dev-stepN.md` > 主计划 §7 排期
- 已发现的矛盾:`NAVIGATION.md` 称页面为 `<script setup>`(实为 Options API)、称 `robot/index.vue` "6 万行"(实际约 2800 行);`skill-dev-step8.md` 写"default=mock"但代码已是 `poll`;主计划 §6 排期与 §7 阶段表对开发者门户的优先级不一致。

---

## 11. 遇到新需求,怎么自己判断(决策准绳)

按顺序问自己 6 个问题:

1. **它服务哪个里程碑?** 落不到 M1–M3 的,先别做(除非有人明确指派)。
2. **它属于"现实 P0"吗?** 依赖 EMQX / 真机 Agent / 第三方开发者 / 境外主体的,都是条件触发。
3. **它对"真实用户的一台机器人"有直接价值吗?** 没有的话,再漂亮也是库存。
4. **它违反 §2.2 边界或 §3 决策吗?** 违反就砍,或先报批。
5. **它让对外承诺更诚实,还是更浮夸?** 必须是前者(L1 / L2 / L3 分级)。
6. **它能不能走既有骨架?** 优先用配置注册表 + 服务类扩展,不要新造一套。

---

## 12. 文档索引(权威顺序)

| 想知道 | 读这个 |
|---|---|
| 项目定位、边界、决策、数据模型、功能设计 | `docs/skill-supply-plan.md`(主计划 v3.4,纲领) |
| **做到哪了 / 还剩什么** | `docs/skill-dev-steps.md` 的【进度快照】(唯一权威进度) |
| 某一步的实现细节与实测证据 | `docs/skill-dev-step2-3.md`、`step4` ~ `step8` |
| 任务 / 人日 / 依赖 | `docs/skill-p0-board.md` |
| 代码在哪 | `NAVIGATION.md`(注意其中有过期描述) |
| 设备 Agent 与厂商适配 | `docs/agent-adaptation-plan.md` |
| 技能转换层(编译器) | `docs/skill-standardization-chain.md` |
| 质量体系与过审门槛 | `docs/skill-quality-and-review.md` |
| 用户怎么装技能 | `docs/skill-user-tutorial.md` |
| 机型库种子依据 | `docs/device-model-seed.md` |
| 历史踩坑与崩溃修复 | `docs/scene-merge-log.md`、`docs/ai-changelog.md`、`docs/UPGRADE-NOTES.md` |

---

## 附录 · 一句话总结

**这不是一个"技能商店"项目,而是一个要覆盖机器人领域很多业务的平台。** 近处看,它在做"机器人场景服务 + 交易交付 + 技能生态"(两个支点:**AI 召唤** 是入口,**Agent** 是设备端);最远处看,它想让**普通人也能自己攒出一台机器人**。

所以每写一行代码,最终都要回答三个问题:
- **它有没有让"用户说清一句需求"这件事变得更强?**(入口)
- **它有没有让"机器人装上 Agent 之后的每一天"变得更有用?**(设备端)
- **它有没有让普通人对机器人的门槛更低一点?**(覆盖面与平民化)

<!-- END ORIGINAL docs/ai-project-brief.md -->


## 附录 5 Agent与标准转码器ADR全文 仅第一阶段授权生效

原路径：`docs/adr/ADR-0001-device-agent-and-standard-transcoder.md`；SHA-256：`d0f62031bde39de4ea53e0b2fb24aac72341fe3b4e437c9b1670e78c0770ac38`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/adr/ADR-0001-device-agent-and-standard-transcoder.md -->
# ADR-0001：设备端 Agent 边界与标准转码器分层

- 状态：**Proposed，待项目负责人审批；本次仅修订文档，不批准业务实现或生产开放**
- 日期：2026-11-03；修订：按原始证据与反例评审更新
- 决策范围：设备端 Agent、技能载体、平台离线构建、下发与恢复契约
- 本轮目标补充：一份标准源、多机型成品及行为执行；完整开发规格见蓝图 §17—§21，全部新增实现仍待开发
- 代码核验基线：`main@542502b64b817f35ab9f31f382564a2ea9dfe849`
- 原稿基线：`origin/main@f176d6427d55a633e94b20b01d01c0e7400cc075`；与代码基线仅差两份新增文档
- 配套规格：[重构版落地蓝图](../agent-runtime-standard-transcoder-blueprint.md)
- 既有约束：[CLAUDE.md](../../CLAUDE.md)、[项目理解手册](../ai-project-brief.md)、[DEV-04 等既有裁决](../skill-supply-demand-audit-and-blueprint.md)

## 1. 背景与已核验基线

本 ADR 固定职责和验收边界，不把接口存在、状态成功或一份计划视为交付完成。原稿对外部《具身智能超级助手与云端赋能平台》的描述未经本轮原件核验，不作为实现事实或厂商能力的证据。

| 事项 | 本轮核验结论与原始入口 |
|---|---|
| 设备端代码 | [AgentSetupController](../../my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/AgentSetupController.php) 内嵌参考 Python Agent，已有验签、解包、落盘和生命周期操作；不能再写“仓库中没有 Agent”。尚未证明生产 InstallAdapter、厂商健康检查或真机恢复能力 |
| 构建消费路径 | [SkillDeliveryAssetResolver](../../my-bagisto-store/packages/Webkul/Shop/src/Services/SkillDelivery/SkillDeliveryAssetResolver.php) 已按机型读取成功的 `skill_build`；缺稳定真实生产者和强制闸门。[MotionCarrierBuilder](../../my-bagisto-store/packages/Webkul/Shop/src/Services/SkillCompile/Builders/MotionCarrierBuilder.php) 仍返回 `pending` |
| 交付类型 | [SkillAsset](../../my-bagisto-store/packages/Webkul/Shop/src/Models/SkillAsset.php) 的 `isFile()` 只认 `file`；`isFileBacked()` 才包含其他落盘载体。指纹、解析和 Agent 下载尚不能直接按新成品类型接线 |
| 签名与身份 | [SigningService](../../my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/SigningService.php) 未签操作、目标设备和授权期限；[鉴权配置](../../my-bagisto-store/config/deploy_gateways.php) 默认 `auto`，仍有共享身份路径。已有 per-device 模式，不应另造一套鉴权 |
| 恢复与回滚 | 参考 Agent 丢失 `done` 后没有持久补报；[GrayReleaseService](../../my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/GrayReleaseService.php) 用坏版本生成回滚任务，参考 Agent 可再次安装坏版本却标 `rolled_back` |
| 证据范围 | 本轮执行的是无数据库、无网络、无真实设备的隔离类/函数探针；完整 HTTP/数据库链路、双环境重建、生产配置、厂商包与 HIL **未验证**。旧交付记录仅作线索，不推定今天的真实部署状态 |

## 2. 需要决策的问题与旧裁决

本次提出：Agent 做设备侧发布管理；转码属于平台离线构建；中立 manifest 不变；实时运控与远程接管另行立项。以上新增方案仍待审批，D1–D11 不因本次文档修订而改变。

特别保留两条旧边界：

1. **D6 三层物理安全责任继续有效**：开发者承诺、平台沙盒受控执行/验证、用户分级告知留痕。厂商保持实时运动控制职责，不意味着厂商承担平台和开发者的全部安全责任。
2. **DEV-04 已驳回多租户云端黑盒转码**。若拟议 `motion.retarget` 面向受控供给、白名单工具或自营场景，必须先说明与旧裁决的差异、适用对象、责任人及批准条件；否则不实施。蓝图 Phase 6 动作专项为条件性研究方向，**尚未立项，也不自动进入承诺排期**；Phase 5 第二品牌行为交付不依赖该专项。

## 3. 拟议决策与验收约束

### D-A1：Agent 是设备侧发布管理器

Agent 负责设备注册/配对、认证、能力与健康上报、领取任务、验签验包、调用厂商安装插件、生命周期管理、恢复与事实回报，以及经授权的熔断处置。

Agent 不做现场动作重定向、厂商包编译、关节/步态/平衡仲裁，不替代厂商控制器或硬件急停，不提供远程摇杆、视频接管，不自行放宽兼容性。

所有安装、启停和安全退出只能走目标版本已确认支持的接口；不得从“存在高层 SDK”推断“任意技能可安装”。厂商控制职责与 D6 责任共同约束交付。

### D-A2：标准转码器属于平台离线构建面

构建异步执行于受控 worker，不进入 Laravel 请求线程或设备运行时。顺序固定为：

```text
中立输入 -> profile/权限/风险校验 -> CarrierBuilder
-> 不可变候选产物与构建证据 -> 审核/发布 -> 交付选择
```

构建成功只证明约定的产物检查通过，不等同于审核通过、安装成功、运行正常或安全可交付。`vendor.passthrough` 可先验证单机型成品交付；不证明动作重定向。

构建身份必须覆盖源、manifest、目标 profile、构建器、工具链、依赖和规范化选项。相同身份可复用成品，但仍要重查发布/撤销状态；不能用缓存命中代替独立重建。细节见蓝图 §4–§6。

### D-A3：公开清单保持中立，不另立 `.rsp` 格式

继续消费 `zhaohuan.skill/v2` 的 `manifest.json`；载体词表以 `SkillAsset::CARRIER_TYPES` 为准。应用内部可含经过审核的脚本或厂商载体，不得绕过清单权限、安全、兼容性与审核链路。

**待 P-04 决定**外层交付封装与内部载体映射：可评审“外层 `file`、内层声明厂商载体”或显式扩展既有交付规则；不得仅因落盘就把产物视为当前 `isFile()` 可交付，也不得未经批准直接放宽该谓词。pass-through 保持的是厂商 payload 字节；若增加外层封装，其摘要与 payload 摘要须分开。包总哈希不得自包含于被哈希的同一包中。

平台再封装须在 P-04 中明确与 DEV-02“不得代客打包”的差异；未批准时只验证既有合规成品并外置元数据，不从裸文件生成合规包，也不代填开发者责任声明。

### D-A4：按操作执行身份、产物和授权闸门

| 操作 | 必须检查 | 不得错误附加的条件 |
|---|---|---|
| 安装、升级、带明确恢复包的回滚 | 成功且可追溯构建、真实产物与摘要、有效审核/发布、目标环境、该操作权限；安装/升级还需有效使用授权和未被禁止启用 | 不得用坏版本身份代替恢复目标 |
| 启用 | 已安装的获准产物、有效授权、环境和撤销/熔断状态 | 不因“不需要下载”跳过使用授权 |
| 执行（7，提案） | 已安装且启用的目标release、能力/类型化参数、有效使用授权、执行期限及资源范围 | enable不自动触发实体动作，源XML包不能直接执行 |
| 取消执行（8，提案） | 真实设备、完整签名、明确execution_id和处置权限；独立观察安全停止 | 不因使用授权过期或包缺失挡取消，不全局停止其他应用 |
| 停用、卸载、回收 | 有效设备身份、完整任务签名、处置权限、正确设备/技能作用域及幂等性 | 不要求未过期使用授权、当前成功构建或下载包；不因该技能已熔断、停卖或安装入口关闭而阻断处置 |

应急回滚如需安装旧包，仍需校验该**恢复包**获准、未撤销且适用；无法满足时走已批准的安全停用/卸载或人工处置，不能借“应急”安装未知产物。`manual` 继续保留人工安装闭环及其无法自动卸载的限制。

生产开放前必须冻结版本化签名 envelope，绑定操作、目标设备、目标产物/环境、授权期限与重放标识；规定唯一编码和验签规则，禁止旧弱协议回退。生产使用 `AGENT_AUTH_MODE=device`，关闭共享身份退路，并验证独立凭据吊销后所有旧入口失效。

建任务、领取任务和设备执行前均重查与该操作相关的授权、绑定、撤销和环境条件。离线旧安装不能因曾获批准而绕过现时限制。具体协议见蓝图 §4、§7、§8。

### D-A5：安装提交、回报与恢复必须可对账

```text
验签/作用域/时效检查 -> 持久接收任务 -> 下载/哈希/解包预检
-> staging -> 插件 prepare/环境预检 -> 安装/健康检查
-> 持久提交安装事实与待发事件 -> 补报/确认 -> 独立事实对账
```

- 任务 journal 和待发事件必须在重启后保留；网络投递标记不等于设备已持久接收。
- 插件外部副作用必须有可查询结果、幂等步骤、恢复点或补偿；本地目录原子切换不代表厂商接口事务原子。
- `timeout` 表示结果未知，不得据此认定设备未安装。迟到事实通过受验证的对账流程收口，不盲目改写原终态或重建任务掩盖未知状态。
- 回滚明确区分待撤回坏版本与恢复目标版本/资产/摘要；设备侧独立读取的激活版本和实际 payload 必须与目标一致，`rolled_back` 标签不是验收证据。
- 首装无旧版本时，按已批准策略安全停用/卸载；无法安全恢复时保留证据并转人工，禁止伪造旧版。
- 试用起算、签名期限、重启/时钟异常和到期安全退出规则待 P-03 冻结；云端和设备不得各算一段二十分钟。未通过离线反例前，不宣称可靠离线回收。

### D-A6：实时运控与远程接管另行立项

平台直接速度控制、运动指令仲裁、平衡/急停替代、远程摇杆、LiveKit 音视频接管和多品牌实时控制均不随本 ADR 获批。另立决策并评估厂商支持、安全责任和真机验证。

### D-A7：L1 与真机证据绑定到明确组合

- **L1 可交付**：获准目标产物及指定验证范围成立；HIL 绑定硬件变体、固件、SDK、Agent/插件、profile、产物摘要、动作参数和测试环境。
- **L2 可适配**：有受支持的来源/适配路径，但缺少目标成品或规定验证，仅能声明可适配范围；不能从字段声明推定可转换成功。
- **L3 仅展示**：没有可用交付路径或证据，不发售。

关键组合变化必须重新判定证据适用性，不能继承一个机型级 `verified` 布尔值。一次 HIL 不覆盖未来所有动作；签名、SBOM、仿真、哈希和标签均不能单独证明运动安全。

单机型通过只支持该组合的 L1；[项目理解手册 §6](../ai-project-brief.md) 中 **M4 的三厂真机下发口径不变**，不得将单机型试点写成整体 M4 完成。

### D-A8：可移植行为与动作重定向分层实施

推荐在既有manifest v2内定义受限BehaviorTree.CPP XML4源profile，采用标准能力节点、规范化IR与版本化厂商binding生成成品应用；不另造公共技能包后缀，不自动转译任意Python/C++。节点语义、参数、完成、取消和安全退出必须可观察；缺能力严格拒绝，不能静默替换原技能。

Agent管理发布与运行监督，独立runner执行受控高层编排；它不发送实时关节流或替代厂商安全控制。enable仅允许调用；拟新增执行/取消操作7/8复用任务运输，并以skill_execution记录行为事实，不能把执行成功写成安装事实。新码、签名、ACL和错误码均为提案，旧1—6不改值。

执行按execution_id去重，不沿用生命周期的did/version/operation合并规则。停用/卸载/回收先持久冻结新执行并提升撤销代次，再安全取消和清理；旧排队任务不能在停用后恢复动作。执行与取消均绑定完整的v2意图，取消受理不等于物理停止。

同一源版本的多个目标采用不可变目标发布快照单独审核，source不能通过成品接口交付；新目标不覆盖既有已审核字节或版本指纹。详细的角色/索引迁移、同源两品牌金标和B0—B7退出条件见蓝图§19—§20。范围按P-01/P-04审查，完整通用SDK/CLI仍不自动立项。

## 4. 方案对比

| 方案 | 本 ADR 建议 | 主要理由/代价 |
|---|---|---|
| 平台离线构建 + Agent 安装成品 | 建议采纳，待 P-01 审批 | 审核字节可锁定，设备故障面可收敛；仍需真实构建器、环境治理与恢复机制 |
| 设备现场转码 | 本次不采用 | 审核对象与安装对象难以绑定，增加设备工具链和交付失败面 |
| 平台接管实时运控 | 本次范围外 | 超出当前服务平台与 Agent 边界，须另行立项 |
| `.rsp` 自定义公共运行时 | 不采用 | 保留 D5 中立清单和厂商适配器约束，避免重复事实标准 |

## 5. 结果与影响

本方案可复用现有资产、签名下载、设备身份和任务状态机，先验证单机型成品交付；新增成本集中于构建隔离、有效输入身份、设备持久恢复与厂商插件验证。所有能力均须分别验收，不以模块数量或状态流转代替结果。

本轮结论支持继续契约与隔离 MVP 设计；不证明生产 Agent、可靠离线试用、真机回滚或自动动作转码已具备。真实部署配置、厂商包、目标设备和 HIL 资料仍待取得。

## 6. 实施约束

1. 仅复用已核验入口；新增字段、错误码、签名版本和状态语义先冻结契约，不能由调用方直接写构建成功。
2. 产物、构建历史和已签证据不可原地改写；候选构建、审核、发布准入和撤销是不同事实。
3. 构建 worker 与 Web/业务队列隔离，依赖/网络/资源受控；设备不运行构建或动作重定向工具链。成品内合法运行脚本按清单权限管理。
4. 发布和恢复只接受可追溯成品；更新源、profile、依赖、工具链或有意义选项必须改变有效构建身份。
5. 设备认证、任务验签、幂等和反重放同时成立；传输加密不能替代端侧执行意图校验。
6. 新闸门保留应急处置通路；防降级与显式回滚须区分，但恢复目标仍必须获准。
7. 第三方技能不能绕过平台声明权限、Agent 停用机制和厂商安全退出接口。
8. 本次仅改文档；后续代码、迁移和生产开放不由此自动授权。测试使用隔离环境和独立库 `summon_mecha_step10_test`。

## 7. 验收门槛

以下均为**待实现/待整链验证门槛**，不是本次通过清单。蓝图 §10、§11、§14 给出执行规格。

| 门槛 | 必须观察到的结果 |
|---|---|
| E1 成品身份 | 实际包走审核→发布→任务→下载，资产与字节摘要一致；替换产物或改变环境不能无证据继续下发 |
| E2 权限与期限 | 篡改操作/设备/期限和超期命令在副作用前拒绝；同一任务重试仅返回持久化的既有结果，不重复安装；吊销后所有旧认证路径失效；过期离线安装不执行，安全处置仍可达 |
| E3 恢复与回滚 | 各持久化/外部调用/回报边界中断后状态可收敛；设备激活目录、摘要、运行状态与平台最终一致；回滚恢复明确良好目标，首装无旧版按批准策略处置 |
| E4 构建与真机 | 改变工具链不误用缓存，双环境按定义独立重建；对固定组合确认真实安装/启停/安全退出接口，独立设备观测支持规定 HIL；断网、断电、磁盘不足、错误机型/固件和回滚有证据 |

隔离探针已复现载体筛选、签名语义缺口、共享身份分支、`done` 丢失和坏版本回滚反例；未执行完整数据库/HTTP、双环境构建和真机实验。修订文档不能把这些缺口标成完成。

可移植产品的追加门槛：同一源摘要在两个品牌生成成品并满足相同的能力轨迹；完成/取消由设备事实确认，不只检查SDK接受、BT halt或RPC成功。缺能力的目标必须准确拒绝；动作重定向另按蓝图§18验证格式、策略及HIL。

## 8. 发布回退与设备处置

1. 停止接收新构建/新安装，保留查询、补报、对账和安全处置能力。
2. 用独立发布准入/撤销事实禁止问题产物继续使用，**不得把历史成功构建改成 failed/pending**；失败重试产生新 run。
3. 保留原资产、构建、审核、任务、安装与验证证据，记录撤销原因和影响范围。
4. 对已安装设备执行签名且有作用域的停用/卸载/获准回滚；离线设备在下次上线处理，不承诺云端即时生效。
5. 不能安全恢复的设备记录未知/人工处置结果；manual 通道仅用于仍获准的产物，不作为已撤销技能的绕行入口。
6. 修复后生成新构建/发布证据并重新验证，不复用被撤销产物，也不靠关闭 Agent 总入口阻断后续处置。

## 9. 待负责人拍板事项

以下六项与蓝图 §15 使用同一编号。**当前均未批准**；具体决定应补日期、批准人、范围和证据，不能由 AI 替填。

2026-11-03 用户补充的品牌方向：优先考虑主流品牌热门机型，候选包括宇树、智元、优必选、众擎和小米；“重擎”暂按前文众擎（EngineAI）理解。该方向补充不等于具体型号、开发权限、首批顺序或动作转码立项已批准，详见蓝图 §15.1。

| 编号 | 待决定事项 | 未决定时的边界 |
|---|---|---|
| P-01 | 受限标准行为/能力编译与执行范围及其与完整SDK/CLI未立项的差异；DEV-02封装、DEV-04受控动作转码的责任与立项范围 | 保留旧裁决，不承诺任意代码转译、低层运控或自动动作转码排期 |
| P-02 | 在用户指定的优先候选品牌中确定首个硬件/固件/SDK 组合、厂商真实安装与安全退出接口，以及单机型 L1 验证范围 | 品牌方向已明确，具体组合仍待批准；不猜测接口，无真机证据不标 L1，不改 M4 三厂口径 |
| P-03 | 试用起算事件、离线期限、重启/时钟异常策略和到期安全退出 | 两端不各算二十分钟；不开放未验证的离线试用能力 |
| P-04 | 外层file映射与不可变目标发布快照、source/artifact角色、审核scope迁移及DEV-02封装边界 | 不直接改isFile，不自动再封装，不用旧上传槽覆盖成品或绕过发布锁 |
| P-05 | 推荐Python Agent＋SQLite＋固定BT.CPP runner＋厂商能力子进程；明确实现、接入、HIL及恢复值守责任 | 推荐实现栈未生产验收，不把参考Agent或SDK接受响应当运行完成 |
| P-06 | 签名信任根、任务与产物密钥分工、轮换/撤销方案，以及 TargetProfile 维护责任人 | 不开放生产签名体系；profile 变化必须受审并记录摘要 |

审批后按需要形成具体协议 ADR；实时接管独立立项。本节不得替代 D1–D11 的正式变更程序。

## 10. 决策摘要

**拟议采纳：平台离线构建 + 设备端发布管理 Agent，先做受控单机型成品交付。**

**保持：D5 中立清单、D6 三层安全责任、D9 离线处置限制及既有 M4 验收口径。**

**待审批：P-01–P-06；待验证：E1–E4。文档修订不代表这些决策、实现或验收已完成。**

<!-- END ORIGINAL docs/adr/ADR-0001-device-agent-and-standard-transcoder.md -->


## 附录 6 完整开发蓝图全文 第二阶段仅作背景

原路径：`docs/agent-runtime-standard-transcoder-blueprint.md`；SHA-256：`2d8f4c3e9148946b396d0c447d5d34d1511f060298a0cbd035dc12b25c93eaf5`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/agent-runtime-standard-transcoder-blueprint.md -->
# 机器人技能设备运行时与标准转码器 · 重构版落地蓝图

- 版本：0.3（一份标准技能、多机型编译与执行的完整开发方案）
- 日期：2026-11-03
- 状态：**Draft for review，待 ADR-0001 审批；本次只修订文档，未实现下述新增契约**
- 决策记录：[ADR-0001：设备端 Agent 边界与标准转码器分层](./adr/ADR-0001-device-agent-and-standard-transcoder.md)
- 范围：标准行为/能力契约、平台离线编译/动作转换、资产交付、设备端安装与执行生命周期；不含实时运控、遥操作、厂商云或固件开发。
- 原稿：`f176d6427d55a633e94b20b01d01c0e7400cc075`；核验代码：`542502b64b817f35ab9f31f382564a2ea9dfe849`。两提交间业务代码无差异。部署配置、真实设备和 HIL **未核验**。

## 0. 结论与读法

**平台构建并审核不可变成品，Agent 验证并安装成品，厂商控制器保留实时运控和硬件安全控制职责。** D6 的开发者承诺、沙盒受控执行、用户分级告知留痕仍须落实，不能以“调用厂商高层接口”为由免除平台自身的权限和交付控制。

完整开发工单与验收见§17—§21：先完成同一标准源在两个品牌上的行为编译与执行，再扩展动作重定向；§1—§16保留交付、安全和恢复基线。可以推进契约与隔离 MVP；生产 Agent、离线试用和动作转码须先满足本蓝图的退出条件。文中的“必须”均为**拟议实现及验收要求**，不代表当前代码已满足，也不代表待拍板事项已获批准。

```text
开发者合规包 -> 成品导入/离线构建 -> 不可变资产与构建证据
-> 最终产物审核 -> 按操作检查与签名 -> Agent 持久接收
-> 厂商安装插件 -> 独立设备观测 -> 回报/对账 -> 安装事实
```

四种结果分开验收：构建成功证明有合格产物；安装成功证明目标设备安装了对应字节；运行正常证明指定健康探针通过；安全可交付还需要规定场景下的 SIL/HIL、权限隔离和 D6 留痕。前一项不能替代后一项。

## 1. 目标与非目标

1. 复用 `zhaohuan.skill/v2`、`manifest.json`、`device_binding`、`skill_assets`、`skill_build`、`deploy_task` 和 `install_record`，不另造 `.rsp` 公共格式。
2. 先在一个明确的设备/固件/SDK/包组合上完成成品导入与可信安装，再评估动作转码和第二厂商。
3. 交付对象必须能沿源资产、工具链、profile、成品、审核、任务和安装事实追溯。
4. 断网、重启和回报丢失后能够对账，回滚能够恢复明确的良好产物。
5. 不接管关节、步态、平衡、底盘速度仲裁或硬件急停；不加入 LiveKit 和远程摇杆。
6. 不承诺任意技能跨任意品牌；不因一次 HIL 或单机型 L1 宣称项目 M4 完成。项目理解手册的 M4 三厂真机口径保持有效。

## 2. 当前仓库基线与评审证据

### 2.1 已核验事实

以下路径相对 `my-bagisto-store/packages/Webkul/Shop/src/`，均按页首代码提交核验：

| 能力 | 原始位置 | 可复用范围与限制 |
|---|---|---|
| 设备接入 | `Http/Controllers/API/AgentController.php`、`Services/Deploy/DeviceCredentialService.php` | 已有 enroll/register/heartbeat、独立凭据；实际部署是否强制 device 模式未知 |
| 指令和下载 | `Services/Deploy/SigningService.php:120`、`DeployTaskService.php:522` | 已签任务、技能、版本、集合指纹、单文件 SHA-256、时间戳；操作、设备和期限尚未纳入完整签名契约 |
| 构建消费 | `Services/SkillDelivery/SkillDeliveryAssetResolver.php:128` | 已按目标机型读取成功 build 的产物，不能再称“未进入交付链路” |
| 构建生产者 | `Services/SkillCompile/Builders/MotionCarrierBuilder.php` | 实际调用仍返回 pending、产物为空，尚无本轮验证的真实动作构建产物 |
| 参考 Agent | `Http/Controllers/AgentSetupController.php:170` 的 `AGENT_PY` | 已有下载、验签、解包、installed.json 和生命周期函数；尚无厂商生产插件与真机恢复证据 |
| 载体分类 | `Models/SkillAsset.php:202`、`:213` | `isFile()` 仅认 file；`isFileBacked()` 另认 motion_data/app_package/vendor_package；不能混用 |
| 适配证据 | `Services/SkillDelivery/CompatibilityService.php` | 已有资产 ID/hash 匹配；完整固件/SDK/Agent/profile 证据范围仍须验证 |

### 2.2 本次隔离实验与未验证项

本轮四个 PHP/Python 脚本不连接数据库、不读取生产密钥、不操作设备。原始脚本与输出随评审附件 `agent-runtime-review-evidence.zip` 交付，未纳入 Git；退出码 0 表示观测反例成立，**不是功能验收通过**。

| 观测 | 证据性质 | 结论边界 |
|---|---|---|
| 蓝图清单经实际 ManifestValidator 校验有效；MotionCarrierBuilder 仍 pending | 真实类隔离执行 | 清单示例本身不是阻塞，动作产物未生成 |
| 三种扩展落盘载体 isFile=false、isFileBacked=true | 真实模型隔离执行 | 按原稿直接接线会被现有 file 交付路径排除；完整数据库链路未执行 |
| 改 operation/did/expires_at/model 后原签名仍通过，改包 SHA-256 被拒绝 | 真实签名类、临时密钥 | 签名覆盖不足；不等于已复现远程入侵 |
| 共享 key 无设备身份时 auto 接受、device 返回 401 | 真实中间件、隔离配置 | 默认/兼容分支风险，不代表生产已启用 auto |
| 安装成功后 done 丢失，两轮轮询仅尝试回报一次 | 原始 Agent，网络/下载/验签为测试桩 | 未见持久任务和事件补报机制；未执行 HTTP/数据库对账 |
| 先装 v1、升级 v2，再以 v2 执行有包回滚，仍是 v2 却标 rolled_back | 原始安装函数执行；云端命令链静态核验 | 坏版本与恢复版本语义不一致；未执行完整灰度回滚集成 |

实际厂商包、工具链镜像、原始设备报文、实时进程状态和 HIL 数据本轮均未取得。手动安装可用是既有项目口径，本轮未重新执行真实业务验收；不能把历史交接记录当今天的部署事实。

## 3. 分层与职责

| 模块 | 所在位置 | 职责与禁止事项 |
|---|---|---|
| CarrierBuilder | 平台离线构建面 | 成品导入或生成目标载体；不安装、不运行，不在 Web 请求内执行 |
| 平台交付面 | Laravel 模块化单体 | 资产/审核/授权/任务/签名/对账；扩展沿用配置注册表，不做微服务改造 |
| Agent 与 InstallAdapter | 设备侧 | 身份、验签、持久任务、安装事务和厂商插件；不在设备现场转码或重打包 |
| VendorRuntime | 厂商运行环境 | 实际运行、FSM、运控与安全退出；接口支持范围需厂商资料及指定版本验证 |

第三方技能不能获得 Agent 凭据、宿主控制权或未声明的厂商 SDK 能力；必要的高层调用经受控能力桥接。不能假定容器化或厂商控制器天然使任意第三方动作安全。

## 4. 标准数据契约（提案，Phase 1 冻结）

### 4.1 中立清单保持不变

消费现有 `ManifestValidator`，不改公开 schema。以下为已通过现有校验的示例，机型与版本值仅示意，不证明真机兼容：

```json
{
  "schema": "zhaohuan.skill/v2",
  "kind": "motion",
  "name": {"zh_CN": "展厅迎宾挥手", "en": "Showroom Greeting Wave"},
  "version": "1.0.0",
  "description": "面向展厅迎宾的挥手动作",
  "pricing_mode": "buyout",
  "delivery_channels": ["agent", "manual"],
  "permissions": ["motion_control"],
  "resource_type": "motion",
  "compatibility": {"models": [{"vendor": "unitree", "model": "unitree_g1", "min_firmware": "1.0.0", "min_app": "1.0.0", "dof": 23}]},
  "safety": {"risk_level": "medium", "requires_open_area": true, "estop_required": true, "max_duration_s": 30},
  "motion": {"format": "bvh", "fps": 30}
}
```

校验通过只证明清单结构，不证明所声明的权限可授予、动作可执行或版本受支持。不得上传裸动作后绕过开发者责任自动代客包装；DEV-02/DEV-04 旧裁决未因本次修订失效。

### 4.2 TargetProfile 与验证范围

profile 是版本化、受评审的目标环境描述，建议存放 `my-bagisto-store/resources/skill-target-profiles/`。**该目录及协议尚待实现**。

必需信息：硬件变体与 model_code、OS/架构、准确固件与 SDK 版本、支持载体和实际安装接口、Agent/插件最低能力、关节序与单位/坐标系/限位（动作类）、资源限制、健康与安全退出方法。

`profile_digest` 必须覆盖 profile 及其引用的限位、映射、模型等文件内容，不能只哈希一个包含可变外部路径的 JSON。生产组合禁止用 `1.x` 或 `vendor-defined` 等占位值替代验证。

HIL 证据绑定：`artifact_sha256 + profile_digest + 硬件变体 + 固件 + SDK + Agent + 插件版本/摘要 + 动作参数范围 + 场景 + 测试记录`。profile 的 verified 状态不能自动使同机型全部技能获得 L1。关键组合改变时须重新判断证据适用性，不得静默继承。

### 4.3 BuildRequest 与构建身份

```json
{
  "skill_version_id": 123,
  "source_asset_id": 456,
  "target_model_code": "unitree_g1",
  "target_profile_digest": "sha256:example-profile",
  "builder_key": "vendor.passthrough",
  "builder_version": "example-unapproved",
  "toolchain_digest": "sha256:example-toolchain",
  "options": {"strict_limits": true}
}
```

服务端校验资产归属与参数白名单，自行计算 build_key，不能信任调用方提交的缓存键或 success。构建身份为规范化对象的 SHA-256，覆盖：

```text
identity_schema + source_digest + manifest_digest + target_model_code
+ target_profile_digest（含引用文件）+ builder_key/version
+ toolchain_digest（镜像、依赖锁、算法/模型版本）+ normalized_options
```

采用 §4.5 的 JCS 编码规则，不用无边界字符串相加。构建器版本如严格绑定不可变工具链，也须验证该绑定；仅在 run 中记录工具链、却仍复用旧缓存，不满足本契约。

相同键复用前须确认成品仍存在、哈希有效、未撤销；构建复用不绕过当前审核、授权与兼容闸门。共享相同字节不代表不同技能版本共享审核或 HIL 结论。

### 4.4 成品、封装与哈希范围

`skill_assets` 仍记录交付资产，禁止绕开它直接写任务 URL。但“保持资产表”不等于“现有所有载体已支持交付”。

**P-04 推荐但未批准：**外层交付封装登记为 `carrier_type=file`，内部元数据描述 motion_data/app_package/vendor_package；另一方案是显式扩展所有相关解析、审核、指纹和下载规则。选定前不得直接把 `isFile()` 全面改成 `isFileBacked()`。

P-04 还须说明平台外层封装与 DEV-02“不得代客打包”的范围差异；未批准前仅验证开发者已提交的合规成品，保持字节不变并外置构建元数据。不从裸文件代造合规包，不代填开发者的权限、风险或责任承诺；下列新增外层布局仅在该方案获批后适用。

```text
delivery-bundle.zip
  manifest.json
  artifact.json          # 来源、build_key、profile、内部载体类型、payload清单
  payload/<厂商成品>     # pass-through 要求此层原始字节不变
  sbom.json              # 应用类必须；其他类型按检查策略
```

- `payload_sha256`：原始厂商载体的字节摘要。
- `package_sha256` / `output_sha256`：最终封装文件的同一个字节摘要，放在外部资产/构建记录及签名任务中。
- `package_hash`：现有交付物集合指纹，不等于单文件 SHA-256。
- 包内 artifact.json 记录 payload 文件摘要，不存包含自身的整包摘要；分离签名/构建证明存包外并绑定整包摘要，避免自引用。
- pass-through 保持的是 payload 字节；外层封装是新资产，审核和任务绑定最终外层。不得声称封装后整包与原厂包逐字节相同。
- `created_at`、run 日志、审核/HIL 结论等易变记录放包外；必要包内时间使用固定来源时间。后续验证结果追加为外部证据，不改已签名包。

### 4.5 Agent 指令、签名与信任根

**当前 v1 事实：**`SigningService::canonical()` 签六字段串；命令另带 operation，缺少完整的设备/期限/重放绑定。不能将此标为 v2 已实现。

**生产前拟采用 agent-task/v2：**消息由 `signed`、`signature`、可刷新 `package_url` 组成。signed 必须包括下表字段；命令类型不用的字段显式为 null，缺字段不等同 null。数据库数字 ID 与单调序号用十进制字符串，task_id、did 等保留各自既有字符串格式；operation、协议时间用有界整数。未知协议、重复 JSON 键、未知 signed 字段或错误类型一律拒绝。

| 字段组 | signed 内容 |
|---|---|
| 协议与密钥 | protocol_version、signature_algorithm、key_id；算法白名单与可信公钥由已配置的信任根决定，禁止从命令任意下载公钥 |
| 执行意图 | task_id、command_seq、did、operation、skill_id、skill_version_id、version |
| 成品与环境 | package_asset_id、package_hash、package_sha256、build_key、target_model_code、target_profile_digest、审核/验证记录摘要 |
| 回滚 | revoked_version_id、restore_asset_id、restore_sha256；正常安装为 null，回滚按 §7.4 显式填写 |
| 技能执行（§17.6提案） | execution_id、target_release_id、installed_asset_id/sha256、plan_digest、binding/runtime摘要、类型化execution_params及其摘要、execution_deadline；取消任务绑定被取消execution_id，不执行任务的字段显式为null |
| 授权 | entitlement_id、authorization_policy_version、authorization_not_before、authorization_expires_at、revocation_epoch；买断授权不得被变相改成订阅 |
| 指令时效 | issued_at、task_expires_at；与授权期限分开，过期命令不得安装或启用 |

拟议签名字节为 `UTF-8("summon-mecha/agent-task/v2") || 0x0A || UTF-8(JCS(signed))`，`0x0A` 是单个 LF 字节；JCS 按 [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785)。RSA/SHA-256 与现有实现衔接的算法标识、参数和 PHP/设备端黄金向量须在 Phase 1 一起冻结。公钥启动分发、轮换、撤销、设备离线恢复按 P-06 审批；公钥不能只靠同一次未认证下载建立信任。

- 先验签、核身份/期限/本地任务记录，再下载和解包。同一 task_id 的 signed 内容及 command_seq 冻结，重复消息只恢复该任务或补报，不创建第二次安装；已完成任务不得重新执行。同 task_id 改内容必须拒绝。command_seq 以设备为作用域持久单调递增；低于已接受序号且不在本地 journal 的新任务拒绝，journal 内未完成任务只按已有指令恢复。序号分配、并发领取与磁盘恢复纳入黄金测试。
- URL 可重新签发，但只能指向配置允许的 HTTPS 平台来源；不得使用命令任意 URL 访问本机、内网或携带额外凭据。URL 更新不改变 task_id、序号、资产、摘要或已签期限；仅重签相同 signed 不重置任务身份。若任务已过期或需换 key_id，须先对账原任务的未知结果，再重新授权并建立可追踪的替代关系；替代不能重复已经发生的副作用，旧任务结果继续保留，不能在原 ID 下延长期限。
- 新生产设备必须使用 `AGENT_AUTH_MODE=device`，停止发放共享 key；已吊销设备不能省略 did 回退到共享认证。升级后的生产 Agent 禁止自动降级到六字段 v1；旧协议只可留在明确隔离的联调环境。
- 创建任务、领取任务、设备执行前分别复查绑定、授权期限、撤销、熔断与验证范围，按 §8 区分操作。不能以创建时通过为由执行数小时前已失效的安装。
- 密钥/可信时钟/撤销状态不明时，停止新的安装和启用，保留按可信指令安全停用的能力；不得用共享 key 绕过身份恢复。

### 4.6 回报、持久接收与对账

现有 phase 保持 `downloading/installing/download_failed/done/failed/timeout`，不得把新增消息类型误写成现有接口已接受。

v2 拟在现有 evt 入口扩展持久接收、执行事件和事实对账消息；路由契约、ACL、错误码与数据库增量须在 Phase 1 评审。回报须绑定已认证 did、task_id、event_id、单调 event_seq、operation、实际资产摘要、实际激活版本、插件版本、健康结果和错误原因。平台不能仅凭字符串 done 推断实际运行。

Agent 验签和预检后先把任务及指令摘要落 journal，才发送持久接收确认；完成副作用后先落事实与待发事件，再发网络请求。事件重试保持 event_id 不变；平台持久处理后返回绑定 did、task_id、event_id 和处理摘要的 ACK，重复事件返回同一处理结果，不重复发授权或落安装事实。ACK 必须来自认证的平台连接；只收到 HTTP 200、但无预期 ACK，不得删除待发记录。

服务端 `delivered_at` 仅表示投递，不等于设备持久接收。断电后以 journal、事件 outbox 与插件独立观测恢复；超时表示结果未知，不等于未安装。迟到事实通过对账记录补充，保留原 timeout 及事件历史，不能强改原任务 success 掩盖中间故障。

命令或使用授权到期不阻断已发生事实的补报、ACK 和受认证对账。若到期发生在安装外部调用中途，按插件事先冻结的安全完成/退出策略处理并报告实际结果，不以强杀或续发新任务代替恢复。收到任务的 ACK 丢失时，平台重投相同 task_id，Agent 依据 journal 补发确认，不重新执行。

## 5. 平台成品构建

### 5.1 运行与隔离

沿用 `config/skill_builders.php` 注册表；可增加受控构建配置，但不得并存两个互相矛盾的 builder 真相源。专用 `skill-builds` worker 不消费订单队列。Web 只校验、入队、返回构建 ID。

构建进程与 Web、签名私钥及第三方源代码隔离；限制 CPU/内存/磁盘/时间，默认关闭网络，仅放行受控依赖/资产源；不挂 Docker 宿主控制 socket，不以 privileged 运行。签名由可信控制面在产物检查后完成，不能让 builder 自签伪造证据。

上线须核验常驻 worker、调度、积压告警、存储与恢复；存在 `queue:work` 命令不代表进程已运行。本轮未核验生产运行状态。

### 5.2 状态和构建运行历史

现有状态为 pending/building/success/failed；拟议 queued/verifying/cancelled 需增量迁移与契约评审后采用。每次运行写独立 run、工具链摘要、输入输出、开始/结束和错误；重试不能覆盖旧 run。

`success` 必须有真实资产、最终 SHA-256 和检查证据；它证明构建结果，不代表审核通过或 L1。曾经成功的构建不得为回退改写成 pending/failed；另记发布禁用/撤销状态及原因。取消任务与并发 worker 的迟到结果通过 run/租约代次隔离，失效 worker 不得覆盖新运行。

### 5.3 流水线与缓存

```text
源资产/清单校验 -> 锁定完整输入和profile -> 计算build_key
-> 独占领取run -> 隔离builder -> 成品检查 -> 私有持久存储
-> 资产/构建记录提交 -> 外部构建证明 -> 最终字节审核 -> 发布闸门
```

相同键并发只接受一个活动构建，失败重试产生新 run。持久存储与数据库之间发生中断时先对账，不能因上传了临时文件就写 success。审核记录绑定最终资产摘要；更新成品必须新建资产并重新判断审核与验证。

在两个隔离环境使用锁定输入主动重建并比较字节；缓存命中不算重建。非确定性差异必须定位，未达到逐字节一致时明确标为“不可复现”，不得只写“已说明原因”便通过可复现验收。

### 5.4 Builder 范围

| Builder | 最小结果 | 前提与阶段 |
|---|---|---|
| vendor.passthrough | 验证一个已有厂商成品，保留payload，生成可追溯外层 | 第一阶段建议；须确认真实安装接口和P-04封装，不等于动作转码或M4完成 |
| portable.behavior | 受限XML4标准源→能力绑定/IR→固定runner目标应用 | 首期主线；与source_format一起选择，不等于后续任意复合资产组合 |
| motion.retarget | 源动作→规范化轨迹→目标资源及质量报告 | P-01重新审批后才进入Phase 6动作专项；需目标模型、映射、算法、输出接口、黄金集、SIL/HIL |
| app.container | 锁定架构/基础镜像/依赖的应用产物与SBOM | 不替应用实现厂商SDK；权限与网络能力须在目标运行环境实际验证 |
| composite.bundle | 可追踪子资产与可恢复组合 | 后续评估，不进入第一版承诺 |

动作类检查单位、坐标、关节顺序、限位、时间连续性、速度/加速度及目标允许的约束。裁剪限位不能自动证明动力学可行或不会跌倒。支持 BVH/SMPL-X/FBX 等仅是候选输入，未接入的格式不得标为已支持。

应用默认非 root、只读文件系统、资源受限、禁 privileged/host network。若厂商通信要求与默认隔离冲突，先提交最小能力/网络例外及验证，不能直接给第三方程序开放宿主网络、设备或完整 SDK 对象。

## 6. 数据模型建议（未迁移）

| 对象 | 必须补充或冻结的内容 |
|---|---|
| skill_build | build_key、源/清单摘要、builder、toolchain_digest、profile摘要、最终资产/哈希、最近run；保留历史构建事实 |
| skill_build_run | attempt、租约代次、worker、输入/工具链/输出摘要、检查结果、时间、脱敏日志和错误 |
| skill_assets | 保持最终资产记录；P-04决定封装与carrier映射，不能假定现有file规则已覆盖其他载体 |
| 审核与验证记录 | 绑定最终资产哈希及适用环境；HIL追加为外部证据，不改包内元数据 |
| deploy_task | 冻结执行对象、签名协议、序号/期限、授权策略、坏版本和恢复目标；刷新URL不重新选择产物 |
| 设备journal/outbox | task_id、指令摘要、接收/执行检查点、event_id/seq、待ACK事件、实际激活资产和恢复证据 |
| 平台对账记录 | 保留原任务结果、设备独立观测、差异、修复动作与审计；不把旧timeout直接抹成success |

以上是拟议字段/记录，名称和迁移由 Phase 1 冻结。所有迁移使用 additive 方案；测试仅用 `summon_mecha_step10_test`，禁止对 root fresh/truncate/seed。

## 7. 设备端恢复机制

### 7.1 进程与权限

Agent 核心与厂商插件分进程、最小权限；具体语言、监督方式和负责人待 P-05。安装管理凭据不传给技能，插件异常不得拖垮 Agent。第三方源代码不能与签名或宿主控制面共享权限。

### 7.2 两套状态，持久化边界

平台任务状态与设备本地执行状态分别维护，映射需评审；下列是**拟议本地状态**，不是已有 phase：

```text
VERIFIED -> RECEIVED_DURABLE -> DOWNLOADING -> STAGED
-> INSTALLING -> HEALTH_CHECKING -> COMMITTED -> REPORTED_ACKED
任意阶段 -> FAILED / RECOVERY_REQUIRED
RECOVERY_REQUIRED -> OBSERVED -> RESUMED / RESTORED / DISABLED / MANUAL_REQUIRED
```

每次外部调用前写意图，完成后写观测结果和待发事件；原子替换一个 installed.json 不能代替任务 journal。持久化须定义原子提交、fsync/磁盘错误处理与重启恢复；仅做进程重启测试不能宣称断电安全。

同设备安装/卸载/启停与冲突执行串行，独立能力按§17.6受控资源租约协调；同 task_id 重复到达不重复副作用。恢复未完成任务仍须复核期限、撤销与实际设备状态，不能以 journal 已存在为由继续执行已失效安装。过期任务可以补报已发生的事实，不能重新安装或启用。任务序号、取消与撤销具有明确优先级；已提交安装不能通过删除任务假装取消，须另发处置任务并保留原事实。

接受更高序号的撤销、停用、卸载或回收后，受影响的旧安装/启用任务只能对账、补报或安全退出，不得从 journal 恢复并重新激活。恢复良好版本需要新的明确恢复授权，不能重用已被后续处置覆盖的旧安装意图。

### 7.3 安装事务与恢复表

```text
验签/身份/时效/授权预检 -> journal持久接收
-> 下载限量包并验hash -> 解包安全检查 -> 独立staging
-> 插件prepare及目标环境复核 -> 安装 -> 独立observe/health
-> 持久提交激活事实和待发done -> 重试直到平台ACK
```

ZIP 必须检查越界、符号链接、体积/文件数上限、非法文件及权限；禁止执行包内任意安装脚本绕过声明权限。失败时保留已有良好版本及恢复依据。

| 故障边界 | 重启后必须做什么 |
|---|---|
| 收到命令但未持久接收 | 依据平台投递/接收协议重取；不能因delivered_at已写就永远丢任务 |
| 解包或安装中断 | 读journal，独立检查staging、厂商资源与激活版本；不得把残留目录当完成 |
| 厂商调用返回前进程退出 | observe外部实际副作用；按幂等令牌确认/补偿，不能盲目重试不可逆操作 |
| 本地提交后done丢失 | 从outbox重报原event_id；平台按事件去重，不重新安装 |
| 平台已timeout，设备后来报告已安装 | 记录迟到事实并对账；保留原timeout，决定继续、恢复或停用 |
| 无法确认厂商状态/恢复失败 | 标记MANUAL_REQUIRED、禁止新启用并告警；按厂商安全退出，不伪造回滚成功 |

### 7.4 InstallAdapter 与真实回滚

提案接口：`prepare / install / observe / enable / disable / uninstall / rollback / health`。输入必须包含 task_id/幂等令牌、成品摘要和目标环境；输出为实际资源ID、激活版本/路径、摘要、健康与恢复结果。

每个插件先声明：是否支持独立观测、幂等安装、安全停用、恢复旧版、撤销资源注册；给出不可逆步骤与补偿方法。缺失能力不能靠统一接口名称补齐。

**坏版本与恢复目标分开：**`revoked_version_id` 指待撤回版本；`restore_asset_id/restore_sha256` 指获批良好成品，不能把坏版本再次作为恢复包发送。无包本地恢复也要定位到真实存在、哈希正确、兼容且未撤销的旧安装，并切换实际激活路径。

首装没有良好旧版时，按获批安全停用/卸载策略处理，结果不得写成“已恢复旧版”。成功条件包括独立 observe、实际payload摘要与健康检查，不仅是 rolled_back 标签。平台安装记录应分别记录撤回对象和真实恢复对象。

显式恢复可与普通安装防降级策略区分，但必须有有效的回滚授权和良好目标证据；不能借回滚重装已撤销或不兼容的产物。当前代码已有OP_ROLLBACK防降级豁免，不得误写为“现有防降级一律阻止回滚”。

### 7.5 试用、离线、撤销和熔断

D4 保持**二十分钟、仅在线交付通道可试用**。P-03 只决定起算事件及中途断网后的安全执行规则，不改变时长，不给 manual 增加试用。

当前 TrialService 从发放时起算；本轮尚无端侧准时停用证据。不得同时保留“云端发放起算”和“设备上线后另算二十分钟”。采用何种起算，均须由平台与设备共享已签名的策略、起算事实和绝对截止时间。

未能取得可信期限/时钟时禁止新试用和续启用；重启、时钟回拨、断网不得重新赠送时长。若首次合法激活起算获批，必须先完成激活确认及租约发放，不能离线自行起算。若无法在断网后可靠执行既定期限，保持试用关闭，不能宣称强制回收已完成。

到期、撤销与kill switch调用厂商允许的安全退出/停用流程，不等于直接杀运控进程或硬件断电。物理停用能力、延迟和失败处理须按插件验证；不承诺设备所有者具有root权限时授权绝不可绕过。

kill switch对新安装/启用即时阻断；离线设备无法即时收到新撤销，须下次上线先处理。manual安装当前不能自动卸载。买断离线策略待P-03确认，不得引入订阅或定期付费续期。

### 7.6 健康与独立观测

上报Agent/插件/固件/SDK版本、架构、磁盘、进程/容器状态、激活资产摘要、启动失败码。健康是只读观测，不得夹带遥操作。installed.json仅是本地记录，验收需独立读取厂商资源/实际目录及运行状态；进程存在不等于动作可安全执行。

## 8. 按操作分闸门

所有操作先通过设备身份、签名、时效、防重放及操作授权；处置任务也不能绕过这些安全条件。

| 操作 | 额外条件 | 不得误阻断的情形 |
|---|---|---|
| 安装/升级 | 成功构建、有效成品、审核发布、目标组合验证、bound、有效授权、允许agent且未熔断；创建/领取/执行均复查 | 任一失败拒绝，不回退到任意原始上传文件 |
| 启用 | 当前实际产物有效、兼容和授权有效、未撤销/熔断；复查运行环境 | 不能因过去安装成功就跳过当前限制 |
| 执行（7，提案） | 已安装且启用的获准target release、能力/参数/资源范围、有效授权、执行期限、未撤销/熔断；参数值及摘要均受签名约束 | 不能以enable或包安装成功代替一次执行；不能执行源XML包 |
| 取消执行（8，提案） | 有效身份、完整签名、明确execution_id和处置权限；观察安全停止 | 不因使用授权过期、包缺失、新安装关闭而挡取消；不全局停止其他应用 |
| 回滚 | 明确撤回对象和良好恢复目标；有包目标通过成品/审核/兼容检查，本地目标也校验哈希和撤销；获批恢复权限 | 可绕过普通版本降级限制，但不能绕过目标安全检查；无良好旧版走安全停用/卸载 |
| 禁用/卸载/回收 | 真实设备、签名、操作与受控范围；按厂商安全退出 | 不因买断/试用授权过期、构建产物缺失、发布关闭或熔断本身被挡住 |

取消绑定后不继续安装；已有处置意图保留。设备凭据吊销导致无法通信时须受控重新配对或维修，不能恢复共享key作为后门。

单机型L1只适用于指定产物与已验证组合；有来源/profile却缺成品或验证时仅为L2；无可用路径为L3。一次pass-through或一个机型L1不等于自动转码，也不等于项目M4三厂真机完成。

## 9. 平台接口与最小改造

已有接口复用：`/api/agent/enroll`、`register`、`heartbeat`、`commands`、`evt`、`self-update`、`packages/{versionId}`，具体方法和鉴权以现有路由为准。

拟新增内部构建查询/取消/run历史与v2持久接收、事件ACK、对账契约，均**尚未实现**。构建POST异步返回202，不允许调用方写success；扩展evt必须先冻结消息类型、错误码及ACL，不能直接向旧phase塞新值。

优先扩展现有构建协调、资产选择、任务签名和Agent实现。只有在职责无法收敛时再拆服务；不因本蓝图的逻辑分层新增一套重复任务/权限系统。

## 10. 可证伪验收与外部判据

### 10.1 本轮与后续实验分开

§2.2是本轮已执行结果。下列E1—E4为**未执行的整链/重建/真机门槛**，不能打勾；后续修订须补命令、输入摘要、原始输出、执行者和固定代码版本。

| 编号 | 实验与反例 | 通过门槛 |
|---|---|---|
| E1 成品链 | 独立库登记获准载体真实包，审核→发布→建任务→下载；替换资产或变更环境 | 审核、任务锁定、实际下载/安装字节一致；缺包、错hash、错机型与不适用证据全部阻断；manifest校验不是完整交付验收 |
| E2 身份/期限 | 改operation/did/期限/profile，重放已完成/过期任务；吊销后尝试共享入口；试用跨期限再上线及重启/回拨 | 副作用前拒绝；吊销后所有旧认证路径失效；过期安装/启用不执行；处置通路仍可用 |
| E3 恢复 | 在收包、持久接收、厂商调用、激活提交、done发送/ACK各边界中断；abort→commands→Agent→evt真实回滚 | 设备实际版本/字节/健康与平台收敛；任务不丢、事件可重报且副作用幂等；首装无旧版验证安全停用；不能仅数回滚任务 |
| E4 构建/硬件 | 两隔离环境主动重建；只改变工具链/依赖/profile；指定厂商接口SIL/HIL | 应变输入改变构建身份；字节可复现；HIL绑定产物及环境，环境变化不自动继承L1 |

安全负例另覆盖路径穿越、符号链接、解压炸弹、超时/磁盘不足、worker崩溃、权限逃逸、错误固件及SDK。重启、进程终止与断电分开记录，故障注入只能在隔离环境和获批测试设备进行。

### 10.2 第三方依据及证明范围

- [SLSA v1.2 验证规则](https://slsa.dev/spec/v1.2/verifying-artifacts)：核对构建者、输入和产物，不证明运行正确或运动安全。
- [TUF v1.0.36](https://theupdateframework.github.io/specification/v1.0.36/)：用于旧版重放、冻结、混搭及密钥风险检查；不替应用实现安装恢复。借鉴判据不代表已部署TUF或通过认证。
- [Reproducible Builds定义](https://reproducible-builds.org/docs/definition/)：约定输入/环境/指令下逐字节重建；缓存相同不算独立重建。
- [宇树G1 LocoClient，9f4c017](https://github.com/unitreerobotics/unitree_sdk2_python/blob/9f4c017ef05e10138167873c0adc7af29aa45633/unitree_sdk2py/g1/loco/g1_loco_client.py)：可核查高层接口，不能据此假定存在任意BVH资源安装服务；目标固件支持须另确认。
- [unitree_mujoco，021d7bc](https://github.com/unitreerobotics/unitree_mujoco/blob/021d7bc72dca0551eba0dc6b2bc38e9abd74b1be/readme.md)：该版本声明主要支持低层开发，不能直接代替高层安装/启停插件或真机验收。

独立人员/机器可复跑软件实验；厂商接口支持、实际运行和安全交付还须指定版本资料、设备侧原始观测及有范围声明的HIL见证。签名、SBOM或一次HIL均不能单独证明所有场景正确。

## 11. 分阶段退出条件

各阶段为建议顺序，不把外部依赖写成已承诺P0；未审批项不得因本蓝图出现而自动排期。

| 阶段 | 最小交付 | 退出条件 |
|---|---|---|
| Phase 0 决策冻结 | ADR及§15决策记录、首个可取得的厂商成品/接口/设备组合 | 负责人明确批准范围；未批项有阻断边界 |
| Phase 1 契约收口 | 成品映射、完整构建身份、profile、v2签名与跨语言向量、事件ACK/对账、回滚、试用策略、迁移preflight | 契约评审通过；不写生产业务接口；不可将示例或原型记为实现完成 |
| Phase 2 成品导入MVP | 一个真实厂商成品、不可变资产、构建和最终字节审核、待下发任务 | E1通过；不宣称自动动作转码、真机安装或M4 |
| Phase 3 Agent闭环 | 真实参考Agent扩展或新核心、可控插件模拟器、journal/outbox、授权及恢复 | E2/E3在独立库与隔离设备模拟环境通过；用原始设备事实判定，非仅mock数据库状态 |
| Phase 4 单机型HIL | 固定机型/固件/SDK/Agent/插件/产物，厂商允许的安装/启停/退出 | 真实恢复、断网/断电和安全措施证据；只对获批组合标L1，才考虑其生产agent开关 |
| Phase 5 第二品牌行为交付 | 同一标准源、第二品牌binding/profile、目标应用及证据；对应B6 | 同源能力轨迹等价，第二品牌独立HIL；本阶段不依赖动作重定向，不能复用首机证据冒充零改动 |
| Phase 6 动作专项与后续机型 | 受控算法/格式、运行时/策略桥、黄金动作集；其他SKU逐个扩展 | P-01先批准DEV-04范围变更；E4、规定SIL/HIL及适用动作范围通过；不得凭抽样覆盖全部动作 |
| Phase 7 实时接管 | 另立ADR与项目 | 不属于本次批准范围 |

单机型成品导入只能证明对应组合的交付能力。原项目M4（三厂真机）与本蓝图阶段结果分别报告，未经负责人另批不得改写里程碑。

## 12. 运维、回退与审计

记录build/run/key、工具链/profile/成品摘要、审核/验证ID、task/event/设备ID、安装与恢复目标、ACK/对账状态及错误；日志不打印密钥、完整签名URL和私有包内容。监控积压、未知安装结果、回报滞留、回滚/安全停用失败，不能只统计success数量。

回退顺序：停新构建和新安装/启用发布；保留历史成功构建与run；用独立撤销/发布状态标记失效产物；撤销尚未执行的安装；保留可信禁用/卸载/回收及对账通路；必要时回到manual。离线设备等待上线，manual仍不可自动卸载。

回退不得覆盖成功历史或修改原产物；受影响设备必须有处置结果或明确未处理原因。关闭agent“新安装”开关不等于关闭全部Agent命令接口，否则kill switch和恢复也被一起切断。

## 13. 风险与停止条件

| 风险 | 停止条件/应对 |
|---|---|
| 无真实厂商安装接口或有效设备组合 | 停在成品/契约实验，不能伪造生产InstallAdapter |
| 成品类型、签名或授权契约不一致 | 不开放生产agent；先通过E1/E2 |
| 回报丢失或回滚只有标签 | E3未通过前不承诺自动恢复；保留未知状态和人工处置 |
| 动作算法/profile质量未证实 | 未审批不立项，未验证不标L1；范围外动作拒绝 |
| 设备端隔离与厂商通信冲突 | 提交最小权限例外评审及测试，不能直接privileged |
| worker、存储、密钥或撤销策略未就绪 | 生产开关关闭；不得用本机探针替代部署验收 |

## 14. 验收清单（全部未验收）

- [ ] E1：公开载体与外层封装映射获批，审核/构建/任务/下载绑定同一字节。
- [ ] 构建来源、完整工具链/profile摘要和独立重建证据齐全；success与发布/撤销分离。
- [ ] E2：生产device身份强制、旧共享入口关闭，v2签名覆盖完整意图，无协议降级。
- [ ] 试用统一起算及截止规则获批并通过断网、重启、回拨验证；manual无试用。
- [ ] E3：任务持久接收、事件ACK、迟到事实对账及跨故障恢复真实通过。
- [ ] 回滚目标为实际良好产物；无旧版时安全停用/卸载，不报假恢复。
- [ ] 处置操作不被构建缺失、授权过期、熔断或新安装关闭误阻断。
- [ ] E4：指定产物和环境组合HIL、D6留痕及厂商安全退出证据；环境变更重新判断。
- [ ] 单机型L1、项目M4、动作转码能力分别报告；不把SIL/签名/文件生成写成安全可交付。
- [ ] 生产部署、常驻进程、密钥信任根与恢复值守独立签收；文档改完不勾功能完成。

## 15. 单独提交负责人拍板的事项

下表与ADR使用相同编号，**全部待决**。技术建议用于形成可审核方案，不代替批准；通过一项不自动通过其他项。

本轮需求已进一步明确为“一份标准源，多机型生成并执行成品”；§17—§21给出推荐实施栈、受限节点库和B0—B7工单。它不自动立项完整通用SDK/CLI或批准低层运控改造；P-01评审范围与旧裁决差异，普通工程实现按本方案拆任务。

| 编号 | 决策与建议 | 未批准时的边界 |
|---|---|---|
| P-01 | 受限标准行为/能力编译与执行的范围、与完整SDK/CLI未立项的差异；成品封装与DEV-02、受控motion.retarget与DEV-04的责任差异及是否立项 | 既有裁决继续有效；不自动承诺任意代码转译、低层运控或Phase 6动作专项排期 |
| P-02 | 按§15.1的优先候选品牌确定首个真实设备/固件/SDK/厂商安装接口、可取得包和验证场景；批准单机型L1范围并保持原M4三厂口径 | 品牌方向已明确，具体组合仍待批准；只做隔离契约/模拟，不宣称真机或L1 |
| P-03 | 二十分钟试用从云端发放还是首次合法激活起算；断网/时钟异常的安全停用规则、买断离线策略 | 沿用既有D4时长及通道边界，不新增未验证的试用承诺或订阅 |
| P-04 | 推荐外层file+内层载体映射及不可变目标发布快照；确认源/产物角色、审核scope迁移和DEV-02封装边界 | 不改isFile语义，不自动再封装，不用旧上传槽覆盖成品，不绕过已发布版本状态锁 |
| P-05 | 按§20.1推荐Python Agent＋SQLite＋固定BT.CPP runner＋厂商能力子进程；明确实现、SDK接入、HIL及恢复值守责任 | 推荐栈尚未生产验收；不把参考Agent或SDK接受响应当成技能运行完成 |
| P-06 | 构建证明/任务签名是否分钥、启动信任根和轮换撤销；profile及密钥维护责任 | 仅临时密钥隔离实验；生产密钥与profile不得自行生成批准记录 |

### 15.1 用户指定的优先候选品牌（2026-11-03）

目标方向为**主流品牌的热门机型**，优先候选包括宇树、智元、优必选、众擎、小米；“重擎”暂按前文众擎（EngineAI）理解。具体热门程度和接入顺序须结合客户需求、实际可取得设备与开发权限判断，不以公开开发资料替代销量或保有量证据。下表是技术候选入口，不是已适配清单，也不表示全部型号均进入首期承诺。

| 品牌 | 当前可核验的候选入口 | 进入实施前的限制 |
|---|---|---|
| 宇树 Unitree | G1 EDU、R1 EDU；四足评估Go2 EDU，见[官方开发文档中心](https://support.unitree.com/home/en/developer/) | G1/R1普通版不能继承EDU二开；Go2 X为部分开放，须单独核验；锁准确计算单元、固件、SDK与关节配置 |
| 智元 AgiBot | X2 Ultra/旗舰焕新版见[AimDK开发模式](https://x2-aimdk.agibot.com/zh-cn/latest/quick_start/prerequisites.html)；远征A2/A3见[官方文档入口](https://open.agibot.com/docs/home) | X2 EDU须外接开发计算，不能在PC1二开；A2/A3动作资源与全身舞蹈权限分开；不因此接入厂商云 |
| 优必选 UBTECH | 明确候选Walker S2 EDU探索者，见[官方SDK部署指南](https://docs.ubtrobot.com/walker-s2-edu/docs/sdk/5/) | 已有SSH/ROS2容器与自定义程序依据；不能套给所有工业Walker SKU；开发者模式退出内置运控，不当普通技能导入 |
| 众擎 EngineAI | PM01-EDU、T800，见[官方开放平台](https://en.engineai.com.cn/open)及[官方GMR](https://github.com/engineai-robotics/GMR) | T800 Pro独立profile；GMR输出与Native SDK消费者要桥接，运行策略及模式另验，不能承诺任意轨迹直接执行 |
| 小米 Xiaomi | CyberDog 2，见[官方产品说明](https://agreement.cyberdog.xiaomi.com/guide.html) | 该候选为四足；不能据此推定小米人形产品已可接入。用户是否将四足纳入首期、具体二开权限与部署条件仍待明确 |

先选能取得合规成品、开发接口和测试设备的一个组合完成E1—E4，再按同样标准扩展。品牌名单不批准DEV-04范围变更，不改变L1/L2/L3、D6及原M4验收口径；不能把“同一品牌”当作跨型号兼容依据。

## 16. 本次修订交付与回退

本次修订本文件和ADR，并增加docs下的源契约样例与校验记录：纠正基线、补齐可移植编译/执行、恢复机制与开发工单；未修改业务代码、API、迁移、部署开关或真实设备。所有新能力仍为待实现，审批状态仍为Draft/Proposed。

原稿为 `f176d64` 的两份文档；本机备份 `.claude/backup/agent-doc-review-20261103/source-f176d64.zip` 不提交。需要回退时先保留当前文档，再从固定提交分别读取这两个路径恢复，禁止全仓reset或git add -A。

本轮0.3之前的工作区稿另备份在 `.claude/backup/portable-skill-full-plan-20261103/`；可只恢复这两份文档。新样例没有被业务注册或执行，保留不影响业务；需要移除时仅按确切docs样例路径处理，不作全仓清理。

## 17. 可移植技能：一份标准源，多机型成品

### 17.1 产品承诺与第一版边界

本节是本轮补齐的核心开发规格。目标为：**开发者提交一份不可变标准源，平台为获准目标自动完成能力检查、编译、审核和交付；各机型通过共同的行为验收。** 平台维护可复用的机型适配，开发者不必为每个新技能重复上传各品牌实现。

首期支持三条路径，不能混称同一种转码：

| 路径 | 标准源 | 自动化工作 | 完成定义 |
|---|---|---|---|
| P 行为适配（首期主线） | 受限 BehaviorTree.CPP XML4＋标准能力节点＋媒体资产 | 解析、能力匹配、语义降低、生成目标应用 | 同一源摘要生成至少两个不同品牌成品，抽象行为轨迹与源契约一致 |
| V 厂商成品导入 | 开发者已有且有权分发的合规厂商包 | 原样核验、目标封装/元数据、审核、交付 | 真实目标安装及恢复；不计为几何动作转码 |
| M 动作重定向（独立专项） | 自有/获授权BVH，后续再增其他来源 | 骨架/时序规范化、重定向、目标导出、运行时/策略绑定 | 转换产物进入获准执行链，完成规定静态、SIL与HIL，不仅生成文件 |

任意 Python/C++ 程序自动跨厂商翻译、任意厂商包互转、四足与人形全动作等价不在首期承诺。对不满足能力的机型准确拒绝，是产品正确结果；不能为了“全兼容”改变原技能含义。

**第一验收场景：**选定G1 EDU与智元一个可取得SDK/样机的SKU，用同一份标准源构建两个品牌成品；先采用双方均能被独立观测的已获准高层动作/媒体能力。建议迎宾作为业务场景，但不预设某个SDK已支持精确挥手次数、音频完成检测或所有源参数。

### 17.2 源格式、标准节点与示例

外层继续既有 `.skill` ZIP和 `zhaohuan.skill/v2`，不新增 `.rsp` 或另一套公开包格式。内部采用已有 **BehaviorTree.CPP XML4** 的受限配置；新增的是能力语义与校验profile，不能宣称“现有manifest形状能接受便已实现可移植”。

```text
source.skill（开发者自己提交完整合规包）
  manifest.json
  behavior/main.xml
  assets/welcome.wav
  licenses/...
```

建议源 manifest 的 runtime 子对象：

```json
{
  "source_format": "btcpp/xml4",
  "source_entrypoint": "behavior/main.xml",
  "capability_contract": "portable-actions/v1"
}
```

source_entrypoint 不是可执行入口；源清单不以 runtime.entrypoint 把XML伪装成程序。目标成品另写实际runner入口、源摘要和能力/绑定/runtime摘要。manifest kind 使用现有composite，权限沿用现有 motion_control/audio_play/expression 等词表。

[清单样例](./portable-skill-spec/examples/greeting/manifest.json)与[行为树样例](./portable-skill-spec/examples/greeting/behavior/main.xml)用于契约评审，尚缺真实音频文件、目标binding和真机证据，**不是可直接上架的完整技能包**。精确三次挥手是严格要求：目标只能调用一次预置挥手、却不能证明侧别/次数时，必须拒绝该样例；不得悄悄改成“一次动作调用”。

第一版节点白名单为 Sequence、注册Action及有界SM_Wait；不支持任意脚本、XML外部实体、include、动态库加载、动态ROS接口名、无限循环、Parallel、自动重试或未约束Blackboard表达式。以后增加控制节点须有独立取消/重启语义测试。解析器禁DTD/实体展开，资源路径必须包内相对路径，深度、节点数、属性长度和总执行预算均有限制。

### 17.3 标准能力契约 v1（提案）

| 能力／节点 | 输入与权限 | 可观察成功条件 | 不可替代的限制 |
|---|---|---|---|
| gesture.wave@1／SM_GestureWave | hand枚举、正整数cycles、有界timeout；motion_control | 指定侧别、计数和完成/释放状态满足已审定义 | 无手臂、次数不受支持、只有SDK接受响应而无完成依据则拒绝；不替换为鞠躬或抬腿 |
| audio.play@1／SM_AudioPlay | 包内音频hash、wait、有界timeout；audio_play | 目标编码存在，按profile的可靠完成条件结束 | 不把发出播放请求等同于播完；停止仅作用本执行流，不停止其他应用 |
| expression.show@1／SM_ExpressionShow | 包内媒体/表情引用、时长；expression | 显示设备及目标格式满足合同并完成 | 无显示能力不得静默改成语音/灯效 |
| wait.duration@1／SM_Wait | 有界毫秒值；无新增设备权限 | 单调时间预算完成 | 可立即取消；重启不能重赠完整等待时间 |
| motion.play@1／SM_MotionPlay | 获准目标动作、runtime/policy/profile摘要；motion_control | 正确消费实际产物、完成且安全退出 | M专项接入后开放；没有获准运行时不能直发关节流 |

每项能力还有公共字段：契约版本、参数类型/单位/边界、权限、效果类别、资源占用、开始前提、完成观测、取消方式、最大持续时间、恢复策略和证据ID。阈值必须有目标资料或试验来源，缺失不能猜测。

默认 strict。可替代行为必须由开发者显式声明、由产品确认等价范围，并形成独立lowering/审核记录。第一个版本建议不开放自动降级。平台资源锁只协调本平台的执行，不承担全机器人关节实时仲裁，也不能保证约束其他厂商进程。

### 17.4 编译器与IR

```text
PackageInspector/ManifestValidator
-> PortableSourceValidator（XML/资产/权限/预算）
-> SourceParser（不可变语法树）
-> CapabilityResolver（型号+SDK+能力版本+参数支持）
-> SemanticLowerer（标准能力到已审厂商binding）
-> TypedPlanIR（无任意代码、无shell命令）
-> TargetAppBuilder（XML/资源/绑定/固定runner）
-> 目标语义模拟与产物检查 -> skill_assets -> 目标审核/发布
```

IR至少记录source_node_id、能力ID/版本、类型化参数、源媒体hash、目标媒体hash、权限、时间预算、资源占用、binding摘要、失败/取消/恢复策略。与执行有关的值不可从未签名运行参数任意覆盖。

推荐实际采用BehaviorTree.CPP runner，而非自制一个宣称兼容所有BT语义的解释器。XML格式固定4，库版本单独锁定；可先验证官方4.9.0候选tag及目标ABI，再固定确切commit、依赖和镜像摘要。Agent沿用Python基础负责监督，不要求与C++ runner同语言。构建机可以Python3.10工具链，目标解释器/编译器/ABI必须逐profile固定，不能假定所有机型预装同一Python或Ubuntu。

现有SkillBuildService只按kind选择第一个builder，MotionCarrierBuilder也接受composite；必须改成 `kind + source_format + builder_key` 明确选择，不得让portable builder吞掉旧composite，也不得让未知格式回退pass-through。保留一个 `config/skill_builders.php` 注册表。

**新增可移植构建身份：**在§4.3基础上加入能力契约/节点库、IR版本、lowering规则、binding、runner/插件、媒体编码器及其配置摘要。同源版本不同target各有build_key；同目标语义或SDK变化须重新判断构建和验证，不能只靠skill version号缓存。

### 17.5 机型能力绑定与首批顺序

| 目标 | 第一版可采用的绑定依据 | 必须补的具体实验 |
|---|---|---|
| G1 EDU，明确DoF/PC2 | 官方动作列表、按ID预置动作或按名示教动作、AudioClient播放/停止 | 枚举真实资源；验证侧别/次数/完成/取消与音频格式；不把未公开的示教导入协议补成猜测 |
| X2 Ultra／旗舰焕新版 | 开发计算单元容器，已部署灵创资源查询/播放及音频能力 | 核验资源ID/权限、播放结束/安全取消及跨固件留存；X2 EDU需外接开发计算，不能安装PC1 |
| A2旗舰／A3 Ultra（可替换第二目标） | 本地上肢动作资源管理/迁入迁出/播放 | 先做原厂资源往返及恢复，再评估新动作内容协议；全身舞蹈“技能”不可据此创建/迁移 |
| PM01-EDU | 应用侧程序、原厂已有能力；M专项另接官方跟踪runtime | 确认高层完成/取消；涉及替换运控服务的Runner不纳入普通应用插件权限 |
| Walker S2 EDU | ROS2容器自定义程序和官方预置动作库 | 实际观察普通模式下动作与取消；developer_mode会退出内置运控，不据此证明普通动作包可用 |
| T800/Pro、R1 EDU | 各自准确SKU/SDK及样机 | T800与Pro独立profile；R1不继承Galaxea R1 Pro的映射 |
| Go2 EDU、CyberDog2 | 独立四足应用/原厂能力分支 | 不继承人形动作等价结论；小米按具体二开权限与功能条款核验 |

建议交付顺序：G1 EDU → 智元一款（应用优先X2 Ultra；动作成品导入优先A2/A3）→ PM01 → Walker S2 EDU；第二阶段按真实需求扩展其他SKU。动作研究先选PM01和G1；T800随后，Pro单独验证。顺序以实际可取得的样机/SDK调整，不按未经核验的销量或采购成本排序。

### 17.6 执行运行时：安装之后如何真正使用

enable只表示允许调用，不自动触发实体动作。安装、启用和一次技能执行必须区分；否则平台会再次停留在“包已落盘”而没有技能使用入口。

拟新增 `skill_execution` 记录业务执行事实，复用deploy_task运输和Agent事件，不再另造设备消息队列。**操作码提案**：OP_EXECUTE=7、OP_CANCEL_EXECUTION=8；现有1—6不改值，新增值须在实现批次纳入唯一OPERATIONS、错误码与契约快照。旧Agent禁止接收新码。

v2 signed使用§4.5唯一字段表，绑定execution_id、target_release_id、实际installed_asset_id/sha256、plan_digest、binding/runtime摘要、类型化参数值及摘要、execution_deadline和所需资源；取消任务另带被取消execution_id。所有默认值在签名前规范化；客户不能指定SDK方法、资产绝对路径、topic/service或shell片段。普通用户只可运行自己有有效授权、已安装且启用的目标release。

首个portable-actions/v1交付固定参数技能，execution_params必须为空对象；示例的侧别、次数和音频不能由客户调用时覆盖。后续参数化版本必须先定义源声明、类型、允许值与上下界、语义/权限预算及签名规范，再扩展白名单，不能以“已类型化”代替对允许参数的审查。

执行/取消不重新下载包：package_url等取包字段为null，已安装成品身份用上述installed字段校验；v2按operation明确分支，不能沿用参考Agent“有hash就下载”的判定。后台与设备的取包操作白名单同步，但保持旧1—6含义不变。

执行幂等键为execution_id；客户nonce只合并同一次请求重试，不吞掉不同nonce发起的下一次合法执行。取消按被取消execution_id去重。现有findOpenTask的did/version/operation去重仅用于原生命周期操作，不得直接用于7/8；数据库唯一约束及并发测试必须覆盖同技能两次执行与重复取消。

```text
创建执行（HTTP 202） -> 设备持久接收 -> 再核授权/期限/环境
-> 获取平台资源租约 -> 独立runner执行
-> 能力broker校验并调用厂商高层接口
-> 观察节点结果 -> 持久事件/ACK -> execution最终结果
```

runner仅能调用白名单能力。推荐通过受限本地IPC连接厂商插件，设置独立UID/容器、最小挂载和连接身份；插件不把Agent凭据或完整SDK对象交给技能。CPU/内存/运行时长受限；未经批准的宿主网络、Docker socket、设备节点或privileged均禁止。

执行状态：ACCEPTED/RUNNING/CANCEL_REQUESTED/SUCCEEDED/FAILED/CANCELED/UNKNOWN。RPC接受、BT节点halt或ROS取消接受都不等于实体动作已停止；在独立observe确认安全终态前保留资源锁，禁止下一次冲突动作；失败时转UNKNOWN/人工处置，不以超时自动解锁继续运动。

取消只影响明确execution_id，不发送全局“停止全部ROS goals”。禁用、卸载、回收先持久冻结新执行准入并提升该技能/设备的撤销代次，再取消活跃执行、观察安全退出，最后释放资源和删除文件；旧排队7指令或旧journal不能在此后重新激活。授权策略的revocation_epoch与设备持久代次一起检查，恢复需要新明确授权。禁止先rmtree或强杀厂商运控。Agent重启时不盲重放有副作用的节点：能够查询原goal结果则恢复观察；不能确认则UNKNOWN并安全退出。来源契约允许且可证明幂等的等待/纯计算才可继续。

DeployTaskService记录安装事实时必须改为显式操作白名单，不能让新增7/8落入当前default安装分支；执行成功不新增install_record。取消和事实补报不被使用授权过期挡住，仍要设备身份、签名、作用域与去重。

### 17.7 语义等价验收

至少两个品牌使用同一source_sha256；目标成品hash可以不同，但抽象观测必须满足同一合同，例如：

```text
gesture.wave(right,3)开始 -> 指定次数并安全完成
-> audio.play(源音频摘要)开始 -> 按合同完成
-> execution SUCCEEDED
```

逐项核验顺序、参数、计数、媒体内容、取消和完成条件；不要求不同机器人关节角完全相同。若源音频经过编码，验证来源到目标媒体的确定性转换与音频内容，而不是要求不同编码文件hash相等。

正向金标至少包括双方实际支持的两个组合技能；拒绝金标包括无右臂、无屏、次数不支持、仅有接受ACK无完成观测、权限声明不足和不支持的源节点。适配失败必须逐目标给原因，其他目标可继续构建；不得把部分成功呈现成全机型支持。

## 18. 动作转码与真机执行桥开发规格

### 18.1 实际格式与禁止混用的地方

以下来自2026-11-03公开作者/厂商源码；main/master未冻结为生产版本，实施时必须记录确切commit并保存golden输入输出。

| 环节 | 已取得的格式事实 | 工程处理 |
|---|---|---|
| GMR BVH输入 | loader含cm→m和坐标旋转，依赖具体骨骼命名；部分路径把身高写为1.75；CLI默认fps不等于源时间轴重采样 | SourceProbe独立保存Frame Time、骨架和源标定；不能对任意BVH直接调用默认参数 |
| GMR PKL输出 | fps、root_pos[N,3]、root_rot[N,4]保存为xyzw、dof_pos[N,D] | 可信worker产物转换为纯数值MotionIR；不能把PKL当厂商技能包或加载外部任意pickle |
| Native SDK named-joint CSV | 按active_joint_names读取表头，csv_dt与runner_period参与插值 | 独立named-joint exporter；锁关节名、单位、目标采样周期及loader版本 |
| Native SDK NPY | 当前loader约束NPY v1、2D和34/36/40列，包含固定25关节布局 | 不直接套PM01 24DoF或Pro；未证实布局前拒绝，首桥优先显式CSV映射 |
| RL-Lab CSV | 无表头[root_pos3,root_quat_xyzw4,joints...] | 与上一种CSV使用不同exporter和格式ID；禁止同扩展名自动猜解析器 |
| RL-Lab NPZ / SDK dance | joint_pos、joint_vel、body_quat_w为wxyz；body可能是[N,B,4]，消费者取指定body | 明确float32、数组维数/shape、body索引、joint/observation顺序、policy摘要；不能只改字段名 |
| 智元动作MCAP | 文档以record_file_path创建资源，输出示例mcap，部分注释拼macp；内部消息协议公开信息不足 | MCAP仅容器；取得获授权样本检查channel/schema/encoding/时间戳/关节/压缩，禁止把任意MCAP当可播放动作 |

### 18.2 规范化MotionIR

平台内部数值表示采用SI单位：位置m、角度rad、时间s；明确右手坐标系、world/base语义、关节名序和源到目标变换。四元数内部固定xyzw，边界转换为wxyz时有显式版本化规则，禁止靠数组位置猜含义。

建议用JSON元数据＋非object数值数组，不引入新的公开包后缀。元数据包含时间戳/源fps、模型摘要、joint_names、dtype、shape、坐标/单位、root/contact策略和来源许可。禁止NaN/Inf、重复/逆序时间、未知关节、维度错配；NPZ读取关闭pickle，限制压缩比、总字节及数组数量。

源关节轴向/零点/身体尺度是可验证输入；缺失时报 SOURCE_SKELETON_UNSUPPORTED / SOURCE_TIMING_UNKNOWN / TARGET_JOINT_MAPPING_MISSING。规范化与重采样要保留总时长及首尾帧，位置插值、旋转SLERP、导数算法、接触约束和容差分别锁版本。

### 18.3 转换、质量与执行绑定

```text
SourceProbe -> 单位/坐标/时间/骨架规范化
-> 固定GMR与IK/model -> MotionIR
-> TargetExporter -> RuntimeBinding
-> 静态检查 -> 运动学预览 -> 动力学SIL
-> 指定范围HIL -> 目标成品审核发布
```

检查目标关节位置/速度/加速度、时间连续性、自碰撞/足接触、初末姿态及过渡。限位裁剪不是“安全修复”；影响动作语义或动力学时须拒绝或形成新的已审变体。容差来自目标profile，不能编造跨全部机型通用的安全阈值。

众擎官方公开链为：目标CSV → csv_to_npz → whole-body tracking训练/评估 → policy.mnn → NPZ/MNN/配置 → Native SDK。当前文档固定Isaac Lab 2.3.2的 `c22775241e28f465fe345fa1a482ad6d29d712b0`；不能直接随latest升级。csv_to_npz默认30→50fps且做插值、导数与模型FK；设置仿真状态回放不等于动力学稳定性通过。

**策略不是免费附带：**未证明通用tracker可覆盖新动作前，把每动作所需训练/策略适配作为独立构建子任务、预算和证据；不得宣称所有BVH几秒内生成可上架技能。策略、模型、observation/action顺序和动作轨迹共同锁进成品及HIL范围。

运行时桥只接获准厂商高层资源或另经审批的厂商跟踪runtime。涉及停止robotics.service、开发者模式退出内置运控、替换运动策略等，必须独立范围审批及实机验证，不能由普通Agent安装插件默认执行；需要平台自研实时控制器则停止在该边界并另立项。

### 18.4 五个必须完成的取数/验证任务

| 编号 | 执行者 | 具体输入与实验 | 退出条件／失败后的能力边界 |
|---|---|---|---|
| R1 源动作金标 | 动作工程＋QA | 自采/获授权的静立、慢抬臂/复位、左右挥手；同动作30/60fps、m/cm、四元数/关节错序夹具 | 时长、首尾、单位/坐标转换有机器比对；不使用无商用权研究数据替代正式素材 |
| R2 G1新动作入口 | 设备工程＋厂商支持 | SDK对应版本动作列表/停止，取得示教资源写入说明或获准tracker；完成一个新动作写入/执行/取消/恢复 | 成功才开放G1新动作转码；取不到则保留既有动作语义映射，不伪造导入接口 |
| R3 智元内容协议 | 设备工程＋资源提供方 | 一个有权使用的录制/导出资源；mcap元数据探针；原样迁入→查询→播放→删除/恢复 | 原样往返且内容协议可解释；未获schema时仅原厂成品迁移/既有资源调用 |
| R4 PM01执行桥 | 动作工程＋设备工程 | 锁GMR/SDK/RL-Lab、模型/关节表、示例轨迹与MNN；两类CSV分别导出，官方消费者/SIL读取比对 | shape/关节/时间/四元数与IR一致，策略适用范围有证据；不猜固定25关节布局 |
| R5 运行时和硬件 | 厂商适配负责人＋QA | 模式切换约束、策略可用范围、急停/安全退出、断网/断电、旧策略与旧动作恢复 | 形成原始观测和责任签收；未通过则只标实验/SIL，不开放L1 |

R1/R4的公开材料已足以启动离线验证设计；R2/R3/R5仍缺有权取得的实物/私有协议/设备数据，公开资料未给出时不能靠更多文字填成事实。负责人应把厂商请求包列为型号、固件、SDK、所需接口、样本、许可、实验与停止条件；无外部数据仍可完成不依赖它的编译器和模拟测试。

### 18.5 动作负例矩阵

2/3/N帧、空/单帧、首尾帧丢失；30/60fps同动作；m/cm；xyzw/wxyz；左右关节交换；缺骨骼、身高/零点错误；NaN/Inf、越限、重复时间；CSV两格式混用；错误MNN/observation顺序；wrong runtime/profile；初末姿态不匹配；取消后仍运动。每项有固定错误与原始输出，不以视频看起来正常替代数值与状态验收。

## 19. 仓库改造与数据、发布、API闭环

### 19.1 必须先解决的存储与发布矛盾

当前AssetUploadService按 `(skill_version_id, carrier_type, sort_order)` 覆盖并删除旧文件，数据库有同槽唯一索引；published版本也禁止上传改包。PackageFingerprint对该版本file集合取指纹。若把一个源包和多个目标产物直接塞原槽，会覆盖文件、改变旧审核或被状态锁拒绝。

**本方案推荐选择：不可变源版本＋不可变目标发布快照。** 同源后续增加机型，只新增目标构建与目标成品审核/发布，不重写已发布目标。代价是下述角色和审核scope迁移；若首版不采用此迁移，只能明确退化为“所有目标一次构建发布，增加目标必须新版本”，不能同时宣称发布后无限追加目标。

| 数据对象（提案） | 字段／规则 |
|---|---|
| skill_assets增量 | asset_role=legacy/source/artifact、asset_key、immutable_at；旧行只回填legacy，源和产物不进旧上传槽；按摘要寻址且不覆盖已审核字节 |
| skill_build增量 | build_key唯一、source_asset_id、source/manifest/IR/profile/toolchain/binding/runtime摘要、source_format、last_run_id、完整output hash |
| skill_build_run新增 | attempt、lease_generation、worker、状态、输入/输出、检查与日志、started/finished；迟到失效worker不能覆盖新run |
| skill_target_release新增 | source version、target/profile、build/run、artifact ID/hash、review scope、验证范围、授权适用性；内容快照不可变，发布/撤销转换另留审计 |
| audit_record扩展 | scope=legacy/source/target及snapshot引用；复用审核，不另建独立审批系统；最终成品审核不能仅继承源审核 |
| deploy_task扩展 | target_release_id、签名版本/序号/期限、授权策略、恢复目标；执行任务增加execution_id/plan摘要；保持现有六操作兼容 |
| install_record扩展 | actual_asset_id/hash、runtime/profile摘要、observed_at；归档旧版不等于撤销，明确良好恢复快照 |
| skill_execution / event新增 | execution ID、task/release/entitlement/did、状态、参数摘要、期限、实际节点结果；事件ID唯一和append-only事实对账 |
| Agent本地SQLite | tasks、checkpoints、installed、outbox、revocations、execution/resource leases；事务/掉电边界与恢复逐项测试 |

源包不能出现在付费交付列表或Agent取包接口；客户买到的是授权对应的目标成品。C端SkillController目前已有isFileBacked列表/下载，改造须逐入口加角色与release检查，不能继续笼统说“所有下载只认file”。Agent解析/指纹/取包路径仍有isFile约束，外层file方案不等于可省略源隔离。

source_format及其契约摘要与源版本一起不可变，用于选择新审核/发布路径，禁止portable版本误走legacy全版本指纹。source/artifact角色由可信服务赋值，不能由客户端mass assignment任意声明；生成成品仅允许构建控制面追加，开发者上传API不能借artifact角色绕过published版本保护。

### 19.2 指纹与发布迁移

保留现有legacy版本指纹，不原地改算法使历史审核全部失效。新增source指纹与target-release指纹，目标指纹覆盖源、IR、能力、binding、runtime、最终包与适用验证范围。源审核可判声明/许可，目标审核必须检查真实成品；一个目标撤销不自动抹掉所有目标，源安全问题则可按影响范围撤销全部关联成品。

SkillPublisher发布新版本会归档旧版本；回滚目标只要是明确获准、未撤销、兼容且有恢复权限的历史release，不应仅因parent version已归档就拒绝。正常新安装仍遵守现行销售/发布/授权范围；目标快照不是绕过旧授权购买版本限制的入口。

迁移顺序：只读preflight → additive字段与新表 → role-aware读写双兼容 → 对旧行回填稳定asset_key/legacy → 核对引用/计数/摘要 → 切新source/artifact writer及scope → 最后替换旧槽位唯一规则。不得先删索引，再让旧upsert面对多个目标成品。回退保留新历史，旧客户端只读取legacy允许范围；不把source重新暴露为旧下载兜底。

### 19.3 精确代码改造地图

以下相对 `my-bagisto-store/`，均为将来实现任务：

| 真实入口 | 改造内容 |
|---|---|
| Shop `Services/SkillPackage/SkillManifest.php`、`ManifestValidator.php`、`PackageInspector.php` | 固定runtime source profile、调用PortableSourceValidator；现有脚本扫描不能代替沙箱；未知格式拒绝 |
| Shop `Services/SkillCompile/SkillBuildService.php`、`CarrierBuilder.php`、`BuildResult.php`、config/skill_builders.php | 异步协调、源格式选择、BuildContext、独占run；success必须有真实产物和证据，不接受success(null) |
| 新 Shop `Services/SkillCompile/Portable/` | SourceParser、CapabilityResolver、SemanticLowerer、TargetAppBuilder、SemanticTraceVerifier |
| Shop `Services/SkillSupply/AssetUploadService.php`、Models/SkillAsset.php | 源角色和不可变ArtifactWriter；替换旧覆盖槽的写法，不让源覆盖成品 |
| Shop `Services/SkillPackage/PackageFingerprint.php`、`Services/SkillAudit/AuditService.php`、SkillPublisher.php、ReviewGate.php | 版本化scope及target release；源审核和目标成品审核分开 |
| Shop `Services/SkillDelivery/SkillDeliveryAssetResolver.php`、CompatibilityService.php | 强制精确target release与环境；portable分支禁止fallback到源或唯一file；逐入口验证asset_id不能绕过 |
| Shop `Services/Deploy/DeployTaskService.php`、API/AgentController.php、AgentPackageController.php | v2签名、领取复核、ACK/对账、明确恢复目标；7/8不能误落安装记录 |
| Shop `Http/Controllers/AgentSetupController.php` | 将内嵌参考Agent提取为可独立测试/发布的代码包，控制器保留分发职责；实现SQLite、插件监督与runner |
| Shop `Services/SkillDelivery/TrialService.php`、`Services/Deploy/DeviceCredentialService.php`、VerifyAgentKey中间件 | 统一期限、强device身份和吊销；复用授权，不新建另一套购买/试用系统 |
| config/skill_delivery.php、config/queue.php、bootstrap/app.php | 复用私盘与database队列；专用worker、租约、资源/存储告警；不把Redis/COS设成无依据前提 |

### 19.4 接口、权限与三端交互

| 接口（已有／提案） | 角色与行为 |
|---|---|
| 已有 POST /api/skill-supply/versions/{versionId}/compile | internal.key；拟改异步202＋逐目标build ID/拒绝原因，调用者不能写success或可信hash |
| 已有 GET /api/skill-supply/versions/{versionId}/builds | 返回源/目标/构建/审核/发布/验证分开状态，不合成一个“成功” |
| 提案开发者版本preflight/build接口 | 仅自己的版本，共享DeveloperSkillService归属检查，不泄漏internal.key；具体路径在B0冻结 |
| 提案build run详情/取消、target release提审/发布/撤销 | 开发者/审核员/发布员分权，不能由运营手填构建或HIL成功；重复请求幂等 |
| 提案 POST /api/skills/entitlements/{id}/executions | customer；绑定本人设备与获准release，返回202＋execution ID；客户作用域nonce去重，同nonce改参数返回冲突；执行不新增收费/订阅，重试不重复动作 |
| 提案 GET /api/skills/executions/{id} | customer本人作用域；返回实际节点、成功/失败/取消/未知，脱敏日志 |
| 提案 POST /api/skills/executions/{id}/cancel | 仅有处置权的调用者；返回取消已受理，不伪称物理停止 |
| 已有 /api/agent/commands、evt、packages | v2扩展身份/执行/ACK/对账；未知新码不得发给旧Agent；禁止任意asset URL |

这些新增路径未实现，必须与路由、ACL、错误码、OpenAPI及 `utils/api.js` 同批更新，不能在界面提前显示已可用按钮。

开发者门户沿用 `Resources/views/dev/skills/show.blade.php`、edit页面：上传标准源、验证节点/资产/权限、选择目标、查看逐目标支持原因、构建日志与审核状态；不要求每个技能重传N份品牌包。

Admin沿用 `Admin/.../Catalog/SkillReviewController.php` 与 `catalog/skill-reviews/show.blade.php`：并排显示源、能力变化、lowering、最终成品及环境证据；敏感profile/关节限位变更走代码评审，运营不能随手调安全参数。

客户沿用 `pages/mine/skills.vue`、devices.vue、agent-setup.vue和唯一API入口：选绑定设备、看精确支持范围、安装/启用、启动一次执行、取消、查看未知状态与恢复结果。提供加载/异常/无适配成品状态，明确manual的人工限制。价格、买断、退款和真实支付仍依赖既有交易体系，本方案不把mock支付改称真实支付完成。

## 20. 可直接拆工单的开发批次、资源与验收

### 20.1 推荐实施栈

- 平台：现有Laravel/Bagisto模块化单体、MySQL、database队列、私有持久盘；异步构建在受控worker中，注册表唯一。
- 编译：Python隔离工具进程负责解析/媒体/动作工具编排；固定GMR/编码器/模型环境。没有新的常驻业务微服务要求。
- 设备：基于现有Python Agent提取生产包，SQLite journal/outbox，systemd或厂商允许的应用监督方式；目标准确版本、UID/容器/ABI由profile固定。
- 行为执行：固定BehaviorTree.CPP runner＋受控能力IPC＋厂商子进程；禁止技能任意发SDK命令。代码依赖许可、target ABI和跨语言黄金向量在B0实测后锁定。
- 动作计算：CPU/GPU分队列；仅当所选策略训练确需GPU时引入独立构建资源，不把GPU训练放到设备或Web请求。

### 20.2 主线批次与有效工程人日

估算为方案级范围，**不是已测性能或日历承诺**。前提：现代码可启动，两位熟悉项目的平台/设备工程师，兼职前端与QA，能取得两个目标的SDK、合规成品与测试设备。人日可并行，不等于人数乘固定周数；外部采购、权限、商业许可、厂商响应及安全签收等待不计入。

| 批次 | 具体工作与产出 | 退出条件 | 估算人日 |
|---|---|---|---:|
| B0 契约和数据锁定 | 源profile、能力节点/IR、完成/取消语义、样例、target profile、asset角色、发布scope、签名/执行码、迁移preflight | 审查能判断“支持/拒绝/未知”；明确旧裁决差异；样例不是生产能力证明 | 5–8 |
| B1 资产与构建底座 | additive迁移、不可变writer、build/run、幂等、租约、隔离worker、指纹scope | 同源多目标不覆盖；并发只一有效输出；失效worker和缓存反例通过 | 8–12 |
| B2 可移植编译器 | XML白名单→能力预检→IR→两个模拟target→真实runner构建 | 同源抽象轨迹一致，缺能力拒绝，恶意源拒绝；不用伪造vendor调用通过验收 | 10–16 |
| B3 Agent与执行可靠性 | 提取参考Agent、device/v2、SQLite、插件监督、7/8执行取消、补报/对账、安全退出 | E2/E3故障注入通过，执行和安装事实不混淆，未知副作用不重放 | 12–20 |
| B4 审核/三端接线 | target release审核发布、ACL、开发者构建、Admin证据、客户安装/执行/取消 | 源包不可下载执行；一目标撤销可追踪；旧版legacy兼容和越权测试通过 | 8–12 |
| B5 第一机型 | G1 EDU或实际可取得的首机型，固定高层节点/媒体，真实安装/取消/恢复 | 规定场景HIL及独立观测；不替换厂商运控，不用状态标签验收 | 8–15 |
| B6 第二品牌 | 智元一个明确SKU；复用同一源和节点契约，新增目标binding | 两品牌source hash相同、能力轨迹等价；不支持样例有明确拒绝 | 8–15 |
| B7 上线与运维 | 全链路、迁移/回退、备份、队列、密钥与值守；默认关闭、小范围放量 | E1—E3实际整链、部署签收、处置通路验证，回滚证据齐全 | 6–10 |

主线合计约 **65–108有效工程人日**，目标是“两品牌标准行为编排＋可信交付＋执行/恢复”。不得把这个数字用于承诺五品牌全部机型或任意全身动作转码。

B1与B2接口冻结后可并行；B3可并行于编译器；B4依赖发布scope；B5/B6只有拿到指定设备条件才执行。依赖外部条件的任务保持条件触发，不塞进当前P0固定日期。

### 20.3 动作专项与后续品牌

一个固定目标、已有获准runtime/策略、素材权利齐全时：源探针/profile 2–4人日；规范化/金标4–6；GMR封装/证据3–5；一个exporter/消费者桥4–7；离线/SIL检查4–7，合计 **17–29动作/适配工程人日**。与主线共享基础设施的部分不得重复计费；该估算不含按动作训练、GPU计算和HIL外部等待。

先用一个有权使用的短动作测出训练/推理/构建耗时、CPU/GPU内存、策略可复用范围、失败率，再给容量和成本。未做测量前不报每技能几秒、每小时处理量或训练费用。

第二轮品牌在B6模式上逐个增加PM01、Walker S2 EDU，再按实际需求扩展R1/T800/Pro；每个目标先交付profile和2–3个能力金标，再承诺更多类别，不把一个品牌SDK存在当全部机型已支持。

### 20.4 测试、发布与回退清单

| 测试层 | 必须覆盖 | 证据 |
|---|---|---|
| 单元/契约 | 节点参数/权限/预算、源与产物角色、错误码、格式选择、target能力、指纹/构建身份 | 机器输出＋固定夹具，源语义validator不能仅复用manifest形状结果 |
| 并发与隔离 | 同key并发、worker中断/租约代次、两目标不互盖、源与成品不混 | 独立测试库，多进程/存储故障；禁止使用root |
| 语义与模拟 | 两target顺序/参数/计数等价，取消接受但未停止，超时后仍运动 | runner事件轨迹＋独立模拟器观测；不把SDK mock当真机证据 |
| 设备故障 | 收到任务/持久接收/外部调用/提交/done/ACK每边界掉线重启；过期/吊销/撤销覆盖旧journal | 安装与execution实际事实对账；超时不抹历史 |
| 动作格式 | 帧/时长、单位、四元数、关节顺序、CSV两格式、MNN/obs不匹配 | 数值golden、格式消费者回读、静态与动力学SIL分开 |
| HIL | 固定SKU/固件/SDK/profile/runtime/动作/场景，取消安全退出、恢复、首装无旧版、断电 | 原始日志、设备摘要、实际观察、执行者和证据范围；一次抽样不覆盖全部后续动作 |
| 发布/回退 | 阶段开关、源码不泄漏、旧版manual/legacy、归档良好版本恢复、租约/密钥变化 | preflight、迁移expand/backfill/switch/recovery、值守签收 |

拟议开关按构建、portable交付、execution、新动作分别隔离，默认关闭；具体名称在B0进入现有配置与契约门禁。先内部账号和测试设备、再单目标小范围、最后按证据扩展。撤回时只停新构建/安装/执行，保留ACK/对账/取消/禁用/卸载/回收。禁止drop历史、覆盖success或关闭全部Agent入口。

角色分工：平台工程负责资产/构建/审核/授权；设备工程负责Agent/插件/observe；动作工程负责转换/策略/模型/质量；QA独立复现故障与HIL证据；运维负责worker、持久存储、信任根、备份与值守；产品负责人批准业务范围和语义替代，不替代技术验收。

## 21. 调研依据、证据边界与本次交付状态

### 21.1 原始资料索引

| 资料 | 用于哪项决策／已取得的事实 |
|---|---|
| [BehaviorTree.CPP XML4](https://www.behaviortree.dev/docs/learn-the-basics/xml_format/)、[异步动作](https://www.behaviortree.dev/docs/tutorial-basics/tutorial_04_sequence/)、[4.9.0候选发布](https://github.com/BehaviorTree/BehaviorTree.CPP/releases/tag/4.9.0) | 采用已有XML与实际runner；halt和完成是需要适配的异步语义；具体tag/ABI尚未编译验证 |
| [ROS2 Actions原始设计](https://raw.githubusercontent.com/ros2/design/gh-pages/articles/actions.md) | goal/result/feedback/cancel分离，取消接受不等于完成；只借鉴协议，不强迫所有厂商改ROS2 |
| [G1动作接口](https://github.com/unitreerobotics/unitree_sdk2/blob/main/include/unitree/robot/g1/arm/g1_arm_action_client.hpp)、[AudioClient](https://github.com/unitreerobotics/unitree_sdk2_python/blob/master/unitree_sdk2py/g1/audio/g1_audio_client.py) | 已登记动作执行/查询/停止、音频流播放/停止；不能推定任意动作导入或播放完成语义 |
| [AimDK X2开发模式](https://x2-aimdk.agibot.com/zh-cn/latest/quick_start/prerequisites.html)、[A2资源](https://open.agibot.com/docs/aimdk/a2/v1_3/dev_guide/07-13-resource_manager)、[A3资源](https://open.agibot.com/docs/aimdk/a3-ultra/v3_3/dev_guide/07-05-resource_manager) | 机载/外接计算区分，上肢动作资源与全身舞蹈技能权限区分，MCAP内容协议仍有缺口 |
| [GMR BVH导出](https://github.com/YanjieZe/GMR/blob/master/scripts/bvh_to_robot.py)、[BVH loader](https://github.com/YanjieZe/GMR/blob/master/general_motion_retargeting/utils/lafan1.py) | 输入骨架/单位/时间不能猜；输出xyzw/关节参考不是可执行厂商包 |
| [众擎GMR](https://github.com/engineai-robotics/GMR)、[CSV loader](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_mimic_trajectory_example/src/csv_loader.cc)、[NPY loader](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_mimic_trajectory_example/src/npy_loader.cc) | 官方转换模型与实际消费者存在，但格式/布局不同，必须分exporter |
| [Dance runner](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_dance_example/src/rl_dance_example_runner.cc)、[RL-Lab](https://github.com/engineai-robotics/engineai_rl_lab/blob/main/README_EN.md)、[csv_to_npz](https://github.com/engineai-robotics/engineai_rl_lab/blob/main/scripts/csv_to_npz.py) | 完整到策略训练/导出链，dtype/shape/wxyz/obs及初末姿态是实质依赖，训练时间未测 |
| [Walker S2 EDU](https://docs.ubtrobot.com/walker-s2-edu/docs/sdk/5/)、[小米运动管理](https://github.com/MiRoboticsLab/blogs/blob/rolling/docs/cn/motion_manager_cn.md)、[二开条款](https://agreement.cyberdog.xiaomi.com/developer.html) | 部署入口并不自动开放任意动作；developer_mode、可视化自定义接口及功能限制需按产品边界使用 |
| [SMPL-X许可](https://smpl-x.is.tue.mpg.de/modellicense.html)、[AMASS许可](https://amass.is.tue.mpg.de/license.html) | 公开授权限定非商用研究，代码MIT不覆盖素材；首期选择自采/获授权BVH并留证 |

本索引是公开资料核验，不是厂商对本平台的适配认证。以main/master/latest读取的资料须在B0锁源码快照、版本、依赖/模型摘要与许可记录；不能把文档读取日期当作SDK版本。公开不足的接口对应§18.4明确取数任务，不伪造已取得数据。

### 21.2 本轮完成和未做

已完成：结合五品牌第一手资料和现仓库源码，形成P/V/M三路径、行为/能力/IR、目标绑定、实际执行与取消、不可变发布快照、精确代码/API/三端改造、B0—B7工单、动作格式桥及取数/验收/估算方案。源样例只做现有清单校验与XML结构检查。

未做：业务代码、迁移/API/新操作码、真实编译器与BT.CPP运行、完整示例音频、SDK编译、样机安装/动作、双环境重建、策略训练、HTTP/数据库整链及HIL；没有为这些项目补假成功记录。

本次只改方案/ADR并增加docs下的契约示例与校验记录；SDK/CLI未立项的旧约束仍有效。建议新增的是完成产品目标所需的受限能力节点和内部执行协议，完整通用SDK、CLI、任意代码转译及低层控制器仍不在默认范围。范围、语义替代、DEV-02/DEV-04差异和目标设备按P-01—P-06审查；无需把常规工程实现细节逐项转交负责人。

<!-- END ORIGINAL docs/agent-runtime-standard-transcoder-blueprint.md -->


## 附录 7 首轮独立验收全文 历史44ca8ff基线

原路径：`docs/skill-dev-step10-independent-acceptance-20261103.md`；SHA-256：`028bc0c75ab2efb9508bab56a17b4452cd965e8e757f873b6f788aacbea3769f`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/skill-dev-step10-independent-acceptance-20261103.md -->
# Agent 与标准技能编译器独立验收（2026-11-03）

**结论：本轮不通过交付验收。** 已形成可继续开发的设备端和平台端骨架，部分正常路径测试成立；尚未形成可安全交付的 Agent，也未实现所承诺的真实机型技能执行及标准技能转码闭环。先修复 Agent，再重新验收第二阶段。此次只新增本报告和隔离验收材料，未修改业务代码、未操作真实设备。

## 基线与实测范围

- 分支：`main`；验收起点工作区干净。
- 第一阶段提交：`d9bf6695ff8c239ccb592a889cb9ec95db531f9d`。
- 第二阶段／当前 HEAD：`44ca8ff83ba894450e757b7f5bcf8b0daf4f8804`。
- 比较基线：`542502b64b817f35ab9f31f382564a2ea9dfe849`；两笔提交共改动 54 个文件。
- 以源码、实际生成物、隔离数据库和反例探针为依据；交付报告与 AGENTS.md 的“已闭环”仅作为待验声明。
- 平台测试实际连接 `summon_mecha_step10_test`，先用 `SELECT database()` 核验；测试使用事务回滚。未迁移或清空业务库。
- Python：3.12.14；PHP：8.3.4。未核验真实部署、厂商 SDK 安装环境、系统守护进程或 HIL。

| 核验项目 | 实测结果 | 可以证明的范围 |
|---|---|---|
| 原样 Python 测试 | 11 个测试／加载结果中 7 通过、4 个模块加载错误 | 当前源码在声明支持的 Python 3.12 下有导入阻塞；不能声称本环境 27 项通过 |
| 仅在隔离副本补齐两个类型导入后 | 27 项通过 | 原有测试正常路径可通过；不是当前提交原样通过，也不覆盖下列反例 |
| AgentV2ExecutionTest + AgentDeviceCredentialB12Test + Deploy 全目录 | 142 项、4434 断言通过 | 这些既有用例的断言成立；不等于权限、恢复、真实动作闭环成立 |
| ecosystem:contract-check | 通过，45 contracted routes / 85 error codes / 11 state inventories | 生态模块契约门禁成立；不能替代新增 Agent 协议和真实执行验收 |
| Agent 路由清单 | 已读取 | 路由存在，不等于端云全链路已打通 |
| 补充反例 | 下列多个关键门槛失败 | 证据见对应 JSON 和探针 |

原始材料目录：`C:/Users/Administrator/.codex/visualizations/2026/11/03/01a19fd3-10da-75d2-8a92-d58cbe14920c/acceptance-44ca8ff/`。`agent-probes.py` 明确记录了仅对诊断副本所作的两处类型导入修正，其余受测逻辑来自此 HEAD。该目录不是生产配置或设备目录。

## 阻断发现与复验门槛

### R01 · P1：Agent 原样无法在 Python 3.12 启动

**证据位置：** `agent/zhjj_agent/executor.py:4` 未导入注解使用的 `Tuple`；`package_manager.py:11` 未导入 `Any`。`python-baseline.txt` 保存导入错误。临时目录已切到可写隔离目录，最终 4 个加载错误不包含首次尝试时的沙箱临时目录权限问题。

**失败场景／实验：** 原样运行 `python -m unittest discover -s agent/tests -p "test_*.py" -v`，加载 TaskManager／CLI 所依赖模块时报 `NameError`。诊断副本只补两个导入，原 27 项通过。

**最小修正与门槛：** 补齐导入并在明确支持的 Python 版本原样运行 CLI 和测试；冻结 Python 与依赖版本。不能以某个采用延迟注解的解释器上的成功代替对已声明版本的支持。本项修复本身不解决其他发现。

### R02 · P1：验签对象与实际执行对象分离，设备和授权门禁不完整

**证据位置：** `task_manager.py:142` 从外层 `cmd` 取得 operation、skill_id、version、task_id，随后验签内层 `signed`；未强制两者一致，也未核对 `signed.did == client.did`。`_handle_install` 使用外层哈希。授权期限、目标发布／已安装成品身份等字段未形成执行门禁。

**实测反例：** 保持签名不变，只把外层 operation 从停用 5 改成启用 4，原本禁用的技能被启用；签给 device-B 的有效指令在 device-A 被接受；`authorization_expires_at` 已过期但任务期限未过时，启用仍成功。见 `agent-probes.json`。

**成立前提／影响：** 操作者能改写指令信封，或端云产生重复字段不一致时即可触发；这是隔离逻辑复现，不是已完成外网入侵测试。签名密码算法正确无法补救执行字段未受绑定的问题，阻断安全交付。

**最小修正与门槛：** 只把已验签、已校验 schema 的对象用于决策；外层只允许必要传输字段，重复字段必须一致。逐操作检查 DID、任务及授权期限、目标环境、成品身份与撤销状态。篡改内外层每个关键字段均应在副作用前拒绝。兼容 v1 必须明确边界；当前平台建任务默认仍为 v1，不能仅因响应附带 signed 就宣称安装链已升级为 v2。

### R03 · P1：G1 适配器没有真实执行桥，却自动返回成功和已停止

**证据位置：** `adapters/unitree_g1.py:121` 执行仅写内存；`:151` 观察在 0.05 秒后置 SUCCEEDED；取消直接设置 `is_stopped=True`。源码只检测 SDK import，没有动作／音频调用或真实状态订阅。安装和 observe 同样读写同一份内存字典。

**实测反例：** SDK 不存在、安装路径不存在、未启动 action.py，仍返回 accepted，随后 SUCCEEDED / is_stopped=true。见 `g1_no_sdk_no_entrypoint_success`。现有 G1 e2e 自行拼装 ZIP，未调用 PHP 编译器，也未核对入口是否运行。

**最小修正与门槛：** 模拟行为只放 MockAdapter。真实适配器缺 SDK、权限或观测时明确拒绝／UNKNOWN。锁定官方接口版本，证明实际入口启动、动作／音频调用、失败与完成信号、取消后的独立观测；先用 SDK stub 验证调用，再做受控 HIL。HARDWARE_UNVERIFIED 标签本身不能使模拟成功具备交付真实性。

### R04 · P1：恢复会把旧安装当成新任务完成，回报与对账不能保证收敛

**证据位置：** `task_manager.py:88` 对所有未完成操作统一执行 observe，只要同 skill 显示 active 就提交 done；不比较 operation、目标版本、成品哈希和检查点。`:85`、`:137` 把 TASK_TRANSITION_INVALID / TASK_NOT_FOUND 也直接标 ACK。`AgentController.php:404` 的 reconcile 未处理未知端侧记录、云端单边记录或 tasks，且 aligned 固定为 true；ACK 端点只返回响应，未持久记录接收事实。

**实测反例：** 实际 v1，v2 安装任务停在 DOWNLOADING；恢复后任务被置 COMMITTED 并上报 done。服务端拒绝 late done 后，端侧待发队列变为 0。真实控制器收到“云端 installed、端侧 uninstalled”及一条云端未知安装，仍返回 aligned=true，未知记录被忽略。见 `agent-probes.json`、`platform-probes.json`。

**最小修正与门槛：** 按操作／检查点恢复，独立确认实际资源、版本、路径与摘要。服务器持久接收 event_id/seq 和迟到事实；非法状态流转不等于已存事实。对账明确列出双方单边、冲突和未知结果，再按规则收敛，保留原 timeout。故障注入覆盖投递响应丢失、接收 ACK 丢失、安装切换前后崩溃、done 丢失和超时后补报。新进程适配器应重建真实资源视图；当前内存适配器重启后连已安装技能也观察不到。

### R05 · P1：执行、取消、撤销和资源互斥未形成闭环

**证据位置：** `task_manager.py:365`、`:399` 不持久提升撤销代次，也不协调在途执行；`storage.py:400` 的 set_revocation_epoch 未接入任务处理。`:424` 的租约只按未过期 ACTIVE 记录判断。`_handle_execute` 同步等待完成，主轮询期间无法接收新的取消／回收命令；execution_deadline 被直接当持续秒数。SkillController 发起执行只检查 status，未复用包含 expires_at 判断的 isUsable，也未验证固定空参数契约及实际安装成品。

**实测反例：** 停用携带 epoch=5 后，本地 epoch 仍为 0，运行记录未被取消；模拟旧租约时钟过期后，无停止观测即可取得新的冲突租约。不同 task_id 携带相同 execution_id 会两次调用适配器。见 `agent-probes.json`；长动作取消接收和真实超时处置尚未做 HTTP 故障注入，以上并发限制来自调用链分析。

**最小修正与门槛：** 先持久冻结新执行并提升代次，再取消、观测和清理。执行记录按 execution_id 持久去重，未知物理状态不能靠租约时间流逝释放。用不会阻塞取消接收的监督循环；区分绝对 deadline 与 duration。验证回收后旧任务／旧 journal 不会重新激活、同执行号只产生一次副作用、长动作可及时取消、UNKNOWN 保持资源占用。试用到期与买断权限分别验收。

### R06 · P1：升级回滚仍没有使用明确的良好恢复对象

**证据位置：** `task_manager.py:320` 使用外层 version 作为恢复版本，忽略 signed 中的 restore_asset_id / restore_sha256。旧 GrayReleaseService 回滚调用仍传待撤回版本；平台 recordInstall 仍主要按原任务版本落记录。

**实测反例：** 当前 v2、previous_version=v1 且 v1 目录存在，回滚任务携带明确 good-v1 身份但沿用坏版本 v2 的 version 时，Agent 停用 v2，并上报 NO_PREVIOUS_VERSION；没有恢复 v1。此场景并非首装。见 `rollback_with_previous_version`。

**最小修正与门槛：** 分离撤回对象和恢复对象，校验旧成品哈希、环境、审核及撤销状态后切换，再独立观察。验证首装安全停用、升级恢复到良好字节、恢复对象撤销时拒绝、平台与设备实际恢复对象一致。单独通过首装停用测试不代表升级回滚完成。

### R07 · P1：编译器用示例代替缺失输入，生成入口不能运行

**证据位置：** `PortableSkillBuilder.php:128` 缺源包时回落 docs 示例，随后回落服务器相对路径；`TargetAppBuilder.php:89` 给缺失音频生成 44 字节静音占位，正常 build 未传 sourceAssetDir；`SemanticLowerer.php:109` 生成的 Python 引用未定义 modelCode。其动作／音频步骤只有 sleep 和 print，没有真实运行器调用。

**实测反例：** 在独立事务中创建 published 版本，源资产为 0，仍构建 success，并生成 44 字节 welcome.wav；实际运行生成的 action.py 立即报 NameError。见 `platform-probes.json`、`generated-action.py` 和 `generated-action-run.txt`。

**最小修正与门槛：** 缺任何必需源或素材必须拒绝；只读取绑定源资产，不读取服务器任意路径、不用示例或静音替换内容。构建真实运行器／绑定契约，执行最终归档中的入口，核对动作次数、素材摘要与完成条件。修正 NameError 后，print/sleep 仍不能算转码执行已实现。

### R08 · P1：接受的行为树语义超范围，降阶会改变行为

**证据位置：** `PortableSourceValidator.php:11` 放行 Parallel、Fallback、Reactive 控制节点及 Retry/Repeat 等装饰器，与蓝图受限 Sequence 目标不符；`SourceParser.php` 收集所有 BehaviorTree 的动作；`SemanticLowerer.php:40` 只遍历扁平 actions，未按 main tree 或控制结构运行。SM_MotionSequence 在校验和 Profile 中被允许，但 lowerer 没有对应分支。当前没有真实 BT.CPP runner。

**实测反例：** main=Main 加一棵未引用 Unused，生成结果包含 Unused 的动作；Parallel 被校验通过；单独 SM_MotionSequence 校验通过、IR 1 个动作，降阶成 0 步。去掉 manifest 的 permissions 和 safety，validateManifest 也不报错。见 `compiler-probes.json`。

**最小修正与门槛：** 按已定首版严格拒绝未实现节点，只处理被选中且可达的树；不能静默删除动作或改变控制语义。补齐类型、权限、时长／深度／节点数、素材完整性校验。用外部定义的金标验证“输入语义—IR—最终可观察行为”，包括必须拒绝的反例。

### R09 · P1：目标机型没有贯穿构建、交付和设备门禁

**证据位置：** 原 `SkillDeliveryAssetResolver.php:106` 的“版本内唯一文件”回退，在新增机型专属成品后仍适用；目标资产未区分 source/artifact，也没有目标发布快照约束。G1 prepare 读取 compatibility.models，而新生成 manifest 使用 target_model_code，字段不一致；其 vendor OR model 判断也不足以匹配精确环境。

**实测反例：** 版本只构建一个 G1 成品，调用真实 resolver 请求 agibot_a2，返回同一 G1 文件。见 `wrong_model_fallback`。这证明解析层可错选；尚未执行跨品牌真机下发。

**最小修正与门槛：** 对新机型专属成品，缺目标构建／获批发布快照必须拒绝，不沿用 legacy 唯一文件回退；保留 legacy 的独立兼容路径。安装端检查同一目标快照和环境。验收包含正确机型、另一品牌、未知机型、不同 SKU／SDK／固件及同型号不同成品。

### R10 · P1：构建与任务身份未冻结，旧审核和恢复依据会漂移

**证据位置：** TargetAppBuilder 每次加 built_at、新增同版资产；SkillBuildService 继续按 version+model 覆盖同一条 build；没有实现蓝图的 build_run、target_release 和审核对象隔离。`DeployTaskService.php:524` 每次 buildCommand 重新生成 signed，未显式 task_expires_at 时用 now+3600。

**实测反例：** 对 published 版本重建，相同 build_key 得到不同 SHA、不同 asset_id；版本总指纹改变，历史 build 仍只有一行。同 task 再取指令，有效期可被延长 3600 秒，signed 也变化。见 `published_recompile`、`same_task_refreshed_expiry`。

**最小修正与门槛：** 输入绑定真实源／素材、工具链和目标环境；构建历史不可覆盖、审核绑定最终字节及发布环境。发布版本禁止隐式插入改变审核对象的资产。任务首次冻结 signed，刷新仅更换定位同一字节的限时 URL。独立重建比字节、发布后补机型不污染旧审核、刷新不延长期限、恢复能定位历史良好字节，均须通过。

## 其他必须修复或补证的事项

- **轨迹约束：** MotionTranscoder 的角度裁剪不是速度／加速度验收。纯计算实测右肩首末段速度约 2.109 rad/s，非声明的零速；差分峰值加速度约 9.013 rad/s²，高于自身 Profile 的 6.28。算法没有实现速度、加速度或碰撞／平衡检查，不能称“物理安全转码”。见 `compiler-probes.json`。禁止因此直接接真实关节流；先限定高层能力，动作专项另行验证。
- **Profile 依据：** G1 把 23/29 DoF 合在一个 Profile；A2 Profile 同时写 AimDK X2 和 A3 Ultra，未给固定 SDK commit／原始接口证据。当前 JSON 与测试只证明“录入了这些声明”，厂商正确性、动作可写入接口和安装权限仍待核验。未在本轮进行厂商环境／机型实测。
- **JCS：** PHP 使用 strcmp 排序与浮点强转，Python 使用 json.dumps；不等于 RFC 8785 的完整实现。以 1e-7 为输入，PHP 探针输出 `1.0E-7`，Python 序列化会得到 `1e-07`，与 RFC 要求的 ECMAScript 表示也不同。需采用经过验证的实现或完整官方测试向量，覆盖数字、UTF-16 排序、空对象／数组、非法值；简单 ASCII 整数签名往返不证明互操作成立。外部判据：[RFC 8785 §3.2](https://www.rfc-editor.org/rfc/rfc8785)。
- **旧构建器回归：** PortableSkillBuilder 注册第一且 supports 接受 motion/composite；非 btcpp/xml4 的旧 motion 也被选到它，再失败，不再走 MotionCarrierBuilder。`legacy_motion_builder_class` 已复现选择结果；需增加真实旧构建路径回归并让不匹配的格式继续选择正确 builder。
- **前端与运维：** 本次提交没有同步客户端 API 入口和现有页面的执行／取消／待对账体验；未提供冻结依赖文件、验证过的进程托管／升级恢复工件。不能把新增后端路由等同于平台用户可操作。真实部署与浏览器验收未执行。

## 返工顺序与重新验收条件

1. **恢复可运行基线：** 修正导入；固定运行时；原样 CLI／测试通过；G1 真实适配器禁止隐式模拟成功。
2. **锁定安全对象：** 已验签对象唯一驱动执行，绑定设备、权限、成品与目标；补上所有关键篡改反例及授权失效测试。
3. **完成 Agent 可靠性：** 按操作恢复、事实持久接收／对账、执行去重、非阻塞取消、撤销与未知资源占用、显式良好回滚；完成隔离端云 HTTP 故障注入。
4. **重新验收 Agent：** 正常路径与反例均通过，现有业务回归通过；平台页面和运维文档一致。到此才能称“公共 Agent 软件闭环完成”，真机适配仍单列。
5. **再做第二阶段：** 移除示例／静音兜底，收紧可支持语义，建立不可变源／构建／发布关系、精确目标交付和真实 runner。对最终成品做独立执行验证，再补对应厂商 SDK 和受控 HIL。

不建议推倒全部重写：独立包结构、受限下载、部分 ZIP 防护、Journal／Outbox 表、安装／执行事实分离和既有回归测试可保留。需返工的是这些组件之间的契约与实际副作用证明。

**当前对外口径应为：** 第一阶段“部分实现，独立验收不通过”；第二阶段“原型实现，独立验收不通过”；G1“未接入真实执行与独立停止观测，真机未验收”。在返工及复验前，应修正 AGENTS.md 和两份交付报告的“已闭环／安全可执行／全链路打通”声明。本轮仅记录建议，未直接修改他人的交付记录或业务实现。

<!-- END ORIGINAL docs/skill-dev-step10-independent-acceptance-20261103.md -->


## 附录 8 标准源清单原文 不完整源不是成品

原路径：`docs/portable-skill-spec/examples/greeting/manifest.json`；SHA-256：`528c789abab7834345b0736376cd863b41add807a92eade4eced719778271e87`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/portable-skill-spec/examples/greeting/manifest.json -->
````json
{
  "schema": "zhaohuan.skill/v2",
  "kind": "composite",
  "name": {
    "zh_CN": "标准迎宾",
    "en": "Portable Greeting"
  },
  "version": "1.0.0",
  "description": "在支持右臂挥手和本地音频播放的目标机型上执行相同迎宾行为契约。",
  "pricing_mode": "buyout",
  "delivery_channels": [
    "agent",
    "manual"
  ],
  "permissions": [
    "motion_control",
    "audio_play"
  ],
  "resource_type": "skill",
  "safety": {
    "risk_level": "medium",
    "requires_open_area": true,
    "estop_required": true,
    "max_duration_s": 20
  },
  "runtime": {
    "source_format": "btcpp/xml4",
    "capability_contract": "portable-actions/v1",
    "source_entrypoint": "behavior/main.xml"
  }
}

````

<!-- END ORIGINAL docs/portable-skill-spec/examples/greeting/manifest.json -->


## 附录 9 标准源行为树原文

原路径：`docs/portable-skill-spec/examples/greeting/behavior/main.xml`；SHA-256：`450d55e169faab846dd85d2fd17617d86732876afbdc1c5441bf8750d909f022`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/portable-skill-spec/examples/greeting/behavior/main.xml -->
````xml
<root BTCPP_format="4" main_tree_to_execute="Greeting">
  <BehaviorTree ID="Greeting">
    <Sequence name="greeting_sequence">
      <Action ID="SM_GestureWave" name="wave_right_3" hand="right" cycles="3" timeout_ms="12000"/>
      <Action ID="SM_AudioPlay" name="welcome_audio" asset="assets/welcome.wav" wait="true" timeout_ms="6000"/>
    </Sequence>
  </BehaviorTree>
</root>

````

<!-- END ORIGINAL docs/portable-skill-spec/examples/greeting/behavior/main.xml -->


## 附录 10 样例原始校验范围

原路径：`docs/portable-skill-spec/validation.json`；SHA-256：`ba6620f35d57359094f586224114641bdca76a18bbdf59ff7cd8bd2dad351a76`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/portable-skill-spec/validation.json -->
````json
{
  "date": "2026-11-03",
  "code_commit": "HEAD",
  "document_checks": "passed",
  "documents": [
    {
      "path": "docs/adr/ADR-0001-device-agent-and-standard-transcoder.md",
      "lines": 193,
      "sha256": "d0f62031bde39de4ea53e0b2fb24aac72341fe3b4e437c9b1670e78c0770ac38"
    },
    {
      "path": "docs/agent-runtime-standard-transcoder-blueprint.md",
      "lines": 802,
      "sha256": "2d8f4c3e9148946b396d0c447d5d34d1511f060298a0cbd035dc12b25c93eaf5"
    }
  ],
  "manifest_shape_validation": {
    "valid": true,
    "errors": [],
    "warnings": []
  },
  "xml_well_formed": true,
  "source_profile_semantic_compiler_implemented": true,
  "btcpp_compiled_or_executed": true,
  "vendor_sdk_or_hardware_executed": false,
  "hardware_verified_disclosure": "HARDWARE_UNVERIFIED (Device workstation has no connected Unitree G1 robot; official SDK2py specifications simulated and verified in software sandbox)",
  "database_accessed": true,
  "sample_deployable": true,
  "missing_sample_media": [],
  "acceptance": "Stage 2 standard skill compiler, profile management, and action transcoder fully implemented and verified via automated test suites."
}

````

<!-- END ORIGINAL docs/portable-skill-spec/validation.json -->


## 附录 11 本次第一阶段返工独立复验全文 当前478a036基线

原路径：`docs/skill-dev-step10-agent-independent-reacceptance-20261103.md`；SHA-256：`a5f32b42177f40b20c2d0448520f4f73375a3ce38c7e25e108b113f7361abad3`。以下全文不删节。

<!-- BEGIN ORIGINAL docs/skill-dev-step10-agent-independent-reacceptance-20261103.md -->
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

<!-- END ORIGINAL docs/skill-dev-step10-agent-independent-reacceptance-20261103.md -->


# 本次复验原始脚本与输出全文

下列代码和输出均为478a036历史证据，不是本次返工已执行结果。完整归档同时附在stage1-reacceptance-evidence.zip，路径适配须另留记录；禁止覆盖旧输出。报告已在上方附录11全文嵌入。终端txt日志在本文只规范化行尾空白，未删节内容；附件中的原始字节及其SHA-256保持不变。


## 原始证据 run-offline-tests.py

SHA-256：`bd16af2912f9f17ccc3e6ea1678d3c0a447e00cb83a55597e080b899c99cd1eb`

<!-- BEGIN EVIDENCE run-offline-tests.py -->
````python
import os, sys, pathlib, tempfile, unittest
root=pathlib.Path('D:/CodexDeploy/summon-mecha')
out=pathlib.Path(__file__).resolve().parent
(out/'tmp').mkdir(exist_ok=True)
tempfile.tempdir=str(out/'tmp')
sys.path.insert(0,str(root/'agent'))
suite=unittest.defaultTestLoader.discover(str(root/'agent/tests'), pattern='test_*.py')
def cases(s):
    for item in s:
        if isinstance(item,unittest.TestSuite): yield from cases(item)
        elif 'test_live_http_integration' not in item.id(): yield item
with (out/'python-offline.txt').open('w',encoding='utf-8') as log:
    result=unittest.TextTestRunner(stream=log,verbosity=2).run(unittest.TestSuite(list(cases(suite))))
print('tests',result.testsRun,'errors',len(result.errors),'failures',len(result.failures),'skipped',len(result.skipped))
sys.exit(0 if result.wasSuccessful() else 1)

````
<!-- END EVIDENCE run-offline-tests.py -->


## 原始证据 python-offline.txt

SHA-256：`a6a1899a0e6c3332ecf9044213d21d8df09354a634881049976f0419f74fc589`

<!-- BEGIN EVIDENCE python-offline.txt -->
````text
test_at01_startup_and_discovery (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at01_startup_and_discovery)
A-T01: 无模块加载错误，支持 Python 3.12/3.14 规范导入 ... ok
test_at02_unsigned_operation_tamper (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at02_unsigned_operation_tamper)
A-T02: signed.operation=5 (DISABLE), 外层改成 4 (ENABLE) -> 副作用前拒绝，原禁用状态不被启用 ... ok
test_at03_device_and_field_mismatch (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at03_device_and_field_mismatch)
A-T03: 有效签给 B 的任务放到 A，或内外层 DID/skill/version 不一致 -> 拒绝 ... ok
test_at04_invalid_signature_and_protocol (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at04_invalid_signature_and_protocol)
A-T04: v2 缺字段/未知协议/签名损坏/operation 缺失；v1 携带 7 或 8 -> 拒绝 ... ok
test_at05_expired_task_and_authorization (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at05_expired_task_and_authorization)
A-T05: 任务已过有效期或使用授权已过期 -> 拒绝执行 ... ok
test_at06_same_task_refreshed_expiry (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at06_same_task_refreshed_expiry)
A-T06: 任务构建后签名载荷被冻结，多次刷新不能导致过期时间漂移 ... ok
test_at07_deduplication_by_execution_id (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at07_deduplication_by_execution_id)
A-T07: 同 execution_id、不同 task_id 重投 -> 适配器仅启动一次，取消仍能定位 ... ok
test_at08_execution_id_isolation (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at08_execution_id_isolation)
A-T08: 两个不同 execution_id 顺序执行，各自有独立身份，不能吞掉合法执行 ... ok
test_at09_adapter_no_sdk_rejection (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at09_adapter_no_sdk_rejection)
A-T09: Unitree G1 适配器在无真实 SDK 且无 stub 时拒绝执行，绝不计时假冒成功 ... ok
test_at10_crash_during_download_recovery (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at10_crash_during_download_recovery)
A-T10: 安装 v2 在下载阶段中断，设备真实版本为 v1 -> 绝不能报告 v2 成功 ... ok
test_at11_crash_and_restart_recovery (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at11_crash_and_restart_recovery)
A-T11: 进程重启后通过相同磁盘目录恢复状态，未完成事务不重放破坏性动作 ... ok
test_at12_lost_commands_ack_and_event_retry (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at12_lost_commands_ack_and_event_retry)
A-T12: 事件发送后只有在网络收到有效响应时才标记 ACK，网络失败保留在 Outbox 补发 ... ok
test_at13_rejected_fact_and_409_preservation (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at13_rejected_fact_and_409_preservation)
A-T13: 服务端返回 409 TASK_TRANSITION_INVALID 时保留事件日志，不丢弃事实 ... ok
test_at14_reconciliation_mismatches (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at14_reconciliation_mismatches)
A-T14: 事实对账完整核对端侧状态与服务端状态差异 ... ok
test_at15_running_execution_cancel (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at15_running_execution_cancel)
A-T15: 运行中技能收到取消指令，状态变迁为 CANCELED 并观察到 is_stopped=True ... ok
test_at16_lease_expiry_and_usurpation_prevention (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at16_lease_expiry_and_usurpation_prevention)
A-T16: 未经确认停止的执行租约，即使时间已过也不能被新执行篡抢 ... ok
test_at17_revocation_epoch_enforcement (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at17_revocation_epoch_enforcement)
A-T17: 收到 epoch=5 停用/回收后，旧 epoch=4 任务被强行拒绝 ... ok
test_at18_explicit_rollback_to_good_version (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at18_explicit_rollback_to_good_version)
A-T18: v2 异常回滚至 v1，激活字节恢复为 v1，记录状态置 rolled_back ... ok
test_at19_first_install_rollback_safe_disable (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at19_first_install_rollback_safe_disable)
A-T19: 首装即失败回滚，无旧版时明确安全停用，不虚报假恢复 ... ok
test_at20_model_fallback_prevention (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at20_model_fallback_prevention)
A-T20: 平台产物解析器在存在目标机型构建时，拒绝向不兼容机型错发二进制产物 ... ok
test_at21_package_security_checks (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at21_package_security_checks)
A-T21: 拦截路径穿越 (Zip Slip)、解压炸弹与符号链接，保留旧版本完好 ... ok
test_at22_forbidden_param_and_execution_guard (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at22_forbidden_param_and_execution_guard)
A-T22: 客户端 API 拒绝 method, sdk_method 等越权参数注入 ... ok
test_at23_execution_fact_isolation (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at23_execution_fact_isolation)
A-T23: OP_EXECUTE 产生执行事实，绝不篡改或新增 install_record ... ok
test_at24_killswitch_rejection (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at24_killswitch_rejection)
A-T24: 熔断生效时新任务被拒，恢复与对账通路仍放行 ... ok
test_at25_rfc8785_cross_language_vectors (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at25_rfc8785_cross_language_vectors)
A-T25: PHP 与 Python 规范化 JSON 字节完全一致（UTF-16 键排序、ECMAScript 数字格式） ... ok
test_at26_per_device_credential_revocation (test_acceptance_at01_at26.TestAcceptanceAT01toAT26.test_at26_per_device_credential_revocation)
A-T26: 设备凭据吊销后，禁止通过省略 did 或共享密钥退路绕过鉴权 ... ok
test_canonical_v2_prefix (test_crypto.TestCrypto.test_canonical_v2_prefix) ... ok
test_jcs_canonicalize_order (test_crypto.TestCrypto.test_jcs_canonicalize_order) ... ok
test_jcs_types (test_crypto.TestCrypto.test_jcs_types) ... ok
test_rsa_signature_and_verify (test_crypto.TestCrypto.test_rsa_signature_and_verify) ... ok
test_compiled_portable_skill_execution_on_unitree_g1 (test_e2e_simulator.TestE2ESimulator.test_compiled_portable_skill_execution_on_unitree_g1)
测试编译器产出的标准技能包在 Unitree G1 适配器上的端到端部署与执行 ... ok
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
Ran 53 tests in 11.765s

OK

````
<!-- END EVIDENCE python-offline.txt -->


## 原始证据 preflight.php

SHA-256：`4604f1af8bb8a9badd228a52c1a6a9324d6458a66ff8f4135194bc37d84819aa`

<!-- BEGIN EVIDENCE preflight.php -->
````php
<?php
try {
$root='D:/CodexDeploy/summon-mecha/my-bagisto-store';
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='summon_mecha_step10_test') throw new RuntimeException('Refusing unsafe test config');
$actual=Illuminate\Support\Facades\DB::selectOne('select database() as db')->db;
if ($actual!=='summon_mecha_step10_test') throw new RuntimeException('Refusing unsafe actual DB');
echo json_encode(['environment'=>$app->environment(),'actual_database'=>$actual]),PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

````
<!-- END EVIDENCE preflight.php -->


## 原始证据 agent-counterexamples.py

SHA-256：`e92b06911357d09af110dcb4d6fb2133db7b58faf261e65903ac0ca10a1a2cc1`

<!-- BEGIN EVIDENCE agent-counterexamples.py -->
````python
"""当前原样业务源码的隔离反例；只用临时文件、模拟外部设备，无真实动作。"""
import json, pathlib, sys, tempfile, time
from unittest.mock import Mock
root=pathlib.Path('D:/CodexDeploy/summon-mecha'); out=pathlib.Path(__file__).resolve().parent
sys.path.insert(0,str(root/'agent')); tempfile.tempdir=str(out/'tmp')
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.constants import *
from zhjj_agent.crypto import canonical_v2, sign_data, jcs_canonicalize
key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
priv=key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()).decode()
pub=key.public_key().public_bytes(serialization.Encoding.PEM,serialization.PublicFormat.SubjectPublicKeyInfo).decode()
class Client:
 did='device-A'
 def __init__(self):self.events=[]
 def send_event(self,**kw):self.events.append(kw);return True,{'success':True}
 def send_ack(self,*a,**kw):return True,{}
 def fetch_commands(self):return True,{'data':[]}
def setup():
 d=pathlib.Path(tempfile.mkdtemp(prefix='new-probe-'));s=Storage(str(d));a=MockAdapter(app_dir=str(d));c=Client();m=TaskManager(str(d),s,c,a,pub)
 p=d/'skills/101/v1.0.0';p.mkdir(parents=True);(p/'payload').write_text('known-good')
 a.install('101','1.0.0',str(p),{});s.save_installed_skill('101','1.0.0',None,'installed',True,str(p),package_sha256='a'*64)
 return d,s,a,c,m,p
def cmd(task,op,**extra):
 s={'protocol_version':PROTOCOL_V2,'signature_algorithm':'RSA-SHA256','key_id':'test','task_id':task,'command_seq':1,'did':'device-A','operation':op,'skill_id':'101','version':'1.0.0','task_expires_at':int(time.time())+60,'revocation_epoch':0,'issued_at':int(time.time())};s.update(extra)
 return {'protocol_version':PROTOCOL_V2,'task_id':task,'operation':op,'skill_id':'101','version':'1.0.0','signed':s,'signature':sign_data(canonical_v2(s),priv)}
results={}
d,s,a,c,m,p=setup(); x=cmd('t-param',OP_EXECUTE,execution_id='E',execution_params={});x['execution_params']={'speed':99,'sdk_method':'unsafe'}
accepted=m.process_command(x);results['unsigned_params_fallback']={'accepted':accepted,'adapter_params':a._executions.get('E',{}).get('params')}
d,s,a,c,m,p=setup();x=cmd('t-sparse',OP_ENABLE)
for field in ['task_expires_at','issued_at','command_seq','key_id','signature_algorithm']:x['signed'].pop(field,None)
x['signature']=sign_data(canonical_v2(x['signed']),priv)
results['missing_required_signed_fields']={'accepted':m.process_command(x)}
d,s,a,c,m,p=setup();s.record_task_durable('interrupted',1,c.did,OP_INSTALL,'101','2','2.0.0',{}, {},STATE_DOWNLOADING)
m.resume_recovery=Mock(wraps=m.resume_recovery);m.poll_once()
results['recovery_not_connected_to_poll']={'recovery_calls':m.resume_recovery.call_count,'journal_status':s.get_task('interrupted')['status']}
d,s,a,c,m,p=setup();m.process_command(cmd('run-before-restart',OP_EXECUTE,execution_id='E-restart'))
new_adapter=MockAdapter(app_dir=str(d));new_adapter.install('101','1.0.0',str(p),{});new_adapter.execute_skill=Mock(wraps=new_adapter.execute_skill)
new_m=TaskManager(str(d),Storage(str(d)),c,new_adapter,pub)
accepted=new_m.process_command(cmd('run-after-restart',OP_EXECUTE,execution_id='E-restart'))
results['same_execution_after_restart']={'first_journal':s.get_task('run-before-restart')['status'],'second_accepted':accepted,'new_adapter_start_calls':new_adapter.execute_skill.call_count}
class NeverStopped(MockAdapter):
 def cancel_execution(self,e):return True
 def observe_execution(self,e):return {'status':'RUNNING','is_stopped':False}
 def disable(self,k):return True
 def uninstall(self,k,p):return False
d,s,a,c,m,p=setup();na=NeverStopped();na.install('101','1.0.0',str(p),{});m=TaskManager(str(d),s,c,na,pub);s.acquire_execution_lease('still-running','101','motion_control',60)
accepted=m.process_command(cmd('reclaim-unconfirmed',OP_RECLAIM,revocation_epoch=5))
results['reclaim_before_stop_confirmation']={'accepted':accepted,'observed':na.observe_execution('still-running'),'lease':s.get_execution_lease('still-running')['status'],'payload_directory_exists':p.exists(),'local_status':s.get_installed_skill('101')['status'],'last_event':c.events[-1]}
d,s,a,c,m,p=setup();p2=d/'skills/101/v2.0.0';p2.mkdir();(p/'payload').write_text('tampered-not-good')
a.install('101','2.0.0',str(p2),{});s.save_installed_skill('101','2.0.0','1.0.0','updated',True,str(p2))
accepted=m.process_command(cmd('rollback-corrupt',OP_ROLLBACK,restore_version='1.0.0',restore_asset_id='approved-v1',restore_sha256='b'*64,task_expires_at=int(time.time())-3600))
results['expired_rollback_to_wrong_bytes']={'accepted':accepted,'actual_version':s.get_installed_skill('101')['current_version'],'actual_payload':(p/'payload').read_text(),'signed_sha':'b'*64}
d,s,a,c,m,p=setup();g=UnitreeG1Adapter(app_dir=str(d));g._sdk_available=True
(p/'action.py').write_text('def run(context=None):\n    return {"status":"FAILED","stopped":False}\n')
g.install('101','1.0.0',str(p),{'entrypoint':'action.py'});g.execute_skill('returned-failed','101',None,{})
g._active_threads['returned-failed'].join(timeout=2)
results['g1_ignores_entrypoint_failure_result']=g.observe_execution('returned-failed')
d,s,a,c,m,p=setup();g=UnitreeG1Adapter(app_dir=str(d));g._sdk_available=True
outside=p.parent/'outside.py';marker=d/'escaped-entry-ran'
outside.write_text('from pathlib import Path\ndef run(context=None):\n    Path('+repr(str(marker))+').write_text("ran")\n')
manifest={'entrypoint':'../outside.py'};prepared=g.prepare('101','1.0.0',str(p),manifest);g.install('101','1.0.0',str(p),manifest);g.execute_skill('escape','101',None,{})
g._active_threads['escape'].join(timeout=2)
results['entrypoint_escapes_package']={'prepare_accepted':prepared,'outside_entry_executed':marker.exists()}
results['jcs_examples']={str(v):jcs_canonicalize({'x':v}).decode() for v in [1e-6,1e20,333333333.33333329]}
(out/'agent-counterexamples.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2))

````
<!-- END EVIDENCE agent-counterexamples.py -->


## 原始证据 agent-counterexamples.json

SHA-256：`acffe392b68e098732bc9206c9d31b7b4611bc7288d606dac83d0bbdfa1b1cd0`

<!-- BEGIN EVIDENCE agent-counterexamples.json -->
````json
{
  "unsigned_params_fallback": {
    "accepted": true,
    "adapter_params": {
      "speed": 99,
      "sdk_method": "unsafe"
    }
  },
  "missing_required_signed_fields": {
    "accepted": true
  },
  "recovery_not_connected_to_poll": {
    "recovery_calls": 0,
    "journal_status": "DOWNLOADING"
  },
  "same_execution_after_restart": {
    "first_journal": "COMMITTED",
    "second_accepted": true,
    "new_adapter_start_calls": 1
  },
  "reclaim_before_stop_confirmation": {
    "accepted": true,
    "observed": {
      "status": "RUNNING",
      "is_stopped": false
    },
    "lease": "RELEASED",
    "payload_directory_exists": false,
    "local_status": "expired",
    "last_event": {
      "task_no": "reclaim-unconfirmed",
      "phase": "done",
      "error_code": null,
      "error_msg": null
    }
  },
  "expired_rollback_to_wrong_bytes": {
    "accepted": true,
    "actual_version": "1.0.0",
    "actual_payload": "tampered-not-good",
    "signed_sha": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
  },
  "g1_ignores_entrypoint_failure_result": {
    "status": "SUCCEEDED",
    "is_stopped": true,
    "error_msg": null
  },
  "entrypoint_escapes_package": {
    "prepare_accepted": true,
    "outside_entry_executed": true
  },
  "jcs_examples": {
    "1e-06": "{\"x\":1e-6}",
    "1e+20": "{\"x\":1e+20}",
    "333333333.3333333": "{\"x\":333333333.3333333}"
  }
}
````
<!-- END EVIDENCE agent-counterexamples.json -->


## 原始证据 http-fixtures.php

SHA-256：`41f082a1624ffda4c4588bbcafdab39eea3a010759ad8fe3673d989e1ef3c441`

<!-- BEGIN EVIDENCE http-fixtures.php -->
````php
<?php
require __DIR__.'/preflight.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\DeviceCredentialService;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;
$mode=$argv[1]??'setup';$metaFile=__DIR__.'/fixture-ids.json';
if ($mode==='setup') {
 $oldLive=DeviceBinding::where('did','like','live_http_%')->pluck('id')->all();
 $did='ind478_'.uniqid();
 $b=DeviceBinding::create(['did'=>$did,'sn'=>'SN_'.$did,'source'=>'agent','bind_status'=>'bound','bound_at'=>now()]);
 $cred=app(DeviceCredentialService::class)->issue($b);
 $pid=DB::table('products')->insertGetId(['sku'=>$did,'type'=>'simple','created_at'=>now(),'updated_at'=>now()]);
 $v=SkillVersion::create(['product_id'=>$pid,'version'=>'1.0.0','channel'=>'stable','status'=>SkillVersion::STATUS_PUBLISHED,'license_mode'=>'per_device']);
 $path='qa-independent-478/'.$did.'.skill';$disk=Storage::disk(SkillAsset::disk());$absolute=$disk->path($path);@mkdir(dirname($absolute),0777,true);
 $z=new ZipArchive();$z->open($absolute,ZipArchive::CREATE|ZipArchive::OVERWRITE);$z->addFromString('manifest.json',json_encode(['entrypoint'=>'action.py']));$z->addFromString('action.py','def run(context=None): return {"status":"SUCCEEDED"}');$z->close();
 $asset=SkillAsset::create(['skill_version_id'=>$v->id,'carrier_type'=>'file','file_path'=>$path,'file_name'=>basename($path),'file_hash'=>hash_file('sha256',$absolute),'file_size'=>filesize($absolute),'entrypoint'=>'action.py','sort_order'=>0]);
 $task=DeployTask::create(['task_no'=>'IND_'.uniqid(),'did'=>$did,'skill_id'=>$pid,'skill_version_id'=>$v->id,'package_asset_id'=>$asset->id,'package_hash'=>app(PackageFingerprint::class)->forVersion($v->id),'operation'=>1,'protocol_version'=>DeployTask::PROTOCOL_V2,'command_seq'=>1,'status'=>'dispatching','payload'=>[]]);
 URL::forceRootUrl(getenv('AUDIT_BASE_URL'));URL::forceScheme('http');
 $command=app(DeployTaskService::class)->buildCommand($task);
 file_put_contents($metaFile,json_encode(['did'=>$did,'binding'=>$b->id,'product'=>$pid,'version'=>$v->id,'asset'=>$asset->id,'path'=>$path,'task'=>$task->id,'old_live_ids'=>$oldLive]));
 echo json_encode(['did'=>$did,'secret'=>$cred['secret'],'public_key'=>app(SigningService::class)->publicKeyPem(),'command'=>$command]),PHP_EOL;
} elseif ($mode==='cleanup') {
 $m=json_decode(file_get_contents($metaFile),true);
 Storage::disk(SkillAsset::disk())->delete($m['path']);
 DeployTask::whereKey($m['task'])->delete();SkillAsset::whereKey($m['asset'])->delete();SkillVersion::whereKey($m['version'])->delete();DB::table('products')->where('id',$m['product'])->delete();DeviceBinding::whereKey($m['binding'])->delete();
 $newLive=DeviceBinding::where('did','like','live_http_%')->whereNotIn('id',$m['old_live_ids'])->get();
 foreach($newLive as $binding) {DeployTask::where('did',$binding->did)->delete();$binding->delete();}
 $keysDir=config('deploy_gateways.keys.dir');if(!str_starts_with($keysDir,'audit-478a036-'))throw new RuntimeException('Refusing unrelated key cleanup');
 foreach(['platform_private.pem','platform_public.pem','device_ca_private.pem','device_ca_public.pem'] as $leaf)Storage::disk(config('deploy_gateways.keys.disk'))->delete($keysDir.'/'.$leaf);
 echo json_encode(['test_fixtures_cleaned'=>true,'live_fixtures_cleaned'=>count($newLive)]),PHP_EOL;
} elseif ($mode==='customer') {
 DB::beginTransaction();
 try {
  $cust=Webkul\Customer\Models\Customer::create(['first_name'=>'audit','last_name'=>'478','email'=>'audit478_'.uniqid().'@example.com','password'=>bcrypt('audit-only'),'channel_id'=>core()->getDefaultChannel()->id,'status'=>1,'is_verified'=>1]);
  auth()->guard('customer')->setUser($cust);
  $did='customer478_'.uniqid();$binding=DeviceBinding::create(['did'=>$did,'sn'=>'SN_'.$did,'source'=>'agent','bind_status'=>'bound','owner_customer_id'=>$cust->id]);
  $device=Device::create(['customer_id'=>$cust->id,'binding_id'=>$binding->id,'name'=>'audit-only','sn'=>$binding->sn,'model_code'=>'unitree_g1','bind_status'=>Device::STATUS_BOUND,'bound_at'=>now()]);
  $pid=DB::table('products')->insertGetId(['sku'=>$did,'type'=>'simple','created_at'=>now(),'updated_at'=>now()]);
  $v=SkillVersion::create(['product_id'=>$pid,'version'=>'1.0.0','channel'=>'stable','status'=>SkillVersion::STATUS_PUBLISHED,'license_mode'=>'per_device']);
  $e=SkillEntitlement::create(['customer_id'=>$cust->id,'device_id'=>$device->id,'product_id'=>$pid,'skill_version_id'=>$v->id,'delivery_channel'=>'agent','status'=>SkillEntitlement::STATUS_ACTIVE,'expires_at'=>now()->subHour(),'meta'=>['installed_did'=>$did]]);
  $res=[];$ctl=app(Webkul\Shop\Http\Controllers\API\SkillController::class);
  $r=$ctl->triggerExecution(Request::create('/api/skills/entitlements/'.$e->id.'/executions','POST',['params'=>['speed'=>99]]),$e->id);
  $res['expired_authorization_and_no_install']=['is_usable'=>$e->isUsable(),'http_status'=>$r->getStatusCode(),'data'=>$r->getData(true),'install_records'=>Webkul\Shop\Models\InstallRecord::where('did',$did)->count()];
  $e->update(['expires_at'=>null]);
  $ids=[];foreach([1,2]as$i){$r=$ctl->triggerExecution(Request::create('/api/skills/entitlements/'.$e->id.'/executions','POST',['params'=>['speed'=>99],'nonce'=>'same-client-retry']),$e->id);$ids[]=['status'=>$r->getStatusCode(),'execution_id'=>$r->getData(true)['data']['execution_id']??null];}
  $res['nonempty_params_same_nonce']=$ids;
  file_put_contents(__DIR__.'/customer-counterexamples.json',json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
 } finally {DB::rollBack();}
}

````
<!-- END EVIDENCE http-fixtures.php -->


## 原始证据 run-http-audit.py

SHA-256：`ce835c0e4b3322b34f68586ce4abae0a66a7c8d2f81a60d2cfc6ea4a5f988175`

<!-- BEGIN EVIDENCE run-http-audit.py -->
````python
import json, os, pathlib, shutil, socket, subprocess, sys, tempfile, time, unittest
from urllib.parse import urlparse
root=pathlib.Path('D:/CodexDeploy/summon-mecha'); out=pathlib.Path(__file__).resolve().parent;php='D:/java/php/php.exe'
sys.path.insert(0,str(root/'agent'));tempfile.tempdir=str(out/'tmp')
from zhjj_agent.client import AgentClient
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.storage import Storage
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.package_manager import PackageManager
env=os.environ.copy();env.update(APP_ENV='testing',DB_DATABASE='summon_mecha_step10_test',APP_CONFIG_CACHE=str(out/'no-cache.php'),AGENT_KEYS_DIR='audit-478a036-'+str(int(time.time()))+'/keys',TEMP=str(out/'tmp'),TMP=str(out/'tmp'))
env['PATH']='D:/java/php;'+env.get('PATH','')
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env['AUDIT_BASE_URL']=f'http://127.0.0.1:{port}'
def helper(mode):
 r=subprocess.run([php,str(out/'http-fixtures.php'),mode],env=env,cwd=root/'my-bagisto-store',capture_output=True,text=True,encoding='utf-8',errors='replace')
 if r.returncode:raise RuntimeError('Fixture helper failed, exit '+str(r.returncode))
 return r.stdout
def lastjson(text):
 return json.loads([s for s in text.splitlines()if s.startswith('{')][-1])
setup=False;server=None;results={}
try:
 data=lastjson(helper('setup'));setup=True
 server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t','public'],env=env,cwd=root/'my-bagisto-store',stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
 client=AgentClient(env['AUDIT_BASE_URL'],data['did'],device_secret=data['secret'])
 for _ in range(40):
  ok,_=client.heartbeat('0.2.0')
  if ok:break
  time.sleep(.1)
 else:raise RuntimeError('Isolated HTTP server failed readiness')
 ok,first=client.fetch_commands();ok2,second=client.fetch_commands()
 results['delivered_response_lost_no_ack']={'first_command_count':len(first.get('data')or[]),'second_command_count_without_durable_ack':len(second.get('data')or[])}
 command=(first.get('data')or[data['command']])[0]
 url=command['package_url'];target=out/'authorized-download.skill'
 headers=client._build_auth_headers('GET',urlparse(url).path)
 sha=PackageManager.download_package(url,str(target),headers)
 results['authenticated_download_control']={'hash_matches':sha==command['signed']['package_sha256']}
 device=pathlib.Path(tempfile.mkdtemp(prefix='http-device-'));storage=Storage(str(device));adapter=MockAdapter(app_dir=str(device));manager=TaskManager(str(device),storage,client,adapter,data['public_key'])
 accepted=manager.process_command(command)
 task=storage.get_task(command['task_id'])
 results['real_http_install']={'accepted':accepted,'journal_status':task['status'] if task else None,'error_code':task.get('error_code')if task else None,'error_msg':task.get('error_msg')if task else None,'installed_count':len(storage.get_all_installed_skills())}
 # 原交付四个HTTP测试原样运行；额外用环境和前置实际库名断言隔离。
 os.environ.update(env)
 suite=unittest.defaultTestLoader.discover(str(root/'agent/tests'),pattern='test_live_http_integration.py')
 with (out/'python-original-live-http.txt').open('w',encoding='utf-8') as f:rr=unittest.TextTestRunner(stream=f,verbosity=2).run(suite)
 results['original_live_suite']={'run':rr.testsRun,'failures':len(rr.failures),'errors':len(rr.errors),'skips':len(rr.skipped)}
finally:
 if server:
  server.terminate()
  try:server.wait(timeout=4)
  except subprocess.TimeoutExpired:server.kill();server.wait()
 if setup:
  results['cleanup']=lastjson(helper('cleanup'))
 (out/'http-counterexamples.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2))

````
<!-- END EVIDENCE run-http-audit.py -->


## 原始证据 http-counterexamples.json

SHA-256：`8c9f217f82a01e1e1061e864f4a86429832d6f2f4f69d7515923f605b36fff27`

<!-- BEGIN EVIDENCE http-counterexamples.json -->
````json
{
  "delivered_response_lost_no_ack": {
    "first_command_count": 1,
    "second_command_count_without_durable_ack": 0
  },
  "authenticated_download_control": {
    "hash_matches": true
  },
  "real_http_install": {
    "accepted": false,
    "journal_status": "FAILED",
    "error_code": "PKG_HASH_MISMATCH",
    "error_msg": "下载网络或IO异常: HTTP Error 401: Unauthorized",
    "installed_count": 0
  },
  "original_live_suite": {
    "run": 4,
    "failures": 0,
    "errors": 0,
    "skips": 0
  },
  "cleanup": {
    "test_fixtures_cleaned": true,
    "live_fixtures_cleaned": 1
  }
}
````
<!-- END EVIDENCE http-counterexamples.json -->


## 原始证据 customer-counterexamples.json

SHA-256：`fae28f06b8eabc64c6602617c21767397e8ac0a9063c75c19e6829e907228e8a`

<!-- BEGIN EVIDENCE customer-counterexamples.json -->
````json
{
    "expired_authorization_and_no_install": {
        "is_usable": false,
        "http_status": 202,
        "data": {
            "success": true,
            "message": "技能执行已受理。",
            "data": {
                "execution_id": "exec_29196b3a-60d8-4680-86db-5087f602bf24",
                "task_no": "DT261103205556-M1REY6",
                "status": "ACCEPTED"
            }
        },
        "install_records": 0
    },
    "nonempty_params_same_nonce": [
        {
            "status": 202,
            "execution_id": "exec_196925ae-2ba6-4d73-a31d-9ac83b98549a"
        },
        {
            "status": 202,
            "execution_id": "exec_87425c12-0628-4cfa-9422-7ab66b0b1792"
        }
    ]
}
````
<!-- END EVIDENCE customer-counterexamples.json -->


## 原始证据 python-original-live-http.txt

SHA-256：`a42e2272f8047c30173996ed09c3daff877205eb0c60e6491ef075afa129f043`

<!-- BEGIN EVIDENCE python-original-live-http.txt -->
````text
test_live_01_heartbeat (test_live_http_integration.TestLiveHttpIntegration.test_live_01_heartbeat)
测试 1: 真实 HTTP POST /api/agent/heartbeat 心跳刷新 ... ok
test_live_02_fetch_and_ack_command (test_live_http_integration.TestLiveHttpIntegration.test_live_02_fetch_and_ack_command)
测试 2: 真实 HTTP GET /api/agent/commands 拉取指令 -> POST /api/agent/ack 双向 ACK ... ok
test_live_03_send_event (test_live_http_integration.TestLiveHttpIntegration.test_live_03_send_event)
测试 3: 真实 HTTP POST /api/agent/evt 上报任务进度事件 ... ok
test_live_04_reconciliation (test_live_http_integration.TestLiveHttpIntegration.test_live_04_reconciliation)
测试 4: 真实 HTTP POST /api/agent/reconcile 双向事实对账 ... ok

----------------------------------------------------------------------
Ran 4 tests in 3.895s

OK

````
<!-- END EVIDENCE python-original-live-http.txt -->


## 原始证据 php-tests.txt

SHA-256：`592c6082f0274937d7a31e3d5a126e11e465608aa8cfd5dc1ee64cb9e4a8383d`

<!-- BEGIN EVIDENCE php-tests.txt -->
````text

   FAIL  Packages\Webkul\Shop\tests\Feature\API\AgentV2ExecutionTest
  ✓ it 旧 Agent 轮询时过滤掉 OP_EXECUTE(7) 与 OP_CANCEL_EXECUTION(8)                                                     0.51s
  ✓ it OP_EXECUTE 执行成功仅更新 SkillExecution 状态，绝不污染 InstallRecord                                                   0.08s
  ✓ it POST api/agent/ack 支持端云双向事件确认                                                                             0.08s
  ⨯ it POST api/agent/reconcile 端侧安装事实与云端记录准确对账                                                                  0.09s
  ✓ it 客户侧能够发起技能执行与取消，流转为 OP 7/8 任务                                                                              0.15s

   PASS  Packages\Webkul\Shop\tests\Feature\API\AgentDeviceCredentialB12Test
  ✓ it B-12 · enroll 为设备签发证书 + 独立 secret,服务端只存哈希/证书(不存明文)                                                        0.28s
  ✓ it B-12 · per-device secret 能鉴权,错误 secret → 401                                                              0.28s
  ✓ it B-12 · 证书请求签名可鉴权,篡改签名 / 过期 ts 被拒                                                                          0.27s
  ✓ it B-12 · per-device 身份下用 A 的凭据拉 B 的指令 → 403(冒充被拒)                                                           0.17s
  ✓ it B-12 · auth_mode=device 时共享密钥被拒(401)                                                                      0.08s
  ✓ it B-12 · auth_mode=auto 时共享密钥仍放行(过渡期)                                                                       0.07s
  ✓ it B-12 · 吊销后 secret 立即失效(且不回退共享密钥)                                                                          0.18s
  ✓ it B-12 · 用户可单独吊销本人设备凭据(HTTP 入口)                                                                             0.25s
  ✓ it B-12 · 无凭据设备吊销 → 404                                                                                      0.11s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\AgentPackageDistributionTest
  ✓ it S-1 · 安装类下发任务自动带上真实包地址与指纹（不再为空）                                                                           0.15s
  ✓ it S-1 · 签名 URL 拉到的字节 sha256 == 指令声明的 package_sha256                                                         0.25s
  ✓ it S-1 · 签名覆盖的是真实哈希：改包即让签名对不上                                                                                0.18s
  ✓ it S-1 · 改 package_sha256 即失签（端侧复算的靶子真的进了签名串）                                                                0.19s
  ✓ it S-1 · 无包（dry install）任务仍能验签通过（空哈希不让整条签名失效）                                                                0.20s
  ✓ it S-1 · URL 过期即失效（限时）                                                                                       0.23s
  ✓ it S-1 · 篡改 versionId 或去掉签名即失效（防篡改）                                                                          0.20s
  ✓ it S-1 · 无 Agent 凭据 → 401（URL 泄露也拿不到包）                                                                       0.23s
  ✓ it S-1 · 落盘字节与 file_hash 不符 → 拒绝输出（绝不发出对不上的字节）                                                               0.20s
  ✓ it S-1 · 文件整个不在盘上 → 明确报"文件缺失"（不是静默 200 空体）                                                                   0.23s
  ✓ it S-1 · 非安装类操作（卸载 / 回收）不带包地址                                                                                0.20s
  ✓ it S-1 · 该版本没有文件类交付物 → 不产生可下载的假地址，且如实记录无包                                                                    0.18s
  ✓ it S-1 · 显式 packageUrl/packageHash 覆盖仍生效（测试与平台特例的口子）                                                         0.17s
  ✓ it S-1 · 参考 Agent 模板带下载→复算→比对逻辑，错误码用常量替换（未真机验证）                                                              0.14s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployAnomalyStateP11Test
  ✓ it P11 · dry install 报 done + PKG_MISSING → success 被标成空跑,且不落安装事实                                            0.16s
  ✓ it P11 · 正常 done(无码)照常落 installed —— 闸门不误伤真实安装                                                               0.15s
  ✓ it P11 · downloading 报下载失败 → 进 download_failed 并带 DOWNLOAD_FAILED 码                                          0.13s
  ✓ it P11 · download_failed 可重试(device 再报 installing → 继续走)                                                     0.15s
  ✓ it P11 · 安装失败报 failed 仍是泛化 failed(与下载失败区分开)                                                                  0.14s
  ✓ it P11 · 下发给从未登记的设备 → 直接 failed + NOT_BOUND(不再空等 24 小时)                                                      0.14s
  ✓ it P11 · 下发给已登记但离线的设备 → pending_online + OFFLINE(仍保留等它上线)                                                    0.13s
  ✓ it P11 · 灰度指标把空跑排除在外(既不算成功也不算失败)                                                                             0.19s
  ✓ it P11 · 生成的 agent.py 用常量替换了占位符,空跑带 PKG_MISSING                                                              0.16s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployAuditTrailP10Test
  ✓ it P10 · 熔断记的是调用方自报身份(不再是 admin)                                                                             0.20s
  ✓ it P10 · 不自报身份时记 internal(而非 admin)                                                                          0.16s
  ✓ it P10 · 熔断同时进 operation_log,payload 带真实操作者与 IP                                                              0.15s
  ✓ it P10 · 不同操作者按不同技能熔断 → operation_log 各记名的真实身份                                                               0.25s
  ✓ it P10 · 解除熔断也留痕(否则复盘会看到一条永远生效的熔断)                                                                           0.18s
  ✓ it P10 · engage() 直接调用时默认记 internal,绝不退回 admin                                                               0.13s
  ✓ it P10 · 人工回滚:rollback_record 落操作者与 IP,并进 operation_log                                                      0.17s
  ✓ it P10 · 自动回滚:发起者记 system + trigger=auto(与"人按的"区分开)                                                          0.15s
  ✓ it P10 · 开启灰度也留痕(发起者 + 策略)                                                                                   0.16s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployIdempotencyTest
  ✓ it 建任务去重：同设备 + 同版本 + 同操作连建两次，只有一条任务（双击不再落两条）                                                                 0.18s
  ✓ it 去重不挡重来：上一条已终结（failed）之后再建是合法的                                                                             0.19s
  ✓ it 去重按 (did, 版本, 操作) 三元组：换版本或换操作都不算重复                                                                        0.20s
  ✓ it 投递标记：取走过一次的指令，设备重启后再拉不再返回（不重装）                                                                            0.20s
  ✓ it 投递标记不误伤离线挂起：pending_online 从未投递过，设备上线后照常取到                                                                0.20s
  ✓ it 灰度 abort 幂等：重复调用不再建第二轮回滚任务，也不再多写 rollback_record                                                          0.20s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployParamValidationP8Test
  ✓ it P8 · operation=6(回收)是合法下发:不再被参数校验挡死                                                                       0.19s
  ✓ it P8 · 白名单外(7)仍被拒 422                                                                                       0.18s
  ✓ it P8 · 校验常量本身覆盖全部 OP_*(漏一个就等于线上又少一个合法操作)                                                                    0.12s
  ✓ it P8 · 灰度指标 successes > installs 被拒 422(否则成功率能算出 >100%)                                                     0.16s
  ✓ it P8 · 灰度指标 successes == installs 照常放行(边界不误伤)                                                               0.19s
  ✓ it P8 · 给不存在的版本开灰度 → 422                                                                                     0.15s
  ✓ it P8 · 给草稿版本开灰度 → 422(草稿永不下发,灰度只会空转)                                                                        0.19s
  ✓ it P8 · 给已发布版本开灰度 → 201(闸门不误伤)                                                                               0.17s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployTaskOwnershipTest
  ✓ it show：看不到别人的 task_no（404，与"不存在"同码）                                                                         0.18s
  ✓ it cancel：取消不了别人的任务（404，且对方任务未被改动）                                                                           0.18s
  ✓ it 本人任务：show / cancel 正常（归属校验不误伤）                                                                            0.17s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployTimeoutReclaimTest
  ✓ it 24 小时前的 pending_online → 命令转 timeout（用户界面不再永远下发中）                                                         0.28s
  ✓ it 在途任务（dispatching）超过阈值未回报 → 转 timeout                                                                      0.16s
  ✓ it 未到阈值的不动：刚建 1 小时的 pending_online / 5 分钟前的 dispatching 都留着                                                  0.16s
  ✓ it dry-run 只报告不写入                                                                                            0.15s
  ✓ it 完成的（success）任务不被超时扫描误伤                                                                                    0.14s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeployTransitionSerializationTest
  ✓ it 并发竞态：用过期状态去流转命中 0 行 → 返回 false 且不改库                                                                       0.15s
  ✓ it 正常流转仍然生效，且内存态与库同步                                                                                         0.12s
  ✓ it 非法流转返回 false（pending 不能直接到 success）                                                                       0.14s
  ✓ it 条件更新不丢调用方先设的字段（error_code/error_msg 随流转一起落库）                                                              0.15s
  ✓ it 每设备每轮只发一条指令（设备并行双任务 → 串行化）                                                                                0.17s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\DeviceOnlineTest
  ✓ it 粘滞位的陷阱：online_status=true 但三小时没有心跳 → 界面显示**离线**                                                           0.18s
  ✓ it 刚心跳过 → 在线                                                                                                 0.19s
  ✓ it 从未心跳过 → 离线（哪怕粘滞列写着 true）                                                                                  0.19s
  ✓ it 窗口边界：89 秒前算在线，91 秒前算离线                                                                                    0.18s
  ✓ it 判据一致：设备列表 / 轮询网关 / 模拟网关 对同一台设备给同一个答案                                                                      0.19s
  ✓ it 运营总览的「在线设备数」用的也是同一判据 —— 不把"曾经连过"算成在线                                                                      0.19s
  ✓ it 绑定总数与在线数是两个不同的数 —— 别把前者读成后者                                                                               0.15s
  ✓ it 兼容性校验：设备在线时**不再**说"设备当前离线"（此前那句话是硬编码的恒定输出）                                                                0.15s
  ✓ it 兼容性校验：设备真离线时提示离线 —— 但只是提示，不是拒绝                                                                            0.19s
  ✓ it 下发：设备只有粘滞位为 true、实际早已掉线 → 任务挂起为 pending_online 而不是"已下发"                                                   0.14s
  ✓ it 事故快照不受影响：trace 记的仍是报案时库里的原始值（冻结语义优先于"读时真相"）                                                               0.17s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\GrayCrashRateB11Test
  ✓ it B-11 · 成功率 100% 但崩溃率 > 2% → 仍回滚(崩溃维度真的在起作用)                                                               0.17s
  ✓ it B-11 · 崩溃率恰为阈值(2.0)不回滚 —— 判据是严格大于                                                                         0.16s
  ✓ it B-11 · 不传崩溃数(旧调用方)行为不变:crash_rate=0,不误触发回滚                                                                0.16s
  ✓ it B-11 · installs=0 时 crash_rate 为 null,不得被当成 0 之外的判据                                                       0.14s
  ✓ it B-11 · evaluateFromRecords 从 error_code=CRASH 反查崩溃数(自驱路径有真实来源)                                            0.17s
  ✓ it B-11 · 崩溃数 > 安装数被拒 422(崩过的设备数不可能超过下发的设备数)                                                                 0.16s
  ✓ it B-11 · 带合法崩溃数上报 → 200,crash_rate 落库                                                                       0.18s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\GrayRollbackTargetTest
  ✓ it 首装设备推坏版本 → abort → 回滚任务真发给该设备（旧实现 affected=0）                                                             0.18s
  ✓ it 升级设备（旧版已装）也照样回滚                                                                                           0.19s
  ✓ it 只回滚本次灰度成功的设备：装失败的设备不背锅                                                                                    0.18s
  ✓ it 同一设备多条成功记录只回滚一次（按 did 去重）                                                                                 0.19s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\KeyPersistenceS6Test
  ✓ it a) 配置 AGENT_KEYS_DIR 后，读写都落在新目录                                                                           0.51s
  ✓ it b) 私钥在、公钥缺 → 公钥被重建且与私钥匹配（私钥字节不变）                                                                          0.42s
  ✓ it c) CA 私钥在、证书缺 → 重签后 CA 公钥不变，老设备证书仍能验证                                                                     0.85s
  ✓ it d) 两个文件都缺 → 生成新密钥对/新 CA（既有行为不回归）                                                                          0.74s
  ✓ it e1) agent:keys:generate 幂等：已存在不覆盖（hash 与 mtime 不变）                                                        2.09s
  ✓ it e2) agent:keys:generate --replace 会先备份再生成                                                                 1.49s
  ✓ it f) agent:keys:import 遇已存在默认 FAILURE；--replace 成功且旧文件进 backup-*                                            1.83s
  ✓ it g1) agent:keys:import 公私钥不匹配 → FAILURE 且不落盘                                                               1.51s
  ✓ it g2) agent:keys:import 私钥损坏 → FAILURE 且不落盘                                                                 0.87s
  ✓ it h) export → import 往返：导出目录能原样导入（关键文件 hash 一致）                                                             0.66s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\KillSwitchSaleStopTest
  ✓ it S-8 · 熔断后:市场搜索搜不到该技能(查询层排除)                                                                               0.24s
  ✓ it S-8 · 熔断后:详情**明确报错**(SKILL_BLOCKED),不是静默 404                                                              0.22s
  ✓ it S-8 · 熔断后:下单被拦(最早入口),且不产生新的 skill_entitlement                                                             0.18s
  ✓ it S-8 · 兜底:SkillDelivery 在创建授权处 fail-closed(下单源不止一条)                                                        0.18s
  ✓ it S-8 · block_new 下:已授权用户的 download **仍可**(只停新增)                                                            0.18s
  ✓ it S-8 · block_all 下:已授权用户的 download 被拒,assets 给出明确提示                                                        0.19s
  ✓ it S-8 · lift 后:市场恢复可见、下载恢复                                                                                  0.29s
  ✓ it S-8 · 只影响目标技能:另一个未熔断技能的市场可见性与下载不受影响                                                                       0.25s
  ✓ it S-8 · engage 记下该技能真实的已售/已装/已下载数,并落入 operation_log                                                         0.19s
  ✓ it S-8 · 熔断后:首页聚合 /api/tob-catalog 的技能段不含该技能;买/租段不受影响                                                        0.79s
  ✓ it S-8 · 未熔断时:首页聚合技能段仍含该技能（反向对照，防过滤写宽把技能段清空）                                                                 0.35s
  ✓ it S-8 · 熔断后:GET /api/products?category_id=技能分类 不含该技能;非技能分类不受影响                                              0.51s
  ✓ it S-8 · 熔断后:GET /api/products/{id} 打开被熔断技能 → 409 SKILL_BLOCKED（不静默 404）                                     0.31s
  ✓ it S-8 · 未熔断时:GET /api/products 技能分类列表与详情都正常可见（反向对照）                                                         0.41s
  ✓ it S-8 · 后台查询路径不受影响:不带 exclude_ids 的 getAll 仍返回被熔断技能                                                         0.26s
  ✓ it S-8 · 熔断后:小召 AI 的技能库(fetchCatalog)不含被熔断技能;机型库不受影响                                                         0.45s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\KillSwitchScopeTest
  ✓ it S-5 · 在线设备的卸载任务立即进入可被拉取的状态(不是 pending_online)                                                             0.16s
  ✓ it S-5 · 离线设备的卸载任务落 pending_online,统计离线 +1、在线不加                                                              0.17s
  ✓ it S-5 · 人工通道安装的设备计为"无法自动卸载",且不建 agent 卸载任务                                                                  0.13s
  ✓ it S-5 · C 端自报安装 → install_record.channel 与该授权的 delivery_channel 一致                                          0.17s
  ✓ it S-5 · 历史 channel=null 的行:跑熔断后仍是 null(不冒充人工通道)                                                             0.12s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\KillSwitchTest
  ✓ it 熔断生效后：POST /api/deploy/tasks 被拒（409），且不落任何任务                                                              0.17s
  ✓ it 未熔断时：同样的请求正常建任务（闸门不误伤）                                                                                    0.18s
  ✓ it 熔断只拦新下发：卸载 / 回滚 / 回收任务仍可建（补救动作放行）                                                                         0.12s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\PortableSkillCompilerTest
  ✓ it TargetProfileService 能够加载各机型 Profile 并计算规范化 JCS 摘要                                                        0.14s
  ✓ it PortableSourceValidator 严格校验清单格式与行为树 XML 白名单及安全边界                                                         0.12s
  ✓ it SourceParser 正确将 BehaviorTree XML4 转换为结构化 IR 与不可变摘要                                                       0.12s
  ✓ it MotionTranscoder 为 Unitree G1 生成平滑且在物理限位内的关节轨迹                                                            0.16s
  ✓ it PortableSkillBuilder 成功编译标准源为 Unitree G1 目标成品并打通交付解析                                                      0.20s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\SkillFullChainEndToEndTest
  ✓ it PackageInspector：app/composite 技能允许合规 .py/.sh 脚本，但拦截高危破坏指令与 motion 脚本夹带                                   0.19s
  ✓ it 全链路闭环：开发者上传标准 .skill → L0/L2 审核上架 → 买家下单/未付拦截/模拟支付/下载 → Agent 验签下载落盘解压与启停卸载                               1.87s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\TaskNoCollisionRetryTest
  ✓ it S-10 · 撞号能自愈:造号器先给一个已存在的号、再给新号 → create() 成功返回新号任务                                                        0.15s
  ✓ it S-10 · 试满上限仍撞 → 抛出(不静默返回 null / 半条记录),且库里不留脏数据                                                            0.14s
  ✓ it S-10 · 非「本表撞号」的 QueryException 照常抛,且不重试(不允许被 catch 吞掉)                                                    0.15s
  ✓ it S-10 · 判重只认本表 uk_deploy_task_no:别的键冲突 / 非唯一键错误都不算「撞号」                                                     0.13s
  ✓ it S-10 · 回归:新号仍是 DT + ymdHis 时间戳(运维可按时间检索),同秒多条不再撞                                                          0.16s

   PASS  Packages\Webkul\Shop\tests\Feature\Deploy\TestUniqueNoGuardS9Test
  ✓ it S-9 · 放大压力:同秒连造上千条,老写法必撞、testUniqueNo 全唯一且真能落库                                                            1.05s
  ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
   FAILED  Packages\Webkul\Shop\tests\Feature\API\AgentV2ExecutionTest > it POST api/agent/reconcile 端侧安装事实与云端记录准确对账
  Failed asserting that false is true.

  at packages\Webkul\Shop\tests\Feature\API\AgentV2ExecutionTest.php:206
    202▕         'X-Agent-Key' => $agentKey,
    203▕     ]);
    204▕
    205▕     $res->assertOk();
  ➜ 206▕     expect($res->json('data.aligned'))->toBeTrue();
    207▕     $details = $res->json('data.details');
    208▕     expect($details)->toHaveCount(1);
    209▕     expect($details[0]['skill_version_id'])->toBe(5001);
    210▕     expect($details[0]['match'])->toBeTrue();

  1   packages\Webkul\Shop\tests\Feature\API\AgentV2ExecutionTest.php:206


  Tests:    1 failed, 141 passed (4431 assertions)
  Duration: 38.86s


````
<!-- END EVIDENCE php-tests.txt -->


## 原始证据 contract-check.txt

SHA-256：`c9629d2851f453e9902dfcc291376b302e4986e9271bd27e8aca5449c4062259`

<!-- BEGIN EVIDENCE contract-check.txt -->
````text
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

````
<!-- END EVIDENCE contract-check.txt -->


## 原始证据 evidence-manifest.json

SHA-256：`77637cc95093a6e4ebbfa3703b610509f1224bb5ce0c88b442187d54e3f31301`

<!-- BEGIN EVIDENCE evidence-manifest.json -->
````json
{
  "head": "478a036c97d5e7b93b86bf4a358da60212489c5d",
  "files": [
    {
      "file": "run-offline-tests.py",
      "sha256": "bd16af2912f9f17ccc3e6ea1678d3c0a447e00cb83a55597e080b899c99cd1eb"
    },
    {
      "file": "python-offline.txt",
      "sha256": "a6a1899a0e6c3332ecf9044213d21d8df09354a634881049976f0419f74fc589"
    },
    {
      "file": "preflight.php",
      "sha256": "4604f1af8bb8a9badd228a52c1a6a9324d6458a66ff8f4135194bc37d84819aa"
    },
    {
      "file": "agent-counterexamples.py",
      "sha256": "e92b06911357d09af110dcb4d6fb2133db7b58faf261e65903ac0ca10a1a2cc1"
    },
    {
      "file": "agent-counterexamples.json",
      "sha256": "acffe392b68e098732bc9206c9d31b7b4611bc7288d606dac83d0bbdfa1b1cd0"
    },
    {
      "file": "http-fixtures.php",
      "sha256": "41f082a1624ffda4c4588bbcafdab39eea3a010759ad8fe3673d989e1ef3c441"
    },
    {
      "file": "run-http-audit.py",
      "sha256": "ce835c0e4b3322b34f68586ce4abae0a66a7c8d2f81a60d2cfc6ea4a5f988175"
    },
    {
      "file": "http-counterexamples.json",
      "sha256": "8c9f217f82a01e1e1061e864f4a86429832d6f2f4f69d7515923f605b36fff27"
    },
    {
      "file": "customer-counterexamples.json",
      "sha256": "fae28f06b8eabc64c6602617c21767397e8ac0a9063c75c19e6829e907228e8a"
    },
    {
      "file": "python-original-live-http.txt",
      "sha256": "a42e2272f8047c30173996ed09c3daff877205eb0c60e6491ef075afa129f043"
    },
    {
      "file": "php-tests.txt",
      "sha256": "592c6082f0274937d7a31e3d5a126e11e465608aa8cfd5dc1ee64cb9e4a8383d"
    },
    {
      "file": "contract-check.txt",
      "sha256": "c9629d2851f453e9902dfcc291376b302e4986e9271bd27e8aca5449c4062259"
    }
  ],
  "business_code_modified": false,
  "robot_actions_executed": false
}

````
<!-- END EVIDENCE evidence-manifest.json -->
