# 五机型机器人技能交付链路 · 研究级 GA 基线与独立审计

- 日期：2026-11-05
- 范围：`unitree_g1`、`unitree_r1`、`agibot_x2`、`agibot_a3`、`engineai_t800`
- 结论级别：**软件公共底座 PASS；当前 SIL replay = NOT_RUN；控制面性能门禁 FAIL；五机型 HIL = HARDWARE_UNVERIFIED；五机型 Production Ready = BLOCKED；整体 GA 未完成**
- 代码事实优先：本文结论以工作区代码、独立测试库 `summon_mecha_step10_test`、实际命令输出和官方原文核验为准。文档中的“待验证”不得被读成已通过。

## 1. 结论摘要与完成等级

### 1.1 已完成且可复现

| 能力 | 结论 | 证据等级 |
|---|---|---|
| 五机型独立 Profile、独立 Adapter 类、支持矩阵 | PASS（结构层） | HIGH_CONFIDENCE_FACT |
| A2 退出 GA 运行目标 | PASS | HIGH_CONFIDENCE_FACT |
| 多目标不可变构建、Profile 摘要、目标级审核事件 | PASS | HIGH_CONFIDENCE_FACT |
| 源包 XML/权限/资源预算/路径安全 | PASS（P0 边界内） | HIGH_CONFIDENCE_FACT |
| Outbox 原子领取、租约、Inbox 幂等 | PASS（自动化测试） | HIGH_CONFIDENCE_FACT |
| 设备事件 `event_id` 幂等、同键异载荷 409 | PASS（新增测试） | HIGH_CONFIDENCE_FACT |
| 执行成功必须有 actual_started + actual_stopped 独立观察 | PASS（新增测试） | HIGH_CONFIDENCE_FACT |
| `CANCEL_REQUESTED` 不释放租约，UNKNOWN 不回退 | PASS（新增测试） | HIGH_CONFIDENCE_FACT |
| 代次化 ReconcileReport 持久化 | PASS（新增测试） | HIGH_CONFIDENCE_FACT |
| 未知厂商物理限值运动技能 fail-closed | PASS（C-T27） | HIGH_CONFIDENCE_FACT |
| 安装下载/Staging 清理 | PASS（短时 soak 复验） | HIGH_CONFIDENCE_FACT |
| 500MiB 流式下载内存 < 64MiB | PASS（当前基准 7.166s / 3.621MiB） | HIGH_CONFIDENCE_FACT |
| 控制面 p95 < 300ms | **FAIL（red-team 基线 p95 1012.640ms、p99 1190.035ms）** | HIGH_CONFIDENCE_FACT |
| 30 分钟 soak | **PASS（899 cycles / 2,697 commands / 0 errors；内存 +8.324MiB）** | HIGH_CONFIDENCE_FACT（仅软件 soak，非 HIL） |
| 五机型 SIL/HIL | **当前 SIL replay 全部 NOT_RUN；G1 仅历史 artifact cross-check；HIL 全部未验证** | 不升级 |

### 1.2 不可宣称的事项

1. 不得宣称“五机型 GA 完成”或“Production Ready”。
2. 不得把 Mock、SIL、Python 脚本断言、SDK import、SDK 返回 `True`、BT halt 或云端 ACK 当成 HIL。
3. 不得把 L2 描述为“开箱即用”或跨品牌自动兼容。
4. 不得把 A2 重新注册为运行目标、默认 Profile 或宣传对象。
5. 不得把本次控制面本机 p95 失败解释为“生产也一定失败”；反过来也不得把开发机通过当成生产通过。

### 1.3 2026-11-05 独立复核更正

旧报告中的部分 PASS 已被更严格的 claim audit 降级，当前以实现和原始输出为准：

- `qa/hil-verification/hil_safety_report.json`：**INVALID_NON_HIL**。实现是 `MockHardwareSafetyInterlock`，12.5ms 为硬编码，没有独立传感器日志、硬件序列号、固件、操作者或可重放物理观察。
- `qa/physics-verification/dynamics_verification_report.json`：**DEMOTED**。源码没有 Pinocchio/RNEA 调用，关节惯量和 28.5mm 间隙为约化模型/硬编码值。
- `qa/dds-verification/dds_conformance_report.json`：**DEMOTED**。只证明结构体字节布局和本地计时，不是 live DDS participant、厂商 IDL 编译器、真实 QoS 或 DDS Security 互操作。
- G1 SIL 当前 **NOT_RUN**。固定 Python 3.12 环境没有 `mujoco` 或 `pinocchio`；只能对旧 `mujoco_telemetry.csv` 做 artifact cross-check，不能重放引擎，不能升级为当前 SIL PASS。
- 五机型仍为 L2、HARDWARE_UNVERIFIED、Production BLOCKED。
- 当前独立门禁：Deploy **167 passed / 5,839 assertions**，编译器 **33 passed / 5,238 assertions**，Agent API **22 passed / 112 assertions**，Agent Python **68 tests OK**，HIL validator **9 tests OK**。
- Composer locked audit：**76 个已知 advisory，3 critical、24 high、38 medium、8 low、3 unknown**，供应链门禁 FAIL。
- 高信号 secret 正则扫描有 6 个待复核项；gitleaks/trivy/trufflehog/pip-audit/safety 未安装，不能证明密钥历史无泄漏。
- 当前 PHP 8.4.24；PHP 8.3/FPM 未验证。

完整 claim ledger：`D:\main\qa\ga\final_evidence_ledger.json`  
claim audit：`D:\main\qa\ga\claim_audit_result.json`  
阻塞登记：`D:\main\qa\ga\blocked_items.json`
## 2. SOTA 边界与结构性缺陷

### 2.1 三条路线比较

| 路线 | 解决的问题 | 不能解决的问题 | 本项目采用方式 |
|---|---|---|---|
| TUF / Uptane / SLSA | 更新元数据角色分离、回滚/混搭攻击防护、构建 provenance、制品身份 | 不能证明安装语义正确、动作安全、停止可观测、物理效果幂等；Uptane 原文明确只覆盖端设备安全交付的一部分，并建议与 in-toto、TPM 等组合 | 用作签名、摘要、目标评审、构建证据和反回滚基础 |
| Transactional Outbox / Inbox + lease + reconcile | MySQL 本地事务与外部投递解耦、至少一次投递、断线补偿、重复消费幂等 | 不能证明外部物理动作只发生一次；不能替代厂商幂等令牌和独立观测 | 用作平台到设备的事实传输与账本层 |
| 能力精化 + 本地 Safety Shield + 物理效果栏栅 | 将标准能力映射到具体目标、在执行前校验参数/限值/权限、把停止与资源释放绑定到观察结果 | 静态能力矩阵无法证明跨机型语义等价；没有 HIL 不能证明真实安全性 | 用作编译期拒绝、执行期闸门和 UNKNOWN 安全收敛 |

