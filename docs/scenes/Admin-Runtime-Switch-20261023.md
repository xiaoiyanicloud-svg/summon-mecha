# 后台运行切换到 SC0–SC5 集成分支（2026-10-23）

## 当前运行态

- URL：`http://127.0.0.1:8000/admin/login`
- 服务目录：`D:\main-scene-integration\my-bagisto-store`
- 代码分支：`codex/scene-workflow-integration`
- 提交：`66c23df674f037cd4efe087c4155cd035e85addc`
- 数据库：`.env` 中 `root`，本机 MySQL
- 未触碰 `D:\main` 的未提交前端改动

## 已执行

1. 在集成分支执行 additive migration：
   - `2026_10_21_100008_complete_scene_resources_and_transactions`
   - `2026_10_22_100009_complete_scene_delivery_and_after_sales`
2. 清理缓存并重置现有管理员登录密码（密码不写入仓库）。
3. 用备用端口完成登录页、管理员登录、场景工作台访问验证。
4. 停止旧 8000 服务，改为从集成分支启动新的 8000 服务。

## 验证

- `/admin/login`：200
- 管理员登录后跳转 `/admin/dashboard`：成功
- `/admin/catalog/scene-workflow`：200
- 真实 Chrome 登录并打开场景工作台：成功，无页面错误
- 截图：`.claude/backup/admin-scene-live-20261023/scene-workflow-admin.png`

## 回退

旧服务来自 `D:\main\my-bagisto-store`。如需回退，停止当前 8000 进程，再从旧目录启动相同 PHP built-in server。两份迁移为 additive，不应 down 或删除表。

## 边界

本次只切换本机运行态和本机 root 数据库迁移；没有 push、没有改动远程仓库、没有改动生产配置。
