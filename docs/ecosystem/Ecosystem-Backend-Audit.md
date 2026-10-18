# 赛事活动与动态资讯：后端只读审计

审计时间：2026-10-18。项目：`D:\CodexDeploy\summon-mecha`。本轮只读源码与本次安全数据库元数据；未修改项目源文件，未执行种子、迁移、写库测试或接口写入。

## 1. 可作为规划基线的结论

1. 机器人赛事有独立的 `Webkul\Shop\Models\Event` / `EventRegistration` 与 `events` / `event_registrations`，不是 Bagisto 的营销日历，也不是商品门票。
2. 已有的是“运营填写赛事展示信息 → 列表/详情公开读取 → 游客或客户提交姓名手机号 → 后台查看联系人”的基础线索闭环。尚不能视为具备容量、资格审核、参赛团队、取消、回执查询、赛程管理与报名状态的赛事平台。
3. **当前主库实际没有赛事或报名。** 本次主库只读结果：`summon_mecha_local.events=0`、`event_registrations=0`；CMS 页面/翻译各10；`marketing_events=1`；应用时区 `Asia/Shanghai`。证据：[events-content-db-audit.json](events-content-readonly-evidence.json)。8场赛事只是 seeder 中的历史样例，不是现库事实，也不能把前端 mock 当作已经发布的活动。
4. 当前最严重的后端边界是：**赛事后台缺 ACL 路由映射**；任意已登录、启用且具非空自定义权限的后台角色，按现有 Bouncer 路径会绕过赛事的逐动作授权。报名列表包含完整姓名/手机号，通用 DataGrid 还具有请求导出能力。
5. 动态资讯没有独立持久化领域/API。CMS 能复用“编辑富文本、slug、翻译、频道、SEO”的基础，缺发布状态/时间、文章类型/分类、作者、来源、置顶、新闻列表/分页、修订与内容审核。不能把默认10个静态页叫作10篇资讯。
6. 建议继续沿现有 Laravel 模块化单体扩展；现阶段无依据引入微服务、收费报名、票务支付、钱包、结算或赛事对战引擎。

## 2. 证据索引（源码精确位置）

以下后文 `Sx:行号` 都指本表的完整文件，不是外部资料；静态推断与已运行的事实分开。

