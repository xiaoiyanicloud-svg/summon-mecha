# 技能平台 · 运行手册（本机可验证部分）

> 读者：**要在这台机器（或同形态的 Windows 机）上把平台跑起来、并判断它是否正常的人**。
> 所有命令都是本机**真跑过**的，输出照抄。凡是没跑过的，本文会**明写「未验证」**并说明为什么 ——
> 一份"看起来跑过"的清单比没有清单更危险。

---

## 0. 这份手册**不**覆盖什么（先说清楚）

| 不写 | 为什么 |
|---|---|
| `supervisor` / `systemd` 配置 | 依赖一台**尚不存在的生产 Linux 主机**。写出来的文本本机无法验证正确性，属"不可验证的自娱文本"。等目标主机到位再写。 |
| `nginx` 配置 | 正确内容取决于尚未确定的事：域名/ICP、FPM 还是 Octane、静态路由归属。现在写只能给出一个**没有依据的模板**。 |
| 生产容器的编排文件 | 同上。 |

替代方案在下面第 4 节：**Windows 计划任务**——它是本机真能验证的那一半。

---

## 1. 当前运行形态（2026-10-15 实测）

| 项 | 现状 | 实测依据 |
|---|---|---|
| Web 服务 | `php artisan serve`，端口 **8000** | `curl http://127.0.0.1:8000/up` → `200` |
| 队列驱动 | `database`（不是 Redis） | `.env` `QUEUE_CONNECTION=database` |
| 缓存驱动 | `database` | `.env` `CACHE_STORE=database` |
| 会话驱动 | `file` | `.env` `SESSION_DRIVER=file` |
| **队列 worker** | ❌ **没有常驻** | `jobs` 表 **19 条积压**，最老一条滞留 **1647 分钟**（≈27 小时） |
| **调度器** | ❌ **没有常驻** | 9 条任务已注册，但没人触发它们 |
| 健康检查 | ✅ `/up` 挂 DB/Cache/Queue 三探针 | 正常 200；库连不上时 500（探针自述，未在本轮复验） |
| 平台告警收件箱 | ✅ `/admin/alerts` | 路由 `admin.alerts.index` 存在 |

> ⚠ **13 个字符串里最要命的是"没有常驻"这两行**：平台的功能是完整的，但**它们不会自己跑**。
> 失败是**静默的** —— 页面不报错，任务只是躺在表里。这正是队列那条告警任务存在的理由。

---

## 2. 命令清单（全部本机实测）

PHP 解释器是 **`/d/php.exe`**（PHP 8.4.24）。`php` 不在 PATH 上；phpstudy 自带的 7.3 跑不了本项目。

### 2.1 看调度排了什么（只读，最该先跑的一条）

```bash
cd D:/main/my-bagisto-store
/d/php.exe artisan schedule:list
```

实测输出 **9 条**（5 条 Bagisto 原生 + 4 条本项目 ops 任务）：

```
 1  0 * * *   php artisan product:price-rule:index     Next Due: 23小时后
 0  3 * * *   php artisan invoice:cron                 Next Due: 2小时后
 0  0 * * *   php artisan campaign:process             Next Due: 23小时后
 1  0 * * *   php artisan indexer:index --type=price   Next Due: 23小时后
 0  * * * *   php artisan inspire                      Next Due: 17分钟后
10  9 * * *   php artisan ops:alert-compliance-due     Next Due: 8小时后      ← 本项目
15  9 * * *   php artisan ops:escalate-overdue-tickets Next Due: 8小时后      ← 本项目
25  * * * *   php artisan ops:alert-open-incidents      Next Due: 42分钟后     ← 本项目
40  * * * *   php artisan ops:alert-queue-backlog       Next Due: 57分钟后     ← 本项目
```

### 2.2 四条 ops 任务先干跑一遍（`--dry-run` 不写库）

```bash
for c in ops:alert-compliance-due ops:escalate-overdue-tickets ops:alert-open-incidents ops:alert-queue-backlog; do
  /d/php.exe artisan $c --dry-run
done
```

实测输出（照抄）：

```
[DRY-RUN] 0 份已备案记录中，0 份即将到期/需年检。
[DRY-RUN] 0 张工单已超 SLA。
[DRY-RUN] 0 起事故未结案。
[DRY-RUN] 待消费 19 条；最老一条滞留 1647 分钟；失败队列 0 条。
   · 队列无人消费：最老任务已滞留 1647 分钟
```

> **最后一条是当前唯一在报警的**。前三条是 0 因为它扫的那几张表本来就是空的 ——
> 不是"功能坏了"。**"扫不到东西"和"扫的东西是 0"要分开读**，这也是平台看板上
> 每个数字都标口径的理由。

### 2.3 验证队列真的有 worker 在消费（**最该先跑的一条**）

