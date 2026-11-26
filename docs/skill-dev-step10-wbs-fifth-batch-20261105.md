# WBS 无外部硬件依赖工作包 · 第五批交付（2026-11-05）

> 范围：**B4 源包安全（ZIP Slip / 软链 / 压缩炸弹 / 保留名冒用 / 磁盘满）**、
> **B5 构建确定性（双目录双进程）**、**B6 MotionCarrier 登记 fail-closed**。
> 环境：PHP 8.4.24（目标 8.3，本机未装，偏差已记）/ Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）。

## 0. 结论

- **B4 完成**：源包读取从编译器内部散落的校验抽成单一安全核 `SourcePackageReader`；
  路径穿越（`..` / 绝对路径 / 盘符 / 反斜杠 / `.` 段 / 双斜杠 / 控制字符）、Unix 软链接条目、
  加密条目、压缩炸弹（声明大小 + 实际流式字节 + 压缩比三闸）、保留名冒用全部拒绝，且**每条拒绝都有机器码**。
- **B4 额外发现一条真实漏洞并修复**：源包里的素材可以**顶替平台生成的 `artifact.json` / `action.py`** ——
  旧代码把源素材合并在平台文件之后，等于让上传者改写产物元数据（`build_key` / `source_digest`）与执行脚本。
  现改为**两层防御**：读取层拒绝保留名，打包层拒绝任何同名覆盖。
- **B4 磁盘满 / 写截断 fail-closed**：产物落盘后**回读校验**（存在性 + 字节数 + sha256），
  不一致就删掉半成品并拒绝登记资产 —— 不再出现"行上哈希是 A、盘上是残缺 B"。
- **B5 完成**：两个**独立 PHP 进程**、**不同存储根目录**、**不同临时目录**、**不同版本 id**，
  构建出**同一个 `build_key`** 与**逐字节相同的产物**（`package_sha256` 一致，9 个条目名/大小/mtime 全等）。
  同时把"缺 `ZipArchive::setMtimeName()`（PHP < 8.3）"从**静默产出不可复现包**改成 **fail-closed 报错**。
- **B6 完成**：动作类登记 fail-closed —— 格式白名单 / fps 范围 / 文件路径与扩展名一致 / 时长不超安全上限 /
  **目标机型必须 GA**，任一不过即 `failed` + 机器码；合法登记仍是 `pending`（平台侧无生产者，D-9 口径不变）。
- 本批 **新增 32 项测试全过**（B4 16 + B5 4 + B6 12）；
  **Deploy 目录 29 个文件 228 项回归全过**；门禁 `ecosystem:contract-check` / `data-preflight` 通过。
- **诚实缺口**：HTTP 层源包上传→编译整链路未做并发/压力测试；`setMtimeName` 缺失分支无自动化用例（仅代码守卫 + 说明）；
  真机 HIL / 生产 FPM·MySQL / KMS 仍 BLOCKED。另见 §7 的三条**环境级风险**。

## 1. 改动清单

### B4 · 源包安全

| 文件 | 改动 |
|---|---|
| `Services/SkillCompile/Portable/SourcePackageReader.php`（新增） | 唯一安全核：体积/条目数/单条/总量/压缩比预算；路径穿越、软链、加密、保留名四类拒绝；`isSafeRelativePath()` 公开供复用 |
| `Services/SkillCompile/Portable/SourcePackageRejected.php`（新增） | 带 `reasonCode` 的拒绝异常，码表与 Reader 常量一一对应 |
| `Services/SkillCompile/Builders/PortableSkillBuilder.php` | 注入 Reader；`resolveSourcePackage()` 改为返回 `{failure|xml_content,assets,source_asset}`，把拒绝码带进 `BuildResult::errorCode`；打包阶段单独捕获 `SourcePackageRejected` |
| `Services/SkillCompile/Portable/TargetAppBuilder.php` | ① 源素材与生成文件同名 → 拒绝（防顶替 `artifact.json`/`action.py`）；② 落盘后**回读校验**（大小 + sha256），失败即删盘 + 抛错；③ 条目写入失败/时间戳固定失败/收尾失败全部 fail-closed；④ `setMtimeName` 缺失 → `BUILD_DETERMINISM_UNSUPPORTED` |
| `config/skill_build.php` | 新增 `source_package.*` 预算（64 MiB 包 / 256 条目 / 16 MiB 单条 / 64 MiB 总量 / 压缩比 100 & 1 MiB 阈值）|