| 编号 | 文件（链接落在关键定义） | 关键行 |
|---|---|---|
| S1 | [机器人赛事 API](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/EventController.php:20) | 20列表；27详情；41报名；49 ended守卫；53校验；63去重；71插入；87输出映射 |
| S2 | [机器人赛事模型](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Models/Event.php:12) | 14表；16可写字段；35报名关系 |
| S3 | [报名模型](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Models/EventRegistration.php:10) | 12表；14可写字段；23赛事关系 |
| S4 | [赛事/报名原始迁移](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_09_07_100001_create_events_tables.php:17) | 17赛事；38报名；40/41索引；43手机号 |
| S5 | [赛事样例 Seeder](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Database/Seeders/EventSeeder.php:14) | 16任意已有记录则全跳过；20样例数组；31插入 |
| S6 | [Shop API 路由](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Routes/api.php:127) | 49 api前缀；78 CMS；127赛事路由 |
| S7 | [Shop Provider](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Providers/ShopServiceProvider.php:74) | 74 customer；75 api.customer；86 API外层；170 write限流器 |
| S8 | [可选客户解析中间件](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Http/Middleware/ResolveApiCustomer.php:22) | 24从Sanctum取用户，25绑定customer，28无条件继续 |
| S9 | [后台赛事控制器](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Catalog/EventController.php:18) | 18列表；32创建；50修改；61硬删除；74报名列表；83校验 |
| S10 | [后台赛事路由](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php:120) | 120–127七个动作 |
| S11 | [后台外层路由](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Routes/web.php:11) | 11 admin会话中间件；20加载catalog |
| S12 | [后台赛事菜单](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Config/menu.php:117) | catalog.events存在；301另一个marketing.communications.events |
| S13 | [后台 ACL 注册表](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php:612) | 无catalog.events；612起有营销事件；792起有CMS |
| S14 | [后台 Bouncer 中间件](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/User/src/Http/Middleware/Bouncer.php:16) | 18登录；26停用；36空权限；58全权限；79–85仅有路由映射才授权 |
| S15 | [ACL 路由映射生成](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Core/src/Acl.php:56) | 64–65 route→key |
| S16 | [后台菜单过滤](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Core/src/Menu.php:53) | 58按permission key过滤菜单 |
| S17 | [报名 DataGrid](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/DataGrids/Catalog/EventRegistrationDataGrid.php:15) | 17全报名表；19完整姓名/手机号；34列；无独立修改/审核动作 |
| S18 | [赛事 DataGrid](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/DataGrids/Catalog/EventDataGrid.php:15) | 18手填teams/hot；56四状态；104无权限判断的编辑/删除动作 |
| S19 | [赛事编辑模板](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/events/edit.blade.php:4) | 4只用event.id建action；18名称无值；23状态无绑定；60/65/75静态默认值 |
| S20 | [通用表单](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Resources/views/components/form/index.blade.php:23) | 无自动event模型灌入；25只有initial-errors；31 CSRF |
| S21 | [DataGrid 导出与清洗](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/DataGrid/src/DataGrid.php:417) | 407下载；421导出返回；450接收export/format；526设置exportable；559选择导出；569 strip_tags |
| S22 | [CMS API](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/CmsPageController.php:49) | 51当前频道+slug；62输出title/html；72不提供正文更新时间 |
| S23 | [CMS 后台控制器](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/CMS/PageController.php:50) | 52创建校验；61创建；95修改；128删除；146批删 |
| S24 | [CMS 仓储](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/CMS/src/Repositories/PageRepository.php:22) | 26向所有locale复制；33/53只替换字符非净化；38/57频道sync |
| S25 | [CMS 翻译模型](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/CMS/src/Models/PageTranslation.php:20) | 22无时间戳；24字段 |
| S26 | [营销事件模型](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Marketing/src/Models/Event.php:20) | 表marketing_events，只有name/description/date |
| S27 | [营销邮件调度](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Marketing/src/Helpers/Campaign.php:28) | 31关联marketing_events；37按日期；43 Birthday；50 Mail::queue |
| S28 | [Booking 事件门票](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/BookingProduct/src/Helpers/EventTicket.php:71) | 73按product；75 ticket；77 qty；89 SUM已订数量 |
| S29 | [Booking 建单监听](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/BookingProduct/src/Providers/EventServiceProvider.php:15) | checkout.order.save.after→Order@afterPlaceOrder |
| S30 | [Booking 仓储](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/BookingProduct/src/Repositories/BookingRepository.php:25) | 30只处理type=booking；58落order/item/product/ticket关系 |

## 3. 真实赛事 API、模型与接口契约

### 3.1 路由与身份（不是强制登录）

| 方法/URL | 名称 | 当前行为 |
|---|---|---|
| GET `/api/events` | shop.api.events.index | 公开；sort DESC、id ASC，全量get，无分页/筛选/上限（S1:20–24） |
| GET `/api/events/{id}` | shop.api.events.show | 公开；不存在404 EVENT_NOT_FOUND（S1:27–35） |
| POST `/api/events/{id}/register` | shop.api.events.register | 游客和已登录用户均可；S6:130挂api.customer，但S7/S8证明只是可选绑定身份 |

`api.customer` 不是 `customer`。API 外层已经有相同可选解析器；赛事重复挂一次不会强制登录。`bootstrap/app.php:26` 的 `validateCsrfTokens(except:['api/*','sanctum/*'])` 也覆盖赛事提交。**无会话/无CSRF/无Bearer仍能提交**是按源码可达行为，并非本次对主库发送POST测得。

当前报名没有 `throttle:write`；现有write限流器可复用，但尚未接这里。输入 name/phone 仅required|string，city/note nullable|string，无max、号码归一化、格式校验、所有权/手机号验证或额外挑战。客户身份如果存在只用于落 `customer_id`，不会限制请求中的联系人属于此客户。

