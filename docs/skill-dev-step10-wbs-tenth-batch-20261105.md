# WBS 无外部硬件依赖工作包 · 第十批交付（2026-11-05）

> 范围：**T1 HTTP 层多设备指标并发 · T2 B3 发布竞态（HTTP 层）· T3 并发密钥轮换/吊销 ·
> T4 真实 ENOSPC / 真实 ACL 拒绝 · T5 独立构建镜像摘要 · T6 沙箱接入执行路径 ·
> T7 全量 Pest 单进程方案 · T8 SoftHSM 真实 KMS 调用演练 · T9 生产栈演练 · T10 KMS 生产化抽象评估**。
> 环境：PHP 8.4.24（目标 8.3 未装，偏差沿用）/ Python 3.12.14 / MySQL 5.7.26
> （隔离库 `summon_mecha_step10_test`）/ Windows 11。**本机进程未提权，Docker daemon 未运行。**
> T8 另用 SoftHSM2 2.5.0（软件 HSM，PKCS#11）做 PROVEN_LOCAL 演练。

## 0. 结论

- **T1 · HTTP 层多设备指标并发：PROVEN（附环境限制）**。起 **6 个独立 `php -S` 进程**
  （8811–8816，各自 PID），用 `curl_multi` 同时发请求。6 条用例全部按**精确增量**断言：
  不同批次并发 → `command_received_total` 恰好 +6；同一批次并发 → 恰好 +1 且 6 个响应全 2xx；
  并发 gauge → 最终值 ∈ {100,200,300}（不是 600）；20 台设备并发 → 恰好 +20 且台账里
  20 个不同 did；未知键 → 422 且不落库；**1 server 串行对照组结果同为 +6**。
  环境限制：`php -S` 没有 php-fpm，6 个进程是本机能构造的最强应用层并发；未做长时间压测。
- **T2 · 发布竞态（HTTP 层）：PROVEN**。3 个独立 server 同时打
  `shop.api.skill-admin.versions.publish`：响应码 ⊆ {200,201,409}、**无 5xx**、
  `skill_versions` 该商品下**恰好 1 行**、恰好 1 条 published、商品可售；
  串行重复发布返回 **422 `VERSION_ALREADY_PUBLISHED`**（不是 500）；3 个不同版本并发发布各自成功
  （证明不是全局锁）。
  ⚠ **口径偏差（诚实）**：prompt 写的是"`skill_target_releases` 恰好 1 行"，但该发布入口
  **不写**这张表（它由构建期 `PortableSkillBuilder` 登记）。因此本批对该表的断言是
  "发布竞态不得产生多余行（0 行）"，而不是一条恒真的"恰好 1 行"。
- **T3 · 并发密钥轮换/吊销：PROVEN**。6 个独立 PHP 进程用同一未来时间点忙等到点后同时调用：
  并发 `issue()` → 密钥环**恰好 6 把**、key_id 无重复、epoch=6；6 进程并发吊销同一把 key →
  6 个进程看到的 `revoked_at` **完全相同**（幂等）、无崩溃；混跑期间独立监视进程采样 0 次
  epoch 回退、0 次 JSON 解析失败；吊销后该 key 验签失败、其它 key 仍可验证；
  `activeKeyId()` 恰好是 epoch 最大的未吊销 key。
- **T4 · 真实 ACL 拒绝：PROVEN_HARDWARE_INDEPENDENT**。`icacls` 对 `skills/` 拒绝 Everyone 写，
  **先真实探测"写不进去"再跑安装**；安装以 `PERMISSION_DENIED` 受控失败、无半装目录、
  无激活指针、锁可再次获取；恢复权限后安装成功。
  **真实 ENOSPC：BLOCKED_ELEVATION**（本机非管理员，`diskpart` / `Mount-DiskImage` /
  `New-VHD` 全部 `requires elevation`），保留第五批注入式结论，不把注入式标成 PROVEN。
  用例已写好在 `test_real_os_failures.TestRealEnospc`，提供 `ZHJJ_ENOSPC_ROOT` 即执行。
