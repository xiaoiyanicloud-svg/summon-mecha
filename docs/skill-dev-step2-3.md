# 技能平台 · 步骤 2–3 交付说明（技术前置 + 数据底座）

> 日期：2026-10-13 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 2–3 步
> 状态：**✅ 步骤 2–3 全链路完成**（数据底座迁移已执行并核验；设备绑定实测通过；异步底座用数据库驱动跑通）。

---

## 一、交付术语表（第 2 步 · 冻结）

> **改这块代码前先看这张表；不允许两套词混用。**

| 术语 | 取值 | 说明 |
|---|---|---|
| `skill_versions.channel` | `stable` / `beta` | **发布通道**（v1 已有，不动） |
| `skill_versions.delivery_channels` | JSON `["agent","vendor:{code}","manual"]` | **交付通道**声明（v3.4 新增） |
| `skill_entitlements.delivery_channel` | `app` / `agent` / `vendor:{code}` | 单据实际交付通道；**`app` ≡ 计划的 `manual`**（v1 事实，不改名） |
| `skill_assets.carrier_type` | `file` / `cloud_config` / `remote_service` / `api_bundle` / **`motion_data`** / **`app_package`** / **`vendor_package`** | 载体类型（v3.4 扩 3 个） |
| `skill_versions.license_mode` | `per_device` / `per_order` / `per_account` / `unlimited` | **授权范围**（v1 已有，不动） |
| `skill_versions.pricing_mode` | `buyout` / `free` | **定价模式**（买断用这个，**不要**写 `license_mode='perpetual'`） |
| 设备真相源 | `device_binding` | 下发只认它；`devices` 退为登记视图 |

---

## 二、代码已交付（本次）

### 第 3 步 · 数据底座（迁移，5 个文件）
| 迁移 | 建了什么 |
|---|---|
| `2026_10_13_000001_add_v34_columns_...` | `skill_versions` 加 6 列（delivery_channels/risk_level/pricing_mode/manifest_schema/source_format/imported_meta）＋ `devices` 加 `binding_id` |
| `..._000002_create_device_model_and_binding_tables` | `device_model`、`device_binding`（含 national_code 一机一码） |
| `..._000003_create_deploy_and_audit_tables` | `deploy_task`、`install_record`、`audit_record`（含 layer 三层）、`security_scan` |
| `..._000004_create_skill_asset_tables` | `skill_category`、`skill_tag`、`skill_tag_pivot`、`skill_search_index`、`skill_build`、`skill_quality` |
| `..._000005_create_ops_tables` | `notification`、`notify_template`、`after_sale`、`refund_request` ＋ `skill_entitlements(status,expires_at)` 索引 |

**共 15 张新表 + 6 个新列 + 1 个索引补丁。** 全部 `hasTable`/`hasColumn` 守卫，可重复执行。

### 模型（新建 7 个）
`DeviceModel`、`DeviceBinding`、`DeployTask`、`InstallRecord`、`AuditRecord`、`SkillBuild`、`SkillQuality`

### 已有模型改动
- `SkillVersion`：fillable/casts 补上新 6 列
- `SkillAsset`：`CARRIER_TYPES` 扩 3 个（`motion_data`/`app_package`/`vendor_package`）——**上传白名单自动生效**（控制器用 `implode(',', SkillAsset::CARRIER_TYPES)`）

### 第 2 步 · 设备真相源（加性 → **2026-10-14 完成读写迁移**）
- 新增 `Services/SkillDelivery/DeviceBindingService.php`：用户登记设备时**补建 binding + 回填 `devices.binding_id`**（事务保证归属一致）。
- `DeviceController::store` 接入（**吞异常**，不影响"登记设备"主链路）。
- ~~**不动 v1 现有以 `devices.customer_id` 为准的读逻辑**~~ → **已按计划完成迁移**，见下：

