# G1-06 媒体域 + G1-07 富文本安全 · 迁移与验证证据

- **日期**：2026-10-19
- **验证方式**：Codex 人工验收 + Claude 辅助实现
- **验证范围**：ADR-0001 §4.5 / §4.7 / §4.8 / §4.9 / §4.10、G1-D06、ecosystem_media / ecosystem_media_refs、媒体上传与公开读取、HTMLPurifier 净化、revision 媒体/指纹联动
- **结论**：独立测试库 `summon_mecha_step10_test` 上的媒体三迁移回滚/重跑、真实上传+引用+净化+公开门禁探针与 22 个指定测试文件全部通过。**root 主库未迁移**，未对 root 执行任何写入。
- **边界**：未打开公开/报名/内容开关；未新增公开媒体 HTTP 路由；未改 CMS、报名、发布/审核状态机、前端。

---

## 1. 范围

### G1-06 媒体域

1. `ecosystem_media`：私有盘、storage_key/sha256 唯一、真实 MIME、大小/尺寸、状态、上传人。
2. `ecosystem_media_refs`：event_revision/content_revision 引用、角色、排序、alt、引用审核状态。
3. `event_rule_versions.file_media_id` FK。
4. MediaService：真实 MIME / 扩展名 / 图片解码 / PDF 头 / 大小 / 尺寸 / 路径 / 哈希不可变校验。
5. MediaRefService：draft 期整组替换、媒体必须 approved、manifest hash、revision 指纹联动、引用审核。
6. MediaAccessService：域开关 + 当前 published revision + 父对象 published/normal + ref/media approved + 文件存在，任一失败统一 404。

### G1-07 富文本安全

1. RichTextSanitizer：HTMLPurifier 4.18 白名单、http/https、rel、危险标签/属性清理。
2. img 只允许已批准且被引用的 media-id；输出只保留 `data-media-id`，不保留远程 src。
3. EventRevisionService / ContentRevisionService 服务端净化并写真实 sanitizer_version。
4. EventRuleVersionService 净化规则正文；规则正文暂不支持内嵌图片。
5. MediaRefService 替换引用后重新净化正文、更新 media_manifest_hash 与内容指纹。
6. G1-04/G1-05 草稿更新保留已有 media_manifest_hash / file_media_id，不再清空。

---

## 2. 改动清单

### 2.1 新增

| 文件 | 内容 |
|---|---|
| `Migrations/2026_10_19_000012_create_ecosystem_media_table.php` | 媒体表与唯一键/status 索引 |
| `Migrations/2026_10_19_000013_create_ecosystem_media_refs_table.php` | 引用表、media FK、owner sort 唯一键 |
| `Migrations/2026_10_19_000014_add_file_media_fk_to_event_rule_versions.php` | 规则文件 FK |
| `Models/EcosystemMedia.php` / `EcosystemMediaRef.php` | 物理身份冻结、枚举守卫、引用父 draft 守卫 |
| `Services/Ecosystem/MediaService.php` | 上传/审核/规则文件绑定 |
| `Services/Ecosystem/MediaRefService.php` | 引用替换/审核/manifest/指纹 |
| `Services/Ecosystem/MediaManifest.php` | 有序媒体集合 sha256 |
| `Services/Ecosystem/MediaAccessService.php` | 公开媒体门禁 |
| `Services/Ecosystem/RichTextSanitizer.php` | HTMLPurifier 白名单净化 |
| 3 个媒体异常 + 媒体/富文本配置 | 稳定错误码与注册表 |
| 6 个新测试文件 | schema/upload/access/refs/rule/sanitizer |

### 2.2 修改

| 文件 | 改动 |
|---|---|
| `ApiErrorCode.php` | 新增 MEDIA_INVALID / MEDIA_NOT_FOUND |
| `EventRevisionService.php` | detail_html 净化、真实 sanitizer_version、保留 media_manifest_hash |
| `ContentRevisionService.php` | body_html 净化、真实 sanitizer_version、保留 media_manifest_hash |
| `EventRuleVersionService.php` | body_html 净化、保留 file_media_id |
| G1-04/G1-05 旧测试 | 更新为媒体/净化后的真实行为 |

---

## 3. root 与独立测试库事实

| 库 | media | refs | content_posts | content_revisions | content_sources | event_revisions | rules | CMS pages |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `root` | 表不存在 | 表不存在 | 表不存在 | 表不存在 | 表不存在 | 表不存在 | 表不存在 | 10 |
| `summon_mecha_step10_test` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 10 |

测试库最终无探针媒体/引用/正文残留；root 未迁移。

```text
PHP 8.4.24 / Laravel 11.44.2 / MySQL 5.7.26 / HTMLPurifier 4.18
G1-06 migrations: 13 / 14 / 15
```

---

## 4. 迁移回滚与重跑

### 4.1 回滚三步

```text
2026_10_19_000014_add_file_media_fk_to_event_rule_versions . DONE
2026_10_19_000013_create_ecosystem_media_refs_table ........ DONE
2026_10_19_000012_create_ecosystem_media_table ............. DONE
```

回滚后：

- ecosystem_media / refs 不存在；
- file_media FK 不存在；
- content/event revisions 与 CMS 10 页均未受影响。

### 4.2 分步重跑

```text
000012 54.16ms DONE
000013 69.73ms DONE
000014 43.93ms DONE
```

重跑后：

- 媒体两表为空；
- file_media FK 恢复；
- CMS 10 页不变。

---

## 5. 真实链路探针

事务内执行、结束整体回滚：

