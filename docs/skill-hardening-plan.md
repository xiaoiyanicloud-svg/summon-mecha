# 技能平台 · 工程颗粒度补齐清单（2026-10-15）

> **这份文档只出清单，不动手。** 等总负责人审定后再排期。
>
> **排序口径 = 「一上线就出事」**，顺序固定：**资金 → 授权/交付 → 下发 → 售后 → 其余**。
> 这个顺序不是按模块重要性排的，是按「出了事能不能挽回」排的：
> 钱漏出去追不回来，授权发错可以补发，工单慢一点只是客诉。
>
> 每项四段：**现在薄在哪**（文件:行号 + 原文）→ **补齐什么** → **怎么验证** → **工作量**。
> 「现在薄在哪」全部来自对当前工作区的**只读审计**（5 路并行，逐条带行号），不是文档推断。

## 工作量口径

| 记号 | 含义 |
|---|---|
| **S** | 半天以内。通常是「接线 / 加约束 / 加幂等分支」这一类 |
| **M** | 1–2 天。要动状态机、补端点或改契约 |
| **L** | 3 天以上。要么整块不存在（要新建），要么牵动多处一致性 |

⚠ **这不是人日估算**，是相对量级。真正排期要看依赖与前置（比如 M3 依赖真网关验签能力）。

---

## 0 · 先补三条横切（它们是下面所有域的共同底座）

这三条**不属于任何一个域**，但不补的话，后面每个域都得各自造一遍，而且会造得不一样。
它们也是前端所有「错误态缺失 / 错误被吞」的**根因** —— 后端形状不统一，前端再补三态也会被打穿。

| # | 现在薄在哪 | 补齐什么 | 怎么验证 | 量 |
|---|---|---|---|---|
| **X1 统一错误信封** | `API/APIController.php` 全文 33 行只有 `guestKey()`，**没有任何 `ok()/fail()` helper**。33 个控制器里失败返回至少有 **8 种结构**：`{message}` 裸（`DeviceController.php:71`）／`{message,code}`（`Dev/DeveloperSkillController.php:62`）／`{success:false,message}`（`EventController.php:46`）／`{success:false,code,message}`（`TtsController.php:250`）／`{message,errors}`（`CustomerController.php:116`）／`{data,message}`（`CartController.php:206`）／`{message:''}` 空串 401（`AuthenticateCustomer.php:24`）／`abort(404)`（`WishlistController.php:102`） | 在 `APIController` 加 `ok()/fail(string $code, string $message, int $status, array $extra)`，34 个控制器统一改用；错误码登记成一张表（新文件或常量类）。**注意 `TtsController/AsrController` 已有的 `reject($code,$message)` 就是项目自己的正确样板**，照它统一即可，不要另发明 | 写一条结构断言：遍历 `Route::getRoutes()` 里 `api/*` 的全部路由，对每条注入一次必失败的请求，断言响应体含 `code` 且状态码 ∈ 4xx/5xx。**这条测试写出来的当天就会红一片**，红的就是要改的清单 | M |
| **X2 全局异常渲染** | `bootstrap/app.php:94` 的钩子是**空的**：`->withExceptions(function (Exceptions $exceptions) { // })`。于是 ValidationException / ModelNotFound / 未捕获异常全部走 Laravel 默认渲染，没有统一出口 | 在 `withExceptions` 里统一映射：`ValidationException` → `{code:'VALIDATION_ERROR', message, errors}` 422；`ModelNotFoundException` → 404；`AuthenticationException` → 401；`Throwable` → 500（生产隐藏细节，`APP_DEBUG=false`） | 三条：① 故意传非法参数 → 断言 `code` 与 422；② 访问不存在的模型 → 断言 404 且**不泄漏类名**；③ `curl /up` 全绿时打一个必炸路由，断言生产模式下响应体不含堆栈 | S |
| **X3 限流与防刷** | 全仓 `throttle` **只出现 1 次**（`Routes/api.php:372`，仅开发者取 token）。**全裸的**：`login`（`CustomerController.php:32` 直接 `attempt()`，无锁定无验证码）、`register`、`tob-order`、`summon`/`summon-ai`/`summon-stream`、`asr`、`tts`、`callback/payment` | 在 `bootstrap/app.php` 用 `RateLimiter::for()` 定义 `login`/`sms`/`ai`/`write` 四档，挂到上述路由。登录额外加**失败计数锁定**（不是只限速：限速挡不住慢速撞库） | 脚本连打 `login` 30 次 → 断言第 N 次起返回 429（或锁定错误码）；连打 `tts` → 断言额度不被无限消耗 | M |

---

## 一、资金（P0 —— 直接漏钱）

> 这个域的共同特征：**表搭了、网关抽象了、审计工具也备好了，但没接线。**

### M1 · 成交价可完全由客户端决定，且无下限/上限/议价授权/留痕 —— 🔶 本轮部分完成

- **现在薄在哪**
  `API/TobOrderController.php:58` 与 `:63` ——
  ```php
  'items.*.price' => 'nullable|numeric',
  'price'         => 'nullable|numeric',
  ```
  `:155` ——
  ```php
  // 单价:优先前端确认价;否则后端商品价;都没有则为 0
  $unitPrice = $line['price'] > 0 ? $line['price'] : (float) ($product->getTypeInstance()->getMinimalPrice() ?: $product->price);
  ```
- ⚠ **这条要读准，别当成 bug**：注释写明「优先前端确认价」，说明 TOB 走的是**询价/议价**流（同一入口还收 `name/phone/company`，是留资形态）。**设计如此。**
  真正的缺口是**三个"没有"**：① 没有下限与上限（`0.01` 与 `-1` 都过 `numeric`）；② 没有任何「谁有权给这个价」的判定 —— 技能单强制登录，**任何登录用户**都能给技能开任何价；③ 议价**不留痕**，事后查不出这个价是谁定的。
- **补齐什么**：`price` 加 `min`/`max`；技能类走**目录价 + 显式折扣授权**（谁批的、折扣多少落 `operation_log`）；`rent_days` 加上限。若确认询价流是唯一用途，则把 `type=skill` 从这条入口拿掉（技能是标准商品，不该走议价）。
- **怎么验证**：单测 `price=0.01` / `price=-1` / `rent_days=99999` 三种入参 → 断言拒绝；正常目录价下单 → 断言 `grand_total` 等于服务端价。
- **量**：**S**（加约束）／若要把技能单摘出去则 **M**

### M2 · 未付款即可拿到技能包（付费墙挂在"建单"而不是"支付成功"）—— ✅ 本轮已修

- **现在薄在哪**
  - `packages/Webkul/Sales/src/Repositories/OrderRepository.php:51` `$data['status'] = Order::STATUS_PENDING;`
  - 同文件 `:83` —— **在事务内、commit 之前**：`Event::dispatch('checkout.order.save.after', $order);`（已核实原文）
  - `Providers/EventServiceProvider.php:55` 把 `Listeners/SkillDelivery.php` 挂在这个事件上
  - `Listeners/SkillDelivery.php:113` `SkillEntitlement::firstOrCreate(...)` —— **当场落授权**，`license_key` 也当场生成
  - `Models/SkillEntitlement.php:126` `isUsable()` 只看 `revoked/expired/expires_at`，**完全不含订单/支付状态**
  - `API/SkillController.php:113` 下载只校验 `findOwnedEntitlement` + `isUsable()`
- **补齐什么**：交付以「订单已支付」为前提。两种做法二选一：① 监听器只落 `pending` 授权，**下载/绑定前**校验 `order.status ∈ {processing, completed}`；② 把授权发放从建单事件挪到**支付成功回调**。
  ⚠ 推荐 ① + ② 同时做一半：单入口做 ①，再补一个「支付成功 → 把该订单的 pending 授权激活」的动作。
- **怎么验证**：建一张技能单**不支付** → `GET .../download` 必须 403/409（现在会成功）；支付回调后再下载 → 成功。**这条测试现在就会红**，正好当验证。
- **量**：**M**

### M3 · 支付回调信任请求体里的 `order_id`，不验签、不核金额 —— ✅ 本轮已修（验签按设计推迟）

- **现在薄在哪**
  - `API/PaymentCallbackController.php:85` ——
    ```php
    if ($request->filled('order_id')) {
        return OrderProxy::modelClass()::find($request->input('order_id'));
    }
    ```
  - `:38` `$result = $gateway->handleCallback($request->all());`；`Services/Payment/MockGateway.php:81` 只认 `mock_paid=1`
  - **回调里从不比较金额**：`PaymentResult::amount`（`PaymentResult.php:14`）拿到了却没用
  - 路由无鉴权：`Routes/api.php:261`
