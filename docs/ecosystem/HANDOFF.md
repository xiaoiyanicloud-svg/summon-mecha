# 生态赛事与资讯 · 接手须知（HANDOFF）

> **任何 AI / 新会话先读这一页。** 它只回答三件事：现在到哪了、下一步做什么、什么不能碰。
> 实现细节看各批 `*-evidence.md`，本页不重复，也不替代证据。

| 项 | 内容 |
|---|---|
| 最后更新 | 2026-10-20（G3 Admin 录分/发布浏览器演练收尾后） |
| 分支 | `codex/ecosystem-g2-free-registration` |
| 范围 | G0（停写去伪）→ G1（可信发布）→ G2（免费活动报名）→ G3（人工赛务）→ G4（分发与运营）→ Q-01~Q-05（横向质量）→ **浏览器 e2e + Admin 赛务演练收尾** |
| 结论 | 代码、独立测试库验证、root additive 迁移完成；**G4 新开关全部关闭**；Q、e2e 与 Admin 演练证据已落地；无真实运营数据 |
| Git | G2~Q 与收尾改动均已提交（`b36798c`、`a09d8b6`、`3759291`）；分支**未配置 upstream，未 push** |

## 1. 一句话状态

G0/G1/G2/G3 与 **G4-01 ~ G4-09** 的代码、独立测试库验证、root additive 迁移均已完成。
**Q-01 ~ Q-05 横向质量包**已完成：契约门禁、真实并发、真实 worker、四角色浏览器演练、
数据 preflight 与迁移/生命周期演练均可复跑，证据见
[Q-01-05-quality-evidence.md](./Q-01-05-quality-evidence.md)。

**G4 最重要的边界**：搜索、收藏、关联、SSR、sitemap、指标、运营台全部默认关闭；
没有打开任何新的生产读/写入口。root 三张新表为空，旧活动/报名未改写。

**Q-03 带来的产品修正**：outbox 可重试失败耗尽后写 `failed`（运营可重排），
不可重试事实仍写 `dead`（终态，绝不复活）。此前 worker 从不写 `failed`，
G4-07 的“通知失败重排”因此永远为空；修正后语义与运营台一致。

**G3 的边界仍有效**：首场赛规未签署，不得写生产 `rules_confirmed_at`、不得创建真实赛项。

**2026-10-20 浏览器 e2e 收尾**：G2 T31 切号 / T47 跨设备、G3 客户侧全链路
（作品提交→展示授权→公开成绩→申诉）、G4 搜索/收藏/关联/SSR/sitemap/撤稿/停读/375px，
已在真实 Chrome + 独立测试库跑通 **39/39**，并修复 H5 收藏 `key` 类型缺陷
（修复前后端 422，收藏按钮实际不可用）。证据见
[G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md)。

**2026-10-20 Admin 赛务收尾**：G3 Admin 录分/发布真实浏览器演练 **12/12** 通过
（录分员保存草稿→提交复核→写名次；发布员暂定发布→终审，两个后台账号与独立
Chrome profile）。证据见 [admin-g3-result.json](./admin-g3-result.json) 与
[G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md) §2。

## 2. 工作区状态（交接前必看）

- **G2 + G3 + G4 + Q 改动已 commit**（2026-10-20）：
  - `b36798c` 代码 / 测试 / QA 脚本；
  - `a09d8b6` 文档 / 证据 / OpenAPI / 结果 JSON；
  - `3759291` 收尾：G3 Admin 录分/发布演练、G2/G3/G4 e2e、门禁与 CI workflow、文档更新。
  分支 `codex/ecosystem-g2-free-registration` **未配置 upstream，也未 push**；
  其他机器要看到，需要先 push 或手工带走这三个提交。
- **本次收尾改动已提交**（`3759291`），包含 `qa/ecosystem/e2e/**`、
  `qa/ecosystem/admin-g3/**`、`tools/ecosystem-gate.sh`、
  `.github/workflows/ecosystem-gate.yml`、`utils/api.js`、`q04/driver.mjs`、
  `G3-G4-browser-e2e-evidence.md`、`g3g4-e2e-result.json`、`admin-g3-result.json`
  及本次更新的 HANDOFF/Roadmap/Backlog/G4-evidence。
- 截图目录按 Git 红线**故意不提交**：`docs/ecosystem/e2e-screenshots/`、
  `docs/ecosystem/admin-g3-screenshots/`、`docs/ecosystem/q04-screenshots/`
  与 `docs/ecosystem/screenshots/e2e-*.png`（均可用脚本复现）。
