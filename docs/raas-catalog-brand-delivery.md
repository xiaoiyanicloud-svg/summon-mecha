# RaaS 分类目录重构与后台品牌管理交付说明

**日期**：2026-11-26  
**范围**：分类目录顶层收束（`租售 TOB -> 租赁 / 购买`）、后台品牌管理全链路 CRUD、`brand` / `robot_type` EAV 对齐与 `pages/robot/index.vue` 前端重构。

---

## 1. 核心架构与边界决策

1. **目录树收束（`tob -> rent / buy`，保留底层稳定 slug）**：
   - 将前台顶级 Tab 从 5 个收束为 4 个：`租售 TOB` | `场景` | `技能` | `配件`。
   - 在 `租售 TOB` 下提供 `全部 | 租赁 | 购买` 子模式切换栏；切换子模式时保留当前选中的品牌与形态过滤状态。
   - **兼容约束**：严格保留既有数据库分类 slug `rent` 与 `buy`（以及 `skill`、`accessory`）及各自 ID 不变，仅在 Bagisto 分类树将 `rent` / `buy` 挂入 `tob` 父级（由 `InstallRaaSCatalogTaxonomy` 幂等完成）。既有订单归因、收藏、购物车、`+方案` 多机合单、AI 推荐跳转（`robotTab = 'rent' | 'buy'`）全部零破坏兼容。

2. **单一数据源的品牌主数据与 EAV / 机型库对齐**：
   - 新增 `catalog_brands` 表（迁移 `2026_11_25_000001_create_catalog_brands_table.php`）及模型 `Webkul\Shop\Models\CatalogBrand`。
   - 核心服务 `Webkul\Shop\Services\Catalog\CatalogBrandService` 承担：
     - 维护 `catalog_brands` 与 Bagisto `brand` EAV 属性（`attribute_options` + `attribute_option_translations`）同步；
     - 幂等安装 `tob -> (rent, buy)` 分类层级、`robot_type` EAV 属性（8 种标准形态枚举 `CatalogBrand::ROBOT_TYPES`）及 TOP 20 初始品牌种子；
     - 为商品列表 / 详情与目录聚合接口批量注入真实销量（`qty_invoiced - qty_refunded`，剔除取消单）、`brand`、`robot_type` 与基于 `merchant_product_exts` / `device_model` 的 RaaS 元信息（`raas_meta`）。
   - 被商品 EAV 属性或 `device_model` 引用的品牌禁止硬删除（后台返回 HTTP 422），引导改用「停用」（`status = false`）。

3. **诚实原则与真实数据对齐**：
   - 清除 `pages/robot/index.vue` 中硬编码的 `* 1.2` 租赁弹窗虚假划线原价、`coupons` 死数据数组及与商户/合同制不符的静态宣传承诺（如默认“全国包邮”、“48 小时发货”等）。
   - 仅在后端返回真实 `raas_meta` / `brand` / `robot_type` 时展示对应能力摘要，未知商品不强行伪造“人形”标签或适配数量。
   - 修复原分类筛选逻辑中 `|| item.brand === 'all'` 与 `|| item.type === 'all'` 导致未分类机器人在特定品牌/形态筛选下串台的缺陷，并补齐原模板第 20 行未闭合 HTML 注释被意外隐藏的搜索框，以及场景 Tab 缺失的搜索过滤能力。

---

## 2. 变更文件清单

### 2.1 后端（Bagisto `my-bagisto-store`）

- **迁移与种子**：
  - `my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_11_25_000001_create_catalog_brands_table.php`
  - `my-bagisto-store/database/seeders/BrandAndRaaSTaxonomySeeder.php`
  - `my-bagisto-store/packages/Webkul/Shop/src/Console/Commands/InstallRaaSCatalogTaxonomy.php`（已在 `ShopServiceProvider` 注册：`catalog:install-raas-taxonomy`，默认 `--dry-run`，需带 `--apply` 写入）
- **Shop 模型、服务与接口**：
  - `my-bagisto-store/packages/Webkul/Shop/src/Models/CatalogBrand.php`
  - `my-bagisto-store/packages/Webkul/Shop/src/Services/Catalog/CatalogBrandService.php`
  - `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/BrandController.php`（新增 `GET /api/brands`）
  - `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/CatalogController.php`（接入 `attachCatalogMeta` 修复 `/api/tob-catalog` 缺销量字段，新增返回 `brands`、`robot_types`、`categories_meta`）
  - `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/ProductController.php`（列表与详情统一注入 `attachCatalogMeta`）
  - `my-bagisto-store/packages/Webkul/Shop/src/Http/Resources/ProductResource.php`（输出 `brand`、`robot_type`、`raas_meta`）
  - `my-bagisto-store/packages/Webkul/Shop/src/Providers/ShopServiceProvider.php`
  - `my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php`