| 项 | 内容 |
|---|---|
| 统一归属入口 | `DeviceBindingService::resolveForCustomer($device, $customerId, bool $heal)` —— **binding 优先**：有 binding 就以 `owner_customer_id` 为准（分叉时以此判）；**读接口 heal=false（GET 不写库）**，写接口 heal=true |
| v1 三接口 | `mine`（透出 `device_truth_source: binding / registration_only`）、`bindDevice`、`confirmInstall` 全部改走该入口；后两者把真相源 `did` 记进授权 `meta`（`bound_did` / `installed_did`），与第 8 步下发链路的凭据对齐 |
| 兼容性校验 | `CompatibilityService` 同样走该入口（只读），未确权时**如实提示**"自动下发不可用"，不让用户以为能一键装 |
| 历史设备自愈 | 之前登记的 devices 没有 binding；写接口会**补建**（自愈），另提供命令 `php artisan skill:backfill-bindings [--dry-run]` 一次性收口（**幂等**，只处理 `bind_status=bound`，不动已解绑设备） |

**为什么必须做**：第 8 步的下发链路**只认 binding**（`deploy_task.did` 来自它）。
v1 若还按 `devices.customer_id` 判归属，两条口径会分叉——**指派得到、下发发不出去**。

**实测（2026-10-14）**：`SkillBindingSourceTest` 5 条全过；真机回填把本地 `devices` 带 binding 的从 1 → 3，
复跑 dry-run 显示"补建 0 / 已有跳过 3"（**幂等已验**）。

---

## 三、运维需做的（**待办，本次未做**）

### O1 · 执行迁移（MySQL 起来后）
```bash
# 1) 先启动 phpstudy 的 MySQL(当前 3306 未监听)
# 2) 跑迁移
cd my-bagisto-store && D:/php.exe artisan migrate
# 3) 验证 15 张表
D:/php.exe artisan migrate:status
```

### O2 · 异步底座（第 2 步）—— ✅ 已用「数据库驱动」完成（未用 Redis）
> **决策**：原计划用 Redis，但本机 **Redis 未装、Docker 未运行**，而 **MySQL 已在跑**。Laravel 的队列/缓存**支持数据库驱动**，且 `jobs/job_batches/failed_jobs` 表早已存在 → **改用数据库驱动**：零新基础设施、立刻可用、将来 Redis 就绪时**改 2 行 env 即可切回**。

**已做的改动**（`.env`）：
```env
QUEUE_CONNECTION=database   # 原 sync
CACHE_STORE=database        # 原 file
```
+ 新建 `cache`/`cache_locks` 表（`artisan make:cache-table` + migrate）。

**实测结果**：
| 探针 | 结果 |
|---|---|
| `queue.default` / `cache.default` | database / database ✅ |
| 缓存 put/get | ok ✅ |
| **原子锁** `Cache::lock()`（试用回收幂等要用） | acquire ok ✅ |
| 入队 → `jobs` 表 +1 | ✅ |
| `queue:work` 消费 → `jobs` 归 0 | ✅（worker 正常消费） |

**→ 将来切 Redis（可选）**：确认 Redis 可达后，把上面 2 行改回 `redis` 即可，代码零改动。

**⚠ 部署时必须做的**（常驻进程，Windows 无 cron）：
```bash
D:/php.exe artisan queue:work          # 常驻消费队列(Windows 计划任务/守护进程)
D:/php.exe artisan schedule:work       # 常驻调度(试用回收每分钟靠它,第6步加任务)
```
> ⚠ **2026-10-15 就地更正**：本行原文写「现在还没加定时任务，所以调度常驻可稍后再起；
> 但第 6 步（试用回收）之前必须有它」——**两句都已失效**：`bootstrap/app.php`
> 现已排 4 条 ops 任务（`schedule:list` 实测 9 条），且试用回收走的**不是**调度器，
> 而是事件驱动。上面两条命令本身仍然正确，**只是本机至今没起**。
> 当前状态与验证方法见 [`skill-runbook.md`](./skill-runbook.md)。

### O3 · 对象存储（第 3 步的一部分）—— ✅ **代码侧已就绪（2026-10-14 补）**

**关键设计：换盘不改数据、不改业务代码。**
`skill_assets.file_path` 存的是**与盘无关的相对路径**（`skills/{product_id}/{version}/{name}`），
落哪个盘由 `config('skill_delivery.asset_disk')` 决定 —— 所以"切对象存储"= **搬字节 + 改一行配置**。

已完成：

