# G4-08 分发与运营验收演练

日期：2026-10-20。环境：独立库 `summon_mecha_step10_test`（`APP_ENV=testing`）+ 本机 MySQL。
本文件只记录**实际执行**的场景；未执行项在 §4 明列。

## 1. 演练矩阵

| 用例 | 场景 | 实际动作 | 结果 |
|---|---|---|---|
| T27 | 分页同时间 / 并发新增 | 5 条同 `published_at` 活动，游标每页 2 条；翻页期间插入更新时间更晚的活动 | 5 条不重不漏；新活动不插队；从头新会话可见 |
| T27 | 游标与筛选不一致 | 第一页带 `q=活动`，第二页改 `q=另一个` | 422 `VALIDATION_ERROR` + `reason=cursor_filter_mismatch` |
| T28 | 关联指向草稿/撤稿/下架 | 活动详情关联公开活动、草稿活动、撤稿活动、active/inactive 机型、公开/草稿技能、status 1/0 场景 | 只输出公开目标；其余整项省略；无 storage_key / 证据引用 |
| T70 | 收藏重复/跨账号/目标撤回 | 同一目标点两次；两个账号分别读列表；目标撤稿后读列表 | 第二次 `created=false`；另一账号看不到；撤稿只计 `stale_count`，无旧标题 |
| T70 | 收藏上限 | 上限设 1，收藏第二个目标 | 409 `FAVORITE_LIMIT_REACHED` |
| T70 | 停读/域关闭侧信道 | kill switch 打开后收藏/关联 | 收藏 503；关联解析为空；域关闭时收藏新增 404、列表空 |
| T71 | 搜索/SSR 元数据 | `q` 命中、`%`/`_`/`\` 字面量；SSR canonical/OG/JSON-LD | 全部通过；筛选页 `noindex,follow` |
| T73 | 停读 | kill switch 打开 | API / SSR / sitemap 全部 503，不伪装空列表 |
| T73 | 域关闭 | `events.public_enabled=false` | SSR 404；API 列表空/详情 404；不渲染空壳 |
| T76 | 查询性能 | 2,000 条已发布活动，20 次采样 | 见 [G4-evidence.md](./G4-evidence.md) §5；单进程基准，不是并发报告 |
| T78 | 通知失败 | 插入 failed 未租用 outbox，运营台重排；再插入已租用 failed | 未租用可重排为 pending/attempts=0；已租用拒绝且保持 failed |
| T79 | 统计口径 | 两条 attempt（一条取消、一条重报后取消） | submitted=2 / seats=0 / canceled=2 / reapply=1；与源事实一致 |
| T80 | 备份恢复 + 前向迁移 | 恢复 `root-before-g4` 到独立库，再跑 G4 迁移 | 223→226 表；events=11、registrations=1 不变；新表为空 |

## 2. 撤销与撤稿链路

撤稿演练同时验证五个出口：

```text
API 详情      -> 410 中性文案，无旧标题/摘要
SSR 详情      -> 410 中性页，noindex
sitemap       -> 不再包含该 URL
收藏列表      -> data=[]，stale_count=1，无旧标题
关联解析      -> 该目标整项省略，不出现占位 CTA
```

活动取消（`hosting_status=canceled`）后：

```text
registration_state=open -> 不再包含该活动
详情 DTO                -> hosting_status=canceled
registration.can_register -> false
registration.reason       -> canceled
```

## 3. 权限与隐私

- Admin 全部 `admin.catalog.ecosystem.*` 路由逐条登记权限；custom 角色直接 URL/Ajax 403。
- 指标页 / 运营台 / 关联维护分别受 `ecosystem.metrics.view`、`ecosystem.ops.view`、
  `ecosystem.events.edit` / `ecosystem.content.edit` 控制。
- 运营台报名行同时受活动人员范围约束；无 pii 能力显示 `张*` / `138****8000`。
- 无活动范围的运营角色看不到该活动报名。
- 访问统计只写日聚合，表 7 列，不存 IP / UA / 用户 ID。
- 收藏响应 `Cache-Control: private, no-store`。

## 4. 未执行 / 不宣称

| 项 | 原因 |
|---|---|
| 生产并发压测 | 只有单进程 CLI 基准；按 Q-02/Q-03 在约定环境补 |
| 真实浏览器 H5/SSR 全链路 | **2026-10-20 已补**：Chrome headless + 独立测试库，39/39，见 [G3-G4-browser-e2e-evidence.md](./G3-G4-browser-e2e-evidence.md)；生产/真机仍需复跑 |
| CDN/反代缓存与撤稿 purge | 本批不启用页面缓存 |
| 搜索引擎实际收录 | sitemap 默认关闭，未提交 |
| 小程序真机分享 / 二维码 | 无目标环境 |
| 大规模数据恢复耗时 | 只在 223 表 / 11 活动规模演练 |
| 文件上传攻击面 | G4 未新增上传入口；沿用 G1 媒体安全测试 |
| 队列消费端到端 | G4 未新增消费者；沿用 G1/G2 outbox 测试 |

## 5. 结论

G4 的分发与运营链路在独立库通过功能、权限、隐私、撤稿与恢复演练。
浏览器 H5/SSR 全链路已于 2026-10-20 本机补测（39/39）；
**未做生产并发、CDN purge 与搜索引擎收录**，不得据此宣称生产已验收；
上线顺序与回退见 [G4-runbook.md](./G4-runbook.md)。
