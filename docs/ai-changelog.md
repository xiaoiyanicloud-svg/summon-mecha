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

### [基线] 2026-09-06 · 机制建立
- 动作：git init（main 分支，core.autocrlf=false 保证字节级保真）+ 本 .gitignore + 本文件；**未修改任何现有文件**。
- 排除入库：两个 Bagisto 的 `vendor/`、`storage/`，根目录 `unpackage/`、`node_modules/`、`.env*`（.env.example 除外）、`.claude/backup/`。
- 基线意义：此后所有 AI 改动都可 diff、可回滚到此点。
