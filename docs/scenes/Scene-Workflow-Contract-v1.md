# 场景流程 v1 技术契约与开放边界

日期：2026-10-20。实现记录：[第 14 步](../skill-dev-step14.md)、[第 15 步](../skill-dev-step15.md)。该契约是本批代码的实际行为，不替代商业条款、真机能力或上线签收。

## 1 当前服务范围

当前只实现需要人工受理和核验的场景服务流程。需求、评估、报价接受、资源预留、项目状态、款项事实分别存在。配置中所有新功能开关默认关闭；订单转换是硬阻断，不能通过设置环境变量绕过。

机器人/服务/运输/人员/配件使用服务端版本化价目。技能组合报价明确拒绝，继续走既有技能交易与 D10 规则；没有新增技能授权或设备命令。资源只能引用 `device_binding`，不修改归属。未验证的机器人任务证据、未知现场条件和不支持的场景不会得到“可交付”结论。

本批现场规则仅是人工现场服务的保守基础规则，九类场景有版本化表单入口，不代表九类完整专业评估。医疗、仓储、工业、巡检等专用能力及安全规则尚未补齐。

## 2 对象与不变式

| 对象 | 写入与版本规则 |
|---|---|
| 场景 revision | 新建正文、表单、验收和履约模板快照；送审、独立批准、发布、撤下；批准内容没有原地编辑入口 |
| 发布指针 | `scenes.active_revision_id` 为当前公开来源；旧 DataGrid 停用同步撤销；重新启用不复活旧版本 |
| 游客能力 | 服务端 `random_bytes(32)` 签发，数据库只存 SHA-256；未签发、自选、过期或撤销的 token 不接受 |
| 需求 | `request_no` 唯一，原文上限 20000 字完整保存；联系信息单独加密，不拼到订单名称中 |
| 需求补充 | 校验 `row_version`，追加 revision；保留旧原文，失效旧评估、报价及 held 资源；已进入项目的修改走变更申请 |
| 认领 | 必须同时拥有登录身份和原游客能力；不按手机号匹配；认领后旧能力不能读取或重放该对象回执 |
| 评估 | 固定检查项逐项输出 pass/fail/unknown；失败优先；未知只到人工待核；冻结输入指纹与具体设备证据 |
| 报价 | 不接客户端单价；价目编码父行分配不可变版本；整数分计算，逐行折扣、税额半入；押金与收入分列 |
| 报价接受 | 必须当前所有者、当前内容 hash 和条款 hash；唯一接受事实，不代表资源或交付确认 |
| 报价退回/拒绝 | 审核人以原因退回，编制人不可自退；原版不可重审，须生成新版。客户只能拒绝 sent，核验 row_version/两 hash，唯一决策保留；accepted 需商务变更 |
| 客户报价历史/对比 | 只有实际发送过的 customer_visible 版本可读；作废草稿不公开。对比限同一需求的两个可见版本，金额差不跨币种；内部政策/成本/审核原因不进客户 DTO |
| 资源预留 | 真实逐台/逐人记录；资源父行按 ID 升序锁；半开区间计入缓冲；expired held 在查询时立即不占用 |
| 交付 | 从项目冻结的任务模板生成一次；依赖无环、检查及私有证据齐全；实际时间与录入时间分开 |
| 验收 | 逐项追加初验、失败及复验；失败产生整改待办；无回复不自动通过；验收不表示返库或退款 |
| 线下财务 | 原始凭证和独立复核分离；录入不置 paid；应退与实际退款分离，累计金额有上限 |
| 变更/取消 | 当前只受理申请，`effective=false`；不默改日期、金额、资源或退款 |
| 服务售后 | 项目所有者申请、授权客服实际处理；不替代技能售后或事故处置 |

状态命令在主库事务内写强审计及 `business_outbox`。审计失败整体回滚。事件仅携带对象 ID 和版本，不携带电话、地址、token、原文或附件路径。outbox 投影有租约、receipt 去重、有界重试和人工重放；旧发布事件不写发布指针。

## 3 API 与客户端恢复

公共读取：`GET /api/scene-workflow/capabilities`、`GET /api/scene-workflow/schemas/{category}`。游客签发：`POST /api/scene-workflow/access`。

需求：`/api/scene-requests` 列表/创建、`/{id}` 详情、`/{id}/supplements`、`/{id}/claim`、`/{id}/attachments`、`GET /{id}/quote-comparison?left_id=...&right_id=...`。报价：`POST /api/scene-quotes/{id}/accept`、`POST /{id}/reject`。项目：`GET /api/scene-projects/{id}`，`POST /{id}/{acceptance|change|cancel|after_sale}`。具体路由以源文件 `Shop/src/Routes/scene-workflow.php` 为准。

