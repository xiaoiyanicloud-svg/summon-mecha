# 第二阶段标准技能编译器返工交付报告

- **交付日期**：2026-11-03
- **基线提交**：`478a036`（第一阶段 Agent 返工闭环基线）
- **依据规范**：
  - `docs/adr/ADR-0001-device-agent-and-standard-transcoder.md`
  - `docs/agent-runtime-standard-transcoder-blueprint.md`
  - `docs/skill-supply-demand-audit-and-blueprint.md`
  - 《第二阶段标准技能编译器返工执行任务书》
- **受测环境**：
  - 操作系统：Windows Server / Windows 10/11
  - PHP：8.3.4 (CLI)
  - Python：3.14.4 / 3.12 兼容环境
  - 隔离测试数据库：`summon_mecha_step10_test`（MySQL 端口 3307，禁止 root 库侵入）
- **交付范围**：厂商中立标准技能包（`zhaohuan.skill/v2`）受限行为适配（P 路径）编译器、真实受限 BehaviorTree.CPP Runner / Broker、目标机型 Profile 体系、确定性构建归档与按目标交付对齐、黑盒验收套件 C-T01 ~ C-T24。

---

## 1. 对应闭环矩阵：工作包 C01–C08 与实施细则

| 工作包 | 重点改造领域 | 实施修复与防护机制 | 验证用例 |
|---|---|---|---|
| **C01**<br>资产角色与不可变对象化 | `skill_assets`<br>`skill_build_runs`<br>`skill_target_releases` | 1. `skill_assets` 引入加性字段：`asset_role`（`legacy`/`source`/`artifact`）、`asset_key`、`target_model_code`、`build_key`、`source_digest`、`review_status`；<br>2. 增加 `skill_build_runs` 表，通过 `(build_key, run_number)` 严格自增，保留每次构建不可变事实，不覆盖历史记录；<br>3. 增加 `skill_target_releases` 表，通过 `(skill_version_id, target_model_code)` 管理各机型独立审核状态（`approved`/`pending`/`rejected`/`revoked`）；<br>4. 阻断任何以 `source` 角色直发端侧的漏洞。 | C-T13<br>C-T14<br>C-T15<br>C-T16<br>C-T23 |
| **C02**<br>标准源语义与边界收敛 | `PortableSourceValidator`<br>`SourceParser` | 1. 强力抵御 XML 外部实体注入（XXE）：全面禁用 `LIBXML_DTDLOAD`、`LIBXML_DTDATTR`、`LIBXML_NOENT`，阻断 DTD、`<!ENTITY`、`xi:include` 与外部协议；<br>2. 首版控制节点严格限定为 `Sequence`，显式拒绝 `Parallel`/`Fallback`/`Repeat`/`Retry`；<br>3. 剔除伪造的未实现动作 `SM_MotionSequence`，仅允许受控节点；<br>4. 语法预算防御：树深度 $\le 16$，节点总数 $\le 128$，文件大小 $\le 512$KB；<br>5. 强类型参数校验：`cycles` 必须为纯正整数，拒绝浮点数截断（如 `"3.5"`）及非数字；<br>6. 语义交叉校验：校验 `requirements.permissions` 与动作所需权限完全覆盖，校验各节点超时总和不超过 `lifecycle.timeout_budget_ms`；<br>7. `main_tree_to_execute` 树隔离：未被引用或多余声明的树（如 `Unused`）彻底隔离，不参与生成且不影响主树；规范化计算不可变 `ir_digest`。 | C-T01<br>C-T02<br>C-T03<br>C-T04<br>C-T05<br>C-T06<br>C-T08 |
| **C03**<br>严格 IR 表达与能力绑定 | `CapabilityResolver` | 1. 将中间表示（IR）与机型 Profile 实施严格能力仲裁；<br>2. 受限动作首批支持 `SM_GestureWave`（手部、方向、摆动次数、周期限位）、`SM_AudioPlay`（音频素材路径与格式核验）、`SM_Wait`（正整数毫秒延时）；<br>3. 任意未满足能力、越界参数、或声明动作未在 Profile 注册时，抛出 `UNSUPPORTED_CAPABILITY` 明确拒绝，绝不静默放宽或冒充成功。 | C-T06<br>C-T07<br>C-T08<br>C-T11 |
| **C04**<br>机型能力 Profile 与证据收束 | `resources/skill-target-profiles/` | 1. 收敛宇树 G1 EDU（`unitree_g1.json`）、智元 A2（`agibot_a2.json`）与模拟人形（`mock_humanoid.json`）配置；<br>2. 剥离无独立算法支撑的 `SM_MotionSequence`，补全 `SM_Wait`，收束单 SKU 规格（A2 标准版）；<br>3. Profile 计算并绑定 RFC 8785 规范化 JCS SHA-256 摘要（`target_profile_digest`）；<br>4. 诚实标定 `HARDWARE_UNVERIFIED`，对齐官方 SDK2/AimDK 物理限位与通信通道。 | C-T07<br>C-T11<br>C-T13 |
| **C05**<br>真实受限 BT.CPP Runner 与 Broker 运行时 | `SemanticLowerer`<br>`runner/bt_runner.py`<br>`runner/broker.py` | 1. 目标包内生成标准运行环境：`action.py` 入口、`runner/bt_runner.py` 顺序行为树执行器、`runner/broker.py` 机型能力代理、`plan.json` 执行计划、`bindings/target_binding.json` 硬件绑定清单；<br>2. 入口严格防御：客户传入非空 `execution_params` 时立即抛出异常拒绝执行（C-T21），修复 `modelCode` 变量作用域（C-T09）；<br>3. 真实素材核验：运行时执行音频前必须真实探测素材文件是否存在（C-T10），禁止用 `print`/`sleep` 伪造假成功；<br>4. 真实取消观测：收到 `cancel_event` 时，正在运行的等待/动作立即响应中断，确认终态才释放租约（C-T12）；<br>5. 独立黑盒观测：向 `ZHJJ_BROKER_LOG_PATH` 输出结构化动作日志，供独立巡检复核。 | C-T09<br>C-T10<br>C-T12<br>C-T21 |
| **C06**<br>确定性构建、发布对象化与安全交付 | `TargetAppBuilder`<br>`PortableSkillBuilder`<br>`SkillDeliveryAssetResolver` | 1. 彻底解决 ZIP 内部时间戳与文件顺序导致的哈希漂移漏洞：统一采用 Unix 纪元时间戳 `1700000000`（`setMtimeName`），对归档文件路径按字典序键值排序（`ksort`），确保**双干净环境无缓存重新构建产出的 ZIP 最终 SHA-256 字节完全一致**（C-T17）；<br>2. 构建身份由源资产哈希、版本号与目标 Profile 摘要直接决定，与自增主键彻底解耦；<br>3. 源文件缺失时禁止从 `docs/` 或服务器全局路径兜底替代（C-T01）；源音频缺失/损坏/空文件时明确拒绝，严禁生成静音占位（C-T02）；<br>4. 交付解析加固：未获准机型、未审核目标、已撤销目标一律不可交付；严格匹配机型代码，拒绝降级错发。 | C-T01<br>C-T02<br>C-T14<br>C-T15<br>C-T16<br>C-T17<br>C-T18<br>C-T19<br>C-T20 |
| **C07**<br>黑盒验收套件 C-T01 ~ C-T24 | `PortableSkillCompilerAcceptanceTest.php` | 交付 24 项完备黑盒验收用例，覆盖源拒绝、语义分析、确定性编译、真实 Python 执行、动力学约束、生命周期与容灾对账全链路。 | 全部 24 项用例通过 |
| **C08**<br>回归质量保证与契约一致性 | 全平台回归 | 1. 平台端 Deploy 特性测试 152 项全部通过；<br>2. 平台端第一阶段测试与生态契约门禁 `ecosystem:contract-check` 全绿；<br>3. 设备端 Agent 57 项 Python 测试全绿。 | 平台 152 项<br>端侧 57 项 |