**结构性结论**：三者是串联防线，不是可互相替代的方案。只做供应链安全会把“合法签名但物理行为错误”的包放过去；只做 Outbox/Inbox 会把“平台账本一致但机器人已运动”的事实漏掉；只做能力矩阵会把“声明支持”误当成“实机已验证”。
**标准可访问性声明**：ISO 13849-1:2023 官方目录页 `https://www.iso.org/standard/69883.html` 在本次访问时被 Cloudflare 人机校验阻断，未取得授权全文。因此本文不声称已完成条款级 ISO 13849 符合性映射，也不能用该目录页替代 PLe/PLd、类别、MTTFd、DC、CCF 或系统级验证。后续 HIL/Assurance Case 必须由持有授权文本的安全工程师逐条映射到具体危害、安全功能、场景、独立观测和验收记录。

### 2.2 官方资料核验表

> 访问日期均为 2026-11-04。标题、版本、日期以访问时官方页面为准；标准条款不等于系统级安全证明。

| 资料 | 版本/日期 | URL | 可复核论点 | 证明边界 |
|---|---|---|---|---|
| The Update Framework Specification | 1.0.37，Last modified 2026-10-08 | https://theupdateframework.github.io/specification/latest/ | 定义 root/targets/snapshot/timestamp 等更新元数据角色与回滚/混搭防护 | 不证明安装脚本、运行时动作、停止观测或机器人安全 |
| Uptane Standard for Design and Implementation | 2.1.0，发布 2023-06-23 | https://uptane.org/docs/latest/standard/uptane-standard | 面向车辆 ECU 的安全更新；明确指出供应链妥协只是整体问题的一部分，需与 in-toto、TPM 等组合 | 不证明厂商 SDK 的安装/执行语义；不替代 HIL |
| SLSA specification | v1.2，页面状态 Approved | https://slsa.dev/spec/v1.2/ | 用构建级别、provenance 与验证约束供应链完整性 | 不证明制品行为安全、权限最小化或物理效果 |
| RFC 8785 JSON Canonicalization Scheme | 2020-06 | https://www.rfc-editor.org/rfc/rfc8785.html | 规定跨平台稳定 JSON 序列化，用于摘要和签名输入 | 只提供规范化，不提供身份、授权或安全状态机 |
| NIST SP 800-218 SSDF | v1.1，2022-02 | https://csrc.nist.gov/pubs/sp/800/218/final | 提出安全软件开发生命周期实践 | 不是具体系统安全证明，不覆盖 HIL |
| NIST SP 800-160 Vol.2 Rev.1 | 2021-12-09 | https://csrc.nist.gov/pubs/sp/800/160/v2/r1/final | 面向网络韧性与系统安全工程，强调 adverse conditions 下的生存能力 | 必须落到具体危害与证据，不能只引用标题 |
| OMG DDS Security | 1.1，2018-07 | https://www.omg.org/spec/DDS-SECURITY/1.1/ | 定义 DDS Security Model 与 SPI 插件架构 | 保护 DDS 通信不证明运动规划安全或硬件 E-Stop |
| OMG DDSI-RTPS | 2.5，2022-04 | https://www.omg.org/spec/DDSI-RTPS/2.5/ | 定义 DDS 互操作 wire protocol | 不证明厂商实现一致性、QoS 正确性或实时安全 |
| Unitree SDK2 | main commit `8e1b927586712edd6a4c29d50dd52dff1e163439`，2026-10-30 | https://github.com/unitreerobotics/unitree_sdk2 | 官方仓库含 G1/R1 相关示例与 SDK 边界 | 不证明本项目安装包、停止观察或 HIL |
| AgiBot AimDK X2 文档 | AimDK_X2 1.1.0 | https://x2-aimdk.agibot.com/zh-cn/latest/quick_start/prerequisites.html | 官方文档区分 X2 版本、计算单元与二次开发接口 | 未取得本项目样机、固件锁和 HIL 证据 |
| EngineAI Native SDK | main commit `335c60e88772c26c7852d0abd6b3c7439037dd8f`，2026-08-04 | https://github.com/engineai-robotics/engineai_robotics_native_sdk | 官方 Native SDK 仓库包含 T800/T800 Pro 相关分支材料 | 不证明 T800 标准 Profile 的关节/力矩/温度限值已闭环 |
| Clopper & Pearson | Biometrika，1934 | https://doi.org/10.1093/biomet/26.4.404 | 二项比例精确置信区间方法来源 | 小样本区间不能代替场景覆盖与 HIL |
| STPA Handbook | 2018 | https://psas.scripts.mit.edu/home/get_file.php?name=STPA_handbook.pdf | 以控制结构和不安全控制行为分析复杂系统危害 | 分析结果仍需具体安全需求、验证和独立证据 |

### 2.3 本项目对 SOTA 的超越点与未超越点

**超越点（在软件层）**：
- 将更新完整性、能力精化、执行事实、独立停止观察和 Reconcile 代次放进同一条可审计链路；
- 对同一 `event_id` 执行“同载荷幂等、异载荷冲突”，并把 `late_report` 与 `UNKNOWN` 固化为不可回退事实；
- 对未知物理限值的运动能力在编译期拒绝，而不是运行期等 SDK 失败；
- 以机型为 scope 生成独立 build key、Product/Profile digest 和 release audit。

**未超越点（硬边界）**：
- 没有真实样机 HIL，无法证明厂商 SDK 的启动/完成/停止语义；
- 没有 KMS/HSM 和多公钥轮换，生产信任根未闭环；
- 控制面本机 p95 超过 300ms，不能声明生产性能达标；
- 五机型中的四款仍缺厂商原始物理限值；
- 通用 `VendorPlanAdapter` 只实现“受限计划运行时”，未证明各厂商真实动作桥独立完成。

## 3. 形式化系统、假设与不可能性

### 3.1 系统定义

```text
x_{t+1} = f(x_t, u_t, w_t)
y_t     = h(x_t, u_t, v_t)
u_t     = Shield(a_t, x_t, profile, evidence)
```

