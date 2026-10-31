# 回传 192.168.3.97 的八项核对信息

生成时间：2026-10-20T14:25:59.122Z。这是可审查补丁，不是已合并或已部署版本。没有访问共享测试库的写入口。

## 1 本机分支和 HEAD

- 工作目录：D:\CodexDeploy\summon-mecha
- 分支：`main`
- HEAD：`3fd8753c1299fc33062bbc2d275c44cc8d31f280`
- 未创建提交、未 push。真实 Git index 保持原样。

## 2 补丁基线

补丁基线为本机 `3fd8753c1299fc33062bbc2d275c44cc8d31f280`，涵盖 SC0 修复及 SC1–SC5 首批实现。本机没有 `4afe73c8bf7c8f4eb1640a54e1e32599078c275a` 对象，不能声称已经以该提交完成合并或重放。

远端目标基线按你提供的当前 HEAD `4afe73c`。不是 `a634c2c`。应先在该基线的隔离 checkout 中审查并三方合并，不能直接覆盖 D:\main。

## 3 git diff --stat

下列是普通命令对已跟踪文件的实际输出；它不包含未跟踪新增文件。完整补丁另见 patch-stat.txt。

```text
components/jiqi/changjing-data.js                  |  63 +--
 my-bagisto-store/bootstrap/app.php                 |   3 +
 my-bagisto-store/database/seeders/SceneSeeder.php  |  28 +-
 .../packages/Webkul/Admin/src/Config/acl.php       |  32 ++
 .../packages/Webkul/Admin/src/Config/menu.php      |   6 +
 .../Http/Controllers/Catalog/SceneController.php   | 180 +++++---
 .../Admin/src/Http/Requests/SceneRequest.php       |  71 ++-
 .../views/catalog/scenes/create.blade.php          |  25 +-
 .../Resources/views/catalog/scenes/edit.blade.php  |  50 ++-
 .../Webkul/Admin/src/Routes/catalog-routes.php     |   3 +-
 .../Sales/src/Repositories/OrderRepository.php     |   4 +-
 .../Shop/src/Http/Controllers/API/ApiErrorCode.php |   4 +
 .../src/Http/Controllers/API/SceneController.php   |  66 ++-
 .../src/Http/Controllers/API/SummonController.php  |  67 ++-
 .../Http/Controllers/API/TobOrderController.php    | 202 +++++----
 .../packages/Webkul/Shop/src/Models/Scene.php      |  56 ++-
 .../Shop/src/Providers/ShopServiceProvider.php     |   1 +
 .../packages/Webkul/Shop/src/Routes/api.php        |   4 +-
 pages.json                                         |   6 +-
 pages/ai/index.vue                                 |   5 +
 pages/home/index.vue                               |  97 ++--
 pages/home/rmfangan.vue                            | 490 +--------------------
 pages/mine/index.vue                               |   2 +
 pages/order/checkout.vue                           | 109 +----
 pages/robot/fangan.vue                             | 395 ++++-------------
 pages/robot/index.vue                              | 107 ++---
 utils/api.js                                       |  26 +-
 27 files changed, 725 insertions(+), 1377 deletions(-)
```

## 4 git diff --name-only 和完整清单

普通输出保存在 tracked-diff-files.txt；包含新增代码/测试/文档的完整清单为 patch-files.txt。补丁共 94 个文本文件，其中 73 个代码/测试文件；不是 19 个。19 是本轮修改前既有文件的回滚备份数，不能当作 Git 变更总数。

重叠文件：远端未提交的 `pages/mine/index.vue` 本批只有两行需求入口，另有 pages-mine-entry.patch 便于逐行合并。`components/EcoCenter.vue` 和 `components/SkillMarket.vue` 本批没有修改。`pages/ai/index.vue`、`utils/api.js`、路由/Provider/bootstrap 等也须与远端最新生态/AI 提交核对，不能整文件覆盖。

## 5 migration、route、config

