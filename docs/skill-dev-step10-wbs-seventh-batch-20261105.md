# WBS 无外部硬件依赖工作包 · 第七批交付（2026-11-05）

> 范围：**A5 安装/回滚/卸载事务**、**A6 沙箱接口**、**A7 密钥生命周期**。
> 环境：PHP 8.4.24（目标 8.3，本机未装，偏差沿用）/ Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）/ Windows 11。
> A5、A6 在**设备端 Agent**（`agent/`）；A7 在**平台端**（per-device 证书密钥环）。

## 0. 结论

- **A5 完成**（设备端）：版本目录固定为 `skills/<skill_id>/v<version>`，安装走
  **staging → 两阶段提交（旧版本留备份）→ 健康检查 → 原子激活指针**；
  回滚只切指针、失败自动放回旧指针；卸载先整体 rename 再删。
  安装/回滚/卸载共用**跨进程技能锁**（`state/locks/<skill_id>.lock`）。
  包审计补齐了重复路径、大小写冲突、绝对路径/盘符、损坏 zip、权限与磁盘空间。
  回滚在**活动执行未确认停止时拒绝**，`restore_sha256` 必须匹配**恢复目标版本**记录的包哈希，
  且磁盘目录摘要必须与安装时一致。
- **A6 完成接口 + 便携实现**：`SandboxRunner` 接口 + `SubprocessSandboxRunner`。
  真实测试证明：超时会杀、输出超限会截断并杀、环境变量白名单不泄露宿主秘密、
  **杀的是整棵进程树**、**退出码 0 恒不等同物理停止**。
  OS 级加固（memory/filesystem/network/cgroup/seccomp/AppArmor/Job Object）如实标 **NOT_RUN**。
- **A7 完成接口 + 本地实现**：`KeyProvider` / `KeyMaterial` / `DeviceKeyProvider`；
  `device_binding.meta.agent_key_ring` 记账 key_id / epoch / 状态 / 窗口。
  验签按 key_id 选钥匙：未知、吊销、超窗、旧代次、缺 key_id 各有独立错误码；
  轮换窗口内旧密钥仍可验签；吊销后重新签发可恢复上报（离线恢复）。
  **KMS/HSM 未实现，标 BLOCKED，没有造假实现**。
- 新增测试：**PHP 10 项 + Python 26 项**。回归 **38 个 PHP 文件 / 307 项 / 6486 断言全绿**，
  **Python 全套 117 项 OK**；`php -l` 8 文件 OK；契约门禁（45 路由 / 85 错误码）与数据 preflight 通过。
  **本批无迁移**（A7 用 binding.meta，不新增表）。
- 变异验证 3 处：关闭安装失败回退 → A5 两红；只杀父进程 → A6 红；关闭吊销分支 → A7 红；还原后全绿。
- **诚实缺口**：磁盘满/权限不足是**注入式**验证而非真实制造；真实厂商适配器未跑；
  沙箱尚未接进适配器执行路径；KMS/HSM、真机 HIL 仍 BLOCKED。

## 1. 改动清单

### A5 · 设备端（`agent/`）

