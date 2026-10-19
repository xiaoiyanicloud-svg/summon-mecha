# G1-05 独立资讯内容域 · 迁移与验证证据

- **日期**：2026-10-19
- **验证方式**：Codex 人工独立验收（Claude 辅助实现）
- **验证范围**：ADR-0001 §4.6 / §4.7 / §4.8、G1-D06、content_posts / content_revisions / content_sources、slug 冲突策略、来源权利依据、内容指纹、CMS 边界
- **结论**：独立测试库 `summon_mecha_step10_test` 上的四迁移回滚、分步重跑、CMS 10 页比对、真实内容链路探针与 16 个指定测试文件全部通过。**root 主库未迁移**，未对 root 执行任何写入。
- **边界**：未打开内容公开开关；未改 CMS 页面、公开/报名开关、前端、媒体或报名表；未执行 `migrate:fresh` / `truncate` / `seed`。

---

## 1. 范围

1. 新建 `content_posts`：内容类型、slug、分类、发布轴、处置轴、双 revision 指针、`row_version` 和审计人。
2. 新建 `content_revisions`：不可变内容快照、作者、来源类型、原文时间、更正说明、审核字段、内容指纹。
3. 新建 `content_sources`：来源名称、原文 URL、核验时间、权利依据、授权范围、私有依据引用和展示顺序。
4. 新建 `ContentPostService` / `ContentRevisionService`：草稿创建/编辑、slug 规则、版本号、来源整组替换、指纹 CAS。
5. slug 冻结策略：全局唯一、3–120、小写 ASCII/数字/连字符、拒保留前缀；首次发布后不可改；撤稿后 slug 永久保留。
6. 内容指纹包含正文与**有序来源清单**，来源变化同样改变指纹。
7. CMS 静态页彻底隔离：`cms_pages` 仍为 10 页，内容域初始 0 篇，不建立 `cms_page_id` 关联。

---

## 2. 改动清单

### 2.1 新增

| 文件 | 内容 |
|---|---|
| `Migrations/2026_10_19_000008_create_content_posts_table.php` | content_posts 全字段、uk(slug)、6 个索引 |
| `Migrations/2026_10_19_000009_create_content_revisions_table.php` | content_revisions 全字段、uk(post_id,revision_no)、review_state 索引、post FK |
| `Migrations/2026_10_19_000010_create_content_sources_table.php` | content_sources 全字段、idx(revision_id)、uk(revision_id,sort_order)、revision FK |
| `Migrations/2026_10_19_000011_add_content_pointers_fk_to_content_posts.php` | content_posts 双 revision 指针 FK |
| `Models/ContentPost.php` | slug 发布后冻结、双指针守卫、禁止删除 |
| `Models/ContentRevision.php` | 非 draft 内容冻结、身份列冻结、禁止删除 |
| `Models/ContentSource.php` | 父 revision 非 draft 时来源冻结 |
| `Services/Ecosystem/ContentPostService.php` | 文章草稿、slug、row_version CAS |
| `Services/Ecosystem/ContentRevisionService.php` | revision 草稿、来源校验/替换、指纹 CAS |
| `Services/Ecosystem/ContentFingerprint.php` | 扩展 content_revision 指纹（含有序来源） |
| `config/ecosystem_content.php` | 内容/来源/权利/状态/slug 注册表 |
| 4 个内容域异常 + 1 个指纹冲突异常 | 稳定错误码 |
| 3 个 G1-05 Feature 测试 | schema、文章壳、revision/source |

### 2.2 修改

| 文件 | 改动 |
|---|---|
| `ApiErrorCode.php` | 新增 CONTENT_SLUG_TAKEN / CONTENT_SOURCE_REQUIRED / CONTENT_REVISION_CONFLICT / CONTENT_TRANSITION_INVALID |
| `EcosystemOrganizerSchemaG1Test.php` | 内容三表已建，媒体表仍未建 |

---

## 3. root 与独立测试库事实

### 3.1 验证结束时

| 库 | content_posts | content_revisions | content_sources | CMS pages | media 表 |
|---|---:|---:|---:|---:|---|
| `root` | 表不存在 | 表不存在 | 表不存在 | 10 | 不存在 |
| `summon_mecha_step10_test` | 0 | 0 | 0 | 10 | 不存在 |

测试库最终无探针残留；root 仍只有 8 条旧 events 和 1 条旧报名。

### 3.2 技术基线

```text
PHP 8.4.24 / Laravel 11.44.2 / MySQL 5.7.26
G1-05 migration batches: 9 / 10 / 11 / 12
```

---

## 4. 迁移回滚与重跑

### 4.1 回滚四步

```text
2026_10_19_000011_add_content_pointers_fk_to_content_posts ... DONE
2026_10_19_000010_create_content_sources_table ............... DONE
2026_10_19_000009_create_content_revisions_table ............. DONE
2026_10_19_000008_create_content_posts_table ................. DONE
```

回滚后：

- content_posts / content_revisions / content_sources 均不存在；
- CMS pages 仍为 10；
- events 仍为 0、event_registrations 仍为 0（测试库基线）。

### 4.2 分步重跑

```text
000008 82.25ms DONE
000009 73.51ms DONE
000010 101.59ms DONE
000011 79.93ms DONE
```

重跑后：

- 4 条内容 FK 全部恢复：content_posts→content_revisions×2、content_revisions→content_posts、content_sources→content_revisions；
- 三表为空；
- CMS pages 仍为 10；
- `ecosystem_media` / `ecosystem_media_refs` 仍不存在（G1-06）。

