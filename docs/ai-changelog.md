# AI 协作变更日志（有痕修改机制）

> 规则：AI 的所有修改必须有痕、可追溯、可一键恢复。
> 机制三层：① git 基线 + 小步提交（恢复主体）；② `.claude/backup/<任务号>/` 文件级双保险；③ 本日志（人读的记录）。

## 恢复速查

| 想做什么 | 命令（在 D:\main 执行） |
|---|---|
| 查看每一步历史 | `git log --oneline` |
| 回滚到某个验收点 | `git reset --hard <提交hash>`（会丢弃其后所有改动，执行前 AI 必须先说明会丢什么） |
| 回到改造前基线 | `git reset --hard <基线hash>` |
| 看某步具体改了什么 | `git show <提交hash> --stat` |
| 只恢复某个文件 | `git checkout <提交hash> -- <文件路径>` |
| 文件级兜底备份 | 手动从 `.claude/backup/<任务号>/` 覆盖回来 |

## 提交纪律

- 每完成一个用户验收通过的小步 = 一次 commit，提交信息写明改了什么、为什么。
- 任务开工前，将待改文件原样复制到 `.claude/backup/<任务号>/原相对路径`。
- 涉及 `storage/`（上传图片）或 `.env` 等不入库文件的操作，必须先做文件级备份。

## 变更记录

### [AI召唤收口] 2026-10-20 · 跨设备历史 + 正式埋点 + 读屏闭环（用户批准一次性完成）
- 范围：新增 additive 数据表、登录客户同步 API、公开埋点 API、CLI 报表与前端账号作用域隔离；未改既有业务表。
- 跨设备历史：`ai_conversations` 按 `customer_id + client_id` 唯一；`client_updated_at` 裁决版本；90 天删除墓碑防旧设备复活。
- 账号隐私：本地历史/墓碑/草稿按 `user_<customer_id>` 分库存储；首次登录可接管游客历史，后续切号不复制上一账号状态。
- 正式埋点：`ai_analytics_events` 白名单、`event_id` 去重、匿名设备键 SHA-256、属性标量裁剪；不存提示词/回答正文。
- 报表：`php artisan ai:analytics-report --days=7`，输出事件计数、每日计数和核心漏斗。
- 读屏：历史抽屉改临时 dialog，打开聚焦搜索框、Esc 关闭、同步结果 `aria-live` 播报；核心控件补齐 ARIA 与键盘焦点态。
- 迁移：独立测试库已执行；root 仅执行同一 additive 迁移（编号 47）。
- 验证：后端/限流 `22 passed / 125 assertions`；契约与 preflight 通过；真实 Chrome 联合验收 `13/13`；H5 编译成功。
- 真实上游：Docker/Coze 已修复并运行；真实流式首事件 1.4s、首正文 3.1s、5.7s 完成，真实非流式 10.8s 完成，页面级真实 AI 浏览器演练通过；证据见 `docs/ai-full-closeout-evidence.md`。
- 回滚：`.claude/backup/ai-full-closeout-20261020/`，迁移回滚命令见收口证据。

### [AI前端交互精修] 2026-10-20 · 历史、草稿与基础无障碍（用户批准下一轮）
- 范围：只改 `pages/ai/index.vue` 前端交互与本地存储，不新增后端接口、数据库或生产依赖。
- 历史对话：
  - 抽屉增加本机存储说明、会话数量、关键词搜索和无结果态。
  - 支持单条删除与清空全部，均带二次确认；删除当前会话时同步清空工作区。
- 草稿与复制：
  - 输入内容和快捷筛选自动保存到本机，刷新后恢复；草稿保留 7 天，发送或新建对话后清除。
  - AI 回答新增“复制”按钮，并提供成功/失败反馈。
- 无障碍与文案：
  - 菜单、发送、语音、快捷标签、推荐问题、历史项和删除/清空等核心操作补齐可访问名称、焦点态与 Enter 键操作。
  - 网络、超时、登录失效等异常统一转换为用户可理解的中文提示，不再直接暴露技术错误。