| 文件 | 改动 |
|---|---|
| `zhjj_agent/install_transaction.py`（新增） | `InstallTransaction`：组件白名单校验、`skill_root` 符号链接逃逸拦截、跨进程锁（POSIX `flock` / Windows `msvcrt`）、`new_staging`、`begin_commit`/`finish_commit`/`abort_commit` 两阶段提交、`activate`/`deactivate` 原子激活指针（临时文件 + fsync + `os.replace`）、`rollback_to`、`uninstall`（先 rename 再删）、安装元数据与**目录树摘要**、OSError 到错误码的翻译 |
| `zhjj_agent/package_manager.py` | 新增条目名审计：重复路径 / 大小写冲突 / 文件与目录同名 / 绝对路径 / 盘符 / `..` / 控制字符 / Windows 保留名 / ADS(`:`) / 结尾点或空格；解压期 `BadZipFile/zlib/EOF` → `ZIP_INVALID`；OSError → `PERMISSION_DENIED`/`DISK_SPACE`；**截断 zip 不再被当成裸单文件"成功安装"**；`commit_staging_to_final`/`install_with_staging` 改走 `InstallTransaction`（单一实现） |
| `zhjj_agent/task_manager.py` | 安装改两阶段提交 + 原子激活；失败分支 `_abort_install` 回退旧版本；回滚增加活动执行拒绝、授权/撤销代次校验、按**目标版本**核对 `restore_sha256`、目录摘要核对、失败恢复旧指针；卸载改原子 `uninstall`；启动清理新增 `.removed_*` 残骸 |
| `zhjj_agent/constants.py` | 新增错误码 `ZIP_DUPLICATE_PATH` / `PERMISSION_DENIED` / `PATH_INVALID` / `PATH_ESCAPE` / `RESTORE_MISMATCH` / `ROLLBACK_BLOCKED` / `ROLLBACK_FAILED` |

### A6 · 设备端（`agent/`）

| 文件 | 改动 |
|---|---|
| `zhjj_agent/sandbox.py`（新增） | `SandboxLimits` / `SandboxResult` / `SandboxRunner` 接口 / `SubprocessSandboxRunner`：独立进程、超时看门狗、stdout/stderr 字节上限、环境白名单、cwd、**进程树杀死**（Windows `taskkill /T`，POSIX `killpg`）、`capabilities()` 如实声明；`physical_stop_confirmed`/`is_safety_evidence` **恒 False** |

### A7 · 平台端（`my-bagisto-store/`）

| 文件 | 改动 |
|---|---|
| `Services/Deploy/Contracts/KeyProvider.php`（新增） | 生命周期接口：`resolve` / `activeKeyId` / `currentEpoch` / `rotate` / `revoke` / `capabilities` |
| `Services/Deploy/Contracts/KeyMaterial.php`（新增） | 公钥视图：key_id / cert / epoch / status / 窗口 / 吊销时间；`isUsableAt()` |
| `Services/Deploy/DeviceKeyProvider.php`（新增） | `KeyProvider` 唯一实现（本地 CA + binding.meta 密钥环）；`capabilities()` 明示 `kms=false / hsm=false` |
| `Services/Deploy/DeviceCredentialService.php` | 新增 `META_KEY_RING` 密钥环：`keyRing` / `activeKeyId` / `currentEpoch` / `resolveKeyMaterial` / `hasAnyKeyMaterial` / `revokeKey` / `verifyDetachedWithCert`；`issue()` 登记环、旧密钥转 retired 并写 `not_after`；`factKeyId()` 改为"环里的活动密钥"；`verifyDetachedSignature(..., ?keyId)`；**issue/revoke/revokeKey 走行锁 + 事务**防并发覆盖；**`revoke()` 同步吊销密钥环**（不能只吊销 bearer secret 而留下可签事实的私钥） |
| `Services/Deploy/AgentFactSignatureVerifier.php` | 新增 `CODE_KEY_REVOKED` / `CODE_KEY_STALE` / `CODE_KEY_INACTIVE`；按 key_id 解析 + 状态/窗口/代次策略；`requiresV3()` 改为"有任何密钥材料就必须签"（含已吊销） |
| `Http/Controllers/API/AgentController.php` | 新增三类密钥错误码归入 401 |
| `config/deploy_gateways.php` | `rotation_window_seconds`（默认 86400）、`epoch_grace`（默认 1） |

### 测试

- `agent/tests/test_install_transaction.py`（新增，18 项）
- `agent/tests/test_sandbox.py`（新增，8 项）
- `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/KeyLifecycleTest.php`（新增，10 项）
- `agent/tests/test_live_http_integration.py`（**测试卫生**：`tearDownClass` 改用 `communicate()`，
  消除解释器退出时的 `ResourceWarning: unclosed file` —— `wait()` 不会关闭 `stderr=PIPE`）