### B5 · 构建确定性

- `TargetAppBuilder`：确定性 mtime 从"能设就设"改为"设不了就拒绝产出"（见上）。
- 新增探针 `qa/wbs-20261105/probes/b5_build_worker.php`（单进程真实编译）与 `b5_compare.php`（比对 + 清理）。

### B6 · MotionCarrier fail-closed

| 文件 | 改动 |
|---|---|
| `Services/SkillCompile/Builders/MotionCarrierBuilder.php` | GA 闸 + 格式白名单 + fps 范围 + 文件路径/扩展名 + 时长上限；输出 `motion_digest`（JCS）、`target_profile_digest`、`source_asset_id`/`source_digest` |
| `config/skill_build.php` | 新增 `motion.allowed_formats`（与 `SkillPackageBuilder::SUPPORTED_MOTION_FORMATS` 同值）、`min_fps` / `max_fps` |

### 附带（additive，不改变现有消费方）

- `Services/SkillCompile/SkillBuildService::compile()` 的每机型结果新增 `error_code` 字段；
  无匹配构建器时也带 `BUILD_BUILDER_NOT_FOUND`。`error` 仍是人话消息，控制器用法不变。

### 测试

- `tests/Feature/Deploy/SourcePackageSecurityTest.php`（16）
- `tests/Feature/Deploy/BuildDeterminismTest.php`（4）
- `tests/Feature/Deploy/MotionCarrierFailClosedTest.php`（12）

## 2. 安全语义（逐条）

| 威胁 | 判据 | 拒绝码 |
|---|---|---|
| ZIP Slip | 条目名出现 `..` / 空段 / `.` 段 / 双斜杠 / 前导 `/` / 盘符 / 反斜杠 / 控制字符 / 超长 | `SOURCE_PACKAGE_UNSAFE_ENTRY` |
| 软链接条目 | Unix external attributes 的文件类型位 = `0120000` | `SOURCE_PACKAGE_SYMLINK_ENTRY` |
| 加密条目 | `statIndex.encryption_method != 0` | `SOURCE_PACKAGE_ENCRYPTED_ENTRY` |
| 保留名冒用 | `artifact.json`（读取层）/ 任何与产物同名（打包层） | `SOURCE_PACKAGE_RESERVED_ENTRY` |
| 压缩炸弹 | 单条实际读取 > 16 MiB；全包实际 > 64 MiB；解压后 > 1 MiB 且压缩比 > 100 | `SOURCE_PACKAGE_ENTRY_TOO_LARGE` / `_TOTAL_TOO_LARGE` / `_BOMB` |
| 体积/条目过多 | 包 > 64 MiB / 条目 > 256 | `SOURCE_PACKAGE_ARCHIVE_TOO_LARGE` / `_TOO_MANY_ENTRIES` |
| 入口问题 | 缺入口 / 入口为空 / 入口路径非法 | `SOURCE_PACKAGE_ENTRYPOINT_MISSING` / `_ENTRYPOINT_INVALID` |
| 磁盘满/写截断 | 落盘后回读：不存在 / 大小不符 / sha256 不符 | `ARTIFACT_WRITE_FAILED` / `ARTIFACT_WRITE_VERIFY_FAILED` |
| 不可复现运行时 | 缺 `ZipArchive::setMtimeName()` | `BUILD_DETERMINISM_UNSUPPORTED` |

> 预算值**不是功能开关**：默认值即生效值（`config('skill_build.source_package.*')` 可被 env 放大，但默认收紧）。
> 理由写在配置注释里：安全上限若"默认关闭"，等于默认不设防。

