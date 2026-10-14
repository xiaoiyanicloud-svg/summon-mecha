# 测试基线（as of 2026-10-15 · commit `92885b1`）

> 本文是一份**冻结快照**，不是活文档。它的用途只有一个：让任何人在读到
> "XX 条测试失败"时能判断**这些失败是不是新引入的**。
> 复跑后数字若变化，请**新开一节并注明日期与 commit**，不要就地改写本文。

## 怎么复跑

```bash
cd D:/main/my-bagisto-store
/d/php.exe vendor/bin/pest packages/Webkul/Shop/tests     # 约 8 分钟
/d/php.exe vendor/bin/pest packages/Webkul/Admin/tests    # 约 3 分钟
```

⚠ 用 `/d/php.exe`（PHP 8.4.24）。`php` 不在 PATH 上；`phpstudy` 自带的 7.3 跑不了本项目。

## 基线数字

| 套件 | 通过 | 失败 | 断言 | 耗时 |
|---|---|---|---|---|
| `Shop/tests`（Unit + Feature 全量） | 562 | **27** | 3234 | 491s |
| `Admin/tests`（全量） | 434 | **81** | 2572 | 171s |
| **合计** | **996** | **108** | 5806 | ~11 分钟 |

## 这 108 条失败是什么

**全部是本机环境差异导致的 Bagisto 原生测试失败，与技能平台的代码无关。**
逐条核过失败类名 —— 没有一个是本项目新增的文件。

### Shop（27 条 · 5 个类）

| 类 | 条数 | 触发点 |
|---|---|---|
| `Feature/Checkout/CheckoutTest` | 14 | 下单：金额、税费、支付方式 |
| `Feature/Checkout/CartTest` | 2 | 含税/不含税价计算 |
| `Feature/API/CategoryProductTest` | 2 | 按价格排序 |
| `Feature/Customers/AccountTest` | 2 | 表单校验错误文案 |
| `Feature/HomePageTest` | 2 | 首页与对比列表 |

共同成因：**币种与税率**。本机默认渠道是 `¥ / CNY`，而 Bagisto 原生测试断言的是
USD（`$`）与美式税率。金额断言对不上，随后整条链路（下单、支付方式）就都倒了。
另有两处是**地址按 `\n` 拆行**、**中文 JSON 转义**（`\u4ea7...` vs 原始中文）的期望值差异。

### Admin（81 条 · 30 个类）

| 域 | 类数 | 典型失败动作 |
|---|---|---|
| `Marketing/*`（Communications / Promotions / SearchAndSeo） | 12 | delete / mass-delete / store / update |
| `Settings/*`（Channels / Currencies / ExchangeRates / Locale / Users / …） | 8 | 同上 |
| `Catalog/*`（Attribute / AttributeFamily / Category / Products） | 4 | 同上 |
| `Customers/*`（Customers / CustomerGroups / Review） | 3 | 同上 |
| `Reporting/*`、`Sales/Orders`、`Cms`、`Dashboard` | 4 | 统计口径 / 列表渲染 |

共同成因：**开发库里有既有数据**。这些测试跑在开发库上（`DatabaseTransactions`
只回滚本用例的写入），所以 `it should delete the existing channel` 这类用例
断言的是"删完之后还剩几条"，而库里本来就有额外的行。**这解释了为什么失败集
几乎全是 delete / mass-delete / update —— 它们是对计数最敏感的一类断言。**

## 这份基线**不**代表什么

- ❌ **不代表"平台是好的"**：它只说"这 108 条失败早于本轮改动"。项目真正的
  功能验证在 `packages/Webkul/Shop/tests/Feature/{Deploy,Dev,Ops,Payment,SkillAudit,Storage}`
  与 Admin 的审核台/运维/合规测试里 —— 那些**全绿**。
- ❌ **不代表可以放着不管**：一条长期红的基线会让**真正的回归淹没在噪声里**。
  新写的测试若失败，第一件事是回来对这份清单；不在清单上的，就是新问题。
- ⚠ **换机器/换库会变**。这份基线绑定"当前开发库 + 本机 PHP 8.4.24 + 默认渠道 CNY"。
  换到一个干净库上，Admin 那 81 条大概率会大幅减少。

## 已知的清理方向（未做）

让基线重新变绿的最短路径不是改代码，而是**让原生测试跑在独立的测试库上**
（`phpunit.xml` 指定单独的 `DB_DATABASE` + 每次跑前 migrate:fresh）。
那样 Admin 的计数类失败会自然消失。本轮没做，因为这会改动所有人的测试流程，
属于需要单独决策的事 —— 记在这里备查。

---

## 复跑快照 2（2026-10-15 · 剩余批次收口后 · 工作区未提交）

按本文开头的规矩新开一节，**不改上面那份**。

| 套件 | 通过 | 失败 | 断言 | 耗时 |
|---|---|---|---|---|
| `Shop/tests` | **579**（+17） | **27**（±0） | 3311 | 约 8 分钟 |
| `Admin/tests` | **436**（+2） | **81**（±0） | 2584 | 205s |
| **合计** | **1015** | **108** | 5895 | — |

**通过数涨了，是因为新加了测试**：Shop +17（`CmsApiTest` 9 + `DeveloperDashboardTest` 8）、
Admin +2（运营总览的下载量两条）。**失败数一条没变。**

**失败类名逐条对过，与快照 1 完全一致**：

- Shop 5 个类：`Checkout/CheckoutTest` 19、`Checkout/CartTest` 2、`API/CategoryProductTest` 2、
  `Customers/AccountTest` 2、`HomePageTest` 2 = **27**
- Admin：按域分布与快照 1 相同（`Marketing/*`、`Settings/*`、`Catalog/*`、`Customers/*`、
  `Reporting/*`、`Sales/Orders`、`Cms`、`Dashboard`）= **81**

### ⚠ 顺带更正快照 1 的一处**算术错误**

快照 1 的 Shop 分项表写 `Checkout/CheckoutTest` **14** 条，其余四个类各 2 条 ——
**分项合计 22，而总数写的是 27**，两者对不上。实测 `CheckoutTest` 是 **19** 条
（19+2+2+2+2 = 27，与总数吻合）。

> 这正是本文自己开篇要防的事：**一份对不上账的清单，比没有清单更糟** ——
> 读的人会拿分项去比对，然后得出"多出来 5 条是这次新引入的"这个错误结论。
> 快照 1 的**总数（27 / 81）是对的**，只有 Shop 的分项表里那一个数字写错了。

### 本轮修掉的一条**闪红**测试（不是新引入，是本轮发现的）

`Shop/tests/Feature/Deploy/DeviceOnlineTest > 窗口边界：89 秒前算在线，91 秒前算离线`
—— 窗口 90 秒，而用例用 89 秒造"应当在线"的那台，**从造数据到发 HTTP 断言只要超过 1 秒
就随机失败**。已用 `$this->travelTo(now())` 冻结时间修掉（边界仍取最贴近窗口的 89/91 秒）。

> 它此前一红一绿地出现在别人的复跑记录里，**会被当成"本批次引入的回归"**。
> 随机失败比稳定失败更坏，原因见本文开篇。