- 验证：
  - `D:\HBuilderX\cli.exe publish web --project D:\main`：编译成功。
  - 真实 Chrome + 本地 mock SSE：13/13 通过；覆盖 ARIA、键盘 Enter、草稿刷新恢复、复制回答、历史搜索、单条删除、清空全部、320px 抽屉和页面横向溢出。
  - `git diff --check`：通过。
  - 截图：`.claude/backup/ai-full-closeout-20261020/evidence/ai-ui-round2-shots/`（按仓库约定不入库）。
- 回滚：用 `.claude/backup/ai-ui-history-a11y-20261020/` 下原文件覆盖 `pages/ai/index.vue`、`docs/ai-changelog.md`。
- 未做：跨设备历史同步、正式埋点、读屏全链路与真实 AI 上游回归；跨设备历史需要后端会话模型和接口，未擅自扩架构。

### [AI前端交互硬化] 2026-10-20 · 小召 P0 闭环补齐（用户批准执行）
- 范围：只改前端交互与既有方案定制承接，不碰后端接口、数据库、生态开关或生产数据。
- `pages/ai/index.vue`：
  - 用 `connecting / thinking / streaming / error` 状态机替代原有布尔态；思考/生成期间禁止并发提问和语音录入。
  - 流式请求支持真实停止；中断后保留已生成内容，并提供“重新生成”。
  - 错误回答增加“重试 / 交给方案专家”，无推荐结果时增加方案定制承接卡。
  - 用户问题发送后立即写入本地历史，避免请求中断或退出后丢失。
  - 推荐机型展示后端已有 `match_reason`。
  - 首屏移除硬编码日期，示例问题改为真实业务场景；预算/任务从文本标签升级为可生成自然语言的快捷匹配器。
  - 输入增加 2000 字上限、字符计数、空输入/生成中按钮态；用户上滑阅读时不再强制拉到底。
- `utils/api.js`：`summonStream` 增加 H5 AbortController、小程序 `requestTask.abort()` 与 `onRequestReady/onClose` 回调。
- `pages/robot/fangan.vue`：支持 `mode=custom&source=ai` 直接进入方案定制视图，并在 24 小时内读取 AI 需求草稿填入留资表单。
- 验证：
  - `D:\HBuilderX\cli.exe publish web --project D:\main`：编译成功。
  - 真实 Chrome + 本地 mock API：12/12 通过；覆盖首屏、快捷筛选、流式推荐理由、停止生成、错误恢复、AI 草稿带入方案表单，以及 320 / 375 / 414 三档无横向溢出和无页面运行时异常。
  - `node --check utils/api.js`、`git diff --check`：通过。
  - 截图：`.claude/backup/ai-full-closeout-20261020/evidence/ai-ui-hardening-shots/`（按仓库约定不入库）。
- 回滚：用 `.claude/backup/ai-ui-hardening-20261020/` 下原文件覆盖 `pages/ai/index.vue`、`utils/api.js`、`pages/robot/fangan.vue`、`docs/ai-changelog.md`。
- 未做：跨设备历史、历史删除/清空、正式埋点、完整无障碍与键盘/读屏回归；这些留在下一轮，不把本轮标成“精细化全部完成”。

### [体检3] 2026-09-07 · 深度健康检查（夜班二期之后）
- 工作区干净；be37f91 以来 37 文件 +1714/−735，全部符合夜班计划范围。
- 语法：PHP×12 + JS/Vue×8 全过；前端符号无重复。
- 数据库：events 8 场 / 报名 1 条 / 孤儿报名 0 / 我们的迁移已登记（2026_09_07_100001_create_events_tables）；订单 55 单 order_type 无一 NULL。
- API：回归 7/7；summon 五组用例匹配正确（含同义词“仓库→仓储”）。
- 后台：登录后赛事管理/报名列表 200 + datagrid 8 场（此前 302 为会话过期，重登即好）。
- 日志：最新 ERROR 为 00:54 的 Malformed UTF-8——源于 curl GBK 测试（已改 UTF-8 脚本验证），非代码问题；此后零新增。
- 结论：**健康**。注意事项：①Bagisto 自带 marketing events 表与我们 events 表同名异实，升级/排查时勿混淆；②订单数随用户真机测试增长属正常。

### [夜班二期] 2026-09-07 · 赛事闭环 + 小召 v1 + 信任/效率层（8/10 完成，按战略优先级）