## 3. 证据

| CLAIM_ID | 内容 | 命令 / 退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| B4-T-01 | B4 16 项全过 | `pest SourcePackageSecurityTest` / 0 | 见 manifest | — |
| B5-T-01 | B5 4 项全过 | `pest BuildDeterminismTest` / 0 | 见 manifest | — |
| B6-T-01 | B6 12 项全过 | `pest MotionCarrierFailClosedTest` / 0 | 见 manifest | — |
| B4B5B6-REG-01 | Deploy 目录 29 文件 / 228 项全过（按文件分进程） | 循环 `pest <file>` / 全 0 | [b4b5b6-deploy-regression.txt](qa/wbs-20261105/b4b5b6-deploy-regression.txt) | `A73C206E…` |
| B5-DET-01 | 双进程 / 双存储根 / 双临时目录 → `build_key` 与 `package_sha256` 完全一致（9 条目 mtime 全为 1700000000） | 两个独立 `php b5_build_worker.php` + `b5_compare.php` / 0 | [b5-determinism-evidence.json](qa/wbs-20261105/b5-determinism-evidence.json) | `C9303E9F…` |
| B4B5B6-GATE-01 | `php -l` 10 文件全 OK + 契约门禁 + 数据 preflight | `/0` | [b4b5b6-gates.txt](qa/wbs-20261105/b4b5b6-gates.txt) | `7E5CF083…` |
| B4-RG-01 | 关闭路径校验 → 5 红；恢复 → 5 绿 | `pest --filter="B4-0[2-6]"` / 1→0 | [red-green-b4.txt](qa/wbs-20261105/red-green-b4.txt) / [green](qa/wbs-20261105/red-green-b4-green.txt) | `7E22D0E3…` / `E8B64426…` |
| B4-RG-02 | 同时关闭两层保留名校验 → 2 红；恢复 → 2 绿 | `pest --filter="B4-0[89]"` / 1→0 | [red](qa/wbs-20261105/red-green-b4-reserved.txt) | `7596A537…` |
| B5-RG-01 | 把固定 mtime 换成随机值 → 2 红；恢复 → 4 绿 | `pest --filter="B5-0[12]"` / 1→0 | [red](qa/wbs-20261105/red-green-b5.txt) / [green](qa/wbs-20261105/red-green-b5-green.txt) | `558A9DB3…` / `A9E7765A…` |
| B6-RG-01 | 关闭 GA 闸 → 2 红（且暴露为**未捕获异常**而非失败码）；恢复 → 2 绿 | `pest --filter="B6-(09\|10)"` / 1→0 | [red](qa/wbs-20261105/red-green-b6.txt) | `4369380F…` |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)（57 条，`06DB0FE2…`）。

最终一次合并跑（3 个测试文件 + 跑后库状态）：[b4b5b6-final.txt](qa/wbs-20261105/b4b5b6-final.txt) / `F169F492…`。

## 4. red → green（本批真实做过的那一部分）

| 变异 | 观察 | 说明 |
|---|---|---|
| 关掉 `isSafeRelativePath` 校验 | B4-02~06 **5 红** | 证明这 5 条确实在测"路径校验"，而不是测别的东西 |
| 同时关掉读取层与打包层保留名检查 | B4-08/09 **2 红** | 证明两层各自都是必要的（只关一层仍会被另一层拦住） |
| 把确定性 mtime 换成 `random_int()` | B5-01/02 **2 红** | 证明"同输入同字节"是被断言住的，不是碰巧 |
| 关掉 MotionCarrier GA 闸 | B6-09/10 **2 红**，且是 `InvalidArgumentException`（`agibot_a2` 无 Profile） | 说明缺这道闸不是"少个提示"，而是**把异常抛给调用方** |

**诚实标注**：其余用例是**先写实现、后写测试**，首跑即绿，**没有**单次 red 记录（B4-01/07/10~16、B5-03/04、B6-01~08/11/12）。

