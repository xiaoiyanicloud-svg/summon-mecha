# 技能平台 · 步骤 6 交付说明（技能供给侧 · 除类目/上传界面外）

> 日期：2026-10-13 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 6 步
> 状态：**✅ 步骤 6 完成** —— 准入层（包格式/审核三层/质量）+ 转换层 + 导入器 + 包解析 + 类目体系 + 接口接线，全部交付并实测通过。
> 架构原则：小服务 + 值对象 + 配置注册表（对齐 `skill_delivery.php` 既有套路），不碰 v1 文件。

---

## 一、已交付（全部实测通过）

### 1 · 准入层
| 组件 | 命名空间 | 职责 |
|---|---|---|
| `SkillManifest` | `Services\SkillPackage` | 中立清单**值对象**（schema/kind/权限白名单/资源类型） |
| `ManifestValidator` | `Services\SkillPackage` | 契约校验（返回 valid/errors/warnings） |
| `RejectionReason` | `Services\SkillAudit` | **拒绝清单**（10 个码 + 人话） |
| `AuditService` | `Services\SkillAudit` | **三层准入**：L0 机审 / L1 仿真 / L2 人审 + `isFullyApproved` |
| `QualityService` | `Services\SkillQuality` | 质量分级（qualified/good/featured）+ 降级复审判据 |

### 2 · 转换层（计划 §4.20 核心）
| 组件 | 命名空间 | 职责 |
|---|---|---|
| `CarrierBuilder` | `Services\SkillCompile` | 载体构建器接口 |
| `BuildResult` | `Services\SkillCompile` | 构建结果值对象 |
| `MotionCarrierBuilder` | `Services\SkillCompile\Builders` | 动作类：登记重定向任务（产物由重定向工具回填） |
| `SkillBuildService` | `Services\SkillCompile` | **编排**：中立定义 + N 目标机型 → skill_build 记录 |
| `config/skill_builders.php` | — | 构建器注册表 |

### 3 · 导入器（"他平台技能可导入"）
| 组件 | 命名空间 | 职责 |
|---|---|---|
| `SkillImporter` / `ImportResult` | `Services\SkillImport` | 接口 + 值对象 |
| `UnitreeImporter` | `Services\SkillImport\Importers` | 宇树 `metadata.yaml` → 中立 manifest（**自动锁 unitree_g1**） |
| `RawMotionImporter` | `Services\SkillImport\Importers` | BVH/SMPL-X/FBX → motion manifest |
| `SkillImportService` | `Services\SkillImport` | 识别选择 + 编排 + `skill_import` 留痕 |
| `config/skill_importers.php` | — | 导入器注册表 |

### 4 · 补建的迁移
`2026_10_13_000006_create_skill_import_table`（**发现遗漏并补上** —— 测试抓出来的）。

---

## 二、实测结果

| 探针 | 结果 |
|---|---|
| 合法 manifest / 非法 manifest | valid=1 / 7 个错误 ✅ |
| L0 机审 | 合法→过(1) / 非法→拒(2) ✅ |
| L1 仿真 | 摔倒→拒 / 通过→过 ✅ |
| 三层全过判定 | 是 ✅ |
| 质量分级 | 高分→featured / 低分→qualified ✅ |
| 转换层编译 | 3 机型→3 条 pending、carrier=motion_data、重定向配置已记、**幂等** ✅ |
| 导入·宇树 | ok、base_image 映射、**锁 unitree_g1** ✅ |
| 导入·BVH | ok、kind=motion、format=bvh ✅ |
| 导入·未知格式 | 正确拒绝 ✅ |
| 语法 / 迁移 / 回滚 | 全过 / DONE / 无脏数据 ✅ |

---

## 三、诚实边界（不夸大）

- **动作重定向算法**是独立工具/服务，`MotionCarrierBuilder` 只做**登记与编排**，产物由重定向工具回填（status=pending）。**没把算法硬塞进平台层**——这是为架构健康做的取舍。
- ~~**内容安全词表**未硬编码大词库（`matchForbidden` 是占位）~~ → **已补齐（2026-10-14）**，见下方 §三之二。
- **L1 仿真**接收仿真器结果（由外部跑），平台侧做判定——仿真器接入是后续。

---

