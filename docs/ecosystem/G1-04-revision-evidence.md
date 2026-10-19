# G1-04 活动修订与规则版本 · 迁移与验证证据

- **日期**：2026-10-19
- **验证方式**：Codex 人工独立验收（Claude 验证会话因余额中断后接手）
- **验证范围**：ADR-0001 §4.2 / §4.3 / §4.5、G1-D03 / G1-D04、`event_revisions`、`event_rule_versions`、`events` 双 revision 指针、内容指纹、草稿服务与不可变守卫
- **结论**：独立测试库 `summon_mecha_step10_test` 上的三迁移回滚、分步重跑、旧字段比对、真实服务链路探针与 13 个指定测试文件全部通过。**root 主库未迁移**，未对 root 执行任何写入。
- **边界**：未执行 `git add` / `git commit` 之外的其他 Git 操作；未执行 `migrate:fresh` / `truncate` / `seed`；未改 `event_registrations`、公开/报名/内容开关、前端、内容域或媒体域。

---

## 1. 范围

1. 新建 `event_revisions`：活动内容不可变快照、revision 编号、审核字段、来源快照、规则摘要、内容指纹和审计字段。
2. 新建 `event_rule_versions`：规则版本、权威语言、正文/外链、生效时间、状态、审核记录和指纹。
3. 为 `events.published_revision_id` / `working_revision_id` 补 `event_revisions.id` 外键。
4. 新建 `EventRevisionService` / `EventRuleVersionService`：只做 draft 创建/编辑；版本号在活动行锁内递增；指纹服务端计算；指纹 CAS 防静默覆盖。
5. 新建模型不可变守卫：离开 draft 后内容冻结，身份列与删除永久禁止。
6. 新建 `RevisionPointerValidator`：G1-04 只允许同活动 draft revision 挂 working 指针；非空 published 指针留给 G1-08。
7. 存量 8 条 events 的 revision 指针保持 NULL；不生成历史 revision，不猜测内容。

---

## 2. 改动清单

### 2.1 新增

| 文件 | 内容 |
|---|---|
| `Migrations/2026_10_19_000005_create_event_revisions_table.php` | `event_revisions` 全字段、唯一键、索引、两个 RESTRICT FK |
| `Migrations/2026_10_19_000006_create_event_rule_versions_table.php` | `event_rule_versions` 全字段、唯一键、索引、event FK；`file_media_id` 暂无 FK |
| `Migrations/2026_10_19_000007_add_revision_pointers_fk_to_events.php` | `events` 两个 revision 指针 FK |
| `Models/EventRevision.php` | fillable/casts/关系；非 draft 冻结、身份列冻结、禁止删除 |
| `Models/EventRuleVersion.php` | fillable/casts/关系；非 draft 冻结、身份列冻结、禁止删除 |
| `Services/Ecosystem/ContentFingerprint.php` | 递归规范化 JSON + sha256；UTC 时间归一；带 fingerprint_version |
| `Services/Ecosystem/EventRevisionService.php` | revision draft 创建/编辑、版本号、来源/时间/URL/枚举校验、指纹 CAS |
| `Services/Ecosystem/EventRuleVersionService.php` | 规则 draft 创建/编辑、版本号、正文/外链、语言、指纹 CAS |
| `Services/Ecosystem/RevisionPointerValidator.php` | working 指针归属/状态守卫；published 指针在 G1-04 禁止非 NULL |
| `Services/Ecosystem/RevisionImmutableException.php` | `EVENT_TRANSITION_INVALID` |
| `Services/Ecosystem/EventTimeValidationException.php` | `EVENT_TIME_INVALID` |
| `Services/Ecosystem/RevisionConflictException.php` | `EVENT_REVISION_CONFLICT` + expected/actual 指纹 |
| `config/ecosystem_revisions.php` | review/rule 状态、授权依据、参与方式、不可变状态、指纹版本 |
| 四个 G1-04 Feature 测试 | schema、指针、revision 服务、规则服务 |

### 2.2 修改