- **T5 · 独立构建镜像摘要：代码 PROVEN，真实镜像摘要 NOT_RUN（BLOCKED_ENV）**。
  `ToolchainFingerprint` 只认环境变量 `SKILL_BUILD_IMAGE_DIGEST`；合法 `sha256:<64hex>` → `PROBED`
  并随 `skill_build_runs.toolchain_json` 落库；未注入 → `null + NOT_RUN`；格式不对 →
  `INVALID_DIGEST` 且 digest 仍为 null。Dockerfile 与 `tools/build-image.ps1` 已就绪，
  Docker daemon 启动后即可产出真实摘要。**未伪造任何 digest。**
- **T6 · 沙箱接入执行路径：WIRED（不是"已隔离"）**。`SkillExecutor` 接受可选
  `sandbox_runner`；`ZHJJ_REQUIRE_SANDBOX` 默认关闭，打开且无 runner 时 **fail-closed**
  （返回 `SANDBOX_UNAVAILABLE`，适配器零调用、不占租约）；`BaseInstallAdapter` 新增
  `run_local_process()` 唯一入口，无 runner 抛 `SandboxUnavailableError`（不降级裸 subprocess）；
  `capabilities()` 如实标注 `PROCESS_BOUNDARY_ONLY`。`is_safety_evidence` / `physical_stop_confirmed`
  仍恒为 `False`。
- **T7 · 全量 Pest 单进程方案：限定为"设计决定"（方案 a 不可作门禁，方案 b 固化）**。
  phpunit.xml 覆盖 **319 个测试文件**（不含 vendor）。已按方案 a 在测试环境跳过 3 个控制器的
  进程级 `set_time_limit`（4 处，`app()->runningUnitTests()` 守卫），探针实测
  `APP_ENV=testing / runningUnitTests=true / max_execution_time=0` —— 旧的 60s/240s 进程级砍杀已消除。
  但**字面意义的全量单进程仍跑不完**：实测 1289.7s、已执行约 **1624 个用例标记（1472 通过 + 152 失败）**
  后进程以 `exit -1` 终止；日志无 PHP fatal、MySQL processlist 无锁等待、Windows 事件日志无 php 崩溃。
  因此不把单进程当门禁；固化 **`tools/pest-all.ps1`（按文件分进程 + 汇总表 + 失败计数 + 退出码）**
  为正式门禁 —— 这是设计决定，不是缺陷兜底。全量分文件结果见 §2 [T7]。
  分文件实测（复跑，补 `ZHJJ_PYTHON_BIN`）：**319 文件 / 2256 通过 / 49 个失败文件 / 19598 断言 / 2313s**；
  本批 Deploy 并发/密钥/工具链用例全通过。失败根因已定位：隔离库播种稀薄
  （`admins=1 / products=0 / customers=0 / core_config=20`）+ Admin 本地化编码比较，
  与单进程设计无关；见 `qa/wbs-20261105/b10-t7-db-seed-state.txt`。
- **T8 · SoftHSM 真实 PKCS#11 演练：PROVEN_LOCAL**。用 SoftHSM2 2.5.0（portable，PKCS#11 v2.40）
  实测：密钥在 token 内生成（`CKA_SENSITIVE=true / CKA_EXTRACTABLE=false`），读取私钥分量抛
  `AttributeSensitive`，导出 PEM 仅含公钥；签名由 HSM 完成、外部用公钥验签通过；销毁私钥对象后再签
  抛 `ObjectHandleInvalid`。**这是软件 HSM，不是硬件 HSM、不是生产 KMS。**
- **T9 · 生产栈演练（php-fpm + MySQL 8）：BLOCKED_ENV**。Docker daemon 未运行（`docker info` exit 1），
  本机非管理员无法启动 `com.docker.service`。STACK-01/02/03 全部 NOT_RUN，六字段见 §5。
- **T10 · KMS 生产化抽象评估：报告已出，未接入任何云 KMS**。现有 `KeyProvider` 只抽象"密钥状态语义"，
  真正的密码学操作（生成设备私钥、签 X.509、指令签名）没有接口；建议新抽
  `CertificateAuthoritySigner` / `AsymmetricSigner` 两层，并指出"设备私钥端侧生成（CSR-up）"才是
  "密钥不落明文"的前提。详见 [KMS 评估](skill-dev-step10-wbs-t10-kms-evaluation-20261105.md)。
