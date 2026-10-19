# G1-03 主办方与活动来源 · 迁移与验证证据

> 后续变更：G1-04 已新增 `event_revisions` / `event_rule_versions` 与 events 双 revision 指针 FK。本文中“无 revision / 无指针 FK / event_revisions 不存在”等表述仅指 G1-03 完成时状态。

- **日期**：2026-10-19
- **验证角色**：独立验证工程师（Codex 负责最终验收）
- **验证范围**：ADR-0001 G1-D01 / G1-D05 / §4.2 / §4.4；`ecosystem_organizers`、`events.source_type/platform_role/organizer_id`、`OrganizerService` / `EventSourceService` / `EventSourceValidator` 行为
- **结论**：独立测试库 `summon_mecha_step10_test` 上的迁移回滚、分步重跑、旧字段比对、真实服务探针与 9 个指定测试文件**全部通过**。**root 主库未迁移**，本轮未对 root 执行任何写操作。
- **边界**：独立验证阶段未执行 `git add` / `git commit`，也未修改任何生产代码；最终交付由 Codex 在验证通过后按明确文件清单提交到当前分支（提交信息 `feat(ecosystem): add G1-03 organizers and event sources`，未使用 `git add -A`）。未执行 `migrate:fresh` / `truncate` / `seed`；所有 `artisan` 命令均显式带 `DB_DATABASE=summon_mecha_step10_test`，并在写入前打印当前库名。

> 本文只记录实测结果。未做的部分集中在 §10，不把“代码已存在”写成“已上线”。

---

## 1. 范围

1. 新建 `ecosystem_organizers`（ADR §4.4 / G1-D05）：11 个资料/核验列、`name(64)` 前缀索引、`idx(status, platform_role)`、`idx(status)`。
2. `events` 新增 `source_type` / `platform_role` / `organizer_id`（ADR §4.2）：三列全部可空、不回溯旧 `events.organizer` 自由文本、两个索引、一个指向 `ecosystem_organizers.id` 的 `RESTRICT` 外键。
3. `OrganizerService`：创建强制 `pending`；核验/驳回/暂停行锁流转；敏感字段变更回 `pending` 并清空核验人与时间。
4. `EventSourceService` + `EventSourceValidator`：草稿三字段“同时为空或同时齐全”、来源/角色组合、主办方存在与角色一致；发布前置校验核验状态、官方 URL 与 B-01/B-03 放行组合。
5. `EventWriteService` 扩展来源三字段写入，并在行锁内二次校验，防止绕过 `EventSourceService` 写入半个来源。
6. 存量 8 行（临时复制的 id 1..8）在迁移后三字段保持 `NULL`，旧字段逐字段不变。
7. 四个公开/报名开关迁移后仍为 `false`；不提前创建 revision / 内容 / 媒体表。

---

## 2. 改动清单（被验证对象）

### 2.1 已跟踪文件（`git diff`）

| 文件 | 改动 |
|---|---|
| `Shop/src/Http/Controllers/API/ApiErrorCode.php` | 新增 `EVENT_SOURCE_REQUIRED`、`EVENT_ORGANIZER_UNVERIFIED` 两个常量（未接 HTTP 路由） |
| `Shop/src/Models/Event.php` | `$fillable` 增加来源三字段；`organizer_id` 整型 cast；新增 `organizerEntity()` 关系（关系名避开旧列 `organizer`） |
| `Shop/src/Services/Ecosystem/EventWriteService.php` | 可写字段增加来源三字段；构造注入 `EventSourceValidator`；在行锁内对来源三字段变更做二次草稿校验 |
| `Shop/tests/Feature/Ecosystem/EcosystemEventSchemaG1Test.php` | G1-02 的“events 零外键”断言改为只约束两个 revision 指针列，避免误伤 G1-03 的合法外键 |

### 2.2 未跟踪新增文件