| 文件 | 改动 |
|---|---|
| `Models/Event.php` | `revisions` / `ruleVersions` / `publishedRevision` / `workingRevision` 关系 |
| `Services/Ecosystem/EventSourceValidator.php` | 抽出公开 `assertSourceRoleCombination()`，来源组合规则复用 |
| `Services/Ecosystem/EventWriteService.php` | 注入 revision 指针守卫；published/working 指针 dirty 时校验 |
| `ApiErrorCode.php` | 新增 `EVENT_TIME_INVALID`、`EVENT_TRANSITION_INVALID` |
| G1-02/G1-03 旧测试 | 更新为 G1-04 的合法 revision 表与指针 FK 事实 |

---

## 3. root 与独立测试库事实

### 3.1 验证结束时

| 库 | events | registrations | organizers | outbox | revisions | rules |
|---|---:|---:|---:|---:|---:|---:|
| `root` | 8 | 1 | 表不存在 | 表不存在 | 表不存在 | 表不存在 |
| `summon_mecha_step10_test` | 0 | 0 | 0 | 0 | 0 | 0 |

root 仍无 G1-02/G1-03/G1-04 的新列、revision 表或指针 FK。测试库最终无探针残留。

### 3.2 技术基线

```text
PHP 8.4.24 / Laravel 11.44.2 / MySQL 5.7.26
G1-04 migration batches: 6 / 7 / 8
```

---

## 4. 迁移回滚与重跑

### 4.1 验证前

- 测试库干净后复制 root 的 8 行旧 events，id 1..8。
- 旧字段快照 md5：`6d4a6764e5c9f599f6283ec9e078e61f`。

### 4.2 回滚三步

```text
2026_10_19_000007_add_revision_pointers_fk_to_events ... DONE
2026_10_19_000006_create_event_rule_versions_table ..... DONE
2026_10_19_000005_create_event_revisions_table ......... DONE
```

回滚后：

- `event_revisions` / `event_rule_versions` 均不存在；
- events 两指针 FK 不存在；
- 8 行旧字段 md5 仍为 `6d4a6764e5c9f599f6283ec9e078e61f`；
- `event_registrations` 保持 0，结构未变。

### 4.3 分步重跑

```text
000005 转 131.01ms DONE
000006 转  83.04ms DONE
000007 转  93.18ms DONE
```

重跑后：

- 6 条目标 FK 全部恢复：events→organizers、events→event_revisions×2、event_revisions→events/organizers、event_rule_versions→events；
- 8 行 event 的 `published_revision_id` / `working_revision_id` 仍全部 NULL；
- 旧字段 md5 不变；
- revisions=0、rules=0、registrations=0。

---

## 5. 真实服务链路探针

在事务内执行，结束后整体回滚：

1. `OrganizerService` 创建并核验 organizer；
2. `EventSourceService` 将活动设为 `platform + organizer`，row_version=1；
3. `EventRevisionService::createDraft` 生成 revision_no=1、draft、64 位指纹；
4. 修改 summary 后指纹变化；
5. `EventRuleVersionService::createDraft` 生成 version_no=1、draft、64 位指纹；
6. 修改 body_html 后指纹变化；
7. revision 置 `in_review` 后 updateDraft 抛 `EVENT_TRANSITION_INVALID`；
8. rule 置 `approved` 后 updateDraft 抛 `EVENT_TRANSITION_INVALID`；
9. 新建 revision_no=2，挂同活动 draft working 指针成功；
10. 写非空 published 指针被拒绝（G1-08 才允许）。

结果：

```json
{"organizer_verified":true,"event_source_assigned":true,"revision_created":true,"revision_fingerprint_changed":true,"rule_created":true,"rule_fingerprint_changed":true,"revision_frozen_after_submit":true,"rule_frozen_after_approval":true,"revision_sequence":true,"working_pointer_assigned":true,"published_pointer_blocked":true,"cleanup_counts":{"events":8,"registrations":0,"organizers":0,"outbox":0,"revisions":0,"rules":0}}
```

`PROBE_PASS`。

---

## 6. 测试结果

