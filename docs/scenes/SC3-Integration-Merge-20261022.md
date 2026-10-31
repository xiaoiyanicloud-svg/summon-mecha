# SC3 最新提交合并与完整复跑（2026-10-22）

## 结论

远端 SC3 收尾提交 `68df6da26515603e6c926218a2258074098e560f` 已合入隔离分支
`codex/scene-workflow-integration`，并在合并生态分支后的实际组合树上完成复跑。

本次不是只读拉取：解决了三处合并冲突，并修复了两处组合后才会暴露的验收兼容问题。

## 合并范围

- 远端分支：`codex/scene-workflow-sc0-sc5`
- 提交：`68df6da`（`feat(scenes): complete SC3 resources and transaction closeout`）
- 合并前集成 HEAD：`2ab79da`
- 当前工作目录：`D:/main-scene-integration`
- 未触碰主工作区 `D:/main` 的未提交文件

冲突处理原则是双方功能全保留：

| 文件 | 处理 |
|---|---|
| `.gitignore` | 同时保留 AI 日志/任务规则和 SC3 私有运行目录规则 |
| `ShopServiceProvider.php` | 保留生态命令、SC3 `SceneOperations` 和附件留存命令 |
| `pages/ai/index.vue` | 保留 AI 会话分析、场景需求入口及双方 API import |

## 合并后修复

| 问题 | 原因 | 修复 |
|---|---|---|
| SC3 seed 假失败 | 合并后存在两个含 `100008` 的迁移，旧守卫用 `%100008%` 模糊匹配 | 改为精确匹配 `2026_10_21_100008_complete_scene_resources_and_transactions` |
| 真实 H5 请求被测试代理拦截 | SC3 代理只认旧本地端口 `8008`，当前生态分支使用 `8000` | 验收代理同时接受 `8000` / `8008`，产品配置不变 |

浏览器运行环境另行区分：HBuilderX 编译使用 Node `18.20.0`；Playwright 浏览器验收要求 Node 20+，本轮使用本机 Node `25.2.1`。

## 完整结果

| 验证 | 结果 | 证据 |
|---|---:|---|
| H5 + 微信完整编译 | 两端通过 | [build-verification.json](sc3-merge-evidence-20261022/build-verification.json) |
| 完整 Laravel/Bagisto 集成 | `ok=true` | [integration-verification.json](sc3-merge-evidence-20261022/integration-verification.json) |
| 完整迁移 | 255/255，无 pending | [migrations.json](sc3-merge-evidence-20261022/migrations.json) |
| HTTP / Admin / CSRF / ACL / Sanctum | 107/107 | [http-checks.json](sc3-merge-evidence-20261022/http-checks.json) |
| worker 故障与恢复 | 8/8 | [worker-checks.json](sc3-merge-evidence-20261022/worker-checks.json) |
| runtime / Scheduler | 10/10 | [runtime-checks.json](sc3-merge-evidence-20261022/runtime-checks.json) |
| 真实编译 H5 浏览器 | 6/6，0 page error | [h5-checks.json](sc3-merge-evidence-20261022/h5-checks.json) |
| SC3 SQLite 专项 | 35/35 | [sc3-sqlite.json](sc3-merge-evidence-20261022/sc3-sqlite.json) |
| 既有场景流程 | 91/91 | [scene-workflow-sqlite.json](sc3-merge-evidence-20261022/scene-workflow-sqlite.json) |
| 控制器实际装载与路由 | 17/17 | [controller-runtime.json](sc3-merge-evidence-20261022/controller-runtime.json) |
| Node 场景契约 | 34/34 | [node.tap](sc3-merge-evidence-20261022/node.tap) |

独立 MySQL 实例已完成初始化、迁移、测试、正常关闭和本次数据库删除；证据记录
`business_database_touched=false`、`production_flags_changed=false`，
未调用外部通知、设备或真实支付通道。

## 边界

- 本结果证明本机组合树可运行，不替代目标业务环境部署、真实运营供给、Admin 实操签收、微信真机或生产 worker/scheduler 常驻。
- SC3 转换开关仍默认关闭；没有因本轮验证修改业务 `.env` 或生产配置。
- `D:/main` 当前仍有用户的未提交微信配置与技能市场改动，未纳入本合并。
