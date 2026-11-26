# WBS 无外部硬件依赖工作包 · 首轮基线审计与开工报批

- 日期：2026-11-05
- 工作目录：D:\main（Windows / PowerShell）
- 本轮性质：**只读审计 + 基线取证**。未改业务代码、未跑 DB 回归、未触碰 root 库、未动 git。
- 结论：当前仓库是 step10 成熟基线；本 WBS 命中的是**真实缺口**，不是重复劳动。A1/A2/A7 与 B1/B2 存在代码级硬缺口，需按优先级返工。

## 1. 基线事实（本轮实测）

| 项 | 实测值 | 备注 |
|---|---|---|
| PHP | 8.4.24 (NTS) | 目标 8.3 未安装，**偏差已记录** |
| Python | 3.12.14 | 固定解释器 |
| PHP lint | 9/9 OK | `qa/wbs-20261105/php-lint.txt` |
| Python compileall | 0 | `qa/wbs-20261105/python-baseline.txt` |
| test_crypto | 4/4 OK | 同上 |
| DB（.env） | mysql / 127.0.0.1 / 库名 `root` | **测试必须显式覆盖为 `summon_mecha_step10_test`** |
| QUEUE_CONNECTION | database | 无常驻 worker |
| CACHE_STORE | database | — |
| SKILL_GA_* | 全部 false | 默认关闭符合要求 |
| 工作区 | 有大量未提交改动 + 未跟踪适配器/telemetry/registry | 见 `git status --short --branch` |

## 2. 已确认的代码级缺口（证据为 file:line）

1. **A1.3 事件正文未签名**：`agent/zhjj_agent/client.py:42` 请求签名只覆盖 `did|ts|method|path`；`send_event()` 的 payload 完全未签。平台 `AgentController::event()`（261-361）在入 Inbox 前只做归属校验，**无正文验签**。
2. **A1.1/A1.2/A1.5/A1.6/A1.7/A1.9 缺失**：无 AgentFactEnvelopeV3；事件为普通 JSON；无 v2/v3 兼容窗口；无跨语言 JCS 黄金向量；无 body 替换攻击测试；无 nonce 重放/跨重启测试。
3. **A1.8 已具备**：`DeployInboxService::processFact()`（83-172）以 `(did,event_id)` + JCS 全载荷哈希做幂等/异载荷 409，契约由 `InboxEvent` 强类型校验。
4. **A2.4 用 now() 伪造 stopped_at**：`agent/zhjj_agent/task_manager.py:320-324`，`stopped_at = now_ts if actual_stopped else None`，未使用观察器上报时间。
5. **A2.1/A2.2/A2.3 缺失**：无 StopObservation DTO（source_type/sensor_id/observed_at/velocity/current/contact/brake/power/quality/raw_evidence_sha256/collector_version），`execution_fact` 的 `evidence` 仍是自由数组；无 source_type 白名单，无法拒绝 sdk_return/bt_halt/mock/sil。
6. **A2.5/A2.6 部分具备**：`ExecutionFact`（Contracts/ExecutionFact.php:57-117）强制终态必须有 `actual_stopped=true`，UNKNOWN 不得声称已停；租约释放仅在 `is_stopped` 分支（task_manager:769-821），UNKNOWN 保持租约。
7. **A7 未实现**：`SigningService.php:11-17` 明确“只内置一把公钥、不支持多公钥/轮换、KMS/HSM 未实现”；无 KeyProvider/key_id/rotation/revocation 接口与验签拒绝逻辑。
8. **B1.2 生产入口绕过强类型入口**：`SkillSupplyController.php:102` 直接调用 `$this->builder->compile(...)`；全仓 `src` 内 `compileWithCommand` **只有定义、无调用方**。BuildCommand 目前是“只在测试中使用”。
9. **B1.3~B1.8 缺失**：无 `skill_build_commands` 迁移/模型；无 `(seller_id, idempotency_key)` 唯一键；无同键异载荷 409；无命令+outbox 事务。
10. **B2.1/B2.2/B2.3 违反**：`SkillBuildService.php:132` 与 `PortableSkillBuilder.php:204` 两处重复 `max(run_number)+1`，无行锁/序列/唯一键冲突有界重试；无 2/8/16 并发测试。
11. **B3.1 Admin 入口缺失**：`SkillTargetReleaseService` 已有 approve/reject/revoke + 只追加 `TargetReleaseEvent`；但 Shop 路由与 Admin 包均未找到 target-release 审核路由/控制器。
12. **B6.1~B6.5 部分**：`MotionCarrierBuilder` 仅登记外部重定向任务并返回 pending（不伪造产物），但缺目标动作产物契约、参数/限值/完成/停止定义与负例。
13. **B7 部分具备**：`SkillDeliveryAssetResolver` 已拒绝 source 资产、校验 approved/profile digest/跨机型；`build_key` 通过 release 快照间接校验。旧 generation 与缺失 HIL 证据的显式状态未验证。