私有授权只通过 Bearer token 或 `X-Scene-Access` header，禁止在 URL 分享能力。创建、补充、接受和项目申请使用 `Idempotency-Key`；同主体、同操作、同键不同载荷返回 409。重放仍检查当前对象访问权限。

客户端在发送前保存原载荷、幂等键和凭证。超时标记“提交结果待确认”，不能标“未提交”或“已受理”；待确认载荷不可被新编辑覆盖，可用原内容重试获取同一回执。即便原场景已撤下，也保留重试回执入口。存储按账号分桶，响应到达时再次核验账号；账号变化不将旧回执写入新账号。

相同恢复规则覆盖需求补充、报价接受/拒绝、项目验收/变更/取消/售后。刷新后显示原载荷待确认入口，即便服务端状态已变化，也按原键恢复回执；等待期间不发送相冲突的新动作。明确 4xx 拒绝可修正内容，网络失败/5xx/不完整回执继续保留未知状态。

旧 `tob-order type=custom` 适配为需求，要求新凭证、幂等键和联系授权，不再生成零元订单。带场景/方案标识的直接 TOB 建单返回 `SCENE_QUOTE_REQUIRED`。完全移除场景标识的普通租买仍是既有普通交易路径；完整来源防绕过及场景 checkout 转换属于未完成范围。

## 4 权限与私有资料

Admin 基础查看为 `catalog.scene-workflow`，另有 contact、requests、assess、quote、approve、revision_write、revision_review、publish、supply、reserve、deliver、finance、finance_review、support、operate。每个真实路由均挂动作鉴权；动态批准、版本编制/审核/发布分别判定权限。编制人不能自审。

附件仅 PDF/PNG/JPEG、最多 8 MB，检查真实 MIME/文件内容，保存到 `storage/app/private/scenes`，不用公开盘。扫描器未配置或扫描失败时拒绝上传，不能手工将未扫描资料设为 clean。客户和 Admin 下载均核对父需求；Admin 读取客资和附件留强审计。平台代录明确记为 admin，不冒充客户确认。

尚未实现附件保全/到期物理清理工作流、客户跨端上传下载页面及真实扫描器集成验收；180 天是代码中的临时技术保留值，正式开放附件前需要确认保留政策。

## 5 时间、开关和部署

业务时间按 UTC 存入 datetime；现场区间要求 ISO 8601 秒级时间含 offset，且与服务地 IANA 时区匹配。不存在的夏令时时间拒绝；区间必须 ends > starts，最多 366 天。运维扫描不能把 confirmed 当 expired 释放。

按环境开放 `SCENE_PUBLICATION_V2`、`SCENE_REQUESTS_ENABLED`、`SCENE_QUOTES_ENABLED`、`SCENE_RESERVATIONS_ENABLED`、`SCENE_PROJECT_ACTIONS_ENABLED`、`SCENE_OPERATIONS_ENABLED`。报价另需明确配置 `scenes.terms` 和 `commercial_policy.required_price_codes`，缺失即拒绝，不默认免费或补商业责任条款。

`scenes:operations` 默认只读；`--consume=100 --sweep` 显式投影和扫描，需 operations 开关。scheduler 已注册每分钟执行，但本机和远端均未验证常驻进程、故障恢复或告警承接，不能因此宣称运营上线。

场景消费者、失败列表、重放和 outbox 统计限定 scene.*；消费者校验支持事件与 aggregate/schema/payload。未知场景事件进入失败重试，不作为无操作签收；其他业务事件不租赁、不重试。拒绝/退回生成重编待办，新版发送或需求变化后关闭；客户复验通过只关闭对应失败项的整改投影，原验收记录保留。

`qa/scenes/preflight.php` 是独立测试库只读预检，强制显式 APP_ENV=testing、DB_DATABASE=summon_mecha_scenes_test，并检查缓存与实际连接身份；不自动执行迁移或准备夹具。其 0 退出码只说明结构/路由就绪，不证明实际 MySQL 并发、监听器或业务验收通过。共享订单仓储已修复异常提交和递归重试，但场景订单转换仍硬阻断。

迁移只做 expand。迁移 `down()` 故意拒绝删除已有需求、报价和交付事实；停写回退保留新表和新数据。若远端生态专项已有同名 outbox/幂等实现，先对照合并，不得直接创建第二套或覆盖现有实现。