### 3.2 输出精确形态

- 列表：`{data:[...events]}`；详情：`{data:event}`。
- 每项：`id,name,type,status,date,dateShort,city,location,organizer,hot,teams,prize,schedule,history,detail,icon,bg,fav`。`dateShort`直接复制date；`bg`来自bg_color；`fav`写死false；没有真实收藏事实（S1:87–108）。
- 成功报名：`{success:true,message:'报名成功',registration:{id,event:赛事名称}}`，默认200（S1:80–84）。没有可安全查询的回执标识、当前报名状态或下一步说明。
- 业务失败：404 EVENT_NOT_FOUND；422 EVENT_ENDED；422 EVENT_ALREADY_REGISTERED；校验失败走全局422信封。id没有whereNumber路由限制，非法id应补契约测试，不能把PHP参数TypeError当理想404。

### 3.3 已有表字段与结构能力

`events`：bigint id；name/type/status/date/city/location/organizer；hot/teams；prize/schedule/history/detail；icon/bg_color/sort；timestamps。主库字符串常规长度实际191，status20、bg_color20；唯一索引只有PK。

`event_registrations`：bigint id；unsigned int event_id、nullable unsigned int customer_id；name191、phone20、city191、note191；timestamps；只有event_id和customer_id普通索引（根元数据与S4一致）。

缺少且无法由当前字段可靠推导：

- 发布/草稿/下线/撤回/取消；公开可见性和渠道；内容归属/主办方结构化主体、来源URL/授权证明。
- 可计算的开始/结束/报名窗口、时区/线上线下模式；当前date是展示字符串。
- 容量、已占名额、每报名人数/队伍、候补；teams是运营手填展示数，**不是容量，也不是报名统计**。
- 报名状态、审核/拒绝原因、取消/签到、状态变更记录、用户可查回执/“我的报名”。
- 唯一约束、引用外键、软删除。赛事PK bigint但报名event_id int，新增FK前须统一类型。
- 参赛项目/分组/团队成员/机器人资料/资格材料/成绩/对阵；这些不是现有“姓名手机号”自动具备的能力。
- 隐私告知版本、必要勾选留痕、保存期限/匿名化、PII访问审计。

### 3.4 当前状态与并发危险（静态推断）

- S1:49仅拒`ended`，所以`upcoming`和`ongoing`仍可报名；date已过去不影响；无定时状态推进。当前状态混合报名阶段与赛事阶段，不足以承载发布和报名两个独立维度。
- S1:63 `exists(event_id,原始phone)` → S1:71 `create`没有事务/唯一键。两个请求同时exists=false可插入两行；同一账号换phone可重复；手机前后空白/格式变体不受统一规范保护。
- 现有 `config/database.php:58/78` strict=false。超长字符串可能被数据库截断，而去重查询用原始值；phone超过20字符时，存在“查长值无记录，插入被截短，再次长值仍无记录”的风险。应先验证长度而不是依赖DB截断。
- 报名读取event→后台改ended→报名仍插入；没有共同锁或当前状态复查。
- 后台删除先`registrations()->delete()`再`event->delete()`（S9:65–66），不在事务中也无FK；报名已读event后可在删除间隙/之后插入孤儿，失败还可能只删了报名却留赛事。不可把“删除赛事”用作取消活动和通知机制。
- 没有容量字段，所以目前不是“超售bug已发生”，而是**容量控制功能尚不存在**；不能为现有teams值宣称名额控制已完成。

## 4. 后台、权限、运营与隐私

### 4.1 需要优先纳入计划的 ACL 缺口

S11只提供登录、启用和角色基础检查。S10的全部七个 `admin.catalog.events.*` 路由没有出现在S13，全文检索其他 `*acl*.php/*Provider.php/*Policy.php` 也未找到补充映射。S15把ACL静态配置映射成route→permission；S14:83仅`isset`才调用allow，缺映射即继续。控制器S9及DataGrid动作S18没有额外 `bouncer()->allow` 或policy。