---

## 5. 真实内容链路探针

在事务内执行，结束后整体回滚：

1. ContentPostService 创建 draft 文章，slug 冲突被 CONTENT_SLUG_TAKEN 拒绝；
2. ContentRevisionService 创建 revision_no=1、draft、64 位指纹；
3. 原始来源写入成功，source.created_at 自动生成；
4. 修改 summary 后指纹变化；
5. 整组替换来源后指纹再次变化；
6. revision 置 in_review 后，内容更新与来源修改/删除均被拒绝；
7. 新建 revision_no=2，挂同文章 draft working 指针成功；
8. 非空 published 指针被拒绝（G1-08 才允许）。

结果：

```json
{"post_draft_created":true,"slug_conflict_rejected":true,"revision_created":true,"source_created":true,"content_fingerprint_changed":true,"source_replacement_changes_fingerprint":true,"revision_frozen_after_submit":true,"source_frozen_after_submit":true,"source_delete_blocked_after_submit":true,"revision_sequence":true,"working_pointer_assigned":true,"published_pointer_blocked":true,"cleanup_counts":{"content_posts":0,"content_revisions":0,"content_sources":0,"cms_pages":10}}
```

`PROBE_PASS`。

---

## 6. 测试结果

| 组 | passed | assertions |
|---|---:|---:|
| G1-05（schema / post / revision+source） | 48 | 473 |
| G1-04（revision / rule） | 58 | 462 |
| G1-03（organizer / event source） | 36 | 225 |
| G1-02（event schema / outbox） | 20 | 177 |
| G0 报名与公开闸 | 7 | 45 |
| Admin ACL / Form / PII | 23 | 329 |
| **合计** | **192 passed / 0 failed** | **1711** |

覆盖：

- 三表全列类型/NULL/默认、唯一键、索引、FK；
- CMS 10 页不变、content_posts 初始 0、无 cms_page_id；
- slug 边界/字符集/保留前缀/唯一/撤稿保留/发布后冻结；
- 文章 row_version CAS、no-op、非 draft 拒绝；
- revision_no 递增、指纹含来源、来源替换、stale fingerprint；
- original / authorized_repost / link_summary 三种来源与权利依据；
- link_summary 正文禁令；
- 非 draft 内容与来源冻结；
- working/published 指针守卫；
- 无媒体表、内容开关 false。

---

## 7. 静态检查与边界

- 本轮 21 个 PHP 文件 `php -l`：0 failures；
- `git diff --check`：通过；
- G1-05 四迁移 `[9]/[10]/[11]/[12] Ran`；
- `content.public_enabled=false`，无公开内容 API/前端；
- `ecosystem_media` / `ecosystem_media_refs` 不存在；
- root 未迁移。

---

## 8. 回滚命令

仅限独立测试库；生产/root 执行前必须备份。

```bash
cd D:/main/my-bagisto-store

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000011_add_content_pointers_fk_to_content_posts.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000010_create_content_sources_table.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000009_create_content_revisions_table.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000008_create_content_posts_table.php --force
```

重新应用按 000008 → 000009 → 000010 → 000011 顺序执行。

---

## 9. 明确未做

1. **root 主库未迁移**。
2. **内容公开读取未接入**：`content.public_enabled=false`，无列表/详情 API、无前端。
3. **无审核/发布状态机**：无非空 published 指针写入、无发布 worker/outbox。
4. **无媒体域**：`media_manifest_hash` 强制 NULL，`ecosystem_media` / refs 未建（G1-06）。
5. **无富文本净化**：`body_html` 原样存储，`sanitizer_version` 固定为 `unsanitized-g1-05`，不得公开输出（G1-07）。
6. **无 Admin UI/路由**：只有服务层与测试。
7. **无数字转拼音/最终保留词全集**：B-04 未冻结，当前只实现已冻结的最小 slug 规则；公开发布继续关闭。
8. **无报名改动**：`event_registrations` 未动。
9. **无 CMS 改动**：10 个协议静态页保持原样。

---

## 10. 风险与后续

| 风险 | 说明 | 当前处置 |
|---|---|---|
| B-04 slug 最终规则未定 | 字符集、保留词全集、中文转写规则仍未最终签署。 | 只实现冻结最小规则；公开开关保持 false；发布前必须复核 B-04。 |
| 富文本未净化 | body_html 原样存储，placeholder sanitizer_version。 | G1-07 前不得接公开读取；不能把“已存储”当“可公开”。 |
| 指纹包含来源 | 来源/权利依据是审批事实，改来源必须使指纹变化。 | 已在 ContentFingerprint 中包含有序来源清单。 |
| 来源记录随 revision 冻结 | 修改来源必须新建 revision。 | ContentSource 模型守卫父 revision 必须 draft。 |
| 循环 FK | content_posts ↔ content_revisions 互相引用；文章/revision 不能物理删除。 | 按审计证据设计保留，G1-08 使用撤稿/归档而不是删库。 |
| CMS 混入风险 | 内容域与 CMS 仍可能被后续代码误连。 | 无 cms_page_id、无 CMS 外键；测试锁住 10 页不变。 |
| root 未迁移 | 当前证据仅来自独立测试库。 | 生产迁移前备份、演练、维护窗口。 |

---

## 11. Git 交付状态

由 Codex 在验收通过后按明确文件清单提交到当前分支，提交信息 `feat(ecosystem): add G1-05 content domain`，未使用 `git add -A`，未 push。