- `x_t`：机器人物理状态（关节角、速度、温度、接触、姿态、电源状态）。
- `l_t`：平台逻辑状态（build/release/task/execution/installation/reconcile）。
- `y_t`：独立设备观测（编码器、接触/电流/温度/独立安全控制器等）。
- `a_t`：平台或操作者意图动作。
- `w_t`：扰动（网络抖动、时钟漂移、电源波动、负载变化、碰撞）。
- `v_t`：观测噪声。
- `Shield`：设备侧安全内核与厂商安全控制边界；平台不能替代硬件 E-Stop。

安全不变量：

```text
I1: authorized(device, skill, release, entitlement, generation) before start
I2: unknown_limits => reject_before_motion
I3: no_conflicting_motion_without_exclusive_resource_lease
I4: terminal_status != inferred_from_sdk_ack
I5: CANCEL_REQUESTED does not imply actual_stopped
I6: SUCCEEDED/FAILED/CANCELED require observed stopped evidence (or enter UNKNOWN)
I7: same (did,event_id) cannot create a second business fact
I8: terminal facts are append-only; late facts do not silently rewrite them
I9: old generation cannot overwrite newer generation
```

停止时间约束：

```text
T_stop_observed <= T_deadline(profile, scenario)
if T_stop_observed is unknown or > deadline:
    execution = UNKNOWN
    lease remains occupied
    no new conflicting motion
```

网络异步性：平台和设备都只能在有限窗口内观察到局部事实；平台不能把本地 MySQL 提交时间当作设备物理时间。设备时间与平台时间只做窗口校验，不作为精确物理排序依据。

崩溃恢复：每个外部副作用前写意图，副作用后写观察；恢复时只能依据持久 journal、设备独立观测和签名授权重新判定。无法判定时进入 `UNKNOWN` 或 `RECOVERY_REQUIRED`，不得重放不可逆动作。

### 3.2 不可能性证明与严格论证

**定理 1（崩溃/分区下无法保证 exactly-once physical effect）**  
若平台发送动作后、收到设备事实前发生无限期分区或崩溃，则平台无法区分：
- 设备未收到动作；
- 设备收到但未执行；
- 设备执行完成但 ACK 丢失；
- 设备正在执行且无法停止。  
平台只能通过重投提高可用性，但重投会引入重复物理效果；若无设备端持久幂等令牌并与副作用原子提交，则不存在同时保证“至少一次”和“至多一次物理效果”的协议。**结论：只能承诺至少一次逻辑投递，并以设备端幂等、观察和 UNKNOWN 收敛物理效果；exactly-once physical effect 是条件性目标，不是默认保证。**

**定理 2（静态 Profile 不能仅靠字段匹配保证语义等价）**  
两个机器人可以有相同的能力 ID、参数名和单位，但关节轴方向、零位、坐标系、连杆几何、碰撞体、速度/加速度限制、完成定义和停止语义不同。令 `M1(a)` 与 `M2(a)` 为同一抽象动作在两个目标上的物理映射，字段匹配只能证明表面签名相同，不能证明 `M1(a)` 与 `M2(a)` 在任务语义、资源占用和停止条件上等价。**结论：必须有目标特定 lowering、能力证据和独立 SIL/HIL。**

**定理 3（完整性不等于执行安全）**  
签名只能证明“某私钥签署了某字节串”。有效签名不约束字节内容的安全性、安装脚本行为、参数合理性、物理限值和现场状态。形式上，`Verify(pk, m, sig)=1` 不推出 `Safe(execute(m, x_t))=true`。**结论：TUF/Uptane/SLSA 是必要条件，不是充分条件。**

**定理 4（没有独立观测不能证明物理停止）**  
若设备仅返回 `CANCEL_ACCEPTED` 或 `SDK halt=True`，该返回值只证明 API 接受；不证明关节速度为零、接触力消失、抱闸生效或电源隔离。令 `y_t` 为独立传感器观测，只有 `ObserveStop(y_t)=true` 才能支持 `CANCELED`。**结论：CANCEL_REQUESTED 不是物理停止。**

**定理 5（云端不能在实时控制回路同时保证低延迟和硬实时安全）**  
网络延迟、调度和 GC 等没有硬上界；若控制周期为 `T_c`，平台到设备往返时延为 `D(t)`，当 `P(D(t) > T_c) > 0` 时，云端闭环无法满足硬实时截止期。**结论：硬件 E-Stop、实时运控、平衡和厂商安全控制权必须留在本地。**

## 4. Fundamental Trade-offs

| 权衡 | 机制 | 本项目取舍 |
|---|---|---|
| 安全 vs 可用性 | 未知限值、签名失败、停止不明时拒绝/锁定；可用性下降 | 选择 fail-closed；UNKNOWN 占用租约，人工处置 |
| 语义表达力 vs 可验证性 | 支持任意脚本/SDK 调用会放大不可验证面 | 首批只支持受限 `SM_GestureWave/SM_AudioPlay/SM_Wait`；复合控制节点拒绝 |
| 反回滚新鲜性 vs 离线可用性 | 严格 generation/非过期校验会让离线旧任务不可安装；宽松会允许回滚攻击 | 安装/启用/执行受期限和 generation 约束；补报与安全处置保留可达 |
| 全局一致性 vs 本地实时性 | MySQL 强一致不能延伸到实时控制 | 平台事务强一致；设备本地 lease + journal + 观察；不做跨端分布式事务 |
| 隔离/沙箱 vs p95 | 子进程、审计、独立观察增加延迟 | 宁可接受当前 p95 FAIL，不用“减少校验”换指标 |
| 证据成本 vs 发布速度 | HIL/原始日志/重放脚本显著增加成本 | 无 HIL 不升级 L1/Production Ready；不跳级 |
| 回滚可逆性 vs 物理状态不可逆性 | 文件可回滚，已发生的动作、碰撞和热损伤不可逆 | 回滚只恢复明确良好版本与安全状态；无法恢复时人工处置 |

## 5. 五个可证伪核心假说