因此：非空custom角色即使没有赛事权限，也可通过直接URL触发赛事列表/创建/修改/删除及报名列表。S16按menu key过滤只会隐藏菜单，不会封接口。空权限角色被登出，匿名会跳后台登录，停用角色会退出，故**不是匿名后台泄露**，而是后台角色间的授权缺失。

首批修复规划应给所有读写动作显式映射（含store/update/registrations），且“可看赛事”和“可看报名PII/导出”分权；不能只添加菜单或仅保护create/edit页。禁止默认给已有所有角色加新PII权限。

### 4.2 编辑表单不回显现有内容

S9:45确实把event传给view，但S19没有initial-values、old值或`:value`绑定；只在action用event.id。名称/正文等为空，status默认首项registering，hot60/teams0/sort0为静态值。S20没有替它自动绑定模型。**代码层确认当前编辑模板缺回填；浏览器实际损坏程度尚未运行验收。** 工程验收需覆盖编辑页所有字段回显、校验失败保留old、只改一个字段不清空其他字段。

另外bg_color被API输出且后台校验接受，但当前create/edit页面没有bg_color输入；default/null处理需统一。Admin validated允许type/hot/teams/icon等nullable，而迁移部分列NOT NULL，缺省值应服务端显式处理，不能由客户端默认值掩盖。所有字段需按实际列长度设置max，富文本不应混为无限长普通字符串。

### 4.3 报名运营当前只读全量联系人

S17查询所有赛事报名，返回完整name/phone/city/时间及赛事名，没有按赛事id/主办方归属固定范围，也没返回customer_id/note。搜索可按赛事名称、联系人、手机号，但没有审核/状态/跟进备注/单条详情/联系结果/取消/签到/通知动作；所谓“可跟进”实质是运营看到号码后线下处理。

**导出不可仅看页面按钮判断。** S21通用DataGrid允许`export=true&format=csv|xls|xlsx`并设exportable=true后下载，S9:76的ajax路径直接process。静态路径因此支持全量查询导出；本轮未向任何真实报名发导出请求，不能声称已拿到CSV。后续应单独授权导出、遮罩默认列表、仅必要角色解密/查看、留访问审计，并验证导出策略与服务器查询一致。

隐私缺口：任意人可提交他人姓名手机号；可凭已报名/未报名错误区分某phone参与情况；原始phone未屏蔽；无保存期限/删除或匿名化机制；Customer模型无赛事报名清理引用，nullable customer_id也没有FK。不能把“删账户”假定成报名PII已删除。隐私策略/具体留痕内容应由产品明确，本文不代拟法律条款，也不凭空要求接短信供应商。

## 5. 三种 Event 必须分开

| 领域 | 模型/表与路径 | 现有用途 | 与机器人赛事关系 |
|---|---|---|---|
| 机器人赛事活动 | S1/S2/S3；events/event_registrations；Admin catalog/events | 展示赛事及联系报名 | 本轮扩展的基础 |
| 营销日历事件 | S26；Marketing migration `2020_11_26_150534_create_marketing_events_table.php:17`；Admin marketing/communications/events | name/description/date；Birthday样例；S27给邮件campaign按日期选订阅人 | 不能拿其1行当1场机器人赛事；其营销后台测试不是赛事报名测试 |
| 预订商品门票 | BookingProduct models/tables booking_products、booking_product_event_tickets、bookings；S28/S29/S30 | type=booking商品进入购物车/订单，票种价格与qty、订单取消/退款数量计算 | 没有指向events的FK/事件关系；不是event_registrations；复用即引入订单/售票业务，须另立明确需求 |

Booking证据补充：`BookingProduct/src/Database/Migrations/2019_07_02_180307_create_booking_products_table.php:18`关联products；`2019_07_05_154440_create_booking_product_event_tickets_table.php:18`关联booking_product_id并含price/qty；`2020_02_18_165639_create_bookings_table.php:18–43`关联product/order/item/ticket。S28:77的“qty减已订数量”是商品购物流程中的读检查，**未证明其并发票量安全**，不宜当可直接复用的报名容量锁。

