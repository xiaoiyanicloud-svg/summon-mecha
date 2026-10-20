# 生态赛事与资讯 · 执行路线图

> 本文只回答两件事:**先做什么、做到什么算健康**。详细工作包见
> [Ecosystem-Engineering-Backlog.md](./Ecosystem-Engineering-Backlog.md),
> 现状事实见 [Ecosystem-Backend-Audit.md](./Ecosystem-Backend-Audit.md) /
> [Ecosystem-Frontend-Audit.md](./Ecosystem-Frontend-Audit.md)。
>
> 原则:先停写、再去伪、最后才开放。任何批次都不以「页面看起来完整」作为完成标准。

## 当前口径（2026-10-19）

- **数据口径（重要）**:root 库 `events` 的 8 条记录与
  `packages/Webkul/Shop/src/Database/Seeders/EventSeeder.php` 的演示样例逐字段一致,
  `event_registrations` 里仅有 1 条 RegTest 测试报名。**这些都不是运营真实数据**,
  不得作为 H5/G1 验收依据,也不得据此宣称「赛事已上线」。EventSeeder 已加
  `local`/`testing` 环境守卫,其它环境直接返回、不插入数据。
- 赛事公开读取由 `ecosystem.events.public_enabled` 控制,**G1 可信发布完成前保持
  `false`**:`GET /api/events` 返回 HTTP 200 + `{"data":[]}`,
  `GET /api/events/{id}` 返回 HTTP 404 `EVENT_NOT_FOUND`(消息「赛事尚未公开发布或不存在」),
  两者都不查库。**只有 G1 可信发布(来源/授权/审批/撤回验收)完成后才允许打开。**
- 站内报名写入口**默认关闭**:`ecosystem.events.registration_enabled` 与
  `ecosystem.events.registration_write_verified` 双重闸,任一为 `false` 即 403
  `EVENT_REGISTRATION_DISABLED`。停写检查早于任何赛事查询与写库。
- 赛事公开合同只下发 `can_register` / `registration_reason`,不再返回
  无真实数据源的 `hot` / `teams` / `fav`;前端不保留样例兜底。
- 动态资讯发布域尚未接入,前端只展示诚实空态,不保留硬编码资讯与假交互。
- 当前唯一可运行的交付通道仍是「下载 + 人工安装」,与本路线图无关,不要混写。

## 批次 A（本次,已完成范围）

**目标:关掉旧写入口,清掉正式路径上的样例与假成功。**

| 项 | 范围 | 健康门槛 |
|---|---|---|
| G0-06 | 旧站内报名默认停写;双重闸;报名只允许 `status=registering` | 关闭时任意 ID(含不存在 ID)稳定 403 + 固定错误码;**检查早于查赛事/写库**;单闸打开仍 403;非 `registering` 稳定 422;不伪造同意 |
| G0-02 | EcoCenter / Dongtai 去样例、去假计数、去假成功;接入 StateView | 空/错/格式异常三态分离;无样例 ID 可报名;详情按 ID 重新取;不破坏商品/技能 tab |

**本批明确不做**:events 表结构变更、G1 内容域、G2 身份/名额/并发/审计、G3 赛务、收藏/分享/搜索/SSR。

补充:G0 只负责**关闭**旧链路,不打开任何公开读取。`ecosystem.events.public_enabled`
的打开动作属于 G1,必须在可信发布链路验收(而非"页面能显示")完成后单独执行。

## 批次 B（G0 收口，已完成 2026-10-19）

**状态：已完成并提交。**

- 提交：`8b236bc`（Admin ACL + PII/导出）、`a22fc0a`（编辑回填 + 校验）。
- **G0-03**：赛事 7 个 Admin 动作逐动作授权；custom 角色直接 URL/Ajax 被拒；
  非法 ID 404；有报名的赛事禁止硬删除（422，保留赛事与报名）。
- **G0-04**：报名 PII 只对 `permission_type=all` 超管显示明文；custom 角色
  name/phone 输出遮罩、列不可搜索/排序；两个赛事 DataGrid 的
  `export`/`format` 参数一律 403，不生成导出文件。
- **G0-05**：create/edit 共用表单；全字段 `old()` 回填；非空列应用层默认值；
  只改一个字段不清空其它字段；name/bg_color/status/hot/teams/sort 边界校验。
