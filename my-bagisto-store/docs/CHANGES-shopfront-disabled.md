# 改动记录:停用 Bagisto 前台 Shop,保留后台 + API 底座

> 目的:本项目只用 Bagisto 做后端底座(uni-app → REST API → Admin 后台)。
> 自带的前台网页商店(Shopfront)已用「纯注释法」停用 —— **不删除任何文件**,随时可还原。

---

## 一、核心改动

### 1. 关闭前台路由
- `packages/Webkul/Shop/src/Routes/web.php`
  - 把原 3 个 `require`(store-front / customer / checkout 路由)全部**注释掉**。
  - 效果:所有前台 URL 失效(`/`、`/categories`、商品页、客户中心等 → 404)。

### 2. 防启动崩溃(关键联动)
- `config/breadcrumbs.php`
  - `'files' => null`,不再加载 `routes/breadcrumbs.php`。
  - **为什么**:`routes/breadcrumbs.php` 在启动引导期被 diglactic 面包屑插件 `require`,
    里面大量调用 `route('shop.*')`;shop.* 路由已注释,若不关闭会**启动即抛 `RouteNotFoundException`**。

### 3. 消除存活代码里的死路由引用(都把 `route('shop.*')` 换成安全字面量 `/admin` 或 `#`)
不再让 Admin 页面 / 邮件 / 下单 API 渲染时因路由名缺失而 500。

| 类别 | 文件 | 处理 |
|---|---|---|
| 后台死链 | `Admin/.../components/layouts/header/index.blade.php` | “Visit Shop” 图标 `href="#"` |
| 后台邮件页脚 | `Admin/.../emails/layout.blade.php` | 头部 logo 链接 `href="#"` |
| 产品预览按钮 | `Admin/.../catalog/products/edit.blade.php` | `href="#"` |
| CMS 预览按钮 | `Admin/.../cms/edit.blade.php` | `href="#"` |
| CMS 列表查看列 | `Admin/.../DataGrids/CMS/CMSPageDataGrid.php` | `'url' => '#'` |
| 客户模拟登录 | `Admin/.../Customers/CustomerController.php@loginAsCustomer` | redirect → `admin.customers.customers.view` |
| 销售后台购物车 | `Admin/.../Sales/CartController.php`(3 处) | `redirect_url => '/admin'` |
| 后台建单摘要 | `Admin/.../sales/orders/create/cart/summary.blade.php` | 成功跳转 `/admin` |
| 未认证跳转 | `Core/.../Exceptions/Handler.php` | `redirect()->guest('/admin')` |
| 社交登录 | `SocialLogin/.../LoginController.php`(4 处) | 跳转 `/admin` |
| 产品 SEO | `Product/.../Helpers/SEO.php` | `'url' => '#'` |
| Paypal 支付 | `Paypal/.../SmartButtonController.php`、`StandardController.php`×2、`paypal-smart-button.blade.php`×2 | 跳转 `/admin` |
| Sitemap 生成 | `Sitemap/.../Models/{Category,Page,Product}.php` | `Url::create('#')`(保留 `->setLastModificationDate()` 链) |
| 安装器 | `Installer/.../Http/Middleware/CanInstall.php` | redirect `/admin` |
| 顾客 API 中间件 | `Shop/.../Http/Middleware/AuthenticateCustomer.php`(2 处) | 非 JSON 跳转 `/admin` |
| **uni-app 结算 API** | `Shop/.../API/{Cart,Onepage,Wishlist}Controller.php` | 返回的 `redirect`/`redirect_url`/`data` 改 `/admin` |
| 订单邮件 | `Shop/.../views/emails/orders/{created,canceled,commented,invoiced,refunded,shipped}.blade.php` | 订单号链接 `route(...)` → `'#'` |
| 客户邮件 | `Shop/.../views/emails/customers/{email-verification,forgot-password,subscribed}.blade.php` | 链接 `route(...)` → `'#'` |

> 说明:这些 `route('shop.*')` 在后台/邮件/下单路径会真实执行,路由名不存在就抛异常 → 500。
> 替换为 `/admin` 或 `#` 后不再崩,后台/邮件/结算接口可正常渲染。

---

## 二、未动的部分(故意保留)

- `Shop/src/Routes/api.php` —— uni-app 用的 REST API 路由(核心)。
- `Shop/src/Http/Controllers/API/**`、`Http/Resources`、`Http/Requests`、`Http/Middleware`。
- `Shop/src/Mail`、`Listeners`(后端邮件/事件)。
- `Shop/src/Resources/lang`(API/后台翻译)、`Resources/views/emails`(邮件模板)。
- 其余所有 Webkul 包(Admin/Core/Customer/Checkout/Sales/Product/Theme ...)。
- 前台控制器/视图/资源文件都**在磁盘上但不可达**(注释路由后不再加载)。

---

## 三、验证(已实测通过)

- `php artisan route:list` → 正常启动,无异常。
- `/api/products` → **200**;`/api/checkout/cart` → **200**;`/admin` → 正常跳登录。
- 前台旧 URL `/categories`、`/` → **404**(商店已停)。
- 登录鉴权闭环:注册→拿 token→带 token 访问订单 **200**→登出 **200**→token 复用 **401** ✅。
- 扫描确认:存活生产代码中 `route('shop.*')` 死引用 = **0**。

---

## 四、还原前台 Shop(需要时)

1. 把 `storage/shop-web-backup/web.php.original` 覆盖回 `packages/Webkul/Shop/src/Routes/web.php`。
2. 把 `config/breadcrumbs.php` 的 `'files'` 改回 `base_path('routes/breadcrumbs.php')`。
3. `php artisan view:clear && php artisan route:clear && php artisan config:clear && php artisan cache:clear`。

---

## 五、已知事项

- `/api/checkout/onepage/summary` 返回 500 = 无购物车时读空 cart(`Attempt to read property "items" on null`)。
  **Bagisto 既有行为**,加购后即有值,**与本次改动无关**。
- 当前库内**无商品数据** `/api/products` 为空,故未实测「下单」全链路;但下单路径上的
  `RouteNotFoundException` 隐患已全部从代码层消除。
