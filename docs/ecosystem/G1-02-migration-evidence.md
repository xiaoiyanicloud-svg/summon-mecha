# G1-02 迁移与验证证据

- **日期**：2026-10-19
- **验证角色**：独立验证工程师（Codex 负责最终验收）
- **验证范围**：`events` 可信发布字段（ADR-0001 §4.2 / §11.1）、`ecosystem_outbox`（§4.11 / G1-D07）、`EventWriteService` / `OutboxWriter` 行为
- **结论（最终）**：独立测试库 `summon_mecha_step10_test` 上的迁移重跑、回滚、回填、跨连接事务可见性、并发 dedupe（autocommit + 调用方事务）与指定回归测试**全部通过**。§6.2 发现的 `OutboxWriter` 并发缺陷已由 Codex 修复并新增回归用例，本次独立复验通过（writer 返回 holder 既有行，不再抛 1062）。**root 主库未迁移**，本轮未对 root 执行任何写操作。
- **边界**：独立验证阶段未执行 `git add` / `git commit`；最终交付已由 Codex 按明确文件清单提交到当前分支（提交信息 `feat(ecosystem): add G1-02 event schema and outbox`，未使用 `git add -A`）。未执行 `migrate:fresh` / `truncate` / `seed`。

> 本文只记录实测结果。未做的部分集中在 §9，不以“应当可用”替代证据。
> 本地证据文件（快照 JSON、探针输出）位于 `my-bagisto-store/storage/app/g1-02-verify/`（该目录被 `.gitignore` 覆盖，不属于提交内容）。

---

## 1. 范围

1. `events` 新增 13 列与 5 个索引，旧列/旧默认值/`event_registrations` 不变。
2. 新建 `ecosystem_outbox` 表、4 个索引与 1 个唯一键。
3. 存量 8 行回填：`publish_status='draft'`、`disposition='normal'`、`row_version=0`，其余新字段 `NULL`。
4. `EventWriteService` 乐观并发（`row_version`）与 `OutboxWriter` 事务/幂等/PII 守卫。
5. 四个公开/内容开关迁移后仍为 `false`。
6. 独立验证工程师本轮的代码改动仅一条：在 `EcosystemEventSchemaG1Test` 的三开关断言中补 `content.public_enabled=false`。
7. **生产修复（Codex，2026-10-19）**：`OutboxWriter` 唯一键冲突后的回查改为 `lockForUpdate()`（current read），并将 1062 判定收窄为“错误号 1062 且消息命中本表 dedupe 唯一键/列名”。
8. **回归测试（Codex，2026-10-19）**：`EcosystemOutboxG1Test` 新增“REPEATABLE READ 下同 key 已被并发事务提交时仍应幂等重放”用例。

---

## 2. 改动清单

### 2.1 独立验证工程师本轮改动

