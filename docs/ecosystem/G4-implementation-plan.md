# G4 分发与运营 · 实施计划（2026-10-20）

> 范围：[Ecosystem-Engineering-Backlog.md](./Ecosystem-Engineering-Backlog.md) §6 `G4-01 ~ G4-09`。
> 依赖：G1 可信发布、G2 免费报名、G3 人工赛务已完成（代码与独立测试库验证）。
> 本文只定义**本批怎么做、边界在哪、出错怎么退**；实现结果与实测证据另见
> [G4-evidence.md](./G4-evidence.md)，上线值守见 [G4-runbook.md](./G4-runbook.md)。

## 1. 结论先行

G4 不是「再补几个页面」，而是把已经存在的公开事实**分发出去**：可检索、可收藏、可关联、
可被搜索引擎理解、可被运营看见并处置。所有能力都必须建立在同一条可见性真相链上：

```text
publish_status=published
  + disposition=normal
  + published_revision_id → 同对象 review_state=approved
  + 富文本 sanitizer_version 与当前版本一致
  + 公开读取 kill switch 未打开
```

**任何 G4 新入口都不得拥有自己的可见性判断**。搜索、收藏、关联、SSR、sitemap、指标、
运营台全部复用 `PublicEventQuery` / `PublicContentQuery` / `PublicReadGate`，
不允许复制一份 `where publish_status='published'`。

本批默认**全部新开关关闭**，代码、测试、迁移先落地；只有在独立测试库与 root 备份演练
通过后才逐项打开。收藏是唯一新增的用户写入能力，也走独立开关 + 登录 + 幂等约束。

## 2. 子批次拆分与依赖

| 子批 | 工作包 | 交付 | 为什么这样拆 |
|---|---|---|---|
| A | G4-01 | 关键词/城市/日期/报名状态/内容分类筛选；LIKE 转义；游标分页；筛选回放 | 纯读能力，先做不引入写风险；游标分页是后三批的共同底座 |
| B | G4-02、G4-03 | 收藏表/服务/API/我的收藏；活动与内容关联表/解析服务/降级/Admin 维护 | 两个都需要「目标是否仍公开」的统一解析器，放在一起避免两套判断 |
| C | G4-04、G4-05 | Laravel SSR、canonical/OG/JSON-LD、sitemap、H5 端 SEO/分享一致性 | 复用 A 的查询与 B 的关联；SSR 与 H5 必须同源，不能各拼一份 |
| D | G4-06、G4-07 | 指标口径与事实、内容访问日聚合、运营工作台与处置入口 | 指标依赖 A/B/C 产生的真实事实；运营台是这些事实的出口 |
| E | G4-08、G4-09 | 安全/权限/文件/队列/恢复演练；开关矩阵、回退、值守交接、root 迁移 | 验收与上线必须最后做，且以真实命令输出为准 |

依赖顺序：A → B → C → D → E。A 的游标元数据会被 C 的 SSR 列表复用；B 的关联解析会被
C 的详情页复用；D 的指标不得反向成为 A/B/C 的运行时依赖。

## 3. 统一公开可见性合同

### 3.1 查询层

- `PublicEventQuery` / `PublicContentQuery` 新增只读方法，不改变既有 `paginate()` 语义：
  - `searchableBase()`（内部）仍使用原有 publicationBase；
  - `findPublishedById` / `findPublishedBySlug` 供收藏与关联复用；
  - `cursorPaginate()` 与 `paginate()` 共用 `applyFilters()`。
- 新增筛选参数（全部白名单校验）：

