# WBS 无外部硬件依赖工作包 · 第十一批交付（2026-11-05）

> 范围：**A1 设备侧自更新 · A2 健康度/资源上报 · A4 声明式目标状态 ·
> A5 执行前授权回源 · C1 应用类载体构建器 · C2 KMS/HSM 抽象层（本地后端）**。
> 环境：PHP 8.4.24 / Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）/ Windows 11。
> 本批**不接**任何外部系统：不接云 KMS、不接厂商 SDK、不接真机、不起 MQTT。

## 0. 结论

- **A1 · 设备侧自更新：已实现并验证**（`zhjj_agent/update.py` + `launcher.py`，11 项单测全过）。
  状态机、签名/哈希/时钟/版本单调、流式下载、版本目录 + 指针切换、启动自检失败自动回滚
  均按第十批提示词落地；`is_safety_evidence` 类不变量未受影响。
- **A2 · 健康度/资源上报：已完成**。设备端 `HealthSampler` 采电池/温度/CPU/内存/磁盘/运行时长/
  错误码趋势（**只收 4 个失败信号键**，正常计数如 `command_received_total` 不算错误），
  **采不到给 None，绝不编造 0**；平台只收白名单字段、越界 422、错误计数键必须是失败键、
  快照按覆盖语义写 `binding.meta.health`。
- **A5 · 执行前授权回源：已完成**。新接口 `POST /api/agent/entitlement/check` 返回稳定
  `reason`（DEVICE_NOT_BOUND / NO_ENTITLEMENT / VERSION_MISMATCH / REVOKED / EXPIRED / NOT_PAID）；
  设备端 `EntitlementGuard` 按 TTL 缓存，平台不可达只在最大陈旧窗口内使用缓存，超窗 fail-closed。
  开关 `ZHJJ_ENTITLEMENT_CHECK` **默认关闭**（项目纪律）；打开后在查库/占租约/调适配器之前生效。
- **A4 · 声明式目标状态：已完成（含受控自动收敛）**。新接口
  `GET /api/agent/desired-state` 计算 desired（已支付 + 有效 + 已发布版本）与 actual（install_record），
  输出 missing / version_mismatch / extra；`desired_state.auto_converge` **默认关闭**，
  打开也只对"声明 agent 通道 + 有包哈希"的缺失项建安装任务，且由 `DeployTaskService` 幂等去重；
  **不自动卸载 extra**（卸载不可逆，必须显式操作）。
- **C1 · 应用类载体构建器：已完成**。`AppCarrierBuilder` 按五个 GA 机型装配确定性 ZIP：
  宇树 `app/ + metadata.yaml + build.sh`、智元 `resources/ + rpc_manifest.json + install.sh`、
  众擎 `plugin/ + runner.json + install.sh`；拒绝路径穿越、缺入口、顶替平台脚本、非 GA 机型。
  **诚实边界：只装配，不构建 ARM64 镜像、不调用厂商 SDK/RPC**（`requires_external_build=true`）。
- **C2 · KMS/HSM 抽象层：已完成（仅本地后端）**。新增 `AsymmetricSigner` /
  `CertificateAuthoritySigner` 两个契约 + 本地 PEM 实现 + `KeyBackendManager`；
  本地签名与既有 `SigningService` **逐字节等价**，CA 签出的证书可链到平台 CA。
  `KEY_BACKEND` 默认 `local`；`kms`/`hsm` 未实现且**直接抛异常，不静默回落**。
- 回归：**PHP 49 个文件 / 370 项 / 7214 断言全绿**（上一批 44/336/7024）；
  **Python 160 项 OK（1 skipped = ENOSPC）**；`php -l` 19 个文件 OK；契约门禁（45 路由）与数据 preflight 通过。
  **本批无迁移**。
- 全量门禁（T7，分文件）复跑：**319 文件 / 2256 通过 / 49 个失败文件 / 19598 断言 / 2313s**；
  失败全部是隔离库播种稀薄 + Admin 本地化编码的**既存环境问题**（与本批无关）。
  ⚠ 该全量门禁在**本批代码落地前**启动，**不包含本批 5 个新测试文件**；本批的验收以 49 文件定向回归为准。

## 1. 改动清单

### A1 · 设备侧自更新（已存在，本批复验）