| 项 | 内容 |
|---|---|
| 单一入口 | `SkillAsset::disk()` 成为唯一取盘处；原有 7 处写死的 `SkillAsset::DISK` 全部改为调它（含 Admin 侧） |
| 驱动 | **腾讯云 COS**：`overtrue/flysystem-cos ^5.2`。⚠ **不是 S3** —— S3 驱动依赖 `aws/aws-sdk-php`，被本项目 composer 安全策略**全部拦下**（多个 advisory），按项目一贯态度**不绕过**；COS 依赖链干净且与既有腾讯云栈一致 |
| 驱动注册 | `AppServiceProvider` 里 `Storage::extend('cos', ...)`（该包是纯 Flysystem 适配器，**不自带 Laravel 驱动注册**，缺这段会报 `Driver [cos] is not supported`） |
| 盘配置 | `config/filesystems.php` 的 `cos` 盘（app_id / secret_id / secret_key / region / bucket / prefix） |
| 迁移命令 | `php artisan skill:move-assets --from=private --to=cos [--delete-source] [--dry-run]`：**幂等**（目标已有则跳过）、**先校验字节数再删源**、失败即中止 |
| 测试 | `packages/Webkul/Shop/tests/Feature/Storage/SkillAssetStorageTest.php`（7 条：配置跟随 / 上传落对盘 / 搬运 / 幂等 / dry-run / 校验后删源 / 参数校验） |

**实测**：真机上演练过 private → local（19968B，内容一致、路径不变、源保留），
并在真实数据上验证"翻转配置即换盘"（`fileExists()` 跟着配置走）；`cos` 盘可正确解析出 `CosAdapter`。

**仍未做（外部依赖）**：**COS bucket 与凭证**（`COS_APP_ID`/`COS_SECRET_ID`/`COS_SECRET_KEY`/`COS_BUCKET`）
尚未提供，故**没有真实跑过 COS 读写**——驱动解析已验，网络读写未验。凭证到位后按下方顺序切换。

**切换顺序（不可颠倒）**：
```
1) php artisan skill:move-assets --from=private --to=cos --dry-run   # 看量
2) php artisan skill:move-assets --from=private --to=cos             # 复制，源保留
3) 抽查下载链路（鉴权下载接口能取到文件）
4) php artisan skill:move-assets --from=private --to=cos --delete-source
5) .env: SKILL_ASSET_DISK=cos   ← 最后一步才改配置
```

> ⚠ **收尾提醒**：`SKILL_ASSET_DISK=cos` 属于存储位置信息，比密码低敏感但仍是内部信息，
> 而 `SkillAsset::disk()` 只影响**SkillAsset** 的落盘；Bagisto 自带的 `disk('private')`
> （数据导入/下载型商品）**不受影响**，也不要顺手改它们。

---

## 四、验收判据（步骤 2–3）

| 项 | 判据 | 状态 |
|---|---|---|
| 迁移可执行 | `artisan migrate` 无错，15 张表建成 | ✅ **已执行** |
| 术语冻结 | 本文件 §一 | ✅ |
| 上传白名单 | 三种新 carrier_type 可选 | ✅（实测白名单 7 项） |
| 设备绑定 | 登记设备后 `device_binding` 有一行、`devices.binding_id` 回填 | ✅ **实测通过**（建 binding→回填→幂等→回滚干净） |
| 异步底座 | 切 redis 后队列/调度能跑 | ✅ 用 **database 驱动**跑通（队列/缓存/原子锁/worker 消费实测）|
| 对象存储 | 技能包能搬到对象存储且业务零改动 | ⚠️ **代码侧已就绪并实测（换盘=改配置）**；**COS 凭证未提供 → 真实 COS 读写未验**（见 O3） |
| 语法 | 全部 `php -l` 通过 | ✅ |

---

## 五、本次未做（属后续步骤）
- ~~绑定真相源的**读写迁移**（v1 接口改读 binding）~~ → **✅ 已完成（2026-10-14，见第 2 步）**
- 转换层 / 审核三层 / 质量体系**逻辑实现** → 第 6 步
- ~~对象存储接入~~ → **✅ 代码侧已补齐（2026-10-14，见 O3）**；剩 COS 凭证与真实读写验证
- ⚠ **已知重复（未修）**：`Admin/.../Catalog/SkillController::storeAsset` 仍是**第三份上传实现**
  （basename 清洗 / 私盘落盘 / 旧文件清理与 `AssetUploadService` 重复）。
  修它需先补 admin 上传路径的回归测试（该路径目前**零测试覆盖**）——按本项目一贯做法：**先写测试再重构**。