| 编号 | 假设 | 预期机制 | 反例 | 最小验证实验 | 杀死条件 | 结果/证据等级 |
|---|---|---|---|---|---|---|
| H1 | 以 `(did,event_id)` 原子 Inbox 可消除 ACK/事实重放导致的二次业务事实 | 唯一键 + 同载荷返回原 `ack_id`，异载荷 409 | 设备重投同一事件却改变阶段，平台仍创建第二条事实 | 1000 次重复 ACK + 重复事实 + 同键异载荷 | 任一次重复 ACK/事实产生第二条受理记录，或同键异载荷被接受 | PASS；HIGH_CONFIDENCE_FACT |
| H2 | 平台不再能从 phase 推断执行成功，必须要求实际启动/停止观察 | `ExecutionFact` 先验证，再推进状态机 | `done` 无 `actual_stopped=true` 仍写 SUCCEEDED | API 发送无停止证据 done | 任务/执行状态被改写 | PASS；HIGH_CONFIDENCE_FACT |
| H3 | 未知厂商物理限值可被编译期 fail-closed | 运动能力检查关节/速度/力矩/温度/碰撞包络 | 未解析 Profile 仍生成 wave 成品 | 同一运动源构建五目标 | 非 G1 目标生成成功产物 | PASS（C-T27）；HIGH_CONFIDENCE_FACT |
| H4 | Outbox lease + 条件更新可让真实 Worker 竞争只产生一个 claim | 事务内 `lockForUpdate` + 状态条件更新 | 两 Worker 同时领取同任务 | 两真实 PHP Worker 竞争 | claim 数 > 1 | PASS；HIGH_CONFIDENCE_FACT |
| H5 | 当前控制面容器可达到 p95 < 300ms | 减少重复初始化、保持生产级 OPcache/FPM | 单请求启动/路由开销 > 300ms | 100 次支持矩阵请求 | p95 ≥ 300ms | **FAIL（red-team p95 1012.640ms / p99 1190.035ms）**；HIGH_CONFIDENCE_FACT |

> 统计报告：当前回归为全量计数；性能样本报告 n、p50/p95/p99/max、吞吐和饱和点，不把点估计写成保证。HIL 场景必须报告 n、失败数 k、Clopper-Pearson 上界。

## 6. 红队攻击与崩溃边界

### 6.1 攻击/失败矩阵

| 场景 | 攻击面 | 当前防线 | 仍需补证 |
|---|---|---|---|
| 同键重试 | 重复命令/重复 ACK | 任务幂等键、`(did,event_id)` Inbox 唯一键 | 生产 MySQL 高并发与故障注入 |
| 同键异参数 | 伪造事件载荷 | 全载荷 JCS SHA-256 比对，冲突 409 | 设备签名事件（当前依赖设备认证通道） |
| Worker 竞态 | 双 Worker claim | 行锁 + 条件更新 + lease token | 生产网络文件系统/DB 隔离复验 |
| 取消/完成并发 | 状态机竞态 | `CANCEL_REQUESTED` 不落终态；终态条件更新 | 真实设备停止并发 HIL |
| 旧 generation 覆盖 | 离线旧任务上线 | generation/revocation_epoch 检查、自动审核失效 | 真实设备持久代次与回滚演练 |
| 安装/卸载互斥 | 同设备资源冲突 | 单任务串行 + 本地 execution lease | 厂商插件并发与硬件资源仲裁 |
| ZIP Slip/软链/炸弹 | 恶意包 | 路径 `realpath`、文件数/总量/压缩比限制 | 生产磁盘耗尽与断电恢复 |
| 重定向泄密 | 跨源重定向带设备认证头 | 跨源剥离 `X-Agent-*`/Authorization | 代理与 CDN 行为实测 |
| 包替换 | 审核后换字节 | 签名 `package_sha256` + 设备复算 | KMS/HSM、多密钥轮换 |
| SDK 假成功 | import/返回 True | 真实 adapter 拒绝 import 当执行；`ExecutionFact` 约束 | 厂商完成/停止语义 HIL |
| 云端实时停止 | 网络延迟/分区 | 明确不接管硬件 E-Stop | 现场安全联锁验证 |
| 日志/审计污染 | 半条事件 | Inbox 与状态推进同事务，迟到事实 append-only | 生产审计存储防篡改 |

### 6.2 崩溃边界分析：什么情况下本方案无效

本方案在以下条件下**不能**保证安全或可交付：

1. 厂商 SDK 没有可验证的幂等令牌，且外部动作已经发生但设备无法独立观察其是否停止；此时只能 `UNKNOWN`，不能自动重试或释放租约。
2. 设备被物理隔离、掉电或传感器失效，平台和 Agent 都失去观测能力；任何云端状态都只是猜测。
3. 厂商固件在 SDK 返回成功后继续运动，或安全控制器不接受本平台生成的停止/禁用指令；此时文件回滚不能证明物理回滚。
4. 安全签名密钥泄露且攻击者同时控制发布、制品和网络；TUF/签名本身不能证明操作者意图。
5. 现场存在未建模的人、障碍物、地面变化或负载，静态 Profile 和 SIL 不能证明实际场景安全。
6. 生产 MySQL/Worker/对象存储发生跨系统不一致，且 reconcile 未能在允许窗口内发现；平台只能保留账本差异，不能凭空修复物理状态。
7. 设备时钟严重漂移、generation 被回滚或设备本地 journal 被清空；此时授权和新鲜性判断不可信，必须人工隔离。
8. 真机 HIL 未执行或证据不满足独立观测、版本锁定、原始日志、重放脚本要求；此时任何“安全完成”结论都无效。

## 7. 架构、状态机、事务与幂等设计

### 7.1 链路

```mermaid
flowchart LR
  A[标准技能源] --> B[源包安全校验]
  B --> C[IR/能力精化]
  C --> D[五目标独立构建]
  D --> E[不可变成品+证据]
  E --> F[目标级审核/发布]
  F --> G[客户授权]
  G --> H[MySQL Transactional Outbox]
  H --> I[Agent 拉取/验签/流式下载]
  I --> J[安全解压/安装/启用]
  J --> K[执行/取消/独立观察]
  K --> L[设备事实+event_id]
  L --> M[平台 Inbox 幂等入账]
  M --> N[Reconcile/丢失事件对账]
  N --> O[回滚/禁用/撤销]
  O --> P[指标/日志/告警/审计]
```

### 7.2 状态机

**Build**

```text
pending -> running -> succeeded | failed | canceled
```

历史成功 build/run 不因重试被覆盖；失败重试创建新 run。

**DeployTask（代码现状保持兼容词表）**

```text
pending -> dispatching -> downloading -> installing -> success | failed | timeout | cancelled
pending -> pending_online -> dispatching
download_failed -> downloading | installing | failed | timeout
```

说明：代码库历史词表使用 `success/failed/cancelled`；研究基线中的 `succeeded/expired` 在实现上线前不得擅自改名。语义以终态不可回退和事实可追溯为准。

**SkillExecution**

