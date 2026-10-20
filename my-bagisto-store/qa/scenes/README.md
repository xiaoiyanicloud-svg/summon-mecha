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
