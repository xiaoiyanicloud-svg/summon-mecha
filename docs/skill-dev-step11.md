# 技能搜索首项修复验收

日期：2026 年 10 月 18 日。实施卡：A1。仓库基线：`c4d2ead` 加原有工作区改动。本说明在仓库中保存为 `docs/skill-dev-step11.md`。

本项修复了技能搜索的供给资格与当前版本筛选。首页和机器页的默认技能列表还有独立数据入口与静态样例，本轮未修改，列为 A1b；不能据此宣称全部技能市场已完成优化。

## 改动清单

| 文件 | 变化 |
|---|---|
| `my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/SkillCatalogController.php` | 以分类子查询始终隔离技能商品；所有搜索要求存在已发布版本；按发布时间与记录 ID 选择当前发布；空数组通道沿用人工缺省；新增搜索参数校验 |
| `my-bagisto-store/packages/Webkul/Shop/tests/Feature/API/SkillCatalogIntegrityStep11Test.php` | 新增 15 条行为测试，覆盖分类缺失、其它分类隔离、没有发布、历史版本、时间与 ID 顺序、空值和异常参数 |
| `docs/skill-platform-research-2026-10-18.md` | 行业来源、机型候选、代码缺口、战略与分卡路线图 |
| `docs/skill-dev-step11.md` | 本验收与回滚说明 |

沿用 API 的 `data/query/filters` 信封和既有路由；没有新增数据库迁移，没有填充本地应用库，没有修改已有买断制、设备归属、审核发布与交付政策。没有提交 Git 或发布到生产。

## 为什么先做这项

本地应用库的技能分类与已发布技能版本均不存在。修改前，分类不存在会取消分类限制，使普通商品可能进入技能搜索；只要不带版本相关筛选，未发布商品也可能进入结果；带筛选时，旧发布版本又能使不满足条件的当前版本继续命中。

这些错误会让开发者、厂商和买家对“平台究竟有什么可交付供给”形成不同理解，因此先修搜索的基本资格，再建设适配证据与需求匹配。

## 实测证据

| 检查 | 结果 |
|---|---|
| 修改前既有 `SkillCatalogFilterB9Test.php` | 7 passed，43 assertions |
| 新增行为测试对修改前控制器 | 13 failed，2 passed；复现问题 |
| 最终新增测试与既有筛选、熔断组合 | **38 passed，214 assertions，4.42 秒** |
| PHP 语法 | `SkillCatalogController.php` 无语法错误 |
| 路由 | `api/skill-catalog` 的 5 条路由可查 |
| 迁移 | `artisan migrate:status` 可正常读取；本项不执行迁移 |
| 本地真实 HTTP | `GET http://127.0.0.1:8008/api/skill-catalog/search` 返回 200，正文 `{"data":[],"query":"","filters":[]}` |
| 差异格式 | `git diff --check` 对本次控制器无错误 |

测试运行于 `.env.testing` 指定的独立数据库 `summon_mecha_step10_test`，使用现有 `DatabaseTransactions` 回滚测试写入。新增测试还显式要求 testing 环境与测试库命名，防止误跑到本地应用库。

一次合并运行曾出现 3 条下载 404。原熔断套件分进程运行通过，新增与既有筛选合跑通过，新增与熔断合跑通过，最后原三文件完全相同组合也通过。原因未定位；这不是已经证明的环境问题，也不作为全量健康证明。保留为后续下载链路测试稳定性检查项。

最终测试命令：

```powershell
Set-Location -LiteralPath 'D:\CodexDeploy\summon-mecha\my-bagisto-store'
& 'D:\java\php\php.exe' -c 'D:\CodexDeploy\summon-mecha-runtime\php.ini' -d max_execution_time=0 vendor/bin/pest packages/Webkul/Shop/tests/Feature/API/SkillCatalogIntegrityStep11Test.php packages/Webkul/Shop/tests/Feature/API/SkillCatalogFilterB9Test.php packages/Webkul/Shop/tests/Feature/Deploy/KillSwitchSaleStopTest.php --compact
```

## 备份与回滚

控制器修改前已核对 SHA256：

```text
1EFB32A561F7FA1EF2BD658242E682E1E172DFA5514F8D88455440D7FD7DD106
```

备份为 `D:\CodexDeploy\summon-mecha\.claude\backup\skill-step11-20261018\SkillCatalogController.php`。应用前校验原文件未被其它工作改动；备份拒绝覆盖。原有工作区改动均保留。

以下命令只用于需要回退本项时。先检查目标文件之后是否有新改动；有新改动时按差异反向撤回本项，不能用备份覆盖他人后续工作。

```powershell
$catalogTarget = 'D:\CodexDeploy\summon-mecha\my-bagisto-store\packages\Webkul\Shop\src\Http\Controllers\API\SkillCatalogController.php'
$catalogBackup = 'D:\CodexDeploy\summon-mecha\.claude\backup\skill-step11-20261018\SkillCatalogController.php'
Copy-Item -LiteralPath $catalogBackup -Destination $catalogTarget
```

没有数据库回滚动作。若恢复旧行为，应同时撤回本项新增的行为测试，保留本轮研究与验收记录。不要使用 `git reset --hard`、`git clean` 或全仓覆盖。

## 未完成与下一项

- A1b：首页与机器页默认列表的供给资格尚未统一；样例需要明确标识并避免冒充可购买供给。
- 机型筛选仍只做交付通道初筛；真实适配、开发版本、固件、SDK 和产物证据尚未接线。
- 技能供需生命周期、开发者首次上架体验和 Agent 能力快照仍按路线图待实施。
- 本项没有全量回归、规模压测、真实支付、小程序验收或真机验证。
- 本地技能供给仍为空；没有为演示制造技能、开发者、订单或厂商合作数据。

每个后续功能继续遵循“当前官方资料复核 → 具体契约 → 实现 → 对应验收”，避免用文档或模拟结果替代真实交付。
