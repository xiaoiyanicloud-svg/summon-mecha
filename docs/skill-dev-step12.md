# 技能平台第 12 步：可信技能供给与购买资格闭环

日期：2026-10-18。对应最新行业调研路线图 A1b。面向开发者与厂商，先保证审核发布的技能能够被准确发现、说明与购买，为后续机型适配证据和供需协作建立可靠入口。

本批状态：代码实现与本地隔离验收完成；未发布到生产，未完成小程序、真实支付或真机验收。不能据此宣布整个技能平台、厂商适配或 Agent 真机链路完成。

## 1 为什么优先做这一项

此前默认目录、搜索和前端演示数据各有口径，技能详情进入订单时还可能使用普通购买类型。只优化卡片和文案，会保留“找到了但没有发布版”“下架后仍能下单”“示例看起来可以购买”的问题。开发者生态需要先有可信的供给入口，再扩大机型覆盖与需求撮合。

本批沿用 uni-app Options API、Bagisto/Laravel 模块化单体和既有人工交付链路，不新增厂商认证、结算体系、跨品牌编译器或未经真机验证的自动安装承诺。行业与战略依据见 `skill-platform-research-2026-10-18.md`。

## 2 功能及下级分支

| 一级能力 | 二级流程 | 三级、四级行为与闭环 |
|---|---|---|
| 真实供给资格 | 公共目录与搜索 | 技能分类存在、当前语言与渠道公开、有非负明确价格、存在 published 版本且未熔断；草稿、仅归档、隐藏、缺价格、已移除分类的历史技能均不进入买家目录 |
| 统一查询 | 聚合、分类、关联、搭售 | 在 SQL 分页与数量统计之前排除不可售技能；普通硬件继续使用原规则；Admin 列表不受买家过滤影响 |
| 真实技能市场 | 首页生态中心、机器页技能 | 共用 SkillMarket；关键词、真实筛选候选项、分页、去重、末页、刷新；快速查询与切页的过期响应不能覆盖新结果 |
| 页面状态 | 加载、空、异常 | 首屏加载、真实零供给、筛选无结果及清空；筛选字典故障和目录故障分别重试；返回页面重新核验目录 |
| 示例隔离 | 保留既有创意样例 | 独立折叠区、明确“不可购买”；只说明能力方向，不显示可交易身份、价格或适配承诺，不生成购买链接 |
| 详情 | 商品与当前版本 | 用真实商品 ID 同时取商品与版本；缺有效 ID、下架、无发布版均明确错误；没有图片时显示占位，破图移除；不再选择假默认商品 |
| 详情子功能 | 评价、安全告知、设备 | 评价与风险各自加载、错误和重试；忽略过期响应；游客设备区提示登录，登录返回后重载；购买前重新读取风险说明，失败阻断继续 |
| 版本历史 | 回退后的旧发布版 | 历史限制 20 条，若当前发布版未包含则额外返回它；新增 current_version_id，最多 21 条，避免大量归档挤掉当前版 |
| 购买 | 技能订单入口 | 详情统一进入 type=skill；登录、服务端价格、已有优惠券与订单机制继续生效；把技能伪装成 buy/rent/accessory/plan/custom 均无法绕过 |
| 交易边界 | 购物车与最终结账 | 加入购物车时校验；最终建单前再次校验；多商品中混入不可售技能时整体拒绝，不生成半张订单 |
| 并发撤回 | 数据库事务与锁 | 有序锁定商品，持锁到订单创建；版本、分类、公开索引和熔断使用当前读取，避免 MySQL REPEATABLE READ 旧快照放行已经撤回的技能 |
| 免费技能 | 保存到购买 | 修复 price 类型使用 empty() 将数值 0 变成 null 的底层缺陷；0 保持免费，空字符串仍表示未定价；实测保存、索引、详情和零元订单 |

“存在发布版”沿用现有发布审核闸门，是平台销售资格，不等于这批代码重新证明了每个包已通过厂商认证、真机安全测试或所有运行依赖验证。机型筛选目前仅按交付通道初筛，前后端均保留明确说明。

## 3 实现位置与契约

- 新增 `Shop/Services/SkillDelivery/SkillMarketService.php`：发布版选择、公开供给与购买资格共用入口。已有授权下载和 Admin 不走这套买家资格过滤。
- `SkillCatalogController`：真实公开列表、图片与摘要、分页元信息、仅 active 机型候选项、当前版本补齐。
- `CatalogController`、`ProductController`：聚合、商品列表、详情与关联商品接入同一资格。
- `CartController`、`OnepageController`、`TobOrderController`：加入购物车与最终下单校验，商品锁持有到建单结束。错误码复用 SKILL_BLOCKED、SKILL_LOGIN_REQUIRED，新增 SKILL_UNAVAILABLE 和 SKILL_ORDER_TYPE_MISMATCH。
- `utils/skill-market.js`、`components/SkillMarket.vue`：状态机、分页、请求失效控制、真实详情 URL；首页、机器页接入同一组件。
- `pages/home/jineng.vue`：真实详情、版本、状态、风险重读及统一技能购买类型。

升级 Bagisto 时须保留两处通用核心扩展：

1. `ProductRepository` 的 exclude_ids 在原数组/字符串外支持 Query Builder 子查询。核心仓库没有引用技能表；业务 SQL 仍由 Shop 服务传入。买家 API 当前固定用数据库搜索，确保下架过滤和数量统计即时一致。未来恢复 Elasticsearch 前，需要提供等价的实时资格约束与分页验收，不能直接切回异步索引结果。
2. `ProductAttributeValueRepository` 仅将 price 的空字符串转成 null，不再将合法 0 当作空值。其他日期、布尔及文件保存逻辑保持原有契约。没有对业务历史数据执行批量修复。

