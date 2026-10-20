# G3-01 ~ G3-06 交付证据

日期：2026-10-19。分支：`codex/ecosystem-g2-free-registration`（延续 G2 工作树）。
范围：人工赛务基础（系列/届次/赛项、队伍、参赛快照、作品、赛程 CSV）。
**未包含** G3-07 ~ G3-12。

## 1 结论

- 代码、迁移、独立测试库验证、root additive 迁移均完成。
- 首场真实赛规未签署，因此没有创建真实赛项/队伍/赛程，也没有打开赛务写闸。
- `ecosystem_competition.write_enabled=false`、`public_schedule_enabled=false`；
  G1/G2 的公开/报名开关未被本批改动。
- root 数据未改写：活动/报名行数不变，`competition_*` 表为空。

## 2 规则冻结（G3-01）

- [G3-01-competition-rule-freeze.md](./G3-01-competition-rule-freeze.md)：责任矩阵、
  资格/评分/同分/申诉/安全必填项、签署栏。
- `CompetitionRuleProfile` 只校验不补默认值；缺键、类型错、占位符（TBD/待定）
  一律 `COMPETITION_RULES_UNCONFIRMED`。
- 本批 `scoring.policy` / `tie.policy` 只允许 `manual`；自动排名留给 G3-07。
- 当前状态：**模板/未签署**。没有把示例规则写入生产库。

## 3 数据库迁移

| 迁移 | 内容 |
|---|---|
| `2026_10_19_110001_create_competition_series_editions_tracks` | 系列/届次/赛项、规则 profile/指纹、scope 与规则版本外键 |
| `2026_10_19_110002_create_competition_teams` | 队伍、成员状态、一次性邀请码（sha256） |
| `2026_10_19_110003_create_competition_entries` | 参赛条目、成员/资格/机器人声明快照；`event_registrations.team_id` |
| `2026_10_19_110004_create_competition_submissions` | 作品主体/版本/私有附件 |
| `2026_10_19_110005_create_competition_stages_matches` | 阶段/场次、`unique(edition_id, match_key)` |

迁移均为 additive、幂等（表/列/索引/外键分别补齐），前置表缺失时显式 throw。
未修改任何已执行的 G0/G1/G2 原始迁移。

## 4 代码落点

**配置**

- `config/ecosystem_competition.php`：写闸、公开赛程闸、规则必填键、邀请/附件/导入参数。

**模型**

- `CompetitionSeries` / `CompetitionEdition` / `CompetitionTrack`
- `CompetitionTeam` / `CompetitionTeamMember` / `CompetitionTeamInvite`
- `CompetitionEntry`
- `CompetitionSubmission` / `CompetitionSubmissionVersion` / `CompetitionSubmissionAttachment`
- `CompetitionStage` / `CompetitionMatch`
- `EventRegistration` 新增 `team_id`、`IDENTITY_TEAM`。

**服务**

- `CompetitionRuleProfile`：规则完整性、规范化、指纹、写闸。
- `CompetitionSeriesService`：系列/届次/赛项、规则确认、scope 绑定、激活/关闭。
- `CompetitionTeamService`：建队、邀请码、接受/拒绝、退出、移交、一人多队。
- `CompetitionTeamRegistrationService`：队伍报名（event→scope→track→team→registration→attempt）。
- `CompetitionEntryService`：资格评估、快照冻结、审核/取消同步。
- `CompetitionSubmissionService`：草稿/提交/补正、截止锁后重检。
- `CompetitionAttachmentService`：私有附件校验/存储/授权读取。
- `CompetitionScheduleService`：CSV 预览/导入/冲突检测/发布/公开赛程。

**API / Admin / 前端**

- Shop API：`CompetitionPublicController`、`CompetitionTeamController`、
  `CompetitionSubmissionController`；`Routes/api.php` 新路由。
- Admin：`CompetitionAdminController`、两个 Blade 页面、`catalog-routes.php`、
  `config/{acl,menu,ecosystem_admin}.php`。
- 前端：`pages/ecosystem/competition-team.vue`、`competition-submission.vue`、
  活动详情赛项/赛程区块；`utils/api.js`、`utils/request.js`（multipart 上传）。

## 5 安全与一致性

- **锁顺序**：event → scope → track → team → registration → attempt/outbox。
  Admin 赛项写动作统一通过 `lockTrackAggregate()`，避免与报名事务形成反向锁环。
- **队伍归属**：报名 `subject_key=t:{team_id}`；只有 active 成员可读队伍私有信息；
  邀请中成员不算有效成员。
- **邀请码**：24 字节随机、库内只存 sha256、一次性、7 天过期；不按手机号搜索客户。
- **快照**：成员/资格/机器人声明在报名事务内冻结；冻结后成员/队长变更拒绝；
  后续改名不回溯覆盖快照。
- **作品**：提交后版本不可变；补正新建 version_no+1；截止时间在锁后重检。
- **附件**：独立私有盘；真实 MIME + 扩展名匹配；服务端生成路径；强制
  `application/octet-stream` + `attachment` + `no-store`；仅有效成员可下载。
