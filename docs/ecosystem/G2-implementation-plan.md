# G2 免费活动运营 · 实施计划

> 日期：2026-10-19
> 分支：`codex/ecosystem-g2-free-registration`
> 基线：G1 已完成（root 195 表；G1 回归 382 passed / 2908 assertions）。
> 本文件只描述**如何落地 G2-01 ~ G2-15**，不把未完成项写成已完成。
> 依赖口径：[ADR-0001](./ADR-0001-trusted-publishing.md)、[产品技术方案](./Ecosystem-Events-Content-Plan.md)、
> [工程任务矩阵](./Ecosystem-Engineering-Backlog.md)、[执行路线图](./Execution-Roadmap.md)。

---

## 0. 结论先行

G2 不能一次做成“大而全”的报名后台。按健康风险拆成 5 批：

| 批次 | 工作包 | 交付物 | 是否可在本机独立库验收 |
|---|---|---|---|
| **G2-A 契约与底座** | G2-01、G2-02、G2-14 | scope/attempt/身份/幂等/人员范围 schema 与服务 | 是 |
| **G2-B 报名主链路** | G2-03、G2-04、G2-06、G2-07 | 提交、幂等、自动/人工确认、审核、取消/重报 | 是（含真实两连接并发） |
| **G2-C 运营闭环** | G2-08、G2-09、G2-11、G2-13 | 改期/取消/调额、凭证/签到、PII、专用导出 | 是 |
| **G2-D 客户端与消息** | G2-05、G2-10、G2-12 | 报名表/登录续办/我的报名、站内消息扇出 | 是（H5 实测；真机另列） |
| **G2-E 演练与收口** | G2-15、Q-02、Q-03 | 跨角色演练、并发/worker/浏览器证据、启停与回滚 | 是 |

**上线闸门不变**：

```
ecosystem.events.registration_enabled = true
ecosystem.events.registration_write_verified = true
scope.status = open
scope.policy_confirmed_at != null
now ∈ [registration_opens_at, registration_closes_at]
```

任一不满足即拒绝写入。G2 代码合入**不等于**生产报名已开放；生产必须逐活动执行
`activate`，并保留一键停写。

---

## 1. 当前事实与 G2 缺口

### 1.1 已有

- `events` 的发布/处置/举办三轴、revision、规则版本、双开关停写（G0/G1）。
- 公开活动/资讯 API、DTO 白名单、撤稿 410、kill switch 503。
- `event_registrations` 仅 6 个业务列（`event_id/customer_id/name/phone/city/note`），
  旧报名是 `exists + create`，无事务、无唯一约束、无容量、无状态。
- `PublicRegistrationState` 目前恒返回 `can_register=false`。
- outbox 只登记 `event/content` 的发布/暂缓/恢复/撤稿事件。
- Admin 旧报名列表只读，PII 只有“超管明文 / custom 遮罩”两档。

### 1.2 G2 必须解决的真实问题

1. **身份**：不能凭手机号自动认领历史报名；新报名只认 Sanctum 登录客户。
2. **名额**：`confirmed_count` 必须在同一事务内随状态变化；待审不占位。
3. **幂等**：超时重试不能产生第二条报名；同 key 不同载荷必须 409。
4. **并发**：最后一名额、审核与取消、降容量与提交必须同一锁序。
5. **旧轮次隔离**：取消/重报后旧请求、旧凭证、旧消息不得作用新轮次。
6. **PII**：联系人加密、默认遮罩、解密/导出分权审计。
7. **范围**：有全局权限但无本活动授权的人不能看/审/导出/签到。
8. **诚实**：未接短信/微信/支付时，界面不得显示其成功。

---

## 2. 政策冻结（工程默认值，待业务签署）

> 这些是 G2-01 的**可执行默认值**。代码按此实现，但 `policy_confirmed_at` 为空时
> 活动不能 `open`。业务改口径只改配置/字段，不改锁协议。

