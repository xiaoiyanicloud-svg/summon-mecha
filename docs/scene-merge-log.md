# 场景方案 · 操作痕迹日志

> 用途：记录「场景方案」平台级整合的每一步改动，崩溃可精准修复。
> 纪律：每步先备份 `.claude/backup/`，再记录本日志，小步验证通过再进下一步；异常即停。

## 变更范围
- 数据家：`components/jiqi/changjing.vue`（场景 + 方案 + 后端取数 唯一数据源）
- 机器页：`pages/robot/index.vue`（仅数据改为 import）
- 详情页：`pages/robot/fangan.vue`（顶部共用大图 + 两按钮「场景租用/方案定制」+ 修复 product/detail 坏路由）
- 首页：`pages/home/index.vue`（只改标题「场景方案」+ 跳转）
- 路由/文案：`pages.json` + 详情页标题
- 不动：`EcoCenter.vue`、`pages/home/jineng.vue`、`unpackage/`、后端

## 步骤记录

### Step 1 — 数据迁入 changjing.vue（done）
- 改动文件：`components/jiqi/changjing.vue`
- 内容：加入 `import { sceneApi }`；`export categoryMap`（{label}版 9 分类）、`export sceneProducts`（28 项，自 robot/index.vue:509-538）、`export async loadBackendScenes`（自 robot/index.vue:1174-1203）、`export DEFAULT_SCHEME`、`export SCHEME_DB`（自 rmfangan.vue）。组件默认导出保留，行为不变。
- 改动前证据：changjing.vue 仅列表 UI（list prop + item-click emit），无数据源、无后端取数。
- 备份：`.claude/backup/changjing.vue.<ts>.bak`
- 回滚：用备份文件覆盖 `components/jiqi/changjing.vue` 即可（未动机器页/详情页/首页）。

### Step 2 — 机器页改 import（done）
- 改动文件：`pages/robot/index.vue`
- 内容：移除 import `sceneApi` → 加 `import { sceneProducts, categoryMap, loadBackendScenes } from '@/components/jiqi/changjing.vue'`；删本地 `const sceneProducts`(509-538) → 注释；删 data 内联 `categoryMap`(748-758) → 注释；删 method `loadBackendScenes`(1174-1203) → 注释；`currentCategoryLabel` 改用 import 的 `categoryMap`（去 `this.`）；调用点 `this.loadBackendScenes()`→`loadBackendScenes()`。
- 改动前证据：上述为机架页唯一定义/引用（grep 确认 `sceneApi.` 仅此处、`this.categoryMap`/`this.loadBackendScenes` 各 1 处）。
- 备份：`.claude/backup/robot_index.vue.<ts>.bak`
- 回滚：备份覆盖即可。
- 验证：grep 确认无 `const sceneProducts`/`this.categoryMap`/`this.loadBackendScenes`/`sceneApi.`，import 正确到位。

### Step 3 — 详情页 fangan.vue 改造（done）
- 改动文件：`pages/robot/fangan.vue`
- 内容：
  - script：import `SCHEME_DB/DEFAULT_SCHEME`(自 changjing)；data 加 `activeMode:'rent'`、`rentTitle`、`scheme`；`onLoad` 读 `title`→`loadScheme(title)`；新增 `loadScheme()`/`setActiveMode()`/`onConsult()`；`goProductDetail` 修复坏路由 → `navigateTo /pages/order/checkout?...&type=rent&qty=1`（对齐 checkout onLoad 契约）。
  - template：banner 下加 `mode-bar` 两按钮(场景租用/方案定制)；`v-if="activeMode==='rent'"` 保留场景说明/案例/适用机型；`v-else` 方案定制(痛点→方案/亮点/收益，含「免费定制方案」CTA)；定制收益区用独占类名 `scheme-scene*` 避免与 banner `.scene-name` 冲突。
  - style：新增 `mode-bar`/`mode-btn`/`.card`/`pain-*`/`benefit-*`/`scheme-scene*`/`data-note`/`custom-cta`。
- 改动前证据：详情页仅场景说明+案例+相关机器人；`goProductDetail` 跳不存在的 `pages/product/detail`。
- 备份：`.claude/backup/robot_fangan.vue.<ts>.bak`
- 验证：grep 确认 `mode-bar/mode-btn/setActiveMode/loadScheme/onConsult` 就位、无 `pages/product/detail` 残留；checkout onLoad 参数与传参一致。
- 回滚：备份覆盖即可。

### Step 4 — 首页只改标题+跳转（done）
- 改动文件：`pages/home/index.vue`
- 内容：`square-title`「热门方案」→「场景方案」；`schemeDetail` 跳转 `pages/home/rmfangan`→`pages/robot/fangan`（仍传 title/icon/consultCount/bgColor，详情页用 title 载入方案定制）。右栏「我要租用/我要定制」两卡未动（首页不改乱）。
- 备份：`.claude/backup/home_index.vue.<ts>.bak`
- 验证：grep 确认标题「场景方案」、跳转 `robot/fangan`、无 `rmfangan` 走首页引用。

