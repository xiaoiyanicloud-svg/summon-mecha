# root 场景增量迁移证据（2026-10-21）

## 结论

本机 Laravel 当前数据库 `root` 已完成七份场景迁移，批次为 `48`。迁移均为新增表、可空列或带默认值列；核心业务表行数迁移前后不变。所有场景开关仍为 `false`。

## 迁移前检查

- 数据库：`root`，MySQL `5.7.26`。
- Laravel 连接引擎配置：`InnoDB ROW_FORMAT=DYNAMIC`。
- 迁移前核心数据：
  - `customers=9`
  - `orders=29`
  - `products=13`
  - `scenes=10`
  - `device_binding=3`
- 迁移前不存在任何 `scene_*` 业务表。
- `business_outbox`、`business_idempotency_keys`、`business_event_receipts` 均不存在，无重复建表风险。
- 七份场景迁移均为 `Pending`。

## 备份

- root：`backups/root-before-scene-20261021.sql`，1,987,384 bytes。
- 专用测试库：`backups/scenes-test-before-scene-20261021.sql`，492,737 bytes。
- 两份 dump 均包含 `-- Dump completed` 结束标记。
- 备份目录由 `.gitignore` 排除，未提交进 Git。

## 专用测试库演练

在 `APP_ENV=testing`、`DB_DATABASE=summon_mecha_scenes_test` 下执行：

```powershell
php artisan migrate --force
php qa/scenes/preflight.php
```

结果：

- `100005`、`100006`、`100007` 全部成功。
- preflight：`ok=true`。
- `missing_tables=[]`。
- `missing_columns=[]`。
- `non_transactional_tables=[]`。
- `pending_migrations=[]`。
- 所有必需唯一索引和 18 个关键路由均存在。
- 六个场景开关全部为 `false`。
- `writes_performed=false`。

## root 执行结果

执行：

```powershell
php artisan migrate --force
```

成功迁移：

- `2026_10_20_100001_create_scene_workflow_foundation`
- `2026_10_20_100002_create_scene_commercial_and_resources`
- `2026_10_20_100003_create_scene_delivery_and_operations`
- `2026_10_20_100004_add_scene_quote_decisions`
- `2026_10_20_100005_add_scene_offline_acceptance`
- `2026_10_21_100006_complete_scene_intake_and_publication`
- `2026_10_21_100007_complete_scene_assessment_and_proposals`

## 迁移后审计

- 核心数据仍为：`customers=9`、`orders=29`、`products=13`、`scenes=10`、`device_binding=3`。
- 新增场景和共享业务表共 36 张。
- 非 InnoDB 表：`[]`。
- 场景/共享业务表外键：46 个。
- 新增业务表当前总行数：`0`。
- 非空新表：`[]`。
- 必需新增列均存在。
- `php artisan ecosystem:contract-check`：通过。
- 场景相关路由：44 条。
- 六个场景开关仍全部关闭。

## 边界

本证据只说明本机 root 的结构迁移和只读 schema 审计完成，不表示真实 MySQL 多连接并发、完整 Admin 会话、HBuilderX H5/小程序、真实扫描器、worker/scheduler 常驻或真实业务流程已验收。回退时应先停止场景写入，保护新增事实表，再按备份恢复；不要执行 `migrate:fresh`、`truncate` 或 `seed`。