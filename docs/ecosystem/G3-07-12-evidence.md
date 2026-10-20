# G3-07 ~ G3-12 交付证据

日期：2026-10-20。分支：`codex/ecosystem-g2-free-registration`（延续 G2/G3 工作树）。
范围：计分契约、成绩复核发布、申诉、修订奖项、作品展示提审、整届演练。
**未打开任何生产写开关。**

## 1 结论

- G3-07 ~ G3-12 的代码、独立测试库验证、root additive 迁移均已完成。
- 首场真实赛规仍未签署，因此 root 没有真实赛项/成绩/申诉/奖项/展示；
  `competition_*` 新表为空。
- 新公开开关 `public_results_enabled`、`public_showcase_enabled` 默认 `false`；
  赛务写闸 `write_enabled` 仍为 `false`。
- 本批不宣称“完整赛事平台已上线”，也不宣称真机比赛验收或生产并发压测通过。

## 2 交付清单

### G3-07 计分契约与策略

- 规则契约扩展：`scoring.policy`、`scoring.scale`、`scoring.fields`、
  `scoring.missing`、`scoring.forfeit`、`tie.policy`。
- 策略注册表：`manual`、`total_desc`（`config/ecosystem_competition.php`）。
- `CompetitionScoringService`：定点整数计算、bcmath 求和、拒绝 JSON 浮点/
  科学计数法/表达式、字段范围校验。
- 并列语义：`shared` 同名次跳号；`strict/manual` 并列组全部留空，等待人工核准。
- 修正 `CompetitionRuleProfile::normalize()`：列表内对象递归排序，
  避免 MySQL JSON 回读键顺序导致指纹误报不一致（G3-01 遗留问题）。

### G3-08 成绩草稿 / 复核 / 发布 / 修订

| 表 | 内容 |
|---|---|
| `competition_results` | `(track, entry, scope_key)` 主体；scope_key = track / match:{id} |
| `competition_result_versions` | 不可变版本、定点分数、名次/并列组、策略快照、规则版本、操作人 |

- 状态：`draft → submitted → provisional → final`；修订进入 `revising`，
  旧版本标 `superseded` 并保留 `superseded_status`。
- 录分权限 `ecosystem.competition.scoring`；复核发布权限
  `ecosystem.competition.results`；服务层强制同一管理员不能既录分又发布。
- 赛项级暂定发布/终审，未确定名次的非排除条目拒绝发布。
- 公开接口 `GET /api/events/{id}/competition/results`，独立开关，
  只返回 `published_version_id`；修订中返回旧版本并标 `under_review`。
- 成绩暂定/终审写 outbox：`competition_result.provisional` / `.final`，
  队长站内信去重。

### G3-09 申诉

| 表 | 内容 |
|---|---|
| `competition_appeals` | 绑定 result/version、窗口、状态、裁决字段 |
| `competition_appeal_events` | 追加式时间线（提交/受理/调查/裁决/撤回） |
| `competition_appeal_attachments` | 私有证据、服务端路径、sha256 |

- 只允许当前有效队长提交；绑定当前公布版本；窗口 = 公布时间 + `window_minutes`。
- 证据按规则声明的类型映射真实 MIME；扩展名必须匹配；数量/大小上限。
- 裁决必须写 `decision_result` / `decision_reason` / `decision_result_version_id`；
  当前公布版本变化时拒绝并提示重新核对。
- 客户 API：列表/详情/提交/撤回/证据上传/私有下载；管理端：受理/裁决/证据下载。
- 申诉受理/裁决/撤回写 outbox，站内信去重。

### G3-10 修订与奖项版本

| 表 | 内容 |
|---|---|
| `competition_awards` | 赛项内 award_key 唯一 |
| `competition_award_versions` | 奖项版本、引用 result_version、状态、历史 |

- 发布必须引用当前已终审成绩版本；旧 published/待复核版本在发布新版本时标
  `superseded`。
- 成绩修订调用 `markPendingForResultVersion()`，引用旧版本的已发布奖项转
  `pending_review`，公开面立即不再返回。
- 撤销奖项保留版本历史；公开只认“奖项 published + 版本 published +
  成绩仍为当前终审”。

### G3-11 作品展示与技能提审交接

| 表 | 内容 |
|---|---|
| `competition_showcases` | entry/作品版本/奖项/展示类型/授权/状态/技能交接 |

- 只能引用已提交作品版本；必须由当前队长对具体版本授权，
  `consent_fingerprint` 绑定版本内容指纹 + 展示标题/摘要/类型。
- 标题或摘要改动会使旧授权失效，必须重新授权；撤回授权会立即下架已发布展示。
- 公开字段仅标题/摘要/队伍/赛道/奖项，不含附件、repo、video、内部备注。
- 获奖不自动上架、不发 license、不下发真机；服务不触碰商品/授权/设备表。
- 队长可显式请求技能提审：需开发者身份 + 自有技能版本，
  调用既有 `DeveloperSkillService::submitForReview`，记录交接结果。

### G3-12 整届演练

- `CompetitionFullEditionDrillG3Test`：规则签署→建队/邀请/接受→队伍报名快照→
  作品→赛程导入/发布→录分/排名/暂定/终审→申诉/受理/裁决→改分重赛→
  奖项待复核/重新发布→展示授权/发布→技能提审交接。
- 覆盖 T59–T69、T72；详见 [G3-12-drill.md](./G3-12-drill.md)。
- 演练中的技能提审使用 mock，只证明调用既有闸门与记录交接，
  **不代表真实技能审核通过**。

## 3 数据库迁移

