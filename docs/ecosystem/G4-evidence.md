# G4 分发与运营 · 交付证据

日期：2026-10-20。范围：G4-01 ~ G4-09。
配套：[G4-implementation-plan.md](./G4-implementation-plan.md) ·
[G4-runbook.md](./G4-runbook.md) · [HANDOFF.md](./HANDOFF.md)。

> 本文件只记录**实际执行过**的验证与未做项。未做不标 ✅。

## 1. 交付清单

| 包 | 交付 | 关键文件 |
|---|---|---|
| G4-01 | 关键词搜索、城市/日期/类型/报名状态筛选、LIKE 转义、游标分页、筛选回放 | `PublicEventQuery.php`、`PublicContentQuery.php`、`PublicListCursor.php`、`EventController.php`、`ContentController.php`、`events.vue`、`content.vue` |
| G4-02 | 收藏表/服务/API/我的收藏/详情收藏态/跨账号隔离/撤稿隐藏 | `2026_10_20_000001_*`、`EcosystemFavorite.php`、`FavoriteService.php`、`FavoriteController.php`、`my-favorites.vue` |
| G4-03 | 关联表/解析降级/公开 DTO/Admin 维护 | `2026_10_20_000002_*`、`EcosystemRelation.php`、`RelationService.php`、`RelationAdminController.php`、`relations.blade.php` |
| G4-04 | Laravel SSR、canonical/OG/JSON-LD、sitemap、草稿预览 noindex | `EcosystemSsrController.php`、`SitemapController.php`、`Resources/views/ecosystem/*`、`store-front-routes.php` |
| G4-05 | H5 SEO 元数据、canonical 与 SSR 一致、分享不携带私有状态 | `utils/ecosystem-seo.js`、`event-detail.vue`、`content-detail.vue`、`utils/config.js` |
| G4-06 | 指标口径与事实、访问日聚合、Admin 指标页 | `2026_10_20_000003_*`、`ContentViewRecorder.php`、`MetricsService.php`、`EcosystemMetricsAdminController.php` |
| G4-07 | 运营工作台（待审/报名/截止/通知/来源/撤稿）、失败通知重排 | `OpsService.php`、`EcosystemOpsAdminController.php`、`ecosystem-ops/index.blade.php` |
| G4-08 | 撤稿/取消/停读演练、独立测试库基准 | `G4DispositionDrillTest.php`、`EcosystemG4Benchmark.php`、`G4-08-drill.md` |
| G4-09 | 开关登记、状态命令、上线/回退/值守手册 | `config/ecosystem_g4.php`、`EcosystemG4Status.php`、`G4-runbook.md`、`.env.example` |

## 2. 数据库变更（additive）

| 迁移 | 表 | 说明 |
|---|---|---|
| `2026_10_20_000001_create_ecosystem_favorites_table` | `ecosystem_favorites` | 用户收藏；唯一键 `(customer_id,target_type,target_key)`；FK customers 级联 |
| `2026_10_20_000002_create_ecosystem_relations_table` | `ecosystem_relations` | 多态关联；唯一键 `(source_type,source_key,target_type,target_key)` |
| `2026_10_20_000003_create_ecosystem_content_view_daily_table` | `ecosystem_content_view_daily` | 日聚合访问；唯一键 `(view_date,target_type,target_key)` |

无删列、无改旧列、无数据回填、无 `migrate:fresh`。

## 3. 测试与实测结果

环境：`APP_ENV=testing`，独立库 `summon_mecha_step10_test`。

```bash
cd D:/main/my-bagisto-store
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test packages/Webkul/Shop/tests/Feature/Ecosystem
# 511 passed / 3,679 assertions（含 G4-01/02/03/04/06/08 新增用例）

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/G4MetricsOpsAdminTest.php
# 13 passed / 495 assertions（逐路由 ACL + 指标/运营台权限与遮罩）

cd D:/main
node --test tools/tests/ecosystem-g4.test.mjs \
  tools/tests/ecosystem-public.test.mjs \
  tools/tests/ecosystem-events.test.mjs
# 37 passed
```

G4 专项文件与断言：

```text
G4 Shop 五个专项文件合计：34 passed / 238 assertions
Admin G4 专项（G4MetricsOpsAdminTest）：6 passed / 57 assertions
```

| 文件 | 覆盖 | 结果 |
|---|---|---|
| `G4SearchPaginationTest.php` | LIKE 转义、搜索开关、游标跨页、filters hash、报名状态 8 态、page 上限 | 9 passed / 72 assertions |
| `G4FavoritesRelationsTest.php` | 登录/开关、幂等、跨账号、撤稿隐藏、上限、关联降级 | 9 passed / 76 assertions |
| `G4SsrSitemapTest.php` | SSR 开关/域开关、canonical/OG/JSON-LD、410、sitemap 排除、kill switch | 9 passed / 45 assertions |
| `G4MetricsTest.php` | 访问去重、关闭时不写库、报名/取消/重报口径 | 4 passed / 19 assertions |
| `G4DispositionDrillTest.php` | 撤稿同步降级、取消后报名筛选、kill switch 全覆盖 | 3 passed / 26 assertions |
| `G4MetricsOpsAdminTest.php` | 指标/运营台权限、PII 遮罩、人员范围、通知重排 | 6 passed / 57 assertions |

