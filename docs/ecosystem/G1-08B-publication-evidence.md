# G1-08B 发布 / 排期 / 暂缓 / 恢复 / 撤稿 —— 交付与实测记录

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g1-trusted-publishing` |
| 范围 | G1-08B 核心层：`EventPublicationService` / `ContentPublicationService` / `PublicationPointerSwitcher` / `PublicationOutbox` |
| 依据 | `docs/ecosystem/ADR-0001-trusted-publishing.md` 附录 B A-02/A-03/A-04、`docs/ecosystem/G1-08-11-implementation-plan.md` §4 / §8.1~§8.6 / §8.9 |
| 数据库 | 独立测试库 `summon_mecha_step10_test`（未触碰 root） |
| 性质 | 服务层闭环；**不含** worker / Admin / 公开 API / 前端 |

---

## 1. 改动清单

### 新增

| 文件 | 作用 |
|---|---|
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/EventPublicationService.php` | 活动 `schedule` / `unschedule` / `publishNow` / `publishScheduled` / `withhold` / `restore` / `retract` |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/ContentPublicationService.php` | 内容同构动作；额外受 `content_publish_enabled + B-04 + B-07` 约束 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationPointerSwitcher.php` | `published_revision_id` 唯一生产写入口；Query Builder 原子更新 + `row_version` CAS |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationOutbox.php` | 白名单 payload + 稳定 `dedupe_key`；不自开事务 |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemEventPublicationG1Test.php` | 20 tests / 125 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemContentPublicationG1Test.php` | 10 tests / 62 assertions |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemPublicationDispositionG1Test.php` | 6 tests / 61 assertions |

### 修改

| 文件 | 改动 |
|---|---|
| `my-bagisto-store/config/ecosystem_publishing.php` | 发布转移表（schedule/unschedule/publish/withhold/restore/retract）、状态/处置/动作/原因码词表、outbox 事件映射、发布开关默认 false |
| `my-bagisto-store/config/ecosystem_outbox.php` | 登记 `event.restored` / `content.restored` |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationState.php` | `assertPublishEnabled`（内容域含 B-04/B-07）、`assertDispositionNormal`、`assertKnownDisposition`、词表驱动 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationPreconditions.php` | `assertEventPublishable` / `assertContentPublishable`；发布向重检统一收敛异常 |
| `my-bagisto-store/packages/Webkul/Shop/src/Services/Ecosystem/PublicationAudit.php` | `recordSystem()`（`actor_type=system`） |
| `docs/ecosystem/ADR-0001-trusted-publishing.md` | 附录 B A-02/A-03/A-04 落地记录 |
| `docs/ecosystem/G1-08-11-implementation-plan.md` | 新增 §4.8 落地记录与差异说明 |

### 备份

原始文件已复制到 `.claude/backup/G1-08B/`（docs 与 my-bagisto-store 相对路径保持一致）。

---

## 2. 冻结语义落地

| 动作 | 父 `publish_status` | 指针 / 时间 | outbox | 审计 |
|---|---|---|---|---|
| `schedule` | 从未发布 `approved -> scheduled`；已发布保持 `published` | `approved_revision_id`、`scheduled_publish_at`（UTC） | **不写** | `ecosystem.{domain}.schedule` |
| `unschedule` | `scheduled -> approved`；已发布保持 `published` | 清 `scheduled_publish_at` | **不写** | `ecosystem.{domain}.unschedule` |
| `publishNow` | 切到 `published`；已发布对象不变 | `published_revision_id` / `published_at` / 首次 `first_published_at`；清候选与排期；revision `published_at` 仅首次 | `*.published` | `ecosystem.{domain}.publish` |
| `withhold` | 保持 `published` | 公开指针保留；清候选与排期 | `*.withheld` | `ecosystem.{domain}.withhold` |
| `restore` | 保持 `published` | 仅 `disposition: withheld -> normal` | `*.restored` | `ecosystem.{domain}.restore` |
| `retract` | 保持原状态 | `disposition=retracted` 终态；清候选与排期；公开指针保留 | `*.retracted` | `ecosystem.{domain}.retract` |
| `publishScheduled` | `scheduled -> published`；已发布对象切候选 | 同 publish | `*.published` | `ecosystem.{domain}.publish`（`actor_type=system`） |

补充：

- 所有动作：父行锁 → `expected_row_version` CAS → revision 行锁 → 重检 → 写入 → outbox → 审计，同一事务。
- 重复发布同一 revision、重复暂缓、重复撤稿是 no-op：不写 outbox、不推 `row_version`。
- `withhold` / `retract` 的调用方原因必填，但只进内部日志与稳定审计原因码，不进 outbox payload，不进公开字段。
- 排期时间接受 `DateTimeInterface`，服务内显式转 UTC；写入/读取使用 `UtcDateTime`。
- `PublicationPointerSwitcher` 是唯一写 `published_revision_id` 的生产代码；`EventWriteService` 与 `ContentPost` 模型守卫未放开。