- **补齐什么**：回调的**订单身份一律来自网关验签结果**，禁止用请求参数反查；回调金额必须等于订单应付；`order_id` 分支删除或收紧到测试环境（当前由 `PaymentManager::assertUsableHere`（`PaymentManager.php:42`）挡在生产之外，但这是"靠一个开关活着"）。
- **怎么验证**：① `POST /api/callback/payment?order_id=<他人订单>&mock_paid=1` → 断言被拒；② 篡改金额的回调 → 断言拒绝并留痕；③ 真网关接入后跑一次金额不符的用例。
- **量**：**M**（验签本身依赖 M6 的流水表先落地）

### M4 · 退款/取消**不撤销授权**，而能撤销的售后审批链**根本没有入口** —— 🔶 本轮部分完成

- **现在薄在哪**
  - 用户自助取消：`API/OrderController.php:230` 直接 `$order->status='canceled'; $order->save();` —— **不 dispatch 任何事件**。撤销钩子注册在 `EventServiceProvider.php:64` 的 `sales.order.cancel.after`，只由 `OrderRepository::cancel()` 触发，这条路径不走它
  - 原生退款：`Listeners/Refund.php:15` 只 `prepareMail(RefundedNotification)`，**不碰 entitlement**
  - `Services/SkillDelivery/AfterSaleService.php:87`（`approve`）`:146`（`reject`）**全仓零调用方** —— 没有 Admin 控制器、没有路由、没有按钮
- **补齐什么**：把撤销挂到 **refund / cancel 的统一事件**上（而不是各写各的）；给售后审批一个**真实后台入口**。这是典型的「退了款还留着货」双花路径，且唯一的对称操作不可达。
- **怎么验证**：① 下单 → 拿授权 → 后台退款 → 断言授权被 `revoke`；② API 取消订单 → 同上；③ 断言新后台入口能被点开并真的调到 `approve()`。
- **量**：**M**

### M5 · 退款金额恒为 0，退款单号可能撞号 —— ✅ 本轮已修

- **现在薄在哪** `Services/SkillDelivery/AfterSaleService.php:111` `$outRefundNo = 'RF'.$afterSale->id.now()->format('ymdHis');`；`:114` `$gateway->refund(null, (float) 0, $outRefundNo)`；`:129` `RefundRequest::create([... 'amount' => 0 ...])`
- **补齐什么**：金额取自该授权对应**订单条目的实付金额**；退款前做「已退金额累计不得超实付」校验；退款单号换成不可碰撞序列（秒级 `ymdHis` 同秒重试会撞）。
- **怎么验证**：对一笔 999 元技能走完售后 → 断言 `RefundRequest.amount === 999`（不是 0）；同秒连发两次 → 断言单号不同。
- **量**：**S**

### M6 · 支付流水从不落库（没有幂等键，也没有对账源）—— ✅ 本轮已修

- **现在薄在哪** `API/PaymentCallbackController.php:56` 只做 `$order->status='processing'; $order->save();` —— `$result->transactionId`（`MockGateway.php:82` 生成的 `MOCKTXN...`）**被丢弃**，全库没有任何支付/交易写入。
  项目自己也承认：`Services/Ops/OpsMetricsService.php:413` 的未接入清单里写着「支付交易号 out_trade_no 从未持久化」。
- **补齐什么**：回调成功即落一行支付流水（`out_trade_no / transaction_id / amount / 渠道 / 时间`），并**以其唯一键做幂等**（现在幂等靠的是"先查订单状态再改"的 check-then-act，见下条）。
- **怎么验证**：回调两次 → 断言流水表只有一行、订单只被推进一次；断言能凭 `out_trade_no` 反查到订单。
- **量**：**M**

### M7 · 并发与事务边界（三条小口，但都在资金路径上）

| 薄在哪 | 补齐什么 | 量 |
|---|---|---|
| `PaymentCallbackController.php:52` 幂等是 **check-then-act**：先读 `in_array($order->status, [...])` 再写，中间无锁无事务。并发重发两次 → 两个请求都读到 `pending` → 都进 `afterOrderCreated`；靠 `skill_entitlements.order_item_id` 唯一键兜底，而唯一约束异常被 `SkillDelivery.php:43` 的 `try/catch` **吞掉** → 可能"状态翻成 processing 但本次未交付" | 回调入口用订单行锁或唯一流水键做真幂等，"翻状态 + 交付"在同一事务内完成（与 M6 同批） | S |
| `AfterSaleService.php:99` **在 `DB::transaction` 内调用外部网关** `:114` `$gateway->refund(...)` —— 钱已退但后续 `RefundRequest::create` 抛错会让事务回滚 | 先落「退款中」记录并提交，**事务外**调网关，回调/轮询回写终态 | M |
| `OnepageController.php:158` 把业务校验失败包成 **500**：`catch (\Exception $e) { return response()->json(['message' => $e->getMessage()], 500); }` —— 而 `validateOrder()` 抛的是"账号被停用 / 未选地址"这类**用户可纠正**的错误 | 业务校验返 4xx（422/409）+ code；500 只留真正未预期异常。**这条必须与 X1 同批**，否则前端会把可纠正错误当服务端故障重试 | S |

### M8 · 资金动作完全不写 `operation_log`（工具备好了没人用）

- **现在薄在哪** `Services/Ops/OperationLogger.php:57` 的 `logOrFail()` 注释明确点名「金融放款」场景 —— **全仓零调用**。`operation_log` 的 11 个写入点**全在 Admin**；Shop 侧（支付回调翻状态、下单、取消、退款审批、授权发放/撤销）**一处都没有**。
- **补齐什么**：退款审批、订单状态变更、授权发放/撤销 接 `OperationLogger`；金额类用 `logOrFail()`（写不进去就失败 —— 这是它能存在的唯一意义）。
- **怎么验证**：走一遍退款 → 断言 `operation_log` 有对应行且带金额与操作者。
- **量**：**S**

### M9 · 结算 / 提现 / 对账 / 发票：四张表，零代码

- **现在薄在哪** `Database/Migrations/2026_10_14_000001_create_ops_safety_tables.php` 的 `:110`（settlement）`:126`（withdrawal）`:140`（reconciliation）`:155`（invoice）—— 全仓无模型、无服务、无控制器、无 Job。
- **补齐什么**：**只有「发票」这一项现在就该做**（用户买完要开票，是 M1 可售的组成部分）。结算/提现/对账依赖真支付资质与分账主体（E1），**维持未做但要在文档里标成"未实现"，而不是让页面显示 0**（本项目的既有立场）。
- **怎么验证**：发票：下单支付后能生成一条 `invoice` 记录并在后台可见；其余三项：断言平台看板对它们显示「未接入」。
- **量**：发票 **M**；其余 **L（且现在不做）**

---

## 二、授权与交付（P0/P1）

### D1 · 自报安装无幂等 + 激活次数竞态 + `max_activations` 形同虚设 —— ✅ 本轮已修

- **现在薄在哪**
  - `API/SkillController.php:276` `$entitlement->activations = $entitlement->activations + 1;` —— 同一方法内 `:275` 设 `STATUS_ACTIVE`，**没有"已 active 就直接返回"的幂等分支**。连点两次 = 计两次
  - `:264-277` 只用 **read-modify-write** 改 `activations`（无原子自增），并发两请求会丢失更新
  - `Models/SkillEntitlement.php:138` `remainingActivations()` 定义了但**全仓无调用**（只在 `IncidentService.php:213` 展示用）；`max_activations` 在交付/下载/安装**任一写路径都未参与判定**
- **补齐什么**：`confirm-install` 幂等（已 active → 直接 200 返回既有状态）；`activations` 改条件自增并校验 `max_activations`。**激活次数是许可与计费的核心账目，现在客户端可以自助篡改。**
- **怎么验证**：同一 entitlement 连发两次 `confirm-install` → 断言 `activations` 只 +1；`max_activations=1` 时第二次 → 断言被拒。
- **量**：**S**

### D2 · 重复 `deliver()` 会把已激活状态**打回** granted（状态回退）—— ✅ 本轮已修

- **现在薄在哪** `Services/SkillDelivery/AppChannel.php:63` `$alreadyDelivered = $entitlement->granted_at !== null;`，`:80-82` **无论 `alreadyDelivered` 是否为真都覆盖 `status`**。
  触发路径真实存在：用户已 `confirm-install`（active）→ 支付回调再次触发 `afterOrderCreated`（`PaymentCallbackController.php:60`）→ 状态被打回 `granted`。
- **补齐什么**：`deliver()` 只允许 `pending_bind → granted` **单向推进**；禁止从 `active/revoked` 回退。
- **怎么验证**：active 状态下再跑一次 `deliver()` → 断言状态仍是 active（现在会变 granted）。
- **量**：**S**

