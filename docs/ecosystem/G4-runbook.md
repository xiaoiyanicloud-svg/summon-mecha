# G4 分发与运营 · 上线与值守手册

日期：2026-10-20。适用：G4-01 ~ G4-09 代码已合并、开关仍默认关闭的状态。
配套：[G4-implementation-plan.md](./G4-implementation-plan.md) ·
[G4-evidence.md](./G4-evidence.md) · [HANDOFF.md](./HANDOFF.md)。

## 1. 一句话

**先读后写、先内后外、先单项后组合。** G4 的入口都建立在 G1 公开可见性上；
任何开关都不能绕过 G1 公开域、G2 报名双闸或 G3 赛务写闸。

## 2. 开关矩阵

| 开关 | 默认 | 打开前置 | 关闭后果 |
|---|---|---|---|
| `ECOSYSTEM_SEARCH_ENABLED` | false | G4-01 测试通过；公开域已开 | `q` / `registration_state` 返回 422；列表仍可用 |
| `ECOSYSTEM_FAVORITES_ENABLED` | false | G4-02 权限/隔离/幂等演练通过 | POST/DELETE 403；GET 空列表 |
| `ECOSYSTEM_RELATIONS_ENABLED` | false | G4-03 失效降级演练通过 | 详情 `related` 全空；Admin 配置保留 |
| `ECOSYSTEM_SSR_ENABLED` | false | G4-04 SSR 测试通过；`ECOSYSTEM_PUBLIC_SITE_URL` 已配置 | `/events`、`/content` 404 |
| `ECOSYSTEM_SITEMAP_ENABLED` | false | SSR 元数据验收通过 | `/sitemap.xml` 404 |
| `ECOSYSTEM_METRICS_ENABLED` | false | Admin 指标页权限验收通过 | 指标页显示“未启用” |
| `ECOSYSTEM_CONTENT_VIEWS_ENABLED` | false | G4-06 访问记录与隐私验收通过 | 访问指标 `available=false` |
| `ECOSYSTEM_OPS_WORKBENCH_ENABLED` | false | G4-07 权限/遮罩/重排验收通过 | 工作台显示“未启用”，仍可只读事实 |

叠加关系（缺一不可）：

```text
公开详情/列表 = G1 domain public_enabled
              + G1 publicationBase（published + normal + approved revision + sanitizer）
              + G4 SSR/sitemap 开关（仅决定是否有该入口）
              + kill switch 未命中

站内报名     = G2 registration_enabled + registration_write_verified
              （G4 收藏/关联/SSR 均不能代替）
```

## 3. 分阶段上线顺序

### 阶段 0：只读预热（不对外）

1. 备份：`mysqldump root > backups/root-before-g4-<日期>.sql`；
2. 执行 additive 迁移（仅 3 张新表）；
3. 打开 `METRICS`、`OPS`（Admin 内部），核对事实与权限；
4. 保持 `SEARCH/FAVORITES/SSR/SITEMAP` 关闭。

**验收**：`ecosystem:g4-status` 输出与预期一致；Admin 指标/运营台仅授权角色可见。

### 阶段 1：搜索与关联（只读）

1. 打开 `SEARCH_ENABLED`；
2. 打开 `RELATIONS_ENABLED`；
3. 观察公开列表 15 分钟：422 比例、查询耗时、错误日志；
4. 保持收藏、SSR、sitemap 关闭。

**回退**：关闭两个开关即可；无需回滚数据。

### 阶段 2：SSR / sitemap（搜索引擎可见）

前置：

- `ECOSYSTEM_PUBLIC_SITE_URL` 与前端 `SSR_BASE_URL` 完全一致；
- 抽查 3 个已发布活动/文章的 canonical、OG、JSON-LD；
- 抽查 1 个撤稿对象：410 中性页、sitemap 不含、旧标题不出现；
- 确认反向代理不会缓存 200 响应超过撤稿处置时间（本批代码不启用页面缓存）。

顺序：先 `SSR_ENABLED`，再 `SITEMAP_ENABLED`。提交搜索引擎前至少观察 24 小时。

**回退**：关闭 sitemap → 关闭 SSR；已收录页面会 404，需在站长平台提交移除。

### 阶段 3：收藏（唯一新增用户写入）