- 仍留在工作区、按 Git 红线**故意不提交**的：`.claude/*.log`、`.claude/tasks/`、
  `docs/ecosystem/screenshots/`、`docs/ecosystem/q04-screenshots/`（截图可用脚本复现）。
- G4 新增/更新文档：
  - `G4-implementation-plan.md`（范围、数据模型、接口、风险、验收映射）
  - `G4-runbook.md`（开关矩阵、分阶段上线、停写/回退、值守）
  - `G4-evidence.md`（交付、测试、root 迁移、基准、未做项）
  - `G4-08-drill.md`（T27/T28/T70/T71/T73/T76/T78/T79/T80 演练对照）
  - `g4-benchmark-result.json`（独立测试库基准输出）
- Q-01 ~ Q-05 新增：
  - 产品代码：`config/ecosystem_contract.php`、`ContractCoverageChecker`、
    `ecosystem:contract-check`、`ecosystem:data-preflight`、
    `OutboxLeaseService` 的 failed/dead 语义修正。
  - QA 工具：`my-bagisto-store/qa/ecosystem/**`（专用库硬校验 + 演练脚本）。
  - 文档/证据：`Q-01-05-quality-evidence.md`、`openapi.json`、
    `q02-result.json`、`q03-result.json`、`q04-result.json`、`q05-result.json`、
    `q04-screenshots/`。
- G3 证据/计划仍在：`G3-01-06-*`、`G3-07-12-*`、`G3-12-drill.md`、`G3-01-competition-rule-freeze.md`。
- **Git 红线**：`CLAUDE.md` 已写明 `my-bagisto-store2 备份/` 会让 git 认为 2739 个
  已跟踪文件被删除。**禁止 `git add -A`**；只按路径 `git add`。
- 不要把 `.claude/backup/`、本机截图、测试库 dump 提交进仓库。

## 3. 文档阅读顺序

1. [Execution-Roadmap.md](./Execution-Roadmap.md) —— 批次边界与健康门槛（含 G4 / Q / 收尾进度）
2. [Q-01-05-quality-evidence.md](./Q-01-05-quality-evidence.md) —— 五个横向质量包的实测结果与未做项
3. [G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md) —— G2 T31/T47、G3 客户侧全链路、G4 浏览器 e2e 与收藏缺陷
4. [G4-runbook.md](./G4-runbook.md) —— 上线顺序、开关、停写/回退、值守
5. [G4-implementation-plan.md](./G4-implementation-plan.md) —— 数据模型、筛选/游标、SSR、指标口径
6. [G4-evidence.md](./G4-evidence.md) —— 实测结果与未做项
7. [HANDOFF.md](./HANDOFF.md) —— 本页
8. [G3-12-drill.md](./G3-12-drill.md)、[G3-07-12-evidence.md](./G3-07-12-evidence.md)
9. [G2-implementation-plan.md](./G2-implementation-plan.md)
10. [ADR-0001-trusted-publishing.md](./ADR-0001-trusted-publishing.md) —— 仍为 **Proposed**

## 4. 当前开关与 root 事实

```text
# G1/G2/G3（沿用）
ECOSYSTEM_EVENT_REGISTRATION_ENABLED=false
ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED=false
ECOSYSTEM_REGISTRATION_ENABLED=false
ECOSYSTEM_REGISTRATION_LIFECYCLE_ENABLED=false
ECOSYSTEM_CONTENT_PUBLISH_ENABLED=false
ECOSYSTEM_COMPETITION_WRITE_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED=false
ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED=false

# G4（本批新增，全部 false）
ECOSYSTEM_SEARCH_ENABLED=false
ECOSYSTEM_FAVORITES_ENABLED=false
ECOSYSTEM_RELATIONS_ENABLED=false
ECOSYSTEM_SSR_ENABLED=false
ECOSYSTEM_SITEMAP_ENABLED=false
ECOSYSTEM_METRICS_ENABLED=false
ECOSYSTEM_CONTENT_VIEWS_ENABLED=false
ECOSYSTEM_OPS_WORKBENCH_ENABLED=false
```

- root：G1/G2/G3 迁移均已执行；G4 新增 3 个迁移（favorites / relations / view daily）。
- root 数据：`events=11`（8 条 Seeder + 3 条既有【测试】数据）、
  `event_registrations=1`（legacy）、G4 三张新表均为 0。
- 备份：`backups/root-before-g4-20261020.sql`（1,696,955 bytes）。
- 生产部署侧**未启动** `schedule:work` / cron；G4 没有新增队列消费器。

## 5. 已完成 / 未完成

### G4-01 ~ G4-09（前批）