| 文件 | 说明 |
|---|---|
| `agent/zhjj_agent/update.py` | 状态机 / 验签 / 流式下载 / 版本目录 / 指针切换 / 回滚 |
| `agent/zhjj_agent/launcher.py` | 读 `current.json`、启动自检、失败计数与回滚 |
| `agent/zhjj_agent/main.py` | `--self-check` / 版本上报接入 |
| `agent/tests/test_self_update.py` | 11 项（断流、换包、重放、切换失败、回滚一次） |

> 本批未改 A1 逻辑，只重新执行其单测确认仍全绿。

### A2 · 健康度

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `agent/zhjj_agent/health.py` | `HealthSampler`：7 类探针，失败一律 None | 新增 |
| `agent/zhjj_agent/client.py` | `heartbeat(health=...)` | 修改 |
| `agent/zhjj_agent/main.py` | 每次心跳携带健康快照 | 修改 |
| `agent/tests/test_health.py` | 8 项 | 新增 |
| `Services/Deploy/DeviceHealthService.php` | 白名单/闭合键/覆盖语义/`meta.health` | 新增 |
| `Http/Controllers/API/AgentController.php` | heartbeat 校验 + 落库 | 修改 |
| `tests/Feature/Deploy/AgentHealthReportTest.php` | 5 项 | 新增 |

### A5 · 授权回源

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/Deploy/AgentEntitlementService.php` | 6 类稳定拒绝原因，只读 | 新增 |
| `Http/Controllers/API/AgentController.php` | `entitlementCheck()` | 修改 |
| `Routes/api.php` | `POST api/agent/entitlement/check` | 修改 |
| `agent/zhjj_agent/entitlement.py` | `EntitlementGuard` + TTL/最大陈旧窗口 | 新增 |
| `agent/zhjj_agent/storage.py` | `entitlement_cache` 表 + 读写方法 | 修改 |
| `agent/zhjj_agent/executor.py` | `ZHJJ_ENTITLEMENT_CHECK` 闸门（默认关） | 修改 |
| `agent/zhjj_agent/task_manager.py` / `main.py` | 透传 guard | 修改 |
| `tests/Feature/Deploy/AgentEntitlementCheckTest.php` | 8 项 | 新增 |
| `agent/tests/test_entitlement.py` | 8 项 | 新增 |

### A4 · 声明式目标状态

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/Deploy/DesiredStateService.php` | desired/actual/drift/generation + 受控收敛 | 新增 |
| `Http/Controllers/API/AgentController.php` | `desiredState()` | 修改 |
| `Routes/api.php` | `GET api/agent/desired-state` | 修改 |
| `config/deploy_gateways.php` | `desired_state.auto_converge`（默认 false） | 修改 |
| `agent/zhjj_agent/desired_state.py` | 设备侧本地 drift 计算（只读） | 新增 |
| `agent/zhjj_agent/client.py` / `main.py` | 拉取与定时对齐 | 修改 |
| `tests/Feature/Deploy/AgentDesiredStateTest.php` | 6 项 | 新增 |
| `agent/tests/test_desired_state.py` | 4 项 | 新增 |

### C1 · 应用类载体

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/SkillCompile/Builders/AppCarrierBuilder.php` | 五机型确定性装配 + 安全拒绝 + 资产登记 | 新增 |
| `config/skill_builders.php` | 注册 builder | 修改 |
| `tests/Feature/Deploy/AppCarrierBuilderTest.php` | 10 项 | 新增 |

### C2 · KMS 抽象

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/Deploy/Contracts/AsymmetricSigner.php` | 签名契约 | 新增 |
| `Services/Deploy/Contracts/CertificateAuthoritySigner.php` | CA 契约 | 新增 |
| `Services/Deploy/Signing/LocalAsymmetricSigner.php` | 本地 PEM 实现 | 新增 |
| `Services/Deploy/Signing/LocalCertificateAuthoritySigner.php` | 本地 CA 实现 | 新增 |
| `Services/Deploy/Signing/KeyBackendManager.php` | 后端选择（未知即抛） | 新增 |
| `Services/Deploy/DeviceCredentialService.php` | 追加 `signCsr()`（不改进现有 issue 路径） | 修改 |
| `config/deploy_gateways.php` | `key_backend`（默认 local） | 修改 |
| `tests/Feature/Deploy/KeyBackendContractTest.php` | 5 项 | 新增 |

### 测试门禁修正

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `tools/pest-all.ps1` | 默认注入 `ZHJJ_PYTHON_BIN`（与 `run-b10-regression.ps1` 一致），消除编译器验收 4 条假红；全量门禁失败文件 50 → 49 | 修改 |

## 2. 证据