| # | 任务 | 提交 | 验收 |
|---|---|---|---|
| 1-2 | 赛事数据层 + API（events/event_registrations 表、模型、种子 8 场、index/show/**register 真实报名**） | ee15b1c 区间 | ✅ curl 全生命周期（列表/报名落库/防重复/ended 拒绝） |
| 3 | 赛事 Admin CRUD + 报名列表 + 菜单 | 4f4c2a0 区间 | ✅ 三页 200 + datagrid 8 场数据 |
| 4 | EcoCenter 前端闭环（拉真数据回退 mock + 报名表单真实落库） | a48245f | ✅ 语法 + 接口对齐 |
| 5-6 | **小召 v1**：/api/summon 规则匹配引擎（15 组同义词表）+ AI 页结果流（场景/机型/备选一键跳转） | f652c93 | ✅ 实测：展会接待→展会接待场景、酒店配送→酒店大堂服务、巡检→电力巡检 |
| 7 | 订单自助取消（后端状态机校验 + 详情页按钮真实化） | 080b792/fix | ✅ 端到端：取消成功/重复取消被拒 |
| 8 | 机器人库搜索（租/购页本地过滤） | （今日提交） | ✅ 语法 + 回归 |
| 9-10 | 资讯接真后台 | **未执行** | 预算不足以保证质量，按"深度优先于广度"纪律留档；明天优先做 |

- **战略对齐自评**：赛事闭环=生态卡位落地；小召 v1=灵魂入口从假变真（意图→场景→机型→方案的结构已立，LLM 可平滑替换引擎）；自助取消=信任层；搜索=交易效率。
- 已知待修：summon 机型匹配依赖商品命名规范（与品牌筛选 v1 同源）；资讯/报名数据随测试增长需定期清理。
- 测试数据：报名记录 RegTest（id=1）保留作闭环证据；测试客户 night-test 保留（后续 T 回归用）。

### [夜班] 2026-09-07 · 7 项任务批量执行（用户批准，全程 CLI 验证，每任务三问验收）

| # | 任务 | 提交 | 三问验收（健康/完成/战略） |
|---|---|---|---|
| 1 | API 回归脚本工具化 | （tools/api-regression.sh） | ✅ 7/7 常备体检 |
| 2 | 登录诚实化（删降级假登录） | 03f0300 | ✅ 信任原则 |
| 3 | 我的页诚实化扫尾（真实手机号/假交互改"开发中"/社区收藏切换/分享真复制/分拣错字） | c45e8bf | ✅ 信任原则 |
| 4 | 死代码清理（robot 5 方法+88 条 mock+enrich 链、featuredScenes、rmfangan 去重） | cbaca83 | ✅ 单一数据源 |
| 5 | 订单详情接真接口 + 假种子清理 + order_type 全链路（后端 formatOrder + 前端映射） | 91f89bf | ✅ 真实数据 |
| 6 | 业务表备份脚本（11 表按日期 JSON 归档） | 9da0544 | ✅ 可恢复性 |
| 7 | T3 地址打通（addressApi 双模式 + 后端 destroy + 张三李四清除） | 0099e96 | ✅ 框架回归 |

- 测试客户 night-test/订单 398 验证完毕后已清理；回归脚本最终 7/7 PASS。
- 任务 8（赛事接真后台，新增数据表）未获单独批准，未执行。
- 每任务前的文件备份在 `.claude/backup/夜班N/`（任务 2 起）。

### [清理] 2026-09-07 · 测试数据清理 + 工作区文档化（用户指令）
- **删除 18 条测试订单**（30、40–48、50–52、387、388、390–392；事务保护，删前备份 JSON 在 `.claude/backup/T4-ordertype/deleted-test-orders-backup.json`）；剩余 41 条真实流程单（buy27/custom10/plan1/rent1/skill1/accessory1）。
- **后台密码更换**：admin@zhaohuan.com（新密码仅项目负责人知晓；实测新密码登 dashboard、旧密码被拒）。
- **关闭 8011 端口多余 serve 进程**（2 个），后端唯一入口 8000。
- **新增 docs/UPGRADE-NOTES.md**：Bagisto 核心改动 16 处清单 + 升级合并流程 + 回归测试清单（应对"开源被改乱"的升级税）。
- **NAVIGATION.md 更新**：store2 定性为用户备份（保留勿删勿部署）、Shopfront 状态纠偏（已恢复启用）、8011/密码/测试单/计算机使用纪律等备忘。
- bagisto/ 空目录已不存在（此前已被移除）。
- store2 保留决策：用户明确要求保留备份，仅文档化说明。

### [体检2] 2026-09-07 · 深度健康检查（T5/T6 之后，覆盖 Bagisto 核心改动）
- 工作区干净；61c1160 以来 9 提交、8 文件 +433/−76，全部符合预期。
- 语法：PHP×3 + JS/Vue×4 全过；前端符号无重复。
- 数据库：59 单 order_type **无一 NULL**（buy27/custom23/plan3/rent3/skill2/accessory1）；迁移已登记；多机订单 390 结构完整（2 条目合计=总额 600、双地址、支付记录）；**库存零污染**（tob-order 不扣库存，沿用原设计）。
- API 回归：tob-catalog 数据不变且锚点无泄漏；非法类型/缺字段正确 422；scenes 200；后台页签过滤 200；后台订单详情页渲染多机订单正常（200，两台机器+场景名+租期齐全）。
- 日志：切 log 邮件后零新增 ERROR（23:11 那条"To 头缺失"为切换前遗留）。
- 结论：**健康**。上线前注意项：①生产 SMTP 配置（含管理员通知邮箱，23:11 那类错误源于此）；②tob-order 不扣库存——卖断型购买是否接库存管理需产品决策。
- 回滚基线不变：`git reset --hard 61c1160` 可回到最后体检点。

### [T6] 2026-09-07 · 场景租用多机合单
- 提交：88de775（后端 items[] 支持）→ 899e2f7（前端 ⊕ 多选 + 底部结算栏 + 结算页多机清单）
- 行为：场景租用页每台机器右上角 ⊕ 选择/取消 → 底部结算栏（已选 N 台 / 租期 chips 1·3·7·30 天全场统一 / 合计）→ 立即下单 → 结算页多机清单模式 → tob-order 提交 items[] → **一张订单多条目**，归类仍走 from=scene → 「方案」页签。
- 后端：store() 重构为统一「行」结构归一化，单商品/定制线索/锚点/归类逻辑全部保留；items 逐行校验存在性。
- 实测：order390（2 台，100×3+50×2×3=600，plan，条目名带场景名前缀）/ 391（单租回归）/ 392（线索回归）全过。
- 新增测试订单：390、391、392（累计待清理 18 条）。
- 回滚：`git revert 88de775 899e2f7`；单商品路径行为未变。

### [T5] 2026-09-07 · 后台订单分类（定制/租用/购买/方案/技能/配件）——四步提交
- 提交：a718c5c（①迁移+回填）→ f20f320（②控制器落类型+枚举扩展）→ 3e9159b（③后台 DataGrid 类型列+筛选）→ 7dbb119（④前端类型透传）
- 数据地基：orders 新增可空列 order_type（带索引），存量 48 单回填 buy=26/custom=22；**已知边界：历史技能/配件单当年按【购买】落库无法区分，归入 buy，自本功能起新单完整**
- 控制器：类型枚举扩为 6 类，条目名标签加"技能/配件"；Order 模型用 $guarded 无需改动
- 后台 UI：OrderDataGrid 纯增量加"类型"列 + 下拉筛选（未动任何现有列/行）
- 前端：checkout 类型白名单透传（顺带修复 custom 被吞成 buy 的已知 bug）；机器人页配件/技能分支直传真实类型
- 回滚：每步独立 revert；数据层回滚=迁移 down()（删列）；orders 全量备份在 .claude/backup/T4-ordertype/orders-backup.json
- 新增测试订单：52（skill, TypeCheck）；累计待清理测试单：30、40–48、50、51、52

### [体检] 2026-09-06 · 全项目健康检查（T1/T2 之后）
- 工作区干净，基线以来 10 文件 +390/−35，全部符合预期；.env 仅 MAIL_MAILER 一行变化。
- 语法：4 个 PHP + 5 个 JS/Vue 全部通过。
- 接口：tob-catalog/scenes/categories 200；rent 路径 422 校验仍在（无数据污染）；custom 线索成功；LEAD-CUSTOM 未泄漏进任何前台目录。
- 日志：切 log 邮件后零新增 ERROR。
- **发现并修复**：脚本创建的锚点商品缺 `product_flat` 行（官方 Repository 边缘情况，主表/订单链路正常但后台商品列表可能空白）——已按现有 flat 结构补插（zh_CN/default，visible=0），补后链路复测无损（订单 51）。
- 待清理测试数据：后台订单 30、40–48、50、51 共 12 条（姓名 555/TestLead/SpeedTest/HealthCheck/PostFlatCheck）。
- 观察项（非缺陷）：enrichProductCards 为保留的死方法；品牌筛选 v1 按商品名推断，后续迁后台属性。

### [T2-1] 2026-09-06 · 留资管道：三处假表单接真实后端 + 品牌筛选修复
- 提交：c3169da（后端）+ 本条前端提交
- 后端（TobOrderController）：custom 类型免 product_id/免地址，自动挂锚点商品（sku=LEAD-CUSTOM，**需在后台创建**，缺失时返回 422 明确提示）；线索金额恒为 0；公司/微信/需求拼进订单条目名，后台订单列表直接可见。已实测 422 提示正确。
- 前端三处真实提交（均走 tob-order type=custom）：
  1. 首页「我要定制」弹窗 submitCustom
  2. fangan 方案页「免费定制方案」→ 新留资表单弹窗 + 成功页（含企微活码占位，标记 [WECOM_QR]，待运营替换图片）
  3. 机器人库「企业部署咨询」submitEnterprise（本地 storage 备份保留，含 synced 标记）
- 品牌筛选修复：mapCatalogProduct 的 brand/type 从写死 'all' 改为按商品名关键词推断（detectBrandKey/detectTypeKey，v1）；未识别回退 'all' 恒显示。后续应迁移为后台商品属性驱动。
- 待办（用户操作）：后台创建 sku=LEAD-CUSTOM 的商品（名称如「定制咨询线索」，价格 0），否则留资提交会收到 422 提示。
- 回滚：`git revert c3169da <本提交>` 或用 `.claude/backup/T2-lead/` 覆盖。

### [T1-1] 2026-09-06 · 机器页性能：前端缓存
- 提交：c8bfdde
- 文件：`pages/robot/index.vue`、`components/jiqi/changjing.vue`
- 内容：目录数据 5 分钟模块级缓存（onShow 高频触发不再全量重拉）；移除 onShow 里的 enrichProductCards 调用（mock 列表已不渲染，纯浪费 0.85s）；loadBackendScenes 加 5 分钟 TTL。
- 根因实测：artisan serve 单进程排队，原 7 请求关键路径 4.4s。
- 回滚：`git revert c8bfdde` 或用 `.claude/backup/T1-perf/` 内原文件覆盖。

### [T1-2] 2026-09-06 · 机器页性能：后端聚合端点
- 文件：新增 `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/CatalogController.php`；`Routes/api.php` 加 `/api/tob-catalog` 路由；`utils/api.js` 加 catalogApi；`robot/index.vue` loadCatalog 聚合优先、失败自动回退旧多请求逻辑。
- 数据层与原生 `/api/products` 一致（ProductRepository::getAll + ProductResource），前端映射零适配；scenes 段复用 SceneController。
- 实测：首屏关键路径 4.4s → **1.1s**（1 个请求 29.7KB）；配合缓存，切回机器页 0 请求秒开。后台改商品 5 分钟内生效，急用刷新前端即可。
- 回滚：`git revert <本提交>`；新控制器可直接删除，前端自动走回退逻辑。

### [基线] 2026-09-06 · 机制建立
- 动作：git init（main 分支，core.autocrlf=false 保证字节级保真）+ 本 .gitignore + 本文件；**未修改任何现有文件**。
- 排除入库：两个 Bagisto 的 `vendor/`、`storage/`，根目录 `unpackage/`、`node_modules/`、`.env*`（.env.example 除外）、`.claude/backup/`。
- 基线意义：此后所有 AI 改动都可 diff、可回滚到此点。
