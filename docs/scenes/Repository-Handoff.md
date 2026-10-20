# 场景流程仓库分支交接

日期：2026-10-20。用户已授权将本轮场景代码提交至 `xiaoiyanicloud-svg/summon-mecha`。

## 分支和范围

- 交接分支：`codex/scene-workflow-sc0-sc5`。
- 代码基线：`3fd8753c1299fc33062bbc2d275c44cc8d31f280`。
- 范围：SC0 真实性修复、SC1–SC5 首批代码、测试和实施文档；全部阶段的剩余项见[第 14 步](../skill-dev-step14.md)。这不是生产上线或全部阶段签收。
- 后续第二批代码/验证和剩余项见[第 15 步](../skill-dev-step15.md)，包含共享订单事务修复、报价退回/拒绝/对比、客户可见性、原载荷回执恢复、事件范围隔离及只读预检。始终 fetch 本分支最新 HEAD，不只取首批提交。
- 最新跨机复测和控制器继承签名修复见[第 16 步](../skill-dev-step16.md)。已取得生态分支合并提交 `2609992`，它只合入至首批 `546a6a0`；capabilities 已 200，但 schemas 404、未签发凭证 500 尚待运行环境核对。第二批必须包含后续 `rejectQuote` 修复及 15 项真实控制器装载/Router 检查。
- 不包含 `.env`、本机备份、截图、打包 ZIP 或临时 Git index。`docs/scenes/handoff/` 是本机生成的补丁交接目录，不纳入本次提交。
- `sc0-evidence`、`sc1-sc5-evidence` 中的 JSON/TAP 是生成时的验证快照。其中 `git_commit_created=false`、`pushed=false` 等字段记录验证发生时的状态，不代表仓库提交后的状态。

## 另一台电脑的合并约定

远端电脑经用户转交的当前 HEAD 为 `4afe73c8bf7c8f4eb1640a54e1e32599078c275a`，分支 `codex/ecosystem-g2-free-registration`。该提交不在本机对象库中，因此本次分支没有声称完成对它的合并或重放。

更新：Git fetch 后已取得该分支及 `4afe73c` 对象，最新观察到 `2609992`（父提交 `4afe73c`、`546a6a0`）。上段保留首批交接时的事实。本机尚未将整条生态分支合入当前工作分支，也未通过 HTTP 核验 8010 的实际 HEAD。请按第 16 步核对最新合并和测试环境。

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

第二批另新增 `2026_10_20_100004_add_scene_quote_decisions.php`，应接在前三份之后；仍未在实际 MySQL 执行。迁移仅按已明确的旧状态标记客户可见性，不猜旧 voided 历史。共享 `OrderRepository` 修复应作为独立变更审查，[复现证据及边界](Order-Transaction-Review.md)说明了本机 SQL 夹具与真实 MySQL 的区别。

## 新接口核对

`GET /api/scene-workflow/capabilities` 定义在 `Shop/src/Routes/scene-workflow.php`，由 `/api` 组加载；不要求登录或 CSRF，不受 `SCENE_*` 开关限制，也不查询新业务表。代码成功合并并注册路由后，即便所有开关为 false，也应返回 HTTP 200 与关闭的能力值。

跨机只读烟测已确认 TCP 8010、`/up`、活动列表和场景列表可达；当时新接口为 404。测试库身份来自用户转交的该机运行时实测，本机未通过 SSH 访问数据库，也没有执行业务写请求。详见[只读烟测记录](sc1-sc5-evidence/lan-smoke.json)。

## 给另一台电脑 AI 的最新联调交接

首批场景代码已推送至本页所列分支，首批代码提交为 `5f500a9290ef9758e352a7a9cc1c586b59a05f18`，推送时已核对 GitHub 分支提交与本地一致。后续批次见本页开头，不以首批提交代替最新 HEAD。提交不等于已合并到 `D:\main` 或已部署到 8010。本文是仓库内交接，未通过远程 shell 或另一台电脑的 AI 会话投递消息，也未收到对方读取确认。

