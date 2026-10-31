# 前端购物车移除（2026-10-31）

## 结论

用户端购物车已完整移除，商品详情现在保留「客服」「收藏」和「立即租用 / 立即购买」直接下单入口。

## 改动

| 文件 | 改动 |
|---|---|
| `pages/robot/index.vue` | 删除购物车图标、数量角标和“加入购物车”按钮；原加购位置改为收藏按钮；移除购物车状态、加购方法与轮询 |
| `pages/order/cart.vue` | 删除购物车页面文件 |
| `pages.json` | 移除 `pages/order/cart` 页面注册 |
| `pages/order/checkout.vue` | 撤销购物车结算模式、购物车清单、草稿清理和隐藏的 `ensureInCart()` 代码；确认订单回到直接下单 |
| `utils/api.js` | 删除用户端 `cartApi` 导出 |

## 保留边界

后端 Bagisto 原生 `/api/checkout/cart` 接口与历史购物车数据未删除。用户端已没有任何入口或调用；保留后端接口避免破坏 Bagisto 原生能力及历史数据。

## 验证

- `pages.json` JSON 校验通过
- 相关 Vue 脚本与 `utils/api.js` 语法检查通过
- HBuilderX H5 编译成功
- 导出产物中不再包含 `pages/order/cart` 或“购物车”用户入口
- 真实 Chrome 390×844 检查商品详情底栏：

```text
底栏文字：客服 / 收藏 / 立即购买
主操作按钮数量：2（收藏 + 主操作）
购物车文字：不存在
横向溢出：无
页面异常：0
网络失败：0
```

## 回滚

改动前备份：

```text
.claude/backup/20261031-remove-cart/
```

恢复对应文件后重新导出 H5。无数据库迁移需要回滚。