### Step 5 — 文案/标题统一（done）
- 改动文件：`pages.json`、`pages/home/rmfangan.vue`、`pages/home/index.vue`(注释)
- 内容：`pages/robot/fangan` 标题「场景详情」→「场景方案详情」；`pages/home/rmfangan` 标题「热门方案详情」→「方案定制」；rmfangan 页内 fallback`热门方案详情/热门方案`→`方案定制/场景方案`；首页两处注释「热门方案」→「场景方案」。「热门品牌」(右栏) 不在范围，保留。
- 备份：`.claude/backup/pages.json.<ts>.bak`
- 验证：grep 无 UI 层「热门方案」残留（rmfangan 内部注释 `.vue:190` 保留，无碍）。

## 待用户确认 / 待预览验证
- 场景↔方案 title 映射：首页场景方案卡带 title（如『仓储分拣』）→ `SCHEME_DB` 直接命中；机器页场景进入无 title → 切「方案定制」用 `DEFAULT_SCHEME` 兜底。若想让特定场景对应专属方案内容，需补映射表（不硬编码）。
- 端到端建议：在 HBuilderX / VSCode 预览，走 首页「场景方案」卡 + 机器页「场景」tab + 详情页两按钮 + 租用→结算。

## 第二轮迭代（用户反馈：每个卡片要对应该卡片数据）
问题：现详情页「方案定制」= `SCHEME_DB[title] || DEFAULT`；首页卡只带 title（无 sceneId→场景租用通用）；机器页场景卡带 sceneId（无 title→方案定制全走 DEFAULT 兜底）。故没有任何卡片能同时拿到两份对应数据。
用户决策：首页也换统一场景数据源；每个场景配**专属**方案内容。

### Step 6 — 数据层：每场景专属方案内容（done）
- 改动文件：`components/jiqi/changjing.vue`
- 内容：新增 `export const SCENE_SCHEMES`（键=scene id 1-28，值=该场景专属方案内容：desc/icon/bgColor/consultCount/tags/stats/pains(3)/benefits(3)/scenes）；未破坏 `sceneProducts`/`SCHEME_DB`/`DEFAULT_SCHEME`。
- 备份：`.claude/backup/changjing.vue.<ts2>.bak`（数据源态）
- 验证：grep 确认 `export const SCENE_SCHEMES`(444) + 组件默认导出/`onItemClick`(503) 保留。**注：第一次插入用 `old="\nexport default {"` 作锚但 new 漏补回 `export default {`，导致 476:7 缺分号编译报错 → 已修复补回 `export default {`(475)，SCENE_SCHEMES 28 项 count 验证通过。**——教训：以 `export default {` 为锚插入时，new 必须保留该行。

### Step 7 — 详情页 fangan.vue 改用 sceneId 取专属方案（done）
- 改动文件：`pages/robot/fangan.vue`
- 内容：import 加 `SCENE_SCHEMES`；`loadScheme(key)` 改为 `(key && (SCENE_SCHEMES[key] || SCHEME_DB[key])) || DEFAULT_SCHEME`；`onLoad`→`loadScheme(this.sceneId || this.rentTitle)`；`setActiveMode` 同样传 `this.sceneId || this.rentTitle`。sceneId 优先命中专属方案，其次 title，最后兜底。
- 备份：`.claude/backup/robot_fangan.vue.<ts>.bak`（已有）

### Step 8 — 首页改用场景数据源（done）
- 改动文件：`pages/home/index.vue`
- 内容：import `sceneProducts, loadBackendScenes`(changjing)；scheme-card 渲染改场景字段（`item.bg||bgColor`/`item.name||title`/`item.desc`）；`schemeDetail` 传 `sceneId/item.id`+`category`+`title`；新增 `refreshScenes()`（loadBackendScenes 后 `this.schemes=[...sceneProducts]`）+ `onShow()` 调用。`schemes` data 保留，onShow 覆盖为场景列表（每个卡片带 sceneId，进详情取场景+专属方案）。
- 备份：`.claude/backup/home_index.vue.<ts>.bak`（已有，注意本步在备份后继续改动，如需还原用最新备份）—— 重新备份见下。
- 验证：grep 确认 import/refreshScenes/onShow/sceneId 就位；`loadBackendScenes(287)`/`sceneProducts(288)` 使用。

### 说明
- 首页每卡（场景）→ `robot/fangan?sceneId=|category=|title=场景名`；详情页按 sceneId 查 `SCENE_SCHEMES[id]` → 该场景专属方案定制内容 + 场景租用数据。**卡片数据不再相通**。
- 待办/提示：静态结构验证完成；端到端（H5 预览）未跑，建议按下方清单预览。原 4 张图（static/cangkufj 等）首页卡现改显场景 icon emoji，图闲置——如需场景配图可给 scene 项加 banner。