| 文件 | 内容 |
|---|---|
| `config/ecosystem_organizers.php` | `source_types` / `platform_roles` / `statuses` / 证据字段 / 重新核验字段 / 发布放行组合（单一事实源） |
| `Shop/src/Database/Migrations/2026_10_19_000003_create_ecosystem_organizers_table.php` | 建表 + 3 个索引；按 `hasTable` / `hasIndex` 幂等编写 |
| `Shop/src/Database/Migrations/2026_10_19_000004_add_source_fields_to_events.php` | 加 3 列 + 2 索引 + FK；缺 000003 时显式失败 |
| `Shop/src/Models/EcosystemOrganizer.php` | 模型、`UtcDateTime` 核验时间 cast、`events()` 关系 |
| `Shop/src/Services/Ecosystem/OrganizerService.php` | 主办方唯一写入口（create/update/verify/reject/suspend，无 delete） |
| `Shop/src/Services/Ecosystem/EventSourceService.php` | 活动来源三字段写入口，先校验后走 `EventWriteService` |
| `Shop/src/Services/Ecosystem/EventSourceValidator.php` | 草稿边界 + 发布前置校验、URL 字面校验（不抓取） |
| `Shop/src/Services/Ecosystem/EventSourceValidationException.php` | 携带稳定 `errorCode` 的校验异常 |
| `Shop/tests/Feature/Ecosystem/EcosystemOrganizerSchemaG1Test.php` | schema 守卫（11 用例） |
| `Shop/tests/Feature/Ecosystem/EcosystemOrganizerServiceG1Test.php` | 服务行为守卫（11 用例） |
| `Shop/tests/Feature/Ecosystem/EcosystemEventSourceG1Test.php` | 来源校验与并发写守卫（14 用例） |

> 本轮独立验证**未修改任何生产代码或测试文件**；上述内容均为被验证对象。

---

## 3. root 与独立测试库事实

### 3.1 连接与版本

```text
CURRENT_DB=summon_mecha_step10_test        # 每条 artisan 命令执行前打印
MySQL 5.7.26 / PHP 8.4.24 / pdo_mysql OK
```

- root 连接串只用于 **只读** `SELECT` / `information_schema` 查询。
- 所有 `artisan` 命令显式使用 `DB_DATABASE=summon_mecha_step10_test`；三个 G1-03 测试文件的 `beforeEach` 也会拒绝非该库连接。

### 3.2 验证开始时

| 库 | `events` | `event_registrations` | `ecosystem_organizers` | `ecosystem_outbox` |
|---|---:|---:|---:|---:|
| `summon_mecha_step10_test` | 8（临时复制 id 1..8） | 0 | 0 行（表存在） | 0 |
| `root`（只读） | 8 | 1 | **表不存在** | **表不存在** |

测试库 G1-03 迁移批次：

```text
2026_10_19_000001_add_g1_publishing_fields_to_events .... [2] Ran
2026_10_19_000002_create_ecosystem_outbox_table ......... [3] Ran
2026_10_19_000003_create_ecosystem_organizers_table ..... [4] Ran
2026_10_19_000004_add_source_fields_to_events ........... [5] Ran
```

### 3.3 root 只读事实（证明未迁移）

```text
root_events=8
root_registrations=1
root_organizers_table=0
root_outbox_table=0
root_g1_cols=0        # kind/publish_status/source_type/platform_role/organizer_id 一个都不存在
```

> 即：root 不仅没有 G1-03 的表/列，G1-02 的字段与 outbox 也未迁移。本轮未对 root 做任何 DDL/DML。

---

## 4. 迁移演练：回滚 → 分步重跑