- **G0-07 回归证据（本机独立测试库 `summon_mecha_step10_test`）**：
  - Admin ACL：7 passed / 72 assertions
  - Admin PII：5 passed / 120 assertions
  - Admin 表单：11 passed / 137 assertions
  - Shop 停写/公开闸：7 passed / 45 assertions
  - 合计：**30 passed / 374 assertions**
  - 前端 Node：**17 passed**
  - `git diff --check`：通过

**仍未完成**：Admin 浏览器截图/端到端演练；全量 Admin 套件；G1 归档/停用能力；
状态转移合法性；真实运营数据。root 库 8 条 Seeder 样例和 1 条 RegTest 报名仍保留，
公开读取保持关闭。

## 批次 C（G1 只读可信目录 → G2 报名写能力）

**目标:先提供可核验的只读公开域,验收通过后才逐活动开写。**

1. **G1-01 至 G1-11**:业务 ADR、events/revision、来源与授权证据、独立内容域、
   媒体与富文本安全、提审/发布、outbox 公共读取、公开 Resource 与独立详情。
2. **G2-01 至 G2-15**:身份/attempt/幂等/名额/审核/取消/凭证/通知/导出/运营演练。

**G1 健康门槛**
- 公开只读版本;草稿 ID 不可枚举;不返回联系人、内部备注、附件存储 key。
- 未审不能发布;改正文/来源/附件不继承审批;两人编辑不静默覆盖。
- 撒稿/更正后旧 ID、旧链接、旧元数据不复活。
- `ECOSYSTEM_CONTENT_PUBLIC_ENABLED` 仅在真实发布 worker 与恢复验收通过后打开;
  未打开时前端不得把 CMS 静态页或样例当资讯。

**G2 健康门槛**
- 报名写库必须同时满足 `registration_enabled=true` 且
  `registration_write_verified=true`;按活动逐项启用,不得一次全开。
- 幂等键、载荷指纹、锁后名额复检、状态/名额/审计/outbox 同事务;失败回滚不占名额。
- 已满、超时、旧规则、旧 revision 一律稳定 4xx/429;不把待审伪装成已确认。
- 未接短信/付费渠道时,界面不得显示其成功。
- PII 加密/遮罩/审计/留存策略批准前,不得自动清理历史数据。

## G3 / G4 健康门槛（方向锚,不是当前排期）

**G3 赛务**
- 规则、资格、同分、申诉、安全职责有负责人与可引用版本,不能靠代码补空白政策。
- 录分与发布分权;期望版本冲突可见;终审后普通编辑不能覆盖。
- 旧奖项/旧成绩/旧裁决不被新版本冒充;获奖不自动上架、不发 license、不下发真机。

**G4 分发与运营**
- 搜索/筛选/分页可回放,无跨页重漏;LIKE 转义;不强行引入 ES。
- 收藏用户/目标唯一、跨设备持久、撤回目标不泄露旧信息。
- SSR/canonical/OG/sitemap 与 uni-app 一致;草稿 noindex;撤稿无旧元数据泄漏。
- 指标口径排除取消/重报重复,无数据源不造指标;性能/权限/文件/队列/备份恢复有真实报告。
- 分阶段开关、停写/回退、文档与值守交接完成;有新报名后不得丢库回退。

## 通用验收纪律

- 后端测试必须指向独立库 `summon_mecha_step10_test`(`APP_ENV=testing`);
  禁止对 `root` 主库执行 `migrate:fresh` / `truncate` / `seed`。
- 用例内用事务清理数据并回滚,不跨用例残留。
- 任何「未做/部分完成」必须写明缺口,不得标 ✅。

## G1 进度（2026-10-19）