```bash
/d/php.exe artisan ops:queue-probe          # 默认最多等 20 秒
```

投一个**合成任务**进去，等它被取走；**退出码 0 = 被消费了，1 = 没有 worker**。
`jobs` 表条数**证明不了任何事**（0 条既可能是"都消费完了"，也可能是"任务从来没进来过"），
唯一能区分的是"投一个进去看它会不会消失"。

实测输出：

```
队列连接：database
已投递探针 56bc8c29747c4e10（投递前 jobs=0），等待被消费…
✅ 已被消费
   投递 2026-10-15 01:22:11 → 消费 2026-10-15 01:22:12（worker pid 19484）
   jobs 表现在剩 0 条
```

> `QUEUE_CONNECTION=sync` 时这条命令会**明确报失败并说明原因** ——
> sync 下任务在请求内同步执行，根本不经过 worker，"探针成功"是假象。

### 2.4 健康检查

```bash
curl -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/up
```

实测：`200`。

⚠ `/up` 判的是**"这个实例现在能不能服务请求"**，**不含队列积压** —— 积压是运营指标，
走 `ops:alert-queue-backlog` 告警，不走健康检查。别把 200 读成"一切正常"。

### 2.5 平台告警收件箱

浏览器打开 `/admin/alerts`（需管理员登录）。**只有「标记已读」，没有「删除」** ——
告警是系统产出的事实记录，删掉就等于抹掉"这件事发生过"。

⚠ 告警**依赖调度器在跑**。调度器没起 → 没人写告警 → 收件箱永远空 → 看起来"平台很健康"。

### 2.6 ⚠⚠ 一个实测踩到的静默陷阱：**维护模式会让 worker 拒收任务**

本机实测：`storage/framework/down` 存在时，`php artisan queue:work --once` 会
**睡一觉然后正常退出（退出码 0、零输出、一条任务都不消费）**。

```
$ php artisan queue:work --once
$ echo $?
0                      ← 看着完全正常
$ # 但 jobs 表里 7 条任务纹丝不动，failed_jobs 也是 0
```

成因在 Laravel 的 `WorkCommand`：`downForMaintenance()` 为真且带了 `--once` 时，
它直接 `sleep()` 返回；起守护进程形态则只是打一句警告后空转。

**为什么这个陷阱特别值得记住**：
- 它**不报错**。退出码 0、没有异常、没有日志，看起来跟"跑完了"一模一样
- 而 HTTP 侧可能**完全正常**（本机的 `PreventRequestsDuringMaintenance` 并没有把请求拦成 503）
  —— 于是"网站好的、队列没在跑"这种自相矛盾的状态可以长期存在

查与解：

```bash
php artisan tinker --execute='echo app()->isDownForMaintenance() ? "维护模式" : "正常";'
ls -la storage/framework/down      # 存在即维护模式
php artisan up                     # 清除
```

> 本机这次 `down` 文件的**内容是一个 2 字节的 `[]`** —— 不是 `artisan down` 会写的东西
> （`FileBasedMaintenanceMode::active()` 只看文件**存不存在**，不看内容，
> 所以一个残留的空文件就足以停掉整个队列）。

---

## 3. 让平台真正跑起来（部署动作，不是代码）

两条常驻进程。**本机现在都没起**，所以上面那些任务等于不存在。

```bash
# 队列消费者（下发状态机、通知、部署任务都靠它）
/d/php.exe artisan queue:work --tries=3

# 调度器（开发/小规模形态）
/d/php.exe artisan schedule:work
```

**Linux 生产形态**（等主机到位再验证，此处仅记录方向，**不算已验证**）：
`crontab` 里 `* * * * * php artisan schedule:run >> /dev/null 2>&1`，
`queue:work` 交给 supervisor/systemd 保活。

**怎么确认 worker 明天还在跑**（比"今天起过了"更有用）：不用盯进程，跑一次
`php artisan ops:queue-probe` —— 退出码非 0 就是 worker 没了。
另外 `ops:alert-queue-backlog` 每小时自动检查最老 job 的滞留时长，超标就在
`/admin/alerts` 报警；**告警页没有新的积压告警，说明 worker 还活着**。
这是"自证活着"的设计，不依赖任何人记得去看。

