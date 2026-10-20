# 第 18 步：SC1 / SC2 开发收尾与独立环境验收交接

日期：2026-10-21。基线 `a9e5192b5ea043807dc88c3facdd979311ad5e9c`，分支 `codex/scene-workflow-sc0-sc5`。用户授权补齐缺失运营规则，实际签约主体、服务联系渠道、责任人员、客户授权及真实供给证据不代填。

本轮完成下列代码和本机隔离验证；SC1 / SC2 整阶段签收仍待真实 MySQL、新版本 Admin 会话及 H5/小程序、真实扫描器和运营复核。不能把隔离验证或远端旧代码的 GET 成功写成这些门槛已通过。实现和自检为本次 Codex，独立人员复核尚未完成。

## 改动与实际行为

| 范围 | 实现 |
|---|---|
| 发布 | draft 按 row_version 编辑、不可覆盖送审版、复制新版、差异查看；原编制人和最后编辑人不能自审；批准/发布核对指纹，旧发布事件不恢复指针 |
| 公开依据 | 案例/量化样本、单位、观察范围、来源哈希和授权索引；独立批准和撤销/到期，客户仅见公开摘要与边界，明确不保证效果 |
| 规则 | commercial / retention / service_calendar 版本与唯一生效指针；草案、独立批准、退回、撤销；撤销不复活旧版，真实主体和负责人缺失时阻断批准 |
| 日历/受理 | 工作周、午休、休息日、特殊工作日、时区、工作分钟；DST 异常拒绝；冻结时钟、转派不重置、升级待办和去重站内信。人员停用仍收需求并分派升级；自动消息不等于实际联系 |
| 附件 | 扫描在事务外，提交/审计/outbox/幂等在事务内；原回执恢复、客户端选择/上传/下载、按主体分桶。扫描器或批准保留政策缺失时阻断，不制造 clean |
| 保留/保全 | 到期停止普通读取；保全及独立解除，另设 preserve_read 按用途留痕读取；活跃需求/项目、争议、工单及待复核验收阻断清理。先提交删除意图，再处理文件与最终审计，中断可重入，保留引用/hash/审计 |
| 专业字段 | 九类 v2 schema、choice / decimal / integer / boolean、单位、未知值和条件不适用；v1 显式升级追加修订，不覆盖旧历史 |
| 勘察/评估 | 私有原件、观察时间、逐项来源及核实字段；客户自报不能当现场核验；硬失败优先、未知待核实。绑定、归属、机型、固件/SDK、任务边界及最新勘察/评估变化均须重评 |
| 技能 | 复用真实 CompatibilityService / SkillDeliveryAssetResolver / SkillMarketService / KillSwitchService；核对文件字节、版本、登记、授权范围、声明及证据指纹。manual 通道不单独证明任务能力；熔断/下架后旧报价不能继续批准/发送/接受 |
| 实例方案 | 独立 proposal 版本、范围/不含内容、BOM 及验收；数量/单位/必选/选配、依赖/环、互斥、等效替代。跨组件台数求和，SKU 不冒充实物；技能数量沿用既有 license_mode |
| 报价/客户 | 仅引用已独立批准方案；费用编码/类型/商品/数量必须匹配。客户查看范围、排除项、组件、验收和版本差异；原接受/拒绝与未知回执恢复保持回归 |
| AI | Summon* 提供 scene_draft，新增本地结构化草稿 API 和同一受理入口；原文、建议与确认项分别留存；建议须确认，仍是客户输入，不是任务证据。模型价格、承诺及虚构 ID 不进入正式事实；失败可人工填写 |

修复完整链路测试发现的 BOM 校验错误：Laravel 的嵌套 wildcard distinct 会把不同组件共同依赖 robot 误判重复，改为组件内部去重；仍拒绝环和未知依赖。转派仅改变负责人，不使已评估/已接受状态倒退为 triaged。

## 数据、路由与权限

增量迁移：`2026_10_21_100006_complete_scene_intake_and_publication.php`、`2026_10_21_100007_complete_scene_assessment_and_proposals.php`，接在 `2026_10_20_100001` 至 `100005` 后。新增政策、公开依据、响应时钟、保全、勘察与方案表；原表只加 nullable 快照/引用和版本列。没有清库、种子或猜测回填。down 拒绝删除事实。

客户新增 `POST /api/scene-workflow/ai-draft`、`GET /api/scene-requests/{id}/attachment-receipt`；上传须带原 `Idempotency-Key`。schema 参数名改为 `{sceneCategory}`，URL 不变，包含另一端 `f1e1ee2` 修复。

后台新增配置、证据、版本编辑/对比、勘察、方案审核、保全和待办转派表单及路由；权限映射覆盖所有真实控制器动作。policy_write / policy_review、evidence_review、supply_review、solution_review、preserve / preserve_read 分权，基本查看不能执行这些写入或受限读取。原 requests、assess、quote、approve 等权限继续适用。

`SkillMarketService::visibleRows` 增加可选明确渠道参数，默认调用方式不变；场景复用原可售判定，不另建一套技能货架。未修改 D1–D11、设备所有权、技能授权或下发逻辑。SC3 订单转换继续硬阻断，SC4 取消/变更仍仅受理申请。

