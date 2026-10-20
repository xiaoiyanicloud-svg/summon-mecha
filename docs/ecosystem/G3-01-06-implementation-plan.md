# G3-01 ~ G3-06 实施计划（人工赛务基础）

> 状态：实施中（2026-10-19）。范围只覆盖 G3-01 至 G3-06；G3-07（计分策略）、
> G3-08（成绩复核）、G3-09（申诉）、G3-10（修订/奖项）、G3-11（展示/提审）、
> G3-12（整届演练）不在本批。

## 1 结论先行

- **先冻结规则，再允许写入**。G3-01 不写“默认赛制”：任何一届赛事的赛项、
  资格、评分、同分、申诉、安全责任必须由负责人显式确认，代码只校验完整性，
  缺失时拒绝激活赛项与确认参赛。
- **G3 与 G2 共用报名锁协议**：event → scope → team → registration → attempt →
  outbox。队伍报名是同一 scope 下的独立 subject（`t:{team_id}`），不复制一套
  绕过 G2 名额/幂等/停写闸的写入口。
- **报名 ≠ 参赛确认**。报名成功只产生 registration/attempt；G3-04 的
  `competition_entries` 才是“当届成员/资格/机器人声明”的冻结快照。
  未达到资格、规则未确认时不能确认参赛。
- **作品与赛程的公开面后置**。G3-05 只做队伍可见的草稿/版本/提交/补正与私有附件；
  G3-06 的赛程先进入 `draft`，运营显式发布后才可能被公开读取，
  且仍受 G1 活动公开状态与 kill switch 约束。
- **所有 G3 写能力默认关闭**：`ecosystem_competition.write_enabled=false`。
  只有独立测试库/演练环境显式打开；生产开放必须逐赛事签署规则并演练。

## 2 G3-01 规则冻结

交付物：

1. [`G3-01-competition-rule-freeze.md`](./G3-01-competition-rule-freeze.md)：
   首场赛事规则冻结模板、责任矩阵、未确认项清单。当前**没有任何真实赛事规则
   完成签署**，因此本批不创建真实赛项、不开放任何报名/成绩能力。
2. 机器可读规则契约 `competition_tracks.rules_profile`：
   ```json
   {
     "owners":     {"rules": "…", "scoring": "…", "appeal": "…", "safety": "…"},
     "eligibility":{"min_members": 1, "max_members": 5,
                    "robot_declaration_required": true,
                    "robot_declaration_fields": ["model", "asset_no"]},
     "scoring":    {"policy": "manual"},
     "tie":        {"policy": "manual"},
     "appeal":     {"window_minutes": 120, "allowed_roles": ["captain"],
                    "evidence_types": ["pdf", "image"]},
     "safety":     {"owner_role": "…", "plan_ref": "…", "emergency_contact_ref": "…"}
   }
   ```
3. `CompetitionRuleProfile` 服务：只校验、不补默认值；计算稳定指纹；
   任何键缺失/类型不符/空字符串 → `COMPETITION_RULES_UNCONFIRMED`。
   指纹与 `event_rule_versions` 绑定；同一赛项确认后规则变化必须新建规则版本。

本批 `scoring.policy` / `tie.policy` 只接受 `manual`。自动排名属于 G3-07，
不得在本批用浮点或用户公式冒充。

## 3 G3-02 系列 / 届次 / 赛项

新增表：

| 表 | 关键约束 |
|---|---|
| `competition_series` | `slug` 唯一；主办主体可空但保留 `organizer_name` 快照 |
| `competition_editions` | `unique(series_id, edition_no)`；`event_id` 唯一且必须是 `kind=competition` 的活动 |
| `competition_tracks` | `unique(edition_id, track_key)`；`registration_scope_id` 唯一；`rules_version_id` 只允许本活动的规则版本 |

- 每个赛项绑定一个 `event_registration_scopes` 行（`scope_key=track:{track_key}`，
  单位固定 `team`）。赛项不能复用别的届次/活动的 scope。
- 普通活动继续使用 `default` scope 与个人报名；`RegistrationSubmissionService`
  新增单位守卫：scope 不是 `person` 时个人报名入口拒绝。
- 赛项激活要求：scope 属于同届活动、`unit=team`、容量已确认、规则契约完整、
  提交截止已配置。赛项激活**不等于**报名开放；报名仍走 G2 双闸 + scope 激活。

## 4 G3-03 队伍

新增表：`competition_teams`、`competition_team_members`、
`competition_team_invites`。

- 队长创建队伍；队长通过**一次性邀请码**邀请成员（库里只存 sha256，
  7 天过期）。不使用手机号/客户 ID 搜索，避免客户枚举与 PII 侧信道。