| 项 | 结论 |
|---|---|
| G4-01 搜索/筛选/分页 | 完成；LIKE 转义、8 种报名状态、游标 + filters hash、page 上限 |
| G4-02 收藏 | 完成；唯一键幂等、跨账号隔离、撤稿隐藏、上限 500、私有 no-store |
| G4-03 关联 | 完成；失效目标整项省略、Admin 维护、公开 DTO 最小化 |
| G4-04 SSR/sitemap | 完成；canonical/OG/JSON-LD、410 中性页、草稿/撤稿排除、kill switch 503 |
| G4-05 H5 SEO | 完成；纯函数 + Node 测试；canonical 指向 SSR，分享不含私有状态 |
| G4-06 指标 | 完成；口径与事实同页；无源返回 unavailable；访问只存日聚合 |
| G4-07 运营台 | 完成；待审/截止/通知失败/来源复核/撤稿；PII 遮罩与活动范围 |
| G4-08 演练 | 完成；撤稿/取消/停读同步降级；独立测试库基准 |
| G4-09 上线 | 完成；开关矩阵、状态命令、runbook、root 备份与 additive 迁移 |

### Q-01 ~ Q-05（横向质量）

| 项 | 结论 |
|---|---|
| Q-01 契约门禁 | 完成；113 条后台路由 + 45 条公开 API + 85 错误码 + 11 状态快照 + OpenAPI 双向核对；抓到并修复 `COMPETITION_TRACK_NOT_FOUND` 未定义常量 |
| Q-02 并发/隔离 | 完成；专用库硬校验、容量 1 两进程竞争、真实行锁等待、RR 快照 vs 当前读 |
| Q-03 worker/补偿 | 完成；afterCommit/回滚、真实 worker、重复投递、崩溃租约、25 条积压、failed/dead 补偿语义修正 |
| Q-04 浏览器演练 | 完成；客户/队长/裁判/超管四角色，375/390px，键盘 Tab，错误分支，403 无 PII 泄漏，9 张截图 |
| Q-05 数据/迁移 | 完成；只读 preflight、计数对账 dry-run/apply、留存 dry-run、expand/backfill/switch/recovery 演练 |

### 浏览器 e2e 收尾（2026-10-20）

| 项 | 结论 |
|---|---|
| G2 T31/T47 | 完成；切号后私有报名不残留；第二设备同账号看到同一报名 |
| G3 客户侧全链路 | 完成；作品草稿→提交→展示授权→公开成绩→申诉，真实页面通过 |
| G4 搜索/关联 | 完成；关键词筛选；五类关联；撤稿目标整项省略 |
| G4 收藏 | 完成；点击/刷新/我的收藏/资讯 tab/跨账号/撤稿 stale；修复 `key` 类型缺陷 |
| G4 SSR/sitemap | 完成；canonical/OG/JSON-LD、收录/排除、撤稿 410、停读 503 |
| 布局 | 完成；375px 详情/成绩页无横向溢出 |
| G3 Admin 录分/发布 | 完成；录分草稿→提交复核→写名次；暂定发布→终审；12/12，两个后台账号分权 |
| 本地门禁 | `tools/ecosystem-gate.sh`；契约 + preflight + 后端/Admin + Node；`--with-admin-browser` 加 Admin 演练，`--with-browser` 再加 e2e |

### G1/G2/G3（前序批次）

**已完成（本机可复现）**：G1 可信发布、公开 API/DTO、媒体/富文本、worker、H5 E2E；
G2 报名主体；G3 人工赛务全链路。

### 未完成

| 项 | 原因 | 谁来做 |
|---|---|---|
| 首场赛规签署 | 需要业务/裁决/评分/安全负责人 | 业务签署后 |
| G4 生产并发压测 | 只有单进程基准，不是并发报告 | QA/运维按 Q-02/Q-03 |
| 搜索引擎提交/站长平台 | sitemap 默认关闭 | 运营 |
| CDN/反代缓存与撤稿 purge | 本批不启用页面缓存 | 运维 |
| 小程序真机分享/canonical | H5 DOM 写入只在 H5 条件编译 | 真机验证 |
| 收藏/关联/访问统计生产数据 | 新表为空、开关关闭 | 运营 |
| 通知渠道 | 只有站内信 outbox | 部署侧 |
| G3 并发/真机附件 | 客户侧全链路与 Admin 录分/发布 UI 已完成真实浏览器演练；MySQL 多连接并发、真机附件上传/下载未验证 | QA/运维 |
| CI 流水线 | 已提供 `.github/workflows/ecosystem-gate.yml`（契约/preflight/PHPUnit/Node），**尚未在 GitHub 运行**；浏览器演练仍靠本地门禁 | 运维（首次 push 后） |
| 生产密钥托管与旧 key 恢复 | 本机轮换已通过 | 部署侧 |
| 生产 `schedule:work`/cron | 未部署 | 运维 |

