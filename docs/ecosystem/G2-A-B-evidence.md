# G2-A / G2-B 交付证据（本机独立测试库）

| 项 | 内容 |
|---|---|
| 日期 | 2026-10-19 |
| 分支 | `codex/ecosystem-g2-free-registration` |
| 范围 | G2-01、G2-02、G2-14；G2-03、G2-04、G2-06、G2-07 |
| 测试库 | `summon_mecha_step10_test`（`APP_ENV=testing`） |
| 生产开关 | `.env` 未改动；报名双闸仍关闭，root 未迁移 |
| 结论 | 底座与报名主链路已完成并在独立库验证；G2-C/D/E 未完成 |

## 1. 改动清单

### 迁移（additive/expand，9 个）

1. `2026_10_19_100001_create_event_registration_scopes_table.php`
2. `2026_10_19_100002_extend_event_registrations_for_g2.php`
3. `2026_10_19_100003_create_event_registration_attempts_table.php`
4. `2026_10_19_100004_create_event_registration_requests_table.php`
5. `2026_10_19_100005_create_event_staff_assignments_table.php`
6. `2026_10_19_100006_create_event_checkin_tokens_table.php`
7. `2026_10_19_100007_create_event_changes_tables.php`
8. `2026_10_19_100008_create_ecosystem_export_files_table.php`
9. `2026_10_19_100009_add_dedupe_key_to_notification.php`

- `event_registrations.event_id` 由 INT 统一为 BIGINT；历史行保留为
  `identity_kind=legacy`、`scope_id/subject_key=NULL`，不按手机号认领。
- 未删除任何旧列；未执行 `migrate:fresh` / `truncate` / `seed`。

### 模型

`EventRegistrationScope`、`EventRegistrationAttempt`、`EventRegistrationRequest`、
`EventStaffAssignment`、`EventCheckinToken`、`EventChange`、
`EventChangeAcknowledgment`、`EcosystemExportFile`；扩展 `Event`、`EventRegistration`。

### 服务

- `RegistrationPolicy`：reason 优先级、容量三态、时间窗、审核窗、写闸。
- `RegistrationScopeService`：创建/更新/激活/关闭/恢复/调容/对账。
- `RegistrationStaffScope`：全局 ACL + 活动范围；仅超管授予/撤销。
- `RegistrationIdempotency`：request_key、载荷指纹、重放与 409。
- `RegistrationContactVault`：Crypt 加密、独立 HMAC 查重、遮罩。
- `RegistrationSubmissionService`：提交/重报、锁序、容量、outbox、审计。
- `RegistrationReviewService`：批准/拒绝/审核到期扫描。
- `RegistrationCancellationService`：本人取消/运营纠正、只释放一次、吊销凭证。
- `PublicRegistrationState`：委托 `RegistrationPolicy`，G1 合同保持兼容。

### API / Admin

- `POST /api/events/{id}/register` 替换旧 exists+create 路径；必须登录、
  `request_key`、表单/隐私/规则版本。
- `GET /api/customer/event-registrations`、`/{public_no}`、
  `/{public_no}/cancel`、`/{public_no}/reapply`、
  `GET /api/customer/registration-requests/{request_key}`。
- 公开活动列表/详情挂可选鉴权 `api.customer`，本人状态可返回
  `requires_login` / `already_registered`。
- Admin：报名范围配置、人员范围、报名列表/详情/批准/拒绝/取消。
- ACL：`ecosystem.registrations.*` 权限 key 与路由登记；
  `permission_type=all` 超管越过活动范围但写审计。
- 命令：`ecosystem:expire-registration-reviews --dry-run|--limit`；
  调度器每分钟触发（受 `review_expiry.enabled` 控制）。

## 2. 测试真实摘要

```text
RegistrationSchemaG2Test                 3 passed
RegistrationScopeServiceG2Test           5 passed
RegistrationStaffScopeG2Test             4 passed
RegistrationSubmissionG2Test             11 passed (53 assertions)
PublicRegistrationApiG2Test              5 passed (35 assertions)
RegistrationAdminAclG2Test               2 passed (7 assertions)
EcosystemPublishingAclG1Test (回归)      7 passed (274 assertions)
PublicEventApiG1Test / Visibility / DTO  17 passed (192 assertions)
```

合计新增：**30 passed**；相关 G1 回归：**24 passed**。

覆盖的关键不变式：

- 容量 1 时第二个客户稳定 `REGISTRATION_FULL`，计数不超额。
- 人工待审不占位；批准在事务取额；拒绝不释放不存在的席位。
- 同 key 同载荷重放不新增；同 key 异载荷 409。
- 取消只释放一次；重报创建 `attempt_no=2`；旧 expected_attempt_id 不影响新轮次。
- 已签到不能自助取消；运营纠正可取消并释放一次。
- 联系人密文入库，旧 `event_registrations.name/phone` 不写明文。
- 有全局权限但无活动范围：403；过期范围失效；非超管不能授予范围。
- G0 双闸关闭时写入口仍 403，不产生 attempt。

## 3. 未完成 / 未验证

- **未做**：G2-05 前端报名页/我的报名页；G2-08 变更确认；G2-09 凭证签发与
  签到；G2-11 解密审计页面/PII 轮换/清理 dry-run；G2-12 outbox 消费与站内信；
  G2-13 专用导出；G2-15 浏览器演练。
- **未做**：真实两连接/多进程锁等待证据（Q-02）；真实 worker 重复投递/租约
  恢复证据（Q-03）；root 迁移；生产 `.env` 开关。
- **未验证**：H5/小程序界面、375/390px、键盘/大字号。
- 当前 `PublicRegistrationState` 在测试中使用 `config` 打开双闸；生产仍未开启。

## 4. 回滚

1. `ECOSYSTEM_EVENT_REGISTRATION_ENABLED=false`；
   `ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED=false`；
   `ECOSYSTEM_REGISTRATION_ENABLED=false`。
2. 目标 scope 置 `closed`。
3. 停止 `ecosystem:expire-registration-reviews` 调度。
4. 代码回滚到 G2 前提交；新增表/列保留（additive），不删除已产生的报名事实。
5. root 回滚只在备份恢复演练后执行，禁止直接 `migrate:rollback` 丢新报名。