```text
ACCEPTED -> RUNNING
RUNNING -> SUCCEEDED | FAILED | CANCELED | UNKNOWN
RUNNING -> CANCEL_REQUESTED -> CANCELED | FAILED | UNKNOWN
```

不变量：
- `SUCCEEDED` 必须有 `actual_started=true` 与 `actual_stopped=true`；
- `FAILED/CANCELED` 必须有独立停止证据；否则 `UNKNOWN`；
- `UNKNOWN` 是终态，迟到成功只能追加 `late_report`，不能静默覆盖；
- `CANCEL_REQUESTED` 保留资源租约，不释放互斥资源。

**Installation**

```text
absent -> downloading -> staged -> verified -> installed -> enabled
任意阶段 -> failed
installed/enabled -> rollback/disabled/uninstalled
```

### 7.3 事务边界

1. MySQL 本地事务只包含数据库操作；事务内不调用 HTTP、SDK、DDS 或机器人动作。
2. `deploy_task` 同时是 Outbox；claim 在事务内锁定行并写 lease，不调用网关。
3. 设备事实先写入 `deploy_inbox_event`，同一事务内推进任务/执行状态；失败则整体回滚。
4. 设备端先写 SQLite journal/outbox，再执行外部动作；结果先持久化，再上报。
5. 平台 ACK 丢失时设备用原 `event_id` 重投；平台返回原 `ack_id`，不重复推进状态。
6. 迟到事实写 `late_report=true`，保留服务端终态，不制造“事后完美一致”的假象。

### 7.4 幂等键

| 对象 | 幂等域 | 冲突语义 |
|---|---|---|
| BuildCommand | `seller:{sellerId}:build:{idempotencyKey}` | 同键异参数拒绝/另一请求 |
| DeployTask | `(did, skill_id, skill_version_id, operation)` 或 `(did,operation,execution_id)` | 同键未终态复用；执行/取消按 execution_id 分离 |
| InboxEvent | `(did,event_id)` | 同载荷返回原 ACK；异载荷 409 |
| ACK response | `ack_id` 唯一 | 重投返回同一 ACK |
| ReconcileReport | `(did,report_hash)` | 重复报告不新增；旧 generation 标记 stale |
| TargetReleaseEvent | 只追加事件 | 历史审核/撤销不覆盖 |
| OutboxLease | `(task_id,token,attempt,expiry)` | 过期可重领；旧 token 不能提交 |

## 8. 数据库约束、索引和迁移策略

### 8.1 关键表与约束

| 表 | 关键字段 | 约束/索引 |
|---|---|---|
| `skill_execution` | `execution_id`, `actual_started`, `actual_stopped`, `execution_evidence`, `last_event_seq` | `execution_id` unique；`(did,status)`、`(skill_id,status)` 索引 |
| `deploy_inbox_event` | `did`, `event_id`, `event_type`, `event_seq`, `payload_hash`, `applied`, `result_status`, `late_report` | `(did,event_id)` unique；`ack_id` unique；`(task_no,stage)` index |
| `device_reconcile_report` | `did`, `model_code`, `observed_generation`, `report_hash`, `drift_count` | `(did,report_hash)` unique；`(did,observed_generation)` index |
| `deploy_task` | `delivery_attempt`, `outbox_lease_token`, `outbox_lease_expires_at` | lease expiry index；任务号 unique |
| `skill_target_release_events` | build/profile/artifact snapshot, event_type, actor, reason | 只追加；version/model index |
| `skill_build_runs` | `(build_key,run_number)` | 不可变 run 历史 |
| `skill_target_releases` | target model, build_key, profile digest, review status | 每机型独立审核 |

### 8.2 迁移策略

- 新迁移：`2026_11_04_000002_add_ga_fact_evidence_and_reconcile_reports.php`。
- 全部为 additive：新增列、索引、表；不修改/删除历史构建与生产数据。
- 测试库仅使用 `summon_mecha_step10_test`。
- 不对 root 执行 `migrate:fresh`、`truncate`、`seed`。
- 生产回退优先前滚；确需回退时先验证无新数据依赖，再按单步回退。
- 迁移状态已在本机测试库执行，结果见 §12。

## 9. P0–P4 实施批次

| 批次 | 工作 | 当前状态 | 退出条件 |
|---|---|---|---|
| P0 | 五 Profile、A2 废止、能力矩阵、构建/审核/Outbox/Inbox/ACK/租约/对账/开关 | 软件 PASS；控制面 p95 FAIL | 性能门禁通过、30min soak 通过、生产迁移演练 |
| P1 | G1/R1 独立 SDK2/DDS 边界、同源编译、能力拒绝、独立 SIL/HIL | G1 软件结构与历史 artifact；当前 replay NOT_RUN；R1 仅契约；HIL 未验证 | 两机型可重放 SIL、真机安装/启停/停止观察/HIL |
| P2 | X2/A3 AimDK 边界、资源权限、独立 Profile/构建/审核/SIL/HIL | 结构层 PARTIAL；运动限值 fail-closed；HIL 未验证 | 厂商原始限值、AimDK 真机桥、各自 HIL |
| P3 | T800 Native SDK/ROS2/CycloneDDS、轨迹映射、仲裁 | 结构层 PARTIAL；HIL 未验证 | T800/T800 Pro 独立组合验证、SIL/HIL |
| P4 | 灰度、监控、故障演练、KMS/HSM、轮换吊销、Runbook、支持矩阵 | BLOCKED | HIL、KMS/HSM、生产 SLO、审计演练完成 |

## 10. 每机型支持矩阵与完成等级

| 机型 | Profile/Adapter | 兼容等级 | 软件 | SIL | HIL | Production Ready |
|---|---|---|---|---|---|---|
| `unitree_g1` | 独立 Profile + `UnitreeG1Adapter` | L2 | PASS（结构/编译/执行契约） | NOT_RUN（仅历史 artifact cross-check） | HARDWARE_UNVERIFIED | BLOCKED |
| `unitree_r1` | 独立 Profile + `UnitreeR1Adapter` | L2 | PARTIAL（SDK 边界契约） | NOT_RUN | HARDWARE_UNVERIFIED | BLOCKED |
| `agibot_x2` | 独立 Profile + `AgibotX2Adapter` | L2 | PARTIAL（AimDK 契约） | NOT_RUN | HARDWARE_UNVERIFIED | BLOCKED |
| `agibot_a3` | 独立 Profile + `AgibotA3Adapter` | L2 | PARTIAL（AimDK 契约） | NOT_RUN | HARDWARE_UNVERIFIED | BLOCKED |
| `engineai_t800` | 独立 Profile + `EngineAIT800Adapter` | L2 | PARTIAL（Native SDK 契约） | NOT_RUN | HARDWARE_UNVERIFIED | BLOCKED |