### 4.1 回滚（只回滚 000004 / 000003）

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback --step=2 --force
INFO  Rolling back migrations.
2026_10_19_000004_add_source_fields_to_events ......... 121.55ms DONE
2026_10_19_000003_create_ecosystem_organizers_table ... 6.20ms DONE
```

回滚后实测：

```text
source_cols=0          # events.source_type / platform_role / organizer_id 全部消失
source_indexes=0       # idx_events_source_role / idx_events_organizer_id 全部消失
fk_left=0              # events 上无任何外键
organizers_table=0     # ecosystem_organizers 表消失
test_events=8          # 8 行仍在
test_regs=0
outbox=0
migrations: 仅 000001 [2]、000002 [3] 保留
events_oldfields_md5=28b18b03f480ebc1f8d5dbd9d97bf7cf  # 与回滚前完全一致
OLD_FIELDS_IDENTICAL
event_registrations 列定义（CRLF 归一后 diff）= 完全一致
```

8 行旧 `organizer` 自由文本与其余旧字段逐字段不变；`event_registrations` 结构与行数不变。

### 4.2 分步重跑

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
    --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000003_create_ecosystem_organizers_table.php --force
2026_10_19_000003_create_ecosystem_organizers_table ... 47.56ms DONE

$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
    --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000004_add_source_fields_to_events.php --force
2026_10_19_000004_add_source_fields_to_events ......... 355.03ms DONE
```

重跑后实测：

```text
events=8 / null_source=8 / null_role=8 / null_org=8     # 存量三字段全 NULL，未从旧 organizer 文本回填
events_oldfields_md5=28b18b03f480ebc1f8d5dbd9d97bf7cf  # 与演练前完全一致
OLD_FIELDS_IDENTICAL_AFTER_RERUN

idx_ecosystem_organizers_name: COLUMN=name SEQ=1 SUB_PART=64 NON_UNIQUE=1
fk_events_organizer_id: events.organizer_id -> ecosystem_organizers.id
                        UPDATE_RULE=RESTRICT DELETE_RULE=RESTRICT

migrations: 000003 [4] Ran, 000004 [5] Ran
```

> 说明：回滚删除迁移记录后，`migrate --path` 按“当前最大 batch + 1”重新分配，因此两次分步重跑仍落在 batch 4 / 5，与任务基线一致。

---

## 5. 真实服务探针

探针在测试库事务中执行，结束时 `DB::rollBack()`，并复核四张表行数与 event#1 字段/版本恢复原状。使用测试库真实 admin（id=1）。

```text
CURRENT_DB=summon_mecha_step10_test
PASS  当前库为 summon_mecha_step10_test
PASS  测试库存在 admin（id=1）
PASS  create 后 status=pending
PASS  create 后核验人/时间为 NULL
PASS  探针前 event#1 row_version=0
PASS  assign 后 row_version=1
PASS  assign 后来源三字段落库
PASS  pending organizer 的草稿校验通过
PASS  pending organizer 调 assertPublishable 抛 EVENT_ORGANIZER_UNVERIFIED（实际码 EVENT_ORGANIZER_UNVERIFIED）
PASS  verify 后 status=verified
PASS  verify 后 verified_by=admin id
PASS  verify 后 verified_at 非空
PASS  verified_at 时区为 UTC
PASS  verified_at 与当前 UTC 时间偏差 <=5s（实际 0s）
PASS  库内 verified_at 按 UTC 解释与当前 UTC 偏差 <=5s（实际 0s，raw=2026-10-19 05:48:07）
PASS  platform + organizer + verified 的 assertPublishable 通过
PASS  改名后 status 回 pending
PASS  改名后 verified_by 清空
PASS  改名后 verified_at 清空
PASS  改名后 verification_note 保留上一轮记录
PASS  改名回 pending 后 assertPublishable 再次抛 EVENT_ORGANIZER_UNVERIFIED
PROBE_SCOPE_OK
PROBE_CLEANUP row_counts={"events":8,"registrations":0,"organizers":0,"outbox":0}
PROBE_CLEANUP event#1={"source_type":null,"platform_role":null,"organizer_id":null,"row_version":0}
PASS  探针回滚后四张表行数与探针前一致（无残留）
PASS  探针回滚后 event#1 来源字段/版本恢复原状
```

覆盖点：

1. `create()` 强制 `pending`，拒绝调用方自带核验字段。
2. `EventSourceService::assign()` 经 `EventWriteService` 乐观并发写入，`row_version` 0 → 1。
3. pending 主办方可保存草稿，但不能发布（`EVENT_ORGANIZER_UNVERIFIED`）。
4. `verify()` 写入核验人、UTC `verified_at`，`platform + organizer` 发布前置通过。
5. 已核验主体改名回 `pending`、清空核验人与时间、保留上一轮 `verification_note`。
6. 探针数据全部回滚，无残留。