## 5. 明确未做 / NOT_PROVEN

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| HTTP 层"上传源包 → 编译"整链路的恶意包压测 | **NOT_PROVEN** | 本批在服务层与 builder 层验证；未对 `POST /api/skills/versions/{id}/compile` 打恶意包做端到端断言（该端点已在 B1 批次覆盖路由与幂等） |
| `setMtimeName` 缺失分支的自动化用例 | **NOT_PROVEN** | 当前运行时（PHP 8.4）有该方法；只能在 PHP < 8.3 上真实验证 |
| 磁盘真实写满 | **NOT_PROVEN** | 未制造真实 ENOSPC；以"写入返回 false / 回读不一致"两条分支覆盖，并用回读校验兜底 |
| 真机 HIL / 生产 MySQL·FPM / KMS·HSM | **BLOCKED** | 外部前提 |
| A3~A7 | **NOT_RUN** | 不在本批 |
| 全量 Pest 一次跑完 | **NOT_RUN** | 项目既有约束（进程级 `set_time_limit` 60s），一律按文件分进程 |

## 6. 等级（本批结束时）

- 软件公共底座：**PARTIAL**（源包安全与构建确定性补齐，仍缺 A3~A7）。
- Agent：**PARTIAL**（A1/A2.4 完成；真机观察器 BLOCKED）。
- Compiler：**PARTIAL → 本批后仅差 A 系**（B1~B8 全部有实现与证据；B4/B5/B6 本批完成）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 7. 环境级风险（本批发现，必须让接手的人知道）

1. **`.env` 的 `DB_DATABASE` 现在是 `root`**（不是隔离测试库）。
   直接跑 `vendor/bin/pest` 会连到 `root` 库。本批**所有**测试与探针都显式加了
   `DB_DATABASE=summon_mecha_step10_test`（Laravel 的 env 加载是 immutable，进程环境变量优先于 `.env`），
   并已用探针实测确认连接库名。**建议**：把测试库固化到 `phpunit.xml` 的 `<env name="DB_DATABASE" .../>`，
   或在 CI/本地脚本里统一注入，别依赖 `.env` 的当前值。
2. **隔离库 `summon_mecha_step10_test` 是"裸迁移"状态**：`categories=1 / locales=1 / currencies=1`，
   而 `root` 库是 `11 / 21 / 65`。因此**依赖默认类目/多语言播种**的少数用例在本库里必失败，
   与本批改动无关。
3. 由此产生的 3 个**环境性失败**（本批实测，均不在本批改动的调用路径上）：
   - `SkillAudit/PackageIntegrityTest`「换包后重新提审 → 发布成功」：断言 catalog 搜索命中（`pkgSearchIds`）
   - `SkillAudit/SupplyChainFieldsTest`「只有非文件类交付物（cloud_config）仍可发布」：同上（`scfSearchIds`）
   - `SkillAudit/ManifestSchemaVersionTest`「Admin 导入路径仍照常写 manifest_schema」：`category_id cannot be null`
   状态标注：**PRE_EXISTING_SUSPECTED（未证明）** —— 根因指向播种缺失；但项目红线禁止 `git stash/checkout`，
   无法在同一工作树做出干净基线对照。**最小验证动作**：给隔离库补默认播种后重跑这 3 个文件。

## 8. 回滚

本批**无迁移**。回滚步骤：

1. 删除 `SourcePackageReader.php`、`SourcePackageRejected.php`；
2. 还原 `PortableSkillBuilder`（去掉 Reader 注入与 `resolveSourcePackage` 结构化返回）、
   `TargetAppBuilder`（去掉同名拒绝与回读校验）、`MotionCarrierBuilder`（还原为仅登记）、
   `SkillBuildService::compile()` 返回值、`config/skill_build.php` 的 `source_package` / `motion` 段；
3. 删除 3 个测试文件与 `qa/wbs-20261105/probes/b5_*.php`。

回滚后重跑 §3 的命令。未执行任何 git 写操作，未触碰 `root` 库。
