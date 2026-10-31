# SC3 资源、档期和交易确认开发收尾

日期：2026-10-21（Asia/Shanghai）；基线 `1e2c5b3`。范围为 SC3-01–SC3-10。本页更新旧交接中“转换未实现”的当前状态，旧记录仍保留当时的事实。

SC3 已接通真实资源核验、已接受报价、独立商务复核、临时占用到订单和项目的事务链路。转换开关默认关闭；本轮只在本机独立环境验证，没有迁移业务库、操作真实收款、发布生产或替运营签收。

## 工作包与验收落点

| 工作包 | 已实现行为 | 本机证据与验收 |
|---|---|---|
| SC3-01 | 逐台/逐人/逐件台账、稳定来源去重、独立核验、有效期/城市/时区/班次；历史台账须补来源并重新核验 | 专项资源核验、历史资源与人员冲突；T27/T45/T66 |
| SC3-02 | 服务与计费区间分开、资源和报价取更严格缓冲、完整占用须在核验班次内、显式 offset 转 UTC | 半开相邻档、行程/休息缓冲、异地、跨日、无效日期与 DST；T35/T45/T67 |
| SC3-03 | 需求→报价→资源 ID 升序父行锁→预留当前读；容量为 1，多资源确认同事务 | MySQL 两进程抢空档、RR 旧快照与延迟获取锁；T36/T37 |
| SC3-04 | 过期 held 查询时立即不占用，扫描只释放 held；维修产生项目影响待办；晚归未核实返库的实物拒绝下一档 | 停 worker、过期重抢、两机一机故障、晚归；T38/T39/T54 |
| SC3-05 | 真实 Sales 仓储、库存模型及 SkillDelivery 监听器故障注入；记录回滚事实与事务层级 | MySQL 专项 40 项及原仓储事务 30 项；T41/T55 |
| SC3-06 | 报价唯一主单、项目、confirmed 资源、快照、任务、关联、审计、outbox、幂等回执同事务 | 跨键/重复/丢响应同单、逐点故障、两进程并发转换；T37/T40/T41 |
| SC3-07 | 价目版本×渠道真实 SKU 映射双人批准；非库存 virtual 服务费用；技能子单、冻结版本与整数尾差 | 缺失/撤销/错误映射、技能拆单/真实授权及普通租买；T30/T43 |
| SC3-08 | 新确认页、旧 checkout/TOB/直接仓储统一防绕过；普通订单列表/详情识别场景项目 | 删 from/改 type/删全部元数据、取消/支付控制器、旧缓存路由；T07/T08/T42/T58 |
| SC3-09 | 冻结线下合同或真实收款条件及截止；原件/哈希与独立复核；接受/资源/商务/款项分开；拒绝普通在线支付与取消 | 自审/跨需求/重复凭证、假付款状态及支付回调拒绝；T33/T44 |
| SC3-10 | 两客户同档、接受后 hold 到期、多机部分故障、丢响应、普通租买/技能回归 | 下列专项、回归、页面及平台编译记录；T35–T45/T58/T67 |

主要后端代码在 `Shop/src/Services/Scenes/` 的资源、排期、商务、SKU、转换、OrderWriter、SkillOrderGate、SkillFulfillment 及 LinkedOrder 服务；实际 API/Admin 控制器和监听器均已接入。客户从 `pages/scene/confirm.vue` 确认后进入项目；普通订单列表显示场景款项状态，详情转入场景项目。

## 关键行为