| 迁移 | 内容 |
|---|---|
| `2026_10_19_120001_create_competition_results` | 成绩主体/版本 |
| `2026_10_19_120002_create_competition_appeals` | 申诉主体/事件/证据 |
| `2026_10_19_120003_create_competition_awards` | 奖项主体/版本 |
| `2026_10_19_120004_create_competition_showcases` | 展示/授权/技能交接 |

均为 additive、幂等（表/索引/外键分别补齐），前置表缺失时显式 throw。
未修改已执行的 G0/G1/G2/G3-01~06 原始迁移。

## 4 权限与开关

| 开关/权限 | 值/用途 |
|---|---|
| `ECOSYSTEM_COMPETITION_WRITE_ENABLED` | `false`：所有赛务写动作稳定 403 |
| `ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED` | `false` |
| `ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED` | `false`：公开成绩 404 |
| `ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED` | `false`：公开展示 404 |
| `ecosystem.competition.scoring` | 录分/提交复核 |
| `ecosystem.competition.results` | 排名/暂定/终审/修订 |
| `ecosystem.competition.appeal` | 申诉受理/裁决 |
| `ecosystem.competition.awards` | 奖项版本/发布/撤销 |
| `ecosystem.competition.showcase` | 展示创建/审核/发布/撤回 |

每条管理路由同时登记 `config/ecosystem_admin.php` 与 `config/acl.php`；
ACL 全路由测试覆盖新路由。

## 5 测试证据（真实输出摘要）

```bash
cd D:/main/my-bagisto-store
DB_DATABASE=summon_mecha_step10_test php artisan test --filter="G3"
# 62 passed / 409 assertions

DB_DATABASE=summon_mecha_step10_test php artisan test packages/Webkul/Shop/tests/Feature/Ecosystem
# 477 passed / 3,441 assertions

DB_DATABASE=summon_mecha_step10_test php artisan test \
  --filter="EcosystemPublishingAclG1Test|CompetitionAdminG3Test|CompetitionG3PermissionsTest"
# 14 passed / 453 assertions

cd D:/main && node --test tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs
# 29 passed
```

覆盖要点：
- G3-07：缺精度/缺字段拒绝；未登记策略拒绝；浮点/超精度/越界拒绝；
  0.1+0.2 = 0.30；缺赛/弃权；shared/strict/manual 并列语义。
- G3-08：草稿→排名→暂定→终审；录分发布分权；未排名拒绝；版本冲突；
  修订保留旧版本；公开只返已公布版本；通知去重。
- G3-09：队长/队员/路人主体；窗口过期；重复申诉；证据 MIME/扩展名；
  私有下载；受理→裁决；结果版本变化拒绝；撤回；时间线三项；通知一次。
- G3-10：终审后发奖；暂定成绩不能发奖；修订后旧奖项待复核、公开面清空；
  新奖项版本指向新成绩；撤销保留历史。
- G3-11：未授权不能审核/发布；非队长不能授权；标题改动使授权失效；
  撤回授权立即下架；公开字段最小化；技能提审走既有闸门；
  不新增商品/授权/设备记录。
- G3-12：整届链路 + 20 个操作审计动作可回查。

## 6 root 迁移与回滚

- 备份：`D:/main/backups/root-before-g3-07-20261020.sql`（1,674,468 bytes）。
- root 已执行 4 个 additive 迁移。
- 迁移后核对：
  - `events=11`、`event_registrations=1`（历史 legacy 未改写）
  - `competition_results=0`、`competition_appeals=0`、
    `competition_awards=0`、`competition_showcases=0`
  - `write_enabled=false`、`public_results_enabled=false`、
    `public_showcase_enabled=false`
- 回滚（仅结构；若已有真实赛务数据，先停写/导出，不得直接回滚）：

```bash
cd D:/main/my-bagisto-store
php artisan migrate:rollback --step=4
```

## 7 未做 / 风险

| 项 | 说明 |
|---|---|
| 首场赛规签署 | 仍未由活动产品/裁决/评分/安全负责人签署；不得写生产规则确认 |
| 真实运营数据 | root 无真实赛项、成绩、申诉、奖项、展示 |
| 并发压测 | 本批为服务层 + HTTP 功能测试；未做 MySQL 多连接/多进程锁等待 |
| 浏览器全链路 | **2026-10-20 补测**：客户侧作品提交/展示/成绩/申诉，以及 Admin 录分→提交复核→写名次→暂定发布→终审（12/12），均已由 [G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md) 覆盖；证据 [admin-g3-result.json](./admin-g3-result.json) |
| 小程序真机 | 作品/证据附件上传下载未在真机验证 |
| 技能提审正向链路 | 演练使用 mock；真实提审仍受协议/包/L0/L2 闸门约束 |
| 通知渠道 | 只写站内信 outbox；未接短信/邮件/微信，`sent` 不代表已送达 |
| CDN/对象存储 | 附件仍为私有本地盘；未验证撤稿后的缓存清理 |
| 多赛事并行 | 排名/奖项按赛项隔离，但未做跨赛项并发压测 |
| 生产开关 | 未打开；未部署 `schedule:work`/cron |

## 8 下一步建议

1. 业务签署首场赛规（含 `scoring.fields/scale/missing/forfeit/tie`）。
2. 签署后单赛事小范围开通：建系列→届次→赛项→确认规则→激活→开放报名 scope；
   先跑 `CompetitionFullEditionDrillG3Test` 同等流程，再对外。
3. 补 Q-02/Q-03 多连接并发与 worker 积压验证。
4. 补真实浏览器全链路（建队→报名→作品→赛程→成绩→申诉→奖项→展示）
   与小程序真机附件验证。**客户侧与 Admin 录分/发布已在 2026-10-20 补测（12/12）；真机附件待补。**
5. 任何一步发现方向偏差，停下来重新对齐，不在错误代码上继续修补。