---

## 2. C-T01 ~ C-T24 黑盒验收测试结果清单

测试套件路径：[`my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerAcceptanceTest.php`](file:///d:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerAcceptanceTest.php)  
测试运行指令：`cd my-bagisto-store && vendor/bin/pest packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerAcceptanceTest.php`

| 用例编号 | 验收项名称与检验目标 | 输入条件与操作 | 实际验证结果 | 判定 |
|---|---|---|---|---|
| **C-T01** | 无 source 资产但 docs 示例存在 | 清空源文件路径，模拟仅 docs 目录下有样例 | 明确阻断构建，抛出异常，无任何示例或服务器路径兜底产物 | **PASS** |
| **C-T02** | 源引用音频缺失/损坏/空文件 | XML 引用音频，但素材为 0 字节损坏文件 | 明确拒绝构建，拒绝生成空文件或静音占位符替代 | **PASS** |
| **C-T03** | DTD/entity/include/外部路径/超限 | 输入携带 `<!ENTITY`、`xi:include`、超深（>16）树 | 拦截所有外部实体与超限树，按对应错误码抛出语义异常 | **PASS** |
| **C-T04** | Parallel/Fallback/Repeat/Retry 控制节点 | XML 中使用非 Sequence 复合控制节点 | 首版受限编译器严格拒绝，明确告知不支持此类控制节点 | **PASS** |
| **C-T05** | 主树 Main 引用，未引用 Unused 树隔离 | XML 声明主树与旁支未引用的 Unused 树 | 生成的 plan.json 与 runner 仅包含 Main 节点，未引用树彻底隔离 | **PASS** |
| **C-T06** | SM_MotionSequence 或未知未实现动作 | XML 声明伪造的未实现动作节点 | 拒绝编译，禁止将其转换为 0 步成功或空节点 | **PASS** |
| **C-T07** | wave(right, 3) 目标能力超出 | 机型不支持右手或超过其最大波幅 | 返回包含 `UNSUPPORTED_CAPABILITY` 的明确语义拒绝 | **PASS** |
| **C-T08** | 参数越界、非整数 cycles、权限少报、超时超限 | 浮点数 `"3.5"`、权限未声明或超时预算不足 | 在任何构建副作用发生前直接拒绝构建 | **PASS** |
| **C-T09** | 编译完整源并解包实际运行入口 | 针对 G1 编译产出 `.skill`，解包并由真实 Python 运行 | 修复变量作用域，无 `modelCode NameError`，成功生成真实动作轨迹日志 | **PASS** |
| **C-T10** | 音频素材在运行时丢失 | 删除解包后 assets 中的音频文件并触发执行 | 运行时真实抛出 `FileNotFoundError` 整体失败，绝不 print/sleep 冒充成功 | **PASS** |
| **C-T11** | 两个品牌 Profile 绑定相同标准源 | 分别为 G1 与 A2 触发同一标准源构建 | 两者 `source_digest` 绝对相同，但输出各机型专属不可变成品 | **PASS** |
| **C-T12** | 长延时节点取消指令测试 | 发送带有 `cancel_event` 的取消指令 | Runner 立即捕获取消信号并安全终止，释放独占硬件资源 | **PASS** |
| **C-T13** | 仅有 G1 成品，请求 A2 成品交付 | 交付解析器请求目标为 A2 的已审核成品 | 返回 `null` 拒绝交付，阻断跨机型成品错发与 source 资产直发 | **PASS** |
| **C-T14** | 未审目标成品 / 已撤销目标 | 目标机型审核状态处于 `pending` 或 `revoked` | 解析器判定不可交付，历史构建运行事实与记录完整保留 | **PASS** |
| **C-T15** | 补充新机型目标独立审核 | 补充 A2 目标审核为 approved | G1 旧审核状态与产物 SHA-256 绝对不变，各机型版本独立管理 | **PASS** |
| **C-T16** | 同源同目标重复重新构建 | 对同一标准源再次触发构建 | 不覆盖历史构建记录，自增创建 `run_number = 2` 的新构建运行 | **PASS** |
| **C-T17** | 两个干净无缓存环境构建确定性 | 清理一切中间产物，在两个独立目录重建成品 | 生成的 ZIP 归档最终字节 SHA-256 绝对完全一致（100% 确定性） | **PASS** |
| **C-T18** | 修改标准源素材内容 | 修改 XML 中文本或音频素材字节 | 重新构建产生的 `source_digest` 与目标成品哈希完全改变 | **PASS** |
| **C-T19** | 旧 motion/composite 格式构建路由 | 传入 `source_format !== 'btcpp/xml4'` 的旧技能 | 自动路由至既有旧构建器（`MotionCarrierBuilder`），不强行套用编译器 | **PASS** |
| **C-T20** | 打包后篡改文件哈希防线 | 修改已打包成品内字节，导致哈希不一致 | 交付解析校验与端侧下载验签直接判定损坏并拒绝 | **PASS** |
| **C-T21** | 获准成品执行时客户传入额外 params | 执行请求中传入非法覆盖参数 | 包装入口强制检查 `execution_params`，非空时抛出异常拒绝越权修改 | **PASS** |
| **C-T22** | 轨迹角度合规但角速度/加速度超包络 | 生成超频高急动度余弦曲线 | 动力学安全约束核验失败，抛出超限异常阻断构建 | **PASS** |
| **C-T23** | 全生命周期身份贯穿测试 | 上传 -> 解析 -> 构建 -> 审核 -> 发布 -> 交付 | 统一身份（`asset_key`, `build_key`, `target_profile_digest`）贯穿全链路 | **PASS** |
| **C-T24** | 熔断关闭新构建时取消与事实可达 | 打开平台熔断开关（新任务阻断） | 阻断新构建与新下发，但历史取消任务与对账事实接口依然可用 | **PASS** |

**验收统计**：24 项黑盒测试全部通过（**PASS: 24, FAIL: 0, BLOCKED: 0**，共 74 项精确断言）。

---

## 3. 全平台回归与契约验证结果

### 3.1 平台端 Pest / Deploy 特性测试套件
- **执行指令**：`cd my-bagisto-store && vendor/bin/pest packages/Webkul/Shop/tests/Feature/Deploy`
- **执行结果**：**152 passed (5,714 assertions)**，耗时 25.36s。
- **包含专项**：
  - `PortableSkillCompilerAcceptanceTest.php`：24 项全部通过；
  - `PortableSkillCompilerTest.php`：5 项全部通过（5,116 项断言）；
  - `SkillFullChainEndToEndTest.php`：开发者上传 -> 平台审核 -> 交付 Agent 验签解包全链路通过；
  - `AgentPackageDistributionTest.php`、`DeployTaskStatusP8Test.php`、`GrayRollbackTargetTest.php`、`DeviceLeaseTimeoutTest.php` 等 123 项既有测试 100% 回归。

### 3.2 契约门禁检查
- **执行指令**：`cd my-bagisto-store && php artisan ecosystem:contract-check`
- **执行结果**：
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

### 3.3 设备端 Agent 测试套件 (Python 3.14/3.12)
- **执行指令**：`cd agent && py -m unittest discover -s tests -p "test_*.py"`
- **执行结果**：**Ran 57 tests, OK**，耗时 12.63s（含 A-T01 ~ A-T26 独立黑盒测试与 Live HTTP 隔离集成测试）。

---

## 4. 四级完成度验收严格评定

依据工程纪律与真实证据原则，完成等级划分并报告如下：

| 完成等级 | 判定状态 | 详细说明与客观证据 |
|---|---|---|
| **软件完成** (Software Complete) | **PASS** | 1. 编译器核心逻辑（格式验证、安全检查、IR 提取、能力绑定、降阶代码生成、确定性打包）全部落地；<br>2. 产出的成品归档包通过真实 Python 运行时完整执行，生成独立观测日志，通过 C-T01 ~ C-T24 验收；<br>3. 双环境重新构建哈希绝对一致，全套 152 项 Deploy 测试与 57 项端侧测试全量绿灯通过。 |
| **SDK 接入完成** (SDK Integrated) | **PARTIAL** | 1. 机型能力 Profile（宇树 G1 / 智元 A2）已完全对齐官方 SDK2 (`unitree_sdk2py`) 与 AimDK 的关节物理限位、自由度模型与 DDS 域通信配置；<br>2. 设备端适配器真实支持 SDK 驱动接入与 stub 契约调用；<br>3. 物理真机环境下的私有二进制动态库及机载 DDS 通信尚需机载环境验证。 |
| **真机完成** (Hardware Verified) | **HARDWARE_UNVERIFIED** | 1. **严守安全红线**：当前开发宿主机为非机载环境，本轮严禁触发任何未受控的真实物理电机动作；<br>2. 设备端适配器与成品 Broker 诚实标定状态为 `HARDWARE_UNVERIFIED`，严禁用 print/sleep 虚构动作成功；<br>3. 硬件在环测试（HIL）需在后续专用机器人实验场开展。 |
| **生产可交付** (Production Ready) | **BLOCKED** | 1. 当前隔离验证指向独立库 `summon_mecha_step10_test`，生产数据库尚未做加性迁移部署；<br>2. 商业授权规则（D1–D11 商业试用策略）、真实生产数字证书与私钥签名尚未配置；<br>3. 需待真机 HIL 验收通过并由业务负责人确认后方可投产。 |