- 三份 `2026_10_20_100001`–`100003` expand 迁移，27 张新表及 scenes 发布指针；本机没有业务库执行记录，远端尚未执行。
- 新配置 `my-bagisto-store/config/scenes.php`。
- Shop 路由 `packages/Webkul/Shop/src/Routes/scene-workflow.php`，由既有 `Routes/api.php` 的 /api 组引入；Admin 另有 `scene-workflow-routes.php`。
- 远端已有 Q-02/Q-03 及共享可靠性实现，本补丁中的 business_outbox / business_idempotency_keys / receipt 必须先核对复用，不能直接创建等价的第二套。当前本机基线没有远端这些代码，尚未完成这项合并。
- 建议单独的 `summon_mecha_scenes_test` 验证，不并行占用 `summon_mecha_step10_test`，禁止 root；不运行 migrate:fresh、truncate 或全库 seed。

## 6 404 请求 method 和完整 URI

`GET http://192.168.3.97:8010/api/scene-workflow/capabilities`

```powershell
curl.exe --noproxy "*" -i -X GET "http://192.168.3.97:8010/api/scene-workflow/capabilities" -H "Accept: application/json"
```

本批代码定义了该 GET 路由；无需客户登录态、CSRF、Bearer 或游客能力。capabilities 不查询业务表，未迁移也可读配置。其他需求/报价/项目接口才依赖新迁移和主体授权。

## 7 实际响应

以下保留响应头及响应体，仅对两条匿名 session Set-Cookie 值脱敏。原始 cookie 不放入共享交接包。

```http
HTTP/1.1 404 Not Found
Host: 192.168.3.97:8010
Connection: close
X-Powered-By: PHP/8.4.24
Cache-Control: no-cache, private
Date: Tue, 20 Oct 2026 14:23:56 GMT
Content-Type: application/json
Referrer-Policy: no-referrer-when-downgrade
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
X-Frame-Options: DENY
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Built-With: Bagisto
Access-Control-Allow-Origin: *
Set-Cookie: [REDACTED anonymous session cookie]
Set-Cookie: [REDACTED anonymous session cookie]

{"code":"NOT_FOUND","message":"\u8bf7\u6c42\u7684\u8d44\u6e90\u4e0d\u5b58\u5728\u3002"}
```

局域网验证：WLAN / 192.168.3.96 → 192.168.3.97:8010，TcpTestSucceeded=True；/up=200，/api/events?per_page=1=200 且空列表 total=0，/api/scenes=200 且空列表。数据库身份来自用户转交的远端运行时实测（testing / summon_mecha_step10_test），不是 HTTP 响应自行证明。

## 8 本批开关和预期

SCENE_PUBLICATION_V2、SCENE_REQUESTS_ENABLED、SCENE_QUOTES_ENABLED、SCENE_RESERVATIONS_ENABLED、SCENE_PROJECT_ACTIONS_ENABLED、SCENE_OPERATIONS_ENABLED，全部默认 false；没有 ECOSYSTEM_* 依赖。

`GET capabilities` 本身不受这些开关限制，新路由注册后即便全部关闭也应返回 200 与 false 能力。旧版本的 404 不能当作本批功能验收失败。订单转换始终明确阻断，未实现，不能通过环境开关绕过；正式条款/必需费用及扫描器未配置时相应写入拒绝。

## 补丁与当前验证

- 补丁：scene-workflow-from-3fd8753.patch
- SHA-256：`22bf24ea756279aba81ee5ba077a990e80771191b94760eaf6efa18e5d4a458a`
- 已用独立临时 Git index 对本机基线执行 `git apply --cached --check`，通过。未对远端 4afe73c 验证；不要直接 apply 到有未提交修改的工作区。
- 本机：新增流程 PHP 27/27、SC0 后端 11/11、前端 28/28；12 个 Vue SFC 与 7+5 个浏览器夹具检查通过；PHP 语法 51 文件通过。不是远端 MySQL / HBuilderX / 真机验收。
- 来源：docs/skill-dev-step14.md 和 docs/scenes/Scene-Workflow-Contract-v1.md。SC1–SC5 均未整体验收，MySQL 并发、订单转换、变更生效、真实进程/全端验证等仍未完成。