**当前真正的赛事报名与订单、付款、退款、库存、技能授权没有耦合。** S1创建时只写registration；S2/S3无order字段/关系，迁移也没有order_id。保留这一边界能先交付免费活动/参赛申请；收费或押金是需要另行拍板的业务，而非补齐报名的必要前提。

## 6. 动态资讯 / CMS 能复用到哪一层

### 6.1 现有CMS的真实能力

- `CMS/src/Models/Page.php:21/35/42/57/64`：cms_pages/layout，translatable的title/slug/html/SEO，预加载translations，频道关联。
- `CMS/src/Database/Migrations/2019_07_30_153530_create_cms_pages_table.php:16`：pages只有id/layout/timestamps。
- `2020_01_14_191854_create_cms_page_translations_table.php:16`：title/url_key/longtext html/SEO/locale/page_id；`unique(page_id,url_key,locale)`；没有翻译更新时间。
- `2020_01_15_130209_create_cms_page_channels_table.php:16`：page/channel关联有唯一键与FK。
- S23提供富文本CRUD，创建时url_key唯一/Slug规则、channels至少1；S24创建把初始内容拷贝给所有locale，更新按locale；Admin CMS create/edit模板启用TinyMCE。
- S22只提供GET `/api/cms/pages/{slug}`，限制当前频道+slug，公开返回title/原样html；没有文章列表或分页，没cache.response。
- 网页 `Shop/src/Routes/store-front-routes.php:33–35` 的 `/page/{slug}` 走 `cache.response`；`config/responsecache.php:31` 默认7天。S23没有CMS更新清响应缓存调用，API/网页可能短期内容不同（配置可改变，不能把7天当所有环境必然值）。

### 6.2 不应混用的点

- “在频道中”是当前公开条件，不是新闻发布状态；CMS无draft/published/archived、发布时间和定时发布；给频道关联一页就可能立刻公开。
- CMS翻译模型timestamps=false（S25:22）；API刻意不返回updated_at，不能拿主表updated_at宣传“这篇正文何时更新”。
- 默认隐私/用户协议/关于页应保留原身份；不要把所有CMS页混入新闻列表，也不要为了新闻目录改坏已有协议slug/API。
- S24只做`str_replace('=&gt;','=>',...)`，不是HTML安全净化。网页 `Shop/src/Resources/views/cms/page.blade.php:19` 用 `{!! $page->html_content !!}`；API也原样输出。当前只有后台可写，不能未经额外白名单净化/安全策略就开放社区发帖或外部投稿。
- 富文本安全与关键词/内容审核是不同问题：过滤script/onerror/javascript URL/iframe与处理违规文本不能互相代替。
- 未发现独立news/article/feed/post模型、迁移、API或评论/点赞/收藏后端。仓库中的newsletter/Marketing邮件订阅不是资讯流。

### 6.3 可复用公共基础（当前赛事/CMS并未自动接入）

| 基础 | 证据 | 复用约束 |
|---|---|---|
| 站内通知 | [NotificationService](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/NotificationService.php:20)，44 toCustomer | 本地inapp已落库；注释明确微信订阅仍待模板。游客没有customer recipient，不能声称报名已发短信/微信 |
| 操作审计 | [OperationLogger](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/Ops/OperationLogger.php:24)，65 logOrFail | 默认失败只写日志；对发布/取消/PII导出先明确审计失败策略，payload不塞整份手机号 |
| 管理员告警 | [AdminAlertService](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/Ops/AdminAlertService.php:44) | 对所有在职管理员逐个投递，非赛事专属角色路由；若含联系人信息需先收窄recipient，不可直接群发PII |
| 内容扫描与留痕 | [ContentSafetyManager](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/ContentSafety/ContentSafetyManager.php:14)，[ContentScanRecorder](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Services/ContentSafety/ContentScanRecorder.php:24)，[ContentSecurityRecord](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/src/Models/ContentSecurityRecord.php:42) | 驱动注册表可复用；已有target偏skill_meta/report/review，尚未events/article，后台筛选和处置需同改；本地词表不是官方合规批准 |
| 限流/统一错误 | S7:170 write；现有Core异常处理 | 挂赛事写路由并测试匿名/客户独立预算，保持API error code与状态可靠 |
| Admin布局/DataGrid/上传编辑体验 | S17/S18/S20/S23 | 复用UI基础与分页，不继承当前赛事ACL缺失或将通用导出视作已授权 |