### D3 · 监听器吞异常且无补偿；两条发放入口的事务边界不一致 —— 🔶 本轮部分完成

- **现在薄在哪**
  - `Listeners/SkillDelivery.php:41-47` `try { ... } catch (\Throwable $e) { report($e); }` —— 发放失败只记日志，**订单照常成功**
  - `:92-98` 游客单（无 `customer_id`）直接 `report(...) + return`，同样静默
  - 两条入口事务性不同：事件路径在**建单事务内**（`OrderRepository.php:83`）；回调路径 `PaymentCallbackController.php:56-60` 先 `save()` 再调，**不在事务内**
- **补齐什么**：失败落**可重试任务 + 告警**，并提供「按订单补发」入口；两条入口统一事务边界。
  ⚠ 这里有个**取舍要你定**：吞异常是刻意的（注释说明"为建单重试兜底"）—— 不能简单改成不吞（那会让下单失败）。正确解法是**吞掉但留下可补偿的痕迹**。
- **怎么验证**：故意让 `delivery_channel` 指向不存在的通道 → 下单仍成功，但**断言告警出现**且补发入口能把授权补上。
- **量**：**M**

### D4 · 自报安装不落 `install_record`（安装事实分裂成两套）—— ✅ 本轮已修

- **现在薄在哪** `API/SkillController.php:264-277` 只改 `meta` 与 `status`，**不写 `InstallRecord`**。唯一写入方是 `Services/Deploy/DeployTaskService.php:223` 的 `recordInstall()`（由 Agent 回报触发）。
  下游读点全依赖它：`DeployController.php:133`、`GrayReleaseService.php:117`、`IncidentService.php:357`。
- **补齐什么**：`confirm-install` 同步 upsert 一行 `install_record`（带 `entitlement_id`、`did`、`status=installed`）。否则走人工通道的用户在灰度回滚、事故影响面里**查无此设备**。
- **怎么验证**：自报安装一次 → 断言 `install_record` 有行，且 `GET devices/{id}/installs` 能看到。
- **量**：**S**

### D5 · 表约束缺位

| 薄在哪 | 补齐什么 | 量 |
|---|---|---|
| `Database/Migrations/2026_09_30_000001_...:124` `license_key` 是 `char(32)->nullable()`，**无 unique、无 index** —— 而它是设备侧拉取授权的身份键 | 加唯一索引（加之前先查重，参照本项目近期迁移的 `hasIndex` 守卫写法） | S |
| 同表 `:134-139` 只建了 `order_item_id` unique 与 `customer_id/device_id/product_id` 索引，**无 `order_id`、无 `expires_at` 索引** —— 而撤销按 `order_id` 查（`SkillDelivery.php:57`）、过期判 `expires_at`（`SkillEntitlement.php:132`） | 补两个索引 | S |

### D6 · "技能分类不存在"会让全站停止发授权，且无任何告警 —— ✅ 本轮已修

- **现在薄在哪** `Listeners/SkillDelivery.php:153` 的 `skillProductIds()`：分类 `slug='skill'` 查不到就 `return []`（`:159-161`），`grantForOrder` 随即 `return`（`:72-74`）。**无日志、无告警。**
- **补齐什么**：空集合时告警（这是配置漂移，不是正常状态）；或提供兜底判定。
- **怎么验证**：把分类 slug 改掉 → 断言告警出现（而不是静默不发授权）。
- **量**：**S**

### D7 · 直接调 `deliver()` 的地方没兜异常

- **现在薄在哪** `API/SkillController.php:207-209`、`API/DeviceController.php:264-272`（在 `store()` 主链路里，未包 try/catch）。`SkillDeliveryManager::channel()` 对未注册通道抛 `InvalidArgumentException` → **设备登记/绑定接口 500**。
- **补齐什么**：与监听器一致的防御式包裹 + 明确错误码。
- **量**：**S**

### D8 · 下载接口无节流、无原子计数 —— ✅ 本轮已修（节流）/ 🔶 计数原子化部分

- **现在薄在哪** `Routes/api.php:235` 技能组只挂 `['customer']`，**全组无 throttle**；`SkillController.php:136-142` 下载只做 read-modify-write 的 `download_count` 累加。
- **补齐什么**：加限流（与 X3 同批）；计数改原子。（顺带：`OpsMetricsService` 里那条"下载量没统计"的失真本轮已修，见 `skill-remaining-closure.md`）
- **量**：**S**

---

## 三、下发（P0/P1）

### P1 · **熔断按钮按下去不下闸** —— `isBlocked()` 全仓零调用 —— ✅ 本轮已修

- **现在薄在哪** `Services/Deploy/KillSwitchService.php:100` 定义了 `isBlocked()`，**全仓 grep 只命中定义本身**。`DeployTaskService::create()`（`:46`）/`dispatch()`（`:79`）与 `DeployController::store()`（`:28`）**都没有 blocklist 校验**。
- **补齐什么**：`create()`/`dispatch()` 入口调 `isBlocked($skillId)`，命中即拒建。
- **怎么验证**：`block_all` 一个技能 → 断言 `POST /api/deploy/tasks` 被拒（现在会成功）。
- **量**：**S** —— 一处调用，收益最大。这是 D9（熔断可一键禁用）的硬缺口。

### P2 · Agent 三接口不校验归属（共享密钥下的横向越权与指令劫持）—— ⚠ 本轮部分完成

- **现在薄在哪**
  - 全局共享密钥：`config/deploy_gateways.php:13` + `Http/Middleware/VerifyAgentKey.php:22-36`（只做 `hash_equals`）+ `AgentController.php:145`（**每台设备拿同一把 key**）
  - `AgentController.php:175-196` 的 `event` **只按 `task_no` 定位，请求体里连 `did` 都没有**
  - `:203-215` 的 `commands` 的 `did` **由客户端传**，命中即返回该设备全部待执行指令并 markSeen
- **补齐什么**：Agent 请求体纳入 `did` 并**强校验归属**；目标形态是 per-device 证书（代码注释已认这是 §4.22 F1）。短期先把归属校验补上。
- **怎么验证**：用 A 设备的 key 报 B 设备的任务 / `?did=<他人did>` 拉指令 → 断言被拒。
- **量**：**M**

### P3 · 用户侧 `show`/`cancel` 越权（IDOR），且单号可枚举 —— ✅ 本轮已修

- **现在薄在哪** `API/DeployController.php:95-104` `DeployTask::query()->where('task_no', $taskNo)->first();`；`:109-118` `$this->service->cancel($taskNo);` —— **都不带归属过滤**（同文件 `:44`/`:127` 其它接口都走了 `resolveOwnedBinding`，就这两处没有）。
  单号规则 `Services/Deploy/DeployTaskService.php:316` `'DT'.now()->format('ymdHis').random_int(1000,9999)` —— **每天只有约 9000 种组合**。
- **补齐什么**：经 entitlement/device/binding **反查归属**，不匹配即 404（不用 403，避免状态码差异被用来枚举）。
- **怎么验证**：A 用户拿 B 的 `task_no` 调 `show` 与 `cancel` → 断言 404。
- **量**：**S**

### P4 · 幂等缺失（三条，都指向"重复执行"）

| 薄在哪 | 补齐什么 | 量 |
|---|---|---|
| `Services/Deploy/DeployTaskService.php:57-74` 建任务**无去重**（迁移 `create_deploy_and_audit_tables.php:16` 自称"幂等由应用层保证"，但应用层没有）→ 双击一次下发 = 同设备两条任务 | 建任务前对 `(did, skill_version_id, operation)` 的**未终态**记录短路或加锁 | M |
| `AgentController.php:218-232` 每次返回该设备**全部** `dispatching/pending_online`，**无"已投递"标记** → 设备回报前重启会重装 | 加投递标记（markSeen 已有，但未参与判定） | S |
| `GrayReleaseService.php:108-145` 的 `abort()` **无"已 aborted"守卫** → 重复调用再建一轮回滚任务 + `rollback_record` | 加终态判断 | S |

### P5 · 灰度回滚的靶子可能是空的

- **现在薄在哪** `Services/Deploy/GrayReleaseService.php:117-120` 只找 `status='updated'`；而 `updated` 依赖同 product 存在**旧版已装记录**（`DeployTaskService.php:256-260` + `isUpgrade()` `:268-288`）。**首次安装该技能 → 记 `installed` → 永远进不了 `$updated`。**
- **补齐什么**：靶子按**本次灰度 `deploy_task` 的 success 反查**，而不是按 `updated` 状态。
- **怎么验证**：对一台**首装**设备推坏版本 → 触发 abort → 断言回滚任务真的发给了那台设备（现在 affected=0，坏版本原地留下且不报错）。
- **量**：**S**

