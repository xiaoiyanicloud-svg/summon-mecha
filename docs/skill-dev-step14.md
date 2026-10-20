# 第 14 步：SC1–SC5 首批实现与跨机只读烟测

日期：2026-10-20。状态：**部分实现，未整体验收，未部署。** 用户随后已授权提交仓库，分支与合并约定见[仓库交接](scenes/Repository-Handoff.md)。基线承接[第 13 步](skill-dev-step13.md)，完整目标仍以[55 工作包/68 组验收](scenes/Scene-Backlog-Acceptance.md)为准。

## 1 实际落地

| 阶段 | 本批代码 | 仍未完成 |
|---|---|---|
| SC1 | 发布 revision/指针、分权审批、表单/验收/履约模板快照；需求/补充/加密联系资料；服务端游客能力、认领、对象权限；共享幂等/强审计/outbox；旧 custom 需求适配；四个客户页面、Admin 工作台、私有附件后端 | 真实 Admin/客户 HTTP 全链；专业表单补齐；附件真实扫描/客户端跨端操作/保留政策；工作日历、提醒与升级；版本差异和完整编辑体验 |
| SC2 | 保守确定性检查、具体设备人工任务证据；版本化价目、整数金额/税/折扣/押金分列；报价送审/独立批准/发送/作废、hash 接受；客户报价详情 | 九类专业约束、技能版本/资产/熔断失效链、完整 BOM 依赖替代、报价拒绝/对比、AI 草稿接入；商业条款待业务配置 |
| SC3 | 逐台资源、具体 binding 引用；UTC/时区/缓冲、父行锁预留、到期即时语义；资源暂停影响待办 | **真实 MySQL 并发、OrderRepository 故障注入、订单转换/SKU 映射未完成**；订单转换为明确拒绝服务，不创建订单或项目；人员工作日历/行程与多地域规则未完成 |
| SC4 | 从冻结模板生成依赖任务、检查与私有证据；逐项验收/整改/复验；返库检查；线下收款/应退/实退的独立复核与限额；变更/取消申请、服务售后处理 | **项目确认依赖未完成的 SC3 转换；当前测试项目是隔离夹具**。变更/取消生效、资源差量、政策费用、事故/技能证据链、线下代录验收、自动结案未完成 |
| SC5 | outbox 租约、receipt、重试/重放；待办投影、资源过期扫描、指标口径；只读 operations 命令及 opt-in schedule；默认停写、历史可查 | 实际进程/通知/值守、MySQL 故障恢复与容量报告、存量回填/影子对照、恢复演练和真实供给签收均未完成 |

没有将这批代码标为 SC1–SC5 全部完成。技术契约详见 [Scene-Workflow-Contract-v1.md](scenes/Scene-Workflow-Contract-v1.md)。

## 2 代码落点与迁移

- `my-bagisto-store/config/scenes.php`：新开关均默认关闭，商务条款/费用政策/扫描器默认为空；现场人工履约模板注册表。
- `Shop/src/Services/Scenes/Scene{Workflow,Principal,Publication,Request,Assessment,Proposal,Supply,Reservation,Project,Attachment,Operations}*`：实际事务及守卫；`SceneOrderConversionService` 仅硬阻断，不能算完成转换。
- `Shop/src/Database/Migrations/2026_10_20_100001*` 至 `100003*`：三份 expand 迁移；新需求/商业/资源/交付/可靠性表及场景发布指针；未在本机业务库或远端执行。
- `Shop/src/Http/Controllers/API/SceneWorkflowController.php`、新路由/安全中间件：统一错误和私有 DTO。`TobOrderController` 不再为 custom 创建锚点零元单。
- `Admin/src/Http/Controllers/Catalog/SceneWorkflowController.php`、动作鉴权、ACL/menu、两份 Blade：受理、联系、评估、核价、审核、资源、交付及款项工作台。旧场景停用同样清指针，历史引用阻止删除，版本引用的旧图保留。
- `pages/scene/{request,requests,detail,project}.vue`、`utils/scene-request.js`、`utils/api.js`：客户流程与待确认重试；首页、机器页、场景详情和“我的”已接入口。
- `Shop/src/Console/Commands/SceneOperations.php`、`bootstrap/app.php`：默认只读报告，显式 outbox/扫描；未启动常驻服务。

新表含外键和唯一约束，binding/order/customer/product 的引用类型按现有源迁移核对；不能把 SQLite 执行成功等同 MySQL 迁移验证。`operation_log` 沿用现有强审计入口，不修改原弱审计语义。

## 3 本机实测及证据边界

证据目录：[sc1-sc5-evidence](scenes/sc1-sc5-evidence/verification.json)。最终计数以该汇总文件为准。

```powershell
node --test tools/tests/scene-catalog.test.mjs tools/tests/scene-request.test.mjs tools/tests/skill-market.test.mjs
php -d extension=pdo_sqlite tools/tests/scene-workflow.php
php -d extension=pdo_sqlite tools/tests/scene-backend.php
node tools/tests/scene-workflow-ui.mjs
node tools/tests/scene-ui-check.mjs
php tools/tests/scene-blade-compile.php
git diff --check
```

隔离 PHP 测试不加载 `.env` 或 Laravel 业务 bootstrap，硬断言 SQLite `:memory:`；真实执行新增迁移、服务、SQL、validator、路由和动作鉴权。通过审计表 trigger 注入失败验证回滚。交付/财务用测试项目与 clean 附件元数据夹具，不是真实订单转换或病毒扫描验收。

