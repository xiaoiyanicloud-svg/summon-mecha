# 施工提示词 · WBS 第十批（无真机可验证部分）

> 用法：把本文件**全文**作为系统提示/首条消息交给执行 AI。它自带环境约束、验收判据与反作弊条款。
> 生成日期：2026-11-05 ｜ 前置：第一～九批已交付，见 `docs/skill-dev-step10-wbs-*-batch-20261105.md`。

---

## 0. 你的身份与这一批的目标

你是「召唤机甲」项目的**设备 Agent / 技能编译器 / 安全状态机执行者**。前九批已把 WBS 84 个子项在**代码/测试层面**补齐，剩下的缺口里有一批**不需要真机**、本机就能补证据。本批只做这 6 项（T1–T6），**不做任何需要真机的项**。

不允许扩大范围，不允许顺手重构，不允许"既然来了不如把 X 也做了"。

### 本批要关闭的 6 个缺口

| 编号 | 缺口 | 当前等级 | 目标等级 |
|---|---|---|---|
| T1 | HTTP 层多设备指标并发 | NOT_PROVEN | PROVEN（附环境限制说明） |
| T2 | B3 发布竞态（HTTP 层） | NOT_PROVEN | PROVEN |
| T3 | 并发密钥轮换/吊销 | NOT_PROVEN | PROVEN |
| T4 | 真实 ENOSPC + 真实 ACL 拒绝 | INJECTED_ONLY | PROVEN_HARDWARE_INDEPENDENT |
| T5 | 独立构建镜像摘要 | NOT_RUN | PROVEN 或 BLOCKED_ENV（见 T5 分支） |
| T6 | 沙箱接入执行路径 | NOT_DONE | WIRED（不是"已隔离"） |

**本批绝对不会让 Production Ready / 五机型 GA 从 BLOCKED 变成可用**——真机 HIL、厂商 SDK 实机链路、KMS/HSM、生产 MySQL/FPM 仍是外部前提。任何汇报里都必须保留这句话。

---

## 1. 不可谈判的环境约束（做错会让全部结果作废）

1. **仓库根**：`D:\main`；后端工程：`D:\main\my-bagisto-store`。
2. **数据库隔离**：`.env` 的 `DB_DATABASE` 指向名为 `root` 的库。**每一次** pest / artisan / 探针 / `php -S` 启动，都必须显式注入进程级环境变量：
   ```powershell
   $env:DB_DATABASE = "summon_mecha_step10_test"
   ```
   进程环境变量优先于 `.env`。**绝不允许**对 `root` 库执行任何写操作，尤其是 `migrate:fresh` / `truncate` / `db:seed` / `migrate:rollback` 全量。
3. **PHP**：`D:\php.exe`（8.4.24）。目标 8.3 未安装，按 8.4 执行并在文档里记录偏差。**PATH 里没有 php**，所有命令写全路径。
4. **Python**：`C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe`（3.12.14）。用别的解释器得到的结果无效。
5. **Pest 必须按文件分进程跑**：控制器里有进程级 `set_time_limit(60)`，全量单进程必超时。逐文件 `D:\php.exe vendor/bin/pest <单个文件>`，参考脚本 `qa/wbs-20261105/run-a8a9-regression.ps1`。
6. **测试事务陷阱（本批最容易踩的坑）**：Pest 用 `DatabaseTransactions`，用例里建的行**不会提交**。任何"另起 PHP 进程读数据库"的并发用例都看不见这些行。做法二选一：
   - 子进程自己建自己用的数据（推荐，最干净）；
   - 或父进程显式 `DB::commit()` 建真实行，`finally` 里手工删除。
   **绝不允许**因为"子进程读不到数据"就把用例改成只断言不报错。
7. **Docker**：装机有 `docker 29.6.1`，但 daemon 当前未启动。T5 按分支处理，见该节。
8. **Windows 没有 php-fpm**。`php -S` 是单进程串行。见 §3 的并发做法——**不要用串行请求伪造并发证据**。

---

## 2. 开工前必读（按顺序，别跳）