| CLAIM_ID | 声明 | 命令 / 退出码 | 原始输出 / SHA256（前 8） |
|---|---|---|---|
| A1-T | 自更新 11 项全过 | `python -m unittest tests.test_self_update` / 0 | [b11-python-regression.txt](qa/wbs-20261105/b11-python-regression.txt) `F5589DF3…` |
| A2-T | 健康采样 8 项 + 平台 5 项 | `python … tests.test_health` / 0；`pest AgentHealthReportTest` / 0 | 同上；[b10-php-regression.txt](qa/wbs-20261105/b10-php-regression.txt) `0F0F1AF6…` |
| A5-T | 平台 8 项 + 设备 8 项 | `pest AgentEntitlementCheckTest` / 0；`python … tests.test_entitlement` / 0 | 同上 |
| A4-T | 平台 6 项 + 设备 4 项 | `pest AgentDesiredStateTest` / 0；`python … tests.test_desired_state` / 0 | 同上 |
| C1-T | 五机型装配 + 安全拒绝 + 确定性 10 项 | `pest AppCarrierBuilderTest` / 0 | 同上 |
| C2-T | 本地签名等价、CA 链验证、capabilities、未知后端 5 项 | `pest KeyBackendContractTest` / 0 | 同上 |
| REG | **49 文件 / 370 项 / 7214 断言全绿** | `run-b10-regression.ps1` / 0 | [b10-php-regression.txt](qa/wbs-20261105/b10-php-regression.txt) `0F0F1AF6…` |
| PY | **160 项 OK，1 skipped** | `python -m unittest discover -s tests` / 0 | [b11-python-regression.txt](qa/wbs-20261105/b11-python-regression.txt) `F5589DF3…` |
| LINT | 19 个 PHP 文件全 OK | `php -l <19 files>` / 0 | [b11-php-lint.txt](qa/wbs-20261105/b11-php-lint.txt) `E856BF4F…` |
| GATE | 契约检查通过（45 路由 / 85 错误码） | `artisan ecosystem:contract-check` / 0 | [b11-contract-check.txt](qa/wbs-20261105/b11-contract-check.txt) `23139722…` |
| PREFLIGHT | 数据 preflight 通过 | `artisan ecosystem:data-preflight` / 0 | [b11-data-preflight.txt](qa/wbs-20261105/b11-data-preflight.txt) `DE3718AA…` |
| ROUTE | 新路由已注册 | `artisan route:list --path=agent` / 0 | [b11-route-list-agent.txt](qa/wbs-20261105/b11-route-list-agent.txt) `C225E49E…` |
| FULL-GATE | 全量分文件（本批前）：319 文件 / 2256 通过 / 49 失败文件 / 19598 断言 / 2313s | `tools/pest-all.ps1` / exit=49 | [summary.txt](qa/wbs-20261105/logs-b10/pest-all/summary.txt) `A15B8A61…` |
| RG | 3 处变异 red→green（A2/A5/C1） | `/1 → 0` | [red-green-b11.txt](qa/wbs-20261105/red-green-b11.txt) `2B1BA2CD…` |

## 3. 关键设计决定（为什么这么做）

| 主题 | 决定 | 理由 |
|---|---|---|
| A2 "采不到" | 返回 None，不返回 0 | 0 会被读成"电量 0% / 温度 0℃"，比未知更危险 |
| A2 错误计数 | 只接受 4 个失败信号键（验签失败/哈希不符/租约冲突/outbox 失败） | 否则错误码趋势会混入正常计数，告警口径失真 |
| A5 缓存 | TTL 300s；平台不可达最多用 900s 陈旧缓存；超窗 fail-closed | 可用性与防"拔线绕过"的折中，窗口写死 |
| A4 desired | 只含"已支付 + 有效 + 已发布" | 未支付/草稿版本进 desired 会造成"授权了但发不出货" |
| A4 收敛 | 默认关；只补缺失，不删 extra | 卸载不可逆；自动删除会让用户设备"技能突然消失" |
| C1 产物 | 只装配厂商载体目录 + 构建/安装脚本 | 平台没有厂商 SDK/ARM64 构建环境；伪造"已构建"比不做更糟 |
| C1 唯一键 | 按机型固定 `sort_order`（1–5） | 现有唯一键是 `(version, carrier, sort_order)`，不区分机型 |
| C2 后端 | 未知 `KEY_BACKEND` 直接抛 | 静默回落本地明文私钥 = "以为上了 KMS" |