- 回归：**PHP 44 个文件 / 336 项 / 7024 断言全绿**（基线 41/318/6544）；
  **Python 129 项 OK（1 skipped = ENOSPC 类）**；`php -l` 8 文件 OK；
  契约门禁（45 路由 / 85 错误码）与数据 preflight 通过。**本批无迁移。**
- **本批不会让 Production Ready / 五机型 GA 从 BLOCKED 变成可用**：真机 HIL、厂商 SDK 实机链路、
  KMS/HSM、生产 MySQL/FPM 仍是外部前提。

## 1. 改动清单

### T1 · 指标并发

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/Deploy/DeployMetricCounter.php` | `applyAgentGauges` 由 `updateOrInsert`（先查再写）改为 `upsert`（MySQL 走 `ON DUPLICATE KEY UPDATE`），并发心跳不会撞唯一键 500，语义仍是**覆盖** | 修改 |
| `tests/Feature/Deploy/DeployMetricHttpConcurrencyTest.php` | 6 条 HTTP 并发用例 + 6 server 管理 / curl_multi / 独立 PDO 校验 / 证据 JSON | 新增 |

### T2 · 发布竞态

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `tests/Feature/Deploy/SkillPublishHttpConcurrencyTest.php` | 3 server 并发发布；setup/verify/cleanup 全在子进程（父进程不建业务行）；4 条用例 | 新增 |

### T3 · 密钥并发

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/Deploy/DeviceCredentialService.php` | ① `revokeKey()` 改**幂等**：已吊销的 key 保留第一次 `revoked_at`；② `verifyDetachedSignature($keyId)` 对已吊销 key **fail-closed**（此前只验签名不验状态） | 修改 |
| `tests/Feature/Deploy/DeviceCredentialConcurrencyTest.php` | 6 进程同步并发 issue/revoke + 监视进程；5 条用例 | 新增 |

### T4 · 真实 OS 失败

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `zhjj_agent/install_transaction.py` | `skill_root(create)` / `new_staging()` / `SkillInstallLock.__enter__` 的 `OSError`（EACCES/ENOSPC）翻译为 `PackageManagerError`，不再裸异常冒到状态机外 | 修改 |
| `agent/tests/test_real_os_failures.py` | 真实 ACL 2 条；真实 ENOSPC 3 条（无 `ZHJJ_ENOSPC_ROOT` 时 skip 并说明 BLOCKED_ELEVATION） | 新增 |