A2：运行 Profile 删除；Adapter 注册拒绝；历史物理素材仅保留为证据，不作为 GA 目标。

## 11. 极端测试、性能基准和 HIL 方案

### 11.1 必测矩阵

| 组 | 场景 | 当前 |
|---|---|---|
| 幂等/重试 | 1000 同键命令、1000 重复 ACK、同键异参数 | 已通过（含新增事件幂等测试） |
| 并发/竞态 | 两真实 Worker 抢同一任务、取消/完成并发、旧 generation/安装卸载互斥 | 双 Worker 与状态机测试通过；生产故障注入未完成 |
| 崩溃恢复 | 下载后/解包后/ACK 前/启动后崩溃 | Agent SQLite journal、UNKNOWN、outbox 重投有测试；断电/文件系统故障未完成 |
| 多机型编译 | 同源五目标、能力不足、Profile 变化、产物串用、A2 拒绝 | 通过；未知物理限值新增 C-T27 fail-closed |
| 安全/租户 | 错签名、过期、nonce 重放、ZIP Slip/软链/炸弹/哈希、跨客户/Seller/设备 | 主要通过；生产 KMS/多密钥未完成 |
| 性能 | 1000 重复、p95/p99、500MiB 流式、30min soak、Outbox 积压 | 500MiB PASS；30min soak PASS；p95/p99 FAIL；Outbox 积压生产演练未完成 |
| 物理安全 | E-Stop、看门狗、通信中断、电源中断、传感器失效、热降额、回滚失败、启动后崩溃 | 当前 SIL replay 未运行；五机型真机 HIL 未执行 |
| 证据完整性 | Mock/SIL 不冒充 HIL；独立观测/版本/原始日志/重放 | 新增 `hil_evidence_validator.py`；五机型未提交 HIL bundle |

### 11.2 HIL 方案

每机型必须分别提交：
1. 硬件序列号哈希、固件、SDK、Agent、制品 SHA-256、Profile 摘要；
2. 独立观察源（不得是 SDK return/BT halt/cloud ACK/script assertion）；
3. E-Stop、看门狗、通信中断、电源中断、传感器失效、热降额、回滚失败、启动后崩溃场景；
4. 原始日志、重放脚本、操作者/场地、开始/结束时间；
5. `actual_started`、`actual_stopped`、物理停止观察；
6. 每个场景报告 `n_trials`、`failures`、置信水平和 Clopper-Pearson 单侧上界；GA 的场景上界必须 ≤ 0.01；
7. `hil_evidence_validator.py` 退出码 0。

**任何一项缺失：该机型 HIL=HARDWARE_UNVERIFIED，Production=BLOCKED。**
### 11.3 标准到危害/安全功能/场景/观察的映射

| 危害 | 安全功能 | 依据（证明边界） | 最小场景 | 独立观察 | 当前证据 |
|---|---|---|---|---|---|
| 替换/回滚到恶意包 | 签名包、Profile 摘要、目标审核、反回滚 generation | TUF/Uptane（更新完整性）；SLSA（构建 provenance）；RFC 8785（规范签名） | 错签名、换字节、旧 build、跨目标串包 | 设备复算 SHA-256；发布/审核事件 | 自动化测试 PASS；KMS/HSM 未完成 |
| 重复物理动作 | 任务/execution 幂等、设备本地 journal、单次启动计数 | Outbox/Inbox 只能保证逻辑幂等；物理幂等依赖设备持久令牌 | 1000 重投、崩溃后重投、双 Worker | 外部适配器启动计数与 journal | 自动化测试 PASS；真机 HIL 未执行 |
| 取消未真正停止 | 本地 Safety Shield、停止观察、资源租约保持 | 系统安全工程/STPA（不安全控制行为）；SDK 返回值不构成观察 | 运行中取消、通信中断、电源中断 | 编码器/接触/电流/安全控制器原始日志 | API/模拟测试 PASS；真机 HIL 未执行 |
| 旧 generation 覆盖新版本 | 新鲜性检查、revoke epoch、拒绝旧包 | Uptane 反回滚思想；本仓库 generation 机制 | 离线设备恢复旧任务、审核后撤稿 | 设备持久 epoch + 平台事件 | 代码与状态机测试；生产演练未完成 |
| 未知物理限值导致过速/过力矩 | 编译期 fail-closed、Profile 限值核验 | ISO 13849-1 条款级映射待授权全文；STPA 用于危害识别 | 未解析 Profile、参数边界、热降额 | 编译拒绝记录；SIL/HIL 传感器日志 | C-T27 / C-T22 PASS；HIL 未执行 |
| 云端进入实时停止回路 | 硬件 E-Stop 独立、本地实时控制 | NIST SP 800-160v2r1/系统安全工程；STPA | 网络分区、延迟、云端故障 | 硬件安全控制器与现场日志 | 架构边界明确；HIL 未执行 |
| DDS 通信窃听/篡改/未授权发布 | DDS Security 认证/访问控制、签名任务 | OMG DDS Security 1.1；DDSI-RTPS 2.5 | 错身份、nonce 重放、跨设备任务 | DDS 安全插件日志 + 设备身份 | 侧信道/协议测试部分完成；真实 DDS 安全插件未接 |
| 崩溃后事实分叉 | 持久 journal、Inbox、reconcile、UNKNOWN | NIST SP 800-160v2r1 韧性思想；STPA | ACK 前/启动后/下载后崩溃 | 设备 journal + 平台 Inbox + 代次报告 | 自动化/短 soak PASS；30min 软件 soak PASS |

### 11.4 性能报告规则

- 报告 n、失败数 k、p50/p95/p99/max、饱和点；不得只写“通过”。
- 生产类似环境未测时写 `UNVERIFIED`，不得用开发机 p95 代替。
- 最新 red-team 本机基线：n=100，p50=777.492ms，p95=1012.640ms，p99=1190.035ms，max=1195.747ms，吞吐约 1.259 rps；阈值 300ms：**FAIL**。并发 2 时已进入饱和区（吞吐不再提升，p95=1879.074ms）。
- 当前 500MiB 流式下载结果：524,288,000 bytes、7.166s、内存增量 3.621MiB：PASS。

## 12. 证据账本、复现命令和原始输出摘要

### 12.1 证据文件

