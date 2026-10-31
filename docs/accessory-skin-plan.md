# 「配件皮肤」产品与工程方案 v1

> 范围：首页「生态中心 → 配件皮肤」与机器页「配件」两个入口，以及背后的商品、适配、详情、下单、后台运营。
> 结论先行：当前是两个入口共用一套「后台商品 + 15 条假数据兜底」的草稿最小闭环。它最大的问题不是视觉，而是**把不可售的假商品做成了可下单路径**，并且**没有表达“装不装得上”**——而后者才是配件业务的核心。
> 本文只给方案，不改代码。P0/P1 的每一步都能独立验收、独立回滚。

---

## 0. 一页结论

| 项 | 结论 |
|---|---|
| 现状定位 | 商品格子，不是配件业务 |
| 真实供给 | `配件` 分类 4 个商品：1 个真实商品（3D 视觉相机）+ 3 个测试商品；无子分类、无配件属性、无适配数据 |
| 前端展示 | 首页固定注入 15 条 mock；后台有货时与真货按名称去重后**混排**；机器页有真货时**左侧分类/品牌/机型筛选全部失效** |
| 交易风险 | mock 商品 `id=1..15` 与真实 products 表 id 冲突，可经「详情 → 立即下单」生成**关联到错误商品**的订单 |
| 价格风险 | 无真实划线价时，前端按现价 ×1.2 **编造原价**并展示删除线 |
| 适配风险 | 「适配机型」只来自后台 related products（root 仅 5 条关系），没有适配等级、转接件、限制条件；“官方正品”文案对所有配件生效 |
| 建议方向 | 从「商品墙」升级为「**适配驱动的配件与安装服务**」：先选机器 → 只看到装得上的 → 看清怎么装/谁装/多久到 → 买或寻源 |
| 交付节奏 | **P0 修真货与诚实**（3–5 人日）→ **P1 建适配与真目录**（7–10 人日）→ **P2 联动、组合与运营**（按需） |
| 实施状态 | **P0 已完成（2026-10-24）**；**P1 代码完成并已在本机执行迁移/分类/接口验证（2026-10-24）**；浏览器 e2e、PHPUnit 未跑；P2 未开始 |
| 明确不做 | 不做大而全配件商城；不做假库存/假原价；不做“全品牌通用”承诺；P1 前不做复杂 BOM 报价 |

---

## 1. 现状实测（不是文档转述）

### 1.1 首页生态中心

