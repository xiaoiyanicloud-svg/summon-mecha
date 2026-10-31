# 客服商品卡闭环（2026-10-30）

## 结论

商品详情页点击「客服」后，不再只预填一段商品名称文字，而是把真实商品作为结构化商品卡带入客服会话。用户在客服中心发送后：

1. 用户会话详情显示商品卡；
2. 后台客服工作台显示同一张商品卡；
3. 后台可点击商品卡直接进入对应商品编辑页；
4. 后台 2 秒增量同步收到的新消息也会渲染商品卡。

本轮复用 `ticket_message.attachments` JSON 列，不新增数据库迁移。

## 实现

| 位置 | 改动 |
|---|---|
| `pages/robot/index.vue` | 客服入口写入 `supportProductContext`，包含商品 ID、名称、SKU、价格、图片、业务类型；仅接收真实 HTTP(S) 或 `/storage/` 商品图 |
| `pages/mine/tickets.vue` | 客服发送区展示“随消息发送商品”卡片；发送时作为 `attachments` 一并提交；支持移除 |
| `pages/mine/ticket-detail.vue` | 会话消息里渲染商品图、名称、SKU、价格和业务类型 |
| `TicketController.php` | 兼容历史字符串附件；新增商品卡白名单校验；商品 ID 必须是后台真实商品；图片仅允许 HTTP(S) 或站内相对地址 |
| `SupportTicketController.php` | 后台增量消息为商品卡补充商品编辑链接 |
| `workspace.blade.php` | 后台工作台消息渲染商品卡，并链接到 `admin.catalog.products.edit` |
| `online-support-workbench.js` | 后台轮询新增消息时动态渲染同一套商品卡 |
| `show.blade.php` | 补充商品卡样式，并更新工作台脚本缓存版本 |

## 数据结构

```json
{
  "type": "product",
  "product_id": 17,
  "name": "机器人备用电池包 48V/20Ah",
  "sku": "TEST-ACC-BATTERY",
  "price": "899",
  "image": "https://example.com/battery.png",
  "kind": "accessory"
}
```

历史字符串附件仍然兼容，不会被本次改动破坏。

## 实测

```text
用户侧客服回归
20 passed / 83 assertions

后台客服回归
26 passed / 125 assertions
```

其中专项断言覆盖：

- 商品卡写入 `ticket_message.attachments`
- 用户会话详情返回商品卡
- 后台增量接口返回商品卡与 `admin_url`
- 后台工作台渲染商品名称、SKU、商品卡样式与编辑入口

其他验证：

- `php artisan view:cache`：Blade 模板编译成功
- HBuilderX H5 编译成功
- 真实 Chrome 390×844：商品详情点击客服后，客服页出现待发送商品卡，展示商品名、类型、SKU、价格；页面异常 0、网络失败 0
- 截图：`docs/product-actions-cart-20261030/service-context-390.png`

## 回滚

改动前备份：

```text
.claude/backup/20261030-support-product-card/
```

无数据库迁移需要回滚。恢复对应文件后重新导出 H5，并刷新后台静态资源缓存。
