# WBS 无外部硬件依赖工作包 · 第二批交付（2026-11-05）

> 范围：**A1 事件正文 agent-fact/v3 签名 + 正文替换/重放防护 + 跨语言 JCS 黄金向量**。
> 本批**未做** A2.4 端侧观察时间替换、B3 Admin 审核 API/ACL（见 §5）。
> 环境：PHP 8.4.24 / Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）。

## 0. 结论

- A1 已完成端到端闭环：**设备私钥签名事件正文 → 平台用登记证书验签 → Inbox 前完成 → 正文替换/未知 key/伪造签名/重放全部拒绝**。
- 本批新增/受影响测试 **67 项全过**：PHP A1 10、Python A1+crypto 8、Agent 回归 39、A2 回归 10。
- 门禁全绿：`ecosystem:contract-check`、`ecosystem:data-preflight`。
- **诚实缺口**：A2.4 端侧 `now()` 仍未替换；B3 Admin API/ACL 未做；真机 HIL 仍 BLOCKED。

## 1. 采用的默认拍板

| 项 | 采用 |
|---|---|
| 信封 | `AgentFactEnvelopeV3`，`protocol=agent-fact/v3` |
| 签名域 | `event_id / did / task_no / event_seq / phase / execution_fact / evidence / reported_at` |
| 规范字节 | `UTF-8("summon-mecha/agent-fact/v3") || 0x0A || UTF-8(JCS(signed))` |
| 密钥所有权 | 设备私钥签事实；平台用 `device_binding.meta.agent_credential.cert_pem` 验签；`key_id = sha256(归一化公钥 PEM)` |
| v2 兼容 | **持证设备强制 v3**（`AGENT_FACT_SIGNATURE_REQUIRED=true`）；无证书历史设备保留 `v2_request_only` 窗口（`AGENT_FACT_SIGNATURE_V2_WINDOW=true`），窗口关闭即拒绝 |
| 时间窗 | `reported_at` 过旧 >7 天或来自未来 >300s 拒绝；重放主防线是 Inbox `event_id` 幂等/409 |

## 2. 改动清单

### 平台
- 新增 `Services/Deploy/Contracts/AgentFactEnvelopeV3.php`：固定签名域 + `canonicalFor()`。
- 新增 `Services/Deploy/AgentFactSignatureVerifier.php`：形状 → 外层/内层一致 → key_id → 签名 → 时间窗。
- `Services/Deploy/DeviceCredentialService.php`：新增 `factKeyId()`、`verifyDetachedSignature()`、`publicKeyPem()`、`normalizePem()`。
- `Http/Controllers/API/AgentController.php::event()`：在 Inbox/状态/执行记录修改**之前**验签；成功后在 payload 写确定性 `_platform_fact_auth={level,key_id}`。
- `ApiErrorCode`：新增 `AGENT_FACT_SIGNATURE_REQUIRED / AGENT_FACT_SIGNATURE_INVALID / AGENT_FACT_KEY_UNKNOWN / AGENT_FACT_BODY_MISMATCH`。
- 新增 `config/agent_fact_signature.php`。

### 端侧
- `constants.py`：新增 `PROTOCOL_FACT_V3`。
- `crypto.py`：新增 `FACT_V3_PREFIX`、`canonical_fact_v3()`、`normalize_pem()`、`public_key_pem_from_private()`、`fact_key_id()`。
- `client.py`：初始化 `fact_key_id`；新增 `build_fact_envelope()`（无设备私钥返回 None）。
- `task_manager.py::emit_event()`：把已签名信封写进 outbox payload；重投复用同一签名。

### 测试
- 新增 `Feature/API/AgentFactSignatureV3Test.php`（10）。
- 新增 `agent/tests/test_fact_envelope_v3.py`（4，含 outbox 持久化）。
- 更新 `SkillFullChainEndToEndTest.php`：真实 enroll 凭据的事件补 v3 信封。
- 新增跨语言黄金向量 `qa/wbs-20261105/jcs-vectors.json`（synthetic-test-only 固定 RSA 密钥）。

## 3. 证据