- **G1-01 ADR**：已完成 [ADR-0001-trusted-publishing.md](./ADR-0001-trusted-publishing.md)，状态为 **Proposed**，等待产品、架构、业务/活动、内容、法务、QA 签署。
- **G1-02**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-02-migration-evidence.md](./G1-02-migration-evidence.md)。已落地 `events` 兼容扩展与 `ecosystem_outbox`；不建 revision/FK、不删旧列、不改 `event_registrations`。root 主库尚未迁移，Admin 尚未接 `row_version`，无 outbox worker，公开/报名/内容开关仍为 `false`。
- **G1-03**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-03-organizer-evidence.md](./G1-03-organizer-evidence.md)。已落地 `ecosystem_organizers` 与 `events.source_type/platform_role/organizer_id`（含 `RESTRICT` 外键），以及 `OrganizerService` / `EventSourceService` / `EventSourceValidator`；回滚/分步重跑、真实服务探针与 9 个指定测试文件（86 passed / 761 assertions）均通过。**root 主库尚未迁移**；无 Admin UI/路由，发布状态机仍由 G1-08，媒体与内容域由 G1-05/06 建表，无报名改动；四个开关仍为 `false`；B-01/B-03 未签署前只放行 `platform + organizer` 发布。
- **G1-04**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-04-revision-evidence.md](./G1-04-revision-evidence.md)。已落地 `event_revisions` / `event_rule_versions`、events 双 revision 指针 FK、内容指纹、revision/rule 草稿服务与不可变守卫；回滚/重跑、真实服务探针与 13 个指定测试文件（144 passed / 1237 assertions）均通过。**root 主库尚未迁移**；无非空 published 指针写入、无审核/发布状态机、无 outbox 发布事件、无媒体/富文本净化（由 G1-06/07 补齐）、内容域由 G1-05 建表、无 Admin UI/路由/前端、无报名改动；四个开关仍为 `false`。
- **G1-05**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-05-content-evidence.md](./G1-05-content-evidence.md)。已落地 `content_posts` / `content_revisions` / `content_sources`、slug 冲突策略、来源权利依据、内容指纹（含有序来源）、文章壳与 revision/source 草稿服务；回滚/重跑、CMS 10 页比对、真实内容链路探针与 16 个指定测试文件（192 passed / 1711 assertions）均通过。**root 主库尚未迁移**；无内容公开 API/前端、无非空 published 指针写入、无审核/发布状态机、无 Admin UI/路由、无报名改动；媒体与富文本净化由 G1-06/07 补齐；内容开关仍为 `false`；B-04 最终 slug 词表未冻结。
- **G1-06/07**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-06-07-media-rich-text-evidence.md](./G1-06-07-media-rich-text-evidence.md)。已落地 `ecosystem_media` / `ecosystem_media_refs`、私有媒体上传与严格解码校验、媒体引用/manifest/指纹联动、公开媒体门禁、HTMLPurifier 白名单净化和 revision 服务集成；回滚/重跑、真实链路探针与 22 个指定测试文件（258 passed / 2206 assertions）均通过。**root 主库尚未迁移**；无公开媒体/内容 HTTP 路由与前端渲染、无审核/发布状态机、无媒体清理/保留策略/CDN、无 Admin UI、无报名改动；四开关仍为 `false`。
- **入口确认**：用户已于 2026-10-19 确认按 ADR 默认口径执行；B-01 外链许可、B-03 主办方核验、B-06 取消/改期政策、B-07 内容法律责任对应的写能力仍保持关闭。

## G1-08 ~ G1-11 收口（2026-10-19）

**本机代码、测试、root 迁移、恢复演练、公开读取、worker/scheduler 与 H5 真实数据 E2E 已完成。**

- **G1-08A/B**：审核、发布、排期、暂缓、恢复、撤稿、指针切换、事务 outbox 与并发 CAS 已完成。
- **G1-09**：outbox 租约、重试、过期恢复、旧 worker 拒绝、到期发布扫描与 kill switch 已完成；本机 `schedule:work` 已常驻并实测每分钟执行。
- **G1-10**：公开活动/资讯/媒体 API 与 DTO 白名单已完成；本机公开读取开关已启用。
- **G1-11**：独立列表/详情、首页预览、筛选分页、分享与富文本渲染已完成；真实数据 H5、410、503 和剪贴板已实测截图。
- **Admin**：活动新建、草稿二次编辑、多来源、媒体上传/审核/预览、revision 媒体引用、主办方核验/驳回/暂停、审核/排期/发布/处置全链路已完成。
- **root**：备份 1,530,144 bytes，恢复演练 186 表 / 8 events / 1 registration 一致；additive 迁移后旧字段哈希不变，8 条样例仍为 draft/normal，报名未丢失。
- 详细生产收口证据：[G1-production-cutover-evidence.md](./G1-production-cutover-evidence.md)。

