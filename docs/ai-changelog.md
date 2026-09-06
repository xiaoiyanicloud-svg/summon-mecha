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
