# Bagisto 升级合并清单（UPGRADE NOTES）

> 本项目基于 **Bagisto 2.3**（composer.lock 已锁定）。以下是对官方核心文件的全部改动。
> **升级 Bagisto 版本前必读**：逐条与官方分支三方合并；改错任意一条都可能破坏后台或 API。
> 升级流程：① 备份 DB + `git tag` 打快照 → ② 拉官方新版做 diff → ③ 按本清单逐文件合并 → ④ `php artisan migrate`（本项目迁移与官方共存）→ ⑤ 跑回归清单。

## 一、核心文件改动（升级时需三方合并）

### Shop 包（API 后端）

| # | 文件 | 改动性质 | 要点 |
|---|---|---|---|
| 1 | `Shop/src/Routes/api.php` | 修改 | 追加自定义路由段：`/api/scenes`、`/api/tob-order`、`/api/tob-catalog`；api 路由组中间件插入 `api.customer`（原生只有 web/shop） |
| 2 | `Shop/src/Providers/ShopServiceProvider.php` | 修改 | L41 注册别名中间件 `api.customer`；L47 api.php 挂载时插入该中间件 |
| 3 | `Shop/src/Http/Middleware/ResolveApiCustomer.php` | **新增** | Sanctum token → 注入 customer guard，使原生控制器无需改动即可识别 API 用户 |
| 4 | `Shop/src/Http/Controllers/API/CustomerController.php` | 修改 | login/register 返回 `createToken('uniapp-api')` 明文 token；logout 吊销当前 token |
| 5 | `Shop/src/Http/Controllers/API/CartController.php` `OnepageController.php` `WishlistController.php` | 修改 | `route('shop.*')` 死引用替换为 `/admin`（Shopfront 停用实验遗留；前台现未停用，但替换保留——升级时随官方结构走） |
| 6 | `Shop/src/Http/Controllers/API/TobOrderController.php` | **新增** | TOB 统一下单：单商品 + items[] 多机合单；定制线索挂锚点商品（sku=LEAD-CUSTOM）；order_type 归类（场景租用→plan）；条目名携带场景/租期/线索信息 |
| 6b | `Shop/src/Http/Controllers/API/OrderController.php` | 修改 | formatOrder() 增加 `order_type` 字段（列表+详情），供前端类型显示/分类 |
| 6c | `Shop/src/Http/Controllers/API/AddressController.php` | 修改 | 新增 `destroy(int $id)`（仅删除本人地址）+ 对应 DELETE 路由 |
| 7 | `Shop/src/Http/Controllers/API/CatalogController.php` | **新增** | `/api/tob-catalog`：一次返回四类目录 + 场景（机器页性能优化，数据层与原生 products API 一致） |
| 8 | `Shop/src/Http/Controllers/API/SceneController.php` | **新增** | 场景列表/详情（scenes 表） |
| 9 | `Shop/src/Models/Scene.php`、`Shop/database/seeders/SceneSeeder.php` | **新增** | 场景模型 + 预置数据 |
| 10 | `Shop/src/Database/Migrations/` | **新增 5 个迁移** | scenes 表（4 个：建表+方案 JSON 列+sort+is_hot）+ orders 表加 `order_type` 列（带索引，含存量回填） |

### Admin 包（后台管理）

| # | 文件 | 改动性质 | 要点 |
|---|---|---|---|
| 11 | `Admin/src/DataGrids/Sales/OrderDataGrid.php` | 修改 | 纯增量：order_type 列 + 下拉筛选 + `?order_type=` 参数过滤（页签用）；未动任何现有列 |
| 12 | `Admin/src/Resources/views/sales/orders/index.blade.php` | 修改 | 顶部常驻分类页签（全部/租用/购买/方案/定制/技能/配件）+ 内联脚本（页签导航后清理 datagrid 的 localStorage 过期分页/筛选状态） |
| 13 | `Admin/src/Http/Controllers/Catalog/SceneController.php`、`DataGrids/Catalog/SceneDataGrid.php`、`Http/Requests/SceneRequest.php`、`Resources/views/catalog/scenes/*`、`Routes/catalog-routes.php`(L86-108)、`Config/menu.php`(L84-86) | **新增整套** | 后台「场景管理」CRUD（照 Category 模式搭）：banner 上传、方案 JSON 列、排序/热门/咨询数 |

### 应用骨架与配置

| # | 文件 | 改动性质 | 要点 |
|---|---|---|---|
| 14 | `bootstrap/app.php` | 修改 | CSRF 豁免 `api/*`、`sanctum/*`（uni-app Bearer 客户端）；`trustProxies('*')` |
| 15 | `config/cors.php` | 修改 | `paths => api/*` 全放开 origins/headers（uni-app/H5 跨域） |
| 16 | `app/Providers/AppServiceProvider.php` | 修改 | `Schema::defaultStringLength(191)` |

## 二、升级回归清单（合并后必测）

1. 后台登录（Sanctum + session 双轨）→ 仪表板
2. 后台 订单列表：类型页签六类切换 + 过滤器面板"类型"下拉 + 订单详情（重点测多机合单单）
3. 后台 场景管理：列表/新建（含 banner 上传）/编辑/删除
4. API：`/api/tob-catalog`、`/api/scenes`、`/api/tob-order`（rent 单商品 / items 多机 / custom 线索 / from=scene 归 plan）
5. 前端：登录、机器页四目录、场景方案、多机合单下单、订单列表

## 三、运维备忘

- **锚点商品**：sku=`LEAD-CUSTOM`（定制线索挂载点，前台不可见）——误删会导致线索提交 422，重建脚本 `my-bagisto-store/_create_lead_anchor.php`
- **MAIL_MAILER=log**（开发环境）：上线改回 `smtp` + 完整认证（含管理员通知邮箱，否则订单邮件报"To 头缺失"）
- **测试数据备份**：历次测试订单的删除备份 JSON 在 `.claude/backup/T4-ordertype/`