## 6. 怎么验证（不要凭空相信文档）

```bash
cd D:/main/my-bagisto-store
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test packages/Webkul/Shop/tests/Feature/Ecosystem
# 522 passed / 3,703 assertions

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test \
  packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
  packages/Webkul/Admin/tests/Feature/Catalog/G4MetricsOpsAdminTest.php
# 13 passed / 495 assertions

DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan ecosystem:g4-status
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan ecosystem:g4-benchmark --events=2000 --queries=20
```

```bash
cd D:/main && node --test tools/tests/ecosystem-g4.test.mjs tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs
# 37 passed
```

Q-01 ~ Q-05：

```bash
cd D:/main/my-bagisto-store

# Q-01 契约门禁（不需要数据库）
php artisan ecosystem:contract-check

# Q-02 / Q-03 / Q-05（必须独立测试库）
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q02/run.php --json=D:/main/docs/ecosystem/q02-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q03/run.php --json=D:/main/docs/ecosystem/q03-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q05/run.php --json=D:/main/docs/ecosystem/q05-result.json

# Q-04 浏览器（先导出 H5）
D:/HBuilderX/cli.exe publish web --project D:/main
node qa/ecosystem/q04/run.mjs --json=D:/main/docs/ecosystem/q04-result.json

# G2/G3/G4 浏览器 e2e（同样先导出 H5）
node qa/ecosystem/e2e/run.mjs --json=D:/main/docs/ecosystem/g3g4-e2e-result.json

# G3 Admin 录分/发布演练（只需 Chrome，不需要 H5）
node qa/ecosystem/admin-g3/run.mjs --json=D:/main/docs/ecosystem/admin-g3-result.json
```

一键门禁（仓库根目录）：

```bash
cd D:/main
bash tools/ecosystem-gate.sh                    # 契约 + preflight + 后端/Admin + Node
bash tools/ecosystem-gate.sh --with-admin-browser  # 另加 G3 Admin 录分/发布演练
bash tools/ecosystem-gate.sh --with-browser     # 另加 Admin 演练 + e2e（e2e 需已导出 H5）
```

Q-01 / Q-05 的 PHPUnit 专项：

```bash
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php artisan test \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemContractGateQ1Test.php \
  packages/Webkul/Shop/tests/Feature/Ecosystem/EcosystemDataPreflightQ5Test.php
```

2026-10-20 回归结果：Ecosystem 全量 **522 passed / 3,703 assertions**；
Admin ACL/G2/G3/G4 专项（5 个文件）**22 passed / 547 assertions**；前端 Node **37 passed**；
G2/G3/G4 浏览器 e2e **39/39 通过**（含 1 项 observed）；G3 Admin 录分/发布演练
**12/12 通过**；清理后测试库 0 残留。
⚠ 两套 PHPUnit 不要并发跑同一测试库。

**测试纪律**：必须指向独立库 `summon_mecha_step10_test`（`APP_ENV=testing`）；
**禁止**对 root 执行 `migrate:fresh` / `truncate` / `seed`。

## 7. 下一步建议（按优先级）

1. **提交收尾改动**：已完成（`3759291`，含 e2e、Admin 演练、门禁与 CI workflow）。
   分支仍未 push；需要其他机器可见时先 push，再按 GitHub Actions 结果确认 CI。
   后续提交仍按路径 `git add`（禁止 `git add -A`），先处理
   `my-bagisto-store2 备份/` 的 git 红线；截图目录按既有约定不入库。
2. 提交前跑门禁：`bash tools/ecosystem-gate.sh`（契约 + preflight + 后端/Admin + Node），
   需要浏览器证据时加 `--with-admin-browser` 或 `--with-browser`；任何一项非零都先修。
3. 按 [G4-runbook.md](./G4-runbook.md) 阶段 0 → 3 逐项打开：先 Admin 指标/运营台，
   再搜索/关联，再 SSR/sitemap，最后收藏。
4. 打开 SSR 前，确保 `ECOSYSTEM_PUBLIC_SITE_URL` 与前端 `SSR_BASE_URL` 完全一致。
5. 打开任何公开入口前，先跑一次撤稿演练：API 410、SSR 410、sitemap 排除、收藏隐藏、关联省略。
6. 生产并发、浏览器、真机与搜索引擎收录按 evidence §7 补证；不要因为页面完整就宣称完成。
   Q-02/Q-03/Q-04 的本机结果不能替代生产规模复跑。
7. 任何一步发现方向偏差，**停下来重新对齐**，不要在错误代码上继续修补。
