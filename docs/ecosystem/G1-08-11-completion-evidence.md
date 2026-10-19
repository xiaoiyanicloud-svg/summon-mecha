# G1-08 ~ G1-11 可信发布闭环 · 最终交付证据

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | codex/ecosystem-g1-trusted-publishing |
| 范围 | G1-08A/08B、G1-09、G1-10、G1-11、Admin 发布工作台、集成修正 |
| 数据库 | 独立测试库 summon_mecha_step10_test；root 主库未迁移、未写入 |
| 公开状态 | 活动/资讯公开开关与 kill switch 均保持默认关闭；**不宣称线上已开放** |
| 运行态 | outbox worker 与到期发布 scheduler 默认关闭；未启动常驻 schedule:work / crontab |
| 结论 | 代码、迁移、服务、HTTP、Admin、Node 测试均已在独立库通过；生产迁移/开关/常驻进程/真实并发与真机分享仍未验证 |

## 1. 已完成范围

- G1-08A：revision 提交/批准/拒绝、审批绑定指纹/媒体 manifest/sanitizer、并发 CAS、事务审计、新草稿失效旧批准。
- G1-08B：发布/排期/取消排期/暂缓/恢复/撤稿、公开指针原子切换、事务 outbox。
- G1-09：outbox 租约/重试/dead/过期恢复、旧 worker 回报拒绝、到期发布扫描、公开读取 kill switch。
- G1-10：活动/资讯/媒体公开 API、显式 DTO、404/410/503、分页筛选、私有盘流式媒体。
- G1-11：独立活动/资讯列表与详情、首页真实预览、分页筛选、分享、受控富文本渲染。
- Admin：活动/资讯 revision 工作台、审核、排期、发布、处置、主办方核验、安全预览、逐路由 ACL。

## 2. 最终验证

### 2.1 Shop G1/G0

`	ext
Tests: 378 passed (2849 assertions)
Duration: 67.50s
`

覆盖 G1-02 ~ G1-11 全部服务/HTTP/媒体/迁移测试，以及 G0 报名停写闸。

### 2.2 Admin G1/G0

`	ext
Tests: 36 passed (618 assertions)
Duration: 8.28s
`

覆盖 G1 Admin ACL/工作流/预览/并发，以及 G0 赛事 ACL/表单/PII 回归。

### 2.3 前端纯函数

`	ext
node --test tools/tests/*.test.mjs
tests 36 / pass 36 / fail 0
`

### 2.4 静态与路由

- 71 个改动/新增 PHP 文件 php -l：0 failures。
- git diff --check：通过。
- `route:list --path=admin/catalog/ecosystem-publishing`：31 条。
- 公开 API：/api/events 4 条、/api/content/posts 3 条。
- migrate:status：测试库 000001 ~ 000016 均 Ran。
- H5 dev server 375/390 截图：活动/资讯空态、活动/资讯 404、首页两个 tab。

## 3. 关键质量修正

1. 已发布对象存在 in_review revision 时禁止再开新 draft，避免并行审核。
2. 已发布对象开新 draft 时清空旧 pproved_revision_id / scheduled_publish_at，防止被取代候选复活。
3. 拒绝后清理失效批准与排期，公开指针不变。
4. H5 分享不再使用 API BASE_URL，改用当前站点 origin/path。
5. xternal_registration_url 受控加入公开 DTO，但 external 活动仍受 B-01/B-03 发布前置约束。
6. Admin cosystem.* 授权改为中间件先 403、控制器再按路由细化，避免新增路由漏授权时静默放行。

## 4. 仍未完成 / 未验证

- root/生产迁移、DDL 锁影响、备份恢复与维护窗口演练。
- 打开 ECOSYSTEM_EVENT_PUBLIC_ENABLED / ECOSYSTEM_CONTENT_PUBLIC_ENABLED。
- 启动并观察 outbox worker / 到期发布 scheduler；sent 仅代表事实被消费，不代表用户收到通知。
- 真实双进程/双连接锁竞争与长时间稳定性。
- H5 有数据视图、410/503 截图、H5 剪贴板实写、小程序真机分享。
- Admin 媒体上传/审核 UI、多来源编辑、活动 G1 新建入口。
- CDN/缓存撤回、报名 G2、搜索/收藏/SSR/OG/sitemap。

## 5. 证据索引

- [G1-08-11-implementation-plan.md](./G1-08-11-implementation-plan.md)
- [G1-08B-publication-evidence.md](./G1-08B-publication-evidence.md)
- [G1-09-worker-evidence.md](./G1-09-worker-evidence.md)
- [G1-10-public-api-evidence.md](./G1-10-public-api-evidence.md)
- [G1-11-frontend-evidence.md](./G1-11-frontend-evidence.md)
- [G1-admin-publishing-console-evidence.md](./G1-admin-publishing-console-evidence.md)
- [Execution-Roadmap.md](./Execution-Roadmap.md)
