# SC5 场景运营开发收尾与验证记录

更新日期：2026-10-22（Asia/Shanghai）。范围为 SC5-01–08 的当前实现、质量修复和本机隔离证据；生产运行容量、目标机器部署和业务签收仍须独立留证。

## 实现与验证落点

| 工作包 | 当前实现 | 实际落点 |
|---|---|---|
| SC5-01 | 漏斗、报价、交付、验收、财务、资源、售后与待办指标；需求相关口径排除测试和旧线索 | `SceneOperationsService::metrics`、`SceneFinancialMetrics::summarize` |
| SC5-02 | 待办按类型、状态、负责人和需求检索；分派/解决保留版本、活动和强审计 | `todoList`、`assignTodo`、`resolveTodo`；`scene_operation_todos` |
| SC5-03 | 消费领取、60 秒租约、最多 8 次领取、退避与受控重放；事件白名单含正价差应收事件 | `consumeOne`、`replay`；`business_outbox`、`business_event_receipts` |
| SC5-04 | 客户 DTO 限定公开字段，私有资料继续鉴权；统一异常返回稳定错误码和错误编号 | `SceneWorkflowSafety`、各场景 DTO/私有附件服务及既有审计通道；不声明存在额外通用脱敏 API |
| SC5-05 | 待办与运营写入先锁对象再校验 `row_version`，旧版本返回 409 | `SceneWorkflow::version`、`resolveTodo`、`assignTodo` |
| SC5-06 | 9 份场景增量迁移纳入测试预检；只读预检先检查环境和数据库身份 | `qa/scenes/preflight.php`、`scene-preflight.test.mjs` |
| SC5-07 | 全局、转换及运维写开关分别生效；保留原历史读取鉴权 | `SceneWorkflow::enabled`、`SceneOrderConversionService`、Scheduler 条件 |
| SC5-08 | 按实际命令和表结构编写运行手册；完整隔离应用实测命令、SQL 和恢复守卫 | [运维手册](SC5-Operations-Runbook.md)、`scene-operations-runbook.mjs`、`runbook-checks.php` |

## 已修复的指标与运维问题

- 初验率按 `project_id + item_key` 去重。两个项目使用同一标准并各通过一次为 `2/2=100%`；初验失败后复验通过仍不算初验通过。
- 收款包括已独立复核的报价凭证及项目后收款；冻结快照只是转换副本，不与原始凭证重复相加。同一报价/项目内复制凭证去重，不同项目的同名凭证分别计入。
- 总收款含押金，不等于收入。已核准应退、待复核建议、实际退款分账，净现金只扣实退。旧冻结总额和异额凭证暴露 `requires_reconciliation`，不假定已核账。
- `scene.project.receivable_increased` 已纳入真实消费者白名单，生产事件可消费，重投仅保留一份 receipt 和应收增量。
- 运维命令实际参数只有 `--consume`、`--sweep`、`--metrics`。`--sweep` 处理预留/待办/联系 SLA；到期 Outbox 租约由下一次消费领取接管。
- 重放复用 `SceneOperationsService::replay`，保留场景范围、已完成、有效租约及强审计守卫。运行手册不再要求裸 SQL 重置。

Admin 路由以 `admin.catalog.scene-workflow.` 命名，默认位于 `/admin/catalog/scene-workflow`。待办检索、分派/解决、重放及指标读取分别由实际 `AuthorizeSceneWorkflow::ACTIONS` 约束；不能使用未注册的 `scene.operations.*` 权限键。

## 当前验证结果

| 验证 | 结果 |
|---|---|
| 完整场景内存流程 | 150 项通过，含 22 项 P1、22 项 P2 回归 |
| SC3 SQLite 专项回归 | 35 项通过 |
| Node 测试（场景、请求、预检、回执恢复及技能市场） | 50 项通过 |
| 控制器/Router 烟测与 Blade 编译 | 17 项通过、10 个模板通过 |
| P1 前端交互与编译 | 17 个 SFC、26 项 Vue 交互无异常；H5、mp-weixin 完整编译成功 |
| 独立 MySQL 完整应用运维演练 | MySQL 5.7.30、218 份迁移全部执行；5 组只读 SQL、7 条 Artisan 命令与权限/恢复守卫共 43 项检查通过 |
| 恢复守卫 | 实际 Admin 角色及 Controller；真实数据库审计插入失败回滚；有效租约、已完成及非场景事件拒绝重放；恢复消费仅一份 receipt |

证据：[P1 修复报告](sc45-p1-fix-evidence/verification.json)、[P2 修复报告](sc45-p2-fix-evidence/verification.json)、[完整运维演练](sc45-p2-fix-evidence/runbook-verification.json)。报告中的 Git 状态是验证当时记录，最终同步见 Git 历史。

本轮运维演练未重新执行网络 HTTP/H5 全链路；SQLite 用例不证明 MySQL 并发。既有 SC3 完整 HTTP、Scheduler 和多进程证据仍见 [SC3 账本](SC3-Closeout-20261021.md)及[集成手册](SC3-Integration-Runbook.md)。

## 目标环境发布边界

部署沿用既有备份和发布流程，核对增量迁移状态、已批准商业/响应日历政策、设备资源资格与实际服务容量，再按需启用场景开关。应用已注册场景 Scheduler，主机运行 `schedule:run`，无需重复添加一份场景命令 Cron。

`qa/scenes/preflight.php` 仅允许独立测试库，不能充当生产巡检命令。生产只读检查使用 `scenes:operations` 和 `migrate:status`；具体告警、受控重放与停写恢复以 [运维手册](SC5-Operations-Runbook.md)为准。生产相近拓扑的 p95/p99 压测、微信真机、真实供给与正式运营签收未在本机验证中完成。
