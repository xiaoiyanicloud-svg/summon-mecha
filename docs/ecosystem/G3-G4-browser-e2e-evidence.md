# G2/G3/G4 浏览器端到端与收尾证据

日期：2026-10-20。范围：G2 T31/T47、G3 客户侧全链路、**G3 Admin 录分/发布**、G4 搜索/收藏/关联/SSR/sitemap/撤稿/停读，
以及本地一键门禁。环境：Windows 11 + PHP 8.4.24 + MySQL 5.7.26 + 独立测试库
`summon_mecha_step10_test` + Chrome headless（CDP）。

> 本文只记录**实际执行过**的检查。生产并发、真机、小程序、外部服务仍未验证，见 §6。

## 1. 交付物

| 交付 | 文件 | 说明 |
|---|---|---|
| e2e 夹具 | `my-bagisto-store/qa/ecosystem/e2e/prepare.php` | 只写独立测试库；活动/资讯/关联/报名 scope/赛务/成绩 |
| e2e 状态变更 | `my-bagisto-store/qa/ecosystem/e2e/mutate.php` | 撤稿、创建/发布展示，全部调用真实服务 |
| e2e 清场 | `my-bagisto-store/qa/ecosystem/e2e/cleanup.php` | 按外键顺序清理；含 marker 兜底 |
| 中断清理 | `my-bagisto-store/qa/ecosystem/e2e/purge-leftovers.php` | 清掉引用对象已消失的孤儿夹具；仅独立测试库 |
| e2e 驱动 | `my-bagisto-store/qa/ecosystem/e2e/run.mjs` | 3 个独立 Chrome profile、真实 DOM 点击、截图 |
| G3 Admin 夹具 | `my-bagisto-store/qa/ecosystem/admin-g3/prepare.php` | 只写独立测试库；赛项/规则/两支队/两条 entry，不预写成绩 |
| G3 Admin 驱动 | `my-bagisto-store/qa/ecosystem/admin-g3/run.mjs` | 录分员与发布员两个独立 Chrome profile，真实后台表单提交 |
| 驱动加固 | `my-bagisto-store/qa/ecosystem/q04/driver.mjs` | CDP 调用/连接/新页面均加超时，避免挂死 |
| 一键门禁 | `tools/ecosystem-gate.sh` | 契约 + preflight + 后端/Admin + Node，可选浏览器 |
| 缺陷修复 | `utils/api.js` | 收藏 POST 的 `key` 强制转字符串（见 §3） |

## 2. 实测结果

命令：

```bash
cd D:/main/my-bagisto-store
# 需先导出 H5（本次已导出）：
# D:/HBuilderX/cli.exe publish web --project D:/main
node qa/ecosystem/e2e/run.mjs --json=D:/main/docs/ecosystem/g3g4-e2e-result.json
```

结果：**39/39 通过**（含 1 项 observed 探针），`ok=true`，退出码 0。
机器可读结果：[g3g4-e2e-result.json](./g3g4-e2e-result.json)；截图：[e2e-screenshots/](./e2e-screenshots/)。

| 分组 | 检查 | 结果 |
|---|---|---|
| 基础设施 | 夹具创建、8010/8011/8099 三服务就绪、清场 | 3/3 |
| G2 | 表单字段错误；报名确认；T47 第二设备同账号看到同一报名；T31 切号后不见他人报名 | 4/4 |
| G3 | 作品草稿→提交→展示草稿→队长授权→后台发布；公开成绩与展示；队长申诉 | 8/8 |
| G4 搜索/关联 | H5 关键词筛选；五类关联（活动/资讯/机型/技能/场景）全部展示；撤稿目标整项省略 | 3/3 |
| G4 收藏 | 会话探针（observed）；真实点击；刷新后仍收藏；我的收藏；资讯 tab；跨账号隔离；撤稿后 stale 隐藏 | 8/8（含 1 项 observed） |
| G4 SSR | 列表只含公开内容；活动 canonical/OG/Event JSON-LD；资讯 Article JSON-LD；sitemap 收录/排除 | 4/4 |
| G4 撤稿 | 撤稿前 410 中性页；撤稿动作；撤稿后 SSR 410；API 不泄漏；sitemap 排除；H5 列表消失 | 6/6 |
| G4 停读 | API / SSR / sitemap 统一 503 | 1/1 |
| 布局 | 375px 活动详情、成绩页无横向溢出 | 2/2 |

### G3 Admin 录分/发布（补测）

命令：

```bash
cd D:/main/my-bagisto-store
node qa/ecosystem/admin-g3/run.mjs --json=D:/main/docs/ecosystem/admin-g3-result.json
```

结果：**12/12 通过**，`ok=true`，退出码 0。机器可读结果：
[admin-g3-result.json](./admin-g3-result.json)；截图：[admin-g3-screenshots/](./admin-g3-screenshots/)。