### Step 9 — 首页前四个带图场景方案 + 详细页对应数据（done）
- 改动文件：`components/jiqi/changjing.vue`、`pages/home/index.vue`、`pages/robot/fangan.vue`
- 内容：
  - changjing：新增 `export const featuredScenes`（4 个带图方案，id 2001-2004，`image`=四图 /static/cangkufj|dianlixj|jiudianfw|zhanguanjd.png，`title`=SCHEME_DB key）。export default 正确保留(486)。
  - 首页：import `featuredScenes`；`schemes=[...featuredScenes,...sceneProducts]`（前四带图）；`schemeDetail` 加传 `image`。
  - 详情页：`loadScheme(sceneId,title)` 双参（sceneId 命中 SCENE_SCHEMES，title 命中 SCHEME_DB）；onLoad 对 `SCHEME_DB[rentTitle]` 填 `sceneInfo`（name/desc/intro/banner=图）；`setActiveMode` 同步双参。
- 数据流：首页前四卡(带图) → `robot/fangan?sceneId=2001&title=仓储分拣&image=...` → 详情：场景租用=方案说明+机型、方案定制=`SCHEME_DB['仓储分拣']` 完整数据。
- 备份：`.claude/backup/{changjing,home_index,robot_fangan}.vue.<ts>.bak`
- 验证：grep 确认 featuredScenes/export default(486)/首页组装(289)/详情双参(215/219) 就位。
- 提示：「场景租用」机型目前仍是详情页内置通用 8 机型（非按场景筛选）；如要按场景精准推机型需另建映射。

### Step 9.1 — 修复「点场景显示商业活动/方案不相关」（done）
- 用户报：点「仓储分拣」进详情顶部显示「商业活动」，方案定制内容与仓储分拣不相关。
- 根因：`fangan.vue` 的 `sceneInfo` 默认 `{name:'商业活动',...}`；只有 title 命中 `SCHEME_DB`（仅4个带图方案）或后端 matched 才改写。普通场景（如「展会品牌活动」category=business_event→商业活动，SCHEME_DB 无它）进入时 sceneInfo 保持默认「商业活动」，方案定制取 `SCENE_SCHEMES[id]` → 与卡片不对应。
- 修复：`fangan.vue` onLoad 增加 `localScene`——按 `sceneId` 从 `sceneProducts` 找到真实场景，用其 `name/desc/intro/banner` 填充 sceneInfo；再被 `SCHEME_DB[title]`（带图方案）覆盖；import 加 `sceneProducts`。
- 效果：任何场景进入，顶部显示该场景真实名称、方案定制对应该场景专属方案；带图方案仍用 SCHEME_DB 完整数据 + 图。

### Step 10 — 后端后台可管理「场景方案」（done）
- 目标：连接 Bagisto 后端，让「场景方案」后台可管理（增删改；编辑场景说明 + 定制方案核心痛点等结构化内容）。
- 后端改动（`my-bagisto-store`）：
  - 迁移 `Shop/.../Migrations/2026_09_05_000001_add_scheme_columns_to_scenes_table.php`：scenes 表加 9 列（icon/bg_color/consult_count/tags/stats/pains/benefits/scheme_scenes/robots，json=array）。
  - 模型 `Shop/.../Models/Scene.php`：fillable/casts 加字段；新增 accessor `getSchemeAttribute()` 组装与前端同构完整对象。
  - `Admin/.../Requests/SceneRequest.php`：补方案字段 rules（textarea nullable|string，服务端再解析）。
  - `Admin/.../Catalog/SceneController.php`：store/update 收集方案字段 + parseBlocks/parseTags/parseStats/parseIdList（textarea→数组，纯字符串解析无注入，走 Eloquent）。
  - `Admin/.../views/catalog/scenes/{create,edit}.blade.php`：加「方案内容」区块（图标/底色/咨询数/三项指标/标签/核心痛点/亮点/预期收益/机型 textarea）。
  - `Admin/.../lang/{zh_CN,en}/app.php`：补方案内容 label 键。
  - `Shop/.../API/SceneController.php`：show() 返回追加 `scheme -> $scene->scheme`。
- 前端：`pages/robot/fangan.vue` `loadTobScene` 读 `res.scheme` 覆盖本地兜底；`setActiveMode` 判断改 `Object.keys(this.scheme).length===0`。
- 验证：`php -l` 5 个后端文件全通过；`migrate` dry-run SQL 正确 + 实跑 DONE（9列已建）；tinker 测 scheme 存取完整（pains/benefits/scenes/robots）；`serve`+`curl /api/scenes/6` 返回 `matched:true`+`scheme`。测试数据已清理。
- 备份：`.claude/backup/backend_*.bak`（Scene 模型/API/Admin 控制器/SceneRequest/create/edit.blade 各一份）。
- 安全：FormRequest 校验 + Bouncer(admin 中间件/ACL=已有 catalog.scenes) + Eloquent 防注入 + 纯文本解析存 JSON（无 eval）+ Storage 图片。

