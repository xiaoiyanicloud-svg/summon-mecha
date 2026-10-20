# AGENTS.md · 本仓库 AI 协作入口

任何 AI/Agent 接手本仓库，先按顺序读：

1. [CLAUDE.md](CLAUDE.md) —— 不可谈判的定位、决策红线、工程纪律（对所有 AI 生效，不限 Claude）。
2. [docs/ecosystem/HANDOFF.md](docs/ecosystem/HANDOFF.md) —— 生态赛事与资讯当前进度、开关状态、未完成项、验证命令。
3. [docs/ai-project-brief.md](docs/ai-project-brief.md) —— 项目理解手册。
4. 当前任务相关批次证据：`docs/ecosystem/*-evidence.md`、`*-implementation-plan.md`。

## 当前状态（2026-10-20）

- G0 / G1 / G2 / G3-01~12 / G4-01~09 / **Q-01~Q-05** 代码与本机验证完成。
- **Q 横向质量包**：契约门禁、真实并发、真实 worker、四角色浏览器演练、数据 preflight 与迁移/生命周期演练；证据见 `docs/ecosystem/Q-01-05-quality-evidence.md`，入口见 `my-bagisto-store/qa/ecosystem/README.md`。
- 构建门禁：`php artisan ecosystem:contract-check`（路由/ACL/错误码/状态/OpenAPI）与只读 `php artisan ecosystem:data-preflight`。
- **所有 G4 新开关默认关闭**：搜索、收藏、关联、SSR、sitemap、指标、内容访问、运营台。
- 报名写闸、赛务写闸、公开赛程/成绩/展示仍关闭；首场赛规未签署。
- root 已做 additive 迁移；备份见 HANDOFF §4。
- 验证必须指向独立库 `summon_mecha_step10_test`；禁止对 root 执行 `migrate:fresh` / `truncate` / `seed`。
- **Git 红线**：`my-bagisto-store2 备份/` 会让 git 认为 2739 个已跟踪文件被删除。禁止 `git add -A`，只按路径 `git add`。