| CLAIM_ID | 内容 | 命令/退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| A1-PHP-01 | V3 验签 10 项全过 | `pest AgentFactSignatureV3Test` / 0 | [a1-v3.txt](qa/wbs-20261105/a1-v3.txt) | 见 manifest |
| A1-PY-01 | Python 信封+crypto 8 项全过 | `unittest tests.test_fact_envelope_v3 tests.test_crypto` / 0 | [a1-python.txt](qa/wbs-20261105/a1-python.txt) | 见 manifest |
| A1-VEC-01 | 跨语言 JCS 规范串与 key_id 一致 | PHP + Python 双端断言 / 0 | [jcs-vectors.json](qa/wbs-20261105/jcs-vectors.json) | 见 manifest |
| A1-REG-01 | Agent API + 全链路 39 项全过 | 6 个 Pest 文件 / 0 | [agent-regression-a1.txt](qa/wbs-20261105/agent-regression-a1.txt) | 见 manifest |
| A2-REG-01 | A1 后 StopObservation 回归 10 项全过 | `pest StopObservationContractTest` / 0 | [a2-stop-observation-after-a1.txt](qa/wbs-20261105/a2-stop-observation-after-a1.txt) | 见 manifest |
| GATE-01 | 契约门禁通过 | `artisan ecosystem:contract-check` / 0 | [contract-check-a1.txt](qa/wbs-20261105/contract-check-a1.txt) | 见 manifest |
| GATE-02 | 数据 preflight 通过 | `artisan ecosystem:data-preflight` / 0 | [data-preflight-a1.txt](qa/wbs-20261105/data-preflight-a1.txt) | 见 manifest |
| LINT-01 | 8 个 PHP 文件 lint 全 OK | `php -l` / 0 | [php-lint-a1.txt](qa/wbs-20261105/php-lint-a1.txt) | 见 manifest |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)。

## 4. 覆盖的攻击面

- **正文替换**：外层 `phase` 改掉、签名不动 → `AGENT_FACT_BODY_MISMATCH`（422），Inbox 不落、状态不动。
- **未知 key_id**：→ `AGENT_FACT_KEY_UNKNOWN`（401）。
- **伪造签名**：用错私钥、key_id 正确 → `AGENT_FACT_SIGNATURE_INVALID`（401）。
- **持证设备缺信封**：→ `AGENT_FACT_SIGNATURE_REQUIRED`（422）。
- **同 event_id 同载荷**：幂等 duplicate，只落一条 Inbox。
- **同 event_id 异载荷（重新签名）**：409 CONFLICT，不静默覆盖。
- **重放**：同签名载荷重投命中 Inbox 幂等；跨进程重启后 outbox 复用同一 `event_id`/签名字节。
- **v2 兼容**：无证书历史设备保留 `v2_request_only` 审计级别；窗口关闭即拒绝。

## 5. 明确未做 / NOT_PROVEN

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| A2.4 端侧 `stopped_at=now()` | **NOT_FIXED** | `agent/zhjj_agent/task_manager.py:320-324` 仍用 `now()`；需把适配器 `observation.observed_at` 接入 `_execution_fact`，并更新 20+ 调用点与测试桩 |
| A2 真机 Stop Observer | **BLOCKED** | 无真实传感器/采样/断线语义；本批观察器均为 synthetic-test-only |
| B3 Admin 审核/撤销 API + ACL | **NOT_RUN** | 服务层状态机已有，Admin 路由/四角色矩阵未做 |
| A3~A7 / B4~B6 | **NOT_RUN** | 不在本批 |
| 生产 KMS/HSM / 真机 HIL / 生产 MySQL | **BLOCKED** | 外部前提 |
| red→green 单次日志 | **NOT_CAPTURED** | 与第一批同口径：缺陷以代码位置 + 新测试通过为证 |

## 6. 等级（本批结束时）

- 软件公共底座：**PARTIAL**（A1 + 第一批 5 项完成；A2.4/B3~B6 未做）。
- 当前 SIL：**NOT_RUN**（本批未跑物理仿真）。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 7. 回滚

```powershell
# 本批无新迁移；回滚 = 还原以下文件 + 删除新增文件
# 平台：AgentFactEnvelopeV3.php / AgentFactSignatureVerifier.php / config/agent_fact_signature.php
#       DeviceCredentialService.php / AgentController.php / ApiErrorCode.php
# 端侧：crypto.py / client.py / constants.py / task_manager.py
# 测试：AgentFactSignatureV3Test.php / test_fact_envelope_v3.py / jcs-vectors.json
#       SkillFullChainEndToEndTest.php 的签名事件段
```

回滚后重跑本批 §3 的全部命令与门禁。未执行任何 git 写操作，未触碰 root 库。