## 7. 现有测试与明确证据缺口

本轮未运行任何测试，以下是**已有文件覆盖范围**，不是通过声明。

- 全仓Shop/Admin/tests中按 `shop.api.events`、`admin.catalog.events`、`EventRegistration`、赛事报名路径搜索，未找到机器人赛事专项测试。`AgentEventOwnershipTest`及其他event listener测试属于机器人Agent/业务事件，不是赛事报名。
- [Admin Marketing EventsTest](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/tests/Feature/Marketing/Communications/EventsTest.php:10) 只测营销事件CRUD；不能转用其结果证明机器人赛事权限、报名或容量。
- [CMS API Tests](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/tests/Feature/CmsApiTest.php:24)：公开正文结构、网页同源、连续两slug、防占位、404/slug约束、非当前频道不暴露（115）；不是资讯发布工作流测试。
- [CMS Placeholder Tests](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Shop/tests/Feature/CmsPlaceholderTest.php:26)：默认协议占位说明诚实性。
- [Admin CMS Tests](D:/CodexDeploy/summon-mecha/my-bagisto-store/packages/Webkul/Admin/tests/Feature/Cms/CmsTest.php:13)：CMS列表/CRUD/校验/批删，未覆盖新闻类型、发布、内容净化和细粒度资讯权限。
- 静态判断已足够确定exists/create没有原子性，但并发故障未在本轮通过真实两连接重现；规划实施验收应在新隔离库做，不污染已清理的F环境或主库。

## 8. 可直接交给规划的工程拆分（建议，尚未开发）

### 8.1 先确定三项业务口径，避免迁移返工

1. 首批赛事是“信息+参赛申请”还是售票/报名即确认？建议先免费信息+申请/确认，订单不接入；如需要收费，单独定义票价、锁名额/超时/退款再设计。
2. 首批报名主体是登录客户个人，还是游客联系人/团队？建议登录个人所有权便于“我的报名”和取消；游客可保留咨询单语义，不伪装成已验证报名。团队/机器人资料需明确字段后再加，不要拿现有teams当真实团队。
3. 动态资讯首批是平台编辑发布，还是用户社区投稿/评论？建议先运营文章/公告，新闻数据与现有协议CMS分域；评论/点赞/收藏若不做应明确不展示伪持久化计数。

### 8.2 建议最小领域/迁移方向（以产品技术方案最终选型为准）

- 沿用events ID；新增结构化时间 `starts_at/ends_at/registration_opens_at/registration_closes_at`、时区约定、发布态与报名开关、可选capacity、报名计数口径、cover/source/summary等所需内容字段；不要把旧date字符串自动瞎解析成权威时间。旧date保留作旧展示或迁移人工补录。
- 报名补status、唯一identity key、合法owner/customer、确认/取消时间、必要隐私版本和审计引用。`event_id`升为与events一致的bigint，清理历史重复/孤儿后才加FK/唯一；当前主库0行降低迁移复杂度但上线前仍应执行安全preflight，不能假设其他部署也空。
- 真正的容量分配、取消释放、Admin关闭/调容量/取消全部复用**同一个events行锁协议**，顺序event→registration，在事务内用当前读复查公开/窗口/剩余名额，再落registration；唯一索引兜底身份幂等。固定手机号还是customer唯一属于上面业务口径，不要只选一个恰好方便建索引的字段。
- 初期小规模可在event锁下当前计数有效报名，或维护reserved_count，但不能“先count后insert”无锁；若用计数列，报名/取消/后台变更与纠错任务都必须遵同一协议，并有计数重建/不变式验收。容量减少不得低于已占名额；是否允许候补需明确，未立项则不做。
- 新文章可用独立 `content_posts`（或既有CMS一对一元数据层）方案；核心至少type/slug/title/summary/body/cover、draft/published/archived、published_at、author/source、审计与索引。**若复用CMS正文则必须先解决自动公开与无正文版本戳，不能只加category字段就宣称资讯完成**。无需一开始抽象万能Content微服务。
- 发布给客户端的是完整公开快照；运营编辑草稿不应立即修改已发布正文。可选择明确revision或独立草稿字段，首批至少乐观并发版本与审计，防两编辑者静默覆盖。定时发布若未要求则先手动发布，别让可用性依赖未启动scheduler。