### 待办 / 提醒
- 后台「场景管理」现已可填方案内容；保存后，场景方案详情页「方案定制」优先显示后台内容，未配置走本地兜底。
- 需要用户在后端后台实际新建/编辑一个场景方案（填说明+痛点等）并前端预览确认端到端。
- bl ade 表单未实际渲染验证（Bagisto 组件需运行后台确认无组件报错）；若有报错发我看。

### Step 11 — 修复「后台不能上传场景图片」（done）
- 现象（用户确认）：能选图/预览，但保存后无图 → 文件未提交到服务器。
- 诊断：`uploadBanner` 单独跑 + 完整 `store(SceneRequest)` + 手工注入 `banner_path` 文件 → 均成功（banner_path 存上，测试数据已删）。故后端 OK；根因是 Bagisto `media.images`(x-admin) 组件在真实表单提交时 `input.files` 未设上 → `banner_path[]` 提交为空。已确认 `public/storage` 已 link、default disk=public、非缓存显示问题；`scenes.banner_path` 全 null（从未存成）。
- 修复：
  - `Admin/.../views/catalog/scenes/{create,edit}.blade.php`：场景图区改用**原生 `<input type="file" name="banner_path[]">`**（原生 input 一定提交文件，绕开组件 bug）+ 即时预览（URL.createObjectURL）+ edit 显示当前图 + 「删除当前场景图」checkbox。
  - `Admin/.../Catalog/SceneController.php` `uploadBanner`：删图判断由 `!isset($data['banner_path'])` 改为显式 `request()->boolean('remove_banner')`（避免无新文件时误删旧图）。
  - `Admin/.../lang/{zh_CN,en}/app.php`（edit）：加 `remove-banner` 键。
  - `D:\php.ini`：`upload_max_filesize` 2M→20M、`post_max_size` 8M→40M（大图被拒）。
- 验证：`php -l SceneController` 通过；`ini_get` 显示 20M/40M 生效；tinker 注入 `banner_path` 数组 → store 成功（原生提交同结构）。
- 备份：`.claude/backup/backend_{Admin_SceneController,create,edit}.blade.<ts>.bak`。
- **注意：需重启后端 PHP 服务**（web server 重新加载 php.ini）后上传限制生效。

### Step 12 — 修「新增场景前端不显示」+ 上传改用 storeAs（done）
- 问题2 根因：新增场景 `status` 存入 0（停用）→ 前端 `getScenes`（`where status=1`）过滤掉，故前端不显示。`store` 原用 `$request->boolean('status')?1:0`，未提交 status 时=0。
  - 修复：`status => $request->missing('status')?1:($request->boolean('status')?1:0)`（store+update）→ 新建默认启用，显式关闭才停用。tinker 验证 status(missing)=1。
- 问题1 根因：`banner_path` 全 null（上传仍失败）。uploadBanner 用 Intervention Image + GD `encode('webp')`，部分环境/大图内存会失败。
  - 修复：uploadBanner 改用 Laravel 原生 `$file->storeAs($dir,$name)` 存原图（保留原扩展名），绕开 GD/webp。tinker 验证 banner 落盘 exists=yes、url 可访问。
- 验证：`php -l` 通过；tinker 两者 OK；测试数据已清理。
- 备份：`.claude/backup/backend_Admin_SceneController.<ts>.bak`。

### Step 13 — 后台场景列表「状态」列可一键切换（done）
- 需求：场景管理主页，每行状态旁可直接点开/关（不用进编辑页）。
- 改动（`my-bagisto-store/packages/Webkul/Admin`）：
  - `Config/Routes/catalog-routes.php`：scene 组加 `Route::post('status/{id}','updateStatus')->name('admin.catalog.scenes.status')`。
  - `Http/Controllers/Catalog/SceneController.php`：新增 `updateStatus(Request,$id)`（findOrFail + boolean status + save + session flash + redirect back）+ `use Illuminate\Http\Request`。
  - `DataGrids/Catalog/SceneDataGrid.php`：status 列 closure 改为「可点击开关表单」（POST + `csrf_field()` + hidden status + button），有 `catalog.scenes.edit` 权限才可点，否则显示纯徽标。点一下提交切换并刷新列表。
- 安全：POST + CSRF + `admin`(Bouncer) 中间件 + `bouncer()->hasPermission` 控制可点；Eloquent 写入。
- 验证：`php -l` 3 文件通过；`route:list` 确认 `admin.catalog.scenes.status` 注册。
- 备份：`.claude/backup/backend_{catalog-routes,Admin_SceneController,SceneDataGrid}.<ts>.bak`。

