# G3-07 ~ G3-12 实施计划（计分 / 成绩 / 申诉 / 修订奖项 / 展示提审 / 整届演练）

> 状态：实施中（2026-10-19）。本批只覆盖 G3-07 ~ G3-12。
> 前置事实见 [G3-01-06-evidence.md](./G3-01-06-evidence.md)；
> 规则冻结模板见 [G3-01-competition-rule-freeze.md](./G3-01-competition-rule-freeze.md)。

## 0 结论先行

1. **不补空白政策**。计分字段/精度/缺赛/弃权/同分策略全部来自已签署的
   `competition_tracks.rules_profile`；规则没写清楚就不允许自动排名，只保留人工名次。
2. **不使用浮点排名**。分数在入口规整为“定点整数 + 小数位”，用 bcmath/整数比较；
   JSON 浮点直接拒绝。排名只接受注册过的确定性策略，**永不执行用户公式**。
3. **录分与发布分权**。`scoring` 权限只能建草稿/提交复核；`results` 权限才能
   暂定发布/终审/修订。同一管理员不能既录分又发布同一版本。
4. **发布即快照**。成绩版本提交后不可改；修订必须新建版本并保留旧版本，
   旧版本标记 `superseded`，不删除、不改写。
5. **申诉只认版本与窗口**。申诉必须绑定当次公布的 `result_version`，窗口 = 公布时间 +
   `appeal.window_minutes`；裁决必须记录理由、适用规则版本、结果版本。
6. **奖项不自动跟随修订**。成绩修订后，引用旧版本的已发布奖项转 `pending_review`，
   不再冒充最终结论；新奖项必须显式重新发布。
7. **作品公开展示必须由队长显式授权**。获奖不会自动上架、不发 license、不下发真机；
   进入技能提审是队长显式动作，走既有 `DeveloperSkillService::submitForReview` 闸门。
8. **所有新写入口默认关闭**。新增开关：
   `ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED=false`、
   `ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED=false`；
   写能力继续受 `ECOSYSTEM_COMPETITION_WRITE_ENABLED=false` 控制。

## 1 G3-07 计分契约与策略注册

### 1.1 规则契约扩展（不改变已有必填项）

```json
"scoring": {
  "policy": "manual | total_desc",
  "scale": 3,
  "fields": [
    {"key": "total", "label": "总分", "type": "decimal", "direction": "higher", "min": "0", "max": "999"}
  ],
  "missing": "exclude | zero | disqualify",
  "forfeit": "exclude | zero | disqualify"
},
"tie": {"policy": "manual | shared | strict"}
```

- `scoring.policy=manual` 时允许没有 `fields/scale`（人工名次）；
  一旦录入数值或使用自动策略，`fields`（1~8 个）与 `scale`（0~6）必须存在。
- `tie.policy=strict` 遇到并列**不猜顺序**，返回未决并列，要求人工核准。
- 缺赛（absent）与弃权（forfeit）分别按 `missing` / `forfeit` 策略处理；
  策略为 `disqualify` 时不得进入排名。
- 规则版本指纹已经覆盖以上内容；成绩版本再单独保存 `scoring_snapshot` 与
  `scoring_fingerprint`，避免规则后续变化改写历史语义。

### 1.2 策略注册

`config/ecosystem_competition.php` 注册：

| key | 实现 | 行为 |
|---|---|---|
| `manual` | `ManualScoringPolicy` | 不自动排名，必须人工给名次 |
| `total_desc` | `TotalDescScoringPolicy` | 定点总分降序；并列按 tie 策略 |

新增策略必须实现 `CompetitionScoringPolicy` 接口并登记配置；
禁止表达式、脚本、用户公式。

### 1.3 分数精度

`CompetitionScoringService`：
- 只接受 int / 数字字符串 / `{value,status}`；浮点输入拒绝；
- 小数位超过 `scale` 拒绝；按十进制字符串转定点整数（bcmath）；
- 总分用整数相加，输出定点字符串，避免 `0.1+0.2` 类误差；
- 单项超出 `min/max` 拒绝。

## 2 G3-08 成绩草稿 / 复核 / 发布 / 修订

### 2.1 表

| 表 | 说明 |
|---|---|
| `competition_results` | 一个 `(track, entry, scope_key)` 的成绩主体；`scope_key=track` 或 `match:{id}` |
| `competition_result_versions` | 不可变版本：分数、总分、名次、并列组、策略快照、规则版本、操作人、时间 |

状态：`draft → submitted → provisional → final`；修订产生新 `draft`，
旧 `final` 标 `superseded`，主体状态 `revising`。

### 2.2 动作与权限

| 动作 | 权限 | 规则 |
|---|---|---|
| 保存草稿 | `ecosystem.competition.scoring` | 仅 draft 可改；重新计算总分 |
| 提交复核 | `ecosystem.competition.scoring` | draft→submitted，之后不可改 |
| 暂定发布 | `ecosystem.competition.results` | submitted→provisional；操作人 ≠ 录分人 |
| 终审 | `ecosystem.competition.results` | provisional→final；操作人 ≠ 录分人 |
| 修订 | `ecosystem.competition.results` | final/provisional→新 draft；旧版本 `superseded`；必须写理由 |
| 排名 | `ecosystem.competition.results` | 只改 draft/submitted 版本；可自动或人工 |

