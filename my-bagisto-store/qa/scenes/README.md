# 场景联调只读预检

此目录不包含密码，不提供通过 HTTP 执行脚本的入口，不会自动迁移、建库、清库或创建业务夹具。

合并后先在仓库根目录执行 `php tools/tests/scene-controller-runtime.php`（15 项、无业务 bootstrap/数据库），确认真实控制器父子类可装载及表单路由可分发。它已捕获并修复第二批 `reject` 方法与基类同名签名冲突；必须使用 handler 已改为 `rejectQuote` 的最新版本。该检查不替代下面的真实环境预检。

先在另一台电脑的隔离 checkout 合并 `codex/scene-workflow-sc0-sc5` 与实际生态分支，核对共享 outbox/幂等和迁移冲突。准备专用 `summon_mecha_scenes_test`，不要占用已有 `summon_mecha_step10_test`，不要使用业务库 `root`。

在该机 Laravel 项目目录执行：

```powershell
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'summon_mecha_scenes_test'
php qa/scenes/preflight.php
```

脚本在业务 bootstrap 前检查显式进程变量和已缓存配置，bootstrap 后再次核对实际应用环境、连接配置及 `SELECT DATABASE()`。仅查询表/列、迁移记录、关键 unique、InnoDB 引擎、路由和功能开关，不输出连接串或密码。失败退出码：结构/路由未齐为 1，环境拒绝或执行失败为 2；0 只说明这些结构检查通过，不代表业务全流程、订单事务或并发验收通过。

可先检查进程变量，但此模式不会核验实际数据库：

```powershell
php qa/scenes/preflight.php --check-env-only
```

本机只执行了环境拒绝和 `--check-env-only` 自动化验证；该机真实 MySQL 正向预检尚待执行。不要把本机 SQLite 夹具结果写成远端 MySQL 已通过。

五份场景迁移应依次为 `2026_10_20_100001` 至 `100005`；迁移必须在双方审查过共享基础设施和独立测试库配置后，由该机执行。`100004` 只把旧状态明确为 sent/accepted/rejected 的报价标为客户可见，不猜测旧 voided 是否曾发送，也不捏造 `sent_at`。`100005_add_scene_offline_acceptance` 增加客户线下凭证/复核表及 nullable 验收实际时间/来源关联；不猜测回填旧实际时间，down 拒绝删除事实。新代录/复核 ACL 为 acceptance_record、acceptance_review，仍受项目写开关控制。

第三批隔离验证和两角色线下确认联调要求见[第 17 步](../../../docs/skill-dev-step17.md)。`php -d extension=pdo_sqlite tools/tests/scene-workflow.php` 固定内存数据库、独立临时私有文件，并包含 Admin Blade 内容渲染、故障回滚和权限直调；它不加载业务 .env，不冒充真实 MySQL/完整 Bagisto Admin 会话。`php qa/scenes/preflight.php` 已包含第五份迁移和新表/列/unique/路由检查；仅 --check-env-only 通过仍不证明迁移已完成。

请回传分支/HEAD、运行路径、预检 JSON、`GET /api/scene-workflow/capabilities` 响应（脱敏 Cookie/token）。然后再准备场景夹具与阶段开关，按[仓库交接](../../../docs/scenes/Repository-Handoff.md)继续联调。订单转换仍未实现，不能通过开关开放。

## SC1 / SC2 收尾批次（2026-10-21）

另一端交接提交 8c7d660 报告已切至专用 summon_mecha_scenes_test、执行到 100004；本轮 GET 确认未签发凭证返回 401，不再是缺表 500。上述旧结果只记录当时状态。当前预检要求 **七份** 场景迁移，新增 `2026_10_21_100006`、`100007`；尚待该机实际执行 100005–100007 后重新预检。检查政策/勘察/方案/保全表、引用列、唯一约束、新路由及批准政策状态；预检不会自动迁移或批准政策。

操作顺序：

1. 在隔离 checkout 合并本分支最新提交，保留该机未提交前端文件；不要整目录覆盖。schema 参数必须是 sceneCategory，公开 URL 不变。
2. 明确环境为 APP_ENV=testing / DB_DATABASE=summon_mecha_scenes_test，停止占用此库的其他测试；先查 migrate:status，按顺序执行待迁移，保持 SCENE_* 关闭。绝不对 root 或 step10_test 测试。
3. 运行 `php qa/scenes/preflight.php` 并回传 JSON。0 只表示表/路由就绪，不代表业务链验收。准备真实测试客户及两名不同 Admin，按动作授予新增分权。
4. 打开工作台政策页，仅在测试库填写明确测试主体/渠道及两名测试负责人，录入实际测试日历并独立批准；正式环境必须换成真实主体。草案不会自动生效。使用真实安全扫描器，不能将 `tools/tests/fixtures/scene-scanner.php` 配为生产扫描器。
5. 按[第 18 步](../../../docs/skill-dev-step18.md)验证：受理原回执/认领/补充；上传超时原键恢复与跨主体拒绝；勘察原件/来源；任务依据双人复核；最新评估→BOM→方案独立批准→定点报价→独立批准/发送→客户比较/接受/拒绝。变时段/证据到期/熔断/下架后旧报价必须拒绝，已有接受事实走变更。
6. 用两连接分别演练同版本编辑、同幂等键上传/受理、最新评估与旧报价接受、证据撤销/机型或固件更新与报价、保全与删除意图竞争。确认同事务强审计失败无半记录；SQLite 不证明这些 MySQL 性质。保留实际命令、连接隔离级别、锁等待、返回码及清理结果。
7. HBuilderX H5/小程序验证 choice/decimal/false/unknown、旧表单升级、直接链接、切换账号、原回执恢复、PDF/PNG/JPEG 下载预览和冻结范围/验收。现有 Chrome 组件证据不替代此步骤。
8. 清理由测试执行人按本次夹具确切 ID/原件键完成，禁止 truncate 或 migrate:fresh。政策/签署/审计事实按测试留存要求归档，不把测试素材作为正式供给。原件清理命令默认只读：`php artisan scenes:attachments <ID>`；只有明确到期且无保全等阻挡时，显式 `--apply --policy-version=retention-vN` 执行。

本机自检命令在仓库根目录执行：`php -d extension=pdo_sqlite tools/tests/scene-workflow.php`、`php tools/tests/scene-controller-runtime.php`、`node --test tools/tests/scene-request.test.mjs tools/tests/scene-catalog.test.mjs tools/tests/scene-preflight.test.mjs`。这些脚本硬隔离，不 bootstrap 业务库；不要将内存套件改成连接测试 MySQL 后直接运行，故障触发器及文件夹具不是 MySQL 通用脚本。