## 三之二、内容安全层（2026-10-14 补 · 原先是空数组占位）

**原先的状态**：`AuditService::matchForbidden` 遍历的是一个**空数组** —— L0 的内容筛查什么都没拦。

**现在**（新增 `Services/ContentSafety/`，注册表驱动，对齐 `skill_delivery.php` 套路）：

| 组件 | 职责 |
|---|---|
| `ContentScanner`（接口） | 扫描器契约，加一种实现 = 加一个类 + 配置注册一行 |
| `LocalKeywordScanner` | **本地词表**扫描：子串匹配 + `re:` 正则；词表放**文件**（运维/合规方可改，不必发版） |
| `ScanResult`（值对象） | 区分 **`passed`（扫过没问题）** 与 **`degraded`（根本没扫成）** —— 两者绝不可混淆 |
| `ContentSafetyManager` | 按 `config/content_safety.php` 解析驱动；配了未注册的驱动**显式报错**，不静默退回 |
| 词表种子 | `Resources/data/forbidden-words.txt`（**种子表**，上线前须合规方补全） |

**三条关键设计决定**：

1. **「没查」≠「合规」**：词表缺失/驱动不可用时返回 `degraded`，并按 `on_missing_words`（默认 `fail`）**拒绝该次提审**，理由码 `CONTENT_UNSCREENED`（新增）。放行会把未筛查内容推上架 —— 比"上传被拦"严重得多。配成 `pass` 时也会在 `notes` 里留"未扫描"的痕迹。
2. **堵住绕过口子**：原先只扫 manifest 的 `name`/`description`，开发者把违规文案塞进**别的字段**就绕过了。现在**递归收集全部字符串值**（含深度上限防御）。
3. **正则随手可用**：`re:` 行支持**裸模式**（自动包定界符 + 补 `u`）。⚠ 不补 `u` 时 `.` 按**字节**匹配，一个汉字占 3 字节，中文规则会永远匹配不上 —— 这是实测踩出来的。

**实测**：`ContentSafetyTest` **11 条全过**（含"绕过口子回归"与"词表缺失时拦截"）；用**仓库默认配置**端到端验过 —— 干净包 L0 `result=1`，含违禁词的包 `result=2` 且理由为 `CONTENT_VIOLATION:命中 [赌博]（驱动 local）`。

**仍未做（外部依赖）**：接微信 `msgSecCheck`（需小程序 appid + access_token，且有网络依赖）。配置里已留 `wechat` 驱动的注册位。**在它接通前，不要对外声称"内容安全完备"** —— 本地词表挡不住谐音、拆字、图片与音频。

---

## 四、待做（步骤 6 剩余）

### 5 · 包解析（`.skill` zip → 清单）
`ManifestParser`(JSON 原生 / YAML 检测库后启用) + `PackageInspector`(只读清单不落地、黑名单拒绝)。
> ⚠ **YAML 依赖受阻**：本项目 composer 安全策略**拦下了所有版本的 `symfony/yaml`**（连最新版都在 advisory 列表）。**没绕过它** → 改用**中立清单同时支持 JSON 与 YAML**：JSON 原生可用；YAML 一旦依赖可用即自动生效（代码零改动）。**不手搓 YAML**（那才是毁架构）。

### 6 · 类目体系
`SkillCategory`/`SkillTag` 模型 + `SkillCatalogSeeder`（5 类目 + 12 标签）+ 公开接口 `GET /api/skill-catalog/categories|tags`。

### 7 · 接口接线（internal.key）
`SkillSupplyController`：`inspect`(包检查) / `import`(导入) / `compile`(转换层) / `submit`(L0提审) / `review`(L1·L2) / `builds`(编译记录)。
→ **审核链、转换层、导入器现在都能被真实调用了。**

---

## 五、步骤 6 收尾实测

| 探针 | 结果 |
|---|---|
| PackageInspector·合法包 | ok，文件列表正确 ✅ |
| PackageInspector·含 `.sh` | 拒绝（黑名单）✅ |
| PackageInspector·缺清单 | 拒绝 ✅ |
| PackageInspector·缺 safety | 拒绝（契约校验）✅ |
| 类目/标签种子 | 5 类目 + 12 标签 ✅ |
| skill-supply / skill-catalog 路由 | 全部注册 ✅ |
