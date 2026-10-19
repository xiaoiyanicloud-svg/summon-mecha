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
- **G1-03**：代码与独立测试库验证已完成（2026-10-19），证据见 [G1-03-organizer-evidence.md](./G1-03-organizer-evidence.md)。已落地 `ecosystem_organizers` 与 `events.source_type/platform_role/organizer_id`（含 `RESTRICT` 外键），以及 `OrganizerService` / `EventSourceService` / `EventSourceValidator`；回滚/分步重跑、真实服务探针与 9 个指定测试文件（86 passed / 761 assertions）均通过。**root 主库尚未迁移**；无 Admin UI/路由、无发布状态机/revision/内容/媒体、无报名改动；四个开关仍为 `false`；B-01/B-03 未签署前只放行 `platform + organizer` 发布。
- **入口确认**：用户已于 2026-10-19 确认按 ADR 默认口径执行；B-01 外链许可、B-03 主办方核验、B-06 取消/改期政策、B-07 内容法律责任对应的写能力仍保持关闭。
