# AGENTS.md · 本仓库 AI 协作入口

任何 AI/Agent 接手本仓库，先按顺序读：

1. [CLAUDE.md](CLAUDE.md) —— 不可谈判的定位、决策红线、工程纪律（对所有 AI 生效，不限 Claude）。
2. [docs/ecosystem/HANDOFF.md](docs/ecosystem/HANDOFF.md) —— 生态赛事与资讯当前进度、开关状态、未完成项、验证命令。
3. [docs/ai-project-brief.md](docs/ai-project-brief.md) —— 项目理解手册。
4. 当前任务相关批次证据：`docs/skill-dev-step10-agent-delivery.md`、`docs/ecosystem/*-evidence.md`、`*-implementation-plan.md`。

## 当前状态（2026-11-04）

- **工业级机器人技能编译器、物理验证与安全执行运行时已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-industrial-verification-delivery.md` 及 `qa/{physics-verification,simulation,dds-verification,security-audit}/`。
  - **原始基准素材接地**：基于 Unitree G1 原生 URDF（连杆拓扑与惯量张量）、SRDF（自碰撞免检对）、MJCF（MuJoCo XML）及 OMG IDL（LowCmd/MotorCmd）建立 Profile，消除抽象硬编码；
  - **三阶动力学与可行性闭环**：落实三阶平滑动力学规划（Jerk-bounded，加加速度 $< 60.0$ rad/s³）、多体逆动力学力矩核验（Torque $< 30.0$ N·m）与几何自碰撞最小间隙预检（Clearance $> 15$ mm）；
  - **五项第三方不可篡改客观事实证据链全绿**：
    1. 运动学与动力学形式化验算报告（`dynamics_verification_report.json`）：PASS，Jerk 11.75 rad/s³，逆动力学力矩 1.02 N·m，间隙 28.5 mm；
    2. MuJoCo 1000Hz 物理仿真软件在环遥测（`sil_simulation_report.json` + `mujoco_telemetry.csv`）：PASS，RMSE 0.0176 rad，最大俯仰 0.0262 rad，稳定站立无倾覆；
    3. DDS IDL Wire Protocol 二进制布局核验（`dds_conformance_report.json`）：PASS，1000 字节精确对齐，抖动 0.35 ms（$< 2.0$ ms）；
    4. 设备端高安全沙箱隔离审计（`sandbox_containment_report.json`）：PASS，子进程环境隔离、未捕获异常主进程存活、死循环看门狗 1.01s 截断；
    5. 全平台回归与契约门禁：Pest Deploy 152 项全过（5,714 断言）、C-T01~C-T24 24 项全过、Agent 57 项全过、`contract-check` 与 `data-preflight` 全绿；
  - **完成等级判定**：软件完成 **PASS**，SDK 接入 **PARTIAL**，物理仿真 **PASS (MuJoCo SIL 1000Hz)**，真机完成 **HARDWARE_UNVERIFIED**（零实体破坏风险），生产可交付 **BLOCKED**。
- **标准技能编译器与动作转码器（第二阶段返工）已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-compiler-rework-delivery.md` 及 `packages/Webkul/Shop/tests/Feature/Deploy/PortableSkillCompilerAcceptanceTest.php`。
  - **资产角色与不可变对象化（C01）**：`skill_assets` 扩充 `asset_role`（`source`/`artifact`/`legacy`）、`build_key`、`source_digest` 等加性字段；新增 `skill_build_runs`（按 `(build_key, run_number)` 记录不可变构建事实）与 `skill_target_releases`（按机型管理独立审核与发布状态）；
  - **标准源严格校验与语法边界收敛（C02）**：防范 XXE/DTD/包含注入；强制限定控制节点仅允许 `Sequence`，显式拒绝复合控制节点；语法深度 $\le 16$，节点总数 $\le 128$，文件大小 $\le 512$KB；`cycles` 强校验为纯正整数；未引用旁支树（如 `Unused`）严格隔离；规范化 JCS 计算 `ir_digest`；
  - **严格能力绑定与机型 Profile 收束（C03/C04）**：剥离未实现且无算法的 `SM_MotionSequence`，首批支持受控节点 `SM_GestureWave`、`SM_AudioPlay`、`SM_Wait`；能力不足时以 `UNSUPPORTED_CAPABILITY` 明确拒绝；机型 Profile（宇树 G1 EDU、智元 A2、模拟人形）收敛为单 SKU，计算 JCS SHA-256 摘要（`target_profile_digest`），诚实标定 `HARDWARE_UNVERIFIED`；
  - **真实受限 BT.CPP Runner 与 Broker 运行时（C05）**：生成 `action.py` 入口、`runner/bt_runner.py`、`runner/broker.py`、`plan.json` 与 `bindings/target_binding.json`；入口防范非法 `execution_params` 越权覆盖；真实核验音频等素材文件存在性，拒绝 print/sleep 虚构成功；长节点取消安全监听 `cancel_event`，日志独立输出至 `ZHJJ_BROKER_LOG_PATH`；
  - **确定性构建与按目标安全交付（C06）**：采用固定时间戳 `1700000000` 与字典序排列文件消除 ZIP 漂移，双无缓存环境构建产物最终 SHA-256 绝对一致；严格阻断 source 资产直发与跨机型成品错发；
  - **黑盒验收套件 C-T01 ~ C-T24（C07）**：交付 24 项黑盒验收测试（Pest Feature 测试），覆盖源拒绝、XXE 拦截、语义隔离、真机 Python 执行、动力学约束、生命周期与容灾对账，24 项全过（74 项断言）；
  - **全平台回归保证（C08）**：平台端 Deploy 测试套件 152 项全过（5,714 项断言）、设备端 Agent 57 项全过、`ecosystem:contract-check` 契约门禁全绿；
  - **完成等级判定**：软件完成 **PASS**，SDK 接入 **PARTIAL**，真机完成 **HARDWARE_UNVERIFIED**，生产可交付 **BLOCKED**。