| 域 | 参数 | 语义 |
|---|---|---|
| 活动 | `q` | 标题/摘要/城市/地点关键词；trim 后 1–50 字符；`%` `_` `\` 转义 |
| 活动 | `city` | 精确匹配 revision 城市快照 |
| 活动 | `kind` | 来自 `config('ecosystem_publishing.event_kinds')` |
| 活动 | `participation_mode` | offline/online/hybrid |
| 活动 | `hosting_status` | 来自 `config('ecosystem_publishing.event_hosting_statuses')` |
| 活动 | `starts_from` / `starts_to` | ISO 日期，UTC 比较 |
| 活动 | `registration_state` | `open` / `upcoming` / `full` / `closed` / `ended` / `canceled` / `external` / `unavailable` |
| 资讯 | `q` | 标题/摘要关键词，规则同上 |
| 资讯 | `type` / `category` | 类型白名单；分类最长 64 |
| 资讯 | `published_from` / `published_to` | 首次公开时间 |

`registration_state` 的定义（以 `event_registration_scopes.default` 为事实源）：

- `open`：平台双闸开启 + scope.status=open + 当前时间在窗口内 + 容量未满（`capacity IS NULL`
  或 `capacity > 0 AND confirmed_count < capacity`）+ 活动未取消/未结束；
- `upcoming`：scope 已开放但 `registration_opens_at > now`；
- `full`：scope 开放且 `capacity > 0 AND confirmed_count >= capacity`；
- `closed`：scope 存在但不满足 open/upcoming（含 `capacity=0`、窗口已过、scope 非 open）；
- `ended`：`hosting_status=completed` 或 `ends_at < now`；
- `canceled`：`hosting_status=canceled`；
- `external`：revision 有合法的 http(s) `external_registration_url`；
- `unavailable`：无 scope 且无外链，且不属于 ended/canceled。

状态过滤只影响列表，不改变详情 DTO 的 `registration.can_register`（后者仍由
`PublicRegistrationState` 逐活动计算）。

### 3.2 分页

保留 `page` 模式（兼容旧客户端），新增 `mode=cursor`：

```json
{
  "data": [...],
  "meta": {
    "mode": "cursor",
    "per_page": 20,
    "has_more": true,
    "next_cursor": "eyJ2IjoxLCJ0Ijoi...",
    "total": null,
    "filters": { "q": "机甲", "kind": "competition" }
  }
}
```

- 游标内容：`{v, t, id, f}`，`t` 为排序时间（UTC `Y-m-d H:i:s`），`id` 为决胜键，
  `f` 为规范化筛选条件的 sha256 前 16 位。
- 服务端校验：base64url、JSON 结构、时间格式、id>0、`f` 与当前筛选一致；不一致返回
  422 `VALIDATION_ERROR`（与既有 API 校验合同一致），不静默从头开始。
- 查询条件：`(t < :t) OR (t = :t AND id < :id)`；每页取 `per_page + 1` 判断 `has_more`。
- `page` 模式增加上限 `max_page=200`，防止深偏移拖库。
- 两种模式互斥：同时给 `page` 与 `cursor` → 422。

### 3.3 LIKE 转义

- `q` 先 `trim`，用 `mb_strlen` 限制 1–50；空串视为未传。
- `addcslashes($q, '\\%_')` 后拼 `%...%`，走参数绑定。
- 测试必须覆盖 `%`、`_`、`\`、中文、超长、纯空白；断言不会把 `%` 当通配符。

## 4. G4-02 收藏

### 4.1 数据模型

`ecosystem_favorites`

| 列 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| customer_id | bigint | FK `customers.id`，删除用户级联 |
| target_type | varchar(20) | `event` / `content` |
| target_key | varchar(191) | 活动=ID 字符串；资讯=slug（发布后不可变） |
| created_at / updated_at | timestamp | |

- 唯一键 `uk_ecosystem_favorites_customer_target (customer_id,target_type,target_key)`；
- 索引 `(target_type,target_key)` 供失效目标清理/统计；
- 不建目标外键：目标撤稿后关系保留，但**读取时过滤**；删除目标不得级联删除用户收藏。

### 4.2 写入与读取

- `FavoriteService::add()`：开关开启 + 登录 + 目标当前公开才允许；`firstOrCreate` 幂等；
  单用户上限 500；返回 `{favorited:true, created:bool}`。
- `FavoriteService::remove()`：幂等；目标已撤稿也允许删除。
- `GET /api/customer/favorites?type=&mode=cursor`：只返回当前仍公开的目标 DTO；
  已失效目标只计入 `meta.stale_count`，**不返回旧标题/摘要/URL**。
- 公开详情 DTO 增加 `favorited`（未登录为 false）；列表 DTO 增加 `favorited`
  （批量查询，避免 N+1）。
- 收藏是私有状态：响应头 `Cache-Control: private, no-store`；不得进入 SSR/OG/sitemap。

## 5. G4-03 关联

### 5.1 数据模型

`ecosystem_relations`

| 列 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| source_type | varchar(20) | `event` / `content` |
| source_key | varchar(191) | 活动/资讯内部 ID 字符串 |
| target_type | varchar(20) | `event` / `content` / `device_model` / `skill` / `scene` |
| target_key | varchar(191) | 活动 ID / 资讯 slug / 机型 ID / 商品 ID / 场景 ID |
| sort_order | smallint unsigned | 默认 0 |
| status | varchar(20) | `active` / `disabled` |
| created_by | bigint nullable | 后台操作人 |
| created_at / updated_at | timestamp | |

- 唯一键 `(source_type,source_key,target_type,target_key)`；
- 索引 `(source_type,source_key,status,sort_order)`、`(target_type,target_key)`；
- 多态目标不建外键；**目标是否存在、是否公开由解析服务实时判断**。

### 5.2 解析与降级

`EcosystemRelationService::forSource()` 按 `sort_order,id` 解析，逐项校验：

| 目标 | 可展示条件 | 输出 |
|---|---|---|
| event | `PublicEventQuery::findPublished` | id/name/时间/城市 |
| content | `PublicContentQuery::findPublishedBySlug` | slug/title/摘要 |
| device_model | `device_model.status='active'` | id/model_name/brand；仅展示，不承诺可售 |
| skill | 商品存在 + 有 `skill_versions.status='published'` | product_id/name |
| scene | `scenes.status=1` | id/name/icon |

- 目标失效、下架、撤稿：**整项省略**，不输出占位 CTA、不输出旧标题；
- 单源最多 12 项，超出截断并记日志；
- 关联不修改设备/商品真相源，也不改变 `can_register` / 购买状态；
- 详情 DTO 增加 `related`，列表不返回，控制响应体大小。

### 5.3 Admin 维护

- 活动/资讯工作页增加「关联内容」区块：新增/停用/删除关联；
- 路由逐条登记 `config/ecosystem_admin.php`，分别使用
  `ecosystem.events.edit` / `ecosystem.content.edit`；
- 只允许选择真实存在的目标；不提供自由文本 URL，避免把关联变成软广外链。

## 6. G4-04 / G4-05 SSR、canonical、OG、sitemap

### 6.1 路由与服务

在 Shop web 路由新增（与 uni-app 同 Laravel 服务）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/events` | 服务端渲染活动列表，筛选/分页与 API 同源 |
| GET | `/events/{id}` | 活动详情；404/410 中性页 |
| GET | `/content` | 资讯列表 |
| GET | `/content/{slug}` | 资讯详情 |
| GET | `/sitemap.xml` | 仅已发布、normal 的活动/资讯 |