| 检查 | 结果 |
|---|---|
| 测试库夹具（活动/规则/赛项/两队/两条 entry）与 8010 服务就绪 | 2/2 |
| 录分员打开成绩台 | 1/1 |
| 保存两条草稿（v1） | 2/2 |
| 两条草稿提交复核（按钮消失、状态变 submitted） | 1/1 |
| 写入选定名次（第 1/2 名） | 2/2 |
| 发布员打开同一成绩台 | 1/1 |
| 暂定发布 → 公开 API 返回 `provisional` | 1/1 |
| 终审公布 → 公开 API 返回 `final` | 1/1 |
| 夹具清场（events/tracks/teams/entries/customers/admins） | 1/1 |

分权与边界：录分员、发布员为两个不同后台账号与独立 Chrome profile；
录分权限与发布权限分别由 `ecosystem.competition.scoring` /
`ecosystem.competition.results` 控制，服务层仍强制“同一管理员不能既录分又发布”。
演练只在本进程启动的测试库服务上打开 `ECOSYSTEM_COMPETITION_WRITE_ENABLED` 等开关，
不修改 `.env`、不写 root。

> 首轮巡检在 `recorder_submitted_reviews` 处得到 11/12：提交后立即读取 DOM，
> 仍看到提交按钮。复核后确认是脚本等待时序问题（页面刷新尚未完成），
> 改为等待“提交复核”按钮数量下降后再判定；复跑 **12/12**，服务端日志无 5xx，
> 未发现产品缺陷。

关键实测值：

```text
G2 register public_no: EV...（真实回执）
G3 appeal_no: AP...（真实申诉编号）
G4 favorite: click → 已收藏 → reload 仍为已收藏 → 我的收藏可见
撤稿后：SSR 410、API 404/410、sitemap 排除、收藏 stale_count=1 且不显示旧标题
kill switch：/api/events=503 /events=503 /sitemap.xml=503
```

> 复跑纪律：本次在清空测试库后完整复跑，结果为 39/39；`cleanup.php` 结束后的残留检查
> （events / content_posts / customers / ecosystem_outbox / ecosystem_relations /
> ecosystem_content_view_daily）均为 0。
> 三个“设备”使用独立 Chrome profile，调试端口由 OS 分配（避免端口碰撞后共享 localStorage）。
> 若演练中途被强行终止，先跑 `qa/ecosystem/e2e/purge-leftovers.php` 清孤儿，再重跑，
> 否则残留夹具会让全量 PHPUnit 出现假失败（本次实际遇到过）。

## 3. 演练抓到的真实缺陷

**H5 收藏按钮在修复前不可用。**

- 前端 `favoriteApi.add('event', event.id)` 把活动 ID 作为 **number** 放进 JSON；
- 后端 `FavoriteController::store` 校验 `key` 为 **string**，返回 422；
- 结果：点击「收藏」只弹错误，状态不变。G4 的后端测试全部用 `(string) $event['id']`，
  所以单元/功能测试没有暴露；真实浏览器 e2e 首次点击即复现。

修复（`utils/api.js`）：

```js
add: (type, key) => http.post('/api/customer/favorites', { type, key: String(key) }, ...)
```

修复后重新导出 H5，e2e 收藏链路 7/7 通过。

## 4. 一键门禁

```bash
cd D:/main
bash tools/ecosystem-gate.sh                    # 契约 + preflight + 后端/Admin + Node
bash tools/ecosystem-gate.sh --with-admin-browser  # 另加 G3 Admin 录分/发布浏览器演练
bash tools/ecosystem-gate.sh --with-browser     # 另加 Admin 演练 + 本文件的 G2/G3/G4 e2e
```

- 日志写入 `.claude/ecosystem-gate-<时间>.log`（已加入 `.gitignore`）；
- PHPUnit 只指向 `summon_mecha_step10_test`；
- 任一步失败继续跑完，最终非零退出。

## 5. 文件与开关边界

- e2e 只在本进程启动的 `artisan serve` 上打开开关，**不修改 `.env`**；
- 不写 root；`prepare/cleanup` 都经过 `qa/ecosystem/bootstrap.php` 硬校验；
- 不新增迁移、不修改已执行迁移；
- 未打开任何生产开关。

## 6. 未做 / 边界

| 项 | 说明 |
|---|---|
| 小程序真机 / Safari / 微信内置浏览器 | 只验证 Chrome headless |
| 生产并发 | 本次是单浏览器流程，不是压测；并发证据仍以 Q-02/Q-03 为准，且需生产规模复跑 |
| 搜索/分享真机 | H5 canonical 已写入；小程序分享、搜索引擎收录未验证 |
| CDN/反代撤稿 purge | 无 CDN，未验证缓存层 |
| 通知渠道 | 只有站内信 outbox，`sent` 不代表已送达 |
| 真机附件上传/下载 | Admin 录分/发布 UI 已由 `admin-g3/run.mjs` 覆盖；作品/申诉附件的真机上传下载仍未验证 |