**当前运营边界**
- root 尚无真实已发布赛事/资讯，公开列表为空是正确状态；不得把 Seeder 样例当作真实内容。
- 资讯发布仍关闭：B-04/B-07 未签署。资讯公开读取代码已就绪，待签署后开启 `ECOSYSTEM_CONTENT_PUBLISH_ENABLED=true`。
- 报名 G2、CDN/缓存撤回、搜索/收藏/SSR/OG/sitemap 不在 G1 范围。
- 小程序真机分享、Safari/微信内置浏览器剪贴板、正式托管主机部署仍需目标环境验证。
- `sent` 只代表 outbox 事实被消费，不代表用户已收到通知。

## G2 进度（2026-10-19，本机）

**代码与独立库验证已完成；生产报名闸仍关闭。**

- **G2-01/02/14**：scope/attempt/幂等/人员范围 schema 与服务；Admin 范围配置与人员授权。
- **G2-03/04/06/07**：提交、幂等、自动/人工确认、审核、取消/重报；审核到期扫描。
- **G2-08/09**：活动取消/改期记录、变更确认、凭证与签到；B-06 生命周期写闸默认关闭。
- **G2-11/13**：联系人加密/遮罩/解密审计；专用 CSV 导出（公式中和、短期 token）。
- **G2-12**：报名 outbox 消费与站内信去重；只发 inapp。
- **G2-05/10**：报名页/我的报名/变更确认/凭证前端。
- **G2-15**：真实浏览器客户侧 + Admin 签到演练通过；多进程容量竞争、锁等待、审核抢名额、取消/提交竞争、等锁截止/降容、afterCommit/回滚、520 行扇出与密钥轮换均已验证。
- **root**：已备份、恢复演练并 additive 迁移 9 个迁移；旧报名保留为 legacy；
  `registration_enabled` / `registration_write_verified` / `ecosystem_registration.enabled`
  仍为 false，未创建 scope、未开放任何活动。

详细证据：[G2-A-B-evidence.md](./G2-A-B-evidence.md)、
[G2-C-D-E-evidence.md](./G2-C-D-E-evidence.md)、
[G2-implementation-plan.md](./G2-implementation-plan.md)。

**G2 仍未完成 / 需业务签署**
- B-06 取消/改期责任、B-05 留存/注销、P-10 重报上限、P-12/P-13 变更政策；
  签署前 `policy_confirmed_at` 不能写入，生产 scope 不能 open。
- 生产部署侧需启动 `schedule:work`/cron，并逐个活动执行激活与演练。
- T31 切号/跨账号浏览器状态、T47 跨设备一致性仍需补；T57 受 B-05 政策阻塞，只提供 dry-run。
- 生产密钥托管与旧 key 恢复演练未做（本机轮换已通过）。
- 小程序真机扫码、Safari/微信内置浏览器未验证。

## G3-01 ~ G3-06 进度（2026-10-19，本机）

**代码、迁移、独立库测试与 root additive 迁移已完成；赛务写闸仍关闭。**

- **G3-01**：规则冻结模板与机器契约已完成（[G3-01-competition-rule-freeze.md](./G3-01-competition-rule-freeze.md)）。
  首场真实赛规尚未签署，因此**不创建真实赛项、不开放报名/成绩**。
- **G3-02**：系列 / 届次 / 赛项 / 专属 team scope、规则版本绑定与激活前置已完成；
  跨届/跨赛项/scope 复用被拒绝；普通活动个人报名保持 person 单位。
- **G3-03/04**：队伍、一次性邀请码、成员接受/退出、队长移交、一人多队限制、
  队伍报名归属（`subject_key=t:{team_id}`）、成员/资格/机器人声明冻结快照已完成。
- **G3-05**：作品草稿/版本/提交/补正、截止锁后重检、跨队拒绝、私有附件
  （真实 MIME/大小/数量/路径校验，仅有效成员可下载）已完成。
- **G3-06**：阶段/场次、CSV 预览导入（逐行错误定位、幂等重试、已发布不可覆盖）、
  发布门禁与公开赛程只读接口已完成；公开赛程开关默认关闭。
- **Admin**：赛务工作台、规则确认、赛项激活、报名范围开放/关闭、赛程预览/导入/发布；
  路由逐条登记权限并通过 ACL 全路由测试。
