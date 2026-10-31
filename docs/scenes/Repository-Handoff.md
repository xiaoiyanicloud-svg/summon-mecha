# 场景流程仓库分支交接

最新开发增量（2026-10-21）：[SC3 收尾账本](SC3-Closeout-20261021.md)与[证据](sc3-closeout-evidence/verification.json)。资源核验/排期、真实 MySQL 并发及仓储故障、报价原子转单、技能拆单/授权时序、线下商务双审和旧订单入口已接通。新增 100008，预检现要求八份迁移；转换默认关闭。以下早期“转换未实现”及测试数保留历史语境，当前启用/迁移以最新账本和 QA README 为准。本批未部署或迁移另一端业务环境。

剩余联调补齐：[完整集成运行手册](SC3-Integration-Runbook.md)提供一条命令重跑真实完整应用、EAV、Admin 会话/CSRF、权限矩阵、HTTP 转单、消费者失败/中断恢复、Scheduler 与实际 H5，运行器自建实例并校验所有权后清理。最新完整证据位于 `sc3-closeout-evidence/integration/`。只读证据一致性检查为 `node tools/tests/scene-sc3-verify-evidence.mjs`；目标地址/连接方式与实际运营签收不能由本机结果代替。

日期：2026-10-20。用户已授权将本轮场景代码提交至 `xiaoiyanicloud-svg/summon-mecha`。

## 分支和范围

- 交接分支：`codex/scene-workflow-sc0-sc5`。
- 代码基线：`3fd8753c1299fc33062bbc2d275c44cc8d31f280`。
- 范围：SC0 真实性修复、SC1–SC5 首批代码、测试和实施文档；全部阶段的剩余项见[第 14 步](../skill-dev-step14.md)。这不是生产上线或全部阶段签收。
- 后续第二批代码/验证和剩余项见[第 15 步](../skill-dev-step15.md)，包含共享订单事务修复、报价退回/拒绝/对比、客户可见性、原载荷回执恢复、事件范围隔离及只读预检。始终 fetch 本分支最新 HEAD，不只取首批提交。
- 最新跨机复测和控制器继承签名修复见[第 16 步](../skill-dev-step16.md)。已取得生态分支合并提交 `2609992`，它只合入至首批 `546a6a0`；capabilities 已 200，但 schemas 404、未签发凭证 500 尚待运行环境核对。第二批必须包含后续 `rejectQuote` 修复及 15 项真实控制器装载/Router 检查。
- 第三批开发见[第 17 步](../skill-dev-step17.md)：线下验收凭证代录/独立复核与客户来源展示、履约时间/阶段约束、返库风险影响及待办。新增 `2026_10_20_100005_add_scene_offline_acceptance.php`、acceptance_record/acceptance_review 两项 ACL；须在专用测试库完成增量迁移并以两个真实 Admin 角色联调。本批没有远端部署、迁移或新数据库身份验证。
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
## 2026-10-21 专用场景测试库与 401 联调复核

- `8010` 当前运行环境为 `APP_ENV=testing`、`DB_DATABASE=summon_mecha_scenes_test`。
- 专用库已完成 `2026_10_20_100001` 至 `100004` 场景迁移，并完成 Bagisto 安装基础 seed；`qa/scenes/preflight.php` 返回 `ok=true`，0 pending，表/列/唯一索引/路由齐全。
- `GET /api/scene-requests` 无凭证返回 `401 SCENE_ACCESS_REQUIRED`。
- `GET /api/scene-requests` 带格式合法但未签发的 64 位凭证返回 `401 SCENE_ACCESS_INVALID`，不再返回 500。
- 之前的 500 根因是 8010 曾连接未包含 `scene_guest_capabilities` 等场景表的旧测试库，不是把缺表异常吞成 401；专用库切换后已恢复正确语义。
- 六个场景开关仍全部关闭；`POST /api/scene-workflow/access` 当前返回 `503 SCENE_WRITES_DISABLED`，完整业务写入仍需按场景逐项开启并准备夹具。

## 2026-10-21：已读取另一端交接与 SC1 / SC2 收尾代码

