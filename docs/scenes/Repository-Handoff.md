# 场景流程仓库分支交接

日期：2026-10-20。用户已授权将本轮场景代码提交至 `xiaoiyanicloud-svg/summon-mecha`。

## 分支和范围

- 交接分支：`codex/scene-workflow-sc0-sc5`。
- 代码基线：`3fd8753c1299fc33062bbc2d275c44cc8d31f280`。
- 范围：SC0 真实性修复、SC1–SC5 首批代码、测试和实施文档；全部阶段的剩余项见[第 14 步](../skill-dev-step14.md)。这不是生产上线或全部阶段签收。
- 不包含 `.env`、本机备份、截图、打包 ZIP 或临时 Git index。`docs/scenes/handoff/` 是本机生成的补丁交接目录，不纳入本次提交。
- `sc0-evidence`、`sc1-sc5-evidence` 中的 JSON/TAP 是生成时的验证快照。其中 `git_commit_created=false`、`pushed=false` 等字段记录验证发生时的状态，不代表仓库提交后的状态。

## 另一台电脑的合并约定

远端电脑经用户转交的当前 HEAD 为 `4afe73c8bf7c8f4eb1640a54e1e32599078c275a`，分支 `codex/ecosystem-g2-free-registration`。该提交不在本机对象库中，因此本次分支没有声称完成对它的合并或重放。

在另一台电脑先读取分支并审查差异：

```powershell
git fetch origin codex/scene-workflow-sc0-sc5
git log --oneline 3fd8753..origin/codex/scene-workflow-sc0-sc5
git diff --stat 3fd8753 origin/codex/scene-workflow-sc0-sc5
```

保留该机未提交修改，在隔离 checkout 中与实际 `4afe73c` 基线合并、验收后再整合；不要整文件覆盖 `D:\main`。

具体需要核对：

1. 该机未提交的 `pages/mine/index.vue` 与本批服务需求入口重叠，需逐行合并。`components/EcoCenter.vue`、`components/SkillMarket.vue` 本批没有修改。
2. `pages/ai/index.vue`、`utils/api.js`、Provider、路由、ACL 和 schedule 与该机后续生态/AI 提交分别核对。
3. 先对照已有 Q-02/Q-03 及 outbox/幂等/receipt 实现，合并共享底座，再决定迁移方案，避免建立等价的第二套。
4. 三份新增迁移尚未在实际 MySQL 环境执行。建议独立 `summon_mecha_scenes_test`，不要与其他测试并发使用同一库，不对 `root` 运行迁移、清库或种子。
5. 新开关默认关闭；正式条款、必需费用或扫描器缺失时相关写入保持阻断。订单转换未实现，不能通过开关开放。

## 新接口核对

`GET /api/scene-workflow/capabilities` 定义在 `Shop/src/Routes/scene-workflow.php`，由 `/api` 组加载；不要求登录或 CSRF，不受 `SCENE_*` 开关限制，也不查询新业务表。代码成功合并并注册路由后，即便所有开关为 false，也应返回 HTTP 200 与关闭的能力值。

跨机只读烟测已确认 TCP 8010、`/up`、活动列表和场景列表可达；当时新接口为 404。测试库身份来自用户转交的该机运行时实测，本机未通过 SSH 访问数据库，也没有执行业务写请求。详见[只读烟测记录](sc1-sc5-evidence/lan-smoke.json)。

## 已执行验证的边界

新增流程 PHP 27/27、SC0 后端回归 11/11、前端 28/28、12 个 Vue SFC、7 个新流程及 5 个目录浏览器夹具检查通过；PHP 语法 51 文件通过。源文件哈希与[验证快照](sc1-sc5-evidence/verification.json)一致。

这些证据不替代真实 MySQL 行锁/订单事务、Admin 角色 HTTP、HBuilderX H5/小程序、真机、扫描器或 worker/scheduler 常驻验收。