| 证据 | 路径 | 说明 |
|---|---|---|
| P0 基线 | `docs/skill-dev-step10-five-model-ga-p0.md` | 既有 P0 交付 |
| 本次研究交付 | `docs/skill-dev-step10-five-model-ga-research-delivery.md` | 本文件 |
| HIL 硬门禁 | `qa/ga/hil_evidence_validator.py` | 拒绝 mock/SIL/SDK return 冒充 HIL |
| HIL 门禁测试 | `qa/ga/test_hil_evidence_validator.py` | 9 项反例/正例测试，含 Clopper-Pearson 上界 |
| 旧 HIL 判定 | `qa/hil-verification/EVIDENCE_STATUS.json` | `INVALID_NON_HIL`，禁止用于 HIL/L1/GA |
| Claim audit | `qa/ga/claim_audit_result.json` | 降级旧 HIL、动力学和 DDS 声明 |
| 完整 claim ledger | `qa/ga/final_evidence_ledger.json` | 23 项 claim，`missing_evidence=[]` |
| 阻塞登记 | `qa/ga/blocked_items.json` | 10 项外部/环境阻塞 |
| Soak 工具 | `qa/ga/soak_agent.py` | 签名 install→execute→uninstall、句柄/线程/文件趋势 |
| Soak 结果 | `qa/ga/redteam_soak_30m_result.json` | 1,800.969s / 899 cycles / 0 errors / PASS |
| Soak 时间线 | `qa/ga/redteam_soak_30m_timeline.jsonl` | 样本时间线 |
| 控制面 p95/p99/饱和点 | `qa/ga/redteam_control_plane_saturation_result.json` | n=100 基线、并发 1/2/4/8；最终 FAIL |
| 500MiB 流式 | `qa/ga/stream_500mb_result.json` | 7.166s / 内存增量 3.621MiB / PASS |
| 并发 claim | `qa/ga/claim_concurrency_result.json` | 双 Worker：1 claim / 1 attempt / 两 worker 均正常退出 |
| 1000 重复命令/ACK | `qa/ga/benchmark_p0_result.json` | 1000→1 任务、1000→1 Inbox |
| 新增事实测试 | `packages/Webkul/Shop/tests/Feature/API/AgentV2ExecutionTest.php` | Inbox/ExecutionFact/UNKNOWN/迟到事实 |
| 编译边界测试 | `packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerAcceptanceTest.php` | C-T25/C-T27/C-T28 |
| 当前 PHP Deploy 日志 | `qa/ga/current_deploy_tests.log` | 167 passed / 5,839 assertions |
| 当前 Agent API 日志 | `qa/ga/current_agent_api_tests.log` | 22 passed / 112 assertions |
| 当前 Python 测试 | `qa/ga/current_agent_unittest.log` | 68 tests OK（最新本地运行） |
| 当前 HIL validator 日志 | `qa/ga/current_hil_validator_tests.log` | 9 tests OK |
| 证据 SHA-256 清单 | `qa/ga/evidence_hashes.json` | 代码、结果与测试日志逐文件哈希 |
### 12.2 复现命令

```powershell
cd D:\main\my-bagisto-store
$env:APP_ENV='testing'
$env:DB_DATABASE='summon_mecha_step10_test'
$env:LOG_CHANNEL='stderr'

php artisan test packages/Webkul/Shop/tests/Feature/Deploy
php artisan test packages/Webkul/Shop/tests/Feature/API/AgentV2ExecutionTest.php
php artisan ecosystem:contract-check
php artisan ecosystem:data-preflight
php artisan migrate:status
php artisan route:list --path=api/agent
php -l <changed-php-file>
```

```powershell
cd D:\main\agent
$python='C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
& $python -m unittest discover -s tests -p 'test_*.py'
& $python -m compileall -q zhjj_agent tests
```

```powershell
cd D:\main
$python='C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
& $python qa/ga/hil_evidence_validator.py <hil-bundle>/evidence.json --json qa/ga/hil_validation_result.json
& $python qa/ga/soak_agent.py --duration-seconds 1800 --interval-seconds 2 --sample-seconds 10 --output qa/ga/soak_30m_result.json --timeline qa/ga/soak_30m_timeline.jsonl
& $python qa/ga/benchmark_control_plane_p95.py
& $python qa/ga/benchmark_stream_500mb.py
```

### 12.3 当前输出摘要

- 平台 Deploy 全量：**167 passed / 5,839 assertions**。
- 编译器专项：**33 passed / 5,238 assertions**（新增 C-T27、C-T28）。
- Agent：**68 tests OK**（含五机型 Adapter、真实 HTTP、停止观察反例）。
- HIL validator：**9 tests OK**。
- 短时 soak：10 cycles / 30 commands / 0 errors；内存增长 5.793MiB、句柄增长 11、临时文件从 2 到 3，PASS（仅软件 soak）。
- 正式 30min soak：1,800.969s、899 cycles、2,697 commands、0 errors；内存增长 8.324MiB、句柄增长 11、线程峰值 2、临时文件 2→3，PASS（仅软件 soak）。
- 控制面 p95/p99：FAIL；n=100，p95=1012.640ms，p99=1190.035ms；饱和点并发 2。
- 迁移：2026_11_04_000001、2026_11_04_000002 在独立测试库 Ran。

## 13. 监控、告警和值守 Runbook

### 13.1 指标与阈值

| 指标 | 告警阈值 | 处置 |
|---|---|---|
| `outbox_pending_age_seconds` | > 300s | 检查 worker、网络、设备在线、Inbox |
| `execution_unknown_total` | > 0 | 冻结相关资源租约，人工确认物理停止 |
| `terminal_execution_without_stop_evidence_total` | > 0 | 立即隔离版本/设备，禁止新冲突动作 |
| `late_fact_total` | > 0 | 检查设备时钟、ACK 丢失、任务终态 |
| `reconcile_reports_with_drift` | > 0 | 检查 generation、安装/执行投影，禁止自动“修好” |
| `queue_lag_seconds` | > 300s | 扩 worker/检查 scheduler；不要只说重启 |
| `package_signature_failed_total` | 突增 | 停止发布，检查密钥/信任根/包源 |
| `package_hash_mismatch_total` | > 0 | 阻断版本，保留包和证据，检查供应链 |
| Agent restart/staging orphan | 突增 | 检查 SQLite WAL、磁盘、子进程回收 |
| HIL evidence invalid | 任一失败 | 该机型降为 HARDWARE_UNVERIFIED，Production BLOCKED |

