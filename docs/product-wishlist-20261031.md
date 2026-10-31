# 商品收藏按钮与收藏页接入（2026-10-31）

## 结论

商品详情原「加入购物车」位置已替换为「收藏 / 已收藏」按钮，并接入真实 Bagisto Wishlist：

- 未登录点击会引导登录；
- 登录后可收藏、取消收藏，并跨请求持久化；
- 商品收藏可在「我的 → 收藏 → 商品」查看；
- 从商品收藏可返回对应租用、购买、配件或技能详情；
- 前端仍无购物车入口。

## 改动

| 文件 | 改动 |
|---|---|
| `pages/robot/index.vue` | 在原先加购位置加入收藏按钮；读取当前账号 Wishlist；支持收藏/取消收藏；从收藏列表返回时自动打开对应商品详情 |
| `utils/api.js` | 新增 `wishlistApi.list / toggle / remove` |
| `pages/ecosystem/my-favorites.vue` | 新增“商品”标签，展示 Wishlist 商品，支持取消收藏和打开详情 |
| `WishlistResource.php` | 返回 `catalog_type` 与收藏时间，供前端恢复到正确详情模式 |
| `WishlistTest.php` | 新增分类类型和 Sanctum Bearer Token 收藏测试 |

## 交互

详情底栏：

```text
客服 | 收藏 | 立即租用/立即购买
```

- 未收藏：显示“收藏”
- 已收藏：显示“已收藏”，按钮使用浅红状态色
- 未登录：提示“登录后收藏”
- 收藏成功后重新读取服务端 Wishlist，不在前端伪造状态

## 实测

```text
php artisan test packages/Webkul/Shop/tests/Feature/Customers/WishlistTest.php
9 passed / 22 assertions
```

覆盖：

- Wishlist 列表与增删
- Sanctum Bearer Token 读取和切换收藏
- 商品 `catalog_type` 返回
- 原有 Wishlist 行为回归

其他验证：

- HBuilderX H5 编译成功
- 真实 Chrome 390×844：
  - 底栏文字：`客服 / 收藏 / 立即租用`
  - 收藏按钮位于原加购位置
  - 无购物车文字
  - 未登录点击收藏出现登录引导
  - 无横向溢出、页面异常、网络失败
- Node 全量测试：88/88 passed
- `ecosystem:contract-check`：通过

## 回滚

改动前备份：

```text
.claude/backup/20261031-product-wishlist/
```

无数据库迁移需要回滚。恢复文件后重新导出 H5。
