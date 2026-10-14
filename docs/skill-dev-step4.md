# 技能平台 · 步骤 4 交付说明（交易闭环 · 除支付资质外）

> 日期：2026-10-13 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 4 步
> 状态：**不依赖支付资质的部分已交付并实测通过**；**微信支付资质到位后只补一个 `WechatGateway` 实现**。

---

## 一、为什么这么做（资质未就绪的应对）

微信支付资质（商户号/类目/收付通）未就绪 → 但"下单 → 支付 → 发授权"整条链路要能**现在开发和联调**。
**方案：支付抽象层**——业务代码只依赖 `PaymentGateway` 接口；现在用 `MockGateway` 跑通，资质到位改一行配置切 `WechatGateway`。

---

## 二、已交付

### 1 · 支付抽象层（`Services/Payment/`）
| 文件 | 作用 |
|---|---|
| `PaymentGateway.php` | 接口：createPayment / handleCallback / refund |
| `PaymentResult.php` | 支付结果值对象 |
| `MockGateway.php` | 开发替身：带 `mock_paid=1` 即算支付成功 |
| `WechatGateway.php` | **占位**：方法抛"资质未就绪"，结构已定 |
| `PaymentManager.php` | 按配置解析网关（对齐项目既有 config 注册表套路） |
| `config/payment_gateways.php` | 注册表；`default` 现为 `mock`，资质到位改 `wechat` |

### 2 · 控制器 + 路由
| 接口 | 说明 |
|---|---|
| `POST /api/payment/orders/{orderId}` | 本人订单创建支付单（返回前端参数） |
| `POST /api/callback/payment` | **支付回调（不挂鉴权）**：验签(mock)→订单置 processing→**触发 v1 授权发放（幂等）** |
| `GET/POST /api/after-sale` | 售后列表 / 发起售后 |
| `GET /api/notifications`、`PUT /api/notifications/{id}/read` | 通知中心 + 标记已读 |

### 3 · 服务层
- `AfterSaleService`：虚拟商品政策（试用不退 / 未下发可退 / 已激活需人工审）→ 通过则**冻结授权 + 退款**（冻结与打款解耦）
- `NotificationService`：站内信落库（订阅消息待小程序模板）

### 4 · 模型
`AfterSale`、`RefundRequest`、`Notification`、`NotifyTemplate`

---

## 三、实测结果（事务包裹、回滚）

| 探针 | 结果 |
|---|---|
| 当前支付网关 | `mock` ✅ |
| mock 支付单 | `out_trade_no=MOCK…` ✅ |
| mock 回调 | `paid=1, txn=MOCKTXN…` ✅ |
| 通知落库 | 建通知 #1，未读计数对 ✅ |
| 售后政策判定 | 已激活授权 → "已激活技能原则上不退"（判定正确）✅ |
| 回滚 | after_sale=0 / notification=0（无脏数据）✅ |
| 语法 | 全部 `php -l` 通过 ✅ |
| 路由 | `route:list` 已注册 ✅ |

---

## 四、待支付资质就绪后（**唯一未做的部分**）

1. **补齐 `WechatGateway`**：JSAPI 下单、回调验签、退款、**收付通/二级商户分账**（二清合规）。
2. **切配置**：`config/payment_gateways.php` 的 `default` 改 `wechat`（或 `.env PAYMENT_GATEWAY=wechat`）。
3. **联调**：真实支付 → 回调 → 授权（复用本次已验证的链路）。

> 业务代码**零改动** —— 这正是抽象层的意义。

---

## 五、备注
- **授权幂等**复用 v1 既有监听器（`SkillDelivery`，`order_item_id` 唯一键兜底），本次未改。
- 支付回调的**验签**在 mock 下省略；生产在 `WechatGateway::handleCallback` 内做（接口已留位）。
- 前端对接（结算页选支付方式、通知中心页、售后入口）属第 7 步。