## 2. A5 事务语义

### 2.1 三类事实分开

| 事实 | 落点 | 为什么不能合并 |
|---|---|---|
| 版本目录 | `skills/<skill_id>/v<version>/` | 固定、可枚举、内容可校验；`..`/分隔符/保留名一律拒绝 |
| 激活指针 | `skills/<skill_id>/.active.json` | "现在生效哪一版"必须**原子**改变；临时文件 + `os.replace` |
| 安装元数据 | 版本目录内 `.install-meta.json` | 记录包哈希与**目录树摘要**，回滚时用于证明"磁盘内容没变" |
| 跨进程锁 | `state/locks/<skill_id>.lock` | 锁文件不能放在会整体 rename/删除的技能目录里 |

### 2.2 安装阶段

1. 下载并流式 SHA-256 复核（原有）。
2. **在技能根目录内**建 `staging`（与最终目录同一文件系统，rename 才是原子的）。
3. 安全解压 + manifest 校验。
4. 适配器 `prepare`。
5. `begin_commit`：旧版本目录移到 `.old-<uuid>`，staging 就位；**旧目录先不删**。
6. 适配器 `install` + 健康探针。
7. `activate` 写激活指针，然后才 `finish_commit` 删备份。
   任一步失败 → `abort_commit`：删半成品、旧目录放回原位。

### 2.3 回滚（`_handle_rollback`）

| 顺序 | 校验 | 失败错误码 |
|---|---|---|
| 1 | 活动执行先取消并**独立观测停止**；未确认停止即拒绝 | `ROLLBACK_BLOCKED` |
| 2 | 授权过期 / 撤销代次更高 → 拒绝回到可执行状态 | `REVOKED` |
| 3 | 目标版本目录存在且不是符号链接 | `RESTORE_TARGET_MISSING` |
| 4 | `restore_sha256` 必须等于**恢复目标版本**记录的 `package_sha256`（旧安装无元数据时退化为对裸载体文件直接算哈希） | `RESTORE_MISMATCH` |
| 5 | 重算目录树摘要并与安装时记录比对（文件损坏/被替换都会露出来） | `RESTORE_MISMATCH` |
| 6 | 原子写激活指针；适配器回滚失败 → 指针放回原版本（无原指针则删除指针） | `ROLLBACK_FAILED` |

> ⚠ 第 4 步修掉一个真实缺陷：原实现拿 `restore_sha256` 与**当前已安装版本**的哈希比，
> 与"要恢复到哪个版本"无关 —— 那个比较即使通过也证明不了恢复目标是对的。

### 2.4 错误码（设备侧新增）

| 错误码 | 语义 |
|---|---|
| `PATH_INVALID` | 技能号/版本号/包内条目名非法 |
| `PATH_ESCAPE` | 技能根目录符号链接逃逸 / staging 不在技能根目录内 |
| `ZIP_DUPLICATE_PATH` | 重复路径、大小写冲突、文件与目录同名 |
| `PERMISSION_DENIED` | 权限不足（OSError → 明确错误码） |
| `DISK_SPACE` | 磁盘空间不足 |
| `RESTORE_MISMATCH` | 恢复目标哈希或目录摘要不符 |
| `ROLLBACK_BLOCKED` | 活动执行未确认停止 |
| `ROLLBACK_FAILED` | 适配器回滚失败（指针已回退） |

## 3. A6 沙箱能力矩阵（诚实版）

