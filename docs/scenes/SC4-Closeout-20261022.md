# SC4 交付、验收、变更与售后开发收尾

更新日期：2026-10-22（Asia/Shanghai）。本页同步当前实现与质量修复结果，覆盖 SC4-01–09 的本机开发和隔离回归，不能替代现场交付、生产容量或运营签收。

## 实现落点

| 工作包 | 当前行为 | 实际落点 |
|---|---|---|
| SC4-01 | 从冻结任务模板展开一次；核验依赖、检查清单和状态迁移 | `SceneProjectService::initializeTasks`、`completeTask` |
| SC4-02 | 人工交付完成记录包含本需求证据、实际时间和平台录入时间；不允许越过前置任务 | `scene_project_tasks`、`SceneProjectService::completeTask` |
| SC4-03 | 客户申请、价差/排期评估、独立审核、客户接受或拒绝；应用时重新核验资源资格、班次、绑定及缓冲占用冲突 | `evaluateChange`、`reviewChange`、`customerAcceptChange`、`applyChangeDirectly` |
| SC4-04 | 按冻结标准逐项验收；初验失败保留，整改和复验追加；线下凭证代录后独立复核 | `SceneAcceptanceService::online`、`recordOffline`、`reviewOffline`；`scene_acceptance_records` |
| SC4-05 | 返库留证并检查部件、外观和损坏争议；晚归或损坏维持维护状态；不自动退款 | `SceneProjectService::returnResources`、`recordCommercial`、`reviewCommercial` |
| SC4-06 | 只使用客户接受时冻结的取消政策；计算应退责任，区分既有应退和实退；外场设备保留占用，可在取消后实际返库 | `processCancellation`、`SceneCancellationPolicy`、`scene_commercial_records` |
| SC4-07 | 平台服务和技能故障分流；技能必须匹配本客户、本项目技能子单及冻结版本的有效正式授权 | `SceneSkillAfterSaleService`、既有 `AfterSaleService::policyFor`；技能退款继续走 D10 |
| SC4-08 | 事故暂停项目、记录事故号、实物转维护并建待办；任务推进核验技能熔断；恢复项目不自动解除设备维护或争议 | `reportIncident`、`resumeProject`、`completeTask` |
| SC4-09 | 终态变更守卫、价差应收、事务与强审计回滚、后台表单契约、客户未知回执恢复 | `scene-sc4-cases.php`、`scene-sc45-p1-cases.php`、`scene-sc45-p2-cases.php`、`scene-project-recovery.test.mjs` |

当前差量应用覆盖金额和排期。涉及地址、机型或技能 BOM 变化时仍须核验完整的新方案与资源，不能把任意 `requested_change` JSON 当成已生效履约内容。

## 关键约束

- 变更评估人不能自行审核，返回 `SCENE_REVIEW_SELF_FORBIDDEN`；客户决策为 `accept` 或 `decline`。所有写入校验 `row_version`，未知回执恢复使用原接口、原载荷及原幂等键。
- 取消费率先由独立人员批准商业政策，再随报价条款及 hash 冻结并展示给客户。没有冻结政策时返回 `SCENE_CANCELLATION_POLICY_MISSING`，不回退到当前政策或写死的费率。
- 取消扣罚基数为冻结政策约定的已复核总收款。新增应退责任为“总收款－冻结政策扣罚－既有已核准应退”；实际退款是原应退责任的结清事实，不再扣第二次。待复核应退及冲突账目先阻断自动结算。
- `refund_due` 是待独立财务复核的责任记录，不能声称已退款。技能工单仅记录技术支持结果；技能退款由既有 D10 售后申请、审核和退款事实源处理，场景入口不能绕过。
- 已取消且未返库的实物保持 maintenance。返库校验证据及实际时序，保留原维护缓冲并关闭相应返库待办；不自动恢复 ready，也不清除损坏争议。
- 改期锁定资源父行后使用当前资格和班次核验；失败回滚客户决定、金额及预留。成功保存新核验事实和原安排历史。

## 路由与权限

以下为默认 Admin 前缀；配置前缀变化时以路由名生成 URL。路由均保留 Admin 会话、CSRF 及场景授权守卫。

| 路径 | 路由名后缀（前缀 `admin.catalog.scene-workflow.`） | 权限（前缀 `catalog.scene-workflow.`） |
|---|---|---|
| `POST /admin/catalog/scene-workflow/projects/{id}/changes/{changeId}/evaluate` | `change-evaluate` | `assess` |
| `POST /admin/catalog/scene-workflow/projects/{id}/changes/{changeId}/review` | `change-review` | `approve` |
| `POST /admin/catalog/scene-workflow/projects/{id}/changes/{changeId}/cancel-execute` | `cancel-execute` | `approve` |
| `POST /admin/catalog/scene-workflow/projects/{id}/incident` | `incident` | `deliver` |
| `POST /admin/catalog/scene-workflow/projects/{id}/resume` | `resume` | `deliver` |
| `POST /admin/catalog/scene-workflow/service-tickets/{id}/resolve` | `ticket` | `support` |

客户变更决定接口为 `POST /api/scene-projects/{id}/changes/{changeId}/decision`，继续校验对象所有权、版本与幂等键。

## 验证与交付边界

最新质量结果见 [P1 修复证据](sc45-p1-fix-evidence/verification.json)和 [P2 修复证据](sc45-p2-fix-evidence/verification.json)。报告中的 Git 状态为该次验证执行时状态，实际同步以 Git 提交历史为准。

| 验证 | 结果与边界 |
|---|---|
| `php -d extension=pdo_sqlite tools/tests/scene-workflow.php` | 150 项通过，包含 22 项 P1 和 22 项 P2 回归；真实迁移及服务、SQLite 内存事务 |
| `php -d extension=pdo_sqlite tools/tests/scene-sc3.php` | 35 项专项回归通过；不作为 MySQL 并发证明 |
| Node 测试（场景、请求、预检、恢复及技能市场） | 50 项通过 |
| 控制器/Router 烟测、Blade 编译 | 17 项通过、10 个模板通过；另有真实详情表单渲染、角色权限矩阵及 Controller 提交检查 |
| 前端交互与完整构建 | P1 修复时 17 个 SFC、26 项真实 Vue 交互通过；H5 和 mp-weixin 使用 HBuilderX 编译器完整构建成功 |
| 完整应用运维验证 | 独立 MySQL 5.7.30、218 份迁移、43 项运维验证通过；见 P2 报告与运维手册 |

新增迁移 `2026_10_22_100009_complete_scene_delivery_and_after_sales.php` 只扩展项目、变更和工单字段，`down()` 拒绝删除历史事实。业务库迁移、正式收款、现场交付、生产压测及微信真机验收需在目标环境独立执行；本页未将其标为完成。停写和恢复步骤见 [SC5 运维手册](SC5-Operations-Runbook.md)。