| 编号 | 决策 | 默认值 | 未确认时的行为 |
|---|---|---|---|
| P-01 | 报名身份 | 仅登录客户本人；`subject_key = c:{customer_id}` 由服务端生成 | 匿名只返回 `requires_login`，不收联系人 |
| P-02 | 历史匿名 | `identity_kind=legacy`，`scope_id/subject_key` 为空；不自动认领 | 不得按手机号合并/认领 |
| P-03 | scope | 首期每活动一个 `scope_key=default` | 无 open scope 即不可报名 |
| P-04 | 计量单位 | `competition→team`，其余 `→person`；首次报名后不可改 | 不一致拒绝 |
| P-05 | 容量 | `null` 不限、`0` 关闭、`>0` 有限；激活时必须显式确认 | 未确认容量不可 open |
| P-06 | 审核方式 | `auto` 提交即确认占位；`manual` 提交为 `submitted` 不占位 | 未配置不可 open |
| P-07 | 时间窗 | `registration_opens_at` 可空=立即；`registration_closes_at` 激活必填；`manual` 的 `review_closes_at` 必填且不早于报名截止 | 缺窗口拒绝 open |
| P-08 | 审核截止后 | 不再批准；到期扫描把 `submitted` 置 `rejected`（`review_expired`），不释放不存在的席位 | 不把待审伪装成确认 |
| P-09 | 取消 | 客户仅在报名截止前且未签到时可自助取消；已确认释放一次；已签到只能运营纠正 | 默认 `cancel_policy=before_close` |
| P-10 | 重报 | 终态 `canceled/rejected` 可新建 attempt；`max_attempts` 可配（默认 3），`null` 不限 | 超过上限稳定拒绝 |
| P-11 | 活动取消 | 立即关闭报名；活动内有效 attempt 置 `canceled`（`event_canceled`）；已占席位释放 | 通知异步，不阻塞停写 |
| P-12 | 改期 | 保留有效 attempt；是否需确认由 scope 的 `change_ack_required` 决定 | 无 ack 政策不得发布需确认变更 |
| P-13 | 变更确认 | 拒绝按 `decline_releases_seat`；逾期按 `no_response_action` | 政策未配置不得发布该变更 |
| P-14 | 凭证 | 32 字节随机 token，仅存 sha256；绑定 attempt，取消/重报/重发即失效；重复签到幂等 | 离线只显示“未核验” |
| P-15 | PII | `name/phone/note` 密文入库；`phone_hash` 用独立 HMAC 键；默认遮罩 | 无 `pii` 能力 + 范围不解密 |
| P-16 | 导出 | CSV 公式中和；默认遮罩；原始值需 `pii + export`；文件 10 分钟过期且受权限复核 | 无能力不生成文件 |
| P-17 | 人员范围 | 全局 ACL **且** `event_staff_assignments` 有效能力；超管可越过范围但必须审计 | 有能力无范围仍 403 |
| P-18 | 消息 | 只发站内信；`sent` 仅代表 outbox 已消费，不代表用户已读/已收到 | 不显示短信/微信成功 |

**待业务签署项**：P-07 具体截止时间、P-10 重报上限、P-12/P-13 变更政策、P-15/P-16
留存与导出范围、B-06 取消/改期责任。签署前只允许测试活动 `open`。

---

## 3. 数据模型

### 3.1 新增表

#### `event_registration_scopes`