- 成员接受后才计入有效成员；邀请中/拒绝/已离开都不算。
- 队长移交、成员退出、队伍解散均走行锁 + `expected_row_version`。
- 队长不能直接退出；必须先移交或解散。
- `allow_multi_team=false` 时，同一客户在同一赛项只能有一个
  invited/active 关系；该判断在赛项行锁内完成，不靠前端去重。
- 队伍报名由队长发起，`subject_key=t:{team_id}`；队员通过队伍关系读取，
  不共享登录态。报名记录新增 `team_id`，历史个人报名不受影响。

## 5 G3-04 当届成员与参赛确认

新增表：`competition_entries`。

- 队伍提交报名时在同一事务内创建/冻结 entry：
  成员（仅 active）、队长、队伍名、赛项、规则版本与指纹、机器人声明。
- 冻结后队伍改名/换人**不回溯覆盖**快照；需要变更必须走后续版本的
  重新核验流程（G3-10 不在本批）。
- 资格检查只读 `rules_profile.eligibility`：人数上下限、机器人声明必填字段。
  规则未确认或快照缺项 → 不能确认参赛。
- 报名自动确认时 entry=`confirmed`；人工审核时 entry=`submitted`，
  审核批准后由 G2 审核服务回调确认；拒绝则 entry=`withdrawn`。

## 6 G3-05 作品

新增表：`competition_submissions`、`competition_submission_versions`、
`competition_submission_attachments`。

- 只有已确认/已提交参赛的 entry 才能创建作品；只允许冻结快照中的队长写。
- 草稿可编辑；提交后版本不可变。补正新建 `version_no+1`，旧版本永远保留。
- 截止时间在锁后重新读取；过期提交拒绝。无截止时间 = 规则未确认，拒绝提交。
- 附件独立私有存储（不走 `ecosystem_media` 的公开审核语义）：
  真实 MIME/扩展名/大小/数量校验、服务端生成存储名、内容哈希；
  下载必须重新核验队伍成员身份，响应 `attachment` + `no-store`。
- 公开展示属于 G3-11，本批不提供任何公开作品接口。

## 7 G3-06 阶段 / 赛程 / 场次

新增表：`competition_stages`、`competition_matches`。

- CSV 列：`stage_key,stage_name,stage_order,match_key,round_no,
  starts_at,ends_at,venue,home_entry_no,away_entry_no`。
- 预览不写库：逐行返回行号、规范化值、错误；`preview_hash` 是文件 sha256。
- 导入前重新解析并校验；导入事务内按 `(track_id, stage_key)` 建阶段、
  按 `(edition_id, match_key)` 幂等写入。完全相同的重试跳过；不同载荷更新
  草稿场次并递增版本；已发布阶段/场次不允许被导入覆盖。
- 拒绝：不存在的 entry、entry 属于其它赛项、同文件重复 match_key、
  同 entry 时间重叠、同场地时间重叠、起止缺失/倒置。
- 发布阶段时一次性校验其全部场次；发布后公开读取只返回
  `stage.published + match.published`，且活动必须已发布、kill switch 未命中。
- 公开赛程开关 `ecosystem_competition.public_schedule_enabled` 默认 false。

## 8 权限与开关

- Shop 本人接口：必须登录，队伍/参赛/作品按 customer 归属逐一重检。
- Admin 新增 `ecosystem.competition.*` 权限：view / settings / review / publish。
  每条路由同时登记 `config/ecosystem_admin.php` 与 `config/acl.php`。
- 全局写闸：`ecosystem_competition.write_enabled=false`。关闭时所有
  G3 写动作稳定 403 `COMPETITION_DISABLED`，且检查早于查库。
- 公开赛程开关独立；关闭时 404，不查库。
- 不修改 G1/G2 已有开关；不打开 root 报名/公开域。

## 9 测试与证据

- 独立库 `summon_mecha_step10_test`；不跑 `migrate:fresh`。
- 每个工作包至少一个 Feature 文件：
  - G3-01/02：规则完整性、系列/届次/赛项隔离、scope 绑定、普通个人报名不被破坏；
  - G3-03：邀请/接受/越权/移交/多队限制/队伍报名归属；
  - G3-04：快照冻结、资格失败、改名不回溯、审核确认/拒绝；
  - G3-05：截止锁后重检、跨队拒绝、版本不可变、附件私有；
  - G3-06：CSV 预览错误定位、幂等/冲突、发布门禁、公开只读。
- 证据文档：`G3-01-06-evidence.md`，包含迁移、测试真实输出、未做项、回滚。

## 10 明确未做

- G3-07 计分自动策略、G3-08 成绩复核/发布、G3-09 申诉、G3-10 修订/奖项、
  G3-11 作品公开与提审、G3-12 整届演练。
- 真实赛事规则签署、真实运营数据、短信/邮件通知、真机比赛验收。
- 管理员批量人员范围继承（沿用 G2-14）、赛项级人员范围细分。
- 赛程自动抽签、实时裁判设备接入。