> ⚠ **消费那 19 条积压会发出 12 封历史订单邮件/通知**（另 7 条是库存/索引重建，无副作用）。
> **已逐条解开 payload 核实**（`DB::table('jobs')` 逐条解 `displayName`）：
>
> | 类型 | 条数 | 有没有对外影响 |
> |---|---|---|
> | `Admin\Mail\Order\CreatedNotification` | 4 | 邮件 |
> | `Shop\Mail\Order\CreatedNotification` | 4 | 邮件 |
> | `Notification\Events\CreateOrderNotification` | 4 | 站内通知 |
> | `Product\Jobs\UpdateCreateInventoryIndex` | 5 | 无 |
> | `Product\Jobs\ElasticSearch\UpdateCreateIndex` | 1 | 无 |
> | `CatalogRule\Jobs\UpdateCreateProductIndex` | 1 | 无 |
>
> 12 条待发的全部来自 **4 张订单（#3171–#3174，`status = pending`，创建于 10-13/10-14）**，
> 收件人**无一例外是团队自用的测试账号**（同一个邮箱）。所以**不构成对外影响**。
> 但这条判断的依据是"收件人是谁"，不是"邮件不发"——**换一个人看到这段，请自己复跑一遍再决定**。

### 3.1 这 19 条的实际处置（2026-10-15，已完成）

**处置决定**：删 12 条陈旧邮件/通知（滞留 9–28 小时，发出去只是噪音），保留 7 条幂等重建类
交给 worker 正常消费 —— 顺便成为"worker 真的在消费"的证据。

**执行结果（实测）**：

| 步骤 | 结果 |
|---|---|
| 删除 12 条邮件/通知 | `jobs` 19 → 7 |
| 起 `queue:work --tries=3` | 7 条被消费：**6 条 DONE，1 条 FAIL** |
| 失败的那条 | `CatalogRule\Jobs\UpdateCreateProductIndex` → `ModelNotFoundException: No query results for model [Product]` —— **它引用的商品已经不存在**，是一条永远不可能成功的陈旧任务。留在 `failed_jobs` 里（1 条），属正常归宿 |
| `ops:queue-probe` | ✅ 投递 → 1 秒内被消费（worker pid 对得上） |

⚠ **操作失误，如实记下**：删那 12 条之前我写了"先备份 payload 到文件"，
但 `file_put_contents('/tmp/...')` 在本机解析成了不存在的 `D:\tmp\`，
**返回 false 而我没检查返回值就执行了删除** —— 那 12 条的 payload 没有留下副本。
影响为零（内容是可从 4 张测试订单重新派发的邮件），但"备份失败仍继续"这个动作本身是错的。

---

## 4. Windows 计划任务（`schtasks`）

> **验证状态：语法已通过 schtasks 自身的参数校验；`/create` 动作在本机未验证 —— 权限不足。**
> 本机实测（普通终端，非提权）：
>
> ```
> schtasks /create ... /sd 01/01/2030 /f   →  错误: 无效开始日期(日期格式应该是 "yyyy/mm/dd")。
> schtasks /create ... /sd 2030/01/01 /f   →  错误: 拒绝访问。
> ```
>
> 第一条证明了**参数已经被解析并校验过**（否则会先报权限错）；
> 第二条证明**唯一挡住它的是权限**。所以下面的命令形态是对的，但**"跑得通"这件事需要你在
> 管理员命令行里亲眼确认一次** —— 我不会把一个没跑通的勾打上。

### 4.1 一个坑，先记下来

`/sd` 的日期格式**取决于系统区域设置**。本机报错原文要求 `yyyy/mm/dd`，
而微软文档里的例子是 `mm/dd/yyyy`。照文档抄会被拒。

### 4.2 创建（**需管理员权限的命令行**）

```bat
schtasks /create /tn "SkillPlatform-schedule" ^
  /tr "\"D:\php.exe\" \"D:\main\my-bagisto-store\artisan\" schedule:run" ^
  /sc MINUTE /mo 1 /f
