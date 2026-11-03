# 第二阶段：标准技能编译器与动作转码器交付报告

- 交付日期：2026-11-03
- 交付工程师：AI 协作工程师（Antigravity）
- 依据标准：`docs/adr/ADR-0001-device-agent-and-standard-transcoder.md`、`docs/agent-runtime-standard-transcoder-blueprint.md`
- 关联闭环：第一阶段设备端 Agent（`agent/zhjj_agent/`，Commit `d9bf669`）

---

## 1. 交付概览

本轮开发全面落地了**第二阶段：标准技能编译器与动作转码器（Standard Skill Compiler and Action Transcoder）**，使召唤机甲平台具备将厂商中立的标准技能包（`zhaohuan.skill/v2`，基于 BehaviorTree.CPP XML4 规范）针对具体目标机器人机型编译、降阶并转码为安全可执行成品（`.skill`）的闭环能力。

### 1.1 核心架构与落地组件

1. **真实机型 Profile 快照体系 (`my-bagisto-store/resources/skill-target-profiles/`)**：
   - 调研并接入宇树 G1 官方 SDK (`unitree_sdk2py` DDS 域、23/29 自由度模型、全关节物理弧度安全范围)；
   - 包含宇树 G1 EDU (`unitree_g1.json`)、智元 A2 (`agibot_a2.json`) 与离线模拟人形 (`mock_humanoid.json`)；
   - 统一遵循 `zhaohuan.target-profile/v1` 契约，支持规范化 JCS SHA-256 摘要（`target_profile_digest`）计算。

2. **源包安全校验与防护 (`PortableSourceValidator.php`)**：
   - 清单校验：强制检验 `zhaohuan.skill/v2` 规范、`runtime.source_format = "btcpp/xml4"`、`safety` 风险等级与最大执行时长；
   - 行为树 XML 校验：禁用外部实体解析（XXE 防御）、限制树深度（<= 16）与节点总数（<= 128）；
   - 节点白名单审查：只允许已受控动作节点（`SM_GestureWave`、`SM_AudioPlay`、`SM_MotionSequence` 等）。

3. **行为树语法分析与 IR 提取 (`SourceParser.php`)**：
   - 将 XML 行为树降解为语义中间表示（Intermediate Representation, IR）；
   - 对 IR 规范化计算不可变 `ir_digest`，确保编译构建身份绝对可追溯。

4. **机型能力仲裁与匹配 (`CapabilityResolver.php`)**：
   - 对齐 IR 声明节点与目标机型 Profile 硬件/SDK 能力（权限声明、手部支持、最大周期、超时时间等）；
   - 明确拒绝能力不足的机型下发，给出详细的未兼容原因。

5. **物理空间动作转码器 (`MotionTranscoder.php`)**：
   - 实现高阶平滑余弦插值（cosine wave interpolation），支持 30Hz 采样率；
   - 起止点速度自然归零，全轨迹严格裁剪在硬件安全角度范围内（弧度制）。

6. **目标语义降阶与代码生成 (`SemanticLowerer.php` & `TargetAppBuilder.php`)**：
   - 降阶生成可独立运行的目标执行脚本 `action.py` 及配套轨迹文件 `data/*.json`；
   - 严守 D6 责任边界：打包为安全 ZIP 格式，嵌入 `manifest.json` 与必要音频占位符；
   - 登记至 `skill_assets` 表，计算递增 `sort_order`（严格遵守 `uk_skill_assets_version_carrier` 唯一性）；
   - 与 `SkillBuildService`、`PortableSkillBuilder` 和 `SkillDeliveryAssetResolver` 无缝对齐。

---

## 2. 自动化验证证据

### 2.1 平台端编译器与交付物解析测试 (PHP / Pest)
执行命令：
```bash
php artisan test packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerTest.php
```
结果：
- 5 项测试全部通过，共 3,850 项断言：
  - `TargetProfileService 能够加载各机型 Profile 并计算规范化 JCS 摘要`：PASS
  - `PortableSourceValidator 严格校验清单格式与行为树 XML 白名单及安全边界`：PASS
  - `SourceParser 正确将 BehaviorTree XML4 转换为结构化 IR 与不可变摘要`：PASS
  - `MotionTranscoder 为 Unitree G1 生成平滑且在物理限位内的关节轨迹`：PASS
  - `PortableSkillBuilder 成功编译标准源为 Unitree G1 目标成品并打通交付解析`：PASS

### 2.2 平台端 Deploy 全量测试
执行命令：
```bash
php artisan test packages/Webkul/Shop/tests/Feature/Deploy
```
结果：
- **128 项测试全量通过**，共 4,374 项断言。

### 2.3 契约门禁检查
执行命令：
```bash
php artisan ecosystem:contract-check
```
结果：
- 契约检查通过（45 contracted routes, 85 error codes, 11 state inventories）。

### 2.4 设备端 Agent 端到端全链路测试 (Python / unittest)
执行命令：
```bash
$env:PYTHONPATH="agent"; py -m unittest discover -s agent/tests -p "test_*.py" -v
```
结果：
- **27 项测试全量通过**（新增编译产出标准技能包在 Unitree G1 适配器上的端到端部署与执行测试）：
  - 任务验签、下载、Staging 隔离解包；
  - 技能安装、启用、执行（`OP_EXECUTE=7`）、独占互斥租约、安全物理停止与对账；
  - 27/27 OK。

---

## 3. 硬件真实性诚实披露 (HARDWARE_UNVERIFIED)

依据项目原则与 ADR-0001 纪律：
- 宇树 G1 EDU 适配器中的硬件执行状态如实标为 `HARDWARE_UNVERIFIED`（当前测试机宿主为非机载环境，无物理连接机器人真机）；
- 软件沙箱、SDK2 接口守卫、DDS 域配置及安全限位均已按照官方文档严格对齐，真机插入时可平滑过渡。
