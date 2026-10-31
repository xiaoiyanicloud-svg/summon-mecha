# SC4/SC5 最新提交同步记录（2026-10-22）

## 结论

远端 `codex/scene-workflow-sc0-sc5` 新提交 `a056dd6` 已同步到本地隔离集成分支
`codex/scene-workflow-integration`，合并提交为 `e1fbadf`。

本批同步内容为 SC0–SC5 的交付、SC4 变更/取消/售后、SC5 指标与 outbox 运维收尾；
同时包含迁移 `2026_10_22_100009_complete_scene_delivery_and_after_sales.php` 和完整证据。

## 合并后兼容修复

合并后的组合树多出生态分支的迁移
`2026_10_20_100001_create_ai_conversation_and_analytics_tables.php`。原场景预检和
SC5 测试按日期 glob 迁移文件，把这份 AI 迁移误算入“场景 9 份迁移”，导致
`SC5-06` 期望 `[9,10]` 失败。

已将以下位置改为只筛选文件名含 `scene` 的增量迁移：

- `my-bagisto-store/qa/scenes/preflight.php`
- `tools/tests/scene-workflow.php`
- `tools/tests/scene-sc5-cases.php`

不改变场景迁移集合，也不改变其他业务迁移。

## 复跑结果

| 验证 | 结果 | 证据 |
|---|---:|---|
| 场景 workflow（含 SC4/SC5/P1/P2） | 150/150 | [scene-workflow.json](sc45-sync-evidence-20261022/scene-workflow.json) |
| SC3 SQLite 专项 | 35/35 | [sc3-sqlite.json](sc45-sync-evidence-20261022/sc3-sqlite.json) |
| Node 请求/目录/预检/回执恢复/技能市场 | 50/50 | [node.tap](sc45-sync-evidence-20261022/node.tap) |
| 控制器与 Router | 17/17 | [controller-runtime.json](sc45-sync-evidence-20261022/controller-runtime.json) |
| Blade 模板 | 10/10 | [blade.json](sc45-sync-evidence-20261022/blade.json) |
| H5 + 微信完整编译 | 两端通过 | [build-verification.json](sc45-sync-evidence-20261022/build-verification.json) |
| SC5 独立 MySQL 运维演练 | 43/43 | [runbook-verification.json](sc45-sync-evidence-20261022/runbook-verification.json) |
| 完整迁移 | 256/256，无 pending | [migrations.json](sc45-sync-evidence-20261022/migrations.json) |

独立 MySQL 实例已正常关闭并删除本次数据库；证据记录
`business_database_touched=false`、`production_flags_changed=false`、`external_calls=false`。

## 边界

- 本结果证明本机组合树与独立 MySQL 运维演练可运行，不替代目标环境部署、正式供给、
  真实客户/Admin 签收、微信真机或生产常驻 worker/scheduler。
- 本轮未修改业务 `.env`、生产开关或 `D:/main` 的未提交改动。
- 集成分支尚未 push。