## 3.5 red → green（3 处）

| 变异 | 观察 |
|---|---|
| A2 健康快照不落库 | **A2-H-01 红**（meta 里没有 health） |
| A5 去掉订单支付闸 | **A5-E-04 红**（未支付授权被放行） |
| C1 去掉路径穿越校验 | **C1-A-02 红**（`../evil.py` 被装配） |

全部从 `.claude/backup/b11-0N/` 逐字节还原，哈希一致；`grep "b11 mutation"` 为 0。
详见 [red-green-b11.txt](qa/wbs-20261105/red-green-b11.txt)。

## 4. 明确未做 / 边界

| 项 | 状态 | 说明 / 最小解除条件 |
|---|---|---|
| MQTT/EMQX 常驻通道 | **未做（外部）** | 需 EMQX 与真机 |
| 三厂专用部署动作（Docker load / RPC 同步 / rsync+ROS2） | **未做（外部）** | 需厂商 SDK/真机；C1 只产出载体与脚本 |
| 真机 HIL / 五机型 GA | **BLOCKED** | 外部硬件 |
| C1 的实际 ARM64 镜像 / RPC 同步 / ROS2 启动 | **未做** | 产物里 `requires_external_build=true` 如实标注 |
| A4 自动卸载 extra | **刻意不做** | 卸载不可逆，必须显式操作 |
| A4 全量自动收敛 | **默认关闭** | 打开需运营确认；当前只补缺失且要求 agent 通道 + 包哈希 |
| A5 平台不可达时的强一致 | **不可达** | 设备离线无法回源；当前按陈旧窗口 fail-closed |
| C2 云 KMS / 硬件 HSM | **未接入** | T10 报告的三项拍板（CSR-up / SoftHSM vs 云 / X.509 签发方式） |
| 首装引导与弱口令加固（A3） | **未做** | 需真机与人工确认，不属本批 |
| 全量门禁的 49 个环境失败 | **NOT_RUN/环境** | 隔离库播种稀薄 + Admin 编码；与本批无关 |

## 5. 环境级风险

1. `.env` 的 `DB_DATABASE` 仍指向 `root`；本批命令全部显式注入隔离库。
2. 全量门禁的 49 个失败文件是**既存环境问题**（播种/编码），不是本批回归；
   本批新增测试已由 49 文件定向回归覆盖。
3. Python 全量套件在机器高负载时 `test_a6_07`（进程树杀死）偶发超时；单独复跑通过。
   这是测试时序问题，不是沙箱实现回归。
4. 新增两个 Agent 路由与一个配置键；`KEY_BACKEND`/`ZHJJ_ENTITLEMENT_CHECK`/
   `DESIRED_STATE_AUTO_CONVERGE` 均**默认关闭/不变**。
5. 健康快照存 `binding.meta.health`，未加索引/聚合；大规模设备查询健康度需要后续物化视图或列。

## 6. 回滚

本批**无迁移**。

1. 删除新增文件：`health.py`、`entitlement.py`、`desired_state.py`、
   `DeviceHealthService.php`、`AgentEntitlementService.php`、`DesiredStateService.php`、
   `AppCarrierBuilder.php`、两个 Contracts、两个 Signing 实现、`KeyBackendManager.php`，
   以及 5 个新 Pest 测试与 3 个新 Python 测试。
2. 还原修改文件：`AgentController.php`、`Routes/api.php`、`config/deploy_gateways.php`、
   `config/skill_builders.php`、`DeviceCredentialService.php`、`client.py`、`storage.py`、
   `executor.py`、`task_manager.py`、`main.py`。
3. 数据：删除 `entitlement_cache` 表（设备端 SQLite）；`binding.meta.health` 为 JSON，随绑定数据保留，
   不回滚不影响业务。
4. 回滚后重跑本文件 §2 命令。

## 7. 等级（本批结束时）

- 软件公共底座：**PARTIAL** —— 6 项无外部依赖功能已落地并有测试；外部验证仍缺。
- Agent：**PARTIAL**（自更新/健康/授权回源/目标状态已实现；真机与三厂部署动作未做）。
- Compiler：**PARTIAL**（应用类载体可装配；真实镜像/RPC/ROS2 未做）。
- KMS/HSM：**PROVEN_LOCAL（仅本地 PEM/SoftHSM 演练）**；生产 KMS/硬件 HSM **BLOCKED**。
- 当前 SIL：**NOT_RUN**；HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**；五机型 GA：**BLOCKED**。