| 字段 | 类型 | 说明 |
|---|---|---|
| id | BIGINT PK | |
| event_id | BIGINT UNSIGNED | FK `events.id`，`uk(event_id, scope_key)` |
| scope_key | VARCHAR(50) | 首期 `default` |
| unit | VARCHAR(10) | `team/person` |
| capacity | INT UNSIGNED NULL | null 不限 / 0 关闭 / >0 有限 |
| capacity_confirmed_at | DATETIME NULL | 激活前置 |
| confirmed_count | INT UNSIGNED | 只计当前 `confirmed/checked_in` |
| review_mode | VARCHAR(10) | `auto/manual` |
| registration_opens_at / registration_closes_at / review_closes_at | DATETIME NULL | UTC |
| cancel_policy | VARCHAR(20) | `before_close/never` |
| reapply_policy | VARCHAR(20) | `allowed/never` |
| max_attempts | TINYINT UNSIGNED NULL | null 不限 |
| change_ack_required | TINYINT(1) | 默认 0 |
| decline_releases_seat | TINYINT(1) | 默认 0 |
| no_response_action | VARCHAR(20) | `none/release` |
| form_version | INT UNSIGNED | 默认 1 |
| form_schema | JSON NULL | 字段定义（白名单） |
| privacy_version | VARCHAR(50) NULL | 同意文本版本 |
| privacy_summary | TEXT NULL | 公开同意摘要 |
| rule_version_id | BIGINT NULL | FK `event_rule_versions.id` |
| status | VARCHAR(20) | `draft/open/closed` |
| policy_confirmed_at / policy_confirmed_by | DATETIME / BIGINT NULL | 激活证据 |
| row_version | INT UNSIGNED | 乐观并发 |
| timestamps | | |

#### `event_registrations` 增量（保留旧行）

- `event_id` 由 `INT UNSIGNED` 改为 `BIGINT UNSIGNED`（先核对无越界）。
- 新增 `scope_id BIGINT NULL`、`subject_key VARCHAR(191) NULL`、
  `public_no VARCHAR(32) NULL UNIQUE`、`current_attempt_id BIGINT NULL`、
  `identity_kind VARCHAR(20) NOT NULL DEFAULT 'legacy'`、`row_version INT UNSIGNED DEFAULT 0`。
- `UNIQUE(scope_id, subject_key)`；历史行两列为 NULL，互不冲突。
- 不删除 `name/phone/city/note`（历史证据），新流程不再向这些列写明文。

#### `event_registration_attempts`

核心字段：`registration_id/event_id/scope_id/owner_customer_id/attempt_no/status/
review_mode/form_version/form_snapshot/rules_version_id/rules_fingerprint/
privacy_version/privacy_fingerprint/consent_accepted_at/
contact_name_enc/contact_phone_enc/contact_phone_hash/contact_phone_last4/
contact_city/note_enc/request_key/submitted_at/decided_*/canceled_*/checked_in_*/
row_version`。
- `UNIQUE(registration_id, attempt_no)`；索引 `(scope_id,status)`、`(event_id,status)`、
  `(owner_customer_id,status)`、`(contact_phone_hash)`。
- 状态：`submitted/confirmed/rejected/canceled/checked_in`。

#### `event_registration_requests`（幂等）

`actor_type/actor_id/action/request_key/payload_hash/target_type/target_id/
result_type/result_id/result_public_no/completed_at`。
`UNIQUE(actor_type, actor_id, action, request_key)`。

#### `event_staff_assignments`

`event_id/admin_id/capabilities(JSON)/valid_from/valid_until/granted_by/note`。
`UNIQUE(event_id, admin_id)`。能力词表：`review/cancel/pii/export/checkin/settings`。
“人员不能自增范围”：只有 `permission_type=all` 可写此表。

#### `event_checkin_tokens`

`event_id/registration_id/attempt_id/token_hash UNIQUE/issued_at/expires_at/
revoked_at/issued_by/issued_source`。原始 token 不落库。

#### `event_changes` / `event_change_acknowledgments`

`event_changes`：`event_id/change_version/type(reschedule|cancel|capacity|close|reopen)/
summary/details(JSON)/requires_ack/ack_deadline/decline_releases_seat/
no_response_action/status(draft|published)/created_by/published_at`。
`event_change_acknowledgments`：`change_id/attempt_id/registration_id/
response(accepted|declined|no_response)/responded_at`，`UNIQUE(change_id, attempt_id)`。

#### `ecosystem_export_files`

`token_hash/event_id/admin_id/filters(JSON)/fields(JSON)/file_path/expires_at/
downloaded_at/revoked_at`。文件在私有盘，10 分钟过期。

### 3.2 既有表增量

- `notification` 增加 `dedupe_key VARCHAR(191) NULL UNIQUE`：outbox 重投不重复发信。
- `event_registrations.customer_id` 保持原类型；新行由应用保证非空。