| 文件 | 改动 | 说明 |
|---|---|---|
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemEventSchemaG1Test.php` | 第 249–254 行断言链追加 `->and(config('ecosystem.content.public_enabled'))->toBeFalse()` | 按任务要求**只加这一条**；未改测试标题、注释与其他断言 |

独立验证工程师**未修改任何生产代码**。

### 2.1.1 Codex 生产修复与回归测试（本次最终复验对象）

| 文件 | 改动 | 说明 |
|---|---|---|
| `Shop/src/Services/Ecosystem/OutboxWriter.php` | 冲突回查由普通 `first()` 改为 `where('dedupe_key', ...)->lockForUpdate()->first()`；新增 `isDedupeKeyViolation()` | `lockForUpdate()` 是 current read，在 `REPEATABLE-READ` 下能看到 holder 已提交的行；1062 判定同时要求错误消息命中 `uk_ecosystem_outbox_dedupe_key` / `dedupe_key`，避免把其他唯一键冲突误当幂等重放 |
| `Shop/tests/Feature/Ecosystem/EcosystemOutboxG1Test.php` | 新增用例“G1-02 · REPEATABLE READ 下同 key 已被并发事务提交时，仍应幂等重放而不是抛 1062” | 用例先用普通 SELECT 建立快照，再由独立 PDO 连接插入并提交同 key 行，最后断言 `write()` 返回外部行而非抛异常 |

验证脚本均为临时脚本，放在 `my-bagisto-store/storage/app/g1-02-verify/`（gitignored，不提交）：

`env_probe.php`、`counts.php`、`snapshot.php`、`schema_probe.php`、`verify_remigrated.php`、`cleanup_temp_events.php`、`tx_visibility.php`、`race_holder.php`、`race_writer.php`、`race_writer_in_tx.php`。

### 2.2 被验证的 G1-02 变更（既有工作区内容，本轮只读审查 + 实测）

| 文件 | 类型 | 内容 |
|---|---|---|
| `Shop/src/Database/Migrations/2026_10_19_000001_add_g1_publishing_fields_to_events.php` | 新增 | 13 列 + 5 索引，additive；`down()` 逆序删索引/列 |
| `Shop/src/Database/Migrations/2026_10_19_000002_create_ecosystem_outbox_table.php` | 新增 | outbox 表 + 唯一键 `uk_ecosystem_outbox_dedupe_key` |
| `config/ecosystem_outbox.php` | 新增 | aggregate/event 类型白名单 |
| `Shop/src/Models/Event.php` | 修改 | 新字段 `$fillable` + UTC/整型 casts |
| `Shop/src/Models/EcosystemOutbox.php` | 新增 | `payload` array cast、UTC 时间 cast |
| `Shop/src/Casts/UtcDateTime.php` | 新增 | UTC DATETIME 读写 cast |
| `Shop/src/Services/Ecosystem/EventWriteService.php` | 新增 | 行锁 + `expected_row_version` 乐观并发 |
| `Shop/src/Services/Ecosystem/EventRevisionConflictException.php` | 新增 | 显式冲突异常 |
| `Shop/src/Services/Ecosystem/OutboxWriter.php` | 新增 + 修复 | 不自开事务、白名单、PII 守卫、dedupe 重放；冲突回查使用 `lockForUpdate()`，1062 判定已收窄 |
| `Shop/src/Http/Controllers/API/ApiErrorCode.php` | 修改 | 新增 `EVENT_REVISION_CONFLICT` 错误码常量（未接 HTTP 路由） |
| `Shop/tests/Feature/Ecosystem/EcosystemEventSchemaG1Test.php` | 新增 | 结构守卫（本轮加一条开关断言） |
| `Shop/tests/Feature/Ecosystem/EcosystemOutboxG1Test.php` | 新增 + 回归用例 | 行为守卫；新增 REPEATABLE READ 并发重放用例 |

> 工作区状态（独立验证结束时、Codex 提交前）：2 个已跟踪文件修改、其余为未跟踪新增。Codex 随后已按明确文件清单暂存并提交，未使用 `git add -A`。

---

## 3. 测试库与 root 事实

### 3.1 连接确认（任何写入前）

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe storage/app/g1-02-verify/env_probe.php
db=summon_mecha_step10_test
driver=mysql
host=127.0.0.1
```

- PHP `8.4.24`，Laravel `11.44.2`，MySQL `5.7.26`，隔离级别 `REPEATABLE-READ`。
- 所有 `artisan` 命令均显式带 `DB_DATABASE=summon_mecha_step10_test`；测试文件自身的 `beforeEach` 也会拒绝非该库连接。

### 3.2 验证开始时的行数

| 库 | `events` | `event_registrations` | `ecosystem_outbox` |
|---|---:|---:|---|
| `summon_mecha_step10_test` | 0 | 0 | 0 |
| `root`（只读） | 8 | 1 | 表不存在 |

### 3.3 root 只读事实（证明未迁移）

```text
root_events_new_columns=            # 13 个新列一个都不存在
root_outbox_table=0
root_has_row_version=no
```

> 本轮对 root 只执行 `SELECT` / `information_schema` 查询和 `preflight.php` 的读取；未执行任何 DDL/DML。

### 3.4 迁移批次

演练前 `migrate:status`：

```text
2026_10_19_000001_add_g1_publishing_fields_to_events .... [2] Ran
2026_10_19_000002_create_ecosystem_outbox_table ......... [3] Ran
```

---

## 4. 迁移演练：回滚 → 重跑 → 清理

### 4.1 准备存量数据

```text
$ /d/php.exe .claude/backup/G1-02/preflight.php
copied=8
test_events=8
ids=1,2,3,4,5,6,7,8
```

- 只复制了旧列（19 列），新列由数据库默认值填充。
- 回滚前基线快照：`events=8`、`registrations=0`、`outbox_exists=1`。
- **旧字段指纹**：`legacy_sha256=b7881c0596ada1ea9dbc0af104ff09a209306f62c773d19fac41a66fb2dceb57`。