| 限制项 | 接口声明 | 本机实现 | 证据 |
|---|---|---|---|
| 独立进程 | ✅ | ✅ | A6-01~07 全部真实子进程 |
| 超时杀死 | ✅ | ✅（看门狗轮询 20ms） | A6-01 |
| stdout/stderr 上限 | ✅ | ✅（读线程截断 + 杀进程） | A6-02/03 |
| 环境变量白名单 | ✅ | ✅（宿主秘密不泄露） | A6-04 |
| cwd | ✅ | ✅（指定，非强制围栏） | A6-05 |
| 进程树杀死 | ✅ | ✅（`taskkill /T` / `killpg`） | A6-07（变异验证过） |
| 内存上限 | 声明字段 | ❌ **未强制** | `capabilities().memory_limit=false` |
| 文件系统隔离 | 声明字段 | ❌ **未强制** | 同上 |
| 网络隔离 | 声明字段 | ❌ **未强制** | 同上 |
| cgroup / seccomp / AppArmor / Job Object | — | ❌ **NOT_RUN** | `os_level_hardening: NOT_RUN` |
| "退出码 0 = 安全/物理停止" | — | **恒 False** | A6-06 |

> A6.4 落到类型上：`SandboxResult.physical_stop_confirmed` 与 `.is_safety_evidence` 是恒返回 False 的属性，
> 没有 setter，也没有任何代码路径能把它们变成 True。真正的停止证据只来自独立 `StopObservation`。

## 4. A7 密钥生命周期

### 4.1 记账形状（`device_binding.meta.agent_key_ring`）

```json
{
  "epoch": 2,
  "keys": {
    "<key_id>": {"cert_pem": "...", "epoch": 2, "status": "active",
                  "created_at": "...", "not_before": null, "not_after": null,
                  "revoked_at": null, "reason": ""}
  }
}
```

- `key_id` 与 Python 端 `fact_key_id()` 同口径：`sha256(归一化 SPKI PEM)`。
- 历史单钥凭据在第一次轮换时自动补进环（`reason: legacy`），不会凭空消失。

### 4.2 验签拒绝矩阵

| 情形 | 错误码 | HTTP |
|---|---|---|
| key_id 不在环里且设备有其它密钥材料 | `AGENT_FACT_KEY_UNKNOWN` | 401 |
| 密钥已吊销 | `AGENT_FACT_KEY_REVOKED` | 401 |
| 已退休且超出轮换窗口 | `AGENT_FACT_KEY_STALE` | 401 |
| 代次落后于 `currentEpoch - epoch_grace` | `AGENT_FACT_KEY_STALE` | 401 |
| `not_before` 在未来 | `AGENT_FACT_KEY_INACTIVE` | 401 |
| 事实里没有 key_id | `AGENT_FACT_SIGNATURE_INVALID`（信封构造拒绝） | 401 |
| 设备完全没有密钥材料（历史共享密钥） | 保留 `v2_request_only` 只读窗口 | 200 |

### 4.3 轮换 / 吊销 / 离线恢复

- **轮换**：`issue()` 生成新证书 → 旧活动密钥 `retired`（`not_after = now + rotation_window_seconds`）→ 代次 +1。
- **吊销**：立即 `revoked`，不等窗口；若吊销的是唯一活动密钥，`activeKeyId()` 变 null → 设备恢复上报前必须重新签发。
  走既有"吊销设备凭据"入口时，`revoke()` 会**同时吊销密钥环里的全部公钥** ——
  否则 bearer secret 失效了、设备仍能用旧私钥签事实，等于吊销只吊销了一半（A7-10 覆盖）。
- **离线恢复**：吊销后重新 `issue()` 即得到新活动密钥，旧密钥**始终**被拒（A7-09）。
- **并发**：`issue()` / `revokeKey()` 都在 `DB::transaction` + `lockForUpdate()` 内读改 `meta`，
  避免两个并发请求互相覆盖密钥环（只做了行锁实现，**并发压测未做**，见 §7）。

## 5. 证据