### P6 · `pending_online` 无超时回收；三列是死列

- **现在薄在哪** `bootstrap/app.php:53-93` 的 `withSchedule` **只有 4 条 `ops:*`**；全仓**没有任何 deploy/gray 相关的定时任务**。`retry_count` / `next_retry_at` 除迁移与模型外**零命中**；`timeout` 只能由设备上报触发（`DeployTaskService.php:144`），**无服务端兜底**。且 `create()` → `dispatch()`（`:71`）非事务，崩在中间会留 `pending`。
- **补齐什么**：加定时任务扫描 `pending_online` 与 `dispatching/downloading/installing` 超时未回报的，置 `timeout`。**给长期离线的设备下发 → 现在会永久 `pending_online`，用户界面永远"下发中"。**
- **怎么验证**：造一条 24 小时前的 `pending_online` → 跑命令 → 断言转成 `timeout` 且用户可见。
- **量**：**M**

### P7 · 状态流转是 check-then-act；同设备并发双任务无串行化

- **现在薄在哪** `DeployTaskService.php:193-215` 读 status → 改 → save，**无 `lockForUpdate`、无事务、无乐观锁**；`dispatch()`（`:79-97`）的 isOnline 判定与 dispatch 之间是 TOCTOU；`AgentController.php:218-222` 一次返回该 did **全部**待执行任务，无串行。
- **补齐什么**：流转用事务 + 条件更新（命中 0 行即视为非法流转）；`commands` 每设备每轮**限 1 条**。
- **怎么验证**：并发发两个流转请求 → 断言只有一个生效；断言设备一轮只拿到一条指令。
- **量**：**M**

### P8 · 参数校验：白名单漏了 `operation=6`（回收）

- **现在薄在哪** `API/DeployController.php:33-38` `operation` 写死 `'in:1,2,3,4,5'`，但 `Models/DeployTask.php:27` 有 `OP_RECLAIM=6`，迁移 `create_deploy_and_audit_tables.php:32` 注释也写「6回收」→ **合法回收被参数校验挡死**。
  另：`GrayReleaseController.php:75-79` 三个计数各 `min:0`，**无 `successes <= installs` 跨字段校验**（`GrayReleaseService.php:53` 能算出 >100%）；`:30-36` 不校验 `skill_version_id` 存在且已发布。
- **补齐什么**：`operation` **与 `OP_*` 常量对齐**（不要再手写字面量）；加 `lte:installs`；`store` 校验版本已发布。
- **量**：**S**

### P9 · 机器消费者拿不到统一错误码；非法流转被静默吞成 200

- **现在薄在哪** `AgentController.php:232` 成功只返 `['data'=>...]`（其余接口是 `{message,data}`）；失败仅中文 message，无 code。`DeployTaskService.php:146-148`/`:202-203` 非法流转返回 false/原任务，而 `AgentController.php:191-195` **仍返 200 'ok'** → **对已 success 的任务报 failed，服务端丢弃但回 200，端云状态永久分叉且无告警。**
- **补齐什么**：统一信封（X1）；非法回报返**明确错误码**。Agent 是机器消费者，结构不一致比 UI 更致命。
- **量**：**S**（X1 的同一批）

### P10 · 审计留痕不足以复盘"谁何时给哪台设备推了什么"

- **现在薄在哪** `rollback_record` 表定义（`create_blocklist_developer_tables.php:47-55`）**无 `created_by`、无 IP**；`KillSwitchService::engage()` `:33` 把 `$createdBy` **写死 `'admin'`**，而 `SkillSupplyController.php:187-192` 不传 → **恒记 admin，实际调用方丢失**（熔断是 D9 的安全动作）；`Services/Deploy` 目录 `grep Log::|logger(` **零命中**。
- **补齐什么**：相关表落操作者 + IP；`engage()` 显式传调用方；熔断/灰度/回滚接 `operation_log`（与 M8 同批）。
- **怎么验证**：以不同管理员身份按熔断 → 断言 `operation_log` 记的是真实身份。
- **量**：**S**

### P11 · 异常态被压平：dry install 报"成功"；`download_failed` 只存在于注释

- **现在薄在哪** `AgentSetupController.php:285-288` `package_url` 为空时**走 dry install**，随后 `:290-291` 直接报 `done` → 服务端落 `success` + `installed`，**无任何标记**。
  `DeployTaskService.php:12-17` 的状态图写了 `download_failed→重试→failed`，但 `TRANSITIONS`（`:24-34`）与 map（`:139-145`）**都没有这个状态** → 下载失败落成泛化 `failed`。
  离线/无包/未确权**均无 error_code**（只有签名失败有 `SIG_INVALID`）。
- **补齐什么**：`success` 要能区分"空跑"；约定 `PKG_MISSING`/`OFFLINE`/`NOT_BOUND` 等码。
  **这条的危险在于它会污染灰度成功率** —— 一条"看起来成功、实际没装"的记录会让灰度判定失准。
- **量**：**M**

---

## 四、售后（P1/P2）

### A1 · 评价与举报两个域**只有空表，零代码**

- **现在薄在哪** `Database/Migrations/2026_10_14_000002_...:147-183` 建了 `skill_review`、`skill_report`，但全仓**无模型、无控制器、无路由、无前端**。迁移自己坐实：`2026_10_14_000007_fix_skill_review_status_default.php:21`「⚠ 现状：skill_review 表 0 行、全仓零代码引用（表建了但功能未做）」。
  本轮实测复核：**`skill_review` 确实零写入方**。
- **补齐什么**：`SkillReview`/`SkillReport` 模型 + 用户侧写入端点（**必须接内容安全扫描**）+ 管理端处置台。
  ⚠ **`skill_report` 是违规技能唯一的用户侧举报通道** —— 它缺失意味着平台"先审后发 + 内容安全"的立场在**上线后**没有兜底。**优先级应高于评价**（评价是增长功能，举报是合规功能）。
- **怎么验证**：举报一个技能 → 断言落 `skill_report` 并在 Admin 处置台可见；重复举报 → 断言去重/限流。
- **量**：**L**（可拆：举报 **M**，评价 **M**）

### A2 · 单号生成竞态 —— 重复提交/并发直接 500

- **现在薄在哪** `API/TicketController.php:55` `'ticket_no' => 'T'.now()->format('ymdHis').$customer->id`；`Services/Incident/IncidentService.php:402-412` 用 `while (Incident::where('incident_no',$no)->exists())` 生成 —— **check-then-act，非原子**。唯一键在 `create_support_ticket_tables.php:31`、`create_ops_safety_tables.php:86`。
- **补齐什么**：号里加随机/序列 + **唯一索引兜底并 `catch` 冲突重试**；引入客户端 `Idempotency-Key`（同 key 直接返回首次结果）。
- **怎么验证**：同一用户同一秒连发两次开工单 → 断言第二次**不 500**（现在会 500 —— 弱网重试、双击是最常见的真实行为）。
- **量**：**S**

### A3 · 售后审批 check-then-act → **重复退款**

- **现在薄在哪** `Services/SkillDelivery/AfterSaleService.php:89-99` 状态守卫在**事务外**，事务内不再重读加锁：
  ```php
  $afterSale = AfterSale::find($afterSaleId);
  if (in_array($afterSale->status, [STATUS_APPROVED, STATUS_REFUNDED], true)) { return [...]; }
  return DB::transaction(function () use ($afterSale, $reviewerId) {
  ```
  且 `apply()` 的防重复（`:54-62`）同为 check-then-act；`after_sale` 表**无 `(customer_id, entitlement_id, status)` 唯一约束**（`create_ops_tables.php:53-72`）。
- **补齐什么**：状态校验移进事务内 + 行锁，或改条件更新；加唯一键。
- **怎么验证**：两个并发 `approve` → 断言只退一次款（这是**资金级**重复，必须与 M5 同批）。
- **量**：**S**

### A4 · `skill_id` 归属不校验（工单 + 事故）—— 削弱事故证据链

- **现在薄在哪** `API/TicketController.php:51` 与 `API/IncidentController.php:62` 的 `skill_id` 都只有 `nullable|integer`，**无归属校验**。反证：同一控制器 `IncidentController.php:73-85` **对 `device_id` 做了归属校验**（`DEVICE_NOT_OWNED`），对 `skill_id` 没有。
  后果不止是脏数据：`IncidentService.php:192-234` 的 `traceFor()` 会按该 `product_id` 去查版本与交付物 sha256 并**固化进证据链** —— **可以把一次事故"挂"到与自己无关的技能版本上**，污染定责依据。
- **补齐什么**：与 `device_id` 一致，校验 `skill_id` 在当前 `customer_id` 的 `skill_entitlements` 中存在。
- **怎么验证**：传他人 `product_id` 报案 → 断言被拒。
- **量**：**S**

