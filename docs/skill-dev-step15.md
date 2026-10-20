# 第 15 步：SC1–SC5 第二批开发与事务故障修复

日期：2026-10-20。基线：`546a6a0`，分支 `codex/scene-workflow-sc0-sc5`。状态：**本批代码及隔离验证完成，SC1–SC5 仍部分完成，未部署。** 承接[第 14 步](skill-dev-step14.md)，目标要求仍为[55 个工作包、68 组验收](scenes/Scene-Backlog-Acceptance.md)。

## 1 本批解决的问题

| 阶段/工作包 | 实际改动 | 验证边界 |
|---|---|---|
| SC1-05/06/08 | 以明确的 `customer_visible` 标记控制报价历史；未发送草稿即使作废也不对客户公开。补充需求的报价失效统一写版本、强审计/outbox 并释放 held；存在 confirmed 或项目时要求变更 | SQLite 真实服务/SQL；没有替代实际客户 HTTP、跨端或附件验收 |
| SC2-06/07/08 | 报价独立审核退回及原因，保留原版并新建重编；客户拒绝验证所有者、版本及两个 hash，幂等留事实并释放 held。新增已发送版本对比、金额差及范围/条款/时间变化；Admin 可查看决策原因 | 已跑正常、越权、过期版本、已接受拒绝、审计失败回滚及浏览器夹具；未完成专业能力/BOM/技能链和实际 Admin 角色 HTTP |
| SC3-05 | 修复共享 `OrderRepository` 的异常后 `finally` 提交和递归重试；使用 Laravel savepoint/Throwable 回滚及顶层并发错误有界重试 | 实际仓储方法 + SQL 依赖夹具；未验证真实 MySQL/完整生产监听器，因此 SC3-05/06 仍未签收 |
| SC4-04/07、SC1-08 | 报价、需求补充、项目验收/变更/取消/售后均保存未知回执的原载荷和键。刷新后仍可按原载荷重试；等待期间阻止冲突动作。复验通过关闭对应整改待办并保留初验失败 | 客户在线验收与隔离项目夹具；没有实现线下代录验收、商务变更生效、真实订单项目创建 |
| SC5-02/03/06 | 场景 worker 只租赁 scene.* 事件，校验类型/aggregate/schema/payload，重放和统计同样限定范围。拒绝/退回产生可处理重编待办，新版发送/需求修订后关闭，旧事件不重开；新增环境及结构只读预检 | 已验证本机故障/租约/receipt/范围隔离和预检环境拒绝；实际常驻进程、真实 MySQL 预检和恢复演练未做 |

订单仓储修复独立提交为 `e53970b`，详见[事务评审与复现](scenes/Order-Transaction-Review.md)。`SkillDelivery` 仅同步注释和格式，未更改既有授权、交付或失败补偿语义。

## 2 代码与迁移

- 新增 `SceneQuoteLifecycleService` 统一版本失效、held 释放、confirmed/项目保护；需求补充/关闭、新版报价发送均复用，审计失败连同状态和资源变更回滚。
- 新增第四份迁移 `2026_10_20_100004_add_scene_quote_decisions.php`：报价 `customer_visible`、`sent_at` 及客户历史索引；不可变 `scene_quote_decisions`，按 quote/kind 唯一。旧 sent/accepted/rejected 可确认曾发送，设为可见；旧 voided 不猜是否曾发送，`sent_at` 不伪造回填。没有修改前三份迁移。
- `SceneProposalService`：退回/拒绝/对比、公开 DTO 允许列表、费用编码重复拒绝、父需求锁下当前读分配版本。正式条款只返回版本/正文及商务政策版本，内部政策、成本和审核意见不对客户输出；客户自己的拒绝原因可查。
- 新接口：`POST /api/scene-quotes/{id}/reject`；`GET /api/scene-requests/{id}/quote-comparison?left_id=...&right_id=...`。沿用作用域、幂等和安全中间件。动态审核退回与批准同用独立 approve 权限，编制人不能自审/自退。
- `pages/scene/detail.vue`：拒绝原因、版本比较、错误/乱序响应处理和原载荷回执恢复；`project.vue`：验收及申请丢响应恢复；共用 `submitSceneCommand`，账号切换不清除旧账号待确认记录或写入新账号。
- `SceneOperationsService`：限定事件领域、类型和 schema；业务投影/receipt/processed 同事务，未知场景事件保留失败；不操作其他生态事件。新场景事件必须同步维护支持清单，不能被当成无操作事件直接确认。
- `qa/scenes/preflight.php`：显式测试库进程变量、配置缓存、实际环境及数据库名、四份迁移、表/列/关键 unique/InnoDB、路由、开关的只读核验。0 只代表结构检查，不能作为业务或并发验收。运行说明见[QA README](../my-bagisto-store/qa/scenes/README.md)。