## 3. WBS 状态表（本轮证据等级 = CODE_INSPECTION；未跑 DB 回归）

| WBS | 状态 | 依据 |
|---|---|---|
| A1 事件正文认证与重放 | PARTIAL | 幂等/409 有；正文签名/V3/兼容窗/黄金向量/重放测试缺 |
| A2 StopObservation | PARTIAL | 终态门禁有；强类型观察器/白名单/观察时间缺，且 now() 伪造 |
| A3 指标 | PARTIAL | `GaOperationalMetrics.php`、`telemetry.py` 存在；9 项计数与基数测试未验 |
| A4 崩溃重启 | PARTIAL | executions 表/outbox/journal 有；failpoint 与独立进程杀进程测试未验 |
| A5 安装/回滚事务 | PARTIAL | realpath、restore_sha256 有；原子激活/磁盘满/并发负例未验 |
| A6 沙箱接口 | PARTIAL | executor/watchdog 有；OS 级加固 NOT_RUN |
| A7 密钥生命周期 | BLOCKED | 单钥模型，KMS/HSM 未实现 |
| B1 BuildCommand 生产化 | PARTIAL | DTO/强类型入口有；生产入口绕过、无命令表/幂等/outbox |
| B2 run_number 分配 | FAIL | 两处 max+1 无锁；无并发测试 |
| B3 目标审核发布 Admin | PARTIAL | 服务层状态机有；Admin API/页面/四角色矩阵缺 |
| B4 源包安全 | PARTIAL | SourceParser 限制有；ZIP Slip/软链/炸弹/磁盘满负例未验 |
| B5 构建确定性 | PARTIAL | 固定时间戳/排序有；本轮未做双目录双进程重跑；工具链摘要 NOT_RUN |
| B6 MotionCarrierBuilder | PARTIAL / BLOCKED | 仅 G1 有真实模型；其余四机型缺厂商限值 → fail-closed |
| B7 交付解析 | PARTIAL | approved/profile/source 守卫有；HIL 证据/旧 generation 未验 |
| B8 构建指标 | PARTIAL | 指标类存在；build_* 计数幂等与基数测试未验 |

## 4. 待用户补充的 3 个槽位（不填则不脑补）

1. **Stop Observer 真实硬件接口**：传感器、采样频率、时间戳语义、断线语义、`actual_stopped` 定义。
   - 建议默认：`source_type` 白名单仅 `hardware_estop` / `motor_controller_feedback` / `safety_plc` / `independent_watchdog`；采样 ≥10 Hz；单调毫秒时间戳；`actual_stopped = 速度≈0 且 电流≈0 且 抱闸闭合，持续 ≥200ms`；断线/过期/倒序/质量不合格/证据哈希不符 → UNKNOWN。
2. **Agent 事件签名升级策略**：是否允许 AgentFactEnvelopeV3、v2 兼容窗口、PHP/Python 密钥所有权与轮换。
   - 建议默认：V3 签名体固定 `{event_id,did,task_no,event_seq,phase,execution_fact,evidence,reported_at}`；`prefix || JCS(body)`；平台私钥签指令、设备私钥签事实，`key_id` 入信封；v2 仅只读兼容且开关默认关闭；未知/吊销/旧 epoch key_id 一律拒。
3. **Build Worker 部署事实**：同步/database queue/独立 worker、MySQL 版本与隔离级别、并发上限、工具链固定摘要。
   - 建议默认：命令落库 + `database` queue；测试用显式 `build:work --once`；生产独立 worker；run_number 用唯一键 + 有界重试（不依赖特定隔离级别）；工具链摘要无独立镜像则标 NOT_RUN。

## 5. 建议的第一批实现切片（不依赖槽位 1/2）

1. **B2**：收敛为单一 `RunNumberAllocator`，唯一键冲突有界重试，补 2/8/16 并发测试（隔离库）。
2. **B1**：新增 `skill_build_commands` 迁移 + 幂等服务 + outbox；把 `SkillSupplyController::compile` 切到 `compileWithCommand`。
3. **B8**：build_* 指标与幂等测试。
4. **B7 硬化**：撤销后 fail-closed、旧 generation 拒绝测试。
5. **A2 平台侧 DTO**：StopObservation 强类型 + source_type 白名单 + 拒绝测试（硬件采集仍 BLOCKED）。

## 6. 明确未做 / 不得升级为完成

- 本轮**未执行**任何 Pest / 全链路回归；不得引用旧日志作为本轮证据。
- 未执行 migrate:fresh/truncate/seed；未对 root 库做任何写操作。
- HIL / 真机 / KMS/HSM / 生产 FPM/MySQL：**NOT_RUN / BLOCKED**。
- 当前等级（本轮）：软件公共底座 PARTIAL；SIL NOT_RUN（本轮未跑）；HIL HARDWARE_UNVERIFIED；Production BLOCKED；五机型 GA BLOCKED。
