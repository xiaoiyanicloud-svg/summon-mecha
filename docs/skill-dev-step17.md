# 第 17 步：客户线下验收凭证、独立复核与履约状态约束

日期：2026-10-20。代码基线 `78b062950f88590285fb95383444e61bbdcb914b`，交接分支 `codex/scene-workflow-sc0-sc5`。本批是 SC4-01/02/04/05、SC3-04 与 SC5-02/03 的实现增量，SC1–SC5 仍未整体验收。未修改远端数据库、业务库 `root`、功能开关或正在运行的服务。

实现与自检：本次 Codex；独立人员复核、业务与运营签收：未完成。没有新增价格、取消或退款的隐含商业默认；关联提交可从本分支对此文件的提交历史取得，迁移影响见下文。

## 1 本批行为

此前客户只能在线逐项验收，平台没有代录客户真实线下确认的通道；交付任务按完成顺序直接覆盖项目阶段，存在并行分支使阶段倒退的可能；返库的损坏争议没有暂停 ready 资源，晚归时间比较还可能混用服务器本地时区。

现在：

- 新 `SceneAcceptanceService` 统一逐项验收判定。在线入口和幂等操作名保持兼容；初验、失败和复验继续追加，必需项未全部通过不进入 accepted。
- 平台可代录客户线下确认人、代表资格核验依据、确认方式、实际时间、逐项结论和私有原件。原件必填，代录产生 pending_review 和可定位的待办，不改变项目验收状态，不产生客户在线点击事实。
- 独立复核人须具有专用权限且不能是录入人。通过前重新核对项目版本、冻结验收模板及内容指纹、附件归属/扫描状态/到期时间/数据库哈希/实际文件哈希。拒绝保留原始记录和原因，不产生验收结果。通过后才追加 method=customer_offline_recorded 的逐项记录，并记录录入人和复核人。
- 客户在代录后在线确认、发起项目操作，或项目有其他版本变化，旧凭证无法通过；复核人仍可退回旧凭证后重录。实际确认早于任务或最近一次验收也不能覆盖最新结果。
- 线下代录及复核均按管理员、操作、幂等键和原始载荷保存回执。强审计失败会回滚凭证、逐项验收、项目版本、整改待办、复核待办、outbox 与幂等回执。
- 客户详情区分在线/线下来源、实际确认时间/平台记录时间及待复核/通过/退回；确认人和代表资格资料加密存储，只向对应权限的后台人员解密，并留读取审计。客户 DTO 不含原件路径、内部复核意见或管理员身份。
- 交付任务时间及返库时间必须包含 UTC offset，严格验证日期、非未来时间并转 UTC；任务不得早于前置任务，返库不得早于已发生交付任务。并行任务只推进项目阶段，不让阶段倒退。
- 实物资源晚归或损坏争议进入暂停/复核，并为其他仍有效的 held/confirmed 档期建立影响待办；不释放 confirmed、不替换已签设备、不伪造退款。人员资源不因实物返库争议被暂停；已停用资源保留原状态与原因。当前没有逐台损坏归因输入，存在项目级损坏争议时保守暂停本项目实物资源，后续需逐台复核。
- outbox 支持新增事件；投影读取当前凭证事实，旧代录事件重放不重开已复核待办，扫描可补回遗漏的待复核投影。

## 2 数据、路由和权限

新增迁移 `2026_10_20_100005_add_scene_offline_acceptance.php`，接在 100001–100004 之后。新增 `scene_offline_acceptances`，包含不可变原始内容、加密确认信息、内容/模板指纹、实际时间、录入/复核信息、独立 row_version；unique(project_id, project_version)。向 `scene_acceptance_records` 增加 nullable occurred_at 和 offline_acceptance_id 外键。

旧记录不猜测实际时间、不批量回填。旧在线记录缺少实际时间时，客户仍能提交当前在线复验；不能用倒签的线下材料覆盖实际时间不明的历史。旧代码曾接受无 offset 的交付时间，本批不猜测其原时区，开放前须审查存量时间数据。

后台 URI 以实际外层路由前缀为准，下表采用配置默认值 APP_ADMIN_URL=admin；未声称已核验远端运行值：

| Method + URI | 权限 | 必需控制字段 |
|---|---|---|
| POST /admin/catalog/scene-workflow/projects/{id}/offline-acceptances | catalog.scene-workflow.acceptance_record | row_version、Idempotency-Key 或表单 idempotency_key，以及客户原始确认信息 |
| POST /admin/catalog/scene-workflow/projects/{id}/offline-acceptances/{confirmationId}/review | catalog.scene-workflow.acceptance_review | row_version、confirmation_version、幂等键、decision=approve/reject、note |

仅查看、交付、财务或代录权限不包含独立复核。拥有两项权限的同一人也不能自行复核自己的记录。页面沿用后台 session/CSRF，未引入公开代验收接口。

项目客户 API 新增只读 offline_confirmations 和验收来源/实际时间字段，原 URI 不变。写入仍受 SCENE_PROJECT_ACTIONS_ENABLED 控制；缺少真实扫描器时不能通过制造 clean 记录开放此功能。只读 preflight 已增加新表/列/唯一约束及路由检查。

## 3 实测证据

