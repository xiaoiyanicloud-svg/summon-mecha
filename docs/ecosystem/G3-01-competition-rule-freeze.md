# G3-01 首场赛事规则冻结

> 状态：**模板 / 未签署**。截至 2026-10-19，没有真实首场赛事规则、负责人和
> 现场安全责任完成确认，因此本平台**不得**创建真实赛项、开放报名或产生成绩。
> 本文是运营与工程共用的冻结清单；只有全部必填项由对应负责人签字并写入
> `competition_tracks.rules_profile` 后，赛项才允许激活。

## 1 为什么不能由代码补默认值

赛事规则决定资格、晋级、同分和申诉结果。任何“代码默认 = 3 人一队”“同分按
报名时间”都会在没有业务依据时制造官方结论。工程侧只做三件事：

1. 校验冻结项是否完整、类型是否合法；
2. 保存规则版本与指纹，后续报名/成绩/申诉引用具体版本；
3. 缺项时拒绝激活赛项、拒绝确认参赛、拒绝发布成绩。

## 2 必填冻结项

### 2.1 责任矩阵（每项必须有真人/岗位）

| 规则域 | 负责人 | 备份负责人 | 可引用版本 | 确认时间 |
|---|---|---|---|---|
| 赛项与赛制 | 待填写 | 待填写 | 待填写 | 待填写 |
| 资格与组队 | 待填写 | 待填写 | 待填写 | 待填写 |
| 评分与同分 | 待填写 | 待填写 | 待填写 | 待填写 |
| 申诉与裁决 | 待填写 | 待填写 | 待填写 | 待填写 |
| 现场与机器人安全 | 待填写 | 待填写 | 待填写 | 待填写 |

对应机器字段：`rules_profile.owners.{rules,scoring,appeal,safety}`。
空字符串、占位符（“待定”“TBD”）一律视为未确认。

### 2.2 资格与组队

| 项目 | 必须明确 | 机器字段 |
|---|---|---|
| 队伍人数下限/上限 | 具体整数，含队长 | `eligibility.min_members` / `max_members` |
| 是否允许一人多队 | 是/否；若允许，说明跨赛项限制 | `track.allow_multi_team` + 业务说明 |
| 机器人声明 | 是否必填；必填字段清单 | `eligibility.robot_declaration_required` / `robot_declaration_fields` |
| 成员资格 | 账号、实名、年龄、监护人等以哪一条为准 | `eligibility` 扩展键 + 规则文档 |
| 冻结时点 | 报名提交时 / 审核通过时 / 赛前某时刻 | 业务文档；本批固定“报名提交时快照” |

未明确的人数上下限或机器人字段，代码不会猜测；赛项激活直接拒绝。

### 2.3 赛项与评分（G3-06/G3-07）

| 项目 | 本批要求 |
|---|---|
| 赛项 key/名称/学科 | 每届唯一；`unique(edition_id, track_key)` |
| 阶段与场次 | G3-06 人工编排 + CSV 预览导入 |
| 评分策略 | `scoring.policy` 必须是已登记策略：`manual` / `total_desc`；不允许用户公式 |
| 计分字段 | 使用自动策略或录入数值时必须给出 `scoring.fields`（1~8 项，key/label/type/direction/min/max） |
| 精度 | `scoring.scale` 0~6 位；服务端按定点整数计算，JSON 浮点直接拒绝 |
| 缺赛 | `scoring.missing`：`exclude` / `zero` / `disqualify` |
| 弃权 | `scoring.forfeit`：`exclude` / `zero` / `disqualify` |
| 同分 | `tie.policy`：`manual` / `shared` / `strict`；`strict` 不猜顺序，并列留空待人工核准 |
| 成绩公布 | G3-08 才允许“草稿→提交复核→暂定→终审→修订”；录分与发布分权 |

示例（**仅结构示例，不是首场真实赛规**）：

```json
"scoring": {
  "policy": "total_desc",
  "scale": 2,
  "fields": [
    {"key": "speed", "label": "速度分", "type": "decimal", "direction": "higher", "min": "0", "max": "100"}
  ],
  "missing": "exclude",
  "forfeit": "disqualify"
},
"tie": {"policy": "shared"}
```

### 2.4 申诉

| 项目 | 机器字段 |
|---|---|
| 可提出主体 | `appeal.allowed_roles`（本批只支持 `captain`） |
| 窗口时长（分钟） | `appeal.window_minutes` |
| 允许证据类型 | `appeal.evidence_types`（pdf/image/zip…） |
| 受理与裁决人 | `owners.appeal` |
| 过期/越权/证据不符的处理 | G3-09 实现；本批只保存契约 |

### 2.5 安全职责

| 项目 | 机器字段 |
|---|---|
| 现场安全负责人岗位 | `safety.owner_role` |
| 安全方案/应急预案引用 | `safety.plan_ref` |
| 紧急联系人引用（不存明文到公开域） | `safety.emergency_contact_ref` |
| 机器人检查项 | `eligibility.robot_declaration_fields` + 规则附件 |

安全方案与紧急联系人属于私有资料：只保存引用/编号，不进入公开 DTO，
不在日志/outbox 中输出明文。

## 3 规则版本与指纹

- 赛项必须绑定本活动的 `event_rule_versions` 版本；不允许引用其它活动/届次的规则。
- `rules_profile` 规范化 JSON 后计算 sha256，写入
  `competition_tracks.rules_fingerprint`。
- 规则实质变化必须新建规则版本并重新确认；已冻结的参赛快照继续引用旧指纹，
  不被新规则追溯覆盖。
- 外部规则文件优先官方链接；只获外链许可的文件不得复制托管（沿用 G1-03/04）。

## 4 工程侧拒绝矩阵

| 场景 | 结果 |
|---|---|
| 规则 profile 缺键 / 类型错 / 空字符串 | 赛项不能激活：`COMPETITION_RULES_UNCONFIRMED` |
| 规则版本不属于该活动 | 拒绝绑定 |
| 队伍人数不在冻结区间 | 不能确认参赛：`COMPETITION_ELIGIBILITY_FAILED` |
| 机器人声明缺必填字段 | 不能确认参赛 |
| 无提交截止时间 | 作品不能提交 |
| 自动策略缺字段/精度/缺赛/弃权语义 | 规则确认失败；不能激活 |
| 非登记策略 / 用户公式 | 拒绝：`COMPETITION_RULES_UNCONFIRMED` |
| 未确定名次 | 不能暂定发布：`COMPETITION_RANKING_REQUIRED` |
| 录分人与发布人相同 | 拒绝：`COMPETITION_DUTY_SEPARATION` |
| 暂未签署的赛项 | 不创建真实赛项、不开放报名、不发布赛程 |

## 5 签署

| 角色 | 姓名/岗位 | 签字 | 日期 |
|---|---|---|---|
| 活动产品 | | | |
| 裁决负责人 | | | |
| 评分负责人 | | | |
| 安全负责人 | | | |
| 工程负责人 | | | |

> 签署完成前，本文件保持“未签署”。不得把模板中的示例值写入生产库。