2026-10-20 北京时间 22:32:53–22:32:55 再次只读实测：源地址 `192.168.3.96`，WLAN，`TcpTestSucceeded=True`；`GET /up`、`GET /api/events?per_page=1`、`GET /api/scenes` 均为 200，后两项为空数据；`GET /api/scene-workflow/capabilities` 仍为 404，响应为 `{"code":"NOT_FOUND","message":"请求的资源不存在。"}`。四次 curl 返回码均为 0，404 是实际 HTTP 状态。详见[复测记录](sc1-sc5-evidence/lan-smoke-recheck.json)。

请按以下顺序推进：

1. 先按上文 fetch、保留未提交文件并在隔离 checkout 合并真实 `4afe73c` 基线；核对已有生态共享底座，解决冲突后再继续。不要用本分支整目录覆盖对方工作区。
2. 在合并后的 Laravel 项目核对 `route:list --path=scene-workflow`，确认存在 `GET|HEAD api/scene-workflow/capabilities`。清理缓存并按实际运行方式重启 8010，再请求该接口。全关闭开关时也应 200；若仍为 404，请回传路由表、运行路径和响应，不用先开放写入口。
3. 从实际运行环境回传 `APP_ENV`、当前连接数据库名、Git HEAD 和六个 `SCENE_*` 开关值，不提供密码。网络烟测不能证明连接了哪个库。使用独立 `summon_mecha_scenes_test`，`APP_ENV=testing`；先确认迁移合并方案，之后才运行迁移与夹具脚本。不要对业务库 `root` 测试，也不要与既有测试并发占用同一库。
4. 按测试阶段准备客户、真实合资格商品、需求、证据、价目、逐台/逐人资源及测试角色。按需逐项开启本分支六个开关，不要为通过测试编造正式条款、税率、退款或扫描结果。缺少条款、商业政策或扫描器的功能应保持阻断并如实记录。
5. 环境和夹具准备好后，本机可通过 API 验证需求原文、作用域、幂等、修订、报价审批/接受、资源冲突响应及已具备夹具的交付动作。MySQL 多连接行锁、事务故障注入和 worker 故障恢复需由另一台本地执行脚本并回传证据，或建立明确授权的远程 shell；仅有 HTTP 不足以验证这些内部性质。不需要开放 MySQL 端口。
6. 订单转换仍未实现且硬阻断，不能通过开关开放。项目交付测试只能先使用明确标记的测试夹具，不能据此宣称真实订单到项目全流程完成。

请一次回传：合并后的分支/HEAD、实际运行项目路径、APP_ENV/数据库名、迁移状态、新路由表、开关值、capabilities 完整响应（脱敏 Cookie/token），以及使用的夹具编号、实际测试命令/退出码/失败信息和清理结果。若合并或迁移受阻，先回传具体差异或错误，不对共享测试库试错。

第二批已提供[只读预检脚本说明](../../my-bagisto-store/qa/scenes/README.md)。在实际 Laravel 隔离 checkout、正确测试库环境下执行 `php qa/scenes/preflight.php` 并回传 JSON；不要用 `--check-env-only` 冒充已核验数据库。该机 MySQL 正向执行、真实业务夹具和完整订单/并发故障注入仍待完成，不能据本机新通过的 40+30 项隔离测试开放转换。

## 已执行验证的边界

新增流程 PHP 27/27、SC0 后端回归 11/11、前端 28/28、12 个 Vue SFC、7 个新流程及 5 个目录浏览器夹具检查通过；PHP 语法 51 文件通过。源文件哈希与[验证快照](sc1-sc5-evidence/verification.json)一致。

这些证据不替代真实 MySQL 行锁/订单事务、Admin 角色 HTTP、HBuilderX H5/小程序、真机、扫描器或 worker/scheduler 常驻验收。
## 2026-10-21 schema 路由绑定修复

`GET /api/scene-workflow/schemas/{category}` 的 404 根因是 Bagisto 全局 `category` 路由模型绑定：`SubstituteBindings` 会把 `business_event` 当作 `Webkul\Category\Models\Category` 查询。现已把路由参数改名为 `{sceneCategory}`、控制器参数同步改名，公开 URL 不变。`8010` 实测 `GET /api/scene-workflow/schemas/business_event` 返回 `200 application/json`，控制器运行态测试新增参数名回归检查。