### Step 14 — 状态切换改 AJAX 不刷新 + 批量操作（done）
- 需求：状态列点一下不整页刷新；加批量操作。
- AJAX 切换：
  - `SceneController::updateStatus`：`expectsJson()` 时返回 `{success,status}` JSON，否则保留原 redirect（兼容非 AJAX）。
  - `SceneDataGrid` status 列 closure 改「AJAX 按钮」（`data-route/data-current/data-on-text/data-off-text` + `onclick=__sceneToggle`）。
  - `scenes/index.blade.php`：`@push('scripts')` 定义 `__sceneToggle`（fetch POST + `X-CSRF-TOKEN` → 更新该徽标，不 reload）。layout 有 `@stack('scripts')`(147) 注入成功。
- 批量操作（Bagisto DataGrid mass action）：
  - 路由：`mass-update`→`massUpdate`、`mass-delete`→`massDestroy`（`admin.catalog.scenes.mass_update/mass_delete`）。
  - `SceneController::massUpdate`（`indices`+`value` in:{0,1} 批量改 status）、`massDestroy`（`indices` 批量删 + 删图）。
  - `SceneDataGrid::prepareMassActions`：批量删除 + 批量更改状态（启用/停用 options），按 `catalog.scenes.delete/edit` 权限显示。
  - lang `index.datagrid.update-status`（zh/en）。
- 安全：`updateStatus`/mass 均为 POST + CSRF + admin(Bouncer) 中间件 + bouncer 权限；`$request->validate` + Eloquent 写入。
- 验证：`php -l` 3 文件通过；`route:list` 见 3 条 scene 路由；layout `@stack('scripts')` 存在。

### Step 15 — 把「仓储分拣/电力巡检/酒店服务/展会接待」落到后台场景管理（done）
- 需求：这四个有图有完整方案数据的，在后台「场景管理」建成可编辑卡片。
- 要点：先用 id 17-20 插入 → 与前端 mock 场景 id 17-20（厂区园区巡逻等）冲突会覆盖 → **删除并改用 id 101-104** 重建（避开前端 1-28 与现有后端 id）。
- 结果：scenes 表新增 4 条（id 101-104）：仓储分拣/电力巡检/酒店服务/展会接待，status=1，带 icon/bg_color/consult_count/tags/stats/pains(3)/benefits(3)/scheme_scenes(3)/robots；4 张图（cangkufj/dianlixj/jiudianfw/zhanguanjd.png）复制到 `storage/app/public/scene/{id}/` 并设 banner_path。
- 验证：tinker 确认 4 条 status=1、banner 落盘 exists=yes、方案内容完整。
- **注意/待对齐**：后台这 4 条（101-104）与前端首页「场景方案」那 4 张带图卡（前端写死 featuredScenes id 2001-2004，含 SCHEME_DB 数据）是**同一批方案的两套**。当前后台编辑只反映到**机器页**（getScenes）；若要让后台编辑也反映到**首页**那 4 张卡，需把首页 featured 改为读后端（可选，待用户确认）。

### Step 16 — 修复「机器页场景无图（localhost 连接被拒）」+ 退出报错（done）
- 现象：机器页点进仓储分拣等后端场景，顶部图 URL = `http://localhost/storage/...` → `ERR_CONNECTION_REFUSED` 无图。
- 根因：后端 `Storage::url(banner_path)` 用 `APP_URL=http://localhost` 拼绝对 URL；前端 `changjing.vue` 的 `loadBackendScenes` 直接 `found.banner = s.banner`（未 normalize）→ 详情页 onLoad 的 `localScene.banner` 也是原始 localhost URL → 图加载失败。
- 修复：banner 统一走 `normalizeImageUrl`（去 host、拼 `BASE_URL`）：
  - `components/jiqi/changjing.vue`：import `BASE_URL` + 新增模块级 `normalizeImageUrl()`；`loadBackendScenes` 的 `found.banner` 与 push 的 `banner` 都 normalize。
  - `pages/robot/fangan.vue`：onLoad `localScene.banner` 改 `this.normalizeImageUrl(localScene.banner)`。
- 说明：`reportAllChanges`/`startTime` 仍是无害的框架/后台 dev 噪音，不影响功能。
- 备份：`.claude/backup/{changjing,fangan}.<ts>.bak`。

