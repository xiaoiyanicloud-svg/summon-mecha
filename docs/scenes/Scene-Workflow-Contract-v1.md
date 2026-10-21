# 场景流程 v1 技术契约与开放边界

更新：2026-10-21。实现记录：[第 14 步](../skill-dev-step14.md)、[第 15 步](../skill-dev-step15.md)、[第 17 步](../skill-dev-step17.md)、[SC3 收尾](SC3-Closeout-20261021.md)。该契约是本批代码的实际行为，不替代商业条款、真机能力或上线签收。

## 1 当前服务范围

当前支持人工受理和核验的场景服务流程。需求、评估、报价接受、资源预留、项目状态、款项事实分别存在。所有新功能开关默认关闭；SC3 已实现实际转单，须目标环境验收并显式开启转换及预留开关，不能借环境开关绕过业务事实校验。

机器人/服务/运输/人员/配件使用服务端版本化价目。SC1/SC2 收尾增量允许在独立批准 BOM 中列入已核验的具体技能版本：必须钉住绑定、登记、兼容性、真实产物字节和任务证据，重新核对当前渠道可售性及熔断。报价不会新增技能授权或设备命令，技能交易与 D10 规则继续沿用。资源只能引用 `device_binding`，不修改归属。未知现场条件和不支持的场景不能得到“可交付”结论。

SC1/SC2 新增九类 v2 专业字段、单位、枚举、分阶段条件规则和现场来源：载荷、净宽、坡度、节拍、楼层、人数及专项工况须逐项对照设备核验边界。诊疗、急救及独立照护仍超出当前服务范围；字段和规则齐备不表示已有对应机型、真机或行业资质。旧 v1 按旧版回显，客户显式升级才使用 v2。

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
| 线下验收 | 必须客户代表依据、实际时间、逐项结论和可读的私有原件；代录 pending_review 未生效，另人专权复核才追加结果；方法明确为 customer_offline_recorded；旧凭证不能覆盖新项目版本或倒盖最新验收 |
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

旧 `tob-order type=custom` 适配为需求，要求新凭证、幂等键和联系授权，不再生成零元订单。带场景/方案标识的直接 TOB 建单返回 `SCENE_QUOTE_REQUIRED`。删除全部场景元数据也不能直接购买已独立批准过的 service SKU（包括后来撤销的映射）；仓储 before-save 使用请求作用域服务端写入上下文校验，客户端不能伪造。未批准的映射草案不影响普通机器人租买，普通租买继续原交易路径。场景 checkout 引导至独立确认页；订单列表、详情和旧缓存关联到项目。

## 4 权限与私有资料

Admin 基础查看为 `catalog.scene-workflow`，另有 contact、requests、assess、quote、approve、revision_write、revision_review、publish、supply、reserve、deliver、finance、finance_review、support、operate。每个真实路由均挂动作鉴权；动态批准、版本编制/审核/发布分别判定权限。编制人不能自审。

新增 acceptance_record/acceptance_review 两项专用权限，代录不能自行复核，即使同人同时拥有两项权限。后台 projects/{id}/offline-acceptances 和 /{confirmationId}/review 需 session/CSRF、项目/凭证版本和幂等键；approve/reject 均留理由。确认人和代表资格加密存储，后台读取留审计；客户只读来源/时间/状态，不取得确认人、内部意见或原件私有路径。代录及复核都会核验原件实际内容；项目发生新操作后旧凭证只能退回重录。

附件仅 PDF/PNG/JPEG、最多 8 MB，检查真实 MIME/文件内容，保存到 `storage/app/private/scenes`，不用公开盘。扫描器未配置或扫描失败时拒绝上传，不能手工将未扫描资料设为 clean。客户和 Admin 下载均核对父需求；Admin 读取客资和附件留强审计。平台代录明确记为 admin，不冒充客户确认。

已实现私有上传幂等及原回执查询、客户上传下载、附件可用状态、版本化保留政策、保全/独立解除及用途受限读取。仅批准的 retention 政策可用于新上传；180 天仅为后台草案建议，不自动生效。到期停止普通下载，活跃需求/项目、争议、未结服务工单、待复核验收或保全均阻断清理。`scenes:attachments` 默认只读；显式指定单个 ID、`--apply` 和当前 `--policy-version` 才提交删除意图并处理私有原件，失败可重入；不删除引用、哈希和审计。真实扫描器与跨端真机仍需现场验收。