| 组 | passed | assertions |
|---|---:|---:|
| G1-04（schema / pointer / revision / rule） | 58 | 462 |
| G1-03（organizer / source） | 36 | 222 |
| G1-02（event schema / outbox） | 20 | 179 |
| G0 报名与公开闸 | 7 | 45 |
| Admin ACL / Form / PII | 23 | 329 |
| **合计** | **144 passed / 0 failed** | **1237** |

覆盖：

- 两表全列类型/NULL/默认、唯一键、索引、FK；
- `file_media_id` 本批无 FK；
- revision/rule 编号 1、2 递增与唯一冲突；
- 指纹稳定性、内容变化、等价时区表示；
- 时间、IANA 时区、起止时间、URL、参与方式和授权依据；
- revision/rule 非 draft 内容冻结；
- 身份列冻结与删除禁止；
- working 指针同活动/draft 校验、跨活动/非 draft 拒绝；
- published 指针 G1-04 非 NULL 拒绝；
- FK 真实阻止删除被引用的 activity/revision；
- `event_registrations` 与四个开关不变。

---

## 7. 静态检查与边界

- 本轮 23 个 PHP 文件 `php -l`：0 failures；
- `git diff --check`：通过；
- G1-04 三迁移 `[6]/[7]/[8] Ran`；
- `content_posts` / `content_revisions` / `ecosystem_media` 均不存在；
- 四个公开/报名/内容开关仍为 `false`；
- root 未迁移。

---

## 8. 回滚命令

仅限独立测试库。生产/root 执行前必须按 ADR §11.2 备份。

```bash
cd D:/main/my-bagisto-store

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000007_add_revision_pointers_fk_to_events.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000006_create_event_rule_versions_table.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000005_create_event_revisions_table.php --force
```

重新应用时按 000005 → 000006 → 000007 顺序执行。

---

## 9. 明确未做

1. **root 主库未迁移**。
2. **无审核/发布状态机**：没有 draft→in_review→approved→scheduled→published、发布 worker、撤稿/暂缓。
3. **无非空 published 指针写入**：G1-08 才开放。
4. **无 outbox 发布事件写入**：revision/rule 服务不写 outbox。
5. **无媒体**：`file_media_id`、`media_manifest_hash` 保持 NULL，媒体表/FK 留 G1-06。
6. **无富文本净化**：`detail_html` / `body_html` 仅原样存储，未公开读取，净化留 G1-07。
7. **无内容域**：`content_posts` / `content_revisions` 未建。
8. **无报名改动**：`event_registrations` 结构、停写闸和 PII 规则未改。
9. **无 Admin UI/路由/前端**。
10. **四开关仍为 false**。

---

## 10. 风险与后续

| 风险 | 说明 | 当前处置 |
|---|---|---|
| 循环 FK | event 引用 revision，revision 又引用 event；有 revision 的活动不能物理删除，revision 也不能删除。 | 按审计证据设计保留；G1-08 必须用归档/撤稿而不是删库。 |
| 模型守卫可被绕过 | Query Builder/DB 直写可绕过 `updating`/`deleting`。 | 服务层是唯一业务写入口；FK 作为数据库兜底。 |
| 指纹算法变更 | 算法/字段口径变化后旧指纹不可比。 | `fingerprint_version` 参与哈希；变更必须递增。 |
| 主办方改名 | revision 创建时快照必须等于当前主体名；草稿期间主体改名不阻塞无关编辑，显式改快照时才重新比对新名。 | 已有回归测试。 |
| 富文本未净化 | detail_html/body_html 当前不可公开读取。 | G1-07 前不得接公开输出；不得把“已存储”当成“可公开”。 |
| published 指针未开放 | G1-04 只建 FK；发布切换未实现。 | 由 G1-08 专门服务完成，普通写入口继续拒绝。 |
| root 未迁移 | 当前所有证据仅来自独立测试库。 | 生产迁移前备份、演练、维护窗口；不得直接对 root 执行。 |

---

## 11. Git 交付状态

独立验收完成后由 Codex 按明确文件清单提交到当前分支，提交信息为 `feat(ecosystem): add G1-04 revisions and rule versions`，未使用 `git add -A`，未 push。