前端 Node：

| 文件 | 覆盖 |
|---|---|
| `ecosystem-g4.test.mjs` | SEO canonical/OG/JSON-LD、noindex、DOM 写入与 `</script>` 转义、游标分页解析、favorited/related 白名单 |

## 4. root 迁移与数据

```text
备份：backups/root-before-g4-20261020.sql（1,696,955 bytes）
迁移：2026_10_20_000001 ~ 000003  DONE
迁移后：events=11 / event_registrations=1 / favorites=0 / relations=0 / view_daily=0
```

- 旧活动与报名未改写；
- 三张新表为空；
- G4 开关全部 `false`（`ecosystem:g4-status` 实测）；
- 未启动新的 worker/定时任务；G4 没有新增队列消费器。

### 4.1 恢复 + 前向迁移演练

```text
1. 从 root-before-g4-20261020.sql 恢复到独立库 summon_mecha_g4_restore_drill
   -> tables=223 / events=11 / event_registrations=1
2. 在演练库执行 php artisan migrate --force（仅 G4 三个迁移）
   -> tables=226 / events=11 / event_registrations=1
   -> favorites=0 / relations=0 / view_daily=0
3. 核验后删除演练库
```

结论：备份可恢复、旧数据不丢、G4 迁移可在恢复库前向执行。
**未演练**：生产规模数据量、恢复耗时与回滚窗口；上线前需在预发环境按实际数据量重做。

## 5. 性能基准（独立测试库，非生产压测）

命令：

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing \
  php artisan ecosystem:g4-benchmark --events=2000 --queries=20
```

结果文件：[g4-benchmark-result.json](./g4-benchmark-result.json)。2,000 条已发布活动、20 次采样：

| 场景 | p50 | p95 | max |
|---|---:|---:|---:|
| 首页列表（page 模式） | 22.30 ms | 29.41 ms | 33.18 ms |
| 游标首页 | 16.02 ms | 19.08 ms | 22.34 ms |
| 关键词 LIKE（全命中） | 31.09 ms | 39.91 ms | 45.62 ms |
| 报名状态 open | 7.43 ms | 8.52 ms | 8.56 ms |
| sitemap 5,000 上限（实际 2,000） | 151.50 ms | 199.86 ms | 259.22 ms |
| 游标连续 10 页 | 160.48 ms | 173.36 ms | 181.03 ms |

> 结果解读边界：这是**单进程 CLI + 真实 MySQL** 的查询耗时，不是并发压测。
> 不能据此宣称“支撑 N 并发”；生产压测仍需按 Q-02/Q-03 在约定环境执行。
> sitemap 是低频入口，但仍建议生产上按 `max_urls` 分片或加缓存后再开放收录。

## 6. 安全与隐私核验

- 收藏列表 `Cache-Control: private, no-store`；不进入 SSR/OG/sitemap。
- 收藏目标不可见统一 404，文案不区分草稿/撤稿/不存在。
- 收藏列表只返回仍公开目标；失效目标只计 `stale_count`。
- 关联目标逐项复核；草稿/撤稿/下架/无已发布技能版本整项省略。
- SSR 410 页面不输出旧标题/摘要/来源/图片；sitemap 不含草稿/撤稿。
- 指标访问表只有 7 列（id/日期/类型/对象/次数/时间），不存 IP/UA/用户 ID。
- 运营台报名行受活动人员范围约束；无 `pii` 能力只显示 `张*` / `138****8000`。
- 新增 Admin 路由全部登记 `config/ecosystem_admin.php` 与 `config/acl.php`；
  ACL 全路由测试通过。

## 7. 未完成 / 已知边界

| 项 | 状态 | 谁来做 |
|---|---|---|
| 生产并发压测 | **未做**；只有单进程基准 | QA/运维按 Q-02/Q-03 |
| 真实浏览器 H5/SSR 端到端 | **未做**；有 HTTP/HTML 断言与 Node 测试 | QA |
| CDN/反代缓存与撤稿 purge | **未做**；本批明确不启用页面缓存 | 运维/CDN |
| 搜索引擎收录/站长平台 | **未做**；sitemap 默认关闭 | 运营 |
| 小程序真机分享/canonical | **未做**；H5 DOM 写入只在 H5 条件编译 | 真机验证 |
| 收藏/关联的运营数据 | 无；root 新表为空 | 运营 |
| 访问统计生产验证 | 未开 `CONTENT_VIEWS_ENABLED` | 运营/运维 |
| 短信/邮件/微信通知 | 未接；只有站内信 outbox | 部署侧 |
| 已发布内容撤稿后的 CDN 旧页 | 未验证（无 CDN） | 运维 |

## 8. 回滚

- 开关回退：按 `G4-runbook.md` §4 关闭对应能力；无需回滚数据。
- 代码回退：回退本批提交；**不要**回滚三张新表迁移（会丢用户收藏/关联/访问事实）。
- 整库恢复：只有在确认没有新收藏/关联后才可用 `root-before-g4-20261020.sql`；
  否则先导出三张新表并在恢复后回灌。
- 紧急停读：`ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED=true`，API/SSR/sitemap 统一 503。