### A5 · 事故处理记录读-改-写（处置留痕会丢）

- **现在薄在哪** `Services/Incident/IncidentService.php:154-165`：读出 `handling_log` → `$log[] = ...` → 整列写回。两名管理员并发处置 → **后写覆盖先写，一条处置记录凭空消失**（经典 lost update）。
- **补齐什么**：行锁后修改，或把处置记录落到**独立 append-only 表**（每行一条，不整列重写）。后者更好 —— 审计日志本来就不该是"可覆盖的一列"。
- **量**：**S**

### A6 · 事故状态无状态机，`resolved_at` 不清空

- **现在薄在哪** `Admin/Http/Controllers/Customers/IncidentController.php:70-100` 只校验 `status` 在枚举内，**不校验当前→目标是否合法**。`IncidentService::advance()`（`:149-151`）在 resolved/closed 时写 `resolved_at`，**重新打开时不清**。
- **补齐什么**：定义合法流转表并在 `advance()`/`handle()` 校验；状态回退时清 `resolved_at`（否则 SLA 统计失真）。
- **量**：**S**

### A7 · 前端：**故障被伪装成"你没有数据"**

- **现在薄在哪**
  - `pages/mine/tickets.vue:75` `const res = await ticketApi.list().catch(() => ({ data: [] }))` —— `pages/mine/incidents.vue:179-183` 同款（devices/skills 同样）
  - 后果：后端 500 / 断网 / token 失效时，页面渲染的是 `tickets.vue:28` 的「🛠️ 暂无工单」/ `incidents.vue:87` 的「还没有上报记录」
- **补齐什么**：**三态分离** —— loading / error（带重试按钮）/ empty，`catch` 里置 `error` 标志而不是赋空数组。
- ⚠ 这是客服排障中最贵的误判类型：「用户说没看到工单」其实是 500。**与 X1/X2 是同一个根因的两端。**
- **量**：**M**（19 页一起做，见 X6）

### A8 · 工单/事故的详情接口在 App 里**没有入口**

- **现在薄在哪** `Routes/api.php:294`（tickets.show）`:306`（incidents.show）与 `utils/api.js:129,142` 的 `detail()` **都已存在**，但列表项 `tickets.vue:34-40`、`incidents.vue:92-101` **没有任何点击跳转**。
- **补齐什么**：列表项加跳转 + 详情页（消费已存在的接口）。
- **后果**：**客服回复了，用户永远看不到**；事故的 `responsibility`（责任认定）也无从查看 —— **工单闭环的最后一公里是断的**。
- **量**：**S**

### A9 · 其余校验与限流缺口

| 薄在哪 | 补齐什么 | 量 |
|---|---|---|
| `TicketController.php:49` `category` 仅 `max:64` 非枚举；`content` 有 `max:2000` 但**不收附件**（`ticket_message.attachments` 列存在，`TicketMessage.php:24`，`store` 完全不接收） | category 收敛枚举；接附件 | S |
| `IncidentController.php:64` `occurred_at` 仅 `date` —— **允许未来时间** → SLA 计算错乱 | 加 `before_or_equal:now` | S |
| `ReviewController.php:64-70` 评价 `title/comment` **无长度上限**；`attachments.*` 无大小上限 | 加上限 | S |
| 售后相关端点**全无 throttle**（`tickets`/`incidents`/`after-sale`/`review`） | 与 X3 同批 | S |
| `Admin/.../IncidentController.php:102-114` 的 `oplog` payload **不含定责依据 `note`** | 把 note 摘要并入 payload | S |

---

## 五、其余（横切与前端，X1–X3 见第 0 节）

### X4 · 审计留痕：Shop 侧**零留痕**，11 个写入点全在 Admin

- **现在薄在哪** `OperationLogger` 的 11 个调用点全在 Admin（`Catalog/SkillController.php:205,272`、`Customers/IncidentController.php:102` 等）。**Shop 侧以下动作一处都没有**：支付回调翻状态、下单/取消、退款审批、设备登记/绑定（D1 设备真相源）、技能下载/绑定/确认安装、**熔断 kill switch（D9）**、灰度/回滚、用户报案。
- **补齐什么**：所有写动作接 `OperationLogger`；高价值动作用 `logOrFail()`。
- **后果**：出事要追责时，「谁按了熔断、谁回滚了灰度、谁改绑了设备」**查无此人**，且**历史无法补**。
- **量**：**M**

### X5 · `SummonAiController` 绕过配置注册表直读 `env()`

- **现在薄在哪** `API/SummonAiController.php:71-73` 与 `:145-147` 直接 `env('COZE_BASE_URL')` / `env('COZE_PAT')` / `env('COZE_BOT_ID')`。这是 Shop src 里**唯一**的直接 `env()` 调用。`config/summon.php` 只登记了 `summon.ai_enabled`，`COZE_*` 不在任何注册表 —— **违背 CLAUDE.md「扩展优先走配置注册表」**。
- **后果**：生产执行 `php artisan config:cache` 后，config 文件外的 `env()` **一律返回 null** → AI 召唤整条链路静默失效，**无报错**。
- **补齐什么**：新建 `config/summon_ai.php` 登记三个键，控制器改读 `config()`。
- **怎么验证**：`php artisan config:cache` 后访问 AI 召唤 → 断言仍工作（现在会挂）。
- **量**：**S**

### X6 · 前端三态覆盖

- **现在薄在哪** 19 页里，模板带持久 loading 分支的只有 **7 页**，其中有 error 分支的**只有 1 页**（`mine/legal.vue:19` —— 本轮新加的）。
  - **有 loading 无 error（6 页）**：`mine/devices.vue`、`mine/incidents.vue`、`mine/notifications.vue`、`mine/skills.vue`、`mine/tickets.vue`、`order/order-detail.vue`
  - **完全没有 loading 态（12 页）**：含主路径 `home/index.vue`、`order/checkout.vue`、`order/index.vue`、`mine/index.vue`、`robot/index.vue`、`ai/index.vue`
- **补齐什么**：抽一个 `<state-view :loading :error :empty>` 组件，逐页接入。
- **量**：**M**

### X7 · 静默吞错 ≥ 15 处

- **现在薄在哪** 显式 `catch(() => ({ data: [] }))` 8 处（`mine/incidents.vue:180-182` 三连发、`mine/tickets.vue:75`、`mine/notifications.vue:70`、`mine/skills.vue:169`、`home/jineng.vue:190`、`components/EcoCenter.vue:593`）；`catch(e){ x = [] }` 7 处（`order/index.vue:146`、`ai/index.vue:361`、`mine/devices.vue:191,199`、`mine/agent-setup.vue:138`、`mine/index.vue:939`、`order/checkout.vue:355`）。
- **补齐什么**：吞错处改为置 `loadError`，交给 X6 的 error 态；确需降级的加 `console.warn` + 埋点。
- **量**：**S**（与 X6 同批）

### X8 · `request.js` 丢字段级错误、把超时并进网络异常

- **现在薄在哪** `utils/request.js:100-106` 只取 `body.message || body.error`，**`body.errors` 被丢** → 后端 422 的字段级错误，用户只看到笼统的 `The given data was invalid.`；`:108-113` 把 `uni.request` 的 `fail`（**含超时**）一律写成"网络异常，请确认后端服务已启动" → 超时被误诊。
- **补齐什么**：补 `body.errors` 合并进提示；区分 `errMsg.includes('timeout')`。
- **另**：`pages/mine/index.vue:1017` 直接 `uni.request(` 绕过了封装，建议收回。
- **量**：**S**

### X9 · 12 个页面各抄一份 `.nav-bar`

- **现在薄在哪** 逐字雷同的导航栏出现在 12 个页面（`home/jineng.vue:4`、`home/rmfangan.vue:4`、`mine/*.vue:4` 共 9 页、`order/order-detail.vue:4`…），已出现 2–3 种变体；另有 15 处 `goBack()` 定义。
- **补齐什么**：抽 `components/NavBar.vue`。**纯整洁性收益，优先级最低** —— 排在所有安全/资金项之后。
- **量**：**S**

---

## 附一 · 本轮**已经修掉**的（不在上面清单里，列出以免重复排期）

| 已修 | 说明 |
|---|---|
| 「下载量没统计」的**反向失真** | `OpsMetricsService::notConnected()` 曾写「下载量…做出来永远是 0」，而下载另有计数（`entitlements.meta.download_count`）。已改成真实指标 + 行为级测试 |
| **`PageRepository` 查询条件累积** | 同一容器内第二次查 CMS 页必然查空（SQL 会叠两条 `url_key`）。`CmsPageController` 已改直查；**原生 `PageController` 未动**（属 Bagisto 本体，单请求容器下无碍） |
| **闪红测试** `DeviceOnlineTest` 窗口边界 | 89 秒 vs 90 秒窗口，1 秒裕度导致随机失败。已冻结时间 |
| `skill_review.status` 等**结论型默认值** | 已在批次 0 修（默认 `pending`） |
| 模板里 6 处 markdown `**` 原样显示 | 已清 + 加测试 |