回滚前 schema 实测：

- 13 个新列全部存在，类型/NULL/默认值与 ADR §4.2 一致（整数类型为 MySQL 5.7 显示宽度，如 `bigint(20) unsigned`）。
- 5 个新索引全部存在且列序正确。
- `ecosystem_outbox` 存在，14 列、4 个索引 + `uk_ecosystem_outbox_dedupe_key`。
- `events` / `ecosystem_outbox` 外键数均为 0。

### 4.2 回滚

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback --step=2 --no-ansi
2026_10_19_000002_create_ecosystem_outbox_table ........ 8.40ms DONE
2026_10_19_000001_add_g1_publishing_fields_to_events ... 132.16ms DONE
```

回滚后实测：

| 检查项 | 结果 |
|---|---|
| 13 个新列 | 全部 `MISSING` |
| 5 个新索引 | 全部消失，`events` 只剩 `PRIMARY` |
| `ecosystem_outbox` | `exists=0` |
| `events` 行数 | 8 |
| `event_registrations` | 0，逐字段与回滚前一致 |
| 旧字段指纹 | `b7881c0596ada1ea9dbc0af104ff09a209306f62c773d19fac41a66fb2dceb57`（与回滚前相同） |
| `migrate:status` | 两个迁移均为 `Pending` |

### 4.3 分别重跑两个迁移

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
    --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000001_add_g1_publishing_fields_to_events.php
2026_10_19_000001_add_g1_publishing_fields_to_events ... 932.46ms DONE

$ DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
    --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000002_create_ecosystem_outbox_table.php
2026_10_19_000002_create_ecosystem_outbox_table ......... 59.63ms DONE
```

重跑后逐行校验（`verify_remigrated.php`）：

```text
PASS events 仍有 8 行 — count=8
PASS 旧字段与回滚前逐字段一致
PASS event_registrations 与回滚前逐字段一致 — count=0
PASS id=1..8 publish_status=draft
PASS id=1..8 disposition=normal
PASS id=1..8 row_version=0
PASS id=1..8 kind/participation_mode/hosting_status/timezone/starts_at/ends_at/
     published_revision_id/working_revision_id/first_published_at/published_at = NULL
ALL_CHECKS_PASS
```

重跑后 schema 恢复为 13 列 + 5 索引 + outbox 表；`legacy_sha256` 仍为 `b7881c...eb57`。

`migrate:status` 恢复到同名批次：

```text
2026_10_19_000001_add_g1_publishing_fields_to_events .... [2] Ran
2026_10_19_000002_create_ecosystem_outbox_table ......... [3] Ran
```

### 4.4 清理临时数据

```text
$ DB_DATABASE=summon_mecha_step10_test /d/php.exe storage/app/g1-02-verify/cleanup_temp_events.php
before_delete_ids=1,2,3,4,5,6,7,8
deleted=8
events_remaining=0
registrations_remaining=0
outbox_remaining=0
```

最终测试库状态：`events=0`、`event_registrations=0`、`ecosystem_outbox=0`。

---

## 5. 跨连接事务可见性与轮询

探针 `tx_visibility.php`：Laravel 连接在事务内写入 outbox，独立 PDO 连接按固定 `dedupe_key` 轮询计数。

| 场景 | 观测点 | 期望 | 实测 |
|---|---|---|---:|
| 提交可见性 | 事务内、提交前，独立连接 count | 0 | 0 ✅ |
| 提交可见性 | 提交后，独立连接 count | 1 | 1 ✅ |
| 回滚不可见 | 事务内写入后、回滚前，独立连接 count | 0 | 0 ✅ |
| 回滚不可见 | 回滚前基线 count | 0 | 0 ✅ |
| 回滚不可见 | 回滚后独立连接 count | 0 | 0 ✅ |
| 清理 | 删除探针记录 | 2 条 key 均归零 | 归零 ✅ |

```text
[case 1] commit visibility
  laravel_tx_open=true
  outbox_id=29
PASS 事务内、提交前独立连接 count=0 — count=0
PASS 提交后独立连接 count=1 — count=1
[case 2] rollback invisibility
  laravel_tx_rolled_back=true
PASS 回滚前（事务内写入后）独立连接 count=0 — count=0
PASS 回滚后独立连接 count 不增加（=0） — count=0
[cleanup]
  deleted=1
ALL_CHECKS_PASS
```

> 上述为修复后的最终复验输出（修复前后行为一致；本探针与 §6.2 的缺陷路径无耦合）。

