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
- **设备端 Agent 与平台必要对接（第一阶段）已闭环**：
  - 交付基准与证据详见 `docs/skill-dev-step10-agent-delivery.md` 及 `docs/adr/ADR-0001-device-agent-and-standard-transcoder.md`。
  - **设备端独立代码包 (`agent/zhjj_agent/`, `agent/agent.py`)**：
    - 独立设备凭据认证与已吊销拒绝（严防通过省略 did 绕过回退共享密钥）；
    - RFC 8785 JCS 规范串 + RSA-SHA256 v2 全字段验签（防操作码/设备/哈希/时效篡改）；
    - 限量下载、流式 SHA-256 复算、Zip Slip 拦截、符号链接拒绝、解压炸弹防御与 Staging 事务隔离解压；
    - 执行（`OP_EXECUTE=7`）与取消（`OP_CANCEL_EXECUTION=8`）生命周期，按 `execution_id` 独立去重，执行事实与安装事实彻底物理隔离（执行成功绝不记为 `install_record`）；
    - 本地 SQLite WAL 持久化 Journal、检查点、Outbox 补偿重试与断电恢复；
    - 首装回滚安全停用机制（无旧版时置 disabled，不虚报假恢复）；
    - 宇树 G1 EDU 机型插件规范对接（诚实标注硬件未验证 `HARDWARE_UNVERIFIED`）与离线测试全功能模拟适配器；
    - 验证：Python `unittest` 26 项单元/集成测试全量通过；
  - **平台端增量对接**：
    - 增量迁移 `2026_11_03_000001_add_v2_and_execution_to_deploy_tasks.php`（已在测试库执行）；
    - `SkillExecution` 独立事实模型；
    - `SigningService` v2 JCS 签名、`AgentController` 协议过滤门禁与 ACK/对账端点、`SkillController` 客户侧执行与取消端点；
    - 验证：`AgentV2ExecutionTest.php` 5 项全过、Deploy 全量 123 项测试全过、`ecosystem:contract-check` 契约门禁全过。
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