---

## 6. 清理临时事件与最终库状态

删除临时复制的 id 1..8（仅测试库，使用 `DELETE`，未用 `truncate`）：

```text
CURRENT_DB=summon_mecha_step10_test
DELETED_EVENTS=8
```

最终行数（测试跑完后再次确认）：

```text
events=0  event_registrations=0  ecosystem_organizers=0  ecosystem_outbox=0
```

---

## 7. 指定测试结果

按文件分进程执行（规避全量套件的 `set_time_limit(60)` 问题），每一轮均指向 `summon_mecha_step10_test`。

| # | 文件 | 结果 | Assertions | 耗时 |
|---|---|---|---:|---:|
| 1 | `Shop/tests/Feature/Ecosystem/EcosystemOrganizerSchemaG1Test.php` | 11 passed | 97 | 2.67s |
| 2 | `Shop/tests/Feature/Ecosystem/EcosystemOrganizerServiceG1Test.php` | 11 passed | 86 | 1.53s |
| 3 | `Shop/tests/Feature/Ecosystem/EcosystemEventSourceG1Test.php` | 14 passed | 39 | 2.06s |
| 4 | `Shop/tests/Feature/Ecosystem/EcosystemEventSchemaG1Test.php` | 9 passed | 114 | 1.50s |
| 5 | `Shop/tests/Feature/Ecosystem/EcosystemOutboxG1Test.php` | 11 passed | 51 | 1.54s |
| 6 | `Shop/tests/Feature/API/EventRegistrationGateG0Test.php` | 7 passed | 45 | 1.89s |
| 7 | `Admin/tests/Feature/Catalog/EventAdminAclG0Test.php` | 7 passed | 72 | 2.15s |
| 8 | `Admin/tests/Feature/Catalog/EventFormG0Test.php` | 11 passed | 137 | 2.24s |
| 9 | `Admin/tests/Feature/Catalog/EventRegistrationPiiG0Test.php` | 5 passed | 120 | 1.08s |
| **合计** | **9 个文件** | **86 passed / 0 failed** | **761** | — |

---

## 8. 其它技术检查

```text
php -l：15 个本轮 PHP 文件全部 “No syntax errors detected”（PHP_LINT_FAIL=0）
git diff --check：退出码 0（无空白错误）
migrate:status：Pending=0；000003 [4] Ran / 000004 [5] Ran
四开关：
  ecosystem.events.public_enabled=false
  ecosystem.events.registration_enabled=false
  ecosystem.events.registration_write_verified=false
  ecosystem.content.public_enabled=false
未提前创建的表（均为 false/不存在）：
  event_revisions / event_rule_versions / content_posts / content_revisions /
  content_sources / ecosystem_media / ecosystem_media_refs / event_registration_scopes
```

---

## 9. 回滚命令

### 9.1 回滚最近两条 G1-03 迁移

```bash
cd my-bagisto-store
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback --step=2 --force
```

> 前提：000003/000004 是最后两条迁移。若之后又追加了迁移，必须改用 `--path` 精确回滚，避免误回滚后续工作包：
>
> ```bash
> DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
>   --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000004_add_source_fields_to_events.php --force
> DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
>   --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000003_create_ecosystem_organizers_table.php --force
> ```

### 9.2 重新应用

```bash
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000003_create_ecosystem_organizers_table.php --force
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000004_add_source_fields_to_events.php --force
```

### 9.3 注意事项

- `000004::down()` 先删 FK、再删索引、最后删列；`000003::down()` 直接 `dropIfExists`。顺序不能反，否则 MySQL 拒绝删除仍被外键使用的索引/表。
- 回滚会删除 `events` 三列，列内数据不可恢复；本轮存量三列均为 `NULL`，无数据损失。生产/root 执行前仍必须按 ADR §11.2 备份。
- **不得**在 `root` 主库执行以上命令；本工作包当前没有 root 迁移授权。

---

## 10. 明确未做

