# tools/pest-all.ps1 —— 全量 Pest 门禁（按文件分进程）
#
# 为什么不是一条 `pest` 跑到底：
#   项目多处控制器调用进程级 `set_time_limit`（TtsController / AsrController /
#   SummonAiController）。PHP CLI 默认不限时；一旦在同一个 Pest 进程里被调到，
#   该进程会在 60/240 秒后被内核砍掉 —— 表现就是"全量跑到中途莫名退出"。
#   本脚本把每个测试文件当独立进程跑，既规避进程级时限，也让单个文件崩溃不拖垮整轮。
#
# 这是**设计决定**，不是缺陷兜底：分文件跑可复现、可定位、可单文件重跑。
# 单进程方案见 docs/skill-dev-step10-wbs-tenth-batch-20261105.md §T7（方案 a 实测结论）。
#
# 用法：
#   powershell -ExecutionPolicy Bypass -File tools/pest-all.ps1
#   powershell -ExecutionPolicy Bypass -File tools/pest-all.ps1 -Filter Deploy
#
# 退出码 = 失败文件数；全绿为 0。

param(
    [string]$Db = "summon_mecha_step10_test",
    [string]$Root = "D:\main\my-bagisto-store",
    [string]$Php = "D:\php.exe",
    [string]$Filter = "",
    [string]$LogDir = "D:\main\qa\wbs-20261105\logs-b10\pest-all"
)

$ErrorActionPreference = "Continue"
Set-Location $Root

if (-not $env:DB_DATABASE) { $env:DB_DATABASE = $Db }
if ($env:DB_DATABASE -ne $Db) {
    Write-Warning "DB_DATABASE=$env:DB_DATABASE（期望 $Db）。本脚本不会改动它；请勿指向 root 库。"
}

# 编译器验收用例要求显式指定受认可的 Python 3.12 解释器（见
# PortableSkillCompilerAcceptanceTest.php:145）。门禁不设它 → 4 条用例必然假红。
# 与 run-b10-regression.ps1 保持一致；已设置则不覆盖。
if (-not $env:ZHJJ_PYTHON_BIN) {
    $env:ZHJJ_PYTHON_BIN = "C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe"
    if (-not (Test-Path $env:ZHJJ_PYTHON_BIN)) {
        Write-Warning "ZHJJ_PYTHON_BIN 指向的 Python 不存在：$env:ZHJJ_PYTHON_BIN"
    }
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

# 只跑 phpunit.xml 声明的 testsuite 目录（不含 vendor）。
$dirs = @(
    "packages/Webkul/Admin/tests/Feature",
    "packages/Webkul/Core/tests/Unit",
    "packages/Webkul/DataGrid/tests/Unit",
    "packages/Webkul/Shop/tests/Feature",
    "packages/Webkul/Shop/tests/Unit"
)

$files = @()
foreach ($d in $dirs) {
    if (-not (Test-Path $d)) { continue }
    $files += Get-ChildItem $d -Recurse -Filter "*Test.php" | Sort-Object FullName | ForEach-Object { $_.FullName }
}
if ($Filter -ne "") { $files = $files | Where-Object { $_ -match [regex]::Escape($Filter) } }

if ($files.Count -eq 0) {
    Write-Error "没有匹配的测试文件（Filter=$Filter）"
    exit 2
}

$sw = [Diagnostics.Stopwatch]::StartNew()
$rows = @()
$failures = 0
$totalTests = 0
$totalAssertions = 0
$i = 0

foreach ($file in $files) {
    $i += 1
    $rel = $file.Replace($Root + "\", "")
    $name = Split-Path $file -Leaf
    $safe = ($rel -replace '[\\/:*?"<>|]', '_')
    $detail = Join-Path $LogDir "$safe.log"

    $out = & $Php vendor/bin/pest $file --compact --colors=never 2>&1
    $exit = $LASTEXITCODE
    $out | Out-File -FilePath $detail -Encoding utf8

    $summary = ($out | Select-String -Pattern "Tests:\s+.*" | Select-Object -Last 1)
    $line = if ($summary) { $summary.Line.Trim() } else { "NO SUMMARY" }
    if ($line -match "Tests:\s+(\d+)\s+passed.*?\((\d+)\s+assertions?\)") {
        $totalTests += [int]$Matches[1]
        $totalAssertions += [int]$Matches[2]
    }
    if ($exit -ne 0) { $failures += 1; $line = "FAILED(exit=$exit) " + $line }

    Write-Host ("[{0,3}/{1}] {2,-62} {3}" -f $i, $files.Count, $name, $line)
    $rows += [pscustomobject]@{ file = $rel; exit = $exit; summary = $line }
}

$sw.Stop()

$summaryLine = "FILES={0} FAILURES={1} TESTS={2} ASSERTIONS={3} DURATION_S={4}" -f `
    $files.Count, $failures, $totalTests, $totalAssertions, [math]::Round($sw.Elapsed.TotalSeconds, 1)
$summaryPath = Join-Path $LogDir "summary.txt"
$rowsPath = Join-Path $LogDir "rows.txt"
@("# generated " + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + " DB=$env:DB_DATABASE", $summaryLine) |
    Out-File -FilePath $summaryPath -Encoding utf8
$rows | ForEach-Object { "{0}`t{1}`t{2}" -f $_.exit, $_.file, $_.summary } | Out-File -FilePath $rowsPath -Encoding utf8

Write-Host ""
Write-Host $summaryLine
Write-Host ("SUMMARY_FILE=" + $summaryPath)
if ($failures -gt 0) {
    Write-Host "FAILED FILES:"
    $rows | Where-Object { $_.exit -ne 0 } | ForEach-Object { Write-Host ("  {0}  {1}" -f $_.file, $_.summary) }
}

exit $failures
