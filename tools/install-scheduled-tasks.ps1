# 召唤机甲 · 技能平台常驻任务安装脚本
#
# 为什么需要它:队列 worker 与调度器必须是"机器一起就在跑",而不是"某个终端窗口里在跑"。
# 本机是 Windows,没有 cron,所以用计划任务替代 —— 这也是 docs/skill-runbook.md §4 的方案。
#
# 需要**管理员权限**(创建计划任务需要)。用法:
#   方式一:右键"以管理员身份运行" PowerShell,执行本脚本
#   方式二:由上层脚本以 -Verb RunAs 拉起(会弹一次 UAC)
#
# 卸载:
#   schtasks /delete /tn "SummonMecha-schedule" /f
#   schtasks /delete /tn "SummonMecha-queue" /f
#
# 设计取舍(为什么 queue 是"每 5 分钟跑一轮"而不是"一个常驻进程"):
#   常驻进程一旦静默退出就没人拉起来(这正是昨晚发生的事)。改成
#   `queue:work --stop-when-empty --max-time=240` 每 5 分钟由系统拉起一轮:
#   进程自己有寿命,系统保证它复活,不需要 supervisor。
#   代价是队列最坏有 5 分钟延迟 —— 对试用回收(20 分钟粒度)完全够用。

$ErrorActionPreference = 'Stop'
$php     = 'D:\php.exe'
$artisan = 'D:\main\my-bagisto-store\artisan'

if (-not (Test-Path $php))     { throw "找不到 $php" }
if (-not (Test-Path $artisan)) { throw "找不到 $artisan" }

$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { throw '需要管理员权限:请用"以管理员身份运行"的 PowerShell 执行本脚本。' }

# 1) 调度器:每分钟一次(等价于 Linux cron 的 * * * * *)
schtasks /create /tn "SummonMecha-schedule" /tr "`"$php`" `"$artisan`" schedule:run" /sc MINUTE /mo 1 /f

# 2) 队列:每 5 分钟拉起一轮,进程自己会在队列空或 240 秒后退出
schtasks /create /tn "SummonMecha-queue" /tr "`"$php`" `"$artisan`" queue:work --tries=3 --stop-when-empty --max-time=240" /sc MINUTE /mo 5 /f

Write-Host ''
Write-Host '=== 已创建(下面应能看到两个任务,状态 Ready)==='
schtasks /query /tn "SummonMecha-schedule" /fo LIST | Select-String 'TaskName|Status|Next Run'
schtasks /query /tn "SummonMecha-queue"    /fo LIST | Select-String 'TaskName|Status|Next Run'
Write-Host ''
Write-Host '提示:立即验证一次 —— 用 claude code 里那条 ops:queue-probe 看退出码。'
