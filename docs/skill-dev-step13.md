# 场景方案 SC0 第一批实施记录

日期：2026-10-20，Asia/Shanghai。依据：[场景工程方案](scenes/Scene-Engineering-Plan.md)、[55 项任务及验收矩阵](scenes/Scene-Backlog-Acceptance.md)。用户已授权开始执行，并明确业务数据库位于另一台机器；本批没有连接该数据库，没有修改 `.env`、新建业务数据库、运行生产迁移或创建业务订单。

本批已实现场景真实性、目录资格校验和后台动作权限等基础改造，并完成隔离验证。SC0 整体仍为**部分完成，待真实数据库与完整客户端联调**；SC1–SC5 未实施。原有定制留资仍使用 LEAD-CUSTOM 锚点，需求实体、版本化报价和实际资源排期没有被标成完成。

## 1 实际改动

| 工作包 | 已实现 | 仍需验证或建设 |
|---|---|---|
| SC0-01 运行基线 | 保留修复前源码哈希及本机备份；明确本机不承担业务数据库 | 另一台机器的表/索引/渠道/场景数量/历史引用、完整路由及配置基线 |
| SC0-02 真实列表 | `utils/scene-catalog.js`：成功响应整体替换；空数组、空文本、0/null 正确处理；非法响应不写成功缓存；并发去重和失效版本防覆盖；首页/机器页有加载、错误和空态，onShow 强制重验 | 真实 API、HBuilderX H5 与小程序编译及跨页面实际运行 |
| SC0-03 机型与交易守卫 | 详情一次读取服务端配置及真实商品；不再使用 8 个模拟商品；缺配置保持空态；`SceneOrderGuard` 校验启用场景、配置、商品类型、租用类目、渠道和可见性；订单项 additional 留场景来源；自定义方案提交前重验目录 | 真实 ProductResource 序列化、Bagisto 完整建单/库存与六分类回归；SC3 才处理已批准报价和资源档期 |
| SC0-04 可见性 | `Scene::enabled()` 共用于列表/详情/规则 AI；非法/缺失/停用详情统一 404；查询分类校验；聚合目录继续复用场景列表 | 真实 HTTP 和线上缓存链；已打开旧客户端不可能自动收到离线撤销，写入仍由服务端拦截 |
| SC0-05 管理权限与表单 | 11 个场景入口统一挂 `AuthorizeSceneAction`；覆盖 store/update/status/hot/count/mass；分类可编辑；机型逐项及当前目录校验；长度、图片数量/大小和文本结构约束；未提交字段保留，明确空值可清空；修复编辑回填 | 真实 admin 登录、角色/Blade/DataGrid/导出 HTTP 演练；版本审阅/发布留 SC1 |
| SC0-06 内容与失败状态 | 移除正式路径固定方案/收益/案例、手填咨询数和默认“100咨询”、企微占位、一小时承诺；客服转真实定制入口；旧方案链接仅精确解析已启用场景；首页 AI 输入转入 AI 页；结算失败不写本地意向单、不伪造成功；本机草稿明确标识 | 真实案例与量化证据留 SC1；不能以目录价格承诺现场能力和档期 |
| SC0-07 种子保护 | SceneSeeder 只创建缺失记录；不覆盖停用、文案和图片 | 真实存量孤儿和静态 ID 冲突诊断，不自动修历史数据 |
| SC0-08 日志与异常 | TOB 不再记录全量请求；订单仓储失败日志不再写整单地址/联系方式；外部错误返回稳定提示和 error_id；新图先保存再删旧图，用 MIME 推断扩展名 | 实际日志链及真实存储故障；现有订单仓储事务/递归重试没有在本批重写 |
| SC0-09 验证 | 23 个 Node 测试、11 组后端内存隔离测试、7 个 Vue SFC 编译、组件浏览器 5 条路径、16 个 PHP/Blade 文件语法检查、diff 检查 | SQLite 不能证明 MySQL 锁语义；组件浏览器验证不能代替完整 uni-app 构建和真库端到端 |

旧 `SCENE_EXAMPLES / DEFAULT_SCHEME / SCHEME_DB / SCENE_SCHEMES` 仅保留为迁移参考，不再进入正式列表和详情；没有直接删除可用于整理运营说明的历史资产。已停用场景和未知商品不能靠这些常量恢复交易资格。

## 2 代码落点

- 前端纯逻辑：`utils/scene-catalog.js`；兼容数据入口：`components/jiqi/changjing-data.js`；API：`utils/api.js`。
- 用户界面：`pages/home/index.vue`、`pages/home/rmfangan.vue`、`pages/robot/index.vue`、`pages/robot/fangan.vue`、`pages/order/checkout.vue`；AI 需求草稿接入 `pages/ai/index.vue`。
- 后端：`Shop/src/Services/Scenes/{SceneCatalogService,SceneOrderGuard}.php`、`Models/Scene.php`、`API/{Scene,Summon,TobOrder}Controller.php`、`API/ApiErrorCode.php`。
- 管理端：`Admin/src/Http/Middleware/AuthorizeSceneAction.php`、Scene Controller/Request、`Routes/catalog-routes.php`、场景 create/edit Blade。
- 其他：`database/seeders/SceneSeeder.php`、`Sales/src/Repositories/OrderRepository.php` 的错误日志。

Shop/Admin/Sales 均以 `my-bagisto-store/packages/Webkul/<包名>` 为根。本批无新增迁移，没有修改 D1–D11、设备归属、技能定价/授权或现有普通租购议价规则。

## 3 已执行验证与证据

在仓库根目录执行：