| CLAIM_ID | 内容 | 命令 / 退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| A5-T-01 | A5 18 项全过（固定目录/原子激活/包审计/并发/失败回退/回滚四项校验/卸载） | `python -m unittest tests.test_install_transaction` / 0 | [a5-install-tests.txt](qa/wbs-20261105/a5-install-tests.txt)（`Ran 18 tests ... OK`） | `655F17F2…` |
| A6-T-01 | A6 8 项全过（真实进程：超时/输出上限/环境/cwd/进程树/退出码 0 非证据/能力诚实） | `python -m unittest tests.test_sandbox` / 0 | [a6-sandbox-tests.txt](qa/wbs-20261105/a6-sandbox-tests.txt)（`Ran 8 tests ... OK`） | `EDD8F1B5…` |
| A5A6A7-REG-01 | Deploy 31 + API 7 = **38 文件 / 307 项 / 6486 断言**全过（按文件分进程） | 循环 `pest <file>` / 全 0 | [a5a6a7-php-regression.txt](qa/wbs-20261105/a5a6a7-php-regression.txt) | `B6D976D5…` |
| A5A6-PY-01 | Python 全套 **117 项 OK**（含 live HTTP） | `python -m unittest discover -s tests` / 0 | [a5a6a7-python-regression.txt](qa/wbs-20261105/a5a6a7-python-regression.txt) | `AA9987A1…` |
| A7-T-01 | A7 10 项全过（接口/轮换/窗口/吊销/旧代次/未知/缺 key_id/离线恢复/凭据吊销同步密钥环） | `pest KeyLifecycleTest` / 0 | 见 [a5a6a7-php-regression.txt](qa/wbs-20261105/a5a6a7-php-regression.txt)（`KeyLifecycleTest.php  Tests: 10 passed (62 assertions)`） | 同上 |
| A7-SNAP-01 | 真实探针：代次 1→2→3、epoch 1 在策略下不可用、epoch 2 仍可用、吊销后 `activeKeyId=null`、`capabilities.kms/hsm=false` | `php probes/a7_key_lifecycle_probe.php` / 0 | [a7-key-lifecycle-probe.json](qa/wbs-20261105/a7-key-lifecycle-probe.json)（探针脚本 [probes/a7_key_lifecycle_probe.php](qa/wbs-20261105/probes/a7_key_lifecycle_probe.php)） | `033AC20A…` |
| A5A7-GATE-01 | `php -l` 8 文件 OK + 契约 `2026-10-20-q1`（45 公开路由 / 85 错误码）+ 数据 preflight 通过 | `/0` | [php-lint-a5a7.txt](qa/wbs-20261105/php-lint-a5a7.txt) / [contract-check-a5a7.txt](qa/wbs-20261105/contract-check-a5a7.txt) | `BD57818A…` / `3EAB9452…` |
| A5-RG-01 / A6-RG-01 / A7-RG-01 | 三处变异 red→green（见 §6） | `/1→0` | [red-green-a5a6a7.txt](qa/wbs-20261105/red-green-a5a6a7.txt) | `74260D03…` |

> ### 5.1 关于 A5A6A7-REG-01 的 `B6D976D5…`
> 这是**收尾后**的最终一次全量运行：DeviceCredentialService 的行锁、`revoke()` 同步吊销密钥环、
> A7-10 用例都已就位。过程中的两次中途运行已被本次覆盖，不作为证据。

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)：
**74 条**，文件本身 SHA256 `AF3FB994…`（由同目录 `regen-manifest.py` 生成，可复现）。
第五批的"57 条"、第六批的"64 条"都是**当时**的快照。

## 6. red → green（变异验证）

| 变异 | 观察 | 说明 |
|---|---|---|
| `_abort_install` 直接 return | **A5-10 / A5-11 两红**（失败后版本目录仍在） | 证明用例断言的是"失败路径真的清理了"，不是"函数返回 False" |
| `_kill_tree` 改成只杀父进程 | **A6-07 红**（孙进程写下了存活标记） | 证明杀掉的是整棵进程树 |
| 验签去掉专用吊销分支 | **A7-05 红**（错误码退化为 `KEY_STALE`） | 证明独立错误码是被断言的语义，不只是"401" |

