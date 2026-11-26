# A5/A6/A7 回归：按文件分进程跑 Deploy 全部 + 7 个 API 契约文件
# （项目约束：控制器 set_time_limit 是进程级的，全量单进程必炸）
$ErrorActionPreference = "Continue"
Set-Location D:\main\my-bagisto-store

$env:DB_DATABASE = "summon_mecha_step10_test"
$env:ZHJJ_PYTHON_BIN = "C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe"

$log = "D:\main\qa\wbs-20261105\a5a6a7-php-regression.txt"
$detailDir = "D:\main\qa\wbs-20261105\logs-a5a6a7"
New-Item -ItemType Directory -Force -Path $detailDir | Out-Null

$phpVersion = (& D:\php.exe -v | Select-Object -First 1)
"# A5/A6/A7 回归 · $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') · DB=$env:DB_DATABASE · $phpVersion" | Out-File -FilePath $log -Encoding utf8

$files = @()
$files += Get-ChildItem packages/Webkul/Shop/tests/Feature/Deploy -Filter *.php | Sort-Object Name | ForEach-Object { $_.FullName }
$apiNames = @(
  "AgentV2ExecutionTest", "AgentErrorContractP9Test", "AgentEventOwnershipTest",
  "AgentDeviceCredentialB12Test", "AgentDeployTest", "StopObservationContractTest",
  "AgentFactSignatureV3Test"
)
foreach ($name in $apiNames) {
  $files += (Resolve-Path "packages/Webkul/Shop/tests/Feature/API/$name.php").Path
}

$failures = 0
foreach ($file in $files) {
  $name = Split-Path $file -Leaf
  $detail = Join-Path $detailDir "$name.log"
  $out = & D:\php.exe vendor/bin/pest $file 2>&1
  $exit = $LASTEXITCODE
  $out | Out-File -FilePath $detail -Encoding utf8
  $summary = ($out | Select-String -Pattern "Tests:\s+.*" | Select-Object -Last 1)
  $line = if ($summary) { $summary.Line.Trim() } else { "NO SUMMARY" }
  if ($exit -ne 0) { $failures += 1; $line = "FAILED(exit=$exit) " + $line }
  "{0,-58} {1}" -f $name, $line | Out-File -FilePath $log -Append -Encoding utf8
}

"" | Out-File -FilePath $log -Append -Encoding utf8
"FILES=$($files.Count) FAILURES=$failures" | Out-File -FilePath $log -Append -Encoding utf8
exit $failures