- 接受报价不是确认履约。转换须由需求所属登录客户提交冻结 `content_hash`、`terms_hash` 和匹配资源组，服务端重查资格、BOM、SKU、资源及商务事实。
- 报价接受期限与商务/资源确认期限独立。有效接受不会仅因旧接受期限过去失效；证据、资源状态及确认期限继续约束。旧报价没有确认条款时必须协商新版。
- 登记资源不产生可承诺容量；binding 仅引用已有设备归属。人员/配件不占机器人 binding，无明确班次不能承诺配人。
- 金额按冻结整数分进入 Bagisto；服务费用不扣机器人销售库存。主单与技能子单关联在同一事务内成立，任务与验收取报价冻结版本，不取后来修改的运行配置。
- 创建场景单不会开通技能。提交后的 outbox 只在实际收款经独立复核且足额、无退款/争议/取消暂停、技能版本有效时复用原授权体系；手改付款状态不能代替凭证，不发送设备执行命令。
- 普通支付创建/回调返回 `409 SCENE_OFFLINE_ONLY`；普通客户/后台取消要求走项目变更，不能借旧入口释放资源、伪置已付或提前撤权。SC4 变更与退款执行仍按原阶段边界推进。
- MySQL 实测捕获并修复 RR 回执读取问题：另一转换提交后，唯一关联指向的项目/订单/子单须当前读，避免成功后误报 404。
- 订单通知移至提交后的 outbox，失败保留待处理事件，不在未提交事务中提前发邮件。

## 实际验证

以下均为隔离夹具，不加载业务 `.env`，不访问真实支付、设备和邮件通道。

| 检查 | 结果 | 证据 |
|---|---:|---|
| MySQL SC3（含 5 组真实并发） | 40/40 | [mysql.json](sc3-closeout-evidence/mysql.json) |
| SQLite SC3 | 35/35 | [sqlite.json](sc3-closeout-evidence/sqlite.json) |
| 既有场景流程 | 91/91 | [regression.json](sc3-closeout-evidence/regression.json) |
| 既有订单仓储事务 | 30/30 | [order-transactions.json](sc3-closeout-evidence/order-transactions.json) |
| SC0 回归 | 11/11 组 | [sc0.json](sc3-closeout-evidence/sc0.json) |
| 控制器装载、Router、未授权入口 | 17/17 | [controllers.json](sc3-closeout-evidence/controllers.json) |
| Blade 实际编译后 PHP 语法解析 | 10/10 | [blade.json](sc3-closeout-evidence/blade.json) |
| Node 请求/目录/预检 | 34/34 | [node.tap](sc3-closeout-evidence/node.tap) |
| Chrome 实际 Vue 页面/交互 | 17 SFC、23 项、0 页面错误 | [ui-verification.json](sc3-closeout-evidence/ui-verification.json) |
| HBuilderX 原版编译器完整 H5／微信构建 | 两端退出码 0，实际产物检查通过 | [build-verification.json](sc3-closeout-evidence/build-verification.json) |

源码 `php -l`、Pint 与最终哈希清单见 `verification.json`。浏览器覆盖丢响应/残缺回执、停写查询、账号切换、旧订单缓存及 390px 窄屏；它不代替 HBuilderX 或微信真机。

MySQL 使用本机独立 **5.7.30 / InnoDB / REPEATABLE READ**，loopback 端口 23316、独立 datadir。runner 新建随机 `summon_mecha_sc3_qa_<12hex>`，仅在本次所有权标记匹配后清理。记录独立连接 ID、隔离级别、锁超时、死锁第二次尝试和 `transactionLevel=0`；没有用顺序调用或 SQLite 冒充 MySQL 行锁。故障覆盖订单头/款项/双地址/逐行/库存/授权监听器/项目/关联/任务/审计/outbox。

专项 `mysql.json` 使用实际 Sales/Core/Skill 迁移、仓储、库存及授权监听器，商品属性为最小隔离夹具列。后续新增的完整集成套件独立加载全部真实 Provider、EAV、Admin session/CSRF 与 Sanctum HTTP，补齐了此前未覆盖的应用栈；两套证据保留各自环境边界，不相互冒充。

### 剩余联调补齐

可复跑入口及部署交接见 [完整集成运行手册](SC3-Integration-Runbook.md)，最新记录见 [integration-verification.json](sc3-closeout-evidence/integration/integration-verification.json)。完整套件覆盖 217 份迁移、真实 EAV、9 个测试 Admin；107 项 HTTP、8 项 worker 恢复、10 项运行事实与 Scheduler、6 项实际 H5 检查通过。包括逐动作权限、CSRF、转单、通知失败、进程中断、真实租约到期恢复，以及真实调度子进程。它保留默认关闭的生产开关，不把 QA 合同和资源写到业务环境。