全部变异已还原：`cmp` 与备份逐字节一致，仓库内无 `MUTATION` 残留。

**诚实标注**：其余用例首跑即绿，**没有**单次 red 记录。
A5-08 的"权限不足"是**注入 `PermissionError`** 验证错误码翻译（Windows 上目录只读位不阻止写入，
无法真实复现），不是真实权限事故。

## 7. 明确未做 / NOT_PROVEN / BLOCKED

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| 磁盘真实的"写满"与"权限拒绝" | **INJECTED_ONLY** | 用 mock `disk_usage` / 注入 `PermissionError` 验证翻译；未真的塞满磁盘或改动 ACL |
| 真实厂商适配器上的安装/回滚 | **NOT_PROVEN** | 本批用 `MockAdapter`；宇树/智元等需真机 |
| 沙箱接入适配器执行路径 | **NOT_DONE** | 接口与实现已就绪，但厂商 SDK 执行不是子进程契约；未强行接线（避免假集成） |
| OS 级隔离（内存/文件系统/网络/cgroup/seccomp/AppArmor/Job Object） | **NOT_RUN** | 本机无法验证，`capabilities()` 如实为 false |
| 并发轮换/吊销压测 | **NOT_PROVEN** | 行锁已实现；未做 N 并发请求压测 |
| 轮换后把新私钥安全送回设备的通道 | **NOT_DONE** | 仍走既有 enrollment（平台代发）；未新增轮换下发 API |
| KMS / HSM | **BLOCKED** | 未接入，且明确不实现假 KMS/HSM |
| 真机 HIL / 生产 FPM·MySQL | **BLOCKED** | 外部前提 |
| 全量 Pest 一次跑完 | **NOT_RUN** | 项目既有约束（进程级 `set_time_limit`），按文件分进程 |

## 8. 等级（本批结束时）

- 软件公共底座：**PARTIAL**（A1–A7 均有实现与证据；A 系仅剩真机/OS 级外部项）。
- Agent：**PARTIAL**（A1/A2.4/A3/A4/A5/A6 完成；真机观察器与 OS 级沙箱仍 BLOCKED/NOT_RUN）。
- Compiler：**PARTIAL**（B1–B8 齐）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 9. 环境级风险（沿用）

1. `.env` 的 `DB_DATABASE` 仍是 `root`；本批所有 pest/artisan 都显式注入
   `DB_DATABASE=summon_mecha_step10_test`。
2. 隔离库仍是"裸迁移"状态；3 个 `SkillAudit` 用例的环境性失败结论不变（`PRE_EXISTING_SUSPECTED（未证明）`）。
3. 本批**无迁移**：A7 的密钥环在 `device_binding.meta` JSON 里，不需要新表。

## 10. 回滚

1. 设备端：删除 `install_transaction.py` / `sandbox.py` 与两个测试文件；
   还原 `package_manager.py`（条目名审计与错误码翻译）、`task_manager.py`（安装/回滚/卸载）、`constants.py` 新增错误码。
2. 平台端：删除 `KeyProvider.php` / `KeyMaterial.php` / `DeviceKeyProvider.php` 与 `KeyLifecycleTest.php`；
   还原 `DeviceCredentialService.php`（密钥环与行锁）、`AgentFactSignatureVerifier.php`（三类错误码与策略）、
   `AgentController.php`（401 映射）、`config/deploy_gateways.php` 两个新键。
   ⚠ **不建议**回滚 A7 的 `requiresV3()` 改动：回退后"只有吊销密钥的设备"会重新获得无签名通道。
3. 数据：无需回滚（无迁移、无新表）。已写入的 `agent_key_ring` 是新增 meta 键，旧代码会忽略它。

回滚后重跑 §5 的命令。全程未执行任何 git 写操作，未触碰 `root` 库。
