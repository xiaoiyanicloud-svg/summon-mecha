# AI 部署手册 · 召唤机甲（SUMMON MECHA）

> **读者**：拿到本仓库链接、需要把系统完整跑起来的 AI Agent（及其背后的人）。
> **目标**：从一台空机器开始，部署到「后端可访问 + 后台可登录 + 前端可运行」。
> **执行方式**：按 §1 → §7 顺序执行，每一步都有验收命令；失败时看 §9 排障。

---

## 0. 硬性前提（先确认，不满足就停下来问人）

| 项 | 要求 |
|---|---|
| 仓库权限 | 私有仓库，负责人必须先把你加入 collaborators 并接受邀请 |
| 网络 | 能访问 GitHub、Packagist（Composer 依赖源） |
| 磁盘 | 至少 2 GB 空闲（依赖 + 日志 + 上传） |
| 操作系统 | Windows 10/11 或 Linux |
| 权限 | 能创建数据库、写 `my-bagisto-store/storage` 与 `bootstrap/cache` |

**禁止**：把 `.env`、数据库导出、`storage/app/keys` 私钥提交进 git。仓库里这些内容本来就不存在，不要“补”进去。

---

## 1. 环境矩阵

| 依赖 | 版本 | 用途 |
|---|---|---|
| PHP | **8.2+**（8.4 实测通过） | 后端 |
| PHP 扩展 | `calendar` `curl` `intl` `mbstring` `openssl` `pdo` `pdo_mysql` `tokenizer` | 缺一不可 |
| Composer | 2.x | PHP 依赖 |
| MySQL | **5.7 / 8.x**（5.7.26 实测通过） | 数据库 |
| Node.js | 18+（可选） | 仅后台主题资源构建；仓库已带构建产物，一般不需要 |
| HBuilderX | 最新版 | 运行/发行 uni-app 前端 |
| 微信开发者工具 | 最新版（可选） | 仅调试小程序端 |

## 2. 拉代码

```bash
git clone https://github.com/xiaoiyanicloud-svg/summon-mecha.git
cd summon-mecha
```

## 3. 后端部署（`my-bagisto-store/`）

### 3.1 安装依赖

```bash
cd my-bagisto-store
composer install
```

> `composer.json` 用 path 仓库引用 `packages/*/*`，这些包都在仓库里，不依赖外部私有包。

### 3.2 创建环境文件

```bash
cp .env.example .env
```

Windows PowerShell：

```powershell
Copy-Item .env.example .env
```

**必须修改的变量**：

```dotenv
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=root
DB_USERNAME=你的数据库用户
DB_PASSWORD=你的数据库密码
```

> 库名 `root` 是本项目当前使用的名字（不常规但有效）。想改名就同时改 `DB_DATABASE`。
> `APP_TIMEZONE=Asia/Shanghai`、`APP_LOCALE=zh_CN`、`APP_CURRENCY=CNY` 已对齐。

生成应用密钥：

```bash
php artisan key:generate
```

### 3.3 建库

```sql
CREATE DATABASE root DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3.4 迁移 + 预置数据

```bash
php artisan migrate --seed --force

# 本项目业务预置数据（幂等，可重复执行）
php artisan db:seed --class=SceneSeeder
php artisan db:seed --class=DeviceModelSeeder
php artisan db:seed --class=SkillCatalogSeeder
```

### 3.5 文件链接

```bash
php artisan storage:link
```

> Windows 若报权限错误：以管理员身份运行终端，或在系统设置里开启「开发者模式」。

### 3.6 启动

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

- 前台：`http://<服务器IP>:8000`
- 后台：`http://<服务器IP>:8000/admin`
- 健康检查：`http://<服务器IP>:8000/up`

### 3.7 默认管理员（**部署后立即改掉**）

`migrate --seed` 会创建：

| 账号 | 密码 |
|---|---|
| `admin@example.com` | `admin123` |

登录后台 → 设置 → 用户管理修改，或命令行：

```bash
php artisan tinker --execute="\Webkul\User\Models\Admin::where('id',1)->update(['email'=>'你的邮箱','password'=>bcrypt('新密码')]);"
```

### 3.8 常驻进程（**不启动就会静默失效**）

试用回收、通知、下发状态机、灰度评估都依赖它们：

```bash
php artisan queue:work
php artisan schedule:work
```

Linux 用 supervisor/systemd 守护；Windows 用任务计划程序或 `nssm`。两条都要常驻。

### 3.9 签名密钥（首次部署）