### T5 · 构建镜像摘要

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Services/SkillCompile/ToolchainFingerprint.php` | 新增 `image()`：只认 `SKILL_BUILD_IMAGE_DIGEST` / `_REF`；`PROBED` / `NOT_RUN` / `INVALID_DIGEST`；新增 `image_ref`/`image_digest`/`image_status`，`container_image_*` 保持同值 | 修改 |
| `docker/build/Dockerfile` | PHP 8.3 CLI + zip/mbstring/pdo_mysql | 新增 |
| `tools/build-image.ps1` | daemon 探测 → build → inspect 取真实摘要 → 写 `docker/build/image-digest.env.ps1` | 新增 |
| `tests/Feature/Deploy/ToolchainFingerprintTest.php` | 2 → 5 条（未注入 / 合法 / 非法） | 修改 |

### T6 · 沙箱接线

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `zhjj_agent/sandbox.py` | 新增 `SandboxUnavailableError` | 修改 |
| `zhjj_agent/adapters/base.py` | `bind_sandbox_runner()` / `run_local_process()` / 诚实的 `capabilities()` | 修改 |
| `zhjj_agent/executor.py` | 可选 `sandbox_runner`；`ZHJJ_REQUIRE_SANDBOX` fail-closed；runner 注入适配器 | 修改 |
| `zhjj_agent/task_manager.py` | 可选 `sandbox_runner` 透传（默认 None，行为不变） | 修改 |
| `agent/tests/test_sandbox_wiring.py` | A6-WIRE-01…04 共 5 条 | 新增 |

### T7 · 全量 Pest 单进程方案

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `Http/Controllers/API/TtsController.php` | `set_time_limit(60)` 前加 `app()->runningUnitTests()` 守卫 | 修改 |
| `Http/Controllers/API/AsrController.php` | `set_time_limit(60)` 同上 | 修改 |
| `Http/Controllers/API/SummonAiController.php` | `summon()` / `summonStream()` 两处 `set_time_limit(240)` 同上 | 修改 |
| `tools/pest-all.ps1` | 按文件分进程的全量门禁：汇总表 + 失败计数 + 退出码 + summary.txt | 新增 |

> 生产行为不变：只在 `APP_ENV=testing`（`runningUnitTests()` 为真）时跳过；CLI/HTTP 生产仍按原值放宽。

### T8 · SoftHSM 真实 PKCS#11 演练

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `qa/wbs-20261105/probes/t8_softhsm_evidence.py` | SoftHSM2 token 内生成不可导出密钥、HSM 签名、外部验签、销毁后签名失败；输出 JSON | 新增 |
| `qa/wbs-20261105/b10-t8-softhsm.{txt,json}` | 原始输出与结构化证据 | 新增 |

> 运行依赖放在 `.tmp-b10/softhsm2/`（portable SoftHSM2 2.5.0 + `python-pkcs11`），不进仓库产物。

### T9 · 生产栈演练

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `qa/wbs-20261105/b10-t9-prod-stack.txt` | BLOCKED_ENV 六字段 + `docker info` / 服务启动原始输出 | 新增 |

### T10 · KMS 生产化抽象评估

| 文件 | 改动 | 新增/修改 |
|---|---|---|
| `docs/skill-dev-step10-wbs-t10-kms-evaluation-20261105.md` | 评估报告（现状事实 / 需抽的层 / 成本 / 风险 / 待拍板项）；**不改生产代码** | 新增 |

## 2. 证据

| CLAIM_ID | 声明 | 命令 / 退出码 | 原始输出 / SHA256（前 8） |
|---|---|---|---|
| A3-HTTP-01 | 同设备 6 并发不同 batch 各 +1 → 恰好 +6 | `pest DeployMetricHttpConcurrencyTest --filter=A3-HTTP-01` / 0 | [b10-t1-http-concurrency.txt](qa/wbs-20261105/b10-t1-http-concurrency.txt) `7029CED7…`；计数见 b10-concurrency-evidence.json `BB518FB9…` |
| A3-HTTP-02 | 同设备 6 并发同一 batch → 恰好 +1，6 个 2xx | 同上（整文件）/ 0 | 同上 |
| A3-HTTP-03 | 并发 gauge → ∈{100,200,300}，绝不为 600 | 同上 / 0 | 同上 |
| A3-HTTP-04 | 20 台设备并发各 +1，无串号 | 同上 / 0 | 同上 |
| A3-HTTP-05 | 未知键 → 422 且不落库 | 同上 / 0 | 同上 |
| A3-HTTP-06 | 1 server 串行对照组结果同为 +6 | 同上 / 0 | 同上 |
| B3-HTTP-01 | 3 进程并发发布同一 version：无 5xx、无重复行 | `pest SkillPublishHttpConcurrencyTest` / 0 | [b10-t2-publish-concurrency.txt](qa/wbs-20261105/b10-t2-publish-concurrency.txt) `C73A72A4…` |
| B3-HTTP-02 | 串行二次发布 → 422 幂等，不产生新行 | 同上 / 0 | 同上 |
| B3-HTTP-03 | 并发发布 3 个不同 version 各自成功 | 同上 / 0 | 同上 |
| B3-HTTP-04 | 发布后版本/商品状态不矛盾 | 同上 / 0 | 同上 |
| A7-CONC-01 | 6 进程并发 issue → 恰好 6 把、key_id 无重复 | `pest DeviceCredentialConcurrencyTest` / 0 | [b10-t3-credential-concurrency.txt](qa/wbs-20261105/b10-t3-credential-concurrency.txt) `42643D82…` |
| A7-CONC-02 | 6 进程并发吊销同一 key → 只写一次、幂等、无崩溃 | 同上 / 0 | 同上 |
| A7-CONC-03 | 混跑期间 epoch 单调不减、JSON 可解析 | 同上 / 0 | 同上 |
| A7-CONC-04 | 吊销后该 key 验签失败，其它 key 不受误伤 | 同上 / 0 | 同上 |
| A7-CONC-05 | activeKeyId 指向 epoch 最大的未吊销 key | 同上 / 0 | 同上 |
| A5-ACL-01 | 真实 EACCES：受控失败、无半装、锁释放 | `python -m unittest tests.test_real_os_failures` / 0 | [b10-t4-real-os-failures.txt](qa/wbs-20261105/b10-t4-real-os-failures.txt) `65796B8C…` |
| A5-ACL-02 | 恢复权限后安装成功 | 同上 / 0 | 同上 |
| A5-ENOSPC-01/02/03 | 真实满盘受控失败 / 失败后无半装状态 / 换盘重试成功 | **NOT_RUN**（skip：BLOCKED_ELEVATION） | 同上（`OK (skipped=1)`） |
| B5-5（T5） | 未注入 → null+NOT_RUN；合法 → PROBED 落库；非法 → INVALID_DIGEST | `pest ToolchainFingerprintTest` / 0 | [b10-t5-toolchain.txt](qa/wbs-20261105/b10-t5-toolchain.txt) `80EAE9F0…` |
| A6-WIRE-01 | 开关开+无 runner → 拒绝、适配器零调用、不占租约 | `python -m unittest tests.test_sandbox_wiring` / 0 | [b10-t6-sandbox-wiring.txt](qa/wbs-20261105/b10-t6-sandbox-wiring.txt) `B0728245…` |
| A6-WIRE-02 | 开关开+有 runner → runner 注入、结果非安全证据 | 同上 / 0 | 同上 |
| A6-WIRE-03 | 开关默认关 → 行为与接线前一致 | 同上 / 0 | 同上 |
| A6-WIRE-04 | 无 runner 时 `run_local_process` 抛明确异常 | 同上 / 0 | 同上 |
| REG | **44 文件 / 336 项 / 7024 断言全绿** | `run-b10-regression.ps1` / 0 | [b10-php-regression.txt](qa/wbs-20261105/b10-php-regression.txt) `98F84ACA…` |
| REG-0 | 改动前基线：41 文件 / 318 项 / 6544 断言全绿 | 同上（变异前） / 0 | [b10-php-regression-baseline.txt](qa/wbs-20261105/b10-php-regression-baseline.txt) `59661DA6…` |
| PY | Python 全套 **129 项 OK，1 skipped（ENOSPC）** | `python -m unittest discover -s tests` / 0 | [b10-python-regression.txt](qa/wbs-20261105/b10-python-regression.txt) `3D617057…` |
| LINT | `php -l` 8 个文件全 OK | `php -l <8 files>` / 0 | [b10-php-lint.txt](qa/wbs-20261105/b10-php-lint.txt) `9C590F72…` |
| GATE | 契约检查通过（45 路由 / 85 错误码） | `artisan ecosystem:contract-check` / 0 | [b10-contract-check.txt](qa/wbs-20261105/b10-contract-check.txt) `CE1D39E2…` |
| PREFLIGHT | 数据 preflight 通过 | `artisan ecosystem:data-preflight` / 0 | [b10-data-preflight.txt](qa/wbs-20261105/b10-data-preflight.txt) `7ACC15D0…` |
| PROBE | 6 个 `php -S` 全部 200 且可完全清理 | PowerShell 探针 / 0 | [b10-six-servers-probe.txt](qa/wbs-20261105/b10-six-servers-probe.txt) `2A7E1702…` |
| RG | 6 处变异 red→green | `/1 → 0` | [red-green-b10.txt](qa/wbs-20261105/red-green-b10.txt) `1FB93983…` |
| T7-A | 全量单进程：319 文件；方案 a 守卫实测 `max_execution_time=0`；字面全量单进程 1289.7s 后 `exit -1` 未汇总（≈1624 用例标记：1472 通过 + 152 失败） | `pest --compact` 单进程 / 1289.7s / **-1** | [t7-full-single-process.log](qa/wbs-20261105/logs-b10/t7-full-single-process.log) `DCCE9BA9…` |
| T7-B | 方案 b 门禁（复跑，补 `ZHJJ_PYTHON_BIN`）：**319 文件 / 2256 通过 / 49 个失败文件 / 19598 断言 / 2313s** | `tools/pest-all.ps1` / exit=49 | [b10-t7-pest-full.txt](qa/wbs-20261105/b10-t7-pest-full.txt) `BE6B2CC6…`；[summary.txt](qa/wbs-20261105/logs-b10/pest-all/summary.txt) `A15B8A61…` |
| T8-HSM | SoftHSM2：密钥不可导出、HSM 签名验签通过、销毁后签名失败 | `python -I probes/t8_softhsm_evidence.py` / 0 | [b10-t8-softhsm.txt](qa/wbs-20261105/b10-t8-softhsm.txt) `A5F548B1…`；JSON `285FB6F4…` |
| T9-STACK | php-fpm + MySQL 8 演练 | — / **NOT_RUN（BLOCKED_ENV）** | [b10-t9-prod-stack.txt](qa/wbs-20261105/b10-t9-prod-stack.txt) `9DED82FC…` |
| T10-KMS | KMS 生产化抽象评估（不改生产代码） | — / 报告 | [KMS 评估](skill-dev-step10-wbs-t10-kms-evaluation-20261105.md) `134506DB…` |

> 并发用例的端口 / 进程数 / 请求数 / 最终计数见 [b10-concurrency-evidence.json](qa/wbs-20261105/b10-concurrency-evidence.json)。
> 完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)：**119 条**，
> 文件自身 SHA256 `8C122543…`（由 `regen-manifest.py` 生成，可复现）。

## 3. 并发证据的形状（为什么这算"真并发"）

- **6 个独立 PHP 进程**：`php -S 127.0.0.1:881x`，各自 PID 写在证据 JSON 里；不是同一个 server 排队。
- **客户端真并发**：`curl_multi` 一次性把 6/20 个请求挂在同一个事件循环里，全部在途。
- **T3 用忙等对齐时间点**：子进程拿到同一个未来 `start_at` 后 `while (microtime(true) < start_at) usleep(500)`，
  到点同时进入临界区 —— 不是 `Start-Sleep` 假装同时。
- **T2 用 3 个 server 收 3 个并发发布**，响应码与最终行数一起断言。
- **对照组**：A3-HTTP-06 用 1 个 server 串行发同样 6 个请求，结果同为 +6 —— 说明 +6 是业务语义，
  并发用例不是"碰巧串行通过"。

## 4. red → green（6 处）

| 变异 | 观察 |
|---|---|
| 原子累加 → 读-改-写（+2ms 窗口放大） | **A3-HTTP-01 红**：delta 4 ≠ 6（丢更新） |
| gauge 覆盖 → 累加 | **A3-HTTP-03 红**：存成 600 |
| 去掉 `(did,batch_id)` 幂等 | **A3-HTTP-02 红**：同一批次 +6 |
| `issue()` 去掉 `lockForUpdate()` | **A7-CONC-01 红**：密钥环只有 1 把（应为 6） |
| T6 fail-closed 检查改成 `False` | **A6-WIRE-01 红**：适配器被调用，`ok=True` |
| 去掉「已发布再发布」守卫 | **B3-HTTP-02 红**：500 而不是 422 |

全部变异均从 `.claude/backup/b10-0N/` 逐字节还原，`Get-FileHash` 与备份一致；
全仓 `grep "b10 mutation"` 为 0。详见 [red-green-b10.txt](qa/wbs-20261105/red-green-b10.txt)。

诚实标注：A5-ACL-01/02、A3-HTTP-04/05/06、B3-HTTP-01/03/04、A7-CONC-02/03/04/05、T5 的
三种输入、T6 的 WIRE-02/03/04 是**首跑即绿**，没有可观测的单次 red —— 未编造。

## 5. 明确未做 / NOT_PROVEN / BLOCKED

| BLOCKED_ITEM | REASON | BLOCKING_SOURCE | WHAT_WAS_NOT_RUN | MINIMUM_UNBLOCK_ACTION | CURRENT_EVIDENCE_LEVEL |
|---|---|---|---|---|---|
| 真实 ENOSPC 下的安装失败 | 无法提权创建/挂载 64MB VHD | 本机进程非管理员：`diskpart` / `Mount-DiskImage` / `New-VHD` 均报 requires elevation | 真实满盘安装（A5-ENOSPC-01/02/03） | 管理员执行 diskpart 建 VHD → 填到只剩几 MB → 设 `ZHJJ_ENOSPC_ROOT=X:\` 后跑 `python -m unittest tests.test_real_os_failures.TestRealEnospc` | **INJECTED_ONLY**（沿用 `test_a5_07` 注入式；`TestRealEnospc` 存在但 skip） |
| 独立构建镜像摘要 | Docker daemon 未运行 | 本机 Docker Desktop 未启动（`docker info` exit 1） | 真实 `docker build` + `docker image inspect` | 启动 Docker Desktop 后执行 `tools/build-image.ps1`，source `docker/build/image-digest.env.ps1` 再跑 `ToolchainFingerprintTest` | **NOT_RUN**（代码路径 PROVEN；未伪造 digest） |
| B3 目标发布表的 HTTP 层并发 | `versions.publish` 不写 `skill_target_releases`；Admin target-release 路由是 session+CSRF | 架构事实（该表由构建期 `PortableSkillBuilder` 登记） | 对 Admin target-release 路由做 HTTP 并发压测 | 需要 Admin 会话并发压测方案；当前服务层双进程已 PROVEN（第四批） | **NOT_PROVEN（HTTP 层）** |
| OS 级沙箱隔离（seccomp/cgroup/namespace/Job Object） | 本机无法验证 | Windows + 无 OS 级加固实现 | 任何 OS 级隔离验证 | 引入 Job Object / 容器级隔离后再谈 | **NOT_RUN**（T6 只做到 **WIRED**） |
| 真机 HIL、厂商 SDK 实机链路 | 外部前提 | 硬件 / 外部系统 | 全部实机验证 | 外部条件到位 | **BLOCKED** |
| 生产栈演练（php-fpm + MySQL 8，T9） | Docker daemon 未运行；本机非管理员无法启动 `com.docker.service` | 本机 Docker Desktop 未启动（`docker info` exit 1） | STACK-01/02/03 全部 | 管理员启动 Docker Desktop 后，用 `php:8.3-fpm` + `mysql:8` 容器跑迁移/种子/冒烟/本批用例 | **NOT_RUN（BLOCKED_ENV）** |
| 云 KMS / 硬件 HSM 生产凭证 | 无凭证、未拍板；T8 只做了软件 HSM | 外部系统 + 人拍板 | 真实云 KMS / 硬件 HSM 接入与 X.509 签发 | 按 T10 报告拍板后，先接 SoftHSM2 后端再评估云 KMS | **PROVEN_LOCAL（仅 SoftHSM2）；生产 BLOCKED** |
| 全量 Pest 单进程作为门禁（T7） | 字面全量单进程 1289.7s 后 `exit -1`，未产出汇总；已排除 `set_time_limit`（守卫实测 `max_execution_time=0`） | 本机环境 / 长时单进程稳定性 | 单进程最终汇总 | 用 `tools/pest-all.ps1` 按文件分进程（已固化为门禁） | **设计决定（非缺陷）** |

## 6. 环境级风险

1. `.env` 的 `DB_DATABASE` 仍指向 `root`；本批所有命令都显式注入 `DB_DATABASE=summon_mecha_step10_test`。
2. PHP 8.4.24 替代目标 8.3 执行（未装 8.3）。
3. **本机进程未提权**：VHD/磁盘管理不可用；Docker daemon 未运行。
4. 并发测试用固定端口 8811–8816。回归按文件串行，端口不冲突；并行跑测试会冲突。
5. **计数器是全局累加值**：并发测试会产生真实提交的计数增量（不回滚）。断言一律用前后差值。
6. 并发测试会留下 `deploy_agent_metric_batches` 的 30 天清理窗口（由被测代码自己维护）；
   测试结束已按 did 删除自己建的 binding / batch / gauge 行。
7. `revokeKey()` 幂等与 `verifyDetachedSignature()` 吊销检查是**行为变更**（更严格 / 更安全），
   旧调用方若依赖"重复吊销改写时间戳"或"已吊销 key 仍能验签"，属于需要修复的用法。
8. **T7 单进程稳定性**：全量单进程在 1289.7s 后 `exit -1`，已排除 `set_time_limit`（守卫实测
   `max_execution_time=0`）；进程在并发用例后遗留 `php -S` 子进程（其清理绑在脚本结束）。
   因此本批采用分文件门禁 `tools/pest-all.ps1`。
9. **T8 的 SoftHSM2 是第三方 portable 包**（`disig/SoftHSM2-for-Windows` v2.5.0，
   ZIP SHA256 `85273BCC…`），只用于软件 HSM 演练；**不是生产 KMS，也不是硬件安全边界**。
10. **T9 未执行**：Docker daemon 未运行、本机非管理员。MySQL 8 / php-fpm 下的行为仍未验证。
11. `tools/pest-all.ps1` 必须存成 **UTF-8 with BOM**：PowerShell 5.1 会把无 BOM 的 UTF-8 当 ANSI 解析，
    导致中文注释后的代码被错误解析（本批实测踩到：无 BOM 时 `FILES=0`）。

## 7. 回滚

本批**无迁移**。

1. 代码：还原 `DeployMetricCounter.php` / `DeviceCredentialService.php` /
   `ToolchainFingerprint.php` / `sandbox.py` / `adapters/base.py` / `executor.py` /
   `task_manager.py` / `install_transaction.py`；删除 5 个新测试文件与
   `docker/build/Dockerfile` / `tools/build-image.ps1`。
2. 数据：无 schema 变更；测试自建的 `device_binding` / 批次 / gauge 行已清理。
3. 备份：6 处变异备份在 `.claude/backup/b10-01…b10-06/`，与仓库当前文件哈希一致
   （可作为"还原点"）。
4. 回滚后重跑本文件 §2 的命令。全程未执行任何 git 写操作，未触碰 `root` 库。
5. **T7**：用 `.claude/backup/b10-t7/` 还原 `TtsController.php` / `AsrController.php` /
   `SummonAiController.php`（撤销 `runningUnitTests()` 守卫）；`tools/pest-all.ps1` 可整体删除，
   不影响任何运行时路径。
6. **T8/T10 不涉及生产代码**：删除 `qa/wbs-20261105/probes/t8_softhsm_evidence.py`、
   `qa/wbs-20261105/b10-t8-softhsm.{txt,json}` 与 `docs/skill-dev-step10-wbs-t10-kms-evaluation-20261105.md` 即可。
   `.tmp-b10/` 下的 SoftHSM2 与 pylibs 是临时运行依赖，可整目录删除。

## 8. 等级（本批结束时）

- 软件公共底座：**PARTIAL** —— HTTP 层并发、密钥并发、真实 ACL、镜像摘要代码路径、沙箱接线、
  软件 HSM（SoftHSM2）均有证据；真实 ENOSPC、真实镜像摘要、OS 级隔离、生产栈（MySQL 8/php-fpm）仍缺。
- Agent：**PARTIAL**（沙箱已 WIRED，不是"已隔离"；真机观察器 BLOCKED）。
- Compiler：**PARTIAL**（镜像摘要代码就绪，真实镜像 NOT_RUN）。
- KMS/HSM：**PROVEN_LOCAL（仅 SoftHSM2 软件 HSM）**；生产 KMS/硬件 HSM **BLOCKED**（未接入、未拍板）。
- 全量 Pest 门禁：**分文件方案已固化**（`tools/pest-all.ps1`）；单进程不采用。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。
