# SC3 完整集成验证与目标环境交接

日期：2026-10-21。配套实现及逐包清单见 [SC3 收尾账本](SC3-Closeout-20261021.md)。本工具补齐完整 Laravel/Bagisto、EAV、Admin session/CSRF、Sanctum HTTP、场景消费者故障恢复及真实编译 H5 的本机验证，不连接现有业务库。

## 一条命令复跑

准备已安装的 PHP、Composer 依赖、Node、MySQL `mysqld`、HBuilderX Vue3 编译器、Chrome 与现有 UI 测试依赖。PHP 需要 PDO MySQL、intl 及项目 Composer 要求的扩展。本机验证 PHP 8.3.4、Node 18.20.8、MySQL 5.7.30；完整前端编译器版本见构建证据。

先在仓库根目录生成当前前端构建：

```powershell
node tools/tests/scene-uni-build.mjs 'D:\工具\HBuilderX.5.07.2026041006\HBuilderX'
```

命令输出 `.claude/sc3-builds/<时间戳>`。将下方 H5 路径替换为本次实际输出，再执行：

```powershell
node tools/tests/scene-sc3-integration.mjs `
  --mysql-bin 'D:\UPUPW_ANK_W64\Modules\MySQL\bin\mysqld.exe' `
  --h5-build '.claude/sc3-builds/<时间戳>/h5' `
  --evidence-dir 'docs/scenes/sc3-closeout-evidence/integration'
```

可用 `--php-bin` 指定 PHP 可执行文件；浏览器依赖沿用 `tools/tests/scene-workflow-ui.mjs` 的 `SCENE_QA_DIR`，默认 `.claude/backup/scene-sc0-20261020/qa` 中的 `playwright-core`。本机 Chrome 路径为 `C:/Program Files/Google/Chrome/Application/chrome.exe`。运行器不会安装/升级 IDE 或依赖，也不发布网站。

它会验证前端构建成功标记及所有输入哈希，旧构建不能冒充当前源码。MySQL 必须使用独立初始化能力；此入口不接收已有数据库地址、用户名或业务密码。验证失败退出非零，保留具体阶段日志。Laravel 某些异常可能让 PHP 进程返回 0，所以运行器还核对每个阶段的独立成功回执，不单凭退出码通过。

## 实际运行方式

1. 创建随机 `.claude/runtime/sc3-http-<20hex>`，写本次所有权标记；新建 datadir 并以 `--no-defaults` 启动仅绑定 loopback 的 MySQL，使用随机高端口。只在该实例新建 `summon_mecha_scenes_test`，另存数据库所有权标记。
2. 每个 PHP 入口在 bootstrap 前校验 APP_ENV、路径、端口、`@@datadir` 和库内标记。应用加载真实 `bootstrap/app.php` 及全部 Provider；环境目录、配置/包缓存、日志、会话、私有附件均指向本次运行目录，不读取业务 `.env`。
3. Laravel Migrator 执行所有注册迁移并核对迁移记录；实际 Installer 基础数据、ProductRepository、EAV 属性及 FlatIndexer 建立明确标为 QA 的商品。正式资源/商务动作仍走原服务，不直接拼 accepted/confirmed 状态。
4. 启动真实 PHP HTTP 服务，用真实 Cookie 登录不同 Admin，验证 CSRF 及逐动作 ACL。资源、SKU 和商务登记/复核由两名不同管理员完成；客户使用真实 Sanctum token 接受报价并转换，核对原键/跨键、跨主体、停写回执和完整后台页面。
5. 场景消费者独立进程实际执行注册命令。通过数据库触发器制造通知失败，再用 MySQL 命名锁建立投影屏障，终止自己启动的 worker；验证租约和事务事实。等待真实租约到期后，新进程恢复，只产生一份通知和 receipt。不是通过改系统时钟或手改到期时间通过。
6. 检查实际生产注册的分钟级 Scheduler 事件及开关、防重叠约束。调度只将 Artisan 入口指向受隔离守卫保护的 QA 入口，随后执行同一个真实 Console Kernel 和 `scenes:operations --consume=100 --sweep`；不会调用业务环境的 Artisan 或执行其他专项任务。
7. Chrome 加载实际 HBuilderX 编译 H5，API 只更换目的地址到本次隔离后端，响应不使用 mock。验证实际订单、项目、款项、资源、窄屏排版和切换账号。微信端保留完整编译证据，真机运行不能由 H5 替代。
8. 关闭自己启动的 HTTP 服务；再次校验 datadir 和库内标记，删除本次新库、正常关闭自己启动的 MySQL。清理错误会导致验证失败，不会扩大范围清库。运行目录保留以供审查，受 `.gitignore` 保护。

公开证据只复制脱敏检查、迁移记录、预检、截图和阶段退出码。不复制 Cookie、token、测试密码、APP_KEY、私有原件、完整进程环境或 datadir。测试中的 `fixture` 扫描器只证明扫描/原件链路，不作为生产杀毒扫描器。

已归档结果可用 `node tools/tests/scene-sc3-verify-evidence.mjs` 只读核对，检查原测试结果、完整构建与当前源码/文档/证据哈希是否一致。源文件变化后它会明确拒绝，不自动改成通过；需要重新执行受影响验证后才可用 `--write --build-dir <实际构建目录>` 整理新证据。

证据保留实际验证时的原始字节哈希，并附加 UTF-8 文本统一为 LF 后的哈希，以兼容 Git 拉取时的 CRLF/LF 转换；文本内容变化仍会拒绝，截图等二进制文件继续逐字节核验。日志保留原始工具输出，包括其空白字符。

## 目标环境执行顺序

本机集成通过不能证明另一台机器已部署。目标机器的地址/连接方式尚未提供时，不猜数据库身份或开启业务写入。收到目标信息后，先做只读核验实际 HEAD、运行目录、APP_ENV、数据库名称、迁移记录、路由、开关、worker 与 scheduler；不要求用户发送密码。

在目标独立测试环境合并本批代码，保持正式写开关关闭，按八份场景迁移顺序执行待迁移。运行 `php qa/scenes/preflight.php`，核对数据库实际身份及结构/路由；`--check-env-only` 不算正向预检。预检新增可选 `--environment-path=<目录>` 供隔离运行使用，该参数不能绕过 testing/专用测试库守卫，也不会自动迁移或 seed。

正式启用所需的真实供给来源/班次、商务主体及政策、扫描器、收款凭证和运营签收必须来自实际负责人。确认页只区分已接受、已占用、商务确认、实际收款；测试合同不转成正式事实。只有实际环境验收通过后，才按批准结果设置 `SCENE_ORDER_CONVERSION_ENABLED` 及相关开关。

目标环境常驻进程按其既有运维方式承接 `schedule:work` 或系统定时器及相关队列服务；本机有限时间调度演练不代表生产服务已经常驻。`scenes:operations` 默认只读；消费/扫描显式使用 `--consume=100 --sweep`，投影失败现在返回非零，可被运行监控捕获。

停写关闭转换与预留等写开关，保留历史读取、幂等回执和所有事实。禁止 down/truncate 或把 confirmed 当 held 释放；通知重试只重放未完成事件，不重新建订单。