```

- `/tn` **用纯 ASCII 名字**（本机实测：名字里带中文/全角括号在 cmd 上下文里会引出编码问题）
- `/tr` 里的路径**必须加转义引号**，因为 `D:\php.exe` 与路径中可能出现的空格
- `artisan` 靠 `__DIR__` 定位应用，**不依赖工作目录**，所以不需要 `cd`

### 4.3 查

```bat
schtasks /query /tn "SkillPlatform-schedule" /v /fo LIST
```

看 `状态` 与 `下次运行时间` 两行。`就绪` = 已注册待触发；`正在运行` = 此刻在跑。

### 4.4 删

```bat
schtasks /delete /tn "SkillPlatform-schedule" /f
```

### 4.5 验证它真的在执行（而不是只注册上了）

"任务已创建"和"任务会执行"是两件事。最小验证：让任务的动作写一个文件，
`/run` 立刻触发一次，然后看文件在不在。

```bat
schtasks /create /tn "SkillPlatform-probe" /tr "cmd /c echo ok > %TEMP%\sp-probe.txt" /sc ONCE /st 23:59 /sd 2030/01/01 /f
schtasks /run    /tn "SkillPlatform-probe"
type %TEMP%\sp-probe.txt
schtasks /delete /tn "SkillPlatform-probe" /f
```

触发时间设在 2030 年是为了**让它永不自动跑** —— 探针只该在你手动 `/run` 时执行一次。

> ⚠ **不要**为了"证明调度在跑"而在开发机上常驻一个每分钟的任务。机制等的是生产宿主与
> 监控承接方，在本机表演一遍不产生任何信息，只留下一个没人记得关的定时器。

---

## 5. 上线检查清单（状态列式）

⚠ 本表**不是项目进度表**，是"上线前必须逐项确认"的操作清单。
「状态」列是**本机实测值**，不是"应该没问题"。

| # | 检查项 | 命令 / 位置 | 期望 | 本机状态 |
|---|---|---|---|---|
| 1 | 数据库连通 | `curl -o /dev/null -w "%{http_code}" http://<host>/up` | `200` | ✅ 200 |
| 2 | 缓存读写一致 | 同上（`/up` 内置 Cache 探针：写入后读回比对） | `200` | ✅ 200 |
| 3 | 队列后端可达 | 同上（Queue 探针，**不含积压**） | `200` | ✅ 200 |
| 4 | 队列有人消费 | `php artisan ops:alert-queue-backlog --dry-run` | 「最老一条滞留」应 **< 阈值** | ❌ **19 条滞留 1647 分钟** |
| 5 | 调度器在跑 | `php artisan schedule:list` 之后看任务是否真的触发过 | 告警页有记录 | ❌ **未常驻** |
| 6 | 备案台账无到期遗漏 | `php artisan ops:alert-compliance-due --dry-run` | 输出条数与你台账对得上 | ⚪ 台账 0 条，**不适用** |
| 7 | 工单 SLA 有人在看 | `php artisan ops:escalate-overdue-tickets --dry-run` | 0 张超时（或已知） | ✅ 0 张 |
| 8 | 事故未结案清零 | `php artisan ops:alert-open-incidents --dry-run` | 0 起（或已知） | ✅ 0 起 |
| 9 | `APP_DEBUG` | `.env` | 生产 **必须 `false`** | ⚪ 本机 `true`（本地） |
| 10 | 支付网关 | `.env` `PAYMENT_GATEWAY` | 生产**必须**是已声明 `production_safe` 的网关；未声明一律被拒 | ⚪ 本机 mock（不可用于生产） |
| 11 | 对象存储 | `.env` `SKILL_ASSET_DISK` | 生产走 `cos`（等 E4 凭证） | ⚪ 本机 `private` |
| 12 | 内容安全词表 | `CONTENT_SAFETY_WORDS_PATH` | 合规方补全的词表；缺失时 `CONTENT_SAFETY_ON_MISSING=fail`（默认，拒绝提审） | ⚪ 用的是仓库附带的**种子表** |
| 13 | 协议正文定稿 | 后台 CMS `/page/privacy-policy`、`/page/terms-of-use` | 应是律师定稿正文，而不是"尚未定稿"说明 | ❌ **当前是说明页**（等 E6） |
| 14 | 备案号 | — | 有真实备案号后才展示；**无号不展示**（占位号码即伪造） | ⚪ 未取得（等 E2/E3） |

**图例**：✅ 已确认符合 ／ ❌ 明确不符合（**这是行动项**） ／ ⚪ 本机不适用或等外部依赖

> 第 13、14 行不是"忘了做"，是**外部依赖没到位**（律师定稿、ICP 备案）。
> 平台对这两件事的处理方式是**如实展示"没有"**，而不是放一个空壳或占位号 ——
> 空的备案号栏位会被读成"已合规"。

---

## 6. 出问题时先看什么

| 现象 | 先查 | 常见原因 |
|---|---|---|
| 页面数字全 0 | 该指标的口径文字 | 很可能是**数据源未接入**（页面会显示「未接入」而非 0），不是"业务为 0" |
| 任务不执行 | `schedule:list` → 再确认 `schedule:work` 在跑 | 调度器没起。**静默失败，页面不报错** |
| 通知/邮件不发 | `jobs` 表条数 | worker 没起，任务躺在表里 |
| 页面改了库不生效 | 该路由是否挂了 `cache.response` | spatie 响应缓存 7 天，改库后要 `responsecache:clear` |
| 后台看板某个维度永远是 0 | 该列**有没有写入方** | 曾经有过一列（`support_ticket.developer_id`）有索引、看着像在用，但全仓没人写它 |

---

## 7. 与其它文档的关系

- 本文只讲**怎么操作与怎么验证**。
- "还有什么没做" → [`skill-remaining-closure.md`](./skill-remaining-closure.md)
- "按什么顺序做" → [`skill-dev-steps.md`](./skill-dev-steps.md)
- "测试失败哪些是既有的" → [`skill-test-baseline.md`](./skill-test-baseline.md)
