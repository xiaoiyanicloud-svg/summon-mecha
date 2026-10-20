# 场景方案现状审计

日期：2026-10-20，Asia/Shanghai。源码基线：`3fd8753c1299fc33062bbc2d275c44cc8d31f280`。本审计创建时仅完成工程方案及只读复现；其基线未包含后续实施改动。

后续执行说明：本文和 `scene-audit-evidence.json` 保留修复前事实；当前改动及测试见[SC0 实施记录](../skill-dev-step13.md)。旧缺陷复现需带 `--before` 读取本机备份，新代码请运行 `tools/tests/scene-catalog.test.mjs` 与 `scene-backend.php`。

当前场景模块具备内容管理、公开列表和详情、多机选择、TOB 建单及定制留资入口。但场景内容、适配资格、客户需求、确认报价和实际交付没有建立完整的结构化关联。现有“已闭环”文档口径只能说明入口可到达，不能作为可交付、可核价、可验收的证明。

配套文件：[工程方案](Scene-Engineering-Plan.md)、[任务及验收矩阵](Scene-Backlog-Acceptance.md)、[复现脚本](scene-readonly-audit.mjs)、[机器证据](scene-audit-evidence.json)。所有下文路径以仓库根目录为基准。

## 1 证据范围

| 证据等级 | 本轮完成 | 能支持的结论 |
|---|---|---|
| 源码核对 | 已读 uni-app、Shop API、Admin、ACL、订单仓储、迁移、种子、技能兼容性和审计服务 | 存在的字段、控制分支、调用和缺口 |
| 可执行复现 | Node.js `v18.20.8`，9 个现有前端行为均复现 | 注入响应下实际执行现有数据模块及详情页 computed 的结果 |
| Laravel 启动 | 默认 PHP 缺 `pdo_mysql`；命令行临时加载 `pdo_mysql`、`intl` 后，配置的 MySQL 连接报 `SQLSTATE[HY000] [2002]` | 本机此时无法进行依赖数据库的运行核验 |
| HTTP、主库统计、完整路由、迁移状态 | 未取得 | 不能判断生产数据规模、实际启用数量、线上可用性、当前迁移是否已执行 |
| 浏览器、小程序、真机、真实交付 | 未执行 | 不宣称页面联调、机器人适配或现场验收通过 |

复现命令：`node docs/scenes/scene-readonly-audit.mjs`。输出到 stdout，不改源码、不联网、不读取 `.env`、不连接数据库、不写入 `uni` 存储。证据包含 SHA-256 和源码行锚点；复现的是缺陷，`defect_observed=true` 不表示功能通过验收。脚本移除 import/export 后在独立 VM 中执行被审计函数，不代替 uni-app 编译或真实接口测试。

尝试过的运行命令：

```powershell
php artisan route:list --path=scenes --json
php -d extension=pdo_mysql -d extension=intl artisan route:list --path=scenes --json
```

未执行现有 `tools/api-regression.sh`：其中会创建 `custom` 订单，不能作为只读审计。未执行 `_repro_scene.php`：其中会创建场景并上传文件。未运行可能连接当前业务库的 PHPUnit 特性测试。

## 2 已执行的九项复现

| 编号 | 输入条件 | 实际结果 | 业务影响 |
|---|---|---|---|
| R01 | `/api/scenes` 成功响应只有 ID 1 | 本地数组仍有 28 项 | 后台停用或删除不能可靠撤销前台卡片 |
| R02 | 成功响应 `data=[]` | 仍有 28 项 | 正常空数据被伪装成有供给 |
| R03 | 第一次人数 37、有图；第二次人数 0、图片 null | 仍是 37、仍留旧图 | 纠错和撤图不能生效；其他 truthy 合并字段也需逐项覆盖测试 |
| R04 | 响应 `data` 类型错误，立即再次调用 | 只请求一次 | 无效响应写入成功 TTL，五分钟内不重新获取 |
| R05 | 场景未配置适用机型 | 返回 8 个模拟商品 | 未建立适配关系也能选择并进入结算 |
| R06 | 已配机型，但目录为空 | 返回 8 个模拟商品 | 请求失败被隐藏为“有机器” |
| R07 | 配置的商品全是 `booking` | 过滤后返回 8 个模拟商品 | 回避不支持商品后重新引入虚假供给 |
| R08 | 配置的商品已不在目录 | 返回 8 个模拟商品 | 商品撤销后仍可进入下单路径 |
| R09 | 场景列表接口抛出异常 | 异常被吞，保留 28 项 | 用户不能区分缓存、示例、真实供给和加载失败 |