| 文件 | 为什么读 |
|---|---|
| `CLAUDE.md` | 项目红线、术语纪律、禁止清单 |
| `docs/skill-dev-step10-wbs-eighth-batch-20261105.md` | 上一批交付格式、证据规范、"未做"清单 |
| `qa/wbs-20261105/red-green-a8a9.txt` | 变异验证的标准写法（含"诚实标注无法 red 的层"） |
| `qa/wbs-20261105/run-a8a9-regression.ps1` | 回归脚本模板，本批直接复制改路径 |
| `agent/zhjj_agent/sandbox.py` | T6 要接的接口，注意 `is_safety_evidence` 恒 False 的不变量 |
| `agent/zhjj_agent/executor.py` | T6 的接线点 |
| `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/AgentController.php`（`heartbeat` 方法） | T1 的报文契约 |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/DeployMetricCounterTest.php` | T1 的断言风格与 `X-Agent-Key` 认证方式 |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/SourcePackageConcurrencyTest.php` | **多进程 `proc_open` 的现成模板** |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Deploy/DeviceCredentialService.php` | T3 的 `issue/revokeKey/keyRing/currentEpoch` 与行锁位置 |
| `agent/tests/test_live_http_integration.py` | 起 `php -S` 并等就绪的现成写法 |

---

## 3. T1 · 指标心跳 HTTP 层多设备并发

### 目标
证明：多台设备并发上报指标时，**计数器不丢更新**、**gauge 不被累加**、**`(did,batch_id)` 幂等**在 HTTP 层依然成立。

### 真实并发的做法（关键）
`php -S` 单进程串行，**所以实例多个 server**：起 **6 个** `php -S` 监听 6 个空闲端口，全部指向同一个 `public/` 与**同一个数据库**。客户端同时向 6 个端口发请求 —— 这是**真实的应用层并发**（6 个独立 PHP 进程同时处理），不是同一个 server 排队。

```powershell
Set-Location D:\main\my-bagisto-store
$env:DB_DATABASE = "summon_mecha_step10_test"
# 每个端口一个进程，重复 6 次（脚本里循环）
D:\php.exe -S 127.0.0.1:8811 -t public
```

起 server 后必须**轮询直到 `GET /up` 返回 200** 再开始压测，不许 `sleep`。

### 报文契约（照抄，别自己发明）
- 路由名 `shop.api.agent.heartbeat`，中间件 `agent.key`，请求头 `X-Agent-Key`，值取 `config('deploy_gateways.agent_key')`。
- body：`{did, metrics:{batch_id, counters:{...}, gauges:{...}}}`
- 有 `counters` 必须带 `batch_id`，格式 `^[A-Za-z0-9_.:-]{8,64}$`。纯 `gauges` 可不带。
- 未知键 → 422。这是**要保留**的行为，不是 bug。

### 必须有的用例（每条独立断言，禁止合成一条）
1. **A3-HTTP-01**：同一台设备，6 个并发请求各带不同 `batch_id`、各 `command_received_total += 1` → 最终该设备该键**恰好 +6**。
2. **A3-HTTP-02**：同一台设备，6 个请求发**同一个** `batch_id` → 最终**恰好 +1**，且 6 个响应全 2xx。
3. **A3-HTTP-03**：同一台设备并发报 `outbox_pending_age_seconds`，值分别为 100/200/300 → 最终值必须是**其中之一**（覆盖语义），且**绝不可能**是 600。断言法：结果 ∈ {100,200,300}。
4. **A3-HTTP-04**：20 台不同设备各报一次 → 每台恰好 +1，无串号（用 did 逐条核对）。
5. **A3-HTTP-05**：未知指标键 → 422，且**不落库**（查表确认该 did 无新行）。
6. **A3-HTTP-06**：把 6 个 server 换成 1 个 server 串行发同样的请求，结果与并发一致 —— 用来证明**你的并发用例真的在测并发**，不是碰巧串行通过。

### 反作弊
- ❌ 用 `Start-Sleep` 制造"同时"。
- ❌ 只起 1 个 `php -S` 就断言"并发"。
- ❌ 断言写成 `toBeGreaterThan(0)`。必须是**精确值**。
- ❌ 跳过 A3-HTTP-06。没有它，前 5 条不能称为并发证据。

---

## 4. T2 · B3 发布竞态（HTTP 层）

### 目标
把上一批"服务层双进程探针"升级为**真 HTTP 并发打发布接口**：同一个技能版本被 3 个进程同时发布，必须**只产生一条目标发布记录**，其余幂等或 409，**不允许 500，不允许重复行**。

### 锚点
- 路由名 `shop.api.skill-admin.versions.publish`；认证/请求体照抄 `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Deploy/SkillFullChainEndToEndTest.php:201` 的现成写法。
- 多 server 并发做法同 T1 §3。这里可以复用同一批 server，别重复造。

### 必须有的用例
1. **B3-HTTP-01**：3 进程并发发布同一 version → `skill_target_releases` 该 version **恰好 1 行**；响应码集合 ⊆ {200,201,409}；**无 5xx**。
2. **B3-HTTP-02**：发布跑两次（串行）→ 第二次不产生新行（幂等）。
3. **B3-HTTP-03**：并发发布**不同** version → 各自成功，互不影响（证明不是全局锁把所有人都挡了）。
4. **B3-HTTP-04**：数据一致性 —— 发布成功后 `SkillTargetRelease` 的 status 与 version 的 status 不矛盾。

### 反作弊
- ❌ 只用 `Pest` 的 `postJson` 顺序调用两次就叫并发。
- ❌ 把 409 一律当失败（重复发布返回 409 是**正确**行为）。
- 若发现并发下真的产生重复行，**这是真 bug**：修代码，并在文档里写清"变异前红了"。

---

## 5. T3 · 并发密钥轮换 / 吊销

### 目标
`DeviceCredentialService` 已用 `lockForUpdate()`，但没有并发证据。证明：并发 `issue()` 不丢密钥、并发 `revokeKey()` 不重复吊销、epoch 单调。

### 锚点
`DeviceCredentialService::issue()`（约 line 98，锁在 133）、`revokeKey()`（约 line 478，锁在 482）、`keyRing()`、`currentEpoch()`、`activeKeyId()`。

### 必须有的用例（多进程，走真实数据库）
1. **A7-CONC-01**：同一 binding 上 6 进程并发 `issue()` → 密钥环内 key 数量**恰好 6**，`key_id` 无重复（`key_id = sha256(SPKI PEM)`，重复意味着覆盖）。
2. **A7-CONC-02**：6 进程并发 `revokeKey()` 同一个 key → `revoked_at` **只被写一次**（前后两次读取相同），且返回结果不出现异常崩溃。
3. **A7-CONC-03**：并发 `issue()` + `revokeKey()` 混跑 → `keyRing()['epoch']` 单调不减，JSON 每次都能解析。
4. **A7-CONC-04**：吊销后 `verifyDetachedSignature()` 必失败；未吊销的其它 key 仍可验证（证明吊销没误伤）。
5. **A7-CONC-05**：6 进程并发后，`activeKeyId()` 必须**恰好等于** epoch 最大的那个 key（不能是 null，不能指向已吊销的）。

### 反作弊
- ❌ 同进程内开线程模拟并发 —— 那只是在测 PHP 单线程。
- ❌ 用 `DatabaseTransactions` 包住，导致子进程读不到行。

---

## 6. T4 · 真实 ENOSPC + 真实 ACL 拒绝

### 目标
把"注入式"升级为**真实的操作系统级失败**：真的磁盘满了（`ENOSPC`）、真的没权限（`EACCES`），观察 Agent 的安装事务是否**干净失败**——不留半装状态、不留锁、不崩栈。

### ENOSPC 做法（不要往 C 盘写满）
```powershell
# 建 64MB 动态 VHD，挂载后格式化
$vhd = "D:\main\.tmp-b10\enospc.vhdx"
New-Item -ItemType Directory -Force D:\main\.tmp-b10 | Out-Null
diskpart /s <脚本: create vdisk file=... maximum=64 type=expandable; select; attach>
# 格式化并分配盘符（选一个空闲盘符，例如 X:）
Format-Volume -DriveLetter X -FileSystem NTFS -Confirm:$false
```
然后把 `InstallTransaction` 的 `skill_root` 指到 `X:\`，写入一个大文件把盘填到只剩几 MB，再触发一次安装（技能包大于剩余空间）→ 断言：
1. 安装返回**受控失败**（有错误码/错误消息），**不是未捕获异常栈**；
2. `X:\` 上**没有**"已激活但缺文件"的目录（激活指针与内容一致）；
3. 失败后再次安装（换到有空间的盘）能成功 —— 证明失败没污染状态；
4. staging 残骸按 A4.5 规则处理（归档优先，归档失败保留现场）。

**收尾必须做**：`Dismount-DiskImage` + `Remove-Item` 删掉 `.vhdx`，并验证 `X:` 已消失。收尾失败要在文档里标红。

### ACL 做法
对隔离目录 `D:\main\.tmp-b10\acl-target` 执行 `icacls` 拒绝当前用户/`Everyone` 的写权限，触发安装 → 断言：受控失败、无未捕获栈、锁已释放（`SkillInstallLock` 不残留）、撤销 ACL 后能正常安装。

> 注意：以管理员身份运行时，某些 ACL 会被特权绕过。**若实测发现拒绝不生效，必须如实写"本机无法构造真实 EACCES"并标 BLOCKED_OS**，不许换成 mock 再标 PROVEN。

### 用例
1. **A5-ENOSPC-01** · 真实 `ENOSPC` 下安装失败是受控的
2. **A5-ENOSPC-02** · 失败后无半装状态（激活指针与 content digest 一致）
3. **A5-ENOSPC-03** · 换盘重试成功（幂等恢复）
4. **A5-ACL-01** · 真实拒绝写：受控失败、锁释放
5. **A5-ACL-02** · 恢复权限后安装成功

### 反作弊
- ❌ 用 `mock` 抛 `OSError(errno.ENOSPC)` 然后标 PROVEN —— 那是注入式，只能标 INJECTED_ONLY。
- ❌ 把 `X:` 留在地上不说。
- ❌ 在 C 盘制造满盘。

---

## 7. T5 · 独立构建镜像摘要

### 先探测
```powershell
docker info --format '{{.ServerVersion}}'
```

### 分支 A：daemon 可用
1. 新增 `my-bagisto-store/docker/build/Dockerfile`：PHP 8.3 CLI + `zip` / `mbstring` / `pdo_mysql` 扩展，用于技能构建工具链。
2. 新增 `tools/build-image.sh`（或 `.ps1`）：`docker build` → `docker image inspect --format '{{index .RepoDigests 0}}'` 取 **真实 digest**。
3. 让 `ToolchainFingerprint::current()` 在环境变量 `SKILL_BUILD_IMAGE_DIGEST` 存在时写入 `{image_ref, image_digest, image_status:"PROBED"}`；不存在时仍写 `null` + `NOT_RUN`（**不许伪造 digest**）。
4. 用例：真实构建 → digest 形如 `sha256:[0-9a-f]{64}`，且落进 `skill_build_runs.toolchain_json`。

### 分支 B：daemon 不可用（当前状态大概率是这个）
1. 代码照上面第 3 步实现（**开关默认关**，`SKILL_BUILD_IMAGE_DIGEST` 不设即为 null）。
2. 用例覆盖两种输入：给 digest → 正确落库；不给 → `null` + `NOT_RUN`。
3. 在文档里写：`BLOCKED_ITEM=独立构建镜像摘要 / REASON=Docker daemon 未运行 / BLOCKING_SOURCE=本机 Docker Desktop 未启动 / WHAT_WAS_NOT_RUN=真实镜像构建 / MINIMUM_UNBLOCK_ACTION=启动 Docker Desktop 后执行 tools/build-image.sh / CURRENT_EVIDENCE_LEVEL=NOT_RUN`

### 反作弊
- ❌ 手写一个 64 位十六进制常量当 digest。
- ❌ 把"代码路径已实现"说成"镜像已验证"。

---

## 8. T6 · 沙箱接入执行路径

### 现状（先确认，别猜）
`agent/zhjj_agent/sandbox.py` 有 `SandboxRunner` 抽象 + `SubprocessSandboxRunner`，`agent/tests/test_sandbox.py` 8 项通过，但 **`executor.py` / `task_manager.py` / 所有 adapters 都没有 import 它**（`grep -rn "subprocess\|Popen" adapters/ executor.py` 为空）。即"接口写好了但没人用"。

### 接线要求（最小、可回滚、默认关闭）
1. `SkillExecutor.__init__` 增加可选 `sandbox_runner: Optional[SandboxRunner] = None`。
2. 新增开关 `ZHJJ_REQUIRE_SANDBOX`（**默认关闭**）。开启且 `sandbox_runner is None` → `execute()` **fail-closed**：直接返回 `(False, EXEC_FAILED, "SANDBOX_UNAVAILABLE")`，**不调用 adapter**，**不占租约**。
3. `BaseInstallAdapter` 增加受保护方法 `run_local_process(argv, cwd, limits)`：默认实现要求 runner 存在，否则抛明确异常。**未来任何 adapter 想启本地进程只能走这里。**
4. **不变量必须保持**：`SandboxResult.physical_stop_confirmed` 恒 `False`、`is_safety_evidence` 恒 `False`。沙箱结果**永远不能**当作物理停止证据。
5. `capabilities()` 必须如实报告"实际强制了什么"，没强制的写没强制（不要写"已隔离"）。

### 用例
1. **A6-WIRE-01**：开关开 + 无 runner → 拒绝执行，adapter **零调用**（用计数器证明），租约表无残留。
2. **A6-WIRE-02**：开关开 + 有 runner → 执行路径拿到 runner，结果对象带 `is_safety_evidence=False`。
3. **A6-WIRE-03**：开关关（默认）→ 行为与接线前**完全一致**（回归，不能因为这次改动影响既有用例）。
4. **A6-WIRE-04**：`run_local_process` 在无 runner 时抛明确异常，而不是静默降级到裸 `subprocess`。

### 反作弊
- ❌ 把 `SubprocessSandboxRunner` 写成"OS 级隔离已实现"。它只是**受控子进程**：超时/输出上限/环境白名单/工作目录，**没有** seccomp/cgroup/namespace。
- ❌ 因为"没有 adapter 真的起进程"就跳过 T6 —— 接线本身就是要**堵住未来绕过的路**。

---

## 9. 证据与留档（格式必须与第八批一致）

目录：`qa/wbs-20261105/`，新文件前缀 `b10-`；明细日志放 `qa/wbs-20261105/logs-b10/`。

每个交付项都要有：

| 字段 | 要求 |
|---|---|
| CLAIM_ID | 如 `A3-HTTP-01` |
| 断言原文 | 一句话，可被第三方独立复算 |
| 命令 | 完整可复制，含 `$env:DB_DATABASE` |
| 退出码 | 必须贴 |
| 原始输出文件 | `qa/wbs-20261105/b10-xxx.txt` |
| SHA256 | `Get-FileHash` 的结果前 8 位 |

交付物清单：
1. `qa/wbs-20261105/b10-*.txt` —— 每个任务组一个原始输出
2. `qa/wbs-20261105/b10-concurrency-evidence.json` —— 并发用例的端口/进程数/请求数/最终计数值
3. `qa/wbs-20261105/b10-php-regression.txt` —— 全量按文件回归（复制 `run-a8a9-regression.ps1` 改成 `run-b10-regression.ps1`）
4. `qa/wbs-20261105/b10-python-regression.txt`
5. `qa/wbs-20261105/red-green-b10.txt` —— 变异记录
6. 更新 `qa/wbs-20261105/evidence-manifest.json`（跑 `regen-manifest.py`，记录清单自身 SHA）
7. `docs/skill-dev-step10-wbs-tenth-batch-20261105.md` —— 交付文档，章节照第八批：结论 / 改动清单 / 证据 / red→green / 未做与 BLOCKED / 环境风险 / 回滚
8. 更新 `docs/skill-dev-steps.md` 的【进度快照】一行

---

## 10. red → green（至少 4 处，必须真做）

| 建议变异 | 期望观察 |
|---|---|
| 把计数器原子累加改成"读-改-写" | A3-HTTP-01 红（丢更新） |
| 把 gauge 覆盖改成累加 | A3-HTTP-03 红（出现 600） |
| 去掉 `(did,batch_id)` 幂等 | A3-HTTP-02 红（变成 +6） |
| 发布时去掉唯一约束/事务护栏 | B3-HTTP-01 红（重复行） |
| `issue()` 去掉 `lockForUpdate()` | A7-CONC-01 红（key 数 < 6） |
| T6 检查开关时改成 `pass` | A6-WIRE-01 红（adapter 被调用） |

要求：
- 每次变异前 `Copy-Item` 备份到 `.claude/backup/b10-<序号>/`，还原后用 `cmp`/哈希逐字节确认。
- 结束前全仓 `grep -rn "MUTATION"` 必须**零结果**。
- **首跑即绿的用例不许编造 red**。像上一批那样，明确写"该项无单次 red，原因如下"。

---

## 11. 红线（违反任意一条，整批作废）

1. **Git**：不许 `git add -A` / `commit` / `reset` / `clean` / `stash` / `checkout`。工作区已有前九批大量未提交改动，**一次 checkout 就全没了**。`git status --porcelain` 只读可以。
2. **数据库**：只准 `summon_mecha_step10_test`。`root` 库禁写、禁迁移、禁 truncate、禁 seed。
3. **不许删改前九批证据**（`qa/wbs-20261105/` 下已有文件）。
4. **不许把未执行/缺依赖的项标 PASS**。这是本项目真实发生过的错误。
5. **不许**用 Mock / SIL / 脚本断言 / cloud ACK 冒充物理停止；不许在没有独立 `StopObservation` 时进终态。
6. **不许**碰厂商云、不许真机、不许物理急停。
7. **新开关默认关闭**（`ZHJJ_REQUIRE_SANDBOX`、`SKILL_BUILD_IMAGE_DIGEST` 等）。
8. **不许**因为跑不过就降低断言强度（如把精确值改成 `>0`）。
9. 术语纪律：`skill_versions.channel`（发布通道）≠ `delivery_channels`（交付通道）；`pricing_mode='buyout'`，**禁止** `license_mode='perpetual'`；`carrier_type` 以 `SkillAsset::CARRIER_TYPES` 为准。

---

## 12. 汇报格式（最后一条消息必须长这样）

```
## 一、逐项声明
| CLAIM_ID | 声明 | 证据 | 等级 |