1. **root 主库未迁移**：root 无 `ecosystem_organizers`、无 `events` 来源三列、无 G1-02 字段/outbox。
2. **无 Admin UI / 路由**：`OrganizerService` 只有服务层与测试调用，没有后台页面、控制器或路由；`EVENT_SOURCE_REQUIRED` / `EVENT_ORGANIZER_UNVERIFIED` 尚未映射到 HTTP 响应。
3. **无发布状态机**：没有 G1-08 的提交/审核/发布/撤稿流程、无 `event_revisions`、无发布 worker。
4. **无内容域 / 媒体表 / 富文本净化**（G1-05/G1-06/G1-07）。
5. **无报名改动**：`event_registrations` 结构、停写闸与 PII 规则均未改动。
6. **四开关仍为 `false`**：迁移只加结构，不打开任何公开读取或报名写入。
7. **B-01 / B-03 未签署**：只通过配置放行 `platform + organizer` 发布；`external`、`co_organizer`、`authorized_operator` 可保存草稿但不能发布。
8. **无 outbox worker / 调度常驻进程**：本轮不涉及。

---

## 11. 风险与口径张力

| 风险 | 说明 | 当前处置 |
|---|---|---|
| `platform_role` 语义张力 | ADR 写“G1 一期一个主体一个角色”，实现要求 `events.platform_role` 与所引用 organizer 的 `platform_role` 完全一致。同一机构既要当 organizer 又要当 co_organizer 时，必须建两条 organizer 记录并分别核验；改角色会把主体打回 `pending`。 | 按 ADR 执行；错误文案明确提示“新建主体并单独核验”。是否允许一主体多角色留待后续 ADR 决策。 |
| `idx(status)` 冗余 | `idx(status, platform_role)` 的最左前缀已覆盖 `status` 单列查询，`idx_ecosystem_organizers_status` 在性能上冗余。 | **仍按 ADR §4.4 保留**，不在本轮擅自删索引；后续如需收缩须走决策与迁移。 |
| `verified_by` 无外键 | 后台账号可能归档/删除，而核验事实必须保留，因此刻意不建 FK。写入时由 `OrganizerService::assertAdminExists()` 校验管理员存在。 | 保留历史 ID；若管理员被删除，历史核验记录不会级联消失。 |
| B-01/B-03 只放行 `platform + organizer` | 外部活动、联合主办、授权承办即使主体已核验，`assertPublishable()` 仍抛 `EVENT_ORGANIZER_UNVERIFIED`。 | 通过 `config/ecosystem_organizers.php` 的 `publish_allowed_*` 控制；签署后改配置放开，不改业务代码。 |
| 草稿不因主体事后被驳回而自动失效 | 已挂到草稿的活动，在 organizer 变为 `rejected`/`suspended` 后不会被动删除来源字段；下一次草稿保存或发布前置会拒绝。 | 由 `assertDraftValues()` / `assertPublishable()` 在写/发布时拦截；G1-08 状态机需保证发布前重检。 |
| 模型层仍可绕过服务写状态 | `EcosystemOrganizer::$fillable` 含 `status` / `verified_by` / `verified_at`，测试辅助函数直接 `create()`；生产写路径约定只走 `OrganizerService`。 | 当前无其它生产写入口；G1-08 接 Admin 时应将模型写入口收敛或在模型层加守卫。 |

---

## 12. 证据与复现

- 迁移/回滚命令、行数、md5、索引进度均来自本轮终端实测输出，已逐项粘贴在 §3–§8。
- 探针脚本为临时脚本，位于系统临时目录（`C:/Users/Administrator/AppData/Local/Temp/g1_03_probe.php`），执行后事务回滚并已确认无残留；未纳入仓库。
- 快照文件（`events` 旧字段 TSV、列/索引/FK TSV）同样位于系统临时目录 `/tmp/g1_03/`，不随仓库提交。
- 复现步骤：确认 `DB_DATABASE=summon_mecha_step10_test` → §4 回滚/重跑 → §5 探针 → §7 分文件测试 → §8 技术检查。