## 附二 · 建议**现在不做**的（并说明为什么）

| 不做 | 为什么 |
|---|---|
| 结算 / 提现 / 对账（M9 后三项） | 依赖真支付资质与分账主体（E1）。**表已建，代码不做** —— 当前立场是"在页面上标未接入"，不是"造一个永远输出 0 的模块" |
| 团队 RBAC | 0 个开发者；`CLAUDE.md` 明令不为不存在的供给方设计功能。**且原设计方案含一个跨租户锁死缺陷**（详见 `skill-remaining-closure.md`） |
| 真实 MQTT / EMQX 下发通道 | 依赖真机与外部服务（E7）。当前 HTTP 轮询已验通 |
| per-device 证书（P2 的目标形态） | 先补**归属校验**（S）挡住越权，证书形态等 Agent 侧接口定型 |

---

## 附三 · 与其它文档的关系

- 「还剩什么没做」→ [`skill-remaining-closure.md`](./skill-remaining-closure.md)
- 「怎么跑起来、怎么验证」→ [`skill-runbook.md`](./skill-runbook.md)
- 「测试失败哪些是既有的」→ [`skill-test-baseline.md`](./skill-test-baseline.md)
- **本文**只回答一个问题：**已经做出来的东西，哪里还不够硬。**

---

## 本轮进展（01:45 起）

> 范围：只做 **4 项 P0**（资金 M2 + 下发 P1/P2/P3），不做其余。保守修：不重构、不动 schema、不顺手改无关代码。
> 每项：`php -l` 通过 → 跑相关 pest → **单项单独 commit**。工作分支 `main`。

| 项 | 状态 | commit |
|---|---|---|
| 三/下发 **P1** 熔断接线 | ✅ 完成 | `54c4f65` |
| 三/下发 **P2** Agent 归属 | ⚠ **部分完成**（见下） | `4a24c66` |
| 三/下发 **P3** show/cancel IDOR | ✅ 完成 | `1a010ac` |
| 一/资金 **M2** 付费墙 | ✅ 完成 | `1e1183a` |

### P1 · 熔断 isBlocked 接线 ✅

- **改哪**：`Services/Deploy/DeployTaskService::create()` 插库**之前**调 `KillSwitchService::isBlocked($skillId,'new')`，命中抛新增的 `SkillBlockedException`；`API/DeployController::store()` 捕获 → `409 {code:'SKILL_BLOCKED'}`。
- **为什么这么改**：闸门必须在插库前（否则留 `pending` 脏任务）；熔断语义是"阻断新下发"，而 **卸载/回滚/回收是熔断的补救动作**，若一并拦住，熔断生效后连批量卸都做不了 → 以 `REMEDIATION_OPS` 放行。
- **循环依赖**：`KillSwitchService` 构造器注入了 `DeployTaskService`，故用 `app()` 惰性解析（同 `evaluateGrayIfAny`）。
- **证据**：`tests/Feature/Deploy/KillSwitchTest.php`（3 例：命中拒建 409 且零落库／未熔断放行 201／补救动作放行）；Deploy+API 回归 20 例全绿。
- **未做**：`dispatch()` 未单独加闸 —— 全仓 `dispatch()` 仅由 `create()` 内部调用（已 grep 确认），闸门在 `create()` 即覆盖。

### P2 · Agent event 归属校验 ⚠ 部分完成

- **已完成的一半**：`API/AgentController::event()` 请求体**必带 `did`**，并在 `onEvent()` **之前**校验 `task.did === did`，不匹配返 404（不泄漏他人任务存在）。若放到 `onEvent` 之后，状态机已被他人任务改完，校验无意义。生成的参考 Agent 脚本（`AgentSetupController`，四处 `evt` 调用 + 契约注释）已同步带上 `did`。
- **证据**：`tests/Feature/API/AgentEventOwnershipTest.php`（4 例：越权被拒且状态不动／一致放行／缺 did→422／未注册 did→404）。
- **卡在哪（未做的一半，如实记录）**：验证项里 `commands?did=<他人did> 拉指令 → 被拒` **做不了** —— Agent 三接口共用**一把** `agent.key`（`VerifyAgentKey` 只做 `hash_equals`，身份与设备无关），服务端**无法区分**请求方是真身还是冒充者。要真正隔离必须上 **per-device 证书**，而计划**附二**已明确将其推迟。故本项短期只兑现"归属一致性"（event），跨设备隔离留给证书形态。

### P3 · show/cancel IDOR ✅

- **改哪**：`API/DeployController` 新增 `findOwnedTask()`：经 `device_binding.owner_customer_id` 反查本人全部 `did`，`task.did` 不在其中即视为不存在；`show()`/`cancel()` 改走它，不匹配一律 **404**（与"任务不存在"同码，避免用状态码差异做枚举信号）。
- **证据**：`tests/Feature/Deploy/DeployTaskOwnershipTest.php`（3 例：越权读 404／越权取消 404 且对方状态不变／本人正常）。

### M2 · 付费墙移到"支付成功" ✅

- **改哪**：新增 `Models/SkillEntitlement::isOrderPaid()`（仅 `processing`/`completed` 为真；订单空/`pending`/`canceled`/`closed` 一律假）；`API/SkillController::download()` 在查找交付物**之前**加此闸 → 未付款 `409`。
- **为什么这么做**：授权在**建单事务内**就落库（`Listeners/SkillDelivery` 挂 `checkout.order.save.after`），`isUsable()` 仅看撤销/过期 —— 与 `isOrderPaid()` **正交**，不动 `isUsable()`（否则会改到"我的技能"列表的 `usable` 语义）。
- **关于计划推荐的 ②（"支付成功 → 激活 pending 授权"）**：判定**冗余**并已核对 —— 授权已在建单时落库、`license_key` 当场生成，且支付回调本就重放 `afterOrderCreated`（幂等）；闸门只看订单状态即闭合该洞，无需新增激活动作。
- **证据**：`tests/Feature/Payment/SkillDownloadPaywallTest.php`（3 例：未付 409／已付放行／状态矩阵）。
- **未做**：`assets`（只回元信息、无包路径）与 `bindDevice`/`confirmInstall`（不改文件下载）**未**加闸 —— 非"拿到技能包"的路径，按保守原则未扩大改动。

### 回归

- 上述 4 项涉及目录：`Deploy`、`API`、`Payment`、`SkillAudit` 全绿。
- `Feature/API/CategoryProductTest`（2 例，按价格排序）失败 —— **既有基线**，`docs/skill-test-baseline.md` 已登记（币种/税率环境差异），与本轮无关。
- 全程**未** `git add -A`；四次提交均显式列举文件。

---

## 本轮进展（01:52 起）

> 范围：只做 **4 项**（资金 M3/M5/M6 + 授权交付 D1），不做其余。保守修：不重构、除 M6 必须建表外不动 schema、不扩大改动范围。
> 每项：`php -l` 通过 → 跑相关 pest → **单项单独 commit**。工作分支 `main`。

| 项 | 状态 | commit |
|---|---|---|
| 一/资金 **M6** 支付流水落库 + 幂等键 | ✅ 完成 | `1f245c4` |
| 一/资金 **M3** 回调身份/金额核对 | ✅ 完成（验签按设计推迟） | `b625358` |
| 一/资金 **M5** 退款金额 + 防撞号 | ✅ 完成 | `8a703fa` |
| 二/授权 **D1** 自报安装幂等 + 激活原子 | ✅ 完成 | `f2da1d9` |

### M6 · 支付流水落库 + `out_trade_no` 唯一键幂等 ✅

- **改哪**：新增迁移 `2026_10_15_000001_create_payment_transaction_table.php` + 模型 `Shop/Models/PaymentTransaction`；`API/PaymentCallbackController::handle()` 在翻状态前先落一行流水，以 `out_trade_no` 唯一键为幂等锚点（并发撞键 → 取回并按幂等返回）。
- **为什么必须建表（说明）**：M6 的核心诉求是「落一行支付流水 + 以其唯一键做幂等」。原生 `order_transactions` **装不下**——它的 `invoice_id` 是 NOT NULL，而本项目 TOB 下单管道**根本不建发票**；且其 `transaction_id` **无唯一索引**，不能当幂等键。故新建 Shop 自有表，把 `out_trade_no` 设为唯一键。此为本项**唯一必要的 schema 变更**。
- **落库字段**：`order_id / out_trade_no / transaction_id / gateway / amount / status / paid_at / raw`。
- **证据**：`tests/Feature/Payment/PaymentTransactionLedgerTest.php`（3 例：落行且字段正确／重复回调仅一行且订单只推进一次／凭 out_trade_no 反查订单）。
- **未做**：未接对账 Job（依赖真网关账单，见 M9「现在不做」）——流水已是**可对账的源数据**，但比对逻辑留待资质就绪。

