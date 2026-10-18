# 开发进度与计划交接索引

日期：2026-10-18。面向接手开发的同事：本索引汇总本仓库（召唤机甲 / 机器人生态技能平台）截至当前的全部开发计划、交付验收文档与状态结论。计划与验收正文都在 `docs/` 下，本文件只做导航与状态速览，细节以各文档为准。

## 1 仓库结构

- 前端：uni-app（Vue3，Options API），仓库根目录即前端工程（`pages/`、`components/`、`utils/`、`static/` 等），H5 用 HBuilderX 编译。
- 后端：`my-bagisto-store/`，Bagisto / Laravel 模块化单体，MySQL，database 队列 + scheduler。
- 辅助脚本与测试工具：`tools/`；部署说明见根目录 `AI-DEPLOY.md`、`NAVIGATION.md`。

## 2 总路线图

- `docs/skill-platform-research-2026-10-18.md`：技能生态行业调研与实施路线图（行业来源、机型候选、代码缺口、分卡路线 A1 → A1b → A3 …）。所有步骤卡以此为准排优先级。

## 3 技能主线进度（按完成顺序）

| 阶段 | 文档 | 状态结论 |
|---|---|---|
| Step2–Step9 | `skill-dev-steps.md`、`skill-dev-step2-3.md`、`skill-dev-step4.md` … `skill-dev-step9.md`、`skill-dev-step9-closure-plan.md` 等 | 历史各步的计划与记录，细节见各文件 |
| Step10 | 计划 `skill-dev-step10-local-improvements-plan.md`；交付验收 `skill-dev-step10.md` | 2026-10-18 交付：优惠券样例清理、支付与交付前置、有限补偿、激活次数、真实下载并发、开发者浏览器六组改进；本地验收完成 |
| Step11（路线卡 A1） | `skill-dev-step11.md` | 修复技能搜索的供给资格与当前发布版筛选，新增 15 条行为测试；首页/机器页默认列表独立数据入口列为 A1b 后续处理 |
| Step12（路线卡 A1b） | `skill-dev-step12.md` | 技能市场"真实供给 → 详情 → 购买 → 下架"闭环，涉及 23 个代码与测试文件；后端 76 项测试、前端 9 项测试、两进程撤回竞态、H5 与接口回归通过 |

Step12 复验命令（Pest 联合回归 + 前端 `node --test`）见 `docs/skill-dev-step12.md` 第 4 节末尾。

## 4 生态赛事活动线（方案已完成，业务功能未开发）

文档在 `docs/ecosystem/`：

- `Ecosystem-Events-Content-Plan.md`：完整产品技术方案。
- `Ecosystem-Engineering-Backlog.md`：工程任务及验收矩阵，已拆成 59 个工程工作包、80 类验收场景（可信发布、活动运营、完整赛事、工程保障四条线）。
- `Ecosystem-Current-Audit.md`：现状审计与证据；另有 `Ecosystem-Frontend-Audit.md`、`Ecosystem-Backend-Audit.md` 只读审计和 `Ecosystem-Official-References.md` 官方实践研究。

状态：审计与实施方案完成，业务功能尚未改动；首期建议"免费报名 + 平台编辑发布"，80 类验收属于后续开发门槛。

## 5 接手前必读的现状提醒

- 截至 2026-10-18，以上各步代码改动**保留在本地工作区，未提交 Git、未发布生产**；代码基线 `c4d2eade149a647694a9b047778fcbb012c61a5a` 加工作区改动。接手后先 `git status` 核对，勿随意 reset/clean。
- Step12 修改前文件备份在仓库 `.claude/backup/skill-step12-20261018/`；回滚脚本 `rollback-skill-step12.ps1` 当前在仓库外（Codex 会话交付目录 `C:\Users\Administrator\Documents\Codex\2026-10-18\ni\outputs\`），默认只输出计划，加 `-Apply` 才执行回滚。
- 已知遗留：原生商品 `CategoryProductTest` 价格升/降序两项断言失败属既有问题，未修复，不能宣称全仓测试全绿；历史下载测试的瞬时 404 未定位。
- 未做事项：真实支付、小程序验证、规模与故障压测、Elasticsearch 等价过滤、真实厂商技能包与真机适配验证。
- 下一优先级：路线卡 A3"可解释的机型与产物适配证据"；生态线 59 个工作包待排期。
- 所有测试样本只在隔离测试库；真实业务库无演示技能、开发者或厂商数据。

## 6 仓库外材料位置（如需归档可再拷入）

- Step12 验收证据（截图、日志、结果 json）：`C:\Users\Administrator\Documents\Codex\2026-10-18\ni\outputs\技能供给闭环验收\`。
- Step10 验收证据与本地启停脚本：`C:\Users\Administrator\Documents\Codex\2026-10-17\ni-h\outputs\`。
- 上述目录中的计划文档正文均已同步进本仓库 `docs/`，哈希一致。