### 3.3 迁移顺序

1. `event_registration_scopes`
2. `event_registrations` 类型统一 + 增量列
3. `event_registration_attempts`
4. `event_registration_requests`
5. `event_staff_assignments`
6. `event_checkin_tokens`
7. `event_changes` / acks
8. `ecosystem_export_files`
9. `notification.dedupe_key`

全部 additive/expand；不做 `migrate:fresh`、不删旧列、不猜历史时间。

---

## 4. 一致性协议

### 4.1 锁顺序（所有写入口共用）

```
event → scope → registration → attempt → outbox
```

- 每次拿到锁后**重新读**当前时间、发布状态、scope 状态、容量和 attempt 状态；
  不用请求开始时的快照放行。
- 容量三态：`null` 不限、`0` 无名额、`>0` 有限；未知/未确认按拒绝处理。
- `confirmed_count` 只随 `confirmed/checked_in` 增减；每次变更后断言
  `0 <= confirmed_count <= capacity`（capacity 非空时）。
- 报名、取消、审核、调额、活动取消、签到全部走同一协议。

### 4.2 幂等

- 客户端为每个动作生成 `request_key`，重试沿用同一个 key。
- 已完成的 key + 相同载荷：返回原 `registration/attempt` 引用，并读取**当前**状态。
- 同 key + 不同载荷：409 `REGISTRATION_REQUEST_CONFLICT`。
- 同 key + 不同用户：独立作用域（唯一键含 actor）。
- 唯一约束兜底并发首次请求；捕获唯一冲突后回查并重放。

### 4.3 错误码（新增）

`REGISTRATION_DISABLED`、`REGISTRATION_NOT_OPEN`、`REGISTRATION_CLOSED`、
`REGISTRATION_FULL`、`REGISTRATION_ALREADY_ACTIVE`、`REGISTRATION_REQUEST_CONFLICT`、
`REGISTRATION_VERSION_STALE`、`REGISTRATION_TRANSITION_INVALID`、
`REGISTRATION_REVIEW_CLOSED`、`REGISTRATION_ATTEMPT_LIMIT`、
`REGISTRATION_NOT_FOUND`、`REGISTRATION_FORBIDDEN`、
`CHECKIN_TOKEN_INVALID`、`CHECKIN_NOT_ALLOWED`、`CHANGE_ACK_CLOSED`、
`EXPORT_EXPIRED`、`EXPORT_FORBIDDEN`。

全部登记进 `ApiErrorCode::all()`；控制器不写裸字面量。

---

## 5. 批次拆分

### G2-A 契约与底座（G2-01 / G2-02 / G2-14）

**交付**

1. 9 个增量迁移 + 模型（Scope、Attempt、Request、StaffAssignment、CheckinToken、
   Change、Ack、ExportFile）。
2. `RegistrationPolicy`：单位映射、激活校验、容量/时间判定、reason 词表。
3. `RegistrationScopeService`：create/update/activate/close/reopen/adjustCapacity；
   激活要求 `policy_confirmed_at`、容量确认、时间窗、`unit` 与 `kind` 一致。
4. `EventStaffScope`：`assertCapability(eventId, capability)`；超管绕过但审计；
   `grant/revoke` 仅超管。
5. Admin 活动配置页：scope 表单 + 人员分配页 + 路由/ACL 登记。
6. 迁移/服务/授权测试。

**健康门槛**

- 历史报名不丢、不被认领、不出现在新流程的“本人报名”里。
- `capacity=0` 不是不限；未确认容量不能 open；`unit` 与 `kind` 不符拒绝。
- custom 角色有全局权限但无 assignment：scope 配置/人员页/审核入口 403。
- 非超管不能给自己或他人加活动范围。

**回滚**：停止 G2-A 路由与开关；`migrate:rollback --step=9` 仅限已备份的隔离库；
root 先备份再执行。旧报名表列不删。

### G2-B 报名主链路（G2-03 / G2-04 / G2-06 / G2-07）

**交付**