### Step 17 — 场景管理加「权重」，全部场景按权重优先（done）
- 需求：后台场景管理加权重字段，权重越高在「全部场景」越靠前；拿仓储分拣/电力巡检/酒店服务/展会接待四个实验，设最高。
- 后端：
  - 迁移 `..._000002_add_sort_to_scenes_table.php`：scenes 加 `sort`(integer default 0)。已跑 migrate。
  - `Scene` model：fillable/casts 加 `sort`(integer)。
  - `SceneRequest`：`sort nullable|integer|min:0`。
  - `Admin/SceneController` store/update：`sort => (int) $request->input('sort',0)`。
  - `Admin/views/catalog/scenes/{create,edit}.blade`：加「权重」number 输入。
  - `Admin/SceneDataGrid`：加 `sort` 列（integer，可排序）。
  - `Shop/API/SceneController` index：`orderBy('sort','desc')->orderBy('id','asc')`；index/show 返回 `sort`。
  - lang zh/en create/edit/datagrid 加 `sort` 键。
- 前端：
  - `pages/robot/index.vue` `filteredSceneList`（全部场景）按 `(b.sort??0)-(a.sort??0)` 降序排序。
  - `components/jiqi/changjing.vue` `loadBackendScenes`：found 覆盖 `found.sort`；push 场景带 `sort`。
- 数据：101-104 四个场景 `sort=999`（最高）。
- 验证：`php -l` 3 文件通过；getScenes 排序 `#101 仓储分拣/#102 电力巡检/#103 酒店服务/#104 展会接待` 在最前，其余 sort=0 在后。
- 备份：`.claude/backup/` 相关。

### Step 17.1 — 修复「加权重后四个场景在机器页消失」（done）
- 现象：加 sort（权重）后，四个（101-104）在机器页「全部场景」消失。
- 根因：机器页场景列表 `filteredSceneList` 基于**模块级非响应数组** `sceneProducts`；`loadBackendScenes()` 是 async 且 onShow 里**不 await**，`push` 后端场景到 `sceneProducts` 后 **computed 不会重算**，导致后端 merge 进来的场景（含 101-104）可能没进渲染结果。
- 修复（`pages/robot/index.vue`）：
  - data 加响应式 `sceneVersion: 0`。
  - `filteredSceneList` 首行 `this.sceneVersion`（建立响应式依赖）。
  - onShow 里 `loadBackendScenes().then(() => this.sceneVersion++)` → merge 完成后强制重算，后端场景（含 101-104）进入列表并因 `sort=999` 排最前。
- 验证：后端 `getScenes` 返回 101-104 且 sort=999 排最前（curl 确认）；前端用 sceneVersion 保证 merge 后刷新。
- 提示：前端需强刷（Ctrl+F5）+ 后端重启 PHP 服务（改了 orderBy/字段）后生效。

### 备注
- 首页「场景方案」的 featured 仍是前端写死（2001-2004）；机器页已能反映后端 101-104。

### Step 18 — 场景租用配置「适用机器」（拉取目录租用分类产品，done）
- 需求：后台场景租用能配置适用机器，从目录「租用」分类产品多选；前端场景租用机型改显示目录租用产品。
- 后端（my-bagisto-store）：
  - `Admin/.../Catalog/SceneController.php`：`create()`/`edit()` 解析租用分类（`CategoryRepository::findBySlug('rent')`，回退 name='租用'）并用 `ProductRepository::getAll(['category_id'=>..,'status'=>1])` 取租用产品，`compact('rentProducts')`；新增 `getRentProducts()`/`normalizeRobotIds()`（兼容数组 checkbox 与旧 textarea）；store/update 的 robots 改 `normalizeRobotIds`。
  - `Admin/.../Requests/SceneRequest.php`：`robots` 改 `nullable|array`。
  - `Admin/.../views/catalog/scenes/{create,edit}.blade.php`：robots textarea 改为「适用机器」租用产品**多选 checkbox**（`name="robots[]"`），edit 按 `$scene->robots` 回显；无租用产品时提示。
  - lang zh/en create/edit `robots`→'适用机器'/'Applicable Robots'。
- 前端（`pages/robot/fangan.vue`）：
  - import 加 `categoryApi/productApi`；data 加 `sceneRobots/rentCatalog`。
  - 新增 `loadRentProducts()`（categoryApi 找 rent → productApi.getProducts({category_id}) → `mapCatalogProduct` 内联）+ `mapCatalogProduct`。
  - `loadTobScene` 读 `res.scheme.robots` → `sceneRobots`。
  - `filteredProducts` computed 改为按 `sceneRobots` 从 `rentCatalog` 匹配（`bagistoId`/`id`），为空回退本地 `allProducts`。
  - onLoad 调 `loadRentProducts()`；机型卡加 `<image v-if="item.image">` + `.product-image-img` 样式。
- 验证：确认租用分类 id=2(slug=rent)、租用产品 3 个；后端 `php -l` 通过；101-104 预设 `robots=[6,8,9]`。
- 提示：后台需重启后端 + 前端强刷；后台编辑场景的「适用机器」勾选租用产品 → 保存即存 scene.robots。