> 说明：独立 PDO 连接处于 autocommit，每次轮询都是独立快照；这与 REPEATABLE-READ 下“同一连接先读后仍看不到新提交”的行为不同，测的是跨连接可见性本身。

---

## 6. 并发 dedupe 竞态

两个独立 CLI 进程：

- **holder**：显式事务插入 `dedupe_key=g1:race:verify`，保持 3 秒后提交。
- **writer**：延迟 1 秒调用 `OutboxWriter`，同 key、同事实（payload 键序不同）。

实测输出：

```text
=== HOLDER (exit 0) ===
holder_start=12:33:50.721
holder_inserted_id=27
holder_tx_open=true
holder_committed_at=3.004s_after_insert
holder_rows_for_key=1

=== WRITER (exit 0) ===
writer_start=12:33:50.692
writer_returned_id=27
writer_elapsed=2.040s
writer_rows_for_key=1
writer_cleanup_deleted=1
writer_rows_after_cleanup=0
WRITER_PASS
```

结论：

- writer 的 INSERT 被 holder 的唯一索引锁阻塞，耗时 `2.040s` 后返回；
- 返回的是 holder 已提交的同一行（id=27），未新增第二条；
- 该 key 总行数始终为 1；
- 清理后为 0；
- 修复后复验，autocommit 场景**未回归**。

### 6.2 调用方事务内的并发 dedupe：修复前失败 → 修复后通过

任务 4 的 writer 在 autocommit 下调用 `OutboxWriter`。但该类文档明确要求 outbox 必须与业务写落在**调用方同一个事务**里。为验证真实调用约定，使用 `race_writer_in_tx.php`：writer 先 `DB::beginTransaction()`，再调用 `write()`；holder 与 writer 使用同 key（修复后已对齐为同事实，payload 键序不同）。

#### 6.2.1 修复前（2026-10-19 12:31，失败）

```text
=== HOLDER (exit 0) ===
holder_start=12:31:02.540
holder_inserted_id=13
holder_tx_open=true
holder_committed_at=3.013s_after_insert
holder_rows_for_key=1

=== WRITER IN TX (exit 0) ===
writer_in_tx_start=12:31:03
result=exception
writer_in_tx_elapsed=1.283s
exception_class=Illuminate\Database\UniqueConstraintViolationException
exception_message=SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
  'g1:race:verify' for key 'uk_ecosystem_outbox_dedupe_key' ...
IN_TX_FAIL: concurrent dedupe threw instead of returning the existing row
writer_in_tx_rows_after_cleanup=0
```

> 说明：修复前该探针的 writer 仍使用早期 payload（`aggregate_id=770002`）。由于 1062 在“回查既有行”阶段即抛出，尚未进入事实指纹比较，因此不影响对快照缺陷的结论。修复后已把 writer 对齐为与 holder 完全同事实（`aggregate_id=770001`、相同 payload 值、不同键序）。

失败原因：

- 事务的第一次 dedupe 预查 `SELECT ... WHERE dedupe_key = ?` 在 `REPEATABLE-READ` 下建立了快照；
- holder 提交后，writer 的 INSERT 返回 1062；
- 冲突后的普通回查仍是**同一快照**的一致性读，看不到 holder 刚提交的行，`$existing` 为 `null`，于是重新抛出异常；
- 该行为与**修复前** `OutboxWriter` 注释“冲突返回时既有行已可见，回查即可”相反。

#### 6.2.2 修复方案（Codex）

1. 冲突回查改为 `where('dedupe_key', $dedupeKey)->lockForUpdate()->first()`。`lockForUpdate()` 是 current read，在 `REPEATABLE-READ` 下读取最新已提交版本，能看到 holder 提交的行。
2. 1062 判定收窄为 `isDedupeKeyViolation()`：错误号必须是 1062，且错误消息必须命中 `uk_ecosystem_outbox_dedupe_key` 或 `dedupe_key`。避免表上其他唯一约束（将来新增）的 1062 被误当作幂等重放。
3. 新增回归用例“G1-02 · REPEATABLE READ 下同 key 已被并发事务提交时，仍应幂等重放而不是抛 1062”：先用普通 SELECT 建立快照，再由独立 PDO 连接插入并提交同 key 行，最后断言 `write()` 返回外部行。

#### 6.2.3 修复后（2026-10-19 12:33，通过）