| 事实 | 位置 |
|---|---|
| 「配件皮肤」tab 固定渲染 `accessoryCards`，3 列网格，无搜索、无分类、无排序、无分页 | [components/EcoCenter.vue:22-37](../components/EcoCenter.vue#L22-L37) |
| 15 条写死 mock：合金护甲、格斗铲、机甲炫彩皮肤…… | [components/EcoCenter.vue:135-151](../components/EcoCenter.vue#L135-L151) |
| 真货与 mock **只按名称去重**，然后直接拼接展示 | [components/EcoCenter.vue:166-173](../components/EcoCenter.vue#L166-L173) |
| mock 有 id、价格、图标，点击进入机器页详情，随后可走「立即下单」 | [components/EcoCenter.vue:273-278](../components/EcoCenter.vue#L273-L278) |
| 拉取失败时保留 mock（注释明确“不置空”） | [components/EcoCenter.vue:230-248](../components/EcoCenter.vue#L230-L248) |
| 卡片 `view` 无 `role/tabindex`，键盘不可达；名称无截断规则；3 列在 375px 下每格约 111px | 同上模板 |

### 1.2 机器页配件 tab

| 事实 | 位置 |
|---|---|
| `filteredAccessoryList` 在 `items` 非空时**直接 return items**，左侧分类、品牌、机型筛选全部不生效 | [components/jiqi/peijian.vue:242-257](../components/jiqi/peijian.vue#L242-L257) |
| 品牌列表是 42 个写死品牌；机型列表由 mock 数据反推，与后台商品无关 | [components/jiqi/peijian.vue:154-175](../components/jiqi/peijian.vue#L154-L175) |
| 20 条 mock 带 `brands/models`，但只在后台无货时才展示，且同样可下单 | [components/jiqi/peijian.vue:177-238](../components/jiqi/peijian.vue#L177-L238) |
| 真货 `category` 一律被写成「配件」，子分类信息丢失 | [pages/robot/index.vue:1471](../pages/robot/index.vue#L1471) |

### 1.3 详情与下单

| 事实 | 位置 |
|---|---|
| 配件详情复用通用商品详情浮层；「适配机型」来自 `GET /api/products/{id}/related` | [pages/robot/index.vue:311-317](../pages/robot/index.vue#L311-L317)、[1095-1110](../pages/robot/index.vue#L1095-L1110) |
| 产品参数来自 Bagisto `custom_attributes`，root 的 4 个配件几乎没有可用属性 | [pages/robot/index.vue:877-887](../pages/robot/index.vue#L877-L887) |
| 配件与购买共用 `BUY_SERVICE`，统一写「官方正品」 | [pages/robot/index.vue:637-654](../pages/robot/index.vue#L637-L654) |
| 无真实划线价时 `originalPrice = 现价 × 1.2`，机器页会展示删除线 | [pages/robot/index.vue:1490](../pages/robot/index.vue#L1490) |
| 下单 `product_id` 优先取 `bagistoId`，否则取 mock `id` | [pages/robot/index.vue:1244](../pages/robot/index.vue#L1244) |
| 后端只校验 `product_id` 存在于 products 表，不校验分类/类型/是否匹配 | [my-bagisto-store/.../TobOrderController.php:80-95](../my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/TobOrderController.php#L80-L95) |

### 1.4 数据底座

- 分类是**平铺**的：`root → rent / buy / accessory / skill`，`配件` 下没有子分类（root 实测）。
- `配件` 商品 4 个：`3D视觉相机`（真实商品，但短描述残留占位 `<p>1</p>`、meta_title 为 `1`）、`机器人备用电池包`、`智能快充充电桩`、`通用机械臂夹爪套装`（后三个为 TEST 数据）。
- 商品属性表只有 Bagisto 默认属性；`brand` 属性存在但配件未填；没有材质、安装方式、包装清单、质保、交期等配件字段。
- `product_relations` 全库 5 条；配件的「适配机型」实际上是关联到 rent/buy 商品，而不是平台机型库。
- 平台已有统一机型库 `device_model`（18 条，10 条 active / 8 条 display_only），技能侧已用 `model_code` 做兼容性输入。**配件没有接这条真相源。**

### 1.5 结论

这是“能点开”的草稿，不是“能成交”的业务：

1. **不可售的东西可售**（mock 可下单、会撞真实商品 id）。
2. **可售的东西选不对**（筛选失效、无子分类、无机型适配）。
3. **卖点不可信**（假原价、全体“官方正品”、无依据的适配列表）。
4. **服务没表达**（安装、调试、质保、交期、寻源全部缺失）——而这些恰是平台相对纯电商的护城河。

---

## 2. 问题分级

### P0 · 必须先修（诚信与正确性）

| # | 问题 | 影响 | 处理 |
|---|---|---|---|
| P0-1 | mock 配件可下单，且 id 与真实商品冲突 | 可能生成错误商品订单、客服无法履约 | mock 一律 `is_demo=true`，详情页禁用下单；或直接移除可售 mock |
| P0-2 | 机器页筛选在真实目录下完全失效 | 用户以为按分类/机型筛了，实际没筛 | 真货路径必须执行分类/机型过滤；无匹配显示空态 |
| P0-3 | 无真实划线价时编造 ×1.2 原价 | 价格欺诈风险 | 只有 `regular > final` 才展示原价；否则不展示 |
| P0-4 | 无图商品落到 Bagisto 占位图 | 卡片像坏图 | 过滤 `product-placeholder`，回退分类图标 |
| P0-5 | 所有配件写「官方正品」 | 对第三方配件是虚假承诺 | 按 `official` 标记分档：官方自营 / 平台严选 / 第三方 |
| P0-6 | 后台无货时首页仍显示 15 条假货 | 用户看到买不到的东西 | 无货即空态 + 寻源入口，不用假货补位 |
| P0-7 | 详情页无适配依据、无安装/质保/交期 | 买前无法判断 | 先补“适配机型（来源） + 安装方式 + 质保 + 交期”四块 |

### P1 · 核心价值（把“配件”做成业务）

- 配件子分类与配件档案。
- 基于 `device_model` 的适配模型与“为我的机器人找配件”。
- 专用配件目录 API（筛选/分页/排序）。
- 首页与机器页改版；详情页升级。
- 后台适配维护与上架前置校验。

### P2 · 生态联动（按业务需要）

- 机器详情“适配配件/常见搭配”。
- 配件清单/组合下单（BOM 的轻量版）。
- 皮肤定制报价流（设计稿 → 报价 → 定制单）。
- 寻源需求（找不到配件 → 留资 → 运营跟进）。
- 指标与运营看板。

---

## 3. 产品定位与原则

### 3.1 一句话定位

> **不是“卖配件”，而是“让用户的机器人装得上、装得好”。**

平台不是制造商，也不是纯货架。真正的差异化是：**机型知识 + 适配判断 + 线下安装/调试 + 寻源能力**。配件页要把这四件事表达出来，而不是再做一个九宫格。

### 3.2 五条原则

1. **真**：页面上出现的每一个可下单商品，都必须对应后台已上架商品；没有就诚实空态。
2. **准**：先选机器，再选配件；适配有等级、有来源、有转接条件。
3. **装得上**：详情必须回答“怎么装、谁装、装完怎么验”。
4. **可服务**：质保、交期、退换、安装服务写清楚；第三方不冒充官方。
5. **可增长**：数据结构能长成“配件 + 技能 + 服务 + 整机”的组合，不挡未来的套件/BOM。

### 3.3 命名（已拍板）

| 位置 | 文案 | 说明 |
|---|---|---|
| 首页 tab | **配件皮肤**（保留） | 不改名，避免“改装”等可能引发合规解释的表述 |
| 机器页 tab | **配件**（保留） | 与顶部五 tab 的短词风格一致 |
| 配件内二级 | **功能配件 / 外观皮肤 / 定制皮肤** | 名称不变，但用二级分类把“功能件、成品皮肤、定制皮肤”三种决策路径分开 |

对外文案纪律：
- 业务名统一用「配件皮肤」，不使用「改装」作为对外业务名。
- 安装能力表述为「安装 / 调试服务」，不暗示“非授权改装”。
- 定制皮肤表述为「定制皮肤 / 定制涂装」，走报价流，不承诺固定交期与总价。
- 这样既保留现有入口的合规口径，又能在内部把适配、安装、定制三条链路做清楚。

---

## 4. 信息架构与用户流程

### 4.1 三个层次

```
发现层（首页生态中心）
  你是谁（机型）→ 有什么（分类/精选）→ 找不到怎么办（寻源）
        ↓
选购层（机器页配件 tab）
  搜索 / 机型 / 分类 / 排序 / 分页 → 商品卡
        ↓
决策层（配件详情）
  适配矩阵 → 参数/包装/安装/质保/交期 → 买 / 预约安装 / 定制报价
```

### 4.2 首页「配件皮肤」tab

```
┌─────────────────────────────────────────┐
│ 配件皮肤                                 │
│ [为我的机器人找配件 ▾]  🔍 搜索配件       │  ← 机型选择器（默认“全部机型”）
├─────────────────────────────────────────┤
│ [全部] [外观皮肤] [防护结构] [动力续航]   │  ← 分类 chips（来自后台子分类）
│ [感知导航] [执行末端] [工具耗材]          │
├─────────────────────────────────────────┤
│ 配件推荐                    查看更多 →   │
│ ┌──────────┐ ┌──────────┐               │  ← 2 列网格，首屏 6 个
│ │ 真图      │ │ 真图      │               │
│ │ 电池包    │ │ 夹爪      │               │
│ │ 直装 Go2  │ │ 需转接    │               │
│ │ ¥899      │ │ ¥1,599    │               │
│ └──────────┘ └──────────┘               │
├─────────────────────────────────────────┤
│ 找不到合适的配件？                        │
│ 告诉我们机型与需求，运营 48h 内响应 →     │  ← 寻源入口（真实留资）
└─────────────────────────────────────────┘
```

要点：
- 机型选择器数据来自 `device_model`（已登录用户可优先读「我的设备」）。
- 精选用后台 `featured`，不写死。
- 无商品时只显示空态和寻源，不显示任何 mock。
- 首页只做“发现”，不做全量列表；全量去机器页。
- **「定制皮肤」是入口而不是商品列表**：进入后展示模板/灵感图与“提交定制需求”，不展示固定价商品。

### 4.3 机器页「配件」tab

```
┌──────────┬──────────────────────────────┐
│ 分类      │ [搜索] [适配机型 ▾] [综合/价格]│
│ 全部      ├──────────────────────────────┤
│ 外观皮肤  │ ┌──────────┐ ┌──────────┐    │
│ 防护结构  │ │ 真图      │ │ 真图      │    │
│ 动力续航  │ │ 名称      │ │ 名称      │    │
│ 感知导航  │ │ 适配 Go2  │ │ 需转接    │    │
│ 执行末端  │ │ ¥899      │ │ ¥399      │    │
│ 工具耗材  │ └──────────┘ └──────────┘    │
└──────────┴──────────────────────────────┘
```

- 左侧 = 后台子分类，不再用假品牌列表。
- 顶部 = 真实机型筛选 + 搜索 + 排序。
- 服务端筛选与分页，不再一次拉 200 条。
- 保留空态/错误态/重试；错误不伪装成“没有匹配”。

### 4.4 配件详情

按顺序回答用户的问题：

1. **这是什么**：真实图集、名称、价格（有折扣才显示原价）、一句话摘要。
2. **能不能装**：适配机型矩阵，每行「机型 + 直装/需转接/需定制/不适配 + 说明 + 来源」。
3. **装完什么样**：外观皮肤给效果图/安装前后对比；功能件给接口/尺寸/供电要求。
4. **怎么装**：自助安装 / 需工程师 / 到店安装调试；是否需要转接件；预计工时。
5. **买了之后**：包装清单、质保月数、发货时效、退换规则。
6. **搭配什么**：同机型其他配件、适配整机、相关技能（后续）。
7. **CTA**：立即购买 / 加入清单 / 预约安装 / 获取定制报价（按商品类型切换）。

### 4.5 关键流程

**成品配件**：选机型 → 看到“直装” → 详情确认接口 → 下单 → （可选）预约安装 → 订单完成。

**外观皮肤（成品）**：选机型 → 看效果图 → 确认材质/工艺/工期 → 下单。

**外观皮肤（定制）**：选机型 → 看模板/灵感 → 提交设计需求 → 运营报价 → 定制单。**不显示固定价，只显示“起价/需报价”。**

**找不到**：任意空态 → 提交寻源（机型、品类、预算、期望时间）→ 运营跟进。寻源是配件供给稀薄期最重要的转化兜底。

---

## 5. 数据模型

### 5.1 分类树（Bagisto 原生，直接建子分类）

```
配件（accessory）
├── 外观皮肤        accessory-skin         → code: skin
├── 防护结构        accessory-armor        → code: armor
├── 动力续航        accessory-power        → code: power
├── 感知导航        accessory-sensor       → code: sensor
├── 执行末端        accessory-end-effector → code: end-effector
└── 工具耗材        accessory-tools        → code: tools
```

- 用 Bagisto category 原生 `parent_id`，不新建分类表；`accessory:install-taxonomy` 幂等安装（默认 dry-run）。
- 前端分类 chips 读 `/api/accessory-catalog/filters`，不直接拼分类树。
- 商品**同时保留**根分类与子分类关系：根关系保证旧接口兼容，子分类用于筛选。
- 历史 4 个商品按名称归类；识别不出的保持只挂根分类，由运营手动补。

### 5.2 配件档案表（`accessory_profiles`，已落地）

**为什么不用 Bagisto 属性族**：配件字段是配件域语义，塞进全局属性族会让所有商品多出一组无关字段，
还要处理属性组/属性族/后台表单渲染；本项目的技能域、赛事域也都用独立表。独立表更清楚、更可控。

| 字段 | 类型 | 用途 | 上架必填 |
|---|---|---|---|
| `summary` | string(255) | 一句话摘要 | 否 |
| `install_method` | enum：`self` / `engineer` / `store` | 安装方式 | 是 |
| `package_contents` | text | 包装清单 | 是 |
| `warranty_months` | smallint | 质保月数 | 是 |
| `lead_time_days` | smallint | 发货/交付时效 | 是 |
| `material` | string(64) | 材质/工艺（皮肤尤其重要） | 皮肤必填 |
| `official` | boolean | 官方自营 | 否（默认否） |
| `custom_service` | boolean | 是否支持定制 | 否 |
| `custom_price_from` | decimal | 定制起价 | 定制件必填 |
| `compatibility_note` | text | 通用适配限制说明 | 无具体适配关系时必填 |
| `power_requirement` | string(128) | 供电/接口要求 | 否 |

复用商品自身的 `weight/length/width/height/color/size` 与 Bagisto `custom_attributes`，不重复造。

### 5.3 适配表（核心新增）

不把适配塞进商品属性，也不依赖 related products。新增轻量表：

```sql
CREATE TABLE accessory_fitments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,          -- → products.id
    model_code      VARCHAR(64) NOT NULL,           -- → device_model.model_code
    fit_level       VARCHAR(16) NOT NULL,          -- direct/adapter/custom/unsupported
    adapter_sku     VARCHAR(64) NULL,               -- 需要转接件时，转接件商品 sku
    note            VARCHAR(255) NULL,              -- “需拆原厂外壳”“仅限 EDU 版”
    source          VARCHAR(16) NOT NULL DEFAULT 'platform', -- vendor/platform/measured
    verified_at     DATETIME NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    UNIQUE KEY uk_product_model (product_id, model_code),
    KEY idx_model_level (model_code, fit_level),
    KEY idx_product (product_id)
);
```

设计理由：
- `device_model` 已是平台机型唯一真相源，技能侧也用它；配件必须接同一条线。
- 适配是**商品 × 机型**的二维关系，不是商品自己的属性。
- `fit_level` 让前端能诚实分级，而不是一句“适配”。
- `adapter_sku` 让“需转接”可落到具体商品，未来可组合下单。
- `source/verified_at` 让“厂商声明”与“平台实测”可区分，避免把声明写成实测。

**适配等级文案（对外统一）**

| 值 | 文案 | 承诺 | 卡片样式 |
|---|---|---|---|
| `direct` | 直装 | 原接口安装，无需改件 | 绿色/蓝色小标 |
| `adapter` | 需转接 | 需另购转接件/支架 | 琥珀色小标 + 转接件链接 |
| `custom` | 需定制 | 需测绘/改件，先报价 | 紫色小标 + “获取报价” |
| `unsupported` | 不适配 | 明确不卖 | 红色提示，弱化 CTA |

> 与平台已有的 L1/L2/L3 兼容性纪律同构：**只承诺证据支持的等级**，不把“需转接/需定制”说成“直装”。

### 5.4 与现有 `product_relations` 的关系

- `product_relations` 继续表达“相关商品/搭配推荐”。
- `accessory_fitments` 专门表达“适配机型”。
- 迁移期：详情页优先读 `accessory_fitments`；为空时回退现有 related products，并显示“适配信息待补充”，**不冒充完整适配**。
- 后台把现有 5 条 relation 迁成 fitment 时，`fit_level` 默认 `direct` 需人工确认，不能自动认定。

### 5.5 上架前置校验（数据质量门）

新增命令 `php artisan accessory:preflight`（只读，参考 `ecosystem:data-preflight`）：

- 配件商品必须有：≥1 张真实图、价格 > 0、子分类、`install_method`、`warranty_months`、`lead_time_days`、`package_contents`。
- 必须有 ≥1 条 `accessory_fitments`，或显式标记 `compatibility_note` 说明为通用件。
- 皮肤类必须有 `material`；定制类必须有 `custom_price_from`。
- 输出缺失清单与商品 id，不自动改数据。
- 上架审核/发布流程可调用，作为“可发布”的硬门槛。

---

## 6. 接口设计

### 6.1 新增专用目录接口（推荐）

不要让前端继续用 `/api/products?category_id=` 拼装。模仿技能目录（`/api/skill-catalog/*`）的做法，新增：

```
GET /api/accessory-catalog/filters
→ {
    categories: [{code,name,count}],
    models: [{model_code, model_name, brand, status}],   // 来自 device_model
    fit_levels: [{value,label}]
  }

GET /api/accessory-catalog/search
  ?q=&category=&model=&fit=&sort=&page=1&limit=12
→ {
    data: [AccessoryCard],
    pagination: {page, per_page, has_more},
    filters: {...},
    compatibility_basis: "declared_fitment"
  }

GET /api/accessory-catalog/{id}
→ { data: AccessoryDetail }
```

**AccessoryCard**

```json
{
  "id": 17,
  "name": "机器人备用电池包 48V/20Ah",
  "sku": "TEST-ACC-BATTERY",
  "price": 899,
  "compare_at_price": null,
  "image": "https://.../battery.webp",
  "category": {"code": "power", "name": "动力续航"},
  "fitment": {
    "level": "direct",
    "label": "直装",
    "models": ["unitree_go2", "unitree_g1"],
    "note": null
  },
  "badges": ["官方推荐"],
  "install": {"method": "self", "label": "自助安装"},
  "delivery": {"label": "3 天内发货"},
  "official": false,
  "custom_service": false
}
```

**AccessoryDetail** 在卡片字段基础上增加：

```json
{
  "images": ["..."],
  "summary": "...",
  "description_html": "...",
  "fitments": [
    {"model_code":"unitree_go2","model_name":"宇树 Go2","fit_level":"direct","label":"直装","note":null,"adapter_sku":null,"source":"platform"}
  ],
  "specs": [{"name":"材质","value":"碳纤维"}],
  "package_contents": ["电池包 ×1","说明书 ×1"],
  "warranty": {"months": 12, "label": "12 个月质保"},
  "lead_time": {"days": 3, "label": "3 天内发货"},
  "install": {"method": "self", "label": "自助安装", "service_available": false},
  "related": {"machines": [], "accessories": [], "skills": []},
  "orderable": true
}
```

关键约束：
- `orderable=false` 时前端禁用下单（mock/下架/无价/无库存）。
- `compare_at_price` 只在真实 `regular > final` 时出现。
- `fitment` 只返回 `direct/adapter/custom`；`unsupported` 不进列表，只在详情页“不适配”说明中出现。
- 所有图片必须是可访问的真实图；无图返回 `null`，前端回退图标。

### 6.2 兼容旧接口的过渡

- 首页与机器页先切到新接口；`/api/tob-catalog` 的 `accessory` 段保留一版，避免其他入口白屏。
- 5 分钟 TTL 的模块级缓存保留，但按 `q/category/model/page` 分键，不能再用单一 `_accessoryLoadedAt`。

### 6.3 下单接口收紧

`TobOrderController` 增加校验（P0 必做）：

- `type=accessory` 时，`product_id` 必须存在、已上架、属于 `accessory` 分类。
- 若请求带 `model_code`，必须存在 `accessory_fitments` 且不是 `unsupported`。
- 价格：服务端按商品目录价重算，前端传入价只作展示/校验，不接受明显偏离。
- 禁止 mock/`is_demo` 商品进入下单。

---

## 7. UI 规格要点

### 7.1 卡片解剖

```
┌──────────────┐
│   真实图 1:1  │  ← 无图回退分类图标；过滤 placeholder
├──────────────┤
│ 名称（2 行截断）│
│ [直装] 适配 Go2│  ← 适配等级 + 1 个代表机型
│ ¥899  ¥1,078  │  ← 原价仅有真实折扣时显示
│ 3 天发货      │  ← 交期（可选）
└──────────────┘
```

- 首页 2.5 卡横滑；机器页 2 列。
- 文字最小 22rpx；价格统一 `#FF7820`；适配标用语义色，不抢价格。
- 卡片 `role="button"` + `tabindex="0"` + `@keyup.enter`，与赛事卡对齐。
- 375/390px 无横向溢出；名称必须截断，不能撑破卡片。

### 7.2 状态

| 状态 | 文案 | 动作 |
|---|---|---|
| 加载中 | 正在加载配件… | 骨架卡 |
| 空（无商品） | 配件正在上架 | 提交寻源需求 |
| 空（筛选无结果） | 没有符合条件的配件 | 清空筛选 / 提交寻源 |
| 错误 | 配件加载失败 | 重试（作废缓存） |
| 不可售 | 该配件暂不可在线购买 | 联系客服 / 寻源 |
| 定制件 | 定制需报价 | 获取定制报价 |

### 7.3 详情页信息顺序

价格 → 名称 → 适配 → 安装 → 参数 → 包装/质保/交期 → 详情富文本 → 搭配 → 底部 CTA。

「适配」必须在「产品介绍」之前，因为它是配件的第一决策因素。

### 7.4 文案纪律

- 「官方正品」只用于 `official=true` 且确有授权的商品；第三方用「平台严选」或「供应商质保」，并写清责任主体。
- 不写“全品牌通用”“开箱即用”这类无法证明的承诺。
- 定制皮肤不展示固定总价，只展示起价与报价流程。
- 没库存/没交期就不写“现货”。

---

## 8. 后台与运营

### 8.1 商品录入

在 Bagisto 商品编辑页，配件类目下增加：

- **分类**：必须选到二级子分类。
- **配件信息**：安装方式、包装清单、质保、交期、材质、官方/定制标记。
- **适配机型**：多选 `device_model` + 每行适配等级 + 转接件 + 备注。
- **图片**：至少 1 张真实图；皮肤需要效果图。
- **参数**：复用 custom attributes。

### 8.2 运营工作流

1. 新建商品 → 选 `配件` 子分类。
2. 填配件信息与适配。
3. 上传真实图。
4. 跑 `accessory:preflight`，补齐缺失项。
5. 设 `featured`（首页精选）。
6. 上架。

### 8.3 内容治理

- 现有真实商品 `3D视觉相机` 的短描述残留 `<p>1</p>`、meta_title 为 `1`，需运营清理。
- 3 个 TEST 商品要么补齐真实信息转正，要么下架，不能长期以测试数据面对用户。
- `official`、`featured` 由运营维护，前台只读。

---

## 9. 分阶段交付

### P0 · 修真货与诚实（3–5 人日）

**目标**：页面上不再出现“假货可下单、假原价、死筛选”。

| 任务 | 文件 | 验收 |
|---|---|---|
| P0-1 mock 隔离：`is_demo` 标记，详情/下单禁用；或移除可售 mock | `EcoCenter.vue`、`peijian.vue`、`robot/index.vue` | mock 商品无法进入 checkout；无 mock 订单 |
| P0-2 真货筛选生效：有真实分类/机型字段时才展示并执行筛选；没有数据就隐藏，不展示假选项 | `peijian.vue` | 选分类后结果变化正确；无分类数据时不出现假品牌/机型下拉 |
| P0-3 去掉编造原价：仅真实折扣展示 | `robot/index.vue`、`EcoCenter.vue` | 无 special_price 的商品不显示删除线 |
| P0-4 过滤占位图，回退图标 | `robot/index.vue`、`EcoCenter.vue` | 无图商品显示分类图标，不显示灰占位 |
| P0-5 服务文案分档：官方/严选/第三方 | `robot/index.vue` | 非 official 商品不再写“官方正品” |
| P0-6 空态与错误态：无货不补 mock | 两个入口 | 清空配件分类后显示空态 + 寻源 CTA |
| P0-7 详情补四块：适配（含来源）、安装、质保、交期 | `robot/index.vue` | 4 个 TEST 商品至少显示“信息待补充”，不空白 |
| P0-8 下单校验：type=accessory 必须属于 accessory 分类且已上架 | `TobOrderController.php` | 传 rent 商品 id + type=accessory 返回 422 |
| P0-9 卡片可访问性与截断 | 两个入口 | Tab 可聚焦；375px 无溢出 |

**P0 不做**：新表、新接口、首页大改版。

### P1 · 适配与真目录（7–10 人日）

| 任务 | 交付 | 状态（2026-10-24） |
|---|---|---|
| P1-1 分类树 | 6 个配件子分类 + 历史商品归类（`accessory:install-taxonomy`，默认 dry-run） | 代码完成；**待 MySQL 启动后执行** |
| P1-2 配件档案 | 独立 `accessory_profiles` 表 + 后台录入（不塞进 Bagisto 属性族） | 完成 |
| P1-3 `accessory_fitments` 表与模型 | 迁移、模型、后台维护 UI | 完成；**迁移待执行** |
| P1-4 目录 API | `filters/search/{id}` 三接口 + 适配/档案/图片 DTO | 完成；**接口待联调** |
| P1-5 首页改版 | 机型选择 + 分类 chips + 真实卡片 + 空态 | 完成 |
| P1-6 机器页改版 | 子分类侧栏 + 真实机型筛选 + 搜索 + 排序 + 分页 | 完成 |
| P1-7 详情升级 | 适配矩阵、安装、包装、质保、交期、定制报价入口 | 完成 |
| P1-8 上架门禁 | `accessory:preflight`（只读，`--strict` 可做门禁） | 完成 |
| P1-9 运营数据 | 把 3 个 TEST 商品补齐档案/适配，或下架 | **未做，需运营/DB** |

**P1 验收**：
- 选「宇树 Go2」只出现适配 Go2 的配件；「需转接」显示转接件与说明。
- 详情页每个适配结论都有等级与来源。
- 后台能独立维护适配，不需要改代码。
- 空目录/筛选空/接口失败三态正确。
- 375/390px 浏览器 e2e 通过。

### P2 · 生态联动（按需，2–4 周）

- P2-1 机器详情「适配配件」横滑。
- P2-2 配件清单/组合下单（复用 `items[]` 多行下单）。
- P2-3 皮肤定制报价流（设计需求 → 报价 → 定制单）。
- P2-4 寻源需求与运营台。
- P2-5 指标：曝光/点击/筛选/下单/寻源/零结果率/配件连带率。
- P2-6 技能详情标注“所需配件”。

---

## 10. 指标

| 指标 | 定义 | 用途 |
|---|---|---|
| 配件详情到达率 | 详情 UV / 配件 tab UV | 列表是否吸引人 |
| 筛选使用率 | 使用机型/分类筛选的会话占比 | 适配入口是否有价值 |
| 零结果率 | 搜索/筛选无结果的请求占比 | 供给缺口 |
| 寻源提交率 | 寻源提交 / 空态曝光 | 兜底转化 |
| 配件下单转化 | 配件订单 / 详情 UV | 商品页质量 |
| 连带率 | 含配件的整机/场景订单占比 | 生态联动效果 |
| 适配纠纷率 | 因“装不上”产生的售后 / 配件订单 | 适配数据质量 |

> 指标口径要与事实同页，没有数据源就返回 `unavailable`，不估算。

---

## 11. 测试与验收

沿用项目既有门禁：

```bash
# 后端
cd D:/main/my-bagisto-store
/d/php.exe artisan accessory:preflight
DB_DATABASE=summon_mecha_step10_test APP_ENV=testing /d/php.exe artisan test packages/Webkul/Shop/tests/Feature/Accessory

# 前端
cd D:/main
node --check components/jiqi/peijian.vue   # 仅语法思路；实际用构建
node --test tools/tests/accessory-catalog.test.mjs

# 浏览器
node qa/ecosystem/e2e/run.mjs   # 增加配件 tab 用例
```

新增用例至少覆盖：

1. 无配件商品 → 两个入口空态，无 mock。
2. 有真实商品 → 卡片图/价/适配标正确，原价不造假。
3. 选机型 → 只出现适配商品；`unsupported` 不出现。
4. 详情 → 适配等级/来源/安装/质保/交期齐全。
5. 下单 → mock 被拒；rent 商品 id + accessory 类型被拒；正常配件成功。
6. 375/390px 无横向溢出；键盘可达。

---

## 12. 风险与非目标

### 风险

| 风险 | 应对 |
|---|---|
| 真实配件只有 4 个，改版后页面很空 | 空态 + 寻源 + 精选少而真；不要用假货填 |
| 适配数据维护成本高 | 只对在售机型维护；通用件用 `compatibility_note` 显式说明 |
| 运营不愿填完整档案 | 字段分“上架必填”和“选填”；preflight 只卡必填 |
| 第三方配件责任边界 | `official` 分档 + 供应商质保文案；不冒充官方 |
| 定制皮肤无标准价 | 只展示起价，走报价流，不直接下单 |
| 旧入口依赖 `/api/tob-catalog` | 新旧接口并行一版，前端灰度切换 |

### 非目标（现在不做）

- 不做全品类配件商城。
- 不做实时库存/多仓履约。
- 不做复杂 BOM 配置器与自动报价。
- 不做第三方商家入驻/分账。
- 不把“皮肤”做成通用贴纸商城；皮肤必须与机型绑定。

---

## 13. 已拍板决策（2026-10-24）

1. **命名保留「配件皮肤」**。不改名为“改装”，对外保持现有合规口径；用二级分类区分功能配件、成品皮肤、定制皮肤。
2. **皮肤走成品 + 定制双轨**。成品皮肤按固定价下单；定制皮肤只展示起价，走「提交需求 → 运营报价 → 定制单」，不直接下单。
3. **适配直接建 `accessory_fitments` 表**，以 `device_model.model_code` 为机型真相源；不先用 related products 过渡。related products 只保留“搭配推荐”语义。

---

## 14. 一句话总结

**把「配件皮肤」从“15 个假商品的九宫格”改成“先选机器、再看适配、能买也能寻源、装不上有人管”的配件与安装服务。** 先修诚信（P0），再建适配（P1），最后做生态联动（P2）。

---

## 15. P1 落地清单（2026-10-24）

### 新增文件

| 文件 | 作用 |
|---|---|
| `my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_24_000001_create_accessory_profile_and_fitment_tables.php` | `accessory_profiles` + `accessory_fitments` |
| `.../Shop/src/Models/AccessoryProfile.php` | 配件档案模型（安装方式/质保/交期/官方/定制） |
| `.../Shop/src/Models/AccessoryFitment.php` | 适配模型（直装/需转接/需定制/不适配 + 来源） |
| `.../Shop/src/Http/Controllers/API/AccessoryCatalogController.php` | `filters/search/{id}` 公开目录接口 |
| `.../Shop/src/Console/Commands/AccessoryInstallTaxonomy.php` | 幂等安装 6 个子分类并归类现有商品（默认 dry-run） |
| `.../Shop/src/Console/Commands/AccessoryDataPreflight.php` | 只读上架检查（`--strict` 可做门禁） |
| `.../Admin/src/Http/Controllers/Catalog/AccessoryController.php` | 后台保存档案与适配 |
| `.../Admin/src/Resources/views/catalog/products/edit/accessory-profile.blade.php` | 商品编辑页的「配件档案与适配」面板 |
| `utils/product-catalog.js` + `tools/tests/product-catalog.test.mjs` | P0 价格/图片纯函数与测试 |

### 执行顺序

```bash
cd D:/main/my-bagisto-store

# 1. 建表（additive）
php artisan migrate --force

# 2. 先看分类安装会做什么
php artisan accessory:install-taxonomy --json=D:/main/docs/accessory-taxonomy-dryrun.json

# 3. 确认后写入
php artisan accessory:install-taxonomy --apply --json=D:/main/docs/accessory-taxonomy-result.json

# 4. 只读检查现有配件缺什么
php artisan accessory:preflight --json=D:/main/docs/accessory-preflight.json
```

### 接口

```
GET /api/accessory-catalog/filters
GET /api/accessory-catalog/search?q=&category=&model=&fit=&sort=&page=&limit=
GET /api/accessory-catalog/{id}
```

### 本机验证记录（2026-10-24）

MySQL 启动后已实际执行并验证：

- `php artisan migrate --force`：迁移成功（两张新表）。
- `php artisan accessory:install-taxonomy`：dry-run 通过；`--apply` 创建 6 个子分类（id 6–11）并把商品 5/17/18/19 归入 sensor/power/power/end-effector。
- `php artisan accessory:install-taxonomy` 再次执行：全部 `exists` / `already_classified`，幂等。
- `GET /api/accessory-catalog/filters`：200，返回 6 个子分类、18 个机型、3 个适配等级；分类计数正确。
- `GET /api/accessory-catalog/search`：200；`category` / `model` / `fit` / `q` / `sort` / 分页均实测。
- `GET /api/accessory-catalog/{id}`：200；非配件商品返回 404；适配矩阵、档案、specs 正常。
- 发现并修复：`cost` 成本字段曾被 `custom_attributes` 透出到公开详情（已加入 skip 列表）；短描述占位 `<p>1</p>` 被当摘要（已过滤纯数字摘要）；`ProductResource` 的 `/cache/{size}` 详情图在部分部署下 404，已改为用 `product_images` 原始路径（`/storage/...`）。
- `php artisan accessory:preflight`：正确报出 5 条缺失（3 个 TEST 商品无图、3D 相机无档案/适配）；`--strict` 返回 1。
- `php artisan view:cache`：Blade 全部编译通过。
- `php artisan route:list --path=accessory` / `--path=accessories`：公开与后台路由均已注册。
- `GET /api/tob-catalog`：旧入口仍返回 4 个配件，未被破坏。
- 演示数据：`database/seeders/AccessoryDemoSeeder.php` 仅对 `TEST-ACC-*` 写入档案与适配，备注明确“演示数据，待运营核实”；已在本机执行。真实 3D 相机未被编造档案，`accessory:preflight` 仍会提示它缺档案/适配。
- 未跑：浏览器 e2e（HBuilderX CLI 登录过期）、PHPUnit（独立测试库当前缺少分类种子）。这两项不能算完成。

### 回滚

- 迁移 `down()` 会删除两张新表（**会丢档案/适配数据**，先导出）。
- 分类安装只创建、不删除；如需回滚，按生成的 JSON 记录手动删除新建分类与 pivot（先备份 `categories` / `category_translations` / `product_categories`）。
- 后台面板与接口均为新增文件/路由；回滚时删除对应文件并从 `catalog-routes.php` / `api.php` / `ShopServiceProvider.php` 移除注册。