### 8.3 推荐交付顺序与阻断条件

| 批次 | 内容 | 完成的必要证据 |
|---|---|---|
| 0 基线与权限 | 真实空数据/样例分离、events所有ACL动作、PII独立权限、编辑回填/校验修复 | 无权限Admin直接URL拒绝；有查看无修改/PII权限角色分离；主站空列表诚实显示 |
| 1 赛事发布 | 发布状态/结构化时间/展示字段、Admin预览与发布、公开列表分页筛选/详情 | 草稿不可读；已下线不可报；排序稳定；编辑未覆盖已发布快照；旧date迁移无伪日期 |
| 2 可靠报名 | 注册/回执/我的报名/取消、幂等/容量/统一锁、最小站内通知 | 真实并发容量与重复测试、关报名/取消同锁竞态、越权读取/取消拒绝；无支付依赖 |
| 3 运营闭环 | 按赛事筛选/遮罩/PII查看授权、确认或拒绝、取消活动与通知、导出策略/审计 | 角色隔离、变更记录、通知失败可复核；不硬删承载活动取消 |
| 4 资讯编辑发布 | 明确文章域、运营CRUD/富文本净化/来源、状态+公开列表/详情、缓存失效 | draft/archived不可公开；危险HTML被处理；API/网页一致；协议CMS仍原样可用 |

阻断：没有确认“名额算人/队/申请/确认”的口径，不实施容量迁移；没有登录/游客所有权口径，不设计取消鉴权；没有真实来源与可发布内容，不用8场奖金额/主办方样例填线上；未明确第三方投稿，不开放UGC写入口；未有收费需求，不接订单/支付。

### 8.4 最低验收矩阵（应编入后续计划）

- API：空库返回data=[]；分页/过滤/稳定排序；草稿ID/非公开内容详情不可枚举；非法id/超长输入/未知状态均稳定4xx。
- 报名：匿名与登录策略清晰；重复重放返回同报名/明确幂等，不重复计数；规范化手机号差异不绕过；他人客户ID/联系人参数不越权。
- 并发：容量1时双连接只有1成功；同identity双请求只1行；报名等待event锁后看见关闭/取消；取消与新增名额守恒；降容量/删除与报名串行；RR旧快照不能漏守卫。
- 生命周期：报名开关+窗口边界、零容量、无限容量（若允许）、活动取消、报名取消/再报名策略；已结束/不在窗口不可报名。
- 隐私：公开API和日志/通知无手机号；PII权限与导出分别鉴权，未授权直接ajax/export拒绝；账号删除/保留期限行为可核验。
- Admin：表单回填全部字段，校验失败保留old；view/create/store/edit/update/delete/registrations完整授权；运营并发编辑无静默丢失；硬删有记录活动受限或归档替代。
- 内容：编辑草稿不即刻公开；发布/撤回/定时口径真实；允许HTML白名单/危险URL/iframe策略；资讯列表不混入协议；频道/语言/缓存一致；修订记录与来源展示可核验。
- 浏览器：真实运营创建/编辑/发布→前台真实列表详情→客户报名→回执/我的报名/取消→后台核验；空/失败页面不回退可报名假赛事。HTTP测试不能冒充真实UI。

## 9. 本次未做

未改源、未写库、未跑测试、未发报名/导出请求、未创建比赛/资讯、未接短信/邮件/支付/第三方主办方、未核验任何样例赛事的真实存在。本报告提供的是工程规划证据与建议，不是功能完成证明。
