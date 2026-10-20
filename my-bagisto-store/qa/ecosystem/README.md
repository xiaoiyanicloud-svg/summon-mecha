# 生态赛事 / 资讯 · QA 演练脚本（Q-01 ~ Q-05）

这些脚本不在请求链路里，也不会被应用自动加载。它们用于**可复核的演练**：
构建门禁、真实并发、真实 worker、真实浏览器、迁移与生命周期。

> ⚠ 所有写库脚本都经过 `bootstrap.php` 的硬校验：
> `APP_ENV` 必须是 `testing`，`DB_DATABASE` 必须以 `_test` 结尾。
> 不满足立刻 `exit(2)`，不执行任何 SQL。**永远不要把这里指向 root 库。**

## 目录

| 包 | 入口 | 说明 |
|---|---|---|
| Q-01 | `php artisan ecosystem:contract-check` | 路由 / ACL / 错误码 / 状态词表 / OpenAPI 双向核对；失败即非零退出 |
| Q-02 | `qa/ecosystem/q02/run.php` | 专用库硬校验、两进程容量竞争、两进程行锁等待、REPEATABLE READ 快照 vs 当前读 |
| Q-03 | `qa/ecosystem/q03/run.php` | afterCommit/回滚、真实 outbox worker、重复投递、崩溃租约、25 条积压、failed 补偿 vs dead 终态 |
| Q-04 | `qa/ecosystem/q04/run.mjs` | 真实 Chrome（CDP）：客户 / 队长 / 裁判 / 超管；375/390px、键盘 Tab、错误分支、403 |
| Q-05 | `qa/ecosystem/q05/run.php` | 只读数据 preflight、计数对账、留存 dry-run、expand/backfill/switch/recovery |

## 运行

```bash
cd D:/main/my-bagisto-store

# Q-01（不需要数据库）
php artisan ecosystem:contract-check

# Q-02 / Q-03 / Q-05
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q02/run.php --json=D:/main/docs/ecosystem/q02-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q03/run.php --json=D:/main/docs/ecosystem/q03-result.json
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing php qa/ecosystem/q05/run.php --json=D:/main/docs/ecosystem/q05-result.json

# Q-04（Windows：需要 Chrome 与已导出的 H5）
# 先导出 H5：D:/HBuilderX/cli.exe publish web --project D:/main
node qa/ecosystem/q04/run.mjs --json=D:/main/docs/ecosystem/q04-result.json
```

Q-04 会自动：

1. 在测试库创建夹具（活动 / scope / 赛项 / 队伍 / 客户 / 后台账号）；
2. 启动独立测试库的 Laravel（8010）与 H5 静态站点（8099）；
3. 用 CDP 驱动 Chrome 完成客户报名、队长邀请、裁判 403、超管列表；
4. 关闭服务并清理夹具，截图写入 `docs/ecosystem/q04-screenshots/`。

## 证据

每个入口都输出机器可读 JSON（无密钥、无完整 PII）：

- `q02-result.json`：7 项检查（guard / 容量竞争 / 锁等待 / RR 快照 / 清理）
- `q03-result.json`：10 项检查（afterCommit / worker / 去重 / 租约 / 积压 / 补偿）
- `q04-result.json`：18 项检查（含 9 张截图索引）
- `q05-result.json`：9 项检查（preflight / 对账 / 留存 / 迁移恢复）

## 纪律

- 不修改已执行迁移伪装升级；迁移演练只创建 `qa_q05_*` 探针并在结束时删除。
- 失败时先看 JSON 里的 `checks[].detail`，不要直接重跑覆盖现场。
- 任何一步发现方向偏差，停下来重新对齐，不要在错误脚本上继续打补丁。