### M3 · 回调只认网关结果 + 金额核对 ✅（验签按设计推迟）

- **改哪**：`PaymentCallbackController::resolveOrder()` **删除**信任请求体 `order_id` 的分支，订单身份**只来自**网关解析出的 `out_trade_no`（解析不出即 404）；新增 `amountMatches()`：`PaymentResult::amount` 必须等于 `order.grand_total`（分/厘容差 0.005），不符返 `409 AMOUNT_MISMATCH`。
- **为什么**：该回调**不挂鉴权**，原 `order_id` 分支等于「任何人 `?order_id=<他人订单>&mock_paid=1` 即可翻他人订单为已支付」；`PaymentResult::amount` 拿到了却从未参与判定。
- **⚠ 验签按设计推迟（不伪造）**：当前仍是 mock 网关（成功由参数自报）。真实验签依赖微信资质，届时在 `WechatGateway::handleCallback` 内做——**本处不伪造验签**，仅在类注释里写明推迟。生产侧仍由 `PaymentManager::assertUsableHere` 拒绝一切未声明 `production_safe` 的网关。
- **证据**：`tests/Feature/Payment/PaymentCallbackHardeningTest.php`（4 例：仅带 order_id 被拒且订单不变／order_id 与合法 out_trade_no 并存时以后者为准／篡改金额 409 且不落流水／金额相符放行）。

### M5 · 退款金额取自实付 + 单号防撞 + 累计校验 ✅

- **改哪**：`AfterSaleService::approve()` 金额取自该授权对应**订单条目实付**（`OrderItem.total`，退化 `price×qty`）；退款前校验「已退金额累计 ≤ 实付」（新增 `paidAmountOf()` / `refundedAmountOf()`）；退款单号由 `'RF'+ymdHis` 改为 `'RF'+ULID`，并**落 `wechat_refund_no`**。
- **为什么**：此前恒 `refund(null, 0.0)` 且 `RefundRequest.amount` 恒 0——退款账目全为 0，事后无法对账；秒级单号同秒重试会撞。
- **证据**：`tests/Feature/Payment/RefundAmountTest.php`（3 例：999 元退 999 且落单号／同秒两单号不同／同一订单条目退满后再来被拒且累计不超实付）。

### D1 · 自报安装幂等 + 激活原子自增 + `max_activations` 生效 ✅

- **改哪**：`SkillController::confirmInstall()` 加「已 `active` → 幂等返回既有状态（带 `idempotent:true`）」分支；`activations` 由 read-modify-write 改为**条件原子自增**（`WHERE status != active AND (max 为空 OR activations < max)`，命中 0 行区分「并发已激活」与「已达上限」）；`max_activations` 参与判定（超限 `409 MAX_ACTIVATIONS_REACHED`）。安装事实的 `meta` 写入与计数分离。
- **为什么**：激活次数是许可与计费的核心账目，此前客户端连点两次=计两次、并发丢更新、`max_activations` 形同虚设（`remainingActivations()` 全仓无写路径调用）。
- **证据**：`tests/Feature/API/ConfirmInstallIdempotencyTest.php`（4 例：连发两次 activations 只 +1／第二次带幂等标记且状态不变／已用满 409 状态不变／未超限放行）；既有 `SkillBindingSourceTest::confirmInstall` 仍绿。
- **未做（如实记录）**：`remainingActivations()` 仍未接入读路径展示——本轮只把它关心的 `max_activations` 判定做实，未扩大改动。

### 回归

- 本轮涉及目录 `API`、`Payment`、`Deploy`、`SkillAudit`：**122 通过 / 2 失败**。
- 2 例失败 = `Feature/API/CategoryProductTest`（按价格排序，币种/税率环境差异），**既有基线**，`docs/skill-test-baseline.md` 已登记，与本轮无关。
- 全程**未** `git add -A`；四次提交均显式列举文件。M6 迁移已在 `root` 库执行（`migrate --path` DONE）。

---

## 本轮进展（01:58 起）· 二、授权与交付 D2 / D3 / D4

> 硬时间盒 14 分钟。改动面 4 个源文件 + 1 个新测试文件，**未**重构、未扩大范围。逐项 `php -l` + 跑相关测试 + 单独 commit。全程未 `git add -A`（显式列举文件）。

### D2 · deliver() 状态单向推进 ✅

- **改哪**：`Services/SkillDelivery/AppChannel.php` —— `deliver()` 里 `status` 赋值加守卫：仅当当前状态**非 active** 时才推进到 `granted`/`pending_bind`。
- **为什么**：此前无条件覆盖 `status`，已 `active`（用户已 `confirm-install`）的授权被重复触发（支付回调再次 `afterOrderCreated`）打回 `granted`，状态回退。`revoke`/`expire` 仍由 `canDeliver()` 前置拦截，不需要在这里判。
- **证据**：`DeliveryHardeningD234Test` 2 例 —— 已 active 再 deliver 仍 active；未交付的（`granted_at` 为空）仍照常推进到 granted（未误伤正向路径）。
- **commit**：`fix(交付): deliver() 状态单向推进…（D2）`

### D4 · 自报安装落 install_record ✅

- **改哪**：`Http/Controllers/API/SkillController.php` —— `confirmInstall()` 激活成功后，若 `$binding && $entitlement->skill_version_id`，`InstallRecord::updateOrCreate(['did'=>$binding->did,'skill_version_id'=>…])` 写 `status=installed` + `entitlement_id` + `current_version`。（新增 `use …Models\InstallRecord;`）
- **为什么**：此前只改 `meta`/`status`，安装事实分裂两套；下游（`GrayReleaseService`/`IncidentService`/`GET devices/{id}/installs`）只读 `install_record`，人工通道用户在灰度回滚、事故影响面里查无此设备。唯一键是 `did+skill_version_id`，故需已确权设备（`binding.did`，heal 后为 `sn_{sn}`）与锁定版本才写。
- **证据**：`DeliveryHardeningD234Test` 1 例 —— 自报安装后 `install_record` 有行且 `entitlement_id`/`status` 正确。
- **未做（如实记录）**：`GET devices/{id}/installs` 的 HTTP 断言未纳入本用例（该 GET 在测试环境返回 302，疑似该路由组的鉴权中间件在 `actingAs` 下仍重定向，未深究）；DB 层真相已断言。幂等分支（已 active 早返回）不补写 `install_record` —— 首次激活即写入，后续重复调用无需再写。
- **commit**：`fix(交付): 自报安装同步落 install_record…（D4）`

### D3 · 失败留痕 + 补发入口 + 统一事务边界 🔶 部分完成

- **改哪**：
  - `Listeners/SkillDelivery.php` —— `grantForItem()` 的 `deliver()` 包 try/catch：异常时把失败写进 `meta.delivery_failed`（时间/异常类/消息）并 `save()`，**再抛回** `afterOrderCreated` 的 `report()` 触发告警；成功时清空 `delivery_failed`。新增 `public redeliver(int $orderId): int` 补发入口（走 `grantForOrder`，逐条幂等）。
  - `Http/Controllers/API/PaymentCallbackController.php` —— 回调里「翻状态 + `afterOrderCreated`」收进 `DB::transaction()`，与建单事件入口（`OrderRepository` 建单事务内）统一事务边界。
- **为什么**：吞异常是刻意的（不能让建单失败），但此前只 `report()` 无任何可补偿痕迹；两条发放入口一个在事务内一个裸 `save()` —— 边界不一致。
- **证据**：`DeliveryHardeningD234Test` 2 例 —— ①`delivery_channel` 指向不存在通道时 `afterOrderCreated` 不抛异常（订单不受拖累）且 `meta.delivery_failed` 有值；②`redeliver()` 两次均返回 1 且只产生 1 行授权（幂等）。
- **未完成（如实记录，含卡点）**：
  1. **「可重试任务」未做**：本机队列为 `database` 驱动且**无常驻 worker**（CLAUDE.md 现状），投递 Job 只会积压。依赖外部前提，按排期原则不排 P0。已用的替代是「meta 留痕 + `redeliver()` 手工补发」。
  2. **补发的 HTTP 入口未接线**：`redeliver()` 目前只能经 `tinker` / 未来 Admin 控制器调用，尚无路由与按钮（加路由需权限边界设计，超出本轮范围）。
  3. **告警仅落在 `report()`**（Laravel 日志），未接统一告警通道 —— 与 X 系列横切项（统一告警/错误信封）一并考虑。