前置：

- 登录态、跨账号隔离、重复点击幂等、撤稿隐藏四项演练通过；
- 单用户上限（默认 500）与限流（`throttle:write`）确认；
- 监控 `ecosystem_favorites` 增长与 5xx。

**回退**：关闭 `FAVORITES_ENABLED`；已有收藏关系保留，但不再可写/不可读（GET 空列表）。
不要删除 `ecosystem_favorites` 表来“回退”，那会丢掉用户偏好。

### 阶段 4：访问统计

打开 `CONTENT_VIEWS_ENABLED`。先观察写入失败日志 `ecosystem.content_view.record_failed`；
该统计失败不影响内容页返回，但指标会偏低。

## 4. 停写 / 停读 / 回退

### 4.1 紧急停读（首选）

```bash
# .env 或配置中心
ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED=true
```

影响：公开 API / 公开媒体 / SSR / sitemap 统一 503；**不是空列表、不是 404**。
需要 `config:cache` + 进程 reload，单改进程内变量不影响其他 worker。

### 4.2 只停某个新入口

| 场景 | 动作 |
|---|---|
| 搜索拖库 | `SEARCH_ENABLED=false` |
| 收藏异常/被刷 | `FAVORITES_ENABLED=false` |
| 关联指向错误 | `RELATIONS_ENABLED=false` |
| SSR 输出错误元数据 | `SSR_ENABLED=false`，必要时同时关 sitemap |
| 指标口径错误 | `METRICS_ENABLED=false`（不删数据） |
| 运营台越权 | 收回对应角色 `ecosystem.ops.*` 权限；必要时 `OPS_WORKBENCH_ENABLED=false` |

### 4.3 回退边界

- **只回退代码/开关，不回滚数据库**：G4 三张新表是 additive；回滚迁移会丢掉收藏/关联/访问事实。
- 已有新收藏/关联后，禁止用旧库备份整库覆盖。若必须恢复，先导出三张新表并在恢复后回灌。
- 撤稿是终态：不要通过“重试/换 ID/删表”让旧内容复活；更正报道必须走独立审核发布。

## 5. 值守与负责人

| 分区 | 负责人（配置项） | 失败处理 |
|---|---|---|
| 待审 revision / 来源复核 / 撤稿 | `ECOSYSTEM_OPS_RESPONSIBLE_REVIEW` / `_SOURCE` / `_DISPOSITION` | 按工作台入口回到发布工作页；不绕过审核 |
| 待审报名 / 即将截止 | `ECOSYSTEM_OPS_RESPONSIBLE_REGISTRATION` | 回到报名运营页；PII 权限不足时不得要求他人代看 |
| 通知失败 | `ECOSYSTEM_OPS_RESPONSIBLE_NOTIFICATION` | 运营台“重排”只接受未租用 failed；消费端按 dedupe_key 去重 |
| 公开读取 503 | 平台运维 | 先确认 kill switch 是否人为打开；再查 DB/队列；不要改成空列表 |

**升级路径**：值班运营 → 平台运维 → 产品/内容负责人。越权、撤稿泄露、成绩/奖项错误
属于立即升级项，先停读再排查。

## 6. 上线检查清单

- [ ] root 已备份，备份文件大小与时间已记录
- [ ] `migrate:status` 无 Pending
- [ ] `ecosystem:g4-status` 输出与开关矩阵一致
- [ ] 独立测试库全量测试通过（数量见 evidence）
- [ ] Admin ACL 全路由测试通过
- [ ] 前端 Node 测试通过
- [ ] 抽查 1 个已发布、1 个撤稿、1 个草稿：API/SSR/sitemap/收藏/关联行为符合预期
- [ ] `ECOSYSTEM_PUBLIC_SITE_URL` 与前端 `SSR_BASE_URL` 一致
- [ ] kill switch 演练过，且知道它返回 503
- [ ] 值守负责人和升级路径已确认

## 7. 本批明确不做

- 不启用页面/CDN 缓存（撤稿即时生效优先）；
- 不接 ES、不接外部搜索；
- 不做短信/邮件/微信通知；
- 不做收藏/搜索的个性化推荐；
- 不把访问统计做成用户画像，不存 IP/UA/用户 ID。
