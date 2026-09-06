# AI 协作变更日志（有痕修改机制）

> 规则：AI 的所有修改必须有痕、可追溯、可一键恢复。
> 机制三层：① git 基线 + 小步提交（恢复主体）；② `.claude/backup/<任务号>/` 文件级双保险；③ 本日志（人读的记录）。

## 恢复速查

| 想做什么 | 命令（在 D:\main 执行） |
|---|---|
| 查看每一步历史 | `git log --oneline` |
| 回滚到某个验收点 | `git reset --hard <提交hash>`（会丢弃其后所有改动，执行前 AI 必须先说明会丢什么） |
| 回到改造前基线 | `git reset --hard <基线hash>` |
| 看某步具体改了什么 | `git show <提交hash> --stat` |
| 只恢复某个文件 | `git checkout <提交hash> -- <文件路径>` |
| 文件级兜底备份 | 手动从 `.claude/backup/<任务号>/` 覆盖回来 |

## 提交纪律

- 每完成一个用户验收通过的小步 = 一次 commit，提交信息写明改了什么、为什么。
- 任务开工前，将待改文件原样复制到 `.claude/backup/<任务号>/原相对路径`。
- 涉及 `storage/`（上传图片）或 `.env` 等不入库文件的操作，必须先做文件级备份。

## 变更记录

### [T1-1] 2026-09-06 · 机器页性能：前端缓存
- 提交：c8bfdde
- 文件：`pages/robot/index.vue`、`components/jiqi/changjing.vue`
- 内容：目录数据 5 分钟模块级缓存（onShow 高频触发不再全量重拉）；移除 onShow 里的 enrichProductCards 调用（mock 列表已不渲染，纯浪费 0.85s）；loadBackendScenes 加 5 分钟 TTL。
- 根因实测：artisan serve 单进程排队，原 7 请求关键路径 4.4s。
- 回滚：`git revert c8bfdde` 或用 `.claude/backup/T1-perf/` 内原文件覆盖。

### [T1-2] 2026-09-06 · 机器页性能：后端聚合端点
- 文件：新增 `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/CatalogController.php`；`Routes/api.php` 加 `/api/tob-catalog` 路由；`utils/api.js` 加 catalogApi；`robot/index.vue` loadCatalog 聚合优先、失败自动回退旧多请求逻辑。
- 数据层与原生 `/api/products` 一致（ProductRepository::getAll + ProductResource），前端映射零适配；scenes 段复用 SceneController。
- 实测：首屏关键路径 4.4s → **1.1s**（1 个请求 29.7KB）；配合缓存，切回机器页 0 请求秒开。后台改商品 5 分钟内生效，急用刷新前端即可。
- 回滚：`git revert <本提交>`；新控制器可直接删除，前端自动走回退逻辑。

### [基线] 2026-09-06 · 机制建立
- 动作：git init（main 分支，core.autocrlf=false 保证字节级保真）+ 本 .gitignore + 本文件；**未修改任何现有文件**。
- 排除入库：两个 Bagisto 的 `vendor/`、`storage/`，根目录 `unpackage/`、`node_modules/`、`.env*`（.env.example 除外）、`.claude/backup/`。
- 基线意义：此后所有 AI 改动都可 diff、可回滚到此点。