- **Admin 管理后台（CRUD + DataGrid + 路由/菜单/权限）**：
  - `my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Catalog/CatalogBrandController.php`
  - `my-bagisto-store/packages/Webkul/Admin/src/DataGrids/Catalog/CatalogBrandDataGrid.php`
  - `my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/brands/{index,create,edit,_form}.blade.php`
  - `my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php`
  - `my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php`
  - `my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php`

### 2.2 前端与测试

- `utils/api.js`（导出 `brandApi.list()`）
- `pages/robot/index.vue`（4 主 Tab、`tobSubTab` 切换、动态品牌栏、搜索框闭合修复、场景搜索、严格品牌/形态过滤、`goDetail`/`goEnterprise`/旧 `robotTab` 兼容）
- `tools/tests/raas-catalog-brand.php`（内存 SQLite 隔离 PHP 集成回归测试）
- `tools/tests/raas-category.test.mjs`（分类页重构与兼容性 Node 回归测试）

---

## 3. 验证方式与实测范围说明

> 本地环境当前未启动 MySQL 3307，所有数据库行为验证均在**内存 SQLite 隔离环境**完成，未接触业务库 `summon_mecha_local`。

1. **PHP 语法检查（`php -l`）**：全部 17 个涉及新增/修改的 PHP 文件通过（含迁移、Model、Service、Controller、Resource、DataGrid、Routes、Menu、ACL、Command、Seeder 及测试脚本）。
2. **Blade 模板编译验证**：通过 `Illuminate\View\Compilers\BladeCompiler` 编译并 `php -l` 检查 `catalog/brands/{index,create,edit,_form}.blade.php`，全部通过；`menu.php` 与 `acl.php` 已检出 `catalog.brands`。
3. **契约门禁（`php artisan ecosystem:contract-check`）**：通过（`admin_routes=113, public_routes=45, contracted_routes=45, error_codes=85, state_inventories=11`）。
4. **隔离 PHP 集成回归（`php tools/tests/raas-catalog-brand.php`）**：5 组用例全过，覆盖 `catalog_brands` 迁移幂等、未入库 TOP 20 兜底、`installRaaSTaxonomy`（dry-run 与 apply、`tob -> rent/buy` 树归并与祖先 slug 保留、`robot_type` 与 20 品牌 EAV 同步）、`CatalogBrandController` 参数校验/CRUD/启停隐藏/422 删除保护及 `attachCatalogMeta` 销量和 RaaS 元信息计算。
5. **Node 回归套件（`node --test`）**：共 51 项测试全过（新增 `raas-category.test.mjs` 5 项 + 既有 `scene-*` 与 `config-consistency.test.mjs` 46 项）。
6. **无头浏览器组件与布局验证（Chromium headless + Vue 3.5.13 + `@vue/compiler-sfc`）**：
   - 既有 `scene-ui-check.mjs`（7 个 SFC 编译、5 项检查、0 报错）与 `scene-workflow-ui.mjs`（17 个 SFC 编译、26 项检查、`pageErrors=[]`）通过；
   - `pages/robot/index.vue` 独立 390x844 渲染验证：正确渲染 4 主 Tab 与 3 子开关、宇树/智元动态品牌栏、过滤无串台、`overflow: false`、`pageErrors: []`（均在隔离 Mock 数据下测试，非 uni-app 完整编译产物或实库联调）。

---

## 4. 部署与运维前置说明

在目标数据库环境上线或联调前，需按顺序执行：

```bash
cd my-bagisto-store
php artisan migrate
# 先 dry-run 预览即将安装的 tob -> (rent, buy)、robot_type 属性与种子品牌
php artisan catalog:install-raas-taxonomy
# 确认无误后正式落地（幂等）
php artisan catalog:install-raas-taxonomy --apply
```

说明：未执行上述数据库迁移与安装前，前台分类页与 `GET /api/brands` 已通过 `CatalogBrandService::publicMetadata()` 与前端 `FALLBACK_BRANDS` 保持 TOP 20 品牌与 8 种形态基础筛选可用；执行 `--apply` 后，后台「商品 -> 品牌管理」的增删改与 Logo 上传及商品 EAV 属性即刻接入同一数据源。