本轮额外修复：运维命令不再错误报告转换未实现；补齐 SC3 必需表和转换开关；非法消费参数在扫描前拒绝；投影失败返回非零；只读预检支持隔离环境目录并正确处理 Windows 绝对缓存路径；真实 H5 截图中确认页状态与提示的行距遮挡已修正。

### 完整 H5／微信编译

使用本机 HBuilderX 原版 Vue3 编译器 **5.26.2026091411.1611**、CLI **Node 18.20.8**、Vite 5.2.8 及对应平台包；真实前端输入逐字节复制到独立目录。H5 输出 98 个文件，微信输出 192 个文件，两端退出码 0。确认页、项目页、订单页的实际 chunk/WXML/JS 产物均检查通过，来源哈希与当前代码匹配，详见 [构建证据](sc3-closeout-evidence/build-verification.json)、[H5 日志](sc3-closeout-evidence/h5-build.log)、[微信日志](sc3-closeout-evidence/mp-weixin-build.log)。

构建通过原版 Vite/uni 插件 API 完整处理平台包；QA 目录显式包含平台依赖，并只读引用 HBuilderX 独立的 Sass 插件，不写入 IDE 或产品配置。工具在缺少页面产物时即使进程退出 0 也判失败，避免只打包 App 的假通过。早期 IDE/自带 Node 22 模式超时、CLI 缺少 Sass 的实际失败保留在 [build-attempts.json](sc3-closeout-evidence/build-attempts.json)，不计通过，也未升级本机 IDE。完整编译不等于微信真机或生产后端联调。

## 部署、存量与回退

新增第八份迁移 `2026_10_21_100008_complete_scene_resources_and_transactions.php`，增加资源来源/核验/班次、报价确认期限、预留快照及 `scene_sku_mappings`、`scene_quote_commercial`、`scene_order_parts`。它接在前七份场景迁移之后，`down()` 拒绝删除历史事实。

新增 `GET /api/scene-quotes/{id}/confirmation`；现有 `POST /api/scene-quotes/{id}/convert` 执行实际转换。历史确认查询不受停写开关限制，访问仍按需求主体与报价可见性校验。capabilities 如实返回能力。

Admin 资源工作台和报价交易面板复用 ACL：`supply` 登记，`supply_review` 核验及 SKU 审核，`reserve` 占用/释放/暂停，`finance` 录入凭证，`finance_review` 独立复核。服务同时拒绝同管理员自审，仅查看权限不能写入。

部署顺序：

1. 在隔离 checkout 合并最新代码，核对实际环境/HEAD/独立数据库身份；按序执行待迁移，再运行更新后的 `qa/scenes/preflight.php`，核对八份迁移、列、unique、路由与开关。预检只读，不批准政策或创建供给。
2. 两名不同 Admin 与测试客户完成真实会话演练。资源来源/班次和有效期须有依据并独立核验，旧 ready 不能自动算已核实；登记真实服务费用及技能 SKU 并批准。
3. 新报价冻结批准方案、整数金额、验收/工作流版本、确认条件和截止；客户接受后录入经扫描的真实合同或收款原件，独立复核再占用、确认。正式库不写测试素材。
4. 在目标环境复跑 MySQL、Admin 权限/CSRF、H5/微信、worker 通知与技能授权链。按验收结果显式开启 `SCENE_ORDER_CONVERSION_ENABLED` 及所需相关开关；代码默认 false，本轮没有修改业务环境变量。

停写时关闭转换/预留等写入口，保留历史查询、confirmed 资源、订单/项目、审计/凭证和幂等回执；不 down/truncate、不过期释放 confirmed、不对历史项目重新建单。普通订单场景守卫继续保留。

完整 Admin 会话、CSRF、EAV、HTTP 和实际消费者/调度演练已纳入本机集成。目标机器部署、正式主体/真实供给、真实商业凭证、微信真机、生产 worker/scheduler 常驻与运营签收仍必须来自目标环境事实；本轮没有把这些写成已完成。