### 13.2 Runbook

**Outbox 积压**：确认 `deploy_task` 最老 pending；检查 worker/DB/lease/设备在线；禁止在 MySQL 事务中发 HTTP；恢复后观察 ACK/Inbox 是否收敛。
**UNKNOWN**：冻结该 execution 的资源租约；下发独立停止/禁用；等待物理观察或人工确认；不得用超时自动释放并启动新动作。
**签名/哈希失败**：冻结目标发布；检查 key id、轮换状态、制品 SHA、CDN/缓存；保留失败包与日志；必要时执行 revoke。
**reconcile drift**：比对设备 generation、安装表、执行表；只追加 drift 和人工处置，不把 timeout/UNKNOWN 改成 success。
**回滚失败**：保留现场；禁止删除证据；按厂商安全退出；如果无法确认停止，标记 MANUAL_REQUIRED。
**E-Stop/看门狗**：由现场安全流程处置；平台不得远程复位硬件 E-Stop，不得进入实时停止回路。

## 14. 未完成项、外部阻塞与风险接受

| 项 | 状态 | 阻塞原因 | 风险接受 |
|---|---|---|---|
| 五机型 HIL | 未执行 | 无真机/样机、无厂商现场窗口 | 不接受为 GA；Production BLOCKED |
| G1 当前 SIL replay | NOT_RUN | 固定 Python 3.12 无 mujoco/pinocchio；仅有旧 telemetry artifact | 不把历史 CSV 交叉核对当当前 SIL PASS |
| 旧动力学与 DDS 报告 | DEMOTED | 无真正 Pinocchio/RNEA；DDS 无 live participant/IDL/QoS | 只保留算术/布局级结论 |
| 依赖漏洞 | FAIL | 76 个 advisory，3 critical、24 high | 生产供应链门禁不通过 |
| Secret history scan | REVIEW_REQUIRED | gitleaks/trivy/trufflehog 等未安装 | 不能宣称密钥历史无泄漏 |
| R1/X2/A3/T800 原始关节/速度/力矩/温度限值 | 未取得 | 厂商确认与原始资料缺失 | 运动能力 fail-closed，不猜默认值 |
| 真实厂商动作桥/停止观察 | PARTIAL | 需要目标 SDK 与样机 | 不宣称 L1 |
| KMS/HSM、多密钥轮换/吊销 | 未完成 | 生产信任根与运维流程未批准 | 不开放生产签名 |
| 控制面 p95 | FAIL | 本机 PHP 8.4/开发服务基线未达 300ms | 不把本地结果升级生产 |
| 30 分钟 soak | PASS（软件层） | 1,800.969s/899 cycles/0 errors，内存、句柄、线程、临时文件均未超阈值 | 不能替代 SIL/HIL |
| 生产规模 1000 并发/网络抖动/Outbox 积压恢复 | 未完成 | 需生产类似容器与网络 | 不给生产 SLO 保证 |
| 告警平台/指标导出器 | PARTIAL | 未接监控平台 | 仅提供只读快照命令 |
| PHP 8.3 | 未验证 | 本机仅有 PHP 8.4.24 | 记录偏差，不静默忽略 |
| A2 历史物理素材 | 保留证据 | 不可作为运行目标 | 不注册、不宣传、不作为默认 |

## 15. 置信度分级表

| 结论 | 等级 | 依据 |
|---|---|---|
| 五机型 Profile/Adapter 结构已存在，A2 运行 Profile 已废止 | HIGH_CONFIDENCE_FACT | 代码、资源、测试 |
| 同源五目标中等待型可构建，运动型在未知限值下拒绝 | HIGH_CONFIDENCE_FACT | C-T25/C-T27 |
| Inbox 同键幂等/异载荷冲突 | HIGH_CONFIDENCE_FACT | API 测试与唯一键 |
| 执行成功必须有独立停止证据 | HIGH_CONFIDENCE_FACT | ExecutionFact + API 测试 |
| CANCEL_REQUESTED 不等于物理停止 | HIGH_CONFIDENCE_FACT | 固定值与测试 |
| UNKNOWN 终态不被迟到成功覆盖 | HIGH_CONFIDENCE_FACT | late_report 测试 |
| Outbox 租约可挡住并发重复 claim | HIGH_CONFIDENCE_FACT | 条件更新 + 并发基准（历史） |
| 五机型无 HIL 前不能升级 L1/GA | LOGICAL_INFERENCE + HIGH_CONFIDENCE_FACT | 规则与证据缺口 |
| 厂商 SDK 真实停止语义 | UNKNOWN | 无真机 HIL || G1 当前 SIL replay | NOT_RUN / UNKNOWN | 固定 Python 3.12 无 mujoco/pinocchio；仅有旧 CSV cross-check |
| 旧 HIL/动力学/DDS PASS 声明 | 已降级 | `claim_audit_result.json`：INVALID_NON_HIL / DEMOTED / DEMOTED |
| 依赖漏洞 | FAIL | Composer audit：3 critical、24 high、38 medium、8 low、3 unknown |
| Secret history scan | EXPLORATORY_HYPOTHESIS | 仅高信号正则 6 项，缺完整历史扫描器 |
| 生产 p95/p99/饱和点 | UNKNOWN | 仅本机 100 请求，p95 FAIL |
| 生产 KMS/HSM 安全 | UNKNOWN | 未实现/未演练 |
| 30min 资源泄漏趋势 | HIGH_CONFIDENCE_FACT（软件 soak PASS） | 899 cycles、0 errors、内存 +8.324MiB、句柄 +11、临时文件 2→3 | 仅限本地 HTTP/Mock 软件链路，不代表真机资源行为 |
| 全链路国家级实验室 GA | **BLOCKED** | HIL、KMS、生产性能、生产故障演练均未闭环 |

### 最终判定

```text
软件公共底座：PASS（存在已证实性能门禁失败，不等于 GA）
SDK 接入：PARTIAL
物理仿真：当前五机型 SIL replay 均 NOT_RUN；G1 仅历史 artifact cross-check
HIL：五机型 HARDWARE_UNVERIFIED
Production Ready：五机型 BLOCKED
整体五机型 GA：BLOCKED
```

> 任何后续将 `HARDWARE_UNVERIFIED` 改为 `HARDWARE_VERIFIED` 的操作，必须先提交通过 `qa/ga/hil_evidence_validator.py` 的原始 bundle，并完成独立人工复核。没有这一步，代码改动、文档措辞或测试数量都不能改变结论。