- 统一由 `PublicReadGate` 先判断：kill switch → 503；
- `ecosystem_g4.ssr.enabled=false` 时上述页面 404（不渲染空壳）；
- sitemap 另有 `ecosystem_g4.sitemap.enabled`，默认 false；
- 不引入第三方 SEO 包；canonical/OG/JSON-LD 在 Blade 里显式输出。

### 6.2 元数据规则

- canonical：`base_url + /events/{id}` 或 `/content/{slug}`；筛选列表 canonical 指向
  无参数列表页，并输出 `noindex,follow`；
- OG：`og:type`（event→article）、`og:title`、`og:description`（截断 200 字）、
  `og:url`、`og:image`（封面绝对 URL，无封面则省略）、`og:site_name`；
- JSON-LD：
  - 活动：`@type=Event`，name/startDate/endDate/eventAttendanceMode/eventStatus/
    location/organizer；无可靠字段则省略对应属性；
  - 资讯：`@type=Article`，headline/datePublished/dateModified/author/
    publisher/image；
- 撤稿：410 页面只输出中性文案与 noindex，不输出旧标题、摘要、来源、图片；
- 草稿预览：沿用 Admin 预览路由，响应加 `X-Robots-Tag: noindex, nofollow` 且
  页面输出 `<meta name="robots" content="noindex,nofollow">`；
- 详情页正文只输出服务端已净化 HTML；媒体 URL 仍走 `/api/...` 受控路由。

### 6.3 sitemap