1. `RegistrationContactVault`（G2-11 的加密能力先在此落地）。
2. `RegistrationIdempotency`。
3. `RegistrationSubmissionService`：submit / reapply。
4. `RegistrationReviewService`：approve / reject + 审核到期扫描命令。
5. `RegistrationCancellationService`：customer cancel / admin cancel。
6. 客户 API：
   - `POST /api/events/{id}/register`
   - `POST /api/customer/event-registrations/{public_no}/cancel`
   - `POST /api/customer/event-registrations/{public_no}/reapply`
   - `GET /api/customer/registration-requests/{request_key}`
7. `PublicRegistrationState` 扩为 G2 全量 reason/actions；公开详情路由挂
   `api.customer`（可选鉴权）。
8. 测试：幂等、并发最后名额、审核抢名额、取消只释放一次、旧轮次隔离。

**健康门槛**

- 同 key 重放不新增行；异载荷 409；失败回滚不占名额。
- 容量 1 两连接并发：只 1 个 confirmed，另一个稳定 4xx。
- 待审不占位；批准在事务取额；拒绝不释放不存在的席位。
- 取消重放不多释放；签到后自助取消拒绝；旧取消不影响新 attempt。
- 截止/关闭在等锁后重新判定，不按旧快照放行。

### G2-C 运营闭环（G2-08 / G2-09 / G2-11 / G2-13）

**交付**

1. `RegistrationChangeService`：活动取消、改期影响、调额、关闭/恢复；变更确认。
2. `RegistrationCredentialService`：凭证签发/轮换/签到；Admin 签到页。
3. `RegistrationContactVault` 完成解密授权、遮罩、审计；留存清理只做 dry-run。
4. `RegistrationExportService`：专用 CSV、公式中和、短期文件、权限撤回后拒绝。
5. Admin 报名工作台：范围内列表/详情/审核/取消/签到/导出。
6. 测试：取消活动立即停写、旧 token 拒绝、重复签到幂等、导出无公式、权限撤回。

**健康门槛**

- 活动取消后报名/签到入口立即拒绝，通知完成与否不影响停写。
- 改期不丢原快照；需确认的变更未配置政策不得发布。
- 旧 attempt 的 token/操作不影响新 attempt；QR 不含 PII。
- 日志/告警/导出无完整 PII；解密与导出各有审计。
- 通用 DataGrid export 旁路仍关闭。

### G2-D 客户端与消息（G2-05 / G2-10 / G2-12）

**交付**

1. `pages/ecosystem/event-register.vue`：登录续办、版本化表单、字段错误、
   提交中/超时查回、回执；不自动提交。
2. `pages/ecosystem/my-registrations.vue`：当前/历史轮次、取消/重报、
   变更确认、凭证。
3. 首页/详情/我的入口接入；切号清私有状态。
4. outbox 扩展 `registration.*`；通知扇出、去重、失败恢复。
5. 测试：前端 normalizer/错误映射/登录续办；outbox 重复投递不重复通知。

**健康门槛**

- 登录后重取版本；旧同意失效；不自动提交。
- 待审显示“待审核，不保证名额”；确认才显示席位。
- 跨账号无数据；刷新/跨设备一致。
- 未接短信/微信/支付不显示其成功。

### G2-E 演练与收口（G2-15 / Q-02 / Q-03）

**交付**

1. T31–T58 对照表；未覆盖项逐条列明。
2. 真实 MySQL 两连接/多进程锁等待证据；真实 worker 重复/租约/恢复证据。
3. 真实浏览器 Admin → 客户 → 签到演练（Playwright 或人工截图 + 命令记录）。
4. 启停/回滚手册；root 迁移与逐活动激活步骤（不默认全开）。
5. `G2-production-cutover-evidence.md`。

**退出条件**

- 报名不变式、并发、角色隔离、消息恢复、浏览器操作全部有证据。
- 任一 P0（越权 PII、重复名额、超额、错误归属、旧请求取消新轮次）未关闭，
  阻塞生产开启。

---

## 6. 代码落点