搜索响应新增 pagination.page/per_page/has_more、market_eligible、image、summary 与 compatibility_basis。page 为 1–10000，limit 为 1–40；既有默认 limit 40 保留，前端分页用 12。原 data 数组契约保留。

## 4 实测与限制

| 检查 | 结果 | 证据与边界 |
|---|---|---|
| 技能联合 Pest 回归 | 76 passed，394 assertions | Step12 新行为 + Step11 目录 + B9 筛选 + B16 版本 + B18 优惠券 + S8 熔断；全部在 summon_mecha_step10_test，事务测试自动回滚 |
| 前端状态测试 | 9 passed | 真实空与异常、重试、请求乱序、分页与重复请求、隐藏失效、非法响应、示例导航、零价与缺价 |
| 两进程撤回竞态 | 通过 | 买方已看到 published 旧快照，等待写方锁约 700ms 后得到 SKILL_UNAVAILABLE；真正独立 PHP/MySQL 连接，非单事务模拟 |
| H5 编译与浏览器 | 通过 | HBuilder Vue3 5.26；390×844、2 倍截图；真实接口13条独立测试样本，验证分页、筛选清空、筛选字典重试、目录故障重试、示例隔离、详情风险与评价重试、购买类型、下架与空状态、首页入口；无未捕获 JS 异常 |
| 仓库 API 体检脚本 | 7 通过、0 失败 | 隔离测试后端 8028，包含线索下单；测试订单与样本按精确 ID 清理 |
| PHP 语法/路由/迁移 | 通过 | 14 个 PHP 文件；API 路由146条；只读取 migrate:status，没有新迁移或业务数据迁移 |
| Tinker 与差异校验 | 通过 | 本地业务库可见技能0、published版本0；diff --check 无错误 |
| 原生商品扩展回归 | 8 passed、2 failed | CategoryProductTest 的价格升序、降序断言失败；使用修改前的三个原类重跑，同样8通过、2失败。既有问题未在本批修复，不能宣称全仓测试全绿 |

旧测试样本同步：S8 和 B18 的可售技能样本补 published 版本与公开索引；B16 样本使用真实公开商品，未发布期望由空历史调整为明确不可用。优惠券“非技能订单”用例改为真正非技能商品，保留原422断言。未通过删除失败用例或放宽销售资格来获得通过。

测试后端与浏览器样本仅在隔离测试库；真实业务库没有写入演示技能、开发者或厂商合作数据。截图中的“验收样本”不代表已有供给。

完整日志、截图、文件清单与回滚脚本在本次交付 outputs。可复验命令：

```powershell
Set-Location -LiteralPath 'D:\CodexDeploy\summon-mecha\my-bagisto-store'
& 'D:\java\php\php.exe' -c 'D:\CodexDeploy\summon-mecha-runtime\php.ini' -d max_execution_time=0 vendor/bin/pest packages/Webkul/Shop/tests/Feature/API/SkillMarketClosureStep12Test.php packages/Webkul/Shop/tests/Feature/API/SkillCatalogIntegrityStep11Test.php packages/Webkul/Shop/tests/Feature/API/SkillCatalogFilterB9Test.php packages/Webkul/Shop/tests/Feature/API/SkillVersionHistoryB16Test.php packages/Webkul/Shop/tests/Feature/API/SkillCouponB18Test.php packages/Webkul/Shop/tests/Feature/Deploy/KillSwitchSaleStopTest.php --compact
Set-Location -LiteralPath 'D:\CodexDeploy\summon-mecha'
& 'C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' --test tools/tests/skill-market.test.mjs
```

## 5 备份与回滚

修改前文件备份至 `.claude/backup/skill-step12-20261018/`，保留仓库相对路径；应用前比较原始 SHA256 和本批已应用 SHA256，发现他人并发改动即停止，不覆盖。本批未提交 Git，未执行 reset、clean 或全仓 add。

交付的 `rollback-skill-step12.ps1` 默认只检查并输出计划；加 -Apply 才回滚。它先验证所有目标仍等于本批交付 SHA256、备份仍等于修改前 SHA256，再恢复18个原文件，将5个新增代码/测试文件移到专属 quarantine，保留可恢复记录。只覆盖本批23个代码与测试文件，文档保留。若后续修改过目标，脚本拒绝覆盖，应人工合并反向差异。

```powershell
& 'C:\Users\Administrator\Documents\Codex\2026-10-18\ni\outputs\rollback-skill-step12.ps1'
# 确实需要回退时：
& 'C:\Users\Administrator\Documents\Codex\2026-10-18\ni\outputs\rollback-skill-step12.ps1' -Apply
```

没有数据库回滚动作；发布前仍需按实际部署环境编译 H5/小程序并执行对应验收。

## 6 未做与下一项

本批未做真实支付、小程序验证、规模与故障压测、Elasticsearch 等价过滤、真实厂商技能包与真机适配验证。原生商品两项价格排序失败保持待修复；历史下载测试曾出现的瞬时404不能凭本次通过宣布已定位解决。

下一项优先 A3“可解释的机型与产物适配证据”：具体机型及开发版、固件/SDK/运行依赖、厂商原生载体、验证来源与时间、未知项和阻断原因。先收紧契约，再贯通开发者提交、审核、详情和部署预检，完成一个配置的证据链后再扩展。

供需生命周期与开发者首次上架体验继续按研究路线图逐项推进；Agent 能力快照与安装回退要与准确产物绑定。没有合法 SDK、可复现技能包与真机测试条件时，不把模拟验证写成“真实热门机型已适配”。