R01–R04、R09 位于 `components/jiqi/changjing-data.js:63` 起的 `loadBackendScenes()`；R05–R08 位于 `pages/robot/fangan.vue:251` 起的 `filteredProducts()`。模拟 ID 1–8 被传为 `product_id`；若恰好对应真实目录 ID，存在错误商品关联风险，是否已发生需要独立数据库核验，不能据此认定线上已经错单。

## 3 源码发现与修复边界

下表“P0”只表示本场景专项开始改造时优先处理的现有真实性、权限或完整性问题，不改变项目既定 M1–M4，也不把真机或支付外部前提排入近期开发。

| 编号 | 严重度及事实 | 代码证据 | 工程处理 |
|---|---|---|---|
| A01 | P0，停用场景仍可按 ID 获取 | `Shop/.../API/SceneController.php:53` 使用 `Scene::find`，不检查 status | 列表、详情、聚合目录、AI 场景、分享和交易命令共用可见性服务 |
| A02 | P0，空、错、下架回退为本地场景及可操作模拟商品 | R01–R09 | 保留说明性容错；失败和示例不能产生可售资格、商品 ID、价格或历史业绩 |
| A03 | P0，非技能价格接受客户端正数覆盖 | `TobOrderController.php:173` | 既有代码明确为议价设计；新场景交易改用平台审核报价，变更旧价格语义前做兼容评审，不直接全局改价 |
| A04 | P0，场景交易缺少结构化来源和服务时间 | `TobOrderController.php` 仅取 `scene` 文本；无 `scene_id`、revision、startDate/endDate 的校验和持久化 | 加场景交易关联、快照与起止区间；不能从名称或租期字符串还原事实 |
| A05 | P0，逐动作授权存在未覆盖路由风险 | `User/.../Middleware/Bouncer.php:83` 仅在 ACL 存在该路由时调用 allow；场景 ACL 只显式列 index/create/edit/delete | 对 store/update/status/hot/consult-count/mass 操作做显式授权及低权限角色 HTTP 验收；本轮未声称已实测越权 |
| A06 | P0，全量请求写日志 | `TobOrderController.php:48` `Log::debug(...$request->all())` | 日志仅留 request_id、对象号、结果、错误码和耗时；生产是否实际记录取决于日志等级，不能据源码认定已泄漏 |
| A07 | P0，事务边界需专项验证 | `Sales/.../OrderRepository.php:45–105` 自开事务、失败回滚递归重试、finally commit；TOB 外层另有 transaction 且捕获异常返回 JSON | 在独立 MySQL 进行故障注入并记录事务层级；新业务不能假设外层 transaction 自动涵盖所有副作用；不在本轮擅改全局仓储 |
| A08 | P1，定制留资仍依赖商品并污染销售语义 | `TobOrderController.php:137` LEAD-CUSTOM、恒零价 | 独立需求实体，报价接受后才建交易订单；旧零元单保留历史 |
| A09 | P1，需求被截断进入名称 | `TobOrderController.php:199` demand 前 80 字，extra 再截断 | 原始需求、结构字段、变更版本分开持久化；名称只做展示 |
| A10 | P1，咨询数是可编辑值，无事件口径 | `Admin/.../SceneController.php:224` | 保留旧值为 legacy，不继续当已咨询人数；新增唯一需求提交事件统计，删除/失效会重算 |
| A11 | P1，分类与复杂字段缺少完整业务约束 | Admin store 固定 enterprise，update 不处理 category；SceneRequest 只校验 robots 为数组，未逐项验目录资格 | 分类枚举、ID/数量/结构/长度/发布资格一起验证；补字段不清空其他字段 |
| A12 | P1，本地三套方案及固定收益 | `changjing-data.js` 的 DEFAULT_SCHEME、SCHEME_DB、SCENE_SCHEMES；前端含固定 ROI、效率、咨询数 | 后端发布版本为事实源；前端只允许明确标注的说明性示例；绩效必须有统计条件和证据 |
| A13 | P1，客服、案例、企微均有占位 | `fangan.vue:394`、`:413`、`:182` | 无真实配置时隐藏相应入口；客服工单带需求/项目上下文；案例使用可发布证据 |
| A14 | P1，无依据承诺一小时响应 | `fangan.vue` 留资弹窗和成功页 | 工作日历、负责人、接单与超时处理齐备才展示承诺，缺配置显示预计安排待确认 |
| A15 | P1，方案只在本机，故障可变成本地意向 | `robot/index.vue:1028`；`checkout.vue` 的 submitIntentWithError | 本地草稿明确标识，不能说后台已收到；服务端需求可续填、可找回、可查询状态 |
| A16 | P1，种子重跑覆盖运营成果 | `database/seeders/SceneSeeder.php:51` 设 banner_path=null、status=1，并覆盖描述 | 新安装才插入缺失项；现存记录先差异报告，再显式导入，不重新启用已撤场景 |
| A17 | P1，booking 限制仅在部分前端绕过 | `fangan.vue` computed 过滤，TOB 无场景级购买资格服务 | 服务端明确支持的商品类型及资格；直调接口和旧分享链接也必须拦截 |
| A18 | P1，AI 的推荐不是交付评估 | rule-v1 按词命中；Coze 分支返回推荐目录，scene=null | AI 提取需求与解释；机型/技能/地区/时间/证据由确定性服务核验，未知转人工 |
| A19 | P1，订单成功未表达资源承诺 | 场景下单没有资源占用、运输缓冲、工程师排班、验收对象 | 订单、资源预留、交付项目和收款分别建模；下单不自动表示有货、付款或安装成功 |
| A20 | P2，旧方案页仍可被路由访问 | `pages/home/rmfangan.vue` 静态 ROBOTS 并自动补 2 台 | 旧链接统一重定向；缺映射显示失效，不按标题猜测新 ID |