| 范围 | 路径 |
|---|---|
| 迁移 | `my-bagisto-store/packages/Webkul/Shop/src/Database/Migrations/2026_10_19_1xxxx_*.php` |
| 模型 | `Shop/src/Models/{EventRegistrationScope,EventRegistrationAttempt,EventRegistrationRequest,EventStaffAssignment,EventCheckinToken,EventChange,EventChangeAck,ExportFile}.php` |
| 服务 | `Shop/src/Services/Ecosystem/Registration*.php` |
| 客户 API | `Shop/src/Http/Controllers/API/EventRegistrationController.php`、`Routes/api.php` |
| Admin | `Admin/src/Http/Controllers/Catalog/Ecosystem/RegistrationController.php`、`Routes/catalog-routes.php`、`Resources/views/catalog/ecosystem-publishing/registrations/` |
| ACL | `config/ecosystem_admin.php`、`Admin/src/Config/acl.php`、`Admin/src/Config/menu.php` |
| outbox | `config/ecosystem_outbox.php`、`EcosystemOutboxProcessor.php` |
| 前端 | `pages/ecosystem/{event-register,my-registrations}.vue`、`utils/api.js`、`utils/ecosystem-public.js`、`pages.json` |
| 测试 | `Shop/tests/Feature/Ecosystem/Registration*G2Test.php`、`Admin/tests/Feature/Ecosystem/Registration*G2Test.php` |
| 证据 | `docs/ecosystem/G2-*-evidence.md` |

---

## 7. 测试与验证纪律

- 后端测试必须指向独立库 `summon_mecha_step10_test`；`DatabaseTransactions` 回滚。
- 禁止对 root 执行 `migrate:fresh` / `truncate` / `seed`。
- 每个批次至少：
  1. `php -l` 所有改动文件；
  2. `artisan route:list` 新路由；
  3. 指定测试文件真实输出；
  4. `git diff --check`；
  5. G1 回归不降级。
- 并发测试必须用**真实两个连接/进程**，不能只在一个事务里模拟。
- 浏览器证据必须来自真实服务与真实数据；无真机/微信环境时如实写“未验证”。

---

## 8. 风险登记

| 风险 | 影响 | 控制 |
|---|---|---|
| 业务政策未签 | 报名开放后责任不清 | `policy_confirmed_at` 闸门；签署前仅测试活动 |
| MySQL 5.7 无 CHECK/SKIP LOCKED | 约束靠应用层 | 唯一索引 + 应用断言 + 真实并发测试 |
| 容量计数漂移 | 超额/负数 | 同事务增减 + 对账命令 + 断言 |
| PII 密钥丢失/轮换 | 无法解密历史 | 独立 HMAC 键；轮换演练；备份 |
| outbox 重投重复通知 | 用户重复收到 | `notification.dedupe_key` 唯一 |
| 活动取消量大 | 长事务/锁表 | 分块处理；先置活动 canceled 使入口立即停写 |
| 旧客户端直写 | 绕过新协议 | G0 双闸仍生效；旧 POST 不升级为 G2 |
| 前端草稿带 PII | 本地泄露 | 只持久化非敏感选项与 request_key |

---

## 9. 回滚与停写

1. 将 `ECOSYSTEM_EVENT_REGISTRATION_ENABLED` 与
   `ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED` 置 false → 所有写入口 403。
2. 将目标 scope 置 `closed`。
3. 停止 `ecosystem:expire-registration-reviews` 与报名消息 worker。
4. 代码回滚到 G2 前提交；数据库保留新增表与列（additive），不删除已产生的报名事实。
5. 有新报名后禁止整体回切到只认识 `name/phone` 的旧代码；优先停写 + 前向修复。

---

## 10. 下一步会话提示

- 每个批次单独开工，先读本文件对应章节 + ADR §4.12/G1-D04 + 上批 evidence。
- 开工前 `git status` 确认没有 `my-bagisto-store2 备份/` 的误删；**禁止 `git add -A`**。
- 完成后把真实测试摘要、未做项、风险写进 `docs/ecosystem/G2-<批>-evidence.md`，
  再进入下一批。
