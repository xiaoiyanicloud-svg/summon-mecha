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
