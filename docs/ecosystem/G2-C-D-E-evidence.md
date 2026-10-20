# G2-C / G2-D / G2-E 交付证据（本机）

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g2-free-registration` |
| 范围 | G2-05、G2-08、G2-09、G2-10、G2-11、G2-12、G2-13、G2-15、Q-02、Q-03 |
| 测试库 | `summon_mecha_step10_test` |
| root | 已 additive 迁移（备份 + 恢复演练）；报名闸仍关闭 |
| 结论 | G2 代码与本机验证完成；生产逐活动开通仍需业务签署与部署侧动作 |

## 1. 本批改动

### 变更与签到（G2-08 / G2-09）

- `RegistrationChangeService`：活动取消、改期记录、变更确认、逾期处理。
- `RegistrationCredentialService`：32 字节 token、仅存 SHA-256、绑定 attempt、
  重发吊销、扫码 payload 前缀剥离、签到幂等。
- `event_changes` / `event_change_acknowledgments`。
- 命令：`ecosystem:expire-registration-change-acks`。
- B-06 闸门：`ECOSYSTEM_REGISTRATION_LIFECYCLE_ENABLED=false` 时取消/改期写能力关闭。

### 导出与留存（G2-13 / G2-11）

- `RegistrationExportService`：私有盘 CSV、BOM、公式中和、遮罩/原始分权、
  短期 token、下载前重检活动范围与权限。
- `ecosystem:registration-retention --check-key`：只统计 + 密钥抽样，不删除。
- `ecosystem:reconcile-registration-scopes`：从 attempts 重算名额计数，默认 dry-run。
- 解密查看写 `registration.pii.viewed` 审计；导出写
  `registration.export.masked|pii` 与 `registration.export.downloaded`。

### 消息（G2-12）

- outbox 注册 `registration.*` 事件。
- `EcosystemOutboxProcessor` 新增报名事实核验与站内信投递：
  `notification` 与 outbox 结算同事务，`dedupe_key` 唯一。
- 只发 `inapp`；未接短信/微信/支付。

### 前端（G2-05 / G2-10）

- 新页面：`pages/ecosystem/event-register.vue`、`pages/ecosystem/my-registrations.vue`。
- 活动详情支持 `login` / `mine` 两种 G2 状态。
- 报名页：内联登录、版本化表单、字段错误、提交中、超时按 request_key 查回、
  回执；只持久化非敏感选项与 request_key。
- 我的报名：回执、取消、重报、变更确认、入场凭证、复制凭证内容。
- `pages.json` 注册页面；`我的` 增加「我的报名」入口。

### 管理端

- 报名列表/详情/审核/取消/签到/导出/活动变更表单。
- ACL：`ecosystem.registrations.{view,review,cancel,pii,export,checkin,settings,staff}`。
- 超管越过活动范围但写审计；非超管必须有 `event_staff_assignments` 能力。

## 2. 测试摘要

```text
Shop ecosystem 全量（含 G1 回归 + G2）  422 passed / 3077 assertions
Admin 生态 ACL + G2 授权                 9 passed / 301 assertions
前端 Node 单测                            29 passed
```

新增 G2 测试文件：

- `RegistrationChangeG2Test` 6 passed
- `RegistrationCredentialG2Test` 5 passed
- `RegistrationExportG2Test` 3 passed
- `RegistrationNotificationG2Test` 3 passed
- `RegistrationSubmissionG2Test` 12 passed
- `RegistrationKeyRotationG2Test` 1 passed
- `PublicRegistrationApiG2Test` 5 passed
- `RegistrationAdminAclG2Test` 2 passed
- `RegistrationSchemaG2Test` / `RegistrationScopeServiceG2Test` /
  `RegistrationStaffScopeG2Test` 12 passed

## 3. Q-02 真实多进程并发

脚本：`.claude/backup/G2-CONCURRENCY/{prepare,worker,lock_probe,cleanup}.php`（未提交）。

### 3.1 容量 1 两进程竞争

```json
// worker-a (pid 12316)
{"ok": false, "error_code": "REGISTRATION_FULL", "error_message": "名额已满。"}
// worker-b (pid 9744)
{"ok": true, "status": "confirmed", "public_no": "EV1394A33852C94DAE"}
```

数据库复核：

```json
{
  "attempts": [{"id":141,"owner_customer_id":158,"status":"confirmed"}],
  "confirmed_count": 1,
  "registrations": 1
}
```

结论：两个真实 PHP 进程同时提交，只有一个 confirmed；失败方没有产生 registration/attempt。

### 3.2 两连接锁等待

```json
{"role":"holder","pid":32124,"note":"held event+scope locks for 3s"}
{"role":"waiter","pid":5336,"scope":197,"waited_ms":2661,"blocked":true}
```

结论：第二个连接在 `SELECT ... FOR UPDATE` 上真实阻塞约 2.66 秒，证明锁协议不是单事务模拟。

### 3.3 T37 两审核者抢最后名额

容量 1、manual、两个 submitted 轮次，两个真实进程同时批准：

```json
{"attempt_id":838,"ok":false,"error_code":"REGISTRATION_FULL","error_message":"名额已满，无法批准。"}
{"attempt_id":839,"ok":true,"status":"confirmed","row_version":1}
```

数据库：attempt 839 `confirmed`、attempt 838 `submitted`、`confirmed_count=1`。

### 3.4 T38 重复取消与新申请竞争

两个进程用同一 request_key 取消 A，另一个进程同时提交 B：

```json
{"ok":true,"replayed":false,"status":"canceled"}
{"ok":false,"error_code":"REGISTRATION_REQUEST_CONFLICT","error_message":"请求正在处理中，请稍后按同一请求键重试。"}
{"customer_id":351,"ok":true,"status":"confirmed"}
```

数据库：A `canceled`、B `confirmed`、`confirmed_count=1`、有效席位事实=1；
没有负数、没有重复释放。

### 3.5 T40 / T42 等锁期间截止 / 降容

holder 先锁 event+scope，worker 等待；holder 在持锁事务内改条件后提交：

```json
// T40：等锁期间把报名截止改为过去
{"error_code":"REGISTRATION_CLOSED","error_message":"报名已截止。"}
// T42：等锁期间把容量改为 0
{"error_code":"REGISTRATION_FULL","error_message":"名额已满。"}
```

两次都没有产生 attempt，证明取得锁后重新读取当前状态，而不是按请求开始快照放行。

### 3.6 T51 afterCommit / rollback

```json
{
  "before_commit": 0,
  "after_commit": 1,
  "rolled_back_visible_during_tx": 1,
  "after_rollback": 1,
  "ok": true
}
```

未提交的 outbox 对其他连接不可见；回滚后不留下事实。

### 3.7 T53 跨 500 行扇出

520 条 confirmed 轮次一次性取消，使用 `chunkById` 而非 offset `chunk`：

```text
RegistrationChangeG2Test · 取消活动跨 500 行扇出不漏人（T53）  PASS
520 条全部 canceled；confirmed_count=0；520 条 event_canceled outbox
```

修复前 offset 分页会因状态更新导致结果集收缩而漏行；已改为 `chunkById`。

### 3.8 T56 密钥轮换

`RegistrationKeyRotationG2Test`：

- 历史 APP_KEY 加密的联系人，在配置
  `ECOSYSTEM_REGISTRATION_PREVIOUS_APP_KEYS` 后可由 key ring 解密；
- `ecosystem:rotate-registration-contact` dry-run 报告可轮换；
- `--apply` 后用当前 APP_KEY 重新加密，并重算 `phone_hash`；
- 移除历史 key 后仍可解密、可校验哈希。

## 4. Q-03 真实 worker

两个进程同时执行：

```text
A: 恢复过期租约 0；领取 1；sent 1 / retried 0 / dead 0 / lease_lost 0 / errors 0。
B: 恢复过期租约 0；领取 0；sent 0 / retried 0 / dead 0 / lease_lost 0 / errors 0。
```

通知结果：

```json
{"type":"registration.confirmed","channel":"inapp","dedupe_key":"notification:registration:142:registration.confirmed"}
```

把同一 outbox 行重置为 pending 再跑一轮后，通知计数仍为 `1`。结论：重复投递不重复发信。

## 5. G2-15 真实浏览器演练

工具：Chrome 155 headless + CDP（无 Playwright）。H5 由 HBuilderX `publish web`
真实构建；后端为独立 8011 进程 + 独立测试库；API 配置临时指向 8011，构建后已恢复
`utils/config.js` 的 8000。

### 5.1 客户侧

`e2e-result.json`：

```json
{
  "ok": true,
  "steps": [
    "event_detail_loaded",
    "register_login_page",
    "logged_in_form",
    "submitted",
    "my_registrations",
    "credential"
  ],
  "public_no": "EV9C0AFDD22C965D05"
}
```

截图（`docs/ecosystem/screenshots/`）：

- `e2e-01-event-detail.png`
- `e2e-02-login.png`
- `e2e-03-form.png`
- `e2e-04-receipt.png`
- `e2e-05-my-registrations.png`
- `e2e-06-credential.png`

### 5.2 Admin 侧

`e2e-admin-result.json`：

```json
{
  "ok": true,
  "steps": [
    "admin_logged_in",
    "registrations_list",
    "checkin_form",
    "checkin_success",
    "checked_in_visible"
  ]
}
```

数据库复核：attempt 143 `status=checked_in`、`checked_in_by=1`、`row_version=1`。

截图：

- `e2e-admin-01-registrations.png`
- `e2e-admin-02-checkin-form.png`
- `e2e-admin-03-checkin-success.png`
- `e2e-admin-04-checked-in-list.png`

演练中发现并修复：扫码/复制得到的完整 QR payload 带
`SUMMONMECHA-CHECKIN:` 前缀，服务端必须先剥离前缀再哈希，否则签到永远无效。
已加回归（凭证测试使用 `qr_payload` 签到）。

## 6. root 迁移

- 备份：`backups/root-pre-g2-20261019.sql`（1,632,567 bytes）。
- 恢复演练：临时库 `root_restore_g2_20261019` → 195 表 / 11 events / 1 registration；
  演练后删除临时库。
- `php artisan migrate --force`：9 个 G2 迁移全部 DONE（root 195 → 204 表）。
- 迁移后：events=11、event_registrations=1；历史行 `identity_kind=legacy`、
  `scope_id=NULL`、`row_version=0`；旧列未删。
- `.env` 未改动：`registration_enabled=false`、`registration_write_verified=false`、
  `ecosystem_registration.enabled=false`、`lifecycle.enabled=false`。

## 7. T31–T58 覆盖对照

| 用例 | 状态 | 证据 / 缺口 |
|---|---|---|
| T31 登录续办/切号/版本过期 | 部分 | 浏览器内联登录 + 登录后重取；切号清私有状态未单独浏览器验证 |
| T32 幂等重放/异载荷 | 通过 | 提交/取消/API 测试 |
| T33 同主体唯一 | 通过 | `uk(scope_id,subject_key)` + 提交测试 |
| T34 容量 1 多进程 | 通过 | Q-02 两进程证据 |
| T35 容量 null/0/有限 | 通过 | 0/有限/null 不限 + 已报名优先于 full 测试 |
| T36 待审/批准/拒绝 | 通过 | 服务 + API 测试 |
| T37 两审核者抢名额 | 通过 | 两 Admin 进程实测：1 confirmed / 1 REGISTRATION_FULL |
| T38 重复取消/新申请竞争 | 通过 | 两取消进程 + 提交进程；不重复释放、不超额 |
| T39 重报旧轮次隔离 | 通过 | 取消/重报/旧 token 测试 |
| T40 等锁期间截止/关闭 | 通过 | holder 持锁改截止，worker 取锁后 REGISTRATION_CLOSED |
| T41 RR 旧快照 | 部分 | `lockForUpdate` 当前读；未单列 RR 测试 |
| T42 降容/关报名竞争 | 通过 | holder 持锁降容 0，worker 取锁后 REGISTRATION_FULL |
| T43 审批晚于审核截止 | 通过 | `RegistrationReviewService` + 到期扫描测试 |
| T44 活动变更/确认 | 通过 | 接受/拒绝/逾期/签到阻塞测试 |
| T45 旧 guest/手机号变体 | 通过 | 历史行隔离 + 手机号归一化；不自动认领 |
| T46 他人回执/取消 | 通过 | API 404 越权测试 |
| T47 刷新/跨设备 | 部分 | API 列表/详情；跨设备未实测 |
| T48 QR 重复/旧轮次/取消 | 通过 | 凭证测试 + 浏览器签到 |
| T49 未授权签到 | 通过 | 活动范围/能力测试 |
| T50 取消活动与异步通知 | 通过 | 取消立即停写 + 通知测试 |
| T51 afterCommit/回滚 | 通过 | 未提交不可见、提交可见、回滚不可见 |
| T52 worker 重复/崩溃/租约 | 通过 | Q-03 + G1 租约测试 |
| T53 扇出跨页 | 通过 | 520 行取消；chunkById 全部覆盖 |
| T54 日志/告警/导出无 PII | 通过 | 通知/导出/DTO 测试 |
| T55 导出公式/撤回/过期 | 通过 | 导出测试 |
| T56 加密/轮换/恢复 | 通过（本机） | 旧 key 可读、轮换后只依赖当前 key；生产密钥托管未演练 |
| T57 注销/留存/删除 | 阻塞 | B-05 未签署；只提供 dry-run，不执行删除 |
| T58 完整浏览器演练 | 通过 | 客户 + Admin 两段真实浏览器 |

## 8. 未完成 / 未验证

- 未接短信/微信/支付；界面不显示其成功。
- T31 的切号/跨账号浏览器状态、T47 的跨设备一致性仍是部分覆盖；
  T57 受 B-05 留存/注销政策阻塞，只提供 dry-run。
- 生产密钥托管与旧 key 恢复演练未做；本机轮换测试已通过。
- 入场凭证目前返回可复制的 QR payload 文本，未渲染二维码图片/扫码枪；
  签到页可粘贴完整 payload。真实摄像头扫码未接入。
- 小程序真机分享/扫码、Safari/微信内置浏览器未验证。
- 生产部署侧未启动 `schedule:work`/cron；本机调度器证据属于 G1。
- 生产 `.env` 未打开任何 G2 开关；未创建 scope、未开放任何活动报名。

## 9. 回滚

1. `ECOSYSTEM_EVENT_REGISTRATION_ENABLED=false`、
   `ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED=false`、
   `ECOSYSTEM_REGISTRATION_ENABLED=false`。
2. 目标 scope `closed`；停止两个到期扫描命令。
3. 代码回滚到 G2 前提交；新增表/列保留（additive），不删报名事实。
4. root 恢复使用 `root-pre-g2-20261019.sql`，必须先演练到临时库。