所有动作带 `expected_row_version`；不一致返回 `COMPETITION_VERSION_STALE`。

### 2.3 公开

`GET /api/events/{id}/competition/results`：
- 受 `public_results_enabled` + 活动已发布 + kill switch 三重门；
- 只返回 `published_version_id`；`revising` 时返回旧版本并标 `under_review=true`；
- 不返回管理员 ID、内部理由、规则安全联系。

### 2.4 通知

成绩暂定发布写 outbox：`competition_result.provisional`，收件人为该 entry 队长；
裁决/奖项同理。只发站内信；`sent` 只代表事实被消费。

## 3 G3-09 申诉

### 3.1 表

| 表 | 说明 |
|---|---|
| `competition_appeals` | 主体、绑定 result/version、窗口、状态、裁决字段 |
| `competition_appeal_events` | 追加式时间线（提交/受理/调查/裁决/撤回） |
| `competition_appeal_attachments` | 私有证据；只存服务端路径与哈希 |

### 3.2 规则

- 只有规则允许的主体（本批 `captain`）可提交；队员/路人拒绝；
- 必须晚于公布时间、早于 `published_at + window_minutes`；过期稳定拒绝；
- 证据类型按 `appeal.evidence_types` 映射真实 MIME；数量/大小/扩展名校验；
- 证据下载仅提交者或具 `ecosystem.competition.appeal` 权限的管理员；
- 裁决必须填 `decision_result` / `decision_reason` / `result_version_id`；
- 裁决时若当前公布版本已变化，返回 `COMPETITION_APPEAL_RESULT_CHANGED`，要求重新核对；
- 申诉/裁决均写追加事件，不删除历史。

## 4 G3-10 修订、排名与奖项版本

### 4.1 表

| 表 | 说明 |
|---|---|
| `competition_awards` | 赛项内奖项主体（award_key 唯一） |
| `competition_award_versions` | 奖项版本：entry、result_version、名次、状态、理由 |

### 4.2 规则

- 奖项发布必须引用**当前已终审**的 result version；
- 成绩修订：引用旧 result version 的已发布奖项版本转 `pending_review`，
  奖项主体状态同步；旧版本保留；
- 新版本发布时旧 published 版本标 `superseded`；
- 公开只返回 `published` 且 result version 仍为当前 final 的版本；
- 历史成绩、裁决、奖项版本永不物理删除。

## 5 G3-11 作品展示与提审交接

### 5.1 表

| 表 | 说明 |
|---|---|
| `competition_showcases` | 展示主体：entry、submission_version、award、状态、授权字段、技能交接字段 |

### 5.2 规则

- 展示只能引用已提交的作品版本（非 draft）；
- 必须由队长对**具体版本**显式授权；版本内容指纹变化则授权失效；
- 管理端先审核通过，再发布；公开字段只含标题/摘要/队伍/奖项，不含附件/repo/video；
- 获奖不自动上架、不发 license、不下发真机（服务不触碰 skill_entitlements/device）；
- 队长可显式请求“进入技能提审”：必须已有 developer_profile + 自有可提审版本，
  调用既有 `DeveloperSkillService::submitForReview`，记录交接结果与时间。

## 6 G3-12 整届演练

交付：
- `CompetitionFullEditionDrillG3Test`：从规则确认→建队→报名→作品→赛程→录分→复核→
  暂定/终审→申诉→裁决→修订→奖项待复核→重新发布→展示授权→技能提审交接；
- 覆盖 T59–T69、T72 的失败分支（越权、过期、错误证据、并发版本冲突、旧奖项冒充）；
- `G3-12-drill.md` 记录用例→证据矩阵与“未做真机/多连接压测/浏览器”边界；
- 不宣称真机比赛验收、不宣称生产已开放。

## 7 实施顺序与健康门槛

1. G3-07 契约/策略/精度 + 单测；
2. G3-08 表/服务/Admin/公开接口 + 单测；
3. G3-09 申诉 + 证据 + 通知；
4. G3-10 奖项与修订联动；
5. G3-11 展示授权与技能提审交接；
6. G3-12 演练测试与证据；
7. 全量回归（Shop Ecosystem、Admin ACL、前端 Node），root 只做 additive 迁移与只读核对。

**任何一步发现规则契约无法由签署文件支撑，立刻停下，不写生产规则确认。**

## 8 明确不做

- 用户自定义公式/脚本排名、实时裁判设备接入、自动抽签；
- 短信/微信/邮件渠道；只写站内信 outbox；
- 真机比赛验收、生产并发压测、生产开关开启；
- 获奖自动上架、license 下发、真机下发；
- G4 的搜索/收藏/SSR/sitemap。