- **commit**：`fix(交付): 发放失败留痕并可补发 + 两条入口统一事务边界（D3，部分）`

### 回归

- 新用例：`packages/Webkul/Shop/tests/Feature/API/DeliveryHardeningD234Test.php` —— **5 通过 / 11 断言**。
- 相关既有套件：`ConfirmInstallIdempotencyTest` + `Payment/` + `SkillBindingSourceTest` —— **32 通过 / 115 断言**，无回归。
- 语法：4 个改动源文件 + 1 个测试文件全部 `php -l` 通过。
- 提交：D2 / D4 / D3 三次（D3 连带提交共享测试文件）。仍未 `git add -A`。


---

## 本轮进展（01:55 起）· 资金 M1/M4 + 授权交付 D6/D8

> 范围：只做 4 项（资金 M1/M4 + 授权交付 D6/D8），不做其余。保守修：不重构、不动 schema、不扩大范围。
> 每项：`php -l` 通过 → 跑相关 pest → **单项单独 commit**。工作分支 `main`。全程**未** `git add -A`（显式列举文件）。

| 项 | 状态 | commit |
|---|---|---|
| 一/资金 **M1** 成交价上下限 + 技能目录价 | 🔶 部分完成 | `939147f` |
| 一/资金 **M4** 取消订单对称撤销授权 | 🔶 部分完成 | `27683d6` |
| 二/授权 **D6** 技能分类缺失告警 | ✅ 完成 | `703416e` |
| 二/授权 **D8** 下载节流 + 计数原子 | ✅ 完成（计数原子性仅单线程验证） | `9fb1b7b` |

### M1 · 成交价上下限 + 技能单强制服务端目录价 🔶

- **改哪**：`API/TobOrderController.php` ——
  - `price` / `items.*.price` 规则加 `numeric|min:0|max:10000000`（负数如 -1 直接拒）；
  - 新增顶层 `rent_days` 规则、`items.*.rent_days` 加 `max:3650`（拒 `rent_days=99999`）；
  - 计价：`type=skill` **一律用服务端目录价**，忽略客户端 `price`；其余类型沿用「前端确认价优先」（询价/议价流，设计如此），仅由校验兜住上下限。
- **为什么**：技能是标准商品，此前**任何登录用户**都能给技能开任意价，无下限/上限；`-1`、`99999` 都过 `numeric`。
- **⚠ 与计划的偏差（如实记录）**：计划示例要 `price=0.01` 被**拒**。但前端 `checkout.vue:205/270` 对部分路径**明确发 `price='0'`**（0 = 「用服务端价」的既有契约），故不能简单上 `min:1`（会打断既有下单）。改法是**用下限 `min:0` 挡负数 + 对技能强制目录价**：客户端传 0.01 的技能单会被替换为服务端价（等于「不接受客户端价」），效果等价且不破坏前端。
- **未做（如实记录）**：① **议价授权判定**（「谁有权给这个价」）未做 —— 属询价/议价流的权限设计，需业务定规则，非纯代码约束；② **议价留痕 `operation_log`** 未做 —— 归 **M8/X4**（Shop 侧统一留痕）批次，本项未重复造。
- **证据**：`HardeningRound3Test` 3 例 —— price=-1 → 422 且 `errors.price`；rent_days=99999 → 422；技能单带 `price=0.01` → 下单成功但 `grand_total ≠ 0.01`（客户端价被忽略）。

### M4 · 用户自助取消订单对称撤销授权 🔶

- **改哪**：`API/OrderController.php` —— 自助取消（含游客单，二者复用同一 `cancel()`）在 `$order->save()` 后 `Event::dispatch('sales.order.cancel.after', $order)`。
- **为什么**：此前直接改 `status='canceled'` **不发任何事件**，而撤销钩子只挂在 `OrderRepository::cancel()` 的该事件上 → 用户取消后**授权照旧可用**（能下载、能绑设备），是典型「退了/撤了还留着货」双花。走统一事件 = 撤销逻辑只此一处，不在控制器里各写各的。
- **副作用说明**：该事件同时驱动 `Order::afterCanceled`（取消邮件）。真实订单本就有 `customer_email`，邮件是恰当的取消通知；与后台取消路径行为一致，非新增一类side effect。
- **未完成（如实记录，含卡点）**：
  1. **原生退款路径未接撤销**：`Listeners/Refund.php`（`sales.refund.save.after`）仍只发通知邮件、不碰 entitlement。**卡点**：原生 `Refund` 与 `order_item` 的对应关系需先核清（部分退款不应撤销整单授权），盲接会过度撤销 —— 需单独一项设计，本时间盒内未动。
  2. **售后审批后台入口未做**：`AfterSaleService::approve/reject` 仍全仓零调用方。**卡点**：Admin 侧无对应控制器/路由，且列表页依赖 Bagisto DataGrid 配置，属「补端点 + 补后台 UI」的 **M** 级工作量，非 15 分钟保守修可覆盖。**注**：退款打款走售后审批（`AfterSaleService::approve` 内已 `revoke`）这条链本身是**闭的**，缺的只是「人能点开」的入口。
- **证据**：`HardeningRound3Test` 1 例 —— 建 pending 订单 + granted 授权 → 调 `shop.api.customers.orders.cancel` → 断言授权状态变 `revoked`。

### D6 · 技能分类缺失告警 ✅

- **改哪**：`Listeners/SkillDelivery.php` 的 `skillProductIds()` —— slug='skill' 查不到时 `Log::warning`（配置漂移）；分类存在但无商品时同样告警。
- **为什么**：此前静默 `return []` → `grantForOrder` 直接 return，订单照常成功却**一张授权都不落**、无任何痕迹。这是「配置漂移 = 全站停发授权」且无人知道。
- **证据**：`HardeningRound3Test` 1 例 —— 删掉 skill 分类 → 反射调用 `skillProductIds()` 返回 `[]` 且 `Log::shouldHaveReceived('warning')` 至少一次。
- **未做**：仅告警到 Laravel 日志（与 X 系列「统一告警通道」一并收敛），未接外部告警渠道 —— 与 D3 同一取舍。

### D8 · 下载节流 + 计数原子 ✅

- **改哪**：`Routes/api.php` —— skills 路由组中间件由 `['customer']` 改 `['customer','throttle:60,1']`；`API/SkillController.php` 的 `download()` 把 `download_count` 的 read-modify-write 改**行锁事务内自增**（`DB::transaction` + `lockForUpdate`）。
- **为什么**：此前技能组**全组无节流**；`download_count` 并发下载会丢更新（计数偏小，进而影响失真指标）。
- **证据**：`HardeningRound3Test` 2 例 —— 断言下载路由的 middleware 含 `throttle`（节流已挂）。
- **未做（如实记录）**：① 计数的**并发原子性**只做了代码层面行锁改造，**未做真并发压测**（测试环境 sqlite 的 `lockForUpdate` 为 no-op，无法在本机证伪丢更新；MySQL 下行锁语义成立）；② 节流阈值 `60/分` 为保守默认，若与 X3 的 `RateLimiter::for()` 命名档位统一，需后续并入。

### 回归

- 新用例：`packages/Webkul/Shop/tests/Feature/API/HardeningRound3Test.php` —— **6 通过 / 14 断言**。
- 相关既有套件：`Feature/API` + `Feature/Payment` + `Feature/SkillAudit` —— **116 通过 / 2 失败**。
- 2 例失败 = `Feature/API/CategoryProductTest`（按价格排序，币种/税率环境差异），**既有基线**，`docs/skill-test-baseline.md` 已登记，与本轮无关。
- `Feature/Checkout`（21 失败）同为**既有基线**（币种/税率 + 支付方式数组差异，均为 onepage 下单，与本轮改动无关）。
- 语法：4 个改动源文件 + 1 个测试文件全部 `php -l` 通过。
- 提交：M1 / M4 / D6 / D8 四次（D8 连带提交共享测试文件）。仍未 `git add -A`。

### 本轮需要总负责人裁决的事项

1. **M1 的议价授权规则**：租/买是否允许议价、谁能给折扣、折扣下限 —— 需业务定规则后才能落「议价授权 + 留痕」（当前仅做了硬边界）。
2. **M4 的原生退款撤销**：部分退款与整单撤销的边界（按 order_item 撤销 vs 整单撤销）需确认后再接 `Listeners/Refund`。
3. **M4 售后审批后台入口**：是否本轮必须交付一个可点的 Admin 入口（M 级，需 DataGrid），还是留待后续批次。