上表的 `Shop/...`、`Admin/...`、`User/...` 分别展开为 `my-bagisto-store/packages/Webkul/{Shop,Admin,User}/src/`。准确哈希和关键行锚点见证据 JSON。

## 4 可以复用的现有基础

| 既有能力 | 复用方法 | 限制 |
|---|---|---|
| Laravel 模块化单体及配置注册表 | 新增 `Services/Scenes/`，适配和评估规则按配置注册 | 不做微服务重构、不另起交易系统 |
| 场景 Admin、DataGrid、图上传 | 扩展成编辑、审阅、发布和停用 | 表单存在不等于逐动作授权完整 |
| Bagisto products/orders/order_items | SKU、订单、金额和基础客户主体继续复用 | 服务 BOM 与现场资源不是商品库存；新增关联不修改六类订单枚举 |
| `CompatibilityService`、技能证据和资产解析 | 用于已指定设备/技能/产物的判断，结果写入场景评估快照 | “装得上”不等于场景验收通过；不能给未指定设备套用一个技能兼容结果 |
| `device_binding` | 设备归属与自动下发继续认 binding | 场景资源表只能引用，不成为另一套所有权事实源 |
| `OperationLogger::logOrFail()` | 报价批准、方案发布、资源替换、验收等同事务强留痕 | 默认 log() 失败不抛，不能用它声称这些动作审计强一致 |
| Notification、support_ticket、incident、admin_alert | 需求接单、任务提醒、异常升级、客户支持 | 需要新增对象关联及业务权限；不能向客户透出内部备注 |
| database 队列和 scheduler 定义 | 到期扫描、通知、证据复核、异常队列 | 本轮未核验常驻进程；代码注册不表示 worker 正在运行 |
| 技能支付、售后和 D10 | 技能项继续走原授权与售后规则 | 技能售后以 entitlement 为对象，不能冒充整套现场服务售后 |

## 5 业务数据补查清单

数据库恢复后，先使用只读账户核验 scenes 已启用/停用/空内容、商品孤儿引用、booking 引用、在售渠道、价格、历史 plan/custom 单、场景名可匹配率、图片存在性、ACL 路由映射、旧需求截断、时区、队列及日志配置。只输出汇总和经遮罩的异常标识，不能导出客户联系方式。

28 项是本地静态卡片数；SceneSeeder 仅预置 ID 6–9。这两个源码事实不能推导数据库恰有 4 个或 28 个场景，更不能据此给每个场景标成“已落地”。历史文档中的订单数量、供给数和常驻状态均不作为本轮当前事实。

## 6 本轮变更与撤回

仅新增 `docs/scenes/` 中的审计、工程方案、任务矩阵、只读脚本和证据文件。未修改 D1–D11、既有平台边界、商品价格规则、用户页面或数据库。

撤回时，仅删除本轮新增且未被后续工作改动的文件；执行前核对 `git status --short -- docs/scenes`。不得使用 `git clean`、全仓 reset 或递归删除备份目录。此处没有需要执行的数据库回滚。