已 fetch 另一端 `8c7d660`（之前为 2ab79da），读取 `f1e1ee2` / `8c7d660`：schema 的 `{category}` 与 Bagisto 全局模型绑定冲突，已按同一修复改为 `{sceneCategory}`，公开路径不变；另一端报告 8010 为 `APP_ENV=testing`、`summon_mecha_scenes_test`，迁移至 100004。旧 500 来自当时运行库未有场景表，不能吞成 401。本机最新 GET-only 20 项通过，未签发凭证为 `401 SCENE_ACCESS_INVALID`；目录空导致详情跳过，未对远端执行写入。

本轮 SC1/SC2 开发、表单/API/后台接通和本机验证详见[第 18 步](../skill-dev-step18.md)、[执行账本](SC1-SC2-Closeout-20261021.md)、[实际技术契约](Scene-Workflow-Contract-v1.md)。本分支包含第三批 a9e5192 及本轮增量；不要只取 78b0629 或旧交接中首批 SHA。政策草案、现场依据、BOM、技能任务依据和附件保全不是自动 seed，不在 root 写数据。

给另一端的具体下一步：

1. fetch `codex/scene-workflow-sc0-sc5` 最新提交，在隔离 checkout 合并；保留 EcoCenter、SkillMarket、mine 等本机未提交内容，不整文件覆盖，不 git add -A。若交接文档发生尾部冲突，保留双方日期说明；sceneCategory 修复两边等价，不能恢复 category 参数名。
2. 按更新后的 `my-bagisto-store/qa/scenes/README.md` 审查并在专用测试库执行待执行的 100005、100006、100007。之后 `optimize:clear`、实际服务重启、重新 preflight；七份迁移、新表/列/唯一约束及路由齐全后才准备写入演练。
3. 两个不同 Admin 完成草案、独立审核/复核及退回，客户完成受理/附件回执/表单修订/方案比较和报价决策。先配测试环境所需实际主体标识、联系渠道、负责人、日历及扫描器；正式库必须是真实主体及授权依据。不能把 fixture 扫描器作为生产杀毒扫描器。
4. 运行新版本 MySQL 多连接、完整 Admin 会话和 HBuilderX H5/小程序验收并回传脱敏结果；本机 91 后端、16 控制器、32 Node、14 SFC/17 交互不替代这些步骤。原件清理需显式单 ID 和批准政策版本，默认只读。

回传实际运行 HEAD/路径、APP_ENV/数据库身份、迁移/preflight JSON、角色与夹具标识、命令/退出码、失败信息和精确清理结果。SC3 转换仍硬阻断，SC4 变更/取消未自动生效，不因本轮条款草案而开放。本页是已写入仓库的交接，未声称对方已经读取、部署或完成新批次验收。

## 2026-10-21：SC1 / SC2 代码提交已推送并复核

本批代码提交为 [`41db1b2ef17fa8a12ea4a216baee1595b2e5483c`](https://github.com/xiaoiyanicloud-svg/summon-mecha/commit/41db1b2ef17fa8a12ea4a216baee1595b2e5483c)，包含 82 个文件的代码、测试、契约、进度和交接。已用 `git ls-remote` 再次核对 GitHub 同名分支指向该提交；本次补充说明前无未提交的已跟踪代码。之后的文档同步提交继续保留在同一分支，合并时取该分支最新 HEAD。

```powershell
git fetch origin codex/scene-workflow-sc0-sc5
git show --stat 41db1b2ef17fa8a12ea4a216baee1595b2e5483c
git log --oneline 78b0629..origin/codex/scene-workflow-sc0-sc5
```

早期 [13/14 只读探测](lan-integration-evidence/2026-10-21-api-probe.json)一并归档，它记录当时未签发凭证请求得到 500 的真实失败及 GitHub 暂时不可达；后续 [20 项通过的只读复测](sc1-sc2-closeout-evidence/remote-readonly.json)已经记录恢复。早期文件不是当前运行状态，也未证明新版本业务写入或实际数据库身份。

给另一端的执行顺序及待回传清单仍按上一节。尚无对方合并 `41db1b2`、执行 100005–100007 或完成新批次验收的回执；不据“已上仓库”推定这些动作完成。本次仅更新文档和归档历史记录，没有变更业务代码或重新运行已通过的代码测试。原验证快照保留其生成时的源文件哈希，后续文档补充不冒充原测试的重跑结果。