迁移、业务夹具、开关、常驻进程均未在另一台电脑操作。没有访问业务库 `root`，没有业务写 API 请求，没有提供或索取数据库密码。

## 3 实测

证据：[本批验证汇总](scenes/sc1-sc5-batch2-evidence/verification.json)。记录 24 个修改源码/测试文件的 SHA-256，截图留在本机，不纳入提交。

| 检查 | 实测 |
|---|---|
| 场景后端真实迁移/服务/SQL/权限 | 40/40，通过新增第四份迁移、退回/拒绝/对比、审计回滚、预留释放/既有项目保护、投影恢复与事件隔离 |
| 真实 `OrderRepository::create` + SQL 夹具 | 修复前 4/30，修复后 30/30；覆盖 Exception/Error、外层回滚/提交、afterCommit 和有界并发错误重试 |
| SC0 后端回归 | 11 组通过 |
| Node | 35/35，包括 3 组预检环境拒绝/仅环境检查/help 参数验证 |
| Chrome headless + 实际 Vue 组件 | 12 个 SFC 编译、12 个交互检查，0 pageerror；含报价比较错误/乱序、拒绝重试和验收已提交丢响应后刷新恢复 |
| PHP 语法 | 17 个修改/新增 PHP 文件通过 |
| Blade | 2 份指令编译及生成 PHP 语法通过；没有实际 Admin 布局、会话或角色 HTTP 渲染 |
| 格式/差异 | 指定生产 PHP 按项目 pint.json 格式检查；git diff --check |

主要命令：

```powershell
php -d extension=pdo_sqlite tools/tests/order-transaction.php
php -d extension=pdo_sqlite tools/tests/scene-workflow.php
php -d extension=pdo_sqlite tools/tests/scene-backend.php
node --test tools/tests/scene-catalog.test.mjs tools/tests/scene-request.test.mjs tools/tests/skill-market.test.mjs tools/tests/scene-preflight.test.mjs
node tools/tests/scene-workflow-ui.mjs
php tools/tests/scene-blade-compile.php
```

本机 Node 预检子进程测试默认 PHP 为 `D:/java/php/php.exe`；其他机器可设置 `SCENE_PHP` 为实际 PHP 路径。所有数据库验证均为硬隔离 SQLite `:memory:`，没有加载业务 `.env`。真实 MySQL 行锁/事务、完整监听器、真实客户端网络和小程序构建不在这些通过结果中。

## 4 下一阶段及未完成

1. 另一台先审查合并实际 `4afe73c` 基线与共享生态底座；保留其未提交文件，单独审查共享订单仓储修复及其普通租买/技能/赛事回归。随后在专用 `summon_mecha_scenes_test` 审核迁移并执行正向预检。
2. SC3-05：真实 MySQL 多连接、RR 旧快照/父锁、锁超时/死锁及完整 `OrderRepository` 库存/授权/监听器故障注入；只有这些通过后才能开发并准入自动转换。**当前转换仍未实现且硬阻断，不创建订单或生产项目。**
3. SC1 的九类专业表单、扫描器/跨端附件/保全、工作日历与升级；SC2 的专业硬筛选、技能资产失效/BOM/AI；SC3 的人员工作日历/SKU 映射/完整来源防绕过；SC4 的商务变更/取消生效、事故链、线下验收/自动结案；SC5 的常驻进程、完整迁移/恢复/容量和真实供给签收仍未完成。

## 5 回退

修改前的 22 个既有文件保存在 `.claude/backup/scene-sc1-sc5-batch2-20261020/restore-manifest.json` 指定路径；新增文件由 Git 提交清单辨识。未部署时只恢复本批明确文件，不全仓 reset、不 git add -A。

已存在新业务事实时，先关闭相应 SCENE_* 写入口并保持修复后的权限、客户历史查询及新表，不运行迁移 down，不删除决策/原始验收，不退回会公开未发送草稿的旧 DTO。confirmed 资源不能被扫描或拒绝流程释放。真实环境回退/恢复尚待演练。