- 只包含当前公开的活动/资讯；`lastmod` 取 `published_at`；
- 单次上限 5000 条（配置），超限记 error 并截断，不静默造假；
- 不包含：草稿、待审、暂缓、撤稿、筛选页、收藏、Admin 预览；
- 响应 `application/xml; charset=UTF-8`，可被 kill switch 立即停掉。

### 6.4 H5 端一致性

- 新增 `utils/ecosystem-seo.js`（纯函数，可 Node 测试）：
  `buildEcosystemSeo({type, entity, baseUrl, siteName})` 返回
  `{title, description, canonical, og, jsonLd, robots}`；
- 详情页在 H5 条件编译块内写入 `document.title` 与 meta/link/script；
- canonical 指向 Laravel SSR 地址（`SSR_BASE_URL`），不是 H5 hash 地址；
- 小程序端不执行 DOM 操作，仍用原生分享；分享路径只含公开标识，不含用户 token/收藏状态；
- 切账号：详情页重新拉取 `favorited`，本地不缓存私有状态。

## 7. G4-06 指标

### 7.1 口径（先定义，后计算）

| 指标 | 事实源 | 口径 |
|---|---|---|
| 报名提交数 | `event_registration_attempts.submitted_at` | 期间内每次 attempt 计 1；request 重放不重复计 |
| 确认席位数 | `event_registration_scopes.confirmed_count` + attempts | 当前 confirmed/checked_in 的 attempt 数；与 scope 计对账 |
| 签到数 | `attempts.checked_in_at` | 期间内首次签到；重复扫描不重复计 |
| 取消数 | `attempts.canceled_at` | 期间内取消；重报生成新 attempt，旧取消不重复计 |
| 重报数 | `attempts.attempt_no >= 2` | 按新 attempt 的 submitted_at |
| 资讯访问 | `ecosystem_content_view_daily` | 日聚合；同一访客 1 小时去重；不存 IP/UA 原文 |
| 作品提交 | `competition_submission_versions.submitted_at` | 期间内提交版本数；同一 submission 多版本分别计 |
| 作品转化 | `competition_showcases` | 已授权 + 已发布展示数；`developer_handoff_at` 非空计为进入提审 |
| 内容发布 | `content_posts.published_at` | 期间内首次公开文章数 |

- 没有事实源的能力返回 `available=false`，**不返回 0 冒充真实**；
- 人数与队伍数分开：报名按 attempt/unit，赛务按 entry，绝不混算；
- 所有查询按平台时区边界转 UTC；期间上限 366 天；
- 指标页只对 `ecosystem.metrics.view` 开放，不输出联系人/手机号。

### 7.2 访问记录

- 表 `ecosystem_content_view_daily(view_date,target_type,target_key,views)`，唯一键
  `(view_date,target_type,target_key)`；
- 仅记录 `event`/`content` 详情成功渲染（SSR 或 API 200），不记录 404/410/503；
- 访客去重键：登录用户 `c:{id}`；游客 `HMAC(app.key, ip|ua|date)` 截断，**不落库**，
  仅用于 cache 去重；cache TTL 1 小时；
- 开关 `ecosystem_g4.metrics.content_views_enabled` 默认 false；关闭时指标页明确
  `content_views.available=false`。

## 8. G4-07 运营工作台

`GET /admin/ecosystem-ops`（权限 `ecosystem.ops.view`），分区展示：

| 分区 | 事实 | 动作 |
|---|---|---|
| 待审 revision | `event_revisions`/`content_revisions` review_state=in_review | 链接到发布工作页 |
| 待审报名 | attempts status=submitted（manual scope） | 链接到报名工作台；无 PII 权限只显示遮罩 |
| 即将截止 | scope open 且 closes_at ≤ now+48h | 链接到 scope 设置 |
| 通知失败 | outbox status=failed/dead 或 attempts 超限 | 只读事实 + 重排按钮（权限 `ecosystem.ops.retry`） |
| 来源复核 | revision/source_verified_at 超过 180 天 | 链接到发布工作页 |
| 更正/撤稿 | 近 30 天 withheld/retracted | 中性列表，无旧正文 |