## 验证证据

本机不加载业务 .env、不连接应用 MySQL。内存测试运行真实场景增量迁移、既有技能/通知迁移和服务，使用独立临时私有文件；扫描夹具只模拟可控故障，不代表生产杀毒扫描验收。

| 命令 | 结果与限制 |
|---|---|
| `php -d extension=pdo_sqlite tools/tests/scene-workflow.php` | 91/91；包含日历/DST、审核分权、绑定/固件/技能字节/熔断、完整 BOM→报价、原件篡改/回执/保全/中断清理、强审计回滚、真实 Blade 表单渲染；不证明 MySQL 锁语义 |
| `php tools/tests/scene-controller-runtime.php` | 16/16；真实继承和 Router 分发，无业务数据库，不等于全应用 Admin 会话 |
| `node --test tools/tests/scene-request.test.mjs tools/tests/scene-catalog.test.mjs tools/tests/scene-preflight.test.mjs` | 32/32；主体切换、上传原回执、H5 Blob 下载/微信文件选择协议、专业字段、旧目录和预检 guard |
| `node tools/tests/scene-workflow-ui.mjs` | 14 SFC、17 项 Chrome 交互、0 pageErrors；实际挂载专业表单、附件组件、报价方案；390px 截图已查看，非 HBuilderX/小程序编译 |
| `php tools/tests/scene-blade-compile.php` | 8 模板编译，产物再做 PHP 语法检查；无 Bagisto layout 渲染 |
| 精确修改路径 PHP/Pint 检查 | 45 PHP 文件语法通过；最终结果见证据，不格式化备份或 vendor |
| `node tools/tests/scene-http-readonly.mjs http://192.168.3.97:8010` | 20 通过、详情因目录空跳过；旧运行版本的 GET only，未写库，不验证本轮新增业务链 |

机器可读记录在 `docs/scenes/sc1-sc2-closeout-evidence/`。截图只留本机，不纳入 Git。首次失败的浏览器 tap 触发方式、BOM distinct 和 Blade 指令连写问题已纠正并重跑；Blade 脚本现会实际校验全部产物语法，错误退出非零。不把失败记录隐藏成未执行。

规则草案参考[《民法典》合同与租赁规则](https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/bgt/art/2023/art_60fb3863b7364b759123a42d1df568ca.html)及[《个人信息保护法》必要范围、期限和删除规则](https://www.cac.gov.cn/2021-08/20/c_1631050028355286.htm)。商务取消/损坏按实际履行、费用依据与责任核定；180 天为待审核运营建议，不冒充法定期限或已批准协议。必要争议保全按单独用途及权限处理。

跨端文件处理按 [uni-app 文件选择](https://uniapp.dcloud.net.cn/api/media/file.html)和[文档打开支持表](https://uniapp.dcloud.net.cn/api/file/file)区分：微信使用 wx.chooseMessageFile；H5 chooseFile 后缀带点、不使用 file 类型。H5 PDF 从带鉴权请求得到的 Blob 发起浏览器下载，不调用 H5 不支持的 openDocument，也不把 token 放到直链。

## 另一端交接与剩余验收

已 fetch 对方 `8c7d660` 并读取交接：其报告 8010 已切至 `APP_ENV=testing` / `summon_mecha_scenes_test`，只执行到 100004。本机重新 GET 未签发凭证已返回 `401 SCENE_ACCESS_INVALID`，旧缺表导致的 500 已恢复。数据库身份来自对方文档，本机没有 SSH/直接 MySQL 证据。

另一端须在保留未提交文件的隔离 checkout 合并本分支，先读取最新 `qa/scenes/README.md`。在专用测试库审查执行剩余 100005、100006、100007，清缓存重启，重新运行 preflight 和真实 Admin/客户链路。新增价目、责任人、授权原件只能取实际业务事实；不使用本机测试夹具充当正式供给。

仍待：MySQL 多连接并发/当前读/回滚；真实 Admin session/CSRF/完整 H5/小程序；真实安全扫描及设备文件预览；批准的主体/联系渠道、客服日历和运营规则、真实任务依据；生产 worker/scheduler 及通知值守。未宣称 SC1/SC2 整阶段关闭，亦未将 SC3–SC5 未完成项划入已完成。

## 回退

修改前备份在 `.claude/backup/scene-sc1-sc2-closeout-20261021/`。部署回退先停止本轮相关场景写入与场景消费者，记录原开关和未处理事件；保留历史查询和全部新表/事实。不要将不识别新事件的旧 worker 与新写入口混跑。

```powershell
git show --stat <本批提交 SHA>
git revert <本批提交 SHA>
```

在实际部署配置中关闭相应 SCENE_* 写开关后清缓存并按原服务方式重启；不更改其他业务的 worker。禁止 migrate:rollback/down、truncate、migrate:fresh 或以备份覆盖另一端工作区。已物理删除的到期文件不能靠代码 revert 恢复；应依授权备份和已留存审计处理，不能伪称文件删除支持事务回滚。