```bash
php artisan agent:keys:generate
```

> 生成后立刻导出备份：`php artisan agent:keys:export --to=<安全目录>`。
> **禁止**随意执行 `agent:keys:import --replace`（等于轮换密钥，已部署 Agent 会全部验签失败）。

## 4. 前端部署（仓库根目录）

1. 修改 `utils/config.js` 里的后端地址：

```js
export const BASE_URL = 'http://<服务器IP或域名>:8000'
```

2. 用 **HBuilderX** 导入仓库根目录（`manifest.json` 已就位）。
3. 运行 / 发行：
   - **H5**：发行 → 网站-H5，产物在 `unpackage/dist/build/h5`
   - **微信小程序**：在 `manifest.json` 填 `mp-weixin.appid`，再运行到微信开发者工具；微信后台需配置 **HTTPS** request 合法域名
4. H5 产物用 Nginx 托管，并把 `/api` 反向代理到后端 8000 端口（或直接前后端同域）。

## 5. 数据库与上传文件（可选：恢复现有业务数据）

默认部署是**空库**（有表结构、基础配置、1 个管理员，没有商品和订单）。要 1:1 复制现有商城，让负责人另外私下提供两样东西：

| 文件 | 内容 | 导入方式 |
|---|---|---|
| `summon-mecha.sql` | MySQL 全量导出（约 9 MB） | `mysql -h127.0.0.1 -uroot -p root < summon-mecha.sql` |
| `storage-public.zip` | 商品/场景图片（约 37 MB） | 解压到 `my-bagisto-store/storage/app/public/` |

⚠️ 这两个文件含真实客户数据，**只私下传输，绝不提交 git**。

## 6. 外部依赖与降级表现

核心交易（商品、订单、租用、技能、后台、支付 mock）不需要外部服务。以下能力在缺少凭据时不可用：

| 能力 | 缺少的东西 | 表现 |
|---|---|---|
| 小召 AI 召唤 / 语音 | `COZE_BASE_URL` `COZE_PAT` `COZE_BOT_ID` + 独立部署的 `coze-studio` | AI 页面报错，其余正常 |
| 微信支付 | 商户号资质；`WechatGateway` 目前是抛异常的占位 | 支付走 `mock`（`config/payment_gateways.php`） |
| 微信内容安全 | appid + access_token（留位未实现） | 不启用 |
| 设备 MQTT 下发 | EMQX + Go 服务 | 默认走 HTTP 轮询（`config/deploy_gateways.php`） |
| 真机 / 厂商 SDK | 宇树、智元、众擎等 | 仅平台侧可用，真机联调不可用 |

## 7. 验收清单（全部通过才算部署完成）

```bash
# 1. 健康检查
curl -i http://127.0.0.1:8000/up
# 期望：HTTP 200

# 2. 迁移状态
php artisan migrate:status
# 期望：没有 Pending

# 3. 调度任务
php artisan schedule:list
# 期望：9 条任务

# 4. 队列积压
php artisan ops:alert-queue-backlog --dry-run
# 期望：能正常输出（不要求 0 积压，但要有 worker 消费）

# 5. 关键页面
# 前台  http://127.0.0.1:8000/          → 200
# 后台  http://127.0.0.1:8000/admin     → 登录页
# 登录  admin@example.com / admin123    → 能进（随后立即改密码）

# 6. 前端
# H5 首页能打开；商品/机器/我的 页面无白屏
# utils/config.js 的 BASE_URL 指向正确后端
```

## 8. 数据库结构说明

- 迁移文件在 `database/migrations` 和各包的 `packages/*/*/Database/Migrations`
- 业务预置数据：`database/seeders/{SceneSeeder,DeviceModelSeeder,SkillCatalogSeeder}.php`
- 数据库真实名称是 `root`，不是项目名
- 后台配置（邮件、支付等）存在 `core_config` 表，恢复生产数据时会把配置一起带过去

## 9. 常见故障

| 症状 | 原因 | 处理 |
|---|---|---|
| `No application encryption key` | 没执行 `key:generate` | 补执行 |
| 500 + 权限错误 | `storage/` 或 `bootstrap/cache/` 不可写 | 给写权限 |
| 图片 404 | 没执行 `storage:link` | 补执行；Windows 用管理员/开发者模式 |
| `/admin` 404 | `APP_ADMIN_URL` 被改 | 改回 `admin` 或清配置缓存 |
| 页面一直转圈 | 前端 `BASE_URL` 指向了 127.0.0.1（对方机器上指自己） | 改成后端真实地址并重新构建 |
| 任务不执行 | `queue:work` / `schedule:work` 没常驻 | 用守护进程启动 |
| 小程序请求失败 | 未配置 HTTPS 合法域名 | 微信公众平台配置域名 |
| `composer install` 很慢/超时 | 网络 | 换国内镜像：`composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/` |

