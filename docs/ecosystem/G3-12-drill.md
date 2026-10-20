# G3-12 整届受控演练记录

日期：2026-10-20。环境：独立测试库 `summon_mecha_step10_test`（`APP_ENV=testing`）。
执行入口：

```bash
cd D:/main/my-bagisto-store
DB_DATABASE=summon_mecha_step10_test php artisan test \
  packages/Webkul/Shop/tests/Feature/Ecosystem/CompetitionFullEditionDrillG3Test.php
# 1 passed / 57 assertions
```

> 本演练是**功能与异常路径演练**，不是真机比赛验收，也不是生产并发压测。
> 技能提审环节使用 mock，只证明调用了既有闸门并记录交接，不代表真实审核通过。

## 1 链路

```text
规则版本 → 系列/届次/赛项 → 报名 scope 激活
  → 建队 → 邀请码 → 成员接受 → 队伍报名 → 参赛快照冻结
  → 作品草稿 → 作品提交
  → CSV 赛程预览 → 导入 → 阶段发布
  → 录分草稿 → 提交复核 → 排名 → 暂定发布 → 终审
  → 队长申诉 → 受理 → 裁决（upheld）
  → 成绩修订（旧版本 superseded）→ 重赛改分 → 再次暂定/终审
  → 旧奖项 pending_review → 新奖项版本发布
  → 展示草稿 → 队长授权 → 审核 → 发布
  → 队长申请技能提审（既有 DeveloperSkillService 闸门）
```

## 2 T59–T69 / T72 对照

| 用例 | 演练中的验证 | 结果 |
|---|---|---|
| T59 队伍权限 | 队员不能提交作品；队伍邀请/接受仅有效成员；非队长不能授权/申诉 | 通过 |
| T60 赛项隔离/冻结 | 快照冻结后队长移交被拒绝；成员快照仍为 2 人，不回溯覆盖 | 通过 |
| T61 作品/版本/私有材料 | 作品草稿→提交；附件私有（G3-05 专项 + 本演练链路） | 通过 |
| T62 赛程导入 | CSV 预览 1 行有效；导入 1 条新建；阶段发布 | 通过 |
| T63 精度/弃权/同分 | `88.50 + 10.25 = 98.75`（定点 9875）；tie=shared；缺赛/弃权在专项测试覆盖 | 通过 |
| T64 录分/发布分权 | 同一管理员录分后再发布被拒绝；不同复核人发布/终审成功 | 通过 |
| T65 申诉窗口/主体 | 队员申诉被拒绝；队长在窗口内提交；绑定当前公布版本 | 通过 |
| T66 证据/越权裁决 | `application/zip` 证据被拒绝；未受理不能裁决（专项测试）；裁决必须写理由与版本 | 通过 |
| T67 改分/终审/旧版本 | 旧公布版本保留且标 `superseded`；新版本重新暂定/终审 | 通过 |
| T68 旧奖项/展示关联 | 引用旧成绩的奖项转 `pending_review`；新奖项版本指向新成绩版本 | 通过 |
| T69 获奖作品授权/提审 | 未授权不能审核；队长授权后可发布；无开发者身份拒绝提审；有身份走既有闸门 | 通过 |
| T72 整届异常可回查 | 20 个关键 `operation_log` 动作全部存在；历史版本未删除 | 通过 |

## 3 回查字段

| 事实 | 回查位置 |
|---|---|
| 规则版本/指纹 | `competition_tracks.rules_version_id/rules_fingerprint`、`competition_entries` 快照 |
| 成绩版本 | `competition_result_versions`（旧 `superseded`、新 `final`） |
| 排名/并列 | `competition_result_versions.rank_no/tie_group` |
| 申诉结论 | `competition_appeals.decision_result_version_id` + `competition_appeal_events` |
| 奖项版本 | `competition_award_versions.result_version_id/status` |
| 展示授权 | `competition_showcases.consent_fingerprint/consent_at/consent_by_customer_id` |
| 技能提审交接 | `competition_showcases.skill_version_id/developer_handoff_at/handoff_result` |
| 操作审计 | `operation_log.action`（含创建/提交/发布/裁决/修订/授权等） |

## 4 演练明确未覆盖

- 真实选手、真实裁判、真实机器人、真机现场；
- 多浏览器/多进程并发报名、审核抢名额、成绩并发编辑；
- 短信/邮件/微信通知送达；
- CDN/对象存储撤稿缓存清理；
- 生产开关与逐赛事开通审批。