```powershell
node --test tools/tests/scene-catalog.test.mjs tools/tests/skill-market.test.mjs
php -d extension=pdo_sqlite tools/tests/scene-backend.php
node tools/tests/scene-ui-check.mjs
git diff --check
```

- Node：23/23 通过，其中 14 个场景测试、9 个现有技能市场回归；覆盖真实快照、缓存、乱序、缺失机型、未知价格、详情交易门禁、留资重复点击和结算失败。
- 后端：11 组通过。测试仅加载 Composer 和最小 Laravel 容器，强制 `SQLite :memory:`；实际运行四份既有场景迁移、Eloquent 查询、服务守卫、表单校验和授权中间件，并加载真实 Admin catalog 路由验证 11 个场景入口均有动作鉴权。权限检查器与 core/channel 是隔离替身，不冒充完整用户登录验收。
- Vue：7 个文件通过 Vue 3.5.13 模板编译和 JavaScript 解析；浏览器挂载真实 fangan/StateView 组件，执行正常目录、sceneId 透传、定制空内容、错误态、无配置空态。接口为隔离假实现，tap 事件按 uni-app 语义派发，截图中的机型和价格是 QA 夹具。
- PHP：16 个变动或新增 PHP/Blade 文件通过 `php -l`。Blade 的 `php -l` 不代替真实 Admin 页面渲染。

证据：[汇总与源码哈希](scenes/sc0-evidence/verification.json)、[前端测试](scenes/sc0-evidence/frontend-tests.tap)、[后端结果](scenes/sc0-evidence/backend-verification.json)、[组件验证](scenes/sc0-evidence/ui-verification.json)、[正常截图](scenes/sc0-evidence/detail-ready.png)、[错误截图](scenes/sc0-evidence/detail-error.png)、[空态截图](scenes/sc0-evidence/detail-empty.png)。

组件 QA 的 Vue/compiler/playwright-core 依赖安装在被忽略的 `.claude/backup/scene-sc0-20261020/qa/`，没有改业务 package.json 或 lock。其他机器可指定 `SCENE_QA_DIR`，按 `scene-ui-check.mjs` 注释安装同版本；浏览器路径可用 `SCENE_CHROME` 指定。

修复前证据仍是历史基线。若本机备份存在，可运行 `node docs/scenes/scene-readonly-audit.mjs --before`，复现原 9 个缺陷；当前代码的验证应运行上面的新测试，不能再把旧 `defect_observed=true` 当作当前问题或功能已完成。

## 4 在有数据库的机器上验收

先配套同步前端及后端源码，沿用该机已有数据库配置。当前没有新增迁移，不需要为了本批重新 seed 或安装数据库。此处命令尚未在该机执行。

```powershell
# 在后端目录：检查现有环境及路由，不执行迁移
php artisan migrate:status --no-ansi
php artisan route:list --path=scenes --json

# 回到仓库根目录：只访问公开 GET，不创建任何业务数据
node tools/tests/scene-http-readonly.mjs http://127.0.0.1:8000
```

将地址改为该机实际服务地址。HTTP 脚本检查列表、真实详情协议、机型引用、未核验指标和四种非法 ID；真实列表为空时会明确跳过详情，不为验收自行写库。若需验证已停用场景，使用已有停用 ID 查看详情，应返回 404；不要在业务库随意停用真实服务。

剩余写入和角色演练须使用独立测试库及测试客户：场景租用单机/多机、custom_plan、定制线索、普通租买/技能回归、旧客户端错误提示、失效商品拒绝、Admin 创建/编辑/停用/批量/导出、图片保存失败、日志脱敏和订单事务失败。不能运行默认可能创建真实零元线索的 `tools/api-regression.sh` 来声称只读检查通过。

前后端应配套更新：新详情协议增加 `products`，场景来源订单要求 `scene_id`，自定义多机来源为 `custom_plan`。旧客户端缺少场景 ID 会收到 422 及重新进入提示，不能静默按原模拟 ID 下单。发布/客户端缓存和最低版本策略需在该环境核验。

## 5 本机备份与回退

修改前的 21 个既有文件备份于 `.claude/backup/scene-sc0-20261020/`，路径层级与仓库一致。`restore-manifest.json` 仅列业务源码备份，不包含 QA node_modules。若当前文件被本批之后的工作修改，先保留差异，不直接覆盖。

本机按清单逐文件恢复命令如下；本轮未执行回退：

```powershell
$sceneTaskRoot = (Get-Location).Path
$sceneBackupRoot = Join-Path $sceneTaskRoot '.claude/backup/scene-sc0-20261020'
$sceneManifest = Get-Content -LiteralPath (Join-Path $sceneBackupRoot 'restore-manifest.json') -Raw | ConvertFrom-Json
foreach ($sceneFile in $sceneManifest.files) {
    Copy-Item -LiteralPath (Join-Path $sceneBackupRoot $sceneFile) -Destination (Join-Path $sceneTaskRoot $sceneFile)
}
```

新增服务/测试/文档保留作复核，不进行递归删除；原入口恢复后不再引用新增服务。另一台机器回到前一兼容发布版本即可，数据库无本批新增表需要 down；已经创建的订单及 additional 场景快照保留。不要使用 `git add -A`、`git clean` 或全仓 reset 处理历史备份。

## 6 下一阶段准入

先补完真实环境基线和 SC0 联调，随后进入 SC1 的需求实体、发布版本、权限和可靠事件基础。报价核准、资源容量与并发、完整交付/验收/售后仍按 SC2–SC5 的依赖推进；不能把本批“目录可选”宣称为“可交付”或“档期已确认”。