- 每个分区返回 `count` 与最多 50 条可定位事实（ID/名称/时间/负责人），不是只给总数；
- 分区权限不足时**整块不渲染**，接口也不返回该块；
- 重排 outbox 只允许 `status=failed`、`lease_owner IS NULL`，重置 attempts/available_at，
  写 operation log；已发送/已租约的记录拒绝；
- 运营不带 PII 权限时，姓名遮罩为 `张*`、手机号只显示末四位；导出仍走 G2 专用导出。

## 9. 开关与默认值

新增 `config/ecosystem_g4.php`，全部默认 false：

```text
ECOSYSTEM_SEARCH_ENABLED=false
ECOSYSTEM_FAVORITES_ENABLED=false
ECOSYSTEM_RELATIONS_ENABLED=false
ECOSYSTEM_SSR_ENABLED=false
ECOSYSTEM_SITEMAP_ENABLED=false
ECOSYSTEM_CONTENT_VIEWS_ENABLED=false
ECOSYSTEM_METRICS_ENABLED=false
ECOSYSTEM_OPS_WORKBENCH_ENABLED=false
```

- 读能力开关与既有域开关叠加：域开关关闭时，即使 G4 开关为 true 也只返回空/404；
- 收藏写入还需登录；`favorites.enabled=false` 时 POST/DELETE 返回 403
  `ECOSYSTEM_FAVORITES_DISABLED`，GET 返回空列表；
- SSR/sitemap 关闭时返回 404，不泄露“功能存在但没开”；
- 所有开关变更走 `config:cache` + 进程 reload，不能只改进程内变量。

## 10. 风险与止损

| 风险 | 止损 |
|---|---|
| 搜索拖库/深偏移 | q 长度上限、page 上限、游标模式、索引；不引入 ES |
| LIKE 通配符注入 | `addcslashes` + 参数绑定 + 定向测试 |
| 收藏泄露撤稿内容 | 读取时统一公开查询；只回 stale_count，不回旧字段 |
| 关联指向草稿/下架目标 | 解析时逐项复核；失效整项省略，不输出 CTA |
| SSR 缓存撤稿旧页 | 本批不启用页面缓存；撤稿后同请求立即 410；后续上 CDN 必须带撤稿 purge |
| sitemap 收录草稿 | 只查 publicationBase；测试断言草稿/撤稿不在 XML |
| 指标造假 | 无源返回 unavailable；口径页与查询同源；不把 0 当“没有数据源” |
| 运营台越权看 PII | 分区权限 + 遮罩 + 专用导出；测试 custom 角色 |
| 新表在 root 迁移失败 | 先备份、先独立库、additive、可回滚；失败不继续 |
| 收藏热点写 | 唯一键幂等；单用户上限；后续如需削峰再上队列，不在本批引入 |

## 11. 验收映射

| 用例 | 本批覆盖 |
|---|---|
| T27 分页同时间/并发发布 | A：游标 + id 决胜 + filters hash；测试并发插入 |
| T28 关联未公开/失效 | B：关联解析降级；测试草稿/撤稿/下架 |
| T70 收藏重复/跨账号/目标撤回 | B：唯一键、跨用户隔离、撤稿隐藏 |
| T71 分享/直达/搜索/SSR 元数据 | A/C：搜索、canonical/OG、H5 与 SSR 同源 |
| T73 DB/队列/撤稿故障 | E：kill switch、SSR 503、outbox 失败面 |
| T76 性能 | E：独立测试库基准命令，不用 `php -S` 冒充压测 |
| T77 窄屏/键盘 | C：SSR 无横向溢出；H5 复用既有布局 |
| T78 定时/积压/撤稿告警 | D：运营台分区 + 负责人 |
| T79 统计口径 | D：口径表 + 源事实对账 |
| T80 备份恢复/开关 | E：备份、恢复演练、开关矩阵 |

## 12. 明确不做（本批）

- 不引入 Elasticsearch / 外部搜索服务；
- 不做用户评论、点赞、关注、推荐流；
- 不做 CDN 自动 purge（只在 runbook 写明后续要求）；
- 不把 uni-app H5 改造成 SSR 框架；Laravel 负责 SSR，H5 负责交互；
- 不新增短信/邮件/微信通知渠道；
- 不改设备/商品真相源，不让关联变成购买承诺；
- 不开放任何生产报名/报名写闸/赛务写闸/公开成绩与展示闸。