## 5 时间、开关和部署

业务时间按 UTC 存入 datetime；现场区间要求 ISO 8601 秒级时间含 offset，且与服务地 IANA 时区匹配。不存在的夏令时时间拒绝；区间必须 ends > starts，最多 366 天。运维扫描不能把 confirmed 当 expired 释放。

交付任务/返库/线下确认的实际时间也严格要求含 offset，不接受服务器本地无时区时间；前置任务之后才能记录后续发生时间，验收/返库不得早于已有交付事实。旧实际时间不猜测回填，客户当前在线复验保持可用。并行任务不使项目阶段倒退。损坏争议或晚归暂停本项目实物资源供给并为其他有效占用建影响待办，人员资源不因实物返库争议停用；保留已签资源和原先停用原因，不自动退款/关闭。

按环境开放 `SCENE_PUBLICATION_V2`、`SCENE_REQUESTS_ENABLED`、`SCENE_QUOTES_ENABLED`、`SCENE_RESERVATIONS_ENABLED`、`SCENE_PROJECT_ACTIONS_ENABLED`、`SCENE_OPERATIONS_ENABLED`。报价另需明确配置 `scenes.terms` 和 `commercial_policy.required_price_codes`，缺失即拒绝，不默认免费或补商业责任条款。

`scenes:operations` 默认只读；`--consume=100 --sweep` 显式投影和扫描，需 operations 开关，非法消费参数在扫描前拒绝，投影失败返回非零。scheduler 注册每分钟执行且防重叠；本机已实际验证消费者进程中断、真实租约恢复及调度子进程。生产常驻与告警承接仍须在目标机器验证，不能因此宣称运营上线。

场景消费者、失败列表、重放和 outbox 统计限定 scene.*；消费者校验支持事件与 aggregate/schema/payload。未知场景事件进入失败重试，不作为无操作签收；其他业务事件不租赁、不重试。拒绝/退回生成重编待办，新版发送或需求变化后关闭；客户复验通过只关闭对应失败项的整改投影，原验收记录保留。

`qa/scenes/preflight.php` 是独立测试库只读预检，强制显式 APP_ENV=testing、DB_DATABASE=summon_mecha_scenes_test，并检查缓存与实际连接身份；不自动执行迁移或准备夹具。其 0 退出码只说明八份迁移及结构/路由就绪，不证明业务验收。共享订单仓储修复和 SC3 转换已用本机独立 MySQL 验证；目标环境仍按 [QA 说明](../../my-bagisto-store/qa/scenes/README.md)验收后启用。

迁移只做 expand。迁移 `down()` 故意拒绝删除已有需求、报价和交付事实；停写回退保留新表和新数据。若远端生态专项已有同名 outbox/幂等实现，先对照合并，不得直接创建第二套或覆盖现有实现。

## 6 SC1 / SC2 收尾增量（2026-10-21）

新增迁移 `2026_10_21_100006`、`100007`，接在五份 10-20 迁移后；还未在远端 MySQL 执行。实现、证据及外部验收边界见[第 18 步](../skill-dev-step18.md)。

- 发布：draft 可以按 row_version 编辑；送审后须复制成新草稿。编制人及最后编辑人不能自行批准；版本指纹核对，最新批准版才能发布。后台支持差异查看。案例/指标须有私有来源、样本/单位、授权索引、观察范围与有效期，另人批准后才能被发布引用；撤销/到期立即影响公开读取，历史仍保留。
- 政策：commercial、retention、service_calendar 各自版本化，只有唯一批准指针生效。缺少实际签约主体/联系渠道、两名有效日历负责人不能批准。撤销当前政策不复活旧版。建议文本和日历仅在后台起草模板，不自动 seed 或放开入口。
- 日历：新受理冻结时区、工作周、日内区间、休息日、特殊工作日、首次联系及升级分钟和责任人；跨午休/节假日计算，DST 缺失/重复边界拒绝。转派不重置原截止时间；工作人员停用仍受理需求并升级分派；真实联系停止时钟，自动消息不能当作已联系。待办有 row_version 与转派依据；旧超期事件读取当前时钟，已联系后不补发。
- 勘察/评估：冻结原文对应修订、逐项观察值、来源及私有原件。客户自报与本次核验分开；硬失败优先于 unknown，明确不适用单列理由。新勘察、新评估使旧依据不再可用。正式报价各关键动作重新查真实文件、原件有效期、绑定归属/机型/固件、技能版本/资产哈希/兼容性与熔断。已有接受或项目时，不直接重评并覆盖承诺。
- 方案：独立于报价的不可变 proposal 版本，冻结当前评估、scope、exclusions、BOM 和验收。组件表达必选/选配、数量上下限、单位、依赖、互斥组及等效替代；同商品跨组件实物台数求和，技能数量按既有 license_mode 约束。审批人与编制人分离。报价只能引用已批准方案，费用编码、类型、商品与数量须一致；客户可查看范围、排除项、选配、数量与验收并比较版本。
- AI：`Summon*` 增加 `scene_draft`；`POST /api/scene-workflow/ai-draft` 生成同一受理入口的待确认草稿，不调用外部模型。原文和结构化建议分别保留，确认后仍只是客户输入；模型价格、能力承诺和未知 ID 不进入正式事实。断网可继续人工填写。