## 10. 绝对不要做

1. 不要提交 `.env`、数据库导出、`storage/app/keys` 私钥。
2. 不要执行 `php artisan agent:keys:import --replace`（除非明确要轮换密钥）。
3. 不要把生产数据库/客户数据推到 GitHub。
4. 不要用 phpstudy 之类的集成环境跑生产；生产用独立 PHP-FPM/Nginx + MySQL。
5. 不要为了“让页面有数据”去伪造订单或客户。

## 11. 交付标准（一句话）

> `curl /up` 返回 200、`/admin` 可登录、前端能打开并连上后端、`queue:work` 与 `schedule:work` 常驻、默认管理员密码已修改。

完成以上全部，即可认为部署完成。

## 附录：Step10–12 增量部署要点（2026-10-18）

这两天的改动（Step10 本地改进、Step11 搜索资格修复、Step12 技能供给与购买闭环）在既有部署流程之上**没有新增数据库迁移**，`php artisan migrate` 不会有新表；下面是相对旧版新增或需要注意的点。各步的验收结论见 `docs/skill-dev-step10.md`、`docs/skill-dev-step11.md`、`docs/skill-dev-step12.md`，总览见 `docs/dev-handover-index.md`。

### A. 新增环境变量（已补进 `.env.example`）

- `SKILL_DELIVERY_CHANNEL=app`：默认交付通道。保持 `app`（应用内交付，零外部依赖）即可；只有设备侧 Agent 就绪且配了 `AGENT_KEY` 才考虑 `agent`。
- `SKILL_DELIVERY_RETRY_SCAN` / `SKILL_DELIVERY_RETRY_EXECUTE`：技能交付有限补偿的两个开关，**默认关闭**。生产先保持关闭；确认要启用时，两项都置 `true`，且 `queue:work` + `schedule:work` 必须常驻，否则调度里的补偿任务不会跑。建议先只开 SCAN 观察一段时间。
- 既有但仍关键：`SKILL_UPLOAD_KEY`（不配则 skill-admin 路由组整体 503）、`AGENT_KEY`（不配则 `/api/agent/*` 整组 503）。

### B. 调度任务（依赖 `schedule:work` / cron 常驻）

`bootstrap/app.php` 里 `skills:scan-delivery-retries`、`skills:audit-delivery --notify` 受 `SKILL_DELIVERY_RETRY_SCAN` 控制，其余 `ops:*`、试用回收等沿用既有调度。3.8 节的 `queue:work` + `schedule:work` 常驻要求不变，但补偿相关命令只有开了开关才会实际执行。手动排查可用：

```bash
php artisan skills:audit-delivery --limit=100        # 只读巡检
php artisan skills:redeliver <orderId>               # 只检查；加 --execute 且过安全门槛才执行
php artisan schedule:list                            # 确认调度已注册
```

### C. 前端后端地址（端口）

仓库里 `utils/config.js` 的 `BASE_URL` 现为 `http://127.0.0.1:8008`（本地测试端口）。**部署到服务器时务必改成后端真实地址**（第 4 节），别把 127.0.0.1 带上线——否则前端在对方浏览器里指向的是对方自己的机器，表现为"页面一直转圈"。

### D. 代码基线与回滚

- 三步改动均以基线 `c4d2eade` 为起点，现已分 5 个 commit 提交在 `main`（Step10 / Step11 / Step12 / 生态文档 / config 杂项）。
- Step12 修改前的原始文件备份在 `.claude/backup/skill-step12-20261018/`（未入库，本地保留）；回滚脚本在 Codex 交付目录，默认只输出计划、加 `-Apply` 才回退。
- 已知遗留：原生 `CategoryProductTest` 价格升/降序 2 项断言失败为**既有问题**，非本批引入，未修复。部署不受影响，但不要以"全仓测试全绿"作为验收口径。

### E. 未做事项（部署前需知晓）

本批未做真实支付联调、小程序端验证、规模/故障压测、Elasticsearch 等价过滤、真实厂商技能包与真机适配验证。上线前这些仍需按实际环境补齐。