---

## 3. 实测证据

### 3.1 新增 G1-08B 测试

```bash
DB_DATABASE=summon_mecha_step10_test /d/php.exe vendor/bin/pest \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemEventPublicationG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemContentPublicationG1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemPublicationDispositionG1Test.php
```

结果：**36 passed / 248 assertions**（三个文件合并执行为 5.81s）。

覆盖：

- 未批准 / 候选不匹配直接发布拒绝；
- 批准后正文 / 来源 / 媒体集合 / sanitizer 变动 → 重检失败且不切换；
- 主办方 pending / rejected / suspended → 拒绝；
- B-01/B-03 未签署非 platform+organizer → 拒绝；B-04/B-07 内容关闭 → 拒绝；
- 排期未来 / 过去 / 取消排期 / UTC 落库；
- 立即发布指针、时间、outbox、审计同事务；
- 已发布对象发布前旧指针保持、发布后切新、旧 revision 保留；
- withhold / restore / retract 全链、retract 终态、清候选与排期；
- 两个发布者相同 `expected_row_version` 只有一个成功；
- outbox / 审计失败整体回滚；重复发布幂等；
- 内容同构关键路径；
- 普通写入口与模型守卫仍不能写 `published_revision_id`。

### 3.2 G1-02 ~ G1-08A 回归

按文件分进程执行 `packages/Webkul/Shop/tests/Feature/Ecosystem/*Test.php`（共 24 个旧文件）：

结果：**267 passed / 2096 assertions，0 failed**。

### 3.3 静态检查

```bash
/d/php.exe -l <每个新增/修改 PHP 文件>   # 全部 No syntax errors detected
cd /d/main && git diff --check             # 无输出（DIFF_CHECK_OK）
```

---

## 4. 回滚

1. 先把 `ECOSYSTEM_EVENT_PUBLISH_ENABLED` / `ECOSYSTEM_CONTENT_PUBLISH_ENABLED` / `ECOSYSTEM_SCHEDULER_ENABLED` 保持/改回 `false`，停止新的发布与排期。
2. 回滚代码（显式文件清单，禁止 `git add -A`）：
   - 删除 `EventPublicationService.php`、`ContentPublicationService.php`、`PublicationPointerSwitcher.php`、`PublicationOutbox.php` 与三个新测试文件；
   - 用 `.claude/backup/G1-08B/` 中的副本恢复 `config/ecosystem_publishing.php`、`config/ecosystem_outbox.php`、`PublicationState.php`、`PublicationPreconditions.php`、`PublicationAudit.php`、ADR 与 plan。
3. 不删除 outbox、operation_log、revision；已发布事实是审计证据。
4. 本批没有新增迁移；`2026_10_19_000015` / `000016` 已在 G1-08A 落地，不需要为本批回滚数据库结构。
5. 生产回滚前导出 `events` / `content_posts` 的 `publish_status` / `disposition` / 三个指针 / `scheduled_publish_at`。

---

## 5. 未做 / 未验证

**未做**

- outbox worker、租约、恢复扫描、排期扫描常驻进程（G1-09）；
- Admin 发布台 / 处置台 / ACL / 菜单；
- 公开 Event/Content/Media API 与 DTO；
- H5 / 小程序前端；
- 排期失败自动回退 `approved`、告警与 `schedule_aborted` 审计（G1-09）；
- 任何公开开关或 B 项签署开关的打开。

**未验证**

- root / 生产迁移（本批未新增迁移，也未触碰 root）；
- 常驻 scheduler / 真实多进程 worker；
- 真实运营角色与 ACL；
- MySQL 5.7 生产数据量下的锁竞争。

---

## 6. 风险

1. **`operation_log.target_id` 仍是 32 位**：G1 父对象主键是 BIGINT，超出 2^31 后审计写入会失败并导致发布事务回滚（fail-closed），已在 GAP-15 记录，本批未扩列。
2. **并发测试是单进程串行模拟**：验证的是 `expected_row_version` CAS 语义，不等价于生产多进程/死锁压测。
3. **排期时间只接受 `DateTimeInterface`**：调用方必须给出带时区语义的时间对象；不要传裸字符串期望服务按 UTC 猜。
4. **`withhold` / `restore` / `retract` 不写公开缓存失效**：G1 尚无公开读取，G1-10 接入公开读取时必须确认没有长缓存。
5. **媒体文件存在性在发布/恢复时被重检**：私有盘文件被运维误删会导致发布/恢复失败（预期 fail-closed），需在 Runbook 中说明。