```text
=== HOLDER (exit 0) ===
holder_start=12:33:44.220
holder_inserted_id=25
holder_tx_open=true
holder_committed_at=3.015s_after_insert
holder_rows_for_key=1

=== WRITER IN TX (exit 0) ===
writer_in_tx_start=12:33:44
result=returned
writer_in_tx_returned_id=25
writer_in_tx_elapsed=1.360s
writer_in_tx_rows_for_key=1
IN_TX_PASS
writer_in_tx_rows_after_cleanup=0
```

最终结论：

- writer **不再抛 1062**，而是阻塞后返回 holder 的既有行（id=25）；
- 返回行 id 与 holder 行一致，该 key 总数始终为 1；
- 探针 key 已清理，最终为 0；
- 新增回归用例在 §7.1 中通过（`EcosystemOutboxG1Test` 共 11 passed），缺陷已被测试覆盖。

---

## 7. 测试复跑与静态检查

### 7.1 指定测试文件（逐文件独立进程，避免 `set_time_limit` 进程级干扰）

| 测试文件 | 结果 | Assertions | Duration |
|---|---:|---:|---:|
| `Shop/tests/Feature/Ecosystem/EcosystemEventSchemaG1Test.php` | 9 passed | 113 | 1.50s |
| `Shop/tests/Feature/Ecosystem/EcosystemOutboxG1Test.php` | 11 passed | 51 | 1.58s |
| `Shop/tests/Feature/API/EventRegistrationGateG0Test.php` | 7 passed | 45 | 1.67s |
| `Admin/tests/Feature/Catalog/EventAdminAclG0Test.php` | 7 passed | 72 | 2.23s |
| `Admin/tests/Feature/Catalog/EventFormG0Test.php` | 11 passed | 137 | 2.23s |
| `Admin/tests/Feature/Catalog/EventRegistrationPiiG0Test.php` | 5 passed | 120 | 1.22s |
| **合计** | **50 passed** | **538** | — |

> `EcosystemOutboxG1Test` 由修复前的 10 passed / 47 assertions 增至 **11 passed / 51 assertions**，新增用例即 §6.2.2 的 REPEATABLE READ 并发重放回归测试。

所有测试均以 `DB_DATABASE=summon_mecha_step10_test` 运行；测试内部使用 `DatabaseTransactions`，未执行 `migrate:fresh` / `truncate` / `seed`。

### 7.2 PHP 语法检查

本轮涉及的 22 个 PHP 文件（生产代码、迁移、配置、测试、验证脚本，含 `OutboxWriter` 修复与新增回归用例）全部 `php -l` 通过：

```text
PHP_LINT_FAILURES=0
```

### 7.3 Git 空白检查

```text
$ git diff --check
GIT_DIFF_CHECK_EXIT=0
```

> 注意：本轮修改的测试文件位于未跟踪目录中，`git diff` 不展示其内容；`git diff --check` 只覆盖 2 个已跟踪文件的修改。

### 7.4 迁移状态摘要

```text
total=211, ran=211, pending=0
2026_10_19_000001_add_g1_publishing_fields_to_events .... [2] Ran
2026_10_19_000002_create_ecosystem_outbox_table ......... [3] Ran
```

---

## 8. 回滚命令

仅在独立测试库执行（**禁止把 `DB_DATABASE` 换成 `root`**）：

```bash
cd D:/main/my-bagisto-store

# 1) 回滚本轮两个迁移
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback --step=2 --no-ansi

# 2) 单独重跑迁移
DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000001_add_g1_publishing_fields_to_events.php --no-ansi

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000002_create_ecosystem_outbox_table.php --no-ansi

# 3) 删除本轮复制的临时行（只删 id 1..8）
DB_DATABASE=summon_mecha_step10_test /d/php.exe storage/app/g1-02-verify/cleanup_temp_events.php
```

若验证脚本已被清理，等价 SQL（**仅限测试库**）：

```sql
DELETE FROM events WHERE id IN (1,2,3,4,5,6,7,8);
```

---

## 9. 明确未做

以下事项**没有做**，不得在验收中视为已完成：

