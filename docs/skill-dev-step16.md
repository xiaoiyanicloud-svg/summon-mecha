# 第 16 步：跨机连通复测与控制器运行时修复

日期：2026-10-20。前置代码为 `6097d01`；当前仍为部分实现、未整体验收。本次只有只读 HTTP 请求、本机源码修复和无业务库测试，没有远端业务写请求、迁移或开关修改。

## 1 实际连通与版本

源 `192.168.3.96` 经 WLAN 到 `192.168.3.97:8010`，`Test-NetConnection` 的 `TcpTestSucceeded=True`。2026-10-20 23:11:18–23:11:20（北京时间）用 `curl.exe --noproxy '*'` 实测：

| 请求 | 实际结果 |
|---|---|
| `GET /up` | 200 |
| `GET /api/scene-workflow/capabilities` | **200**，此前为 404；requests/quotes/projects/publication_v2/attachments/order_conversion 均 false |
| `GET /api/events?per_page=1` | 200，空 data，total=0 |
| `GET /api/scenes` | 200，空 data |

Git fetch 已取得另一台的 `codex/ecosystem-g2-free-registration` 分支，HEAD 为 `260999217fa8bd1ad376e12ad73b1fa9a441c665`。其两个父提交为 `4afe73c` 和 `546a6a0`，说明**首批场景代码已经合并入生态分支**。该树尚未包含 `e53970b`、`6097d01` 或第二批 QA 预检脚本。这是仓库事实，不能代替对 8010 实际 checkout/HEAD 的运行时确认。

已从该分支实际读取 `docs/ecosystem/HANDOFF.md`、`Q-01-05-quality-evidence.md` 和 `qa/ecosystem/README.md`。其中“未 push”等文字是旧状态，应以当前 Git 事实为准；其生态 Q-02/Q-03 结果仍不能证明场景新代码通过 MySQL 验收。

## 2 只读接口检查结果

10 项补充检查有 6 项符合目标预期，4 项尚未符合。完整数据见 [lan-readonly.json](scenes/lan-integration-evidence/lan-readonly.json)。

- 无凭证读取需求列表、需求详情、项目详情、私有附件均为 401 / `SCENE_ACCESS_REQUIRED`，没有返回私有资料。
- 0 和超过路由范围的需求 ID 均为 404，没有触发 TypeError。
- `GET /api/scene-workflow/schemas/enterprise` 实际 404，目标 200；非法类别实际也是 404，目标为有路由的 422。**Git 中该路由存在，本机真实控制器/Router 分发也通过；当前不能把远端 404 直接归因为功能代码、功能开关或迁移。需要核对运行路径、路由缓存、route:list 和实际源码。**
- 版本对比接口实际 404；该接口不在 `2609992` 树中，需合入第二批后再测。
- 使用语法合法但未签发的合成凭证读取需求列表，目标为 401 / `SCENE_ACCESS_INVALID`，实际为 500 / `SERVER_ERROR`。原因尚未定位；需核对实际测试库、场景迁移和脱敏的异常类/错误码。没有把它当作认证通过，也没有据此继续写入。

本次未通过 HTTP 核验当前 APP_ENV/库名，不能因 capabilities 为 200 就认定正确测试库、迁移和夹具均已就绪。未提供登录凭证、未直接连接 MySQL、未请求运行 shell 的 HTTP 后门。

## 3 发现并修复本机第二批控制器签名冲突

核对真实父类时发现：`APIController` 已有 protected `reject(string $code, string $message, array $extra=[]): JsonResponse` 响应辅助方法，第二批 `SceneWorkflowController` 新增同名 public 动作但签名不同。PHP 在实际装载控制器时会发生 fatal，退出码 255。此前的 `php -l`、服务/SQL 测试和 Vue 夹具没有装载该父子类，漏掉了此问题；它们不能作为完整应用 HTTP 通过的证据。

修复为动作方法 `rejectQuote`，路由 handler 同步修改，**公开 URI `POST /api/scene-quotes/{id}/reject` 不变**，保留父类响应辅助方法。尚未核验另一台部署此修复。

新增 `tools/tests/scene-controller-runtime.php`，实际装载场景 API/Admin 控制器和父类、验证路由方法公开可调用，并通过 Laravel Router 分发 capabilities、九类表单、非法类别及未登录私有请求。使用真实响应工厂与场景服务；不加载 `.env` 或业务 bootstrap，任何数据库解析都会被测试硬拒绝。因此它验证控制器/路由运行时，不声称完整 Bagisto 应用或真实 MySQL 已通过。

```powershell
php tools/tests/scene-controller-runtime.php
php -d extension=pdo_sqlite tools/tests/scene-workflow.php
```

实测分别 **15/15**、**40/40**，见 [controller-runtime.json](scenes/lan-integration-evidence/controller-runtime.json) 和 [workflow-regression.json](scenes/lan-integration-evidence/workflow-regression.json)。两个修改生产 PHP 按项目 Pint 配置检查通过，新测试脚本语法通过。上批证据保留原生成时快照，不能用其源码哈希替代本次修复版本。

## 4 给另一台 AI 的可执行交接

1. fetch 场景分支最新 HEAD，保留既有未提交文件，合并 `e53970b`、`6097d01` 及本次 `rejectQuote` 修复；不要只停在 `546a6a0`。共享订单仓储独立审查，不覆盖整个目录。
2. 在实际 8010 项目路径回传 `git rev-parse HEAD`、工作区差异（仅文件名，排除环境文件内容）和 `php artisan route:list --path=scene-workflow`。确认 schemas 路由及 handler 存在；如有缓存，按实际运行环境清理并重启服务，再复测 enterprise/非法类别。
3. 运行上述无业务库控制器测试，必须 15/15；再于专用 `summon_mecha_scenes_test`、APP_ENV=testing 下执行 `php qa/scenes/preflight.php`，回传 JSON、实际 APP_ENV/数据库名。该脚本在第二批中，`2609992` 没有它。不要将 `_step10_test` 共享库临时当作场景专用库。
4. 对合成未签发凭证的 500，先记录该次请求对应的**异常类和脱敏错误码**；不回传密码、SQL、连接串、原始客资或堆栈。数据库身份与迁移确认前不准备写入夹具。
5. 环境、迁移和路由齐备后，按阶段准备独立夹具及开关，再开展跨机读写/权限/幂等流程。订单转换仍未实现且硬阻断，真实 MySQL 并发/完整监听器故障注入仍需本机执行脚本并回传证据。

## 5 回退

修改前的控制器、路由和交接文档在 `.claude/backup/scene-lan-runtime-20261020/restore-manifest.json` 指定路径。该修复不涉及数据库；尚未部署时可按明确路径恢复，但会重新引入继承签名 fatal。出现远端问题优先保持场景写开关关闭、保留历史查询和业务事实，不 down 表、不清库，不退回有已知缺陷的控制器。
