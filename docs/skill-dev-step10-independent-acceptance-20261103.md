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