- **前端**：活动详情赛项/赛程区块、组队/邀请/队员管理、队伍报名、作品提交页。
- **数据库**：独立库 `summon_mecha_step10_test` 迁移通过；root 已备份
  `backups/root-before-g3-20261019-232107.sql`（1,650,618 bytes）后 additive 迁移，
  旧活动/报名未改写，`competition_*` 表为空，赛务写闸/公开赛程闸均为 false。
- **测试**：G3 专项 34 passed / 170 assertions；Ecosystem 全量 453 passed / 3,227
  assertions；Admin ACL + G3 10 passed / 357 assertions；前端 Node 29 passed。
- 详细证据：[G3-01-06-evidence.md](./G3-01-06-evidence.md)。

**G3 仍未完成 / 需业务签署**
- 首场目标赛事的赛项、资格、评分、同分、申诉与安全责任尚未由负责人签署；
  签署前只能使用测试活动，生产 `rules_confirmed_at` 不应写入。
- G3 的 MySQL 多连接并发压测、真实浏览器全链路演练、小程序真机附件上传/下载
  尚未执行；现有证据为服务层 + HTTP 功能测试 + 独立库迁移。
- 作品附件为私有盘；未接 CDN/对象存储，未验证撤稿后的缓存清理。

## G3-07 ~ G3-12 进度（2026-10-20，本机）

**代码、独立库测试与 root additive 迁移已完成；赛务写闸与公开成绩/展示闸仍关闭。**

- **G3-07**：计分契约（策略/精度/字段/缺赛/弃权/同分）与策略注册完成；
  定点整数计算，拒绝浮点/用户公式；`manual`、`total_desc` 两种已登记策略。
- **G3-08**：成绩草稿/提交复核/排名/暂定/终审/修订完成；录分与发布分权；
  旧版本保留并标 `superseded`；公开成绩只返回已公布版本，修订中标记复核中。
- **G3-09**：申诉窗口/主体/证据类型校验、受理/调查/裁决、私有证据下载完成；
  裁决记录理由与适用成绩版本；事件时间线只追加。
- **G3-10**：奖项版本完成；成绩修订后旧奖项转 `pending_review`，新版本显式
  重新发布并指向新成绩；历史版本不删除。
- **G3-11**：作品展示必须由队长对具体版本授权；授权指纹绑定内容；
  公开字段最小化；获奖不自动上架/发 license/下发真机；技能提审走既有闸门。
- **G3-12**：整届受控演练测试通过（T59–T69、T72；
  [G3-12-drill.md](./G3-12-drill.md)）。
- **Admin**：成绩、申诉、奖项、展示四个工作台；路由逐条登记权限，
  scoring/results/appeal/awards/showcase 分权。
- **前端**：成绩与展示页、队长展示授权/技能提审入口；活动详情入口。
- **数据库**：独立库迁移通过；root 备份
  `backups/root-before-g3-07-20261020.sql`（1,674,468 bytes）后 additive 迁移；
  新 `competition_*` 表为空，活动/报名未改写。
- **测试**：G3 专项 62 passed / 409 assertions；Ecosystem 全量 477 passed /
  3,441 assertions；Admin ACL + G3 14 passed / 453 assertions；前端 Node 29 passed。
- 详细证据：[G3-07-12-evidence.md](./G3-07-12-evidence.md)。

**G3-07 ~ G3-12 仍未完成 / 需业务签署**
- 首场赛规仍未签署；不得创建真实赛项、写生产 `rules_confirmed_at` 或开放成绩/展示。
- 并发压测、真实浏览器全链路、小程序真机附件仍未做。
- 技能提审正向链路在演练中使用 mock；真实提审仍受协议/包/L0/L2 闸门约束。
- 通知只有站内信 outbox；未接短信/邮件/微信，`sent` 不代表已送达。

## G4-01 ~ G4-09 进度（2026-10-20，本机）

**代码、独立库测试与 root additive 迁移已完成；G4 新开关全部关闭。**

- **G4-01**：关键词/城市/日期/类型/报名状态筛选；LIKE 通配符转义；游标分页 +
  筛选指纹；page 模式保留并加 200 页上限。
