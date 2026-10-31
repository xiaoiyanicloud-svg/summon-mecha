> **2026-10-31 后续变更：用户端购物车已移除，本文中的购物车页与入口仅作历史记录。**

# 商品详情操作区与真实购物车/客服闭环（2026-10-30）

## 结论

租赁、购买、配件三类商品详情页的「加入购物车 / 立即操作」按钮已放大为 **220 × 112 rpx 横向长方形，按钮文字 30rpx，按钮内不放图标**；左侧客服与购物车入口改为深蓝线性图标，并分别接入真实客服中心和真实购物车页。

同时修复了一个经接口复现的既有断点：游客购物车原先依赖 PHP Session，API 请求之间没有稳定会话，表现为 **“加入购物车成功，刷新后为空”**。本次以持久化 `X-Guest-Key` 绑定游客购物车，新增购物车页，并接通“购物车 → 确认订单 → `/api/tob-order`”链路。

## 改动

| 文件 | 内容 |
|---|---|
| `pages/robot/index.vue` | 详情底栏改为两个横向长方形主按钮；客服/购物车改为线性图标与“客服 / 购物车”文案；租赁加购先选租期；购物车图标打开购物车页；客服携带商品上下文进入客服中心；展示购物车数量角标 |
| `pages/mine/tickets.vue` | 支持从商品详情带入 `category` 与咨询内容，进入客服中心后自动预填 |
| `pages/order/cart.vue` | 新增真实购物车页：读取条目、改数量、删除、业务类型分流、同类型全选、合计、去结算 |
| `pages/order/checkout.vue` | 新增 `cartMode`：展示购物车商品清单，按行传数量/租金天数，提交后清理已下单条目 |
| `pages.json` | 注册 `pages/order/cart` |
| `CartController.php` | 游客购物车按 `X-Guest-Key` 解析；登录用户按 `customer_id` 解析；加购写入业务上下文；更新/删除校验条目归属 |
| `CartItemResource.php` | 增加 `product_id / sku / order_type / rent_days / rent_period / start_date / product_type` |
| `2026_10_30_000001_add_guest_key_to_carts_table.php` | 给 `cart` 增加可空 `guest_key` 与 `(guest_key, is_active)` 索引，不改历史行 |
| `CartApiPersistenceTest.php` | 覆盖游客购物车跨请求持久化、数量更新、不同游客隔离与租赁周期元数据 |

### 结算边界

购物车允许同时保存租赁、购买、配件，但不同业务类型必须分开结算。这是刻意约束：三类订单的计价、履约和售后语义不同，不能混进同一个 `order_type`。购物车页会明确提示，并按当前业务类型选择可一起结算的商品。

## 实测证据

### 后端

```text
php artisan test packages/Webkul/Shop/tests/Feature/API/CartApiPersistenceTest.php
3 passed / 21 assertions
```

```text
php artisan test packages/Webkul/Shop/tests/Feature/Checkout/CartTest.php \
  --filter="should add a simple product to the cart for guest user|should add a simple product to the cart for customer|should display the cart items for a guest user"
3 passed / 59 assertions
```

本机真实接口复现：

```text
加购后查询：items=1
product_id=17
order_type=accessory
清理后：cart-empty
```

迁移状态：

- 独立测试库 `summon_mecha_step10_test`：Ran（Batch 11）
- root：Ran（Batch 54，仅 additive）

### 前端与浏览器

- HBuilderX H5 编译成功。
- Node 全量测试：**88/88 passed**。
- `php artisan ecosystem:contract-check`：通过（45 public routes / 85 error codes）。
- 四个改动页面 `<script>` 均通过 `node --check`。
- 真实 Chrome，390×844：
  - 上一轮验收截图中的按钮为 `93 × 58`；按用户最终要求，代码已继续加长至 `220 × 112rpx` 并移除按钮内图标，本轮不再导出截图；
  - 客服入口进入 `#/pages/mine/tickets`，输入框预填“咨询配件「机器人备用电池包 48V/20Ah」：”；
  - 配件加购后进入 `#/pages/order/cart`，显示真实商品、SKU、价格与数量；
  - 「去结算」进入 `#/pages/order/checkout?cartMode=1`，呈现配件清单与 ¥899 合计；
  - 页面异常 `0`，网络失败 `0`；
  - 客服页的两条 401 来自验收脚本故意写入的失效测试 token，用于验证未登录/失效态不会伪造客服数据；真实登录态使用后端 token。

截图与机器可读结果：

- `docs/product-actions-cart-20261030/rent-detail-390.png`
- `docs/product-actions-cart-20261030/buy-detail-390.png`
- `docs/product-actions-cart-20261030/accessory-detail-390.png`
- `docs/product-actions-cart-20261030/service-context-390.png`
- `docs/product-actions-cart-20261030/cart-page-390.png`
- `docs/product-actions-cart-20261030/checkout-page-390.png`
- `docs/product-actions-cart-20261030/result.json`

## 未做 / 边界

- 浏览器验收停在「确认订单」页，**没有提交真实业务订单**；提交仍走既有 `/api/tob-order` 真实入口。
- 支付资质、真实微信支付、客服人员在线值守不在本次范围；本次只保证入口、数据与页面链路真实，不伪造客服在线或支付成功。
- 购物车混类结算不提供“一单混合三类商品”，按上面的业务边界拆分。

## 回滚

改动前备份位于：

```text
.claude/backup/20261030-product-detail-actions-cart/
```

回滚页面时请按文件恢复到对应路径，然后重新导出 H5。新增购物车页回滚还需删除 `pages.json` 中的 `pages/order/cart` 注册；数据库回滚命令为：

```bash
cd D:/main/my-bagisto-store
php artisan migrate:rollback \
  --path=packages/Webkul/Shop/src/Database/Migrations/2026_10_30_000001_add_guest_key_to_carts_table.php
```

不要执行 `migrate:fresh`、`truncate` 或 `seed`。