### Step 19 — 场景管理「热门」开关（联动首页场景方案，done）
- 需求：后台场景加热门开/关；热门场景显示在首页「场景方案」。用户决策：首页完全由后台热门控制（frontend featured 退役）；101-104 默认热门。
- 后端：
  - 迁移 `..._000003_add_is_hot_to_scenes_table.php`：scenes 加 `is_hot` bool default 0。
  - `Scene` model fillable/casts 加 `is_hot`(boolean)。
  - `Admin/SceneController` 新增 `toggleHot(Request,$id)`（翻转 is_hot，`expectsJson` 返回 `{success,is_hot}`）；store/update 加 `'is_hot' => boolean?1:0`。
  - 路由 `admin.catalog.scenes.hot`（POST hot/{id}）。
  - `SceneDataGrid` 加「热门」列（closure 可点开关，`__sceneHotToggle`）；`index.blade` 加 `__sceneHotToggle` JS（复用 __sceneCsrf）。
  - `create/edit.blade` 加「热门」switch；lang zh/en datagrid+create/edit 加 is_hot/hot/not-hot。
  - `Shop/API/SceneController` index 返回加 `icon/bg_color/is_hot`；show 加 `is_hot`。
  - 101-104 用 `is_hot=1`（默认热门）。
- 前端：
  - `changjing.vue` `loadBackendScenes`：found/push 合并 `icon/bg_color/is_hot`。
  - `pages/home/index.vue` `refreshScenes`：`const hot=sceneProducts.filter(s=>s.is_hot); this.schemes = hot.length?hot:sceneProducts`（featured 退役）；卡渲染 `image v-if="item.image || item.banner"`（后端热门场景显示 banner 图）；去掉 `featuredScenes` import。
- 验证：`php -l` 3 文件通过；`route:list` 见 scenes.hot；101-104 is_hot=1。
- 提示：重启后端 + 前端强刷；后台「热门」列点一下即切（不刷新），首页场景方案随之增/减。

### Step 19.1 — 修复「后台场景管理无法加载卡片数据」（done）
- 现象：加「热门」后，后台场景列表加载不出数据。
- 根因：`SceneDataGrid::prepareQueryBuilder` 只 `select('id','name','category','status')`，但 Step17/19 新增的 `sort`、`is_hot` 列闭包读取 `$value->sort`/`$value->is_hot` —— 该查询未 select 这两个字段 → 属性不存在 → DataGrid 处理报错 → 列表 500/加载失败。
- 修复：`prepareQueryBuilder` `select(... , 'sort', 'is_hot')` 并 `orderBy('sort','desc')->orderBy('id','asc')`（列表也按权重排）。
- 验证：`php -l` 通过；datagrid 查询取到 `sort=999/hot=1`（101-104）。

### Step 19.2 — 修复「首页取消热门后仍显示场景卡」（本地死数据兜底，done）
- 现象：后台取消「仓储分拣」热门(is_hot=0)，首页「场景方案」仍显示仓储分拣卡；开后端其它热门也不上首页。
- 排查（排除法，后端/接口全证对）：
  - 数据库 scenes：101 is_hot=false、103/104/6 is_hot=true（用户取消已存库）→ 后端对。
  - `curl /api/scenes`：返回 101 is_hot=false，热门={103,104,6}，无分页、字段全 → API 对。
  - 首页源码 `refreshScenes` filter(is_hot)、无 featuredScenes import → 前端取数逻辑对。
- 真正根因：首页 `data.schemes`（原 192-207）**初始化是写死的 14 项死数据**，前 4 项=仓储分拣/电力巡检/酒店服务/展会接待（带图）。渲染遍历 `schemePages`(=this.schemes)；`refreshScenes` 需 `await` 后端返回后才覆盖，在后端返回前/失败帧页面先渲染（且停留）在**这份写死死数据** → 仓储分拣永远显示，与实际 is_hot 无关。即「取消后仍用本地死数据」。
- 修复：`pages/home/index.vue` `data.schemes`(14 项死数据) → `schemes: []`，首页方案完全由 refreshScenes 用后端热门填充，无任何写死卡兜底。
- 行为（改后）：后端返回→首页=热门(103/104/6)、仓储分拣消失；后端请求失败→`hot=[]`→回退 `sceneProducts`(本地 mock，无 is_hot 假卡)→也不显示仓储分拣。
- 效果：仓储分拣在任何情况下都不再作为「写死卡」出现。
- 提示：HBuilderX 重新「运行到浏览器」（源码改动需重新编译）。
- 版本：在已改文件上二次修正，未先独立备份；回滚=把死数据数组重新写回 data.schemes（参考早期 backup）。