## 二、改动清单
| 文件 | 改动 | 新增/修改 |

## 三、回归与门禁
（PHP 文件数/用例数/断言数、Python 用例数、php -l、契约门禁、数据 preflight）

## 四、red → green

## 五、未做 / NOT_PROVEN / BLOCKED（六字段表）
| BLOCKED_ITEM | REASON | BLOCKING_SOURCE | WHAT_WAS_NOT_RUN | MINIMUM_UNBLOCK_ACTION | CURRENT_EVIDENCE_LEVEL |

## 六、验收三问
功能健康吗？这步真完成了吗？符合战略吗？

## 七、等级（本批结束时）
软件公共底座 / Agent / Compiler / SIL / HIL / Production Ready / 五机型 GA
```

---

## 13. 开工前自检（做完再动手）

- [ ] 已读 §2 全部文件
- [ ] `$env:DB_DATABASE` 已确认是 `summon_mecha_step10_test`（打印出来看）
- [ ] `git status --porcelain` 已存档到 `qa/wbs-20261105/b10-git-status-before.txt`
- [ ] 已跑一次基线回归并存档（作为"改动前全绿"的证据）
- [ ] 已确认 `php -S` 能在本机起 6 个实例且都返回 200
- [ ] 已确认 Docker daemon 状态，据此选择 T5 分支

**完成后同样保存 `b10-git-status-after.txt`，并确认只多出本批该有的文件。**