1. 上传并批准 PNG / PNG / PDF 三份媒体；
2. 创建并核验 organizer、配置 event 来源、创建 event revision；
3. `detail_html` 中合法 media-id 图片保留，script 被移除；
4. 挂 event media ref，生成 media_manifest_hash 与内容指纹；
5. 内容文章 revision 同样净化 body_html、挂 cover、生成 manifest/指纹；
6. 规则版本挂 PDF 文件，指纹变化且 script 被移除；
7. 模拟活动 published + current published revision + 开关打开，MediaAccessService 可读取；
8. 将媒体置 quarantined 后统一 404。

```json
{"media_uploaded_approved":true,"event_revision_sanitized":true,"event_media_ref_manifest":true,"event_ref_approved":true,"content_revision_sanitized":true,"content_media_ref_manifest":true,"rule_file_attached":true,"public_media_allowed":true,"quarantined_media_denied":true,"cleanup_counts":{"media":0,"refs":0,"content_posts":0,"content_revisions":0,"content_sources":0,"event_revisions":0,"rules":0,"cms_pages":10}}
```

`PROBE_PASS`。

---

## 6. 测试结果

| 组 | passed | assertions |
|---|---:|---:|
| G1-06/07 媒体+净化子组 | 60 | 458 |
| 其余 G1-02/03/04/05 + G0 + Admin 回归 | 198 | 1748 |
| **合计（22 文件）** | **258 passed / 0 failed** | **2206** |

G1-06/07 子组覆盖：

- 媒体两表全列/NULL/默认/唯一键/索引/FK；
- 真实 PNG/PDF、MIME 伪造、SVG/PHP、大小、尺寸、路径、重复 hash；
- 私有盘落盘、无公开 URL；
- pending/approved/rejected/quarantined；
- MediaRefService 整组替换、max refs、角色/alt、非 draft 冻结、reviewRef；
- manifest hash 与 event/content 指纹联动；
- 规则文件 FK 写入与非 approved 拒绝；
- HTML 危险标签/事件属性/协议/远程图片清理；
- img 只保留已批准引用 media-id；
- revision 服务净化、真实 sanitizer_version、media_manifest_hash/file_media_id 保留；
- 公开媒体门禁逐项拒绝与合法场景通过。

---

## 7. 静态检查与边界

- 31 个本轮 PHP 文件 `php -l`：0 failures；
- `git diff --check`：通过；
- 媒体三迁移 `[13]/[14]/[15] Ran`；
- 四开关仍为 `false`；
- 无公开媒体 HTTP 路由；
- 无真实公开文件 URL / CDN / signed URL；
- root 未迁移；
- 工作区另有一个与本任务无关的 `utils/config.js` 改动，未纳入本次提交。

---

## 8. 回滚命令

仅限独立测试库；生产/root 执行前必须备份。

```bash
cd D:/main/my-bagisto-store

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000014_add_file_media_fk_to_event_rule_versions.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000013_create_ecosystem_media_refs_table.php --force

DB_DATABASE=summon_mecha_step10_test /d/php.exe artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_19_000012_create_ecosystem_media_table.php --force
```

重新应用按 000012 → 000013 → 000014 顺序执行。

---

## 9. 明确未做

1. **root 主库未迁移**。
2. **无公开媒体 HTTP 路由/controller**：MediaAccessService 已有门禁，但未对外暴露。
3. **无前端富文本渲染**：sanitizer 输出 `data-media-id`，G1-11 再接安全渲染与媒体解析。
4. **无审核/发布状态机**：非空 published 指针仍由后续 G1-08 控制。
5. **规则正文暂不支持内嵌图片**：本批 allowedMediaIds 固定为空。
6. **无媒体清理/保留策略/B-05 期限**。
7. **无 CDN、signed URL、第三方副本清除承诺**。
8. **无 Admin UI/路由**。
9. **无 CMS / 报名改动**。
10. **四开关仍为 false**。

---

## 10. 风险与后续

| 风险 | 说明 | 当前处置 |
|---|---|---|
| 私有盘配置可被人为改错 | `ecosystem_media.disk` 若指向 public 盘会泄露。 | 部署检查必须确认 private disk；无公开 URL；测试锁住当前 local 配置。 |
| HTMLPurifier 版本升级 | 白名单/解析行为可能变化。 | sanitizer_version=g1-07-v1；算法/配置变化必须升版本并重跑危险样本。 |
| 图片渲染依赖 media-id | sanitized HTML 不含 src，渲染端必须通过受控接口解析。 | G1-11 前不得直接渲染；当前无公开路由。 |
| media manifest 含 review_status | 引用审核状态变化会改变 manifest/指纹。 | reviewRef 同步重算，保证批准/发布核验一致。 |
| 引用只能 draft 期修改 | 已提审/批准的 revision 不能改媒体，必须新 revision。 | 模型与服务双层守卫。 |
| 规则正文不含图片 | 当前规则图片只能通过 file_media_id 作为文件。 | 内嵌规则图片留后续决策。 |
| 无公开路由 | MediaAccessService 只有服务层。 | 不泄露 storage_key；G1-10/G1-11 再接 controller 与渲染。 |
| root 未迁移 | 当前证据仅测试库。 | 生产迁移前备份、演练、维护窗口。 |

---

## 11. Git 交付状态

由 Codex 在验收通过后按明确文件清单提交到当前分支，提交信息 `feat(ecosystem): add G1-06 media and G1-07 sanitizer`，未使用 `git add -A`，未 push。`utils/config.js` 的无关改动未纳入。