浏览器使用已有 Chrome headless、真实 Vue 3 组件和隔离 API 夹具，12 个 SFC 编译、需求表单/失败保留/幂等重试/详情/空态/验收页检查。没有 HBuilderX 或本项目完整构建依赖，**没有 H5/微信小程序构建验收**。Blade 仅编译指令并做 PHP 语法检查，没有真实布局、登录会话或角色 HTTP 页面验收。

## 4 2026-10-20 跨机网络与 API 烟测

实际时间约 22:15（Asia/Shanghai）。源 `192.168.3.96`，目标 `http://192.168.3.97:8010`。未改 VPN、防火墙、路由或 `.env`，使用 `curl.exe --noproxy "*"`。

| 检查 | 实测 |
|---|---|
| `Find-NetRoute -RemoteIPAddress 192.168.3.97` | WLAN / InterfaceIndex 17 / 源 192.168.3.96 / 192.168.3.0/24 / NextHop 0.0.0.0 |
| `Test-NetConnection 192.168.3.97 -Port 8010` | TcpTestSucceeded=True；PingSucceeded=False 不影响本次 TCP 成功 |
| `GET /up` | HTTP 200；PHP/8.4.24；正文为框架 Application up，**没有数据库身份信息** |
| `GET /api/events?per_page=1` | HTTP 200 application/json，data=[]，meta.total=0 |
| `GET /api/scenes` | HTTP 200 application/json，data=[] |
| `GET /api/scene-workflow/capabilities` | HTTP 404 / NOT_FOUND；远端没有暴露本批新接口 |

只读烟测已成功。随后用户转交远端 AI 的实测结果：`APP_ENV=testing`、当前连接 `summon_mecha_step10_test`、MySQL `127.0.0.1:3306`。这是**远端执行后经用户转交的证据，本机 HTTP 响应没有返回库名，尚非本机独立核验**。未做订单/资源写入或 MySQL 测试。无 SSH 通道，未探测 3306，未访问 `root` 业务库，也未发送测试写请求。HTTP GET 可能由远端框架创建匿名 session，不代表创建业务夹具。

远端说明中的三份文件不存在于本工作区；用户已转交关键内容。远端 HEAD 为 `4afe73c8bf7c8f4eb1640a54e1e32599078c275a`，分支 `codex/ecosystem-g2-free-registration`，MySQL 5.7.26；本机该提交对象不存在，本机仍在 `main / 3fd8753c1299fc33062bbc2d275c44cc8d31f280`。远端已有生态专项 Q-02/Q-03 和共享可靠性代码，需要先对照复用，不重复运行或另建等价底座；这些证据不能签收本批未合并的场景代码。远端当前未提交的 `pages/mine/index.vue` 与本批两行需求入口重叠，必须手工合并；`EcoCenter.vue`、`SkillMarket.vue` 本批没有修改。

## 5 远端合并与验收顺序

1. 先取得远端代码/迁移基线、已有共享 outbox/幂等实现和三份质量文档。新接口 404 表明代码尚未同步，不得直接覆盖另一专项改动。
2. 只在独立测试库、串行执行本批测试。启动环境必须 `APP_ENV=testing`、`DB_DATABASE=summon_mecha_step10_test`，bootstrap 后再次断言实际连接库名；配置缓存不能被环境变量想当然覆盖。
3. 本批仅新增迁移，不需要 `migrate:fresh`、truncate 或重跑全库 seed。业务库 `root` 禁止这些动作。本机已知测试库为空不代表可任意清库。
4. 测试环境显式开放 publication/requests；建立真实渠道、商品、客户和 Admin 角色夹具，再走版本→需求→评估→报价。正式商务条款没有冻结时报价保持阻断。
5. 先审查既有 Q-02/Q-03；只补缺失的场景资源父锁、空档 RR 当前读、多资源回滚、订单各节点注入和监听器事务测试。测试必须由远端 shell 执行或交给该机 AI，不通过公开 API 开后门执行脚本。
6. SC3 转换开发和验收通过前不启用自动订单/项目；SC4 不能用手工改库的项目冒充真实链路。之后再做实际 Admin、H5/小程序、扫描器、worker/scheduler 和正常/异常项目全链。

## 6 回退

本轮修改前的既有文件备份在 `.claude/backup/scene-sc1-sc5-20261020/`，`restore-manifest.json` 为明确路径清单；保留 SC0 修改，不做全仓 reset。新文件清单在证据汇总中。

```powershell
$sceneBackupRoot = Join-Path (Get-Location) '.claude/backup/scene-sc1-sc5-20261020'
$sceneRestorePaths = Get-Content -LiteralPath (Join-Path $sceneBackupRoot 'restore-manifest.json') -Raw | ConvertFrom-Json
foreach ($sceneRestorePath in $sceneRestorePaths) {
    Copy-Item -LiteralPath (Join-Path $sceneBackupRoot $sceneRestorePath) -Destination (Join-Path (Get-Location) $sceneRestorePath) -Force
}
```

未部署时可按清单恢复源码并单独审阅新增文件。若已产生新需求/报价/项目，**仅停写并保持新表、历史查询和兼容代码**，不能直接回退为旧 custom 零元建单或运行迁移 down。生产回退需要另一台环境的实际备份/恢复演练；本轮未做。