### Step 19.3 — 修复「删死数据后首页场景方案全消失」（done）
- 现象：Step19.2 把 data.schemes 写死死数据改为 `[]` 后，首页「场景方案」卡片全消失。
- 根因：死数据此前掩盖了「`refreshScenes` 未在运行时把 `this.schemes` 填上」的问题；删掉死数据后 schemes 停在初始空数组 → 全空。仅当 `this.schemes` 保持初始 `[]` 才会全空。
- 排查（全证对）：数据库 is_hot / API 返回 / 后端 CORS(`allowed_origins=*`) / `sceneProducts`(28 项非 undefined) / `refreshScenes` filter 逻辑，均正确。
- 修复（`pages/home/index.vue`）：
  - `data.schemes: []` → `schemes: [...sceneProducts]`（初始给本地场景兜底，**绝不空**；onLoad/onShow 用后台热门覆盖）。
  - 新增 `onLoad(){ this.refreshScenes() }`；保留 `onShow` 也调 → 首屏 + 每次显示都拉后台热门。
- 行为：后端正常→首页=热门(is_hot=true 场景，仓储分拣不在)；后端失败→显示本地场景(28 项分页，无 is_hot 假卡)。
- 提示：HBuilderX 重编译；若仍显示本地场景而非热门 → 需查前端 getScenes 实际响应（浏览器 Network 里 /api/scenes 是否 200 且 data 含 is_hot=true）。

### Step 19.4 — 修复「首页方案卡消失/文字乱/咨询人数丢失」（生命周期钩子错位，done）
- 现象：上一轮后首页「场景方案」卡要么消失、要么显示本地 mock 且文字溢出乱、咨询人数丢失。
- **真根因（总根因）**：首页页面生命周期钩子 `onLoad`/`onShow` **被放在了 `methods` 对象内部**（在 methods 的 `},` 之前），而 uni-app 只调用**与 methods 平级**的页面生命周期。它们从不触发 → `refreshScenes()` 从未执行 → `this.schemes` 永远停在 data 初始值。这才是「取消热门无效 / 首页不更新」的真正原因（此前 CORS/缓存/编译全是表象）。
  - 死数据版：初始=写死 14 项(含仓储分拣) → 仓储分拣永远显示。
  - 改 `[]`/sceneProducts 版：初始=本地 mock → 咨询人数丢失 + desc 长溢出乱。
- 修复（`pages/home/index.vue`）：
  - 把 `onLoad`/`onShow` 从 methods 内**移到页面顶级**（与 onPageScroll 平级）→ 框架真正触发 refreshScenes，加载后端热门覆盖。
  - 卡片模板 `consult` 改为优先显示 `consultCount+'咨询'`，无则显示 desc。
  - CSS：`.scheme-card-title` 单行截断(nowrap+ellipsis)、`.scheme-card-consult` 两行截断(clamp)+`line-clamp` 标准属性 → 文字不再溢出。
- 行为：refreshScenes 生效后首页=后端热门(is_hot=true)；仓储分拣(取消热门)消失；新开热门自动上首页；咨询人数：后端 consult_count 有则显示数字，否则显示 desc(两行截断)。
- 提示：HBuilderX 重编译。

### Step 19.5 — 首页方案卡只显示「图片 + 标题 + 咨询人数」（去 desc，done）
- 需求：首页方案卡仅需 图片/标题/咨询人数，去掉详细描述。
- 改：
  - `pages/home/index.vue` 卡 `consult` 区：`consultCount ? consultCount+'咨询' : '预约咨询'`（不再显示 desc）。
  - `components/jiqi/changjing.vue` `loadBackendScenes`：found/push 合并 `consultCount = s.consult_count ?? s.consultCount ?? 0`。
  - 后端 `Shop/API/SceneController@index` 返回追加 `consult_count`。
- 验证：`php -l` 通过。**需重启后端 PHP 服务 + 前端 HBuilderX 重编译。**

### Step 20 — 后台场景管理列表加「咨询人数」内联编辑（done）
- 需求：后台场景管理每个场景都能填咨询人数数字。
- 现状：`scenes` 表已有 `consult_count`；模型 fillable+cast；`SceneRequest` 校验；`store/update` 保存；create/edit 表单已有「咨询数」输入框（label 键 `consult-count`）。
- 新增（列表内联编辑，仿状态/热门）：
  - 路由 `post consult-count/{id}` → `updateConsultCount`。
  - `SceneController::updateConsultCount`（AJAX 保存 `consult_count`）。
  - `SceneDataGrid`：`prepareQueryBuilder` select 加 `consult_count`；新增「咨询人数」列（可编辑 number 输入框，`onchange=__sceneConsult`）。
  - `index.blade.php`：加 `__sceneConsult` JS（AJAX POST，改完失焦即存）。
  - lang zh/en datagrid 加 `consult-count` 列名；create/edit 的「咨询数」统一改「咨询人数」。
- 验证：`php -l` 3 文件通过；`route:list` 见 `admin.catalog.scenes.consult-count`；`view:clear`。
- 提示：刷新后台「场景管理」页面；列表每行「咨询人数」数字框改完即存，无需进编辑页。