- **G4-02**：收藏表/服务/API/我的收藏；唯一键幂等、跨账号隔离、撤稿目标只计
  `stale_count`、单用户上限 500、响应 `private, no-store`。
- **G4-03**：活动/资讯关联；失效/草稿/撤稿/下架目标整项省略；Admin 维护入口；
  不修改设备/商品真相源。
- **G4-04**：Laravel SSR `/events`、`/content`、详情、`/sitemap.xml`；
  canonical/OG/JSON-LD；撤稿 410 中性页；草稿预览沿用 `X-Robots-Tag: noindex`。
- **G4-05**：H5 SEO 纯函数与详情页接入；canonical 指向 SSR；分享路径不含私有状态。
- **G4-06**：指标口径与事实同页；报名按 attempt、席位按当前 confirmed/checked_in；
  内容访问日聚合（不存 IP/UA/用户 ID）；无源返回 `available=false`。
- **G4-07**：运营工作台（待审 revision/报名、即将截止、通知失败、来源复核、撤稿）；
  PII 遮罩 + 活动人员范围；失败通知重排只接受未租用 failed。
- **G4-08**：撤稿/取消/停读同步降级演练；独立测试库基准命令
  （`ecosystem:g4-benchmark`，非生产压测）。
- **G4-09**：`config/ecosystem_g4.php` 开关矩阵、`ecosystem:g4-status` 只读状态、
  上线/回退/值守手册 `G4-runbook.md`。
- **数据库**：root 备份 `backups/root-before-g4-20261020.sql`（1,696,955 bytes）后
  additive 迁移 3 个；旧活动/报名未改写，新表为空。
- **测试**：Ecosystem 全量 511 passed / 3,679 assertions；Admin ACL + G4 13 passed /
  495 assertions；前端 Node 37 passed。
- 详细证据：[G4-evidence.md](./G4-evidence.md)。

**G4 仍未完成 / 需真实环境**
- 生产并发压测、真实浏览器 H5/SSR 全链路、搜索引擎收录、CDN 撤稿 purge、
  小程序真机分享与统计生产验证均未做；不得用单进程基准冒充并发结论。
- 所有 G4 开关默认 false；打开顺序与回退见 [G4-runbook.md](./G4-runbook.md)。

## Q-01 ~ Q-05 横向质量（2026-10-20，本机）

**五个横向工作包全部落地；演练脚本可复跑，证据已归档。**

- **Q-01 契约门禁**：`config/ecosystem_contract.php` 冻结 45 条公开路由 / 85 个错误码 /
  11 个状态快照；`php artisan ecosystem:contract-check` 双向核对路由—ACL—错误码—状态—OpenAPI，
  失败即非零退出。抓到并修复 `COMPETITION_TRACK_NOT_FOUND` 未定义常量。
- **Q-02 并发/隔离**：专用测试库硬校验（root 拒绝）、容量 1 两进程竞争、真实行锁等待、
  REPEATABLE READ 快照 vs `FOR UPDATE` 当前读；7/7 通过。
- **Q-03 worker/补偿**：afterCommit/回滚、真实 worker、重复投递、崩溃租约、25 条积压、
  failed 人工补偿 vs dead 终态；10/10 通过。修复了“worker 从不写 failed，
  运营台重排入口永远为空”的缺陷。
- **Q-04 浏览器演练**：客户 / 队长 / 裁判 / 超管四角色；375/390px、键盘 Tab、
  错误分支、403 无 PII 泄漏；18/18 通过，9 张截图。
- **Q-05 数据/迁移**：只读 `ecosystem:data-preflight`、计数对账 dry-run/apply、
  留存 dry-run、expand/backfill/switch/recovery 演练；9/9 通过。
- 详细证据：[Q-01-05-quality-evidence.md](./Q-01-05-quality-evidence.md)。
- 运行方式：[my-bagisto-store/qa/ecosystem/README.md](../../my-bagisto-store/qa/ecosystem/README.md)。

**Q 仍未完成 / 需真实环境**
- 生产规模并发与真实部署环境的 worker / 浏览器复跑；CI 流水线尚未接入。
- Q-04 仅 Chrome headless；小程序真机、微信内置浏览器、Safari 未验证。
- Q-05 的迁移演练是测试库流程演练，不等于生产迁移已批准。