本机没有运行应用 MySQL。测试不加载业务 .env，数据库固定 SQLite :memory:，线下原件写入独立临时私有目录并于结束后清理。原件夹具是测试输入，不代表真实扫描器或客户签署验收。

| 检查 | 命令 | 实际结果/边界 |
|---|---|---|
| 场景服务、迁移及 SQL 故障/权限检查 | `php -d extension=pdo_sqlite tools/tests/scene-workflow.php` | 61/61；包含原 40 项及新增 21 项，不证明 MySQL 并发 |
| 真实父类/控制器和 Router | `php tools/tests/scene-controller-runtime.php` | 15/15；现在同时实际装载并反射所有场景 Admin 路由动作；不加载业务 bootstrap/数据库 |
| 前端核心与预检环境 guard | `node --test tools/tests/scene-request.test.mjs tools/tests/scene-catalog.test.mjs tools/tests/scene-preflight.test.mjs` | 26/26；不代表远端 MySQL 正向 preflight |
| Vue 组件/浏览器 | `node tools/tests/scene-workflow-ui.mjs` | 12 SFC、14 交互检查、0 pageErrors；Chrome headless + 隔离 API 夹具，非 HBuilderX/小程序编译 |
| Admin 内容渲染 | 包含于新增服务测试 | 真实 Blade 指令/循环/表单/路由/转义与权限分支通过；未渲染 Bagisto layout 组件或启动真实 Admin 登录会话 |
| Blade 指令编译 | `php tools/tests/scene-blade-compile.php` | 2 模板；编译产物再做 PHP 语法检查 |
| PHP 语法与 Pint | 精确本批 PHP 路径 | 见 verification.json；不扫描备份或 vendor |

证据目录：[sc1-sc5-batch3-evidence](scenes/sc1-sc5-batch3-evidence)。新增故障检查包含：跨主体读取、权限直调、自审、重复提交、过期/缺失/篡改/跨需求原件、倒签、项目变更、部分必需项未确认、失败及复验、强审计在最终复核时失败的整笔回滚、旧事件重放、损坏影响其他有效档期且排除已过期 hold。客户窄屏截图已人工查看；截图仅留本机，不纳入 Git。

## 4 修改落点与合并顺序

- Shop：SceneAcceptanceService、SceneProjectService、SceneTime、SceneOperationsService 与上述迁移。
- Admin：SceneWorkflowController、AuthorizeSceneWorkflow、scene-workflow-routes、acl 和需求工作台 show.blade.php。
- 前端：pages/scene/project.vue。API URI 无变化，无新增 utils/api.js 写入口。
- QA：只读 preflight、真实控制器测试、场景回归和独立新增 case 文件、组件浏览器检查。
- 文档：本页、技术契约、任务矩阵、仓库交接及 qa/scenes/README；不覆盖另一台未提交的 EcoCenter、SkillMarket 或 mine 页面。

另一台须 fetch 本分支完整最新提交，先包含共享订单修复、第二批和 rejectQuote 修复，再在专用 summon_mecha_scenes_test 审查执行第五份增量迁移。迁移失败即保持写开关关闭，回传脱敏异常；不得对 root 或共享 step10_test 试错。补授新 ACL、清缓存重启后，用两个真实后台角色分别代录/复核，核验客户只读来源，再做真实 MySQL 锁/并发/故障检查。当前没有对方部署或读取确认。

## 5 回退

本机修改前文件在 `.claude/backup/scene-acceptance-20261020/restore-manifest.json` 及对应相对路径下。先关闭场景项目写入，并保持历史读取；同一代码版本运维扫描要配套部署，不能将旧消费者与新事件混跑。

在实际部署使用的环境配置中设置 `SCENE_PROJECT_ACTIONS_ENABLED=false`，清配置缓存并按实际服务方式重启；若需停止新增事件消费，再设 `SCENE_OPERATIONS_ENABLED=false` 并停止对应场景消费者。操作前记录当前开关值和未完成待办，不影响其他业务 worker。

需要代码回退时，先核对本批提交 SHA 与路径，使用可审查的 git revert；保留 100005 的表、列、凭证、复核、验收及已确认资源。禁止 migrate:rollback/down、清表或退回覆盖未知时区的旧写入。源文件备份仅用于本机对照，不直接覆盖另一台存在未提交改动的目录。

## 6 仍未完成

- 真实订单到项目的自动转换、完整 SKU/技能行映射、防绕过及 MySQL 多连接/监听器故障注入；转换仍硬阻断。
- 商务变更、取消的审批/客户接受/资源差量/退款联动；当前仍只受理申请，不默认为生效。
- 九类完整专业现场规则、技能资产/固件/熔断证据联动、事故全链、生产项目创建和自动结案。
- 附件保全、正式保留政策、实际扫描器集成和客户跨端上传下载；180 天技术保留值未作为业务批准政策。
- 真实 Admin 会话/H5/小程序/真机、远端第五份迁移与正向 preflight、生产 worker/scheduler/通知/SLA/容量及运营签收。本批未重新核验 8010 运行版本与数据库身份，不将旧烟测结果作为新版本证据。

这些缺口与本批隔离验证并列保留，不以新增表单、测试计数或 Git 推送替代阶段关闭。