- **赛程**：时间必须带时区偏移；entry 必须已确认且同赛项；同 entry/同场地重叠、
  文件内重复 match_key 均逐行定位；未发布不公开；已发布不可被导入覆盖。
- **错误码**：全部登记在 `ApiErrorCode`（`COMPETITION_*`），无裸字面量。
- **未写 outbox**：本批作品/赛程不发通知，避免未扩展的消费器把未知聚合标为失败；
  操作审计走 `OperationLogger::logOrFail`，与业务同事务。

## 6 测试证据（真实输出摘要）

命令：

```bash
DB_DATABASE=summon_mecha_step10_test php artisan test --filter="G3"
DB_DATABASE=summon_mecha_step10_test php artisan test packages/Webkul/Shop/tests/Feature/Ecosystem
DB_DATABASE=summon_mecha_step10_test php artisan test --filter="EcosystemPublishingAclG1Test|CompetitionAdminG3Test"
node --test tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs
```

结果：

| 套件 | 结果 |
|---|---|
| G3 专项（规则/系列/队伍/作品/赛程/API） | **34 passed / 170 assertions** |
| Shop Ecosystem 全量（G1+G2+G3 回归） | **453 passed / 3,227 assertions** |
| Admin ACL + G3 赛务 | **10 passed / 357 assertions** |
| 前端 Node | **29 passed** |
| `php -l` 改动 PHP | 全部通过 |
| `git diff --check` | 通过 |

覆盖要点：

- G3-01：缺键/占位符/非 manual 策略拒绝；指纹与键顺序无关；改库指纹不一致拒绝；
  写闸关闭稳定拒绝且不落库。
- G3-02：系列/届次/赛项服务创建；同活动两届拒绝；跨届 scope 拒绝；
  激活前置（规则/容量/提交截止）；普通活动仍 person、个人入口不能写 team scope。
- G3-03/04：邀请一次性、接受后才计入；非队长越权拒绝；一人多队默认拒绝；
  移交后权限变化；队伍报名归属与快照；人数/机器人声明不足拒绝；冻结后变更拒绝。
- G3-05：草稿→提交→补正；截止锁后重检；跨队/越权拒绝；URL 协议；
  附件私有存储、跨队下载拒绝、DTO 不含 storage_key；已提交版本禁止上传。
- G3-06：预览不写库；重试不重复且阶段版本不空转；预览/文件 hash 不一致拒绝；
  不存在/跨赛项/重复 match_key/时间冲突逐行定位且整批不导入；
  发布前公开为空、发布后只返回已发布；开关关闭 404；Admin 权限分离与写闸 403。

## 7 root 迁移与回滚

- 备份：`backups/root-before-g3-20261019-232107.sql`（1,650,618 bytes）。
- root `migrate --force` 执行 5 个 G3 迁移成功。
- 迁移后核对：`events=11`（8 条 Seeder + 3 条既有【测试】数据）、
  `event_registrations=1`、`competition_series=0`、`competition_tracks=0`；
  `registration_enabled=false`、`competition.write_enabled=false`、
  `public_schedule_enabled=false`。
- 回滚（仅结构，不丢新数据的前提是表中无 G3 数据）：

```bash
cd D:/main/my-bagisto-store
php artisan migrate:rollback --step=5
```

若已有真实赛务数据，**不得**直接回滚；先停写、导出/快照，按前向修复处理
（与 G2 的“有新报名后不丢库回退”一致）。

## 8 未做 / 风险

- **真实赛规未签署**：未创建真实赛项、未开放报名、未录入任何成绩。
- G3-07 计分策略、G3-08 成绩复核/发布、G3-09 申诉、G3-10 修订/奖项、
  G3-11 作品展示/提审、G3-12 整届演练不在本批。
- 未做 G3 的 MySQL 两连接/多进程并发压测（容量竞争、审核抢名额、报名与赛程导入并发）；
  现有为服务层 + HTTP 功能测试。上线前需按 Q-02/Q-03 补真实锁等待证据。
- 未做真实浏览器全链路（建队→报名→审核→作品→赛程→公开）截图；
  Admin 页面仅通过 HTTP 200/权限测试，未做视觉与窄屏回归。
- 小程序真机附件选择/下载、Safari/微信内置浏览器下载未验证。
- 前端机器人声明表单目前固定 `model` / `asset_no` 两个字段；若首场规则使用其它
  字段名，需要按 `rules_profile.eligibility.robot_declaration_fields` 扩展 UI
  （后端已按规则校验任意字段名）。
- 作品附件未接 CDN/对象存储；未验证撤稿/隔离后的缓存清理（本批无公开作品）。
- 队伍重报会以新 attempt 重新冻结 entry 快照；旧快照只保留在操作审计中，
  尚未做 G3-10 的“历史快照版本表”。
- 赛项规则变更会清除确认并递增 scope form_version；已有队伍快照继续引用旧指纹，
  但本批没有“规则变更影响通知/重确认”流程（G3-10/G4 处理）。
- Admin 赛项/规则页面未做 CSV 模板下载；模板列在配置与计划文档中。