客户新增 `GET /api/scene-requests/{id}/attachment-receipt`（原幂等键置于 Header）；上传须 `Idempotency-Key`。所有访问能力只在 Header，不进 URL。schema 路由参数使用 `{sceneCategory}`，公开 URL 形状不变，避开 Bagisto 全局 `category` 绑定。

后台新增 policy_view / policy_write / policy_review、evidence_view / evidence_review、supply_review、solution_review、preserve / preserve_read 权限；勘察和编制实例方案复用 assess；待办转派复用 operate。具体路由及控制器动作映射以源码为准，未映射动作默认拒绝。报价仍须正式价目、税率及条款，不以草案建议补价格。

## 7 SC3 交易确认增量（2026-10-21）

- 第八份迁移为 `2026_10_21_100008_complete_scene_resources_and_transactions.php`，增加资源来源/班次/核验、报价确认期限、预留快照以及 SKU 映射、建单前商务、技能子单关联。只做 expand，down 拒绝删除事实。
- 正式报价新增 `confirmation={method,required_minor}` 和 `confirmation_deadline`。`offline_contract` 门槛为 0，`offline_receipt` 门槛须为正整数分且不超过应收；确认截止不得晚于服务开始。这些连同工作流版本进入冻结条款和 hash。缺少此条款的历史报价不能转换。
- `GET /api/scene-quotes/{id}/confirmation` 返回所属可见报价、当前 held 组/占用区间、最小商务复核摘要、开关及已有正式回执。停写后仍可查，不暴露凭证、人员来源、审核意见或私有路径。
- `POST /api/scene-quotes/{id}/convert` 要求所属登录客户、`Idempotency-Key`、`terms_hash`、`content_hash` 及存在实物/人员/配件时对应 `hold_group`。返回 `order_id`、`skill_order_id`（可空）、`project_id`、公开单号及独立商务/款项状态。相同报价跨键/并发仍同一订单与项目；原键不同载荷 409，丢响应保留原载荷恢复。
- 临时预留须精确满足冻结 BOM 的产品/绑定和台数/人数。核验人不同于登记人；服务城市、IANA 时区、有效期与可服务班次同时匹配。资源与报价取最大准备/行程/维护缓冲；expired held 即时不占，confirmed 不能按 hold 释放。资源变更或部分维修不静默替换已承诺资源。
- 价目版本×渠道映射由两人登记/批准。服务费为非库存 virtual SKU，技能为原商品独立子单。原件凭证须同需求、clean、未撤销/到期且实际字节哈希一致；登记不等于复核，客户截图不置 paid。
- 合同授信可形成 `commercial_state=confirmed,payment_state=unpaid`；部分/足额实际收款分别为 `offline_partial`、`offline_verified`。普通在线支付/回调返回 `409 SCENE_OFFLINE_ONLY`，普通取消要求项目变更，不直接释放资源或处理退款。
- 技能仅在提交后的独立 outbox 事务内按真实足额复核收款、当前版本和项目状态复用原授权；建单监听器不提前授权，服务费确认不等于技能已付，不发设备指令。
- `SCENE_ORDER_CONVERSION_ENABLED` 默认 false，转换还依赖预留开关；历史查询保留。新增后台动作复用 supply/supply_review、reserve、finance/finance_review 分权。实际本机 MySQL 40 项、浏览器与完整 H5/微信构建证据及部署边界见收尾账本。