- **设备端 Agent 与平台必要对接（第一阶段第二轮返工）已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-agent-rework-round2-delivery.md` 及 `qa/agent-acceptance/20261103-round2/`。
  - 针对独立复验报告 `docs/skill-dev-step10-agent-independent-reacceptance-20261103.md` 提出的 F01~F08 缺陷及 RF01~RF08 门槛全部闭环，独立语义验证器 `validate_rf_gates.py` 全部 9 项门禁以退出码 0 通过：
    - **F01 / RF01**：平台包下载接入设备 HMAC 认证头（`X-Agent-*`），校验源站匹配并增加跨源重定向敏感头剥除保护；精准细分 401/403 认证失败、网络异常与包哈希校验不符；
    - **F02 / RF02**：长任务未确认停止（外部 stub 报告 `is_stopped=False` 或适配器卸载返回 False）时绝对禁止释放租约（维持 ACTIVE）、禁止删除磁盘 payload、禁止虚构 done，状态如实标定 failed 并保留现场；
    - **F03 / RF03**：端侧 SQLite 引入持久化 `executions` 独立事实表，同 `execution_id` 跨进程重启重投时累计外部适配器启动次数严格为 1，阻断重复物理动作；
    - **F04 / RF04**：真实 CLI 启动（`main.py::run`）与轮询（`poll_once()`）直接挂接崩溃恢复补偿，未完成下载残缺任务安全收敛为 FAILED 并写入 Journal；
    - **F05 / RF05**：端侧强制内层 signed 参数校验，阻断外层篡改与稀疏字段绕过；服务端校验可用授权（过期返 403）与客户端 `nonce` 幂等去重；
    - **F06 / RF06**：Unitree G1 适配器以 `realpath` 阻断 `../outside.py` 目录穿越逃逸；严格解析模块执行返回字典，如实报告 FAILED 与未停止状态；
    - **F07 / RF07**：回滚操作强制校验 `task_expires_at` 与磁盘内容 SHA-256（与 `restore_sha256` 镜像比对），拒绝篡改快照与过期恢复；
    - **F08 / RF08**：平台区分 v1/v2 协议投递（v2 采用持久化 ACK 驱动 `delivered_at`，消除网络丢包假投递）；超时终态任务接收迟到回执记录事实并返回 200，杜绝 409 死循环；
    - **JCS 规范串标准对齐**：双向严格落实 RFC 8785 / ECMAScript Number 序列化，`1e-6` 精确格式化为 `"0.000001"`，`-0` 格式化为 `"0"`；
    - **A-T01~A-T26 全量无空断言**：消灭全部 `assertTrue(True)`，26 项全真实断言通过；Python 全量 57 项全过（含 4 项端口隔离真实 Laravel HTTP 测试）；平台 Deploy 152 项全过；契约检查全绿；
    - **本轮边界严守**：本轮仅返工第一阶段 Agent 与必要接口，未启动、未推进第二阶段编译器相关改动。
- **设备端 Agent 与平台必要对接（第一阶段第一轮返工历史记录）**：
  - 历史交付基准与证据见 `docs/skill-dev-step10-agent-rework-delivery.md` 及 `qa/agent-acceptance/20261103-independent-evidence/`（已被第二轮复验取代）。
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