1. **root 主库未迁移**。只读探针确认：13 个新列均不存在、`ecosystem_outbox` 不存在、`events` 无 `row_version`；root 的 8 条事件与 1 条报名未改动。
2. **Admin 未接 `row_version`**。`packages/Webkul/Admin/src` 中 `row_version` 引用数为 0；`EVENT_REVISION_CONFLICT` 只有常量，未接 HTTP 路由/控制器。当前后台仍按旧路径写 `events`。
3. **没有 outbox worker / 消费者**。除模型、`OutboxWriter`、迁移外，无消费代码；本轮未启动 `queue:work` 或 scheduler。
4. **公开/报名/内容开关仍为 `false`**。配置默认值均为 `false`，`.env` 无任何 `ECOSYSTEM_*` 覆盖；测试断言确认四项全为 `false`。
5. **没有新增公开写入口**。`GET /api/events` 仍为空列表，`GET /api/events/{id}` 仍 404（回归测试覆盖）。
6. **G1-02 之外未做**：`event_revisions`、`ecosystem_organizers`、富文本净化、发布/审核状态机、公开 Resource/前端、报名 PII 与导出、`event_id` 类型统一/外键修复。
7. **Git 交付**：已按明确文件清单提交到 `codex/ecosystem-g1-trusted-publishing`（提交信息 `feat(ecosystem): add G1-02 event schema and outbox`），未 push。

### 9.1 已修复（不属于未做）

**§6.2 的并发 dedupe 缺陷已修复**（Codex，2026-10-19）：

- 生产修复：冲突回查改为 `lockForUpdate()`（current read），1062 判定收窄为命中本表 dedupe 唯一键/列名。
- 测试覆盖：`EcosystemOutboxG1Test` 新增“REPEATABLE READ 下同 key 已被并发事务提交时，仍应幂等重放而不是抛 1062”用例；该文件 11 passed / 51 assertions。
- 独立复验：in-transaction 并发探针返回 holder 既有行、无 1062、总数 1（§6.2.3）；autocommit 探针无回归（§6.1）；跨连接事务可见性探针通过（§5）。

---

## 10. ADR 与库事实偏差

| 项 | ADR-0001 描述 | 库内实测 | 处理 |
|---|---|---|---|
| `events.name` | `VARCHAR(255)`（§4.1） | `varchar(191)` | **偏差**。以现有库/现有校验（`EventFormG0Test` 断言 191 通过、192 拒绝）为准；G1-02 未改旧列 |
| `events.type` | `VARCHAR(255)`（§4.1） | `varchar(191)` | **偏差**（同类旧列事实）；G1-02 未改旧列 |
| `events.sort` | `INT`，索引“现状”（§4.1） | `int(11) NOT NULL DEFAULT '0'`，**无索引** | **偏差**。G1-02 未给旧列补索引，避免超出 additive 范围 |
| `events` 新字段 | §4.2 类型/NULL/默认 | 13 列全部一致 | 一致（整数显示宽度差异已归一化） |
| `events` 新索引 | §4.2 五个索引 | 五个全部存在、列序一致 | 一致 |
| `ecosystem_outbox` | §4.11 列/索引/唯一键 | 14 列、4 索引 + 唯一键 | 一致 |
| 外键 | G1-02 不建 FK | `events` / `ecosystem_outbox` FK 数均为 0 | 一致 |
| MySQL | 5.7 设计 | 实际 `5.7.26` | 一致 |

> 上述偏差是**旧库既有事实**，G1-02 未修改旧列；若后续要把 ADR 与库对齐，需另立工作包（改列类型/补索引属于收缩或性能变更，不在本次 additive 迁移内）。

---

## 11. 结论

- 测试库 `summon_mecha_step10_test` 上的 G1-02 迁移可回滚、可重跑、回填正确，旧字段与 `event_registrations` 逐字段不变。
- outbox 与业务写同事务：提交前跨连接不可见、提交后可见、回滚后不留记录。
- 并发 dedupe：**autocommit 与调用方事务两种调用方式均通过**。冲突写入阻塞后返回 holder 的既有行，不产生第二条；1062 判定已收窄到本表 dedupe 唯一键，避免误吞其他唯一约束冲突。
- 修复前的 REPEATABLE READ 缺陷已有独立回归用例覆盖，修复后独立复验通过（§6.2.3）。
- 指定 6 个测试文件全部通过（**50 passed / 538 assertions**）；PHP 语法、`git diff --check`、迁移状态均正常。
- **验收结论：G1-02 在本次独立验证范围内可验收，无遗留阻塞项。**
- **root 主库仍未迁移，Admin 未接 `row_version`，无 outbox worker，公开开关仍为 `false`** —— 这些是后续工作包的明确范围边界，不是本次 G1-02 schema/行为验证的缺口。
